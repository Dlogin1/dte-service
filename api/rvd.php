<?php

declare(strict_types=1);

// API JSON: los warnings/deprecaciones de PHP van a los logs de Vercel, nunca al
// cuerpo de la respuesta (corromperian el JSON). Ver Sii.php (curl_close 8.5).
ini_set('display_errors', '0');

// Resumen de Ventas Diarias (RVD) — el reporte diario obligatorio para quien
// emite boletas electrónicas.
//
// OJO CON EL NOMBRE: antes se llamaba RCOF (Reporte de Consumo de Folios). Esa
// obligación terminó en agosto de 2022 y la reemplazó el RVD. El XML mantiene
// el tag raíz <ConsumoFolios> por compatibilidad con el esquema del SII, así
// que en la documentación vieja aparecen como si fueran lo mismo.
//
// POST { fecha: 'YYYY-MM-DD', detalle: [{folio, monto, fecha}] }
//  200 → { ok, track_id, documentos }
//  204 → no hubo ventas ese día (no se reporta nada)

require __DIR__ . '/../vendor/autoload.php';

use Clari\DteService\Ambiente;
use Clari\DteService\Auth;
use Clari\DteService\Certificado;
use Clari\DteService\Emisor;
use Clari\DteService\Sii;
use Clari\DteService\Lib;
use libredte\lib\Core\Package\Billing\Component\Book\Enum\TipoLibro;
use libredte\lib\Core\Package\Billing\Component\Book\Support\BookBag;
use libredte\lib\Core\Package\Billing\Component\TradingParties\Factory\EmisorFactory;

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

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    salir(422, ['error' => 'falta fecha (YYYY-MM-DD)']);
}
if (!$docs) {
    // Día sin ventas: el SII no espera un reporte vacío.
    salir(204, ['ok' => true, 'documentos' => 0]);
}

try {
    $emisorDatos = Emisor::datos();

    // Detalle por documento. En la boleta el monto es BRUTO (IVA incluido):
    // se desglosa acá porque el RVD pide neto e IVA por separado.
    $detalle = [];
    foreach ($docs as $d) {
        $total = (int) ($d['monto'] ?? 0);
        if ($total < 1 || !isset($d['folio'])) {
            continue;
        }
        $neto = (int) round($total / 1.19);
        $detalle[] = [
            'TpoDoc' => 39,
            'NroDoc' => (int) $d['folio'],
            'TasaImp' => 19,
            'FchDoc' => (string) ($d['fecha'] ?? $fecha),
            'MntNeto' => $neto,
            'MntIVA' => $total - $neto,
            'MntTotal' => $total,
        ];
    }
    if (!$detalle) {
        salir(204, ['ok' => true, 'documentos' => 0]);
    }

    $app = Lib::app();
    $book = $app->getPackageRegistry()->getBillingPackage()->getBookComponent();

    $emisor = (new EmisorFactory())->create([
        'rut' => $emisorDatos['RUTEmisor'],
        'razon_social' => $emisorDatos['RznSoc'],
        'autorizacion_dte' => [
            'fecha_resolucion' => Emisor::fechaResolucion(),
            'numero_resolucion' => Emisor::numeroResolucion(),
        ],
    ]);

    $bolsa = new BookBag(
        tipo: TipoLibro::RVD,
        caratula: ['FchInicio' => $fecha, 'FchFinal' => $fecha],
        detalle: $detalle,
        certificate: Certificado::cargar(),
        emisor: $emisor,
    );

    $bolsa = $book->getLoaderWorker()->load($bolsa);
    $libro = $book->getBuilderWorker()->build($bolsa);

    // Validar antes de gastar una llamada al SII.
    $validador = $book->getValidatorWorker();
    $validador->validateSchema($bolsa);
    $resultado = $validador->validateSignature($bolsa);
    if (!$resultado->isValid()) {
        salir(422, ['error' => 'la firma del RVD no valida']);
    }

    $xml = $libro->getXmlDocument()->setEncoding('ISO-8859-1')->saveXml();
    $trackId = Sii::enviarLibroBoletas($xml);

    salir(200, [
        'ok' => true,
        'ambiente' => $ambiente,
        'fecha' => $fecha,
        'documentos' => count($detalle),
        'track_id' => $trackId,
    ]);
} catch (\Throwable $e) {
    salir(502, ['error' => 'no se pudo enviar el RVD', 'detalle' => $e->getMessage()]);
}
