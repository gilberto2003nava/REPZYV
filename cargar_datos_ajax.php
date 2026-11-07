<?php
/**
 * Endpoint AJAX para cargar datos existentes de capturas
 * Usado por capturar_mes.php para pre-llenar el formulario de edición
 */
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';

// Obtener parámetros
$municipio = $_POST['municipio'] ?? '';
$hospital = $_POST['hospital'] ?? '';
$mes = intval($_POST['mes'] ?? 0);
$anio = intval($_POST['anio'] ?? 0);
$jurisdiccion_param = $_POST['jurisdiccion'] ?? '';

// Validar parámetros
if (empty($municipio) || $mes < 1 || $mes > 12 || $anio < 2020) {
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos']);
    exit;
}

// Determinar jurisdicción de trabajo
if ($rol === 'admin') {
    // Si viene jurisdicción del frontend, usarla
    if (!empty($jurisdiccion_param)) {
        $jurisdiccion_trabajo = $jurisdiccion_param;
    } else {
        // Admin puede ver cualquier jurisdicción, detectar desde el municipio
        // Buscar jurisdicción del municipio en los registros
        $stmt_juris = mysqli_prepare($conn, "SELECT DISTINCT jurisdiccion FROM registros WHERE municipio = ? LIMIT 1");
        mysqli_stmt_bind_param($stmt_juris, 's', $municipio);
        mysqli_stmt_execute($stmt_juris);
        $res_juris = mysqli_stmt_get_result($stmt_juris);
        $row_juris = mysqli_fetch_assoc($res_juris);
        $jurisdiccion_trabajo = $row_juris['jurisdiccion'] ?? '';
        
        // Si no hay registros, buscar en metas
        if (empty($jurisdiccion_trabajo)) {
            $stmt_juris2 = mysqli_prepare($conn, "SELECT DISTINCT jurisdiccion FROM metas WHERE municipio = ? LIMIT 1");
            mysqli_stmt_bind_param($stmt_juris2, 's', $municipio);
            mysqli_stmt_execute($stmt_juris2);
            $res_juris2 = mysqli_stmt_get_result($stmt_juris2);
            $row_juris2 = mysqli_fetch_assoc($res_juris2);
            $jurisdiccion_trabajo = $row_juris2['jurisdiccion'] ?? '';
        }
        
        // Si aún no hay, usar la primera que tenga el municipio en la lista de funciones.php
        if (empty($jurisdiccion_trabajo)) {
            foreach (jurisdicciones() as $juris) {
                $munis = municipiosPorJurisdiccion($juris);
                foreach ($munis as $m) {
                    if (mb_strtoupper($m, 'UTF-8') === mb_strtoupper($municipio, 'UTF-8')) {
                        $jurisdiccion_trabajo = $juris;
                        break 2;
                    }
                }
            }
        }
    }
} else {
    $jurisdiccion_trabajo = $jurisdiccion_usuario;
}

$mes_clave = mesClaveDesdeNumero($mes);

// Cargar datos del municipio
$datos_municipio = [];
$tiene_datos = false;
$tipos_municipio = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];

foreach ($tipos_municipio as $tipo) {
    $sql = "SELECT `$mes_clave` as valor FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'isss', $anio, $jurisdiccion_trabajo, $municipio, $tipo);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    
    $valor = $row ? intval($row['valor']) : 0;
    $datos_municipio[$tipo] = $valor;
    
    if ($valor > 0) {
        $tiene_datos = true;
    }
}

// Cargar datos del hospital si se especificó
$datos_hospital = [];
if (!empty($hospital)) {
    $tipos_hospital = ['agredidos', 'tratamientos'];
    
    foreach ($tipos_hospital as $tipo) {
        $sql = "SELECT `$mes_clave` as valor FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'isss', $anio, $jurisdiccion_trabajo, $hospital, $tipo);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = mysqli_fetch_assoc($res);
        
        $valor = $row ? intval($row['valor']) : 0;
        $datos_hospital[$tipo] = $valor;
        
        if ($valor > 0) {
            $tiene_datos = true;
        }
    }
}

// Responder
echo json_encode([
    'ok' => true,
    'municipio' => $datos_municipio,
    'hospital' => $datos_hospital,
    'tiene_datos' => $tiene_datos,
    'periodo' => [
        'mes' => $mes,
        'mes_clave' => $mes_clave,
        'anio' => $anio
    ]
]);
