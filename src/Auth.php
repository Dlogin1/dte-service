<?php

declare(strict_types=1);

namespace Clari\DteService;

/**
 * Autenticación servicio-a-servicio: clari (Node) es el ÚNICO cliente y manda
 * `Authorization: Bearer <DTE_SERVICE_TOKEN>` (mismo secreto en las env vars
 * de ambos proyectos de Vercel). Comparación en tiempo constante.
 *
 * Fail-closed: si DTE_SERVICE_TOKEN no está configurado, todo se rechaza —
 * nunca un servicio de emisión tributaria abierto por accidente.
 */
final class Auth
{
    public static function exigirToken(): void
    {
        $esperado = getenv('DTE_SERVICE_TOKEN') ?: '';
        $recibido = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($esperado === '' || !hash_equals('Bearer ' . $esperado, $recibido)) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'no autorizado']);
            exit;
        }
    }
}
