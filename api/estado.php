<?php

declare(strict_types=1);

// API JSON: los warnings/deprecaciones de PHP van a los logs de Vercel, nunca al
// cuerpo de la respuesta (corromperian el JSON). Ver Sii.php (curl_close 8.5).
ini_set('display_errors', '0');

// Consulta el estado en el SII de un envío de boletas, por su track ID.
// La usa el cron de regsi (api/cron/revisar-sii.js) para cerrar el ciclo:
// enviado → aceptado / aceptado con reparos / rechazado.
//
// POST { track_id }
//  200 → { ok, estado, glosa, veredicto }
//        veredicto: pendiente | aceptado | aceptado_reparos | rechazado
//  502 → el SII no respondió (reintentable)

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;
use Clari\DteService\Sii;

Auth::exigirToken();
header('Content-Type: application/json; charset=utf-8');

function salir(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ambiente = Ambiente::activo();
} catch (\Throwable $e) {
    salir(503, ['error' => $e->getMessage()]);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
$trackId = trim((string) ($in['track_id'] ?? ''));
if ($trackId === '') {
    salir(422, ['error' => 'falta track_id']);
}

/**
 * Traduce el estado del SII al vocabulario de la tabla `dte` de regsi.
 *
 * Códigos del SII para envíos de boletas (los mismos del flujo DTE clásico):
 *   EPR/DOK  → envío procesado, todo correcto.
 *   RSC/RCT/RFR/RCH → rechazado (esquema, firma, contenido…).
 *   DNK      → procesado con reparos (aceptado, pero con observaciones).
 *   SOK/RPR/PDR/-11… → aún en proceso: se vuelve a consultar después.
 * Cualquier código desconocido queda 'pendiente' a propósito: preferimos
 * reconsultar de más antes que dar por aceptado algo que no lo está.
 */
function veredicto(string $estado): string
{
    $e = strtoupper(substr(trim($estado), 0, 3));
    if (in_array($e, ['EPR', 'DOK'], true)) {
        return 'aceptado';
    }
    if ($e === 'DNK') {
        return 'aceptado_reparos';
    }
    if (in_array($e, ['RSC', 'RCT', 'RFR', 'RCH', 'RRR'], true)) {
        return 'rechazado';
    }
    return 'pendiente';
}

try {
    $r = Sii::estadoEnvio($trackId);
    $salida = [
        'ok' => true,
        'ambiente' => $ambiente,
        'track_id' => $trackId,
        'estado' => $r['estado'],
        'glosa' => $r['glosa'],
        'veredicto' => veredicto($r['estado']),
    ];
    // ?ver=crudo — diagnóstico: la respuesta completa del SII (puede traer el
    // detalle de POR QUÉ rechazó: qué firma, qué campo). Token-protegido.
    if (($_GET['ver'] ?? '') === 'crudo') {
        $crudo = (string) ($r['crudo'] ?? '');
        if (!mb_check_encoding($crudo, 'UTF-8')) {
            $crudo = mb_convert_encoding($crudo, 'UTF-8', 'ISO-8859-1');
        }
        $salida['crudo'] = mb_substr($crudo, 0, 4000);
    }
    salir(200, $salida);
} catch (\Throwable $e) {
    salir(502, ['error' => 'no se pudo consultar el SII', 'detalle' => $e->getMessage()]);
}
