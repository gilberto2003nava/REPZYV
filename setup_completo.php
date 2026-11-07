<?php
/**
 * Script de configuración completa para producción
 * Crea todas las tablas, vistas y procedimientos necesarios
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);

echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>";
echo "<title>Setup Completo - Producción</title>";
echo "<style>
body { font-family: Arial; padding: 20px; background: #f5f5f5; max-width: 1200px; margin: 0 auto; }
.success { color: green; font-weight: bold; }
.error { color: red; font-weight: bold; }
.warning { color: orange; }
.info { color: blue; }
h1 { background: #333; color: white; padding: 15px; border-radius: 5px; }
h2 { background: #666; color: white; padding: 10px; border-radius: 3px; margin-top: 20px; }
.step { background: white; padding: 15px; margin: 10px 0; border-left: 4px solid #4CAF50; }
</style></head><body>";

echo "<h1>🔧 Configuración Completa del Sistema</h1>";

require_once 'includes/conexion.php';

if (!$pdo) {
    die("<p class='error'>❌ Error: No se pudo conectar a la base de datos</p></body></html>");
}

echo "<p class='success'>✅ Conexión establecida correctamente</p>";

// PASO 1: Crear tabla bloqueos_usuarios
echo "<div class='step'>";
echo "<h2>Paso 1: Tabla bloqueos_usuarios</h2>";
try {
    $sql = "CREATE TABLE IF NOT EXISTS `bloqueos_usuarios` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `usuario_id` int(11) DEFAULT NULL,
      `email` varchar(100) DEFAULT NULL,
      `ip_address` varchar(45) NOT NULL,
      `intentos` int(11) NOT NULL DEFAULT 1,
      `bloqueado_hasta` datetime DEFAULT NULL,
      `fecha_primer_intento` datetime NOT NULL DEFAULT current_timestamp(),
      `fecha_ultimo_intento` datetime NOT NULL DEFAULT current_timestamp(),
      `desbloqueado_por` int(11) DEFAULT NULL,
      `fecha_desbloqueo` datetime DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_usuario_id` (`usuario_id`),
      KEY `idx_email` (`email`),
      KEY `idx_ip_address` (`ip_address`),
      KEY `idx_bloqueado_hasta` (`bloqueado_hasta`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    echo "<p class='success'>✅ Tabla bloqueos_usuarios creada/verificada</p>";
} catch (PDOException $e) {
    echo "<p class='warning'>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// PASO 2: Crear tabla accesos_temporales
echo "<div class='step'>";
echo "<h2>Paso 2: Tabla accesos_temporales</h2>";
try {
    $sql = "CREATE TABLE IF NOT EXISTS `accesos_temporales` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `usuario_id` int(11) NOT NULL,
      `fecha_inicio` datetime NOT NULL DEFAULT current_timestamp(),
      `fecha_fin` datetime NOT NULL,
      `motivo` text DEFAULT NULL,
      `otorgado_por` int(11) NOT NULL,
      `activo` tinyint(1) NOT NULL DEFAULT 1,
      `fecha_creacion` timestamp NOT NULL DEFAULT current_timestamp(),
      `fecha_modificacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_usuario_id` (`usuario_id`),
      KEY `idx_fecha_fin` (`fecha_fin`),
      KEY `idx_activo` (`activo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql);
    echo "<p class='success'>✅ Tabla accesos_temporales creada/verificada</p>";
} catch (PDOException $e) {
    echo "<p class='warning'>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// PASO 3: Agregar columnas a tabla usuarios
echo "<div class='step'>";
echo "<h2>Paso 3: Actualizar tabla usuarios</h2>";

$columnas = [
    "ADD COLUMN `activo` tinyint(1) NOT NULL DEFAULT 1 AFTER `telefono`",
    "ADD COLUMN `fecha_baja` datetime DEFAULT NULL AFTER `activo`",
    "ADD COLUMN `motivo_baja` text DEFAULT NULL AFTER `fecha_baja`",
    "ADD COLUMN `dado_baja_por` int(11) DEFAULT NULL AFTER `motivo_baja`",
    "ADD COLUMN `ultimo_acceso` datetime DEFAULT NULL AFTER `dado_baja_por`",
    "ADD COLUMN `intentos_fallidos` int(11) NOT NULL DEFAULT 0 AFTER `ultimo_acceso`",
    "ADD COLUMN `bloqueado_hasta` datetime DEFAULT NULL AFTER `intentos_fallidos`"
];

foreach ($columnas as $columna) {
    try {
        $pdo->exec("ALTER TABLE `usuarios` $columna");
        echo "<p class='success'>✅ Columna agregada</p>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "<p class='info'>ℹ️ Columna ya existe (OK)</p>";
        } else {
            echo "<p class='warning'>⚠️ " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}
echo "</div>";

// PASO 4: Crear vista v_usuarios_completo
echo "<div class='step'>";
echo "<h2>Paso 4: Vista v_usuarios_completo</h2>";
try {
    $sql = "CREATE OR REPLACE VIEW `v_usuarios_completo` AS
    SELECT 
        u.id,
        u.nombre,
        u.email,
        u.rol,
        u.jurisdiccion,
        u.telefono,
        u.activo,
        u.fecha_baja,
        u.motivo_baja,
        u.ultimo_acceso,
        u.intentos_fallidos,
        u.bloqueado_hasta,
        u.created_at,
        CASE 
            WHEN u.bloqueado_hasta IS NOT NULL AND u.bloqueado_hasta > NOW() THEN 'BLOQUEADO'
            WHEN u.activo = 0 THEN 'INACTIVO'
            WHEN EXISTS (
                SELECT 1 FROM accesos_temporales at 
                WHERE at.usuario_id = u.id 
                AND at.activo = 1 
                AND at.fecha_fin > NOW()
            ) THEN 'ACCESO_TEMPORAL'
            ELSE 'ACTIVO'
        END as estado,
        (SELECT COUNT(*) FROM bloqueos_usuarios WHERE usuario_id = u.id) as total_bloqueos,
        (SELECT COUNT(*) FROM accesos_temporales WHERE usuario_id = u.id) as total_accesos_temporales,
        (SELECT fecha_fin FROM accesos_temporales 
         WHERE usuario_id = u.id AND activo = 1 AND fecha_fin > NOW() 
         ORDER BY fecha_fin DESC LIMIT 1) as acceso_temporal_hasta
    FROM usuarios u";
    
    $pdo->exec($sql);
    echo "<p class='success'>✅ Vista v_usuarios_completo creada correctamente</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// PASO 5: Crear procedimientos almacenados
echo "<div class='step'>";
echo "<h2>Paso 5: Procedimientos Almacenados</h2>";

// Procedimiento 1
try {
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_limpiar_bloqueos_expirados");
    $sql = "CREATE PROCEDURE sp_limpiar_bloqueos_expirados()
    BEGIN
        UPDATE usuarios 
        SET bloqueado_hasta = NULL, intentos_fallidos = 0
        WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < NOW();
        
        UPDATE bloqueos_usuarios
        SET bloqueado_hasta = NULL
        WHERE bloqueado_hasta IS NOT NULL AND bloqueado_hasta < NOW();
    END";
    $pdo->exec($sql);
    echo "<p class='success'>✅ sp_limpiar_bloqueos_expirados creado</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Procedimiento 2
try {
    $pdo->exec("DROP PROCEDURE IF EXISTS sp_desactivar_accesos_expirados");
    $sql = "CREATE PROCEDURE sp_desactivar_accesos_expirados()
    BEGIN
        UPDATE accesos_temporales
        SET activo = 0
        WHERE activo = 1 AND fecha_fin < NOW();
    END";
    $pdo->exec($sql);
    echo "<p class='success'>✅ sp_desactivar_accesos_expirados creado</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// PASO 6: Verificación final
echo "<div class='step'>";
echo "<h2>Paso 6: Verificación Final</h2>";

// Verificar vista
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM v_usuarios_completo");
    $result = $stmt->fetch();
    echo "<p class='success'>✅ Vista v_usuarios_completo funciona correctamente ({$result['total']} usuarios)</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Vista no funciona: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Verificar procedimientos
try {
    $pdo->exec("CALL sp_limpiar_bloqueos_expirados()");
    echo "<p class='success'>✅ sp_limpiar_bloqueos_expirados funciona</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

try {
    $pdo->exec("CALL sp_desactivar_accesos_expirados()");
    echo "<p class='success'>✅ sp_desactivar_accesos_expirados funciona</p>";
} catch (PDOException $e) {
    echo "<p class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "</div>";

echo "<hr>";
echo "<h2 style='color: green;'>✅ Configuración Completada</h2>";
echo "<p><strong>Siguiente paso:</strong> <a href='admin_usuarios.php' style='font-size: 18px; color: blue;'>Ir a Admin Usuarios</a></p>";
echo "<p><a href='test_conexion.php'>Ver diagnóstico completo</a> | <a href='inicio.php'>Ir al inicio</a></p>";

echo "</body></html>";
?>
