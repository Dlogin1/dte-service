<?php

declare(strict_types=1);

// Consulta el estado en el SII de un envío de boletas, por su track ID.
// La usa el cron de clari (api/cron/revisar-sii.js) para cerrar el ciclo:
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
 * Traduce el estado del SII al vocabulario de la tabla `dte` de clari.
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
    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'track_id' => $trackId,
        'estado' => $r['estado'],
        'glosa' => $r['glosa'],
        'veredicto' => veredicto($r['estado']),
    ]);
} catch (\Throwable $e) {
    salir(502, ['error' => 'no se pudo consultar el SII', 'detalle' => $e->getMessage()]);
}
