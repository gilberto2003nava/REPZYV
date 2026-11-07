<?php

/**
 * CRUD Enfermedad de Chagas e Intoxicación por Artrópodos
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
    header('Location: chagas_artropodos.php');
    exit;
}

$rol = $_SESSION['rol'];
$usuario_id = $_SESSION['usuario_id'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';
$esAdmin = ($rol === 'admin');

$accion = $_POST['accion'] ?? '';
$anio = (int)($_POST['anio'] ?? date('Y'));

// URL de redirección
$redirect = "chagas_artropodos.php?anio=$anio";

/**
 * Recalcula los remanentes de una jurisdicción para un año específico
 */
function recalcularRemanentesChagas($conn, $jurisdiccion, $anio)
{
    // Obtener remanente del año anterior (diciembre)
    $remanentePrevio = 0;
    $anioAnt = $anio - 1;
    $stmt = mysqli_prepare($conn, "SELECT remanente FROM chagas_registro WHERE jurisdiccion = ? AND anio = ? AND mes = 12");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'si', $jurisdiccion, $anioAnt);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        if ($row = mysqli_fetch_assoc($res)) {
            $remanentePrevio = (int)$row['remanente'];
        }
        mysqli_stmt_close($stmt);
    }

    // Recalcular todos los meses del año en orden
    for ($mes = 1; $mes <= 12; $mes++) {
        $stmt = mysqli_prepare($conn, "SELECT id, entregado, utilizado FROM chagas_registro WHERE jurisdiccion = ? AND anio = ? AND mes = ?");
        mysqli_stmt_bind_param($stmt, 'sii', $jurisdiccion, $anio, $mes);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        if ($row = mysqli_fetch_assoc($res)) {
            $entregado = (int)$row['entregado'];
            $utilizado = (int)$row['utilizado'];
            $remanente = $remanentePrevio + $entregado - $utilizado;

            $upd = mysqli_prepare($conn, "UPDATE chagas_registro SET remanente = ? WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'ii', $remanente, $row['id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

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
    $observaciones = $_POST['observaciones'] ?? '';

    // Validar permisos
    if (!$esAdmin && $jurisdiccion !== $jurisdiccion_usuario) {
        $_SESSION['error'] = 'No tienes permiso para esta jurisdicción';
        header("Location: $redirect");
        exit;
    }

    // Reglas de negocio por rol
    if ($esAdmin) {
        $utilizado = 0;       // Admins NO capturan utilización
        $observaciones = '';   // Admins NO capturan observaciones
    } else {
        $entregado = 0;       // Capturistas NO capturan entregas

        // Validación de secuencia para capturistas
        $stmt_seq = mysqli_prepare($conn, "SELECT MAX(mes) as max_mes FROM chagas_registro WHERE jurisdiccion = ? AND anio = ?");
        mysqli_stmt_bind_param($stmt_seq, 'si', $jurisdiccion, $anio);
        mysqli_stmt_execute($stmt_seq);
        $res_seq = mysqli_stmt_get_result($stmt_seq);
        $row_seq = mysqli_fetch_assoc($res_seq);
        $max_mes_actual = $row_seq['max_mes'] ? (int)$row_seq['max_mes'] : 0;
        mysqli_stmt_close($stmt_seq);

        if ($mes !== ($max_mes_actual + 1)) {
            $_SESSION['error'] = 'Debes registrar los meses en orden secuencial. El siguiente mes a registrar es: ' . ($max_mes_actual + 1);
            header("Location: $redirect");
            exit;
        }
    }

    // Verificar duplicado
    $stmt = mysqli_prepare($conn, "SELECT id FROM chagas_registro WHERE jurisdiccion = ? AND anio = ? AND mes = ?");
    mysqli_stmt_bind_param($stmt, 'sii', $jurisdiccion, $anio, $mes);
    mysqli_stmt_execute($stmt);
    if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0) {
        $_SESSION['error'] = 'Ya existe un registro para ese mes. Usa editar.';
        header("Location: $redirect");
        exit;
    }
    mysqli_stmt_close($stmt);

    // Insertar
    $stmt = mysqli_prepare($conn, "INSERT INTO chagas_registro (jurisdiccion, anio, mes, entregado, utilizado, observaciones, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'siiiisi', $jurisdiccion, $anio, $mes, $entregado, $utilizado, $observaciones, $usuario_id);

    if (mysqli_stmt_execute($stmt)) {
        recalcularRemanentesChagas($conn, $jurisdiccion, $anio);
        $_SESSION['mensaje'] = 'Registro creado correctamente';
        registrarAccion($conn, $usuario_id, 'crear_chagas', "Jur: $jurisdiccion, Mes: $mes", 1);
    } else {
        $_SESSION['error'] = 'Error al crear registro: ' . mysqli_error($conn);
    }
}

// ========== EDITAR ==========
elseif ($accion === 'editar') {
    $id = (int)($_POST['id'] ?? 0);
    $jurisdiccion = $_POST['jurisdiccion'] ?? '';
    $mes = (int)($_POST['mes'] ?? 0);
    $entregado_post = (int)($_POST['entregado'] ?? 0);
    $utilizado_post = (int)($_POST['utilizado'] ?? 0);
    $observaciones_post = $_POST['observaciones'] ?? '';

    if (!$esAdmin && $jurisdiccion !== $jurisdiccion_usuario) {
        $_SESSION['error'] = 'No tienes permiso para esta jurisdicción';
        header("Location: $redirect");
        exit;
    }

    // Obtener valores actuales para mantener los campos protegidos
    $stmt = mysqli_prepare($conn, "SELECT entregado, utilizado, observaciones FROM chagas_registro WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $reg_actual = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$reg_actual) {
        $_SESSION['error'] = 'Registro no encontrado';
        header("Location: $redirect");
        exit;
    }

    if ($esAdmin) {
        // Admin solo cambia entregado, mantiene utilizado y observaciones
        $entregado = $entregado_post;
        $utilizado = (int)$reg_actual['utilizado'];
        $observaciones = $reg_actual['observaciones'];
    } else {
        // Capturista solo cambia utilizado y observaciones, mantiene entregado
        $entregado = (int)$reg_actual['entregado'];
        $utilizado = $utilizado_post;
        $observaciones = $observaciones_post;
    }

    $stmt = mysqli_prepare($conn, "UPDATE chagas_registro SET mes = ?, entregado = ?, utilizado = ?, observaciones = ?, usuario_id = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'iiiisi', $mes, $entregado, $utilizado, $observaciones, $usuario_id, $id);

    if (mysqli_stmt_execute($stmt)) {
        recalcularRemanentesChagas($conn, $jurisdiccion, $anio);
        $_SESSION['mensaje'] = 'Registro actualizado correctamente';
        registrarAccion($conn, $usuario_id, 'editar_chagas', "ID: $id", 1);
    } else {
        $_SESSION['error'] = 'Error al actualizar registro';
    }
}

// ========== ELIMINAR ==========
elseif ($accion === 'eliminar') {
    if (!$esAdmin) {
        $_SESSION['error'] = 'Solo el administrador puede eliminar registros';
        header("Location: $redirect");
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);

    $stmt = mysqli_prepare($conn, "SELECT jurisdiccion FROM chagas_registro WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);
    mysqli_stmt_execute($stmt);
    $reg = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$reg || (!$esAdmin && $reg['jurisdiccion'] !== $jurisdiccion_usuario)) {
        $_SESSION['error'] = 'No autorizado o registro no encontrado';
        header("Location: $redirect");
        exit;
    }

    $jurisdiccion = $reg['jurisdiccion'];
    $stmt = mysqli_prepare($conn, "DELETE FROM chagas_registro WHERE id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $id);

    if (mysqli_stmt_execute($stmt)) {
        recalcularRemanentesChagas($conn, $jurisdiccion, $anio);
        $_SESSION['mensaje'] = 'Registro eliminado correctamente';
        registrarAccion($conn, $usuario_id, 'eliminar_chagas', "ID: $id", 1);
    } else {
        $_SESSION['error'] = 'Error al eliminar registro';
    }
}

header("Location: $redirect");
exit;
