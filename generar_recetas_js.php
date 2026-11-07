<?php

/**
 * GENERADOR DE RECETAS - BIOLÓGICO CANINO
 * VERSIÓN FINAL: Corregida paridad de columnas y tipos de bind_param.
 */
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// ========== ACCIONES ESPECIALES (BORRAR) ==========
if (isset($_GET['borrar'])) {
    $f1 = (int)$_GET['folio_inicio'];
    $f2 = (int)$_GET['folio_fin'];
    $t = trim($_GET['tipo_insumo']);
    mysqli_query($conn, "DELETE FROM recetas_biologico WHERE folio BETWEEN $f1 AND $f2 AND tipo_insumo = '$t'");
    echo json_encode(['success' => true]);
    exit;
}

// ========== GENERAR NUEVAS RECETAS ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
    $CANT = (int)($_POST['cantidad'] ?? 0);
    $fecha = $_POST['fecha'] ?? date('Y-m-d');
    $receptor = trim($_POST['receptor'] ?? '');
    $cedula = trim($_POST['cedula'] ?? '');
    $producto = trim($_POST['producto'] ?? '');
    $direccion = trim($_POST['direccion'] ?? '');
    $tipo = trim($_POST['tipo_insumo'] ?? 'GENERAL');

    // Obtener año y mes de la fecha de emisión
    $dateObj = strtotime($fecha);
    $anio = (int)date('Y', $dateObj);
    $mes = (int)date('m', $dateObj);

    if (empty($jurisdiccion) || $CANT < 1) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos o cantidad invalida']);
        exit;
    }

    $numR = (int)ceil($CANT / 20);

    // Obtener último folio GLOBAL por año y tipo de insumo
    $sqlU = "SELECT MAX(folio) as u FROM recetas_biologico WHERE anio = ? AND tipo_insumo = ?";
    $stU = mysqli_prepare($conn, $sqlU);
    mysqli_stmt_bind_param($stU, 'is', $anio, $tipo);
    mysqli_stmt_execute($stU);
    $resU = mysqli_stmt_get_result($stU);
    $rowU = mysqli_fetch_assoc($resU);
    $ultimoFolio = (int)($rowU['u'] ?? 0);

    $loteId = 'LOTE_' . time() . '_' . rand(100, 999);
    $recetas = [];

    mysqli_begin_transaction($conn);

    for ($i = 0; $i < $numR; $i++) {
        $folio = $ultimoFolio + $i + 1;
        // Distribución: 20 frascos por receta intermedias, resto en la última
        $cantEsta = ($i < $numR - 1) ? 20 : ($CANT - ($i * 20));
        if ($cantEsta < 1) $cantEsta = 1;

        $fmt = str_pad($folio, 6, '0', STR_PAD_LEFT);
        $uid = $_SESSION['usuario_id'];

        // 14 columnas en INSERT
        $sql = "INSERT INTO recetas_biologico (lote_id, jurisdiccion, folio, folio_formateado, producto, tipo_insumo, cantidad_frascos, fecha_emision, anio, mes, receptor_nombre, receptor_cedula, direccion, usuario_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $st = mysqli_prepare($conn, $sql);

        // Tipos (14): s s i s s s i s i i s s s i
        mysqli_stmt_bind_param(
            $st,
            'ssisssisiiissi',
            $loteId,
            $jurisdiccion,
            $folio,
            $fmt,
            $producto,
            $tipo,
            $cantEsta,
            $fecha,
            $anio,
            $mes,
            $receptor,
            $cedula,
            $direccion,
            $uid
        );
        mysqli_stmt_execute($st);

        $recetas[] = [
            'folio' => $folio,
            'folio_formateado' => $fmt,
            'cantidad_frascos' => $cantEsta,
            'producto' => $producto,
            'jurisdiccion' => $jurisdiccion,
            'fecha_emision' => $fecha,
            'receptor_nombre' => $receptor
        ];
    }

    mysqli_commit($conn);
    echo json_encode([
        'success' => true,
        'num_recetas' => $numR,
        'ultimo_folio' => $ultimoFolio + $numR,
        'recetas' => $recetas,
        'tipo_insumo' => $tipo
    ]);
    exit;
}
