<?php

declare(strict_types=1);

namespace Clari\DteService;

use Derafu\Certificate\Contract\CertificateInterface;
use Derafu\Certificate\Service\CertificateLoader;

/**
 * Carga el certificado digital desde las variables de entorno.
 *
 * El certificado es de una PERSONA NATURAL (el representante legal), que firma
 * en representación de la empresa emisora. Vive como .p12 codificado en base64
 * en CERT_P12_BASE64 + su clave en CERT_PASS: nunca en disco, nunca en el repo
 * (que es público por AGPL), nunca en logs (§10.3 del prompt).
 */
final class Certificado
{
    private static ?CertificateInterface $cache = null;

    public static function cargar(): CertificateInterface
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $b64 = getenv('CERT_P12_BASE64') ?: '';
        $pass = getenv('CERT_PASS');

        if ($b64 === '' || $pass === false || $pass === '') {
            throw new \RuntimeException(
                'Certificado no configurado (faltan CERT_P12_BASE64 y/o CERT_PASS).'
            );
        }

        $p12 = base64_decode($b64, true);
        if ($p12 === false) {
            throw new \RuntimeException('CERT_P12_BASE64 no es base64 válido.');
        }

        // loadFromData lanza CertificateException si la clave es incorrecta. Su
        // mensaje ("mac verify failure", etc.) NO debe llegar al cliente ni a
        // otros catch que devuelven $e->getMessage(): se envuelve en una
        // excepción propia con texto genérico. El original va al log del servidor.
        try {
            self::$cache = (new CertificateLoader())->loadFromData($p12, (string) $pass);
        } catch (\Throwable $e) {
            error_log('Certificado::cargar loadFromData: ' . $e->getMessage());
            throw new \RuntimeException('No se pudo abrir el certificado (.p12): revisa CERT_PASS y el base64.');
        }

        return self::$cache;
    }
}
