<?php

declare(strict_types=1);

// API JSON: los warnings/deprecaciones de PHP van a los logs de Vercel, nunca al
// cuerpo de la respuesta (corromperian el JSON). Ver Sii.php (curl_close 8.5).
ini_set('display_errors', '0');

// Emisión de una boleta electrónica afecta (DTE tipo 39).
//
// regsi (Node) manda: folio ya reservado, el CAF, y los datos de la venta.
// Este servicio arma el documento con LibreDTE, lo timbra con el CAF, lo firma
// con el certificado, construye el sobre EnvioBOLETA y lo sube al SII.
//
// Devuelve el XML del documento y el TED (timbre) — NO un PDF: el PDF lo genera
// regsi con su pipeline de Chrome headless, que ya sabe poner QR (§4.2).
//
// POST { folio, caf_xml, monto, glosa?, receptor?:{rut,razon_social,direccion,comuna}, fecha? }
//  200 → { ok, track_id, folio, xml, ted, total }
//  422 → datos inválidos (no reintentar igual)
//  502 → el SII rechazó o no respondió (reintentable)

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;
use Clari\DteService\Certificado;
use Clari\DteService\Emisor;
use Clari\DteService\Sii;
use Clari\DteService\Lib;
use libredte\lib\Core\Package\Billing\Component\Document\Enum\TipoSobre;
use libredte\lib\Core\Package\Billing\Component\Document\Support\DocumentEnvelope;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Entity\AutorizacionDte;

Auth::exigirToken();
header('Content-Type: application/json; charset=utf-8');

function salir(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

// Guard de ambiente ANTES de cualquier lógica (§10.1): fail-closed.
try {
    $ambiente = Ambiente::activo();
} catch (\Throwable $e) {
    salir(503, ['error' => $e->getMessage()]);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) {
    salir(422, ['error' => 'cuerpo JSON inválido']);
}

$folio = (int) ($in['folio'] ?? 0);
$cafXml = (string) ($in['caf_xml'] ?? '');
$monto = (int) ($in['monto'] ?? 0);          // TOTAL con IVA incluido (boleta)
// iso1(): el SII rechaza el sobre entero si algún texto trae caracteres fuera
// de ISO-8859-1 (LPX-00217; nos pasó con un guión largo en la glosa).
$glosa = \Clari\DteService\Refirmador::iso1(trim((string) ($in['glosa'] ?? 'Servicio regsi')));
// Modo multi-ítem (set de pruebas / futura factura): arreglo `detalle`. Si no
// viene, se usa el modo simple monto+glosa (el flujo de pago real, un ítem).
$detalleIn = is_array($in['detalle'] ?? null) ? $in['detalle'] : [];

if ($folio < 1 || $cafXml === '' || ($monto < 1 && $detalleIn === [])) {
    salir(422, ['error' => 'faltan folio, caf_xml y (monto o detalle)']);
}

try {
    $app = Lib::app();
    $billing = $app->getPackageRegistry()->getBillingPackage();
    $biller = $billing->getDocumentComponent();

    $certificado = Certificado::cargar();
    $caf = $billing->getIdentifierComponent()->getCafLoaderWorker()->load($cafXml);

    // El folio lo reserva regsi (RPC siguiente_folio, atómica en Postgres).
    // Acá solo se comprueba que caiga dentro del rango autorizado del CAF:
    // usar un folio fuera de rango es rechazo seguro del SII.
    if ($folio < $caf->getFolioDesde() || $folio > $caf->getFolioHasta()) {
        salir(422, ['error' => sprintf(
            'folio %d fuera del rango del CAF (%d-%d)',
            $folio, $caf->getFolioDesde(), $caf->getFolioHasta()
        )]);
    }

    // Receptor: por defecto consumidor final (RUT genérico 66666666-6), que es
    // el caso de la boleta. Fase 3 (factura 33) usará datos reales de empresa.
    $r = is_array($in['receptor'] ?? null) ? $in['receptor'] : [];
    $iso1 = static fn ($s) => \Clari\DteService\Refirmador::iso1((string) $s);
    $receptor = [
        'RUTRecep' => strtoupper((string) ($r['rut'] ?? '66666666-6')),
        'RznSocRecep' => $iso1($r['razon_social'] ?? 'Sin RUT'),
        'DirRecep' => $iso1($r['direccion'] ?? 'Sin dirección'),
        'CmnaRecep' => $iso1($r['comuna'] ?? 'Santiago'),
    ];

    // Detalle. En la boleta 39 los precios son BRUTOS (IVA incluido): LibreDTE
    // calcula neto, IVA y exento al normalizar. Dos modos:
    //   · multi-ítem: arreglo `detalle` con {nombre, cantidad, precio, exento?,
    //     unidad?} — para el set de pruebas y facturación futura.
    //   · simple: un ítem con glosa + monto — el flujo de pago real.
    if ($detalleIn !== []) {
        $detalle = [];
        foreach ($detalleIn as $it) {
            $linea = [
                'NmbItem' => $iso1(trim((string) ($it['nombre'] ?? ''))),
                'QtyItem' => (float) ($it['cantidad'] ?? 1),
                'PrcItem' => (float) ($it['precio'] ?? 0),
            ];
            $u = trim((string) ($it['unidad'] ?? ''));
            if ($u !== '') {
                $linea['UnmdItem'] = $u;            // unidad de medida (ej. 'Kg')
            }
            if (!empty($it['exento'])) {
                $linea['IndExe'] = 1;               // ítem exento de IVA
            }
            $detalle[] = $linea;
        }
    } else {
        $detalle = [[
            'NmbItem' => $glosa,
            'QtyItem' => 1,
            'PrcItem' => $monto,
        ]];
    }

    $datos = [
        'Encabezado' => [
            'IdDoc' => [
                'TipoDTE' => 39,
                'Folio' => $folio,
                'FchEmis' => (string) ($in['fecha'] ?? date('Y-m-d')),
            ],
            'Emisor' => Emisor::datos(),
            'Receptor' => $receptor,
        ],
        'Detalle' => $detalle,
    ];

    // Referencia opcional. OJO: la Referencia de la BOLETA (EnvioBOLETA_v11.xsd)
    // NO es la de la factura — solo admite NroLinRef + CodRef + RazonRef (+
    // CodVndor/CodCaja). TpoDocRef/FolioRef no existen en este esquema y el
    // gateway del SII rechaza el envío con SCH-00001 "Invalid Schema Name".
    // En el set de pruebas: CodRef='SET', RazonRef='CASO-N'.
    if (is_array($in['referencia'] ?? null) && trim((string) ($in['referencia']['razon'] ?? '')) !== '') {
        $datos['Referencia'] = [[
            'NroLinRef' => 1,
            'CodRef' => trim((string) ($in['referencia']['tipo'] ?? 'SET')),
            'RazonRef' => trim((string) $in['referencia']['razon']),
        ]];
    }

    // 1) Armar el documento. Ideal: bill() completo (arma+timbra+firma). Si el
    //    timbrado de la librería falla (dev-master lo rompió en un rebuild:
    //    "No fue posible timbrar los datos"), FALLBACK: armar y normalizar SIN
    //    timbre ni firma — el Refirmador construye el TED y la firma él mismo
    //    (nuestro timbre es el certificado ante el SII de todos modos).
    try {
        $bolsa = $biller->bill($datos, $caf, $certificado);
    } catch (\Throwable $e) {
        $bolsa = $biller->bill($datos);
    }

    // 2) Validación de esquema/firma de la librería, ahora SOLO informativa: el
    //    control real es Refirmador (autoverificación openssl) + el veredicto
    //    del SII. En el fallback no hay firma de la librería que validar.
    try {
        $biller->getValidatorWorker()->validateSchema($bolsa);
    } catch (\Throwable $e) { /* informativo */ }

    // 3) DTE STANDALONE: se toma el documento de la bolsa, se RE-TIMBRA (FRMT
    //    con la clave del CAF) y se RE-FIRMA (xmldsig estándar), porque las
    //    firmas/timbre de LibreDTE dev-master no validan contra el SII
    //    (505 Firma DTE Incorrecta / RFR, comprobado). Ver src/Refirmador.php.
    $dteXml = $bolsa->getXmlDocument()->setEncoding('ISO-8859-1')->saveXml();
    $dteFirmado = \Clari\DteService\Refirmador::firmarDte($dteXml, $certificado, $cafXml);

    // 4) Sobre EnvioBOLETA por TEMPLATE, no por el DocumentEnvelope de la
    //    librería: su normalizador ponía el receptor del DTE en la carátula
    //    (rechazo "RUT Receptor Caratula Invalido") y mover nodos entre
    //    documentos con DOM corrompía los namespaces. El DTE ya firmado entra
    //    como STRING LITERAL (sus bytes no se tocan más), y la carátula va
    //    dirigida al SII (60803000-K), que es a quien se le envía el sobre.
    $emisorRut = Emisor::datos()['RUTEmisor'];
    $rutEnvia = strtoupper($certificado->getId());
    $caratula = '<Caratula version="1.0">'
        . '<RutEmisor>' . $emisorRut . '</RutEmisor>'
        . '<RutEnvia>' . $rutEnvia . '</RutEnvia>'
        . '<RutReceptor>60803000-K</RutReceptor>'
        . '<FchResol>' . Emisor::fechaResolucion() . '</FchResol>'
        . '<NroResol>' . Emisor::numeroResolucion() . '</NroResol>'
        . '<TmstFirmaEnv>' . date('Y-m-d\TH:i:s') . '</TmstFirmaEnv>'
        . '<SubTotDTE><TpoDTE>39</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>'
        . '</Caratula>';
    $xmlSobre = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n"
        . '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        . ' xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd" version="1.0">'
        . '<SetDTE ID="SetDoc">' . $caratula . $dteFirmado . '</SetDTE>'
        . \Clari\DteService\Refirmador::esqueletoFirma($certificado, 'SetDoc')
        . '</EnvioBOLETA>';
    $xmlSobre = \Clari\DteService\Refirmador::firmarSobre($xmlSobre, $certificado);

    // MODO DEBUG (diagnóstico del rechazo del gateway): devuelve el sobre EXACTO
    // que se subiría, aplanado igual que en el envío real, SIN llamar al SII.
    // Permite inspeccionar raíz/namespace/encoding y validar contra el XSD
    // oficial en local. Token-protegido como todo el endpoint; no gasta folio en
    // el SII (el folio local reservado se pierde, aceptable en certificación).
    if (!empty($in['debug_sobre'])) {
        // En base64: el sobre va en ISO-8859-1 y json_encode falla con bytes
        // no-UTF-8 (devolvía un cuerpo vacío). Base64 preserva los bytes exactos.
        salir(200, [
            'ok' => true,
            'debug' => 'sobre NO enviado al SII',
            'folio' => $folio,
            // Tal cual se envía: formateado por LibreDTE (el SII rechaza el
            // sobre aplanado con SCH-00001; ver Sii::subirXml).
            'sobre_b64' => base64_encode($xmlSobre),
            // Bytes exactos que ESTE PHP canonicaliza y digestea por firma:
            // para diffear contra un C14N de referencia desde afuera.
            'c14n_b64' => \Clari\DteService\Refirmador::c14nReferencias($xmlSobre),
        ]);
    }

    // 4) Subir al SII → track ID.
    $trackId = Sii::enviarBoleta($xmlSobre);

    $xmlDoc = $bolsa->getXmlDocument();
    $xmlStr = $xmlDoc ? $xmlDoc->saveXml() : null;

    // El TED debe viajar como XML LITERAL, no como estructura: el código de
    // barras PDF417 de la boleta contiene exactamente esos bytes, y quien lo
    // escanee revalida la firma sobre ellos. Serializarlo de otra forma (JSON,
    // por ejemplo) produciría un timbre que no valida.
    // Del SOBRE FINAL (re-timbrado), no de la bolsa: el FRMA del TED se
    // recalculó en el re-firmado y el de la bolsa quedó obsoleto.
    $tedXml = null;
    if (preg_match('/<TED[^>]*>.*?<\/TED>/s', $xmlSobre, $m)) {
        $tedXml = $m[0];
    } elseif ($xmlStr && preg_match('/<TED[^>]*>.*?<\/TED>/s', $xmlStr, $m)) {
        $tedXml = $m[0];
    }

    // Totales calculados por LibreDTE (del XML normalizado). Se devuelven para
    // poder VERIFICAR cada caso del set: que el neto/IVA/exento/total cuadren con
    // lo esperado antes de darlo por bueno (los precios entran BRUTOS).
    $totales = [];
    foreach (['MntNeto', 'IVA', 'MntExe', 'MntTotal'] as $campo) {
        if ($xmlStr && preg_match('/<' . $campo . '>(\d+)<\/' . $campo . '>/', $xmlStr, $mm)) {
            $totales[$campo] = (int) $mm[1];
        }
    }

    // json_encode FALLA (cuerpo vacío) si algún string trae bytes no-UTF-8
    // (los XML pueden venir en ISO-8859-1). Se normaliza antes de responder.
    $aUtf8 = static function (?string $s): ?string {
        if ($s === null || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        return mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    };

    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'folio' => $folio,
        'track_id' => $trackId,
        'total' => $totales['MntTotal'] ?? $monto,
        'totales' => $totales,
        // XML del documento (no del sobre): es lo que regsi archiva y de donde
        // saca los datos para dibujar el PDF.
        'xml' => $aUtf8($xmlStr),
        // Timbre electrónico como XML, listo para el PDF417.
        'ted_xml' => $aUtf8($tedXml),
    ]);
} catch (\Throwable $e) {
    // 502: problema hablando con el SII o al construir → regsi lo reintenta
    // con backoff (§9) sin invalidar el pago.
    salir(502, ['error' => 'no se pudo emitir', 'detalle' => $e->getMessage()]);
}
