<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$tipo = $_GET['tipo'] ?? '';

try {
    if ($tipo === 'receptores') {
        // Obtener receptores únicos con sus cédulas
        $sql = "SELECT DISTINCT receptor_nombre, receptor_cedula 
                FROM recetas_biologico 
                WHERE receptor_nombre IS NOT NULL 
                AND receptor_nombre != '' 
                AND receptor_cedula IS NOT NULL 
                AND receptor_cedula != ''
                ORDER BY receptor_nombre";
        
        $result = mysqli_query($conn, $sql);
        $receptores = [];
        
        while ($row = mysqli_fetch_assoc($result)) {
            $receptores[] = [
                'nombre' => $row['receptor_nombre'],
                'cedula' => $row['receptor_cedula']
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $receptores]);
        
    } elseif ($tipo === 'productos') {
        // Obtener productos únicos
        $sql = "SELECT DISTINCT producto 
                FROM recetas_biologico 
                WHERE producto IS NOT NULL 
                AND producto != ''
                ORDER BY producto";
        
        $result = mysqli_query($conn, $sql);
        $productos = [];
        
        while ($row = mysqli_fetch_assoc($result)) {
            $productos[] = $row['producto'];
        }
        
        echo json_encode(['success' => true, 'data' => $productos]);
        
    } else {
        echo json_encode(['success' => false, 'error' => 'Tipo no válido']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

mysqli_close($conn);
?>
