<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'No autenticado']);
    exit;
}

// Verificar método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

// Verificar CSRF token
if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$municipio = sanitizarSQL($_POST['municipio'] ?? '');
$jurisdiccion = sanitizarSQL($_POST['jurisdiccion'] ?? '');
$anio = validarInput($_POST['anio'] ?? 0, 'anio');
$mes = validarInput($_POST['mes'] ?? 0, 'entero'); // Mes a borrar (1-12)

// DEBUG: Registrar lo que se recibe
error_log("BORRAR DEBUG - Municipio recibido: '$municipio' (length: " . strlen($municipio) . ")");
error_log("BORRAR DEBUG - Jurisdicción recibida: '$jurisdiccion'");
error_log("BORRAR DEBUG - Año: $anio, Mes: $mes");

// Validar datos
if (empty($municipio) || empty($jurisdiccion) || !$anio || !$mes) {
    echo json_encode(['ok' => false, 'msg' => 'Datos incompletos. Se requiere municipio, jurisdicción, año y mes.']);
    exit;
}

// Verificar permisos
if ($rol === 'capturista') {
    // Verificar si el año está cerrado
    $check_anio = mysqli_prepare($conn, "SELECT COUNT(*) as total, SUM(editable) as editables FROM registros WHERE anio = ?");
    mysqli_stmt_bind_param($check_anio, 'i', $anio);
    mysqli_stmt_execute($check_anio);
    $res_anio = mysqli_stmt_get_result($check_anio);
    $estado_anio = mysqli_fetch_assoc($res_anio);
    
    $anio_cerrado = ($estado_anio['total'] > 0 && (int)$estado_anio['editables'] === 0);
    
    if ($anio_cerrado) {
        echo json_encode(['ok' => false, 'msg' => 'El año está cerrado. No se pueden borrar registros.']);
        exit;
    }
    
    // Para borrar, el capturista debe poder editar ese mes específico
    if (!puedeEditar($mes, $anio)) {
        echo json_encode(['ok' => false, 'msg' => 'No tienes permisos para borrar este mes. Solo puedes borrar dentro del periodo de edición o con prórroga activa.']);
        exit;
    }
}

// Obtener la clave del mes (ene, feb, mar, etc.)
$mes_clave = mesClaveDesdeNumero($mes);

// DEBUG: Registrar el SQL que se va a ejecutar
error_log("BORRAR DEBUG - Mes clave: '$mes_clave'");

// Poner en 0 solo la columna del mes especificado para ese municipio
$sql_update = "UPDATE registros SET `$mes_clave` = 0 WHERE anio = ? AND municipio = ?";

// Si la jurisdicción no es 'TODAS', filtrar también por jurisdicción
if ($jurisdiccion !== 'TODAS') {
    $sql_update .= " AND jurisdiccion = ?";
    error_log("BORRAR DEBUG - SQL: $sql_update [params: $anio, '$municipio', '$jurisdiccion']");
    $stmt_update = mysqli_prepare($conn, $sql_update);
    mysqli_stmt_bind_param($stmt_update, 'iss', $anio, $municipio, $jurisdiccion);
} else {
    error_log("BORRAR DEBUG - SQL: $sql_update [params: $anio, '$municipio']");
    $stmt_update = mysqli_prepare($conn, $sql_update);
    mysqli_stmt_bind_param($stmt_update, 'is', $anio, $municipio);
}

if (!$stmt_update) {
    echo json_encode(['ok' => false, 'msg' => 'Error al preparar consulta: ' . mysqli_error($conn)]);
    exit;
}

if (mysqli_stmt_execute($stmt_update)) {
    $registros_actualizados = mysqli_stmt_affected_rows($stmt_update);
    
    error_log("BORRAR DEBUG - Registros actualizados: $registros_actualizados");
    
    // DEBUG: Verificar si realmente se actualizó
    $sql_verify = "SELECT COUNT(*) as total FROM registros WHERE anio = ? AND municipio = ? AND `$mes_clave` = 0";
    if ($jurisdiccion !== 'TODAS') {
        $sql_verify .= " AND jurisdiccion = ?";
        $stmt_verify = mysqli_prepare($conn, $sql_verify);
        mysqli_stmt_bind_param($stmt_verify, 'iss', $anio, $municipio, $jurisdiccion);
    } else {
        $stmt_verify = mysqli_prepare($conn, $sql_verify);
        mysqli_stmt_bind_param($stmt_verify, 'is', $anio, $municipio);
    }
    mysqli_stmt_execute($stmt_verify);
    $res_verify = mysqli_stmt_get_result($stmt_verify);
    $count_ceros = mysqli_fetch_assoc($res_verify)['total'];
    error_log("BORRAR DEBUG - Registros con $mes_clave=0 después del UPDATE: $count_ceros");
    
    // Registrar en bitácora
    $mes_nombre = ucfirst($mes_clave);
    registrarAccion($conn, $usuario_id, 'BORRAR_MES', "Borró datos de $mes_nombre de $municipio ($jurisdiccion, $anio)", 1);
    
    echo json_encode([
        'ok' => true, 
        'msg' => "Se borraron los datos de $mes_nombre para $municipio ($registros_actualizados registros actualizados, $count_ceros con valor 0)"
    ]);
} else {
    error_log("BORRAR DEBUG - Error MySQL: " . mysqli_error($conn));
    echo json_encode(['ok' => false, 'msg' => 'Error al borrar: ' . mysqli_error($conn)]);
}

mysqli_stmt_close($stmt_update);
