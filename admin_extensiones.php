<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

// Verificar permisos de administrador
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: panel.php');
    exit;
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'grant_extension') {
            $usuario_id = intval($_POST['usuario_id']);
            $mes = intval($_POST['mes']);
            $anio = intval($_POST['anio']);
            $horas = intval($_POST['horas']); // Duración en horas

            if ($usuario_id > 0 && $mes >= 1 && $mes <= 12 && $anio >= 2020) {
                // Calcular fecha límite
                $fecha_limite = date('Y-m-d H:i:s', strtotime("+$horas hours"));

                // Insertar extensión
                $stmt = mysqli_prepare($conn, "INSERT INTO extensiones (usuario_id, mes, anio, fecha_limite) VALUES (?, ?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "iiis", $usuario_id, $mes, $anio, $fecha_limite);

                if (mysqli_stmt_execute($stmt)) {
                    // Notificar al usuario automáticamente
                    $asunto = "Prórroga Concedida";
                    $mensaje = "Se le ha habilitado la captura para el mes $mes/$anio por $horas horas.";
                    $admin_id = $_SESSION['usuario_id'];
                    $stmt_msg = mysqli_prepare($conn, "INSERT INTO mensajes (remitente_id, destinatario_id, asunto, mensaje, tipo) VALUES (?, ?, ?, ?, 'general')");
                    mysqli_stmt_bind_param($stmt_msg, "iiss", $admin_id, $usuario_id, $asunto, $mensaje);
                    mysqli_stmt_execute($stmt_msg);

                    $success = "Extensión concedida correctamente.";
                } else {
                    $error = "Error al conceder extensión: " . mysqli_error($conn);
                }
            } else {
                $error = "Datos inválidos.";
            }
        } elseif ($_POST['action'] === 'revoke_extension') {
            $ext_id = intval($_POST['extension_id']);
            $stmt = mysqli_prepare($conn, "DELETE FROM extensiones WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "i", $ext_id);
            if (mysqli_stmt_execute($stmt)) {
                $success = "Extensión revocada correctamente.";
            } else {
                $error = "Error al revocar: " . mysqli_error($conn);
            }
        }
    }
}

// Obtener usuarios capturistas para el select
$usuarios = [];
$res_u = mysqli_query($conn, "SELECT id, nombre, jurisdiccion FROM usuarios WHERE rol IN ('capturista', 'supervisor') ORDER BY nombre");
while ($r = mysqli_fetch_assoc($res_u)) {
    $usuarios[] = $r;
}

// Determinar vista (activas o historial)
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'activas';

// Obtener extensiones activas
$extensiones = [];
$res_e = mysqli_query($conn, "SELECT e.*, u.nombre as usuario_nombre, u.jurisdiccion 
                              FROM extensiones e 
                              JOIN usuarios u ON e.usuario_id = u.id 
                              WHERE e.fecha_limite > NOW() 
                              ORDER BY e.created_at DESC");
while ($r = mysqli_fetch_assoc($res_e)) {
    $r['tiempo_restante'] = (strtotime($r['fecha_limite']) - time()) / 3600;
    $extensiones[] = $r;
}

// Obtener historial de extensiones (vencidas) - con paginación
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$por_pagina = 20;
$offset = ($pagina - 1) * $por_pagina;

$historial = [];
$total_historial = 0;

if ($vista === 'historial') {
    // Contar total
    $res_count = mysqli_query($conn, "SELECT COUNT(*) as total FROM extensiones WHERE fecha_limite <= NOW()");
    $total_historial = mysqli_fetch_assoc($res_count)['total'];
    
    // Obtener registros paginados
    $res_h = mysqli_query($conn, "SELECT e.*, u.nombre as usuario_nombre, u.jurisdiccion 
                                  FROM extensiones e 
                                  JOIN usuarios u ON e.usuario_id = u.id 
                                  WHERE e.fecha_limite <= NOW() 
                                  ORDER BY e.fecha_limite DESC 
                                  LIMIT $por_pagina OFFSET $offset");
    while ($r = mysqli_fetch_assoc($res_h)) {
        $historial[] = $r;
    }
}

$total_paginas = ceil($total_historial / $por_pagina);

?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>Control de Tiempos - Sistema Hospitalario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <link href="assets/css/dark-mode.css" rel="stylesheet">
    <style>
        /* Estilos para headers de tabla */
        .table thead {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
        }
        .table thead th {
            padding: 14px 12px;
            font-weight: 600;
            font-size: 0.9rem;
            text-align: left;
            border-bottom: 2px solid #1e40af;
            color: white !important;
        }
        .table tbody tr {
            border-bottom: 1px solid #e2e8f0;
        }
        .table tbody td {
            padding: 12px;
            font-size: 0.875rem;
        }
        
        /* Estilos para el header de página */
        .page-header * {
            color: white !important;
        }
        
        /* Tabs de navegación */
        .nav-tabs-custom {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 24px;
            padding: 0;
            list-style: none;
        }
        
        .nav-tab-item {
            flex: 1;
            text-align: center;
        }
        
        .nav-tab-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 24px;
            background: #f8fafc;
            color: #64748b;
            text-decoration: none;
            border-radius: 12px 12px 0 0;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            border-bottom: none;
            position: relative;
            bottom: -2px;
        }
        
        .nav-tab-link:hover {
            background: #eff6ff;
            color: #2563eb;
        }
        
        .nav-tab-link.active {
            background: white;
            color: #2563eb;
            border-color: #e2e8f0;
            border-bottom-color: white;
            box-shadow: 0 -4px 12px rgba(37, 99, 235, 0.1);
        }
        
        .nav-tab-badge {
            background: #ef4444;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 700;
            min-width: 24px;
            display: inline-block;
        }
        
        .nav-tab-link.active .nav-tab-badge {
            background: #2563eb;
        }
        
        /* Paginación */
        .pagination-custom {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 24px;
            padding: 16px;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        .pagination-btn {
            padding: 8px 16px;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            color: #475569;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        
        .pagination-btn:hover:not(:disabled) {
            border-color: #2563eb;
            color: #2563eb;
            background: #eff6ff;
        }
        
        .pagination-btn:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }
        
        .pagination-btn.active {
            background: #2563eb;
            color: white;
            border-color: #2563eb;
        }
        
        .pagination-info {
            color: #64748b;
            font-size: 0.9rem;
            padding: 0 12px;
        }
    </style>
</head>

<body class="theme-metas">
    <div class="app-shell">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
            <h1 class="page-title" style="color: white; margin-bottom: 0.5rem;">
                <i class="fas fa-clock me-2"></i>
                Control de Tiempos y Prórrogas
            </h1>
            <p class="page-subtitle" style="color: rgba(255, 255, 255, 0.9); margin: 0;">Gestione habilitaciones extraordinarias de captura para usuarios fuera de fecha.</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Tabs de navegación -->
        <ul class="nav-tabs-custom">
            <li class="nav-tab-item">
                <a href="?vista=activas" class="nav-tab-link <?= $vista === 'activas' ? 'active' : '' ?>">
                    <i class="fas fa-clock"></i>
                    <span>Prórrogas Activas</span>
                    <?php if (count($extensiones) > 0): ?>
                        <span class="nav-tab-badge"><?= count($extensiones) ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-tab-item">
                <a href="?vista=historial" class="nav-tab-link <?= $vista === 'historial' ? 'active' : '' ?>">
                    <i class="fas fa-history"></i>
                    <span>Historial</span>
                    <?php if ($total_historial > 0): ?>
                        <span class="nav-tab-badge"><?= $total_historial ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>

        <div class="row">
            <!-- Formulario de Nueva Extensión (solo en vista activas) -->
            <?php if ($vista === 'activas'): ?>
            <div class="col-md-4 mb-4">
                <div class="glass-card p-4">
                    <h5 class="card-title mb-4"><i class="fas fa-clock me-2 text-primary"></i>Nueva Habilitación</h5>
                    <form method="POST">
                        <input type="hidden" name="action" value="grant_extension">

                        <div class="mb-3">
                            <label class="form-label">Usuario</label>
                            <select name="usuario_id" class="form-select" required>
                                <option value="">Seleccione Usuario...</option>
                                <?php foreach ($usuarios as $u): ?>
                                    <option value="<?php echo $u['id']; ?>">
                                        <?php echo htmlspecialchars($u['nombre']); ?> (<?php echo htmlspecialchars($u['jurisdiccion']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Mes</label>
                                <select name="mes" class="form-select" required>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $m == date('n') - 1 ? 'selected' : ''; ?>>
                                            <?php echo mesClaveDesdeNumero($m); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Año</label>
                                <input type="number" name="anio" class="form-control" value="<?php echo date('Y'); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Duración</label>
                            <select name="horas" class="form-select">
                                <option value="24">24 Horas</option>
                                <option value="48">48 Horas</option>
                                <option value="72">72 Horas</option>
                                <option value="1">1 Hora (Prueba)</option>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-unlock me-2"></i>Habilitar Captura
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tabla de Extensiones Activas o Historial -->
            <div class="<?= $vista === 'activas' ? 'col-md-8' : 'col-12' ?>">
                <div class="glass-card p-4">
                    <?php if ($vista === 'activas'): ?>
                        <h5 class="card-title mb-4">
                            <i class="fas fa-clock-rotate-left me-2 text-success"></i>Prórrogas Activas
                        </h5>

                        <?php if (count($extensiones) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Usuario</th>
                                        <th>Periodo</th>
                                        <th>Vence en</th>
                                        <th>Límite</th>
                                        <th>Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($extensiones as $ext): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold"><?php echo htmlspecialchars($ext['usuario_nombre']); ?></div>
                                                <small class="text-muted"><?php echo htmlspecialchars($ext['jurisdiccion']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-light text-dark border">
                                                    <?php echo mesClaveDesdeNumero($ext['mes']) . '/' . $ext['anio']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                $horas_restantes = round($ext['tiempo_restante'], 1);
                                                $color = $horas_restantes < 2 ? 'text-danger fw-bold' : 'text-success';
                                                echo "<span class='$color'>$horas_restantes hrs</span>";
                                                ?>
                                            </td>
                                            <td class="text-muted small">
                                                <?php echo date('d/m H:i', strtotime($ext['fecha_limite'])); ?>
                                            </td>
                                            <td>
                                                <form method="POST" onsubmit="return confirm('¿Revocar esta extensión?');">
                                                    <input type="hidden" name="action" value="revoke_extension">
                                                    <input type="hidden" name="extension_id" value="<?php echo $ext['id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Revocar">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-check-circle fa-3x mb-3 opacity-25"></i>
                                <p>No hay prórrogas activas actualmente.</p>
                            </div>
                        <?php endif; ?>
                    
                    <?php else: ?>
                        <!-- Vista de Historial -->
                        <h5 class="card-title mb-4">
                            <i class="fas fa-history me-2 text-secondary"></i>Historial de Prórrogas
                        </h5>

                        <?php if (count($historial) > 0): ?>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead>
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Periodo</th>
                                            <th>Concedida</th>
                                            <th>Venció</th>
                                            <th>Duración</th>
                                            <th>Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($historial as $hist): 
                                            $duracion_horas = round((strtotime($hist['fecha_limite']) - strtotime($hist['created_at'])) / 3600, 1);
                                        ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($hist['usuario_nombre']); ?></div>
                                                    <small class="text-muted"><?php echo htmlspecialchars($hist['jurisdiccion']); ?></small>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary">
                                                        <?php echo mesClaveDesdeNumero($hist['mes']) . '/' . $hist['anio']; ?>
                                                    </span>
                                                </td>
                                                <td class="text-muted small">
                                                    <?php echo date('d/m/Y H:i', strtotime($hist['created_at'])); ?>
                                                </td>
                                                <td class="text-muted small">
                                                    <?php echo date('d/m/Y H:i', strtotime($hist['fecha_limite'])); ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-light text-dark border">
                                                        <?php echo $duracion_horas; ?> hrs
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>Completada
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Paginación -->
                            <?php if ($total_paginas > 1): ?>
                                <div class="pagination-custom">
                                    <a href="?vista=historial&pagina=<?= max(1, $pagina - 1) ?>" 
                                       class="pagination-btn <?= $pagina <= 1 ? 'disabled' : '' ?>"
                                       <?= $pagina <= 1 ? 'onclick="return false;"' : '' ?>>
                                        <i class="fas fa-chevron-left"></i> Anterior
                                    </a>
                                    
                                    <span class="pagination-info">
                                        Página <?= $pagina ?> de <?= $total_paginas ?> 
                                        (<?= $total_historial ?> registros)
                                    </span>
                                    
                                    <a href="?vista=historial&pagina=<?= min($total_paginas, $pagina + 1) ?>" 
                                       class="pagination-btn <?= $pagina >= $total_paginas ? 'disabled' : '' ?>"
                                       <?= $pagina >= $total_paginas ? 'onclick="return false;"' : '' ?>>
                                        Siguiente <i class="fas fa-chevron-right"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="text-center py-5 text-muted">
                                <i class="fas fa-history fa-3x mb-3 opacity-25"></i>
                                <p>No hay historial de prórrogas.</p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>