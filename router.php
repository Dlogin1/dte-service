<?php

declare(strict_types=1);

// Router para el servidor embebido de PHP (contenedor). Replica el ruteo por
// archivos que hacía Vercel: una petición a /api/salud ejecuta /app/api/salud.php,
// /api/emitir → /app/api/emitir.php, etc. Así las URLs no cambian y regsi le
// sigue hablando igual (DTE_SERVICE_URL + '/api/...').

$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Normalizar: /api/salud → /api/salud.php (si no trae extensión).
$destino = __DIR__ . $uri;
if (!str_ends_with($destino, '.php')) {
    $destino .= '.php';
}

// Seguridad: solo se ejecutan archivos .php DENTRO de /app/api. Nada de servir
// código de src/ o el propio router por la web.
$apiDir = realpath(__DIR__ . '/api');
$archivo = realpath($destino);

if ($archivo !== false
    && $apiDir !== false
    && str_starts_with($archivo, $apiDir . DIRECTORY_SEPARATOR)
    && str_ends_with($archivo, '.php')
    && is_file($archivo)
) {
    require $archivo;
    return true;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['error' => 'Endpoint no encontrado']);
return true;
