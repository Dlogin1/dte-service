<?php

declare(strict_types=1);

namespace Clari\DteService;

use Derafu\Certificate\Contract\CertificateInterface;

/**
 * Re-firma el sobre EnvioBOLETA con criptografía ESTÁNDAR (DOM C14N +
 * openssl_sign), reemplazando las firmas que trae de LibreDTE.
 *
 * POR QUÉ EXISTE: el generador de firmas de LibreDTE lib-core dev-master
 * produce DigestValue que NO corresponden a ninguna serialización real del
 * documento exportado (se comprobó recomputando C14N inclusivo y exclusivo
 * sobre el sobre final: ninguno coincide). Sus propias validaciones pasan
 * porque generador y validador comparten la misma implementación, pero el SII
 * —que implementa C14N estándar— rechaza todo con RFR (Rechazado por Firma).
 *
 * La ESTRUCTURA de las firmas que arma LibreDTE está bien (Reference con URI al
 * ID correcto, KeyInfo con módulo/exponente/certificado, rsa-sha1): solo se
 * recalculan DigestValue y SignatureValue sobre el DOM FINAL, y el XML se envía
 * exactamente como quedó (sin reformatear después de firmar, o se invalida).
 *
 * Orden importante: primero la firma del DTE (Documento) y después la del sobre
 * (SetDTE), porque la segunda cubre a la primera.
 */
final class Refirmador
{
    public static function refirmar(string $xml, CertificateInterface $cert): string
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;   // el DOM debe ser EXACTAMENTE lo que se envía
        $doc->formatOutput = false;
        if (!$doc->loadXML($xml)) {
            throw new \RuntimeException('re-firma: el sobre no parsea');
        }
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $firmas = iterator_to_array($xp->query('//ds:Signature'));
        if (count($firmas) < 1) {
            throw new \RuntimeException('re-firma: el sobre no trae firmas');
        }
        // Las firmas cuyo padre NO es la raíz (la del DTE) van primero; la del
        // sobre (hija de EnvioBOLETA) al final, para que cubra a las demás.
        usort($firmas, function ($a, $b) use ($doc) {
            return (int) ($a->parentNode === $doc->documentElement)
                <=> (int) ($b->parentNode === $doc->documentElement);
        });

        foreach ($firmas as $sig) {
            self::recalcular($doc, $xp, $sig, $cert);
        }

        $salida = $doc->saveXML();
        if (!is_string($salida) || $salida === '') {
            throw new \RuntimeException('re-firma: no se pudo serializar');
        }
        return $salida;
    }

    private static function recalcular(
        \DOMDocument $doc,
        \DOMXPath $xp,
        \DOMElement $sig,
        CertificateInterface $cert
    ): void {
        $ref = $xp->query('.//ds:Reference', $sig)->item(0);
        if (!$ref instanceof \DOMElement) {
            throw new \RuntimeException('re-firma: firma sin Reference');
        }
        $id = ltrim($ref->getAttribute('URI'), '#');
        $nodo = $xp->query('//*[@ID=' . self::xq($id) . ']')->item(0);
        if (!$nodo instanceof \DOMElement) {
            throw new \RuntimeException("re-firma: no existe el nodo ID='$id'");
        }

        // 1) Digest del nodo referenciado: SHA1 sobre C14N inclusivo sin
        //    comentarios (lo que valida el SII; verificado con lxml).
        $digest = base64_encode(sha1($nodo->C14N(false, false), true));
        self::reemplazarTexto($doc, $xp, $sig, './/ds:DigestValue', $digest);

        // 2) Firmar el SignedInfo canonicalizado (ya con el digest nuevo).
        $si = $xp->query('.//ds:SignedInfo', $sig)->item(0);
        if (!$si instanceof \DOMElement) {
            throw new \RuntimeException('re-firma: firma sin SignedInfo');
        }
        $firma = '';
        if (!openssl_sign($si->C14N(false, false), $firma, $cert->getPrivateKey(), OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('re-firma: openssl_sign falló: ' . openssl_error_string());
        }
        self::reemplazarTexto($doc, $xp, $sig, './/ds:SignatureValue',
            trim(chunk_split(base64_encode($firma), 64, "\n")));

        // 3) Autoverificación: la firma recién puesta debe validar con la
        //    clave pública del certificado. Fail-closed: mejor no enviar que
        //    enviar una firma que el SII va a rechazar.
        if (openssl_verify($si->C14N(false, false), $firma, $cert->getPublicKey(), OPENSSL_ALGO_SHA1) !== 1) {
            throw new \RuntimeException('re-firma: la autoverificación falló');
        }
    }

    private static function reemplazarTexto(
        \DOMDocument $doc,
        \DOMXPath $xp,
        \DOMElement $ambito,
        string $ruta,
        string $texto
    ): void {
        $el = $xp->query($ruta, $ambito)->item(0);
        if (!$el instanceof \DOMElement) {
            throw new \RuntimeException("re-firma: falta $ruta");
        }
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($doc->createTextNode($texto));
    }

    /** Literal seguro para XPath (evita romper la consulta con comillas). */
    private static function xq(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
