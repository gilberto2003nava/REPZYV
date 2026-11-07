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

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

// Cargar año activo del config
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_actual = $config_sistema['anio_activo'] ?? date('Y');

$rol = $_SESSION['rol'];
// Permitir acceso a capturistas, supervisores y admin (aunque admin tiene su propio metas.php)
if (!in_array($rol, ['capturista', 'supervisor', 'admin'])) {
    header('Location: panel.php');
    exit;
}

// Obtener jurisdicción del usuario
$jurisdiccionUsuario = $_SESSION['jurisdiccion'] ?? '';
if (empty($jurisdiccionUsuario)) {
    // Si no tiene jurisdicción, no puede ver metas específicas (o mostrar error)
    $error = "No tienes una jurisdicción asignada. Contacta al administrador.";
}

// Obtener todas las metas para SU jurisdicción
$tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
$tipos_hospitales = ['agredidos', 'tratamientos']; // Hospitales solo tienen estos 2 tipos

$metas_organizadas = [];
$municipios_con_metas = [];
$hospitales_con_metas = [];

if (!empty($jurisdiccionUsuario)) {
    $sql = "SELECT m.*, u.nombre as creador_nombre 
            FROM metas m 
            LEFT JOIN usuarios u ON m.creada_por = u.id 
            WHERE m.anio = ? AND m.jurisdiccion = ?
            ORDER BY m.municipio, m.tipo";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'is', $anio_actual, $jurisdiccionUsuario);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    while ($meta = mysqli_fetch_assoc($result)) {
        $muni = $meta['municipio'];
        $tipo = $meta['tipo'];

        if (!isset($metas_organizadas[$muni])) {
            $metas_organizadas[$muni] = [];
        }
        $metas_organizadas[$muni][$tipo] = $meta;
        
        // Clasificar como hospital o municipio basado en el nombre
        if (preg_match('/^H\./i', $muni) || preg_match('/^H\.G\./i', $muni) || preg_match('/^H\.M\./i', $muni)) {
            if (!in_array($muni, $hospitales_con_metas)) {
                $hospitales_con_metas[] = $muni;
            }
        } else {
            if (!in_array($muni, $municipios_con_metas)) {
                $municipios_con_metas[] = $muni;
            }
        }
    }
}

// Ordenar alfabéticamente
sort($municipios_con_metas);
sort($hospitales_con_metas);

// Usar los municipios/hospitales de la BD si hay datos, sino usar los de funciones.php
$municipios_juris = !empty($municipios_con_metas) ? $municipios_con_metas : (!empty($jurisdiccionUsuario) ? municipiosPorJurisdiccion($jurisdiccionUsuario) : []);
$hospitales_juris = !empty($hospitales_con_metas) ? $hospitales_con_metas : [];

// Si no hay metas pero sí hay jurisdicción, obtener lista de lugares de funciones.php
if (empty($metas_organizadas) && !empty($jurisdiccionUsuario)) {
    $municipios_juris = municipiosPorJurisdiccion($jurisdiccionUsuario);
    $lugares_con_hospitales = municipiosPorJurisdiccion($jurisdiccionUsuario, 'agredidos');
    $hospitales_juris = array_diff($lugares_con_hospitales, $municipios_juris);
}

?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>Mis Metas - Sistema Hospitalario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <link href="assets/css/dark-mode.css" rel="stylesheet">
    <style>
        /* Estilos específicos reutilizados de metas.php pero simplificados */
        .lugar-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }

        .lugar-header {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 10px;
        }

        .lugar-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 1.2rem;
        }

        .lugar-icon.municipio {
            background: #ede9fe;
            color: #8b5cf6;
        }

        .lugar-icon.hospital {
            background: #e0f2fe;
            color: #0ea5e9;
        }

        .lugar-title {
            font-weight: 600;
            color: #1e293b;
            font-size: 1.1rem;
        }

        .lugar-subtitle {
            color: #64748b;
            font-size: 0.85rem;
        }

        .metas-grid-view {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 15px;
        }

        .meta-card {
            background: #f8fafc;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            border: 1px solid transparent;
        }

        .meta-card.active {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .meta-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .meta-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
        }

        .meta-card.active .meta-number {
            color: #15803d;
        }
        
        /* RESPONSIVO */
        @media (max-width: 992px) {
            .lugar-grid{grid-template-columns:1fr}
        }
        @media (max-width: 576px) {
            .lugar-item{padding:12px}
            .lugar-item h4{font-size:0.95rem}
            .tipo-grid{grid-template-columns:1fr 1fr}
            .tipo-item{padding:8px;font-size:0.8rem}
        }
    </style>
</head>

<body class="theme-metas">
    <div class="app-shell">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
            <div>
                <h1 class="page-title" style="color: white; margin-bottom: 0.5rem;">
                    <i class="fas fa-bullseye me-2"></i>
                    Mis Metas Asignadas
                </h1>
                <p class="page-subtitle" style="color: rgba(255, 255, 255, 0.9); margin: 0;">Jurisdicción: <strong><?php echo htmlspecialchars($jurisdiccionUsuario); ?></strong> | Año: <?php echo $anio_actual; ?></p>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i><?php echo $error; ?>
            </div>
        <?php else: ?>

            <!-- Resumen Rápido -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="glass-card p-3 d-flex align-items-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3 text-primary">
                            <i class="fas fa-bullseye fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="mb-0">
                                <?php
                                $total_metas = 0;
                                foreach ($metas_organizadas as $m) $total_metas += count($m);
                                echo $total_metas;
                                ?>
                            </h3>
                            <span class="text-muted">Metas Establecidas</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-card p-3 d-flex align-items-center">
                        <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3 text-success">
                            <i class="fas fa-city fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?php echo count($municipios_juris); ?></h3>
                            <span class="text-muted">Municipios</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="glass-card p-3 d-flex align-items-center">
                        <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3 text-info">
                            <i class="fas fa-hospital fa-2x"></i>
                        </div>
                        <div>
                            <h3 class="mb-0"><?php echo count($hospitales_juris); ?></h3>
                            <span class="text-muted">Hospitales</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Lista de Municipios -->
            <h5 class="mb-3 text-secondary border-bottom pb-2">Municipios</h5>
            <?php foreach ($municipios_juris as $municipio): ?>
                <div class="lugar-item">
                    <div class="lugar-header mb-3">
                        <div class="lugar-icon municipio"><i class="fas fa-city"></i></div>
                        <div>
                            <div class="lugar-title"><?php echo $municipio; ?></div>
                            <div class="lugar-subtitle">Municipio</div>
                        </div>
                    </div>
                    <div class="metas-grid-view">
                        <?php foreach ($tipos as $tipo):
                            $meta = $metas_organizadas[$municipio][$tipo] ?? null;
                            $isActive = $meta ? 'active' : '';
                        ?>
                            <div class="meta-card <?php echo $isActive; ?>">
                                <div class="meta-label"><?php echo ucfirst($tipo); ?></div>
                                <div class="meta-number">
                                    <?php echo $meta ? number_format($meta['meta']) : '-'; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Lista de Hospitales -->
            <?php if (!empty($hospitales_juris)): ?>
                <h5 class="mb-3 text-secondary border-bottom pb-2 mt-4">Hospitales</h5>
                <?php foreach ($hospitales_juris as $hospital): ?>
                    <div class="lugar-item">
                        <div class="lugar-header mb-3">
                            <div class="lugar-icon hospital"><i class="fas fa-hospital"></i></div>
                            <div>
                                <div class="lugar-title"><?php echo $hospital; ?></div>
                                <div class="lugar-subtitle">Hospital</div>
                            </div>
                        </div>
                        <div class="metas-grid-view">
                            <?php foreach ($tipos_hospitales as $tipo):
                                $meta = $metas_organizadas[$hospital][$tipo] ?? null;
                                $isActive = $meta ? 'active' : '';
                            ?>
                                <div class="meta-card <?php echo $isActive; ?>">
                                    <div class="meta-label"><?php echo ucfirst($tipo); ?></div>
                                    <div class="meta-number">
                                        <?php echo $meta ? number_format($meta['meta']) : '-'; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="alert alert-light text-center text-muted">No hay hospitales registrados en esta jurisdicción.</div>
            <?php endif; ?>

        <?php endif; ?>
    </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>