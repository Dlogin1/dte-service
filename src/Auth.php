<?php

declare(strict_types=1);

namespace Clari\DteService;

/**
 * Autenticación servicio-a-servicio: regsi (Node) es el ÚNICO cliente y manda
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
        if (!self::tieneToken()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'no autorizado']);
            exit;
        }
    }

    /**
     * Chequeo SUAVE: ¿la petición trae el token válido? Devuelve bool sin cortar
     * la ejecución. Lo usa /api/salud para decidir cuánto detalle mostrar: el
     * health público va sin token; los datos del certificado (nombre del titular)
     * y el commit de la dependencia solo se muestran a quien presenta el token.
     * Misma comparación en tiempo constante y fail-closed que exigirToken().
     */
    public static function tieneToken(): bool
    {
        $esperado = getenv('DTE_SERVICE_TOKEN') ?: '';
        $recibido = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        return $esperado !== '' && hash_equals('Bearer ' . $esperado, $recibido);
    }
}
