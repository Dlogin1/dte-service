<?php

declare(strict_types=1);

namespace Clari\DteService;

/**
 * Guard doble de ambiente (regla dura §10.1 del prompt).
 *
 * El servicio se niega a operar contra producción (Palena) salvo que exista
 * una SEGUNDA variable explícita de confirmación — mismo patrón que
 * AUTO_PUBLISH=on en clari-growth-os. Fail-closed: si SII_AMBIENTE falta o
 * trae cualquier otro valor, no se emite nada.
 */
final class Ambiente
{
    public const CERTIFICACION = 'certificacion';
    public const PRODUCCION = 'produccion';

    /**
     * Devuelve el ambiente activo o lanza si la configuración no autoriza operar.
     */
    public static function activo(): string
    {
        $ambiente = getenv('SII_AMBIENTE') ?: '';

        if ($ambiente === self::CERTIFICACION) {
            return self::CERTIFICACION;
        }

        if ($ambiente === self::PRODUCCION) {
            // Producción exige la doble llave: sin ella, bloqueado.
            if (getenv('SII_PRODUCCION_CONFIRMADA') === 'si') {
                return self::PRODUCCION;
            }
            throw new \RuntimeException(
                'SII_AMBIENTE=produccion sin SII_PRODUCCION_CONFIRMADA=si — bloqueado (§10.1).'
            );
        }

        throw new \RuntimeException(
            'SII_AMBIENTE ausente o inválido — fail-closed: el servicio no opera.'
        );
    }
}
