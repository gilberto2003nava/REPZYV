<?php
include 'includes/conexion.php';

header('Content-Type: text/plain');

echo "--- Debugging Folio Data ---\n";

$sql = "SELECT id, lote_id, folio, tipo_insumo, anio, LENGTH(tipo_insumo) as len_tipo, HEX(tipo_insumo) as hex_tipo 
        FROM recetas_biologico 
        WHERE folio BETWEEN 1 AND 10 
        ORDER BY folio ASC";

$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        print_r($row);
    }
} else {
    echo "Error: " . mysqli_error($conn);
}
