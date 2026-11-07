<?php

/**
 * MOVIMIENTO DE BIOLÓGICO CANINO
 * Formulario estilo Excel para capturistas / Vista tabla para admin
 */
session_start();
require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

require_once 'partials/check_session.php';

$rol = $_SESSION['rol'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';
$esAdmin = ($rol === 'admin');

// Cargar configuración
$config_file = __DIR__ . '/config_sistema.json';
$config = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$anioConfig = $config['anio_activo'] ?? date('Y');

// Año seleccionado (por GET o el configurado)
$anioSeleccionado = isset($_GET['anio']) ? (int)$_GET['anio'] : $anioConfig;

// Obtener años disponibles (desde 2020 hasta el año actual + 1)
$anioInicio = 2020;
$anioFin = max((int)date('Y') + 1, $anioConfig);
$aniosDisponibles = range($anioFin, $anioInicio);

$meses = [
    1 => 'Enero',
    2 => 'Febrero',
    3 => 'Marzo',
    4 => 'Abril',
    5 => 'Mayo',
    6 => 'Junio',
    7 => 'Julio',
    8 => 'Agosto',
    9 => 'Septiembre',
    10 => 'Octubre',
    11 => 'Noviembre',
    12 => 'Diciembre'
];

$jurisdicciones = jurisdicciones();

// Obtener datos existentes del año seleccionado
$datos = [];
$sql = "SELECT * FROM biologico_canino WHERE anio = ?";
if (!$esAdmin) {
    $sql .= " AND jurisdiccion = ?";
}
$sql .= " ORDER BY jurisdiccion, mes";

$stmt = mysqli_prepare($conn, $sql);
if ($esAdmin) {
    mysqli_stmt_bind_param($stmt, 'i', $anioSeleccionado);
} else {
    mysqli_stmt_bind_param($stmt, 'is', $anioSeleccionado, $jurisdiccion_usuario);
}
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
while ($row = mysqli_fetch_assoc($result)) {
    $datos[$row['jurisdiccion']][$row['mes']] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movimiento de Biológico Canino - <?= $anioSeleccionado ?></title>
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .page-header {
            background: linear-gradient(135deg, #0891b2 0%, #06b6d4 50%, #22d3ee 100%);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            color: white;
            margin-bottom: 1.5rem;
            box-shadow: 0 8px 30px rgba(8, 145, 178, 0.3);
        }

        .page-header h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        /* Contenedor principal */
        .main-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            overflow: hidden;
        }

        /* Selector de mes para capturista */
        .mes-selector {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
        }

        .mes-selector label {
            font-weight: 600;
            color: #475569;
        }

        .mes-selector select {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            min-width: 150px;
        }

        /* TABLA ESTILO EXCEL */
        .excel-table-container {
            overflow-x: auto;
            padding: 1rem;
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }

        .excel-table th,
        .excel-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: center;
            vertical-align: middle;
        }

        .excel-table thead th {
            background: #0891b2;
            color: white;
            font-weight: 600;
            font-size: 0.8rem;
        }

        .excel-table thead th.subheader {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.75rem;
        }

        .excel-table tbody td {
            background: white;
        }

        .excel-table tbody td.label-cell {
            background: #f1f5f9;
            font-weight: 600;
            text-align: left;
            color: #334155;
        }

        .excel-table tbody td.section-header {
            background: #0891b2;
            color: white;
            font-weight: 700;
            text-align: center;
        }

        .excel-table input[type="number"],
        .excel-table input[type="text"],
        .excel-table input[type="date"] {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 0.85rem;
            text-align: center;
        }

        .excel-table input:focus {
            border-color: #0891b2;
            outline: none;
            box-shadow: 0 0 0 2px rgba(8, 145, 178, 0.2);
        }

        .excel-table input.calculated {
            background: #f0fdf4;
            font-weight: 700;
            color: #16a34a;
            border-color: #86efac;
        }

        .excel-table textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 0.85rem;
            resize: vertical;
            min-height: 60px;
        }

        /* Botones */
        .actions-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
        }

        .btn-guardar {
            background: linear-gradient(135deg, #16a34a, #22c55e);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-guardar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(22, 163, 74, 0.4);
        }

        .btn-exportar {
            background: linear-gradient(135deg, #0891b2, #06b6d4);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }

        .btn-exportar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(8, 145, 178, 0.4);
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            padding: 1rem 1.5rem;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            display: none;
            z-index: 9999;
        }

        .toast.success {
            background: linear-gradient(135deg, #16a34a, #22c55e);
        }

        .toast.error {
            background: linear-gradient(135deg, #dc2626, #ef4444);
        }

        /* Admin: Filtros */
        .admin-filters {
            display: flex;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            align-items: center;
        }

        .admin-filters select {
            padding: 0.5rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-weight: 600;
        }

        /* Admin: Tabla resumen */
        .admin-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
        }

        .admin-table th {
            background: linear-gradient(135deg, #0891b2, #06b6d4);
            color: white;
            padding: 10px 6px;
            font-weight: 600;
            border: 1px solid #0891b2;
            position: sticky;
            top: 0;
        }

        .admin-table td {
            padding: 8px 6px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .admin-table tbody tr:hover {
            background: #f0f9ff;
        }

        .admin-table .jur-cell {
            text-align: left;
            font-weight: 600;
            background: #f8fafc;
            position: sticky;
            left: 0;
            z-index: 10;
        }

        .admin-table .total-row {
            background: #dbeafe;
            font-weight: 700;
        }

        .admin-table .total-row td {
            border-color: #93c5fd;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-badge.complete {
            background: #dcfce7;
            color: #16a34a;
        }

        .status-badge.pending {
            background: #fef3c7;
            color: #d97706;
        }

        .status-badge.empty {
            background: #f1f5f9;
            color: #64748b;
        }

        /* NUEVA TABLA ESTILO EXCEL HORIZONTAL */
        .excel-form-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            min-width: 900px;
        }

        .excel-form-table th {
            padding: 10px 8px;
            font-weight: 600;
            font-size: 0.75rem;
            text-align: center;
            border: 1px solid #94a3b8;
            color: white;
        }

        .excel-form-table th.th-green {
            background: linear-gradient(135deg, #059669, #10b981);
        }

        .excel-form-table th.th-blue {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
        }

        .excel-form-table th.th-orange {
            background: linear-gradient(135deg, #d97706, #f59e0b);
        }

        .excel-form-table th.th-red {
            background: linear-gradient(135deg, #dc2626, #ef4444);
        }

        .excel-form-table th.th-purple {
            background: linear-gradient(135deg, #7c3aed, #8b5cf6);
        }

        .excel-form-table th.th-cyan {
            background: linear-gradient(135deg, #0891b2, #06b6d4);
        }

        .excel-form-table tr.subheader th {
            background: #f1f5f9;
            color: #475569;
            font-size: 0.7rem;
            padding: 6px;
        }

        .excel-form-table td {
            padding: 8px 6px;
            border: 1px solid #cbd5e1;
            text-align: center;
            vertical-align: middle;
        }

        .excel-form-table input[type="number"],
        .excel-form-table input[type="text"],
        .excel-form-table input[type="date"] {
            width: 100%;
            padding: 8px 6px;
            border: 2px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.9rem;
            text-align: center;
            transition: all 0.2s;
        }

        .excel-form-table input:focus {
            border-color: #0891b2;
            outline: none;
            box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.15);
        }

        .excel-form-table input[type="number"] {
            width: 90px;
        }

        .excel-form-table input[type="text"] {
            width: 100px;
        }

        .excel-form-table input[type="date"] {
            width: 130px;
        }

        .excel-form-table input.readonly-inherited {
            background: #f0fdf4;
            border-color: #86efac;
            color: #16a34a;
            font-weight: 600;
        }

        .excel-form-table .calc-cell {
            background: #fef3c7;
        }

        .excel-form-table .calc-value {
            font-size: 1.1rem;
            font-weight: 700;
            color: #b45309;
        }

        .excel-form-table td:nth-child(10) .calc-value {
            color: #0891b2;
        }

        .excel-form-table td:nth-child(10) {
            background: #e0f2fe;
        }

        /* RESPONSIVO */
        @media (max-width: 992px) {
            .page-header {
                padding: 1rem;
                margin-bottom: 1rem;
            }

            .page-header h1 {
                font-size: 1.2rem;
            }

            .main-container {
                border-radius: 12px;
            }

            .mes-selector {
                flex-wrap: wrap;
                gap: 0.5rem;
                padding: 0.75rem;
            }

            .mes-selector select {
                min-width: 120px;
            }

            .admin-filters {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }

            .excel-table-container {
                padding: 0.5rem;
            }

            .admin-table,
            .excel-form-table {
                font-size: 0.7rem;
            }

            .admin-table th,
            .admin-table td {
                padding: 6px 4px;
            }

            .actions-bar {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .btn-guardar,
            .btn-exportar {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .page-header h1 {
                font-size: 1rem;
            }

            .page-header p {
                font-size: 0.8rem;
            }

            .mes-selector {
                padding: 0.5rem;
            }

            .mes-selector label {
                font-size: 0.85rem;
            }

            .mes-selector select {
                font-size: 0.85rem;
                padding: 0.4rem;
            }

            .excel-form-table input {
                font-size: 0.8rem;
                padding: 6px 4px;
            }

            .excel-form-table input[type="number"] {
                width: 70px;
            }

            .excel-form-table input[type="text"] {
                width: 80px;
            }

            .excel-form-table input[type="date"] {
                width: 110px;
            }

            .excel-form-table th {
                font-size: 0.65rem;
                padding: 6px 4px;
            }

            .calc-value {
                font-size: 0.95rem;
            }
        }

        /* Botones de acción */
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 2px;
        }

        .btn-edit {
            background: #eff6ff;
            color: #3b82f6;
        }

        .btn-edit:hover {
            background: #3b82f6;
            color: #fff;
            transform: scale(1.1);
        }

        .btn-delete {
            background: #fef2f2;
            color: #ef4444;
        }

        .btn-delete:hover {
            background: #ef4444;
            color: #fff;
            transform: scale(1.1);
        }

        /* Modales */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
            backdrop-filter: blur(4px);
        }

        .modal-container {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            z-index: 9999;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            padding: 1.5rem;
            border-radius: 16px 16px 0 0;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .modal-header.delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
        }

        .modal-header i {
            font-size: 1.5rem;
        }

        .modal-body {
            padding: 2rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 2px solid #f1f5f9;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
        }

        .form-group-modal {
            margin-bottom: 1.5rem;
        }

        .form-group-modal label {
            display: block;
            font-weight: 600;
            color: #475569;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .form-group-modal input {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .form-group-modal input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-modal {
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-cancel {
            background: #f1f5f9;
            color: #64748b;
        }

        .btn-cancel:hover {
            background: #e2e8f0;
        }

        .btn-save {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .btn-confirm-delete {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
        }

        .btn-confirm-delete:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }
    </style>
</head>

<body>
    <div class="toast" id="toast"></div>

    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">

            <div class="page-header">
                <h1><i class="fas fa-syringe"></i> Movimiento de Biológico Canino <?= $anioSeleccionado ?></h1>
                <p>Registro de vacuna antirrábica canina - <?= $esAdmin ? 'Vista Administrador' : htmlspecialchars($jurisdiccion_usuario) ?></p>
            </div>


            <?php if ($esAdmin): ?>
                <!-- ========== VISTA ADMINISTRADOR ========== -->
                <div class="main-container">
                    <div class="admin-filters">
                        <div>
                            <label style="font-weight:600;color:#475569;margin-right:8px;"><i class="fas fa-calendar"></i> Año:</label>
                            <select id="filterAnio" onchange="cambiarAnio()">
                                <?php foreach ($aniosDisponibles as $a): ?>
                                    <option value="<?= $a ?>" <?= $a == $anioSeleccionado ? 'selected' : '' ?>><?= $a ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="font-weight:600;color:#475569;margin-right:8px;"><i class="fas fa-filter"></i> Mes:</label>
                            <select id="filterMes" onchange="filtrarTabla()">
                                <option value="">Todos los meses</option>
                                <?php foreach ($meses as $num => $nombre): ?>
                                    <option value="<?= $num ?>"><?= $nombre ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="margin-left:auto;display:flex;gap:10px;">
                            <button class="btn-exportar" onclick="abrirModalEditarProducto()" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">
                                <i class="fas fa-edit"></i> Editar Producto
                            </button>
                            <button class="btn-exportar" onclick="exportarExcel()">
                                <i class="fas fa-file-excel"></i> Exportar a Excel
                            </button>
                        </div>
                    </div>

                    <div class="excel-table-container" style="max-height:70vh;overflow:auto;">
                        <table class="admin-table" id="tablaAdmin">
                            <thead>
                                <tr>
                                    <th rowspan="2" style="min-width:180px;position:sticky;left:0;z-index:20;background:#0891b2;">Jurisdicción</th>
                                    <th rowspan="2">Biológico</th>
                                    <th rowspan="2">Presentación</th>
                                    <th colspan="3" style="background:#059669;">Existencia Inicio</th>
                                    <th colspan="3" style="background:#2563eb;">Dosis Recibidas</th>
                                    <th rowspan="2" style="background:#d97706;">Total Dosis</th>
                                    <th rowspan="2" style="background:#dc2626;">Dosis Admin.</th>
                                    <th rowspan="2" style="background:#7c3aed;">Desperdicio</th>
                                    <th colspan="3" style="background:#0891b2;">Existencia Fin</th>
                                    <th rowspan="2" style="background:#64748b;min-width:100px;position:sticky;right:0;z-index:20;">Acciones</th>
                                </tr>
                                <tr>
                                    <th style="background:#10b981;font-size:0.7rem;">Dosis</th>
                                    <th style="background:#10b981;font-size:0.7rem;">Lote</th>
                                    <th style="background:#10b981;font-size:0.7rem;">Fecha Cad.</th>
                                    <th style="background:#3b82f6;font-size:0.7rem;">Dosis</th>
                                    <th style="background:#3b82f6;font-size:0.7rem;">Lote</th>
                                    <th style="background:#3b82f6;font-size:0.7rem;">Fecha Cad.</th>
                                    <th style="background:#06b6d4;font-size:0.7rem;">Dosis</th>
                                    <th style="background:#06b6d4;font-size:0.7rem;">Lote</th>
                                    <th style="background:#06b6d4;font-size:0.7rem;">Fecha Cad.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $totales = ['inicio' => 0, 'recibidas' => 0, 'total' => 0, 'admin' => 0, 'desp' => 0, 'fin' => 0];
                                $mesSeleccionado = isset($_GET['mes']) ? (int)$_GET['mes'] : null;

                                // Iterar por cada mes (1-12)
                                for ($mes = 1; $mes <= 12; $mes++) {
                                    // Si hay filtro de mes, saltar los otros meses
                                    if ($mesSeleccionado && $mes != $mesSeleccionado) {
                                        continue;
                                    }

                                    // Mostrar encabezado del mes
                                    echo '<tr style="background:#0891b2;color:white;font-weight:700;"><td colspan="16" style="padding:10px;text-align:center;font-size:1rem;"><i class="fas fa-calendar"></i> ' . $meses[$mes] . ' (' . $anioSeleccionado . ')</td></tr>';

                                    // Mostrar todas las jurisdicciones para este mes
                                    foreach ($jurisdicciones as $jur) {
                                        $d = $datos[$jur][$mes] ?? null;

                                        // Calcular existencia inicio heredada del mes anterior
                                        $existenciaInicioHeredada = null;
                                        if ($mes > 1) {
                                            if (isset($datos[$jur][$mes - 1])) {
                                                $existenciaInicioHeredada = [
                                                    'dosis' => $datos[$jur][$mes - 1]['existencia_fin_dosis'] ?? 0,
                                                    'lote' => $datos[$jur][$mes - 1]['existencia_fin_lote'] ?? '',
                                                    'caducidad' => $datos[$jur][$mes - 1]['existencia_fin_caducidad'] ?? ''
                                                ];
                                            }
                                        }

                                        // Usar existencia heredada si existe, sino usar la guardada
                                        $existenciaInicioDosis = $existenciaInicioHeredada
                                            ? $existenciaInicioHeredada['dosis']
                                            : ($d['existencia_inicio_dosis'] ?? 0);
                                        $existenciaInicioLote = $existenciaInicioHeredada
                                            ? $existenciaInicioHeredada['lote']
                                            : ($d['existencia_inicio_lote'] ?? '');
                                        $existenciaInicioCaducidad = $existenciaInicioHeredada
                                            ? $existenciaInicioHeredada['caducidad']
                                            : ($d['existencia_inicio_caducidad'] ?? '');

                                        // Calcular totales con la existencia heredada
                                        $dosisRecibidas = $d['dosis_recibidas'] ?? 0;
                                        $dosisAdmin = $d['dosis_administradas'] ?? 0;
                                        $desperdicio = $d['desperdicio_dosis'] ?? 0;
                                        $totalDosis = $existenciaInicioDosis + $dosisRecibidas;
                                        $existenciaFin = $totalDosis - $dosisAdmin - $desperdicio;

                                        if ($d || $existenciaInicioHeredada) {
                                            $totales['inicio'] += $existenciaInicioDosis;
                                            $totales['recibidas'] += $dosisRecibidas;
                                            $totales['total'] += $totalDosis;
                                            $totales['admin'] += $dosisAdmin;
                                            $totales['desp'] += $desperdicio;
                                            $totales['fin'] += $existenciaFin;
                                        }
                                ?>
                                        <tr data-mes="<?= $mes ?>" data-jur="<?= htmlspecialchars($jur) ?>" data-id="<?= $d ? $d['id'] : '' ?>">
                                            <td class="jur-cell"><?= htmlspecialchars($jur) ?></td>
                                            <td style="font-size:0.75rem;"><?= $d ? htmlspecialchars($d['biologico'] ?? 'RABIFFA') : 'RABIFFA' ?></td>
                                            <td style="font-size:0.75rem;"><?= $d ? htmlspecialchars($d['presentacion'] ?? 'FCO 20 DOSIS') : 'FCO 20 DOSIS' ?></td>
                                            <td<?= $existenciaInicioHeredada ? ' style="background:#f0fdf4;color:#16a34a;font-weight:600;" title="Heredado del mes anterior"' : '' ?>><?= number_format($existenciaInicioDosis) ?></td>
                                                <td style="font-size:0.7rem;"><?= $existenciaInicioLote ?: '-' ?></td>
                                                <td style="font-size:0.7rem;"><?= $existenciaInicioCaducidad ? date('d/m/Y', strtotime($existenciaInicioCaducidad)) : '-' ?></td>
                                                <td><?= number_format($dosisRecibidas) ?></td>
                                                <td style="font-size:0.7rem;"><?= $d ? htmlspecialchars($d['dosis_recibidas_lote'] ?: '-') : '-' ?></td>
                                                <td style="font-size:0.7rem;"><?= ($d && $d['dosis_recibidas_caducidad']) ? date('d/m/Y', strtotime($d['dosis_recibidas_caducidad'])) : '-' ?></td>
                                                <td style="font-weight:600;background:#fef3c7;"><?= number_format($totalDosis) ?></td>
                                                <td style="font-weight:600;background:#fee2e2;"><?= number_format($dosisAdmin) ?></td>
                                                <td><?= number_format($desperdicio) ?></td>
                                                <td style="font-weight:600;background:#e0f2fe;"><?= number_format($existenciaFin) ?></td>
                                                <td style="font-size:0.7rem;"><?= $d ? htmlspecialchars($d['existencia_fin_lote'] ?: '-') : '-' ?></td>
                                                <td style="font-size:0.7rem;"><?= ($d && $d['existencia_fin_caducidad']) ? date('d/m/Y', strtotime($d['existencia_fin_caducidad'])) : '-' ?></td>
                                                <td style="text-align:center;position:sticky;right:0;background:white;z-index:5;">
                                                    <?php if ($d): ?>
                                                        <button class="btn-action btn-edit" onclick="abrirModalEditar(<?= $d['id'] ?>, &quot;<?= htmlspecialchars($jur, ENT_QUOTES) ?>&quot;, <?= $mes ?>)" title="Editar registro">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button class="btn-action btn-delete" onclick="abrirModalEliminar(<?= $d['id'] ?>, &quot;<?= htmlspecialchars($jur, ENT_QUOTES) ?>&quot;, <?= $mes ?>)" title="Eliminar registro">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    <?php else: ?>
                                                        <span style="color:#94a3b8;font-size:0.75rem;">Sin datos</span>
                                                    <?php endif; ?>
                                                </td>
                                        </tr>
                                <?php
                                    }
                                }
                                ?>
                            </tbody>
                            <tfoot>
                                <tr class="total-row">
                                    <td class="jur-cell">TOTAL</td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td><?= number_format($totales['inicio']) ?></td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td><?= number_format($totales['recibidas']) ?></td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td style="background:#fef3c7;"><?= number_format($totales['total']) ?></td>
                                    <td style="background:#fee2e2;"><?= number_format($totales['admin']) ?></td>
                                    <td><?= number_format($totales['desp']) ?></td>
                                    <td style="background:#e0f2fe;"><?= number_format($totales['fin']) ?></td>
                                    <td>-</td>
                                    <td>-</td>
                                    <td style="position:sticky;right:0;background:#dbeafe;z-index:5;">-</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>

                    <!-- CONTENEDOR DE OBSERVACIONES (editable por admin) -->
                    <div style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;">
                        <div style="font-weight:600;color:#475569;margin-bottom:0.5rem;">
                            <i class="fas fa-clipboard-list"></i> OBSERVACIONES:
                        </div>
                        <textarea id="observacionesAdmin" style="width:100%;min-height:80px;padding:0.75rem;border:1px solid #e2e8f0;border-radius:8px;font-size:0.9rem;resize:vertical;" placeholder="Escriba las observaciones generales del mes..."><?php
                                                                                                                                                                                                                                                        // Cargar observaciones del primer registro del mes
                                                                                                                                                                                                                                                        $mesObs = $mesSeleccionado ?: date('n');
                                                                                                                                                                                                                                                        $obsTexto = '';
                                                                                                                                                                                                                                                        foreach ($datos as $jur => $mesesData) {
                                                                                                                                                                                                                                                            if (isset($mesesData[$mesObs]['observaciones']) && !empty($mesesData[$mesObs]['observaciones'])) {
                                                                                                                                                                                                                                                                $obsTexto = $mesesData[$mesObs]['observaciones'];
                                                                                                                                                                                                                                                                break;
                                                                                                                                                                                                                                                            }
                                                                                                                                                                                                                                                        }
                                                                                                                                                                                                                                                        echo htmlspecialchars($obsTexto);
                                                                                                                                                                                                                                                        ?></textarea>
                        <button onclick="guardarObservaciones()" class="btn-guardar" style="margin-top:0.5rem;padding:8px 16px;font-size:0.85rem;">
                            <i class="fas fa-save"></i> Guardar Observaciones
                        </button>
                    </div>
                </div>

                <!-- MODALES PARA ADMIN -->
                <div class="modal-overlay" id="modalOverlay" onclick="cerrarModales()"></div>

                <!-- Modal Editar -->
                <div class="modal-container" id="modalEditar">
                    <div class="modal-header">
                        <i class="fas fa-edit"></i>
                        <div>
                            <h3 style="margin:0;font-size:1.25rem;">Editar Registro</h3>
                            <p style="margin:0.25rem 0 0;opacity:0.9;font-size:0.9rem;" id="modalEditarSubtitulo"></p>
                        </div>
                    </div>
                    <form id="formEditar" onsubmit="guardarEdicion(event)">
                        <div class="modal-body">
                            <input type="hidden" id="edit_id" name="id">
                            <input type="hidden" id="edit_jurisdiccion" name="jurisdiccion">
                            <input type="hidden" id="edit_mes" name="mes">
                            <input type="hidden" id="edit_anio" name="anio" value="<?= $anioSeleccionado ?>">

                            <div style="background:#dbeafe;border-left:4px solid #3b82f6;padding:12px;margin-bottom:1rem;border-radius:6px;">
                                <p style="margin:0;font-size:0.85rem;color:#1e40af;">
                                    <i class="fas fa-info-circle"></i> <strong>Nota:</strong> La existencia inicial se hereda automáticamente del mes anterior (si existe).
                                </p>
                            </div>

                            <div class="form-group-modal">
                                <label><i class="fas fa-box"></i> Existencia Inicio (Dosis)</label>
                                <input type="number" id="edit_existencia_inicio_dosis" name="existencia_inicio_dosis" min="0" required>
                            </div>
                            <div class="form-group-modal">
                                <label><i class="fas fa-tag"></i> Lote Existencia Inicio</label>
                                <input type="text" id="edit_existencia_inicio_lote" name="existencia_inicio_lote">
                            </div>
                            <div class="form-group-modal">
                                <label><i class="fas fa-calendar"></i> Fecha Caducidad Existencia Inicio</label>
                                <input type="date" id="edit_existencia_inicio_caducidad" name="existencia_inicio_caducidad">
                            </div>

                            <div class="form-group-modal">
                                <label><i class="fas fa-truck"></i> Dosis Recibidas</label>
                                <input type="number" id="edit_dosis_recibidas" name="dosis_recibidas" min="0" required>
                            </div>
                            <div class="form-group-modal">
                                <label><i class="fas fa-tag"></i> Lote Dosis Recibidas</label>
                                <input type="text" id="edit_dosis_recibidas_lote" name="dosis_recibidas_lote">
                            </div>
                            <div class="form-group-modal">
                                <label><i class="fas fa-calendar"></i> Fecha Caducidad Dosis Recibidas</label>
                                <input type="date" id="edit_dosis_recibidas_caducidad" name="dosis_recibidas_caducidad">
                            </div>

                            <div class="form-group-modal">
                                <label><i class="fas fa-syringe"></i> Dosis Administradas</label>
                                <input type="number" id="edit_dosis_administradas" name="dosis_administradas" min="0" required>
                            </div>
                            <div class="form-group-modal">
                                <label><i class="fas fa-trash"></i> Desperdicio</label>
                                <input type="number" id="edit_desperdicio_dosis" name="desperdicio_dosis" min="0" required>
                            </div>

                            <div class="form-group-modal">
                                <label><i class="fas fa-tag"></i> Lote Existencia Fin</label>
                                <input type="text" id="edit_existencia_fin_lote" name="existencia_fin_lote">
                            </div>
                            <div class="form-group-modal">
                                <label><i class="fas fa-calendar"></i> Fecha Caducidad Existencia Fin</label>
                                <input type="date" id="edit_existencia_fin_caducidad" name="existencia_fin_caducidad">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn-modal btn-cancel" onclick="cerrarModales()">
                                <i class="fas fa-times"></i> Cancelar
                            </button>
                            <button type="submit" class="btn-modal btn-save">
                                <i class="fas fa-save"></i> Guardar Cambios
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Modal Eliminar -->
                <div class="modal-container" id="modalEliminar">
                    <div class="modal-header delete">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>
                            <h3 style="margin:0;font-size:1.25rem;">Confirmar Eliminación</h3>
                            <p style="margin:0.25rem 0 0;opacity:0.9;font-size:0.9rem;">Esta acción no se puede deshacer</p>
                        </div>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:1rem;color:#475569;margin:0 0 1rem 0;">
                            ¿Estás seguro de que deseas eliminar este registro?
                        </p>
                        <div style="background:#fef2f2;border:2px solid #fecaca;border-radius:8px;padding:1rem;">
                            <p style="margin:0;font-weight:600;color:#dc2626;" id="modalEliminarInfo"></p>
                        </div>
                        <input type="hidden" id="delete_id">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal btn-cancel" onclick="cerrarModales()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="button" class="btn-modal btn-confirm-delete" onclick="confirmarEliminacion()">
                            <i class="fas fa-trash-alt"></i> Eliminar
                        </button>
                    </div>
                </div>

                <!-- Modal Editar Producto (Biológico y Presentación) -->
                <div class="modal-container" id="modalEditarProducto">
                    <div class="modal-header" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">
                        <i class="fas fa-flask"></i>
                        <div>
                            <h3 style="margin:0;font-size:1.25rem;">Editar Información del Producto</h3>
                            <p style="margin:0.25rem 0 0;opacity:0.9;font-size:0.9rem;">Cambiar nombre del biológico y presentación</p>
                        </div>
                    </div>
                    <div class="modal-body">
                        <div style="margin-bottom:1rem;">
                            <label style="font-weight:600;color:#475569;display:block;margin-bottom:0.5rem;">
                                <i class="fas fa-syringe"></i> Nombre del Biológico:
                            </label>
                            <input type="text" id="edit_biologico"
                                style="width:100%;padding:0.75rem;border:2px solid #e2e8f0;border-radius:8px;font-size:0.95rem;"
                                placeholder="Ej: RABIFFA, RABISIN, etc.">
                        </div>
                        <div>
                            <label style="font-weight:600;color:#475569;display:block;margin-bottom:0.5rem;">
                                <i class="fas fa-box"></i> Presentación:
                            </label>
                            <input type="text" id="edit_presentacion"
                                style="width:100%;padding:0.75rem;border:2px solid #e2e8f0;border-radius:8px;font-size:0.95rem;"
                                placeholder="Ej: FCO 20 DOSIS, FCO 10 DOSIS, etc.">
                        </div>
                        <div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:1rem;margin-top:1rem;border-radius:8px;">
                            <p style="margin:0;font-size:0.85rem;color:#92400e;">
                                <i class="fas fa-info-circle"></i> <strong>Nota:</strong> Estos cambios se aplicarán a TODOS los registros del año <?= $anioSeleccionado ?>.
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-modal btn-cancel" onclick="cerrarModales()">
                            <i class="fas fa-times"></i> Cancelar
                        </button>
                        <button type="button" class="btn-modal btn-confirm" onclick="guardarProducto()" style="background:linear-gradient(135deg,#8b5cf6,#7c3aed);">
                            <i class="fas fa-save"></i> Guardar Cambios
                        </button>
                    </div>
                </div>

            <?php else: ?>
                <!-- ========== VISTA CAPTURISTA - TABLA ESTILO EXCEL ========== -->
                <?php
                // Determinar el próximo mes disponible para captura
                // Solo puede capturar el mes siguiente al último capturado (secuencial)
                $ultimoMesCapturado = 0;
                for ($m = 12; $m >= 1; $m--) {
                    if (isset($datos[$jurisdiccion_usuario][$m])) {
                        $ultimoMesCapturado = $m;
                        break;
                    }
                }
                $proximoMesDisponible = $ultimoMesCapturado + 1; // El siguiente mes a capturar
                ?>
                <div class="main-container">
                    <div class="mes-selector">
                        <label><i class="fas fa-calendar"></i> Año:</label>
                        <select id="selectAnio" onchange="cambiarAnio()">
                            <?php foreach ($aniosDisponibles as $a): ?>
                                <option value="<?= $a ?>" <?= $a == $anioSeleccionado ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label style="margin-left:1rem;"><i class="fas fa-calendar-alt"></i> Mes:</label>
                        <select id="selectMes" onchange="cargarMes()">
                            <option value="">-- Seleccionar --</option>
                            <?php foreach ($meses as $num => $nombre):
                                $tieneData = isset($datos[$jurisdiccion_usuario][$num]);
                                // Habilitar solo: meses ya capturados (para ver/editar) o el próximo mes disponible
                                $habilitado = $tieneData || ($num == $proximoMesDisponible && $num <= 12);
                                $esSiguiente = ($num == $proximoMesDisponible && !$tieneData);
                            ?>
                                <option value="<?= $num ?>"
                                    <?= !$habilitado ? 'disabled style="color:#cbd5e1;"' : '' ?>
                                    <?= $tieneData ? 'style="font-weight:bold;color:#16a34a;"' : '' ?>
                                    <?= $esSiguiente ? 'style="font-weight:bold;color:#2563eb;"' : '' ?>>
                                    <?= $nombre ?> <?= $tieneData ? '✓' : ($esSiguiente ? '← Siguiente' : '🔒') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span id="statusMes" style="margin-left:auto;font-size:0.85rem;color:#64748b;"></span>
                    </div>

                    <form id="formBiologico" onsubmit="guardarDatos(event)" style="display:none;">
                        <input type="hidden" id="mesActual" name="mes" value="">
                        <input type="hidden" id="anioActual" name="anio" value="<?= $anioSeleccionado ?>">
                        <input type="hidden" name="jurisdiccion" value="<?= htmlspecialchars($jurisdiccion_usuario) ?>">
                        <input type="hidden" name="biologico" value="RABIFFA">
                        <input type="hidden" name="presentacion" value="FCO 20 DOSIS">
                        <input type="hidden" id="hidden_total_dosis" name="total_dosis" value="0">
                        <input type="hidden" id="hidden_existencia_fin" name="existencia_fin_dosis" value="0">

                        <div class="excel-table-container">
                            <!-- Título -->
                            <div style="background:linear-gradient(135deg,#0369a1,#0891b2);color:white;padding:12px 16px;border-radius:8px 8px 0 0;text-align:center;">
                                <strong>INFORME DE MOVIMIENTO DE BIOLÓGICO (VACUNA ANTIRRÁBICA CANINA) <?= $anioSeleccionado ?></strong>
                                <div style="font-size:0.85rem;opacity:0.9;margin-top:4px;">
                                    <?= htmlspecialchars($jurisdiccion_usuario) ?> — <span id="mesNombre">Seleccione un mes</span>
                                </div>
                            </div>

                            <!-- Tabla estilo Excel horizontal -->
                            <div style="overflow-x:auto;">
                                <table class="excel-form-table">
                                    <thead>
                                        <tr>
                                            <th colspan="3" class="th-green">EXISTENCIA INICIO DEL MES</th>
                                            <th colspan="3" class="th-blue">DOSIS RECIBIDAS EN EL MES</th>
                                            <th class="th-orange">TOTAL DOSIS</th>
                                            <th class="th-red">DOSIS ADMIN.</th>
                                            <th class="th-purple">DESPERDICIO</th>
                                            <th colspan="3" class="th-cyan">EXISTENCIA FIN DEL MES</th>
                                        </tr>
                                        <tr class="subheader">
                                            <th>Dosis</th>
                                            <th>Lote</th>
                                            <th>Caducidad</th>
                                            <th>Dosis</th>
                                            <th>Lote</th>
                                            <th>Caducidad</th>
                                            <th></th>
                                            <th></th>
                                            <th></th>
                                            <th>Dosis</th>
                                            <th>Lote</th>
                                            <th>Caducidad</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <!-- Existencia Inicio -->
                                            <td><input type="number" id="existencia_inicio_dosis" name="existencia_inicio_dosis" min="0" value="0" oninput="calcularTotales()"></td>
                                            <td><input type="text" id="existencia_inicio_lote" name="existencia_inicio_lote" placeholder="Lote"></td>
                                            <td><input type="date" id="existencia_inicio_caducidad" name="existencia_inicio_caducidad"></td>
                                            <!-- Dosis Recibidas -->
                                            <td><input type="number" id="dosis_recibidas" name="dosis_recibidas" min="0" value="0" oninput="calcularTotales()"></td>
                                            <td><input type="text" id="dosis_recibidas_lote" name="dosis_recibidas_lote" placeholder="Lote"></td>
                                            <td><input type="date" id="dosis_recibidas_caducidad" name="dosis_recibidas_caducidad"></td>
                                            <!-- Total (calculado) -->
                                            <td class="calc-cell"><span id="total_dosis" class="calc-value">0</span></td>
                                            <!-- Administradas -->
                                            <td><input type="number" id="dosis_administradas" name="dosis_administradas" min="0" value="0" oninput="calcularTotales()"></td>
                                            <!-- Desperdicio -->
                                            <td><input type="number" id="desperdicio_dosis" name="desperdicio_dosis" min="0" value="0" oninput="calcularTotales()"></td>
                                            <!-- Existencia Fin -->
                                            <td class="calc-cell"><span id="existencia_fin_dosis" class="calc-value">0</span></td>
                                            <td><input type="text" id="existencia_fin_lote" name="existencia_fin_lote" placeholder="Lote"></td>
                                            <td><input type="date" id="existencia_fin_caducidad" name="existencia_fin_caducidad"></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Fórmulas explicativas -->
                            <div style="background:#f8fafc;padding:12px 16px;border:1px solid #e2e8f0;border-top:none;font-size:0.8rem;color:#64748b;">
                                <i class="fas fa-calculator"></i> <strong>Fórmulas:</strong>
                                <span style="margin-left:12px;background:#fef3c7;padding:2px 8px;border-radius:4px;">Total = Inicio + Recibidas</span>
                                <span style="margin-left:12px;background:#e0f2fe;padding:2px 8px;border-radius:4px;">Existencia Fin = Total − Administradas − Desperdicio</span>
                            </div>

                            <!-- Observaciones del capturista -->
                            <div style="padding:1rem 1.5rem;border-top:1px solid #e2e8f0;">
                                <label style="font-weight:600;color:#475569;display:block;margin-bottom:0.5rem;">
                                    <i class="fas fa-comment-alt"></i> Observaciones:
                                </label>
                                <textarea id="observaciones" name="observaciones"
                                    style="width:100%;min-height:80px;padding:0.75rem;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;resize:vertical;"
                                    placeholder="Escriba sus observaciones del mes (opcional)..."></textarea>
                            </div>
                        </div>

                        <div class="actions-bar">
                            <span id="ultimaActualizacion" style="color:#64748b;font-size:0.85rem;"></span>
                            <button type="submit" class="btn-guardar">
                                <i class="fas fa-save"></i> Guardar Cambios
                            </button>
                        </div>
                    </form>

                    <div id="mensajeInicial" style="text-align:center;padding:4rem 2rem;">
                        <i class="fas fa-hand-pointer" style="font-size:3rem;color:#0891b2;margin-bottom:1rem;display:block;"></i>
                        <h3 style="color:#1e293b;margin-bottom:0.5rem;">Selecciona un mes</h3>
                        <p style="color:#64748b;">Para comenzar a capturar los datos del movimiento de biológico</p>
                    </div>
                </div>
            <?php endif; ?>

        </main>
    </div>

    <script>
        const datosExistentes = <?= json_encode($datos) ?>;
        const meses = <?= json_encode($meses) ?>;
        const esAdmin = <?= $esAdmin ? 'true' : 'false' ?>;
        const anioSeleccionado = <?= $anioSeleccionado ?>;
        const jurisdiccionUsuario = '<?= addslashes($jurisdiccion_usuario) ?>';

        function showToast(msg, type = 'success') {
            const toast = document.getElementById('toast');
            toast.textContent = msg;
            toast.className = 'toast ' + type;
            toast.style.display = 'block';
            setTimeout(() => toast.style.display = 'none', 3000);
        }

        // Función para cambiar de año (recarga la página)
        function cambiarAnio() {
            const anio = esAdmin ? document.getElementById('filterAnio').value : document.getElementById('selectAnio').value;
            window.location.href = 'biologico_canino.php?anio=' + anio;
        }

        <?php if ($esAdmin): ?>
            // Funciones Admin
            function filtrarTabla() {
                const mes = document.getElementById('filterMes').value;
                document.querySelectorAll('#tablaAdmin tbody tr').forEach(row => {
                    if (!mes || row.dataset.mes === mes) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }

            function exportarExcel() {
                const mes = document.getElementById('filterMes').value;
                let url = 'export_biologico_excel.php?anio=' + anioSeleccionado;
                if (mes) url += '&mes=' + mes;
                window.location.href = url;
            }

            // Funciones de Modales
            function abrirModalEditar(id, jurisdiccion, mes) {
                // Buscar los datos del registro
                fetch(`guardar_biologico.php?action=obtener&id=${id}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            const reg = data.registro;
                            document.getElementById('edit_id').value = id;
                            document.getElementById('edit_jurisdiccion').value = jurisdiccion;
                            document.getElementById('edit_mes').value = mes;
                            document.getElementById('modalEditarSubtitulo').textContent = `${jurisdiccion} - ${meses[mes]} ${anioSeleccionado}`;

                            // Verificar si hay mes anterior para heredar existencia
                            const mesAnterior = parseInt(mes) - 1;
                            let existenciaHeredada = null;

                            if (mesAnterior > 0 && datosExistentes[jurisdiccion] && datosExistentes[jurisdiccion][mesAnterior]) {
                                const anterior = datosExistentes[jurisdiccion][mesAnterior];
                                existenciaHeredada = {
                                    dosis: anterior.existencia_fin_dosis || 0,
                                    lote: anterior.existencia_fin_lote || '',
                                    caducidad: anterior.existencia_fin_caducidad || ''
                                };
                            }

                            // Si hay mes anterior, usar existencia heredada (solo lectura)
                            if (existenciaHeredada) {
                                document.getElementById('edit_existencia_inicio_dosis').value = existenciaHeredada.dosis;
                                document.getElementById('edit_existencia_inicio_lote').value = existenciaHeredada.lote;
                                document.getElementById('edit_existencia_inicio_caducidad').value = existenciaHeredada.caducidad;

                                // Marcar como solo lectura (heredado)
                                document.getElementById('edit_existencia_inicio_dosis').readOnly = true;
                                document.getElementById('edit_existencia_inicio_dosis').style.backgroundColor = '#f0fdf4';
                                document.getElementById('edit_existencia_inicio_dosis').style.color = '#16a34a';
                                document.getElementById('edit_existencia_inicio_dosis').style.fontWeight = '600';
                                document.getElementById('edit_existencia_inicio_dosis').title = 'Heredado del mes anterior (no editable)';

                                document.getElementById('edit_existencia_inicio_lote').readOnly = true;
                                document.getElementById('edit_existencia_inicio_lote').style.backgroundColor = '#f0fdf4';
                                document.getElementById('edit_existencia_inicio_lote').style.color = '#16a34a';

                                document.getElementById('edit_existencia_inicio_caducidad').readOnly = true;
                                document.getElementById('edit_existencia_inicio_caducidad').style.backgroundColor = '#f0fdf4';
                                document.getElementById('edit_existencia_inicio_caducidad').style.color = '#16a34a';
                            } else {
                                // Primer mes o sin mes anterior: usar datos guardados (editable)
                                document.getElementById('edit_existencia_inicio_dosis').value = reg.existencia_inicio_dosis || 0;
                                document.getElementById('edit_existencia_inicio_lote').value = reg.existencia_inicio_lote || '';
                                document.getElementById('edit_existencia_inicio_caducidad').value = reg.existencia_inicio_caducidad || '';

                                // Habilitar edición
                                document.getElementById('edit_existencia_inicio_dosis').readOnly = false;
                                document.getElementById('edit_existencia_inicio_dosis').style.backgroundColor = '';
                                document.getElementById('edit_existencia_inicio_dosis').style.color = '';
                                document.getElementById('edit_existencia_inicio_dosis').style.fontWeight = '';
                                document.getElementById('edit_existencia_inicio_dosis').title = '';

                                document.getElementById('edit_existencia_inicio_lote').readOnly = false;
                                document.getElementById('edit_existencia_inicio_lote').style.backgroundColor = '';
                                document.getElementById('edit_existencia_inicio_lote').style.color = '';

                                document.getElementById('edit_existencia_inicio_caducidad').readOnly = false;
                                document.getElementById('edit_existencia_inicio_caducidad').style.backgroundColor = '';
                                document.getElementById('edit_existencia_inicio_caducidad').style.color = '';
                            }

                            document.getElementById('edit_dosis_recibidas').value = reg.dosis_recibidas || 0;
                            document.getElementById('edit_dosis_recibidas_lote').value = reg.dosis_recibidas_lote || '';
                            document.getElementById('edit_dosis_recibidas_caducidad').value = reg.dosis_recibidas_caducidad || '';
                            document.getElementById('edit_dosis_administradas').value = reg.dosis_administradas || 0;
                            document.getElementById('edit_desperdicio_dosis').value = reg.desperdicio_dosis || 0;
                            document.getElementById('edit_existencia_fin_lote').value = reg.existencia_fin_lote || '';
                            document.getElementById('edit_existencia_fin_caducidad').value = reg.existencia_fin_caducidad || '';

                            document.getElementById('modalOverlay').style.display = 'block';
                            document.getElementById('modalEditar').style.display = 'block';
                        }
                    })
                    .catch(err => {
                        alert('Error al cargar los datos del registro');
                        console.error(err);
                    });
            }

            function abrirModalEliminar(id, jurisdiccion, mes) {
                document.getElementById('delete_id').value = id;
                document.getElementById('modalEliminarInfo').textContent = `${jurisdiccion} - ${meses[mes]} ${anioSeleccionado}`;
                document.getElementById('modalOverlay').style.display = 'block';
                document.getElementById('modalEliminar').style.display = 'block';
            }

            function cerrarModales() {
                document.getElementById('modalOverlay').style.display = 'none';
                document.getElementById('modalEditar').style.display = 'none';
                document.getElementById('modalEliminar').style.display = 'none';
                document.getElementById('modalEditarProducto').style.display = 'none';
            }

            function abrirModalEditarProducto() {
                // Obtener valores actuales del primer registro visible
                const primerFila = document.querySelector('#tablaAdmin tbody tr[data-id]');
                if (primerFila) {
                    const biologico = primerFila.querySelector('td:nth-child(2)').textContent.trim();
                    const presentacion = primerFila.querySelector('td:nth-child(3)').textContent.trim();
                    document.getElementById('edit_biologico').value = biologico;
                    document.getElementById('edit_presentacion').value = presentacion;
                } else {
                    // Valores por defecto
                    document.getElementById('edit_biologico').value = 'RABIFFA';
                    document.getElementById('edit_presentacion').value = 'FCO 20 DOSIS';
                }

                document.getElementById('modalOverlay').style.display = 'block';
                document.getElementById('modalEditarProducto').style.display = 'block';
            }

            async function guardarProducto() {
                const biologico = document.getElementById('edit_biologico').value.trim();
                const presentacion = document.getElementById('edit_presentacion').value.trim();

                if (!biologico || !presentacion) {
                    alert('Por favor complete todos los campos');
                    return;
                }

                if (!confirm(`¿Actualizar TODOS los registros del año <?= $anioSeleccionado ?> con:\nBiológico: ${biologico}\nPresentación: ${presentacion}?`)) {
                    return;
                }

                try {
                    const formData = new FormData();
                    formData.append('action', 'actualizar_producto');
                    formData.append('biologico', biologico);
                    formData.append('presentacion', presentacion);
                    formData.append('anio', <?= $anioSeleccionado ?>);

                    const response = await fetch('guardar_biologico.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        showToast('Producto actualizado correctamente en todos los registros');
                        cerrarModales();

                        // ACTUALIZAR LA TABLA SIN RECARGAR LA PÁGINA
                        // Actualizar todas las celdas de Biológico y Presentación en la tabla
                        const filas = document.querySelectorAll('#tablaAdmin tbody tr[data-id]');
                        filas.forEach(fila => {
                            const celdaBiologico = fila.querySelector('td:nth-child(2)'); // Columna Biológico
                            const celdaPresentacion = fila.querySelector('td:nth-child(3)'); // Columna Presentación

                            if (celdaBiologico) {
                                celdaBiologico.textContent = biologico;
                                // Animación de actualización
                                celdaBiologico.style.backgroundColor = '#dcfce7';
                                setTimeout(() => {
                                    celdaBiologico.style.backgroundColor = '';
                                }, 1000);
                            }

                            if (celdaPresentacion) {
                                celdaPresentacion.textContent = presentacion;
                                // Animación de actualización
                                celdaPresentacion.style.backgroundColor = '#dcfce7';
                                setTimeout(() => {
                                    celdaPresentacion.style.backgroundColor = '';
                                }, 1000);
                            }
                        });

                        // Actualizar también en el objeto datosExistentes para mantener consistencia
                        Object.keys(datosExistentes).forEach(jur => {
                            Object.keys(datosExistentes[jur]).forEach(mes => {
                                if (datosExistentes[jur][mes]) {
                                    datosExistentes[jur][mes].biologico = biologico;
                                    datosExistentes[jur][mes].presentacion = presentacion;
                                }
                            });
                        });

                    } else {
                        alert('Error: ' + (data.error || 'No se pudo actualizar'));
                    }
                } catch (err) {
                    alert('Error al guardar los cambios');
                    console.error(err);
                }
            }

            async function guardarEdicion(e) {
                e.preventDefault();
                const formData = new FormData(document.getElementById('formEditar'));
                formData.append('action', 'editar');

                try {
                    const response = await fetch('guardar_biologico.php', {
                        method: 'POST',
                        body: formData
                    });
                    const data = await response.json();

                    if (data.success) {
                        alert('Registro actualizado correctamente');
                        cerrarModales();
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'No se pudo actualizar el registro'));
                    }
                } catch (err) {
                    alert('Error al guardar los cambios');
                    console.error(err);
                }
            }

            async function confirmarEliminacion() {
                const id = document.getElementById('delete_id').value;

                if (!confirm('¿Estás completamente seguro de eliminar este registro?')) {
                    return;
                }

                try {
                    const response = await fetch('guardar_biologico.php?action=eliminar&id=' + id, {
                        method: 'POST'
                    });
                    const data = await response.json();

                    if (data.success) {
                        alert('Registro eliminado correctamente');
                        cerrarModales();
                        location.reload();
                    } else {
                        alert('Error: ' + (data.error || 'No se pudo eliminar el registro'));
                    }
                } catch (err) {
                    alert('Error al eliminar el registro');
                    console.error(err);
                }
            }

            async function guardarObservaciones() {
                const observaciones = document.getElementById('observacionesAdmin').value;
                const mes = document.getElementById('filterMes').value || <?= date('n') ?>; // Mes actual si no hay filtro

                const formData = new FormData();
                formData.append('anio', anioSeleccionado);
                formData.append('mes', mes);
                formData.append('observaciones', observaciones);

                try {
                    const response = await fetch('guardar_biologico.php?action=observaciones', {
                        method: 'POST',
                        body: formData
                    });
                    const result = await response.json();

                    if (result.success) {
                        showToast('Observaciones guardadas');
                    } else {
                        showToast(result.error || 'Error al guardar', 'error');
                    }
                } catch (err) {
                    showToast('Error de conexión', 'error');
                }
            }

        <?php else: ?>
            // Funciones Capturista
            function cargarMes() {
                const mes = document.getElementById('selectMes').value;
                if (!mes) {
                    document.getElementById('formBiologico').style.display = 'none';
                    document.getElementById('mensajeInicial').style.display = 'block';
                    return;
                }

                document.getElementById('formBiologico').style.display = 'block';
                document.getElementById('mensajeInicial').style.display = 'none';
                document.getElementById('mesActual').value = mes;
                document.getElementById('mesNombre').textContent = meses[mes];

                // Limpiar campos
                const campos = ['existencia_inicio_dosis', 'existencia_inicio_lote', 'existencia_inicio_caducidad',
                    'dosis_recibidas', 'dosis_recibidas_lote', 'dosis_recibidas_caducidad',
                    'dosis_administradas', 'desperdicio_dosis',
                    'existencia_fin_lote', 'existencia_fin_caducidad', 'observaciones'
                ];
                campos.forEach(c => {
                    const el = document.getElementById(c);
                    if (el) {
                        if (el.type === 'number') el.value = '0';
                        else if (el.tagName === 'TEXTAREA') el.value = '';
                        else el.value = '';
                    }
                });

                // Obtener datos del mes anterior para existencia inicial
                const mesAnterior = parseInt(mes) - 1;
                let tieneAnterior = false;
                let existenciaAnterior = {
                    dosis: 0,
                    lote: '',
                    caducidad: ''
                };

                if (mesAnterior > 0 && datosExistentes[jurisdiccionUsuario] && datosExistentes[jurisdiccionUsuario][mesAnterior]) {
                    const anterior = datosExistentes[jurisdiccionUsuario][mesAnterior];
                    existenciaAnterior.dosis = anterior.existencia_fin_dosis || 0;
                    existenciaAnterior.lote = anterior.existencia_fin_lote || '';
                    existenciaAnterior.caducidad = anterior.existencia_fin_caducidad || '';
                    tieneAnterior = true;
                }

                // Cargar datos existentes del mes actual
                if (datosExistentes[jurisdiccionUsuario] && datosExistentes[jurisdiccionUsuario][mes]) {
                    const d = datosExistentes[jurisdiccionUsuario][mes];
                    document.getElementById('dosis_recibidas').value = d.dosis_recibidas || 0;
                    document.getElementById('dosis_recibidas_lote').value = d.dosis_recibidas_lote || '';
                    document.getElementById('dosis_recibidas_caducidad').value = d.dosis_recibidas_caducidad || '';
                    document.getElementById('dosis_administradas').value = d.dosis_administradas || 0;
                    document.getElementById('desperdicio_dosis').value = d.desperdicio_dosis || 0;
                    document.getElementById('existencia_fin_lote').value = d.existencia_fin_lote || '';
                    document.getElementById('existencia_fin_caducidad').value = d.existencia_fin_caducidad || '';
                    document.getElementById('observaciones').value = d.observaciones || '';

                    // Si hay mes anterior, usar su existencia final; si no, usar lo guardado
                    if (tieneAnterior) {
                        document.getElementById('existencia_inicio_dosis').value = existenciaAnterior.dosis;
                        document.getElementById('existencia_inicio_lote').value = existenciaAnterior.lote;
                        document.getElementById('existencia_inicio_caducidad').value = existenciaAnterior.caducidad;
                    } else {
                        document.getElementById('existencia_inicio_dosis').value = d.existencia_inicio_dosis || 0;
                        document.getElementById('existencia_inicio_lote').value = d.existencia_inicio_lote || '';
                        document.getElementById('existencia_inicio_caducidad').value = d.existencia_inicio_caducidad || '';
                    }

                    // BLOQUEAR CAMPOS SI ES CAPTURISTA (ya tiene datos guardados = solo lectura)
                    if (!esAdmin) {
                        const form = document.getElementById('formBiologico');
                        const inputs = form.querySelectorAll('input[type="number"], input[type="text"], input[type="date"], textarea');
                        inputs.forEach(input => {
                            input.disabled = true;
                            input.style.backgroundColor = '#f1f5f9';
                            input.style.cursor = 'not-allowed';
                        });

                        // Deshabilitar botón de guardar
                        const btnGuardar = form.querySelector('button[type="submit"]');
                        if (btnGuardar) {
                            btnGuardar.disabled = true;
                            btnGuardar.style.opacity = '0.5';
                            btnGuardar.style.cursor = 'not-allowed';
                        }

                        document.getElementById('statusMes').innerHTML = '<i class="fas fa-lock" style="color:#64748b;"></i> Datos guardados (solo lectura)';
                    } else {
                        document.getElementById('statusMes').innerHTML = '<i class="fas fa-check-circle" style="color:#16a34a;"></i> Datos guardados';
                    }

                    if (d.fecha_actualizacion) {
                        document.getElementById('ultimaActualizacion').innerHTML = '<i class="fas fa-clock"></i> Última actualización: ' + new Date(d.fecha_actualizacion).toLocaleString('es-MX');
                    }
                } else {
                    // Mes sin datos - HABILITAR CAMPOS para captura
                    if (!esAdmin) {
                        const form = document.getElementById('formBiologico');
                        const inputs = form.querySelectorAll('input[type="number"], input[type="text"], input[type="date"], textarea');
                        inputs.forEach(input => {
                            input.disabled = false;
                            input.style.backgroundColor = '';
                            input.style.cursor = '';
                        });

                        // Habilitar botón de guardar
                        const btnGuardar = form.querySelector('button[type="submit"]');
                        if (btnGuardar) {
                            btnGuardar.disabled = false;
                            btnGuardar.style.opacity = '';
                            btnGuardar.style.cursor = '';
                        }
                    }

                    document.getElementById('statusMes').innerHTML = '<i class="fas fa-info-circle" style="color:#d97706;"></i> Sin datos';
                    document.getElementById('ultimaActualizacion').innerHTML = '';

                    // Cargar existencia del mes anterior automáticamente
                    if (tieneAnterior) {
                        document.getElementById('existencia_inicio_dosis').value = existenciaAnterior.dosis;
                        document.getElementById('existencia_inicio_lote').value = existenciaAnterior.lote;
                        document.getElementById('existencia_inicio_caducidad').value = existenciaAnterior.caducidad;
                    }
                }

                // Bloquear campos de existencia inicial si hay mes anterior (se hereda automáticamente)
                const inputInicioDosis = document.getElementById('existencia_inicio_dosis');
                const inputInicioLote = document.getElementById('existencia_inicio_lote');
                const inputInicioCad = document.getElementById('existencia_inicio_caducidad');

                if (tieneAnterior) {
                    inputInicioDosis.readOnly = true;
                    inputInicioDosis.classList.add('readonly-inherited');
                    inputInicioDosis.title = 'Heredado del mes anterior';
                    inputInicioLote.readOnly = true;
                    inputInicioLote.classList.add('readonly-inherited');
                    inputInicioCad.readOnly = true;
                    inputInicioCad.classList.add('readonly-inherited');
                } else {
                    inputInicioDosis.readOnly = false;
                    inputInicioDosis.classList.remove('readonly-inherited');
                    inputInicioDosis.title = '';
                    inputInicioLote.readOnly = false;
                    inputInicioLote.classList.remove('readonly-inherited');
                    inputInicioCad.readOnly = false;
                    inputInicioCad.classList.remove('readonly-inherited');
                }

                calcularTotales();
            }

            function calcularTotales() {
                const inicio = parseInt(document.getElementById('existencia_inicio_dosis').value) || 0;
                const recibidas = parseInt(document.getElementById('dosis_recibidas').value) || 0;
                const administradas = parseInt(document.getElementById('dosis_administradas').value) || 0;
                const desperdicio = parseInt(document.getElementById('desperdicio_dosis').value) || 0;

                const total = inicio + recibidas;
                const fin = total - administradas - desperdicio;

                // Mostrar en pantalla
                document.getElementById('total_dosis').textContent = total.toLocaleString();
                document.getElementById('existencia_fin_dosis').textContent = fin.toLocaleString();

                // Guardar en campos hidden para enviar al servidor
                document.getElementById('hidden_total_dosis').value = total;
                document.getElementById('hidden_existencia_fin').value = fin;
            }

            async function guardarDatos(e) {
                e.preventDefault();

                const formData = new FormData(document.getElementById('formBiologico'));

                try {
                    const response = await fetch('guardar_biologico.php', {
                        method: 'POST',
                        body: formData
                    });

                    // Verificar si la respuesta es JSON
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        console.error('Respuesta no es JSON:', text);
                        showToast('Error del servidor: respuesta inválida', 'error');
                        return;
                    }

                    const result = await response.json();

                    if (result.success) {
                        showToast('Datos guardados correctamente');
                        const mes = document.getElementById('mesActual').value;
                        if (!datosExistentes[jurisdiccionUsuario]) datosExistentes[jurisdiccionUsuario] = {};
                        datosExistentes[jurisdiccionUsuario][mes] = result.data;

                        // Actualizar select del mes actual
                        const option = document.querySelector(`#selectMes option[value="${mes}"]`);
                        if (option && !option.textContent.includes('✓')) {
                            option.textContent = meses[mes] + ' ✓';
                            option.style.fontWeight = 'bold';
                            option.style.color = '#16a34a';
                        }

                        // Habilitar el siguiente mes
                        const mesActualNum = parseInt(mes);
                        const siguienteMes = mesActualNum + 1;
                        if (siguienteMes <= 12) {
                            const siguienteOption = document.querySelector(`#selectMes option[value="${siguienteMes}"]`);
                            if (siguienteOption) {
                                siguienteOption.disabled = false;
                                siguienteOption.style.color = '#2563eb';
                                siguienteOption.style.fontWeight = 'bold';
                                // Actualizar texto si no tiene datos
                                if (!siguienteOption.textContent.includes('✓')) {
                                    siguienteOption.textContent = meses[siguienteMes] + ' ← Siguiente';
                                }
                            }
                        }

                        // BLOQUEAR TODOS LOS CAMPOS (capturista no puede editar después de guardar)
                        if (!esAdmin) {
                            const form = document.getElementById('formBiologico');
                            const inputs = form.querySelectorAll('input[type="number"], input[type="text"], input[type="date"], textarea');
                            inputs.forEach(input => {
                                input.disabled = true;
                                input.style.backgroundColor = '#f1f5f9';
                                input.style.cursor = 'not-allowed';
                            });

                            // Deshabilitar botón de guardar
                            const btnGuardar = form.querySelector('button[type="submit"]');
                            if (btnGuardar) {
                                btnGuardar.disabled = true;
                                btnGuardar.style.opacity = '0.5';
                                btnGuardar.style.cursor = 'not-allowed';
                            }

                            // Mostrar mensaje de solo lectura
                            document.getElementById('statusMes').innerHTML = '<i class="fas fa-lock" style="color:#64748b;"></i> Datos guardados (solo lectura)';
                        }

                        document.getElementById('ultimaActualizacion').innerHTML = '<i class="fas fa-clock"></i> Última actualización: ' + new Date().toLocaleString('es-MX');
                    } else {
                        showToast(result.error || 'Error al guardar', 'error');
                    }
                } catch (err) {
                    console.error('Error completo:', err);
                    showToast('Error de conexión: ' + err.message, 'error');
                }
            }
        <?php endif; ?>
    </script>
</body>

</html>