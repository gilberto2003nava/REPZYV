<?php
// Exportador simple sin includes problemáticos
error_reporting(E_ALL);
ini_set('display_errors', 0); // No mostrar errores en output
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/export_errors.log');

// Aumentar límites para generación de Excel grande
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '300'); // 5 minutos

session_start();

// Iniciar buffer de salida limpio
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

if (!isset($_SESSION['usuario_id'])) {
    ob_end_clean();
    http_response_code(403);
    exit('No autorizado');
}

// Limpiar cualquier salida previa de forma segura
ob_clean();

// Incluir conexión oficial
require_once __DIR__ . '/includes/conexion.php';

if (!$conn) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    exit('Error de conexión a la base de datos central.');
}

require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');
$esAdmin = ($_SESSION['rol'] === 'admin');
$jurUsuario = $_SESSION['jurisdiccion'] ?? '';

// Función jurisdicciones (igual que en el sistema)
function jurisdicciones()
{
    return [
        '1 ATLACOMULCO',
        '2 IXTLAHUACA',
        '3 JILOTEPEC',
        '4 TENANGO DEL VALLE',
        '5 TOLUCA',
        '6 XONACATLÁN',
        '7 TEJUPILCO',
        '8 TENANCINGO',
        '9 VALLE DE BRAVO',
        '10 ATIZAPÁN DE ZARAGOZA',
        '11 CUAUTITLÁN',
        '12 NAUCALPAN',
        '13 TEOTIHUACÁN',
        '14 TLALNEPANTLA',
        '15 ZUMPANGO',
        '16 AMECAMECA',
        '17 ECATEPEC',
        '18 NEZAHUALCÓYOTL',
        '19 TEXCOCO'
    ];
}

$jurisdicciones = jurisdicciones();

$meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

// Lista completa de los 33 insumos con presentaciones (LISTA OFICIAL DEL EXCEL)
$insumosHardcoded = [
    ['id' => 1, 'nombre' => 'HIDROCLORURO DE XILACINA AL 2%', 'presentacion' => 'FRASCO C/25 ML'],
    ['id' => 2, 'nombre' => 'TILETAMINA-ZOLAZEPAM AL 10% (ZOLETIL)', 'presentacion' => 'FRASCO C/5 ML'],
    ['id' => 3, 'nombre' => 'CLORHIDRATO DE TRAMADOL 50 mg', 'presentacion' => 'FRASCO C/ 50 ml'],
    ['id' => 4, 'nombre' => 'CLORHIDRATO DE DOXOPRAM', 'presentacion' => 'FRASCO C/20 ML'],
    ['id' => 5, 'nombre' => 'ALUSPRAY', 'presentacion' => 'FRASCO C/210 ML'],
    ['id' => 6, 'nombre' => 'ATROPINA', 'presentacion' => 'AMPOLLETA'],
    ['id' => 7, 'nombre' => 'EPINEFRINA', 'presentacion' => 'AMPOLLETA'],
    ['id' => 8, 'nombre' => 'LIDOCAINA/EPINEFRINA', 'presentacion' => 'FCO CON 50ML'],
    ['id' => 9, 'nombre' => 'SUTURA DE ACIDO POLIGLICOLICO, CALIBRE 0', 'presentacion' => 'PIEZA'],
    ['id' => 10, 'nombre' => 'SUTURA DE ACIDO POLIGLICOLICO, CALIBRE 1', 'presentacion' => 'PIEZA'],
    ['id' => 11, 'nombre' => 'SUTURA DE ACIDO POLIGLICOLICO, CALIBRE 2-0', 'presentacion' => 'PIEZA'],
    ['id' => 12, 'nombre' => 'GUANTES DE CIRUJANO No. 6 ½', 'presentacion' => 'PAR'],
    ['id' => 13, 'nombre' => 'GUANTES DE CIRUJANO No. 7 ½', 'presentacion' => 'PAR'],
    ['id' => 14, 'nombre' => 'HOJA DE BISTURÍ No.21', 'presentacion' => 'PIEZA'],
    ['id' => 15, 'nombre' => 'GASA SECA CORTADA DE ALGODÓN 10X10 CM.', 'presentacion' => 'PAQUETE C/200 PZAS.'],
    ['id' => 16, 'nombre' => 'CUBREBOCAS', 'presentacion' => 'PIEZA'],
    ['id' => 17, 'nombre' => 'JERINGA DE 1 ML. CON AGUJA', 'presentacion' => 'PIEZA'],
    ['id' => 18, 'nombre' => 'JERINGA DE 3 ML. CON AGUJA', 'presentacion' => 'PIEZA'],
    ['id' => 19, 'nombre' => 'JERINGA DE 5 ML. CON AGUJA', 'presentacion' => 'PIEZA'],
    ['id' => 20, 'nombre' => 'JERINGA DE 10 ML. CON AGUJA', 'presentacion' => 'PIEZA'],
    ['id' => 21, 'nombre' => 'AGUJA HIPODERMICA CALIBRE 21 G.', 'presentacion' => 'ENVASE CON 100 PIEZAS'],
    ['id' => 22, 'nombre' => 'AGUJA HIPODERMICA CALIBRE 25 G.', 'presentacion' => 'ENVASE CON 100 PIEZAS'],
    ['id' => 23, 'nombre' => 'AGUA OXIGENADA', 'presentacion' => 'ENVASE CON 480 ML.'],
    ['id' => 24, 'nombre' => 'GLUTARALDEHIDO AL 2%', 'presentacion' => 'GALON'],
    ['id' => 25, 'nombre' => 'JABON LIQUIDO QUIRURGICO', 'presentacion' => 'GALON'],
    ['id' => 26, 'nombre' => 'IODOPOVIDONA', 'presentacion' => 'GALON'],
    ['id' => 27, 'nombre' => 'ALCOHOL', 'presentacion' => 'ENVASE 20 LTS.'],
    ['id' => 28, 'nombre' => 'TORUNDA DE ALGODÓN', 'presentacion' => 'BOLSA 500 GR.'],
    ['id' => 29, 'nombre' => 'SOLUCION HARTMAN', 'presentacion' => 'FRASCO DE 500 ML.'],
    ['id' => 30, 'nombre' => 'EQUIPO DE VENOCLISIS', 'presentacion' => 'PIEZA'],
    ['id' => 31, 'nombre' => 'CATETER PARA VENOCLISIS, CALIBRE 22', 'presentacion' => 'PIEZA'],
    ['id' => 32, 'nombre' => 'BENCILPENICILLINA BENZATINICA COMPUESTA FCO 3 ML', 'presentacion' => 'FRASCO C/3ML'],
    ['id' => 33, 'nombre' => 'FORMATOS DE RESPONSIVAS DE ESTERILIZACIÓN', 'presentacion' => 'PIEZA']
];

// Intentar obtener insumos de la BD, si no hay suficientes usar hardcoded
$insumos = [];
try {
    // Verificar si la tabla existe
    $tableCheck = $conn->query("SHOW TABLES LIKE 'insumos'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $r = $conn->query("SELECT id, nombre FROM insumos ORDER BY id LIMIT 33");
        if ($r && $r->num_rows > 0) {
            while ($row = $r->fetch_assoc()) {
                $insumos[] = $row;
            }
        }
    }
} catch (Exception $e) {
    // Si hay error, simplemente usar hardcoded
    error_log("Error al obtener insumos: " . $e->getMessage());
}

// Si no hay insumos en BD o son menos de 33, usar la lista hardcoded
if (count($insumos) < 33) {
    $insumos = $insumosHardcoded;
}

// Siempre mostrar los 12 meses completos
$mesMax = 12;

// Función para convertir número a letra de columna
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

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Material ' . $anio);

    // Colores pastel como en la página web
    $colores = [
        'titulo' => '1e40af',
        'mes' => '93c5fd',
        'ent' => 'dbeafe',
        'util' => 'ffedd5',
        'rem' => 'dcfce7',
        'jur' => 'f1f5f9',
        'tot' => 'e2e8f0',
        'tot_ent' => 'e0e7ff',
        'tot_util' => 'fce7f3',
        'borde' => 'cbd5e1'
    ];

    // Bordes para TODAS las celdas (arriba, abajo, izquierda, derecha)
    $borderStyle = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => '000000'] // Negro
            ]
        ]
    ];

    // Dimensiones de tabla (agregamos 1 fila más para presentación)
    $filasTabla = 24; // Era 23, ahora 24 (título + presentación + 2 headers + 19 jurisdicciones + total)
    $colsTabla = 1 + ($mesMax * 3) + 2;
    $espV = 2;
    $espH = 1;

    $idx = 0;

    // Generar tablas en cuadrícula 3x11
    for ($filaT = 0; $filaT < 3; $filaT++) {
        for ($colT = 0; $colT < 11; $colT++) {
            if ($idx >= count($insumos)) break;

            $ins = $insumos[$idx];
            $startRow = 2 + ($filaT * ($filasTabla + $espV));
            $startCol = 2 + ($colT * ($colsTabla + $espH));

            // Obtener datos del insumo
            $datos = [];
            try {
                // Verificar si la tabla existe
                $tableCheck = $conn->query("SHOW TABLES LIKE 'material_registro'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    $sql = "SELECT * FROM material_registro WHERE insumo_id=? AND anio=?";
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $stmt->bind_param('ii', $ins['id'], $anio);
                        $stmt->execute();
                        $res = $stmt->get_result();
                        while ($rw = $res->fetch_assoc()) {
                            $j = $rw['jurisdiccion'];
                            $m = (int)$rw['mes'];
                            if (!isset($datos[$j])) $datos[$j] = [];
                            $datos[$j][$m] = $rw;
                        }
                        $stmt->close();
                    }
                }
            } catch (Exception $e) {
                // Si hay error, continuar con datos vacíos
                error_log("Error al obtener datos del insumo {$ins['id']}: " . $e->getMessage());
            }

            // Calcular remanentes
            foreach ($datos as $j => &$md) {
                $remPrev = 0;
                for ($m = 1; $m <= 12; $m++) {
                    if (isset($md[$m])) {
                        $e = (int)$md[$m]['entregado'];
                        $u = (int)$md[$m]['utilizado'];
                        $d = $remPrev + $e;
                        $md[$m]['disp'] = $d;
                        $md[$m]['rem'] = $d - $u;
                        $remPrev = $md[$m]['rem'];
                    }
                }
            }
            unset($md);

            $row = $startRow;
            $c1 = getCol($startCol);
            $c2 = getCol($startCol + $colsTabla - 1);

            // Título del insumo (texto completo)
            $sheet->mergeCells($c1 . $row . ':' . $c2 . $row);
            $sheet->setCellValue($c1 . $row, $ins['nombre']); // Texto completo sin recortar
            $sheet->getStyle($c1 . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 13, 'name' => 'Calibri', 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['titulo']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => $colores['borde']]
                    ]
                ]
            ]);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;

            // NUEVA FILA: Presentación del insumo (mismo estilo que el título)
            $sheet->mergeCells($c1 . $row . ':' . $c2 . $row);
            $presentacion = isset($ins['presentacion']) ? $ins['presentacion'] : 'Presentación';
            $sheet->setCellValue($c1 . $row, $presentacion);
            $sheet->getStyle($c1 . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 13, 'name' => 'Calibri', 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['titulo']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => $colores['borde']]
                    ]
                ]
            ]);
            $sheet->getRowDimension($row)->setRowHeight(28);
            $row++;

            // Headers de meses con estilo pastel
            $col = $startCol;
            $sheet->setCellValue(getCol($col) . $row, 'Jurisdicción');
            $sheet->mergeCells(getCol($col) . $row . ':' . getCol($col) . ($row + 1));
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['jur']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => $colores['borde']]
                    ]
                ]
            ]);
            $col++;

            for ($m = 1; $m <= $mesMax; $m++) {
                $sheet->mergeCells(getCol($col) . $row . ':' . getCol($col + 2) . $row);
                $sheet->setCellValue(getCol($col) . $row, $meses[$m]);
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 11, 'name' => 'Calibri', 'color' => ['rgb' => '1e3a8a']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['mes']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => $colores['borde']]
                        ]
                    ]
                ]);
                $col += 3;
            }

            $sheet->mergeCells(getCol($col) . $row . ':' . getCol($col + 1) . $row);
            $sheet->setCellValue(getCol($col) . $row, 'Totales');
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 11, 'name' => 'Calibri', 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '6b7280']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => $colores['borde']]
                    ]
                ]
            ]);
            $row++;

            // Subheaders E U R con colores pastel
            $col = $startCol + 1;
            for ($m = 1; $m <= $mesMax; $m++) {
                $sheet->setCellValue(getCol($col) . $row, 'Entregado');
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '1e40af']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['ent']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => $colores['borde']]
                        ]
                    ]
                ]);
                $col++;
                $sheet->setCellValue(getCol($col) . $row, 'Utilizado');
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => 'c2410c']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['util']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => $colores['borde']]
                        ]
                    ]
                ]);
                $col++;
                $sheet->setCellValue(getCol($col) . $row, 'Remanente');
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '166534']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['rem']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => $colores['borde']]
                        ]
                    ]
                ]);
                $col++;
            }
            $sheet->setCellValue(getCol($col) . $row, 'Tot. Entregado');
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '3730a3']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot_ent']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => $colores['borde']]
                    ]
                ]
            ]);
            $col++;
            $sheet->setCellValue(getCol($col) . $row, 'Tot. Utilizado');
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '9d174d']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot_util']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => $colores['borde']]
                    ]
                ]
            ]);
            // Ajustar altura de fila para los subheaders
            $sheet->getRowDimension($row)->setRowHeight(22);
            $row++;

            // Datos de jurisdicciones con colores pastel
            $totMes = [];
            for ($m = 1; $m <= $mesMax; $m++) {
                $totMes[$m] = ['e' => 0, 'u' => 0, 'r' => 0];
            }
            $gTotE = 0;
            $gTotU = 0;

            foreach ($jurisdicciones as $jur) {
                if (!$esAdmin && $jur !== $jurUsuario) continue;

                $col = $startCol;
                $sheet->setCellValue(getCol($col) . $row, $jur); // Nombre completo de jurisdicción
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['jur']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true]
                ]);
                $col++;

                $tE = 0;
                $tU = 0;

                for ($m = 1; $m <= $mesMax; $m++) {
                    $reg = isset($datos[$jur][$m]) ? $datos[$jur][$m] : null;
                    if ($reg) {
                        $d = $reg['disp'];
                        $u = (int)$reg['utilizado'];
                        $rm = $reg['rem'];

                        $totMes[$m]['e'] += $d;
                        $totMes[$m]['u'] += $u;
                        $totMes[$m]['r'] += $rm;
                        $tE += $d;
                        $tU += $u;

                        // Entregado (azul pastel)
                        $sheet->setCellValue(getCol($col) . $row, $d ?: '-');
                        $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                            'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '1e40af']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'eff6ff']],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true]
                        ]);
                        $col++;

                        // Utilizado (naranja pastel)
                        $sheet->setCellValue(getCol($col) . $row, $u ?: '-');
                        $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                            'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => 'c2410c']],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'fff7ed']],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true]
                        ]);
                        $col++;

                        // Remanente (verde pastel o rojo si negativo)
                        $sheet->setCellValue(getCol($col) . $row, $rm);
                        $colorRem = $rm < 0 ? 'fef2f2' : 'f0fdf4';
                        $colorTextoRem = $rm < 0 ? 'dc2626' : '166534';
                        $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                            'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri', 'color' => ['rgb' => $colorTextoRem]],
                            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorRem]],
                            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true]
                        ]);
                        $col++;
                    } else {
                        // Celdas vacías
                        for ($i = 0; $i < 3; $i++) {
                            $sheet->setCellValue(getCol($col) . $row, '-');
                            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                                'font' => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '9ca3af']],
                                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f9fafb']],
                                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true]
                            ]);
                            $col++;
                        }
                    }
                }

                $gTotE += $tE;
                $gTotU += $tU;

                // Totales por jurisdicción
                $sheet->setCellValue(getCol($col) . $row, $tE);
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri', 'color' => ['rgb' => '3730a3']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot_ent']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true]
                ]);
                $col++;
                $sheet->setCellValue(getCol($col) . $row, $tU);
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri', 'color' => ['rgb' => '9d174d']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot_util']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true]
                ]);
                $row++;
            }

            // Fila TOTAL con estilo
            $col = $startCol;
            $sheet->setCellValue(getCol($col) . $row, 'TOTAL');
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot']]]
            ]);
            $col++;

            for ($m = 1; $m <= $mesMax; $m++) {
                $sheet->setCellValue(getCol($col) . $row, $totMes[$m]['e']);
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);
                $col++;
                $sheet->setCellValue(getCol($col) . $row, $totMes[$m]['u']);
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);
                $col++;
                $sheet->setCellValue(getCol($col) . $row, $totMes[$m]['r']);
                $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                    'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot']]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
                ]);
                $col++;
            }

            $sheet->setCellValue(getCol($col) . $row, $gTotE);
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            $col++;
            $sheet->setCellValue(getCol($col) . $row, $gTotU);
            $sheet->getStyle(getCol($col) . $row)->applyFromArray([
                'font' => ['size' => 10, 'bold' => true, 'name' => 'Calibri'],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colores['tot']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);

            // Aplicar bordes a TODAS las celdas de la tabla (arriba, abajo, izquierda, derecha)
            $sheet->getStyle(getCol($startCol) . $startRow . ':' . getCol($startCol + $colsTabla - 1) . $row)->applyFromArray($borderStyle);

            // AUTOAJUSTAR COLUMNAS PARA ESTA TABLA ESPECÍFICA - OPTIMIZADO
            for ($colIdx = $startCol; $colIdx < $startCol + $colsTabla; $colIdx++) {
                $colLetter = getCol($colIdx);
                // Establecer anchos fijos en lugar de calcular (mucho más rápido)
                if ($colIdx == $startCol) {
                    // Columna de jurisdicción
                    $sheet->getColumnDimension($colLetter)->setWidth(25);
                } else {
                    // Columnas de datos
                    $sheet->getColumnDimension($colLetter)->setWidth(12);
                }
            }

            $idx++;
        }
    }

    // AJUSTE GLOBAL SIMPLIFICADO - Sin recálculos pesados
    // Ya no necesitamos esto porque establecimos anchos fijos arriba

    // CREAR HOJA DE RESUMEN
    $resumenSheet = $spreadsheet->createSheet();
    $resumenSheet->setTitle('Resumen ' . $anio);

    // Título de la hoja resumen
    $resumenSheet->setCellValue('A1', 'RESUMEN GENERAL - MATERIAL DE CURACIÓN ' . $anio);
    $resumenSheet->mergeCells('A1:H1');
    $resumenSheet->getStyle('A1')->applyFromArray([
        'font' => ['bold' => true, 'size' => 16, 'name' => 'Calibri', 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1e40af']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ]);

    // Headers del resumen
    $resumenSheet->setCellValue('A3', 'Insumo');
    $resumenSheet->setCellValue('B3', 'Total Entregado');
    $resumenSheet->setCellValue('C3', 'Total Utilizado');
    $resumenSheet->setCellValue('D3', 'Remanente Final');
    $resumenSheet->setCellValue('E3', 'Jurisdicciones');
    $resumenSheet->setCellValue('F3', 'Último Mes');
    $resumenSheet->setCellValue('G3', 'Estado');
    $resumenSheet->setCellValue('H3', 'Observaciones');

    $resumenSheet->getStyle('A3:H3')->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'name' => 'Calibri', 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3b82f6']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
    ]);

    // Datos del resumen
    $rowResumen = 4;
    foreach ($insumos as $ins) {
        // Obtener totales por insumo
        $sql = "SELECT 
                    SUM(entregado) as total_ent, 
                    SUM(utilizado) as total_util,
                    COUNT(DISTINCT jurisdiccion) as num_jur,
                    MAX(mes) as ultimo_mes
                FROM material_registro 
                WHERE insumo_id=? AND anio=?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ii', $ins['id'], $anio);
        $stmt->execute();
        $totales = $stmt->get_result()->fetch_assoc();

        $totalEnt = (int)($totales['total_ent'] ?? 0);
        $totalUtil = (int)($totales['total_util'] ?? 0);
        $remanenteFinal = $totalEnt - $totalUtil;
        $numJur = (int)($totales['num_jur'] ?? 0);
        $ultimoMes = $totales['ultimo_mes'] ? $meses[(int)$totales['ultimo_mes']] : 'N/A';

        // Estado basado en remanente
        $estado = $remanenteFinal < 0 ? 'DÉFICIT' : ($remanenteFinal == 0 ? 'AGOTADO' : 'DISPONIBLE');
        $colorEstado = $remanenteFinal < 0 ? 'fef2f2' : ($remanenteFinal == 0 ? 'fef3c7' : 'f0fdf4');

        // Observaciones
        $obs = '';
        if ($remanenteFinal < 0) $obs = 'Requiere reabastecimiento urgente';
        elseif ($remanenteFinal == 0) $obs = 'Stock agotado';
        elseif ($numJur < 5) $obs = 'Pocas jurisdicciones registradas';
        else $obs = 'Normal';

        $resumenSheet->setCellValue('A' . $rowResumen, $ins['nombre']); // Nombre completo
        $resumenSheet->setCellValue('B' . $rowResumen, number_format($totalEnt));
        $resumenSheet->setCellValue('C' . $rowResumen, number_format($totalUtil));
        $resumenSheet->setCellValue('D' . $rowResumen, number_format($remanenteFinal));
        $resumenSheet->setCellValue('E' . $rowResumen, $numJur);
        $resumenSheet->setCellValue('F' . $rowResumen, $ultimoMes);
        $resumenSheet->setCellValue('G' . $rowResumen, $estado);
        $resumenSheet->setCellValue('H' . $rowResumen, $obs);

        // Aplicar colores según estado y ajuste de texto
        $resumenSheet->getStyle('A' . $rowResumen . ':H' . $rowResumen)->applyFromArray([
            'font' => ['size' => 10, 'name' => 'Calibri'],
            'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);

        $resumenSheet->getStyle('G' . $rowResumen)->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $colorEstado]],
            'font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
            'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        $rowResumen++;
    }

    // Autoajustar anchos de columnas del resumen - SIMPLIFICADO
    $resumenSheet->getColumnDimension('A')->setWidth(35); // Nombre insumo
    $resumenSheet->getColumnDimension('B')->setWidth(15); // Total Entregado
    $resumenSheet->getColumnDimension('C')->setWidth(15); // Total Utilizado
    $resumenSheet->getColumnDimension('D')->setWidth(15); // Remanente Final
    $resumenSheet->getColumnDimension('E')->setWidth(12); // Jurisdicciones
    $resumenSheet->getColumnDimension('F')->setWidth(12); // Último Mes
    $resumenSheet->getColumnDimension('G')->setWidth(14); // Estado
    $resumenSheet->getColumnDimension('H')->setWidth(40); // Observaciones

    // Ajustar altura de filas para el contenido con auto-height
    for ($r = 4; $r < $rowResumen; $r++) {
        $resumenSheet->getRowDimension($r)->setRowHeight(-1); // Auto height
    }

    // Ajustar altura del título y headers
    $resumenSheet->getRowDimension(1)->setRowHeight(30);
    $resumenSheet->getRowDimension(3)->setRowHeight(24);

    // Aplicar bordes al resumen
    $resumenSheet->getStyle('A3:H' . ($rowResumen - 1))->applyFromArray($borderStyle);

    // Generar archivo
    $filename = 'Material_Curacion_' . $anio . '.xlsx';
    $filepath = __DIR__ . '/storage/' . $filename;

    // Crear directorio si no existe
    $storageDir = __DIR__ . '/storage';
    if (!is_dir($storageDir)) {
        if (!mkdir($storageDir, 0755, true)) {
            throw new Exception('No se pudo crear el directorio de almacenamiento');
        }
    }

    // Verificar que el directorio sea escribible
    if (!is_writable($storageDir)) {
        throw new Exception('El directorio de almacenamiento no es escribible');
    }

    // Generar archivo Excel
    $writer = new Xlsx($spreadsheet);

    // Limpiar cualquier output antes de guardar
    if (ob_get_level()) {
        ob_clean();
    }

    // Guardar el archivo (save() no retorna valor, lanza excepción si falla)
    $writer->save($filepath);

    // Verificar que el archivo se creó correctamente
    if (!file_exists($filepath)) {
        throw new Exception('El archivo Excel no se generó correctamente');
    }

    $filesize = filesize($filepath);
    if ($filesize === false || $filesize === 0) {
        throw new Exception('El archivo Excel está vacío o corrupto');
    }

    // Limpiar TODO el buffer antes de enviar
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Enviar headers limpios
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . $filesize);
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('Pragma: public');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('Pragma: public');

    // Flush headers
    flush();

    // Enviar archivo
    readfile($filepath);

    // Limpiar archivo temporal
    unlink($filepath);
} catch (Exception $e) {
    // Limpiar buffer en caso de error
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Log del error
    error_log('Error Excel: ' . $e->getMessage() . ' en línea ' . $e->getLine());

    // Respuesta de error
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Error generando Excel: ' . $e->getMessage();

    // Limpiar archivo temporal si existe
    if (isset($filepath) && file_exists($filepath)) {
        unlink($filepath);
    }
}

if (isset($conn)) {
    $conn->close();
}
exit;
