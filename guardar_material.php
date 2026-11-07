<?php
/**
 * CRUD Material de Curación
 * Acciones: crear, editar, eliminar
 */

session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: material_curacion.php');
    exit;
}

$rol = $_SESSION['rol'];
$usuario_id = $_SESSION['usuario_id'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';
$esAdmin = ($rol === 'admin');

$accion = $_POST['accion'] ?? '';
$insumo_id = (int)($_POST['insumo_id'] ?? 0);
$anio = (int)($_POST['anio'] ?? date('Y'));

// ========== VERIFICAR/CREAR INSUMO ==========
if ($accion === 'verificar_insumo') {
    header('Content-Type: application/json');
    
    $id = (int)($_POST['id'] ?? 0);
    $nombre = $_POST['nombre'] ?? '';
    $presentacion = $_POST['presentacion'] ?? '';
    
    if (!$id || !$nombre) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
        exit;
    }
    
    try {
        // Verificar si el insumo ya existe
        $stmt = mysqli_prepare($conn, "SELECT id FROM insumos WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if (mysqli_num_rows($result) === 0) {
            // No existe, crear el insumo
            $stmt = mysqli_prepare($conn, "INSERT INTO insumos (id, nombre, presentacion, categoria, activo, orden) VALUES (?, ?, ?, 'estatal', 1, ?)");
            mysqli_stmt_bind_param($stmt, 'issi', $id, $nombre, $presentacion, $id);
            
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception('Error al crear insumo: ' . mysqli_error($conn));
            }
        }
        
        echo json_encode(['success' => true, 'insumo_id' => $id]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// URL de redirección (SIN insumo para volver al estado inicial)
$redirect = "material_curacion.php?anio=$anio";
if (!$esAdmin && $jurisdiccion_usuario) {
    $redirect .= "&jurisdiccion=" . urlencode($jurisdiccion_usuario);
}

// Función para recalcular remanentes de una jurisdicción (acumulativo)
function recalcularRemanentes($conn, $insumo_id, $jurisdiccion, $anio) {
    // Obtener remanente del año anterior (diciembre)
    $remanentePrevio = 0;
    $anioAnt = $anio - 1;
    $stmt = mysqli_prepare($conn, "SELECT remanente FROM material_registro WHERE insumo_id = ? AND jurisdiccion = ? AND anio = ? AND mes = 12");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'isi', $insumo_id, $jurisdiccion, $anioAnt);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $remanentePrevio = (int)$row['remanente'];
        }
        mysqli_stmt_close($stmt);
    }
    
    // Recalcular TODOS los meses del año en orden
    // El remanente de cada mes = remanente anterior + entregado - utilizado
    for ($mes = 1; $mes <= 12; $mes++) {
        $stmt = mysqli_prepare($conn, "SELECT id, entregado, utilizado FROM material_registro WHERE insumo_id = ? AND jurisdiccion = ? AND anio = ? AND mes = ?");
        mysqli_stmt_bind_param($stmt, 'isii', $insumo_id, $jurisdiccion, $anio, $mes);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($res)) {
            // Fórmula: Remanente = (Remanente mes anterior) + Entregado - Utilizado
            $entregado = (int)$row['entregado'];
            $utilizado = (int)$row['utilizado'];
            $remanente = $remanentePrevio + $entregado - $utilizado;
            
            $upd = mysqli_prepare($conn, "UPDATE material_registro SET remanente = ? WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'ii', $remanente, $row['id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            
            // El remanente de este mes pasa al siguiente
            $remanentePrevio = $remanente;
        }
        mysqli_stmt_close($stmt);
    }
}


// ========== CREAR ==========
if ($accion === 'crear') {
    $jurisdiccion = $_POST['jurisdiccion'] ?? '';
    $mes = (int)($_POST['mes'] ?? 0);
    $entregado = (int)($_POST['entregado'] ?? 0);
    $utilizado = (int)($_POST['utilizado'] ?? 0);
    
    // Validar permisos
    if (!$esAdmin && $jurisdiccion !== $jurisdiccion_usuario) {
        $_SESSION['error'] = 'No tienes permiso para esta jurisdicción';
        header("Location: $redirect");
        exit;
    }
    
    // Capturista no puede poner entregado
    if (!$esAdmin) {
        $entregado = 0;
    }
    
    // Verificar si ya existe
    $stmt = mysqli_prepare($conn, "SELECT id FROM material_registro WHERE insumo_id = ? AND jurisdiccion = ? AND anio = ? AND mes = ?");
    mysqli_stmt_bind_param($stmt, 'isii', $insumo_id, $jurisdiccion, $anio, $mes);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    
    if (mysqli_fetch_assoc($res)) {
        $_SESSION['error'] = 'Ya existe un registro para ese mes. Usa editar.';
        header("Location: $redirect");
        exit;
    }
    
    // Insertar
    $remanente = 0;
    $stmt = mysqli_prepare($conn, "INSERT INTO material_registro (insumo_id, jurisdiccion, anio, mes, entregado, utilizado, remanente, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'isiiiiii', $insumo_id, $jurisdiccion, $anio, $mes, $entregado, $utilizado, $remanente, $usuario_id);
    
    if (mysqli_stmt_execute($stmt)) {
        recalcularRemanentes($conn, $insumo_id, $jurisdiccion, $anio);
        $_SESSION['mensaje'] = 'Registro creado correctamente';
        registrarAccion($conn, $usuario_id, 'crear_material', "Insumo: $insumo_id, Jur: $jurisdiccion, Mes: $mes", 1);
    } else {
        $_SESSION['error'] = 'Error al crear registro';
    }
}

// ========== EDITAR ==========
elseif ($accion === 'editar') {
    $id = (int)($_POST['id'] ?? 0);
    $jurisdiccion = $_POST['jurisdiccion'] ?? '';
    $mes = (int)($_POST['mes'] ?? 0);
    $entregado = (int)($_POST['entregado'] ?? 0);
    $utilizado = (int)($_POST['utilizado'] ?? 0);
    
    // Validar permisos
    if (!$esAdmin && $jurisdiccion !== $jurisdiccion_usuario) {
        $_SESSION['error'] = 'No tienes permiso para esta jurisdicción';
        header("Location: $redirect");
        exit;
    }
    
    // Obtener datos actuales si capturista (para mantener entregado)
    if (!$esAdmin) {
        $stmt = mysqli_prepare($conn, "SELECT entregado FROM material_registro WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $entregado = (int)$row['entregado'];
        }
    }
    
    // Actualizar
    $stmt = mysqli_prepare($conn, "UPDATE material_registro SET mes = ?, entregado = ?, utilizado = ?, usuario_id = ?, fecha_actualizacion = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'iiiii', $mes, $entregado, $utilizado, $usuario_id, $id);
    
    if (mysqli_stmt_execute($stmt)) {
        recalcularRemanentes($conn, $insumo_id, $jurisdiccion, $anio);
        $_SESSION['mensaje'] = 'Registro actualizado correctamente';
        registrarAccion($conn, $usuario_id, 'editar_material', "ID: $id", 1);
    } else {
        $_SESSION['error'] = 'Error al actualizar registro';
    }
}

// ========== ELIMINAR ==========
elseif ($accion === 'eliminar') {
    $id = (int)($_POST['id'] ?? 0);
    
    // Obtener jurisdicción del registro
    $stmt = mysqli_prepare($conn, "SELECT jurisdiccion FROM material_registro WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $reg = mysqli_fetch_assoc($res);
    
    if (!$reg) {
        $_SESSION['error'] = 'Registro no encontrado';
        header("Location: $redirect");
        exit;
    }
    
    // Validar permisos
    if (!$esAdmin && $reg['jurisdiccion'] !== $jurisdiccion_usuario) {
        $_SESSION['error'] = 'No tienes permiso para eliminar este registro';
        header("Location: $redirect");
        exit;
    }
    
    $jurisdiccion = $reg['jurisdiccion'];
    
    // Eliminar
    $stmt = mysqli_prepare($conn, "DELETE FROM material_registro WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    
    if (mysqli_stmt_execute($stmt)) {
        recalcularRemanentes($conn, $insumo_id, $jurisdiccion, $anio);
        $_SESSION['mensaje'] = 'Registro eliminado correctamente';
        registrarAccion($conn, $usuario_id, 'eliminar_material', "ID: $id", 1);
    } else {
        $_SESSION['error'] = 'Error al eliminar registro';
    }
}

header("Location: $redirect");
exit;
