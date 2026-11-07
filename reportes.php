<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

$usuarioId = (int)$_SESSION['usuario_id'];
$rol = $_SESSION['rol'] ?? 'capturista';

// Cargar año activo de la configuración
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

// Handle multi-year input - por defecto usa el año activo
$aniosInput = isset($_GET['anio']) ? $_GET['anio'] : $anio_activo;
$anios = [];
if (is_array($aniosInput)) {
    $anios = array_map('intval', $aniosInput);
} elseif (is_string($aniosInput)) {
    // If getting passed as query string "anio=2024" or "anio=2024,2025" from manual link
    if (strpos($aniosInput, ',') !== false) {
        $anios = array_map('intval', explode(',', $aniosInput));
    } else {
        $anios = [(int)$aniosInput];
    }
} else {
    $anios = [(int)$aniosInput];
}
if (empty($anios)) {
    $anios = [(int)$anio_activo];
}

$jurisdiccionSel = ($rol === 'admin') ? (isset($_GET['jurisdiccion']) ? trim($_GET['jurisdiccion']) : '') : ($_SESSION['jurisdiccion'] ?? '');
$municipioSel = isset($_GET['municipio']) ? trim($_GET['municipio']) : '';

$tipoSel = isset($_GET['tipo']) ? trim($_GET['tipo']) : '';
$tiposDisponibles = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
$nombresTipos = [
    'vacunados' => 'Animales Vacunados',
    'esterilizados' => 'Animales Esterilizados',
    'control' => 'Animales en Control',
    'muestras' => 'Muestras Antirrábicas',
    'agredidos' => 'Personas Agredidas',
    'tratamientos' => 'Tratamientos Antirrábicos'
];

$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$columnasMeses = implode(',', array_map(fn($m) => "r.$m", $meses));

// Build Query - Use LEFT JOIN with metas to include municipalities with metas but no captures
$conds = [];
$params = [];
$types = '';

// Years IN clause
$placeholders = implode(',', array_fill(0, count($anios), '?'));
$conds[] = "m.anio IN ($placeholders)";
foreach ($anios as $a) {
    $params[] = $a;
    $types .= 'i';
}

if ($rol !== 'admin') {
    // RESTRICTION: Filter by Jurisdiction instead of User ID to allow seeing all files from their unit
    $conds[] = 'm.jurisdiccion = ?';
    $params[] = $_SESSION['jurisdiccion'] ?? '';
    $types .= 's';
}
if ($jurisdiccionSel !== '' && $rol === 'admin') {
    $conds[] = 'm.jurisdiccion = ?';
    $params[] = $jurisdiccionSel;
    $types .= 's';
}
if ($municipioSel !== '') {
    $conds[] = 'm.municipio = ?';
    $params[] = $municipioSel;
    $types .= 's';
}
if ($tipoSel !== '' && in_array($tipoSel, $tiposDisponibles)) {
    $conds[] = 'm.tipo = ?';
    $params[] = $tipoSel;
    $types .= 's';
}

// LEFT JOIN: metas as base, registros as optional
// This ensures municipalities with metas but no captures are included
$sql = "SELECT 
            m.anio, 
            m.tipo, 
            m.jurisdiccion, 
            m.municipio, 
            m.meta as meta_anual,
            COALESCE(r.ene, 0) as ene,
            COALESCE(r.feb, 0) as feb,
            COALESCE(r.mar, 0) as mar,
            COALESCE(r.abr, 0) as abr,
            COALESCE(r.may, 0) as may,
            COALESCE(r.jun, 0) as jun,
            COALESCE(r.jul, 0) as jul,
            COALESCE(r.ago, 0) as ago,
            COALESCE(r.sep, 0) as sep,
            COALESCE(r.oct, 0) as oct,
            COALESCE(r.nov, 0) as nov,
            COALESCE(r.dic, 0) as dic
        FROM metas m
        LEFT JOIN registros r ON (
            m.jurisdiccion = r.jurisdiccion 
            AND m.municipio = r.municipio 
            AND m.tipo = r.tipo 
            AND m.anio = r.anio
        )
        WHERE " . implode(' AND ', $conds) . " 
        ORDER BY CAST(SUBSTRING_INDEX(m.jurisdiccion, ' ', 1) AS UNSIGNED), m.anio DESC, m.municipio, m.tipo";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$filas = mysqli_fetch_all($res, MYSQLI_ASSOC);

// Guardar info de debug para mostrar después
$debug_info = [
    'sql' => $sql,
    'params' => $params,
    'anios_filtrados' => $anios,
    'total_registros' => count($filas),
    'jurisdiccion_filtro' => $jurisdiccionSel ?: 'Todas',
    'municipio_filtro' => $municipioSel ?: 'Todos',
    'tipo_filtro' => $tipoSel ?: 'Todos'
];

$totalesMes = array_fill_keys($meses, 0);
$totalGlobal = 0;
$metaGlobal = 0;
foreach ($filas as &$f) {
    $rowTotal = 0;
    foreach ($meses as $m) {
        $val = (int)($f[$m] ?? 0);
        $rowTotal += $val;
        $totalesMes[$m] += $val;
    }
    $f['total'] = $rowTotal;
    $f['logro'] = ($f['meta_anual'] > 0) ? round(min(100, ($rowTotal / (int)$f['meta_anual']) * 100), 2) : 0;
    $totalGlobal += $rowTotal;
    $metaGlobal += (int)$f['meta_anual'];
}
unset($f);
$logroGlobal = $metaGlobal > 0 ? round(min(100, ($totalGlobal / $metaGlobal) * 100), 2) : 0;

$years = [];
$yearsRes = mysqli_query($conn, "SELECT DISTINCT anio FROM registros ORDER BY anio DESC");
while ($rowYear = mysqli_fetch_assoc($yearsRes)) {
    $years[] = (int)$rowYear['anio'];
}
if (empty($years)) {
    $years[] = (int)$anio_activo;
}
?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Reportes Colaterales</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/dark-mode.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/FileSaver.js/2.0.5/FileSaver.min.js"></script>
    <style>
        /* === DISEÑO ACORDE A LA PALETA AZUL DE LA APP === */

        /* Título de página */
        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: var(--radius-lg, 16px);
            padding: 1.25rem 1.5rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(30, 64, 175, 0.15);
            border: none;
        }

        .page-header h1 {
            margin: 0 0 0.35rem 0;
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            line-height: 1.2;
            background: none;
            -webkit-background-clip: unset;
            -webkit-text-fill-color: unset;
            background-clip: unset;
        }

        .page-header p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.875rem;
            margin: 0;
        }

        /* === SECCIÓN DE FILTROS MEJORADA === */
        .filtros-section {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            border-radius: 16px;
            padding: 1.75rem 2rem;
            margin-bottom: 2rem;
            border: 2px solid #e2e8f0;
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.08);
            position: relative;
            overflow: hidden;
        }

        .filtros-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #1e40af, #3b82f6, #60a5fa);
        }

        .filtros-section .filtros-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #e2e8f0;
        }

        .filtros-section .filtros-header i {
            font-size: 1.5rem;
            color: #3b82f6;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            padding: 10px;
            border-radius: 10px;
        }

        .filtros-section .filtros-header h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 700;
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
            align-items: end;
        }

        .filtro-grupo {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filtro-grupo label {
            font-size: 0.85rem;
            font-weight: 700;
            color: #475569;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .filtro-grupo label i {
            color: #3b82f6;
            font-size: 0.9rem;
        }

        .filtro-grupo select,
        .filtro-grupo input {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: white;
            color: #1e293b;
            font-weight: 500;
        }

        .filtro-grupo select:hover,
        .filtro-grupo input:hover {
            border-color: #cbd5e1;
        }

        .filtro-grupo select:focus,
        .filtro-grupo input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
            outline: none;
        }

        .filtro-grupo select[multiple] {
            padding: 6px 8px;
            height: 48px;
            overflow-y: auto;
        }

        .filtro-grupo select[multiple] option {
            padding: 8px 12px;
            border-radius: 6px;
            margin-bottom: 4px;
            transition: all 0.15s;
        }

        .filtro-grupo select[multiple] option:hover {
            background: #dbeafe;
        }

        .filtro-grupo select[multiple] option:checked {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            font-weight: 600;
        }

        .filtro-grupo small {
            color: #64748b;
            font-size: 0.75rem;
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .filtro-grupo small::before {
            content: '💡';
            font-size: 0.85rem;
        }

        .btn-filtrar {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            border: none;
            padding: 13px 32px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-filtrar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
        }

        .btn-filtrar:active {
            transform: translateY(0);
        }

        .btn-filtrar i {
            font-size: 1.1rem;
        }

        /* Tabla moderna con colores AZULES acordes */
        .table-container {
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 25px rgba(0, 0, 0, 0.08);
            background: white;
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            background: #fff;
        }

        .excel-table th {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
            color: #fff;
            font-weight: 700;
            padding: 14px 12px;
            text-transform: uppercase;
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            border: none;
            white-space: nowrap;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        .excel-table th:first-child {
            border-radius: 12px 0 0 0;
        }

        .excel-table th:last-child {
            border-radius: 0 12px 0 0;
        }

        .excel-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #475569;
            transition: all 0.2s ease;
        }

        .excel-table tbody tr {
            transition: all 0.2s ease;
        }

        .excel-table tbody tr:hover {
            background: linear-gradient(90deg, #eff6ff, #dbeafe);
        }

        .excel-table tbody tr:hover td {
            color: #1e293b;
        }

        /* Título de hoja con gradiente AZUL */
        .excel-title {
            text-align: center;
            padding: 1.5rem 2rem;
            font-size: 1.3rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            border-radius: 12px;
            margin: 2rem 0 1.5rem 0;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        /* Celdas de jurisdicción con color AZUL */
        .juris-cell {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe) !important;
            font-weight: 700 !important;
            color: #1e40af !important;
            text-align: center;
            vertical-align: middle;
            border-right: 3px solid #3b82f6 !important;
        }

        /* Fila de subtotal con AZUL claro */
        .subtotal-row {
            background: linear-gradient(90deg, #93c5fd, #60a5fa) !important;
        }

        .subtotal-row td {
            font-weight: 700 !important;
            color: #1e3a8a !important;
            border-color: #3b82f6 !important;
        }

        /* Gran total con AZUL oscuro */
        .gran-total-row {
            background: linear-gradient(90deg, #1e40af, #1d4ed8) !important;
        }

        .gran-total-row td {
            font-weight: 800 !important;
            color: white !important;
            font-size: 0.95rem;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
        }

        /* Columnas Total y % Logro destacadas */
        .col-total {
            background: linear-gradient(180deg, #fef3c7, #fde68a) !important;
            font-weight: 600;
            color: #92400e !important;
        }

        /* Clases de utilidad */
        .right {
            text-align: right;
        }

        .center {
            text-align: center;
        }

        /* Porcentajes con colores */
        .logro-bajo {
            color: #dc2626 !important;
            font-weight: 700;
            background: linear-gradient(90deg, #fee2e2, #fecaca) !important;
        }

        .logro-medio {
            color: #d97706 !important;
            font-weight: 700;
            background: linear-gradient(90deg, #fef3c7, #fde68a) !important;
        }

        .logro-alto {
            color: #059669 !important;
            font-weight: 700;
            background: linear-gradient(90deg, #d1fae5, #a7f3d0) !important;
        }

        /* Botón de exportar con VERDE */
        .action-card.success {
            background: linear-gradient(135deg, #10b981, #059669) !important;
            border: none;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
            transition: all 0.3s ease;
            color: white;
            border-radius: 12px;
            padding: 16px 24px;
        }

        .action-card.success:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.5);
        }

        /* Alerta de información */
        .info-banner {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-left: 5px solid #3b82f6;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .info-banner .info-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .info-banner .info-left i {
            color: #2563eb;
            font-size: 1.2rem;
        }

        .info-banner .badge-anio {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 1rem;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }

        .info-banner .info-right {
            color: #64748b;
            font-size: 0.9rem;
        }

        /* Scrollbar personalizado */
        ::-webkit-scrollbar {
            height: 8px;
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 10px;
        }

        /* Acciones */
        .acciones-section {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }

        /* Efecto de página de libro para cuando filtro es "Todos" */
        .book-page {
            background: #ffffff;
            border-radius: 4px 12px 12px 4px;
            box-shadow:
                0 1px 3px rgba(0, 0, 0, 0.12),
                0 4px 6px rgba(0, 0, 0, 0.08),
                3px 0 8px -2px rgba(0, 0, 0, 0.15),
                inset -2px 0 5px rgba(0, 0, 0, 0.03);
            margin-bottom: 0;
            padding: 1.5rem 2rem 2rem 2rem;
            position: relative;
            border-left: 5px solid #2563eb;
            transition: all 0.5s ease;
            display: none;
            opacity: 0;
            transform: rotateY(-90deg) translateX(-50px);
            transform-origin: left center;
        }

        .book-page.active {
            display: block;
            opacity: 1;
            transform: rotateY(0deg) translateX(0);
        }

        .book-page.prev {
            transform: rotateY(90deg) translateX(50px);
        }

        .book-page::before {
            content: '';
            position: absolute;
            left: 0;
            top: 10px;
            bottom: 10px;
            width: 3px;
            background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.1), transparent);
        }

        .book-page::after {
            content: '';
            position: absolute;
            right: -5px;
            top: 5px;
            bottom: 5px;
            width: 5px;
            background: linear-gradient(90deg, rgba(0, 0, 0, 0.08), transparent);
            border-radius: 0 5px 5px 0;
        }

        .book-page .page-number {
            position: absolute;
            bottom: 8px;
            right: 15px;
            font-size: 0.75rem;
            color: #94a3b8;
            font-style: italic;
        }

        .book-page .excel-title {
            margin-top: 0 !important;
            margin-bottom: 1rem;
        }

        /* Contenedor de páginas tipo libro */
        .book-container {
            display: flex;
            flex-direction: column;
            gap: 0;
            perspective: 1500px;
            min-height: 400px;
        }

        /* Navegación del libro */
        .book-navigation {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, #f8fafc, #e2e8f0);
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .book-nav-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .book-nav-btn.prev-btn {
            background: linear-gradient(135deg, #64748b, #475569);
            color: white;
        }

        .book-nav-btn.next-btn {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: white;
        }

        .book-nav-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .book-nav-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .page-indicator {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 20px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .page-indicator .current-page {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2563eb;
        }

        .page-indicator .separator {
            color: #94a3b8;
        }

        .page-indicator .total-pages {
            font-size: 1rem;
            color: #64748b;
        }

        .page-title-display {
            font-weight: 600;
            color: #1e40af;
            font-size: 1.1rem;
            min-width: 200px;
            text-align: center;
        }

        /* Indicadores de páginas (dots) */
        .page-dots {
            display: flex;
            gap: 8px;
            justify-content: center;
            margin-top: 1rem;
        }

        .page-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #cbd5e1;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .page-dot:hover {
            background: #94a3b8;
        }

        .page-dot.active {
            background: #2563eb;
            transform: scale(1.2);
            border-color: #bfdbfe;
        }

        /* RESPONSIVO */
        @media (max-width: 992px) {
            .report-header {
                padding: 1rem
            }

            .report-header h1 {
                font-size: 1.3rem
            }

            .filter-panel {
                flex-direction: column;
                gap: 0.75rem;
                padding: 1rem
            }

            .filter-panel select,
            .filter-panel button {
                width: 100%
            }

            .report-table {
                font-size: 0.8rem
            }

            .report-table th,
            .report-table td {
                padding: 8px 6px
            }

            .summary-cards {
                grid-template-columns: 1fr 1fr
            }

            /* Navegación del libro en tablet */
            .book-navigation {
                flex-wrap: wrap;
                gap: 0.75rem;
                padding: 0.75rem
            }

            .page-title-display {
                order: 3;
                width: 100%;
                min-width: auto;
                font-size: 1rem
            }
        }

        @media (max-width: 576px) {
            .report-header {
                padding: 0.75rem
            }

            .report-header h1 {
                font-size: 1.1rem
            }

            .filter-panel {
                padding: 0.75rem
            }

            .report-table {
                font-size: 0.7rem
            }

            .report-table th,
            .report-table td {
                padding: 6px 4px
            }

            .summary-cards {
                grid-template-columns: 1fr
            }

            .summary-card {
                padding: 12px
            }

            .btn {
                padding: 8px 12px;
                font-size: 0.85rem
            }

            /* Botones de navegación como flechas */
            .book-navigation {
                padding: 0.5rem;
                gap: 0.5rem
            }

            .book-nav-btn {
                padding: 10px 14px;
                font-size: 0;
                border-radius: 50%;
                width: 44px;
                height: 44px;
                justify-content: center
            }

            .book-nav-btn i {
                font-size: 1rem;
                margin: 0
            }

            .page-indicator {
                padding: 6px 14px
            }

            .page-indicator .current-page {
                font-size: 1.2rem
            }

            .page-indicator .total-pages {
                font-size: 0.85rem
            }

            .page-title-display {
                font-size: 0.85rem;
                padding: 0 0.5rem
            }

            .page-dots {
                margin-top: 0.75rem;
                gap: 6px
            }

            .page-dot {
                width: 10px;
                height: 10px
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
                <h1 style="color: white; margin-bottom: 0.5rem;">
                    <i class="fas fa-file-alt me-2"></i>
                    Reporte de Colaterales <?= implode(', ', $anios) ?>
                </h1>
                <p style="color: rgba(255, 255, 255, 0.9); margin: 0;">Visualiza y exporta el reporte.</p>
            </div>

            <!-- Info de consulta -->
            <div class="info-banner">
                <div class="info-left">
                    <i class="fas fa-chart-bar"></i>
                    <span><strong>Mostrando datos de:</strong></span>
                    <span class="badge-anio"><?= implode(', ', $debug_info['anios_filtrados']) ?></span>
                    <span style="color: #64748b;">| <?= $debug_info['total_registros'] ?> registros</span>
                </div>
                <div class="info-right">
                    <i class="fas fa-calendar-check"></i> Año activo del sistema: <strong><?= $anio_activo ?></strong>
                </div>
            </div>

            <!-- SECCIÓN DE FILTROS MEJORADA -->
            <section class="filtros-section">
                <div class="filtros-header">
                    <i class="fas fa-filter"></i>
                    <h3>Filtros de Búsqueda</h3>
                </div>
                <form method="get">
                    <div class="filtros-grid">
                        <div class="filtro-grupo">
                            <label><i class="fas fa-calendar-alt"></i> Años</label>
                            <select name="anio[]" multiple>
                                <?php foreach ($years as $year): ?>
                                    <option value="<?= $year ?>" <?= in_array($year, $anios) ? 'selected' : '' ?>><?= $year ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small style="color: #64748b; font-size: 0.75rem;">Ctrl+Click para selección múltiple</small>
                        </div>
                        <div class="filtro-grupo">
                            <label><i class="fas fa-tags"></i> Tipo de Reporte</label>
                            <select name="tipo">
                                <option value="">Todos los tipos</option>
                                <?php foreach ($nombresTipos as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $tipoSel === $key ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($rol === 'admin'): ?>
                            <div class="filtro-grupo">
                                <label><i class="fas fa-building"></i> Jurisdicción</label>
                                <select name="jurisdiccion" id="jurisdiccion">
                                    <option value="">Todas las jurisdicciones</option>
                                    <?php foreach (jurisdicciones() as $j): ?>
                                        <option value="<?= htmlspecialchars($j) ?>" <?= $jurisdiccionSel === $j ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <div class="filtro-grupo">
                            <label><i class="fas fa-map-marker-alt"></i> Municipio</label>
                            <select name="municipio" id="municipio">
                                <option value="">Todos los municipios</option>
                                <?php if ($jurisdiccionSel): foreach (municipiosPorJurisdiccion($jurisdiccionSel) as $m): ?>
                                        <option value="<?= htmlspecialchars($m) ?>" <?= $municipioSel === $m ? 'selected' : '' ?>><?= htmlspecialchars($m) ?></option>
                                <?php endforeach;
                                endif; ?>
                            </select>
                        </div>
                        <div class="filtro-grupo">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn-filtrar">
                                <i class="fas fa-search"></i> Buscar
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <!-- ACCIONES -->
            <div class="acciones-section">
                <a class="action-card success" href="#" onclick="generateExcel('<?= implode(',', $anios) ?>', '<?= $jurisdiccionSel ?>', '<?= $municipioSel ?>'); return false;">
                    <div class="icon"><i class="fas fa-file-excel"></i></div>
                    <div>
                        <div class="donut-title" style="color: white; font-weight: 700;">Exportar a Excel</div>
                        <small style="color: rgba(255,255,255,0.9);">Formato oficial con logos</small>
                    </div>
                </a>
            </div>

            <!-- TABLAS DE DATOS -->
            <section class="glass-card">
                <?php
                // Agrupar datos por tipo para mostrar tablas separadas como en Excel
                $datosPorTipo = [];
                foreach ($filas as $f) {
                    $tipo = $f['tipo'];
                    if (!isset($datosPorTipo[$tipo])) {
                        $datosPorTipo[$tipo] = [
                            'filas' => [],
                            'grupos' => [],
                            'totalMeta' => 0,
                            'totalMeses' => array_fill_keys($meses, 0),
                            'totalGeneral' => 0
                        ];
                    }
                    $datosPorTipo[$tipo]['filas'][] = $f;
                    $datosPorTipo[$tipo]['totalMeta'] += (int)$f['meta_anual'];
                    $datosPorTipo[$tipo]['totalGeneral'] += $f['total'];
                    foreach ($meses as $m) {
                        $datosPorTipo[$tipo]['totalMeses'][$m] += (int)$f[$m];
                    }

                    // Agrupar por jurisdicción
                    $juris = $f['jurisdiccion'];
                    if (!isset($datosPorTipo[$tipo]['grupos'][$juris])) {
                        $datosPorTipo[$tipo]['grupos'][$juris] = [
                            'filas' => [],
                            'meta' => 0,
                            'meses' => array_fill_keys($meses, 0),
                            'total' => 0
                        ];
                    }
                    $datosPorTipo[$tipo]['grupos'][$juris]['filas'][] = $f;
                    $datosPorTipo[$tipo]['grupos'][$juris]['meta'] += (int)$f['meta_anual'];
                    $datosPorTipo[$tipo]['grupos'][$juris]['total'] += $f['total'];
                    foreach ($meses as $m) {
                        $datosPorTipo[$tipo]['grupos'][$juris]['meses'][$m] += (int)$f[$m];
                    }
                }

                // Si hay filtro de tipo, mostrar solo ese tipo
                $tiposAMostrar = $tipoSel ? [$tipoSel => $datosPorTipo[$tipoSel] ?? null] : $datosPorTipo;

                // Si no hay datos pero hay filtro de tipo, crear estructura vacía
                if ($tipoSel && (!isset($tiposAMostrar[$tipoSel]) || $tiposAMostrar[$tipoSel] === null)) {
                    $tiposAMostrar[$tipoSel] = [
                        'filas' => [],
                        'grupos' => [],
                        'totalMeta' => 0,
                        'totalMeses' => array_fill_keys($meses, 0),
                        'totalGeneral' => 0
                    ];
                }

                // No filtrar tipos vacíos si hay un tipo específico seleccionado
                // Si no hay tipo seleccionado, filtrar solo si hay datos
                if (!$tipoSel) {
                    $tiposAMostrarFiltrados = array_filter($tiposAMostrar);
                    // Si después de filtrar no hay nada, mostrar todos los tipos con estructura vacía
                    if (empty($tiposAMostrarFiltrados)) {
                        // Crear estructura vacía para todos los tipos disponibles
                        $tiposAMostrar = [];
                        foreach ($tiposDisponibles as $tipoDisp) {
                            $tiposAMostrar[$tipoDisp] = [
                                'filas' => [],
                                'grupos' => [],
                                'totalMeta' => 0,
                                'totalMeses' => array_fill_keys($meses, 0),
                                'totalGeneral' => 0
                            ];
                        }
                    } else {
                        $tiposAMostrar = $tiposAMostrarFiltrados;
                    }
                }

                // Asegurar que siempre haya al menos un tipo para mostrar
                if (empty($tiposAMostrar)) {
                    $tipoDefault = $tipoSel ?: 'vacunados';
                    $tiposAMostrar[$tipoDefault] = [
                        'filas' => [],
                        'grupos' => [],
                        'totalMeta' => 0,
                        'totalMeses' => array_fill_keys($meses, 0),
                        'totalGeneral' => 0
                    ];
                }

                // Determinar si mostrar como libro (cuando es "Todos")
                $esModoPaginas = empty($tipoSel);
                $numeroPagina = 1;
                $totalPaginas = count($tiposAMostrar);
                $nombresTiposArray = [];
                foreach ($tiposAMostrar as $t => $d) {
                    $nombresTiposArray[] = $nombresTipos[$t] ?? ucfirst($t);
                }

                if ($esModoPaginas && $totalPaginas > 1): ?>
                    <!-- Navegación del libro -->
                    <div class="book-navigation">
                        <button class="book-nav-btn prev-btn" onclick="prevPage()" id="btnPrev" disabled>
                            <i class="fas fa-chevron-left"></i> Anterior
                        </button>
                        <div class="page-indicator">
                            <span class="current-page" id="currentPage">1</span>
                            <span class="separator">/</span>
                            <span class="total-pages"><?= $totalPaginas ?></span>
                        </div>
                        <div class="page-title-display" id="pageTitle"><?= $nombresTiposArray[0] ?? '' ?></div>
                        <button class="book-nav-btn next-btn" onclick="nextPage()" id="btnNext">
                            Siguiente <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                    <div class="page-dots" id="pageDots">
                        <?php for ($i = 1; $i <= $totalPaginas; $i++): ?>
                            <span class="page-dot <?= $i === 1 ? 'active' : '' ?>" onclick="goToPage(<?= $i ?>)" title="<?= $nombresTiposArray[$i - 1] ?? '' ?>"></span>
                        <?php endfor; ?>
                    </div>
                    <div class="book-container" id="bookContainer">
                    <?php endif; ?>

                    <?php foreach ($tiposAMostrar as $tipo => $datos):
                        // Asegurar que $datos tenga estructura válida
                        if (!is_array($datos) || $datos === null) {
                            $datos = [
                                'filas' => [],
                                'grupos' => [],
                                'totalMeta' => 0,
                                'totalMeses' => array_fill_keys($meses, 0),
                                'totalGeneral' => 0
                            ];
                        }
                        $nombreTipo = $nombresTipos[$tipo] ?? ucfirst($tipo);
                        $logroTotal = $datos['totalMeta'] > 0 ? number_format(($datos['totalGeneral'] / $datos['totalMeta']) * 100, 2) : '0.00';
                    ?>

                        <?php if ($esModoPaginas): ?>
                            <div class="book-page <?= $numeroPagina === 1 ? 'active' : '' ?>" data-page="<?= $numeroPagina ?>" data-title="<?= htmlspecialchars($nombreTipo) ?>">
                                <span class="page-number">Página <?= $numeroPagina ?> de <?= $totalPaginas ?></span>
                            <?php endif; ?>

                            <div class="excel-title" style="margin-top: 2rem;">
                                <strong><?= strtoupper($nombreTipo) ?> <?= implode('-', $anios) ?></strong>
                            </div>

                            <div class="table-container" style="overflow-x:auto; margin-bottom: 2rem; position: relative; min-height: 400px;">
                                <?php
                                $sinDatos = empty($datos['grupos']) || (isset($datos['grupos']) && count($datos['grupos']) === 0);
                                if ($sinDatos):
                                ?>
                                    <!-- Marca de agua cuando no hay datos -->
                                    <div style="position: absolute; top: 200px; left: 50%; transform: translateX(-50%); z-index: 10; text-align: center; pointer-events: none; width: 100%;">
                                        <i class="fas fa-inbox" style="font-size: 4rem; color: rgba(59, 130, 246, 0.3); margin-bottom: 1rem; display: block;"></i>
                                        <p style="font-size: 1.3rem; font-weight: 700; color: rgba(30, 64, 175, 0.5); margin: 0; text-transform: uppercase; letter-spacing: 2px;">
                                            Sin Datos Registrados
                                        </p>
                                        <p style="font-size: 0.95rem; color: rgba(100, 116, 139, 0.4); margin-top: 0.5rem;">
                                            No se encontraron registros para los filtros seleccionados
                                        </p>
                                    </div>
                                <?php endif; ?>
                                <table class="excel-table" style="<?= $sinDatos ? 'opacity: 0.5;' : '' ?>">
                                    <thead>
                                        <tr>
                                            <?php if ($rol === 'admin'): ?>
                                                <th style="width: 180px;">Jurisdicción</th>
                                            <?php endif; ?>
                                            <th style="width: 200px;">Municipio</th>
                                            <th>Meta Anual</th>
                                            <?php foreach ($meses as $m): ?>
                                                <th><?= strtoupper($m) ?></th>
                                            <?php endforeach; ?>
                                            <th style="background: linear-gradient(135deg, #F59E0B, #D97706); color: white;">Total</th>
                                            <th style="background: linear-gradient(135deg, #F59E0B, #D97706); color: white;">% Logro</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        if (!empty($datos['grupos'])):
                                            foreach ($datos['grupos'] as $jurisdiccion => $grupo):
                                                // SEGURIDAD: Capturistas solo ven su jurisdicción
                                                if ($rol !== 'admin' && $jurisdiccion !== ($_SESSION['jurisdiccion'] ?? '')) {
                                                    continue;
                                                }

                                                $numFilas = count($grupo['filas']);
                                                $primeraFila = true;

                                                foreach ($grupo['filas'] as $f):
                                                    $logro = ($f['meta_anual'] > 0) ? number_format(($f['total'] / $f['meta_anual']) * 100, 2) : '0.00';
                                        ?>
                                                    <tr>
                                                        <?php if ($rol === 'admin' && $primeraFila): ?>
                                                            <td rowspan="<?= $numFilas + 1 ?>" class="juris-cell">
                                                                <?= htmlspecialchars($jurisdiccion) ?>
                                                            </td>
                                                        <?php $primeraFila = false;
                                                        endif; ?>
                                                        <td><?= htmlspecialchars($f['municipio']) ?></td>
                                                        <td class="right"><?= number_format((int)$f['meta_anual'], 0, '', ',') ?></td>
                                                        <?php foreach ($meses as $m): ?>
                                                            <td class="right"><?= number_format((int)$f[$m], 0, '', ',') ?></td>
                                                        <?php endforeach; ?>
                                                        <td class="right col-total"><?= number_format($f['total'], 0, '', ',') ?></td>
                                                        <td class="right <?= ((float)$logro < 70) ? 'logro-bajo' : (((float)$logro >= 90) ? 'logro-alto' : 'logro-medio') ?>"><?= $logro ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>

                                                <!-- Subtotal por jurisdicción -->
                                                <tr class="subtotal-row">
                                                    <?php if ($rol === 'capturista'): ?>
                                                        <td class="center">TOTAL</td>
                                                    <?php else: ?>
                                                        <td class="center">TOTAL</td>
                                                    <?php endif; ?>
                                                    <td class="right"><?= number_format($grupo['meta'], 0, '', ',') ?></td>
                                                    <?php foreach ($meses as $m): ?>
                                                        <td class="right"><?= number_format($grupo['meses'][$m], 0, '', ',') ?></td>
                                                    <?php endforeach; ?>
                                                    <td class="right"><?= number_format($grupo['total'], 0, '', ',') ?></td>
                                                    <?php
                                                    $logroJuris = $grupo['meta'] > 0 ? number_format(($grupo['total'] / $grupo['meta']) * 100, 2) : '0.00';
                                                    ?>
                                                    <td class="right"><?= $logroJuris ?>%</td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <!-- Gran Total -->
                                            <tr class="gran-total-row">
                                                <td colspan="<?= $rol === 'admin' ? '2' : '1' ?>" class="center" style="font-size: 1rem;">GRAN TOTAL</td>
                                                <td class="right"><?= number_format($datos['totalMeta'], 0, '', ',') ?></td>
                                                <?php foreach ($meses as $m): ?>
                                                    <td class="right"><?= number_format($datos['totalMeses'][$m], 0, '', ',') ?></td>
                                                <?php endforeach; ?>
                                                <td class="right"><?= number_format($datos['totalGeneral'], 0, '', ',') ?></td>
                                                <td class="right"><?= $logroTotal ?>%</td>
                                            </tr>
                                        <?php else: ?>
                                            <!-- Estructura vacía cuando no hay datos - usar jurisdicciones y municipios reales -->
                                            <?php
                                            // Obtener jurisdicciones y municipios reales del sistema
                                            $jurisdiccionesReales = jurisdicciones();

                                            foreach ($jurisdiccionesReales as $jurisReal):
                                                // SEGURIDAD: Capturistas solo ven su jurisdicción
                                                if ($rol !== 'admin' && $jurisReal !== ($_SESSION['jurisdiccion'] ?? '')) {
                                                    continue;
                                                }

                                                $municipiosReales = municipiosPorJurisdiccion($jurisReal, $tipo);
                                                if (empty($municipiosReales)) continue; // Saltar si no hay municipios

                                                $numFilasReal = count($municipiosReales);
                                            ?>
                                                <?php foreach ($municipiosReales as $idx => $munReal): ?>
                                                    <tr>
                                                        <?php if ($rol === 'admin' && $idx === 0): ?>
                                                            <td rowspan="<?= $numFilasReal + 1 ?>" class="juris-cell">
                                                                <?= htmlspecialchars($jurisReal) ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td><?= htmlspecialchars($munReal) ?></td>
                                                        <td class="right">0</td>
                                                        <?php foreach ($meses as $m): ?>
                                                            <td class="right">0</td>
                                                        <?php endforeach; ?>
                                                        <td class="right col-total">0</td>
                                                        <td class="right">0.00%</td>
                                                    </tr>
                                                <?php endforeach; ?>

                                                <!-- Subtotal por jurisdicción -->
                                                <tr class="subtotal-row">
                                                    <?php if ($rol === 'admin'): ?>
                                                        <!-- Admin ya tiene la celda de jurisdicción con rowspan, solo necesita TOTAL -->
                                                    <?php endif; ?>
                                                    <td class="center">TOTAL</td>
                                                    <td class="right">0</td>
                                                    <?php foreach ($meses as $m): ?>
                                                        <td class="right">0</td>
                                                    <?php endforeach; ?>
                                                    <td class="right">0</td>
                                                    <td class="right">0.00%</td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <!-- Gran Total -->
                                            <tr class="gran-total-row">
                                                <td colspan="<?= $rol === 'admin' ? '2' : '1' ?>" class="center" style="font-size: 1rem;">GRAN TOTAL</td>
                                                <td class="right">0</td>
                                                <?php foreach ($meses as $m): ?>
                                                    <td class="right">0</td>
                                                <?php endforeach; ?>
                                                <td class="right">0</td>
                                                <td class="right">0.00%</td>
                                            </tr>
                                        <?php endif; // Fin de if !empty($datos['grupos']) 
                                        ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($esModoPaginas): ?>
                            </div>
                        <?php $numeroPagina++;
                            endif; ?>

                    <?php endforeach; ?>

                    <?php if ($esModoPaginas && $totalPaginas > 1): ?>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($esModoPaginas && $totalPaginas > 1): ?>
                <script>
                    // Control de páginas del libro
                    let currentPage = 1;
                    const totalPages = <?= $totalPaginas ?>;
                    const pageTitles = <?= json_encode($nombresTiposArray) ?>;

                    function updateNavigation() {
                        document.getElementById('currentPage').textContent = currentPage;
                        document.getElementById('pageTitle').textContent = pageTitles[currentPage - 1] || '';
                        document.getElementById('btnPrev').disabled = currentPage === 1;
                        document.getElementById('btnNext').disabled = currentPage === totalPages;

                        // Actualizar dots
                        document.querySelectorAll('.page-dot').forEach((dot, index) => {
                            dot.classList.toggle('active', index + 1 === currentPage);
                        });

                        // Actualizar páginas
                        document.querySelectorAll('.book-page').forEach((page, index) => {
                            const pageNum = index + 1;
                            page.classList.remove('active', 'prev');
                            if (pageNum === currentPage) {
                                page.classList.add('active');
                            } else if (pageNum < currentPage) {
                                page.classList.add('prev');
                            }
                        });
                    }

                    function nextPage() {
                        if (currentPage < totalPages) {
                            currentPage++;
                            updateNavigation();
                        }
                    }

                    function prevPage() {
                        if (currentPage > 1) {
                            currentPage--;
                            updateNavigation();
                        }
                    }

                    function goToPage(page) {
                        if (page >= 1 && page <= totalPages) {
                            currentPage = page;
                            updateNavigation();
                        }
                    }

                    // Navegación con teclado
                    document.addEventListener('keydown', (e) => {
                        if (e.key === 'ArrowRight') nextPage();
                        if (e.key === 'ArrowLeft') prevPage();
                    });
                </script>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const mapping = <?= json_encode(array_reduce(jurisdicciones(), function ($acc, $j) {
                            $acc[$j] = municipiosPorJurisdiccion($j);
                            return $acc;
                        }, [])) ?>;
        const jurisSel = document.getElementById('jurisdiccion');
        const muniSel = document.getElementById('municipio');
        jurisSel?.addEventListener('change', () => {
            const j = jurisSel.value;
            muniSel.innerHTML = '<option value="">Todos</option>';
            if (mapping[j]) {
                mapping[j].forEach(m => {
                    const opt = document.createElement('option');
                    opt.value = m;
                    opt.textContent = m;
                    muniSel.appendChild(opt);
                });
            }
        });

        document.getElementById('jurisdiccion').addEventListener('change', function() {
            this.form.submit();
        });

        async function generateExcel(anios, jurisdiccion, municipio) {
            const btn = document.querySelector('.action-card.success');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<div class="icon"><i class="fas fa-spinner fa-spin"></i></div><div><div class="donut-title">Generando...</div><small>Espere por favor</small></div>';

            try {
                // Fetch data
                const response = await fetch(`data_colaterales.php?anio=${anios}&jurisdiccion=${encodeURIComponent(jurisdiccion)}&municipio=${encodeURIComponent(municipio)}`);
                const data = await response.json();

                if (data.error) throw new Error(data.error);

                const workbook = new ExcelJS.Workbook();

                // Load Images from img/ directory
                let logo1Id = null;
                let logo2Id = null;
                try {
                    const img1 = await fetch('img/edo_mex.jpg');
                    const blob1 = await img1.blob();
                    const buffer1 = await blob1.arrayBuffer();
                    logo1Id = workbook.addImage({
                        buffer: buffer1,
                        extension: 'jpeg',
                    });

                    const img2 = await fetch('img/conc.jpg');
                    const blob2 = await img2.blob();
                    const buffer2 = await blob2.arrayBuffer();
                    logo2Id = workbook.addImage({
                        buffer: buffer2,
                        extension: 'jpeg',
                    });
                } catch (e) {
                    console.warn('Logos no encontrados', e);
                }

                // Global Styles - BORDES ESTILO EXCEL TRADICIONAL
                const borderThin = {
                    top: {
                        style: 'thin',
                        color: {
                            argb: 'FF000000'
                        }
                    },
                    left: {
                        style: 'thin',
                        color: {
                            argb: 'FF000000'
                        }
                    },
                    bottom: {
                        style: 'thin',
                        color: {
                            argb: 'FF000000'
                        }
                    },
                    right: {
                        style: 'thin',
                        color: {
                            argb: 'FF000000'
                        }
                    }
                };
                const borderMedium = {
                    top: {
                        style: 'medium',
                        color: {
                            argb: 'FF000000'
                        }
                    },
                    left: {
                        style: 'medium',
                        color: {
                            argb: 'FF000000'
                        }
                    },
                    bottom: {
                        style: 'medium',
                        color: {
                            argb: 'FF000000'
                        }
                    },
                    right: {
                        style: 'medium',
                        color: {
                            argb: 'FF000000'
                        }
                    }
                };
                const borderStyle = borderThin;
                const fontStyle = {
                    name: 'Calibri',
                    size: 10
                };

                // Encabezado AZUL OSCURO como en imagen
                const headerFill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: '1F4E79'
                    } // Azul oscuro tipo Excel
                };

                // Subtotal AMARILLO/DORADO como en imagen
                const subTotalFill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: 'FFD966'
                    } // Amarillo dorado
                };

                // Gran total NARANJA
                const grandTotalFill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: 'F4B183'
                    } // Naranja claro
                };

                // Evaluation Styles - COLORES ACORDES A LA PALETA AZUL
                const evalFontStyle = {
                    name: 'Calibri',
                    size: 9
                };

                // Encabezado evaluación - Azul institucional
                const evalHeaderFill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: '1D4ED8'
                    } // Azul institucional
                };

                // Total evaluación - Verde éxito
                const evalTotalFill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: '059669'
                    } // Verde esmeralda
                };

                // Título de sección - Azul oscuro
                const evalTitleFill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: '1E40AF'
                    } // Azul oscuro
                };

                const meses = data.meses;
                // Columnas con MÁS ESPACIO entre ellas
                const columns = [{
                        header: 'JURISDICCIÓN',
                        key: 'juris',
                        width: 28
                    },
                    {
                        header: 'MUNICIPIO',
                        key: 'mun',
                        width: 30
                    },
                    {
                        header: 'META',
                        key: 'meta',
                        width: 12
                    }
                ];
                meses.forEach(m => columns.push({
                    header: m.toUpperCase(),
                    key: m,
                    width: 9
                }));
                columns.push({
                    header: 'TOTAL',
                    key: 'total',
                    width: 12
                });
                columns.push({
                    header: '% LOGRO',
                    key: 'logro',
                    width: 12
                });

                // --- GENERATE INDIVIDUAL SHEETS ---
                for (const [type, sheetData] of Object.entries(data.sheets)) {
                    const sheet = workbook.addWorksheet(sheetData.title.substring(0, 30));

                    // Logos
                    if (logo1Id !== null) sheet.addImage(logo1Id, {
                        tl: {
                            col: 0,
                            row: 0
                        },
                        ext: {
                            width: 120,
                            height: 90
                        }
                    }); // Logo izquierdo
                    if (logo2Id !== null) sheet.addImage(logo2Id, {
                        tl: {
                            col: columns.length - 4,
                            row: 0
                        },
                        ext: {
                            width: 300,
                            height: 80
                        }
                    });

                    // Headers (6 Lines + Title)
                    const headerTexts = [
                        'INSTITUTO DE SALUD DEL ESTADO DE MÉXICO',
                        'COORDINACIÓN DE SALUD',
                        'DIRECCIÓN DE SERVICIOS DE SALUD',
                        'SUBDIRECCIÓN DE PREVENCIÓN Y CONTROL DE ENFERMEDADES',
                        'DEPARTAMENTO DE ZOONOSIS Y VECTORES',
                        'PROGRAMA DE PREVENCIÓN Y CONTROL DE LA RABIA'
                    ];

                    let headerRowIdx = 1;
                    headerTexts.forEach(text => {
                        const r = sheet.getRow(headerRowIdx);
                        r.getCell(2).value = text;
                        sheet.mergeCells(headerRowIdx, 2, headerRowIdx, columns.length - 1);
                        r.getCell(2).alignment = {
                            horizontal: 'center'
                        };
                        r.getCell(2).font = {
                            bold: true,
                            name: 'Arial',
                            size: 9
                        };
                        headerRowIdx++;
                    });

                    // Sheet Title (Row 7) - COLORES AZUL INSTITUCIONAL
                    const nameRow = sheet.getRow(7);
                    nameRow.getCell(1).value = sheetData.title.toUpperCase();
                    sheet.mergeCells(7, 1, 7, columns.length);
                    nameRow.getCell(1).alignment = {
                        horizontal: 'center',
                        vertical: 'middle'
                    };
                    nameRow.getCell(1).font = {
                        bold: true,
                        name: 'Calibri',
                        size: 12,
                        color: {
                            argb: 'FFFFFF'
                        }
                    };
                    nameRow.getCell(1).fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: {
                            argb: '1E40AF'
                        } // Azul institucional
                    };

                    // Column Headers (Row 8) - COLORES VIVOS
                    const headerRow = sheet.getRow(8);
                    let colIdx = 1;
                    columns.forEach(col => {
                        const cell = headerRow.getCell(colIdx);
                        cell.value = col.header;
                        cell.fill = headerFill;
                        cell.font = {
                            bold: true,
                            name: 'Calibri',
                            size: 9,
                            color: {
                                argb: 'FFFFFF'
                            }
                        };
                        cell.border = borderStyle;
                        cell.alignment = {
                            horizontal: 'center',
                            vertical: 'middle'
                        };
                        sheet.getColumn(colIdx).width = col.width;
                        colIdx++;
                    });

                    let currentRowIdx = 9;

                    // Colores para jurisdicción - AZUL CLARO como en imagen
                    const jurisFill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: {
                            argb: 'DEEAF6'
                        }
                    }; // Azul claro Excel
                    const altJurisFill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: {
                            argb: 'DEEAF6'
                        }
                    }; // Mismo color
                    let jurisColorIndex = 0;

                    // Data Rows con JURISDICCIÓN usando merge (solo aparece una vez por grupo)
                    for (const [juris, group] of Object.entries(sheetData.groups)) {
                        const startRow = currentRowIdx;
                        const numRows = group.rows.length;
                        const currentJurisFill = jurisColorIndex % 2 === 0 ? jurisFill : altJurisFill;
                        jurisColorIndex++;

                        group.rows.forEach((r, rowIndex) => {
                            const row = sheet.getRow(currentRowIdx);

                            // Jurisdicción solo en primera fila del grupo
                            if (rowIndex === 0) {
                                row.getCell(1).value = r.jurisdiccion;
                            }

                            row.getCell(2).value = r.municipio;
                            row.getCell(3).value = r.meta_anual;

                            let c = 4;
                            meses.forEach(m => {
                                row.getCell(c).value = r[m] || 0;
                                c++;
                            });
                            row.getCell(c).value = r.total_vertical;
                            c++;
                            row.getCell(c).value = r.logro_pct + '%';

                            // Aplicar estilos con BORDES VISIBLES
                            for (let i = 1; i <= columns.length; i++) {
                                row.getCell(i).border = borderThin;
                                row.getCell(i).font = fontStyle;
                                if (i >= 3) row.getCell(i).alignment = {
                                    horizontal: 'right'
                                };
                            }

                            // Color de logro - COLORES VIBRANTES
                            const logroCell = row.getCell(columns.length);
                            if (r.logro_pct < 70) logroCell.font = {
                                ...fontStyle,
                                color: {
                                    argb: 'C00000'
                                },
                                bold: true
                            }; // Rojo
                            else if (r.logro_pct >= 90) logroCell.font = {
                                ...fontStyle,
                                color: {
                                    argb: '006600'
                                },
                                bold: true
                            }; // Verde
                            else logroCell.font = {
                                ...fontStyle,
                                color: {
                                    argb: 'FF6600'
                                },
                                bold: true
                            }; // Naranja

                            currentRowIdx++;
                        });

                        // Merge jurisdicción cells
                        if (numRows > 1) {
                            sheet.mergeCells(startRow, 1, startRow + numRows - 1, 1);
                        }
                        // Aplicar estilo a jurisdicción CON BORDES
                        const jurisCell = sheet.getCell(startRow, 1);
                        jurisCell.fill = currentJurisFill;
                        jurisCell.font = {
                            name: 'Calibri',
                            size: 10,
                            bold: true
                        };
                        jurisCell.alignment = {
                            horizontal: 'center',
                            vertical: 'middle',
                            wrapText: true
                        };
                        jurisCell.border = {
                            top: {
                                style: 'thin',
                                color: {
                                    argb: 'FF000000'
                                }
                            },
                            left: {
                                style: 'thin',
                                color: {
                                    argb: 'FF000000'
                                }
                            },
                            bottom: {
                                style: 'thin',
                                color: {
                                    argb: 'FF000000'
                                }
                            },
                            right: {
                                style: 'thin',
                                color: {
                                    argb: 'FF000000'
                                }
                            }
                        };

                        // Subtotal
                        const subRow = sheet.getRow(currentRowIdx);
                        subRow.getCell(1).value = 'TOTAL';
                        sheet.mergeCells(currentRowIdx, 1, currentRowIdx, 2);
                        subRow.getCell(1).alignment = {
                            horizontal: 'right'
                        };

                        subRow.getCell(3).value = group.subtotal.meta;
                        let c = 4;
                        meses.forEach(m => {
                            subRow.getCell(c).value = group.subtotal.meses[m];
                            c++;
                        });
                        subRow.getCell(c).value = group.subtotal.total_vertical;
                        c++;
                        subRow.getCell(c).value = group.subtotal.logro_pct + '%';

                        for (let i = 1; i <= columns.length; i++) {
                            subRow.getCell(i).border = borderThin;
                            subRow.getCell(i).font = {
                                ...fontStyle,
                                bold: true
                            };
                            subRow.getCell(i).fill = subTotalFill;
                            if (i >= 3) subRow.getCell(i).alignment = {
                                horizontal: 'right'
                            };
                        }
                        currentRowIdx++;
                    }

                    // Grand Total - NARANJA VIBRANTE
                    const grandRow = sheet.getRow(currentRowIdx);
                    grandRow.getCell(1).value = 'GRAN TOTAL';
                    sheet.mergeCells(currentRowIdx, 1, currentRowIdx, 2);
                    grandRow.getCell(1).alignment = {
                        horizontal: 'center'
                    };

                    grandRow.getCell(3).value = sheetData.granTotal.meta;
                    let cIdx = 4;
                    meses.forEach(m => {
                        grandRow.getCell(cIdx).value = sheetData.granTotal.meses[m];
                        cIdx++;
                    });
                    grandRow.getCell(cIdx).value = sheetData.granTotal.total_vertical;
                    cIdx++;
                    grandRow.getCell(cIdx).value = sheetData.granTotal.logro_pct + '%';

                    for (let i = 1; i <= columns.length; i++) {
                        grandRow.getCell(i).border = borderMedium;
                        grandRow.getCell(i).font = {
                            ...fontStyle,
                            bold: true,
                            size: 10
                        };
                        grandRow.getCell(i).fill = grandTotalFill;
                        if (i >= 3) grandRow.getCell(i).alignment = {
                            horizontal: 'right'
                        };
                    }
                } // End Sheets Loop

                // --- SHEET: Evaluación Estatal (GRID LAYOUT) ---
                const sheet2 = workbook.addWorksheet('Evaluación estatal');

                // Header Sheet 2
                if (logo1Id !== null) sheet2.addImage(logo1Id, {
                    tl: {
                        col: 0,
                        row: 0
                    },
                    ext: {
                        width: 120,
                        height: 70
                    }
                });

                // Use same header text lines
                const headerTextsEval = [
                    'INSTITUTO DE SALUD DEL ESTADO DE MÉXICO',
                    'COORDINACIÓN DE SALUD',
                    'DIRECCIÓN DE SERVICIOS DE SALUD',
                    'SUBDIRECCIÓN DE PREVENCIÓN Y CONTROL DE ENFERMEDADES',
                    'DEPARTAMENTO DE REPZYV (REPORTE DE ZOONOSIS Y VECTORES)',
                    'PROGRAMA DE PREVENCIÓN Y CONTROL DE LA RABIA'
                ];
                let evalHeaderRowIdx = 1;
                headerTextsEval.forEach(text => {
                    const r = sheet2.getRow(evalHeaderRowIdx);
                    r.getCell(2).value = text;
                    // Estimate merge width for 2 tables side by side (approx 10-12cols)
                    sheet2.mergeCells(evalHeaderRowIdx, 2, evalHeaderRowIdx, 12);
                    r.getCell(2).alignment = {
                        horizontal: 'center'
                    };
                    r.getCell(2).font = {
                        bold: true,
                        name: 'Arial',
                        size: 9
                    };
                    evalHeaderRowIdx++;
                });

                // Title - COLORES AZUL INSTITUCIONAL
                const titleRow2 = sheet2.getRow(evalHeaderRowIdx);
                titleRow2.getCell(1).value = `EVALUACIÓN ESTATAL ${anios.split(',').join('-')}`;
                sheet2.mergeCells(evalHeaderRowIdx, 1, evalHeaderRowIdx, 12);
                titleRow2.getCell(1).alignment = {
                    horizontal: 'center',
                    vertical: 'middle'
                };
                titleRow2.getCell(1).font = {
                    bold: true,
                    name: 'Calibri',
                    size: 14,
                    color: {
                        argb: 'FFFFFF'
                    }
                };
                titleRow2.getCell(1).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: '1E40AF'
                    } // Azul institucional
                };
                evalHeaderRowIdx++;

                let currentRowGrid = evalHeaderRowIdx + 1;

                // Helper to render FULL evaluation table with 12 months (16 columns total)
                // Columns: Jurisdicción, Meta, Ene, Feb, Mar, Abr, May, Jun, Jul, Ago, Sep, Oct, Nov, Dic, Total, % Logro
                const renderFullEvalTable = (title, tableData, totalData, startRow, startCol) => {
                    let rIdx = startRow;
                    const numCols = 16; // Jurisdicción + Meta + 12 meses + Total + % Logro

                    // Title row - COLOR VIBRANTE CON BORDES
                    const tr = sheet2.getRow(rIdx);
                    tr.getCell(startCol).value = title.toUpperCase();
                    sheet2.mergeCells(rIdx, startCol, rIdx, startCol + numCols - 1);
                    tr.getCell(startCol).font = {
                        bold: true,
                        name: 'Calibri',
                        size: 10,
                        color: {
                            argb: 'FFFFFF'
                        }
                    };
                    tr.getCell(startCol).alignment = {
                        horizontal: 'center'
                    };
                    tr.getCell(startCol).fill = evalTitleFill;
                    tr.getCell(startCol).border = {
                        top: {
                            style: 'medium',
                            color: {
                                argb: 'FF000000'
                            }
                        },
                        left: {
                            style: 'medium',
                            color: {
                                argb: 'FF000000'
                            }
                        },
                        bottom: {
                            style: 'medium',
                            color: {
                                argb: 'FF000000'
                            }
                        },
                        right: {
                            style: 'medium',
                            color: {
                                argb: 'FF000000'
                            }
                        }
                    };
                    rIdx++;

                    // Headers row - COLORES AZULES
                    const headers = ['Jurisdicción Sanitaria', 'Meta Anual', 'Ene', 'Feb', 'Mzo', 'Abr', 'Mayo', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic', 'Total', '% Logro'];
                    const hr = sheet2.getRow(rIdx);
                    headers.forEach((h, idx) => {
                        const cell = hr.getCell(startCol + idx);
                        cell.value = h;
                        cell.fill = evalHeaderFill;
                        cell.font = {
                            bold: true,
                            name: 'Calibri',
                            size: 8,
                            color: {
                                argb: 'FFFFFF'
                            }
                        };
                        cell.border = borderStyle;
                        cell.alignment = {
                            horizontal: 'center'
                        };
                        if (h === '% Logro' || h === 'Total') {
                            cell.fill = {
                                type: 'pattern',
                                pattern: 'solid',
                                fgColor: {
                                    argb: '059669'
                                }
                            }; // Verde éxito
                        }
                    });
                    rIdx++;

                    // Data rows
                    tableData.forEach(row => {
                        const r = sheet2.getRow(rIdx);
                        r.getCell(startCol).value = row.jurisdiccion;
                        r.getCell(startCol + 1).value = row.meta;

                        // Monthly values
                        const mesesKeys = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
                        mesesKeys.forEach((m, idx) => {
                            r.getCell(startCol + 2 + idx).value = row[m] || 0;
                        });

                        r.getCell(startCol + 14).value = row.avance; // Total
                        r.getCell(startCol + 15).value = row.logro_pct + '%';

                        // Style all cells - COLORES AZULES
                        for (let i = 0; i < numCols; i++) {
                            r.getCell(startCol + i).border = borderStyle;
                            r.getCell(startCol + i).font = evalFontStyle;
                            if (i > 0) r.getCell(startCol + i).alignment = {
                                horizontal: 'right'
                            };
                        }
                        // Total y % con fondo azul muy claro
                        r.getCell(startCol + 14).fill = {
                            type: 'pattern',
                            pattern: 'solid',
                            fgColor: {
                                argb: 'DBEAFE'
                            }
                        };
                        r.getCell(startCol + 15).fill = {
                            type: 'pattern',
                            pattern: 'solid',
                            fgColor: {
                                argb: 'DBEAFE'
                            }
                        };

                        // Color de logro
                        const logroPct = row.logro_pct || 0;
                        if (logroPct < 70) {
                            r.getCell(startCol + 15).font = {
                                ...evalFontStyle,
                                color: {
                                    argb: 'DC2626'
                                },
                                bold: true
                            };
                        } else if (logroPct >= 90) {
                            r.getCell(startCol + 15).font = {
                                ...evalFontStyle,
                                color: {
                                    argb: '059669'
                                },
                                bold: true
                            };
                        } else {
                            r.getCell(startCol + 15).font = {
                                ...evalFontStyle,
                                color: {
                                    argb: 'D97706'
                                },
                                bold: true
                            };
                        }
                        rIdx++;
                    });

                    // Total row - VERDE VIBRANTE
                    const totR = sheet2.getRow(rIdx);
                    totR.getCell(startCol).value = 'TOTAL ESTATAL';
                    totR.getCell(startCol + 1).value = totalData.meta;

                    const mesesKeys = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
                    if (totalData.meses) {
                        mesesKeys.forEach((m, idx) => {
                            totR.getCell(startCol + 2 + idx).value = totalData.meses[m] || 0;
                        });
                    }

                    totR.getCell(startCol + 14).value = totalData.avance;
                    totR.getCell(startCol + 15).value = totalData.logro_pct + '%';

                    for (let i = 0; i < numCols; i++) {
                        totR.getCell(startCol + i).border = {
                            top: {
                                style: 'medium',
                                color: {
                                    argb: 'FF000000'
                                }
                            },
                            left: {
                                style: 'thin',
                                color: {
                                    argb: 'FF000000'
                                }
                            },
                            bottom: {
                                style: 'medium',
                                color: {
                                    argb: 'FF000000'
                                }
                            },
                            right: {
                                style: 'thin',
                                color: {
                                    argb: 'FF000000'
                                }
                            }
                        };
                        totR.getCell(startCol + i).font = {
                            ...evalFontStyle,
                            bold: true,
                            color: {
                                argb: 'FFFFFF'
                            }
                        };
                        if (i > 0) totR.getCell(startCol + i).alignment = {
                            horizontal: 'right'
                        };
                        totR.getCell(startCol + i).fill = evalTotalFill;
                    }
                    rIdx++;

                    return rIdx;
                };

                // Define table layout: 3 tables per row (matching original Excel)
                // Row 1: VACUNADOS | CONTROL | AGREDIDOS
                // Row 2: ESTERILIZADOS | MUESTRAS | TRATAMIENTOS
                const tableLayout = [
                    ['vacunados', 'control', 'agredidos'],
                    ['esterilizados', 'muestras', 'tratamientos']
                ];
                const tableWidth = 19; // 16 columns + 3 spacer columns

                let currentRow = currentRowGrid;
                tableLayout.forEach(rowTypes => {
                    let maxRows = 0;
                    rowTypes.forEach((tipo, colIndex) => {
                        if (data.evaluacionPorTipo && data.evaluacionPorTipo[tipo]) {
                            const evalData = data.evaluacionPorTipo[tipo];
                            const startCol = 1 + (colIndex * tableWidth);
                            const nextRow = renderFullEvalTable(
                                evalData.title,
                                evalData.data,
                                evalData.total,
                                currentRow,
                                startCol
                            );
                            maxRows = Math.max(maxRows, nextRow - currentRow);
                        }
                    });
                    currentRow += maxRows + 10; // Más espacio entre secciones de tablas
                });

                // Column Widths for 3 tables side by side - MÁS ESPACIO ENTRE TABLAS
                for (let t = 0; t < 3; t++) {
                    const base = 1 + (t * 19); // 16 cols + 3 spacer
                    sheet2.getColumn(base).width = 24; // Jurisdicción
                    sheet2.getColumn(base + 1).width = 12; // Meta
                    for (let m = 0; m < 12; m++) {
                        sheet2.getColumn(base + 2 + m).width = 8; // Meses
                    }
                    sheet2.getColumn(base + 14).width = 10; // Total
                    sheet2.getColumn(base + 15).width = 10; // % Logro
                    // 3 columnas de espacio entre tablas
                    sheet2.getColumn(base + 16).width = 3; // Spacer 1
                    sheet2.getColumn(base + 17).width = 3; // Spacer 2
                    sheet2.getColumn(base + 18).width = 3; // Spacer 3
                }

                // 8. Download
                const buffer = await workbook.xlsx.writeBuffer();
                saveAs(new Blob([buffer]), `Colaterales_${anios}.xlsx`);

                btn.innerHTML = originalText;

            } catch (e) {
                console.error(e);
                alert('Error al generar el Excel: ' + e.message);
                document.querySelector('.action-card.success').innerHTML = '<div class="icon"><i class="fas fa-exclamation-triangle"></i></div><div><div class="donut-title">Error</div><small>Reintentar</small></div>';
            }
        }
    </script>
</body>

</html>