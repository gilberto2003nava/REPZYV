<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

// Headers para evitar caché - IMPORTANTE para seguridad
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 01 Jan 2000 00:00:00 GMT");

// Registrar logout en bitácora antes de destruir la sesión
if (isset($_SESSION['usuario_id'])) {
    $usuario_id = $_SESSION['usuario_id'];
    $stmt = mysqli_prepare($conn, "INSERT INTO bitacora (usuario_id, accion, detalles, exito) VALUES (?, 'logout', 'Cierre de sesión', 1)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $usuario_id);
        mysqli_stmt_execute($stmt);
    }
}

// Limpiar todas las variables de sesión
$_SESSION = array();

// Eliminar cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// NO eliminar cookie "remember_credentials" - solo guarda el email para autocompletar
// El usuario puede desmarcar el checkbox si no quiere que se recuerde

// Destruir la sesión
session_destroy();

// Redireccionar al login
header('Location: inicio.php');
exit;
?>
