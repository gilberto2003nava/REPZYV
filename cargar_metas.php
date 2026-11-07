<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';

// Cargar año activo del config
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

$juris = $_GET['jurisdiccion'] ?? '';
$muni  = $_GET['municipio'] ?? '';
$tipo  = $_GET['tipo'] ?? '';
$anio  = $anio_activo; // Usar año activo del sistema
header('Content-Type: application/json; charset=utf-8');

// Consultar metas incluyendo municipio y nombre del usuario creador
$sql = "SELECT m.id, m.meta, m.anio, m.municipio, m.creada_por, u.nombre AS creada_por_nombre, m.created_at
		FROM metas m
		LEFT JOIN usuarios u ON u.id = m.creada_por
		WHERE m.jurisdiccion=? AND m.municipio=? AND m.tipo=? AND m.anio=?";

$stmt = mysqli_prepare($conn, $sql);
if (!$stmt) {
	// Preparación falló: registrar y devolver array vacío para evitar errores en el front
	error_log('[cargar_metas.php] mysqli_prepare failed: ' . mysqli_error($conn));
	echo json_encode([]);
	exit;
}

mysqli_stmt_bind_param($stmt,'sssi',$juris,$muni,$tipo,$anio);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$metas = [];
while($row = mysqli_fetch_assoc($res)) {
	$metas[] = [
		'id' => (int)$row['id'],
		'meta' => (int)$row['meta'],
		'anio' => (int)$row['anio'],
		'municipio' => $row['municipio'] ?? null,
		'creada_por' => $row['creada_por'] ?? null,
		'creada_por_nombre' => $row['creada_por_nombre'] ?? null,
		'created_at' => $row['created_at'] ?? null,
	];
}

echo json_encode($metas);
?>







