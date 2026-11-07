<?php
/**
 * Exportar Inmunoglobulina Humana a Excel - Formato oficial
 */
session_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

$rol = $_SESSION['rol'];
$anio = (int)($_GET['anio'] ?? date('Y'));
$mesFilter = isset($_GET['mes']) ? (int)$_GET['mes'] : null;
$jurFilter = $_GET['jurisdiccion'] ?? null;

$meses = [1=>'ENERO',2=>'FEBRERO',3=>'MARZO',4=>'ABRIL',5=>'MAYO',6=>'JUNIO',
          7=>'JULIO',8=>'AGOSTO',9=>'SEPTIEMBRE',10=>'OCTUBRE',11=>'NOVIEMBRE',12=>'DICIEMBRE'];

$jurisdiccionesLista = jurisdicciones();

// Obtener datos
$sql = "SELECT * FROM inmunoglobulina_humana WHERE anio = ?";
$params = [$anio];
$types = 'i';

if ($jurFilter && $rol === 'admin') {
    $sql .= " AND jurisdiccion = ?";
    $params[] = $jurFilter;
    $types .= 's';
}
if ($rol !== 'admin') {
    $sql .= " AND jurisdiccion = ?";
    $params[] = $_SESSION['jurisdiccion'];
    $types .= 's';
}

$sql .= " ORDER BY mes, jurisdiccion";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$datos = [];
while ($row = mysqli_fetch_assoc($result)) {
    $datos[$row['mes']][$row['jurisdiccion']] = $row;
}

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setCreator('Sistema Zoonosis')->setTitle('Movimiento de Inmunoglobulina Humana ' . $anio);

$jurisdiccionesMostrar = $jurFilter ? [$jurFilter] : $jurisdiccionesLista;

if ($mesFilter) {
    $trimestres = [[$mesFilter]];
    $nombresTrimestres = [$meses[$mesFilter]];
} else {
    $trimestres = [[1,2,3], [4,5,6], [7,8,9], [10,11,12]];
    $nombresTrimestres = ['ENE-FEB-MAR', 'ABR-MAY-JUN', 'JUL-AGO-SEP', 'OCT-NOV-DIC'];
}

// Colores
$colorEncabezado = 'D9E8FB';    // Azul claro
$colorSubEncabezado = 'E8F4E8'; // Verde claro
$colorTotal = 'FFF2CC';          // Amarillo claro
$colorTitulo = 'B8D4E8';         // Azul medio

$sheetIndex = 0;
foreach ($trimestres as $triIndex => $mesesTrimestre) {
    if ($sheetIndex === 0) {
        $sheet = $spreadsheet->getActiveSheet();
    } else {
        $sheet = $spreadsheet->createSheet();
    }
    $sheet->setTitle($nombresTrimestres[$triIndex]);
    $sheetIndex++;
    
    // Anchos de columna MUY amplios
    $sheet->getColumnDimension('A')->setWidth(2);
    $sheet->getColumnDimension('B')->setWidth(24);  // JURISDICCION
    $sheet->getColumnDimension('C')->setWidth(12);  // BIOLOGICO
    $sheet->getColumnDimension('D')->setWidth(18);  // PRESENTACION
    $sheet->getColumnDimension('E')->setWidth(12);  // DOSIS
    $sheet->getColumnDimension('F')->setWidth(12);  // LOTE
    $sheet->getColumnDimension('G')->setWidth(16);  // FECHA CADUCIDAD
    $sheet->getColumnDimension('H')->setWidth(12);  // DOSIS
    $sheet->getColumnDimension('I')->setWidth(12);  // LOTE
    $sheet->getColumnDimension('J')->setWidth(16);  // FECHA CADUCIDAD
    $sheet->getColumnDimension('K')->setWidth(16);  // TOTAL DE DOSIS
    $sheet->getColumnDimension('L')->setWidth(18);  // DOSIS ADMINISTRADAS
    $sheet->getColumnDimension('M')->setWidth(16);  // DESPERDICIO
    $sheet->getColumnDimension('N')->setWidth(12);  // DOSIS
    $sheet->getColumnDimension('O')->setWidth(12);  // LOTE
    $sheet->getColumnDimension('P')->setWidth(16);  // FECHA CADUCIDAD
    
    $rowActual = 1;
    
    foreach ($mesesTrimestre as $numMes) {
        $nombreMes = $meses[$numMes];
        $row = $rowActual;
        
        // LOGO IZQUIERDO
        if (file_exists(__DIR__ . '/img/edo_mex.jpg')) {
            $logo1 = new Drawing();
            $logo1->setPath(__DIR__ . '/img/edo_mex.jpg');
            $logo1->setCoordinates("B{$row}");
            $logo1->setHeight(55);
            $logo1->setWorksheet($sheet);
        }
        
        // ENCABEZADOS INSTITUCIONALES
        $sheet->mergeCells("D{$row}:M{$row}");
        $sheet->setCellValue("D{$row}", 'INSTITUTO DE SALUD DEL ESTADO DE MEXICO');
        $sheet->getStyle("D{$row}")->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // LOGO DERECHO
        if (file_exists(__DIR__ . '/img/conc.jpg')) {
            $logo2 = new Drawing();
            $logo2->setPath(__DIR__ . '/img/conc.jpg');
            $logo2->setCoordinates("O{$row}");
            $logo2->setHeight(55);
            $logo2->setWorksheet($sheet);
        }
        
        $row++;
        $sheet->mergeCells("D{$row}:M{$row}");
        $sheet->setCellValue("D{$row}", 'SUBDIRECCION DE PREVENCION Y CONTROL DE ENFERMEDADES');
        $sheet->getStyle("D{$row}")->getFont()->setSize(9);
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row++;
        $sheet->mergeCells("D{$row}:M{$row}");
        $sheet->setCellValue("D{$row}", 'DEPARTAMENTO DE ZOONOSIS Y VECTORES');
        $sheet->getStyle("D{$row}")->getFont()->setSize(9);
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row++;
        $sheet->mergeCells("D{$row}:M{$row}");
        $sheet->setCellValue("D{$row}", 'PROGRAMA DE PREVENCIÓN Y CONTROL DE LA RABIA');
        $sheet->getStyle("D{$row}")->getFont()->setSize(9);
        $sheet->getStyle("D{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row += 2;
        
        // TÍTULO PRINCIPAL con color
        $sheet->mergeCells("B{$row}:P{$row}");
        $sheet->setCellValue("B{$row}", "INFORME DE MOVIMIENTO DE BIOLÓGICO (INMUNOGLOBULINA HUMANA ANTIRRÁBICA) {$anio}");
        $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$row}:P{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorTitulo);
        $sheet->getRowDimension($row)->setRowHeight(22);
        
        $row++;
        
        // SUBTÍTULO Y MES
        $sheet->mergeCells("B{$row}:L{$row}");
        $sheet->setCellValue("B{$row}", 'EXISTENCIA DE MOVIMIENTO DE INMUNOGLOBULINA HUMANA ANTIRRÁBICA EN LAS 19 JURISDICCIONES SANITARIAS');
        $sheet->mergeCells("M{$row}:P{$row}");
        $sheet->setCellValue("M{$row}", "MES REPORTADO: {$nombreMes}");
        $sheet->getStyle("B{$row}:P{$row}")->getFont()->setBold(true)->setSize(8);
        $sheet->getStyle("B{$row}:P{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getRowDimension($row)->setRowHeight(18);
        
        $row++;
        $headerRow1 = $row;
        
        // ENCABEZADOS FILA 1 con color
        $sheet->setCellValue("B{$row}", 'JURISDICCION');
        $sheet->setCellValue("C{$row}", 'BIOLOGICO');
        $sheet->setCellValue("D{$row}", 'PRESENTACION');
        $sheet->mergeCells("E{$row}:G{$row}");
        $sheet->setCellValue("E{$row}", 'EXISTENCIA AL INICIO DEL MES');
        $sheet->mergeCells("H{$row}:J{$row}");
        $sheet->setCellValue("H{$row}", 'DOSIS RECIBIDAS EN EL MES');
        $sheet->setCellValue("K{$row}", 'TOTAL DE DOSIS EN EL MES');
        $sheet->setCellValue("L{$row}", 'DOSIS ADMINISTRADAS');
        $sheet->setCellValue("M{$row}", 'DESPERDICIO EN DOSIS');
        $sheet->mergeCells("N{$row}:P{$row}");
        $sheet->setCellValue("N{$row}", 'EXISTENCIA AL FIN DEL MES');
        
        $sheet->getStyle("B{$row}:P{$row}")->getFont()->setBold(true)->setSize(8);
        $sheet->getStyle("B{$row}:P{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$row}:P{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorEncabezado);
        $sheet->getRowDimension($row)->setRowHeight(45);
        
        $row++;
        
        // ENCABEZADOS FILA 2 con color
        $sheet->setCellValue("E{$row}", 'DOSIS');
        $sheet->setCellValue("F{$row}", 'LOTE');
        $sheet->setCellValue("G{$row}", 'FECHA CADUCIDAD');
        $sheet->setCellValue("H{$row}", 'DOSIS');
        $sheet->setCellValue("I{$row}", 'LOTE');
        $sheet->setCellValue("J{$row}", 'FECHA CADUCIDAD');
        $sheet->setCellValue("N{$row}", 'DOSIS');
        $sheet->setCellValue("O{$row}", 'LOTE');
        $sheet->setCellValue("P{$row}", 'FECHA CADUCIDAD');
        
        $sheet->getStyle("B{$row}:P{$row}")->getFont()->setBold(true)->setSize(8);
        $sheet->getStyle("B{$row}:P{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$row}:P{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorSubEncabezado);
        $sheet->getRowDimension($row)->setRowHeight(20);
        
        // Merge verticales
        $sheet->mergeCells("B{$headerRow1}:B{$row}");
        $sheet->mergeCells("C{$headerRow1}:C{$row}");
        $sheet->mergeCells("D{$headerRow1}:D{$row}");
        $sheet->mergeCells("K{$headerRow1}:K{$row}");
        $sheet->mergeCells("L{$headerRow1}:L{$row}");
        $sheet->mergeCells("M{$headerRow1}:M{$row}");
        
        $row++;
        $dataStartRow = $row;

        // DATOS
        foreach ($jurisdiccionesMostrar as $jur) {
            $d = $datos[$numMes][$jur] ?? null;
            
            // HEREDAR EXISTENCIA INICIAL DEL MES ANTERIOR (igual que en la página web)
            $existenciaInicioDosis = '';
            $existenciaInicioLote = '';
            $existenciaInicioCad = '';
            
            if ($numMes > 1 && isset($datos[$numMes - 1][$jur])) {
                // Usar existencia final del mes anterior
                $mesAnterior = $datos[$numMes - 1][$jur];
                $existenciaInicioDosis = $mesAnterior['existencia_fin_dosis'] ?? '';
                $existenciaInicioLote = $mesAnterior['existencia_fin_lote'] ?? '';
                $existenciaInicioCad = $mesAnterior['existencia_fin_caducidad'] ?? '';
            } elseif ($d) {
                // Primer mes o sin mes anterior: usar lo guardado
                $existenciaInicioDosis = $d['existencia_inicio_dosis'] ?? '';
                $existenciaInicioLote = $d['existencia_inicio_lote'] ?? '';
                $existenciaInicioCad = $d['existencia_inicio_caducidad'] ?? '';
            }
            
            $sheet->setCellValue("B{$row}", $jur);
            $sheet->setCellValue("C{$row}", $d['biologico'] ?? 'INMUNOGLOBULINA HUMANA ANTIRRÁBICA');
            $sheet->setCellValue("D{$row}", $d['presentacion'] ?? 'FRASCO');
            $sheet->setCellValue("E{$row}", $existenciaInicioDosis);
            $sheet->setCellValue("F{$row}", $existenciaInicioLote);
            $sheet->setCellValue("G{$row}", $existenciaInicioCad);
            $sheet->setCellValue("H{$row}", $d['dosis_recibidas'] ?? '');
            $sheet->setCellValue("I{$row}", $d['dosis_recibidas_lote'] ?? '');
            $sheet->setCellValue("J{$row}", $d['dosis_recibidas_caducidad'] ?? '');
            if ($d) {
                $sheet->setCellValue("K{$row}", "=E{$row}+H{$row}");
            }
            $sheet->setCellValue("L{$row}", $d['dosis_administradas'] ?? '');
            $sheet->setCellValue("M{$row}", $d['desperdicio_dosis'] ?? '');
            if ($d) {
                $sheet->setCellValue("N{$row}", "=K{$row}-L{$row}-M{$row}");
            }
            $sheet->setCellValue("O{$row}", $d['existencia_fin_lote'] ?? '');
            $sheet->setCellValue("P{$row}", $d['existencia_fin_caducidad'] ?? '');
            
            $sheet->getStyle("B{$row}:P{$row}")->getFont()->setSize(8);
            $sheet->getStyle("B{$row}:P{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            
            $row++;
        }
        
        $dataEndRow = $row - 1;
        
        // TOTAL con color amarillo
        $sheet->setCellValue("B{$row}", 'TOTAL');
        $sheet->setCellValue("E{$row}", "=SUM(E{$dataStartRow}:E{$dataEndRow})");
        $sheet->setCellValue("H{$row}", "=SUM(H{$dataStartRow}:H{$dataEndRow})");
        $sheet->setCellValue("K{$row}", "=SUM(K{$dataStartRow}:K{$dataEndRow})");
        $sheet->setCellValue("L{$row}", "=SUM(L{$dataStartRow}:L{$dataEndRow})");
        $sheet->setCellValue("M{$row}", "=SUM(M{$dataStartRow}:M{$dataEndRow})");
        $sheet->setCellValue("N{$row}", "=SUM(N{$dataStartRow}:N{$dataEndRow})");
        
        $sheet->getStyle("B{$row}:P{$row}")->getFont()->setBold(true)->setSize(8);
        $sheet->getStyle("B{$row}:P{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$row}:P{$row}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorTotal);
        
        $row += 2;
        
        // OBSERVACIONES DEL ADMINISTRADOR (solo esta en la hoja principal)
        $sheet->setCellValue("B{$row}", 'OBSERVACIONES:');
        $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setSize(9);
        
        $row++;
        $sheet->mergeCells("B{$row}:P{$row}");
        $obsTextoAdmin = '';
        foreach ($jurisdiccionesMostrar as $jur) {
            if (isset($datos[$numMes][$jur]['observaciones']) && !empty(trim($datos[$numMes][$jur]['observaciones']))) {
                $obsTextoAdmin = $datos[$numMes][$jur]['observaciones'];
                break;
            }
        }
        $sheet->setCellValue("B{$row}", $obsTextoAdmin);
        $sheet->getStyle("B{$row}:P{$row}")->getFont()->setSize(8);
        $sheet->getStyle("B{$row}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("B{$row}:P{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension($row)->setRowHeight(40);
        
        $row += 3;
        
        // FIRMA
        $sheet->mergeCells("B{$row}:I{$row}");
        $sheet->setCellValue("B{$row}", '________________________________________________________________________________');
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        $row++;
        $sheet->mergeCells("B{$row}:I{$row}");
        $sheet->setCellValue("B{$row}", 'NOMBRE Y FIRMA DEL NORMATIVO DE ZOONOSIS Y VECTORES');
        $sheet->getStyle("B{$row}")->getFont()->setBold(true)->setSize(8);
        $sheet->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        
        // Autoajustar ancho de columnas basado en contenido
        foreach(range('B','P') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        $rowActual = $row + 5;
    }
}

$filename = 'Inmunoglobulina_Humana_' . $anio . ($mesFilter ? '_' . $meses[$mesFilter] : '') . '.xlsx';

// ========== HOJAS DE OBSERVACIONES POR MES ==========
$mesesParaObs = $mesFilter ? [$mesFilter] : range(1, 12);

foreach ($mesesParaObs as $numMes) {
    $nombreMes = $meses[$numMes];
    
    // Verificar si hay observaciones en este mes
    $hayObservaciones = false;
    foreach ($jurisdiccionesLista as $jur) {
        if (isset($datos[$numMes][$jur]['observaciones_capturista']) && !empty(trim($datos[$numMes][$jur]['observaciones_capturista']))) {
            $hayObservaciones = true;
            break;
        }
    }
    
    if (!$hayObservaciones) continue;
    
    // Crear hoja de observaciones para este mes
    $sheetObs = $spreadsheet->createSheet();
    $sheetObs->setTitle('Obs ' . substr($nombreMes, 0, 3));
    
    // Anchos de columna
    $sheetObs->getColumnDimension('A')->setWidth(3);
    $sheetObs->getColumnDimension('B')->setWidth(25);
    $sheetObs->getColumnDimension('C')->setWidth(80);
    
    // Título
    $sheetObs->mergeCells('B1:C1');
    $sheetObs->setCellValue('B1', 'OBSERVACIONES - ' . $nombreMes . ' ' . $anio);
    $sheetObs->getStyle('B1')->getFont()->setBold(true)->setSize(12);
    $sheetObs->getStyle('B1:C1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorTitulo);
    $sheetObs->getStyle('B1:C1')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheetObs->getStyle('B1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheetObs->getRowDimension(1)->setRowHeight(25);
    
    // Encabezados
    $sheetObs->setCellValue('B2', 'JURISDICCIÓN');
    $sheetObs->setCellValue('C2', 'OBSERVACIONES');
    $sheetObs->getStyle('B2:C2')->getFont()->setBold(true)->setSize(9);
    $sheetObs->getStyle('B2:C2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($colorEncabezado);
    $sheetObs->getStyle('B2:C2')->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    $sheetObs->getStyle('B2:C2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    
    $rowObs = 3;
    
    // Observaciones de cada jurisdicción
    foreach ($jurisdiccionesLista as $jur) {
        if (isset($datos[$numMes][$jur]['observaciones_capturista']) && !empty(trim($datos[$numMes][$jur]['observaciones_capturista']))) {
            $obsCapturista = trim($datos[$numMes][$jur]['observaciones_capturista']);
            
            $sheetObs->setCellValue("B{$rowObs}", $jur);
            $sheetObs->setCellValue("C{$rowObs}", $obsCapturista);
            
            $sheetObs->getStyle("B{$rowObs}:C{$rowObs}")->getFont()->setSize(9);
            $sheetObs->getStyle("B{$rowObs}:C{$rowObs}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            $sheetObs->getStyle("B{$rowObs}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
            $sheetObs->getStyle("C{$rowObs}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP)->setHorizontal(Alignment::HORIZONTAL_LEFT);
            
            // Altura según longitud del texto
            $lineas = max(1, ceil(strlen($obsCapturista) / 90));
            $sheetObs->getRowDimension($rowObs)->setRowHeight(max(20, $lineas * 15));
            
            $rowObs++;
        }
    }
}

// Volver a la primera hoja como activa
$spreadsheet->setActiveSheetIndex(0);

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;
