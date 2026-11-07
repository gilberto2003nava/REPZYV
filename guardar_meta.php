<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$accion = $_POST['accion'] ?? 'crear';

// ==================== ACTUALIZAR META ====================
if ($accion === 'actualizar') {
    $id = validarInput($_POST['id'] ?? '', 'int', ['min' => 1]);
    $valor = validarInput($_POST['valor'] ?? '', 'int', ['min' => 0, 'max' => 999999]);
    
    if ($id === false || $valor === false) {
        echo json_encode(['ok' => false, 'msg' => 'Datos inválidos']);
        exit;
    }
    
    try {
        mysqli_begin_transaction($conn);
        
        // Obtener datos de la meta actual
        $get_sql = "SELECT jurisdiccion, municipio, tipo, anio FROM metas WHERE id = ?";
        $get_stmt = mysqli_prepare($conn, $get_sql);
        mysqli_stmt_bind_param($get_stmt, 'i', $id);
        mysqli_stmt_execute($get_stmt);
        $meta_actual = mysqli_stmt_get_result($get_stmt)->fetch_assoc();
        
        if (!$meta_actual) {
            throw new Exception('Meta no encontrada');
        }
        
        // Actualizar en tabla metas
        $update_sql = "UPDATE metas SET meta = ?, creada_por = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, 'iii', $valor, $usuario_id, $id);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Error al actualizar la meta');
        }
        
        // Sincronizar en tabla registros
        $update_reg_sql = "UPDATE registros SET meta_anual = ? WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
        $update_reg_stmt = mysqli_prepare($conn, $update_reg_sql);
        mysqli_stmt_bind_param($update_reg_stmt, 'isssi', $valor, $meta_actual['jurisdiccion'], $meta_actual['municipio'], $meta_actual['tipo'], $meta_actual['anio']);
        mysqli_stmt_execute($update_reg_stmt);
        
        mysqli_commit($conn);
        
        registrarAccion($conn, $usuario_id, 'actualizar_meta', "Meta ID $id actualizada a valor: $valor", 1);
        echo json_encode(['ok' => true, 'msg' => 'Meta actualizada correctamente']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error en actualizar_meta: " . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
    }
    
    mysqli_close($conn);
    exit;
}

// ==================== ELIMINAR META ====================
if ($accion === 'eliminar') {
    $id = validarInput($_POST['id'] ?? '', 'int', ['min' => 1]);
    
    if ($id === false) {
        echo json_encode(['ok' => false, 'msg' => 'ID inválido']);
        exit;
    }
    
    try {
        mysqli_begin_transaction($conn);
        
        // Obtener datos de la meta actual
        $get_sql = "SELECT jurisdiccion, municipio, tipo, anio FROM metas WHERE id = ?";
        $get_stmt = mysqli_prepare($conn, $get_sql);
        mysqli_stmt_bind_param($get_stmt, 'i', $id);
        mysqli_stmt_execute($get_stmt);
        $meta_actual = mysqli_stmt_get_result($get_stmt)->fetch_assoc();
        
        if (!$meta_actual) {
            throw new Exception('Meta no encontrada');
        }
        
        // Eliminar de tabla metas
        $delete_sql = "DELETE FROM metas WHERE id = ?";
        $delete_stmt = mysqli_prepare($conn, $delete_sql);
        mysqli_stmt_bind_param($delete_stmt, 'i', $id);
        
        if (!mysqli_stmt_execute($delete_stmt)) {
            throw new Exception('Error al eliminar la meta');
        }
        
        // Poner meta_anual en 0 en registros (no eliminar el registro para mantener capturas)
        $update_reg_sql = "UPDATE registros SET meta_anual = 0 WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
        $update_reg_stmt = mysqli_prepare($conn, $update_reg_sql);
        mysqli_stmt_bind_param($update_reg_stmt, 'sssi', $meta_actual['jurisdiccion'], $meta_actual['municipio'], $meta_actual['tipo'], $meta_actual['anio']);
        mysqli_stmt_execute($update_reg_stmt);
        
        mysqli_commit($conn);
        
        registrarAccion($conn, $usuario_id, 'eliminar_meta', "Meta ID $id eliminada: {$meta_actual['municipio']} - {$meta_actual['tipo']}", 1);
        echo json_encode(['ok' => true, 'msg' => 'Meta eliminada correctamente']);
        
    } catch (Exception $e) {
        mysqli_rollback($conn);
        error_log("Error en eliminar_meta: " . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Error: ' . $e->getMessage()]);
    }
    
    mysqli_close($conn);
    exit;
}

// ==================== CREAR META (original) ====================

$required = ['jurisdiccion', 'municipio', 'tipo', 'valor', 'anio'];
foreach ($required as $field) {
    if (!isset($_POST[$field]) || $_POST[$field] === '') {
        echo json_encode(['ok' => false, 'msg' => "Campo $field es requerido"]);
        exit;
    }
}

$jurisdiccion = validarInput($_POST['jurisdiccion'], 'jurisdiccion');
$municipio = sanitizarSQL($_POST['municipio']);
$tipo = sanitizarSQL($_POST['tipo']);
$valor = validarInput($_POST['valor'], 'int', ['min' => 0, 'max' => 999999]);
$anio = validarInput($_POST['anio'], 'anio');

if ($jurisdiccion === false || empty($municipio) || empty($tipo) || $valor === false || $anio === false) {
    echo json_encode(['ok' => false, 'msg' => 'Datos inválidos. Verifique los valores ingresados.']);
    exit;
}

$tipos_permitidos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
if (!in_array($tipo, $tipos_permitidos)) {
    echo json_encode(['ok' => false, 'msg' => 'Tipo de meta no válido']);
    exit;
}

// Validar que el lugar (municipio/hospital) existe en la jurisdicción
$municipios_base = municipiosPorJurisdiccion($jurisdiccion);
$hospitales = municipiosPorJurisdiccion($jurisdiccion, 'agredidos');
$todos_lugares = array_unique(array_merge($municipios_base, $hospitales));

if (!in_array($municipio, $todos_lugares)) {
    echo json_encode(['ok' => false, 'msg' => 'El municipio/hospital no pertenece a la jurisdicción seleccionada']);
    exit;
}

// Para hospitales, validar que solo se puedan asignar ciertos tipos
$es_hospital = in_array($municipio, $hospitales) && !in_array($municipio, $municipios_base);
$tipos_hospital = ['agredidos', 'tratamientos', 'control', 'muestras'];

if ($es_hospital && !in_array($tipo, $tipos_hospital)) {
    echo json_encode(['ok' => false, 'msg' => 'Los hospitales solo pueden tener metas de tipos: ' . implode(', ', $tipos_hospital)]);
    exit;
}

try {
    mysqli_begin_transaction($conn);
    
    // 1. TABLA METAS - Verificar si ya existe
    $check_sql = "SELECT id FROM metas WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, 'sssi', $jurisdiccion, $municipio, $tipo, $anio);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);
    
    if (mysqli_num_rows($check_result) > 0) {
        // Actualizar meta existente en tabla metas
        $update_sql = "UPDATE metas SET meta = ?, creada_por = ? 
                      WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, 'issssi', $valor, $usuario_id, $jurisdiccion, $municipio, $tipo, $anio);
        
        if (!mysqli_stmt_execute($update_stmt)) {
            throw new Exception('Error al actualizar la meta en tabla metas');
        }
    } else {
        // Insertar nueva meta en tabla metas
        $insert_sql = "INSERT INTO metas (jurisdiccion, municipio, tipo, meta, anio, creada_por) 
                      VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = mysqli_prepare($conn, $insert_sql);
        mysqli_stmt_bind_param($insert_stmt, 'sssiii', $jurisdiccion, $municipio, $tipo, $valor, $anio, $usuario_id);
        
        if (!mysqli_stmt_execute($insert_stmt)) {
            throw new Exception('Error al crear la meta en tabla metas');
        }
    }
    
    // 2. TABLA REGISTROS - Sincronizar meta_anual (para reportes)
    $check_reg_sql = "SELECT id FROM registros WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
    $check_reg_stmt = mysqli_prepare($conn, $check_reg_sql);
    mysqli_stmt_bind_param($check_reg_stmt, 'sssi', $jurisdiccion, $municipio, $tipo, $anio);
    mysqli_stmt_execute($check_reg_stmt);
    $check_reg_result = mysqli_stmt_get_result($check_reg_stmt);
    
    if (mysqli_num_rows($check_reg_result) > 0) {
        // Actualizar meta_anual en registros existente
        $update_reg_sql = "UPDATE registros SET meta_anual = ? WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
        $update_reg_stmt = mysqli_prepare($conn, $update_reg_sql);
        mysqli_stmt_bind_param($update_reg_stmt, 'isssi', $valor, $jurisdiccion, $municipio, $tipo, $anio);
        
        if (!mysqli_stmt_execute($update_reg_stmt)) {
            throw new Exception('Error al actualizar meta_anual en registros');
        }
    } else {
        // Crear registro nuevo con la meta (capturas en 0)
        $insert_reg_sql = "INSERT INTO registros (usuario_id, anio, jurisdiccion, municipio, tipo, meta_anual, 
                          ene, feb, mar, abr, may, jun, jul, ago, sep, oct, nov, dic, editable, fecha_captura, created_at) 
                          VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, NOW(), NOW())";
        $insert_reg_stmt = mysqli_prepare($conn, $insert_reg_sql);
        mysqli_stmt_bind_param($insert_reg_stmt, 'iisssi', $usuario_id, $anio, $jurisdiccion, $municipio, $tipo, $valor);
        
        if (!mysqli_stmt_execute($insert_reg_stmt)) {
            throw new Exception('Error al crear registro con meta');
        }
    }
    
    mysqli_commit($conn);
    
    registrarAccion($conn, $usuario_id, 'guardar_meta', "Meta $tipo guardada para $municipio, $jurisdiccion: $valor", 1);
    echo json_encode(['ok' => true, 'msg' => 'Meta guardada correctamente (sincronizada en ambas tablas)']);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    error_log("Error en guardar_meta.php: " . $e->getMessage());
    registrarAccion($conn, $usuario_id, 'error_meta', "Error al guardar meta: " . $e->getMessage(), 0);
    echo json_encode(['ok' => false, 'msg' => 'Error interno del servidor: ' . $e->getMessage()]);
}

mysqli_close($conn);
?>







