<?php

declare(strict_types=1);

namespace Clari\DteService;

/**
 * Datos tributarios del emisor (Clari SpA), desde variables de entorno.
 *
 * Van acá y no en cada petición porque son fijos y son identidad de la empresa:
 * clari (Node) no debería poder cambiarlos por accidente en un request.
 *
 * Fail-closed: si falta cualquiera, no se emite. NO se inventan valores por
 * defecto — el RUT, el giro y el domicilio los define el contador (§13).
 */
final class Emisor
{
    /**
     * @return array{RUTEmisor:string,RznSocEmisor:string,GiroEmisor:string,DirOrigen:string,CmnaOrigen:string}
     */
    public static function datos(): array
    {
        $req = [
            'RUTEmisor' => 'EMISOR_RUT',              // 76.543.210-K → 76543210-K
            'RznSocEmisor' => 'EMISOR_RAZON_SOCIAL',
            'GiroEmisor' => 'EMISOR_GIRO',
            'DirOrigen' => 'EMISOR_DIRECCION',
            'CmnaOrigen' => 'EMISOR_COMUNA',
        ];

        $datos = [];
        $faltan = [];
        foreach ($req as $campo => $env) {
            $v = trim((string) (getenv($env) ?: ''));
            if ($v === '') {
                $faltan[] = $env;
            }
            $datos[$campo] = $v;
        }

        if ($faltan) {
            throw new \RuntimeException(
                'Datos del emisor incompletos: falta ' . implode(', ', $faltan)
            );
        }

        // El SII quiere el RUT sin puntos y con guion, en mayúsculas.
        $datos['RUTEmisor'] = strtoupper(str_replace('.', '', $datos['RUTEmisor']));

        return $datos;
    }

    /** RUT del emisor sin dígito verificador (lo piden los servicios REST del SII). */
    public static function rutPartes(): array
    {
        [$rut, $dv] = explode('-', self::datos()['RUTEmisor']) + [1 => ''];
        return ['rut' => (int) $rut, 'dv' => strtoupper($dv)];
    }

    /**
     * Fecha de la resolución del SII que autoriza a emitir (la entrega el SII
     * al inscribirse como emisor electrónico). En certificación es 0 / la fecha
     * que indique el propio SII.
     */
    public static function fechaResolucion(): string
    {
        return trim((string) (getenv('EMISOR_FECHA_RESOLUCION') ?: '')) ?: '2020-01-01';
    }

    public static function numeroResolucion(): int
    {
        return (int) (getenv('EMISOR_NUMERO_RESOLUCION') ?: 0);
    }
}
