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
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', $ds);

        // 1) Cada DTE se re-firma en un documento AUTÓNOMO. El SII valida la
        //    firma del DTE extrayéndolo del sobre: ahí los xmlns heredados del
        //    sobre (xmlns:xsi) NO están en alcance, así que el C14N del
        //    Documento cambia. Firmar el DTE dentro del sobre produce
        //    "505 Firma DTE Incorrecta" aunque la firma sea consistente in
        //    situ. (Es exactamente lo que hacía LibreDTE v2: firmaba el DTE
        //    standalone y lo insertaba ya firmado.)
        foreach (iterator_to_array($xp->query('//*[local-name()="DTE"]')) as $dte) {
            $tmp = new \DOMDocument();
            $tmp->preserveWhiteSpace = true;
            $tmp->formatOutput = false;
            $tmp->appendChild($tmp->importNode($dte, true));
            $xpT = new \DOMXPath($tmp);
            $xpT->registerNamespace('ds', $ds);
            $sigT = $xpT->query('//ds:Signature')->item(0);
            if ($sigT instanceof \DOMElement) {
                self::recalcular($tmp, $xpT, $sigT, $cert);
            }
            $dte->parentNode->replaceChild($doc->importNode($tmp->documentElement, true), $dte);
        }

        // 2) La firma del SOBRE sí se calcula EN SITU (el SII valida el sobre
        //    tal como llega), y al final: cubre los DTE ya re-firmados.
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', $ds);
        $sigSobre = $xp->query('/*/ds:Signature')->item(0);
        if (!$sigSobre instanceof \DOMElement) {
            throw new \RuntimeException('re-firma: el sobre no trae firma propia');
        }
        self::recalcular($doc, $xp, $sigSobre, $cert);

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

    /**
     * DIAGNÓSTICO: los bytes EXACTOS que este PHP produce al canonicalizar cada
     * nodo referenciado por las firmas (los mismos que se digestean). Permite
     * comparar byte a byte contra un C14N de referencia (lxml) desde afuera.
     * Solo datos ya presentes en el sobre; nada sensible.
     *
     * @return array<string,string> id => base64(C14N del nodo)
     */
    public static function c14nReferencias(string $xml): array
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $doc->loadXML($xml);
        $ds = 'http://www.w3.org/2000/09/xmldsig#';
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', $ds);

        $salida = [];
        foreach ($xp->query('//ds:Signature/ds:SignedInfo/ds:Reference') as $ref) {
            $id = ltrim($ref->getAttribute('URI'), '#');
            $sig = $ref->parentNode->parentNode;   // Reference → SignedInfo → Signature
            if ($sig->parentNode === $doc->documentElement) {
                // Firma del sobre: contexto in situ.
                $nodo = $xp->query('//*[@ID=' . self::xq($id) . ']')->item(0);
            } else {
                // Firma de un DTE: mismo contexto AUTÓNOMO que usa refirmar().
                $dte = $sig->parentNode;
                $tmp = new \DOMDocument();
                $tmp->preserveWhiteSpace = true;
                $tmp->appendChild($tmp->importNode($dte, true));
                $xpT = new \DOMXPath($tmp);
                $nodo = $xpT->query('//*[@ID=' . self::xq($id) . ']')->item(0);
            }
            if ($nodo instanceof \DOMElement) {
                $salida[$id] = base64_encode($nodo->C14N(false, false));
            }
        }
        return $salida;
    }
}
