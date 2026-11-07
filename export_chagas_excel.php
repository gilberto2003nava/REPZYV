<?php

/**
 * EXPORTADOR EXCEL - CHAGAS Y ARTRÓPODOS
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(403);
    exit('No autorizado');
}

require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
$esAdmin = ($_SESSION['rol'] === 'admin');
$jurUsuario = $_SESSION['jurisdiccion'] ?? '';

$jurisdicciones = jurisdicciones();
$mesesNames = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

// Obtener datos
$datos = [];
$sql = "SELECT * FROM chagas_registro WHERE anio = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $anio);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $j = $row['jurisdiccion'];
    $m = (int)$row['mes'];
    if (!isset($datos[$j])) $datos[$j] = [];
    $datos[$j][$m] = $row;
}

// Remanentes año anterior
$anioAnt = $anio - 1;
$resAnt = $conn->query("SELECT jurisdiccion, remanente FROM chagas_registro WHERE anio = $anioAnt AND mes = 12");
$remanentesAnt = [];
while ($r = $resAnt->fetch_assoc()) $remanentesAnt[$r['jurisdiccion']] = (int)$r['remanente'];

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Estadística ' . $anio);

    // Estilos
    $headerColor = '334155';
    $subHeaderColor = '475569';
    $borderColor = '1e293b';

    // Función columna
    function getCol($n)
    {
        $l = '';
        while ($n > 0) {
            $n--;
            $l = chr(65 + ($n % 26)) . $l;
            $n = intval($n / 26);
        }
        return $l;
    }

    $row = 1;
    $numCols = 1 + (12 * 3) + 3; // Juris + 12*3 + Totales(3)
    $maxCol = getCol($numCols);

    // LOGOS
    if (file_exists(__DIR__ . '/img/edo_mex.jpg')) {
        $logo1 = new Drawing();
        $logo1->setPath(__DIR__ . '/img/edo_mex.jpg');
        $logo1->setCoordinates("B1");
        $logo1->setHeight(60);
        $logo1->setWorksheet($sheet);
    }
    if (file_exists(__DIR__ . '/img/conc.jpg')) {
        $logo2 = new Drawing();
        $logo2->setPath(__DIR__ . '/img/conc.jpg');
        $logo2->setCoordinates("AL1"); // Cerca del final (columna 38)
        $logo2->setHeight(60);
        $logo2->setWorksheet($sheet);
    }

    // Encabezados Institucionales
    $institucionales = [
        'INSTITUTO DE SALUD DEL ESTADO DE MÉXICO',
        'COORDINACIÓN DE SALUD',
        'DIRECCIÓN DE SERVICIOS DE SALUD',
        'SUBDIRECCIÓN DE PREVENCIÓN Y CONTROL DE ENFERMEDADES',
        'DEPARTAMENTO DE ZOONOSIS Y VECTORES'
    ];
    foreach ($institucionales as $h) {
        $sheet->mergeCells("A$row:$maxCol$row");
        $sheet->setCellValue("A$row", $h);
        $sheet->getStyle("A$row")->getFont()->setBold(true)->setSize(10);
        $sheet->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $row++;
    }

    $row++; // Espacio
    $sheet->mergeCells("A$row:$maxCol$row");
    $sheet->setCellValue("A$row", "ENFERMEDAD DE CHAGAS E INTOXICACIÓN POR ARTRÓPODOS - " . $anio);
    $sheet->getStyle("A$row")->applyFromArray([
        'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $headerColor]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ]);
    $row++;

    // Headers Tabla
    $startTable = $row;
    $sheet->setCellValue("A$row", "Jurisdicción");
    $sheet->mergeCells("A$row:A" . ($row + 1));

    $c = 2;
    for ($m = 1; $m <= 12; $m++) {
        $sheet->mergeCells(getCol($c) . $row . ':' . getCol($c + 2) . $row);
        $sheet->setCellValue(getCol($c) . $row, $mesesNames[$m]);
        $c += 3;
    }
    $sheet->mergeCells(getCol($c) . $row . ':' . getCol($c + 2) . $row);
    $sheet->setCellValue(getCol($c) . $row, "TOTALES ANUALES");
    $row++;

    $c = 2;
    for ($m = 1; $m <= 13; $m++) {
        $sheet->setCellValue(getCol($c) . $row, "Ent.");
        $sheet->setCellValue(getCol($c + 1) . $row, "Util.");
        $sheet->setCellValue(getCol($c + 2) . $row, "Rem.");
        $c += 3;
    }

    $sheet->getStyle("A$startTable:" . $maxCol . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $subHeaderColor]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ]);
    $row++;

    // Datos
    $gTotalEnt = 0;
    $gTotalUtil = 0;
    $gTotalRem = 0;
    foreach ($jurisdicciones as $jur) {
        if (!$esAdmin && $jur !== $jurUsuario) continue;

        $sheet->setCellValue("A$row", $jur);
        $jurData = $datos[$jur] ?? [];
        $remPrev = $remanentesAnt[$jur] ?? 0;
        $tEnt = 0;
        $tUtil = 0;

        $c = 2;
        for ($m = 1; $m <= 12; $m++) {
            $reg = $jurData[$m] ?? null;
            if ($reg) {
                $e = (int)$reg['entregado'];
                $u = (int)$reg['utilizado'];
                $rem = $remPrev + $e - $u;
                $remPrev = $rem;
                $tEnt += $e;
                $tUtil += $u;
                $sheet->setCellValue(getCol($c) . $row, $e);
                $sheet->setCellValue(getCol($c + 1) . $row, $u);
                $sheet->setCellValue(getCol($c + 2) . $row, $rem);
            } else {
                $sheet->setCellValue(getCol($c) . $row, "-");
                $sheet->setCellValue(getCol($c + 1) . $row, "-");
                $sheet->setCellValue(getCol($c + 2) . $row, "-");
            }
            $c += 3;
        }
        $sheet->setCellValue(getCol($c) . $row, $tEnt);
        $sheet->setCellValue(getCol($c + 1) . $row, $tUtil);
        $sheet->setCellValue(getCol($c + 2) . $row, $remPrev);

        $gTotalEnt += $tEnt;
        $gTotalUtil += $tUtil;
        $gTotalRem += $remPrev;
        $row++;
    }

    // Fila Total
    $sheet->setCellValue("A$row", "TOTAL ESTATAL");
    $sheet->getStyle("A$row:" . $maxCol . $row)->getFont()->setBold(true);
    $sheet->getStyle("A$row:" . $maxCol . $row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('e2e8f0');

    $sheet->setCellValue(getCol(1 + (12 * 3) + 1) . $row, $gTotalEnt);
    $sheet->setCellValue(getCol(1 + (12 * 3) + 2) . $row, $gTotalUtil);
    $sheet->setCellValue(getCol(1 + (12 * 3) + 3) . $row, $gTotalRem);

    // Ajustes finales Hoja 1
    $sheet->getStyle("A$startTable:" . $maxCol . $row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    for ($i = $startTable; $i <= $row; $i++) {
        $sheet->getRowDimension($i)->setRowHeight(-1);
    }
    foreach (range('A', $maxCol) as $col) {
        $sheet->getColumnDimension($col)->setAutoSize(true);
    }

    // ========== HOJA 2: OBSERVACIONES ==========
    $sheetObs = $spreadsheet->createSheet();
    $sheetObs->setTitle('Observaciones');

    $row = 1;
    // Logos Hoja 2
    if (file_exists(__DIR__ . '/img/edo_mex.jpg')) {
        $logo3 = new Drawing();
        $logo3->setPath(__DIR__ . '/img/edo_mex.jpg');
        $logo3->setCoordinates("A1");
        $logo3->setHeight(50);
        $logo3->setWorksheet($sheetObs);
    }

    $cMes = 13; // Juris + 12 meses
    $maxColObs = getCol($cMes);
    $sheetObs->mergeCells("A$row:$maxColObs$row");
    $sheetObs->setCellValue("A$row", "DETALLE DE OBSERVACIONES POR MES");
    $sheetObs->getStyle("A$row")->getFont()->setBold(true)->setSize(14);
    $sheetObs->getStyle("A$row")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $row += 3;

    $startTableObs = $row;
    $sheetObs->setCellValue("A$row", "Jurisdicción");
    for ($m = 1; $m <= 12; $m++) {
        $sheetObs->setCellValue(getCol($m + 1) . $row, $mesesNames[$m]);
    }

    $sheetObs->getStyle("A$row:" . $maxColObs . $row)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $subHeaderColor]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ]);
    $row++;

    foreach ($jurisdicciones as $jur) {
        if (!$esAdmin && $jur !== $jurUsuario) continue;

        $sheetObs->setCellValue("A$row", $jur);
        for ($m = 1; $m <= 12; $m++) {
            $obs = $datos[$jur][$m]['observaciones'] ?? '';
            $sheetObs->setCellValue(getCol($m + 1) . $row, $obs);
        }
        $sheetObs->getRowDimension($row)->setRowHeight(-1); // Auto-alto de fila
        $row++;
    }

    // Estilos Hoja 2
    $sheetObs->getStyle("A$startTableObs:" . $maxColObs . ($row - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true]
    ]);

    $sheetObs->getColumnDimension('A')->setAutoSize(true);
    for ($m = 1; $m <= 12; $m++) {
        $sheetObs->getColumnDimension(getCol($m + 1))->setWidth(40); // Ancho fijo para observaciones
    }

    // Volver a la primera
    $spreadsheet->setActiveSheetIndex(0);

    // Generar archivo
    $filename = "Chagas_Artropodos_$anio.xlsx";
    $writer = new Xlsx($spreadsheet);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    ob_end_clean();
    $writer->save('php://output');
    exit;
} catch (Exception $e) {
    ob_end_clean();
    echo "Error: " . $e->getMessage();
}
