<?php

declare(strict_types=1);

namespace Clari\DteService;

use Derafu\Kernel\Environment;
use Derafu\Signature\Contract\SignatureGeneratorInterface;
use Derafu\Signature\Service\SignatureGenerator;
use Derafu\Xml\Service\XmlDecoder;
use Derafu\Xml\Service\XmlEncoder;
use Derafu\Xml\Service\XmlService;
use Derafu\Xml\Service\XmlValidator;
use libredte\lib\Core\Application;

/**
 * Punto ÚNICO para obtener la aplicación de LibreDTE, configurada para Vercel.
 *
 * POR QUÉ EXISTE: en Vercel el disco del deploy (`/var/task/user`) es de SOLO
 * LECTURA. El kernel de LibreDTE, por defecto, escribe su caché (el contenedor
 * de dependencias compilado) en `<proyecto>/var/cache/dev` — que cae en ese
 * disco de solo lectura y revienta con "Unable to create directory". El único
 * directorio escribible es `/tmp` (`sys_get_temp_dir()`), así que se redirige
 * la caché y los logs ahí.
 *
 * Todos los endpoints deben pedir la app por acá — NUNCA `Application::getInstance()`
 * directo — para garantizar que el singleton se inicialice con esta configuración
 * (el primero que lo pida fija la instancia para toda la invocación).
 */
final class Lib
{
    public static function app(): Application
    {
        $base = sys_get_temp_dir() . '/libredte';
        // Mismo entorno por defecto ('dev'), sólo se cambian los directorios
        // escribibles. context = [] (4º arg son los directorios).
        $env = new Environment('dev', true, [], [
            'cache' => $base . '/cache',
            'log' => $base . '/log',
        ]);

        return Application::getInstance($env);
    }

    /**
     * Firmador XML autónomo, para firmar la semilla del SII (Sii::token()).
     *
     * No se pide al contenedor de LibreDTE porque ahí el servicio de firma es
     * PRIVADO (`public: false` en el services.yaml de derafu/signature), y pedirlo
     * por ID falla con "non-existent service". Se instancia directo: es la MISMA
     * clase que LibreDTE usa internamente (via DI) para firmar los DTE, así que
     * la firma es equivalente. Las clases hoja del XML no tienen dependencias.
     *
     * (Ojo: el ARMADO/firma de la boleta en emitir.php sí usa el contenedor —
     * ahí el servicio se resuelve por inyección interna y funciona. Esto es solo
     * para el uso "desde afuera" de la firma, como la semilla.)
     */
    public static function firmador(): SignatureGeneratorInterface
    {
        $xml = new XmlService(new XmlEncoder(), new XmlDecoder(), new XmlValidator());

        return new SignatureGenerator($xml);
    }
}
