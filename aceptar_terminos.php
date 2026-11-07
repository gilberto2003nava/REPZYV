<?php
/**
 * Procesar aceptación de términos y condiciones
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

require_once __DIR__ . '/includes/conexion.php';

$usuario_id = $_SESSION['usuario_id'];

// Actualizar que el usuario aceptó los términos
$sql = "UPDATE usuarios SET terminos_aceptados = 1, fecha_terminos = NOW() WHERE id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $usuario_id);

if (mysqli_stmt_execute($stmt)) {
    // Registrar en bitácora
    $stmtBit = mysqli_prepare($conn, "INSERT INTO bitacora (usuario_id, accion, detalles, exito) VALUES (?, 'terminos', 'Aceptó términos y condiciones', 1)");
    if ($stmtBit) {
        mysqli_stmt_bind_param($stmtBit, 'i', $usuario_id);
        mysqli_stmt_execute($stmtBit);
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => mysqli_error($conn)]);
}
