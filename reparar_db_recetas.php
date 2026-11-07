<?php

/**
 * SCRIPT DE REPARACIÓN Y DIAGNÓSTICO
 * Ejecute esto para corregir el ID y ver qué está pasando con las cantidades.
 */
require_once __DIR__ . '/includes/conexion.php';

header('Content-Type: text/plain; charset=utf-8');

echo "--- INICIANDO REPARACIÓN DE TABLA recetas_biologico ---\n\n";

if (!$conn) {
    die("Error de conexión: " . mysqli_connect_error());
}

// 1. VERIFICAR ESTRUCTURA ACTUAL
echo "1. Estructura actual de 'id':\n";
$res = mysqli_query($conn, "SHOW COLUMNS FROM recetas_biologico LIKE 'id'");
$col = mysqli_fetch_assoc($res);
print_r($col);

// 2. APLICAR FIX DE AUTO_INCREMENT
echo "\n2. Aplicando AUTO_INCREMENT y PRIMARY KEY...\n";
mysqli_query($conn, "ALTER TABLE recetas_biologico MODIFY id INT NOT NULL"); // Quitar temporalmente para limpiar
mysqli_query($conn, "ALTER TABLE recetas_biologico DROP PRIMARY KEY"); // Por si acaso
mysqli_query($conn, "ALTER TABLE recetas_biologico ADD PRIMARY KEY (id)");
if (mysqli_query($conn, "ALTER TABLE recetas_biologico MODIFY id INT NOT NULL AUTO_INCREMENT")) {
    echo "✓ AUTO_INCREMENT activado satisfactoriamente.\n";
} else {
    echo "✗ Error al activar AUTO_INCREMENT: " . mysqli_error($conn) . "\n";
    echo "Intentando limpiar IDs duplicados primero...\n";
    mysqli_query($conn, "SET @count = 0; UPDATE recetas_biologico SET id = (@count := @count + 1);");
    if (mysqli_query($conn, "ALTER TABLE recetas_biologico MODIFY id INT NOT NULL AUTO_INCREMENT")) {
        echo "✓ AUTO_INCREMENT activado tras limpieza de IDs.\n";
    }
}

// 3. BUSCAR TRIGGERS OCULTOS
echo "\n3. Buscando TRIGGERS que afecten a esta tabla:\n";
$resTrig = mysqli_query($conn, "SHOW TRIGGERS LIKE 'recetas_biologico'");
$found = false;
while ($trig = mysqli_fetch_assoc($resTrig)) {
    echo "ADVERTENCIA: Se encontró un TRIGGER: " . $trig['Trigger'] . "\n";
    echo "Evento: " . $trig['Event'] . "\n";
    echo "Sentencia: " . $trig['Statement'] . "\n\n";
    $found = true;
}
if (!$found) echo "No se encontraron triggers para esta tabla.\n";

// 4. VERIFICAR ÚLTIMO FOLIO
echo "\n4. Verificando estado de folios para 2026:\n";
$resFolio = mysqli_query($conn, "SELECT MAX(folio) as maxF FROM recetas_biologico WHERE anio = 2026");
$rowF = mysqli_fetch_assoc($resFolio);
echo "Folio más alto en 2026: " . ($rowF['maxF'] ?? 0) . "\n";

echo "\n--- PROCESO FINALIZADO ---\n";
echo "Por favor, ejecute de nuevo la generación de recetas y verifique si el problema persiste.";
