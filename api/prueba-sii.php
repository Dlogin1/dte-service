<?php

declare(strict_types=1);

// API JSON: los warnings/deprecaciones de PHP van a los logs de Vercel, nunca al
// cuerpo de la respuesta (corromperian el JSON). Ver Sii.php (curl_close 8.5).
ini_set('display_errors', '0');

// Prueba de conectividad + autenticación con el SII, SIN emitir nada.
//
// Verifica las dos partes del flujo que NO dependen del CAF ni del set de
// pruebas, y que son las más impredecibles:
//   1. Que dte-service (en Vercel) ALCANZA los servidores del SII (red).
//   2. Que el CERTIFICADO autentica contra el SII: semilla → firma → token.
//
// Token-protegido (no es público) porque usa el certificado contra el SII.
//
// Interpretación del resultado:
//   ok:true               → el certificado ya autentica con el SII. Solo falta
//                           la inscripción y el CAF para emitir de verdad.
//   error "no autorizado" → esperable si el emisor aún no está habilitado en el
//                           ambiente de certificación de BOLETAS (paso 2.1). No
//                           es un bug del sistema.
//   error de red / firma  → eso sí conviene revisar ahora (avísame).

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;
use Clari\DteService\Sii;

Auth::exigirToken();
header('Content-Type: application/json; charset=utf-8');

function salir(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ambiente = Ambiente::activo();
} catch (\Throwable $e) {
    salir(503, ['error' => $e->getMessage()]);
}

try {
    // token() hace semilla + firma con el certificado + canje por token.
    // Si devuelve un token, la red y el certificado funcionan contra el SII.
    $token = Sii::token();
    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'sii_conexion' => 'OK',
        'sii_autenticacion' => 'OK',
        'token_obtenido' => $token !== '',
        'nota' => 'El certificado autentica contra el SII. Falta solo inscripción + CAF para emitir.',
    ]);
} catch (\Throwable $e) {
    salir(502, [
        'ok' => false,
        'ambiente' => $ambiente,
        'sii_autenticacion' => 'FALLÓ',
        'detalle' => $e->getMessage(),
        'nota' => 'Si el mensaje habla de RUT no autorizado/habilitado, es esperable: '
                . 'falta inscribir al emisor en el ambiente de certificación de boletas '
                . '(paso 2.1 del checklist). Si es un error de red o de firma, revísalo.',
    ]);
}
