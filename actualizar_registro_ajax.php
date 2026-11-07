<?php
/**
 * Endpoint AJAX para actualizar registros de captura
 * Usado por el modal de edición en capturar_mes.php
 */
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar sesión
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida']);
    exit;
}

// Verificar CSRF token
if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';

// Obtener parámetros
$municipio = $_POST['municipio'] ?? '';
$jurisdiccion = $_POST['jurisdiccion'] ?? '';
$mes_clave = $_POST['mes'] ?? '';
$anio = intval($_POST['anio'] ?? 0);

// Validar parámetros básicos
if (empty($municipio) || empty($mes_clave) || $anio < 2020) {
    echo json_encode(['ok' => false, 'msg' => 'Parámetros inválidos']);
    exit;
}

// Validar mes
$meses_validos = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
if (!in_array($mes_clave, $meses_validos)) {
    echo json_encode(['ok' => false, 'msg' => 'Mes inválido']);
    exit;
}

// Determinar jurisdicción de trabajo
if ($rol === 'admin') {
    $jurisdiccion_trabajo = $jurisdiccion;
} else {
    // Capturista solo puede editar su propia jurisdicción
    $jurisdiccion_trabajo = $jurisdiccion_usuario;
    
    // Verificar si el año está cerrado para edición
    $check_anio_cerrado = mysqli_prepare($conn, "SELECT COUNT(*) as total, SUM(editable) as editables FROM registros WHERE anio = ?");
    mysqli_stmt_bind_param($check_anio_cerrado, 'i', $anio);
    mysqli_stmt_execute($check_anio_cerrado);
    $res_anio = mysqli_stmt_get_result($check_anio_cerrado);
    $estado_anio = mysqli_fetch_assoc($res_anio);
    
    if ($estado_anio['total'] > 0 && (int)$estado_anio['editables'] === 0) {
        echo json_encode(['ok' => false, 'msg' => 'El año ' . $anio . ' está cerrado para edición. Contacta al administrador si necesitas realizar cambios.']);
        exit;
    }
    
    // Verificar permisos de tiempo para capturistas
    // Para EDITAR, verificamos la fecha de captura del registro específico
    
    $mes_num = numeroDesdeMesClave($mes_clave);
    
    // Obtener la fecha de captura más reciente del municipio para este mes/año
    $sql_fecha = "SELECT MAX(fecha_captura) as fecha_captura FROM registros 
                  WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND fecha_captura IS NOT NULL";
    $stmt_fecha = mysqli_prepare($conn, $sql_fecha);
    mysqli_stmt_bind_param($stmt_fecha, 'iss', $anio, $jurisdiccion_trabajo, $municipio);
    mysqli_stmt_execute($stmt_fecha);
    $res_fecha = mysqli_stmt_get_result($stmt_fecha);
    $row_fecha = mysqli_fetch_assoc($res_fecha);
    
    $puede_editar = false;
    $motivo_bloqueo = '';
    
    // PRIMERO: Verificar si tiene prórroga activa para este mes/año
    $stmt_prorroga = mysqli_prepare($conn, "SELECT fecha_limite FROM extensiones 
                                            WHERE usuario_id = ? AND mes = ? AND anio = ? AND fecha_limite > NOW()");
    mysqli_stmt_bind_param($stmt_prorroga, 'iii', $usuario_id, $mes_num, $anio);
    mysqli_stmt_execute($stmt_prorroga);
    $res_prorroga = mysqli_stmt_get_result($stmt_prorroga);
    
    if (mysqli_fetch_assoc($res_prorroga)) {
        // Tiene prórroga activa - puede editar
        $puede_editar = true;
        $motivo_bloqueo = 'Prórroga activa';
    } elseif ($row_fecha && $row_fecha['fecha_captura']) {
        // Hay registro previo: verificar 5 días hábiles desde la última captura
        $fecha_captura = new DateTime($row_fecha['fecha_captura']);
        $hoy = new DateTime();
        $limite = clone $fecha_captura;
        $dias_habiles = 0;
        
        // Contar 5 días hábiles desde la fecha de captura
        while ($dias_habiles < 5) {
            $limite->add(new DateInterval('P1D'));
            // Lunes=1, Domingo=7. Solo contar Lunes-Viernes
            if ($limite->format('N') < 6) {
                $dias_habiles++;
            }
        }
        
        // Establecer hora límite al final del día
        $limite->setTime(23, 59, 59);
        
        if ($hoy <= $limite) {
            $puede_editar = true;
            $motivo_bloqueo = 'Dentro de los 5 días hábiles desde captura (' . $fecha_captura->format('d/m/Y') . ')';
        } else {
            $diff = $hoy->diff($fecha_captura);
            $motivo_bloqueo = 'Han pasado más de 5 días hábiles desde la captura (' . $fecha_captura->format('d/m/Y') . ')';
        }
    } else {
        // No hay registro previo - esto NO debería pasar en el modal de EDITAR
        // pero por seguridad, permitimos si está dentro del periodo de registro
        $dia_actual = (int)date('j');
        if ($dia_actual <= 10) {
            $puede_editar = true;
            $motivo_bloqueo = 'Primera captura dentro del periodo de 10 días';
        } else {
            $motivo_bloqueo = 'No se encontró registro previo y estás fuera del periodo de registro (solo primeros 10 días del mes)';
        }
    }
    
    if (!$puede_editar) {
        echo json_encode(['ok' => false, 'msg' => 'No tienes permiso para editar este periodo. ' . $motivo_bloqueo . '. Solicita una prórroga al administrador si necesitas editar.']);
        exit;
    }
}

// Obtener valores de los tipos
$tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
$valores = [];
$actualizados = 0;
$errores = [];

foreach ($tipos as $tipo) {
    $campo = 'valor_' . $tipo;
    if (isset($_POST[$campo]) && $_POST[$campo] !== '') {
        $valor = intval($_POST[$campo]);
        if ($valor < 0) {
            $errores[] = "Valor inválido para $tipo";
            continue;
        }
        $valores[$tipo] = $valor;
    }
}

if (empty($valores)) {
    echo json_encode(['ok' => false, 'msg' => 'No se proporcionaron valores para actualizar']);
    exit;
}

// Actualizar cada tipo
foreach ($valores as $tipo => $valor) {
    // Verificar si existe el registro
    $sql_check = "SELECT id, editable FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ?";
    $stmt_check = mysqli_prepare($conn, $sql_check);
    mysqli_stmt_bind_param($stmt_check, 'isss', $anio, $jurisdiccion_trabajo, $municipio, $tipo);
    mysqli_stmt_execute($stmt_check);
    $res_check = mysqli_stmt_get_result($stmt_check);
    $existe = mysqli_fetch_assoc($res_check);
    
    if ($existe) {
        // Verificar si el registro está editable (solo para capturistas)
        if ($rol === 'capturista' && isset($existe['editable']) && (int)$existe['editable'] === 0) {
            $errores[] = "El registro de $tipo está bloqueado para edición";
            continue;
        }
        
        // Actualizar registro existente
        $sql_update = "UPDATE registros SET `$mes_clave` = ?, usuario_id = ?, fecha_captura = NOW() WHERE id = ?";
        $stmt_update = mysqli_prepare($conn, $sql_update);
        mysqli_stmt_bind_param($stmt_update, 'iii', $valor, $usuario_id, $existe['id']);
        
        if (mysqli_stmt_execute($stmt_update)) {
            $actualizados++;
        } else {
            $errores[] = "Error al actualizar $tipo: " . mysqli_error($conn);
        }
    } else {
        // Crear nuevo registro
        // Primero verificar si existe meta
        $sql_meta = "SELECT meta FROM metas WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
        $stmt_meta = mysqli_prepare($conn, $sql_meta);
        mysqli_stmt_bind_param($stmt_meta, 'sssi', $jurisdiccion_trabajo, $municipio, $tipo, $anio);
        mysqli_stmt_execute($stmt_meta);
        $res_meta = mysqli_stmt_get_result($stmt_meta);
        $meta_row = mysqli_fetch_assoc($res_meta);
        $meta_anual = $meta_row ? $meta_row['meta'] : 0;
        
        $sql_insert = "INSERT INTO registros (usuario_id, anio, jurisdiccion, municipio, meta_anual, tipo, `$mes_clave`, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt_insert = mysqli_prepare($conn, $sql_insert);
        mysqli_stmt_bind_param($stmt_insert, 'iissisi', $usuario_id, $anio, $jurisdiccion_trabajo, $municipio, $meta_anual, $tipo, $valor);
        
        if (mysqli_stmt_execute($stmt_insert)) {
            $actualizados++;
        } else {
            $errores[] = "Error al crear $tipo: " . mysqli_error($conn);
        }
    }
}

// Respuesta
if ($actualizados > 0) {
    $msg = "Se actualizaron $actualizados registros correctamente.";
    if (!empty($errores)) {
        $msg .= " Errores: " . implode(', ', $errores);
    }
    echo json_encode(['ok' => true, 'msg' => $msg, 'actualizados' => $actualizados]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'No se pudo actualizar ningún registro. ' . implode(', ', $errores)]);
}
