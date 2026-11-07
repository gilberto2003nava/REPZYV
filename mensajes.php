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

require_once 'partials/check_session.php';

// Verificar que sea admin
if ($_SESSION['rol'] !== 'admin') {
    header('Location: panel.php');
    exit;
}

$usuario_id = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$nombre_usuario = $_SESSION['nombre'] ?? 'Usuario';

// --- LOGICA DE BACKEND ---

// Enviar mensaje
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'enviar_mensaje') {
    $destinatario_id = !empty($_POST['destinatario_id']) ? intval($_POST['destinatario_id']) : null;
    $asunto = trim($_POST['asunto']);
    $mensaje = trim($_POST['mensaje']);
    $tipo = $_POST['tipo'] ?? 'general';
    $referencia_mes = !empty($_POST['referencia_mes']) ? intval($_POST['referencia_mes']) : null;
    $referencia_anio = !empty($_POST['referencia_anio']) ? intval($_POST['referencia_anio']) : null;

    // Validación básica
    if (empty($asunto) || empty($mensaje)) {
        $error = "Asunto y mensaje son obligatorios.";
    } else {
        // Si el destinatario es "Admin" (0 o null en el formulario), buscar un admin
        if (empty($destinatario_id)) {
            $stmt_admin = mysqli_prepare($conn, "SELECT id FROM usuarios WHERE rol = 'admin' LIMIT 1");
            mysqli_stmt_execute($stmt_admin);
            $res_admin = mysqli_stmt_get_result($stmt_admin);
            $row_admin = mysqli_fetch_assoc($res_admin);
            if ($row_admin) {
                $destinatario_id = $row_admin['id'];
            } else {
                $error = "No se encontró ningún administrador para recibir el mensaje.";
            }
        }

        if (!isset($error)) {
            $sql_insert = "INSERT INTO mensajes (remitente_id, destinatario_id, asunto, mensaje, tipo, referencia_mes, referencia_anio) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql_insert);
            mysqli_stmt_bind_param($stmt, "iisssii", $usuario_id, $destinatario_id, $asunto, $mensaje, $tipo, $referencia_mes, $referencia_anio);

            if (mysqli_stmt_execute($stmt)) {
                $success = "Mensaje enviado correctamente.";
                // Redirigir para evitar reenvío de formulario y mantener el chat activo
                header("Location: mensajes.php?chat_con=" . $destinatario_id . "&success=1");
                exit;
            } else {
                $error = "Error al enviar mensaje: " . mysqli_error($conn);
            }
        }
    }
}

// Acción: Habilitar Mes (Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'habilitar_mes' && $rol === 'admin') {
    $usuario_ext_id = intval($_POST['usuario_id_ext']);
    $mes_ext = intval($_POST['mes_ext']);
    $anio_ext = intval($_POST['anio_ext']);

    // Calcular fecha límite (24 horas desde ahora)
    $fecha_limite = date('Y-m-d H:i:s', strtotime('+24 hours'));

    $stmt_ext = mysqli_prepare($conn, "INSERT INTO extensiones (usuario_id, mes, anio, fecha_limite) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt_ext, "iiis", $usuario_ext_id, $mes_ext, $anio_ext, $fecha_limite);

    if (mysqli_stmt_execute($stmt_ext)) {
        // Enviar respuesta automática
        $asunto_resp = "Solicitud Aprobada: Apertura de Mes";
        $msg_resp = "Su solicitud para habilitar la captura del mes $mes_ext/$anio_ext ha sido aprobada.\n\nTiene 24 horas a partir de este momento para realizar su captura.";
        // Insertar respuesta
        $stmt_resp = mysqli_prepare($conn, "INSERT INTO mensajes (remitente_id, destinatario_id, asunto, mensaje, tipo) VALUES (?, ?, ?, ?, 'general')");
        mysqli_stmt_bind_param($stmt_resp, "iiss", $usuario_id, $usuario_ext_id, $asunto_resp, $msg_resp);
        mysqli_stmt_execute($stmt_resp);

        $success = "Mes habilitado correctamente por 24 horas. Se ha notificado al usuario.";
    } else {
        $error = "Error al habilitar el mes: " . mysqli_error($conn);
    }
}

// Marcar como leído
if (isset($_GET['leer'])) {
    $msg_id = intval($_GET['leer']);
    $stmt_read = mysqli_prepare($conn, "UPDATE mensajes SET leido = 1 WHERE id = ? AND destinatario_id = ?");
    mysqli_stmt_bind_param($stmt_read, "ii", $msg_id, $usuario_id);
    mysqli_stmt_execute($stmt_read);
}

// Obtener mensajes recibidos
$sql_recibidos = "SELECT m.*, u.nombre as remitente_nombre, u.rol as remitente_rol 
                  FROM mensajes m 
                  LEFT JOIN usuarios u ON m.remitente_id = u.id 
                  WHERE m.destinatario_id = ? 
                  ORDER BY m.fecha_envio DESC";
$stmt_recibidos = mysqli_prepare($conn, $sql_recibidos);
mysqli_stmt_bind_param($stmt_recibidos, "i", $usuario_id);
mysqli_stmt_execute($stmt_recibidos);
$res_recibidos = mysqli_stmt_get_result($stmt_recibidos);

// Obtener mensajes enviados
$sql_enviados = "SELECT m.*, u.nombre as destinatario_nombre 
                 FROM mensajes m 
                 LEFT JOIN usuarios u ON m.destinatario_id = u.id 
                 WHERE m.remitente_id = ? 
                 ORDER BY m.fecha_envio DESC";
$stmt_enviados = mysqli_prepare($conn, $sql_enviados);
mysqli_stmt_bind_param($stmt_enviados, "i", $usuario_id);
mysqli_stmt_execute($stmt_enviados);
$res_enviados = mysqli_stmt_get_result($stmt_enviados);

// Obtener todos los mensajes (enviados y recibidos) combinados para el chat principal
$sql_todos = "
    (SELECT m.*, u.nombre as otro_usuario_nombre, u.rol as otro_usuario_rol, 'recibido' as tipo_mensaje
     FROM mensajes m 
     LEFT JOIN usuarios u ON m.remitente_id = u.id 
     WHERE m.destinatario_id = ?)
    UNION ALL
    (SELECT m.*, u.nombre as otro_usuario_nombre, u.rol as otro_usuario_rol, 'enviado' as tipo_mensaje
     FROM mensajes m 
     LEFT JOIN usuarios u ON m.destinatario_id = u.id 
     WHERE m.remitente_id = ?)
    ORDER BY fecha_envio ASC";
$stmt_todos = mysqli_prepare($conn, $sql_todos);
mysqli_stmt_bind_param($stmt_todos, "ii", $usuario_id, $usuario_id);
mysqli_stmt_execute($stmt_todos);
$res_todos = mysqli_stmt_get_result($stmt_todos);

// Contar mensajes no leídos
$unread_count = 0;
mysqli_data_seek($res_recibidos, 0);
while ($m = mysqli_fetch_assoc($res_recibidos)) {
    if ($m['leido'] == 0) $unread_count++;
}
mysqli_data_seek($res_recibidos, 0);

// Obtener lista de usuarios para destinatarios (Si es admin ve a todos, si es capturista solo ve administradores)
$usuarios_dest = [];
if ($rol === 'admin') {
    $res_users = mysqli_query($conn, "SELECT id, nombre, rol FROM usuarios WHERE id != $usuario_id ORDER BY nombre");
} else {
    $res_users = mysqli_query($conn, "SELECT id, nombre, rol FROM usuarios WHERE rol = 'admin' ORDER BY nombre");
}
while ($u = mysqli_fetch_assoc($res_users)) {
    $usuarios_dest[] = $u;
}

// Determinar si hay un chat activo desde la URL
$chat_con_id = isset($_GET['chat_con']) ? intval($_GET['chat_con']) : null;
$success_msg = isset($_GET['success']) ? true : false;
?>
<!DOCTYPE html>
<html lang="es" data-bs-theme="light">

<head>
    <meta charset="utf-8">
    <title>Mensajería Interna - Sistema Hospitalario</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>" rel="stylesheet">
    <link href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>" rel="stylesheet">
    <style>
        /* ═══════════════════════════════════════════════════════════ */
        /* DISEÑO ESTILO CHAT - PALETA AZUL DEL SISTEMA */
        /* ═══════════════════════════════════════════════════════════ */

        .chat-layout {
            display: flex;
            gap: 20px;
            height: calc(100vh - 200px);
            min-height: 500px;
            position: relative;
            /* Para posicionamiento absoluto si fuera necesario */
        }

        /* Clase de utilidad para ocultar/mostrar en móvil */
        @media (max-width: 992px) {
            .chat-contacts {
                display: flex;
                /* Por defecto visible */
            }

            .chat-main {
                display: none;
                /* Por defecto oculto */
            }

            /* Cuando se activa el chat */
            .chat-layout.show-chat .chat-contacts {
                display: none;
            }

            .chat-layout.show-chat .chat-main {
                display: flex;
            }
        }

        /* Lista de contactos/chats */
        .chat-contacts {
            width: 320px;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .contacts-header {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .contacts-header h6 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .contacts-search {
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
        }

        .contacts-search input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            font-size: 0.85rem;
            background: #f8fafc;
        }

        .contacts-search input:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
        }

        .contacts-list {
            flex: 1;
            overflow-y: auto;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f1f5f9;
        }

        .contact-item:hover {
            background: #f8fafc;
        }

        .contact-item.active {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-left: 3px solid #2563eb;
        }

        .contact-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            flex-shrink: 0;
        }

        .contact-avatar.admin {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .contact-avatar.capturista {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        .contact-info {
            flex: 1;
            min-width: 0;
        }

        .contact-name {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.9rem;
            margin-bottom: 2px;
        }

        .contact-role {
            font-size: 0.75rem;
            color: #64748b;
        }

        .contact-badge {
            background: #ef4444;
            color: white;
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 600;
        }

        /* Área de chat principal */
        .chat-main {
            flex: 1;
            background: #fff;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .chat-header {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .chat-header-avatar {
            width: 42px;
            height: 42px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
        }

        .chat-header-info h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1rem;
        }

        .chat-header-info small {
            opacity: 0.85;
            font-size: 0.8rem;
        }

        .chat-messages {
            flex: 1;
            padding: 12px 16px;
            overflow-y: auto;
            background: #f0f4f8;
        }

        /* Burbujas de mensaje - Estilo chat con paleta azul */
        .message-bubble {
            max-width: 65%;
            margin-bottom: 12px;
            clear: both;
            display: flex;
            flex-direction: column;
        }

        .message-bubble.received {
            float: left;
            align-items: flex-start;
        }

        .message-bubble.sent {
            float: right;
            align-items: flex-end;
        }

        .bubble-content {
            padding: 8px 12px;
            border-radius: 12px;
            position: relative;
            display: inline-block;
            word-wrap: break-word;
            max-width: 100%;
        }

        .message-bubble.received .bubble-content {
            background: #fff;
            border-top-left-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .message-bubble.sent .bubble-content {
            background: #bfdbfe;
            border-top-right-radius: 4px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        /* Nombre del remitente */
        .bubble-sender {
            font-size: 0.8rem;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 2px;
            display: block;
        }

        /* Texto del mensaje */
        .bubble-text {
            color: #1e293b;
            font-size: 0.875rem;
            line-height: 1.4;
            white-space: pre-wrap;
            word-wrap: break-word;
        }

        /* Hora del mensaje - FUERA de la burbuja */
        .bubble-time {
            font-size: 0.7rem;
            color: #94a3b8;
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .message-bubble.sent .bubble-time {
            justify-content: flex-end;
        }

        .message-bubble.received .bubble-time {
            justify-content: flex-start;
        }

        .bubble-time .check {
            color: #2563eb;
            font-size: 0.65rem;
        }

        .bubble-time i.fa-check {
            color: #94a3b8;
            font-size: 0.65rem;
        }

        /* Mensaje no leído */
        .message-bubble.unread {
            cursor: pointer;
        }

        .message-bubble.unread .bubble-content {
            background: #fef3c7;
            border-left: 3px solid #f59e0b;
        }

        /* Clearfix para floats */
        .messages-tab::after {
            content: "";
            display: table;
            clear: both;
        }

        /* Separador de fecha */
        .date-separator {
            text-align: center;
            margin: 12px 0;
            clear: both;
        }

        .date-separator span {
            background: #e0e7ff;
            padding: 5px 12px;
            border-radius: 7.5px;
            font-size: 0.75rem;
            color: #3730a3;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
        }

        /* Acción de admin */
        .admin-action {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            border: 1px solid #f59e0b;
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 12px;
        }

        .admin-action small {
            color: #92400e;
            font-weight: 500;
        }

        /* Fecha separador */
        .date-separator {
            text-align: center;
            margin: 24px 0;
        }

        .date-separator span {
            background: #fff;
            padding: 6px 18px;
            border-radius: 20px;
            font-size: 0.75rem;
            color: #64748b;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            border: 1px solid #e2e8f0;
        }

        /* Empty state */
        .chat-empty {
            text-align: center;
            padding: 60px 20px;
        }

        .chat-empty-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: #3b82f6;
        }

        .chat-empty h6 {
            color: #1f2937;
            margin-bottom: 8px;
        }

        .chat-empty p {
            color: #64748b;
            margin: 0;
            font-size: 0.9rem;
        }

        /* Input de mensaje */
        .chat-input {
            padding: 16px 20px;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 12px;
            align-items: flex-end;
        }

        .chat-input-field {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 24px;
            font-size: 0.9rem;
            resize: none;
            max-height: 120px;
            transition: border-color 0.2s;
        }

        .chat-input-field:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .chat-send-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
        }

        .chat-send-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
        }

        /* Tabs de navegación */
        .chat-tabs {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
            background: #f8fafc;
        }

        .chat-tab {
            flex: 1;
            padding: 14px;
            text-align: center;
            cursor: pointer;
            font-weight: 500;
            color: #64748b;
            transition: all 0.2s;
            border-bottom: 2px solid transparent;
        }

        .chat-tab:hover {
            background: #fff;
            color: #2563eb;
        }

        .chat-tab.active {
            background: #fff;
            color: #2563eb;
            border-bottom-color: #2563eb;
        }

        .chat-tab i {
            margin-right: 8px;
        }

        /* Botón flotante */
        .fab-button {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.4);
            display: none;
            /* Oculto porque ahora usamos el input inline */
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            cursor: pointer;
            transition: all 0.3s;
            z-index: 100;
        }

        /* ═══════════════════════════════════════════════════════════ */
        /* INPUT DE MENSAJE ESTILO WHATSAPP */
        /* ═══════════════════════════════════════════════════════════ */
        .chat-input-container {
            background: #fff;
            border-top: 1px solid #e2e8f0;
            padding: 12px 16px;
        }

        .chat-input-wrapper {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .chat-input-row {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .chat-select {
            flex: 0 0 180px;
            padding: 10px 14px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            font-size: 0.85rem;
            background: #f8fafc;
            color: #1f2937;
            cursor: pointer;
            transition: all 0.2s;
        }

        .chat-select:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
        }

        /* Ocultar selector de destinatario siempre (se maneja por lista de contactos) */
        .chat-input-row:first-child {
            display: none !important;
        }

        .chat-input-subject {
            flex: 1;
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            font-size: 0.9rem;
            background: #f8fafc;
            transition: all 0.2s;
        }

        .chat-input-subject:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
        }

        .chat-input-field {
            flex: 1;
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 20px;
            font-size: 0.9rem;
            resize: none;
            min-height: 44px;
            max-height: 120px;
            background: #f8fafc;
            transition: all 0.2s;
            font-family: inherit;
        }

        .chat-input-field:focus {
            outline: none;
            border-color: #3b82f6;
            background: #fff;
        }

        .chat-send-btn {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3);
            flex-shrink: 0;
        }

        .chat-send-btn:hover {
            transform: scale(1.08);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.4);
        }

        .chat-send-btn:active {
            transform: scale(0.95);
        }

        /* Avatar dinámico en header */
        .chat-header-avatar.admin-avatar {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
        }

        .chat-header-avatar.user-avatar {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .chat-layout {
                flex-direction: column;
                height: auto;
                overflow: visible !important;
                /* Permitir que el Offcanvas salga */
            }

            .chat-contacts {
                width: 100%;
                /* max-height retirado para dejar que offcanvas maneje su altura */
            }

            .chat-main {
                min-height: 400px;
            }
        }

        @media (max-width: 768px) {
            .chat-layout {
                height: calc(100vh - 160px);
                /* Ajuste para header móvil */
            }

            .chat-main {
                display: flex;
                flex-direction: column;
                height: 100%;
                border-radius: 0;
                border: none;
            }

            /* Ajustes para Offcanvas en móvil */
            .chat-contacts.offcanvas-lg {
                background: white !important;
                z-index: 1060 !important;
                /* Asegurar que esté sobre el backdrop */
                visibility: visible !important;
            }

            .chat-main {
                display: flex !important;
                height: 100%;
                width: 100%;
                z-index: 1;
            }

            /* Ocultar elementos que ahora están en el offcanvas si fuera necesario, 
               pero bootstrap offcanvas-lg ya maneja la visibilidad en desktop vs móvil */

            .message-bubble {
                max-width: 85%;
            }

            .chat-messages {
                padding: 16px;
                padding-bottom: 80px;
            }

            .contact-item {
                padding: 12px;
            }

            .chat-input-container {
                padding: 8px;
                background: #fff;
                border-top: 1px solid #e2e8f0;
            }

            .chat-input-field {
                font-size: 16px;
            }
        }
    </style>
</head>

<body class="theme-metas">
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>

        <!-- SIDEBAR: Lista de Usuarios (Offcanvas en móvil) -->
        <!-- Movido fuera de chat-layout para evitar conflictos de apilamiento (capa gris) -->
        <!-- Toast Container para notificaciones -->
        <div class="toast-container position-fixed bottom-0 end-0 p-3">
            <div id="liveToast" class="toast align-items-center text-white bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-check-circle me-2"></i> Mensaje enviado correctamente.
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>

        <!-- SIDEBAR: Lista de Usuarios (Offcanvas en móvil) -->
        <div class="chat-contacts offcanvas-lg offcanvas-start" tabindex="-1" id="offcanvasContacts" aria-labelledby="offcanvasContactsLabel">
            <div class="offcanvas-header d-lg-none">
                <h5 class="offcanvas-title" id="offcanvasContactsLabel">Contactos</h5>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#offcanvasContacts" aria-label="Close"></button>
            </div>
            <div class="contacts-header d-none d-lg-flex">
                <h6><i class="fas fa-comments"></i> Mensajes</h6>
                <?php if ($rol === 'admin'): ?>
                    <span class="badge bg-white text-primary">Admin</span>
                <?php endif; ?>
                <?php if ($rol !== 'admin'): ?>
                    <button class="btn btn-sm btn-warning text-white" onclick="solicitarApertura()" title="Solicitar apertura">
                        <i class="fas fa-lock-open"></i>
                    </button>
                <?php endif; ?>
            </div>
            <div class="offcanvas-body d-flex flex-column p-0">
                <!-- Contenido original del sidebar -->
                <div class="contacts-search p-3">
                    <input type="text" placeholder="Buscar contacto..." id="searchContact" onkeyup="filterContacts()">
                </div>
                <div class="chat-tabs px-3">
                    <div class="chat-tab active" data-tab="inbox" onclick="switchTab('inbox')">
                        <i class="fas fa-inbox"></i>Recibidos
                        <?php if ($unread_count > 0): ?>
                            <span class="contact-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="chat-tab" data-tab="unread" onclick="switchTab('unread')">
                        <i class="fas fa-envelope"></i>No leídos
                        <?php if ($unread_count > 0): ?>
                            <span class="contact-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="contacts-list flex-grow-1" id="contactsList">
                    <?php foreach ($usuarios_dest as $u):
                        $initials = strtoupper(substr($u['nombre'], 0, 2));
                        $avatarClass = $u['rol'] === 'admin' ? 'admin' : 'capturista';
                    ?>
                        <div class="contact-item" onclick="selectContact(<?php echo $u['id']; ?>, '<?php echo htmlspecialchars($u['nombre']); ?>', '<?php echo $u['rol']; ?>'); var offcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasContacts')); if(offcanvas) offcanvas.hide();">
                            <div class="contact-avatar <?php echo $avatarClass; ?>">
                                <?php echo $initials; ?>
                            </div>
                            <div class="contact-info">
                                <div class="contact-name"><?php echo htmlspecialchars($u['nombre']); ?></div>
                                <div class="contact-role"><?php echo ucfirst($u['rol']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($rol !== 'admin'): ?>
                        <div class="contact-item" onclick="selectContact(0, 'Administradores', 'admin'); var offcanvas = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasContacts')); if(offcanvas) offcanvas.hide();">
                            <div class="contact-avatar admin">
                                <i class="fas fa-user-shield"></i>
                            </div>
                            <div class="contact-info">
                                <div class="contact-name">Soporte Admin</div>
                                <div class="contact-role">Administradores del sistema</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <main class="app-main" id="appMain">
            <div class="page-header" id="welcomeHeader" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1 class="page-title" style="color: white; margin-bottom: 0.5rem;">
                            <i class="fas fa-comments me-2"></i>Mensajería Interna
                        </h1>
                        <p class="page-subtitle" style="color: rgba(255, 255, 255, 0.9); margin: 0;">Comunícate con el equipo de forma rápida y sencilla</p>
                    </div>
                </div>
            </div>

            <?php if (isset($success)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <div class="main-container">

                <!-- Layout principal del chat -->
                <div class="chat-layout" id="chatLayout">
                    <!-- SIDEBAR removido de aquí para evitar problemas de capa gris -->

                    <!-- Área de chat principal -->
                    <div class="chat-main" id="chatMain">
                        <?php if (isset($chat_activo) && $chat_activo): // Solo si hay un chat seleccionado 
                        ?>
                            <div class="chat-header">
                                <!-- Botón Menú Contactos (Hamburguesa - Solo Móvil) -->
                                <button class="btn btn-link text-white p-0 me-3 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasContacts" aria-controls="offcanvasContacts" title="Ver lista de contactos" style="text-decoration: none;">
                                    <i class="fas fa-bars fa-lg"></i>
                                </button>

                                <div class="chat-header-avatar <?php echo (isset($otro_usuario_rol) && $otro_usuario_rol === 'admin') ? 'admin-avatar' : 'user-avatar'; ?>">
                                    <?php echo strtoupper(substr($otro_usuario_nombre, 0, 1)); ?>
                                </div>
                                <div class="chat-header-info">
                                    <h5><?php echo htmlspecialchars($otro_usuario_nombre); ?></h5>
                                    <small>
                                        <?php
                                        if (isset($otro_usuario_rol) && $otro_usuario_rol === 'admin') echo '<i class="fas fa-shield-alt text-warning"></i> Administrador';
                                        else echo '<i class="fas fa-user"></i> Capturista';
                                        ?>
                                    </small>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="chat-header intro-header">
                                <!-- Botón Menú Contactos (Hamburguesa - Solo Móvil) -->
                                <button class="btn btn-link text-white p-0 me-3 d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasContacts" aria-controls="offcanvasContacts" title="Ver lista de contactos" style="text-decoration: none;">
                                    <i class="fas fa-bars fa-lg"></i>
                                </button>
                                <div class="chat-header-avatar" id="chatAvatar">
                                    <i class="fas fa-comments"></i>
                                </div>
                                <div class="chat-header-info">
                                    <h5 id="chatTitle">Mensajes</h5>
                                    <small id="chatSubtitle">Selecciona un contacto para chatear</small>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="chat-messages" id="chatMessages">
                            <!-- Placeholder de Bienvenida -->
                            <?php if (!isset($chat_activo) || !$chat_activo): ?>
                                <div id="chatPlaceholder" class="chat-empty" style="display: flex; flex-direction: column; justify-content: center; height: 100%;">
                                    <div class="chat-empty-icon" style="font-size: 4rem; color: #e2e8f0; margin-bottom: 20px;">
                                        <i class="fas fa-comments-alt"></i>
                                    </div>
                                    <h5 style="color: #64748b;">Tus conversaciones</h5>
                                    <p style="color: #94a3b8;">Selecciona un contacto de la lista para comenzar a chatear.</p>
                                    <button class="btn btn-primary d-lg-none mt-3" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasContacts">
                                        Ver Contactos
                                    </button>
                                </div>
                            <?php endif; ?>

                            <!-- Tab: Inbox - Ahora oculto por defecto si no hay chat activo -->
                            <div class="messages-tab" id="inboxMessages" style="<?php echo (!isset($chat_activo) || !$chat_activo) ? 'display: none;' : ''; ?>">
                                <?php if (mysqli_num_rows($res_todos) > 0): ?>
                                    <?php
                                    $lastDate = '';
                                    while ($msg = mysqli_fetch_assoc($res_todos)):
                                        $msgDate = date('d/m/Y', strtotime($msg['fecha_envio']));
                                        $isUnread = $msg['leido'] == 0 && $msg['tipo_mensaje'] === 'recibido';
                                        $isSolicitud = $msg['tipo'] === 'solicitud_apertura';
                                        $isReceived = $msg['tipo_mensaje'] === 'recibido';

                                        if ($msgDate !== $lastDate):
                                            $lastDate = $msgDate;
                                    ?>
                                            <div class="date-separator">
                                                <span><?php echo $msgDate === date('d/m/Y') ? 'Hoy' : $msgDate; ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <?php
                                        // El "otro usuario" en la conversación
                                        $otro_id = ($msg['remitente_id'] == $usuario_id) ? $msg['destinatario_id'] : $msg['remitente_id'];
                                        ?>
                                        <div class="message-bubble <?php echo $isReceived ? 'received' : 'sent'; ?> <?php echo $isUnread ? 'unread' : ''; ?>"
                                            data-otro-id="<?php echo $otro_id; ?>"
                                            <?php if ($isUnread): ?>onclick="window.location='?leer=<?php echo $msg['id']; ?>'" <?php endif; ?>>
                                            <div class="bubble-content">
                                                <?php if ($isReceived): ?>
                                                    <div class="bubble-sender"><?php echo htmlspecialchars($msg['otro_usuario_nombre']); ?></div>
                                                <?php endif; ?>
                                                <span class="bubble-text"><?php echo nl2br(htmlspecialchars($msg['mensaje'])); ?></span>

                                                <?php if ($isSolicitud && $rol === 'admin' && $isReceived): ?>
                                                    <div class="admin-action">
                                                        <small>Solicita: <?php echo $msg['referencia_mes'] . '/' . $msg['referencia_anio']; ?></small>
                                                        <form method="POST" class="mt-1">
                                                            <input type="hidden" name="action" value="habilitar_mes">
                                                            <input type="hidden" name="usuario_id_ext" value="<?php echo $msg['remitente_id']; ?>">
                                                            <input type="hidden" name="mes_ext" value="<?php echo $msg['referencia_mes']; ?>">
                                                            <input type="hidden" name="anio_ext" value="<?php echo $msg['referencia_anio']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-warning text-white">
                                                                <i class="fas fa-unlock me-1"></i>Habilitar
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="bubble-time">
                                                <?php echo date('g:i a', strtotime($msg['fecha_envio'])); ?>
                                                <?php if (!$isReceived): ?>
                                                    <?php if ($msg['leido']): ?>
                                                        <i class="fas fa-check-double check"></i>
                                                    <?php else: ?>
                                                        <i class="fas fa-check"></i>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <div class="chat-empty">
                                        <div class="chat-empty-icon">
                                            <i class="fas fa-comments"></i>
                                        </div>
                                        <h6>Sin mensajes</h6>
                                        <p>Tus conversaciones aparecerán aquí</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Tab: Unread - Solo mensajes no leídos -->
                            <div class="messages-tab" id="unreadMessages" style="display: none;">
                                <?php
                                mysqli_data_seek($res_recibidos, 0);
                                $hasUnread = false;
                                $lastDate = '';
                                while ($msg = mysqli_fetch_assoc($res_recibidos)):
                                    if ($msg['leido'] == 0):
                                        $hasUnread = true;
                                        $msgDate = date('d/m/Y', strtotime($msg['fecha_envio']));
                                        $isSolicitud = $msg['tipo'] === 'solicitud_apertura';

                                        if ($msgDate !== $lastDate):
                                            $lastDate = $msgDate;
                                ?>
                                            <div class="date-separator">
                                                <span><?php echo $msgDate === date('d/m/Y') ? 'Hoy' : $msgDate; ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <div class="message-bubble received unread" onclick="window.location='?leer=<?php echo $msg['id']; ?>'">
                                            <div class="bubble-content">
                                                <div class="bubble-sender"><?php echo htmlspecialchars($msg['remitente_nombre']); ?></div>
                                                <span class="bubble-text"><?php echo nl2br(htmlspecialchars($msg['mensaje'])); ?></span>

                                                <?php if ($isSolicitud && $rol === 'admin'): ?>
                                                    <div class="admin-action">
                                                        <small>Solicita: <?php echo $msg['referencia_mes'] . '/' . $msg['referencia_anio']; ?></small>
                                                        <form method="POST" class="mt-1">
                                                            <input type="hidden" name="action" value="habilitar_mes">
                                                            <input type="hidden" name="usuario_id_ext" value="<?php echo $msg['remitente_id']; ?>">
                                                            <input type="hidden" name="mes_ext" value="<?php echo $msg['referencia_mes']; ?>">
                                                            <input type="hidden" name="anio_ext" value="<?php echo $msg['referencia_anio']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-warning text-white">
                                                                <i class="fas fa-unlock me-1"></i>Habilitar
                                                            </button>
                                                        </form>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <span class="bubble-time"><?php echo date('g:i a', strtotime($msg['fecha_envio'])); ?></span>
                                        </div>
                                    <?php
                                    endif;
                                endwhile;

                                if (!$hasUnread): ?>
                                    <div class="chat-empty">
                                        <div class="chat-empty-icon">
                                            <i class="fas fa-check-circle" style="color: #2563eb;"></i>
                                        </div>
                                        <h6>¡Todo al día!</h6>
                                        <p>No tienes mensajes sin leer</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Input de mensaje estilo WhatsApp -->
                        <form method="POST" class="chat-input-container" id="messageForm" style="display: none;">
                            <input type="hidden" name="action" value="enviar_mensaje">
                            <input type="hidden" name="tipo" id="msgTipo" value="general">
                            <input type="hidden" name="referencia_mes" id="msgMes">
                            <input type="hidden" name="referencia_anio" id="msgAnio">

                            <div class="chat-input-wrapper">
                                <div class="chat-input-row">
                                    <select name="destinatario_id" id="msgDestino" class="chat-select" required>
                                        <?php if ($rol !== 'admin'): ?>
                                            <option value="">Administradores</option>
                                        <?php else: ?>
                                            <option value="">Seleccionar destinatario...</option>
                                            <?php foreach ($usuarios_dest as $u): ?>
                                                <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['nombre']); ?></option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                    <input type="hidden" name="asunto" id="msgAsunto" value="Chat">
                                </div>
                                <div class="chat-input-row">
                                    <textarea name="mensaje" id="msgCuerpo" class="chat-input-field" placeholder="Escribe tu mensaje..." rows="1" required></textarea>
                                    <button type="submit" class="chat-send-btn" title="Enviar mensaje">
                                        <i class="fas fa-paper-plane"></i>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Cambiar entre tabs de Recibidos/No leídos
        function switchTab(tab) {
            document.querySelectorAll('.chat-tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.chat-tab[data-tab="${tab}"]`).classList.add('active');

            const messageForm = document.getElementById('messageForm');

            if (tab === 'inbox') {
                document.getElementById('inboxMessages').style.display = 'block';
                document.getElementById('unreadMessages').style.display = 'none';
                document.getElementById('chatTitle').textContent = 'Conversación';
                document.getElementById('chatSubtitle').innerHTML = '<i class="fas fa-circle text-success me-1" style="font-size: 0.5rem;"></i> Todos los mensajes';
                messageForm.style.display = 'none';

                // Ocultar mensajes bubbles al volver a la bandeja general (ahora vacía/placeholder)
                document.querySelectorAll('#inboxMessages .message-bubble, #inboxMessages .date-separator').forEach(el => el.style.display = 'none');

                // Mostrar placeholder
                const placeholder = document.getElementById('chatPlaceholder');
                if (placeholder) placeholder.style.display = 'flex';
                document.getElementById('inboxMessages').style.display = 'none';

                // Quitar selección activa de contactos
                document.querySelectorAll('.contact-item').forEach(c => c.classList.remove('active'));
            } else if (tab === 'unread') {
                document.getElementById('inboxMessages').style.display = 'none';
                document.getElementById('unreadMessages').style.display = 'block';
                document.getElementById('chatTitle').textContent = 'Mensajes No Leídos';
                document.getElementById('chatSubtitle').innerHTML = '<i class="fas fa-envelope text-warning me-1"></i> Pendientes de leer';
                messageForm.style.display = 'none';
            }
        }

        // Seleccionar contacto
        function selectContact(id, nombre, rol) {
            document.querySelectorAll('.contact-item').forEach(c => c.classList.remove('active'));
            event.currentTarget.classList.add('active');

            // Actualizar select de destinatario
            const selectDestino = document.getElementById('msgDestino');
            if (id === 0) {
                selectDestino.value = '';
            } else {
                selectDestino.value = id;
            }

            // Establecer asunto predeterminado si no es solicitud
            if (document.getElementById('msgTipo').value !== 'solicitud_apertura') {
                document.getElementById('msgAsunto').value = 'Chat con ' + nombre;
            }

            // Mostrar el formulario ya que el chat está activo
            const messageForm = document.getElementById('messageForm');
            messageForm.style.display = 'block';

            // Actualizar header del chat
            document.getElementById('chatTitle').textContent = nombre;
            document.getElementById('chatSubtitle').innerHTML = `<i class="fas fa-circle text-success me-1" style="font-size: 0.5rem;"></i> ${rol.charAt(0).toUpperCase() + rol.slice(1)}`;

            // Actualizar avatar
            const avatar = document.getElementById('chatAvatar');
            const initials = nombre.substring(0, 2).toUpperCase();
            avatar.innerHTML = initials;
            avatar.className = 'chat-header-avatar ' + (rol === 'admin' ? 'admin-avatar' : 'user-avatar');

            // Ocultar placeholder y mostrar mensajes
            const placeholder = document.getElementById('chatPlaceholder');
            if (placeholder) placeholder.style.display = 'none';
            document.getElementById('inboxMessages').style.display = 'block';

            // Enfocar el campo de mensaje directamente
            document.getElementById('msgCuerpo').focus();

            // Mostrar el chat principal en móvil
            document.getElementById('chatLayout').classList.add('show-chat');

            // --- FILTRAR MENSAJES ---
            // Ocultar todos los mensajes y separadores primero
            document.querySelectorAll('#inboxMessages .message-bubble, #inboxMessages .date-separator').forEach(el => el.style.display = 'none');

            // Mostrar solo los mensajes que corresponden al contacto seleccionado
            const relevantMessages = document.querySelectorAll(`#inboxMessages .message-bubble[data-otro-id="${id}"]`);
            relevantMessages.forEach(el => el.style.display = 'flex');

            // Mostrar también los separadores de fecha que tengan mensajes visibles debajo (opcional, simplificado por ahora)
            // Para una versión más pro, podríamos re-analizar los divisores, pero esto soluciona el problema principal.

            // Hacer scroll al final después de filtrar
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Filtrar contactos
        function filterContacts() {
            const search = document.getElementById('searchContact').value.toLowerCase();
            document.querySelectorAll('.contact-item').forEach(item => {
                const name = item.querySelector('.contact-name').textContent.toLowerCase();
                item.style.display = name.includes(search) ? 'flex' : 'none';
            });
        }

        // Solicitar apertura de mes
        function solicitarApertura() {
            const mesActual = new Date().getMonth() + 1;
            const anioActual = new Date().getFullYear();

            let mesPasado = mesActual - 1;
            let anioPasado = anioActual;
            if (mesPasado === 0) {
                mesPasado = 12;
                anioPasado = anioActual - 1;
            }

            const nombresMeses = ["", "Enero", "Febrero", "Marzo", "Abril", "Mayo", "Junio", "Julio", "Agosto", "Septiembre", "Octubre", "Noviembre", "Diciembre"];

            document.getElementById('msgTipo').value = 'solicitud_apertura';
            document.getElementById('msgMes').value = mesPasado;
            document.getElementById('msgAnio').value = anioPasado;

            const selectDestino = document.getElementById('msgDestino');
            if (selectDestino.options.length > 0) selectDestino.selectedIndex = 0;

            document.getElementById('msgAsunto').value = `Solicitud de Apertura - ${nombresMeses[mesPasado]} ${anioPasado}`;
            document.getElementById('msgCuerpo').value = `Hola Administrador,\n\nSolicito amablemente que habiliten la captura para el mes de ${nombresMeses[mesPasado]} del año ${anioPasado}, ya que no pude completar el registro a tiempo.\n\nGracias.`;

            document.getElementById('msgCuerpo').focus();
        }

        // Auto-resize del textarea
        const textarea = document.getElementById('msgCuerpo');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // --- SISTEMA DE NOTIFICACIONES NATIVAS ---
        let lastNotifiedId = null;

        function requestNotificationPermission() {
            if (!("Notification" in window)) return;
            if (Notification.permission !== "granted" && Notification.permission !== "denied") {
                Notification.requestPermission();
            }
        }

        function checkNewMessages() {
            fetch('api_verificar_mensajes.php')
                .then(response => response.json())
                .then(data => {
                    if (data.new_messages && data.id !== lastNotifiedId) {
                        // No notificar si el chat de esa persona está abierto y visible
                        const urlParams = new URLSearchParams(window.location.search);
                        const activeChatId = urlParams.get('chat_con');

                        if (activeChatId != data.remitente_id || document.hidden) {
                            showNativeNotification(data);
                            lastNotifiedId = data.id;
                        }
                    }
                })
                .catch(err => console.error('Error al verificar mensajes:', err));
        }

        function showNativeNotification(data) {
            if (Notification.permission === "granted") {
                const notification = new Notification(`Nuevo mensaje de ${data.remitente}`, {
                    body: data.mensaje,
                    icon: 'assets/img/icon-message.png' // Asegúrate de que este icono exista o usa uno genérico
                });

                notification.onclick = function() {
                    window.focus();
                    window.location.href = `mensajes.php?chat_con=${data.remitente_id}`;
                };
            }
        }

        // Iniciar sistema de notificaciones
        document.addEventListener('DOMContentLoaded', function() {
            requestNotificationPermission();
            // Verificar cada 10 segundos
            setInterval(checkNewMessages, 10000);

            const chatMessages = document.getElementById('chatMessages');
            if (chatMessages) chatMessages.scrollTop = chatMessages.scrollHeight;

            // Mostrar Toast si el envío fue exitoso
            <?php if (isset($success_msg) && $success_msg): ?>
                const toastLiveExample = document.getElementById('liveToast');
                const toast = new bootstrap.Toast(toastLiveExample);
                toast.show();
            <?php endif; ?>

            // Activar chat automáticamente si viene en la URL
            <?php if (isset($chat_con_id) && $chat_con_id): ?>
                const activeContact = document.querySelector(`.contact-item[data-id="<?php echo $chat_con_id; ?>"]`);
                if (activeContact) {
                    const nombre = activeContact.querySelector('.contact-name').textContent;
                    const rol = activeContact.querySelector('.contact-role').textContent.toLowerCase();
                    selectContact(<?php echo $chat_con_id; ?>, nombre, rol);
                }
            <?php endif; ?>
        });

        // Auto-scroll al fondo del chat
        var chatMessages = document.querySelector('.chat-messages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }

        // Lógica para vista móvil (Master-Detail) - YA NO NECESARIA CON OFFCANVAS
        // El Offcanvas maneja la visibilidad de la lista de contactos.
        // Solo necesitamos asegurar que el chat principal ocupe el ancho completo siempre.

        document.addEventListener('DOMContentLoaded', function() {
            // Ajuste inicial si es necesario
        });
    </script>
</body>

</html>