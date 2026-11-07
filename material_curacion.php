<?php

/**
 * MÓDULO DE MATERIAL DE CURACIÓN
 * Vista dual: Formulario/Tabla de captura | Tabla de seguimiento
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

// Headers adicionales anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

require_once 'partials/check_session.php';

$rol = $_SESSION['rol'];
$usuario_id = $_SESSION['usuario_id'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';
$esAdmin = ($rol === 'admin');

$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$anioActual = $config_sistema['anio_activo'] ?? date('Y');

$tablaExiste = false;
$checkTable = mysqli_query($conn, "SHOW TABLES LIKE 'insumos'");
if ($checkTable && mysqli_num_rows($checkTable) > 0) $tablaExiste = true;

$anioSel = isset($_GET['anio']) ? (int)$_GET['anio'] : $anioActual;
$insumoSel = isset($_GET['insumo']) ? (int)$_GET['insumo'] : 0;
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'captura';
$modoCaptura = isset($_GET['modo']) ? $_GET['modo'] : 'simple'; // simple o tabla

$insumos = [];
if ($tablaExiste) {
    $res = mysqli_query($conn, "SELECT DISTINCT id, nombre, presentacion FROM insumos WHERE activo = 1 ORDER BY orden");
    if ($res) while ($row = mysqli_fetch_assoc($res)) $insumos[] = $row;
}
if ($insumoSel === 0 && count($insumos) > 0) $insumoSel = $insumos[0]['id'];

$insumoInfo = null;
foreach ($insumos as $ins) if ($ins['id'] == $insumoSel) {
    $insumoInfo = $ins;
    break;
}

$todasJurisdicciones = jurisdicciones();
$meses = [1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'];
$mesesCompletos = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

// Obtener registros
$datos = [];
$mesMaximo = 1;

if ($tablaExiste && $insumoSel > 0) {
    $sql = "SELECT * FROM material_registro WHERE insumo_id = ? AND anio = ? ORDER BY jurisdiccion, mes";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $insumoSel, $anioSel);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    while ($row = mysqli_fetch_assoc($res)) {
        $jur = $row['jurisdiccion'];
        $mes = (int)$row['mes'];
        if (!isset($datos[$jur])) $datos[$jur] = [];
        $datos[$jur][$mes] = $row;
        if ($mes > $mesMaximo) $mesMaximo = $mes;
    }

    // Calcular el siguiente mes disponible para el usuario actual
    $mesSeleccionado = 1; // Por defecto enero
    if (!$esAdmin && isset($datos[$jurisdiccion_usuario])) {
        // Para capturista: buscar el último mes registrado de su jurisdicción
        $mesesRegistrados = array_keys($datos[$jurisdiccion_usuario]);
        if (!empty($mesesRegistrados)) {
            $ultimoMes = max($mesesRegistrados);
            $mesSeleccionado = ($ultimoMes < 12) ? $ultimoMes + 1 : 12;
        }
    } elseif ($esAdmin) {
        // Para admin: usar el mes máximo global + 1
        $mesSeleccionado = ($mesMaximo < 12) ? $mesMaximo + 1 : 12;
    }

    // Obtener remanentes de diciembre del año anterior para cada jurisdicción
    $anioAnterior = $anioSel - 1;
    $sql = "SELECT jurisdiccion, remanente FROM material_registro WHERE insumo_id = ? AND anio = ? AND mes = 12";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ii', $insumoSel, $anioAnterior);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);

    $remanentesAnoAnterior = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $remanentesAnoAnterior[$row['jurisdiccion']] = (int)$row['remanente'];
    }
}

$mesesAMostrar = [];
// Si hay datos, mostrar todos los 12 meses para ver el flujo completo
// Si no hay datos, también mostrar los 12 meses (se maneja en la vista)
if (!empty($datos)) {
    for ($m = 1; $m <= 12; $m++) {
        $mesesAMostrar[$m] = true;
    }
} else {
    // Sin datos, se manejan los 12 meses en la vista
    for ($m = 1; $m <= 12; $m++) {
        $mesesAMostrar[$m] = true;
    }
}

// Calcular valores
foreach ($datos as $jur => &$mesesData) {
    $remanentePrevio = 0;
    for ($m = 1; $m <= 12; $m++) {
        if (isset($mesesData[$m])) {
            $entregadoNuevo = (int)$mesesData[$m]['entregado'];
            $utilizado = (int)$mesesData[$m]['utilizado'];
            $disponible = $remanentePrevio + $entregadoNuevo;
            $remanente = $disponible - $utilizado;
            $mesesData[$m]['disponible_calc'] = $disponible;
            $mesesData[$m]['remanente_calc'] = $remanente;
            $remanentePrevio = $remanente;
        }
    }
}
unset($mesesData);

// Calcular totales para las tarjetas de resumen
// Sumar TODOS los valores que se muestran en la tabla (todas las jurisdicciones y todos los meses)
$totalEntregadoGlobal = 0;
$totalUtilizadoGlobal = 0;
$totalRemanenteGlobal = 0;

foreach ($datos as $jur => $mesesData) {
    // Si es capturista, solo contar datos de su jurisdicción
    if (!$esAdmin && $jur !== $jurisdiccion_usuario) {
        continue;
    }

    // Calcular y sumar cada celda de la tabla
    $remanentePrevio = $remanentesAnoAnterior[$jur] ?? 0;

    foreach ($mesesData as $mes => $reg) {
        $entregadoNuevo = (int)$reg['entregado'];
        $utilizado = (int)$reg['utilizado'];
        $disponible = $remanentePrevio + $entregadoNuevo;
        $remanente = $disponible - $utilizado;

        // Sumar lo que se muestra en cada celda de la tabla
        $totalEntregadoGlobal += $disponible;  // Columna "Entregado" (disponible)
        $totalUtilizadoGlobal += $utilizado;    // Columna "Utilizado"
        $totalRemanenteGlobal += $remanente;    // Columna "Remanente"

        $remanentePrevio = $remanente;
    }
}

$mensaje = $_SESSION['mensaje'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

// Datos para JS (editar/eliminar)
$datosJS = [];
foreach ($datos as $jur => $mesesData) {
    $datosJS[$jur] = [];
    foreach ($mesesData as $mes => $reg) {
        $datosJS[$jur][$mes] = [
            'id' => $reg['id'],
            'mes' => $mes,
            'mes_nombre' => $mesesCompletos[$mes],
            'entregado' => (int)$reg['entregado'],
            'utilizado' => (int)$reg['utilizado']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Material de Curación - Zoonosis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>">
    <!-- Build: <?= date('Y-m-d H:i:s') ?> -->
    <style>
        /* Header con gradiente azul - mismo estilo que capturar_mes.php */
        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: 16px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
        }

        .page-header .page-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: white;
        }

        .page-header .page-title i {
            margin-right: 0.5rem;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px
        }

        /* Tarjeta de controles */
        .controls-card {
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .08);
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center
        }

        .btn-insumo {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #fff;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 12px rgba(59, 130, 246, .3);
            transition: all .2s
        }

        .btn-insumo:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(59, 130, 246, .4)
        }

        .btn-vista {
            padding: 10px 20px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            background: #fff;
            cursor: pointer;
            font-weight: 500;
            transition: all .2s
        }

        .btn-vista.active {
            background: #3b82f6;
            color: #fff;
            border-color: #3b82f6
        }

        .btn-vista:hover:not(.active) {
            border-color: #3b82f6;
            color: #3b82f6
        }

        .btn-modo {
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid #cbd5e1;
            background: #fff;
            cursor: pointer;
            font-size: .9rem;
            transition: all .2s
        }

        .btn-modo.active {
            background: #10b981;
            color: #fff;
            border-color: #10b981
        }

        .year-select {
            padding: 10px 16px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            font-weight: 500;
            font-size: 1rem
        }

        .btn-export {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: 10px 18px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(16, 185, 129, .3);
            transition: all .2s
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, .4);
            color: #fff
        }

        /* Tarjeta de contenido */
        .content-card {
            background: #fff;
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .08);
            margin-bottom: 24px
        }

        .content-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px
        }

        .insumo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
            max-height: 400px;
            overflow-y: auto
        }

        .insumo-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
            cursor: pointer
        }

        .insumo-card:hover {
            border-color: #3b82f6;
            background: #eff6ff
        }

        .insumo-card.selected {
            border-color: #3b82f6;
            background: #dbeafe
        }

        .insumo-card .insumo-name {
            font-weight: 600;
            color: #1e40af;
            font-size: .9rem
        }

        .insumo-card .insumo-pres {
            font-size: .75rem;
            color: #64748b
        }

        .capture-section {
            background: #fff;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .08)
        }

        .insumo-actual {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #fff;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 16px
        }

        /* FORMULARIO ESTILO CAPTURAR_MES - Tarjetas con íconos */
        .capture-grid-material {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem
        }

        .material-card {
            background: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .08);
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
            transition: all .3s ease
        }

        .material-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%
        }

        .material-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, .12)
        }

        /* Colores por tipo de campo */
        .material-card.jurisdiccion::before {
            background: linear-gradient(90deg, #3b82f6, #2563eb)
        }

        .material-card.mes::before {
            background: linear-gradient(90deg, #8b5cf6, #7c3aed)
        }

        .material-card.entregado::before {
            background: linear-gradient(90deg, #10b981, #059669)
        }

        .material-card.utilizado::before {
            background: linear-gradient(90deg, #f59e0b, #d97706)
        }

        .material-card .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem
        }

        .material-card.jurisdiccion .card-icon {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #2563eb
        }

        .material-card.mes .card-icon {
            background: linear-gradient(135deg, #ede9fe, #ddd6fe);
            color: #7c3aed
        }

        .material-card.entregado .card-icon {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #059669
        }

        .material-card.utilizado .card-icon {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #d97706
        }

        .material-card .card-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: .5rem;
            color: #1e293b
        }

        .material-card .card-subtitle {
            font-size: .85rem;
            color: #64748b;
            margin-bottom: 1rem
        }

        .material-card .card-input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            transition: all .3s ease;
            background: #fff
        }

        .material-card .card-input:hover {
            border-color: #cbd5e1
        }

        .material-card .card-input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, .15)
        }

        .material-card .card-input::placeholder {
            color: #cbd5e1;
            font-weight: 400
        }

        .material-card .card-input-readonly {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #93c5fd;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 700;
            text-align: center;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af
        }

        .form-actions-material {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem
        }

        .btn-save-material {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: 16px 48px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 6px 20px rgba(16, 185, 129, .3);
            transition: all .3s
        }

        .btn-save-material:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(16, 185, 129, .4)
        }

        .legend-material {
            display: flex;
            gap: 2rem;
            justify-content: center;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 12px;
            flex-wrap: wrap
        }

        .legend-item-material {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .9rem;
            color: #64748b;
            font-weight: 500
        }

        .dot-material {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15)
        }

        .dot-material.green {
            background: #22c55e
        }

        .dot-material.orange {
            background: #f59e0b
        }

        .dot-material.blue {
            background: #3b82f6
        }

        /* Formulario simple (mantener para compatibilidad) */
        .simple-form {
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
            border: 2px solid #0ea5e9;
            border-radius: 12px;
            padding: 0;
            overflow: hidden
        }

        .simple-form-header {
            background: linear-gradient(135deg, #0284c7, #0369a1);
            color: #fff;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            gap: 10px
        }

        .simple-form table {
            width: 100%;
            border-collapse: collapse
        }

        .simple-form th {
            background: #0ea5e9;
            color: #fff;
            padding: 10px;
            font-size: .85rem
        }

        .simple-form td {
            padding: 10px;
            background: #fff
        }

        .simple-form .col-ent {
            background: #dcfce7
        }

        .simple-form .col-util {
            background: #fce7f3
        }

        .simple-form th.col-util {
            background: #db2777 !important;
            color: #fff !important
        }

        .btn-add {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer
        }

        /* FORMULARIO LIMPIO Y ELEGANTE */
        .clean-form-container {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, .08)
        }

        .clean-form-header {
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: #fff
        }

        .header-icon {
            width: 48px;
            height: 48px;
            background: rgba(255, 255, 255, .2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem
        }

        .header-text h3 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 700
        }

        .header-text p {
            margin: .25rem 0 0;
            opacity: .9;
            font-size: .9rem
        }

        .clean-form {
            padding: 2rem
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: .5rem
        }

        .form-label {
            font-weight: 600;
            color: #475569;
            font-size: .9rem;
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .form-label i {
            color: #3b82f6
        }

        .required {
            color: #ef4444;
            font-weight: 700
        }

        .form-control {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: .95rem;
            transition: all .2s;
            background: #fff;
            color: #1e293b
        }

        .form-control:hover {
            border-color: #cbd5e1
        }

        .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, .1);
            outline: none
        }

        .form-control-static {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border: 2px solid #93c5fd;
            border-radius: 10px;
            padding: 12px 14px;
            font-weight: 600;
            color: #1e40af;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .form-text {
            color: #64748b;
            font-size: .8rem;
            margin-top: 4px
        }

        .form-actions {
            display: flex;
            justify-content: center;
            padding-top: 1rem;
            border-top: 2px solid #f1f5f9
        }

        .btn-submit {
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border: none;
            padding: 14px 40px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 12px rgba(16, 185, 129, .3);
            transition: all .2s
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(16, 185, 129, .4)
        }

        .form-footer {
            background: #f8fafc;
            padding: 1.25rem 2rem;
            display: flex;
            gap: 2rem;
            justify-content: center;
            flex-wrap: wrap;
            border-top: 2px solid #e2e8f0
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: .9rem;
            color: #64748b
        }

        .dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15)
        }

        .dot.green {
            background: #22c55e
        }

        .dot.orange {
            background: #f59e0b
        }

        .dot.blue {
            background: #3b82f6
        }

        /* Tabla captura */
        .capture-table {
            width: 100%;
            border-collapse: collapse
        }

        .capture-table th {
            background: #1e40af;
            color: #fff;
            padding: 10px;
            font-weight: 600
        }

        .capture-table td {
            padding: 8px;
            border-bottom: 1px solid #e2e8f0
        }

        .capture-table .th-ent {
            background: #22c55e
        }

        .capture-table .th-util {
            background: #db2777;
            color: #fff
        }

        .capture-table .cell-ent {
            background: #f0fdf4
        }

        .capture-table .cell-util {
            background: #fdf2f8
        }

        .btn-save-row {
            background: #10b981;
            color: #fff;
            border: none;
            width: 32px;
            height: 32px;
            border-radius: 6px;
            cursor: pointer
        }

        /* Tabla seguimiento - Estilo igual a Reportes */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            padding: 1rem
        }

        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
            min-width: 1800px
        }

        .excel-table th {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 50%, #3b82f6 100%);
            color: #fff;
            font-weight: 600;
            padding: 12px 8px;
            text-align: center;
            border: 1px solid #1e40af;
            position: sticky;
            top: 0;
            z-index: 10;
            font-size: .75rem;
            white-space: nowrap
        }

        .excel-table th:first-child {
            border-radius: 12px 0 0 0;
            min-width: 200px;
            max-width: 200px
        }

        .excel-table th:last-child {
            border-radius: 0 12px 0 0;
            min-width: 80px
        }

        .excel-table td {
            padding: 10px 8px;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            font-size: .8rem
        }

        .excel-table tbody tr {
            background: white;
            transition: all .2s ease
        }

        .excel-table tbody tr:hover {
            background: linear-gradient(90deg, #eff6ff, #dbeafe)
        }

        .excel-table tbody tr:hover td {
            color: #1e293b
        }

        .excel-table thead tr:nth-child(2) th {
            font-size: .7rem;
            font-weight: 600
        }

        /* Subheaders pastel */
        .excel-table .th-ent {
            background: #dbeafe !important;
            color: #1e40af !important
        }

        .excel-table .th-util {
            background: #ffedd5 !important;
            color: #c2410c !important
        }

        .excel-table .th-rem {
            background: #dcfce7 !important;
            color: #166534 !important
        }

        .excel-table .th-tot-ent {
            background: #e0e7ff !important;
            color: #3730a3 !important
        }

        .excel-table .th-tot-util {
            background: #fce7f3 !important;
            color: #9d174d !important
        }

        .excel-table .col-jur {
            background: #f8fafc;
            text-align: left;
            font-weight: 600;
            position: sticky;
            left: 0;
            color: #1f2937;
            border-right: 2px solid #e2e8f0
        }

        /* Celdas pastel */
        .excel-table .cell-ent {
            background: #eff6ff;
            color: #1e40af;
            font-weight: 500
        }

        .excel-table .cell-util {
            background: #fff7ed;
            color: #c2410c;
            font-weight: 500
        }

        .excel-table .cell-rem {
            background: #f0fdf4;
            color: #166534;
            font-weight: 600
        }

        .excel-table .cell-rem.negative {
            background: #fef2f2;
            color: #dc2626;
            font-weight: 600
        }

        .excel-table .cell-empty {
            background: #f9fafb;
            color: #9ca3af
        }

        /* Celdas de totales */
        .excel-table .cell-tot-ent {
            background: #e0e7ff;
            color: #3730a3;
            font-weight: 700
        }

        .excel-table .cell-tot-util {
            background: #fce7f3;
            color: #9d174d;
            font-weight: 700
        }

        /* Fila de totales */
        .excel-table .row-total td {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0) !important;
            color: #1e293b !important;
            font-weight: 700;
            border-top: 3px solid #3b82f6;
            font-size: .9rem
        }

        .excel-table .row-total td:first-child {
            text-align: left
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #64748b
        }

        .setup-alert {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px
        }

        .legend {
            display: flex;
            gap: 16px;
            padding: 12px;
            background: #f0f9ff;
            border-top: 1px solid #bae6fd;
            font-size: .8rem;
            color: #475569;
            border-radius: 0 0 12px 12px
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            display: inline-block;
            margin-right: 4px
        }

        .btn-action {
            width: 28px;
            height: 28px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: .9rem;
            transition: all .2s;
            display: inline-flex;
            align-items: center;
            justify-content: center
        }

        .btn-action:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: none !important
        }

        .btn-edit {
            background: #eff6ff;
            color: #3b82f6
        }

        .btn-edit:hover:not(:disabled) {
            background: #3b82f6;
            color: #fff;
            transform: scale(1.1)
        }

        .btn-delete {
            background: #fef2f2;
            color: #ef4444
        }

        .btn-delete:hover:not(:disabled) {
            background: #ef4444;
            color: #fff;
            transform: scale(1.1)
        }

        .actions-cell {
            display: flex;
            gap: 6px;
            justify-content: center;
            align-items: center
        }

        .actions-cell {
            display: flex;
            gap: 6px;
            justify-content: center
        }

        .mes-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
            cursor: pointer;
            text-align: center;
            margin-bottom: 8px;
            transition: all .2s
        }

        .mes-card:hover {
            border-color: #3b82f6;
            background: #eff6ff;
            transform: translateY(-2px)
        }

        .mes-card.selected {
            border-color: #3b82f6;
            background: #dbeafe
        }

        .insumo-card {
            background: #f8fafc;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 14px;
            cursor: pointer;
            transition: all .2s
        }

        .insumo-card:hover {
            border-color: #3b82f6;
            background: #eff6ff;
            transform: translateY(-2px)
        }

        .insumo-card.selected {
            border-color: #3b82f6;
            background: #dbeafe
        }

        .insumo-card .insumo-name {
            font-weight: 600;
            color: #1e40af;
            font-size: .9rem
        }

        .insumo-card .insumo-pres {
            font-size: .75rem;
            color: #64748b;
            margin-top: 4px
        }

        /* ========================================
           SPLIT VIEW DASHBOARD - Estilo Minimalista
           ======================================== */

        .split-view-form {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        }

        .split-container {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 0;
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05), 0 10px 40px rgba(0, 0, 0, 0.08);
            min-height: 600px;
            position: relative;
            z-index: 10;
        }

        /* Lado Izquierdo - Formulario */
        .split-left {
            padding: 3rem;
            background: white;
        }

        .form-header-split {
            margin-bottom: 2.5rem;
        }

        .form-title-split {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0 0 0.5rem 0;
            letter-spacing: -0.025em;
        }

        .form-subtitle-split {
            font-size: 0.95rem;
            color: #64748b;
            margin: 0;
            font-weight: 400;
        }

        .form-fields-split {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .field-group-split {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            position: relative;
            padding-left: 12px;
        }

        /* Líneas BLANCAS a la izquierda de cada campo */
        .field-group-split::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 3px;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.15);
        }

        .field-label-split {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #334155;
            letter-spacing: -0.01em;
        }

        .field-icon-split {
            width: 18px;
            height: 18px;
        }

        /* Iconos con colores específicos */
        .field-group-split:nth-child(1) .field-icon-split {
            color: #3b82f6;
        }

        .field-group-split:nth-child(2) .field-icon-split {
            color: #8b5cf6;
        }

        .field-group-split:nth-child(3) .field-icon-split {
            color: #10b981;
        }

        .field-group-split:nth-child(4) .field-icon-split {
            color: #f59e0b;
        }

        .field-input-split {
            width: 100%;
            padding: 0.875rem 1rem;
            background: #f8fafc;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            color: #0f172a;
            transition: all 0.2s ease;
        }

        .field-input-split:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }

        .field-input-split:focus {
            outline: none;
            background: white;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .field-input-readonly-split {
            padding: 0.875rem 1rem;
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            border: 1.5px solid #93c5fd;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            color: #1e40af;
            display: flex;
            align-items: center;
        }

        /* Botón Submit */
        .btn-submit-split {
            margin-top: 2rem;
            width: 100%;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
        }

        .btn-submit-split:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.35);
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        .btn-submit-split:active {
            transform: translateY(0);
        }

        .btn-icon-split {
            width: 20px;
            height: 20px;
        }

        /* Lado Derecho - Resumen en Vivo (FONDO BLANCO) */
        .split-right {
            padding: 3rem 2.5rem;
            background: white;
            display: flex;
            flex-direction: column;
            gap: 2rem;
            border-left: 1px solid #e2e8f0;
        }

        .summary-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .summary-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #0f172a;
            margin: 0;
            letter-spacing: -0.025em;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .summary-title::before {
            content: '';
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            mask-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='white'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'/%3E%3C/svg%3E");
            mask-size: 18px;
            mask-repeat: no-repeat;
            mask-position: center;
        }

        .summary-badge {
            display: inline-block;
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border: 1px solid #93c5fd;
            border-radius: 8px;
            color: #1e40af;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.025em;
            text-transform: uppercase;
            width: fit-content;
        }

        .summary-cards {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            flex: 1;
        }

        .summary-card {
            padding: 1.75rem 1.5rem;
            border-radius: 16px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        /* Efecto de brillo en hover */
        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
            transition: left 0.5s ease;
        }

        .summary-card:hover::before {
            left: 100%;
        }

        .summary-card:hover {
            transform: translateX(5px) scale(1.02);
        }

        .summary-card.green {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            border: 2px solid #047857;
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.4);
        }

        .summary-card.green .summary-label,
        .summary-card.green .summary-value,
        .summary-card.green .summary-desc {
            color: white;
        }

        .summary-card.green .summary-icon {
            color: rgba(255, 255, 255, 0.9);
        }

        .summary-card.orange {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            border: 2px solid #b45309;
            box-shadow: 0 8px 24px rgba(245, 158, 11, 0.4);
        }

        .summary-card.orange .summary-label,
        .summary-card.orange .summary-value,
        .summary-card.orange .summary-desc {
            color: white;
        }

        .summary-card.orange .summary-icon {
            color: rgba(255, 255, 255, 0.9);
        }

        .summary-card.blue {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: 2px solid #1d4ed8;
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.4);
        }

        .summary-card.blue .summary-label,
        .summary-card.blue .summary-value,
        .summary-card.blue .summary-desc {
            color: white;
        }

        .summary-card.blue .summary-icon {
            color: rgba(255, 255, 255, 0.9);
        }

        .summary-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }

        .summary-icon {
            width: 24px;
            height: 24px;
        }

        .summary-card.green .summary-icon {
            color: #10b981;
        }

        .summary-card.orange .summary-icon {
            color: #f59e0b;
        }

        .summary-card.blue .summary-icon {
            color: #3b82f6;
        }

        .summary-label {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .summary-value {
            font-size: 2.5rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 0.25rem;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .summary-desc {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
        }

        .summary-footer {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            margin-top: auto;
        }

        .summary-footer-icon {
            width: 18px;
            height: 18px;
            color: #3b82f6;
            flex-shrink: 0;
        }

        .summary-footer span {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
        }

        /* RESPONSIVO */
        @media (max-width: 992px) {
            .main-container {
                padding: 12px
            }

            .controls-card {
                flex-direction: column;
                padding: 12px;
                gap: 10px
            }

            .controls-card>* {
                width: 100%
            }

            .btn-insumo,
            .btn-vista,
            .btn-export {
                width: 100%;
                justify-content: center
            }

            .content-card {
                padding: 16px
            }

            .simple-form table {
                font-size: .8rem
            }

            .excel-table {
                font-size: .7rem
            }

            .excel-table th,
            .excel-table td {
                padding: 6px 4px
            }

            .insumo-grid {
                grid-template-columns: 1fr 1fr
            }

            /* Split View Responsive */
            .split-container {
                grid-template-columns: 1fr;
                min-height: auto;
            }

            .split-left {
                padding: 1.5rem;
                /* Reduced from 2rem 1.5rem */
            }

            .split-right {
                padding: 1.5rem;
            }

            .summary-cards {
                flex-direction: row;
                overflow-x: auto;
                gap: 1rem;
            }

            .summary-card {
                min-width: 200px;
            }

            /* Botón de toggle solo visible en móvil/tablet */
            .summary-toggle-btn {
                display: flex !important;
            }

            /* Estado colapsado */
            .split-right.collapsed .summary-cards,
            .split-right.collapsed .summary-footer {
                display: none;
            }

            .split-right.collapsed {
                padding-bottom: 1rem;
            }

            .split-right.collapsed .summary-header {
                margin-bottom: 0;
            }
        }

        /* Botón toggle oculto en desktop */
        .summary-toggle-btn {
            display: none;
            background: none;
            border: none;
            color: #3b82f6;
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
            margin-left: auto;
        }

        @media (max-width: 768px) {

            /* Hacer tabla scrollable horizontalmente */
            .simple-form {
                overflow-x: auto
            }

            .simple-form table {
                min-width: 500px
            }

            .capture-table {
                min-width: 500px
            }

            .table-responsive {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch
            }
        }

        @media (max-width: 992px) {

            /* RESET AGRESIVO PARA MÓVIL */
            .main-container {
                padding: 12px 8px !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden !important;
                box-sizing: border-box !important;
            }

            .split-container {
                display: block !important;
                /* Force vertical stacking, kill grid */
                border-radius: 12px;
                width: 100% !important;
                margin: 0 !important;
            }

            .split-left,
            .split-right {
                padding: 1.25rem 1rem !important;
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* Asegurar que los inputs no desborden */
            .field-input-split,
            .field-input-readonly-split,
            .form-control,
            .form-select {
                width: 100% !important;
                min-width: 0 !important;
                /* Permitir encogimiento flex */
                box-sizing: border-box !important;
                max-width: 100% !important;
            }

            .form-title-split {
                font-size: 1.5rem;
                word-wrap: break-word;
            }

            .btn-submit-split {
                width: 100% !important;
                box-sizing: border-box !important;
                padding: 14px !important;
                font-size: 1rem !important;
                margin-top: 1.5rem !important;
                display: flex !important;
                justify-content: center !important;
            }

            .controls-card {
                padding: 12px !important;
                gap: 10px !important;
                flex-direction: column !important;
                width: 100% !important;
            }

            .controls-card>* {
                width: 100% !important;
                margin: 0 !important;
            }

            /* Ajuste de iconos y textos */
            .btn-insumo {

                padding: 10px 16px;
                font-size: .9rem
            }

            .year-select {
                padding: 8px 12px
            }

            .content-card {
                padding: 12px
            }

            .insumo-actual {
                font-size: .85rem;
                padding: 6px 12px
            }

            .simple-form th,
            .simple-form td {
                padding: 8px 6px;
                font-size: .8rem
            }

            .simple-form select,
            .simple-form input {
                padding: 8px;
                font-size: .85rem
            }

            .capture-table th,
            .capture-table td {
                padding: 6px;
                font-size: .75rem
            }

            .excel-table {
                font-size: .65rem
            }

            .excel-table th,
            .excel-table td {
                padding: 4px 2px
            }

            .col-jur {
                font-size: .7rem;
                min-width: 100px
            }

            .insumo-grid {
                grid-template-columns: 1fr
            }

            .actions-cell {
                flex-direction: column;
                gap: 4px
            }

            .btn-action {
                width: 24px;
                height: 24px
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <h1 class="page-title">
                    <svg width="32" height="32" fill="currentColor" viewBox="0 0 24 24" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                        <path d="M4.22 11.29l6.36-6.36c1.95-1.95 5.12-1.95 7.07 0 1.95 1.95 1.95 5.12 0 7.07l-6.36 6.36c-1.95 1.95-5.12 1.95-7.07 0-1.95-1.95-1.95-5.12 0-7.07zm9.9 2.12l-5.66-5.66-4.24 4.24c-1.17 1.17-1.17 3.07 0 4.24 1.17 1.17 3.07 1.17 4.24 0l5.66-2.82z" opacity="0.4" />
                        <path d="M10.59 12l5.66 5.66c1.17 1.17 3.07 1.17 4.24 0 1.17-1.17 1.17-3.07 0-4.24L14.83 7.76 10.59 12z" />
                    </svg>
                    Material de Curación
                </h1>
                <p style="color: rgba(255, 255, 255, 0.95); margin: 0; font-size: 0.95rem; font-weight: 500;">
                    Gestión y seguimiento de insumos médicos
                </p>
            </div>

            <?php if (!$tablaExiste): ?>
                <div class="setup-alert">
                    <h5 style="margin:0 0 10px;color:#92400e">⚠️ Configuración Requerida</h5>
                    <p style="margin:0"><a href="setup_material.php">Ejecutar configuración</a></p>
                </div>
            <?php else: ?>

                <?php if ($mensaje): ?><div class="alert alert-success alert-dismissible fade show" style="max-width:800px;margin:0 auto 20px"><?= htmlspecialchars($mensaje) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
                <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show" style="max-width:800px;margin:0 auto 20px"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

                <div class="main-container">
                    <!-- Controles reorganizados -->
                    <div class="controls-card">
                        <div style="display: flex; align-items: center; gap: 20px; flex-wrap: wrap; width: 100%;">
                            <!-- Selector de insumo -->
                            <button class="btn-insumo" data-bs-toggle="modal" data-bs-target="#modalInsumos">
                                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                                </svg>
                                <?= $insumoInfo ? htmlspecialchars($insumoInfo['nombre']) : 'Seleccionar Insumo' ?>
                            </button>

                            <!-- Separador visual -->
                            <div style="width: 1px; height: 30px; background: #e2e8f0;"></div>

                            <!-- Selector de año -->
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <label for="selectAnioMaterial" style="font-weight: 600; color: #475569; font-size: 0.9rem; white-space: nowrap;">
                                    <i class="fas fa-calendar-alt" style="color: #3b82f6;"></i> Año:
                                </label>
                                <select id="selectAnioMaterial" name="anio_selector" class="year-select" onchange="cambiarAnio(this.value)">
                                    <?php for ($y = date('Y'); $y >= 2020; $y--): ?><option value="<?= $y ?>" <?= $y == $anioSel ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
                                </select>
                            </div>

                            <!-- Botones de vista -->
                            <div style="display:flex;gap:8px;margin-left:auto;">
                                <button class="btn-vista <?= $vista === 'captura' ? 'active' : '' ?>" onclick="cambiarVista('captura')">
                                    <i class="fas fa-edit"></i> Capturar
                                </button>
                                <button class="btn-vista <?= $vista === 'tabla' ? 'active' : '' ?>" onclick="cambiarVista('tabla')">
                                    <i class="fas fa-table"></i> Ver Tabla
                                </button>
                            </div>

                            <!-- Botón de exportar -->
                            <button class="btn-export" onclick="descargarExcel()" title="Exportar a Excel">
                                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                                Excel
                            </button>
                        </div>
                    </div>

                    <!-- VISTA CAPTURA -->
                    <div id="vistaCaptura" style="<?= $vista === 'captura' ? '' : 'display:none' ?>">
                        <div class="content-card">
                            <div class="content-card-header">
                                <span class="insumo-actual"><?= $insumoInfo ? htmlspecialchars($insumoInfo['nombre']) : 'Sin insumo' ?></span>
                            </div>

                            <!-- Modo Simple (Split View Dashboard) -->
                            <div id="modoSimple" style="<?= $modoCaptura === 'simple' ? '' : 'display:none' ?>">
                                <form method="POST" action="guardar_material.php" class="split-view-form">
                                    <input type="hidden" name="accion" value="crear">
                                    <input type="hidden" name="insumo_id" value="<?= $insumoSel ?>">
                                    <input type="hidden" name="anio" value="<?= $anioSel ?>">

                                    <div class="split-container">
                                        <!-- Lado Izquierdo: Formulario -->
                                        <div class="split-left">
                                            <div class="form-header-split">
                                                <h2 class="form-title-split">Registro de Insumos</h2>
                                                <p class="form-subtitle-split">Complete la información del movimiento</p>
                                            </div>

                                            <div class="form-fields-split">
                                                <!-- Jurisdicción -->
                                                <div class="field-group-split">
                                                    <label for="jurisdiccion_field" class="field-label-split">
                                                        <svg class="field-icon-split" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                                        </svg>
                                                        <span>Jurisdicción</span>
                                                    </label>
                                                    <?php if ($esAdmin): ?>
                                                        <select name="jurisdiccion" id="jurisdiccion_field" class="field-input-split" required>
                                                            <option value="">Seleccione una jurisdicción</option>
                                                            <?php foreach ($todasJurisdicciones as $jur): ?>
                                                                <option value="<?= htmlspecialchars($jur) ?>"><?= htmlspecialchars($jur) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    <?php else: ?>
                                                        <div class="field-input-readonly-split">
                                                            <?= htmlspecialchars($jurisdiccion_usuario) ?>
                                                        </div>
                                                        <input type="hidden" name="jurisdiccion" value="<?= htmlspecialchars($jurisdiccion_usuario) ?>">
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Mes -->
                                                <div class="field-group-split">
                                                    <label for="mes_field" class="field-label-split">
                                                        <svg class="field-icon-split" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                                        </svg>
                                                        <span>Mes</span>
                                                    </label>
                                                    <select name="mes" id="mes_field" class="field-input-split" required>
                                                        <?php foreach ($mesesCompletos as $num => $nombre): ?>
                                                            <option value="<?= $num ?>" <?= $num == $mesSeleccionado ? 'selected' : '' ?>><?= $nombre ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>

                                                <!-- Entregado -->
                                                <?php if ($esAdmin): ?>
                                                    <div class="field-group-split">
                                                        <label for="entregado" class="field-label-split">
                                                            <svg class="field-icon-split" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                                                            </svg>
                                                            <span>Unidades Entregadas</span>
                                                        </label>
                                                        <input type="number" name="entregado" id="entregado" class="field-input-split" min="0" value="0" placeholder="0" required>
                                                    </div>
                                                    <input type="hidden" name="utilizado" value="0">
                                                <?php else: ?>
                                                    <!-- Capturista: solo captura Utilizado -->
                                                    <input type="hidden" name="entregado" value="0">
                                                    <div class="field-group-split">
                                                        <label for="utilizado" class="field-label-split">
                                                            <svg class="field-icon-split" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                            </svg>
                                                            <span>Unidades Utilizadas</span>
                                                        </label>
                                                        <input type="number" name="utilizado" id="utilizado" class="field-input-split" min="0" value="0" placeholder="0" required>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Botón -->
                                            <button type="submit" class="btn-submit-split">
                                                <svg class="btn-icon-split" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                                </svg>
                                                <span>Guardar Registro</span>
                                            </button>
                                        </div>

                                        <!-- Lado Derecho: Resumen en Vivo -->
                                        <div class="split-right" id="summarySection">
                                            <div class="summary-header">
                                                <h3 class="summary-title">Resumen en Vivo</h3>
                                                <div class="summary-badge">Balance de Insumos</div>

                                                <!-- Botón de colapsar (visible solo en móvil por CSS) -->
                                                <button type="button" class="summary-toggle-btn" onclick="toggleSummary()" title="Mostrar/Ocultar Resumen">
                                                    <svg id="toggleIcon" width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="transition: transform 0.3s;">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path>
                                                    </svg>
                                                </button>
                                            </div>

                                            <div class="summary-cards">
                                                <div class="summary-card green">
                                                    <div class="summary-card-header">
                                                        <svg class="summary-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"></path>
                                                        </svg>
                                                        <span class="summary-label">Entregado</span>
                                                    </div>
                                                    <div class="summary-value" id="summary-entregado"><?= number_format($totalEntregadoGlobal) ?></div>
                                                    <div class="summary-desc">unidades recibidas</div>
                                                </div>

                                                <div class="summary-card orange">
                                                    <div class="summary-card-header">
                                                        <svg class="summary-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"></path>
                                                        </svg>
                                                        <span class="summary-label">Utilizado</span>
                                                    </div>
                                                    <div class="summary-value" id="summary-utilizado"><?= number_format($totalUtilizadoGlobal) ?></div>
                                                    <div class="summary-desc">unidades consumidas</div>
                                                </div>

                                                <div class="summary-card blue">
                                                    <div class="summary-card-header">
                                                        <svg class="summary-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                                                        </svg>
                                                        <span class="summary-label">Remanente</span>
                                                    </div>
                                                    <div class="summary-value" id="summary-remanente"><?= number_format($totalRemanenteGlobal) ?></div>
                                                    <div class="summary-desc">unidades disponibles</div>
                                                </div>
                                            </div>

                                            <div class="summary-footer">
                                                <svg class="summary-footer-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                </svg>
                                                <span>Totales calculados desde la base de datos</span>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Modo Tabla -->
                            <div id="modoTabla" style="<?= $modoCaptura === 'tabla' ? '' : 'display:none' ?>">
                                <div class="table-responsive">
                                    <table class="capture-table" id="tablaCaptura">
                                        <thead>
                                            <tr>
                                                <th style="text-align:left">Jurisdicción</th>
                                                <th style="width:140px">Mes</th>
                                                <?php if ($esAdmin): ?><th class="th-ent" style="width:100px">Entregado</th><?php endif; ?>
                                                <th class="th-util" style="width:100px">Utilizado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($todasJurisdicciones as $idx => $jur):
                                                if (!$esAdmin && $jur !== $jurisdiccion_usuario) continue;
                                            ?>
                                                <tr data-jur="<?= htmlspecialchars($jur, ENT_QUOTES) ?>" data-idx="<?= $idx ?>">
                                                    <td style="font-weight:600"><?= htmlspecialchars($jur) ?></td>
                                                    <td>
                                                        <select id="mes_<?= $idx ?>" class="form-select form-select-sm">
                                                            <?php foreach ($mesesCompletos as $num => $nombre): ?>
                                                                <option value="<?= $num ?>"><?= $nombre ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </td>
                                                    <?php if ($esAdmin): ?>
                                                        <td class="cell-ent"><input type="number" id="ent_<?= $idx ?>" class="form-control form-control-sm" min="0" value="0"></td>
                                                    <?php endif; ?>
                                                    <td class="cell-util"><input type="number" id="util_<?= $idx ?>" class="form-control form-control-sm" min="0" value="0"></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="mt-3 text-center">
                                    <button type="button" class="btn btn-success btn-lg" onclick="guardarTodos()" style="padding:12px 40px;border-radius:12px;font-weight:600">
                                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="vertical-align:middle;margin-right:8px">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                                        </svg>
                                        Guardar Registros
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- VISTA TABLA (seguimiento) -->
                    <div id="vistaTabla" style="<?= $vista === 'tabla' ? '' : 'display:none' ?>">
                        <div class="content-card">
                            <div class="content-card-header">
                                <span class="insumo-actual"><?= $insumoInfo ? htmlspecialchars($insumoInfo['nombre']) : 'Sin insumo' ?></span>
                            </div>

                            <?php
                            $sinDatos = empty($datos);

                            // Calcular totales por mes
                            $totales = [];
                            foreach ($mesesAMostrar as $numMes => $v) {
                                $totales[$numMes] = ['ent' => 0, 'util' => 0, 'rem' => 0];
                            }
                            ?>

                            <div class="table-responsive">
                                <table class="excel-table">
                                    <thead>
                                        <tr>
                                            <th rowspan="2" style="min-width:200px;max-width:200px">Jurisdicción</th>
                                            <?php
                                            // Si no hay datos, mostrar todos los 12 meses
                                            $mesesAMostrarTabla = $sinDatos ? [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true, 7 => true, 8 => true, 9 => true, 10 => true, 11 => true, 12 => true] : $mesesAMostrar;
                                            foreach ($mesesAMostrarTabla as $numMes => $v):
                                            ?>
                                                <th colspan="3" style="min-width:270px"><?= $mesesCompletos[$numMes] ?></th>
                                            <?php endforeach; ?>
                                            <th colspan="2" style="background:linear-gradient(135deg,#9ca3af,#6b7280);color:#fff;min-width:200px">Totales</th>
                                            <th rowspan="2" style="width:80px;min-width:80px">Acción</th>
                                        </tr>
                                        <tr>
                                            <?php foreach ($mesesAMostrarTabla as $numMes => $v): ?>
                                                <th class="th-ent" style="min-width:90px">Entregado</th>
                                                <th class="th-util" style="min-width:90px">Utilizado</th>
                                                <th class="th-rem" style="min-width:90px">Remanente</th>
                                            <?php endforeach; ?>
                                            <th class="th-tot-ent" style="min-width:100px">Total Entregado</th>
                                            <th class="th-tot-util" style="min-width:100px">Total Utilizado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($sinDatos):
                                            // Mostrar todas las 19 jurisdicciones vacías
                                            $jurisdiccionesEjemplo = $esAdmin ? $todasJurisdicciones : [$jurisdiccion_usuario];
                                            foreach ($jurisdiccionesEjemplo as $jur):
                                        ?>
                                                <tr>
                                                    <td class="col-jur"><?= htmlspecialchars($jur) ?></td>
                                                    <?php foreach ($mesesAMostrarTabla as $numMes => $v): ?>
                                                        <td class="cell-ent">-</td>
                                                        <td class="cell-util">-</td>
                                                        <td class="cell-rem">0</td>
                                                    <?php endforeach; ?>
                                                    <td class="cell-tot-ent">0</td>
                                                    <td class="cell-tot-util">0</td>
                                                    <td>
                                                        <div class="actions-cell">
                                                            <button class="btn-action btn-edit" disabled title="Sin datos para editar">
                                                                <i class="fas fa-edit"></i>
                                                            </button>
                                                            <button class="btn-action btn-delete" disabled title="Sin datos para eliminar">
                                                                <i class="fas fa-trash-alt"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <!-- Fila de totales vacía -->
                                            <tr class="row-total">
                                                <td>TOTALES</td>
                                                <?php foreach ($mesesAMostrarTabla as $numMes => $v): ?>
                                                    <td>0</td>
                                                    <td>0</td>
                                                    <td>0</td>
                                                <?php endforeach; ?>
                                                <td>0</td>
                                                <td>0</td>
                                                <td></td>
                                            </tr>
                                            <?php else:
                                            $jurisdiccionesAMostrar = $esAdmin ? array_keys($datos) : [$jurisdiccion_usuario];
                                            foreach ($jurisdiccionesAMostrar as $jur):
                                                if (!isset($datos[$jur])) continue;
                                                $jurData = $datos[$jur];
                                                $puedeEditar = $esAdmin || ($jur === $jurisdiccion_usuario);
                                                $totalEntJur = 0;
                                                $totalUtilJur = 0;
                                            ?>
                                                <tr>
                                                    <td class="col-jur"><?= htmlspecialchars($jur) ?></td>
                                                    <?php
                                                    $remanteAnterior = $remanentesAnoAnterior[$jur] ?? 0;
                                                    foreach ($mesesAMostrarTabla as $numMes => $v):
                                                        // Verificar explícitamente si existe registro para este mes
                                                        $reg = isset($jurData[$numMes]) ? $jurData[$numMes] : null;

                                                        if ($reg && isset($reg['id'])):
                                                            // HAY REGISTRO EN BD para este mes
                                                            $entregado = (int)$reg['entregado'];
                                                            $utilizado = (int)$reg['utilizado'];
                                                            $disponible = $remanteAnterior + $entregado;
                                                            $remanteActual = $disponible - $utilizado;

                                                            // Sumar lo que se MUESTRA en la tabla (disponible, no entregado)
                                                            $totales[$numMes]['ent'] += $disponible;
                                                            $totales[$numMes]['util'] += $utilizado;
                                                            $totales[$numMes]['rem'] += $remanteActual;
                                                            $totalEntJur += $disponible;
                                                            $totalUtilJur += $utilizado;

                                                            // Actualizar remanente para el siguiente mes
                                                            $remanteAnterior = $remanteActual;
                                                    ?>
                                                            <td class="cell-ent"><?= number_format($disponible) ?></td>
                                                            <td class="cell-util"><?= number_format($utilizado) ?></td>
                                                            <td class="cell-rem <?= $remanteActual < 0 ? 'negative' : '' ?>"><?= number_format($remanteActual) ?></td>
                                                        <?php else:
                                                            // NO HAY REGISTRO EN BD para este mes
                                                            // El remanente se mantiene igual para cálculos futuros, pero NO se muestra
                                                        ?>
                                                            <td class="cell-ent">-</td>
                                                            <td class="cell-util">-</td>
                                                            <td class="cell-rem">-</td>
                                                    <?php
                                                        // IMPORTANTE: $remanteAnterior NO se modifica aquí, se mantiene para el siguiente mes
                                                        endif;
                                                    endforeach;
                                                    ?>
                                                    <td class="cell-tot-ent"><?= number_format($totalEntJur) ?></td>
                                                    <td class="cell-tot-util"><?= number_format($totalUtilJur) ?></td>
                                                    <td>
                                                        <?php if ($puedeEditar && !empty($jurData)): ?>
                                                            <div class="actions-cell">
                                                                <button class="btn-action btn-edit" onclick="abrirEditar('<?= htmlspecialchars($jur, ENT_QUOTES) ?>')" title="Editar">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button class="btn-action btn-delete" onclick="abrirEliminar('<?= htmlspecialchars($jur, ENT_QUOTES) ?>')" title="Eliminar">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <div class="actions-cell">
                                                                <button class="btn-action btn-edit" disabled title="Sin datos para editar">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button class="btn-action btn-delete" disabled title="Sin datos para eliminar">
                                                                    <i class="fas fa-trash-alt"></i>
                                                                </button>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <!-- Fila de totales -->
                                            <tr class="row-total">
                                                <td>TOTALES</td>
                                                <?php
                                                $granTotalEnt = 0;
                                                $granTotalUtil = 0;
                                                foreach ($mesesAMostrar as $numMes => $v):
                                                    $granTotalEnt += $totales[$numMes]['ent'];
                                                    $granTotalUtil += $totales[$numMes]['util'];
                                                ?>
                                                    <td><?= number_format($totales[$numMes]['ent']) ?></td>
                                                    <td><?= number_format($totales[$numMes]['util']) ?></td>
                                                    <td><?= number_format($totales[$numMes]['rem']) ?></td>
                                                <?php endforeach; ?>
                                                <td><?= number_format($granTotalEnt) ?></td>
                                                <td><?= number_format($granTotalUtil) ?></td>
                                                <td></td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <div class="mt-3 p-3" style="background:#f8fafc;border-radius:10px;font-size:.8rem;color:#475569;text-align:center">
                                <strong>Entreg.</strong> = Disponible (remanente anterior + entregado) |
                                <strong>Utiliz.</strong> = Material consumido |
                                <strong>Reman.</strong> = Remanente del mes
                            </div>
                        </div>
                    </div>
                </div> <!-- Cierre main-container -->
            <?php endif; ?>
        </main>
    </div>

    <!-- Formulario oculto -->
    <form id="formCaptura" method="POST" action="guardar_material.php" style="display:none">
        <input type="hidden" name="accion" value="crear">
        <input type="hidden" name="insumo_id" value="<?= $insumoSel ?>">
        <input type="hidden" name="anio" value="<?= $anioSel ?>">
        <input type="hidden" name="jurisdiccion" id="form_jurisdiccion">
        <input type="hidden" name="mes" id="form_mes">
        <input type="hidden" name="entregado" id="form_entregado">
        <input type="hidden" name="utilizado" id="form_utilizado">
    </form>

    <!-- Modal Insumos -->
    <div class="modal fade" id="modalInsumos" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff">
                    <h5 class="modal-title">
                        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24" style="display: inline-block; vertical-align: middle; margin-right: 0.5rem;">
                            <path d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                        Seleccionar Insumo
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Campo de búsqueda -->
                    <div style="margin-bottom: 1rem;">
                        <input type="text"
                            id="searchInsumo"
                            name="search_insumo"
                            class="form-control"
                            placeholder="🔍 Buscar insumo..."
                            style="padding: 0.75rem; border: 2px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem;"
                            autocomplete="off">
                    </div>
                    <div class="insumo-grid" id="insumoGridContainer">
                        <!-- Los insumos se cargan dinámicamente con JavaScript -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Lista de 33 insumos hardcodeada - LISTA OFICIAL
        const insumosDisponibles = [{
                id: 1,
                nombre: 'HIDROCLORURO DE XILACINA AL 2%',
                presentacion: 'FRASCO C/25 ML'
            },
            {
                id: 2,
                nombre: 'TILETAMINA-ZOLAZEPAM AL 10% (ZOLETIL)',
                presentacion: 'FRASCO C/5 ML'
            },
            {
                id: 3,
                nombre: 'CLORHIDRATO DE TRAMADOL 50 mg',
                presentacion: 'FRASCO C/ 50 ml'
            },
            {
                id: 4,
                nombre: 'CLORHIDRATO DE DOXOPRAM',
                presentacion: 'FRASCO C/20 ML'
            },
            {
                id: 5,
                nombre: 'ALUSPRAY',
                presentacion: 'FRASCO C/210 ML'
            },
            {
                id: 6,
                nombre: 'ATROPINA',
                presentacion: 'AMPOLLETA'
            },
            {
                id: 7,
                nombre: 'EPINEFRINA',
                presentacion: 'AMPOLLETA'
            },
            {
                id: 8,
                nombre: 'LIDOCAINA/EPINEFRINA',
                presentacion: 'FCO CON 50ML'
            },
            {
                id: 9,
                nombre: 'SUTURA DE ACIDO POLIGLICOLICO, CALIBRE 0',
                presentacion: 'PIEZA'
            },
            {
                id: 10,
                nombre: 'SUTURA DE ACIDO POLIGLICOLICO, CALIBRE 1',
                presentacion: 'PIEZA'
            },
            {
                id: 11,
                nombre: 'SUTURA DE ACIDO POLIGLICOLICO, CALIBRE 2-0',
                presentacion: 'PIEZA'
            },
            {
                id: 12,
                nombre: 'GUANTES DE CIRUJANO No. 6 ½',
                presentacion: 'PAR'
            },
            {
                id: 13,
                nombre: 'GUANTES DE CIRUJANO No. 7 ½',
                presentacion: 'PAR'
            },
            {
                id: 14,
                nombre: 'HOJA DE BISTURÍ No.21',
                presentacion: 'PIEZA'
            },
            {
                id: 15,
                nombre: 'GASA SECA CORTADA DE ALGODÓN 10X10 CM.',
                presentacion: 'PAQUETE C/200 PZAS.'
            },
            {
                id: 16,
                nombre: 'CUBREBOCAS',
                presentacion: 'PIEZA'
            },
            {
                id: 17,
                nombre: 'JERINGA DE 1 ML. CON AGUJA',
                presentacion: 'PIEZA'
            },
            {
                id: 18,
                nombre: 'JERINGA DE 3 ML. CON AGUJA',
                presentacion: 'PIEZA'
            },
            {
                id: 19,
                nombre: 'JERINGA DE 5 ML. CON AGUJA',
                presentacion: 'PIEZA'
            },
            {
                id: 20,
                nombre: 'JERINGA DE 10 ML. CON AGUJA',
                presentacion: 'PIEZA'
            },
            {
                id: 21,
                nombre: 'AGUJA HIPODERMICA CALIBRE 21 G.',
                presentacion: 'ENVASE CON 100 PIEZAS'
            },
            {
                id: 22,
                nombre: 'AGUJA HIPODERMICA CALIBRE 25 G.',
                presentacion: 'ENVASE CON 100 PIEZAS'
            },
            {
                id: 23,
                nombre: 'AGUA OXIGENADA',
                presentacion: 'ENVASE CON 480 ML.'
            },
            {
                id: 24,
                nombre: 'GLUTARALDEHIDO AL 2%',
                presentacion: 'GALON'
            },
            {
                id: 25,
                nombre: 'JABON LIQUIDO QUIRURGICO',
                presentacion: 'GALON'
            },
            {
                id: 26,
                nombre: 'IODOPOVIDONA',
                presentacion: 'GALON'
            },
            {
                id: 27,
                nombre: 'ALCOHOL',
                presentacion: 'ENVASE 20 LTS.'
            },
            {
                id: 28,
                nombre: 'TORUNDA DE ALGODÓN',
                presentacion: 'BOLSA 500 GR.'
            },
            {
                id: 29,
                nombre: 'SOLUCION HARTMAN',
                presentacion: 'FRASCO DE 500 ML.'
            },
            {
                id: 30,
                nombre: 'EQUIPO DE VENOCLISIS',
                presentacion: 'PIEZA'
            },
            {
                id: 31,
                nombre: 'CATETER PARA VENOCLISIS, CALIBRE 22',
                presentacion: 'PIEZA'
            },
            {
                id: 32,
                nombre: 'BENCILPENICILLINA BENZATINICA COMPUESTA FCO 3 ML',
                presentacion: 'FRASCO C/3ML'
            },
            {
                id: 33,
                nombre: 'FORMATOS DE RESPONSIVAS DE ESTERILIZACIÓN',
                presentacion: 'PIEZA'
            }
        ];

        // Cargar insumos al abrir el modal
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('insumoGridContainer');
            const searchInput = document.getElementById('searchInsumo');
            const insumoSelActual = <?= $insumoSel ?>;

            // Función para renderizar insumos con búsqueda flexible (multi-palabra)
            function renderInsumos(filtro = '') {
                container.innerHTML = '';
                const filtroLower = filtro.trim().toLowerCase();
                const palabras = filtroLower.split(/\s+/).filter(p => p !== '');

                // Filtrar insumos de forma flexible
                const insumosFiltrados = insumosDisponibles.filter(ins => {
                    if (palabras.length === 0) return true;
                    const textoCompleto = (ins.nombre + ' ' + (ins.presentacion || '')).toLowerCase();
                    // Todas las palabras escritas deben estar en el nombre o presentación
                    return palabras.every(palabra => textoCompleto.includes(palabra));
                });

                insumosFiltrados.forEach(ins => {
                    let nombreHTML = ins.nombre;
                    let presHTML = ins.presentacion || '';

                    // Resaltar coincidencias si hay búsqueda
                    if (palabras.length > 0) {
                        palabras.forEach(p => {
                            const regex = new RegExp(`(${p.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
                            nombreHTML = nombreHTML.replace(regex, '<mark class="p-0 bg-warning bg-opacity-50">$1</mark>');
                            presHTML = presHTML.replace(regex, '<mark class="p-0 bg-warning bg-opacity-50">$1</mark>');
                        });
                    }

                    const card = document.createElement('div');
                    card.className = 'insumo-card' + (ins.id === insumoSelActual ? ' selected' : '');
                    card.style.transition = 'all 0.2s ease';
                    card.innerHTML = `
                        <div class="insumo-name">${nombreHTML}</div>
                        <div class="insumo-pres">${presHTML}</div>
                    `;
                    card.onclick = function(event) {
                        seleccionarInsumoJS(event, ins.id, ins.nombre, ins.presentacion);
                    };
                    container.appendChild(card);
                });

                if (insumosFiltrados.length === 0) {
                    container.innerHTML = `
                        <div class="text-center py-5">
                            <i class="fas fa-search-minus fs-1 text-muted mb-3 d-block"></i>
                            <p class="text-muted">No se encontraron insumos que coincidan con "<strong>${filtro}</strong>"</p>
                            <button class="btn btn-sm btn-outline-primary" onclick="document.getElementById('searchInsumo').value=''; document.getElementById('searchInsumo').dispatchEvent(new Event('input'));">Limpiar búsqueda</button>
                        </div>
                    `;
                }
            }

            // Búsqueda en tiempo real
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    renderInsumos(this.value);
                });
            }

            // Autofocus al abrir el modal
            const modalEl = document.getElementById('modalInsumos');
            if (modalEl) {
                modalEl.addEventListener('shown.bs.modal', function() {
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                });
            }

            // Renderizar todos los insumos inicialmente
            renderInsumos();
        });

        // Función para seleccionar insumo (crea en BD si no existe)
        function seleccionarInsumoJS(event, id, nombre, presentacion) {
            // Prevenir múltiples clics
            if (event.currentTarget.classList.contains('loading')) {
                return;
            }

            // Mostrar loading
            const btn = event.currentTarget;
            const originalHTML = btn.innerHTML;
            btn.classList.add('loading');
            btn.innerHTML = '<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin"></i> Cargando...</div>';
            btn.style.pointerEvents = 'none';
            btn.style.opacity = '0.6';

            // Enviar al servidor para crear/verificar insumo
            fetch('guardar_material.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `accion=verificar_insumo&id=${id}&nombre=${encodeURIComponent(nombre)}&presentacion=${encodeURIComponent(presentacion)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Redirigir con el insumo seleccionado
                        window.location.href = `material_curacion.php?insumo=${id}&anio=<?= $anioSel ?>&vista=<?= $vista ?>`;
                    } else {
                        alert('Error: ' + (data.error || 'No se pudo seleccionar el insumo'));
                        btn.innerHTML = originalHTML;
                        btn.style.pointerEvents = 'auto';
                        btn.style.opacity = '1';
                        btn.classList.remove('loading');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error de conexión. Por favor, intente nuevamente.');
                    btn.innerHTML = originalHTML;
                    btn.style.pointerEvents = 'auto';
                    btn.style.opacity = '1';
                    btn.classList.remove('loading');
                });
        }
    </script>

    <!-- Modales solo para Admin -->
    <?php if ($esAdmin): ?>
        <!-- Modal Editar -->
        <div class="modal fade" id="modalEditar" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" style="background:linear-gradient(135deg,#1e40af,#3b82f6);color:#fff">
                        <h5 class="modal-title">✏️ Editar - <span id="edit_jur_title"></span></h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-2">Selecciona el mes:</p>
                        <div id="editMesesContainer"></div>
                        <div id="editFormContainer" style="display:none;margin-top:16px">
                            <form method="POST" action="guardar_material.php">
                                <input type="hidden" name="accion" value="editar">
                                <input type="hidden" name="id" id="edit_id">
                                <input type="hidden" name="insumo_id" value="<?= $insumoSel ?>">
                                <input type="hidden" name="anio" value="<?= $anioSel ?>">
                                <input type="hidden" name="jurisdiccion" id="edit_jurisdiccion">
                                <input type="hidden" name="mes" id="edit_mes">
                                <div class="row">
                                    <?php if ($esAdmin): ?>
                                        <div class="col-6 mb-3">
                                            <label for="edit_entregado" class="form-label">Entregado</label>
                                            <input type="number" name="entregado" id="edit_entregado" class="form-control" min="0">
                                        </div>
                                    <?php endif; ?>
                                    <div class="col-6 mb-3">
                                        <label for="edit_utilizado" class="form-label">Utilizado</label>
                                        <input type="number" name="utilizado" id="edit_utilizado" class="form-control" min="0">
                                    </div>
                                </div>
                                <div class="d-flex gap-2 justify-content-end">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                    <button type="submit" class="btn btn-primary">Guardar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Eliminar -->
        <div class="modal fade" id="modalEliminar" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header" style="background:#fee2e2;color:#dc2626">
                        <h5 class="modal-title">🗑️ Eliminar - <span id="del_jur_title"></span></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted mb-2">Selecciona el mes a eliminar:</p>
                        <div id="delMesesContainer"></div>
                        <div id="delConfirmContainer" style="display:none;margin-top:16px;text-align:center">
                            <p>¿Eliminar registro de <strong id="del_mes_title"></strong>?</p>
                            <form method="POST" action="guardar_material.php">
                                <input type="hidden" name="accion" value="eliminar">
                                <input type="hidden" name="id" id="del_id">
                                <input type="hidden" name="insumo_id" value="<?= $insumoSel ?>">
                                <input type="hidden" name="anio" value="<?= $anioSel ?>">
                                <input type="hidden" name="jurisdiccion" id="del_jurisdiccion">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" class="btn btn-danger">Eliminar</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const insumoActual = <?= $insumoSel ?>;
        const anioActual = <?= $anioSel ?>;
        const vistaActual = '<?= $vista ?>';
        const modoActual = '<?= $modoCaptura ?>';
        const esAdmin = <?= $esAdmin ? 'true' : 'false' ?>;
        const datosJur = <?= json_encode($datosJS) ?>;

        // Función para colapsar/expandir el resumen en móvil
        function toggleSummary() {
            const section = document.getElementById('summarySection');
            const icon = document.getElementById('toggleIcon');

            section.classList.toggle('collapsed');

            if (section.classList.contains('collapsed')) {
                // Icono hacia abajo (indicando que se puede abrir)
                icon.style.transform = 'rotate(180deg)';
            } else {
                // Icono hacia arriba (indicando que se puede cerrar)
                icon.style.transform = 'rotate(0deg)';
            }
        }

        function seleccionarInsumo(id) {
            window.location.href = `material_curacion.php?insumo=${id}&anio=${anioActual}&vista=${vistaActual}&modo=${modoActual}`;
        }

        function cambiarAnio(anio) {
            window.location.href = `material_curacion.php?insumo=${insumoActual}&anio=${anio}&vista=${vistaActual}&modo=${modoActual}`;
        }

        function cambiarVista(vista) {
            window.location.href = `material_curacion.php?insumo=${insumoActual}&anio=${anioActual}&vista=${vista}&modo=${modoActual}`;
        }

        function cambiarModo(modo) {
            window.location.href = `material_curacion.php?insumo=${insumoActual}&anio=${anioActual}&vista=captura&modo=${modo}`;
        }

        function guardarFila(jur, idx) {
            const mes = document.getElementById('mes_' + idx).value;
            const entregado = esAdmin ? document.getElementById('ent_' + idx).value : 0;
            const utilizado = document.getElementById('util_' + idx).value;

            document.getElementById('form_jurisdiccion').value = jur;
            document.getElementById('form_mes').value = mes;
            document.getElementById('form_entregado').value = entregado;
            document.getElementById('form_utilizado').value = utilizado;
            document.getElementById('formCaptura').submit();
        }

        // Guardar todos los registros que tengan valores
        function guardarTodos() {
            const filas = document.querySelectorAll('#tablaCaptura tbody tr');
            let registros = [];

            filas.forEach(fila => {
                const jur = fila.dataset.jur;
                const idx = fila.dataset.idx;
                const mes = document.getElementById('mes_' + idx).value;
                const entregado = esAdmin ? (parseInt(document.getElementById('ent_' + idx).value) || 0) : 0;
                const utilizado = parseInt(document.getElementById('util_' + idx).value) || 0;

                // Solo agregar si tiene algún valor
                if (entregado > 0 || utilizado > 0) {
                    registros.push({
                        jur,
                        mes,
                        entregado,
                        utilizado
                    });
                }
            });

            if (registros.length === 0) {
                alert('No hay registros con valores para guardar');
                return;
            }

            // Guardar uno por uno (el primero)
            const r = registros[0];
            document.getElementById('form_jurisdiccion').value = r.jur;
            document.getElementById('form_mes').value = r.mes;
            document.getElementById('form_entregado').value = r.entregado;
            document.getElementById('form_utilizado').value = r.utilizado;
            document.getElementById('formCaptura').submit();
        }

        // Modal Editar
        function abrirEditar(jur) {
            const data = datosJur[jur] || {};
            document.getElementById('edit_jur_title').textContent = jur;
            document.getElementById('edit_jurisdiccion').value = jur;
            document.getElementById('editFormContainer').style.display = 'none';

            let html = '';
            for (const mes in data) {
                html += `<div class="mes-card" onclick="seleccionarMesEditar('${jur}', ${mes})">${data[mes].mes_nombre}</div>`;
            }
            document.getElementById('editMesesContainer').innerHTML = html || '<p>No hay registros</p>';
            new bootstrap.Modal(document.getElementById('modalEditar')).show();
        }

        function seleccionarMesEditar(jur, mes) {
            const d = datosJur[jur][mes];
            document.querySelectorAll('#editMesesContainer .mes-card').forEach(c => c.classList.remove('selected'));
            event.target.classList.add('selected');

            document.getElementById('edit_id').value = d.id;
            document.getElementById('edit_mes').value = mes;
            if (esAdmin) document.getElementById('edit_entregado').value = d.entregado;
            document.getElementById('edit_utilizado').value = d.utilizado;
            document.getElementById('editFormContainer').style.display = 'block';
        }

        // Modal Eliminar
        function abrirEliminar(jur) {
            const data = datosJur[jur] || {};
            document.getElementById('del_jur_title').textContent = jur;
            document.getElementById('del_jurisdiccion').value = jur;
            document.getElementById('delConfirmContainer').style.display = 'none';

            let html = '';
            for (const mes in data) {
                html += `<div class="mes-card" onclick="seleccionarMesEliminar('${jur}', ${mes})" style="border-color:#fca5a5">${data[mes].mes_nombre}</div>`;
            }
            document.getElementById('delMesesContainer').innerHTML = html || '<p>No hay registros</p>';
            new bootstrap.Modal(document.getElementById('modalEliminar')).show();
        }

        function seleccionarMesEliminar(jur, mes) {
            const d = datosJur[jur][mes];
            document.querySelectorAll('#delMesesContainer .mes-card').forEach(c => c.classList.remove('selected'));
            event.target.classList.add('selected');

            document.getElementById('del_id').value = d.id;
            document.getElementById('del_mes_title').textContent = d.mes_nombre;
            document.getElementById('delConfirmContainer').style.display = 'block';
        }

        // Función para descargar Excel con modal de progreso
        function descargarExcel() {
            // Crear modal de progreso
            const modalHTML = `
        <div id="modalProgreso" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);display:flex;align-items:center;justify-content:center;z-index:9999;">
            <div style="background:white;border-radius:16px;padding:32px;box-shadow:0 20px 60px rgba(0,0,0,0.3);max-width:400px;width:90%;text-align:center;">
                <div style="margin-bottom:24px;">
                    <svg width="48" height="48" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="margin:0 auto;color:#10b981;animation:spin 1s linear infinite;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                </div>
                <h3 style="margin:0 0 8px;color:#1e293b;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;font-weight:600;font-size:1.1rem;">Generando Excel</h3>
                <p style="margin:0 0 20px;color:#64748b;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;font-size:0.9rem;">Por favor espera mientras se prepara tu archivo...</p>
                <div style="background:#f1f5f9;border-radius:8px;overflow:hidden;height:8px;margin-bottom:16px;">
                    <div id="barraProgreso" style="background:linear-gradient(90deg,#10b981,#34d399);height:100%;width:0%;animation:progreso 2s ease-in-out infinite;"></div>
                </div>
                <p style="margin:0;color:#94a3b8;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;font-size:0.8rem;" id="textoProgreso">Preparando datos...</p>
            </div>
        </div>
        <style>
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            @keyframes progreso {
                0% { width: 0%; }
                50% { width: 70%; }
                100% { width: 100%; }
            }
        </style>
    `;

            document.body.insertAdjacentHTML('beforeend', modalHTML);
            const modal = document.getElementById('modalProgreso');
            const textoProgreso = document.getElementById('textoProgreso');

            // Desactivar botón
            const btn = event.target.closest('button');
            const textoOriginal = btn.innerHTML;
            btn.disabled = true;

            // Simular progreso
            let progreso = 0;
            const mensajes = [
                'Preparando datos...',
                'Formateando celdas...',
                'Aplicando estilos...',
                'Finalizando archivo...'
            ];

            const intervalo = setInterval(() => {
                progreso++;
                if (progreso < mensajes.length) {
                    textoProgreso.textContent = mensajes[progreso];
                }
            }, 600);

            // Usar fetch para mejor control de errores
            const url = `export_material_simple.php?anio=${anioActual}`;

            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    // Verificar que sea un archivo Excel
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('spreadsheet')) {
                        throw new Error('El archivo no es un Excel válido');
                    }

                    return response.blob();
                })
                .then(blob => {
                    if (blob.size === 0) {
                        throw new Error('El archivo está vacío');
                    }

                    // Actualizar mensaje final
                    clearInterval(intervalo);
                    textoProgreso.textContent = '¡Descargando archivo!';

                    // Crear enlace de descarga
                    const urlBlob = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = urlBlob;
                    a.download = `Material_Curacion_${anioActual}.xlsx`;
                    document.body.appendChild(a);
                    a.click();

                    // Limpiar
                    setTimeout(() => {
                        window.URL.revokeObjectURL(urlBlob);
                        document.body.removeChild(a);
                        modal.remove();
                        btn.innerHTML = textoOriginal;
                        btn.disabled = false;
                    }, 500);
                })
                .catch(error => {
                    clearInterval(intervalo);
                    modal.remove();
                    btn.innerHTML = textoOriginal;
                    btn.disabled = false;
                    alert('Error al generar el archivo Excel: ' + error.message);
                });
        }

        // Función de prueba para diagnosticar problemas
        function probarDescarga() {
            const btn = event.target;
            const textoOriginal = btn.innerHTML;
            btn.innerHTML = 'Probando...';
            btn.disabled = true;

            fetch('test_download.php')
                .then(response => {
                    console.log('Test response status:', response.status);
                    if (!response.ok) {
                        throw new Error(`Test failed: ${response.status}`);
                    }
                    return response.blob();
                })
                .then(blob => {
                    console.log('Test blob size:', blob.size);

                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'prueba.xlsx';
                    a.click();
                    window.URL.revokeObjectURL(url);

                    alert('Prueba exitosa! El sistema funciona.');
                })
                .catch(error => {
                    console.error('Test error:', error);
                    alert('Error en prueba: ' + error.message);
                })
                .finally(() => {
                    btn.innerHTML = textoOriginal;
                    btn.disabled = false;
                });
        }
    </script>
</body>

</html>