<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();

// Verificar conexión a base de datos
if (!$pdo || !$conn) {
    die('
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error de Conexión</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; text-align: center; background: #f5f5f5; }
            .error-box { max-width: 600px; margin: 0 auto; padding: 30px; border: 2px solid #dc3545; border-radius: 8px; background: #fff; }
            h1 { color: #dc3545; }
            p { color: #333; margin: 15px 0; }
        </style>
    </head>
    <body>
        <div class="error-box">
            <h1>⚠️ Error de Conexión</h1>
            <p>No se pudo conectar a la base de datos.</p>
            <p>Por favor, contacte al administrador del sistema.</p>
            <p><small>Código de error: DB_CONNECTION_FAILED</small></p>
        </div>
    </body>
    </html>
    ');
}

// Cargar configuración del sistema
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$modo_mantenimiento = $config_sistema['modo_mantenimiento'] ?? false;
$mensaje_mantenimiento = $config_sistema['mensaje_mantenimiento'] ?? 'El sistema se encuentra en mantenimiento.';
$registro_publico = $config_sistema['registro_publico'] ?? true;

// Si está en modo mantenimiento y no es admin, mostrar página de mantenimiento
if ($modo_mantenimiento && (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'admin')) {
    // Permitir login pero verificar después si es admin
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        // Mostrar página de mantenimiento solo si no está intentando loguearse
    }
}

if (isset($_SESSION['usuario_id'])) {
    header('Location: panel.php');
    exit;
}

// Cargar credenciales guardadas si existe la cookie (solo para autocompletar)
$email_guardado = '';
if (isset($_COOKIE['remember_credentials'])) {
    try {
        $credentials = json_decode(base64_decode($_COOKIE['remember_credentials']), true);
        if ($credentials && isset($credentials['email'])) {
            $email_guardado = htmlspecialchars($credentials['email'], ENT_QUOTES, 'UTF-8');
        }
    } catch (Exception $e) {
        // Ignorar errores
    }
}

$registroDeshabilitado = !$registro_publico;
$error = $_SESSION['error_login'] ?? '';
unset($_SESSION['error_login']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'];

    $rateLimitCheck = verificarRateLimitLogin($ip);

    if (!$rateLimitCheck['permitido']) {
        $error = $rateLimitCheck['mensaje'];
    } else {
        if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
            $error = 'Token de seguridad inválido. Por favor, recargue la página e intente nuevamente.';
            registrarIntentoLogin($ip);
        } else {
            // Validar email con filtro estricto
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $email = filter_var($email, FILTER_VALIDATE_EMAIL);

            // Validar contraseña
            $password = isset($_POST['password']) ? $_POST['password'] : '';

            if ($email === false || empty($password) || strlen($password) > 255) {
                $error = 'Correo y contraseña obligatorios. El correo debe ser válido.';
                registrarIntentoLogin($ip);
            } else {
                // Verificar si el usuario está bloqueado en la base de datos
                $bloqueoCheck = verificarBloqueoUsuario($pdo, $email);

                if ($bloqueoCheck['bloqueado']) {
                    $error = $bloqueoCheck['mensaje'];
                } else {
                    try {
                        // Usar prepared statement con parámetros nombrados - incluir campo activo
                        $stmt = $pdo->prepare('SELECT id, nombre, password, rol, jurisdiccion, activo, fecha_baja, motivo_baja FROM usuarios WHERE email = :email LIMIT 1');

                        // Ejecutar con parámetros seguros
                        $stmt->execute([':email' => $email]);
                        $user = $stmt->fetch(PDO::FETCH_ASSOC);

                        // Verificar credenciales
                        if ($user && password_verify($password, $user['password'])) {
                            // Verificar si el usuario está dado de baja (inactivo)
                            if ($user['activo'] == 0) {
                                $motivoBaja = $user['motivo_baja'] ?? 'Usuario desactivado por el administrador';
                                $error = "Su cuenta ha sido desactivada. Motivo: " . htmlspecialchars($motivoBaja) . ". Contacte al administrador para más información.";
                                registrarIntentoLogin($ip);

                                // Registrar intento de acceso de usuario inactivo en bitácora
                                try {
                                    $stmtBit = $pdo->prepare('INSERT INTO bitacora (usuario_id, accion, detalles, exito, ip_address) VALUES (:usuario_id, :accion, :detalles, :exito, :ip_address)');
                                    $stmtBit->execute([
                                        ':usuario_id' => (int)$user['id'],
                                        ':accion' => 'intento_login_usuario_inactivo',
                                        ':detalles' => 'Usuario inactivo intentó iniciar sesión. Motivo de baja: ' . $motivoBaja,
                                        ':exito' => 0,
                                        ':ip_address' => $ip
                                    ]);
                                } catch (Exception $e) {
                                    error_log('Error al registrar intento de login de usuario inactivo: ' . $e->getMessage());
                                }
                            }
                            // Verificar modo mantenimiento - solo admins pueden entrar
                            else if ($modo_mantenimiento && $user['rol'] !== 'admin') {
                                $error = $mensaje_mantenimiento;
                                registrarIntentoLogin($ip);
                            } else {
                                // Regenerar ID de sesión para prevenir session fixation
                                session_regenerate_id(true);

                                // Resetear intentos fallidos
                                resetearIntentosFallidos($pdo, $user['id']);

                                // Guardar datos de sesión de forma segura
                                $_SESSION['usuario_id'] = (int)$user['id'];
                                $_SESSION['nombre'] = htmlspecialchars($user['nombre'], ENT_QUOTES, 'UTF-8');
                                $_SESSION['rol'] = htmlspecialchars($user['rol'], ENT_QUOTES, 'UTF-8');
                                $_SESSION['jurisdiccion'] = $user['jurisdiccion'] !== null ? htmlspecialchars($user['jurisdiccion'], ENT_QUOTES, 'UTF-8') : null;
                                $_SESSION['login_time'] = time();
                                $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
                                $_SESSION['ip_address'] = $ip;
                                $_SESSION['nuevo_login'] = true; // Flag para marcar la pestaña en el frontend

                                limpiarIntentosLogin($ip);

                                // Manejar "Recuérdame" - solo guardar email para autocompletar
                                if (isset($_POST['remember']) && $_POST['remember'] == 1) {
                                    // Guardar solo el email (NO la contraseña)
                                    $remember_data = json_encode([
                                        'email' => $email
                                    ]);

                                    // Guardar en cookie por 30 días
                                    $cookie_expiry = time() + (30 * 24 * 60 * 60);

                                    setcookie(
                                        'remember_credentials',
                                        base64_encode($remember_data),
                                        $cookie_expiry,
                                        '/',
                                        '',
                                        false,
                                        true
                                    );
                                } else {
                                    // Si no marcó "Recuérdame", eliminar la cookie
                                    if (isset($_COOKIE['remember_credentials'])) {
                                        setcookie('remember_credentials', '', time() - 3600, '/', '', false, true);
                                    }
                                }

                                // Registrar login en bitácora usando prepared statement
                                try {
                                    $stmtBit = $pdo->prepare('INSERT INTO bitacora (usuario_id, accion, detalles, exito, ip_address) VALUES (:usuario_id, :accion, :detalles, :exito, :ip_address)');
                                    $stmtBit->execute([
                                        ':usuario_id' => (int)$user['id'],
                                        ':accion' => 'login',
                                        ':detalles' => 'Inicio de sesión exitoso',
                                        ':exito' => 1,
                                        ':ip_address' => $ip
                                    ]);
                                } catch (Exception $e) {
                                    // Log silencioso si falla la bitácora
                                    error_log('Error al registrar login en bitácora: ' . $e->getMessage());
                                }

                                header('Location: panel.php');
                                exit;
                            }
                        } else {
                            // Credenciales incorrectas
                            registrarIntentoLogin($ip);

                            // Incrementar intentos fallidos en BD si el usuario existe
                            if ($user) {
                                $intentosFallidos = incrementarIntentosFallidos($pdo, $email);

                                // Si alcanzó 5 intentos, bloquear usuario
                                if ($intentosFallidos >= 5) {
                                    bloquearUsuario($pdo, $email, $ip);
                                    $error = 'Ha excedido el número máximo de intentos. Su cuenta ha sido bloqueada por 24 horas por seguridad.';
                                } else {
                                    $intentosRestantes = 5 - $intentosFallidos;
                                    if ($intentosRestantes > 0) {
                                        $error = "Credenciales incorrectas. Le quedan $intentosRestantes intento(s) antes del bloqueo de 24 horas.";
                                    }
                                }
                            } else {
                                // Usuario no existe, mostrar mensaje genérico
                                $intentosRestantes = $rateLimitCheck['intentos_restantes'] - 1;
                                if ($intentosRestantes > 0) {
                                    $error = "Credenciales incorrectas. Le quedan $intentosRestantes intento(s) antes del bloqueo temporal.";
                                } else {
                                    $error = 'Credenciales incorrectas. Este es su último intento antes del bloqueo temporal.';
                                }
                            }
                        }
                    } catch (PDOException $e) {
                        // No revelar detalles de la base de datos
                        $error = 'Error al procesar la solicitud. Por favor, intente nuevamente.';
                        error_log('PDO Error en login: ' . $e->getMessage());
                        registrarIntentoLogin($ip);
                    }
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
    <title>REPZYV - Portal de Reporte de Zoonosis y Vectores</title>

    <!-- SEO Refinado: Estilo SINBA/Salud -->
    <meta name="description" content="Plataforma oficial para la gestión y seguimiento de programas de salud pública. Control de vacunación antirrábica, esterilizaciones, muestras y personas agredidas en jurisdicciones sanitarias.">
    <meta name="keywords" content="SINBA, Zoonosis, Salud Pública, Vacunación Antirrábica, Jurisdicción Sanitaria, Control de Insumos, México">
    <meta name="author" content="Zoonosis Sistema">

    <!-- Open Graph (Facebok/WhatsApp) -->
    <meta property="og:title" content="REPZYV - Gestión de Zoonosis y Vectores">
    <meta property="og:description" content="Sistema oficial para el reporte y seguimiento de zoonosis y vectores en jurisdicciones sanitarias.">
    <meta property="og:image" content="img/Gemini_Generated_Image_tkcyxmtkcyxmtkcy.png">
    <meta property="og:type" content="website">

    <!-- Optimización y Rendimiento (Equipos HDD) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- Cache Busting (Versionado) para evitar errores persistentes -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>" rel="stylesheet">
    <link rel="preload" as="image" href="img/Gemini_Generated_Image_tkcyxmtkcyxmtkcy.png">

    <!-- Seguridad: Bloqueo de inspección y selección -->
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

        .login-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            padding: 2rem;
        }

        .login-left {
            width: 100%;
            max-width: 480px;
        }

        @media (max-width: 768px) {
            .login-card {
                padding: 1.5rem;
                border-radius: 16px;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }

            .logo-icon {
                width: 50px;
                height: 50px;
            }

            .form-control {
                padding: 0.625rem 0.875rem;
            }

            .btn-login {
                padding: 0.75rem;
            }
        }

        @media (max-width: 576px) {
            .login-header h1 {
                font-size: 1.3rem;
            }

            .login-header p {
                font-size: 0.9rem;
            }

            .logo-icon {
                width: 40px;
                height: 40px;
            }
        }

        .login-card {
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

        .login-header h1 {
            color: #1e3a5f !important;
            font-weight: 700;
        }

        .login-header p {
            color: #4a9fb5 !important;
            font-weight: 600;
        }

        .form-label {
            color: #1e3a5f !important;
            font-weight: 700;
        }

        .login-footer a {
            color: #4a9fb5 !important;
            font-weight: 700;
            text-decoration: none;
        }

        .login-footer a:hover {
            color: #2d7a9b !important;
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .login-container {
                padding: 1rem;
            }

            .login-card {
                padding: 2rem;
                border-radius: 20px;
            }
        }

        @media (max-width: 576px) {
            .login-card {
                padding: 1.5rem;
                border-radius: 16px;
            }
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header .logo {
            font-size: 3rem;
            margin-bottom: 0.5rem;
            display: flex;
            justify-content: center;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            fill: url(#logoGradient);
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

        .btn-login {
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

        .btn-login:hover {
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

        .alert-warning {
            background: #fffbeb;
            color: #d97706;
        }

        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #4a9fb5;
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: #2d7a9b;
        }

        .password-toggle svg {
            width: 20px;
            height: 20px;
        }

        .divider {
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(74, 159, 181, 0.3), transparent);
            margin: 1rem 0;
        }

        .form-check {
            display: flex;
            align-items: center;
        }

        .form-check-input {
            width: 1.25rem;
            height: 1.25rem;
            margin-top: 0;
            margin-right: 0.5rem;
            border: 2px solid #4a9fb5;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .form-check-input:checked {
            background-color: #4a9fb5;
            border-color: #4a9fb5;
        }

        .form-check-input:focus {
            border-color: #2d7a9b;
            box-shadow: 0 0 0 4px rgba(74, 159, 181, 0.2);
        }

        .form-check-label {
            color: #1e3a5f !important;
            font-weight: 600;
            cursor: pointer;
            margin-bottom: 0;
        }

        .forgot-password-link {
            color: #4a9fb5 !important;
            font-weight: 600;
            text-decoration: none;
            font-size: 0.95rem;
        }

        .forgot-password-link:hover {
            color: #2d7a9b !important;
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <?php if ($modo_mantenimiento): ?>
        <!-- Tarjeta flotante de mantenimiento -->
        <div style="
        position: fixed;
        top: 24px;
        left: 50%;
        transform: translateX(-50%);
        z-index: 9999;
        width: 90%;
        max-width: 420px;
    ">
            <div style="
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 20px 50px rgba(220, 38, 38, 0.25), 0 0 0 1px rgba(220, 38, 38, 0.1);
            border: 1px solid rgba(254, 202, 202, 0.5);
        ">
                <div style="display: flex; align-items: flex-start; gap: 16px;">
                    <div style="
                    width: 52px;
                    height: 52px;
                    background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
                    border-radius: 14px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    flex-shrink: 0;
                ">
                        <i class="fas fa-tools" style="font-size: 1.4rem; color: #dc2626;"></i>
                    </div>
                    <div style="flex: 1;">
                        <h4 style="margin: 0 0 6px 0; font-size: 1.05rem; font-weight: 600; color: #991b1b;">
                            Sistema en Mantenimiento
                        </h4>
                        <p style="margin: 0 0 10px 0; font-size: 0.9rem; color: #6b7280; line-height: 1.5;">
                            <?php echo htmlspecialchars($mensaje_mantenimiento); ?>
                        </p>
                        <div style="
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
                        padding: 6px 12px;
                        border-radius: 8px;
                        font-size: 0.8rem;
                        color: #1e40af;
                        font-weight: 500;
                    ">
                            <i class="fas fa-user-shield" style="font-size: 0.75rem;"></i>
                            Administradores pueden acceder
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <style>
            .login-left {
                padding-top: 160px !important;
            }

            @media (max-width: 768px) {
                .login-left {
                    padding-top: 180px !important;
                }
            }
        </style>
    <?php endif; ?>

    <div class="login-container">
        <div class="login-left">
            <div class="login-card">
                <div class="login-header">
                    <div class="logo">
                        <svg class="logo-icon" viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="logoGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#a0d8ef;stop-opacity:1" />
                                    <stop offset="30%" style="stop-color:#7bc4e0;stop-opacity:1" />
                                    <stop offset="70%" style="stop-color:#4fa8c5;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#2d7a9b;stop-opacity:1" />
                                </linearGradient>
                                <linearGradient id="centerGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#e0f2fe;stop-opacity:0.95" />
                                </linearGradient>
                            </defs>

                            <!-- Círculos concéntricos más visibles -->
                            <circle cx="50" cy="50" r="45" fill="none" stroke="url(#logoGradient)" stroke-width="2" opacity="0.6" />
                            <circle cx="50" cy="50" r="40" fill="none" stroke="url(#logoGradient)" stroke-width="2.5" opacity="0.7" />
                            <circle cx="50" cy="50" r="35" fill="none" stroke="url(#logoGradient)" stroke-width="3" opacity="0.8" />
                            <circle cx="50" cy="50" r="30" fill="none" stroke="url(#logoGradient)" stroke-width="3" opacity="0.85" />
                            <circle cx="50" cy="50" r="25" fill="none" stroke="url(#logoGradient)" stroke-width="3.5" opacity="0.9" />
                            <circle cx="50" cy="50" r="20" fill="none" stroke="url(#logoGradient)" stroke-width="4" opacity="0.95" />

                            <!-- Líneas curvas dinámicas más gruesas -->
                            <path d="M 15 30 Q 30 20 45 30 Q 60 40 75 30 Q 85 35 90 45"
                                fill="none" stroke="url(#logoGradient)" stroke-width="3.5" opacity="0.7" />
                            <path d="M 10 50 Q 25 40 40 50 Q 55 60 70 50 Q 85 40 95 50"
                                fill="none" stroke="url(#logoGradient)" stroke-width="4" opacity="0.8" />
                            <path d="M 15 70 Q 30 80 45 70 Q 60 60 75 70 Q 85 65 90 55"
                                fill="none" stroke="url(#logoGradient)" stroke-width="3.5" opacity="0.7" />

                            <!-- Centro blanco más definido -->
                            <circle cx="50" cy="50" r="18" fill="url(#centerGradient)" stroke="#2d7a9b" stroke-width="2" opacity="1" />

                            <!-- Silueta humana central más grande y visible -->
                            <g transform="translate(50,50)">
                                <!-- Cabeza -->
                                <circle cx="0" cy="-9" r="4" fill="#2d7a9b" />
                                <!-- Cuerpo -->
                                <rect x="-3" y="-5" width="6" height="10" fill="#2d7a9b" rx="1" />
                                <!-- Brazos más gruesos -->
                                <line x1="-3" y1="-2" x2="-7" y2="2" stroke="#2d7a9b" stroke-width="2.5" />
                                <line x1="3" y1="-2" x2="7" y2="2" stroke="#2d7a9b" stroke-width="2.5" />
                                <!-- Piernas más gruesas -->
                                <line x1="-2" y1="5" x2="-5" y2="11" stroke="#2d7a9b" stroke-width="2.5" />
                                <line x1="2" y1="5" x2="5" y2="11" stroke="#2d7a9b" stroke-width="2.5" />
                            </g>

                            <!-- Murciélago más grande y visible -->
                            <g transform="translate(75,25)">
                                <path d="M 0 0 Q -4 -3 -8 0 Q -4 3 0 0 Q 4 -3 8 0 Q 4 3 0 0"
                                    fill="#2d7a9b" opacity="1" stroke="#1a5c7a" stroke-width="1" />
                                <circle cx="0" cy="0" r="1.5" fill="#1a5c7a" />
                            </g>

                            <!-- Virus/patógenos más grandes y visibles -->
                            <circle cx="25" cy="25" r="3" fill="#4fa8c5" opacity="1" stroke="#2d7a9b" stroke-width="1">
                                <animate attributeName="opacity" values="1;0.5;1" dur="3s" repeatCount="indefinite" />
                            </circle>
                            <circle cx="75" cy="75" r="2.5" fill="#7bc4e0" opacity="1" stroke="#4fa8c5" stroke-width="1">
                                <animate attributeName="opacity" values="1;0.4;1" dur="2.5s" repeatCount="indefinite" />
                            </circle>
                            <circle cx="20" cy="70" r="3.5" fill="#2d7a9b" opacity="1" stroke="#1a5c7a" stroke-width="1">
                                <animate attributeName="opacity" values="1;0.3;1" dur="4s" repeatCount="indefinite" />
                            </circle>

                            <!-- Elementos decorativos más visibles -->
                            <circle cx="30" cy="15" r="2" fill="#7bc4e0" opacity="0.9" />
                            <circle cx="85" cy="40" r="2" fill="#4fa8c5" opacity="0.8" />
                            <circle cx="15" cy="85" r="2" fill="#2d7a9b" opacity="0.9" />
                            <circle cx="70" cy="15" r="2" fill="#1a5c7a" opacity="0.8" />

                            <!-- Cruz médica más visible -->
                            <g opacity="0.4">
                                <rect x="47" y="32" width="6" height="36" fill="#2d7a9b" rx="2" />
                                <rect x="32" y="47" width="36" height="6" fill="#2d7a9b" rx="2" />
                            </g>

                            <!-- Efectos adicionales para mayor visibilidad -->
                            <circle cx="50" cy="50" r="48" fill="none" stroke="url(#logoGradient)" stroke-width="1" opacity="0.3" />
                        </svg>
                    </div>
                    <h1>REPZYV</h1>
                    <p>Reporte de Zoonosis y Vectores</p>
                </div>

                <?php if ($registroDeshabilitado): ?>
                    <div class="alert alert-warning">
                        <strong>⚠️ Registro deshabilitado</strong>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="email">Correo electrónico</label>
                        <input type="email" name="email" id="email" class="form-control" required placeholder="usuario@ejemplo.com" autocomplete="email">
                    </div>

                    <div class="mb-4">
                        <label class="form-label" for="password">Contraseña</label>
                        <div class="password-container">
                            <input type="password" name="password" id="password" class="form-control" required placeholder="••••••••" autocomplete="current-password">
                            <button type="button" class="password-toggle" onclick="togglePassword()">
                                <svg id="eye-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                    <circle cx="12" cy="12" r="3"></circle>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="mb-4 d-flex flex-column flex-sm-row justify-content-between align-items-start align-items-sm-center gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
                            <label class="form-check-label" for="remember">
                                Recuérdame
                            </label>
                        </div>
                        <a href="olvide_contrasena.php" class="forgot-password-link">¿Olvidaste tu contraseña?</a>
                    </div>

                    <button type="submit" class="btn-login">Iniciar Sesión</button>
                </form>

                <div class="login-footer">
                    <?php if (!$registroDeshabilitado): ?>
                        <a href="registro.php">¿No tienes cuenta? Regístrate</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Seguridad: Anti-Inspección
        document.addEventListener('contextmenu', event => event.preventDefault()); // Bloquear clic derecho
        document.onkeydown = function(e) {
            if (e.keyCode == 123) return false; // F12
            if (e.ctrlKey && e.shiftKey && e.keyCode == 'I'.charCodeAt(0)) return false; // Ctrl+Shift+I
            if (e.ctrlKey && e.shiftKey && e.keyCode == 'J'.charCodeAt(0)) return false; // Ctrl+Shift+J
            if (e.ctrlKey && e.keyCode == 'U'.charCodeAt(0)) return false; // Ctrl+U (Ver código fuente)
        };

        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.innerHTML = `
                    <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                    <line x1="1" y1="1" x2="23" y2="23"></line>
                `;
            } else {
                passwordInput.type = 'password';
                eyeIcon.innerHTML = `
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                `;
            }
        }

        // Autocompletar email si existe cookie "Recuérdame"
        document.addEventListener('DOMContentLoaded', function() {
            const emailGuardado = '<?= $email_guardado ?>';
            if (emailGuardado) {
                const emailInput = document.querySelector('input[name="email"]');
                if (emailInput) {
                    emailInput.value = emailGuardado;
                    // Marcar el checkbox "Recuérdame"
                    const rememberCheckbox = document.getElementById('remember');
                    if (rememberCheckbox) {
                        rememberCheckbox.checked = true;
                    }
                    // Hacer focus en el campo de contraseña
                    const passwordInput = document.getElementById('password');
                    if (passwordInput) {
                        passwordInput.focus();
                    }
                }
            }
        });
    </script>
</body>

</html>