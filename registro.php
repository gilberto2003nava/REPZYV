<?php
session_start();
require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
// SEGURIDAD: Prevenir registro como admin
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Forzar rol a capturista siempre
    $_POST["rol"] = "capturista";

    // Si alguien intenta enviar rol=admin, bloquear
    if (isset($_REQUEST["rol"]) && $_REQUEST["rol"] === "admin") {
        http_response_code(403);
        die(json_encode(["success" => false, "message" => "Acción no permitida"]));
    }
}

require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();

// Verificar si el registro público está habilitado
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$registro_publico = $config_sistema['registro_publico'] ?? true;

if (!$registro_publico) {
    $_SESSION['error'] = 'El registro público está deshabilitado. Contacta al administrador.';
    header('Location: inicio.php');
    exit;
}

$error = $_SESSION['error'] ?? '';
$exito = $_SESSION['exito'] ?? '';
unset($_SESSION['error'], $_SESSION['exito']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting por IP
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $rateLimitCheck = verificarRateLimitRegistro($ip);

    if (!$rateLimitCheck['permitido']) {
        $error = $rateLimitCheck['mensaje'];
    } elseif (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Token de seguridad inválido. Por favor, recargue la página e intente nuevamente.';
    } else {
        // Sanitizar y validar inputs
        $nombre = trim($_POST['nombre'] ?? '');
        $nombre = htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8');
        $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';
        $rol = in_array($_POST['rol'] ?? '', ['admin', 'capturista']) ? $_POST['rol'] : 'capturista';
        $jurisdiccion = trim($_POST['jurisdiccion'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');

        // Validaciones
        if (empty($nombre) || strlen($nombre) < 3 || strlen($nombre) > 100) {
            $error = 'El nombre debe tener entre 3 y 100 caracteres.';
        } elseif ($email === false) {
            $error = 'El correo electrónico no es válido.';
        } elseif (empty($password) || strlen($password) > 255) {
            $error = 'La contraseña es obligatoria y no debe exceder 255 caracteres.';
        } elseif ($rol === 'capturista' && empty($jurisdiccion)) {
            $error = 'Los capturistas deben seleccionar una jurisdicción.';
        } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&#+\-_.])[A-Za-z\d@$!%*?&#+\-_.]{8,}$/', $password)) {
            $error = 'La contraseña debe tener mínimo 8 caracteres, una mayúscula, una minúscula, un número y un carácter especial (@$!%*?&#+-_.)';
        } else {
            // Verificar si el email ya existe
            $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $error = 'El correo ya está registrado.';
            } else {
                // Hash seguro de contraseña
                $hash = password_hash($password, PASSWORD_DEFAULT);

                // Si es admin, jurisdicción debe ser NULL
                $jurisdiccion_final = ($rol === 'admin') ? null : $jurisdiccion;

                // Insertar usuario
                $insert = $pdo->prepare('INSERT INTO usuarios (nombre, email, password, telefono, rol, jurisdiccion, created_at) VALUES (:nombre, :email, :password, :telefono, :rol, :jurisdiccion, NOW())');
                try {
                    $insert->execute([
                        'nombre' => $nombre,
                        'email' => $email,
                        'password' => $hash,
                        'telefono' => $telefono,
                        'rol' => $rol,
                        'jurisdiccion' => $jurisdiccion_final
                    ]);

                    // Registrar en bitácora
                    $nuevo_usuario_id = $pdo->lastInsertId();
                    registrarBitacora(
                        $nuevo_usuario_id,
                        'registro',
                        'Usuario registrado: ' . $nombre . ' (' . $rol . ')',
                        1
                    );

                    $exito = 'Registro exitoso. Inicia sesión.';
                    header('Refresh: 2; url=inicio.php');
                } catch (Exception $e) {
                    $error = 'Error al registrar. Intenta nuevamente.';
                    error_log('Error en registro: ' . $e->getMessage());
                }
            }
        }
    }
}

$csrfToken = generarTokenCSRF();
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Usuarios - REPZYV</title>

    <!-- SEO Refinado: Estilo SINBA/Salud -->
    <meta name="description" content="Formulario oficial de registro para el acceso al sistema de seguimiento sanitario. Gestión controlada de vacunación y programas de salud por jurisdicción.">
    <meta name="keywords" content="Registro, Zoonosis, Salud Pública, Jurisdicciones Sanitarias, Plataforma Oficial">

    <!-- Optimización y Rendimiento (Equipos HDD) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>" rel="stylesheet">
    <link rel="preload" as="image" href="img/Gemini_Generated_Image_tkcyxmtkcyxmtkcy.png">

    <!-- Seguridad: Bloqueo de inspección -->
    <style>
        body {
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            min-height: 100vh;
            display: flex;
            background-image: url('img/Gemini_Generated_Image_tkcyxmtkcyxmtkcy.png');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-attachment: fixed;
            background-color: #b3dfe8;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background: transparent;
            z-index: 0;
        }

        .registro-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            padding: 2rem;
        }

        .registro-left {
            width: 100%;
            max-width: 480px;
        }

        .registro-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 24px;
            padding: 3.5rem;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2), inset 0 0 30px rgba(255, 255, 255, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        .registro-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .registro-header h1 {
            color: #1e3a5f !important;
            font-weight: 700;
            font-size: 2rem;
        }

        .registro-header p {
            color: #4a9fb5 !important;
            font-weight: 600;
            margin-top: 0.5rem;
        }

        .form-label {
            color: #1e3a5f !important;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 2px solid #4a9fb5;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.95);
            color: #1e3a5f;
        }

        .form-control:focus {
            border-color: #2d7a9b;
            box-shadow: 0 0 0 4px rgba(74, 159, 181, 0.2);
            background: rgba(255, 255, 255, 1);
            color: #1e3a5f;
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .form-select {
            padding: 0.75rem 1rem;
            border: 2px solid #4a9fb5;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.95);
            color: #1e3a5f;
        }

        .form-select:focus {
            border-color: #2d7a9b;
            box-shadow: 0 0 0 4px rgba(74, 159, 181, 0.2);
            background: rgba(255, 255, 255, 1);
            color: #1e3a5f;
        }

        .btn-registro {
            width: 100%;
            padding: 0.875rem;
            background: linear-gradient(135deg, #4a9fb5 0%, #2d7a9b 100%);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(74, 159, 181, 0.3);
        }

        .btn-registro:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(74, 159, 181, 0.4);
            background: linear-gradient(135deg, #6dc5d8 0%, #4a9fb5 100%);
        }

        .alert {
            border-radius: 10px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: none;
        }

        .alert-danger {
            background: #fef2f2;
            color: #dc2626;
        }

        .alert-success {
            background: #f0fdf4;
            color: #166534;
        }

        .registro-footer {
            text-align: center;
            margin-top: 1.5rem;
        }

        .registro-footer a {
            color: #4a9fb5 !important;
            font-weight: 700;
            text-decoration: none;
        }

        .registro-footer a:hover {
            color: #2d7a9b !important;
            text-decoration: underline;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(74, 159, 181, 0.3), transparent);
            margin: 1rem 0;
        }

        .form-text {
            color: #6b7280 !important;
            font-size: 0.85rem;
            margin-top: 0.25rem;
        }

        .password-requirements {
            background: #f0fdf4;
            border: 2px solid #86efac;
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .requirement-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            color: #6b7280;
            font-size: 0.9rem;
        }

        .requirement-item:last-child {
            margin-bottom: 0;
        }

        .requirement-item i {
            font-size: 1rem;
            width: 1.25rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .requirement-item i.fa-circle-notch {
            color: #d1d5db;
        }

        .requirement-item i.fa-check-circle {
            color: #22c55e;
        }

        .requirement-item.completed {
            color: #166534;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .registro-container {
                padding: 1rem;
            }

            .registro-card {
                padding: 2rem;
                border-radius: 20px;
            }

            .registro-header h1 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .registro-card {
                padding: 1.5rem;
                border-radius: 16px;
            }

            .registro-header h1 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>

<body>
    <div class="registro-container">
        <div class="registro-left">
            <div class="registro-card">
                <div class="registro-header">
                    <h1>Registro</h1>
                    <p>Crea tu cuenta</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <?php if ($exito): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($exito) ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label">Nombre Completo</label>
                        <input type="text" name="nombre" class="form-control" required placeholder="Tu nombre completo">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Correo electrónico</label>
                        <input type="email" name="email" class="form-control" required placeholder="tu@email.com">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Teléfono</label>
                        <input type="tel" name="telefono" class="form-control" placeholder="Tu número de teléfono">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Contraseña</label>
                        <div style="position: relative;">
                            <input type="password" name="password" id="password" class="form-control" required placeholder="••••••••" style="padding-right: 45px;">
                            <button type="button" onclick="togglePassword('password', 'toggleIcon1')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-eye" id="toggleIcon1"></i>
                            </button>
                        </div>

                        <!-- Lista de requisitos -->
                        <div class="password-requirements mt-3" style="display: none;">
                            <div class="requirement-item">
                                <i class="fas fa-circle-notch" id="req-length"></i>
                                <span>Mínimo 8 caracteres</span>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-circle-notch" id="req-uppercase"></i>
                                <span>Una mayúscula (A-Z)</span>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-circle-notch" id="req-lowercase"></i>
                                <span>Una minúscula (a-z)</span>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-circle-notch" id="req-number"></i>
                                <span>Un número (0-9)</span>
                            </div>
                            <div class="requirement-item">
                                <i class="fas fa-circle-notch" id="req-special"></i>
                                <span>Un carácter especial (@$!%*?&#+-_.)</span>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rol</label>
                        <select name="rol" id="rol-select" class="form-select">
                            <option value="capturista">Capturista</option>
                        </select>
                    </div>

                    <div class="mb-4" id="jurisdiccion-container">
                        <label class="form-label">
                            Jurisdicción
                            <span class="text-danger" id="jurisdiccion-required">*</span>
                        </label>
                        <select name="jurisdiccion" id="jurisdiccion-select" class="form-select" required>
                            <option value="">Seleccionar jurisdicción</option>
                            <?php
                            $jurisdicciones = jurisdicciones();
                            foreach ($jurisdicciones as $jurisdiccion):
                            ?>
                                <option value="<?= htmlspecialchars($jurisdiccion) ?>"><?= htmlspecialchars($jurisdiccion) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="btn-registro">Registrarse</button>
                </form>

                <div class="registro-footer">
                    <a href="inicio.php">¿Ya tienes cuenta? Inicia sesión</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Seguridad: Anti-Inspección
        document.addEventListener('contextmenu', event => event.preventDefault());
        document.onkeydown = function(e) {
            if (e.keyCode == 123) return false;
            if (e.ctrlKey && e.shiftKey && e.keyCode == 'I'.charCodeAt(0)) return false;
            if (e.ctrlKey && e.shiftKey && e.keyCode == 'J'.charCodeAt(0)) return false;
            if (e.ctrlKey && e.keyCode == 'U'.charCodeAt(0)) return false;
        };

        const passwordInput = document.getElementById('password');
        const passwordRequirements = document.querySelector('.password-requirements');
        const rolSelect = document.getElementById('rol-select');
        const jurisdiccionContainer = document.getElementById('jurisdiccion-container');

        const requirements = {
            length: {
                regex: /.{8,}/,
                id: 'req-length'
            },
            uppercase: {
                regex: /[A-Z]/,
                id: 'req-uppercase'
            },
            lowercase: {
                regex: /[a-z]/,
                id: 'req-lowercase'
            },
            number: {
                regex: /\d/,
                id: 'req-number'
            },
            special: {
                regex: /[@$!%*?&#\+\-_.]/,
                id: 'req-special'
            }
        };

        // Validador de contraseña
        passwordInput.addEventListener('focus', () => {
            passwordRequirements.style.display = 'block';
        });

        passwordInput.addEventListener('input', () => {
            const password = passwordInput.value;

            Object.keys(requirements).forEach(key => {
                const req = requirements[key];
                const element = document.getElementById(req.id);
                const itemElement = element.closest('.requirement-item');

                if (req.regex.test(password)) {
                    element.classList.remove('fa-circle-notch');
                    element.classList.add('fa-check-circle');
                    itemElement.classList.add('completed');
                } else {
                    element.classList.remove('fa-check-circle');
                    element.classList.add('fa-circle-notch');
                    itemElement.classList.remove('completed');
                }
            });
        });

        passwordInput.addEventListener('blur', () => {
            if (passwordInput.value === '') {
                passwordRequirements.style.display = 'none';
            }
        });

        // Mostrar/ocultar jurisdicción según el rol
        rolSelect.addEventListener('change', () => {
            const jurisdiccionSelect = document.getElementById('jurisdiccion-select');
            const jurisdiccionRequired = document.getElementById('jurisdiccion-required');

            if (rolSelect.value === 'admin') {
                jurisdiccionContainer.style.display = 'none';
                jurisdiccionSelect.removeAttribute('required');
                jurisdiccionSelect.value = '';
            } else {
                jurisdiccionContainer.style.display = 'block';
                jurisdiccionSelect.setAttribute('required', 'required');
            }
        });

        // Inicializar estado al cargar la página
        const jurisdiccionSelect = document.getElementById('jurisdiccion-select');
        if (rolSelect.value === 'admin') {
            jurisdiccionContainer.style.display = 'none';
            jurisdiccionSelect.removeAttribute('required');
        } else {
            jurisdiccionContainer.style.display = 'block';
            jurisdiccionSelect.setAttribute('required', 'required');
        }

        // Función para mostrar/ocultar contraseña
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);

            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>