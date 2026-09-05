<?php

declare(strict_types=1);

ini_set('display_errors', '0');

// Emisión de un LOTE de boletas (varios DTE) en UN SOLO sobre EnvioBOLETA.
//
// Existe porque la certificación del SII exige que el Set de Pruebas se envíe
// "en solo un archivo (sobre)" (instructivo del set). El flujo normal de regsi
// (una boleta por pago) sigue usando /api/emitir.
//
// POST { caf_xml, fecha?, documentos: [ { folio, detalle: [ {nombre, cantidad,
//        precio, exento?, unidad?} ], referencia?: {tipo, razon} } ] }
//  200 → { ok, track_id, documentos: [ {folio, total, totales} ] }

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;
use Clari\DteService\Certificado;
use Clari\DteService\Emisor;
use Clari\DteService\Lib;
use Clari\DteService\Refirmador;
use Clari\DteService\Sii;

Auth::exigirToken();
header('Content-Type: application/json; charset=utf-8');

function salir(int $codigo, array $datos): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $ambiente = Ambiente::activo();
} catch (\Throwable $e) {
    salir(503, ['error' => $e->getMessage()]);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
$cafXml = (string) ($in['caf_xml'] ?? '');
$fecha = (string) ($in['fecha'] ?? date('Y-m-d'));
$docsIn = is_array($in['documentos'] ?? null) ? $in['documentos'] : [];

if ($cafXml === '' || $docsIn === []) {
    salir(422, ['error' => 'faltan caf_xml y documentos[]']);
}

try {
    $app = Lib::app();
    $billing = $app->getPackageRegistry()->getBillingPackage();
    $biller = $billing->getDocumentComponent();
    $certificado = Certificado::cargar();
    $caf = $billing->getIdentifierComponent()->getCafLoaderWorker()->load($cafXml);

    $fragmentos = [];
    $resumen = [];
    foreach ($docsIn as $d) {
        $folio = (int) ($d['folio'] ?? 0);
        if ($folio < $caf->getFolioDesde() || $folio > $caf->getFolioHasta()) {
            salir(422, ['error' => "folio $folio fuera del rango del CAF"]);
        }
        $detalle = [];
        foreach ((array) ($d['detalle'] ?? []) as $it) {
            $linea = [
                // iso1: el SII rechaza caracteres fuera de ISO-8859-1 (LPX-00217).
                'NmbItem' => Refirmador::iso1(trim((string) ($it['nombre'] ?? ''))),
                'QtyItem' => (float) ($it['cantidad'] ?? 1),
                'PrcItem' => (float) ($it['precio'] ?? 0),
            ];
            if (trim((string) ($it['unidad'] ?? '')) !== '') {
                $linea['UnmdItem'] = trim((string) $it['unidad']);
            }
            if (!empty($it['exento'])) {
                $linea['IndExe'] = 1;
            }
            $detalle[] = $linea;
        }
        $datos = [
            'Encabezado' => [
                'IdDoc' => ['TipoDTE' => 39, 'Folio' => $folio, 'FchEmis' => $fecha],
                'Emisor' => Emisor::datos(),
                'Receptor' => [
                    'RUTRecep' => '66666666-6',
                    'RznSocRecep' => 'Sin RUT',
                    'DirRecep' => 'Sin dirección',
                    'CmnaRecep' => 'Santiago',
                ],
            ],
            'Detalle' => $detalle,
        ];
        if (is_array($d['referencia'] ?? null) && trim((string) ($d['referencia']['razon'] ?? '')) !== '') {
            $datos['Referencia'] = [[
                'NroLinRef' => 1,
                'CodRef' => trim((string) ($d['referencia']['tipo'] ?? 'SET')),
                'RazonRef' => trim((string) $d['referencia']['razon']),
            ]];
        }

        // bill() completo; si el timbrado de la librería falla, fallback a armar
        // sin timbre/firma (Refirmador los construye). Ver api/emitir.php.
        try {
            $bolsa = $biller->bill($datos, $caf, $certificado);
        } catch (\Throwable $e) {
            $bolsa = $biller->bill($datos);
        }
        $dteXml = $bolsa->getXmlDocument()->setEncoding('ISO-8859-1')->saveXml();
        $fragmentos[] = Refirmador::firmarDte($dteXml, $certificado, $cafXml);

        $totales = [];
        $xmlStr = $bolsa->getXmlDocument()->saveXml();
        foreach (['MntNeto', 'IVA', 'MntExe', 'MntTotal'] as $campo) {
            if ($xmlStr && preg_match('/<' . $campo . '>(\d+)<\/' . $campo . '>/', $xmlStr, $mm)) {
                $totales[$campo] = (int) $mm[1];
            }
        }
        $resumen[] = ['folio' => $folio, 'total' => $totales['MntTotal'] ?? null, 'totales' => $totales];
    }

    // Sobre único con TODOS los DTE (mismo template probado de /api/emitir).
    $emisorRut = Emisor::datos()['RUTEmisor'];
    $rutEnvia = strtoupper($certificado->getId());
    $caratula = '<Caratula version="1.0">'
        . '<RutEmisor>' . $emisorRut . '</RutEmisor>'
        . '<RutEnvia>' . $rutEnvia . '</RutEnvia>'
        . '<RutReceptor>60803000-K</RutReceptor>'
        . '<FchResol>' . Emisor::fechaResolucion() . '</FchResol>'
        . '<NroResol>' . Emisor::numeroResolucion() . '</NroResol>'
        . '<TmstFirmaEnv>' . date('Y-m-d\TH:i:s') . '</TmstFirmaEnv>'
        . '<SubTotDTE><TpoDTE>39</TpoDTE><NroDTE>' . count($fragmentos) . '</NroDTE></SubTotDTE>'
        . '</Caratula>';
    $xmlSobre = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n"
        . '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        . ' xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioBOLETA_v11.xsd" version="1.0">'
        . '<SetDTE ID="SetDoc">' . $caratula . implode('', $fragmentos) . '</SetDTE>'
        . Refirmador::esqueletoFirma($certificado, 'SetDoc')
        . '</EnvioBOLETA>';
    $xmlSobre = Refirmador::firmarSobre($xmlSobre, $certificado);

    if (!empty($in['debug_sobre'])) {
        salir(200, ['ok' => true, 'debug' => 'NO enviado', 'sobre_b64' => base64_encode($xmlSobre)]);
    }

    $trackId = Sii::enviarBoleta($xmlSobre);

    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'track_id' => $trackId,
        'documentos' => $resumen,
    ]);
} catch (\Throwable $e) {
    salir(502, ['error' => 'no se pudo emitir el lote', 'detalle' => $e->getMessage()]);
}
