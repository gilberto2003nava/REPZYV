<?php

/**
 * SCRIPT: actualizar_receta_ajax.php
 * VERSIÓN FINAL: Corregida paridad de columnas y tipos de bind_param.
 */

session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Sesion expirada']);
    exit;
}

$loteId = isset($_POST['lote_id']) ? trim($_POST['lote_id']) : '';
$jurisdiccion = isset($_POST['jurisdiccion']) ? trim($_POST['jurisdiccion']) : '';
$receptor = isset($_POST['receptor']) ? trim($_POST['receptor']) : '';
$cedula = isset($_POST['cedula']) ? trim($_POST['cedula']) : '';
$producto = isset($_POST['producto']) ? trim($_POST['producto']) : '';
$fecha = isset($_POST['fecha']) ? trim($_POST['fecha']) : '';
$direccion = isset($_POST['direccion']) ? trim($_POST['direccion']) : '';
$VALOR_TOTAL_FRASCOS = isset($_POST['cantidad_frascos']) ? (int)$_POST['cantidad_frascos'] : 0;

if (empty($loteId) || $VALOR_TOTAL_FRASCOS < 1) {
    echo json_encode(['success' => false, 'error' => 'Datos invalidos o cantidad cero']);
    exit;
}

// 1. Obtener recetas actuales
$sql = "SELECT id, folio, anio, mes, tipo_insumo FROM recetas_biologico WHERE lote_id = ? ORDER BY folio ASC";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 's', $loteId);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

$recetasExistentes = [];
while ($row = mysqli_fetch_assoc($res)) {
    $recetasExistentes[] = $row;
}

if (empty($recetasExistentes)) {
    echo json_encode(['success' => false, 'error' => 'Lote no encontrado']);
    exit;
}

$recetaBase = $recetasExistentes[0];
$numActual = count($recetasExistentes);
$numNecesario = (int)ceil($VALOR_TOTAL_FRASCOS / 20);
if ($numNecesario < 1) $numNecesario = 1;

mysqli_begin_transaction($conn);

// Ajustar número de recetas (Añadir o Quitar)
$diff = $numNecesario - $numActual;

if ($diff > 0) {
    // 1. DESPLAZAR FOLIOS POSTERIORES (HACIA ADELANTE) PARA HACER ESPACIO
    // Obtenemos el folio más alto del lote actual antes de insertar
    $ultimoF_lote = $recetasExistentes[$numActual - 1]['folio'];
    $tipo = $recetaBase['tipo_insumo'];
    $anio = $recetaBase['anio'];

    // SQL Dinámico para desplazar (LPAD mantiene el formato 000000)
    // FIX: Actualizar formato usando el valor original para evitar doble suma
    $sqlShift = "UPDATE recetas_biologico 
                 SET folio_formateado = LPAD(folio + ?, 6, '0'),
                     folio = folio + ?
                 WHERE anio = ? AND tipo_insumo = ? AND folio > ?
                 ORDER BY folio DESC"; // DESC para evitar colisiones temporales
    $stmtS = mysqli_prepare($conn, $sqlShift);
    mysqli_stmt_bind_param($stmtS, 'iiisi', $diff, $diff, $anio, $tipo, $ultimoF_lote);
    mysqli_stmt_execute($stmtS);

    // 2. INSERTAR NUEVAS RECETAS
    $ultimoF = $ultimoF_lote;
    for ($k = 0; $k < $diff; $k++) {
        $nuevoF = $ultimoF + 1 + $k;
        $fmt = str_pad($nuevoF, 6, '0', STR_PAD_LEFT);
        $anio = $recetaBase['anio'];
        $mes = $recetaBase['mes'];
        $tipo = $recetaBase['tipo_insumo'];
        $uid = $_SESSION['usuario_id'];

        // 14 columnas en INSERT (incluyendo id autoincremental manejado por NULL)
        // Pero usamos 13 en la lista de INSERT explícita
        $sqlIns = "INSERT INTO recetas_biologico (lote_id, jurisdiccion, folio, folio_formateado, producto, tipo_insumo, cantidad_frascos, fecha_emision, anio, mes, receptor_nombre, receptor_cedula, direccion, usuario_id) 
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtI = mysqli_prepare($conn, $sqlIns);
        // Cantidad temporal 0, se actualiza luego
        $tempCant = 0;
        // Tipos (14): s s i s s s i s i i s s s i
        mysqli_stmt_bind_param(
            $stmtI,
            'ssisssisiiissi',
            $loteId,
            $jurisdiccion,
            $nuevoF,
            $fmt,
            $producto,
            $tipo,
            $tempCant,
            $fecha,
            $anio,
            $mes,
            $receptor,
            $cedula,
            $direccion,
            $uid
        );
        mysqli_stmt_execute($stmtI);
    }
} elseif ($diff < 0) {
    // 1. IDENTIFICAR RECETAS A BORRAR
    $eliminar = abs($diff);
    $maxFolioBorr = $recetasExistentes[$numActual - 1]['folio']; // Para el desplazamiento

    for ($k = 0; $k < $eliminar; $k++) {
        $idBorr = $recetasExistentes[$numActual - 1 - $k]['id'];
        mysqli_query($conn, "DELETE FROM recetas_biologico WHERE id = $idBorr");
    }

    // 2. DESPLAZAR FOLIOS POSTERIORES (HACIA ATRÁS) PARA CERRAR HUECOS
    $tipo = $recetaBase['tipo_insumo'];
    $anio = $recetaBase['anio'];

    $sqlShift = "UPDATE recetas_biologico 
                 SET folio_formateado = LPAD(folio + ?, 6, '0'),
                     folio = folio + ?
                 WHERE anio = ? AND tipo_insumo = ? AND folio > ?
                 ORDER BY folio ASC"; // ASC para cerrar huecos
    $stmtS = mysqli_prepare($conn, $sqlShift);
    mysqli_stmt_bind_param($stmtS, 'iiisi', $diff, $diff, $anio, $tipo, $maxFolioBorr);
    mysqli_stmt_execute($stmtS);
}

// 2. Actualizar todas las recetas del lote con los datos correctos y distribución 20-20-resto
$sqlFinal = "SELECT id FROM recetas_biologico WHERE lote_id = ? ORDER BY folio ASC";
$stmtF = mysqli_prepare($conn, $sqlFinal);
mysqli_stmt_bind_param($stmtF, 's', $loteId);
mysqli_stmt_execute($stmtF);
$resF = mysqli_stmt_get_result($stmtF);
$todas = mysqli_fetch_all($resF, MYSQLI_ASSOC);
$totalR = count($todas);

$folioBase = (int)$recetasExistentes[0]['folio'];
for ($i = 0; $i < $totalR; $i++) {
    // Distribución: 20 frascos por receta intermedias, resto en la última
    if ($i < $totalR - 1) {
        $cant = 20;
    } else {
        $cant = $VALOR_TOTAL_FRASCOS - ($i * 20);
        if ($cant < 1) $cant = 1;
    }

    // Calcular folio consecutivo para esta receta del lote
    $nuevoF = $folioBase + $i;
    $fmt = str_pad($nuevoF, 6, '0', STR_PAD_LEFT);

    $idUpd = $todas[$i]['id'];
    $sqlUpd = "UPDATE recetas_biologico SET jurisdiccion=?, receptor_nombre=?, receptor_cedula=?, producto=?, fecha_emision=?, direccion=?, cantidad_frascos=?, folio=?, folio_formateado=? WHERE id=?";
    $stmtU = mysqli_prepare($conn, $sqlUpd);
    mysqli_stmt_bind_param($stmtU, 'ssssssiisi', $jurisdiccion, $receptor, $cedula, $producto, $fecha, $direccion, $cant, $nuevoF, $fmt, $idUpd);
    mysqli_stmt_execute($stmtU);
}

mysqli_commit($conn);
echo json_encode(['success' => true, 'lote_id' => $loteId, 'recetas_actualizadas' => $totalR]);
mysqli_close($conn);
