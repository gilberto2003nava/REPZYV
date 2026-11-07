<?php
/**
 * Script de configuración de base de datos para producción
 * Ejecuta todos los scripts SQL necesarios
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300); // 5 minutos

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Setup Database</title>";
echo "<style>body{font-family:Arial;padding:20px;background:#f5f5f5;}";
echo ".success{color:green;}.error{color:red;}.warning{color:orange;}</style></head><body>";

echo "<h1>🔧 Configuración de Base de Datos - Producción</h1>";

require_once 'includes/conexion.php';

if (!$pdo) {
    die("<p class='error'>❌ Error: No se pudo conectar a la base de datos</p></body></html>");
}

echo "<p class='success'>✅ Conexión establecida correctamente</p>";

// Lista de archivos SQL a ejecutar en orden
$sql_files = [
    'sql/gestion_usuarios.sql' => 'Gestión de Usuarios (vistas y procedimientos)',
    'sql/terminos_condiciones.sql' => 'Términos y Condiciones',
    'sql/terminos_usuarios.sql' => 'Relación Usuarios-Términos',
    'sql/biologico_canino.sql' => 'Biológico Canino',
    'sql/material_curacion.sql' => 'Material de Curación',
    'sql/recetas_biologico.sql' => 'Recetas Biológico',
    'sql/fix_bloqueos_usuarios.sql' => 'Fix Bloqueos de Usuarios'
];

echo "<h2>Ejecutando Scripts SQL...</h2>";

foreach ($sql_files as $file => $description) {
    echo "<h3>📄 $description</h3>";
    
    if (!file_exists($file)) {
        echo "<p class='warning'>⚠️ Archivo no encontrado: $file</p>";
        continue;
    }
    
    $sql_content = file_get_contents($file);
    
    if (empty($sql_content)) {
        echo "<p class='warning'>⚠️ Archivo vacío: $file</p>";
        continue;
    }
    
    // Dividir por delimitadores y punto y coma
    $statements = [];
    $current = '';
    $in_delimiter = false;
    
    foreach (explode("\n", $sql_content) as $line) {
        // Detectar cambio de delimitador
        if (preg_match('/^DELIMITER\s+(.+)$/i', trim($line), $matches)) {
            if ($current) {
                $statements[] = $current;
                $current = '';
            }
            $in_delimiter = ($matches[1] !== ';');
            continue;
        }
        
        $current .= $line . "\n";
        
        // Si no estamos en delimitador especial, dividir por ;
        if (!$in_delimiter && preg_match('/;\s*$/', $line)) {
            $statements[] = $current;
            $current = '';
        }
    }
    
    if ($current) {
        $statements[] = $current;
    }
    
    $success = 0;
    $errors = 0;
    
    foreach ($statements as $statement) {
        $statement = trim($statement);
        
        // Ignorar comentarios y líneas vacías
        if (empty($statement) || 
            strpos($statement, '--') === 0 || 
            strpos($statement, '/*') === 0 ||
            strtoupper($statement) === 'DELIMITER') {
            continue;
        }
        
        try {
            $pdo->exec($statement);
            $success++;
        } catch (PDOException $e) {
            // Ignorar errores de "ya existe"
            if (strpos($e->getMessage(), 'already exists') !== false ||
                strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "<p class='warning'>⚠️ Ya existe (ignorado)</p>";
            } else {
                echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
                $errors++;
            }
        }
    }
    
    if ($success > 0) {
        echo "<p class='success'>✅ Ejecutadas $success sentencias correctamente</p>";
    }
    if ($errors > 0) {
        echo "<p class='error'>❌ $errors errores encontrados</p>";
    }
}

echo "<hr>";
echo "<h2>✅ Proceso Completado</h2>";
echo "<p><a href='test_conexion.php'>Ver diagnóstico</a> | ";
echo "<a href='admin_usuarios.php'>Ir a Admin Usuarios</a> | ";
echo "<a href='inicio.php'>Ir al inicio</a></p>";

echo "</body></html>";
?>
