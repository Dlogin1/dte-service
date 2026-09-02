<?php

declare(strict_types=1);

ini_set('display_errors', '0');

// Resumen de Ventas Diarias (RVD / ex-RCOF) — reporte diario obligatorio del
// emisor de boletas. Construido por TEMPLATE según el formato oficial del SII
// (consumo_folios.pdf + ConsumoFolio_v10.xsd) y firmado con Refirmador, igual
// que el sobre de boletas (la vía probada). No usa el book builder de LibreDTE:
// su firma no valida contra el SII y su agregador ignora el monto exento.
//
// POST { fecha: 'YYYY-MM-DD', detalle: [{folio, monto, exento?}], sec? }
//  200 → { ok, track_id, documentos }
//  204 → día sin ventas (no se reporta)

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;
use Clari\DteService\Certificado;
use Clari\DteService\Emisor;
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
$fecha = (string) ($in['fecha'] ?? '');
$docs = is_array($in['detalle'] ?? null) ? $in['detalle'] : [];
$sec = max(1, (int) ($in['sec'] ?? 1));   // secuencia de envío (1; correcciones: 2, 3…)

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    salir(422, ['error' => 'falta fecha (YYYY-MM-DD)']);
}
if (!$docs) {
    salir(204, ['ok' => true, 'documentos' => 0]);
}

try {
    // Totales y folios. En la boleta el monto es BRUTO; `exento` es la parte
    // sin IVA (debe cuadrar con los DTE aceptados).
    $neto = 0; $iva = 0; $exento = 0; $total = 0; $folios = [];
    foreach ($docs as $d) {
        $t = (int) ($d['monto'] ?? 0);
        if ($t < 1 || !isset($d['folio'])) {
            continue;
        }
        $e = max(0, (int) ($d['exento'] ?? 0));
        $n = (int) round(($t - $e) / 1.19);
        $neto += $n;
        $iva += $t - $e - $n;
        $exento += $e;
        $total += $t;
        $folios[] = (int) $d['folio'];
    }
    if (!$folios) {
        salir(204, ['ok' => true, 'documentos' => 0]);
    }
    sort($folios);
    $folios = array_values(array_unique($folios));

    // Rangos CONSECUTIVOS de folios utilizados (exigencia del formato).
    $rangos = [];
    $ini = $fin = $folios[0];
    foreach (array_slice($folios, 1) as $f) {
        if ($f === $fin + 1) {
            $fin = $f;
        } else {
            $rangos[] = [$ini, $fin];
            $ini = $fin = $f;
        }
    }
    $rangos[] = [$ini, $fin];
    $rangosXml = '';
    foreach ($rangos as [$a, $b]) {
        $rangosXml .= '<RangoUtilizados><Inicial>' . $a . '</Inicial><Final>' . $b . '</Final></RangoUtilizados>';
    }

    $certificado = Certificado::cargar();
    $emisorRut = Emisor::datos()['RUTEmisor'];
    $rutEnvia = strtoupper($certificado->getId());
    $cant = count($folios);

    $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n"
        . '<ConsumoFolios xmlns="http://www.sii.cl/SiiDte"'
        . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
        . ' xsi:schemaLocation="http://www.sii.cl/SiiDte ConsumoFolio_v10.xsd" version="1.0">'
        . '<DocumentoConsumoFolios ID="RVD">'
        . '<Caratula version="1.0">'
        . '<RutEmisor>' . $emisorRut . '</RutEmisor>'
        . '<RutEnvia>' . $rutEnvia . '</RutEnvia>'
        . '<FchResol>' . Emisor::fechaResolucion() . '</FchResol>'
        . '<NroResol>' . Emisor::numeroResolucion() . '</NroResol>'
        . '<FchInicio>' . $fecha . '</FchInicio>'
        . '<FchFinal>' . $fecha . '</FchFinal>'
        . '<SecEnvio>' . $sec . '</SecEnvio>'
        . '<TmstFirmaEnv>' . date('Y-m-d\TH:i:s') . '</TmstFirmaEnv>'
        . '</Caratula>'
        . '<Resumen>'
        . '<TipoDocumento>39</TipoDocumento>'
        . '<MntNeto>' . $neto . '</MntNeto>'
        . '<MntIva>' . $iva . '</MntIva>'
        . '<TasaIVA>19</TasaIVA>'
        . '<MntExento>' . $exento . '</MntExento>'
        . '<MntTotal>' . $total . '</MntTotal>'
        . '<FoliosEmitidos>' . $cant . '</FoliosEmitidos>'
        . '<FoliosAnulados>0</FoliosAnulados>'
        . '<FoliosUtilizados>' . $cant . '</FoliosUtilizados>'
        . $rangosXml
        . '</Resumen>'
        . '</DocumentoConsumoFolios>'
        . Refirmador::esqueletoFirma($certificado, 'RVD')
        . '</ConsumoFolios>';

    $xml = Refirmador::firmarSobre($xml, $certificado);

    if (!empty($in['debug_sobre'])) {
        salir(200, ['ok' => true, 'debug' => 'NO enviado', 'sobre_b64' => base64_encode($xml)]);
    }

    $trackId = Sii::enviarLibroBoletas($xml);

    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'fecha' => $fecha,
        'documentos' => $cant,
        'track_id' => $trackId,
    ]);
} catch (\Throwable $e) {
    salir(502, ['error' => 'no se pudo enviar el RVD', 'detalle' => $e->getMessage()]);
}
