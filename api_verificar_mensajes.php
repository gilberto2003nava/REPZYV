<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/conexion.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$usuario_id = $_SESSION['usuario_id'];

// Obtener el último mensaje no leído para notificar
$sql = "SELECT m.*, u.nombre as remitente_nombre 
        FROM mensajes m 
        JOIN usuarios u ON m.remitente_id = u.id 
        WHERE m.destinatario_id = ? AND m.leido = 0 
        ORDER BY m.fecha_envio DESC LIMIT 1";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $usuario_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if ($msg = mysqli_fetch_assoc($result)) {
    echo json_encode([
        'new_messages' => true,
        'id' => $msg['id'],
        'remitente' => $msg['remitente_nombre'],
        'mensaje' => mb_strimwidth($msg['mensaje'], 0, 50, "..."),
        'remitente_id' => $msg['remitente_id']
    ]);
} else {
    echo json_encode(['new_messages' => false]);
}
