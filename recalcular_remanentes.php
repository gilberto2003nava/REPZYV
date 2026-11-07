<?php
/**
 * Script para recalcular todos los remanentes de material de curación
 * Ejecutar una vez para corregir datos existentes
 */

require_once __DIR__ . '/includes/conexion.php';

echo "<pre>";
echo "=== RECALCULANDO REMANENTES ===\n\n";

// Obtener todas las combinaciones únicas de insumo/jurisdiccion/anio
$sql = "SELECT DISTINCT insumo_id, jurisdiccion, anio FROM material_registro ORDER BY insumo_id, jurisdiccion, anio";
$result = mysqli_query($conn, $sql);

$total = 0;
while ($row = mysqli_fetch_assoc($result)) {
    $insumo_id = $row['insumo_id'];
    $jurisdiccion = $row['jurisdiccion'];
    $anio = $row['anio'];
    
    echo "Procesando: Insumo $insumo_id, $jurisdiccion, $anio\n";
    
    // Obtener remanente del año anterior
    $remanentePrevio = 0;
    $anioAnt = $anio - 1;
    $stmt = mysqli_prepare($conn, "SELECT remanente FROM material_registro WHERE insumo_id = ? AND jurisdiccion = ? AND anio = ? AND mes = 12");
    mysqli_stmt_bind_param($stmt, 'isi', $insumo_id, $jurisdiccion, $anioAnt);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    if ($r = mysqli_fetch_assoc($res)) {
        $remanentePrevio = (int)$r['remanente'];
    }
    mysqli_stmt_close($stmt);
    
    // Recalcular cada mes
    for ($mes = 1; $mes <= 12; $mes++) {
        $stmt = mysqli_prepare($conn, "SELECT id, entregado, utilizado FROM material_registro WHERE insumo_id = ? AND jurisdiccion = ? AND anio = ? AND mes = ?");
        mysqli_stmt_bind_param($stmt, 'isii', $insumo_id, $jurisdiccion, $anio, $mes);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        
        if ($r = mysqli_fetch_assoc($res)) {
            $entregado = (int)$r['entregado'];
            $utilizado = (int)$r['utilizado'];
            
            // Disponible = remanente anterior + entregado
            $disponible = $remanentePrevio + $entregado;
            // Remanente = disponible - utilizado
            $remanente = $disponible - $utilizado;
            
            // Actualizar
            $upd = mysqli_prepare($conn, "UPDATE material_registro SET remanente = ? WHERE id = ?");
            mysqli_stmt_bind_param($upd, 'ii', $remanente, $r['id']);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);
            
            echo "  Mes $mes: Entregado=$entregado, Disponible=$disponible, Utilizado=$utilizado, Remanente=$remanente\n";
            
            $remanentePrevio = $remanente;
            $total++;
        }
        mysqli_stmt_close($stmt);
    }
    echo "\n";
}

echo "=== COMPLETADO ===\n";
echo "Registros actualizados: $total\n";
echo "</pre>";

echo "<br><a href='material_curacion.php'>Volver a Material de Curación</a>";
