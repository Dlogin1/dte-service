<?php

declare(strict_types=1);

// API JSON: los warnings/deprecaciones de PHP van a los logs de Vercel, nunca al
// cuerpo de la respuesta (corromperian el JSON). Ver Sii.php (curl_close 8.5).
ini_set('display_errors', '0');

// Endpoint de salud — el ÚNICO objetivo de la Fase 0 es que esta página
// responda ok:true en Vercel: eso prueba que LibreDTE compila y carga en el
// runtime PHP comunitario (riesgo #1, §4.2 del prompt). No expone secretos:
// solo versión de PHP, extensiones y el estado del guard de ambiente.

header('Content-Type: application/json; charset=utf-8');

// Carga directa (no vía composer): así el guard de ambiente se reporta
// incluso si el build de composer falló y no hay vendor/autoload.php.
require_once __DIR__ . '/../src/Ambiente.php';

$salida = [
    'ok' => false,
    'servicio' => 'dte-service',
    'php' => PHP_VERSION,
];

// 1) Extensiones que LibreDTE lib-core declara en su composer.json.
$requeridas = ['curl', 'json', 'mbstring', 'openssl', 'soap'];
// Las usan sus dependencias de XML/firma. gd se espera AUSENTE en Vercel:
// por eso el PDF lo genera regsi con su pipeline Chrome headless (§4.2).
$deseables = ['dom', 'libxml', 'SimpleXML', 'xsl', 'xmlwriter', 'zip', 'gd'];

$faltanRequeridas = array_values(array_filter($requeridas, fn ($e) => !extension_loaded($e)));
$salida['extensiones'] = [
    'faltan_requeridas' => $faltanRequeridas,
    'deseables' => array_combine($deseables, array_map('extension_loaded', $deseables)),
];

// 2) ¿Compiló e instaló LibreDTE? (composer install del build de Vercel)
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    $salida['libredte'] = ['error' => 'vendor/autoload.php no existe: composer install no corrió o falló en el build'];
} else {
    require $autoload;
    try {
        $salida['libredte'] = [
            'version' => \Composer\InstalledVersions::getPrettyVersion('libredte/libredte-lib-core'),
            // El commit exacto instalado: con composer.lock commiteado este
            // valor es reproducible entre deploys (§4.2, riesgo #2).
            'commit' => substr((string) \Composer\InstalledVersions::getReference('libredte/libredte-lib-core'), 0, 12),
        ];
    } catch (\Throwable $e) {
        $salida['libredte'] = ['error' => $e->getMessage()];
    }
}

// 3) Guard de ambiente (§10.1): reporta si el servicio operaría y contra qué
//    ambiente. En Fase 0 lo esperado es 'certificacion' (Maullín).
try {
    $salida['ambiente'] = \Clari\DteService\Ambiente::activo();
} catch (\Throwable $e) {
    $salida['ambiente'] = 'BLOQUEADO: ' . $e->getMessage();
}

// 4) ¿Está configurada la autenticación servicio-a-servicio?
$salida['auth_configurada'] = (getenv('DTE_SERVICE_TOKEN') ?: '') !== '';

// 5) Preparación para emitir (NO expone secretos). Sirve para verificar, antes
//    de tener la inscripción y el CAF del SII, dos cosas que ya están en tu
//    mano: que el CERTIFICADO carga bien y que los datos del EMISOR están
//    puestos. El certificado se prueba con el mismo cargador que usa la emisión
//    real; solo se muestran datos públicos (titular y vencimiento), nunca la
//    clave privada.
$prep = ['emisor' => []];
foreach (['EMISOR_RUT', 'EMISOR_RAZON_SOCIAL', 'EMISOR_GIRO', 'EMISOR_DIRECCION', 'EMISOR_COMUNA'] as $e) {
    $prep['emisor'][$e] = (getenv($e) ?: '') !== '';
}
if ((getenv('CERT_P12_BASE64') ?: '') === '') {
    $prep['certificado'] = 'no configurado';
} elseif (!is_file($autoload)) {
    $prep['certificado'] = 'no verificable (falta el build de LibreDTE)';
} else {
    require_once __DIR__ . '/../src/Certificado.php';
    try {
        \Clari\DteService\Certificado::cargar();   // lanza si base64/clave son inválidos
        $cert = ['carga' => true];
        // Titular y vencimiento son datos públicos del certificado (aparecen en
        // cada documento firmado). La clave privada nunca se toca ni se expone.
        $der = base64_decode((string) getenv('CERT_P12_BASE64'), true);
        $store = [];
        if ($der !== false && @openssl_pkcs12_read($der, $store, (string) getenv('CERT_PASS'))) {
            $x = openssl_x509_parse($store['cert'] ?? '');
            if (is_array($x)) {
                $cert['titular'] = $x['subject']['CN'] ?? null;
                $cert['vence'] = isset($x['validTo_time_t']) ? date('Y-m-d', $x['validTo_time_t']) : null;
                $cert['vigente'] = isset($x['validTo_time_t']) ? ($x['validTo_time_t'] > time()) : null;
            }
        }
        $prep['certificado'] = $cert;
    } catch (\Throwable $e) {
        // Sin el mensaje original: podría filtrar detalles del certificado.
        $prep['certificado'] = ['carga' => false, 'pista' => 'no se pudo abrir el certificado (.p12/.pfx) — revisa CERT_PASS y que el base64 esté completo. Si es un .pfx exportado en Windows con cifrado antiguo, reexpórtalo con AES.'];
    }
}
$salida['preparacion_dte'] = $prep;

$salida['ok'] = $faltanRequeridas === []
    && isset($salida['libredte']['version'])
    && in_array($salida['ambiente'], ['certificacion', 'produccion'], true);

http_response_code($salida['ok'] ? 200 : 500);
echo json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
