<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/security.php';
establecerHeadersNoCache();

require_once __DIR__ . '/partials/check_session.php';

require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/check_mantenimiento.php';

// Cargar año activo del config
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anioActual = $config_sistema['anio_activo'] ?? date('Y');

$rol = $_SESSION['rol'];

$mensaje = $_SESSION['mensaje'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

$statsJurisdiccion = [];
$statsMunicipio = [];
$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

$where = 'WHERE anio = ?';
$params = [$anioActual];
$types = 'i';
if ($rol !== 'admin') {
    $where .= ' AND jurisdiccion = ?';
    $params[] = $_SESSION['jurisdiccion'];
    $types .= 's';
}

$sql = "SELECT jurisdiccion, municipio, meta_anual, " . implode(',', $meses) . " FROM registros {$where}";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($result)) {
    $avance = 0;
    foreach ($meses as $mes) {
        $avance += (int) ($row[$mes] ?? 0);
    }
    $meta = max(0, (int) $row['meta_anual']);

    $jurisKey = $row['jurisdiccion'];
    if (!isset($statsJurisdiccion[$jurisKey])) {
        $statsJurisdiccion[$jurisKey] = ['meta' => 0, 'avance' => 0];
    }
    $statsJurisdiccion[$jurisKey]['meta'] += $meta;
    $statsJurisdiccion[$jurisKey]['avance'] += $avance;

    $munKey = $row['municipio'] ?: 'Sin municipio';
    if (!isset($statsMunicipio[$munKey])) {
        $statsMunicipio[$munKey] = ['meta' => 0, 'avance' => 0];
    }
    $statsMunicipio[$munKey]['meta'] += $meta;
    $statsMunicipio[$munKey]['avance'] += $avance;
}

$calcPercent = function ($avance, $meta) {
    return $meta > 0 ? round(($avance / $meta) * 100, 1) : 0;
};

// Calcular totales generales
$metaTotal = 0;
$avanceTotal = 0;
foreach ($statsJurisdiccion as $juris => $valores) {
    $percent = $calcPercent($valores['avance'], $valores['meta']);
    $statsJurisdiccion[$juris]['percent'] = min(100, $percent);
    $metaTotal += $valores['meta'];
    $avanceTotal += $valores['avance'];
}

// Calcular porcentajes para municipios
foreach ($statsMunicipio as $muni => $valores) {
    $percent = $calcPercent($valores['avance'], $valores['meta']);
    $statsMunicipio[$muni]['percent'] = min(100, $percent);
}

// Porcentaje de avance general
$porcentajeGeneral = $calcPercent($avanceTotal, $metaTotal);

// Para capturista: calcular totales de su jurisdicción
$metaCapturista = 0;
$avanceCapturista = 0;
foreach ($statsMunicipio as $muni => $valores) {
    $metaCapturista += $valores['meta'];
    $avanceCapturista += $valores['avance'];
}
$porcentajeCapturista = $calcPercent($avanceCapturista, $metaCapturista);

$chartLabels = array_keys($statsJurisdiccion);
$chartData = array_map(fn($j) => min(100, $j['percent']), $statsJurisdiccion);

$municipioLabels = array_keys($statsMunicipio);
$municipioData = array_map(fn($m) => $calcPercent($m['avance'], $m['meta']), $statsMunicipio);

if (empty($chartLabels)) {
    $chartLabels = ['Sin datos'];
    $chartData = [0];
}
if (empty($municipioLabels)) {
    $municipioLabels = ['Sin datos'];
    $municipioData = [0];
}

$bitacora = [];
if ($rol === 'admin') {
    $sqlBit = "SELECT b.fecha, b.accion, b.detalles, b.exito, u.nombre 
               FROM bitacora b 
               LEFT JOIN usuarios u ON u.id = b.usuario_id 
               ORDER BY b.fecha DESC 
               LIMIT 15";
    if ($resBit = mysqli_query($conn, $sqlBit)) {
        while ($row = mysqli_fetch_assoc($resBit)) {
            $bitacora[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Panel - REPZYV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Optimización: Cache Busting para discos HDD -->
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                    <div>
                        <h1 style="color: white; margin-bottom: 0.5rem;">
                            <i class="fas fa-home me-2"></i>
                            Hola, <?= htmlspecialchars($_SESSION['nombre']) ?>
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0;">Panel central de seguimiento <?= $rol === 'admin' ? 'administrativo' : 'de captura' ?>.</p>
                    </div>
                    <div style="display: flex; gap: 0.75rem; align-items: center; flex-wrap: wrap;">
                        <!-- Manuales -->
                        <a href="storage/pdfs/Manual_Usuario_Final_2026-01-26_08-39-07.pdf" target="_blank"
                            style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 0.5rem 1rem; border-radius: 8px; color: white; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s; border: 1px solid rgba(255, 255, 255, 0.3);"
                            onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'"
                            onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'">
                            <i class="fas fa-book"></i>
                            <span style="font-size: 0.9rem;">Manual de Usuario</span>
                        </a>
                        <a href="storage/pdfs/Manual_Tecnico_COMPLETO_2026-01-26_09-53-30.pdf" target="_blank"
                            style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 0.5rem 1rem; border-radius: 8px; color: white; text-decoration: none; display: flex; align-items: center; gap: 0.5rem; transition: all 0.3s; border: 1px solid rgba(255, 255, 255, 0.3);"
                            onmouseover="this.style.background='rgba(255, 255, 255, 0.3)'"
                            onmouseout="this.style.background='rgba(255, 255, 255, 0.2)'">
                            <i class="fas fa-code"></i>
                            <span style="font-size: 0.9rem;">Manual Técnico</span>
                        </a>
                        <!-- Contacto -->
                        <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.25); display: flex; align-items: center; gap: 0.5rem;">
                            <i class="fas fa-phone" style="color: rgba(255, 255, 255, 0.9);"></i>
                            <a href="tel:5669563208" style="color: white; text-decoration: none; font-size: 0.9rem; font-weight: 600;">
                                Gilberto Velázquez - 566 956 3208
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <section class="stats-grid">
                <div class="stat-card">
                    <span class="label">Año en curso</span>
                    <div class="value"><?= $anioActual ?></div>
                </div>
                <div class="stat-card">
                    <span class="label"><?= $rol === 'admin' ? 'Jurisdicciones monitoreadas' : 'Municipios asignados' ?></span>
                    <div class="value"><?= $rol === 'admin' ? count($statsJurisdiccion) : count($statsMunicipio) ?></div>
                </div>
                <div class="stat-card">
                    <span class="label">Meta anual <?= $rol === 'admin' ? 'total' : '' ?></span>
                    <div class="value"><?= number_format($rol === 'admin' ? $metaTotal : $metaCapturista) ?></div>
                </div>
                <div class="stat-card">
                    <span class="label">Avance acumulado</span>
                    <div class="value">
                        <?= number_format($rol === 'admin' ? $avanceTotal : $avanceCapturista) ?>
                        <small style="font-size:0.5em;color:var(--text-muted);margin-left:4px;">(<?= $rol === 'admin' ? $porcentajeGeneral : $porcentajeCapturista ?>%)</small>
                    </div>
                </div>
            </section>

            <section class="quick-actions-section">
                <h3 style="margin-bottom: 20px; color: var(--dark);">Acciones rápidas</h3>
                <div class="quick-actions-grid">
                    <a class="quick-action-card" href="capturar_mes.php" style="--card-color: #2563eb; --card-bg: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%);">
                        <div class="quick-action-icon">
                            <i class="fas fa-calendar-plus"></i>
                        </div>
                        <div class="quick-action-content">
                            <span class="quick-action-title">Nueva captura</span>
                            <span class="quick-action-desc">Registrar datos del mes</span>
                        </div>
                    </a>
                    <a class="quick-action-card" href="graficas.php" style="--card-color: #8b5cf6; --card-bg: linear-gradient(135deg, #8b5cf6 0%, #a78bfa 100%);">
                        <div class="quick-action-icon">
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="quick-action-content">
                            <span class="quick-action-title">Gráficas</span>
                            <span class="quick-action-desc">Ver avances por jurisdicción</span>
                        </div>
                    </a>
                    <a class="quick-action-card" href="historial_mes.php" style="--card-color: #06b6d4; --card-bg: linear-gradient(135deg, #06b6d4 0%, #22d3ee 100%);">
                        <div class="quick-action-icon">
                            <i class="fas fa-history"></i>
                        </div>
                        <div class="quick-action-content">
                            <span class="quick-action-title">Historial</span>
                            <span class="quick-action-desc">Consultar repositorio</span>
                        </div>
                    </a>
                    <?php if ($rol === 'admin'): ?>
                        <a class="quick-action-card" href="metas.php" style="--card-color: #10b981; --card-bg: linear-gradient(135deg, #10b981 0%, #34d399 100%);">
                            <div class="quick-action-icon">
                                <i class="fas fa-bullseye"></i>
                            </div>
                            <div class="quick-action-content">
                                <span class="quick-action-title">Metas</span>
                                <span class="quick-action-desc">Administrar metas</span>
                            </div>
                        </a>
                        <a class="quick-action-card" href="cerrar_anio.php" style="--card-color: #f59e0b; --card-bg: linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%);">
                            <div class="quick-action-icon">
                                <i class="fas fa-calendar-times"></i>
                            </div>
                            <div class="quick-action-content">
                                <span class="quick-action-title">Cerrar año</span>
                                <span class="quick-action-desc">Bloquear edición</span>
                            </div>
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($rol === 'admin'): ?>
                <section class="glass-card">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                        <h3 style="margin:0;">Bitácora reciente</h3>
                        <a class="btn btn-outline" href="bitacora.php">Ver bitácora completa</a>
                    </div>
                    <p style="color:var(--text-muted);margin-bottom:12px;">Últimos movimientos registrados en el sistema.</p>
                    <?php if ($bitacora): ?>
                        <div style="max-height:320px;overflow:auto;">
                            <table class="table-modern">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Usuario</th>
                                        <th>Acción</th>
                                        <th>Detalles</th>
                                        <th>Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bitacora as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($row['fecha']) ?></td>
                                            <td><?= htmlspecialchars($row['nombre'] ?? 'Sistema') ?></td>
                                            <td><?= htmlspecialchars($row['accion']) ?></td>
                                            <td style="max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                                <?= htmlspecialchars($row['detalles'] ?? '') ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$row['exito'] === 1): ?>
                                                    <span class="badge badge-success">Éxito</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger">Error</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p style="color:var(--text-muted);">Aún no hay registros en la bitácora.</p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
    </div>

    <?php include __DIR__ . '/partials/modal_terminos.php'; ?>
</body>

</html>