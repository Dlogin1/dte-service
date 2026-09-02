<?php

declare(strict_types=1);

namespace Clari\DteService;

use Derafu\Signature\Contract\SignatureServiceInterface;
use libredte\lib\Core\Application;

/**
 * Comunicación con los servicios REST de BOLETA ELECTRÓNICA del SII.
 *
 * POR QUÉ ESTE ARCHIVO EXISTE (importante):
 * LibreDTE lib-core sabe CONSTRUIR la boleta y el sobre EnvioBOLETA, firmarlos
 * y validarlos — pero su único job de subida (SendXmlDocumentJob) publica en
 * `/cgi_dte/UPL/DTEUpload`, que es la vía clásica de las FACTURAS. Desde 2021
 * las boletas electrónicas se envían por una API REST distinta, con su propio
 * flujo de autenticación. Eso es lo que implementa esta clase; todo lo demás
 * (armado, timbre, firma) lo sigue haciendo LibreDTE.
 *
 * Flujo de autenticación (el mismo espíritu del SOAP): pedir una semilla,
 * firmarla con el certificado, canjearla por un token que dura ~1 hora.
 *
 * ⚠️ Las rutas REST del SII cambian de vez en cuando y su documentación es
 * escasa. Están todas centralizadas acá arriba a propósito: si el SII mueve
 * algo, se corrige en un solo lugar. Verificar contra el «Instructivo para
 * certificación de boletas electrónicas» del SII al hacer la certificación.
 */
final class Sii
{
    // Servidores por ambiente: [autenticación y consultas, envío de boletas].
    private const HOSTS = [
        Ambiente::CERTIFICACION => ['apicert.sii.cl', 'pangal.sii.cl'],
        Ambiente::PRODUCCION => ['api.sii.cl', 'rahue.sii.cl'],
    ];

    // User-Agent CANÓNICO que exige la API del SII (su gateway valida el patrón
    // "Mozilla/4.0 (compatible; PROG 1.0..."). No inventar variantes.
    private const USER_AGENT = 'Mozilla/4.0 (compatible; PROG 1.0; regsi)';

    private static ?string $token = null;

    private static function hosts(): array
    {
        return self::HOSTS[Ambiente::activo()];
    }

    /**
     * Token de sesión del SII para la API de boletas. Se cachea en memoria
     * durante la invocación (el servicio es sin estado: no se persiste).
     */
    public static function token(): string
    {
        if (self::$token !== null) {
            return self::$token;
        }

        [$apiHost] = self::hosts();
        $base = 'https://' . $apiHost . '/recursos/v1';

        // 1) Semilla.
        $resp = self::curl($base . '/boleta.electronica.semilla', 'GET');
        if (!preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/', $resp['body'], $m)) {
            throw new \RuntimeException(
                'No fue posible obtener la semilla del SII (HTTP ' . $resp['status'] . '): ' . self::preview($resp['body'])
            );
        }
        $semilla = $m[1];

        // 2) Firmar la semilla con el certificado (firma XML del core).
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<getToken><item><Semilla>' . $semilla . '</Semilla></item></getToken>';
        /** @var SignatureServiceInterface $firmador */
        $firmador = Lib::firmador();
        $xmlFirmado = self::aplanar($firmador->signXml($xml, Certificado::cargar()));

        // 3) Canjear la semilla firmada por el token.
        $resp = self::curl($base . '/boleta.electronica.token', 'POST', $xmlFirmado, [
            'Content-Type: application/xml',
        ]);
        if (!preg_match('/<TOKEN>(.+?)<\/TOKEN>/', $resp['body'], $m)) {
            throw new \RuntimeException(
                'No fue posible obtener el token del SII (HTTP ' . $resp['status'] . '): ' . self::preview($resp['body'])
            );
        }

        return self::$token = $m[1];
    }

    /**
     * DIAGNÓSTICO: pide la semilla y devuelve el XML firmado que se enviaría al
     * SII, SIN canjearlo. Sirve para inspeccionar el formato exacto de la firma
     * (¿trae <X509Certificate>? ¿con qué namespace/prefijo?) cuando el SII lo
     * rechaza. Solo datos públicos: semilla efímera + certificado público +
     * firma; nunca la llave privada. Lo usa /api/prueba-sii?ver=xml.
     *
     * @return array{semilla:string,xml_firmado:string}
     */
    public static function semillaFirmadaDebug(): array
    {
        [$apiHost] = self::hosts();
        $base = 'https://' . $apiHost . '/recursos/v1';

        $resp = self::curl($base . '/boleta.electronica.semilla', 'GET');
        if (!preg_match('/<SEMILLA>(\d+)<\/SEMILLA>/', $resp['body'], $m)) {
            throw new \RuntimeException(
                'No fue posible obtener la semilla del SII (HTTP ' . $resp['status'] . '): ' . self::preview($resp['body'])
            );
        }
        $semilla = $m[1];

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
             . '<getToken><item><Semilla>' . $semilla . '</Semilla></item></getToken>';
        $xmlFirmado = self::aplanar(Lib::firmador()->signXml($xml, Certificado::cargar()));

        return ['semilla' => $semilla, 'xml_firmado' => $xmlFirmado];
    }

    /**
     * Sube el sobre EnvioBOLETA al SII. Devuelve el track ID del envío, que es
     * lo que después se consulta para saber si fue aceptado.
     */
    public static function enviarBoleta(string $xmlSobre): string
    {
        return self::subirXml($xmlSobre, 'envio.xml');
    }

    /** Subida de un XML (sobre de boletas o libro RVD) al recurso de envío. */
    private static function subirXml(string $xml, string $nombreArchivo): string
    {
        // OJO: el sobre va TAL CUAL lo serializa LibreDTE (formateado). NO se
        // aplana: el gateway del SII rechaza sobres en una sola línea con
        // SCH-00001 "Invalid Schema Name" (probado empíricamente: el mismo sobre
        // aplanado → SCH-00001; formateado → trackid). El aplanado solo aplica a
        // la SEMILLA del getToken, donde sí es obligatorio (ESTADO 11 sin él).
        $xmlSobre = $xml;
        $emisor = Emisor::rutPartes();

        // rutSender = RUT del CERTIFICADO (la persona autenticada que obtuvo el
        // token), NO el de la empresa: el SII los cruza y si no calzan responde
        // 401 "NO ESTA AUTENTICADO". rutCompany sí es la empresa emisora.
        $certId = strtoupper(Certificado::cargar()->getId());   // ej. 17872544-0
        $p = strrpos($certId, '-');
        $sender = ['rut' => substr($certId, 0, (int) $p), 'dv' => substr($certId, $p + 1)];
        [, $envioHost] = self::hosts();
        $url = 'https://' . $envioHost . '/recursos/v1/boleta.electronica.envio';

        // multipart/form-data NATIVO de curl (mismo formato que el curl -F con el
        // que se validó el flujo). CURLStringFile evita archivo temporal.
        $cuerpo = [
            'rutSender' => (string) $sender['rut'],
            'dvSender' => $sender['dv'],
            'rutCompany' => (string) $emisor['rut'],
            'dvCompany' => $emisor['dv'],
            'archivo' => new \CURLStringFile($xmlSobre, $nombreArchivo, 'text/xml'),
        ];

        $resp = self::curl($url, 'POST', $cuerpo, [
            'Cookie: TOKEN=' . self::token(),
            // La API del SII exige en TODA llamada autenticada (fuera de semilla/
            // token): accept application/json + este User-Agent CANÓNICO. Su
            // gateway valida el patrón "Mozilla/4.0 (compatible; PROG 1.0..." y
            // sin él (o sin el accept) responde 401 NO ESTA AUTENTICADO.
            // El Content-Type multipart (con boundary) lo pone curl solo.
            'User-Agent: ' . self::USER_AGENT,
            'accept: application/json',
        ]);

        // Con accept: application/json el SII responde JSON:
        //   { "rut_emisor": ..., "trackid": 32084932, "estado": "REC", ... }
        $j = json_decode($resp['body'], true);
        if (is_array($j) && !empty($j['trackid'])) {
            return (string) $j['trackid'];
        }
        // Compatibilidad con la variante XML (<trackid>...</trackid>).
        if (preg_match('/<trackid>(\d+)<\/trackid>/i', $resp['body'], $m)) {
            return $m[1];
        }
        // El SII responde el detalle del rechazo en el mismo cuerpo.
        throw new \RuntimeException(
            'El SII no aceptó el envío (HTTP ' . $resp['status'] . '): ' . self::resumen($resp['body'])
        );
    }

    /**
     * Envía el Resumen de Ventas Diarias (RVD, ex RCOF) al SII.
     *
     * Usa el mismo recurso de envío que las boletas: el SII distingue el tipo
     * de documento por el contenido del XML (raíz <ConsumoFolios>), no por la
     * ruta. ⚠️ Confirmar en la certificación: si el SII pide un recurso propio
     * para el libro, solo hay que cambiar la constante de abajo.
     */
    public static function enviarLibroBoletas(string $xmlLibro): string
    {
        return self::subirXml($xmlLibro, 'libro.xml');
    }

    /**
     * Consulta el estado de un envío por su track ID.
     *
     * @return array{estado:string,glosa:string,crudo:string}
     */
    public static function estadoEnvio(string $trackId): array
    {
        $emisor = Emisor::rutPartes();
        [$apiHost] = self::hosts();
        // Formato del recurso: /{rut}-{dv}-{trackid} (guiones, SIN sufijo
        // /estado). Lo confirma el propio SII: al reenviar un sobre duplicado
        // responde 405 con "X-Location: .../boleta.electronica.envio/78492726-1-32084825".
        $url = sprintf(
            'https://%s/recursos/v1/boleta.electronica.envio/%d-%s-%s',
            $apiHost, $emisor['rut'], $emisor['dv'], rawurlencode($trackId)
        );

        $resp = self::curl($url, 'GET', null, [
            'Cookie: TOKEN=' . self::token(),
            'User-Agent: ' . self::USER_AGENT,
            'accept: application/json',
        ]);

        $j = json_decode($resp['body'], true);
        if (is_array($j)) {
            return [
                'estado' => (string) ($j['estado'] ?? $j['status'] ?? ''),
                'glosa' => (string) ($j['glosa'] ?? $j['descripcion'] ?? ''),
                'crudo' => $resp['body'],
            ];
        }
        return ['estado' => '', 'glosa' => self::resumen($resp['body']), 'crudo' => $resp['body']];
    }

    /** @param string|array|null $cuerpo string = cuerpo crudo; array = multipart nativo de curl */
    private static function curl(string $url, string $metodo, string|array|null $cuerpo = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $metodo,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($cuerpo !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // Sin curl_close(): deprecado y sin efecto desde PHP 8.0; en 8.5 emite un
        // aviso que corrompía la respuesta JSON. El recurso se libera solo.

        if ($body === false) {
            throw new \RuntimeException('Error de red hablando con el SII: ' . $err);
        }
        return ['status' => $status, 'body' => (string) $body];
    }

    /** Recorta la respuesta del SII para que quepa en un mensaje de error. */
    private static function resumen(string $body): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? ''), 0, 300);
    }

    /**
     * Igual que resumen() pero CONSERVA las etiquetas: para diagnosticar el
     * formato exacto de la respuesta (¿XML clásico? ¿JSON? ¿código de ESTADO?)
     * cuando el parseo esperado no calza.
     */
    private static function preview(string $body): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', $body) ?? ''), 0, 400);
    }

    /**
     * Aplana el XML firmado a una sola línea, quitando SÓLO el espaciado entre
     * tags (`>   <` → `><`). Necesario porque el SII exige el getToken en una
     * línea: su `getCertificado` se cae con XML indentado ("elemento Certificate
     * no existe").
     *
     * Es SEGURO respecto de la firma: derafu calcula digest y SignatureValue con
     * C14N() sobre el DOM (que NO tiene nodos de espacios, porque la entrada era
     * compacta) y sólo `saveXml()` agrega la indentación cosmética al final.
     * Quitarla devuelve el documento EXACTAMENTE a la forma que se firmó. Los
     * saltos internos del base64 (X509Certificate, Modulus, SignatureValue) no se
     * tocan: no están entre `>` y `<`, y son parte de lo que se canonicalizó.
     */
    private static function aplanar(string $xml): string
    {
        return preg_replace('/>\s+</', '><', trim($xml)) ?? $xml;
    }

}
