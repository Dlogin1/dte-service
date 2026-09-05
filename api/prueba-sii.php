<?php

declare(strict_types=1);

// API JSON: los warnings/deprecaciones de PHP van a los logs, nunca al cuerpo de
// la respuesta (corromperían el JSON). Ver Sii.php (curl_close 8.5).
ini_set('display_errors', '0');

// Prueba de conectividad + autenticación con el SII, SIN emitir nada.
//
// Verifica las dos partes del flujo que NO dependen del CAF ni del set de
// pruebas, y que son las más impredecibles:
//   1. Que dte-service ALCANZA los servidores del SII (red).
//   2. Que el CERTIFICADO autentica contra el SII: semilla → firma → token.
//   Incluye un auto-test de que este OpenSSL firma con SHA1 (el SII lo exige;
//   en Debian funciona, en el OpenSSL de Vercel no — por eso el contenedor).
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

// Modo diagnóstico: ?ver=token devuelve un TOKEN de sesión del SII vigente
// (~1 h), para poder probar la SUBIDA del sobre directo con curl desde afuera
// sin pasar por este servicio (aislar si el rechazo es por nuestro multipart).
// Token-protegido como todo el endpoint; solo ambiente de certificación.
if (($_GET['ver'] ?? '') === 'token') {
    try {
        salir(200, ['ambiente' => $ambiente, 'token_sii' => Sii::token()]);
    } catch (\Throwable $e) {
        salir(502, ['ambiente' => $ambiente, 'error' => $e->getMessage()]);
    }
}

// Modo diagnóstico: ?ver=xml devuelve el XML firmado que se enviaría al SII, sin
// canjearlo, para inspeccionar el formato de la firma (solo datos públicos).
if (($_GET['ver'] ?? '') === 'xml') {
    try {
        salir(200, ['ambiente' => $ambiente] + Sii::semillaFirmadaDebug());
    } catch (\Throwable $e) {
        salir(502, ['ambiente' => $ambiente, 'error' => $e->getMessage()]);
    }
}

// Auto-test de firma con una llave DESECHABLE (no el certificado): ¿puede este
// OpenSSL firmar con SHA1 (lo que exige el SII) y con SHA256? Separa "el entorno
// no firma SHA1" (política de criptografía) de un problema del certificado.
$sha1 = ['openssl' => OPENSSL_VERSION_TEXT];
$pk = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($pk === false) {
    $sha1['error'] = 'no se pudo generar llave de prueba: ' . openssl_error_string();
} else {
    $f = null;
    $sha1['sha1'] = @openssl_sign('x', $f, $pk, OPENSSL_ALGO_SHA1) ? 'OK' : ('FALLA: ' . openssl_error_string());
    $f = null;
    $sha1['sha256'] = @openssl_sign('x', $f, $pk, OPENSSL_ALGO_SHA256) ? 'OK' : ('FALLA: ' . openssl_error_string());
    $sha1['ok'] = ($sha1['sha1'] === 'OK');
}
// El TIMBRE del TED se firma con la llave del CAF, que el SII entrega de 512
// BITS. OpenSSL 3.x a security level ≥1 rechaza firmar con RSA < 1024 bits →
// "No fue posible timbrar los datos" aunque SHA1 con 2048 bits funcione. Este
// test lo aísla.
$pk512 = @openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($pk512 === false) {
    $sha1['rsa512'] = 'no se pudo generar llave 512: ' . openssl_error_string();
} else {
    $f = null;
    $sha1['rsa512'] = @openssl_sign('x', $f, $pk512, OPENSSL_ALGO_SHA1) ? 'OK' : ('FALLA: ' . openssl_error_string());
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
