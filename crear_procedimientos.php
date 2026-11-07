<?php
/**
 * Script para crear procedimientos almacenados en producción
 * Ejecutar una sola vez desde el navegador
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>";
echo "<title>Crear Procedimientos</title>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; }
.success { color: green; font-weight: bold; }
.error { color: red; font-weight: bold; }
.info { color: blue; }
pre { background: #fff; padding: 10px; border: 1px solid #ddd; }
</style></head><body>";

echo "<h1>🔧 Crear Procedimientos Almacenados</h1>";

require_once 'includes/conexion.php';

if (!$pdo) {
    die("<p class='error'>❌ Error: No se pudo conectar a la base de datos</p></body></html>");
}

echo "<p class='success'>✅ Conexión establecida</p>";

// Procedimiento 1: sp_desactivar_accesos_expirados
echo "<h2>1. Creando sp_desactivar_accesos_expirados</h2>";

try {
    // Eliminar si existe
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_desactivar_accesos_expirados");
    
    // Crear procedimiento
    $sql1 = "
    CREATE PROCEDURE sp_desactivar_accesos_expirados()
    BEGIN
        UPDATE accesos_temporales
        SET activo = 0
        WHERE activo = 1 
          AND fecha_fin < NOW();
    END";
    
    $pdo->exec($sql1);
    echo "<p class='success'>✅ Procedimiento sp_desactivar_accesos_expirados creado correctamente</p>";
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Procedimiento 2: sp_limpiar_bloqueos_expirados
echo "<h2>2. Creando sp_limpiar_bloqueos_expirados</h2>";

try {
    // Eliminar si existe
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_limpiar_bloqueos_expirados");
    
    // Crear procedimiento
    $sql2 = "
    CREATE PROCEDURE sp_limpiar_bloqueos_expirados()
    BEGIN
        UPDATE usuarios 
        SET bloqueado_hasta = NULL, 
            intentos_fallidos = 0
        WHERE bloqueado_hasta IS NOT NULL 
          AND bloqueado_hasta < NOW();
        
        UPDATE bloqueos_usuarios
        SET bloqueado_hasta = NULL
        WHERE bloqueado_hasta IS NOT NULL 
          AND bloqueado_hasta < NOW();
    END";
    
    $pdo->exec($sql2);
    echo "<p class='success'>✅ Procedimiento sp_limpiar_bloqueos_expirados creado correctamente</p>";
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Verificar procedimientos creados
echo "<h2>3. Verificando Procedimientos</h2>";

try {
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = 'u543028883_zoonosis'");
    $procedures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($procedures) > 0) {
        echo "<p class='success'>✅ Procedimientos encontrados: " . count($procedures) . "</p>";
        echo "<ul>";
        foreach ($procedures as $proc) {
            echo "<li class='info'>" . htmlspecialchars($proc['Name']) . " (Tipo: " . $proc['Type'] . ")</li>";
        }
        echo "</ul>";
    } else {
        echo "<p class='error'>⚠️ No se encontraron procedimientos</p>";
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error al verificar: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Probar los procedimientos
echo "<h2>4. Probando Procedimientos</h2>";

try {
    echo "<p class='info'>Ejecutando sp_limpiar_bloqueos_expirados()...</p>";
    $pdo->exec("CALL sp_limpiar_bloqueos_expirados()");
    echo "<p class='success'>✅ sp_limpiar_bloqueos_expirados ejecutado correctamente</p>";
    
    echo "<p class='info'>Ejecutando sp_desactivar_accesos_expirados()...</p>";
    $pdo->exec("CALL sp_desactivar_accesos_expirados()");
    echo "<p class='success'>✅ sp_desactivar_accesos_expirados ejecutado correctamente</p>";
    
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error al ejecutar: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<h2>✅ Proceso Completado</h2>";
echo "<p><strong>Siguiente paso:</strong> Intenta acceder a <a href='admin_usuarios.php'>admin_usuarios.php</a></p>";
echo "<p><a href='test_conexion.php'>Ver diagnóstico completo</a></p>";

echo "</body></html>";
?>
