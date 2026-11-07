<?php
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();

$mensaje = '';
$error = '';
$paso = 1; // 1=solicitar email, 2=ingresar código, 3=éxito
$emailGuardado = '';
$exitoCompleto = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Token de seguridad inválido. Por favor, recargue la página e intente nuevamente.';
    } else {
        // PASO 1: Solicitar código
        if (isset($_POST['solicitar_codigo'])) {
            $email = validarInput($_POST['email'] ?? '', 'email');

            if ($email === false) {
                $error = 'Por favor, ingresa un correo electrónico válido.';
            } else {
                // Verificar que el email existe
                $query = "SELECT id, nombre, rol FROM usuarios WHERE email = ?";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 's', $email);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $user = mysqli_fetch_assoc($res);

                if ($user) {
                    // Generar Token Numérico de 6 dígitos
                    $token = random_int(100000, 999999);
                    $expiracion = date('Y-m-d H:i:s', strtotime('+15 minutes'));

                    // Insertar en tabla restablecimientos
                    $insertQuery = "INSERT INTO restablecimientos (usuario_id, email, codigo, expiracion, usado) VALUES (?, ?, ?, ?, 0)";
                    $stmtInsert = mysqli_prepare($conn, $insertQuery);
                    mysqli_stmt_bind_param($stmtInsert, 'isss', $user['id'], $email, $token, $expiracion);

                    if (mysqli_stmt_execute($stmtInsert)) {
                        // Enviar Correo
                        $envio = enviarCorreoToken($email, $token);
                        if ($envio['ok']) {
                            $paso = 2;
                            $emailGuardado = $email;
                            $mensaje = 'Hemos enviado un código de verificación a tu correo electrónico. Ingrésalo abajo junto con tu nueva contraseña.';
                        } else {
                            $error = 'Error al enviar el correo: ' . $envio['mensaje'];
                        }
                    } else {
                        $error = 'Error al generar el código. Intenta nuevamente.';
                    }
                } else {
                    // Por seguridad, simulamos éxito aunque no exista
                    $paso = 2;
                    $emailGuardado = $email;
                    $mensaje = 'Si el correo existe en nuestro sistema, recibirás un código de verificación. Ingrésalo abajo.';
                }
            }
        }

        // PASO 2: Verificar código y cambiar contraseña
        elseif (isset($_POST['verificar_codigo'])) {
            $codigo = trim($_POST['codigo'] ?? '');
            $email_post = trim($_POST['email_hidden'] ?? '');
            $nueva_password = $_POST['nueva_password'] ?? '';
            $confirmar_password = $_POST['confirmar_password'] ?? '';

            if (empty($codigo) || empty($nueva_password) || empty($confirmar_password)) {
                $error = 'Todos los campos son obligatorios.';
                $paso = 2;
                $emailGuardado = $email_post;
            } elseif ($nueva_password !== $confirmar_password) {
                $error = 'Las contraseñas no coinciden.';
                $paso = 2;
                $emailGuardado = $email_post;
            } elseif (strlen($nueva_password) < 8) {
                $error = 'La contraseña debe tener al menos 8 caracteres.';
                $paso = 2;
                $emailGuardado = $email_post;
            } else {
                // Verificar código
                $query = "SELECT r.*, u.id as usuario_id, u.nombre FROM restablecimientos r 
                          JOIN usuarios u ON r.usuario_id = u.id 
                          WHERE r.email = ? AND r.codigo = ? AND r.usado = 0 AND r.expiracion > NOW()
                          ORDER BY r.created_at DESC LIMIT 1";
                $stmt = mysqli_prepare($conn, $query);
                mysqli_stmt_bind_param($stmt, 'ss', $email_post, $codigo);
                mysqli_stmt_execute($stmt);
                $res = mysqli_stmt_get_result($stmt);
                $restablecer = mysqli_fetch_assoc($res);

                if ($restablecer) {
                    // Código válido, actualizar contraseña
                    $password_hash = password_hash($nueva_password, PASSWORD_DEFAULT);
                    $update_query = "UPDATE usuarios SET password = ? WHERE id = ?";
                    $stmt_update = mysqli_prepare($conn, $update_query);
                    mysqli_stmt_bind_param($stmt_update, 'si', $password_hash, $restablecer['usuario_id']);

                    if (mysqli_stmt_execute($stmt_update)) {
                        // Marcar código como usado
                        $mark_query = "UPDATE restablecimientos SET usado = 1 WHERE id = ?";
                        $stmt_mark = mysqli_prepare($conn, $mark_query);
                        mysqli_stmt_bind_param($stmt_mark, 'i', $restablecer['id']);
                        mysqli_stmt_execute($stmt_mark);

                        // Registrar en bitácora
                        registrarBitacora(
                            $restablecer['usuario_id'],
                            'restablecer_password',
                            'Contraseña restablecida exitosamente mediante código de verificación',
                            1
                        );

                        $paso = 3;
                        $exitoCompleto = true;
                        $mensaje = '¡Contraseña restablecida correctamente! Ahora puedes iniciar sesión con tu nueva contraseña.';
                    } else {
                        $error = 'Error al actualizar la contraseña. Intenta nuevamente.';
                        $paso = 2;
                        $emailGuardado = $email_post;
                    }
                } else {
                    $error = 'Código inválido o expirado. Solicita un nuevo código.';
                    $paso = 1;
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
    <title>Recuperar Contraseña - REPZYV</title>

    <!-- SEO Refinado: Estilo SINBA/REPZYV -->
    <meta name="description" content="Portal de recuperación de acceso al Sistema REPZYV (Reporte de Zoonosis y Vectores). Restablecimiento seguro de credenciales para personal oficial.">
    <meta name="keywords" content="Recuperar Contraseña, Zoonosis, Salud Pública, Seguridad de Acceso">

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

        .recuperar-container {
            display: flex;
            width: 100%;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            position: relative;
            z-index: 1;
            padding: 2rem;
        }

        .recuperar-card {
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

        .recuperar-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .recuperar-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: #1e3a5f;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            font-size: 2.5rem;
            color: #166534;
        }

        .recuperar-header h1 {
            color: #1e3a5f !important;
            font-weight: 700;
            font-size: 2rem;
        }

        .recuperar-header p {
            color: #4a9fb5 !important;
            font-weight: 600;
            margin-top: 0.5rem;
            font-size: 0.95rem;
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
            outline: none;
        }

        .form-control::placeholder {
            color: #9ca3af;
        }

        .btn-recuperar {
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

        .btn-recuperar:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 159, 181, 0.4);
        }

        .btn-recuperar:active {
            transform: translateY(0);
        }

        .recuperar-footer {
            text-align: center;
            margin-top: 1.5rem;
        }

        .recuperar-footer a {
            color: #4a9fb5;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .recuperar-footer a:hover {
            color: #2d7a9b;
        }

        .alert {
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border: none;
        }

        .alert-danger {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #991b1b;
        }

        .alert-success {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #166534;
        }

        @media (max-width: 576px) {
            .recuperar-card {
                padding: 2rem;
                border-radius: 16px;
            }

            .recuperar-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>

<body>
    <div class="recuperar-container">
        <div class="recuperar-card">
            <?php if ($paso === 3 && $exitoCompleto): ?>
                <!-- PASO 3: ÉXITO -->
                <div class="recuperar-header">
                    <div class="success-icon">
                        <i class="fas fa-check"></i>
                    </div>
                    <h1>¡Contraseña Restablecida!</h1>
                    <p><?= htmlspecialchars($mensaje) ?></p>
                </div>

                <a href="inicio.php" class="btn btn-recuperar">
                    <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
                </a>

            <?php elseif ($paso === 2): ?>
                <!-- PASO 2: INGRESAR CÓDIGO Y NUEVA CONTRASEÑA -->
                <div class="recuperar-header">
                    <div class="recuperar-icon">
                        <i class="fas fa-key"></i>
                    </div>
                    <h1>Restablecer Contraseña</h1>
                    <p>Ingresa el código que recibiste por correo y tu nueva contraseña.</p>
                </div>

                <?php if ($mensaje): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($mensaje) ?>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="email_hidden" value="<?= htmlspecialchars($emailGuardado) ?>">

                    <div class="mb-3">
                        <label class="form-label">Código de Verificación</label>
                        <input type="text" name="codigo" class="form-control" required placeholder="123456" maxlength="6" pattern="[0-9]{6}" autofocus>
                        <small class="text-muted">Código de 6 dígitos enviado a tu correo</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Nueva Contraseña</label>
                        <div style="position: relative;">
                            <input type="password" name="nueva_password" id="nueva_password" class="form-control" required placeholder="••••••••" style="padding-right: 45px;">
                            <button type="button" onclick="togglePassword('nueva_password', 'toggleIcon1')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-eye" id="toggleIcon1"></i>
                            </button>
                        </div>
                        <small class="text-muted">Mínimo 8 caracteres</small>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Confirmar Contraseña</label>
                        <div style="position: relative;">
                            <input type="password" name="confirmar_password" id="confirmar_password" class="form-control" required placeholder="••••••••" style="padding-right: 45px;">
                            <button type="button" onclick="togglePassword('confirmar_password', 'toggleIcon2')" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #64748b; padding: 0; width: 24px; height: 24px; display: flex; align-items: center; justify-content: center;">
                                <i class="fas fa-eye" id="toggleIcon2"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" name="verificar_codigo" class="btn-recuperar">
                        <i class="fas fa-lock me-2"></i>Restablecer Contraseña
                    </button>
                </form>

                <div class="recuperar-footer">
                    <a href="olvide_contrasena.php">
                        <i class="fas fa-arrow-left me-2"></i>Solicitar nuevo código
                    </a>
                </div>

            <?php else: ?>
                <!-- PASO 1: SOLICITAR CÓDIGO -->
                <div class="recuperar-header">
                    <div class="recuperar-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h1>Recuperar Contraseña</h1>
                    <p>Ingresa tu correo electrónico y te enviaremos un código de verificación.</p>
                </div>

                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                    <div class="mb-4">
                        <label class="form-label">Correo Electrónico</label>
                        <input type="email" name="email" class="form-control" required placeholder="tu@correo.com" autofocus>
                    </div>

                    <button type="submit" name="solicitar_codigo" class="btn-recuperar">
                        <i class="fas fa-paper-plane me-2"></i>Enviar Código
                    </button>
                </form>

                <div class="recuperar-footer">
                    <a href="inicio.php">
                        <i class="fas fa-arrow-left me-2"></i>Volver al inicio de sesión
                    </a>
                </div>
            <?php endif; ?>
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
</body>

</html>