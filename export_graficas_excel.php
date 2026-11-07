<?php
/**
 * Exportación de Gráficas a Excel
 * - Hoja 1: Resumen por Jurisdicción (gráfica + tabla)
 * - Hojas siguientes: Detalle por jurisdicción (2 municipios por fila, máx 6 por hoja)
 * - Total aproximado: 30-35 hojas
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

// Configuración
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$anioActual = $config_sistema['anio_activo'] ?? date('Y');

$rol = $_SESSION['rol'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';

$tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
$tiposNombres = [
    'vacunados' => 'Vacunados', 'esterilizados' => 'Esteriliz.', 
    'control' => 'Control', 'muestras' => 'Muestras',
    'agredidos' => 'Agredidos', 'tratamientos' => 'Tratamient.'
];

$todas_jurisdicciones = jurisdicciones();

// ========== RECOPILAR DATOS ==========
$datosCompletos = [];

foreach ($todas_jurisdicciones as $juris) {
    if ($rol !== 'admin' && $juris !== $jurisdiccion_usuario) continue;
    
    $datosCompletos[$juris] = ['lugares' => [], 'promedio' => 0, 'totalMeta' => 0, 'totalAvance' => 0];
    
    $sql = "SELECT DISTINCT municipio FROM registros WHERE jurisdiccion = ? AND anio = ? ORDER BY municipio";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'si', $juris, $anioActual);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $totalProm = 0;
    $countLugares = 0;
    
    while ($row = mysqli_fetch_assoc($result)) {
        $muni = $row['municipio'];
        $esHospital = preg_match('/^H\./i', $muni) || preg_match('/HOSPITAL/i', $muni);
        $tiposValidos = $esHospital ? ['agredidos', 'tratamientos'] : $tipos;
        
        $datosCompletos[$juris]['lugares'][$muni] = [
            'es_hospital' => $esHospital,
            'registros' => [],
            'promedio' => 0,
            'totalMeta' => 0,
            'totalAvance' => 0
        ];
        
        $sumPct = 0;
        $countPct = 0;
        
        foreach ($tiposValidos as $tipo) {
            $sqlTipo = "SELECT meta_anual, 
                        (COALESCE(ene,0)+COALESCE(feb,0)+COALESCE(mar,0)+COALESCE(abr,0)+
                         COALESCE(may,0)+COALESCE(jun,0)+COALESCE(jul,0)+COALESCE(ago,0)+
                         COALESCE(sep,0)+COALESCE(oct,0)+COALESCE(nov,0)+COALESCE(dic,0)) as avance
                        FROM registros WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
            $stmtTipo = mysqli_prepare($conn, $sqlTipo);
            mysqli_stmt_bind_param($stmtTipo, 'sssi', $juris, $muni, $tipo, $anioActual);
            mysqli_stmt_execute($stmtTipo);
            $resTipo = mysqli_stmt_get_result($stmtTipo);
            $rowTipo = mysqli_fetch_assoc($resTipo);
            
            $meta = (int)($rowTipo['meta_anual'] ?? 0);
            $avance = (int)($rowTipo['avance'] ?? 0);
            $pct = $meta > 0 ? round(($avance / $meta) * 100, 1) : 0;
            
            $datosCompletos[$juris]['lugares'][$muni]['registros'][$tipo] = [
                'meta' => $meta, 'avance' => $avance, 'porcentaje' => $pct
            ];
            
            $datosCompletos[$juris]['lugares'][$muni]['totalMeta'] += $meta;
            $datosCompletos[$juris]['lugares'][$muni]['totalAvance'] += $avance;
            $datosCompletos[$juris]['totalMeta'] += $meta;
            $datosCompletos[$juris]['totalAvance'] += $avance;
            
            if ($meta > 0) { $sumPct += $pct; $countPct++; }
            mysqli_stmt_close($stmtTipo);
        }
        
        $promLugar = $countPct > 0 ? round($sumPct / $countPct, 1) : 0;
        $datosCompletos[$juris]['lugares'][$muni]['promedio'] = $promLugar;
        $totalProm += $promLugar;
        $countLugares++;
    }
    mysqli_stmt_close($stmt);
    
    $datosCompletos[$juris]['promedio'] = $countLugares > 0 ? round($totalProm / $countLugares, 1) : 0;
}

// ========== ESTILOS ==========
$colores = [
    'titulo_bg' => '2C3E50', 'titulo_text' => 'FFFFFF',
    'header_bg' => '3498DB', 'header_text' => 'FFFFFF',
    'muni_bg' => '3498DB', 'hosp_bg' => 'E67E22',
    'subheader_bg' => 'ECF0F1',
    'verde_bg' => 'D5F4E6', 'verde_text' => '27AE60',
    'amarillo_bg' => 'FEF9E7', 'amarillo_text' => 'F39C12',
    'rojo_bg' => 'FDEDEC', 'rojo_text' => 'E74C3C',
    'borde' => 'BDC3C7'
];

$borderThin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => $colores['borde']]]]];

function getColorEstado($p, $t = 'text') {
    global $colores;
    if ($p >= 90) return $colores['verde_' . $t];
    if ($p >= 70) return $colores['amarillo_' . $t];
    return $colores['rojo_' . $t];
}

function getEstado($p) {
    if ($p >= 90) return 'EXCELENTE';
    if ($p >= 70) return 'REGULAR';
    return 'EN RIESGO';
}

// ========== CREAR EXCEL ==========
$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator('Sistema Zoonosis')->setTitle('Gráficas ' . $anioActual);
$chartNum = 0;


// ========== HOJA 1: RESUMEN POR JURISDICCIÓN ==========
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Resumen');

// Anchos
$sheet->getColumnDimension('A')->setWidth(2);
$sheet->getColumnDimension('B')->setWidth(18);
$sheet->getColumnDimension('C')->setWidth(8);
$sheet->getColumnDimension('D')->setWidth(3);
$sheet->getColumnDimension('E')->setWidth(25);
$sheet->getColumnDimension('F')->setWidth(12);
$sheet->getColumnDimension('G')->setWidth(14);

// Título
$sheet->mergeCells('B2:G2');
$sheet->setCellValue('B2', 'RESUMEN POR JURISDICCIÓN');
$sheet->getStyle('B2:G2')->applyFromArray([
    'font' => ['bold' => true, 'size' => 18, 'color' => ['rgb' => $colores['titulo_text']]],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['titulo_bg']]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
]);
$sheet->getRowDimension(2)->setRowHeight(35);

// Subtítulo
$sheet->mergeCells('B3:G3');
$sheet->setCellValue('B3', 'Año: ' . $anioActual . ' | Generado: ' . date('d/m/Y H:i'));
$sheet->getStyle('B3:G3')->applyFromArray([
    'font' => ['size' => 10, 'color' => ['rgb' => '7F8C8D']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'ECF0F1']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);

// Datos para gráfica (columnas B-C, ocultas visualmente pero usadas por gráfica)
$dataRow = 5;
$dataStartRow = $dataRow;
foreach ($datosCompletos as $juris => $datos) {
    $sheet->setCellValue('B' . $dataRow, $juris);
    $sheet->setCellValue('C' . $dataRow, $datos['promedio']);
    $dataRow++;
}
$dataEndRow = $dataRow - 1;
$numJuris = count($datosCompletos);

// Crear gráfica de barras horizontales
$chartNum++;
$labels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Resumen!$B$' . $dataStartRow . ':$B$' . $dataEndRow, null, $numJuris)];
$values = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Resumen!$C$' . $dataStartRow . ':$C$' . $dataEndRow, null, $numJuris)];

$series = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, range(0, 0), [], $labels, $values);
$series->setPlotDirection(DataSeries::DIRECTION_BAR);

$plotArea = new PlotArea(null, [$series]);
$legend = new Legend(Legend::POSITION_BOTTOM, null, false);
$title = new Title('% Avance por Jurisdicción');

$chart = new Chart('chart_resumen', $title, $legend, $plotArea, true, DataSeries::EMPTY_AS_GAP, null, null);
$chart->setTopLeftPosition('B' . ($dataEndRow + 2));
$chart->setBottomRightPosition('C' . ($dataEndRow + 2 + $numJuris + 2));
$sheet->addChart($chart);

// Tabla de resumen (al lado derecho)
$tableStartRow = $dataStartRow;
$sheet->setCellValue('E' . ($tableStartRow - 1), 'JURISDICCIÓN');
$sheet->setCellValue('F' . ($tableStartRow - 1), '% LOGRO');
$sheet->setCellValue('G' . ($tableStartRow - 1), 'ESTADO');
$sheet->getStyle('E' . ($tableStartRow - 1) . ':G' . ($tableStartRow - 1))->applyFromArray([
    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => $colores['header_text']]],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['header_bg']]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
]);
$sheet->getStyle('E' . ($tableStartRow - 1) . ':G' . ($tableStartRow - 1))->applyFromArray($borderThin);

$tRow = $tableStartRow;
foreach ($datosCompletos as $juris => $datos) {
    $prom = $datos['promedio'];
    $sheet->setCellValue('E' . $tRow, $juris);
    $sheet->setCellValue('F' . $tRow, number_format($prom, 1) . '%');
    $sheet->setCellValue('G' . $tRow, getEstado($prom));
    
    $sheet->getStyle('E' . $tRow . ':G' . $tRow)->applyFromArray($borderThin);
    $sheet->getStyle('F' . $tRow . ':G' . $tRow)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => getColorEstado($prom, 'text')]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ]);
    $sheet->getStyle('G' . $tRow)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => getColorEstado($prom, 'bg')]]
    ]);
    $tRow++;
}


// ========== HOJAS POR JURISDICCIÓN (máx 6 municipios por hoja) ==========
$MUNICIPIOS_POR_HOJA = 6; // 3 filas de 2 municipios

foreach ($datosCompletos as $juris => $datosJuris) {
    
    // Ordenar lugares: municipios primero, hospitales después
    $lugares = $datosJuris['lugares'];
    uasort($lugares, fn($a, $b) => $a['es_hospital'] <=> $b['es_hospital']);
    $lugaresArray = array_keys($lugares);
    $totalLugares = count($lugaresArray);
    
    // Dividir en chunks de 6
    $chunks = array_chunk($lugaresArray, $MUNICIPIOS_POR_HOJA);
    $numChunks = count($chunks);
    
    foreach ($chunks as $chunkIdx => $chunk) {
        
        // Crear hoja
        $sheet = $spreadsheet->createSheet();
        $sheetSuffix = $numChunks > 1 ? ' (' . ($chunkIdx + 1) . ')' : '';
        $sheetName = substr(preg_replace('/[\\\\\/\?\*\[\]:\'"]/', '', $juris), 0, 28) . $sheetSuffix;
        $sheet->setTitle($sheetName);
        
        // Anchos de columnas
        $sheet->getColumnDimension('A')->setWidth(2);
        $sheet->getColumnDimension('B')->setWidth(13);
        $sheet->getColumnDimension('C')->setWidth(7);
        $sheet->getColumnDimension('D')->setWidth(9);
        $sheet->getColumnDimension('E')->setWidth(9);
        $sheet->getColumnDimension('F')->setWidth(9);
        $sheet->getColumnDimension('G')->setWidth(2); // Separador
        $sheet->getColumnDimension('H')->setWidth(13);
        $sheet->getColumnDimension('I')->setWidth(7);
        $sheet->getColumnDimension('J')->setWidth(9);
        $sheet->getColumnDimension('K')->setWidth(9);
        $sheet->getColumnDimension('L')->setWidth(9);
        
        // Título jurisdicción
        $sheet->mergeCells('B2:L2');
        $sheet->setCellValue('B2', $juris);
        $sheet->getStyle('B2:L2')->applyFromArray([
            'font' => ['bold' => true, 'size' => 16, 'color' => ['rgb' => $colores['titulo_text']]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['titulo_bg']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);
        
        // Info
        $numMunis = count(array_filter($datosJuris['lugares'], fn($l) => !$l['es_hospital']));
        $numHosp = count(array_filter($datosJuris['lugares'], fn($l) => $l['es_hospital']));
        $promJuris = $datosJuris['promedio'];
        $paginaInfo = $numChunks > 1 ? " | Página " . ($chunkIdx + 1) . " de $numChunks" : "";
        
        $sheet->mergeCells('B3:L3');
        $sheet->setCellValue('B3', "$numMunis Mpios | $numHosp Hosp | Prom: " . number_format($promJuris, 1) . "% " . getEstado($promJuris) . $paginaInfo);
        $sheet->getStyle('B3:L3')->applyFromArray([
            'font' => ['size' => 10, 'bold' => true, 'color' => ['rgb' => getColorEstado($promJuris, 'text')]],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => getColorEstado($promJuris, 'bg')]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);
        
        $currentRow = 5;
        
        // Procesar de 2 en 2
        for ($i = 0; $i < count($chunk); $i += 2) {
            
            // ===== MUNICIPIO IZQUIERDO =====
            $muni1 = $chunk[$i];
            $info1 = $lugares[$muni1];
            $esHosp1 = $info1['es_hospital'];
            $prom1 = $info1['promedio'];
            $regs1 = $info1['registros'];
            $numRegs1 = count($regs1);
            
            $bgColor1 = $esHosp1 ? $colores['hosp_bg'] : $colores['muni_bg'];
            $icon1 = $esHosp1 ? '🏥' : '📍';
            
            // Título
            $sheet->mergeCells('B' . $currentRow . ':D' . $currentRow);
            $sheet->setCellValue('B' . $currentRow, $icon1 . ' ' . substr($muni1, 0, 18));
            $sheet->getStyle('B' . $currentRow . ':D' . $currentRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor1]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1]
            ]);
            
            $sheet->mergeCells('E' . $currentRow . ':F' . $currentRow);
            $sheet->setCellValue('E' . $currentRow, number_format($prom1, 1) . '% ' . getEstado($prom1));
            $sheet->getStyle('E' . $currentRow . ':F' . $currentRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => getColorEstado($prom1, 'text')]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => getColorEstado($prom1, 'bg')]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            
            // ===== MUNICIPIO DERECHO =====
            if ($i + 1 < count($chunk)) {
                $muni2 = $chunk[$i + 1];
                $info2 = $lugares[$muni2];
                $esHosp2 = $info2['es_hospital'];
                $prom2 = $info2['promedio'];
                $regs2 = $info2['registros'];
                $numRegs2 = count($regs2);
                
                $bgColor2 = $esHosp2 ? $colores['hosp_bg'] : $colores['muni_bg'];
                $icon2 = $esHosp2 ? '🏥' : '📍';
                
                $sheet->mergeCells('H' . $currentRow . ':J' . $currentRow);
                $sheet->setCellValue('H' . $currentRow, $icon2 . ' ' . substr($muni2, 0, 18));
                $sheet->getStyle('H' . $currentRow . ':J' . $currentRow)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor2]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'indent' => 1]
                ]);
                
                $sheet->mergeCells('K' . $currentRow . ':L' . $currentRow);
                $sheet->setCellValue('K' . $currentRow, number_format($prom2, 1) . '% ' . getEstado($prom2));
                $sheet->getStyle('K' . $currentRow . ':L' . $currentRow)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => getColorEstado($prom2, 'text')]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => getColorEstado($prom2, 'bg')]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);
            }
            
            $currentRow++;
            
            // Headers tablas
            $headers = ['TIPO', '%', 'META', 'AVANCE'];
            $cols1 = ['B', 'C', 'D', 'E'];
            $cols2 = ['H', 'I', 'J', 'K'];
            
            foreach ($headers as $idx => $h) {
                $sheet->setCellValue($cols1[$idx] . $currentRow, $h);
                if ($i + 1 < count($chunk)) {
                    $sheet->setCellValue($cols2[$idx] . $currentRow, $h);
                }
            }
            $sheet->getStyle('B' . $currentRow . ':E' . $currentRow)->applyFromArray([
                'font' => ['bold' => true, 'size' => 8],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['subheader_bg']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $sheet->getStyle('B' . $currentRow . ':E' . $currentRow)->applyFromArray($borderThin);
            
            if ($i + 1 < count($chunk)) {
                $sheet->getStyle('H' . $currentRow . ':K' . $currentRow)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 8],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['subheader_bg']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);
                $sheet->getStyle('H' . $currentRow . ':K' . $currentRow)->applyFromArray($borderThin);
            }
            
            $currentRow++;
            $dataStartRow1 = $currentRow;
            
            // Datos tabla 1
            foreach ($regs1 as $tipo => $reg) {
                $sheet->setCellValue('B' . $currentRow, $tiposNombres[$tipo]);
                $sheet->setCellValue('C' . $currentRow, $reg['porcentaje']);
                $sheet->setCellValue('D' . $currentRow, $reg['meta']);
                $sheet->setCellValue('E' . $currentRow, $reg['avance']);
                $sheet->getStyle('B' . $currentRow . ':E' . $currentRow)->applyFromArray($borderThin);
                $sheet->getStyle('C' . $currentRow . ':E' . $currentRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle('C' . $currentRow)->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => getColorEstado($reg['porcentaje'], 'text')]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => getColorEstado($reg['porcentaje'], 'bg')]]
                ]);
                $currentRow++;
            }
            $dataEndRow1 = $currentRow - 1;
            
            // Datos tabla 2
            if ($i + 1 < count($chunk)) {
                $dataRow2 = $dataStartRow1;
                foreach ($regs2 as $tipo => $reg) {
                    $sheet->setCellValue('H' . $dataRow2, $tiposNombres[$tipo]);
                    $sheet->setCellValue('I' . $dataRow2, $reg['porcentaje']);
                    $sheet->setCellValue('J' . $dataRow2, $reg['meta']);
                    $sheet->setCellValue('K' . $dataRow2, $reg['avance']);
                    $sheet->getStyle('H' . $dataRow2 . ':K' . $dataRow2)->applyFromArray($borderThin);
                    $sheet->getStyle('I' . $dataRow2 . ':K' . $dataRow2)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                    $sheet->getStyle('I' . $dataRow2)->applyFromArray([
                        'font' => ['bold' => true, 'color' => ['rgb' => getColorEstado($reg['porcentaje'], 'text')]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => getColorEstado($reg['porcentaje'], 'bg')]]
                    ]);
                    $dataRow2++;
                }
                $dataEndRow2 = $dataRow2 - 1;
            }

            
            // Gráficas debajo de las tablas
            $chartRow = $currentRow + 1;
            
            // Gráfica 1
            $chartNum++;
            $labels1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'" . '!$B$' . $dataStartRow1 . ':$B$' . $dataEndRow1, null, $numRegs1)];
            $values1 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'" . '!$C$' . $dataStartRow1 . ':$C$' . $dataEndRow1, null, $numRegs1)];
            
            $series1 = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, range(0, 0), [], $labels1, $values1);
            $series1->setPlotDirection(DataSeries::DIRECTION_BAR);
            
            $plot1 = new PlotArea(null, [$series1]);
            $chart1 = new Chart('chart_' . $chartNum, null, null, $plot1, true, DataSeries::EMPTY_AS_GAP, null, null);
            $chart1->setTopLeftPosition('B' . $chartRow);
            $chart1->setBottomRightPosition('F' . ($chartRow + 5));
            $sheet->addChart($chart1);
            
            // Gráfica 2
            if ($i + 1 < count($chunk)) {
                $chartNum++;
                $labels2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'" . $sheetName . "'" . '!$H$' . $dataStartRow1 . ':$H$' . $dataEndRow2, null, $numRegs2)];
                $values2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'" . $sheetName . "'" . '!$I$' . $dataStartRow1 . ':$I$' . $dataEndRow2, null, $numRegs2)];
                
                $series2 = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, range(0, 0), [], $labels2, $values2);
                $series2->setPlotDirection(DataSeries::DIRECTION_BAR);
                
                $plot2 = new PlotArea(null, [$series2]);
                $chart2 = new Chart('chart_' . $chartNum, null, null, $plot2, true, DataSeries::EMPTY_AS_GAP, null, null);
                $chart2->setTopLeftPosition('H' . $chartRow);
                $chart2->setBottomRightPosition('L' . ($chartRow + 5));
                $sheet->addChart($chart2);
            }
            
            // Espacio para siguiente par
            $currentRow = $chartRow + 7;
        }
    }
}

// ========== DESCARGAR ==========
$filename = 'Graficas_Zoonosis_' . $anioActual . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->setIncludeCharts(true);
$writer->save('php://output');
exit;
