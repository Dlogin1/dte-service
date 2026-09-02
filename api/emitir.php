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
$glosa = trim((string) ($in['glosa'] ?? 'Servicio regsi'));
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
    $receptor = [
        'RUTRecep' => strtoupper((string) ($r['rut'] ?? '66666666-6')),
        'RznSocRecep' => (string) ($r['razon_social'] ?? 'Sin RUT'),
        'DirRecep' => (string) ($r['direccion'] ?? 'Sin dirección'),
        'CmnaRecep' => (string) ($r['comuna'] ?? 'Santiago'),
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
                'NmbItem' => trim((string) ($it['nombre'] ?? '')),
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

    // 1) Armar, timbrar (CAF) y firmar (certificado) el documento.
    $bolsa = $biller->bill($datos, $caf, $certificado);

    // 2) Validar esquema y firma antes de gastar una llamada al SII.
    $validador = $biller->getValidatorWorker();
    $validador->validateSchema($bolsa);
    $resultado = $validador->validateSignature($bolsa);
    if (!$resultado->isValid()) {
        salir(422, ['error' => 'la firma del documento no valida']);
    }

    // 3) Sobre EnvioBOLETA (las boletas usan su propio tipo de sobre).
    $bolsa->getEmisor()->setAutorizacionDte(
        new AutorizacionDte(Emisor::fechaResolucion(), Emisor::numeroResolucion())
    );
    // No se fija el tipo de sobre a mano: addDocument() lo deduce del tipo de
    // documento (el 39 mapea a ENVIO_BOLETA) y rechaza mezclas incompatibles.
    $sobre = new DocumentEnvelope();
    $sobre->addDocument($bolsa);
    $sobre->setCertificate($certificado);
    if ($sobre->getTipoSobre() !== TipoSobre::ENVIO_BOLETA) {
        salir(422, ['error' => 'el documento no corresponde a un sobre de boletas']);
    }

    $despachador = $biller->getDispatcherWorker();
    $despachador->normalize($sobre);
    $despachador->validateSchema($sobre);

    // El SII exige ISO-8859-1 en los XML de DTE.
    $xmlSobre = $sobre->getXmlDocument()->setEncoding('ISO-8859-1')->saveXml();

    // Re-firmar con criptografía estándar: las firmas de LibreDTE dev-master no
    // validan contra el SII (RFR); ver src/Refirmador.php. Después de esto el
    // XML NO se puede modificar (ni reformatear) o la firma se invalida.
    $xmlSobre = \Clari\DteService\Refirmador::refirmar($xmlSobre, $certificado);

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
    $tedXml = null;
    if ($xmlStr && preg_match('/<TED[^>]*>.*?<\/TED>/s', $xmlStr, $m)) {
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
