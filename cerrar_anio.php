<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

if ($_SESSION['rol']!=='admin'){header('Location: panel.php');exit;}

// Cargar configuración del sistema
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

// Obtener años disponibles
$anios_disponibles = [];
$res = mysqli_query($conn, "SELECT DISTINCT anio FROM registros ORDER BY anio DESC");
while ($row = mysqli_fetch_assoc($res)) {
    $anios_disponibles[] = $row['anio'];
}

// Obtener estado de cada año
$estados_anios = [];
foreach ($anios_disponibles as $a) {
    $res = mysqli_query($conn, "SELECT COUNT(*) as total, SUM(editable) as editables FROM registros WHERE anio = $a");
    $row = mysqli_fetch_assoc($res);
    $estados_anios[$a] = [
        'total' => $row['total'],
        'editables' => $row['editables'] ?? 0,
        'cerrado' => ($row['editables'] == 0 && $row['total'] > 0)
    ];
}

$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD']==='POST'){
    $accion = $_POST['accion'] ?? '';
    $anio = (int)$_POST['anio'];
    
    if ($accion === 'cerrar') {
        $stmt = mysqli_prepare($conn,"UPDATE registros SET editable=0 WHERE anio=?");
        mysqli_stmt_bind_param($stmt,'i',$anio);
        mysqli_stmt_execute($stmt);
        $mensaje = "Año $anio cerrado correctamente. Los registros ya no pueden ser editados.";
        $tipo_mensaje = 'warning';
    } elseif ($accion === 'reabrir') {
        $stmt = mysqli_prepare($conn,"UPDATE registros SET editable=1 WHERE anio=?");
        mysqli_stmt_bind_param($stmt,'i',$anio);
        mysqli_stmt_execute($stmt);
        $mensaje = "Año $anio reabierto. Los registros pueden ser editados nuevamente.";
        $tipo_mensaje = 'success';
    } elseif ($accion === 'activar') {
        $config_sistema['anio_activo'] = $anio;
        file_put_contents($config_file, json_encode($config_sistema, JSON_PRETTY_PRINT));
        $anio_activo = $anio;
        $mensaje = "Año $anio establecido como año activo del sistema.";
        $tipo_mensaje = 'info';
    } elseif ($accion === 'iniciar_nuevo_anio') {
        // Iniciar nuevo año: cerrar actual, opcionalmente copiar metas, activar nuevo
        $anio_anterior = $anio;
        $nuevo_anio = $anio + 1;
        $copiar_metas = isset($_POST['copiar_metas']) && $_POST['copiar_metas'] == '1';
        
        mysqli_begin_transaction($conn);
        try {
            // 1. Cerrar el año anterior
            $stmt = mysqli_prepare($conn, "UPDATE registros SET editable=0 WHERE anio=?");
            mysqli_stmt_bind_param($stmt, 'i', $anio_anterior);
            mysqli_stmt_execute($stmt);
            
            $metas_copiadas = 0;
            if ($copiar_metas) {
                // 2. Verificar si ya existen metas para el nuevo año
                $check_metas = mysqli_prepare($conn, "SELECT COUNT(*) as total FROM metas WHERE anio = ?");
                mysqli_stmt_bind_param($check_metas, 'i', $nuevo_anio);
                mysqli_stmt_execute($check_metas);
                $res_check = mysqli_stmt_get_result($check_metas);
                $metas_existentes = mysqli_fetch_assoc($res_check)['total'];
                
                if ($metas_existentes == 0) {
                    // 3. Copiar metas del año anterior al nuevo año
                    $sql_copiar = "INSERT INTO metas (jurisdiccion, municipio, tipo, meta, anio, creada_por)
                                   SELECT jurisdiccion, municipio, tipo, meta, ?, creada_por
                                   FROM metas WHERE anio = ?";
                    $stmt_copiar = mysqli_prepare($conn, $sql_copiar);
                    mysqli_stmt_bind_param($stmt_copiar, 'ii', $nuevo_anio, $anio_anterior);
                    mysqli_stmt_execute($stmt_copiar);
                    $metas_copiadas = mysqli_affected_rows($conn);
                }
            }
            
            // 4. Activar el nuevo año
            $config_sistema['anio_activo'] = $nuevo_anio;
            file_put_contents($config_file, json_encode($config_sistema, JSON_PRETTY_PRINT));
            $anio_activo = $nuevo_anio;
            
            mysqli_commit($conn);
            
            // Agregar nuevo año a la lista si no existe
            if (!in_array($nuevo_anio, $anios_disponibles)) {
                $anios_disponibles[] = $nuevo_anio;
                rsort($anios_disponibles);
            }
            
            $mensaje = "¡Año $nuevo_anio iniciado correctamente! Se cerró el año $anio_anterior.";
            if ($metas_copiadas > 0) {
                $mensaje .= " Se copiaron $metas_copiadas metas.";
            } elseif (!$copiar_metas) {
                $mensaje .= " Las metas deberán configurarse manualmente.";
            }
            $tipo_mensaje = 'success';
            
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $mensaje = "Error al iniciar nuevo año: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    }
    
    // Recargar estados
    $anios_disponibles = [];
    $res = mysqli_query($conn, "SELECT DISTINCT anio FROM registros ORDER BY anio DESC");
    while ($row = mysqli_fetch_assoc($res)) {
        $anios_disponibles[] = $row['anio'];
    }
    // Agregar año activo si no tiene registros aún
    if (!in_array($anio_activo, $anios_disponibles)) {
        $anios_disponibles[] = $anio_activo;
        rsort($anios_disponibles);
    }
    
    $estados_anios = [];
    foreach ($anios_disponibles as $a) {
        $res = mysqli_query($conn, "SELECT COUNT(*) as total, SUM(editable) as editables FROM registros WHERE anio = $a");
        $row = mysqli_fetch_assoc($res);
        $estados_anios[$a] = [
            'total' => $row['total'] ?? 0,
            'editables' => $row['editables'] ?? 0,
            'cerrado' => (($row['total'] ?? 0) > 0 && (int)($row['editables'] ?? 0) === 0)
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Años - Zoonosis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css">
    <style>
        .hero-section {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);
            border-radius: 24px;
            padding: 40px;
            margin-bottom: 32px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(59, 130, 246, 0.3);
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero-content {
            position: relative;
            z-index: 1;
        }
        .hero-title {
            color: white;
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 8px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .hero-subtitle {
            color: rgba(255,255,255,0.9);
            font-size: 1.1rem;
            margin-bottom: 24px;
        }
        .hero-stats {
            display: flex;
            gap: 32px;
            flex-wrap: wrap;
        }
        .hero-stat {
            background: rgba(255,255,255,0.15);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 20px 28px;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .hero-stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: white;
            line-height: 1;
        }
        .hero-stat-label {
            color: rgba(255,255,255,0.8);
            font-size: 0.9rem;
            margin-top: 4px;
        }
        
        .year-card {
            background: #ffffff;
            border-radius: 20px;
            padding: 0;
            border: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }
        .year-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
        }
        .year-card-header {
            padding: 28px 24px 20px;
            position: relative;
        }
        .year-card.active .year-card-header {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        .year-card.closed .year-card-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .year-card.open .year-card-header {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .year-badge {
            position: absolute;
            top: 16px;
            right: 16px;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            background: rgba(255,255,255,0.25);
            color: white;
            backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .year-number {
            font-size: 3.5rem;
            font-weight: 900;
            color: white;
            line-height: 1;
            text-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .year-icon {
            position: absolute;
            bottom: -10px;
            right: 20px;
            font-size: 5rem;
            color: rgba(255,255,255,0.15);
        }
        
        .year-card-body {
            padding: 24px;
        }
        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
        }
        .mini-stat {
            flex: 1;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 14px;
            padding: 16px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }
        .mini-stat-value {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1;
        }
        .mini-stat-label {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .progress-bar-custom {
            height: 8px;
            background: #e2e8f0;
            border-radius: 10px;
            overflow: hidden;
            margin-bottom: 20px;
        }
        .progress-fill {
            height: 100%;
            border-radius: 10px;
            transition: width 0.6s ease;
        }
        .progress-fill.editable {
            background: linear-gradient(90deg, #10b981, #34d399);
        }
        .progress-fill.locked {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }
        
        .year-actions {
            display: flex;
            gap: 10px;
        }
        .btn-year {
            flex: 1;
            padding: 14px 20px;
            border-radius: 14px;
            font-weight: 700;
            font-size: 0.85rem;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .btn-year:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .btn-cerrar {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border: 2px solid #fcd34d;
        }
        .btn-cerrar:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(245, 158, 11, 0.35);
            background: linear-gradient(135deg, #fde68a 0%, #fcd34d 100%);
        }
        .btn-reabrir {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border: 2px solid #6ee7b7;
        }
        .btn-reabrir:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(16, 185, 129, 0.35);
            background: linear-gradient(135deg, #a7f3d0 0%, #6ee7b7 100%);
        }
        .btn-activar {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border: 2px solid #93c5fd;
        }
        .btn-activar:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.35);
            background: linear-gradient(135deg, #bfdbfe 0%, #93c5fd 100%);
        }
        
        .years-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 28px;
        }
        
        .alert-custom {
            border-radius: 16px;
            padding: 20px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 16px;
            border: none;
            animation: slideIn 0.4s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-custom.success {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
        }
        .alert-custom.warning {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }
        .alert-custom.info {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        .alert-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            flex-shrink: 0;
        }
        .alert-custom.success .alert-icon { background: rgba(16, 185, 129, 0.2); }
        .alert-custom.warning .alert-icon { background: rgba(245, 158, 11, 0.2); }
        .alert-custom.info .alert-icon { background: rgba(59, 130, 246, 0.2); }
        
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 20px;
            border: 2px dashed #cbd5e1;
        }
        .empty-icon {
            font-size: 4rem;
            color: #94a3b8;
            margin-bottom: 16px;
        }
        .empty-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #475569;
            margin-bottom: 8px;
        }
        .empty-text {
            color: #64748b;
        }

        @media (max-width: 768px) {
            .hero-section { padding: 28px 20px; }
            .hero-title { font-size: 1.6rem; }
            .hero-stats { gap: 16px; }
            .hero-stat { padding: 16px 20px; }
            .hero-stat-value { font-size: 1.8rem; }
            .years-grid { grid-template-columns: 1fr; }
            .year-number { font-size: 2.8rem; }
            .year-actions { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        
        <!-- Hero Section -->
        <div class="hero-section">
            <div class="hero-content">
                <h1 class="hero-title"><i class="fas fa-calendar-alt" style="margin-right: 12px;"></i>Gestión de Años</h1>
                <p class="hero-subtitle">Administra los períodos del sistema, cierra años para proteger datos históricos o establece el año de trabajo activo.</p>
                
                <div class="hero-stats">
                    <div class="hero-stat">
                        <div class="hero-stat-value"><?= $anio_activo ?></div>
                        <div class="hero-stat-label"><i class="fas fa-star"></i> Año Activo</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-value"><?= count($anios_disponibles) ?></div>
                        <div class="hero-stat-label"><i class="fas fa-layer-group"></i> Años Registrados</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-value"><?= count(array_filter($estados_anios, fn($e) => $e['cerrado'])) ?></div>
                        <div class="hero-stat-label"><i class="fas fa-lock"></i> Años Cerrados</div>
                    </div>
                </div>
            </div>
        </div>

        <?php if($mensaje): ?>
            <div class="alert-custom <?= $tipo_mensaje ?>">
                <div class="alert-icon">
                    <i class="fas fa-<?= $tipo_mensaje === 'success' ? 'check-circle' : ($tipo_mensaje === 'warning' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                </div>
                <div>
                    <strong style="display:block; margin-bottom: 2px;">
                        <?= $tipo_mensaje === 'success' ? '¡Operación exitosa!' : ($tipo_mensaje === 'warning' ? 'Año cerrado' : 'Información') ?>
                    </strong>
                    <?= htmlspecialchars($mensaje) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($anios_disponibles)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-calendar-times"></i></div>
                <div class="empty-title">No hay años registrados</div>
                <p class="empty-text">Aún no existen registros en el sistema. Los años aparecerán aquí cuando se capturen datos.</p>
            </div>
        <?php else: ?>
            <div class="years-grid">
                <?php foreach ($anios_disponibles as $anio): 
                    $estado = $estados_anios[$anio];
                    $es_activo = ($anio == $anio_activo);
                    $esta_cerrado = $estado['cerrado'];
                    $porcentaje_editable = $estado['total'] > 0 ? round(($estado['editables'] / $estado['total']) * 100) : 0;
                    
                    $clase_card = $es_activo ? 'active' : ($esta_cerrado ? 'closed' : 'open');
                ?>
                    <div class="year-card <?= $clase_card ?>">
                        <div class="year-card-header">
                            <span class="year-badge">
                                <?php if ($es_activo): ?>
                                    <i class="fas fa-star"></i> Activo
                                <?php elseif ($esta_cerrado): ?>
                                    <i class="fas fa-lock"></i> Cerrado
                                <?php else: ?>
                                    <i class="fas fa-unlock"></i> Abierto
                                <?php endif; ?>
                            </span>
                            <div class="year-number"><?= $anio ?></div>
                            <i class="fas fa-calendar year-icon"></i>
                        </div>
                        
                        <div class="year-card-body">
                            <div class="stats-row">
                                <div class="mini-stat">
                                    <div class="mini-stat-value"><?= number_format($estado['total']) ?></div>
                                    <div class="mini-stat-label">Registros</div>
                                </div>
                                <div class="mini-stat">
                                    <div class="mini-stat-value"><?= $porcentaje_editable ?>%</div>
                                    <div class="mini-stat-label">Editables</div>
                                </div>
                            </div>
                            
                            <div class="progress-bar-custom">
                                <div class="progress-fill <?= $esta_cerrado ? 'locked' : 'editable' ?>" style="width: <?= $esta_cerrado ? 100 : $porcentaje_editable ?>%"></div>
                            </div>
                            
                            <div class="year-actions">
                                <?php if (!$esta_cerrado): ?>
                                    <form method="post" style="flex:1;" onsubmit="return confirm('¿Cerrar el año <?= $anio ?>?\n\nLos registros quedarán protegidos y no podrán editarse.')">
                                        <input type="hidden" name="accion" value="cerrar">
                                        <input type="hidden" name="anio" value="<?= $anio ?>">
                                        <button type="submit" class="btn-year btn-cerrar" style="width:100%;">
                                            <i class="fas fa-lock"></i> Cerrar
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" style="flex:1;" onsubmit="return confirm('¿Reabrir el año <?= $anio ?>?\n\nLos registros podrán ser editados nuevamente.')">
                                        <input type="hidden" name="accion" value="reabrir">
                                        <input type="hidden" name="anio" value="<?= $anio ?>">
                                        <button type="submit" class="btn-year btn-reabrir" style="width:100%;">
                                            <i class="fas fa-unlock"></i> Reabrir
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <form method="post" style="flex:1;">
                                    <input type="hidden" name="accion" value="activar">
                                    <input type="hidden" name="anio" value="<?= $anio ?>">
                                    <button type="submit" class="btn-year btn-activar" style="width:100%;" <?= $es_activo ? 'disabled' : '' ?>>
                                        <i class="fas fa-star"></i> <?= $es_activo ? 'Activo' : 'Activar' ?>
                                    </button>
                                </form>
                            </div>
                            
                            <?php if ($es_activo && !$esta_cerrado): ?>
                            <!-- Botón especial para iniciar nuevo año -->
                            <div style="margin-top: 12px; padding-top: 12px; border-top: 2px dashed #cbd5e1;">
                                <form method="post" onsubmit="return confirmarNuevoAnio(<?= $anio ?>)">
                                    <input type="hidden" name="accion" value="iniciar_nuevo_anio">
                                    <input type="hidden" name="anio" value="<?= $anio ?>">
                                    
                                    <div style="margin-bottom: 10px; padding: 10px; background: #f8fafc; border-radius: 8px;">
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.85rem; color: #475569;">
                                            <input type="checkbox" name="copiar_metas" value="1" style="width: 18px; height: 18px; accent-color: #7c3aed;">
                                            <span>Copiar metas del <?= $anio ?> al <?= $anio + 1 ?></span>
                                        </label>
                                    </div>
                                    
                                    <button type="submit" class="btn-year" style="width:100%; background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); color: white; border: 2px solid #8b5cf6;">
                                        <i class="fas fa-rocket"></i> Iniciar Año <?= $anio + 1 ?>
                                    </button>
                                </form>
                                <small style="display: block; text-align: center; margin-top: 8px; color: #64748b;">
                                    <i class="fas fa-info-circle"></i> Cierra <?= $anio ?> y activa <?= $anio + 1 ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmarNuevoAnio(anioActual) {
    const nuevoAnio = anioActual + 1;
    const copiarMetas = document.querySelector('input[name="copiar_metas"]').checked;
    
    let mensaje = `¿Iniciar el año ${nuevoAnio}?\n\nEsto hará lo siguiente:\n`;
    mensaje += `• Cerrar el año ${anioActual} (bloquear edición)\n`;
    if (copiarMetas) {
        mensaje += `• Copiar las metas del ${anioActual} al ${nuevoAnio}\n`;
    } else {
        mensaje += `• NO se copiarán metas (deberás configurarlas manualmente)\n`;
    }
    mensaje += `• Activar el año ${nuevoAnio} como año de trabajo\n\n¿Continuar?`;
    
    return confirm(mensaje);
}
</script>
</body>
</html>
