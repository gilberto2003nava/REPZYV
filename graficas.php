<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/security.php';
establecerHeadersNoCache();

require_once 'partials/check_session.php';

require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/check_mantenimiento.php';

$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anioActual = isset($_GET['anio']) ? (int)$_GET['anio'] : ($config_sistema['anio_activo'] ?? date('Y'));

// Años disponibles para el selector
$anioInicio = 2020;
$anioFin = max((int)date('Y') + 1, $anioActual);
$aniosDisponibles = range($anioFin, $anioInicio);

$rol = $_SESSION['rol'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';

$tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
$todas_jurisdicciones = jurisdicciones();

$datosCompletos = [];

foreach ($todas_jurisdicciones as $juris) {
    if ($rol !== 'admin' && $juris !== $jurisdiccion_usuario) {
        continue;
    }

    // Totales para calcular % de logro REAL de la jurisdicción (no promedio)
    $totalMetaJuris = 0;
    $totalAvanceJuris = 0;

    $datosCompletos[$juris] = ['logro' => 0, 'totalMeta' => 0, 'totalAvance' => 0, 'lugares' => []];

    $sql = "SELECT DISTINCT municipio FROM registros WHERE jurisdiccion = ? AND anio = ? ORDER BY municipio";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'si', $juris, $anioActual);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($result)) {
        $muni = $row['municipio'];
        $esHospital = preg_match('/^H\./', $muni);

        // Totales para calcular % de logro REAL del municipio
        $totalMetaMuni = 0;
        $totalAvanceMuni = 0;

        $datosCompletos[$juris]['lugares'][$muni] = ['es_hospital' => $esHospital, 'tipos' => [], 'logro' => 0, 'totalMeta' => 0, 'totalAvance' => 0];

        $tiposValidos = $esHospital ? ['agredidos', 'tratamientos'] : $tipos;

        foreach ($tiposValidos as $tipo) {
            $sqlTipo = "SELECT meta_anual, (ene+feb+mar+abr+may+jun+jul+ago+sep+oct+nov+dic) as avance
                        FROM registros WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
            $stmtTipo = mysqli_prepare($conn, $sqlTipo);
            mysqli_stmt_bind_param($stmtTipo, 'sssi', $juris, $muni, $tipo, $anioActual);
            mysqli_stmt_execute($stmtTipo);
            $resTipo = mysqli_stmt_get_result($stmtTipo);
            $rowTipo = mysqli_fetch_assoc($resTipo);

            $meta = (int)($rowTipo['meta_anual'] ?? 0);
            $avance = (int)($rowTipo['avance'] ?? 0);
            // Porcentaje de logro por tipo (igual que en reportes)
            $porcentaje = $meta > 0 ? round(($avance / $meta) * 100, 2) : 0;

            $datosCompletos[$juris]['lugares'][$muni]['tipos'][$tipo] = ['meta' => $meta, 'avance' => $avance, 'porcentaje' => $porcentaje];

            // Sumar para el total del municipio
            $totalMetaMuni += $meta;
            $totalAvanceMuni += $avance;

            mysqli_stmt_close($stmtTipo);
        }

        // Calcular % de logro REAL del municipio (suma avances / suma metas)
        $logroMuni = $totalMetaMuni > 0 ? round(($totalAvanceMuni / $totalMetaMuni) * 100, 2) : 0;
        $datosCompletos[$juris]['lugares'][$muni]['logro'] = $logroMuni;
        $datosCompletos[$juris]['lugares'][$muni]['totalMeta'] = $totalMetaMuni;
        $datosCompletos[$juris]['lugares'][$muni]['totalAvance'] = $totalAvanceMuni;

        // Sumar para el total de la jurisdicción
        $totalMetaJuris += $totalMetaMuni;
        $totalAvanceJuris += $totalAvanceMuni;
    }
    mysqli_stmt_close($stmt);

    // Calcular % de logro REAL de la jurisdicción (suma avances / suma metas)
    $logroJuris = $totalMetaJuris > 0 ? round(($totalAvanceJuris / $totalMetaJuris) * 100, 2) : 0;
    $datosCompletos[$juris]['logro'] = $logroJuris;
    $datosCompletos[$juris]['totalMeta'] = $totalMetaJuris;
    $datosCompletos[$juris]['totalAvance'] = $totalAvanceJuris;
}

$jurisdiccionesArray = array_keys($datosCompletos);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Gráficas por Jurisdicción - Zoonosis <?= $anioActual ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/pptxgenjs@3.12.0/dist/pptxgen.bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xlsx-js-style@1.2.0/dist/xlsx.bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/exceljs@4.4.0/dist/exceljs.min.js"></script>
    <style>
        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: var(--radius-lg, 16px);
            padding: 1.25rem 1.5rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 20px rgba(30, 64, 175, 0.15);
            border: none;
            max-width: 100%
        }

        .page-header h1 {
            margin: 0 0 0.35rem 0;
            font-size: 1.5rem;
            font-weight: 800;
            color: white;
            line-height: 1.2
        }

        .page-header .subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.875rem;
            margin: 0
        }

        .page-header .export-btns {
            display: flex;
            gap: 0.5rem;
            margin-top: 1rem;
            flex-wrap: wrap
        }

        .page-header .export-btn {
            border: none;
            color: white;
            padding: 10px 18px;
            border-radius: 10px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1)
        }

        .page-header .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15)
        }

        .page-header .export-btn.pptx {
            background: linear-gradient(135deg, #ea580c 0%, #f97316 100%)
        }

        .page-header .export-btn.excel {
            background: linear-gradient(135deg, #16a34a 0%, #22c55e 100%)
        }

        .page-header label {
            color: white !important;
            font-weight: 600;
            font-size: 0.9rem
        }

        .page-header select {
            padding: 8px 12px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 600;
            background: rgba(255, 255, 255, 0.95);
            cursor: pointer;
            color: var(--text-primary, #1e293b)
        }


        /* Vista inicial: Grid de tarjetas */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem
        }

        .juris-card {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 3px solid transparent;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08)
        }

        .juris-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.15);
            border-color: #3b82f6
        }

        .juris-card.active {
            border-color: #2563eb;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)
        }

        .juris-card-content {
            text-align: center
        }

        .juris-donut {
            width: 150px;
            height: 150px;
            margin: 0 auto 1rem
        }

        .juris-name {
            font-weight: 800;
            font-size: 1.1rem;
            color: #1e293b;
            margin-bottom: 0.5rem
        }

        .juris-meta {
            font-size: 0.9rem;
            color: #64748b
        }

        /* Vista expandida */
        .graficas-layout {
            display: none;
            gap: 2rem;
            min-height: calc(100vh - 250px)
        }

        .graficas-layout.active {
            display: flex
        }

        .cards-grid.hidden {
            display: none
        }

        /* Botón hamburguesa para jurisdicciones en móvil */
        .juris-toggle-mobile {
            display: none;
            width: 100%;
            padding: 14px 18px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
            margin-bottom: 1rem
        }

        .juris-toggle-mobile:hover {
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4)
        }

        .juris-toggle-mobile .juris-chevron-icon {
            transition: transform 0.3s ease
        }

        .juris-toggle-mobile.active .juris-chevron-icon {
            transform: rotate(180deg)
        }

        .sidebar-jurisdicciones {
            width: 280px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            max-height: calc(100vh - 280px);
            overflow-y: auto;
            padding-right: 0.5rem
        }

        .sidebar-card {
            background: white;
            border-radius: 12px;
            padding: 0.75rem 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 2px solid transparent;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            align-items: center;
            gap: 0.75rem
        }

        .sidebar-card:hover {
            border-color: #3b82f6;
            background: #f8fafc
        }

        .sidebar-card.active {
            border-color: #2563eb;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%)
        }

        .sidebar-card .mini-donut {
            width: 50px;
            height: 50px
        }

        .sidebar-card .info {
            flex: 1
        }

        .sidebar-card .info .name {
            font-weight: 700;
            font-size: 0.85rem;
            color: #1e293b
        }

        .sidebar-card .info .meta {
            font-size: 0.75rem;
            color: #64748b
        }

        .main-panel {
            flex: 1;
            background: white;
            border-radius: 20px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            display: flex;
            flex-direction: column
        }

        .panel-header {
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            color: #1e293b;
            padding: 1.5rem 2rem;
            position: relative;
            border-bottom: 1px solid #cbd5e1
        }

        .panel-header h2 {
            margin: 0 0 0.75rem 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: #334155
        }

        .panel-header .promedio-grande {
            font-size: 2.5rem;
            font-weight: 800;
            margin-right: 0.75rem
        }

        .panel-header .promedio-label {
            font-size: 0.85rem;
            color: #64748b
        }

        .header-buttons {
            position: absolute;
            top: 1rem;
            right: 1rem;
            display: flex;
            gap: 0.5rem
        }

        .btn-action {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 10px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            font-size: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-action:hover {
            background: #e2e8f0;
            border-color: #94a3b8
        }

        .btn-action.pptx {
            background: #fff7ed;
            border-color: #fdba74;
            color: #ea580c
        }

        .btn-action.pptx:hover {
            background: #ffedd5
        }

        .btn-action.excel {
            background: #f0fdf4;
            border-color: #86efac;
            color: #16a34a
        }

        .btn-action.excel:hover {
            background: #dcfce7
        }

        .btn-action.close {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #dc2626
        }

        .btn-action.close:hover {
            background: #fee2e2
        }

        .panel-body {
            flex: 1;
            overflow-y: auto;
            padding: 2rem
        }

        .municipio-section {
            margin-bottom: 2.5rem;
            background: #f8fafc;
            border-radius: 16px;
            padding: 1.5rem;
            border-left: 5px solid #3b82f6
        }

        .municipio-section.hospital {
            border-left-color: #f59e0b;
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%)
        }

        .municipio-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem
        }

        .municipio-nombre {
            font-size: 1.2rem;
            font-weight: 800;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .municipio-promedio {
            font-size: 1.4rem;
            font-weight: 800;
            padding: 8px 20px;
            border-radius: 25px;
            color: white
        }

        .chart-wrapper {
            height: 250px
        }

        /* Modal de descarga */
        .download-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px)
        }

        .download-modal.active {
            display: flex
        }

        .download-modal-content {
            background: white;
            border-radius: 20px;
            padding: 2.5rem;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.25);
            max-width: 400px;
            width: 90%;
            animation: modalIn 0.3s ease
        }

        @keyframes modalIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px)
            }

            to {
                opacity: 1;
                transform: scale(1) translateY(0)
            }
        }

        .download-modal-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 1.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem
        }

        .download-modal-icon.loading {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #2563eb;
            animation: pulse 1.5s infinite
        }

        .download-modal-icon.success {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #16a34a
        }

        .download-modal-icon.error {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc2626
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1)
            }

            50% {
                transform: scale(1.05)
            }
        }

        .download-modal-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 0.5rem
        }

        .download-modal-text {
            color: #64748b;
            font-size: 0.95rem;
            margin-bottom: 1.5rem
        }

        .download-modal-progress {
            width: 100%;
            height: 8px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 1rem
        }

        .download-modal-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #2563eb);
            border-radius: 10px;
            transition: width 0.3s ease;
            animation: progressAnim 2s infinite
        }

        @keyframes progressAnim {
            0% {
                background-position: 0% 50%
            }

            50% {
                background-position: 100% 50%
            }

            100% {
                background-position: 0% 50%
            }
        }

        .download-modal-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: none
        }

        .download-modal-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4)
        }

        .download-modal-btn.show {
            display: inline-block
        }

        /* RESPONSIVO */
        @media (max-width: 992px) {
            .page-header {
                padding: 0.875rem 1rem;
                margin-bottom: 1rem
            }

            .page-header h1 {
                font-size: 1.25rem
            }

            .filter-bar {
                flex-direction: column;
                gap: 0.75rem;
                padding: 1rem
            }

            .filter-bar select,
            .filter-bar button {
                width: 100%
            }

            .export-buttons {
                flex-direction: column;
                width: 100%
            }

            .export-buttons .btn {
                width: 100%;
                justify-content: center
            }

            .charts-container {
                padding: 1rem
            }

            .chart-grid {
                grid-template-columns: 1fr
            }

            .chart-card {
                padding: 1rem
            }

            .summary-table {
                font-size: 0.8rem
            }

            .summary-table th,
            .summary-table td {
                padding: 8px 6px
            }

            .cards-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
                gap: 1rem
            }

            .juris-card {
                padding: 1rem
            }

            .juris-donut {
                width: 120px;
                height: 120px
            }

            /* Mostrar botón hamburguesa */
            .juris-toggle-mobile {
                display: flex
            }

            /* Layout en columna */
            .graficas-layout {
                flex-direction: column;
                gap: 1rem
            }

            /* Ocultar sidebar por defecto */
            .sidebar-jurisdicciones {
                display: none;
                width: 100%;
                max-height: 250px;
                background: white;
                border-radius: 12px;
                padding: 12px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                border: 1px solid #e2e8f0
            }

            .sidebar-jurisdicciones.show {
                display: flex
            }

            /* Panel principal ocupa todo el ancho */
            .main-panel {
                width: 100%
            }

            .panel-header {
                padding: 1rem
            }

            .panel-header h2 {
                font-size: 1.2rem
            }

            .panel-header .promedio-grande {
                font-size: 2rem
            }

            .header-buttons {
                position: static;
                margin-bottom: 1rem;
                justify-content: flex-end
            }

            .panel-body {
                padding: 1rem
            }

            .municipio-section {
                padding: 1rem
            }

            .municipio-nombre {
                font-size: 1rem
            }
        }

        @media (max-width: 576px) {
            .page-header {
                padding: 0.75rem 0.875rem
            }

            .page-header h1 {
                font-size: 1.1rem
            }

            .page-header .subtitle {
                font-size: 0.8rem
            }

            .page-header .export-btns {
                gap: 0.4rem;
                margin-top: 0.75rem
            }

            .page-header .export-btn {
                padding: 8px 14px;
                font-size: 0.8rem
            }

            .filter-bar {
                padding: 0.75rem;
                gap: 0.5rem
            }

            .filter-bar select {
                padding: 0.5rem;
                font-size: 0.85rem
            }

            .charts-container {
                padding: 0.75rem
            }

            .chart-card {
                padding: 0.75rem
            }

            .chart-card h3 {
                font-size: 1rem
            }

            .summary-table {
                font-size: 0.75rem
            }

            .summary-table th,
            .summary-table td {
                padding: 6px 4px
            }

            .download-modal-content {
                margin: 10px;
                padding: 1rem
            }

            .cards-grid {
                grid-template-columns: 1fr;
                gap: 0.75rem
            }

            .juris-card {
                padding: 1rem;
                border-radius: 14px
            }

            .juris-donut {
                width: 100px;
                height: 100px;
                margin-bottom: 0.75rem
            }

            .juris-name {
                font-size: 0.95rem
            }

            .juris-percent {
                font-size: 1.8rem
            }

            .juris-card-actions {
                gap: 0.5rem
            }

            .juris-card-actions .btn {
                padding: 8px 12px;
                font-size: 0.8rem
            }

            /* Sidebar más compacta */
            .sidebar-jurisdicciones {
                max-height: 200px;
                padding: 8px
            }

            .sidebar-card {
                padding: 0.5rem 0.75rem
            }

            .sidebar-card .mini-donut {
                width: 40px;
                height: 40px
            }

            .sidebar-card .info .name {
                font-size: 0.8rem
            }

            .sidebar-card .info .meta {
                font-size: 0.7rem
            }

            /* Panel header más compacto */
            .panel-header {
                padding: 0.75rem
            }

            .panel-header h2 {
                font-size: 1rem;
                margin-bottom: 0.5rem
            }

            .panel-header .promedio-grande {
                font-size: 1.6rem
            }

            .panel-header .promedio-label {
                font-size: 0.75rem
            }

            .header-buttons .btn-action {
                padding: 8px 10px;
                font-size: 0.85rem
            }

            .panel-body {
                padding: 0.75rem
            }

            .municipio-section {
                padding: 0.75rem;
                margin-bottom: 1.5rem
            }

            .municipio-nombre {
                font-size: 0.9rem
            }

            .municipio-promedio {
                font-size: 1.1rem;
                padding: 6px 14px
            }

            .chart-wrapper {
                height: 200px
            }
        }
    </style>
</head>

<body>
    <!-- Modal de descarga -->
    <div class="download-modal" id="downloadModal">
        <div class="download-modal-content">
            <div class="download-modal-icon loading" id="modalIcon">
                <i class="fas fa-file-download" id="modalIconI"></i>
            </div>
            <h3 class="download-modal-title" id="modalTitle">Generando archivo...</h3>
            <p class="download-modal-text" id="modalText">Por favor espera mientras se prepara tu descarga</p>
            <div class="download-modal-progress" id="modalProgress">
                <div class="download-modal-progress-bar" id="modalProgressBar" style="width:30%"></div>
            </div>
            <button class="download-modal-btn" id="modalBtn" onclick="closeDownloadModal()">Cerrar</button>
        </div>
    </div>

    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">

            <div class="page-header">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
                    <div style="flex:1;min-width:250px;">
                        <h1><i class="fas fa-chart-pie me-3"></i>Gráficas de Avance <?= $anioActual ?></h1>
                        <p class="subtitle">Haz clic en cualquier gráfica para ver el desglose por municipio</p>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.75rem;">
                        <label><i class="fas fa-calendar"></i> Año:</label>
                        <select id="selectAnio" onchange="cambiarAnio()">
                            <?php foreach ($aniosDisponibles as $a): ?>
                                <option value="<?= $a ?>" <?= $a == $anioActual ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="export-btns">
                    <button class="export-btn pptx" onclick="exportAllPPTX()"><i class="fas fa-file-powerpoint"></i> Exportar Todo a PowerPoint</button>
                    <button class="export-btn excel" onclick="exportExcelWithModal()"><i class="fas fa-file-excel"></i> Exportar Todo a Excel</button>
                </div>
            </div>

            <!-- Vista inicial: Solo las gráficas de % de logro -->
            <div class="cards-grid" id="cardsGrid">
                <?php $idx = 0;
                foreach ($datosCompletos as $juris => $datos): $logro = $datos['logro'];
                    $idx++; ?>
                    <div class="juris-card" data-idx="<?= $idx ?>" onclick="expandir(<?= $idx ?>)">
                        <div class="juris-card-content">
                            <div class="juris-donut"><canvas id="donut<?= $idx ?>"></canvas></div>
                            <div class="juris-name"><?= htmlspecialchars($juris) ?></div>
                            <div class="juris-meta"><?= count($datos['lugares']) ?> municipios/hospitales</div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Vista expandida: Sidebar + Panel detalle -->
            <div class="graficas-layout" id="graficasLayout">
                <!-- Botón hamburguesa para mostrar jurisdicciones en móvil -->
                <button class="juris-toggle-mobile" id="jurisToggle" onclick="toggleJurisSidebar()">
                    <i class="fas fa-list"></i>
                    <span id="jurisToggleText">Ver Jurisdicciones</span>
                    <i class="fas fa-chevron-down juris-chevron-icon"></i>
                </button>

                <div class="sidebar-jurisdicciones" id="sidebarJuris">
                    <?php $idx = 0;
                    foreach ($datosCompletos as $juris => $datos): $idx++; ?>
                        <div class="sidebar-card" data-idx="<?= $idx ?>" onclick="seleccionar(<?= $idx ?>)">
                            <div class="mini-donut"><canvas id="mini<?= $idx ?>"></canvas></div>
                            <div class="info">
                                <div class="name"><?= htmlspecialchars($juris) ?></div>
                                <div class="meta"><?= count($datos['lugares']) ?> lugares</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="main-panel">
                    <div class="panel-header">
                        <div class="header-buttons">
                            <button class="btn-action pptx" onclick="exportPPTX()" title="PowerPoint"><i class="fas fa-file-powerpoint"></i></button>
                            <button class="btn-action excel" onclick="exportExcel()" title="Excel"><i class="fas fa-file-excel"></i></button>
                            <button class="btn-action close" onclick="cerrar()" title="Cerrar"><i class="fas fa-times"></i></button>
                        </div>
                        <h2 id="panelTitle"></h2>
                        <div style="display:flex;align-items:baseline">
                            <span class="promedio-grande" id="panelPromedio"></span>
                            <span class="promedio-label">% de Logro (Avance / Meta)</span>
                        </div>
                    </div>
                    <div class="panel-body" id="panelBody"></div>
                </div>
            </div>
        </main>
    </div>

    <script>
        Chart.register(ChartDataLabels);
        Chart.defaults.font.size = 14;

        // Función para cambiar de año
        function cambiarAnio() {
            const anio = document.getElementById('selectAnio').value;
            window.location.href = 'graficas.php?anio=' + anio;
        }

        const d = <?= json_encode($datosCompletos) ?>;
        const jArr = <?= json_encode($jurisdiccionesArray) ?>;
        const colores = {
            vacunados: '#7DD3FC',
            esterilizados: '#86EFAC',
            control: '#93C5FD',
            muestras: '#FDE68A',
            agredidos: '#FCA5A5',
            tratamientos: '#C4B5FD'
        }; // Colores pastel
        const nombres = {
            vacunados: 'Vacunados',
            esterilizados: 'Esterilizaciones',
            control: 'Control',
            muestras: 'Muestras',
            agredidos: 'Agredidos',
            tratamientos: 'Tratamientos'
        };
        let chartsBar = {};
        let jurisActual = '';
        let miniChartsCreated = false;

        // ========== FUNCIONES DEL MODAL DE DESCARGA ==========
        function showDownloadModal(type, title, text) {
            const modal = document.getElementById('downloadModal');
            const icon = document.getElementById('modalIcon');
            const iconI = document.getElementById('modalIconI');
            const titleEl = document.getElementById('modalTitle');
            const textEl = document.getElementById('modalText');
            const progress = document.getElementById('modalProgress');
            const btn = document.getElementById('modalBtn');

            icon.className = 'download-modal-icon loading';
            iconI.className = type === 'excel' ? 'fas fa-file-excel' : 'fas fa-file-powerpoint';
            titleEl.textContent = title || 'Generando archivo...';
            textEl.textContent = text || 'Por favor espera mientras se prepara tu descarga';
            progress.style.display = 'block';
            btn.classList.remove('show');

            modal.classList.add('active');
        }

        function updateDownloadModal(status, title, text) {
            const icon = document.getElementById('modalIcon');
            const iconI = document.getElementById('modalIconI');
            const titleEl = document.getElementById('modalTitle');
            const textEl = document.getElementById('modalText');
            const progress = document.getElementById('modalProgress');
            const btn = document.getElementById('modalBtn');

            if (status === 'success') {
                icon.className = 'download-modal-icon success';
                iconI.className = 'fas fa-check';
                progress.style.display = 'none';
                btn.classList.add('show');
            } else if (status === 'error') {
                icon.className = 'download-modal-icon error';
                iconI.className = 'fas fa-times';
                progress.style.display = 'none';
                btn.classList.add('show');
            }

            titleEl.textContent = title;
            textEl.textContent = text;
        }

        function closeDownloadModal() {
            document.getElementById('downloadModal').classList.remove('active');
        }

        // Exportar Excel con modal
        function exportExcelWithModal() {
            showDownloadModal('excel', 'Generando Excel...', 'Preparando tablas y gráficos de todas las jurisdicciones');

            // Crear iframe oculto para la descarga
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.src = 'export_graficas_excel.php';
            document.body.appendChild(iframe);

            // Simular progreso y mostrar éxito después de un tiempo
            setTimeout(() => {
                updateDownloadModal('success', '¡Descarga lista!', 'El archivo Excel se ha generado correctamente');
                setTimeout(() => {
                    document.body.removeChild(iframe);
                }, 1000);
            }, 2500);
        }

        function gc(p) {
            return p >= 90 ? '#10b981' : (p >= 70 ? '#f59e0b' : '#ef4444')
        }

        // Crear donuts grandes en la vista inicial
        <?php $idx = 0;
        foreach ($datosCompletos as $juris => $datos): $idx++; ?>
                (function() {
                    const p = <?= $datos['logro'] ?>;
                    const c = gc(p);
                    new Chart(document.getElementById('donut<?= $idx ?>'), {
                        type: 'doughnut',
                        data: {
                            datasets: [{
                                data: [p, 100 - p],
                                backgroundColor: [c, '#e2e8f0'],
                                borderWidth: 0
                            }]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: true,
                            cutout: '65%',
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    enabled: false
                                },
                                datalabels: {
                                    display: false
                                }
                            }
                        },
                        plugins: [{
                            id: 'ct',
                            beforeDraw: ch => {
                                const ctx = ch.ctx,
                                    w = ch.width,
                                    h = ch.height;
                                ctx.save();
                                ctx.font = 'bold 28px sans-serif';
                                ctx.textAlign = 'center';
                                ctx.textBaseline = 'middle';
                                ctx.fillStyle = c;
                                ctx.fillText(p.toFixed(1) + '%', w / 2, h / 2);
                                ctx.restore();
                            }
                        }]
                    });
                })();
        <?php endforeach; ?>

        function crearMiniCharts() {
            if (miniChartsCreated) return;
            <?php $idx = 0;
            foreach ($datosCompletos as $juris => $datos): $idx++; ?>
                    (function() {
                        const p = <?= $datos['logro'] ?>;
                        const c = gc(p);
                        new Chart(document.getElementById('mini<?= $idx ?>'), {
                            type: 'doughnut',
                            data: {
                                datasets: [{
                                    data: [p, 100 - p],
                                    backgroundColor: [c, '#e2e8f0'],
                                    borderWidth: 0
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: true,
                                cutout: '60%',
                                plugins: {
                                    legend: {
                                        display: false
                                    },
                                    tooltip: {
                                        enabled: false
                                    },
                                    datalabels: {
                                        display: false
                                    }
                                }
                            },
                            plugins: [{
                                id: 'ct',
                                beforeDraw: ch => {
                                    const ctx = ch.ctx,
                                        w = ch.width,
                                        h = ch.height;
                                    ctx.save();
                                    ctx.font = 'bold 12px sans-serif';
                                    ctx.textAlign = 'center';
                                    ctx.textBaseline = 'middle';
                                    ctx.fillStyle = c;
                                    ctx.fillText(p.toFixed(0) + '%', w / 2, h / 2);
                                    ctx.restore();
                                }
                            }]
                        });
                    })();
            <?php endforeach; ?>
            miniChartsCreated = true;
        }

        function expandir(idx) {
            document.getElementById('cardsGrid').classList.add('hidden');
            document.getElementById('graficasLayout').classList.add('active');
            crearMiniCharts();
            seleccionar(idx);
        }

        // Toggle para sidebar de jurisdicciones en móvil
        function toggleJurisSidebar() {
            const btn = document.getElementById('jurisToggle');
            const sidebar = document.getElementById('sidebarJuris');
            btn.classList.toggle('active');
            sidebar.classList.toggle('show');

            const text = document.getElementById('jurisToggleText');
            text.textContent = sidebar.classList.contains('show') ? 'Ocultar Jurisdicciones' : 'Ver Jurisdicciones';
        }

        function seleccionar(idx) {
            document.querySelectorAll('.sidebar-card').forEach(c => c.classList.remove('active'));
            document.querySelector('.sidebar-card[data-idx="' + idx + '"]').classList.add('active');

            // Cerrar sidebar en móvil después de seleccionar
            if (window.innerWidth <= 992) {
                const btn = document.getElementById('jurisToggle');
                const sidebar = document.getElementById('sidebarJuris');
                btn.classList.remove('active');
                sidebar.classList.remove('show');
                document.getElementById('jurisToggleText').textContent = 'Ver Jurisdicciones';
            }

            jurisActual = jArr[idx - 1];
            const datos = d[jurisActual];
            const p = datos.logro;

            document.getElementById('panelTitle').innerHTML = '<i class="fas fa-map-marker-alt me-2"></i>' + jurisActual;
            document.getElementById('panelPromedio').textContent = p.toFixed(2) + '%';
            document.getElementById('panelPromedio').style.color = gc(p);

            Object.values(chartsBar).forEach(ch => ch.destroy());
            chartsBar = {};

            let html = '';
            const lugares = Object.entries(datos.lugares).sort((a, b) => {
                if (a[1].es_hospital !== b[1].es_hospital) return a[1].es_hospital ? 1 : -1;
                return a[0].localeCompare(b[0]);
            });

            let chartIdx = 0;
            lugares.forEach(([nom, inf]) => {
                chartIdx++;
                const eh = inf.es_hospital;
                const pl = inf.logro;
                const cl = gc(pl);

                html += `<div class="municipio-section ${eh?'hospital':''}">
            <div class="municipio-header">
                <span class="municipio-nombre"><i class="fas fa-${eh?'hospital':'city'}"></i>${nom}</span>
                <span class="municipio-promedio" style="background:${cl}">${pl.toFixed(2)}% Logro</span>
            </div>
            <div class="chart-wrapper"><canvas id="bar${chartIdx}"></canvas></div>
        </div>`;
            });

            document.getElementById('panelBody').innerHTML = html;

            chartIdx = 0;
            lugares.forEach(([nom, inf]) => {
                chartIdx++;
                const eh = inf.es_hospital;
                const tiposArr = eh ? ['agredidos', 'tratamientos'] : ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];

                const labels = tiposArr.map(t => nombres[t]);
                const dataVals = tiposArr.map(t => inf.tipos[t] ? inf.tipos[t].porcentaje : 0);
                const bgColors = tiposArr.map(t => colores[t]);

                chartsBar['bar' + chartIdx] = new Chart(document.getElementById('bar' + chartIdx), {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            data: dataVals,
                            backgroundColor: bgColors,
                            borderRadius: 8,
                            barThickness: 30
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: {
                            padding: {
                                right: 70
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                max: 100,
                                grid: {
                                    color: '#e2e8f0'
                                },
                                ticks: {
                                    font: {
                                        size: 12,
                                        weight: 600
                                    },
                                    callback: v => v + '%'
                                }
                            },
                            y: {
                                grid: {
                                    display: false
                                },
                                ticks: {
                                    font: {
                                        weight: 700,
                                        size: 13
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            datalabels: {
                                anchor: 'end',
                                align: 'end',
                                color: '#1e293b',
                                font: {
                                    weight: 'bold',
                                    size: 12
                                },
                                formatter: v => v.toFixed(2) + '%'
                            }
                        }
                    }
                });
            });
        }

        function cerrar() {
            document.getElementById('cardsGrid').classList.remove('hidden');
            document.getElementById('graficasLayout').classList.remove('active');
            document.querySelectorAll('.sidebar-card').forEach(c => c.classList.remove('active'));
            jurisActual = '';
        }

        async function exportPPTX() {
            if (!jurisActual) return;
            const pptx = new PptxGenJS();
            pptx.layout = 'LAYOUT_16x9';
            const datos = d[jurisActual];
            const logro = datos.logro;
            const colorP = logro >= 90 ? '16a34a' : (logro >= 70 ? 'ca8a04' : 'dc2626');
            const getColorP = (p) => p >= 90 ? '16a34a' : (p >= 70 ? 'ca8a04' : 'dc2626');
            const getEstado = (p) => p >= 90 ? 'EXCELENTE' : (p >= 70 ? 'REGULAR' : 'EN RIESGO');

            // Colores corporativos
            const colorPrimario = '1e3a5f';
            const bgColoresTipo = {
                vacunados: 'E0F7FA',
                esterilizados: 'E8F5E9',
                control: 'E3F2FD',
                muestras: 'FFFDE7',
                agredidos: 'FFEBEE',
                tratamientos: 'F3E5F5'
            };
            const coloresTitulo = {
                vacunados: '0891B2',
                esterilizados: '059669',
                control: '2563EB',
                muestras: 'D97706',
                agredidos: 'DC2626',
                tratamientos: '7C3AED'
            };

            // Capturar donut del sidebar
            const activeCard = document.querySelector('.sidebar-card.active .mini-donut canvas');
            const donutImg = activeCard ? activeCard.toDataURL('image/png') : null;

            // Estadísticas
            const numMunis = Object.values(datos.lugares).filter(l => !l.es_hospital).length;
            const numHosp = Object.values(datos.lugares).filter(l => l.es_hospital).length;

            // SLIDE 1: PORTADA
            let slide = pptx.addSlide();
            slide.bkgd = {
                color: 'f8fafc'
            };
            slide.addText('📍 ' + jurisActual, {
                x: 0.5,
                y: 0.2,
                w: '70%',
                h: 0.5,
                fontSize: 26,
                bold: true,
                color: colorPrimario
            });
            slide.addShape('rect', {
                x: 7,
                y: 0.2,
                w: 2.5,
                h: 0.5,
                fill: {
                    color: colorP
                }
            });
            slide.addText(logro.toFixed(2) + '% ' + getEstado(logro), {
                x: 7,
                y: 0.23,
                w: 2.5,
                h: 0.45,
                fontSize: 14,
                bold: true,
                color: 'FFFFFF',
                align: 'center'
            });

            if (donutImg) {
                slide.addImage({
                    data: donutImg,
                    x: 0.5,
                    y: 1,
                    w: 2.5,
                    h: 2.5
                });
            }

            // Tabla resumen de tipos
            const tiposArr = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
            const tiposData = [];
            for (const tipo of tiposArr) {
                let totalMeta = 0,
                    totalAvance = 0;
                for (const lugar of Object.values(datos.lugares)) {
                    if (lugar.tipos[tipo] && lugar.tipos[tipo].meta > 0) {
                        totalMeta += lugar.tipos[tipo].meta;
                        totalAvance += lugar.tipos[tipo].avance;
                    }
                }
                const logroTipo = totalMeta > 0 ? Math.round((totalAvance / totalMeta) * 100) : 0;
                tiposData.push({
                    tipo,
                    nombre: nombres[tipo],
                    logro: logroTipo,
                    meta: totalMeta,
                    avance: totalAvance
                });
            }

            const tablaTipos = [
                [{
                        text: 'TIPO',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF',
                            fontSize: 9
                        }
                    },
                    {
                        text: '% LOGRO',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF',
                            fontSize: 9
                        }
                    },
                    {
                        text: 'META',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF',
                            fontSize: 9
                        }
                    },
                    {
                        text: 'AVANCE',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF',
                            fontSize: 9
                        }
                    }
                ]
            ];
            tiposData.forEach(t => {
                const bgColor = bgColoresTipo[t.tipo] || 'F5F5F5';
                tablaTipos.push([{
                        text: t.nombre,
                        options: {
                            fontSize: 8,
                            fill: {
                                type: 'solid',
                                color: bgColor
                            }
                        }
                    },
                    {
                        text: t.logro + '%',
                        options: {
                            fontSize: 8,
                            bold: true,
                            color: getColorP(t.logro)
                        }
                    },
                    {
                        text: t.meta.toLocaleString(),
                        options: {
                            fontSize: 8
                        }
                    },
                    {
                        text: t.avance.toLocaleString(),
                        options: {
                            fontSize: 8
                        }
                    }
                ]);
            });

            slide.addTable(tablaTipos, {
                x: 3.3,
                y: 1,
                w: 6.5,
                colW: [2, 1.2, 1.65, 1.65],
                border: {
                    pt: 0.5,
                    color: 'CCCCCC'
                },
                fontFace: 'Arial'
            });
            slide.addText('🏙️ ' + numMunis + ' Municipios   |   🏥 ' + numHosp + ' Hospitales', {
                x: 3.3,
                y: 3.8,
                w: 6.5,
                h: 0.3,
                fontSize: 11,
                color: '475569',
                align: 'center'
            });

            // Ordenar lugares
            const lugares = Object.entries(datos.lugares).sort((a, b) => {
                if (a[1].es_hospital !== b[1].es_hospital) return a[1].es_hospital ? 1 : -1;
                return a[0].localeCompare(b[0]);
            });

            // SLIDES POR CADA TIPO (HOJA) CON TODOS LOS MUNICIPIOS
            tiposArr.forEach(tipo => {
                const tipoInfo = tiposData.find(t => t.tipo === tipo);
                if (!tipoInfo || tipoInfo.meta === 0) return; // Saltar si no hay datos

                slide = pptx.addSlide();
                const colorTitulo = coloresTitulo[tipo] || '1e3a5f';
                const bgColor = bgColoresTipo[tipo] || 'F5F5F5';

                // Header del tipo
                slide.addShape('rect', {
                    x: 0,
                    y: 0,
                    w: '100%',
                    h: 0.7,
                    fill: {
                        color: colorTitulo
                    }
                });
                slide.addText(nombres[tipo].toUpperCase(), {
                    x: 0.3,
                    y: 0.15,
                    w: 6,
                    h: 0.4,
                    fontSize: 22,
                    bold: true,
                    color: 'FFFFFF'
                });
                slide.addText(jurisActual, {
                    x: 6.5,
                    y: 0.15,
                    w: 3.3,
                    h: 0.2,
                    fontSize: 10,
                    color: 'FFFFFF',
                    align: 'right'
                });
                slide.addText('% Logro: ' + tipoInfo.logro + '%', {
                    x: 6.5,
                    y: 0.35,
                    w: 3.3,
                    h: 0.25,
                    fontSize: 14,
                    bold: true,
                    color: 'FFFFFF',
                    align: 'right'
                });

                // Tabla con TODOS los municipios para este tipo
                const tablaMunis = [
                    [{
                            text: 'MUNICIPIO/HOSPITAL',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: colorTitulo
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        },
                        {
                            text: 'META ANUAL',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: colorTitulo
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        },
                        {
                            text: 'AVANCE',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: colorTitulo
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        },
                        {
                            text: '% LOGRO',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: colorTitulo
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        }
                    ]
                ];

                let totalMeta = 0,
                    totalAvance = 0;
                lugares.forEach(([nom, inf]) => {
                    const eh = inf.es_hospital;
                    // Hospitales solo tienen agredidos y tratamientos
                    if (eh && !['agredidos', 'tratamientos'].includes(tipo)) return;

                    const tipoData = inf.tipos[tipo];
                    if (!tipoData || tipoData.meta === 0) return;

                    const meta = tipoData.meta;
                    const avance = tipoData.avance;
                    const pct = tipoData.porcentaje;
                    totalMeta += meta;
                    totalAvance += avance;

                    tablaMunis.push([{
                            text: (eh ? '[H] ' : '') + nom,
                            options: {
                                fontSize: 7,
                                fill: {
                                    type: 'solid',
                                    color: eh ? 'FFF7ED' : 'FFFFFF'
                                }
                            }
                        },
                        {
                            text: meta.toLocaleString(),
                            options: {
                                fontSize: 7,
                                align: 'right'
                            }
                        },
                        {
                            text: avance.toLocaleString(),
                            options: {
                                fontSize: 7,
                                align: 'right'
                            }
                        },
                        {
                            text: pct.toFixed(1) + '%',
                            options: {
                                fontSize: 7,
                                bold: true,
                                color: getColorP(pct),
                                align: 'right'
                            }
                        }
                    ]);
                });

                // Fila de TOTAL
                const pctTotal = totalMeta > 0 ? Math.round((totalAvance / totalMeta) * 100) : 0;
                tablaMunis.push([{
                        text: 'TOTAL',
                        options: {
                            bold: true,
                            fontSize: 8,
                            fill: {
                                type: 'solid',
                                color: 'E2E8F0'
                            }
                        }
                    },
                    {
                        text: totalMeta.toLocaleString(),
                        options: {
                            bold: true,
                            fontSize: 8,
                            fill: {
                                type: 'solid',
                                color: 'E2E8F0'
                            },
                            align: 'right'
                        }
                    },
                    {
                        text: totalAvance.toLocaleString(),
                        options: {
                            bold: true,
                            fontSize: 8,
                            fill: {
                                type: 'solid',
                                color: 'E2E8F0'
                            },
                            align: 'right'
                        }
                    },
                    {
                        text: pctTotal + '%',
                        options: {
                            bold: true,
                            fontSize: 8,
                            fill: {
                                type: 'solid',
                                color: 'E2E8F0'
                            },
                            color: getColorP(pctTotal),
                            align: 'right'
                        }
                    }
                ]);

                slide.addTable(tablaMunis, {
                    x: 0.3,
                    y: 0.85,
                    w: 9.4,
                    colW: [4.5, 1.6, 1.6, 1.7],
                    border: {
                        pt: 0.5,
                        color: 'CCCCCC'
                    },
                    fontFace: 'Arial'
                });

                // Gráfica de barras si hay espacio (máximo 10 municipios)
                const munisDatos = lugares.filter(([nom, inf]) => {
                    const eh = inf.es_hospital;
                    if (eh && !['agredidos', 'tratamientos'].includes(tipo)) return false;
                    return inf.tipos[tipo] && inf.tipos[tipo].meta > 0;
                }).slice(0, 10);

                if (munisDatos.length > 0 && munisDatos.length <= 10) {
                    // Solo mostrar nota si hay muchos municipios
                    if (lugares.length > 10) {
                        slide.addText('📊 Mostrando top 10 municipios en gráfica', {
                            x: 0.3,
                            y: 4.8,
                            w: 9,
                            h: 0.2,
                            fontSize: 8,
                            color: '64748b',
                            italic: true
                        });
                    }
                }
            });

            // SLIDE FINAL: REFERENCIAS
            slide = pptx.addSlide();
            slide.addText('📚 REFERENCIAS', {
                x: 0.5,
                y: 0.3,
                w: '90%',
                h: 0.5,
                fontSize: 22,
                bold: true,
                color: colorPrimario
            });

            const tablaRefs = [
                [{
                        text: 'ABREV',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF'
                        }
                    },
                    {
                        text: 'NOMBRE',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF'
                        }
                    },
                    {
                        text: 'DESCRIPCIÓN',
                        options: {
                            bold: true,
                            fill: {
                                type: 'solid',
                                color: '5B7FA3'
                            },
                            color: 'FFFFFF'
                        }
                    }
                ],
                [{
                    text: 'VAC',
                    options: {
                        fill: {
                            type: 'solid',
                            color: 'E0F7FA'
                        }
                    }
                }, {
                    text: 'Vacunados'
                }, {
                    text: 'Caninos y felinos vacunados contra rabia'
                }],
                [{
                    text: 'EST',
                    options: {
                        fill: {
                            type: 'solid',
                            color: 'E8F5E9'
                        }
                    }
                }, {
                    text: 'Esterilizados'
                }, {
                    text: 'Cirugías de esterilización realizadas'
                }],
                [{
                    text: 'CTRL',
                    options: {
                        fill: {
                            type: 'solid',
                            color: 'E3F2FD'
                        }
                    }
                }, {
                    text: 'Control'
                }, {
                    text: 'Acciones de control de fauna nociva'
                }],
                [{
                    text: 'MUES',
                    options: {
                        fill: {
                            type: 'solid',
                            color: 'FFFDE7'
                        }
                    }
                }, {
                    text: 'Muestras'
                }, {
                    text: 'Muestras enviadas a laboratorio'
                }],
                [{
                    text: 'AGR',
                    options: {
                        fill: {
                            type: 'solid',
                            color: 'FFEBEE'
                        }
                    }
                }, {
                    text: 'Agredidos'
                }, {
                    text: 'Personas atendidas por agresión animal'
                }],
                [{
                    text: 'TRAT',
                    options: {
                        fill: {
                            type: 'solid',
                            color: 'F3E5F5'
                        }
                    }
                }, {
                    text: 'Tratamientos'
                }, {
                    text: 'Tratamientos antirrábicos aplicados'
                }]
            ];

            slide.addTable(tablaRefs, {
                x: 0.5,
                y: 0.9,
                w: 9,
                colW: [1, 2, 6],
                border: {
                    pt: 0.5,
                    color: 'CCCCCC'
                },
                fontFace: 'Arial',
                fontSize: 10
            });

            slide.addShape('rect', {
                x: 0.5,
                y: 3.5,
                w: 9,
                h: 1,
                fill: {
                    color: 'f1f5f9'
                }
            });
            slide.addText('ESTADOS DE AVANCE:', {
                x: 0.7,
                y: 3.6,
                w: 8,
                h: 0.25,
                fontSize: 10,
                bold: true,
                color: '333333'
            });
            slide.addText('🟢 ≥90% EXCELENTE', {
                x: 0.7,
                y: 3.9,
                w: 3,
                h: 0.25,
                fontSize: 10,
                color: '16a34a',
                bold: true
            });
            slide.addText('🟡 70-89% REGULAR', {
                x: 3.7,
                y: 3.9,
                w: 3,
                h: 0.25,
                fontSize: 10,
                color: 'ca8a04',
                bold: true
            });
            slide.addText('🔴 <70% EN RIESGO', {
                x: 6.7,
                y: 3.9,
                w: 3,
                h: 0.25,
                fontSize: 10,
                color: 'dc2626',
                bold: true
            });

            pptx.writeFile({
                fileName: 'Reporte_' + jurisActual.replace(/\s/g, '_') + '_<?= $anioActual ?>.pptx'
            });
        }

        async function exportExcel() {
            if (!jurisActual) return;
            const datos = d[jurisActual];

            const lugares = Object.entries(datos.lugares).sort((a, b) => {
                if (a[1].es_hospital !== b[1].es_hospital) return a[1].es_hospital ? 1 : -1;
                return a[0].localeCompare(b[0]);
            });

            const numMunis = Object.values(datos.lugares).filter(l => !l.es_hospital).length;
            const numHosp = Object.values(datos.lugares).filter(l => l.es_hospital).length;
            const logro = datos.logro;

            // Crear workbook con ExcelJS
            const workbook = new ExcelJS.Workbook();
            workbook.creator = 'Sistema Zoonosis';
            workbook.created = new Date();

            // Colores
            const colorVerde = {
                argb: 'FF16A34A'
            };
            const colorAmarillo = {
                argb: 'FFCA8A04'
            };
            const colorRojo = {
                argb: 'FFDC2626'
            };
            const bgVerde = {
                argb: 'FFDCFCE7'
            };
            const bgAmarillo = {
                argb: 'FFFEF3C7'
            };
            const bgRojo = {
                argb: 'FFFEE2E2'
            };
            const getColor = (p) => p >= 90 ? colorVerde : (p >= 70 ? colorAmarillo : colorRojo);
            const getBg = (p) => p >= 90 ? bgVerde : (p >= 70 ? bgAmarillo : bgRojo);

            const tiposArr = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
            const coloresTipo = {
                vacunados: {
                    header: 'FF0891B2',
                    bg: 'FFE0F7FA'
                },
                esterilizados: {
                    header: 'FF059669',
                    bg: 'FFE8F5E9'
                },
                control: {
                    header: 'FF2563EB',
                    bg: 'FFE3F2FD'
                },
                muestras: {
                    header: 'FFD97706',
                    bg: 'FFFFFDE7'
                },
                agredidos: {
                    header: 'FFDC2626',
                    bg: 'FFFFEBEE'
                },
                tratamientos: {
                    header: 'FF7C3AED',
                    bg: 'FFF3E5F5'
                }
            };

            // HOJA RESUMEN
            const wsResumen = workbook.addWorksheet('Resumen');
            wsResumen.columns = [{
                width: 30
            }, {
                width: 15
            }, {
                width: 15
            }, {
                width: 15
            }];

            wsResumen.mergeCells('A1:D1');
            wsResumen.getCell('A1').value = jurisActual + ' - RESUMEN';
            wsResumen.getCell('A1').font = {
                bold: true,
                size: 16,
                color: {
                    argb: 'FFFFFFFF'
                }
            };
            wsResumen.getCell('A1').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: {
                    argb: 'FF1E3A5F'
                }
            };
            wsResumen.getCell('A1').alignment = {
                horizontal: 'center'
            };
            wsResumen.getRow(1).height = 30;

            wsResumen.getCell('A2').value = '% Logro General: ' + logro.toFixed(2) + '%';
            wsResumen.getCell('A2').font = {
                bold: true,
                size: 14,
                color: getColor(logro)
            };
            wsResumen.getCell('B2').value = 'Municipios: ' + numMunis;
            wsResumen.getCell('C2').value = 'Hospitales: ' + numHosp;

            // Tabla resumen por tipo
            const headerRow = wsResumen.getRow(4);
            ['TIPO', 'META ANUAL', 'AVANCE', '% LOGRO'].forEach((h, i) => {
                const cell = headerRow.getCell(i + 1);
                cell.value = h;
                cell.font = {
                    bold: true,
                    color: {
                        argb: 'FFFFFFFF'
                    }
                };
                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: 'FF475569'
                    }
                };
                cell.alignment = {
                    horizontal: 'center'
                };
            });

            let rowNum = 5;
            tiposArr.forEach(tipo => {
                let totalMeta = 0,
                    totalAvance = 0;
                for (const lugar of Object.values(datos.lugares)) {
                    if (lugar.tipos[tipo] && lugar.tipos[tipo].meta > 0) {
                        totalMeta += lugar.tipos[tipo].meta;
                        totalAvance += lugar.tipos[tipo].avance;
                    }
                }
                const logroTipo = totalMeta > 0 ? Math.round((totalAvance / totalMeta) * 100) : 0;

                const row = wsResumen.getRow(rowNum);
                row.getCell(1).value = nombres[tipo];
                row.getCell(1).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: coloresTipo[tipo].bg
                    }
                };
                row.getCell(2).value = totalMeta;
                row.getCell(2).numFmt = '#,##0';
                row.getCell(3).value = totalAvance;
                row.getCell(3).numFmt = '#,##0';
                row.getCell(4).value = logroTipo;
                row.getCell(4).numFmt = '0"%"';
                row.getCell(4).font = {
                    bold: true,
                    color: getColor(logroTipo)
                };
                row.getCell(4).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(logroTipo)
                };
                rowNum++;
            });

            // HOJAS POR CADA TIPO CON TODOS LOS MUNICIPIOS
            tiposArr.forEach(tipo => {
                const ws = workbook.addWorksheet(nombres[tipo].substring(0, 31));
                ws.columns = [{
                    width: 35
                }, {
                    width: 15
                }, {
                    width: 15
                }, {
                    width: 15
                }];

                const colorHeader = coloresTipo[tipo].header;
                const colorBg = coloresTipo[tipo].bg;

                // Título
                ws.mergeCells('A1:D1');
                ws.getCell('A1').value = nombres[tipo].toUpperCase() + ' - ' + jurisActual;
                ws.getCell('A1').font = {
                    bold: true,
                    size: 14,
                    color: {
                        argb: 'FFFFFFFF'
                    }
                };
                ws.getCell('A1').fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: colorHeader
                    }
                };
                ws.getCell('A1').alignment = {
                    horizontal: 'center'
                };
                ws.getRow(1).height = 28;

                // Headers
                const hRow = ws.getRow(3);
                ['MUNICIPIO/HOSPITAL', 'META ANUAL', 'AVANCE', '% LOGRO'].forEach((h, i) => {
                    const cell = hRow.getCell(i + 1);
                    cell.value = h;
                    cell.font = {
                        bold: true,
                        color: {
                            argb: 'FFFFFFFF'
                        }
                    };
                    cell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: {
                            argb: colorHeader
                        }
                    };
                    cell.alignment = {
                        horizontal: 'center'
                    };
                    cell.border = {
                        bottom: {
                            style: 'thin',
                            color: {
                                argb: 'FF000000'
                            }
                        }
                    };
                });

                let rNum = 4;
                let totalMeta = 0,
                    totalAvance = 0;

                lugares.forEach(([nom, inf]) => {
                    const eh = inf.es_hospital;
                    // Hospitales solo tienen agredidos y tratamientos
                    if (eh && !['agredidos', 'tratamientos'].includes(tipo)) return;

                    const tipoData = inf.tipos[tipo];
                    if (!tipoData || tipoData.meta === 0) return;

                    const meta = tipoData.meta;
                    const avance = tipoData.avance;
                    const pct = tipoData.porcentaje;
                    totalMeta += meta;
                    totalAvance += avance;

                    const row = ws.getRow(rNum);
                    row.getCell(1).value = (eh ? '[H] ' : '') + nom;
                    if (eh) row.getCell(1).font = {
                        italic: true,
                        color: {
                            argb: 'FFB45309'
                        }
                    };

                    row.getCell(2).value = meta;
                    row.getCell(2).numFmt = '#,##0';
                    row.getCell(2).alignment = {
                        horizontal: 'right'
                    };

                    row.getCell(3).value = avance;
                    row.getCell(3).numFmt = '#,##0';
                    row.getCell(3).alignment = {
                        horizontal: 'right'
                    };

                    row.getCell(4).value = pct;
                    row.getCell(4).numFmt = '0.0"%"';
                    row.getCell(4).font = {
                        bold: true,
                        color: getColor(pct)
                    };
                    row.getCell(4).fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: getBg(pct)
                    };
                    row.getCell(4).alignment = {
                        horizontal: 'right'
                    };

                    // Bordes
                    for (let c = 1; c <= 4; c++) {
                        row.getCell(c).border = {
                            top: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            },
                            bottom: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            },
                            left: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            },
                            right: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            }
                        };
                    }
                    rNum++;
                });

                // Fila TOTAL
                const pctTotal = totalMeta > 0 ? Math.round((totalAvance / totalMeta) * 100) : 0;
                const totalRow = ws.getRow(rNum);
                totalRow.getCell(1).value = 'TOTAL';
                totalRow.getCell(1).font = {
                    bold: true
                };
                totalRow.getCell(1).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: 'FFE2E8F0'
                    }
                };

                totalRow.getCell(2).value = totalMeta;
                totalRow.getCell(2).numFmt = '#,##0';
                totalRow.getCell(2).font = {
                    bold: true
                };
                totalRow.getCell(2).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: 'FFE2E8F0'
                    }
                };
                totalRow.getCell(2).alignment = {
                    horizontal: 'right'
                };

                totalRow.getCell(3).value = totalAvance;
                totalRow.getCell(3).numFmt = '#,##0';
                totalRow.getCell(3).font = {
                    bold: true
                };
                totalRow.getCell(3).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: 'FFE2E8F0'
                    }
                };
                totalRow.getCell(3).alignment = {
                    horizontal: 'right'
                };

                totalRow.getCell(4).value = pctTotal;
                totalRow.getCell(4).numFmt = '0"%"';
                totalRow.getCell(4).font = {
                    bold: true,
                    color: getColor(pctTotal)
                };
                totalRow.getCell(4).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(pctTotal)
                };
                totalRow.getCell(4).alignment = {
                    horizontal: 'right'
                };

                for (let c = 1; c <= 4; c++) {
                    totalRow.getCell(c).border = {
                        top: {
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
                        }
                    };
                }

                // Info del tipo
                ws.getCell('A' + (rNum + 2)).value = '📊 ' + nombres[tipo];
                ws.getCell('A' + (rNum + 2)).font = {
                    bold: true,
                    size: 11
                };
                ws.getCell('A' + (rNum + 3)).value = '🟢 ≥90% Excelente  |  🟡 70-89% Regular  |  🔴 <70% En Riesgo';
                ws.getCell('A' + (rNum + 3)).font = {
                    italic: true,
                    color: {
                        argb: 'FF64748B'
                    }
                };
            });

            // Descargar
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'Reporte_' + jurisActual.replace(/\s/g, '_') + '_<?= $anioActual ?>.xlsx';
            link.click();
            URL.revokeObjectURL(link.href);
        }

        // Exportar TODAS las jurisdicciones a PowerPoint con detalle por municipio
        async function exportAllPPTX() {
            try {
                showDownloadModal('pptx', 'Generando PowerPoint...', 'Preparando gráficas y tablas de todas las jurisdicciones');

                if (typeof PptxGenJS === 'undefined') {
                    updateDownloadModal('error', 'Error', 'La librería PptxGenJS no se cargó. Recarga la página con Ctrl+Shift+R');
                    return;
                }

                const pptx = new PptxGenJS();
                pptx.author = 'Sistema Zoonosis';
                pptx.title = 'Reporte de Avance <?= $anioActual ?>';
                pptx.layout = 'LAYOUT_16x9';

                // Colores pastel para gráficos
                const coloresPastelMap = {
                    vacunados: '7DD3FC',
                    esterilizados: '86EFAC',
                    control: '93C5FD',
                    muestras: 'FDE68A',
                    agredidos: 'FCA5A5',
                    tratamientos: 'C4B5FD'
                };
                const tiposAbrev = {
                    vacunados: 'VAC',
                    esterilizados: 'EST',
                    control: 'CTRL',
                    muestras: 'MUES',
                    agredidos: 'AGR',
                    tratamientos: 'TRAT'
                };
                const tiposNombres = {
                    vacunados: 'Vacunados',
                    esterilizados: 'Esterilizados',
                    control: 'Control',
                    muestras: 'Muestras',
                    agredidos: 'Agredidos',
                    tratamientos: 'Tratamientos'
                };

                // Función para obtener color según porcentaje
                const getColorP = (p) => p >= 90 ? '4CAF50' : (p >= 70 ? 'FF9800' : 'F44336');
                const getEstado = (p) => p >= 90 ? 'EXCELENTE' : (p >= 70 ? 'REGULAR' : 'EN RIESGO');

                // SLIDE 1: PORTADA
                let slide = pptx.addSlide();
                slide.bkgd = {
                    color: '4A6FA5'
                };
                slide.addText('REPORTE DE AVANCE', {
                    x: 0,
                    y: 2,
                    w: '100%',
                    h: 1,
                    fontSize: 44,
                    bold: true,
                    color: 'FFFFFF',
                    align: 'center'
                });
                slide.addText('PROGRAMA DE ZOONOSIS <?= $anioActual ?>', {
                    x: 0,
                    y: 3,
                    w: '100%',
                    h: 0.6,
                    fontSize: 24,
                    color: 'E0E7FF',
                    align: 'center'
                });
                slide.addText('Generado: ' + new Date().toLocaleDateString('es-MX'), {
                    x: 0,
                    y: 4.5,
                    w: '100%',
                    h: 0.4,
                    fontSize: 14,
                    color: 'B0C4DE',
                    align: 'center'
                });
                slide.addText(jArr.length + ' Jurisdicciones', {
                    x: 0,
                    y: 4.9,
                    w: '100%',
                    h: 0.3,
                    fontSize: 12,
                    color: 'B0C4DE',
                    align: 'center'
                });

                // SLIDE 2: RESUMEN POR JURISDICCIÓN (gráfica + tabla)
                slide = pptx.addSlide();
                slide.addText('RESUMEN POR JURISDICCIÓN', {
                    x: 0.3,
                    y: 0.15,
                    w: 9,
                    h: 0.4,
                    fontSize: 20,
                    bold: true,
                    color: '2C3E50'
                });

                // Filtrar jurisdicciones con datos
                const jurisConDatos = jArr.filter(j => d[j].logro > 0);

                // Gráfica de barras horizontal
                if (jurisConDatos.length > 0) {
                    const chartDataResumen = [{
                        name: '% Logro',
                        labels: jurisConDatos.map(j => j.substring(0, 12)),
                        values: jurisConDatos.map(j => d[j].logro)
                    }];

                    slide.addChart('bar', chartDataResumen, {
                        x: 0.2,
                        y: 0.6,
                        w: 5.8,
                        h: 4.6,
                        barDir: 'bar',
                        barGapWidthPct: 25,
                        showValue: true,
                        dataLabelPosition: 'outEnd',
                        dataLabelFontSize: 8,
                        dataLabelFontBold: true,
                        valAxisMaxVal: 100,
                        valAxisMinVal: 0,
                        catAxisLabelFontSize: 8,
                        chartColors: ['5B9BD5'],
                        showLegend: false
                    });
                }

                // Tabla resumen
                const tablaResumen = [
                    [{
                            text: 'JURISDICCIÓN',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: '4A6FA5'
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        },
                        {
                            text: '% LOGRO',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: '4A6FA5'
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        },
                        {
                            text: 'ESTADO',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: '4A6FA5'
                                },
                                color: 'FFFFFF',
                                fontSize: 8
                            }
                        }
                    ]
                ];
                jArr.forEach(j => {
                    const p = d[j].logro;
                    tablaResumen.push([{
                            text: j.substring(0, 18),
                            options: {
                                fontSize: 7
                            }
                        },
                        {
                            text: p.toFixed(1) + '%',
                            options: {
                                fontSize: 7,
                                bold: true,
                                color: getColorP(p)
                            }
                        },
                        {
                            text: getEstado(p),
                            options: {
                                fontSize: 6,
                                color: getColorP(p)
                            }
                        }
                    ]);
                });

                slide.addTable(tablaResumen, {
                    x: 6.2,
                    y: 0.6,
                    w: 3.6,
                    colW: [1.8, 0.8, 1],
                    border: {
                        pt: 0.5,
                        color: 'CCCCCC'
                    },
                    fontFace: 'Arial'
                });

                // SLIDES DE DETALLE POR JURISDICCIÓN (2 municipios por slide)
                for (let jIdx = 0; jIdx < jArr.length; jIdx++) {
                    const juris = jArr[jIdx];
                    const datos = d[juris];
                    const logroJuris = datos.logro;

                    // Ordenar lugares: primero municipios, luego hospitales
                    const lugares = Object.entries(datos.lugares).sort((a, b) => {
                        if (a[1].es_hospital !== b[1].es_hospital) return a[1].es_hospital ? 1 : -1;
                        return a[0].localeCompare(b[0]);
                    });

                    if (lugares.length === 0) continue;

                    // Crear slides con 2 municipios cada uno
                    for (let i = 0; i < lugares.length; i += 2) {
                        slide = pptx.addSlide();

                        // Header de la jurisdicción
                        slide.addShape('rect', {
                            x: 0,
                            y: 0,
                            w: '100%',
                            h: 0.55,
                            fill: {
                                color: '4A6FA5'
                            }
                        });
                        slide.addText((jIdx + 1) + '. ' + juris, {
                            x: 0.3,
                            y: 0.12,
                            w: 6,
                            h: 0.35,
                            fontSize: 16,
                            bold: true,
                            color: 'FFFFFF'
                        });
                        slide.addText(logroJuris.toFixed(1) + '% Logro General', {
                            x: 6.5,
                            y: 0.12,
                            w: 3.3,
                            h: 0.35,
                            fontSize: 12,
                            bold: true,
                            color: 'FFFFFF',
                            align: 'right'
                        });

                        // Procesar hasta 2 municipios
                        const municipiosEnSlide = lugares.slice(i, i + 2);

                        municipiosEnSlide.forEach((item, idx) => {
                            const [nombreMuni, infoMuni] = item;
                            const esHospital = infoMuni.es_hospital;
                            const logroMuni = infoMuni.logro;
                            const colorMuni = getColorP(logroMuni);

                            // Posición: izquierda (idx=0) o derecha (idx=1)
                            const xBase = idx === 0 ? 0.2 : 5.1;
                            const anchoBloque = 4.7;

                            // Título del municipio con badge de %
                            const bgMuni = esHospital ? 'FFF7ED' : 'F0F9FF';
                            const iconMuni = esHospital ? '🏥' : '🏙️';
                            slide.addShape('rect', {
                                x: xBase,
                                y: 0.7,
                                w: anchoBloque,
                                h: 0.45,
                                fill: {
                                    color: bgMuni
                                }
                            });
                            slide.addText(iconMuni + ' ' + nombreMuni.substring(0, 20), {
                                x: xBase + 0.1,
                                y: 0.75,
                                w: 3.2,
                                h: 0.35,
                                fontSize: 11,
                                bold: true,
                                color: '1E293B'
                            });
                            slide.addShape('rect', {
                                x: xBase + 3.4,
                                y: 0.75,
                                w: 1.2,
                                h: 0.32,
                                fill: {
                                    color: colorMuni
                                }
                            });
                            slide.addText(logroMuni.toFixed(1) + '%', {
                                x: xBase + 3.4,
                                y: 0.77,
                                w: 1.2,
                                h: 0.28,
                                fontSize: 10,
                                bold: true,
                                color: 'FFFFFF',
                                align: 'center'
                            });

                            // Tipos a mostrar
                            const tiposArr = esHospital ? ['agredidos', 'tratamientos'] : ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];

                            // Tabla de datos
                            const tablaMuni = [
                                [{
                                        text: 'TIPO',
                                        options: {
                                            bold: true,
                                            fill: {
                                                type: 'solid',
                                                color: '5B7FA3'
                                            },
                                            color: 'FFFFFF',
                                            fontSize: 7
                                        }
                                    },
                                    {
                                        text: 'META',
                                        options: {
                                            bold: true,
                                            fill: {
                                                type: 'solid',
                                                color: '5B7FA3'
                                            },
                                            color: 'FFFFFF',
                                            fontSize: 7
                                        }
                                    },
                                    {
                                        text: 'AVANCE',
                                        options: {
                                            bold: true,
                                            fill: {
                                                type: 'solid',
                                                color: '5B7FA3'
                                            },
                                            color: 'FFFFFF',
                                            fontSize: 7
                                        }
                                    },
                                    {
                                        text: '%',
                                        options: {
                                            bold: true,
                                            fill: {
                                                type: 'solid',
                                                color: '5B7FA3'
                                            },
                                            color: 'FFFFFF',
                                            fontSize: 7
                                        }
                                    }
                                ]
                            ];

                            const chartLabels = [];
                            const chartValues = [];
                            const chartColors = [];

                            tiposArr.forEach(tipo => {
                                const tipoData = infoMuni.tipos[tipo] || {
                                    meta: 0,
                                    avance: 0,
                                    porcentaje: 0
                                };
                                const pct = tipoData.porcentaje || 0;

                                tablaMuni.push([{
                                        text: tiposAbrev[tipo],
                                        options: {
                                            fontSize: 7,
                                            fill: {
                                                type: 'solid',
                                                color: coloresPastelMap[tipo].replace('#', '')
                                            }
                                        }
                                    },
                                    {
                                        text: (tipoData.meta || 0).toLocaleString(),
                                        options: {
                                            fontSize: 7,
                                            align: 'right'
                                        }
                                    },
                                    {
                                        text: (tipoData.avance || 0).toLocaleString(),
                                        options: {
                                            fontSize: 7,
                                            align: 'right'
                                        }
                                    },
                                    {
                                        text: pct.toFixed(0) + '%',
                                        options: {
                                            fontSize: 7,
                                            bold: true,
                                            color: getColorP(pct),
                                            align: 'right'
                                        }
                                    }
                                ]);

                                chartLabels.push(tiposAbrev[tipo]);
                                chartValues.push(pct);
                                chartColors.push(coloresPastelMap[tipo]);
                            });

                            slide.addTable(tablaMuni, {
                                x: xBase,
                                y: 1.2,
                                w: anchoBloque,
                                colW: [1.1, 1.2, 1.2, 1.2],
                                border: {
                                    pt: 0.5,
                                    color: 'CCCCCC'
                                },
                                fontFace: 'Arial'
                            });

                            // Gráfica de barras horizontal
                            const chartData = [{
                                name: '% Logro',
                                labels: chartLabels,
                                values: chartValues
                            }];

                            const alturaGrafica = esHospital ? 1.5 : 2.3;
                            slide.addChart('bar', chartData, {
                                x: xBase,
                                y: esHospital ? 2.6 : 3.0,
                                w: anchoBloque,
                                h: alturaGrafica,
                                barDir: 'bar',
                                barGapWidthPct: 40,
                                showValue: true,
                                dataLabelPosition: 'outEnd',
                                dataLabelFontSize: 8,
                                dataLabelFontBold: true,
                                valAxisMaxVal: 100,
                                valAxisMinVal: 0,
                                catAxisLabelFontSize: 9,
                                chartColors: chartColors,
                                showLegend: false
                            });
                        });

                        // Pie de página con número de slide
                        const numSlide = 2 + Math.floor(i / 2) + 1;
                        slide.addText('Slide ' + numSlide + ' | ' + juris, {
                            x: 0.3,
                            y: 5.1,
                            w: 9,
                            h: 0.2,
                            fontSize: 8,
                            color: '94A3B8'
                        });
                    }
                }

                // SLIDE FINAL: REFERENCIAS
                slide = pptx.addSlide();
                slide.addText('REFERENCIAS', {
                    x: 0.5,
                    y: 0.3,
                    w: 9,
                    h: 0.5,
                    fontSize: 22,
                    bold: true,
                    color: '2C3E50'
                });

                // Tabla de abreviaturas
                const tablaRefs = [
                    [{
                            text: 'ABREV',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: '4A6FA5'
                                },
                                color: 'FFFFFF'
                            }
                        },
                        {
                            text: 'NOMBRE',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: '4A6FA5'
                                },
                                color: 'FFFFFF'
                            }
                        },
                        {
                            text: 'DESCRIPCIÓN',
                            options: {
                                bold: true,
                                fill: {
                                    type: 'solid',
                                    color: '4A6FA5'
                                },
                                color: 'FFFFFF'
                            }
                        }
                    ],
                    [{
                        text: 'VAC',
                        options: {
                            fill: {
                                type: 'solid',
                                color: 'E0F7FA'
                            }
                        }
                    }, {
                        text: 'Vacunados'
                    }, {
                        text: 'Caninos y felinos vacunados contra rabia'
                    }],
                    [{
                        text: 'EST',
                        options: {
                            fill: {
                                type: 'solid',
                                color: 'E8F5E9'
                            }
                        }
                    }, {
                        text: 'Esterilizados'
                    }, {
                        text: 'Cirugías de esterilización realizadas'
                    }],
                    [{
                        text: 'CTRL',
                        options: {
                            fill: {
                                type: 'solid',
                                color: 'E3F2FD'
                            }
                        }
                    }, {
                        text: 'Control'
                    }, {
                        text: 'Acciones de control de fauna nociva'
                    }],
                    [{
                        text: 'MUES',
                        options: {
                            fill: {
                                type: 'solid',
                                color: 'FFFDE7'
                            }
                        }
                    }, {
                        text: 'Muestras'
                    }, {
                        text: 'Muestras enviadas a laboratorio'
                    }],
                    [{
                        text: 'AGR',
                        options: {
                            fill: {
                                type: 'solid',
                                color: 'FFEBEE'
                            }
                        }
                    }, {
                        text: 'Agredidos'
                    }, {
                        text: 'Personas atendidas por agresión animal'
                    }],
                    [{
                        text: 'TRAT',
                        options: {
                            fill: {
                                type: 'solid',
                                color: 'F3E5F5'
                            }
                        }
                    }, {
                        text: 'Tratamientos'
                    }, {
                        text: 'Tratamientos antirrábicos aplicados'
                    }]
                ];

                slide.addTable(tablaRefs, {
                    x: 0.5,
                    y: 0.9,
                    w: 9,
                    colW: [1, 2, 6],
                    border: {
                        pt: 0.5,
                        color: 'CCCCCC'
                    },
                    fontFace: 'Arial',
                    fontSize: 10
                });

                // Tabla de estados
                slide.addShape('rect', {
                    x: 0.5,
                    y: 3.5,
                    w: 9,
                    h: 1.3,
                    fill: {
                        color: 'F5F5F5'
                    }
                });
                slide.addText('ESTADOS DE AVANCE:', {
                    x: 0.7,
                    y: 3.6,
                    w: 8,
                    h: 0.3,
                    fontSize: 11,
                    bold: true,
                    color: '333333'
                });
                slide.addText('🟢 ≥90% = EXCELENTE', {
                    x: 0.7,
                    y: 3.95,
                    w: 3,
                    h: 0.25,
                    fontSize: 10,
                    color: '4CAF50',
                    bold: true
                });
                slide.addText('🟡 70-89% = REGULAR', {
                    x: 3.7,
                    y: 3.95,
                    w: 3,
                    h: 0.25,
                    fontSize: 10,
                    color: 'FF9800',
                    bold: true
                });
                slide.addText('🔴 <70% = EN RIESGO', {
                    x: 6.7,
                    y: 3.95,
                    w: 3,
                    h: 0.25,
                    fontSize: 10,
                    color: 'F44336',
                    bold: true
                });
                slide.addText('Nota: Los hospitales solo reportan Agredidos y Tratamientos', {
                    x: 0.7,
                    y: 4.35,
                    w: 8,
                    h: 0.25,
                    fontSize: 9,
                    italic: true,
                    color: '666666'
                });

                await pptx.writeFile({
                    fileName: 'Reporte_Graficas_<?= $anioActual ?>.pptx'
                });

                updateDownloadModal('success', '¡Descarga lista!', 'El archivo PowerPoint se ha generado correctamente');

            } catch (e) {
                console.error('Error en exportAllPPTX:', e);
                updateDownloadModal('error', 'Error al exportar', e.message);
            }
        }

        // Exportar TODAS las jurisdicciones a Excel con IMÁGENES de gráficas
        async function exportAllExcel() {
            const workbook = new ExcelJS.Workbook();
            workbook.creator = 'Sistema Zoonosis';
            workbook.created = new Date();

            // Colores
            const colorVerde = {
                argb: 'FF16A34A'
            };
            const colorAmarillo = {
                argb: 'FFCA8A04'
            };
            const colorRojo = {
                argb: 'FFDC2626'
            };
            const bgVerde = {
                argb: 'FFDCFCE7'
            };
            const bgAmarillo = {
                argb: 'FFFEF3C7'
            };
            const bgRojo = {
                argb: 'FFFEE2E2'
            };
            const bgAzul = {
                argb: 'FF1E3A5F'
            };
            const bgGris = {
                argb: 'FF475569'
            };
            const getColor = (p) => p >= 90 ? colorVerde : (p >= 70 ? colorAmarillo : colorRojo);
            const getBg = (p) => p >= 90 ? bgVerde : (p >= 70 ? bgAmarillo : bgRojo);
            const getEstado = (p) => p >= 90 ? 'EXCELENTE' : (p >= 70 ? 'REGULAR' : 'EN RIESGO');

            // ========== HOJA RESUMEN CON GRÁFICAS DONUT ==========
            const wsGraficas = workbook.addWorksheet('Gráficas Resumen');
            wsGraficas.columns = [{
                width: 22
            }, {
                width: 22
            }, {
                width: 22
            }, {
                width: 22
            }];

            // Título
            wsGraficas.mergeCells('A1:D1');
            wsGraficas.getCell('A1').value = '📊 REPORTE DE AVANCE ZOONOSIS <?= $anioActual ?>';
            wsGraficas.getCell('A1').font = {
                bold: true,
                size: 18,
                color: {
                    argb: 'FFFFFFFF'
                }
            };
            wsGraficas.getCell('A1').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAzul
            };
            wsGraficas.getCell('A1').alignment = {
                horizontal: 'center',
                vertical: 'middle'
            };
            wsGraficas.getRow(1).height = 40;

            wsGraficas.mergeCells('A2:D2');
            wsGraficas.getCell('A2').value = 'Gráficas de Promedio por Jurisdicción - ' + new Date().toLocaleDateString('es-MX');
            wsGraficas.getCell('A2').alignment = {
                horizontal: 'center'
            };

            // Capturar e insertar gráficas donut
            const donutCanvases = document.querySelectorAll('.juris-donut canvas');
            let imgRow = 4;

            for (let i = 0; i < donutCanvases.length && i < jArr.length; i++) {
                const col = (i % 3);
                if (i > 0 && col === 0) imgRow += 12;

                const imgData = donutCanvases[i].toDataURL('image/png');
                const imageId = workbook.addImage({
                    base64: imgData.split(',')[1],
                    extension: 'png'
                });

                wsGraficas.addImage(imageId, {
                    tl: {
                        col: col + 0.1,
                        row: imgRow - 1
                    },
                    ext: {
                        width: 140,
                        height: 140
                    }
                });

                // Nombre y promedio debajo
                const prom = d[jArr[i]].promedio;
                const cellRow = imgRow + 8;
                const colLetter = String.fromCharCode(65 + col);

                wsGraficas.getCell(colLetter + cellRow).value = jArr[i];
                wsGraficas.getCell(colLetter + cellRow).font = {
                    bold: true,
                    size: 10
                };
                wsGraficas.getCell(colLetter + cellRow).alignment = {
                    horizontal: 'center'
                };

                wsGraficas.getCell(colLetter + (cellRow + 1)).value = prom.toFixed(2) + '% - ' + getEstado(prom);
                wsGraficas.getCell(colLetter + (cellRow + 1)).font = {
                    bold: true,
                    color: getColor(prom)
                };
                wsGraficas.getCell(colLetter + (cellRow + 1)).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(prom)
                };
                wsGraficas.getCell(colLetter + (cellRow + 1)).alignment = {
                    horizontal: 'center'
                };
            }

            // Leyenda
            const legRow = imgRow + 12;
            wsGraficas.getCell('A' + legRow).value = '📝 LEYENDA:';
            wsGraficas.getCell('A' + legRow).font = {
                bold: true
            };
            wsGraficas.getCell('A' + (legRow + 1)).value = '🟢 ≥90% EXCELENTE';
            wsGraficas.getCell('A' + (legRow + 1)).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgVerde
            };
            wsGraficas.getCell('B' + (legRow + 1)).value = '🟡 70-89% REGULAR';
            wsGraficas.getCell('B' + (legRow + 1)).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAmarillo
            };
            wsGraficas.getCell('C' + (legRow + 1)).value = '🔴 <70% EN RIESGO';
            wsGraficas.getCell('C' + (legRow + 1)).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgRojo
            };

            // ========== HOJAS POR JURISDICCIÓN CON GRÁFICAS DE BARRAS ==========
            const cardsHidden = document.getElementById('cardsGrid').classList.contains('hidden');

            for (let jIdx = 0; jIdx < jArr.length; jIdx++) {
                const juris = jArr[jIdx];
                const datos = d[juris];
                const prom = datos.promedio;

                // Activar jurisdicción para renderizar gráficas
                if (!cardsHidden) expandir(jIdx + 1);
                else seleccionar(jIdx + 1);
                await new Promise(r => setTimeout(r, 400));

                const lugares = Object.entries(datos.lugares).sort((a, b) => {
                    if (a[1].es_hospital !== b[1].es_hospital) return a[1].es_hospital ? 1 : -1;
                    return a[0].localeCompare(b[0]);
                });

                const sheetName = juris.substring(0, 31).replace(/[\\\/\?\*\[\]]/g, '');
                const ws = workbook.addWorksheet(sheetName);
                ws.columns = [{
                    width: 90
                }];

                // Título
                ws.getCell('A1').value = '📍 ' + juris;
                ws.getCell('A1').font = {
                    bold: true,
                    size: 18,
                    color: {
                        argb: 'FFFFFFFF'
                    }
                };
                ws.getCell('A1').fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: bgAzul
                };
                ws.getCell('A1').alignment = {
                    horizontal: 'center',
                    vertical: 'middle'
                };
                ws.getRow(1).height = 40;

                // Info general
                const numMunis = Object.values(datos.lugares).filter(l => !l.es_hospital).length;
                const numHosp = Object.values(datos.lugares).filter(l => l.es_hospital).length;
                ws.getCell('A2').value = '📊 Promedio: ' + prom.toFixed(2) + '% - ' + getEstado(prom) + '  |  🏙️ ' + numMunis + ' Municipios  |  🏥 ' + numHosp + ' Hospitales';
                ws.getCell('A2').font = {
                    bold: true,
                    size: 12,
                    color: getColor(prom)
                };
                ws.getCell('A2').fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(prom)
                };
                ws.getCell('A2').alignment = {
                    horizontal: 'center'
                };
                ws.getRow(2).height = 30;

                // Capturar e insertar gráficas de barras
                const barCanvases = document.querySelectorAll('#panelBody canvas');
                let currentRow = 4;

                for (let i = 0; i < barCanvases.length && i < lugares.length; i++) {
                    const [nom, inf] = lugares[i];
                    const promL = inf.promedio;
                    const eh = inf.es_hospital;

                    // Título del municipio
                    ws.getCell('A' + currentRow).value = (eh ? '🏥 ' : '🏙️ ') + nom + ' - ' + promL.toFixed(2) + '% ' + getEstado(promL);
                    ws.getCell('A' + currentRow).font = {
                        bold: true,
                        size: 11,
                        color: getColor(promL)
                    };
                    ws.getCell('A' + currentRow).fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: getBg(promL)
                    };
                    ws.getRow(currentRow).height = 22;
                    currentRow++;

                    // Insertar imagen de la gráfica
                    const imgData = barCanvases[i].toDataURL('image/png');
                    const imageId = workbook.addImage({
                        base64: imgData.split(',')[1],
                        extension: 'png'
                    });
                    ws.addImage(imageId, {
                        tl: {
                            col: 0,
                            row: currentRow - 1
                        },
                        ext: {
                            width: 650,
                            height: 180
                        }
                    });
                    currentRow += 11;

                    // Anotación con detalles
                    const tiposArr = eh ? ['agredidos', 'tratamientos'] : ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
                    const nombresT = {
                        vacunados: '💉VAC',
                        esterilizados: '✂️EST',
                        control: '📋CTRL',
                        muestras: '🧪MUES',
                        agredidos: '⚠️AGR',
                        tratamientos: '💊TRAT'
                    };
                    let detalle = '    ';
                    tiposArr.forEach((t, idx) => {
                        const val = inf.tipos[t] ? inf.tipos[t].porcentaje.toFixed(1) : '0';
                        const meta = inf.tipos[t] ? inf.tipos[t].meta : 0;
                        const avance = inf.tipos[t] ? inf.tipos[t].avance : 0;
                        detalle += nombresT[t] + ': ' + val + '% (' + avance + '/' + meta + ')';
                        if (idx < tiposArr.length - 1) detalle += '  |  ';
                    });
                    ws.getCell('A' + currentRow).value = detalle;
                    ws.getCell('A' + currentRow).font = {
                        size: 9,
                        color: {
                            argb: 'FF64748B'
                        }
                    };
                    currentRow += 2;
                }

                // Leyenda al final
                ws.getCell('A' + currentRow).value = '═══════════════════════════════════════════════════════════════════════════════════════════════════';
                currentRow++;
                ws.getCell('A' + currentRow).value = '📝 REFERENCIAS: 💉VAC=Vacunados | ✂️EST=Esterilizados | 📋CTRL=Control | 🧪MUES=Muestras | ⚠️AGR=Agredidos | 💊TRAT=Tratamientos';
                ws.getCell('A' + currentRow).font = {
                    size: 9,
                    color: {
                        argb: 'FF475569'
                    }
                };
                currentRow++;
                ws.getCell('A' + currentRow).value = '🟢 ≥90% Excelente  |  🟡 70-89% Regular  |  🔴 <70% En Riesgo';
                ws.getCell('A' + currentRow).font = {
                    size: 9
                };
                if (numHosp > 0) {
                    currentRow++;
                    ws.getCell('A' + currentRow).value = '🏥 Los hospitales solo reportan Agredidos y Tratamientos';
                    ws.getCell('A' + currentRow).font = {
                        italic: true,
                        size: 9,
                        color: {
                            argb: 'FFB45309'
                        }
                    };
                }
            }

            // Volver a vista inicial
            if (!cardsHidden) cerrar();

            // Descargar
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'Reporte_Graficas_<?= $anioActual ?>.xlsx';
            link.click();
            URL.revokeObjectURL(link.href);
        }

        // Función legacy para compatibilidad - exporta datos tabulares
        async function exportAllExcelTabla() {
            const workbook = new ExcelJS.Workbook();
            workbook.creator = 'Sistema Zoonosis';
            workbook.created = new Date();

            // Colores
            const colorVerde = {
                argb: 'FF16A34A'
            };
            const colorAmarillo = {
                argb: 'FFCA8A04'
            };
            const colorRojo = {
                argb: 'FFDC2626'
            };
            const bgVerde = {
                argb: 'FFDCFCE7'
            };
            const bgAmarillo = {
                argb: 'FFFEF3C7'
            };
            const bgRojo = {
                argb: 'FFFEE2E2'
            };
            const bgAzul = {
                argb: 'FF1E3A5F'
            };
            const bgGris = {
                argb: 'FF475569'
            };
            const getColor = (p) => p >= 90 ? colorVerde : (p >= 70 ? colorAmarillo : colorRojo);
            const getBg = (p) => p >= 90 ? bgVerde : (p >= 70 ? bgAmarillo : bgRojo);
            const getEstado = (p) => p >= 90 ? 'EXCELENTE' : (p >= 70 ? 'REGULAR' : 'EN RIESGO');

            // ========== HOJA RESUMEN ==========
            const wsResumen = workbook.addWorksheet('Resumen');
            wsResumen.columns = [{
                width: 30
            }, {
                width: 14
            }, {
                width: 14
            }, {
                width: 12
            }, {
                width: 12
            }, {
                width: 10
            }];

            // Título
            wsResumen.mergeCells('A1:F1');
            const titleCell = wsResumen.getCell('A1');
            titleCell.value = 'REPORTE DE AVANCE ZOONOSIS <?= $anioActual ?>';
            titleCell.font = {
                bold: true,
                size: 18,
                color: {
                    argb: 'FFFFFFFF'
                }
            };
            titleCell.fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAzul
            };
            titleCell.alignment = {
                horizontal: 'center',
                vertical: 'middle'
            };
            wsResumen.getRow(1).height = 35;

            wsResumen.getCell('A2').value = 'Generado: ' + new Date().toLocaleDateString('es-MX');
            wsResumen.getCell('D2').value = 'Total: ' + jArr.length + ' Jurisdicciones';

            // Headers
            const headers = ['JURISDICCIÓN', 'PROMEDIO', 'ESTADO', 'MUNICIPIOS', 'HOSPITALES', 'TOTAL'];
            const headerRow = wsResumen.getRow(4);
            headers.forEach((h, i) => {
                const cell = headerRow.getCell(i + 1);
                cell.value = h;
                cell.font = {
                    bold: true,
                    color: {
                        argb: 'FFFFFFFF'
                    }
                };
                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: bgGris
                };
                cell.alignment = {
                    horizontal: 'center'
                };
            });

            // Datos
            let rowNum = 5;
            jArr.forEach(j => {
                const datos = d[j];
                const prom = datos.promedio;
                const numMunis = Object.values(datos.lugares).filter(l => !l.es_hospital).length;
                const numHosp = Object.values(datos.lugares).filter(l => l.es_hospital).length;
                const row = wsResumen.getRow(rowNum);

                row.getCell(1).value = j;
                row.getCell(2).value = prom;
                row.getCell(2).numFmt = '0.00"%"';
                row.getCell(2).font = {
                    bold: true,
                    color: getColor(prom)
                };
                row.getCell(2).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(prom)
                };
                row.getCell(3).value = getEstado(prom);
                row.getCell(3).font = {
                    bold: true,
                    color: getColor(prom)
                };
                row.getCell(3).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(prom)
                };
                row.getCell(4).value = numMunis;
                row.getCell(5).value = numHosp;
                row.getCell(6).value = numMunis + numHosp;

                for (let c = 1; c <= 6; c++) {
                    row.getCell(c).alignment = {
                        horizontal: 'center'
                    };
                    row.getCell(c).border = {
                        top: {
                            style: 'thin',
                            color: {
                                argb: 'FFE2E8F0'
                            }
                        },
                        bottom: {
                            style: 'thin',
                            color: {
                                argb: 'FFE2E8F0'
                            }
                        },
                        left: {
                            style: 'thin',
                            color: {
                                argb: 'FFE2E8F0'
                            }
                        },
                        right: {
                            style: 'thin',
                            color: {
                                argb: 'FFE2E8F0'
                            }
                        }
                    };
                }
                rowNum++;
            });

            // Datos para gráfico de resumen
            wsResumen.getCell('H4').value = 'Jurisdicción';
            wsResumen.getCell('I4').value = 'Promedio';
            jArr.forEach((j, i) => {
                wsResumen.getCell('H' + (5 + i)).value = j.substring(0, 15);
                wsResumen.getCell('I' + (5 + i)).value = d[j].promedio;
            });
            wsResumen.getColumn(8).hidden = true;
            wsResumen.getColumn(9).hidden = true;

            // Leyenda
            const legRow = rowNum + 2;
            wsResumen.getCell('A' + legRow).value = 'LEYENDA:';
            wsResumen.getCell('A' + legRow).font = {
                bold: true
            };
            wsResumen.getCell('A' + (legRow + 1)).value = '≥90%';
            wsResumen.getCell('A' + (legRow + 1)).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgVerde
            };
            wsResumen.getCell('B' + (legRow + 1)).value = 'Excelente';
            wsResumen.getCell('C' + (legRow + 1)).value = '70-89%';
            wsResumen.getCell('C' + (legRow + 1)).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAmarillo
            };
            wsResumen.getCell('D' + (legRow + 1)).value = 'Regular';
            wsResumen.getCell('E' + (legRow + 1)).value = '<70%';
            wsResumen.getCell('E' + (legRow + 1)).fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgRojo
            };
            wsResumen.getCell('F' + (legRow + 1)).value = 'En Riesgo';

            wsResumen.getCell('A' + (legRow + 3)).value = '💡 Para crear gráfico: Mostrar columnas H-I → Seleccionar H4:I' + (4 + jArr.length) + ' → Insertar → Gráfico de Barras';
            wsResumen.getCell('A' + (legRow + 3)).font = {
                italic: true,
                color: {
                    argb: 'FF64748B'
                }
            };

            // ========== HOJAS POR JURISDICCIÓN ==========
            jArr.forEach(juris => {
                const datos = d[juris];
                const lugares = Object.entries(datos.lugares).sort((a, b) => {
                    if (a[1].es_hospital !== b[1].es_hospital) return a[1].es_hospital ? 1 : -1;
                    return a[0].localeCompare(b[0]);
                });

                const numMunis = Object.values(datos.lugares).filter(l => !l.es_hospital).length;
                const numHosp = Object.values(datos.lugares).filter(l => l.es_hospital).length;
                const prom = datos.promedio;

                const sheetName = juris.substring(0, 31).replace(/[\\\/\?\*\[\]]/g, '');
                const ws = workbook.addWorksheet(sheetName);
                ws.columns = [{
                    width: 30
                }, {
                    width: 12
                }, {
                    width: 10
                }, {
                    width: 10
                }, {
                    width: 10
                }, {
                    width: 10
                }, {
                    width: 10
                }, {
                    width: 10
                }, {
                    width: 12
                }, {
                    width: 12
                }];

                // Título
                ws.mergeCells('A1:J1');
                const tc = ws.getCell('A1');
                tc.value = juris;
                tc.font = {
                    bold: true,
                    size: 16,
                    color: {
                        argb: 'FFFFFFFF'
                    }
                };
                tc.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: bgAzul
                };
                tc.alignment = {
                    horizontal: 'center',
                    vertical: 'middle'
                };
                ws.getRow(1).height = 30;

                // Info
                ws.getCell('A2').value = 'Promedio: ' + prom.toFixed(2) + '%';
                ws.getCell('A2').font = {
                    bold: true,
                    color: getColor(prom)
                };
                ws.getCell('A2').fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: getBg(prom)
                };
                ws.getCell('B2').value = getEstado(prom);
                ws.getCell('B2').font = {
                    bold: true,
                    color: getColor(prom)
                };
                ws.getCell('D2').value = 'Municipios: ' + numMunis;
                ws.getCell('E2').value = 'Hospitales: ' + numHosp;
                ws.getCell('F2').value = 'Total: ' + lugares.length;

                // Headers
                const hdrs = ['MUNICIPIO/HOSPITAL', 'PROM %', 'VAC %', 'EST %', 'CTRL %', 'MUES %', 'AGR %', 'TRAT %', 'META', 'AVANCE'];
                const hr = ws.getRow(4);
                hdrs.forEach((h, i) => {
                    const cell = hr.getCell(i + 1);
                    cell.value = h;
                    cell.font = {
                        bold: true,
                        color: {
                            argb: 'FFFFFFFF'
                        }
                    };
                    cell.fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: bgGris
                    };
                    cell.alignment = {
                        horizontal: 'center'
                    };
                });

                // Datos
                let rn = 5;
                lugares.forEach(([nom, inf]) => {
                    const eh = inf.es_hospital;
                    const promL = inf.promedio;
                    const row = ws.getRow(rn);

                    row.getCell(1).value = (eh ? '[H] ' : '') + nom;
                    if (eh) {
                        row.getCell(1).font = {
                            italic: true,
                            color: {
                                argb: 'FFB45309'
                            }
                        };
                        row.getCell(1).fill = {
                            type: 'pattern',
                            pattern: 'solid',
                            fgColor: {
                                argb: 'FFFFFBEB'
                            }
                        };
                    }

                    row.getCell(2).value = promL;
                    row.getCell(2).numFmt = '0.0"%"';
                    row.getCell(2).font = {
                        bold: true,
                        color: getColor(promL)
                    };
                    row.getCell(2).fill = {
                        type: 'pattern',
                        pattern: 'solid',
                        fgColor: getBg(promL)
                    };

                    const tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
                    tipos.forEach((t, i) => {
                        const cell = row.getCell(i + 3);
                        if (eh && !['agredidos', 'tratamientos'].includes(t)) {
                            cell.value = '-';
                            cell.font = {
                                color: {
                                    argb: 'FF94A3B8'
                                }
                            };
                        } else {
                            const val = inf.tipos[t] ? inf.tipos[t].porcentaje : 0;
                            cell.value = val;
                            cell.numFmt = '0.0"%"';
                            cell.font = {
                                color: getColor(val)
                            };
                            cell.fill = {
                                type: 'pattern',
                                pattern: 'solid',
                                fgColor: getBg(val)
                            };
                        }
                        cell.alignment = {
                            horizontal: 'center'
                        };
                    });

                    const tiposArr = eh ? ['agredidos', 'tratamientos'] : tipos;
                    let totalMeta = 0,
                        totalAvance = 0;
                    tiposArr.forEach(t => {
                        if (inf.tipos[t]) {
                            totalMeta += inf.tipos[t].meta;
                            totalAvance += inf.tipos[t].avance;
                        }
                    });
                    row.getCell(9).value = totalMeta;
                    row.getCell(10).value = totalAvance;

                    for (let c = 1; c <= 10; c++) {
                        row.getCell(c).border = {
                            top: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            },
                            bottom: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            },
                            left: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            },
                            right: {
                                style: 'thin',
                                color: {
                                    argb: 'FFE2E8F0'
                                }
                            }
                        };
                    }
                    rn++;
                });

                // Datos para gráfico
                ws.getCell('L4').value = 'Lugar';
                ws.getCell('M4').value = 'Promedio';
                lugares.forEach(([nom, inf], i) => {
                    ws.getCell('L' + (5 + i)).value = nom.substring(0, 20);
                    ws.getCell('M' + (5 + i)).value = inf.promedio;
                });
                ws.getColumn(12).hidden = true;
                ws.getColumn(13).hidden = true;

                // Notas
                ws.getCell('A' + (rn + 1)).value = '📊 Para gráfico: Mostrar columnas L-M → Seleccionar L4:M' + (4 + lugares.length) + ' → Insertar → Gráfico de Barras';
                ws.getCell('A' + (rn + 1)).font = {
                    italic: true,
                    color: {
                        argb: 'FF64748B'
                    }
                };
                ws.getCell('A' + (rn + 3)).value = '[H] = Hospital (solo Agredidos/Tratamientos)';
                ws.getCell('A' + (rn + 3)).font = {
                    italic: true,
                    color: {
                        argb: 'FF64748B'
                    }
                };
            });

            // ========== HOJA REFERENCIAS ==========
            const wsRef = workbook.addWorksheet('Referencias');
            wsRef.columns = [{
                width: 18
            }, {
                width: 25
            }, {
                width: 45
            }];

            wsRef.mergeCells('A1:C1');
            wsRef.getCell('A1').value = 'REFERENCIAS Y NOMENCLATURA';
            wsRef.getCell('A1').font = {
                bold: true,
                size: 16,
                color: {
                    argb: 'FFFFFFFF'
                }
            };
            wsRef.getCell('A1').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAzul
            };
            wsRef.getCell('A1').alignment = {
                horizontal: 'center'
            };
            wsRef.getRow(1).height = 30;

            const refHeaders = ['ABREVIATURA', 'NOMBRE', 'DESCRIPCIÓN'];
            refHeaders.forEach((h, i) => {
                const cell = wsRef.getRow(3).getCell(i + 1);
                cell.value = h;
                cell.font = {
                    bold: true,
                    color: {
                        argb: 'FFFFFFFF'
                    }
                };
                cell.fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: bgGris
                };
            });

            const refs = [
                ['VAC', 'Vacunados Antirrábicos', 'Caninos y felinos vacunados contra rabia', 'FFCFFAFE'],
                ['EST', 'Esterilizaciones', 'Cirugías de esterilización realizadas', 'FFD1FAE5'],
                ['CTRL', 'Control Poblacional', 'Acciones de control de fauna nociva', 'FFDBEAFE'],
                ['MUES', 'Muestras', 'Muestras enviadas a laboratorio', 'FFFEF3C7'],
                ['AGR', 'Agredidos', 'Personas atendidas por agresión animal', 'FFFEE2E2'],
                ['TRAT', 'Tratamientos', 'Tratamientos antirrábicos aplicados', 'FFEDE9FE']
            ];
            refs.forEach((r, i) => {
                const row = wsRef.getRow(4 + i);
                row.getCell(1).value = r[0];
                row.getCell(1).fill = {
                    type: 'pattern',
                    pattern: 'solid',
                    fgColor: {
                        argb: r[3]
                    }
                };
                row.getCell(1).font = {
                    bold: true
                };
                row.getCell(2).value = r[1];
                row.getCell(3).value = r[2];
            });

            wsRef.getCell('A11').value = 'INDICADORES:';
            wsRef.getCell('A11').font = {
                bold: true
            };
            wsRef.getCell('A12').value = '≥90%';
            wsRef.getCell('A12').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgVerde
            };
            wsRef.getCell('B12').value = 'EXCELENTE';
            wsRef.getCell('B12').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgVerde
            };
            wsRef.getCell('C12').value = 'Meta alcanzada o superada';
            wsRef.getCell('A13').value = '70-89%';
            wsRef.getCell('A13').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAmarillo
            };
            wsRef.getCell('B13').value = 'REGULAR';
            wsRef.getCell('B13').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgAmarillo
            };
            wsRef.getCell('C13').value = 'En proceso de cumplimiento';
            wsRef.getCell('A14').value = '<70%';
            wsRef.getCell('A14').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgRojo
            };
            wsRef.getCell('B14').value = 'EN RIESGO';
            wsRef.getCell('B14').fill = {
                type: 'pattern',
                pattern: 'solid',
                fgColor: bgRojo
            };
            wsRef.getCell('C14').value = 'Requiere atención inmediata';

            // Descargar
            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'Reporte_Datos_<?= $anioActual ?>.xlsx';
            link.click();
            URL.revokeObjectURL(link.href);
        }
    </script>
</body>

</html>