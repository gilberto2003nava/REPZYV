<?php
// Script de diagnóstico para verificar la conexión y estructura de BD

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Diagnóstico de Conexión - Producción</h2>";

// Test 1: Conexión básica
echo "<h3>1. Test de Conexión</h3>";
require_once 'includes/conexion.php';

if ($pdo) {
    echo "✅ PDO conectado correctamente<br>";
} else {
    echo "❌ PDO no conectado<br>";
}

if ($conn) {
    echo "✅ MySQLi conectado correctamente<br>";
} else {
    echo "❌ MySQLi no conectado<br>";
}

// Test 2: Verificar tablas
echo "<h3>2. Verificar Tablas Existentes</h3>";
try {
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tablas encontradas: " . count($tables) . "<br>";
    echo "<ul>";
    foreach ($tables as $table) {
        echo "<li>$table</li>";
    }
    echo "</ul>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 3: Verificar vista v_usuarios_completo
echo "<h3>3. Verificar Vista v_usuarios_completo</h3>";
try {
    $stmt = $pdo->query("SELECT * FROM v_usuarios_completo LIMIT 1");
    echo "✅ Vista v_usuarios_completo existe<br>";
} catch (Exception $e) {
    echo "❌ Vista no existe: " . $e->getMessage() . "<br>";
}

// Test 4: Verificar procedimientos almacenados
echo "<h3>4. Verificar Procedimientos Almacenados</h3>";
try {
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = 'u543028883_zoonosis'");
    $procedures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($procedures) > 0) {
        echo "Procedimientos encontrados: " . count($procedures) . "<br>";
        foreach ($procedures as $proc) {
            echo "- " . $proc['Name'] . "<br>";
        }
    } else {
        echo "⚠️ No hay procedimientos almacenados<br>";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

// Test 5: Verificar tabla usuarios
echo "<h3>5. Verificar Tabla usuarios</h3>";
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM usuarios");
    $result = $stmt->fetch();
    echo "✅ Tabla usuarios existe con " . $result['total'] . " registros<br>";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "<br>";
}

echo "<hr>";
echo "<p><a href='inicio.php'>Volver al inicio</a></p>";
?>
