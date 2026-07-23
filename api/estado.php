<?php

declare(strict_types=1);

// Consulta de estado de un envío al SII por track ID — se implementa en
// Fase 1 (la usa el cron api/cron/revisar-sii.js de clari). Mismo contrato
// que emitir.php: token + guard de ambiente antes de todo.

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;

Auth::exigirToken();

header('Content-Type: application/json; charset=utf-8');

try {
    $ambiente = Ambiente::activo();
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

http_response_code(501);
echo json_encode([
    'error' => 'no implementado: consulta de estado llega en Fase 1',
    'ambiente' => $ambiente,
]);
