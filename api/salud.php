<?php

declare(strict_types=1);

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
// por eso el PDF lo genera clari con su pipeline Chrome headless (§4.2).
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

$salida['ok'] = $faltanRequeridas === []
    && isset($salida['libredte']['version'])
    && in_array($salida['ambiente'], ['certificacion', 'produccion'], true);

http_response_code($salida['ok'] ? 200 : 500);
echo json_encode($salida, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
