<?php
echo "<h1>Test de Archivos en Producción</h1>";
echo "<p>Directorio actual: " . __DIR__ . "</p>";
echo "<hr>";

$archivos = [
    'verificar_codigo.php',
    'olvide_contrasena.php',
    'inicio.php',
    'panel.php',
    'admin_usuarios.php',
    'includes/conexion.php',
    'includes/funciones.php',
    'includes/security.php'
];

echo "<h2>Verificando archivos:</h2>";
echo "<ul>";
foreach ($archivos as $archivo) {
    $existe = file_exists($archivo);
    $color = $existe ? 'green' : 'red';
    $icono = $existe ? '✅' : '❌';
    echo "<li style='color: $color;'>$icono $archivo</li>";
}
echo "</ul>";

echo "<hr>";
echo "<h2>URL Actual:</h2>";
echo "<p>" . $_SERVER['REQUEST_URI'] . "</p>";
echo "<p>Host: " . $_SERVER['HTTP_HOST'] . "</p>";
echo "<p>Script: " . $_SERVER['SCRIPT_NAME'] . "</p>";

echo "<hr>";
echo "<h2>Enlaces de prueba:</h2>";
echo "<p><a href='verificar_codigo.php?email=test@test.com'>Ir a verificar_codigo.php</a></p>";
echo "<p><a href='olvide_contrasena.php'>Ir a olvide_contrasena.php</a></p>";
echo "<p><a href='inicio.php'>Ir a inicio.php</a></p>";
?>
