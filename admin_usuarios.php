<?php
session_start();
require_once 'includes/conexion.php';
require_once 'includes/funciones.php';
require_once 'includes/security.php';

establecerHeadersSeguridad();
establecerHeadersNoCache();

// Verificar que el usuario esté logueado y sea admin
require_once 'partials/check_session.php';

// Verificar que sea admin
if ($_SESSION['rol'] !== 'admin') {
    header('Location: panel.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$nombre_usuario = $_SESSION['nombre'];

// Limpiar bloqueos y accesos expirados
try {
    $pdo->exec("CALL sp_limpiar_bloqueos_expirados()");
    $pdo->exec("CALL sp_desactivar_accesos_expirados()");
} catch (PDOException $e) {
    // Silenciar error si los procedimientos no existen aún
}

// Obtener filtros
$filtro_estado = $_GET['estado'] ?? 'todos';
$filtro_rol = $_GET['rol'] ?? 'todos';
$filtro_busqueda = $_GET['busqueda'] ?? '';

// Construir query con filtros
$sql = "SELECT * FROM v_usuarios_completo WHERE 1=1";
$params = [];

if ($filtro_estado !== 'todos') {
    $sql .= " AND estado = :estado";
    $params[':estado'] = strtoupper($filtro_estado);
}

if ($filtro_rol !== 'todos') {
    $sql .= " AND rol = :rol";
    $params[':rol'] = $filtro_rol;
}

if (!empty($filtro_busqueda)) {
    // Dividir la búsqueda en palabras para mayor flexibilidad
    $palabras = explode(' ', trim($filtro_busqueda));
    $subQueries = [];
    foreach ($palabras as $i => $palabra) {
        if (trim($palabra) === '') continue;
        $key = ":busqueda" . $i;
        $subQueries[] = "(nombre LIKE $key OR email LIKE $key OR jurisdiccion LIKE $key)";
        $params[$key] = '%' . $palabra . '%';
    }
    if (!empty($subQueries)) {
        $sql .= " AND (" . implode(' AND ', $subQueries) . ")";
    }
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener estadísticas
$stats_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN activo = 1 AND (bloqueado_hasta IS NULL OR bloqueado_hasta < NOW()) THEN 1 ELSE 0 END) as activos,
    SUM(CASE WHEN activo = 0 THEN 1 ELSE 0 END) as inactivos,
    SUM(CASE WHEN bloqueado_hasta IS NOT NULL AND bloqueado_hasta > NOW() THEN 1 ELSE 0 END) as bloqueados,
    SUM(CASE WHEN rol = 'admin' THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN rol = 'capturista' THEN 1 ELSE 0 END) as capturistas
FROM usuarios";
$stats = $pdo->query($stats_sql)->fetch(PDO::FETCH_ASSOC);

// Obtener bloqueos activos
$bloqueos_sql = "SELECT b.*, u.nombre, u.email as usuario_email, u.rol
FROM bloqueos_usuarios b
LEFT JOIN usuarios u ON b.usuario_id = u.id
WHERE b.bloqueado_hasta > NOW() OR (b.bloqueado_hasta IS NULL AND b.intentos >= 5)
ORDER BY b.fecha_ultimo_intento DESC";
$bloqueos = $pdo->query($bloqueos_sql)->fetchAll(PDO::FETCH_ASSOC);

// Obtener accesos temporales activos
$accesos_sql = "SELECT at.*, u.nombre, u.email, u.rol, admin.nombre as otorgado_por_nombre
FROM accesos_temporales at
INNER JOIN usuarios u ON at.usuario_id = u.id
INNER JOIN usuarios admin ON at.otorgado_por = admin.id
WHERE at.activo = 1 AND at.fecha_fin > NOW()
ORDER BY at.fecha_fin ASC";
$accesos_temporales = $pdo->query($accesos_sql)->fetchAll(PDO::FETCH_ASSOC);

$titulo = "Gestión de Usuarios";
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?> - Sistema Zoonosis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <!-- Header con estilo -->
            <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 2rem; color: white; margin-bottom: 2rem; box-shadow: 0 10px 40px rgba(59, 130, 246, 0.3);">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="mb-1" style="color: white; font-size: 1.8rem; font-weight: 700;">
                            <i class="bi bi-people-fill"></i> <?php echo $titulo; ?>
                        </h1>
                        <p class="mb-0" style="color: rgba(255, 255, 255, 0.95); font-size: 1rem;">Administración completa de usuarios del sistema</p>
                    </div>
                    <div>
                        <button class="btn btn-light btn-lg" data-bs-toggle="modal" data-bs-target="#modalNuevoUsuario" style="box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                            <i class="bi bi-person-plus"></i> Nuevo Usuario
                        </button>
                    </div>
                </div>
            </div>

            <!-- Estadísticas con más estilo -->
            <div class="row g-3 mb-4">
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #3b82f6; transition: transform 0.2s;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 small fw-semibold">Total Usuarios</p>
                                    <h2 class="mb-0 fw-bold" style="color: #3b82f6;"><?php echo $stats['total']; ?></h2>
                                </div>
                                <div class="bg-primary bg-opacity-10 p-3 rounded-circle" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-people fs-2 text-primary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #10b981; transition: transform 0.2s;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 small fw-semibold">Activos</p>
                                    <h2 class="mb-0 fw-bold text-success"><?php echo $stats['activos']; ?></h2>
                                </div>
                                <div class="bg-success bg-opacity-10 p-3 rounded-circle" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-check-circle fs-2 text-success"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #ef4444; transition: transform 0.2s;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 small fw-semibold">Bloqueados</p>
                                    <h2 class="mb-0 fw-bold text-danger"><?php echo $stats['bloqueados']; ?></h2>
                                </div>
                                <div class="bg-danger bg-opacity-10 p-3 rounded-circle" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-lock-fill fs-2 text-danger"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #6b7280; transition: transform 0.2s;">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <p class="text-muted mb-1 small fw-semibold">Inactivos</p>
                                    <h2 class="mb-0 fw-bold text-secondary"><?php echo $stats['inactivos']; ?></h2>
                                </div>
                                <div class="bg-secondary bg-opacity-10 p-3 rounded-circle" style="width: 60px; height: 60px; display: flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-x-circle fs-2 text-secondary"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <style>
                .card:hover {
                    transform: translateY(-5px);
                    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15) !important;
                }
            </style>

            <!-- Tabs -->
            <ul class="nav nav-tabs mb-3" id="usuariosTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="usuarios-tab" data-bs-toggle="tab" data-bs-target="#usuarios" type="button">
                        <i class="bi bi-people"></i> Usuarios (<?php echo count($usuarios); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="bloqueos-tab" data-bs-toggle="tab" data-bs-target="#bloqueos" type="button">
                        <i class="bi bi-shield-lock"></i> Bloqueos (<?php echo count($bloqueos); ?>)
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="accesos-tab" data-bs-toggle="tab" data-bs-target="#accesos" type="button">
                        <i class="bi bi-clock-history"></i> Accesos Temporales (<?php echo count($accesos_temporales); ?>)
                    </button>
                </li>
            </ul>

            <div class="tab-content" id="usuariosTabContent">
                <!-- Tab Usuarios -->
                <div class="tab-pane fade show active" id="usuarios" role="tabpanel">
                    <div class="card border-0 shadow-sm" style="overflow: visible !important;">
                        <div class="card-header bg-white py-3" style="overflow: visible !important;">
                            <div class="row g-3">
                                <div class="col-md-3">
                                    <select class="form-select" id="filtroEstado" onchange="aplicarFiltros()">
                                        <option value="todos" <?php echo $filtro_estado === 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                                        <option value="activo" <?php echo $filtro_estado === 'activo' ? 'selected' : ''; ?>>Activos</option>
                                        <option value="bloqueado" <?php echo $filtro_estado === 'bloqueado' ? 'selected' : ''; ?>>Bloqueados</option>
                                        <option value="inactivo" <?php echo $filtro_estado === 'inactivo' ? 'selected' : ''; ?>>Inactivos</option>
                                        <option value="acceso_temporal" <?php echo $filtro_estado === 'acceso_temporal' ? 'selected' : ''; ?>>Acceso Temporal</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" id="filtroRol" onchange="aplicarFiltros()">
                                        <option value="todos" <?php echo $filtro_rol === 'todos' ? 'selected' : ''; ?>>Todos los roles</option>
                                        <option value="admin" <?php echo $filtro_rol === 'admin' ? 'selected' : ''; ?>>Administradores</option>
                                        <option value="capturista" <?php echo $filtro_rol === 'capturista' ? 'selected' : ''; ?>>Capturistas</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <div class="position-relative">
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="filtroBusqueda" placeholder="Buscar por nombre, email o jurisdicción..." value="<?php echo htmlspecialchars($filtro_busqueda); ?>" autocomplete="off">
                                            <button class="btn btn-primary" onclick="aplicarFiltros()">
                                                <i class="bi bi-search"></i> Buscar
                                            </button>
                                        </div>
                                        <div id="autocompleteResults" class="list-group position-absolute w-100 shadow-lg" style="top: 100%; left: 0; z-index: 2000; display: none; background: white;"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>ID</th>
                                            <th>Usuario</th>
                                            <th>Email</th>
                                            <th>Rol</th>
                                            <th>Jurisdicción</th>
                                            <th>Estado</th>
                                            <th>Último Acceso</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($usuarios)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4 text-muted">
                                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                                    No se encontraron usuarios con los filtros aplicados
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($usuarios as $u): ?>
                                                <tr>
                                                    <td><?php echo $u['id']; ?></td>
                                                    <td>
                                                        <div class="d-flex align-items-center">
                                                            <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;color:white;font-weight:bold;margin-right:10px;">
                                                                <?php echo strtoupper(substr($u['nombre'], 0, 1)); ?>
                                                            </div>
                                                            <div>
                                                                <div class="fw-semibold"><?php echo htmlspecialchars($u['nombre']); ?></div>
                                                                <?php if ($u['telefono']): ?>
                                                                    <small class="text-muted"><i class="bi bi-telephone"></i> <?php echo htmlspecialchars($u['telefono']); ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($u['email']); ?></td>
                                                    <td>
                                                        <span class="badge <?php echo $u['rol'] === 'admin' ? 'bg-danger' : 'bg-info'; ?>">
                                                            <i class="bi bi-<?php echo $u['rol'] === 'admin' ? 'shield-fill-check' : 'person'; ?>"></i>
                                                            <?php echo ucfirst($u['rol']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($u['jurisdiccion'] ?? 'N/A'); ?></td>
                                                    <td>
                                                        <?php
                                                        $estado_class = '';
                                                        $estado_icon = '';
                                                        switch ($u['estado']) {
                                                            case 'ACTIVO':
                                                                $estado_class = 'bg-success';
                                                                $estado_icon = 'check-circle';
                                                                break;
                                                            case 'BLOQUEADO':
                                                                $estado_class = 'bg-danger';
                                                                $estado_icon = 'lock-fill';
                                                                break;
                                                            case 'INACTIVO':
                                                                $estado_class = 'bg-secondary';
                                                                $estado_icon = 'x-circle';
                                                                break;
                                                            case 'ACCESO_TEMPORAL':
                                                                $estado_class = 'bg-warning';
                                                                $estado_icon = 'clock-history';
                                                                break;
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $estado_class; ?>">
                                                            <i class="bi bi-<?php echo $estado_icon; ?>"></i>
                                                            <?php echo str_replace('_', ' ', $u['estado']); ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php if ($u['ultimo_acceso']): ?>
                                                            <small><?php echo date('d/m/Y H:i', strtotime($u['ultimo_acceso'])); ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">Nunca</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="dropdown">
                                                            <button class="btn btn-sm btn-light" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                                <i class="bi bi-three-dots-vertical"></i>
                                                            </button>
                                                            <ul class="dropdown-menu dropdown-menu-end">
                                                                <?php if ($u['estado'] === 'BLOQUEADO'): ?>
                                                                    <li><a class="dropdown-item" href="#" onclick="desbloquearUsuario(<?php echo $u['id']; ?>); return false;">
                                                                            <i class="bi bi-unlock text-success"></i> Desbloquear
                                                                        </a></li>
                                                                    <li>
                                                                        <hr class="dropdown-divider">
                                                                    </li>
                                                                <?php endif; ?>

                                                                <?php if ($u['activo'] == 1): ?>
                                                                    <li><a class="dropdown-item" href="#" onclick="desactivarUsuario(<?php echo $u['id']; ?>); return false;">
                                                                            <i class="bi bi-pause-circle text-warning"></i> Desactivar
                                                                        </a></li>
                                                                <?php else: ?>
                                                                    <li><a class="dropdown-item" href="#" onclick="activarUsuario(<?php echo $u['id']; ?>); return false;">
                                                                            <i class="bi bi-play-circle text-success"></i> Activar
                                                                        </a></li>
                                                                <?php endif; ?>

                                                                <li><a class="dropdown-item" href="#" onclick="otorgarAccesoTemporal(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['nombre']); ?>'); return false;">
                                                                        <i class="bi bi-clock text-primary"></i> Acceso Temporal
                                                                    </a></li>

                                                                <?php if ($u['id'] != $usuario_id): ?>
                                                                    <li>
                                                                        <hr class="dropdown-divider">
                                                                    </li>
                                                                    <li><a class="dropdown-item text-danger" href="#" onclick="eliminarUsuario(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['nombre']); ?>'); return false;">
                                                                            <i class="bi bi-trash"></i> Eliminar
                                                                        </a></li>
                                                                <?php endif; ?>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Bloqueos -->
                <div class="tab-pane fade" id="bloqueos" role="tabpanel">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="bi bi-shield-lock text-danger"></i> Usuarios Bloqueados</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Email</th>
                                            <th>IP</th>
                                            <th>Intentos</th>
                                            <th>Bloqueado Hasta</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($bloqueos)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4 text-muted">
                                                    <i class="bi bi-shield-check fs-1 d-block mb-2 text-success"></i>
                                                    No hay usuarios bloqueados actualmente
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($bloqueos as $b): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($b['nombre'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($b['email'] ?? $b['usuario_email'] ?? 'N/A'); ?></td>
                                                    <td><code><?php echo htmlspecialchars($b['ip_address']); ?></code></td>
                                                    <td><span class="badge bg-danger"><?php echo $b['intentos']; ?> intentos</span></td>
                                                    <td>
                                                        <?php if ($b['bloqueado_hasta']): ?>
                                                            <small class="text-danger"><?php echo date('d/m/Y H:i', strtotime($b['bloqueado_hasta'])); ?></small>
                                                        <?php else: ?>
                                                            <small class="text-muted">Permanente</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <button class="btn btn-success btn-sm" onclick="desbloquearIP('<?php echo htmlspecialchars($b['ip_address']); ?>', <?php echo $b['id']; ?>)">
                                                            <i class="bi bi-unlock"></i> Desbloquear
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab Accesos Temporales -->
                <div class="tab-pane fade" id="accesos" role="tabpanel">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h5 class="mb-0"><i class="bi bi-clock-history text-warning"></i> Accesos Temporales Activos</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Fecha Fin</th>
                                            <th>Motivo</th>
                                            <th>Otorgado Por</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($accesos_temporales)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>
                                                    No hay accesos temporales activos
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($accesos_temporales as $at): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($at['nombre']); ?></td>
                                                    <td><small><?php echo date('d/m/Y H:i', strtotime($at['fecha_fin'])); ?></small></td>
                                                    <td><small><?php echo htmlspecialchars($at['motivo'] ?? 'N/A'); ?></small></td>
                                                    <td><small><?php echo htmlspecialchars($at['otorgado_por_nombre']); ?></small></td>
                                                    <td>
                                                        <button class="btn btn-danger btn-sm" onclick="revocarAccesoTemporal(<?php echo $at['id']; ?>)">
                                                            <i class="bi bi-x-circle"></i> Revocar
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Nuevo Usuario -->
    <div class="modal fade" id="modalNuevoUsuario" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #3b82f6, #2563eb); color: white;">
                    <h5 class="modal-title"><i class="bi bi-person-plus"></i> Nuevo Usuario</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formNuevoUsuario">
                        <div class="mb-3">
                            <label for="nuevoNombre" class="form-label">Nombre Completo *</label>
                            <input type="text" class="form-control" id="nuevoNombre" name="nombre" autocomplete="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="nuevoEmail" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="nuevoEmail" name="email" autocomplete="email" required>
                        </div>
                        <div class="mb-3">
                            <label for="nuevoPassword" class="form-label">Contraseña *</label>
                            <input type="password" class="form-control" id="nuevoPassword" name="password" autocomplete="new-password" required minlength="8">
                            <small class="text-muted">Mínimo 8 caracteres</small>
                        </div>
                        <div class="mb-3">
                            <label for="nuevoTelefono" class="form-label">Teléfono</label>
                            <input type="tel" class="form-control" id="nuevoTelefono" name="telefono" autocomplete="tel">
                        </div>
                        <div class="mb-3">
                            <label for="rolNuevoUsuario" class="form-label">Rol *</label>
                            <select class="form-select" name="rol" id="rolNuevoUsuario" autocomplete="off" required>
                                <option value="">Seleccione...</option>
                                <option value="capturista">Capturista</option>
                                <option value="admin">Administrador</option>
                            </select>
                        </div>
                        <div class="mb-3" id="jurisdiccionContainer">
                            <label for="jurisdiccionNuevoUsuario" class="form-label">Jurisdicción *</label>
                            <select class="form-select" name="jurisdiccion" id="jurisdiccionNuevoUsuario" autocomplete="off">
                                <option value="">Seleccione...</option>
                                <?php
                                $jurisdicciones = jurisdicciones();
                                foreach ($jurisdicciones as $jur) {
                                    echo "<option value=\"$jur\">$jur</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarNuevoUsuario()">
                        <i class="bi bi-save"></i> Guardar Usuario
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Acceso Temporal -->
    <div class="modal fade" id="modalAccesoTemporal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: white;">
                    <h5 class="modal-title"><i class="bi bi-clock-history"></i> Otorgar Acceso Temporal</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="formAccesoTemporal">
                        <input type="hidden" name="usuario_id" id="accesoUsuarioId">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Usuario: <strong id="accesoUsuarioNombre"></strong>
                        </div>
                        <div class="mb-3">
                            <label for="accesoDuracion" class="form-label">Duración del Acceso *</label>
                            <select class="form-select" name="duracion" id="accesoDuracion" autocomplete="off" required>
                                <option value="">Seleccione...</option>
                                <option value="1">1 día</option>
                                <option value="3">3 días</option>
                                <option value="7">7 días (1 semana)</option>
                                <option value="15">15 días</option>
                                <option value="30">30 días (1 mes)</option>
                                <option value="custom">Personalizado...</option>
                            </select>
                        </div>
                        <div class="mb-3 d-none" id="fechaFinContainer">
                            <label for="fechaFinCustom" class="form-label">Fecha y Hora de Fin *</label>
                            <input type="datetime-local" class="form-control" name="fecha_fin_custom" id="fechaFinCustom" autocomplete="off">
                        </div>
                        <div class="mb-3">
                            <label for="accesoMotivo" class="form-label">Motivo *</label>
                            <textarea class="form-control" id="accesoMotivo" name="motivo" rows="3" autocomplete="off" required placeholder="Ej: Completar capturas pendientes del mes anterior"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-warning" onclick="guardarAccesoTemporal()">
                        <i class="bi bi-check-circle"></i> Otorgar Acceso
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Autocompletado tipo Google
        let debounceTimer;
        const inputBusqueda = document.getElementById('filtroBusqueda');
        const resultsContainer = document.getElementById('autocompleteResults');

        inputBusqueda.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            const query = this.value.trim().toLowerCase();

            // --- FILTRADO EN TIEMPO REAL DE LA TABLA ---
            const rows = document.querySelectorAll('table tbody tr');
            rows.forEach(row => {
                if (row.cells.length < 8) return; // Saltar fila de "no resultados"
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });

            if (query.length < 1) {
                resultsContainer.style.display = 'none';
                return;
            }

            // Mostrar estado de carga breve
            resultsContainer.innerHTML = '<div class="list-group-item text-muted small py-3"><span class="spinner-border spinner-border-sm me-2"></span>Buscando...</div>';
            resultsContainer.style.display = 'block';

            debounceTimer = setTimeout(() => {
                // Usar ruta absoluta relativa al origen para evitar líos con slashes en la URL
                const ajaxUrl = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/')) + '/ajax/sugerencias_usuarios.php';

                fetch(`${ajaxUrl}?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        resultsContainer.innerHTML = '';
                        if (data && data.length > 0) {
                            data.forEach(item => {
                                const div = document.createElement('a');
                                div.href = '#';
                                div.className = 'list-group-item list-group-item-action border-0 py-3';
                                div.style.cssText = 'transition: all 0.2s ease; border-bottom: 1px solid #f1f5f9 !important;';
                                div.innerHTML = `
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle bg-primary bg-opacity-10 text-primary d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-weight: bold; font-size: 0.8rem;">
                                            ${item.nombre.charAt(0).toUpperCase()}
                                        </div>
                                        <div class="text-start">
                                            <div class="fw-bold text-dark" style="font-size: 0.9rem;">${item.nombre}</div>
                                            <div class="text-muted" style="font-size: 0.75rem;">${item.email}</div>
                                        </div>
                                    </div>
                                `;
                                div.onclick = (e) => {
                                    e.preventDefault();
                                    inputBusqueda.value = item.nombre;
                                    resultsContainer.style.display = 'none';
                                    aplicarFiltros();
                                };
                                resultsContainer.appendChild(div);
                            });
                            resultsContainer.style.display = 'block';
                            // Asegurar que sea visible (scroll a la vista en móvil)
                            if (window.innerWidth < 768) {
                                resultsContainer.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'nearest'
                                });
                            }
                        } else {
                            resultsContainer.innerHTML = '<div class="list-group-item text-muted small py-3">No se encontraron coincidencias</div>';
                            // Mantener visible un momento para feedback
                            setTimeout(() => {
                                if (inputBusqueda.value.trim().length < 2) resultsContainer.style.display = 'none';
                            }, 2000);
                        }
                    })
                    .catch(err => {
                        console.error('Error fetching suggestions:', err);
                        resultsContainer.style.display = 'none';
                    });
            }, 300);
        });

        // Ocultar resultados al hacer clic fuera
        document.addEventListener('click', function(e) {
            if (!inputBusqueda.contains(e.target) && !resultsContainer.contains(e.target)) {
                resultsContainer.style.display = 'none';
            }
        });

        // Estilos para el autocompletado
        const style = document.createElement('style');
        style.innerHTML = `
            #autocompleteResults {
                border-radius: 12px;
                overflow-y: auto;
                max-height: 300px;
                box-shadow: 0 15px 35px rgba(0,0,0,0.2) !important;
                border: 1px solid #e2e8f0;
                margin-top: 5px;
                background: white;
                z-index: 2000 !important;
            }
            #autocompleteResults .list-group-item:hover {
                background-color: #f8fafc;
                transform: translateX(5px);
                color: #2563eb;
            }
            #autocompleteResults .list-group-item:last-child {
                border-bottom: none !important;
            }
        `;
        document.head.appendChild(style);

        // Fix global para aria-hidden en modales de Bootstrap
        document.addEventListener('DOMContentLoaded', function() {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                modal.addEventListener('show.bs.modal', function() {
                    // Remover aria-hidden cuando el modal se está mostrando
                    this.removeAttribute('aria-hidden');
                });

                modal.addEventListener('shown.bs.modal', function() {
                    // Asegurar que aria-hidden esté removido cuando el modal está visible
                    this.removeAttribute('aria-hidden');
                });

                modal.addEventListener('hide.bs.modal', function() {
                    // Mantener sin aria-hidden mientras se oculta
                    this.removeAttribute('aria-hidden');
                });

                modal.addEventListener('hidden.bs.modal', function() {
                    // Restaurar aria-hidden solo cuando está completamente oculto
                    this.setAttribute('aria-hidden', 'true');
                });
            });
        });

        // Manejo de rol y jurisdicción en nuevo usuario
        document.getElementById('rolNuevoUsuario').addEventListener('change', function() {
            const jurisdiccionContainer = document.getElementById('jurisdiccionContainer');
            const jurisdiccionSelect = document.getElementById('jurisdiccionNuevoUsuario');

            if (this.value === 'admin') {
                jurisdiccionContainer.classList.add('d-none');
                jurisdiccionSelect.removeAttribute('required');
            } else {
                jurisdiccionContainer.classList.remove('d-none');
                jurisdiccionSelect.setAttribute('required', 'required');
            }
        });

        // Manejo de duración personalizada
        document.getElementById('accesoDuracion').addEventListener('change', function() {
            const fechaFinContainer = document.getElementById('fechaFinContainer');
            const fechaFinCustom = document.getElementById('fechaFinCustom');

            if (this.value === 'custom') {
                fechaFinContainer.classList.remove('d-none');
                fechaFinCustom.setAttribute('required', 'required');
            } else {
                fechaFinContainer.classList.add('d-none');
                fechaFinCustom.removeAttribute('required');
            }
        });

        function guardarNuevoUsuario() {
            const form = document.getElementById('formNuevoUsuario');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';

            fetch('ajax/guardar_usuario.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Usuario creado exitosamente');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-save"></i> Guardar Usuario';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al guardar el usuario');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-save"></i> Guardar Usuario';
                });
        }

        function guardarAccesoTemporal() {
            const form = document.getElementById('formAccesoTemporal');
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            const formData = new FormData(form);
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';

            fetch('ajax/guardar_acceso_temporal.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Acceso temporal otorgado exitosamente');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        btn.disabled = false;
                        btn.innerHTML = '<i class="bi bi-check-circle"></i> Otorgar Acceso';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al otorgar acceso');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-check-circle"></i> Otorgar Acceso';
                });
        }

        function aplicarFiltros() {
            const estado = document.getElementById('filtroEstado').value;
            const rol = document.getElementById('filtroRol').value;
            const busqueda = document.getElementById('filtroBusqueda').value;

            const params = new URLSearchParams();
            if (estado !== 'todos') params.append('estado', estado);
            if (rol !== 'todos') params.append('rol', rol);
            if (busqueda) params.append('busqueda', busqueda);

            window.location.href = 'admin_usuarios.php?' + params.toString();
        }

        function desbloquearUsuario(usuarioId) {
            if (!confirm('¿Desbloquear este usuario?')) return;

            const btn = event.target.closest('button');
            if (btn) btn.disabled = true;

            fetch('ajax/desbloquear_usuario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        usuario_id: usuarioId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Usuario desbloqueado');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al desbloquear usuario');
                    if (btn) btn.disabled = false;
                });
        }

        function desbloquearIP(ip, bloqueoId) {
            if (!confirm('¿Desbloquear la IP ' + ip + '?')) return;

            const btn = event.target.closest('button');
            if (btn) btn.disabled = true;

            fetch('ajax/desbloquear_ip.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        bloqueo_id: bloqueoId,
                        ip: ip
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('IP desbloqueada');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al desbloquear IP');
                    if (btn) btn.disabled = false;
                });
        }

        function activarUsuario(usuarioId) {
            console.log('activarUsuario llamado con ID:', usuarioId);
            if (!confirm('¿Activar este usuario?')) {
                console.log('Activación cancelada por el usuario');
                return;
            }

            const btn = event.target.closest('button');
            if (btn) btn.disabled = true;

            console.log('Enviando petición para activar usuario...');
            fetch('ajax/cambiar_estado_usuario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        usuario_id: usuarioId,
                        activo: 1
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Usuario activado');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al activar usuario');
                    if (btn) btn.disabled = false;
                });
        }

        function desactivarUsuario(usuarioId) {
            console.log('desactivarUsuario llamado con ID:', usuarioId);
            const motivo = prompt('Motivo de la desactivación:');
            if (!motivo) {
                console.log('Desactivación cancelada - no se proporcionó motivo');
                return;
            }

            const btn = event.target.closest('button');
            if (btn) btn.disabled = true;

            console.log('Enviando petición para desactivar usuario con motivo:', motivo);
            fetch('ajax/cambiar_estado_usuario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        usuario_id: usuarioId,
                        activo: 0,
                        motivo: motivo
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Usuario desactivado');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al desactivar usuario');
                    if (btn) btn.disabled = false;
                });
        }

        function eliminarUsuario(usuarioId, nombre) {
            if (!confirm('¿ELIMINAR permanentemente al usuario "' + nombre + '"?')) return;
            const motivo = prompt('Motivo de la eliminación:');
            if (!motivo) return;

            const btn = event.target.closest('button');
            if (btn) btn.disabled = true;

            fetch('ajax/eliminar_usuario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        usuario_id: usuarioId,
                        motivo: motivo
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Usuario eliminado');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al eliminar usuario');
                    if (btn) btn.disabled = false;
                });
        }

        function otorgarAccesoTemporal(usuarioId, nombre) {
            document.getElementById('accesoUsuarioId').value = usuarioId;
            document.getElementById('accesoUsuarioNombre').textContent = nombre;
            const modal = new bootstrap.Modal(document.getElementById('modalAccesoTemporal'));
            modal.show();
        }

        function revocarAccesoTemporal(accesoId) {
            if (!confirm('¿Revocar este acceso temporal?')) return;

            const btn = event.target.closest('button');
            if (btn) btn.disabled = true;

            fetch('ajax/revocar_acceso_temporal.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        acceso_id: accesoId
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Acceso revocado');
                        setTimeout(() => location.reload(), 100);
                    } else {
                        alert('Error: ' + data.message);
                        if (btn) btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al revocar acceso');
                    if (btn) btn.disabled = false;
                });
        }
    </script>
</body>

</html>