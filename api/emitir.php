<?php

declare(strict_types=1);

// Emisión de DTE — se implementa en Fase 1 (boleta 39). El endpoint ya existe
// para dejar fijado el contrato: token obligatorio + guard de ambiente ANTES
// de cualquier lógica. Recibirá {tipo, folio, receptor, items…} y devolverá
// el XML timbrado + datos del timbre (el PDF lo genera clari, §4.2).

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;

Auth::exigirToken();

header('Content-Type: application/json; charset=utf-8');

try {
    $ambiente = Ambiente::activo();
} catch (\Throwable $e) {
    // Fail-closed: sin ambiente autorizado no se emite nada (§10.1).
    http_response_code(503);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

http_response_code(501);
echo json_encode([
    'error' => 'no implementado: emisión llega en Fase 1',
    'ambiente' => $ambiente,
]);
