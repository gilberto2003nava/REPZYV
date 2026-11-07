<?php

/**
 * DIAGNÓSTICO DE COMPATIBILIDAD - CHAGAS
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Diagnóstico de Módulo Chagas</h1>";

// 1. Verificar Conexión
echo "<h3>1. Conexión a Base de Datos</h3>";
require_once __DIR__ . '/includes/conexion.php';

if (!$conn) {
    echo "<p style='color:red'>❌ Error de conexión mysqli: " . mysqli_connect_error() . "</p>";
} else {
    echo "<p style='color:green'>✅ Conexión mysqli exitosa.</p>";

    // 2. Verificar Tabla
    echo "<h3>2. Verificando Tabla 'chagas_registro'</h3>";
    $res = mysqli_query($conn, "SHOW TABLES LIKE 'chagas_registro'");
    if (mysqli_num_rows($res) == 0) {
        echo "<p style='color:red'>❌ La tabla 'chagas_registro' NO EXISTE.</p>";
        echo "<pre>
EJECUTA ESTE SQL EN PHPMYADMIN:

CREATE TABLE `chagas_registro` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `jurisdiccion` varchar(50) NOT NULL,
  `anio` int(11) NOT NULL,
  `mes` tinyint(4) NOT NULL,
  `entregado` int(11) DEFAULT 0,
  `utilizado` int(11) DEFAULT 0,
  `remanente` int(11) DEFAULT 0,
  `observaciones` text DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha_captura` datetime DEFAULT current_timestamp(),
  `fecha_actualizacion` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_jur_anio_mes` (`jurisdiccion`, `anio`, `mes`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        </pre>";
    } else {
        echo "<p style='color:green'>✅ La tabla 'chagas_registro' existe correctamente.</p>";
    }
}

// 3. Verificar Vendor / PhpSpreadsheet
echo "<h3>3. Verificando Autoload / PhpSpreadsheet</h3>";
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "<p style='color:green'>✅ vendor/autoload.php existe.</p>";
    require_once __DIR__ . '/vendor/autoload.php';
    if (class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
        echo "<p style='color:green'>✅ PhpSpreadsheet está cargado correctamente.</p>";
    } else {
        echo "<p style='color:red'>❌ PhpSpreadsheet NO se encontró en vendor.</p>";
    }
} else {
    echo "<p style='color:red'>❌ vendor/autoload.php NO existe en la carpeta produccion/.</p>";
}

// 4. Versión de PHP
echo "<h3>4. Información del Servidor</h3>";
echo "<p>Versión PHP: " . PHP_VERSION . "</p>";
if (version_compare(PHP_VERSION, '8.0.0', '>=')) {
    echo "<p style='color:blue'>ℹ️ PHP 8 Detectado. mysqli_prepare fallará si la tabla no existe.</p>";
}

echo "<hr><p>Si la tabla no existía, créala y vuelve a intentar cargar el módulo.</p>";
