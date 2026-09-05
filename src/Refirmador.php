<?php

declare(strict_types=1);

namespace Clari\DteService;

use Derafu\Certificate\Contract\CertificateInterface;

/**
 * Firma de DTE y sobre EnvioBOLETA con criptografía ESTÁNDAR (DOM C14N +
 * openssl), reemplazando las firmas/timbre que trae LibreDTE lib-core
 * dev-master, que NO validan contra el SII (RFR/505 comprobados).
 *
 * Principios aprendidos a golpes (no cambiar sin re-certificar):
 *  - El DTE se firma STANDALONE (documento propio, con su xmlns) y luego se
 *    inserta en el sobre como STRING LITERAL. Nunca extraer/insertar nodos con
 *    importNode entre documentos: libxml destroza los namespaces (aparece un
 *    prefijo falso `default:`) y la firma muere.
 *  - El timbre del TED es el elemento <FRMT> (hermano de <DD>). El <FRMA> de
 *    adentro es la firma DEL SII sobre el CAF: intocable.
 *  - FRMT = RSA-SHA1 sobre los bytes LITERALES de <DD>...</DD> (ISO-8859-1),
 *    con la clave privada del CAF (<RSASK>).
 *  - La firma del sobre se calcula EN SITU sobre el documento final; después
 *    de firmar no se puede reformatear nada.
 */
final class Refirmador
{
    private const DS = 'http://www.w3.org/2000/09/xmldsig#';

    /**
     * Re-timbra (FRMT) y re-firma un DTE STANDALONE. Entra y sale como string
     * ISO-8859-1; la salida NO trae declaración XML (lista para insertarse en
     * el sobre).
     */
    public static function firmarDte(string $dteXml, CertificateInterface $cert, string $cafXml): string
    {
        // El CAF dentro del DD debe ir BYTE-IDÉNTICO al archivo que emitió el
        // SII: su <FRMA> es la firma del SII sobre esos bytes exactos, y la
        // librería lo re-indenta al construir el TED (614B vs 406B) → el SII
        // reparaba con 510 "Firma Timbre Electrónico Incorrecta". Se reemplaza
        // por el bloque verbatim ANTES de timbrar, así el FRMT lo cubre.
        if (preg_match('~<CAF version=.*?</CAF>~s', $cafXml, $mc)) {
            $verbatim = $mc[0];
            $dteXml = (string) preg_replace_callback(
                '~<CAF version=.*?</CAF>~s',
                static fn () => $verbatim,
                $dteXml,
                1
            );
        }

        $doc = self::cargar($dteXml, 'dte');
        $xp = self::xpath($doc);

        // 0) LIMPIAR LOS FIXTURES QUE INYECTA LibreDTE dev-master. Su
        //    Fake{Emisor,Receptor}Provider rellena campos OPCIONALES con datos de
        //    fantasía (código de sucursal 123456, teléfono/correo inventados) y
        //    agrega una Referencia "Email receptor: ...@example.com". regsi NUNCA
        //    provee esos campos, y el matcher del set de certificación del SII se
        //    descoloca con ellos. Se eliminan; queda solo la referencia real (el
        //    CASO) y los datos verdaderos del emisor.
        //    (Ninguno está dentro del <DD>/TED, así que no afecta el timbre; la
        //    firma xmldsig se recalcula al final sobre el documento ya limpio.)
        foreach (iterator_to_array($xp->query(
            '//*[local-name()="Referencia"][*[local-name()="RazonRef" and starts-with(., "Email receptor")]]'
        )) as $ref) {
            $ref->parentNode->removeChild($ref);
        }
        foreach (['CdgSIISucur', 'Contacto', 'CorreoRecep', 'CorreoEmisor'] as $tag) {
            foreach (iterator_to_array($xp->query('//*[local-name()="' . $tag . '"]')) as $el) {
                $el->parentNode->removeChild($el);
            }
        }
        // Renumerar NroLinRef de las referencias que queden (por si quedó hueco).
        $n = 0;
        foreach ($xp->query('//*[local-name()="Referencia"]') as $ref) {
            $n++;
            $nl = $xp->query('.//*[local-name()="NroLinRef"]', $ref)->item(0);
            if ($nl instanceof \DOMElement) {
                self::texto($doc, $nl, (string) $n);
            }
        }

        // 1) TIMBRE PROPIO. No dependemos del timbrado de LibreDTE: su rama
        //    dev-master cambió y dejó de timbrar ("No fue posible timbrar los
        //    datos"), tumbando la emisión sin que cambiáramos código. Además su
        //    FRMT nunca validó contra el SII (reparo 510). Si el documento no
        //    trae TED, lo construimos acá según la norma; si lo trae, se
        //    re-timbra igual. En ambos casos el FRMT lo firmamos nosotros.
        if (!preg_match('~<RSASK>(.*?)</RSASK>~s', $cafXml, $m)) {
            throw new \RuntimeException('timbre: el CAF no trae RSASK');
        }
        $claveCaf = self::pemCanonico($m[1]);
        if (openssl_pkey_get_private($claveCaf) === false) {
            throw new \RuntimeException('timbre: la llave del CAF no decodifica: ' . openssl_error_string());
        }

        if ($xp->query('//*[local-name()="TED"]')->length === 0) {
            self::construirTed($doc, $xp, $cafXml);
        }

        $serial = self::iso($doc->saveXML());
        if (!preg_match('~<DD>.*?</DD>~s', $serial, $mdd)) {
            throw new \RuntimeException('timbre: no hay <DD> en el DTE');
        }
        // El SII NO verifica el FRMT sobre los bytes recibidos: reconstruye el
        // DD en su forma CANÓNICA — aplanado, sin espacios entre etiquetas
        // (los saltos DENTRO de un texto, como los base64, se conservan) — y
        // verifica contra eso. Igual que LibreDTE v2 (getFlattened). Firmar el
        // DD literal con el CAF verbatim (que trae saltos) daba reparo 510.
        $ddCanonico = (string) preg_replace('~>\s+<~', '><', $mdd[0]);
        $firmaTed = '';
        if (!openssl_sign($ddCanonico, $firmaTed, $claveCaf, OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('timbre: openssl_sign falló: ' . openssl_error_string());
        }
        $frmt = $xp->query('//*[local-name()="TED"]/*[local-name()="FRMT"]')->item(0);
        if (!$frmt instanceof \DOMElement) {
            throw new \RuntimeException('timbre: el TED no trae FRMT');
        }
        self::texto($doc, $frmt, base64_encode($firmaTed));

        // 2) Re-firmar la firma xmldsig del Documento (cubre el FRMT nuevo).
        //    Si la librería no firmó (fallback sin certificado), se garantiza el
        //    ID del Documento y se inserta el esqueleto de firma nuestro.
        $documento = $xp->query('//*[local-name()="Documento"]')->item(0);
        if ($documento instanceof \DOMElement && $documento->getAttribute('ID') === '') {
            $folioId = trim((string) ($xp->query('//*[local-name()="Folio"]')->item(0)->textContent ?? ''));
            $documento->setAttribute('ID', 'R' . preg_replace('~[^0-9Kk]~', '', $xp->query('//*[local-name()="RUTEmisor"]')->item(0)->textContent ?? '') . 'T39F' . $folioId);
        }
        $sig = $xp->query('//ds:Signature')->item(0);
        if (!$sig instanceof \DOMElement && $documento instanceof \DOMElement) {
            $frag = $doc->createDocumentFragment();
            $frag->appendXML(self::esqueletoFirma($cert, $documento->getAttribute('ID')));
            $documento->parentNode->appendChild($frag);
            $sig = $xp->query('//ds:Signature')->item(0);
        }
        if (!$sig instanceof \DOMElement) {
            throw new \RuntimeException('firma DTE: el DTE no trae Signature');
        }
        self::recalcular($doc, $xp, $sig, $cert);

        $salida = self::iso($doc->saveXML());

        // RED DE SEGURIDAD (fail-closed): nunca dejar salir un DTE con datos de
        // fantasía de LibreDTE. Si aparece alguno, se aborta la emisión en vez de
        // enviar basura al SII. Ver la limpieza de fixtures arriba.
        self::sinFixtures($salida);

        return trim((string) preg_replace('~^<\?xml[^>]*\?>\s*~', '', $salida));
    }

    /**
     * Construye el TED (timbre) del documento desde cero, según la norma del SII.
     *
     * Estructura: <TED version="1.0"><DD>…datos del documento…<CAF/>…<TSTED/></DD>
     * <FRMT algoritmo="SHA1withRSA"/></TED>, insertado como último hijo de
     * <Documento> (antes de TmstFirma, que se agrega después si falta).
     * El FRMT lo firma el llamador (firmarDte) sobre el <DD> aplanado.
     *
     * El CAF va VERBATIM (bytes exactos del archivo del SII): su <FRMA> es la
     * firma del SII sobre esos bytes y el emisor no puede reformatearlo.
     */
    private static function construirTed(\DOMDocument $doc, \DOMXPath $xp, string $cafXml): void
    {
        $g = static function (string $tag) use ($xp): string {
            $n = $xp->query('//*[local-name()="' . $tag . '"]')->item(0);
            return $n ? trim($n->textContent) : '';
        };

        $items = $xp->query('//*[local-name()="Detalle"]/*[local-name()="NmbItem"]');
        $it1 = $items->length ? trim($items->item(0)->textContent) : '';

        if (!preg_match('~<CAF version=.*?</CAF>~s', $cafXml, $mc)) {
            throw new \RuntimeException('timbre: el CAF no trae bloque <CAF>');
        }

        // El DD se arma como STRING (el CAF debe entrar verbatim, sin que el DOM
        // lo reserialice) y luego se importa como fragmento.
        $dd = '<DD>'
            . '<RE>' . $g('RUTEmisor') . '</RE>'
            . '<TD>' . $g('TipoDTE') . '</TD>'
            . '<F>' . $g('Folio') . '</F>'
            . '<FE>' . $g('FchEmis') . '</FE>'
            . '<RR>' . $g('RUTRecep') . '</RR>'
            . '<RSR>' . self::esc($g('RznSocRecep')) . '</RSR>'
            . '<MNT>' . $g('MntTotal') . '</MNT>'
            . '<IT1>' . self::esc($it1) . '</IT1>'
            . $mc[0]
            . '<TSTED>' . date('Y-m-d\TH:i:s') . '</TSTED>'
            . '</DD>';
        $ted = '<TED version="1.0">' . $dd . '<FRMT algoritmo="SHA1withRSA"></FRMT></TED>';

        $documento = $xp->query('//*[local-name()="Documento"]')->item(0);
        if (!$documento instanceof \DOMElement) {
            throw new \RuntimeException('timbre: no se encontró <Documento>');
        }

        $frag = $doc->createDocumentFragment();
        if (!@$frag->appendXML($ted)) {
            throw new \RuntimeException('timbre: no se pudo construir el TED');
        }
        $documento->appendChild($frag);

        // TmstFirma (obligatorio, va después del TED) si la librería no lo puso.
        if ($xp->query('//*[local-name()="TmstFirma"]')->length === 0) {
            $ts = $doc->createElement('TmstFirma', date('Y-m-d\TH:i:s'));
            $documento->appendChild($ts);
        }
    }

    /** Escapa texto para insertarlo en el XML del TED armado como string. */
    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'ISO-8859-1');
    }

    /**
     * Reconstruye un PEM en forma CANÓNICA (cabecera + base64 a 64 columnas con
     * \n + pie). El RSASK viene incrustado en el XML del CAF y puede traer CRLF
     * o espacios que el decodificador de OpenSSL 3 nuevo rechaza con
     * "DECODER routines::unsupported" (OpenSSL viejo lo toleraba; un rebuild del
     * contenedor rompió el timbre por esto).
     */
    private static function pemCanonico(string $pem): string
    {
        if (!preg_match('~-----BEGIN ([A-Z0-9 ]+)-----(.*?)-----END \1-----~s', $pem, $m)) {
            throw new \RuntimeException('timbre: RSASK sin estructura PEM');
        }
        $tipo = $m[1];
        $b64 = preg_replace('~\s+~', '', $m[2]);
        if ($b64 === '' || base64_decode($b64, true) === false) {
            throw new \RuntimeException('timbre: base64 del RSASK inválido');
        }
        return "-----BEGIN $tipo-----\n" . chunk_split($b64, 64, "\n") . "-----END $tipo-----\n";
    }

    /** Aborta si el XML contiene marcas conocidas de datos de fantasía de LibreDTE. */
    private static function sinFixtures(string $xml): void
    {
        foreach (['example.com', 'SASCO', 'correo.sii', 'correo.sasco', '32525575', '76192083'] as $sucio) {
            if (stripos($xml, $sucio) !== false) {
                throw new \RuntimeException('DTE con dato de fantasía de LibreDTE (' . $sucio . '): emisión abortada');
            }
        }
    }

    /** Firma EN SITU la firma raíz del sobre (Reference al SetDTE). */
    public static function firmarSobre(string $sobreXml, CertificateInterface $cert): string
    {
        $doc = self::cargar($sobreXml, 'sobre');
        $xp = self::xpath($doc);
        $sig = $xp->query('/*/ds:Signature')->item(0);
        if (!$sig instanceof \DOMElement) {
            throw new \RuntimeException('firma sobre: no hay Signature raíz');
        }
        self::recalcular($doc, $xp, $sig, $cert);
        return self::iso($doc->saveXML());
    }

    /**
     * Esqueleto de firma xmldsig (digest y firma vacíos; los completa
     * recalcular()). KeyInfo con la clave y el certificado reales.
     */
    public static function esqueletoFirma(CertificateInterface $cert, string $referencia): string
    {
        $mod = trim($cert->getModulus());
        $exp = trim($cert->getExponent());
        $x509 = trim($cert->getCertificate(true));
        return '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . '<SignedInfo>'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<Reference URI="#' . $referencia . '">'
            . '<Transforms><Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<DigestValue></DigestValue>'
            . '</Reference>'
            . '</SignedInfo>'
            . '<SignatureValue></SignatureValue>'
            . '<KeyInfo><KeyValue><RSAKeyValue>'
            . '<Modulus>' . $mod . '</Modulus>'
            . '<Exponent>' . $exp . '</Exponent>'
            . '</RSAKeyValue></KeyValue>'
            . '<X509Data><X509Certificate>' . $x509 . '</X509Certificate></X509Data>'
            . '</KeyInfo>'
            . '</Signature>';
    }

    /**
     * DIAGNÓSTICO: bytes C14N de cada nodo firmado, en el MISMO contexto en que
     * se firmó — el sobre in situ; el DTE desde sus bytes literales (es
     * autocontenido: lleva su propio xmlns). Para verificar digests desde afuera.
     *
     * @return array<string,string> id => base64(C14N)
     */
    public static function c14nReferencias(string $sobreXml): array
    {
        $salida = [];

        $doc = self::cargar($sobreXml, 'debug-sobre');
        $xp = self::xpath($doc);
        foreach ($xp->query('/*/ds:Signature/ds:SignedInfo/ds:Reference') as $ref) {
            $id = ltrim($ref->getAttribute('URI'), '#');
            $n = $xp->query('//*[@ID=' . self::xq($id) . ']')->item(0);
            if ($n instanceof \DOMElement) {
                $salida[$id] = base64_encode($n->C14N(false, false));
            }
        }

        if (preg_match('~<DTE\b.*?</DTE>~s', $sobreXml, $m)) {
            // El fragmento no trae declaración XML: sin ella loadXML asume
            // UTF-8 y los bytes ISO-8859-1 (í, ó...) rompen el parseo.
            $d2 = self::cargar('<?xml version="1.0" encoding="ISO-8859-1"?>' . $m[0], 'debug-dte');
            $x2 = self::xpath($d2);
            foreach ($x2->query('//ds:Signature/ds:SignedInfo/ds:Reference') as $ref) {
                $id = ltrim($ref->getAttribute('URI'), '#');
                $n = $x2->query('//*[@ID=' . self::xq($id) . ']')->item(0);
                if ($n instanceof \DOMElement) {
                    $salida[$id] = base64_encode($n->C14N(false, false));
                }
            }
        }
        return $salida;
    }

    /** Recalcula DigestValue y SignatureValue de UNA firma, y autoverifica. */
    private static function recalcular(
        \DOMDocument $doc,
        \DOMXPath $xp,
        \DOMElement $sig,
        CertificateInterface $cert
    ): void {
        $ref = $xp->query('.//ds:Reference', $sig)->item(0);
        if (!$ref instanceof \DOMElement) {
            throw new \RuntimeException('firma: sin Reference');
        }
        $id = ltrim($ref->getAttribute('URI'), '#');
        $nodo = $xp->query('//*[@ID=' . self::xq($id) . ']')->item(0);
        if (!$nodo instanceof \DOMElement) {
            throw new \RuntimeException("firma: no existe el nodo ID='$id'");
        }

        $digest = base64_encode(sha1($nodo->C14N(false, false), true));
        $dv = $xp->query('.//ds:DigestValue', $sig)->item(0);
        if (!$dv instanceof \DOMElement) {
            throw new \RuntimeException('firma: sin DigestValue');
        }
        self::texto($doc, $dv, $digest);

        $si = $xp->query('.//ds:SignedInfo', $sig)->item(0);
        if (!$si instanceof \DOMElement) {
            throw new \RuntimeException('firma: sin SignedInfo');
        }
        $firma = '';
        if (!openssl_sign($si->C14N(false, false), $firma, $cert->getPrivateKey(), OPENSSL_ALGO_SHA1)) {
            throw new \RuntimeException('firma: openssl_sign falló: ' . openssl_error_string());
        }
        $sv = $xp->query('.//ds:SignatureValue', $sig)->item(0);
        if (!$sv instanceof \DOMElement) {
            throw new \RuntimeException('firma: sin SignatureValue');
        }
        self::texto($doc, $sv, trim(chunk_split(base64_encode($firma), 64, "\n")));

        if (openssl_verify($si->C14N(false, false), $firma, $cert->getPublicKey(), OPENSSL_ALGO_SHA1) !== 1) {
            throw new \RuntimeException('firma: la autoverificación falló');
        }
    }

    // ── utilitarios ────────────────────────────────────────────────────────

    private static function cargar(string $xml, string $quien = ''): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        $errs = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) {
            $e = $errs[0] ?? null;
            throw new \RuntimeException(sprintf(
                'firma%s: el XML no parsea: %s (línea %s) | inicio: %s | largo: %d',
                $quien !== '' ? " [$quien]" : '',
                trim((string) ($e->message ?? '?')),
                (string) ($e->line ?? '?'),
                substr($xml, 0, 160),
                strlen($xml)
            ));
        }
        return $doc;
    }

    private static function xpath(\DOMDocument $doc): \DOMXPath
    {
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', self::DS);
        return $xp;
    }

    /** Garantiza bytes ISO-8859-1 (saveXML de docs sin encoding sale UTF-8). */
    private static function iso(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') && preg_match('~[\x80-\xFF]~', $s)
            ? mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8')
            : $s;
    }

    private static function texto(\DOMDocument $doc, \DOMElement $el, string $valor): void
    {
        while ($el->firstChild) {
            $el->removeChild($el->firstChild);
        }
        $el->appendChild($doc->createTextNode($valor));
    }

    private static function xq(string $s): string
    {
        return "'" . str_replace("'", "''", $s) . "'";
    }
}
