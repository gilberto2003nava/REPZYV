<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

header('Content-Type: application/json; charset=utf-8');

// Verificar autenticación
if (!isset($_SESSION['usuario_id']) || (isset($_SESSION['rol']) && $_SESSION['rol'] !== 'admin')) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$loteId = isset($_GET['lote_id']) ? $_GET['lote_id'] : '';

if (empty($loteId)) {
    echo json_encode(['success' => false, 'error' => 'Lote ID requerido']);
    exit;
}

try {
    // Obtener datos del lote
    $sql = "SELECT 
                lote_id,
                jurisdiccion,
                tipo_insumo,
                MIN(folio) as folio_inicio,
                MAX(folio) as folio_fin,
                COUNT(*) as num_recetas,
                MAX(fecha_emision) as fecha_emision,
                SUM(cantidad_frascos) as cantidad_total_frascos,
                (SELECT receptor_nombre FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as receptor_nombre,
                (SELECT receptor_cedula FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as receptor_cedula,
                (SELECT producto FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as producto,
                (SELECT direccion FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as direccion
            FROM recetas_biologico r
            WHERE lote_id = ?
            GROUP BY lote_id
            LIMIT 1";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $loteId);
    if (!mysqli_stmt_execute($stmt)) {
        throw new Exception("Error al ejecutar consulta: " . mysqli_error($conn));
    }

    $result = mysqli_stmt_get_result($stmt);

    if ($row = mysqli_fetch_assoc($result)) {
        // Calcular cantidad total segura
        $cantTotal = isset($row['cantidad_total_frascos']) ? (int)$row['cantidad_total_frascos'] : 20;

        echo json_encode([
            'success' => true,
            'data' => [
                'lote_id' => $row['lote_id'],
                'folio_inicio' => $row['folio_inicio'],
                'folio_fin' => $row['folio_fin'],
                'num_recetas' => $row['num_recetas'],
                'jurisdiccion' => $row['jurisdiccion'],
                'receptor_nombre' => isset($row['receptor_nombre']) ? $row['receptor_nombre'] : '',
                'receptor_cedula' => isset($row['receptor_cedula']) ? $row['receptor_cedula'] : '',
                'producto' => isset($row['producto']) ? $row['producto'] : '',
                'fecha_emision' => isset($row['fecha_emision']) ? $row['fecha_emision'] : '',
                'tipo_insumo' => isset($row['tipo_insumo']) ? $row['tipo_insumo'] : 'GENERAL',
                'direccion' => isset($row['direccion']) ? $row['direccion'] : '',
                'cantidad_frascos' => $cantTotal
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Lote no encontrado']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

if (isset($conn) && $conn) {
    mysqli_close($conn);
}
