<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Sesión no válida']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'msg' => 'Token de seguridad inválido. Recargue la página e intente nuevamente.']);
    exit;
}

// Cargar año activo del config
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

$usuarioId = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$anio = $anio_activo; // Usar año activo del sistema

// Determinar la columna de mes (clave 'ene'..'dic') correspondiente al mes anterior
$currentMonth = (int)date('n');
$mesNumPrev = $currentMonth - 1;
if ($mesNumPrev === 0) $mesNumPrev = 12;
$mes = mesClaveDesdeNumero($mesNumPrev);

// Lista blanca de columnas de meses
$meses_validos = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
if (!in_array($mes, $meses_validos)) {
    echo json_encode(['ok' => false, 'msg' => 'Error interno: mes inválido']);
    exit;
}

// Las restricciones de tiempo solo aplican a capturistas, no a administradores
// Los administradores pueden capturar y actualizar en cualquier momento

$tipo = sanitizarSQL($_POST['tipo'] ?? '');
$jurisdiccion = validarInput($_POST['jurisdiccion'] ?? '', 'jurisdiccion');
$municipio = sanitizarSQL($_POST['municipio'] ?? '');
$valor = validarInput($_POST['valor_mes'] ?? 0, 'int', ['min' => 0, 'max' => 999999]);
$meta_anual = validarInput($_POST['meta_anual'] ?? 0, 'int', ['min' => 0, 'max' => 999999]);

// Validaciones básicas
if (empty($tipo) || $jurisdiccion === false || empty($municipio) || $valor === false) {
    echo json_encode(['ok' => false, 'msg' => 'Datos incompletos o inválidos. Verifique los valores ingresados.']);
    exit;
}

// Validar jurisdicción para capturistas
if ($rol === 'capturista' && $jurisdiccion != $_SESSION['jurisdiccion']) {
    echo json_encode(['ok' => false, 'msg' => 'No puedes capturar datos para otra jurisdicción']);
    exit;
}

// Verificar si el año está cerrado para edición (aplica a capturistas)
if ($rol === 'capturista') {
    $check_anio_cerrado = mysqli_prepare($conn, "SELECT COUNT(*) as total, SUM(editable) as editables FROM registros WHERE anio = ?");
    mysqli_stmt_bind_param($check_anio_cerrado, 'i', $anio);
    mysqli_stmt_execute($check_anio_cerrado);
    $res_anio = mysqli_stmt_get_result($check_anio_cerrado);
    $estado_anio = mysqli_fetch_assoc($res_anio);
    
    // Si hay registros y todos tienen editable=0, el año está cerrado
    if ($estado_anio['total'] > 0 && (int)$estado_anio['editables'] === 0) {
        echo json_encode(['ok' => false, 'msg' => 'El año ' . $anio . ' está cerrado para edición. Contacta al administrador si necesitas realizar cambios.']);
        exit;
    }
}

// Siempre verificar y usar meta predefinida de la DB (para ambos roles)
$check_meta_query = "SELECT meta FROM metas WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?";
$check_meta_stmt = mysqli_prepare($conn, $check_meta_query);
mysqli_stmt_bind_param($check_meta_stmt, 'sssi', $jurisdiccion, $municipio, $tipo, $anio);
mysqli_stmt_execute($check_meta_stmt);
$meta_result = mysqli_stmt_get_result($check_meta_stmt);
$meta_row = mysqli_fetch_assoc($meta_result);

if (!$meta_row) {
    echo json_encode(['ok' => false, 'msg' => 'No existe meta definida para este municipio y tipo. Contacte al administrador para establecerla en la página de metas.']);
    exit;
}

$meta_anual = $meta_row['meta'];

// Verificar si ya existe un registro para este año, jurisdicción, municipio y tipo
$campoFecha = 'fecha_captura_' . $mes;
$check_query = "SELECT id, `$mes` as valor_mes, editable, `$campoFecha` as fecha_captura_mes, fecha_captura, created_at
                 FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ?";
$check_stmt = mysqli_prepare($conn, $check_query);
mysqli_stmt_bind_param($check_stmt, 'isss', $anio, $jurisdiccion, $municipio, $tipo);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);
$existing = mysqli_fetch_assoc($check_result);

if ($existing) {
    // Verificar si el registro está editable (0 = cerrado, 1 = abierto)
    if (isset($existing['editable']) && (int)$existing['editable'] === 0) {
        echo json_encode(['ok' => false, 'msg' => 'Este año está cerrado para edición. Los registros no pueden ser modificados.']);
        exit;
    }
    
    // Verificar si es una actualización (el mes ya tiene un valor)
    $esActualizacion = !empty($existing['valor_mes']);
    
    // Para capturistas, aplicar restricciones de tiempo
    if ($rol === 'capturista') {
        if ($esActualizacion) {
            // Es una actualización: verificar si puede actualizar (10 días hábiles desde la fecha de captura O tiene extensión)
            $fechaCaptura = $existing['fecha_captura_mes'];
            if (empty($fechaCaptura)) {
                // Si no hay fecha de captura del mes, usar fecha_captura general o created_at
                $fechaCaptura = $existing['fecha_captura'] ?? $existing['created_at'] ?? date('Y-m-d H:i:s');
            }
            
            // Pasar mes y año para verificar extensiones
            if (!puedeActualizar($fechaCaptura, $mesNumPrev, $anio)) {
                echo json_encode(['ok' => false, 'msg' => 'Fuera del período de actualización. Solo puedes actualizar durante los 5 días hábiles siguientes a la fecha de captura, o solicita una prórroga al administrador.']);
                exit;
            }
        } else {
            // Es una nueva captura para este mes: verificar si puede capturar (primeros 10 días hábiles o extensión)
            if (!puedeCapturarInicial($mes, $anio)) {
                echo json_encode(['ok' => false, 'msg' => 'Fuera del período de captura. Solo se puede capturar del 1° al 10° día hábil de cada mes, o solicita una prórroga al administrador.']);
                exit;
            }
        }
    }
    
    // Usar la fecha de captura del mes específico (ya definida arriba)
    $fechaActual = date('Y-m-d H:i:s');
    
    // Si es la primera vez que se captura este mes, establecer la fecha de captura
    if (!$esActualizacion) {
        $update_query = "UPDATE registros SET `$mes` = ?, usuario_id = ?, `$campoFecha` = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, 'iiss', $valor, $usuarioId, $fechaActual, $existing['id']);
    } else {
        // Actualización: mantener la fecha original de captura, solo actualizar el valor
        $update_query = "UPDATE registros SET `$mes` = ?, usuario_id = ? WHERE id = ?";
        $update_stmt = mysqli_prepare($conn, $update_query);
        mysqli_stmt_bind_param($update_stmt, 'iii', $valor, $usuarioId, $existing['id']);
    }
    
    if (mysqli_stmt_execute($update_stmt)) {
        $mensaje = $esActualizacion ? 'Datos actualizados correctamente' : 'Datos guardados correctamente';
        echo json_encode(['ok' => true, 'msg' => $mensaje]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Error al actualizar: ' . mysqli_error($conn)]);
    }
} else {
    // Nueva captura: verificar restricción de tiempo para capturistas
    if ($rol === 'capturista' && !puedeCapturarInicial($mes, $anio)) {
        echo json_encode(['ok' => false, 'msg' => 'Fuera del período de captura. Solo se puede capturar del 1° al 10° día hábil de cada mes, o solicita una prórroga al administrador.']);
        exit;
    }
    
    // Insertar nuevo registro (columna de mes validada)
    // $campoFecha ya está definida arriba
    $fechaActual = date('Y-m-d H:i:s');
    $insert_query = "INSERT INTO registros (usuario_id, anio, jurisdiccion, municipio, meta_anual, `$mes`, tipo, `$campoFecha`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $insert_stmt = mysqli_prepare($conn, $insert_query);
    mysqli_stmt_bind_param($insert_stmt, 'iissiiss', $usuarioId, $anio, $jurisdiccion, $municipio, $meta_anual, $valor, $tipo, $fechaActual);
    
    if (mysqli_stmt_execute($insert_stmt)) {
        echo json_encode(['ok' => true, 'msg' => 'Datos guardados correctamente']);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Error al guardar: ' . mysqli_error($conn)]);
    }
}
?>

