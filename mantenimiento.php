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

// Solo admin puede acceder
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: inicio.php');
    exit;
}

// Archivo de configuración del sistema
$config_file = __DIR__ . '/config_sistema.json';

// Cargar configuración actual
function cargarConfiguracion($config_file) {
    if (file_exists($config_file)) {
        $json = file_get_contents($config_file);
        return json_decode($json, true) ?? [];
    }
    return [
        'registro_publico' => true,
        'modo_mantenimiento' => false,
        'mensaje_mantenimiento' => 'El sistema se encuentra en mantenimiento. Por favor, intente más tarde.',
        'fecha_mantenimiento' => null
    ];
}

// Guardar configuración
function guardarConfiguracion($config_file, $config) {
    return file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
}

$config = cargarConfiguracion($config_file);
$success = null;
$error = null;

// Procesar formulario ANTES de check_mantenimiento para que lea el estado actualizado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'toggle_registro':
                $config['registro_publico'] = ($_POST['registro_publico'] ?? '0') === '1';
                if (guardarConfiguracion($config_file, $config)) {
                    $success = $config['registro_publico'] 
                        ? 'Registro público habilitado correctamente.' 
                        : 'Registro público deshabilitado correctamente.';
                } else {
                    $error = 'Error al guardar la configuración.';
                }
                break;
                
            case 'toggle_mantenimiento':
                $config['modo_mantenimiento'] = ($_POST['modo_mantenimiento'] ?? '0') === '1';
                $config['mensaje_mantenimiento'] = trim($_POST['mensaje_mantenimiento'] ?? '');
                if (empty($config['mensaje_mantenimiento'])) {
                    $config['mensaje_mantenimiento'] = 'El sistema se encuentra en mantenimiento. Por favor, intente más tarde.';
                }
                $config['fecha_mantenimiento'] = $config['modo_mantenimiento'] ? date('Y-m-d H:i:s') : null;
                
                if (guardarConfiguracion($config_file, $config)) {
                    $success = $config['modo_mantenimiento'] 
                        ? 'Modo mantenimiento ACTIVADO. Los usuarios no podrán acceder, pero los administradores sí.' 
                        : 'Modo mantenimiento DESACTIVADO. El sistema está disponible para todos.';
                } else {
                    $error = 'Error al guardar la configuración.';
                }
                break;
        }
    }
}

// Recargar config después de posibles cambios
$config = cargarConfiguracion($config_file);

// Incluir check_mantenimiento DESPUÉS de procesar el formulario
require_once __DIR__ . '/includes/check_mantenimiento.php';
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <title>Mantenimiento - Sistema Zoonosis</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <link href="assets/css/dark-mode.css" rel="stylesheet">
    <style>
        .config-card {
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        .config-card:hover {
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        .config-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        .config-icon {
            width: 56px;
            height: 56px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        .config-icon.registro {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }
        .config-icon.mantenimiento {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
        }
        .config-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
            margin: 0;
        }
        .config-subtitle {
            font-size: 0.85rem;
            color: #64748b;
            margin: 0;
        }
        .toggle-switch {
            position: relative;
            width: 60px;
            height: 32px;
        }
        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #cbd5e1;
            transition: 0.3s;
            border-radius: 32px;
        }
        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 24px;
            width: 24px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: 0.3s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        input:checked + .toggle-slider {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }
        input:checked + .toggle-slider.warning {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        input:checked + .toggle-slider:before {
            transform: translateX(28px);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-badge.active {
            background: #dcfce7;
            color: #166534;
        }
        .status-badge.inactive {
            background: #fee2e2;
            color: #991b1b;
        }
        .status-badge.warning {
            background: #fee2e2;
            color: #991b1b;
        }
        .mensaje-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.9rem;
            resize: vertical;
            min-height: 100px;
            transition: all 0.2s;
        }
        .mensaje-textarea:focus {
            outline: none;
            border-color: #f59e0b;
        }
        .info-box {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        .info-box i {
            color: #2563eb;
        }
        .warning-box {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            border: 1px solid #f87171;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        .warning-box i {
            color: #991b1b;
        }
        .btn-save {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.3);
            color: white;
        }
        .btn-save.warning {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        }
        .btn-save.warning:hover {
            box-shadow: 0 6px 16px rgba(245, 158, 11, 0.3);
        }
        
        /* RESPONSIVO */
        @media (max-width: 992px) {
            .config-card{padding:1rem}
            .config-grid{grid-template-columns:1fr}
        }
        @media (max-width: 576px) {
            .config-card{padding:0.75rem}
            .config-card h5{font-size:1rem}
            .form-control{padding:8px 12px}
            .btn{padding:8px 12px;font-size:0.85rem;width:100%}
        }
    </style>
</head>
<body class="theme-metas">
    <div class="app-shell">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="page-title" style="color: white; margin-bottom: 0.5rem;">
                        <i class="fas fa-tools me-2"></i>Mantenimiento del Sistema
                    </h1>
                    <p class="page-subtitle" style="color: rgba(255, 255, 255, 0.9); margin: 0;">Configura opciones de acceso y disponibilidad del sistema</p>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Registro Público -->
            <div class="col-lg-6">
                <form method="POST" id="formRegistro">
                    <input type="hidden" name="action" value="toggle_registro">
                    <input type="hidden" name="registro_publico" id="inputRegistro" value="<?php echo $config['registro_publico'] ? '1' : '0'; ?>">
                    <div class="config-card">
                        <div class="config-header">
                            <div class="config-icon registro">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <div>
                                <h5 class="config-title">Registro Público</h5>
                                <p class="config-subtitle">Permite que nuevos usuarios se registren</p>
                            </div>
                        </div>
                        
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="status-badge <?php echo $config['registro_publico'] ? 'active' : 'inactive'; ?>" id="badgeRegistro">
                                <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                <span id="badgeRegistroText"><?php echo $config['registro_publico'] ? 'Habilitado' : 'Deshabilitado'; ?></span>
                            </span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="toggleRegistro" <?php echo $config['registro_publico'] ? 'checked' : ''; ?> onchange="mostrarModalRegistro(this)">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="info-box">
                            <small>
                                <i class="fas fa-info-circle me-2"></i>
                                <span id="infoRegistro">
                                <?php if ($config['registro_publico']): ?>
                                    Los usuarios pueden crear nuevas cuentas desde la página de inicio.
                                <?php else: ?>
                                    Solo los administradores pueden crear nuevas cuentas de usuario.
                                <?php endif; ?>
                                </span>
                            </small>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Modo Mantenimiento -->
            <div class="col-lg-6" id="containerMantenimiento">
                <form method="POST" id="formMantenimiento">
                    <input type="hidden" name="action" value="toggle_mantenimiento">
                    <input type="hidden" name="modo_mantenimiento" id="inputMantenimiento" value="<?php echo $config['modo_mantenimiento'] ? '1' : '0'; ?>">
                    <input type="hidden" name="mensaje_mantenimiento" id="inputMensaje" value="<?php echo htmlspecialchars($config['mensaje_mantenimiento']); ?>">
                    <div class="config-card">
                        <div class="config-header">
                            <div class="config-icon mantenimiento">
                                <i class="fas fa-hard-hat"></i>
                            </div>
                            <div>
                                <h5 class="config-title">Modo Mantenimiento</h5>
                                <p class="config-subtitle">Bloquea el acceso al sistema temporalmente</p>
                            </div>
                        </div>
                        
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="status-badge <?php echo $config['modo_mantenimiento'] ? 'warning' : 'active'; ?>" id="badgeMantenimiento">
                                <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                <span id="badgeMantenimientoText"><?php echo $config['modo_mantenimiento'] ? 'En Mantenimiento' : 'Sistema Activo'; ?></span>
                            </span>
                            <label class="toggle-switch">
                                <input type="checkbox" id="toggleMantenimiento" <?php echo $config['modo_mantenimiento'] ? 'checked' : ''; ?> onchange="mostrarModalMantenimiento(this)">
                                <span class="toggle-slider warning"></span>
                            </label>
                        </div>

                        <?php if ($config['modo_mantenimiento'] && $config['fecha_mantenimiento']): ?>
                        <div class="mb-3">
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>
                                Activado: <?php echo date('d/m/Y H:i', strtotime($config['fecha_mantenimiento'])); ?>
                            </small>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Mensaje para usuarios:</label>
                            <textarea id="mensajeMantenimiento" class="mensaje-textarea" placeholder="Mensaje que verán los usuarios..."><?php echo htmlspecialchars($config['mensaje_mantenimiento']); ?></textarea>
                        </div>

                        <div class="warning-box">
                            <small>
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Advertencia:</strong> Al activar el modo mantenimiento, ningún usuario (excepto administradores) podrá acceder al sistema.
                            </small>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
    </div>

    <!-- Modal Registro Público -->
    <div class="modal fade" id="modalRegistro" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header" style="background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); color: white; border-radius: 16px 16px 0 0;">
                    <h5 class="modal-title">
                        <i class="fas fa-user-plus me-2"></i>
                        <span id="modalRegistroTitulo">Cambiar Registro Público</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div id="modalRegistroIcono" style="width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 2rem;">
                            <i class="fas fa-user-slash"></i>
                        </div>
                        <h5 id="modalRegistroAccion" class="mb-2"></h5>
                        <p id="modalRegistroDescripcion" class="text-muted mb-0"></p>
                    </div>
                    <div id="modalRegistroInfo" class="p-3 rounded-3 mb-3">
                        <h6 class="fw-bold mb-2"><i class="fas fa-info-circle me-2"></i>¿Qué sucederá?</h6>
                        <ul class="mb-0 ps-3" id="modalRegistroLista"></ul>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn btn-secondary" id="btnCancelarRegistro">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="button" class="btn" id="btnConfirmarRegistro">
                        <i class="fas fa-check me-2"></i>Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Modo Mantenimiento -->
    <div class="modal fade" id="modalMantenimiento" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content" style="border-radius: 16px; border: none;">
                <div class="modal-header" id="modalMantenimientoHeader" style="color: white; border-radius: 16px 16px 0 0;">
                    <h5 class="modal-title">
                        <i class="fas fa-hard-hat me-2"></i>
                        <span id="modalMantenimientoTitulo">Modo Mantenimiento</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="text-center mb-4">
                        <div id="modalMantenimientoIcono" style="width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 2rem;">
                            <i id="modalMantenimientoIconoI" class="fas fa-tools"></i>
                        </div>
                        <h5 id="modalMantenimientoAccion" class="mb-2"></h5>
                        <p id="modalMantenimientoDescripcion" class="text-muted mb-0"></p>
                    </div>
                    <div id="modalMantenimientoInfo" class="p-3 rounded-3 mb-3">
                        <h6 class="fw-bold mb-2"><i class="fas fa-exclamation-triangle me-2"></i>¿Qué sucederá?</h6>
                        <ul class="mb-0 ps-3" id="modalMantenimientoLista"></ul>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid #e2e8f0;">
                    <button type="button" class="btn btn-secondary" id="btnCancelarMantenimiento">
                        <i class="fas fa-times me-2"></i>Cancelar
                    </button>
                    <button type="button" class="btn" id="btnConfirmarMantenimiento">
                        <i class="fas fa-check me-2"></i>Confirmar
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let modalRegistro, modalMantenimiento;
        let nuevoEstadoRegistro, nuevoEstadoMantenimiento;
        const estadoActualRegistro = <?php echo $config['registro_publico'] ? 'true' : 'false'; ?>;
        const estadoActualMantenimiento = <?php echo $config['modo_mantenimiento'] ? 'true' : 'false'; ?>;

        document.addEventListener('DOMContentLoaded', function() {
            modalRegistro = new bootstrap.Modal(document.getElementById('modalRegistro'));
            modalMantenimiento = new bootstrap.Modal(document.getElementById('modalMantenimiento'));

            // Event listeners para botones de Registro
            document.getElementById('btnCancelarRegistro').addEventListener('click', function() {
                document.getElementById('toggleRegistro').checked = estadoActualRegistro;
                modalRegistro.hide();
            });

            document.getElementById('btnConfirmarRegistro').addEventListener('click', function() {
                document.getElementById('inputRegistro').value = nuevoEstadoRegistro ? '1' : '0';
                modalRegistro.hide();
                document.getElementById('formRegistro').submit();
            });

            // Event listeners para botones de Mantenimiento
            document.getElementById('btnCancelarMantenimiento').addEventListener('click', function() {
                document.getElementById('toggleMantenimiento').checked = estadoActualMantenimiento;
                modalMantenimiento.hide();
            });

            document.getElementById('btnConfirmarMantenimiento').addEventListener('click', function() {
                document.getElementById('inputMantenimiento').value = nuevoEstadoMantenimiento ? '1' : '0';
                document.getElementById('inputMensaje').value = document.getElementById('mensajeMantenimiento').value;
                modalMantenimiento.hide();
                document.getElementById('formMantenimiento').submit();
            });

            // Restaurar estado al cerrar modal con X o clic fuera
            document.getElementById('modalRegistro').addEventListener('hidden.bs.modal', function () {
                document.getElementById('toggleRegistro').checked = estadoActualRegistro;
            });

            document.getElementById('modalMantenimiento').addEventListener('hidden.bs.modal', function () {
                document.getElementById('toggleMantenimiento').checked = estadoActualMantenimiento;
            });
        });

        function mostrarModalRegistro(checkbox) {
            nuevoEstadoRegistro = checkbox.checked;
            
            const icono = document.getElementById('modalRegistroIcono');
            const accion = document.getElementById('modalRegistroAccion');
            const descripcion = document.getElementById('modalRegistroDescripcion');
            const info = document.getElementById('modalRegistroInfo');
            const lista = document.getElementById('modalRegistroLista');
            const btnConfirmar = document.getElementById('btnConfirmarRegistro');

            if (nuevoEstadoRegistro) {
                icono.style.background = 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)';
                icono.style.color = '#166534';
                icono.innerHTML = '<i class="fas fa-user-plus"></i>';
                accion.textContent = 'Habilitar Registro Público';
                accion.style.color = '#166534';
                descripcion.textContent = 'Los nuevos usuarios podrán crear cuentas desde la página de inicio.';
                info.style.background = 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)';
                info.style.border = '1px solid #86efac';
                lista.innerHTML = '<li>Cualquier persona podrá registrarse en el sistema</li><li>Se mostrará el enlace "Regístrate" en la página de inicio</li><li>Los nuevos usuarios tendrán rol de capturista por defecto</li>';
                btnConfirmar.className = 'btn btn-success';
            } else {
                icono.style.background = 'linear-gradient(135deg, #fee2e2 0%, #fecaca 100%)';
                icono.style.color = '#991b1b';
                icono.innerHTML = '<i class="fas fa-user-slash"></i>';
                accion.textContent = 'Deshabilitar Registro Público';
                accion.style.color = '#991b1b';
                descripcion.textContent = 'Solo los administradores podrán crear nuevas cuentas.';
                info.style.background = 'linear-gradient(135deg, #fee2e2 0%, #fecaca 100%)';
                info.style.border = '1px solid #fca5a5';
                lista.innerHTML = '<li>Se ocultará el enlace de registro en la página de inicio</li><li>Nadie podrá crear cuentas nuevas por su cuenta</li><li>Solo administradores podrán agregar usuarios manualmente</li>';
                btnConfirmar.className = 'btn btn-danger';
            }

            modalRegistro.show();
        }

        function mostrarModalMantenimiento(checkbox) {
            nuevoEstadoMantenimiento = checkbox.checked;
            
            const header = document.getElementById('modalMantenimientoHeader');
            const icono = document.getElementById('modalMantenimientoIcono');
            const iconoI = document.getElementById('modalMantenimientoIconoI');
            const accion = document.getElementById('modalMantenimientoAccion');
            const descripcion = document.getElementById('modalMantenimientoDescripcion');
            const info = document.getElementById('modalMantenimientoInfo');
            const lista = document.getElementById('modalMantenimientoLista');
            const btnConfirmar = document.getElementById('btnConfirmarMantenimiento');

            if (nuevoEstadoMantenimiento) {
                header.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                icono.style.background = 'linear-gradient(135deg, #fee2e2 0%, #fecaca 100%)';
                icono.style.color = '#991b1b';
                iconoI.className = 'fas fa-lock';
                accion.textContent = 'Activar Modo Mantenimiento';
                accion.style.color = '#991b1b';
                descripcion.textContent = 'El sistema quedará bloqueado para usuarios normales.';
                info.style.background = 'linear-gradient(135deg, #fee2e2 0%, #fecaca 100%)';
                info.style.border = '1px solid #f87171';
                lista.innerHTML = '<li><strong>Los usuarios NO podrán iniciar sesión</strong></li><li>Se mostrará el mensaje de mantenimiento personalizado</li><li>Solo los administradores podrán acceder al sistema</li><li>Los usuarios activos serán desconectados al cerrar sesión</li>';
                btnConfirmar.className = 'btn btn-danger';
                btnConfirmar.style.color = 'white';
            } else {
                header.style.background = 'linear-gradient(135deg, #22c55e 0%, #16a34a 100%)';
                icono.style.background = 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)';
                icono.style.color = '#166534';
                iconoI.className = 'fas fa-unlock';
                accion.textContent = 'Desactivar Modo Mantenimiento';
                accion.style.color = '#166534';
                descripcion.textContent = 'El sistema volverá a estar disponible para todos.';
                info.style.background = 'linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%)';
                info.style.border = '1px solid #86efac';
                lista.innerHTML = '<li>El sistema estará disponible para todos los usuarios</li><li>Se quitará el banner de mantenimiento</li><li>Los usuarios podrán iniciar sesión normalmente</li>';
                btnConfirmar.className = 'btn btn-success';
                btnConfirmar.style.color = 'white';
            }

            modalMantenimiento.show();
        }
    </script>
</body>
</html>
