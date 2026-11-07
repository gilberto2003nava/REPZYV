<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

// =====================================================
// AJAX Handler: Cargar municipios/hospitales
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cargar_lugares'])) {
    header('Content-Type: text/html; charset=utf-8');
    $jurisdiccion = $_POST['jurisdiccion'] ?? '';
    $tipo_meta = $_POST['tipo_meta'] ?? '';

    if ($jurisdiccion && $tipo_meta) {
        // Usar SIEMPRE la función municipiosPorJurisdiccion() 
        // porque estamos CREANDO metas nuevas (no hay registros aún)
        $lugares = municipiosPorJurisdiccion($jurisdiccion, $tipo_meta);

        echo '<option value="">Seleccionar municipio/hospital</option>';
        foreach ($lugares as $lugar) {
            // Determinar si es hospital (empieza con H.)
            $esHospital = preg_match('/^H\./', $lugar);
            $tipo = $esHospital ? ' (Hospital)' : ' (Municipio)';
            echo '<option value="' . htmlspecialchars($lugar) . '">' . htmlspecialchars($lugar) . $tipo . '</option>';
        }
    } else {
        echo '<option value="">Seleccione jurisdicción y tipo primero</option>';
    }
    exit;
}
// =====================================================

establecerHeadersSeguridad();

require_once __DIR__ . '/partials/check_session.php';

$esAdmin = isset($_SESSION['rol']) && $_SESSION['rol'] === 'admin';
if (!$esAdmin) {
    header('Location: panel.php');
    exit;
}

$csrfToken = generarTokenCSRF();

// Cargar año activo
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_sistema = $config_sistema['anio_activo'] ?? date('Y');

// Obtener años disponibles de la tabla metas
$anios_disponibles = [];
$sql_anios = "SELECT DISTINCT anio FROM metas ORDER BY anio DESC";
$result_anios = mysqli_query($conn, $sql_anios);
while ($row_anio = mysqli_fetch_assoc($result_anios)) {
    $anios_disponibles[] = $row_anio['anio'];
}

// Asegurar que siempre incluya el año actual y los 2 años anteriores
$anio_actual_sistema = (int)date('Y');
for ($i = 0; $i <= 2; $i++) {
    $anio_check = $anio_actual_sistema - $i;
    if (!in_array($anio_check, $anios_disponibles)) {
        $anios_disponibles[] = $anio_check;
    }
}

// Ordenar años de forma descendente
rsort($anios_disponibles);

// Año seleccionado por filtro (GET) o año del sistema por defecto
$anio_actual = isset($_GET['anio']) && in_array($_GET['anio'], $anios_disponibles) ? $_GET['anio'] : $anio_sistema;

// Obtener todas las jurisdicciones
$jurisdicciones = jurisdicciones();
$tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];

// Obtener todas las metas organizadas
$metas_organizadas = [];
$sql = "SELECT m.*, u.nombre as creador_nombre 
        FROM metas m 
        LEFT JOIN usuarios u ON m.creada_por = u.id 
        WHERE m.anio = ? 
        ORDER BY m.jurisdiccion, m.municipio, m.tipo";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $anio_actual);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$debug_count = 0;
while ($meta = mysqli_fetch_assoc($result)) {
    $juris = $meta['jurisdiccion'];
    $muni = $meta['municipio'];
    $tipo_meta = $meta['tipo'];

    if (!isset($metas_organizadas[$juris])) {
        $metas_organizadas[$juris] = [];
    }
    if (!isset($metas_organizadas[$juris][$muni])) {
        $metas_organizadas[$juris][$muni] = [];
    }

    $metas_organizadas[$juris][$muni][$tipo_meta] = $meta;
    $debug_count++;
}

// Obtener jurisdicción seleccionada (por defecto la primera)
$juris_seleccionada = $_GET['jurisdiccion'] ?? $jurisdicciones[0];
$tipo_seleccionado = $_GET['tipo'] ?? $tipos[0];
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>Gestión de Metas - Sistema Hospitalario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>">
    <style>
        /* Header mejorado usando variables CSS */
        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: var(--radius-lg, 16px);
            padding: 2rem;
            color: var(--text-primary, white);
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(59, 130, 246, 0.3);
        }

        .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            margin: 0;
            color: var(--text-primary, white) !important;
        }

        .page-subtitle {
            opacity: 0.95;
            font-size: 1rem;
            margin: 0;
            color: var(--text-primary, rgba(255, 255, 255, 0.95)) !important;
        }

        /* Solo el label del año en blanco, no el select */
        .page-header .form-label {
            color: var(--text-primary, white) !important;
        }

        /* El select mantiene sus estilos normales */
        .page-header .form-select {
            color: #1e293b;
            background-color: white;
            border-radius: var(--radius-md, 12px);
        }

        /* El botón mantiene sus estilos */
        .page-header .btn {
            color: var(--text-primary, white);
            border-radius: var(--radius-md, 12px);
        }

        /* Wrapper de jurisdicciones */
        .jurisdiccion-wrapper {
            margin-bottom: 30px;
        }

        /* Botón hamburguesa para móvil - oculto en desktop */
        .juris-toggle-btn {
            display: none;
            width: 100%;
            padding: 14px 18px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border: none;
            border-radius: 12px;
            cursor: pointer;
            color: white;
            font-weight: 600;
            font-size: 0.95rem;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);
            transition: all 0.3s ease;
        }

        .juris-toggle-btn:hover {
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
        }

        .juris-toggle-content {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .juris-toggle-btn .juris-icon {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.25);
            font-size: 1rem;
        }

        .juris-chevron {
            transition: transform 0.3s ease;
        }

        .juris-toggle-btn.active .juris-chevron {
            transform: rotate(180deg);
        }

        /* Lista de Jurisdicciones */
        .jurisdiccion-list {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }

        .jurisdiccion-item {
            display: flex;
            align-items: center;
            padding: 14px 20px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            color: var(--secondary);
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
        }

        .jurisdiccion-item:hover {
            border-color: var(--primary);
            color: var(--primary);
            box-shadow: 0 6px 12px rgba(37, 99, 235, 0.15);
        }

        .jurisdiccion-item.active {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.3);
        }

        .jurisdiccion-item .juris-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 12px;
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
            font-size: 1.3rem;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
        }

        .jurisdiccion-item:not(.active) .juris-icon {
            background: linear-gradient(135deg, #4b5563 0%, #374151 100%);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .jurisdiccion-item.active .juris-icon {
            background: rgba(255, 255, 255, 0.25);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .jurisdiccion-item .juris-name {
            font-weight: 600;
            font-size: 0.95rem;
        }

        /* Tabla Mejorada */
        .metas-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            margin-top: 20px;
        }

        /* Tabla responsiva */
        .table-responsive-wrapper {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        .metas-header {
            background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
            color: white;
            padding: 20px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .metas-header h3 {
            margin: 0;
            font-weight: 600;
            font-size: 1.2rem;
        }

        .metas-header .badge-count {
            background: rgba(255, 255, 255, 0.2);
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .metas-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .metas-table thead {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }

        .metas-table th {
            padding: 16px 20px;
            font-weight: 700;
            text-align: left;
            border-bottom: 2px solid #e2e8f0;
            color: var(--dark);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .metas-table th.text-center {
            text-align: center;
        }

        .metas-table td {
            padding: 18px 20px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }

        .metas-table tbody tr {
            transition: all 0.2s ease;
        }

        .metas-table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
            transform: scale(1.005);
        }

        .metas-table tbody tr:last-child td {
            border-bottom: none;
        }

        .lugar-badge {
            display: inline-flex;
            align-items: center;
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            gap: 8px;
        }

        .lugar-badge.municipio {
            background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0369a1;
            border-left: 4px solid #0284c7;
        }

        .lugar-badge.hospital {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #b45309;
            border-left: 4px solid #f59e0b;
        }

        .meta-cell {
            text-align: center;
        }

        .meta-value {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 70px;
            padding: 10px 16px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 1rem;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            color: var(--primary);
            transition: all 0.2s ease;
        }

        .meta-value:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .meta-value.empty {
            background: #f8fafc;
            color: #94a3b8;
            font-weight: 400;
        }

        .meta-value.na {
            background: #fef2f2;
            color: #dc2626;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--secondary);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            opacity: 0.5;
        }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
        }

        .stat-card:nth-child(1)::before {
            background: linear-gradient(180deg, #3b82f6, #1d4ed8);
        }

        .stat-card:nth-child(2)::before {
            background: linear-gradient(180deg, #10b981, #059669);
        }

        .stat-card:nth-child(3)::before {
            background: linear-gradient(180deg, #f59e0b, #d97706);
        }

        .stat-card:nth-child(4)::before {
            background: linear-gradient(180deg, #8b5cf6, #7c3aed);
        }

        .stat-card:nth-child(1) .stat-value {
            color: #2563eb;
        }

        .stat-card:nth-child(2) .stat-value {
            color: #059669;
        }

        .stat-card:nth-child(3) .stat-value {
            color: #d97706;
        }

        .stat-card:nth-child(4) .stat-value {
            color: #7c3aed;
        }

        .stat-card:nth-child(1) .stat-label i {
            color: #3b82f6;
        }

        .stat-card:nth-child(2) .stat-label i {
            color: #10b981;
        }

        .stat-card:nth-child(3) .stat-label i {
            color: #f59e0b;
        }

        .stat-card:nth-child(4) .stat-label i {
            color: #8b5cf6;
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--secondary);
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary);
        }

        /* Modal Mejorado */
        .modal-content {
            border: none;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            overflow: hidden;
        }

        .modal-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            border: none;
            padding: 24px 28px;
        }

        .modal-header .modal-title {
            font-weight: 700;
            font-size: 1.3rem;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .modal-header .btn-close:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 28px;
            background: #f8fafc;
        }

        .modal-body .form-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .modal-body .form-select,
        .modal-body .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            background: white;
        }

        .modal-body .form-select:focus,
        .modal-body .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.1);
        }

        .modal-footer {
            border: none;
            padding: 20px 28px 28px;
            background: #f8fafc;
            gap: 12px;
        }

        .modal-footer .btn {
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.95rem;
        }

        .modal-footer .btn-secondary {
            background: #e2e8f0;
            border: none;
            color: #475569;
        }

        .modal-footer .btn-secondary:hover {
            background: #cbd5e1;
        }

        .modal-footer .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            border: none;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .modal-footer .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.4);
        }

        /* Botones de Acciones */
        .btn-action {
            width: 32px;
            height: 32px;
            padding: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.85rem;
        }

        .btn-action.edit {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #b45309;
        }

        .btn-action.edit:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(245, 158, 11, 0.3);
        }

        .btn-action.delete {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
        }

        .btn-action.delete:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3);
        }

        .actions-cell {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .modal-danger .modal-header {
            background: linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
        }

        .modal-warning .modal-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }

        /* Lista de Tipos en Modal */
        .tipos-lista {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .tipo-item {
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            font-weight: 500;
            font-size: 0.9rem;
            background: white;
        }

        .tipo-item:hover {
            border-color: #3b82f6;
            background: #eff6ff;
        }

        .tipo-item.selected {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: white;
        }

        .tipo-item.selected-delete {
            border-color: #dc2626;
            background: linear-gradient(135deg, #dc2626, #991b1b);
            color: white;
        }

        .tipo-item.disabled {
            opacity: 0.4;
            cursor: not-allowed;
            background: #f1f5f9;
        }

        .tipo-item .tipo-valor {
            display: block;
            font-size: 0.75rem;
            opacity: 0.8;
            margin-top: 2px;
        }

        /* RESPONSIVO */
        @media (max-width: 992px) {

            /* Mostrar botón hamburguesa */
            .juris-toggle-btn {
                display: flex;
                margin-bottom: 12px;
            }

            /* Ocultar lista por defecto */
            .jurisdiccion-list {
                display: none;
                flex-direction: column;
                gap: 8px;
                background: white;
                border-radius: 12px;
                padding: 12px;
                box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
                border: 1px solid #e2e8f0;
                max-height: 300px;
                overflow-y: auto;
            }

            /* Mostrar lista cuando está activa */
            .jurisdiccion-list.show {
                display: flex;
            }

            .jurisdiccion-item {
                width: 100%;
                padding: 12px 14px;
            }

            .metas-container {
                margin-top: 15px;
            }

            .table-responsive-wrapper {
                margin: 0 -15px;
                padding: 0 15px;
            }

            .lugar-grid {
                grid-template-columns: 1fr
            }

            .lugar-item {
                padding: 12px
            }

            .meta-form-grid {
                grid-template-columns: 1fr
            }

            .modal-dialog {
                margin: 10px
            }

            .stats-row {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-value {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 768px) {
            .metas-table {
                font-size: 0.85rem;
            }

            .metas-table th,
            .metas-table td {
                padding: 12px 8px;
                white-space: nowrap;
            }

            .lugar-badge {
                padding: 8px 12px;
                font-size: 0.8rem;
            }

            .meta-value {
                min-width: 60px;
                padding: 8px 12px;
                font-size: 0.9rem;
            }
        }

        @media (max-width: 576px) {
            .jurisdiccion-list {
                gap: 8px;
            }

            .jurisdiccion-item {
                padding: 10px 14px;
                font-size: 0.8rem;
            }

            .jurisdiccion-item .juris-icon {
                width: 32px;
                height: 32px;
                font-size: 0.9rem;
                margin-right: 8px;
            }

            .jurisdiccion-item .juris-name {
                font-size: 0.8rem;
            }

            .metas-header {
                padding: 12px 15px;
                flex-direction: column;
                gap: 8px;
                text-align: center;
            }

            .metas-header h3 {
                font-size: 1rem;
            }

            .metas-table th,
            .metas-table td {
                padding: 8px 6px;
                font-size: 0.75rem;
            }

            .lugar-badge {
                padding: 6px 8px;
                font-size: 0.7rem;
            }

            .meta-value {
                min-width: 45px;
                padding: 5px 8px;
                font-size: 0.75rem;
            }

            .btn-action {
                width: 28px;
                height: 28px;
                font-size: 0.75rem;
            }

            .lugar-item {
                padding: 10px
            }

            .lugar-item h4 {
                font-size: 0.95rem
            }

            .tipo-grid {
                grid-template-columns: 1fr 1fr
            }

            .tipo-item {
                padding: 8px;
                font-size: 0.8rem
            }

            .btn {
                padding: 8px 12px;
                font-size: 0.85rem
            }

            .stats-row {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }

            .stat-card {
                padding: 12px;
            }

            .stat-label {
                font-size: 0.75rem;
            }

            .stat-value {
                font-size: 1.3rem;
            }

            .page-title {
                font-size: 1.1rem;
            }

            .page-subtitle {
                font-size: 0.8rem;
            }
        }
    </style>
</head>

<body class="theme-metas">
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h1 class="page-title" style="color: white; margin-bottom: 0.5rem;">
                            <i class="fas fa-chart-line me-2"></i>
                            Gestión de Metas Hospitalarias
                        </h1>
                        <p class="page-subtitle" style="color: rgba(255, 255, 255, 0.9); margin: 0;">Administre las metas anuales por jurisdicción y tipo</p>
                    </div>
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="d-flex align-items-center gap-2">
                            <label class="form-label mb-0 text-white fw-bold"><i class="fas fa-calendar-alt me-1"></i>Año:</label>
                            <select id="filtroAnio" class="form-select form-select-sm" style="width: auto; min-width: 100px;" onchange="cambiarAnio(this.value)">
                                <?php foreach ($anios_disponibles as $anio): ?>
                                    <option value="<?= $anio ?>" <?= $anio == $anio_actual ? 'selected' : '' ?>><?= $anio ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearMeta">
                            <i class="fas fa-plus me-2"></i>Nueva Meta
                        </button>
                    </div>
                </div>
            </div>

            <!-- Estadísticas rápidas -->
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label"><i class="fas fa-bullseye me-2"></i>Metas Definidas</div>
                    <div class="stat-value"><?php
                                            $total_metas = 0;
                                            foreach ($metas_organizadas as $juris_data) {
                                                foreach ($juris_data as $muni_data) {
                                                    $total_metas += count($muni_data);
                                                }
                                            }
                                            echo $total_metas;
                                            ?></div>
                    <div style="font-size: 0.8rem; color: #666; margin-top: 0.5rem;">
                        <?php
                        $count_juris = count($metas_organizadas);
                        $count_municipios = 0;
                        foreach ($metas_organizadas as $juris_data) {
                            $count_municipios += count($juris_data);
                        }
                        echo "($count_juris jurisdicciones, $count_municipios municipios/hospitales)";
                        ?>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><i class="fas fa-map-marked-alt me-2"></i>Jurisdicciones</div>
                    <div class="stat-value"><?php echo count($jurisdicciones); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><i class="fas fa-list-alt me-2"></i>Tipos de Meta</div>
                    <div class="stat-value"><?php echo count($tipos); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label"><i class="fas fa-calendar-alt me-2"></i>Año Seleccionado</div>
                    <div class="stat-value"><?php echo $anio_actual; ?></div>
                </div>
            </div>

            <!-- Lista de Jurisdicciones con efecto hamburguesa en móvil -->
            <div class="jurisdiccion-wrapper">
                <!-- Botón hamburguesa para móvil -->
                <button class="juris-toggle-btn" onclick="toggleJurisdiccionList()">
                    <div class="juris-toggle-content">
                        <div class="juris-icon">
                            <i class="fas fa-hospital-alt"></i>
                        </div>
                        <span class="juris-selected-name"><?php echo $juris_seleccionada; ?></span>
                    </div>
                    <i class="fas fa-chevron-down juris-chevron"></i>
                </button>

                <!-- Lista de jurisdicciones -->
                <div class="jurisdiccion-list" id="jurisdiccionList">
                    <?php foreach ($jurisdicciones as $juris): ?>
                        <a href="?jurisdiccion=<?php echo urlencode($juris); ?>&tipo=<?php echo urlencode($tipo_seleccionado); ?>&anio=<?php echo $anio_actual; ?>"
                            class="jurisdiccion-item <?php echo $juris === $juris_seleccionada ? 'active' : ''; ?>">
                            <div class="juris-icon">
                                <i class="fas fa-hospital-alt"></i>
                            </div>
                            <span class="juris-name"><?php echo $juris; ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Tabla de metas -->
            <?php
            // IMPORTANTE: Obtener los municipios/hospitales directamente de la BD para usar los nombres originales
            // Esto asegura que coincidan exactamente con los datos del archivo insertar_colaterales.sql
            $sql_lugares = "SELECT DISTINCT municipio FROM metas WHERE jurisdiccion = ? AND anio = ? ORDER BY municipio";
            $stmt_lugares = $conn->prepare($sql_lugares);
            $stmt_lugares->bind_param('si', $juris_seleccionada, $anio_actual);
            $stmt_lugares->execute();
            $result_lugares = $stmt_lugares->get_result();
            $todos_lugares = [];
            while ($row_lugar = $result_lugares->fetch_assoc()) {
                $todos_lugares[] = $row_lugar['municipio'];
            }

            // Si no hay datos en metas, usar los de funciones.php como respaldo (convertidos a mayúsculas)
            if (empty($todos_lugares)) {
                $municipios_juris_original = municipiosPorJurisdiccion($juris_seleccionada);
                $hospitales_juris_original = municipiosPorJurisdiccion($juris_seleccionada, 'agredidos');
                $municipios_juris = array_map(function ($m) {
                    return mb_strtoupper($m, 'UTF-8');
                }, $municipios_juris_original);
                $hospitales_juris = array_map(function ($m) {
                    return mb_strtoupper($m, 'UTF-8');
                }, $hospitales_juris_original);
                $todos_lugares = array_unique(array_merge($municipios_juris, $hospitales_juris));
                sort($todos_lugares);
            }

            // Determinar cuáles son hospitales (nombres que empiezan con H.)
            $hospitales_juris = array_filter($todos_lugares, function ($lugar) {
                return preg_match('/^H\./', $lugar);
            });
            ?>

            <div class="metas-container">
                <div class="metas-header">
                    <h3><i class="fas fa-table me-2"></i>Metas de <?php echo $juris_seleccionada; ?></h3>
                    <span class="badge-count"><i class="fas fa-list me-1"></i><?php echo count($todos_lugares); ?> registros</span>
                </div>
                <div class="table-responsive-wrapper">
                    <table class="metas-table">
                        <thead>
                            <tr>
                                <th style="width: 25%;"><i class="fas fa-map-pin me-2"></i>Municipio/Hospital</th>
                                <?php foreach ($tipos as $tipo): ?>
                                    <th class="text-center" style="width: 10%;">
                                        <?php echo ucfirst($tipo); ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-center" style="width: 10%;"><i class="fas fa-cogs me-1"></i>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $tipos_hospital = ['agredidos', 'tratamientos'];

                            foreach ($todos_lugares as $lugar):
                                // Un lugar es hospital si su nombre empieza con H.
                                $es_hospital = preg_match('/^H\./', $lugar);
                                $badge_class = $es_hospital ? 'hospital' : 'municipio';
                            ?>
                                <tr>
                                    <td>
                                        <span class="lugar-badge <?php echo $badge_class; ?>">
                                            <i class="fas fa-<?php echo $es_hospital ? 'hospital' : 'city'; ?> me-1"></i>
                                            <?php echo $lugar; ?>
                                        </span>
                                    </td>
                                    <?php foreach ($tipos as $tipo): ?>
                                        <td class="meta-cell">
                                            <?php
                                            if ($es_hospital && !in_array($tipo, $tipos_hospital)) {
                                                echo '<span class="meta-value na">N/A</span>';
                                            } else {
                                                $meta = $metas_organizadas[$juris_seleccionada][$lugar][$tipo] ?? null;
                                                if ($meta):
                                            ?>
                                                    <span class="meta-value"><?php echo number_format($meta['meta']); ?></span>
                                                <?php else: ?>
                                                    <span class="meta-value empty">—</span>
                                            <?php endif;
                                            }
                                            ?>
                                        </td>
                                    <?php endforeach; ?>
                                    <td class="meta-cell">
                                        <div class="actions-cell">
                                            <button type="button" class="btn-action edit" onclick="abrirEditarLugar('<?php echo htmlspecialchars($juris_seleccionada); ?>', '<?php echo htmlspecialchars($lugar); ?>', <?php echo $es_hospital ? 'true' : 'false'; ?>)" title="Editar Metas">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn-action delete" onclick="abrirEliminarLugar('<?php echo htmlspecialchars($juris_seleccionada); ?>', '<?php echo htmlspecialchars($lugar); ?>', <?php echo $es_hospital ? 'true' : 'false'; ?>)" title="Eliminar Metas">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal para crear meta -->
            <div class="modal fade" id="modalCrearMeta" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Crear Nueva Meta</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="formCrearMeta" method="post" action="guardar_meta.php">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <div class="modal-body">
                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="form-label"><i class="fas fa-map-marked-alt me-2 text-primary"></i>Jurisdicción</label>
                                        <select name="jurisdiccion" class="form-select" required>
                                            <option value="">Seleccionar jurisdicción</option>
                                            <?php foreach ($jurisdicciones as $juris): ?>
                                                <option value="<?php echo $juris; ?>"><?php echo $juris; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><i class="fas fa-tags me-2 text-warning"></i>Tipo de Meta</label>
                                        <select name="tipo" class="form-select" required>
                                            <option value="">Seleccionar tipo</option>
                                            <?php foreach ($tipos as $tipo): ?>
                                                <option value="<?php echo $tipo; ?>"><?php echo ucfirst($tipo); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><i class="fas fa-hospital me-2 text-success"></i>Municipio/Hospital</label>
                                        <select name="municipio" class="form-select" required>
                                            <option value="">Seleccione jurisdicción y tipo primero</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label"><i class="fas fa-chart-line me-2 text-info"></i>Valor de la Meta</label>
                                        <input type="number" name="valor" class="form-control" required min="0" placeholder="Ingrese el valor">
                                    </div>
                                    <div class="col-12">
                                        <input type="hidden" name="anio" value="<?php echo $anio_actual; ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancelar</button>
                                <button type="button" id="btnGuardarMeta" class="btn btn-primary"><i class="fas fa-save me-2"></i>Guardar Meta</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal para editar meta -->
            <div class="modal fade" id="modalEditarMeta" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content modal-warning">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Meta</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="formEditarMeta">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="accion" value="actualizar">
                            <input type="hidden" id="edit_jurisdiccion_val">
                            <input type="hidden" id="edit_municipio_val">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-hospital me-2 text-success"></i>Municipio/Hospital</label>
                                    <input type="text" class="form-control" id="edit_lugar_display" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-list me-2 text-primary"></i>Seleccione el Tipo de Meta</label>
                                    <div id="edit_tipos_lista" class="tipos-lista"></div>
                                </div>
                                <div class="mb-3" id="edit_valor_container" style="display:none;">
                                    <label class="form-label"><i class="fas fa-chart-line me-2 text-info"></i>Nuevo Valor</label>
                                    <input type="number" name="valor" id="edit_valor" class="form-control" min="0" placeholder="Ingrese el nuevo valor">
                                    <input type="hidden" name="id" id="edit_meta_id">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancelar</button>
                                <button type="submit" class="btn btn-warning text-white" id="btn_actualizar" disabled><i class="fas fa-save me-2"></i>Actualizar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Modal para eliminar meta -->
            <div class="modal fade" id="modalEliminarMeta" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content modal-danger">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Eliminar Meta</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form id="formEliminarMeta">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" id="delete_jurisdiccion_val">
                            <input type="hidden" id="delete_municipio_val">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-hospital me-2 text-success"></i>Municipio/Hospital</label>
                                    <input type="text" class="form-control" id="delete_lugar_display" readonly>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><i class="fas fa-list me-2 text-danger"></i>Seleccione el Tipo a Eliminar</label>
                                    <div id="delete_tipos_lista" class="tipos-lista"></div>
                                </div>
                                <div id="delete_confirm_container" style="display:none;">
                                    <input type="hidden" name="id" id="delete_meta_id">
                                    <div class="alert alert-danger">
                                        <i class="fas fa-exclamation-circle me-2"></i>
                                        <strong>¿Está seguro?</strong> Esta acción no se puede deshacer.
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>Cancelar</button>
                                <button type="submit" class="btn btn-danger" id="btn_eliminar" disabled><i class="fas fa-trash me-2"></i>Eliminar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos de metas para JavaScript
        const metasData = <?php echo json_encode($metas_organizadas); ?>;
        const tiposPermitidos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
        const tiposHospital = ['agredidos', 'tratamientos'];

        // ==================== FUNCIONES GLOBALES (fuera de ready) ====================
        function toggleJurisdiccionList() {
            const btn = document.querySelector('.juris-toggle-btn');
            const list = document.getElementById('jurisdiccionList');
            btn.classList.toggle('active');
            list.classList.toggle('show');
        }

        function cambiarAnio(anio) {
            const url = new URL(window.location.href);
            url.searchParams.set('anio', anio);
            window.location.href = url.toString();
        }

        // ==================== EDITAR META ====================
        function abrirEditarLugar(jurisdiccion, municipio, esHospital) {
            $('#edit_jurisdiccion_val').val(jurisdiccion);
            $('#edit_municipio_val').val(municipio);
            $('#edit_lugar_display').val(municipio);
            $('#edit_valor_container').hide();
            $('#btn_actualizar').prop('disabled', true);

            let html = '';
            const metas = metasData[jurisdiccion]?.[municipio] || {};

            tiposPermitidos.forEach(tipo => {
                const meta = metas[tipo];
                const tipoCapitalized = tipo.charAt(0).toUpperCase() + tipo.slice(1);

                if (esHospital && !tiposHospital.includes(tipo)) {
                    html += `<div class="tipo-item disabled"><i class="fas fa-ban me-1"></i>${tipoCapitalized}<span class="tipo-valor">N/A</span></div>`;
                } else if (meta) {
                    html += `<div class="tipo-item" data-id="${meta.id}" data-valor="${meta.meta}" data-tipo="${tipo}"><i class="fas fa-check-circle me-1"></i>${tipoCapitalized}<span class="tipo-valor">Meta: ${Number(meta.meta).toLocaleString()}</span></div>`;
                } else {
                    html += `<div class="tipo-item disabled"><i class="fas fa-minus-circle me-1"></i>${tipoCapitalized}<span class="tipo-valor">Sin meta</span></div>`;
                }
            });

            $('#edit_tipos_lista').html(html);

            $('#edit_tipos_lista .tipo-item:not(.disabled)').click(function() {
                $('#edit_tipos_lista .tipo-item').removeClass('selected');
                $(this).addClass('selected');
                $('#edit_meta_id').val($(this).data('id'));
                $('#edit_valor').val($(this).data('valor'));
                $('#edit_valor_container').slideDown();
                $('#btn_actualizar').prop('disabled', false);
            });

            $('#modalEditarMeta').modal('show');
        }

        $('#formEditarMeta').on('submit', function(e) {
            e.preventDefault();
            const id = $('#edit_meta_id').val();
            const valor = $('#edit_valor').val();
            const csrf = $('input[name="csrf_token"]').first().val();

            $.ajax({
                url: 'guardar_meta.php',
                method: 'POST',
                data: {
                    accion: 'actualizar',
                    id: id,
                    valor: valor,
                    csrf_token: csrf
                },
                dataType: 'json',
                success: function(response) {
                    if (response.ok) {
                        $('#modalEditarMeta').modal('hide');
                        location.reload();
                    } else {
                        alert('Error: ' + response.msg);
                    }
                },
                error: function() {
                    alert('Error al actualizar');
                }
            });
        });

        // ==================== ELIMINAR META ====================
        function abrirEliminarLugar(jurisdiccion, municipio, esHospital) {
            $('#delete_jurisdiccion_val').val(jurisdiccion);
            $('#delete_municipio_val').val(municipio);
            $('#delete_lugar_display').val(municipio);
            $('#delete_confirm_container').hide();
            $('#btn_eliminar').prop('disabled', true);

            let html = '';
            const metas = metasData[jurisdiccion]?.[municipio] || {};

            tiposPermitidos.forEach(tipo => {
                const meta = metas[tipo];
                const tipoCapitalized = tipo.charAt(0).toUpperCase() + tipo.slice(1);

                if (esHospital && !tiposHospital.includes(tipo)) {
                    html += `<div class="tipo-item disabled"><i class="fas fa-ban me-1"></i>${tipoCapitalized}<span class="tipo-valor">N/A</span></div>`;
                } else if (meta) {
                    html += `<div class="tipo-item" data-id="${meta.id}" data-tipo="${tipo}"><i class="fas fa-trash me-1"></i>${tipoCapitalized}<span class="tipo-valor">Meta: ${Number(meta.meta).toLocaleString()}</span></div>`;
                } else {
                    html += `<div class="tipo-item disabled"><i class="fas fa-minus-circle me-1"></i>${tipoCapitalized}<span class="tipo-valor">Sin meta</span></div>`;
                }
            });

            $('#delete_tipos_lista').html(html);

            $('#delete_tipos_lista .tipo-item:not(.disabled)').click(function() {
                $('#delete_tipos_lista .tipo-item').removeClass('selected-delete');
                $(this).addClass('selected-delete');
                $('#delete_meta_id').val($(this).data('id'));
                $('#delete_confirm_container').slideDown();
                $('#btn_eliminar').prop('disabled', false);
            });

            $('#modalEliminarMeta').modal('show');
        }

        $('#formEliminarMeta').on('submit', function(e) {
            e.preventDefault();
            const id = $('#delete_meta_id').val();
            const csrf = $('input[name="csrf_token"]').first().val();

            $.ajax({
                url: 'guardar_meta.php',
                method: 'POST',
                data: {
                    accion: 'eliminar',
                    id: id,
                    csrf_token: csrf
                },
                dataType: 'json',
                success: function(response) {
                    if (response.ok) {
                        $('#modalEliminarMeta').modal('hide');
                        location.reload();
                    } else {
                        alert('Error: ' + response.msg);
                    }
                },
                error: function() {
                    alert('Error al eliminar');
                }
            });
        });

        // ==================== DOCUMENT READY - EVENT LISTENERS ====================
        $(document).ready(function() {
            console.log('DOM completamente cargado - Inicializando eventos de metas');

            // Reset modales
            $('#modalEditarMeta, #modalEliminarMeta').on('hidden.bs.modal', function() {
                $(this).find('.tipo-item').removeClass('selected selected-delete');
                $('#edit_valor_container, #delete_confirm_container').hide();
                $('#btn_actualizar, #btn_eliminar').prop('disabled', true);
            });

            // ==================== CREAR META ====================
            function cargarEstablecimientos() {
                const jurisdiccion = $('select[name="jurisdiccion"]').val();
                const tipo = $('select[name="tipo"]').val();
                const anioFiltro = $('#filtroAnio').val();

                console.log('Cargando establecimientos:', {
                    jurisdiccion,
                    tipo,
                    anioFiltro
                });

                if (jurisdiccion && tipo) {
                    $.post('metas.php', {
                        cargar_lugares: true,
                        jurisdiccion: jurisdiccion,
                        tipo_meta: tipo,
                        anio_filtro: anioFiltro
                    }, function(data) {
                        console.log('Municipios recibidos:', data);
                        $('select[name="municipio"]').html(data);
                    }).fail(function(xhr, status, error) {
                        console.error('Error cargando municipios:', error);
                    });
                } else {
                    $('select[name="municipio"]').html('<option value="">Seleccione jurisdicción y tipo primero</option>');
                }
            }

            $('select[name="jurisdiccion"]').change(cargarEstablecimientos);
            $('select[name="tipo"]').change(cargarEstablecimientos);

            console.log('Funciones de carga de municipios registradas');

            // Enviar formulario de meta via AJAX (usando click en botón)
            $(document).on('click', '#btnGuardarMeta', function(e) {
                console.log('=== CLICK EN GUARDAR META ===');

                const form = $('#formCrearMeta');
                const jurisdiccion = form.find('select[name="jurisdiccion"]').val();
                const municipio = form.find('select[name="municipio"]').val();
                const tipo = form.find('select[name="tipo"]').val();
                const valor = form.find('input[name="valor"]').val();

                console.log('Jurisdicción:', jurisdiccion);
                console.log('Municipio:', municipio);
                console.log('Tipo:', tipo);
                console.log('Valor:', valor);

                if (!jurisdiccion || !municipio || !tipo || !valor) {
                    alert('Por favor, complete todos los campos');
                    console.log('VALIDACIÓN FALLÓ');
                    return;
                }

                console.log('Validación OK - Enviando AJAX...');

                $.ajax({
                    url: 'guardar_meta.php',
                    method: 'POST',
                    data: form.serialize(),
                    dataType: 'json',
                    success: function(response) {
                        console.log('Respuesta recibida:', response);
                        if (response.ok) {
                            alert('Meta guardada correctamente');
                            $('#modalCrearMeta').modal('hide');
                            location.reload();
                        } else {
                            alert('Error: ' + response.msg);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error AJAX:', error);
                        console.error('Status:', status);
                        console.error('Response:', xhr.responseText);
                        alert('Error al guardar la meta: ' + error);
                    }
                });
            });

            console.log('Event listener de click registrado con delegación');

            $('#modalCrearMeta').on('hidden.bs.modal', function() {
                $('#formCrearMeta')[0].reset();
                $('select[name="municipio"]').html('<option value="">Seleccione jurisdicción y tipo primero</option>');
            });

            // Cerrar lista al hacer clic fuera
            document.addEventListener('click', function(e) {
                const wrapper = document.querySelector('.jurisdiccion-wrapper');
                const btn = document.querySelector('.juris-toggle-btn');
                const list = document.getElementById('jurisdiccionList');

                if (wrapper && !wrapper.contains(e.target)) {
                    btn.classList.remove('active');
                    list.classList.remove('show');
                }
            });

        }); // FIN $(document).ready()
    </script>
</body>

</html>