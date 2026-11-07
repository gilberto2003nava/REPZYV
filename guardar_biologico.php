<?php
/**
 * Guardar datos de Biológico Canino
 */
// Deshabilitar display de errores para evitar output HTML
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Iniciar sesión antes de cualquier output
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Establecer header JSON INMEDIATAMENTE
header('Content-Type: application/json; charset=utf-8');

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Incluir conexión (sin output)
require_once __DIR__ . '/includes/conexion.php';

// Verificar que la conexión existe
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
    exit;
}

// Acción: Obtener registro por ID (solo admin)
if (isset($_GET['action']) && $_GET['action'] === 'obtener') {
    if ($_SESSION['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    $sql = "SELECT * FROM biologico_canino WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $registro = mysqli_fetch_assoc($result);
    
    if ($registro) {
        echo json_encode(['success' => true, 'registro' => $registro]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Registro no encontrado']);
    }
    exit;
}

// Acción: Editar registro (solo admin)
if (isset($_POST['action']) && $_POST['action'] === 'editar') {
    if ($_SESSION['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    // Obtener datos del registro actual para saber jurisdicción, mes y año
    $sqlActual = "SELECT jurisdiccion, mes, anio FROM biologico_canino WHERE id = ?";
    $stmtActual = mysqli_prepare($conn, $sqlActual);
    mysqli_stmt_bind_param($stmtActual, 'i', $id);
    mysqli_stmt_execute($stmtActual);
    $registroActual = mysqli_stmt_get_result($stmtActual)->fetch_assoc();
    
    if (!$registroActual) {
        echo json_encode(['success' => false, 'error' => 'Registro no encontrado']);
        exit;
    }
    
    $jurisdiccion = $registroActual['jurisdiccion'];
    $mes = (int)$registroActual['mes'];
    $anio = (int)$registroActual['anio'];
    
    // HEREDAR EXISTENCIA DEL MES ANTERIOR (igual que en la captura normal)
    $mesAnterior = $mes - 1;
    $existenciaAnterior = null;
    if ($mesAnterior > 0) {
        $sqlAnterior = "SELECT existencia_fin_dosis, existencia_fin_lote, existencia_fin_caducidad 
                        FROM biologico_canino WHERE jurisdiccion=? AND anio=? AND mes=?";
        $stmtAnt = mysqli_prepare($conn, $sqlAnterior);
        mysqli_stmt_bind_param($stmtAnt, 'sii', $jurisdiccion, $anio, $mesAnterior);
        mysqli_stmt_execute($stmtAnt);
        $existenciaAnterior = mysqli_stmt_get_result($stmtAnt)->fetch_assoc();
    }
    
    // Si hay mes anterior, HEREDAR su existencia final (NO usar lo que viene del POST)
    $existencia_inicio_dosis = $existenciaAnterior 
        ? (int)$existenciaAnterior['existencia_fin_dosis'] 
        : (int)($_POST['existencia_inicio_dosis'] ?? 0);
    $existencia_inicio_lote = $existenciaAnterior 
        ? $existenciaAnterior['existencia_fin_lote'] 
        : trim($_POST['existencia_inicio_lote'] ?? '');
    $existencia_inicio_caducidad = $existenciaAnterior 
        ? $existenciaAnterior['existencia_fin_caducidad'] 
        : trim($_POST['existencia_inicio_caducidad'] ?? '');
    
    $dosis_recibidas = (int)($_POST['dosis_recibidas'] ?? 0);
    $dosis_recibidas_lote = trim($_POST['dosis_recibidas_lote'] ?? '');
    $dosis_recibidas_caducidad = trim($_POST['dosis_recibidas_caducidad'] ?? '');
    $dosis_administradas = (int)($_POST['dosis_administradas'] ?? 0);
    $desperdicio_dosis = (int)($_POST['desperdicio_dosis'] ?? 0);
    $existencia_fin_lote = trim($_POST['existencia_fin_lote'] ?? '');
    $existencia_fin_caducidad = trim($_POST['existencia_fin_caducidad'] ?? '');
    
    // Calcular totales
    $total_dosis = $existencia_inicio_dosis + $dosis_recibidas;
    $existencia_fin_dosis = $total_dosis - $dosis_administradas - $desperdicio_dosis;
    
    $sql = "UPDATE biologico_canino SET 
            existencia_inicio_dosis = ?,
            existencia_inicio_lote = ?,
            existencia_inicio_caducidad = ?,
            dosis_recibidas = ?,
            dosis_recibidas_lote = ?,
            dosis_recibidas_caducidad = ?,
            total_dosis = ?,
            dosis_administradas = ?,
            desperdicio_dosis = ?,
            existencia_fin_dosis = ?,
            existencia_fin_lote = ?,
            existencia_fin_caducidad = ?
            WHERE id = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'issississsssi',
        $existencia_inicio_dosis,
        $existencia_inicio_lote,
        $existencia_inicio_caducidad,
        $dosis_recibidas,
        $dosis_recibidas_lote,
        $dosis_recibidas_caducidad,
        $total_dosis,
        $dosis_administradas,
        $desperdicio_dosis,
        $existencia_fin_dosis,
        $existencia_fin_lote,
        $existencia_fin_caducidad,
        $id
    );
    
    if (mysqli_stmt_execute($stmt)) {
        // ACTUALIZACIÓN EN CASCADA: Actualizar todos los meses siguientes
        actualizarMesesSiguientesEnCascada($conn, $jurisdiccion, $anio, $mes, $existencia_fin_dosis);
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

// Acción: Eliminar registro (solo admin)
if (isset($_GET['action']) && $_GET['action'] === 'eliminar') {
    if ($_SESSION['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
    
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'ID inválido']);
        exit;
    }
    
    $sql = "DELETE FROM biologico_canino WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'i', $id);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

// Acción especial: actualizar producto (biológico y presentación) para todo el año (admin)
if (isset($_POST['action']) && $_POST['action'] === 'actualizar_producto') {
    if ($_SESSION['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo el administrador puede editar el producto']);
        exit;
    }
    
    $biologico = trim($_POST['biologico'] ?? '');
    $presentacion = trim($_POST['presentacion'] ?? '');
    $anio = (int)($_POST['anio'] ?? date('Y'));
    
    if (empty($biologico) || empty($presentacion)) {
        echo json_encode(['success' => false, 'error' => 'Biológico y presentación son requeridos']);
        exit;
    }
    
    // Actualizar en TODOS los registros del año
    $sql = "UPDATE biologico_canino SET biologico = ?, presentacion = ? WHERE anio = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ssi', $biologico, $presentacion, $anio);
    
    if (mysqli_stmt_execute($stmt)) {
        $registros_actualizados = mysqli_stmt_affected_rows($stmt);
        echo json_encode([
            'success' => true, 
            'mensaje' => "Se actualizaron $registros_actualizados registros"
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

// Acción especial: guardar solo observaciones (admin)
if (isset($_GET['action']) && $_GET['action'] === 'observaciones') {
    if ($_SESSION['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo el administrador puede editar observaciones']);
        exit;
    }
    
    $anio = (int)($_POST['anio'] ?? date('Y'));
    $mes = (int)($_POST['mes'] ?? 0);
    $observaciones = trim($_POST['observaciones'] ?? '');
    
    if ($mes < 1 || $mes > 12) {
        echo json_encode(['success' => false, 'error' => 'Mes inválido']);
        exit;
    }
    
    // Actualizar observaciones en todos los registros del mes
    $sql = "UPDATE biologico_canino SET observaciones = ? WHERE anio = ? AND mes = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sii', $observaciones, $anio, $mes);
    
    if (mysqli_stmt_execute($stmt)) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

// Acción especial: corregir existencia_inicio (admin) - para resolver discrepancias
if (isset($_POST['action']) && $_POST['action'] === 'corregir_existencia_inicio') {
    if ($_SESSION['rol'] !== 'admin') {
        echo json_encode(['success' => false, 'error' => 'Solo el administrador puede corregir discrepancias']);
        exit;
    }
    
    $jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
    $mes = (int)($_POST['mes'] ?? 0);
    $anio = (int)($_POST['anio'] ?? date('Y'));
    $existencia_inicio_dosis = (int)($_POST['existencia_inicio_dosis'] ?? 0);
    
    if (empty($jurisdiccion) || $mes < 1 || $mes > 12) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos o inválidos']);
        exit;
    }
    
    // Actualizar solo la existencia_inicio_dosis del registro específico
    $sql = "UPDATE biologico_canino SET existencia_inicio_dosis = ? WHERE jurisdiccion = ? AND anio = ? AND mes = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'isii', $existencia_inicio_dosis, $jurisdiccion, $anio, $mes);
    
    if (mysqli_stmt_execute($stmt)) {
        $affected = mysqli_stmt_affected_rows($stmt);
        if ($affected > 0) {
            echo json_encode(['success' => true, 'mensaje' => 'Existencia inicial corregida correctamente']);
        } else {
            echo json_encode(['success' => false, 'error' => 'No se encontró el registro para actualizar']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
    }
    exit;
}

$jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
$anio = (int)($_POST['anio'] ?? date('Y'));
$mes = (int)($_POST['mes'] ?? 0);

if (empty($jurisdiccion) || $mes < 1 || $mes > 12) {
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    exit;
}

// Verificar permisos
$rol = $_SESSION['rol'];
if ($rol !== 'admin' && $_SESSION['jurisdiccion'] !== $jurisdiccion) {
    echo json_encode(['success' => false, 'error' => 'Sin permisos para esta jurisdicción']);
    exit;
}

// Verificar que no se salte meses (solo para capturistas, admin puede editar cualquier mes)
if ($rol !== 'admin' && $mes > 1) {
    // Verificar si ya existe este registro (es edición)
    $checkExiste = mysqli_prepare($conn, "SELECT id FROM biologico_canino WHERE jurisdiccion=? AND anio=? AND mes=?");
    mysqli_stmt_bind_param($checkExiste, 'sii', $jurisdiccion, $anio, $mes);
    mysqli_stmt_execute($checkExiste);
    $yaExiste = mysqli_stmt_get_result($checkExiste)->fetch_assoc();
    
    if (!$yaExiste) {
        // Es nuevo registro, verificar que el mes anterior esté capturado
        $checkAnterior = mysqli_prepare($conn, "SELECT id FROM biologico_canino WHERE jurisdiccion=? AND anio=? AND mes=?");
        $mesAnteriorCheck = $mes - 1;
        mysqli_stmt_bind_param($checkAnterior, 'sii', $jurisdiccion, $anio, $mesAnteriorCheck);
        mysqli_stmt_execute($checkAnterior);
        $anteriorExiste = mysqli_stmt_get_result($checkAnterior)->fetch_assoc();
        
        if (!$anteriorExiste) {
            $mesesNombres = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
                           7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
            echo json_encode(['success' => false, 'error' => "Debe capturar primero {$mesesNombres[$mesAnteriorCheck]} antes de capturar {$mesesNombres[$mes]}"]);
            exit;
        }
    }
}

// Obtener existencia final del mes anterior (si existe)
$mesAnterior = $mes - 1;
$existenciaAnterior = null;
if ($mesAnterior > 0) {
    $sqlAnterior = "SELECT existencia_fin_dosis, existencia_fin_lote, existencia_fin_caducidad 
                    FROM biologico_canino WHERE jurisdiccion=? AND anio=? AND mes=?";
    $stmtAnt = mysqli_prepare($conn, $sqlAnterior);
    mysqli_stmt_bind_param($stmtAnt, 'sii', $jurisdiccion, $anio, $mesAnterior);
    mysqli_stmt_execute($stmtAnt);
    $existenciaAnterior = mysqli_stmt_get_result($stmtAnt)->fetch_assoc();
}

// Datos base - Si hay mes anterior, usar su existencia final como inicio
$existencia_inicio = $existenciaAnterior 
    ? (int)$existenciaAnterior['existencia_fin_dosis'] 
    : (int)($_POST['existencia_inicio_dosis'] ?? 0);
$existencia_inicio_lote = $existenciaAnterior 
    ? $existenciaAnterior['existencia_fin_lote'] 
    : trim($_POST['existencia_inicio_lote'] ?? '');
$existencia_inicio_caducidad = $existenciaAnterior 
    ? $existenciaAnterior['existencia_fin_caducidad'] 
    : ($_POST['existencia_inicio_caducidad'] ?: null);

$dosis_recibidas = (int)($_POST['dosis_recibidas'] ?? 0);
$dosis_administradas = (int)($_POST['dosis_administradas'] ?? 0);
$desperdicio = (int)($_POST['desperdicio_dosis'] ?? 0);

// FÓRMULAS (igual que en Excel):
// Total = Existencia Inicio + Dosis Recibidas
$total_dosis = $existencia_inicio + $dosis_recibidas;
// Existencia Fin = Total - Administradas - Desperdicio
$existencia_fin = $total_dosis - $dosis_administradas - $desperdicio;

$data = [
    'biologico' => $_POST['biologico'] ?? 'RABIFFA',
    'presentacion' => $_POST['presentacion'] ?? 'FCO 20 DOSIS',
    'existencia_inicio_dosis' => $existencia_inicio,
    'existencia_inicio_lote' => $existencia_inicio_lote,
    'existencia_inicio_caducidad' => $existencia_inicio_caducidad,
    'dosis_recibidas' => $dosis_recibidas,
    'dosis_recibidas_lote' => trim($_POST['dosis_recibidas_lote'] ?? ''),
    'dosis_recibidas_caducidad' => $_POST['dosis_recibidas_caducidad'] ?: null,
    'dosis_administradas' => $dosis_administradas,
    'desperdicio_dosis' => $desperdicio,
    'existencia_fin_dosis' => $existencia_fin, // Calculado automáticamente
    'existencia_fin_lote' => trim($_POST['existencia_fin_lote'] ?? ''),
    'existencia_fin_caducidad' => $_POST['existencia_fin_caducidad'] ?: null,
    'observaciones' => trim($_POST['observaciones'] ?? ''),
    'usuario_id' => $_SESSION['usuario_id']
];

try {
    // Verificar si existe
    $check = mysqli_prepare($conn, "SELECT id FROM biologico_canino WHERE jurisdiccion=? AND anio=? AND mes=?");
    
    if (!$check) {
        throw new Exception('Error al preparar consulta de verificación: ' . mysqli_error($conn));
    }
    
    mysqli_stmt_bind_param($check, 'sii', $jurisdiccion, $anio, $mes);
    mysqli_stmt_execute($check);
    $exists = mysqli_stmt_get_result($check)->fetch_assoc();
    
    if ($exists) {
        // UPDATE
        $sql = "UPDATE biologico_canino SET 
                biologico=?, presentacion=?,
                existencia_inicio_dosis=?, existencia_inicio_lote=?, existencia_inicio_caducidad=?,
                dosis_recibidas=?, dosis_recibidas_lote=?, dosis_recibidas_caducidad=?,
                total_dosis=?, dosis_administradas=?, desperdicio_dosis=?,
                existencia_fin_dosis=?, existencia_fin_lote=?, existencia_fin_caducidad=?,
                observaciones=?, usuario_id=?
                WHERE jurisdiccion=? AND anio=? AND mes=?";
        $stmt = mysqli_prepare($conn, $sql);
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Error al preparar consulta: ' . mysqli_error($conn)]);
            exit;
        }
        
        // Tipos: ss iss iss iii iss s i | sii = 19 parámetros
        mysqli_stmt_bind_param($stmt, 'ssisssissiiisssisii',
            $data['biologico'], $data['presentacion'],
            $data['existencia_inicio_dosis'], $data['existencia_inicio_lote'], $data['existencia_inicio_caducidad'],
            $data['dosis_recibidas'], $data['dosis_recibidas_lote'], $data['dosis_recibidas_caducidad'],
            $total_dosis, $data['dosis_administradas'], $data['desperdicio_dosis'],
            $data['existencia_fin_dosis'], $data['existencia_fin_lote'], $data['existencia_fin_caducidad'],
            $data['observaciones'], $data['usuario_id'],
            $jurisdiccion, $anio, $mes
        );
    } else {
        // INSERT
        $sql = "INSERT INTO biologico_canino 
                (jurisdiccion, anio, mes, biologico, presentacion,
                existencia_inicio_dosis, existencia_inicio_lote, existencia_inicio_caducidad,
                dosis_recibidas, dosis_recibidas_lote, dosis_recibidas_caducidad,
                total_dosis, dosis_administradas, desperdicio_dosis,
                existencia_fin_dosis, existencia_fin_lote, existencia_fin_caducidad,
                observaciones, usuario_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        
        if (!$stmt) {
            echo json_encode(['success' => false, 'error' => 'Error al preparar consulta INSERT: ' . mysqli_error($conn)]);
            exit;
        }
        
        // Tipos: s ii ss iss iss iii iss s i = 19 parámetros
        mysqli_stmt_bind_param($stmt, 'siississsissiiisssi',
            $jurisdiccion, $anio, $mes,
            $data['biologico'], $data['presentacion'],
            $data['existencia_inicio_dosis'], $data['existencia_inicio_lote'], $data['existencia_inicio_caducidad'],
            $data['dosis_recibidas'], $data['dosis_recibidas_lote'], $data['dosis_recibidas_caducidad'],
            $total_dosis, $data['dosis_administradas'], $data['desperdicio_dosis'],
            $data['existencia_fin_dosis'], $data['existencia_fin_lote'], $data['existencia_fin_caducidad'],
            $data['observaciones'], $data['usuario_id']
        );
    }
    
    if (mysqli_stmt_execute($stmt)) {
        // ACTUALIZACIÓN EN CASCADA: Actualizar todos los meses siguientes
        actualizarMesesSiguientesEnCascada($conn, $jurisdiccion, $anio, $mes, $existencia_fin);
        
        $data['jurisdiccion'] = $jurisdiccion;
        $data['anio'] = $anio;
        $data['mes'] = $mes;
        $data['total_dosis'] = $total_dosis; // Campo calculado
        $data['fecha_actualizacion'] = date('Y-m-d H:i:s');
        echo json_encode(['success' => true, 'data' => $data]);
    } else {
        $error = mysqli_stmt_error($stmt) ?: mysqli_error($conn);
        echo json_encode(['success' => false, 'error' => 'Error al ejecutar consulta: ' . $error]);
    }
} catch (Exception $e) {
    error_log('Error en guardar_biologico.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    error_log('Error fatal en guardar_biologico.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor']);
}

/**
 * Función para actualizar en cascada todos los meses siguientes
 * Cuando se guarda un mes, actualiza la existencia_inicio de todos los meses posteriores
 * 
 * @param mysqli $conn Conexión a la base de datos
 * @param string $jurisdiccion Jurisdicción del registro
 * @param int $anio Año del registro
 * @param int $mesActual Mes que se acaba de guardar
 * @param int $existenciaFin Existencia final del mes guardado
 */
function actualizarMesesSiguientesEnCascada($conn, $jurisdiccion, $anio, $mesActual, $existenciaFin) {
    // Obtener datos del mes actual para heredar lote y caducidad
    $sqlActual = "SELECT existencia_fin_lote, existencia_fin_caducidad FROM biologico_canino 
                  WHERE jurisdiccion = ? AND anio = ? AND mes = ?";
    $stmtActual = mysqli_prepare($conn, $sqlActual);
    mysqli_stmt_bind_param($stmtActual, 'sii', $jurisdiccion, $anio, $mesActual);
    mysqli_stmt_execute($stmtActual);
    $datosActual = mysqli_stmt_get_result($stmtActual)->fetch_assoc();
    
    if (!$datosActual) {
        return; // No hay datos del mes actual
    }
    
    $lote = $datosActual['existencia_fin_lote'];
    $caducidad = $datosActual['existencia_fin_caducidad'];
    
    // Actualizar todos los meses siguientes (desde mesActual+1 hasta 12)
    for ($mesSiguiente = $mesActual + 1; $mesSiguiente <= 12; $mesSiguiente++) {
        // Verificar si existe el mes siguiente
        $sqlCheck = "SELECT id, dosis_recibidas, dosis_administradas, desperdicio_dosis 
                     FROM biologico_canino WHERE jurisdiccion = ? AND anio = ? AND mes = ?";
        $stmtCheck = mysqli_prepare($conn, $sqlCheck);
        mysqli_stmt_bind_param($stmtCheck, 'sii', $jurisdiccion, $anio, $mesSiguiente);
        mysqli_stmt_execute($stmtCheck);
        $mesSiguienteData = mysqli_stmt_get_result($stmtCheck)->fetch_assoc();
        
        if (!$mesSiguienteData) {
            // Si no existe el mes siguiente, detener la cascada
            break;
        }
        
        // Actualizar existencia_inicio del mes siguiente con la existencia_fin del mes actual
        $sqlUpdate = "UPDATE biologico_canino SET 
                      existencia_inicio_dosis = ?,
                      existencia_inicio_lote = ?,
                      existencia_inicio_caducidad = ?
                      WHERE jurisdiccion = ? AND anio = ? AND mes = ?";
        $stmtUpdate = mysqli_prepare($conn, $sqlUpdate);
        mysqli_stmt_bind_param($stmtUpdate, 'isssii', 
            $existenciaFin, 
            $lote, 
            $caducidad,
            $jurisdiccion, 
            $anio, 
            $mesSiguiente
        );
        mysqli_stmt_execute($stmtUpdate);
        
        // Recalcular totales del mes siguiente
        $dosisRecibidas = (int)$mesSiguienteData['dosis_recibidas'];
        $dosisAdmin = (int)$mesSiguienteData['dosis_administradas'];
        $desperdicio = (int)$mesSiguienteData['desperdicio_dosis'];
        
        $nuevoTotal = $existenciaFin + $dosisRecibidas;
        $nuevaExistenciaFin = $nuevoTotal - $dosisAdmin - $desperdicio;
        
        // Actualizar total_dosis y existencia_fin_dosis del mes siguiente
        $sqlUpdateTotales = "UPDATE biologico_canino SET 
                             total_dosis = ?,
                             existencia_fin_dosis = ?
                             WHERE jurisdiccion = ? AND anio = ? AND mes = ?";
        $stmtUpdateTotales = mysqli_prepare($conn, $sqlUpdateTotales);
        mysqli_stmt_bind_param($stmtUpdateTotales, 'iisii', 
            $nuevoTotal, 
            $nuevaExistenciaFin,
            $jurisdiccion, 
            $anio, 
            $mesSiguiente
        );
        mysqli_stmt_execute($stmtUpdateTotales);
        
        // Preparar para el siguiente mes en la cascada
        $existenciaFin = $nuevaExistenciaFin;
        
        // Obtener lote y caducidad actualizados para el siguiente mes
        $sqlSiguiente = "SELECT existencia_fin_lote, existencia_fin_caducidad FROM biologico_canino 
                         WHERE jurisdiccion = ? AND anio = ? AND mes = ?";
        $stmtSiguiente = mysqli_prepare($conn, $sqlSiguiente);
        mysqli_stmt_bind_param($stmtSiguiente, 'sii', $jurisdiccion, $anio, $mesSiguiente);
        mysqli_stmt_execute($stmtSiguiente);
        $datosSiguiente = mysqli_stmt_get_result($stmtSiguiente)->fetch_assoc();
        
        if ($datosSiguiente) {
            $lote = $datosSiguiente['existencia_fin_lote'];
            $caducidad = $datosSiguiente['existencia_fin_caducidad'];
        }
    }
}
