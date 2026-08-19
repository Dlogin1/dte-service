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

// Auto-test SHA1: ¿este OpenSSL puede firmar con SHA1 EN ABSOLUTO? El SII exige
// SHA1. Se prueba con una llave DESECHABLE (no el certificado), para separar
// "el entorno no firma SHA1" de "la llave del certificado tiene un problema".
$sha1 = ['ok' => false];
$pk = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($pk === false) {
    $sha1 = ['ok' => false, 'error' => 'no se pudo generar llave de prueba: ' . openssl_error_string()];
} else {
    $firma = null;
    if (@openssl_sign('prueba', $firma, $pk, OPENSSL_ALGO_SHA1)) {
        $sha1 = ['ok' => true];
    } else {
        $sha1 = ['ok' => false, 'error' => openssl_error_string()];
    }
}

try {
    // token() hace semilla + firma con el certificado + canje por token.
    $token = Sii::token();
    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'sha1_openssl' => $sha1,
        'sii_conexion' => 'OK',
        'sii_autenticacion' => 'OK',
        'token_obtenido' => $token !== '',
        'nota' => 'El certificado autentica contra el SII. Falta solo inscripción + CAF para emitir.',
    ]);
} catch (\Throwable $e) {
    salir(502, [
        'ok' => false,
        'ambiente' => $ambiente,
        'sha1_openssl' => $sha1,
        'sii_autenticacion' => 'FALLÓ',
        'detalle' => $e->getMessage(),
        'nota' => 'Diagnóstico: si sha1_openssl.ok=false → el OpenSSL del servidor no '
                . 'firma con SHA1 (problema de entorno). Si sha1_openssl.ok=true pero el '
                . 'detalle dice "invalid digest" → el problema es la llave del certificado: '
                . 'hay que reexportar el .pfx forzando RSA clásico. Si habla de RUT no '
                . 'habilitado → falta la inscripción en el SII (paso 2.1).',
    ]);
}
