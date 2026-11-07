<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersSeguridad();
establecerHeadersNoCache();

require_once 'partials/check_session.php';

require_once __DIR__ . '/includes/check_mantenimiento.php';

$rol = $_SESSION['rol'];
$usuario_id = $_SESSION['usuario_id'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';

$es_admin = ($rol === 'admin');
$todas_jurisdicciones = jurisdicciones();

// Determinar jurisdicción de trabajo
if ($es_admin) {
    $jurisdiccion_trabajo = $_POST['jurisdiccion_sel'] ?? $_GET['jurisdiccion_sel'] ?? '';
} else {
    $jurisdiccion_trabajo = $jurisdiccion_usuario;
}

// Cargar configuración del sistema
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

// Determinar mes y año actual (desde config o servidor)
$usar_fecha_servidor = $config_sistema['usar_fecha_servidor'] ?? true;

if ($usar_fecha_servidor) {
    // Usar fecha del servidor (automático)
    $mes_actual_num = (int)date('n');
    $anio_actual = (int)date('Y');
} else {
    // Usar fecha manual del config
    $mes_actual_num = (int)($config_sistema['mes_actual'] ?? date('n'));
    $anio_actual = (int)($config_sistema['anio_actual'] ?? date('Y'));
}

// Obtener municipios y hospitales
$municipios = [];
$hospitales = [];
if (!empty($jurisdiccion_trabajo) && $jurisdiccion_trabajo !== 'TODAS') {
    $municipios = municipiosPorJurisdiccion($jurisdiccion_trabajo);
    $hospitales = hospitalesPorJurisdiccion($jurisdiccion_trabajo);
}

$csrfToken = generarTokenCSRF();

// Obtener meses disponibles para captura
$meses_disponibles = [];
$dia_actual = (int)date('j'); // Día del mes (1-31)

if ($rol === 'capturista') {
    // REGLA: SOLO durante los primeros 10 días del mes se puede registrar el mes ANTERIOR
    // Después del día 10, NO se puede registrar (periodo cerrado hasta el próximo mes)

    if ($dia_actual <= 10) {
        // Primeros 10 días: capturar mes ANTERIOR
        $mes_disponible = $mes_actual_num - 1;
        $anio_disponible = $anio_actual;

        if ($mes_disponible === 0) {
            $mes_disponible = 12;
            $anio_disponible = $anio_actual - 1;
        }

        // Agregar el mes disponible según la regla
        $meses_disponibles[] = [
            'mes' => $mes_disponible,
            'anio' => $anio_disponible,
            'label' => ucfirst(mesClaveDesdeNumero($mes_disponible)) . ' ' . $anio_disponible
        ];
    }
    // Si día > 10, $meses_disponibles queda vacío (no hay periodo disponible para registrar)

    // Agregar meses con extensiones/prórrogas activas
    $stmt_ext = mysqli_prepare($conn, "SELECT DISTINCT mes, anio FROM extensiones WHERE usuario_id = ? AND fecha_limite > NOW() ORDER BY anio DESC, mes DESC");
    mysqli_stmt_bind_param($stmt_ext, 'i', $usuario_id);
    mysqli_stmt_execute($stmt_ext);
    $res_ext = mysqli_stmt_get_result($stmt_ext);

    while ($ext = mysqli_fetch_assoc($res_ext)) {
        // Evitar duplicados
        $ya_existe = false;
        foreach ($meses_disponibles as $md) {
            if ($md['mes'] == $ext['mes'] && $md['anio'] == $ext['anio']) {
                $ya_existe = true;
                break;
            }
        }

        if (!$ya_existe) {
            $meses_disponibles[] = [
                'mes' => $ext['mes'],
                'anio' => $ext['anio'],
                'label' => ucfirst(mesClaveDesdeNumero($ext['mes'])) . ' ' . $ext['anio'] . ' (Prórroga)'
            ];
        }
    }
} else {
    // Admin: todos los meses del año activo
    for ($m = 1; $m <= 12; $m++) {
        $meses_disponibles[] = [
            'mes' => $m,
            'anio' => $anio_activo,
            'label' => ucfirst(mesClaveDesdeNumero($m)) . ' ' . $anio_activo
        ];
    }
}

// Variables para mensajes (mantener compatibilidad)
$mes_anterior_num = $mes_actual_num - 1;
$anio_mes_anterior = $anio_actual;
if ($mes_anterior_num === 0) {
    $mes_anterior_num = 12;
    $anio_mes_anterior = $anio_actual - 1;
}
$mes_clave_anterior = mesClaveDesdeNumero($mes_anterior_num);
$anio_captura = $anio_mes_anterior;
$puede_capturar_mes_anterior = !empty($meses_disponibles);

// Procesar formulario de captura
$mensaje = '';
$error = '';

// Verificar si el usuario tiene extensión activa (para mostrar en el formulario)
$tiene_extension = false;
if ($rol === 'capturista' && isset($_POST['mes']) && isset($_POST['anio'])) {
    $mes_check = intval($_POST['mes']);
    $anio_check = intval($_POST['anio']);
    $stmt_check_ext = mysqli_prepare($conn, "SELECT id FROM extensiones WHERE usuario_id = ? AND mes = ? AND anio = ? AND fecha_limite > NOW()");
    mysqli_stmt_bind_param($stmt_check_ext, 'iii', $usuario_id, $mes_check, $anio_check);
    mysqli_stmt_execute($stmt_check_ext);
    $res_check_ext = mysqli_stmt_get_result($stmt_check_ext);
    $tiene_extension = mysqli_num_rows($res_check_ext) > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_captura'])) {
    if (!isset($_POST['csrf_token']) || !validarTokenCSRF($_POST['csrf_token'])) {
        $error = 'Token de seguridad inválido. Por favor, recargue la página e intente nuevamente.';
    } else {
        $municipio = sanitizarSQL($_POST['municipio'] ?? '');
        $mes_seleccionado = validarInput($_POST['mes'] ?? 0, 'int', ['min' => 1, 'max' => 12]);
        $anio_seleccionado = validarInput($_POST['anio'] ?? 0, 'anio');

        if (empty($municipio) || $mes_seleccionado === false) {
            $error = "Datos incompletos. Selecciona municipio y mes.";
        } else {
            $mes_clave_sel = mesClaveDesdeNumero($mes_seleccionado);

            // Verificar si el año está cerrado para edición (solo para capturistas)
            if ($rol === 'capturista') {
                $check_anio_cerrado = mysqli_prepare($conn, "SELECT COUNT(*) as total, SUM(editable) as editables FROM registros WHERE anio = ?");
                mysqli_stmt_bind_param($check_anio_cerrado, 'i', $anio_seleccionado);
                mysqli_stmt_execute($check_anio_cerrado);
                $res_anio = mysqli_stmt_get_result($check_anio_cerrado);
                $estado_anio = mysqli_fetch_assoc($res_anio);

                // Si hay registros y todos tienen editable=0, el año está cerrado
                if ($estado_anio['total'] > 0 && (int)$estado_anio['editables'] === 0) {
                    $error = "El año $anio_seleccionado está cerrado para edición. Contacta al administrador si necesitas realizar cambios.";
                }
            }

            // NUEVA LÓGICA: Verificar si es REGISTRO NUEVO o EDICIÓN
            if (empty($error) && $rol === 'capturista') {
                // Verificar si ya existe algún registro para este municipio/mes/año
                $tipos_check = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
                $ya_existe_registro = false;

                foreach ($tipos_check as $tipo_check) {
                    $stmt_check = mysqli_prepare($conn, "SELECT id FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ? AND `$mes_clave_sel` IS NOT NULL");
                    if ($stmt_check) {
                        mysqli_stmt_bind_param($stmt_check, 'isss', $anio_seleccionado, $jurisdiccion_trabajo, $municipio, $tipo_check);
                        mysqli_stmt_execute($stmt_check);
                        $res_check = mysqli_stmt_get_result($stmt_check);
                        if (mysqli_num_rows($res_check) > 0) {
                            $ya_existe_registro = true;
                            break;
                        }
                    }
                }

                if ($ya_existe_registro) {
                    // ES EDICIÓN: Verificar permisos estrictos (5 días hábiles o prórroga)
                    if (!puedeEditar($mes_clave_sel, $anio_seleccionado)) {
                        $error = "No puedes editar este registro. Solo puedes editar dentro de los 5 días hábiles desde la captura, o solicita una prórroga al administrador.";
                    }
                } else {
                    // ES REGISTRO NUEVO: Verificar que esté en la lista de meses disponibles
                    $mes_permitido = false;
                    foreach ($meses_disponibles as $md) {
                        if ($md['mes'] == $mes_seleccionado && $md['anio'] == $anio_seleccionado) {
                            $mes_permitido = true;
                            break;
                        }
                    }

                    if (!$mes_permitido) {
                        $error = "No puedes registrar datos para este periodo. Solo puedes capturar el mes anterior o meses con prórroga activa.";
                    }
                }
            }

            if (empty($error)) {
                // Verificar si el usuario tiene extensión activa para este periodo
                $tiene_extension = false;
                if ($rol === 'capturista') {
                    $stmt_check_ext = mysqli_prepare($conn, "SELECT id FROM extensiones WHERE usuario_id = ? AND mes = ? AND anio = ? AND fecha_limite > NOW()");
                    mysqli_stmt_bind_param($stmt_check_ext, 'iii', $usuario_id, $mes_seleccionado, $anio_seleccionado);
                    mysqli_stmt_execute($stmt_check_ext);
                    $res_check_ext = mysqli_stmt_get_result($stmt_check_ext);
                    $tiene_extension = mysqli_num_rows($res_check_ext) > 0;
                }

                // Guardar los 6 tipos
                $tipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];

                // VALIDAR según si tiene extensión o no
                $valores_completos = true;
                $valores = [];

                if ($tiene_extension || $rol === 'admin') {
                    // Con extensión o admin: permitir actualización parcial
                    foreach ($tipos as $tipo) {
                        if (isset($_POST[$tipo]) && $_POST[$tipo] !== '') {
                            $valores[$tipo] = intval($_POST[$tipo]);
                            if ($valores[$tipo] < 0) {
                                $valores_completos = false;
                                break;
                            }
                        }
                    }
                } else {
                    // Sin extensión: TODOS los 6 valores son obligatorios
                    foreach ($tipos as $tipo) {
                        if (!isset($_POST[$tipo]) || $_POST[$tipo] === '') {
                            $valores_completos = false;
                            break;
                        }
                        $valores[$tipo] = intval($_POST[$tipo]);
                        if ($valores[$tipo] < 0) {
                            $valores_completos = false;
                            break;
                        }
                    }
                }

                if (!$valores_completos) {
                    if ($tiene_extension) {
                        $error = "Los valores ingresados no son válidos (deben ser números positivos).";
                    } else {
                        $error = "Debes ingresar TODOS los 6 valores (Vacunados, Esterilizados, Control, Muestras, Agredidos, Tratamientos). No se permiten registros incompletos.";
                    }
                } else {
                    // Si tiene extensión, verificar si ya registró durante la extensión
                    if ($tiene_extension) {
                        // Verificar si ya existe algún registro para este municipio/mes/año
                        $ya_registro_en_extension = false;
                        foreach ($tipos as $tipo_check) {
                            $stmt_check_reg = mysqli_prepare($conn, "SELECT id FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ? AND `$mes_clave_sel` IS NOT NULL AND `$mes_clave_sel` > 0");
                            if ($stmt_check_reg) {
                                mysqli_stmt_bind_param($stmt_check_reg, 'isss', $anio_seleccionado, $jurisdiccion_trabajo, $municipio, $tipo_check);
                                mysqli_stmt_execute($stmt_check_reg);
                                $res_check_reg = mysqli_stmt_get_result($stmt_check_reg);
                                if (mysqli_num_rows($res_check_reg) > 0) {
                                    $ya_registro_en_extension = true;
                                    break;
                                }
                            }
                        }

                        if ($ya_registro_en_extension) {
                            $error = "Ya realizaste un registro durante esta extensión. No se permiten modificaciones adicionales. La extensión es para una sola captura.";
                        }
                    }

                    if (empty($error)) {
                        // Proceder a guardar solo los valores proporcionados
                        $errores = [];
                        $guardados = 0;

                        foreach ($valores as $tipo => $valor) {
                            // Verificar si existe meta
                            $stmt_meta = mysqli_prepare($conn, "SELECT meta FROM metas WHERE jurisdiccion = ? AND municipio = ? AND tipo = ? AND anio = ?");
                            if (!$stmt_meta) {
                                $errores[] = "Error en consulta de meta para $tipo: " . mysqli_error($conn);
                                continue;
                            }
                            mysqli_stmt_bind_param($stmt_meta, 'sssi', $jurisdiccion_trabajo, $municipio, $tipo, $anio_seleccionado);
                            mysqli_stmt_execute($stmt_meta);
                            $res_meta = mysqli_stmt_get_result($stmt_meta);
                            $meta_row = mysqli_fetch_assoc($res_meta);
                            $meta_anual = $meta_row ? $meta_row['meta'] : 0;

                            // Verificar si ya existe registro
                            $stmt_check = mysqli_prepare($conn, "SELECT id FROM registros WHERE anio = ? AND jurisdiccion = ? AND municipio = ? AND tipo = ?");
                            if (!$stmt_check) {
                                $errores[] = "Error en consulta de verificación para $tipo: " . mysqli_error($conn);
                                continue;
                            }
                            mysqli_stmt_bind_param($stmt_check, 'isss', $anio_seleccionado, $jurisdiccion_trabajo, $municipio, $tipo);
                            mysqli_stmt_execute($stmt_check);
                            $res_check = mysqli_stmt_get_result($stmt_check);
                            $existe = mysqli_fetch_assoc($res_check);

                            if ($existe) {
                                // Actualizar
                                $sql_update = "UPDATE registros SET `$mes_clave_sel` = ?, usuario_id = ? WHERE id = ?";
                                $stmt_update = mysqli_prepare($conn, $sql_update);
                                mysqli_stmt_bind_param($stmt_update, 'iii', $valor, $usuario_id, $existe['id']);
                                if (mysqli_stmt_execute($stmt_update)) {
                                    $guardados++;
                                } else {
                                    $errores[] = "Error al actualizar $tipo";
                                }
                            } else {
                                // Insertar nuevo
                                $sql_insert = "INSERT INTO registros (usuario_id, anio, jurisdiccion, municipio, meta_anual, tipo, `$mes_clave_sel`) VALUES (?, ?, ?, ?, ?, ?, ?)";
                                $stmt_insert = mysqli_prepare($conn, $sql_insert);
                                mysqli_stmt_bind_param($stmt_insert, 'iissisi', $usuario_id, $anio_seleccionado, $jurisdiccion_trabajo, $municipio, $meta_anual, $tipo, $valor);
                                if (mysqli_stmt_execute($stmt_insert)) {
                                    $guardados++;
                                } else {
                                    $errores[] = "Error al guardar $tipo";
                                }
                            }
                        }

                        if ($guardados > 0) {
                            $mensaje = "Se guardaron $guardados registros correctamente.";
                        }
                        if (!empty($errores)) {
                            $error = implode(', ', $errores);
                        }
                    }
                }
            }
        }
    }
}

// Obtener todos los registros del año activo para la jurisdicción
$registros_por_mes = [];
if (!empty($jurisdiccion_trabajo) && $conn) {
    for ($mes = 1; $mes <= 12; $mes++) {
        $mes_clave = mesClaveDesdeNumero($mes);

        $sql = "SELECT r.municipio, r.tipo, r.`$mes_clave` as valor, r.meta_anual, r.fecha_captura, r.created_at
                FROM registros r
                WHERE r.jurisdiccion = ? AND r.anio = ?
                ORDER BY r.municipio, r.tipo";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 'si', $jurisdiccion_trabajo, $anio_activo);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);

        $registros_por_mes[$mes] = [];
        while ($row = mysqli_fetch_assoc($res)) {
            $muni = $row['municipio'];
            if (!isset($registros_por_mes[$mes][$muni])) {
                $registros_por_mes[$mes][$muni] = [
                    'municipio' => $muni,
                    'es_hospital' => preg_match('/^H\./', $muni),
                    'tipos' => []
                ];
            }
            $registros_por_mes[$mes][$muni]['tipos'][$row['tipo']] = [
                'valor' => $row['valor'] ?? 0,
                'meta' => $row['meta_anual'] ?? 0,
                'fecha_captura' => $row['fecha_captura'] ?? $row['created_at'],
                'created_at' => $row['created_at']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Captura Mensual - Zoonosis</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>" rel="stylesheet">
    <link href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>" rel="stylesheet">
    <style>
        /* Estilos para el efecto de libro */
        .book-container {
            perspective: 2000px;
            margin: 2rem auto;
            max-width: 1400px;
        }

        .book-page {
            background: white;
            border-radius: 16px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            min-height: 600px;
            position: relative;
            transform-style: preserve-3d;
            transition: transform 0.6s ease;
        }

        .book-page.flipping {
            animation: pageFlip 0.6s ease;
        }

        @keyframes pageFlip {
            0% {
                transform: rotateY(0deg);
            }

            50% {
                transform: rotateY(-90deg);
            }

            100% {
                transform: rotateY(0deg);
            }
        }

        /* Navegación de meses */
        .month-nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 2px solid #e2e8f0;
        }

        .month-nav button {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            color: white;
            padding: 14px 28px;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .month-nav button:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.5);
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        .month-nav button:active:not(:disabled) {
            transform: translateY(-1px);
        }

        .month-nav button:disabled {
            opacity: 0.4;
            cursor: not-allowed;
            background: linear-gradient(135deg, #94a3b8, #64748b);
            box-shadow: none;
            transform: none;
        }

        @media (max-width: 768px) {
            .month-nav {
                flex-direction: column;
                gap: 1rem;
                padding: 1rem;
            }

            .month-nav button {
                width: 100%;
                justify-content: center;
            }

            .month-title {
                font-size: 1.5rem;
                margin: 0.5rem 0;
            }

            .view-toggle {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        .month-title {
            font-size: 2rem;
            font-weight: 800;
            color: #1e40af;
            text-align: center;
            flex: 1;
            text-shadow: 0 2px 4px rgba(30, 64, 175, 0.1);
            letter-spacing: -0.5px;
        }

        /* Toggle de vista */
        .view-toggle {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .view-toggle button {
            flex: 1;
            padding: 12px 24px;
            border: 2px solid #e2e8f0;
            background: white;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .view-toggle button.active {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-color: #2563eb;
            color: white;
        }

        /* Tabla de captura mejorada */
        .capture-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
        }

        .capture-table th {
            background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 100%);
            color: white;
            padding: 16px 12px;
            font-weight: 700;
            font-size: 0.9rem;
            text-align: center;
            border: none;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .capture-table th:first-child {
            border-top-left-radius: 12px;
            text-align: left;
            padding-left: 20px;
        }

        .capture-table th:last-child {
            border-top-right-radius: 12px;
        }

        .capture-table tbody tr {
            transition: all 0.3s ease;
            border-bottom: 1px solid #f1f5f9;
        }

        .capture-table tbody tr:hover {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            transform: scale(1.01);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .capture-table tbody tr:last-child {
            border-bottom: none;
        }

        .capture-table td {
            padding: 16px 12px;
            text-align: center;
            color: #334155;
            font-weight: 500;
            border: none;
        }

        .capture-table td:first-child {
            text-align: left;
            padding-left: 20px;
            font-weight: 600;
        }

        .capture-table .badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
            display: inline-block;
            min-width: 120px;
        }

        .capture-table .badge.bg-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb) !important;
            color: white;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        .capture-table .badge.bg-warning {
            background: linear-gradient(135deg, #fbbf24, #f59e0b) !important;
            color: #78350f;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
        }

        .capture-table .badge.bg-light {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0) !important;
            color: #1e293b;
            font-weight: 700;
            border: 2px solid #cbd5e1;
        }

        .capture-table input {
            width: 100%;
            padding: 8px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
        }

        .capture-table input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }

        /* Contenedor de la tabla con header */
        .table-container {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            margin-top: 2rem;
        }

        .table-header {
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
            color: white;
            padding: 24px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid #3b82f6;
        }

        .table-header h3 {
            margin: 0;
            font-weight: 700;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-header .badge {
            background: rgba(59, 130, 246, 0.2);
            backdrop-filter: blur(10px);
            padding: 10px 20px;
            border-radius: 20px;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .table-body {
            padding: 0;
            overflow-x: auto;
        }

        /* Botones de acción mejorados */
        .btn-group {
            display: flex;
            gap: 8px;
            justify-content: center;
        }

        .btn-group .btn {
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-group .btn-warning {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #78350f;
            box-shadow: 0 4px 12px rgba(251, 191, 36, 0.3);
        }

        .btn-group .btn-warning:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(251, 191, 36, 0.5);
        }

        .btn-group .btn-danger {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
        }

        .btn-group .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.5);
        }

        .btn-group .btn-secondary {
            background: linear-gradient(135deg, #94a3b8, #64748b);
            color: white;
        }

        /* Selector de jurisdicción */
        .jurisdiction-selector {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border: 2px solid #f59e0b;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .jurisdiction-selector select {
            width: 100%;
            padding: 12px;
            border: 2px solid #f59e0b;
            border-radius: 10px;
            font-weight: 600;
        }

        /* Estilos del formulario de captura (copiados del archivo antiguo) */
        .capture-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: 16px;
            padding: 2rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 10px 40px rgba(59, 130, 246, 0.3);
        }

        .capture-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .capture-header .subtitle {
            opacity: 0.9;
            font-size: 1rem;
        }

        .capture-header .badge-info {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
        }

        /* Selector de municipio y periodo mejorado */
        .selector-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }

        .selector-card .form-label {
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 0.5rem;
        }

        .selector-card .form-select {
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 1rem;
            transition: all 0.3s ease;
        }

        .selector-card .form-select:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }

        /* Cards de captura por tipo */
        .capture-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .capture-card {
            background: white;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .capture-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 5px;
            height: 100%;
        }

        .capture-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.12);
        }

        /* Colores por tipo */
        .capture-card.vacunados::before {
            background: linear-gradient(90deg, #06b6d4, #0891b2);
        }

        .capture-card.esterilizados::before {
            background: linear-gradient(90deg, #10b981, #059669);
        }

        .capture-card.control::before {
            background: linear-gradient(90deg, #3b82f6, #2563eb);
        }

        .capture-card.muestras::before {
            background: linear-gradient(90deg, #f59e0b, #d97706);
        }

        .capture-card.agredidos::before {
            background: linear-gradient(90deg, #ef4444, #dc2626);
        }

        .capture-card.tratamientos::before {
            background: linear-gradient(90deg, #8b5cf6, #7c3aed);
        }

        .capture-card .card-icon {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }

        .capture-card.vacunados .card-icon {
            background: linear-gradient(135deg, #cffafe, #a5f3fc);
            color: #0891b2;
        }

        .capture-card.esterilizados .card-icon {
            background: linear-gradient(135deg, #d1fae5, #a7f3d0);
            color: #059669;
        }

        .capture-card.control .card-icon {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #2563eb;
        }

        .capture-card.muestras .card-icon {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            color: #d97706;
        }

        .capture-card.agredidos .card-icon {
            background: linear-gradient(135deg, #fee2e2, #fecaca);
            color: #dc2626;
        }

        .capture-card.tratamientos .card-icon {
            background: linear-gradient(135deg, #ede9fe, #ddd6fe);
            color: #7c3aed;
        }

        .capture-card .card-title {
            font-weight: 700;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: #1e293b;
        }

        .capture-card .card-subtitle {
            font-size: 0.85rem;
            color: #64748b;
            margin-bottom: 1rem;
        }

        .capture-card input {
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 1.2rem;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
        }

        .capture-card input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }

        .capture-card input::placeholder {
            color: #cbd5e1;
            font-weight: 400;
        }

        /* Botón de guardar mejorado */
        .btn-guardar {
            background: linear-gradient(135deg, #10b981, #059669);
            border: none;
            padding: 16px 40px;
            font-size: 1.1rem;
            font-weight: 700;
            border-radius: 12px;
            color: white;
            box-shadow: 0 8px 30px rgba(16, 185, 129, 0.4);
            transition: all 0.3s ease;
        }

        .btn-guardar:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 40px rgba(16, 185, 129, 0.5);
            background: linear-gradient(135deg, #059669, #047857);
        }

        .btn-guardar i {
            margin-right: 10px;
        }

        /* RESPONSIVO */
        @media (max-width: 992px) {
            .capture-header {
                padding: 1rem
            }

            .capture-header h1 {
                font-size: 1.3rem
            }

            .capture-grid {
                grid-template-columns: 1fr
            }

            .capture-card {
                padding: 1rem
            }
        }

        @media (max-width: 576px) {
            .capture-header {
                padding: 0.75rem
            }

            .capture-header h1 {
                font-size: 1.1rem
            }

            .capture-card {
                padding: 0.75rem
            }

            .capture-card h3 {
                font-size: 1rem
            }
        }
    </style>
</head>

<body class="theme-metas">
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <!-- Header -->
            <div style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 2rem; color: white; margin-bottom: 2rem;">
                <h1 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 0.5rem;">
                    <i class="fas fa-calendar-alt me-2"></i>Captura Mensual
                </h1>
                <p style="opacity: 0.9; margin: 0;">
                    <?php if ($es_admin): ?>
                        Modo Administrador - Año Activo: <?= $anio_activo ?>
                    <?php else: ?>
                        Jurisdicción: <?= htmlspecialchars($jurisdiccion_usuario) ?> - Año: <?= $anio_activo ?>
                    <?php endif; ?>
                </p>
            </div>

            <?php if ($mensaje): ?>
                <div class="alert alert-success alert-dismissible fade show" style="border-radius: 12px; border-left: 5px solid #10b981;">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($mensaje) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" style="border-radius: 12px; border-left: 5px solid #ef4444;">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($rol === 'capturista' && empty($meses_disponibles)): ?>
                <div class="alert alert-warning" style="border-radius: 12px; border-left: 5px solid #f59e0b;">
                    <i class="fas fa-lock me-2"></i>
                    <strong>Periodo cerrado.</strong> No hay periodos disponibles para captura.
                    Usa el botón "Solicitar Prórroga" en Mensajes si necesitas registrar datos fuera de fecha.
                </div>
            <?php endif; ?>

            <?php if ($rol === 'capturista' && !empty($meses_disponibles)): ?>
                <div class="alert alert-info" style="border-radius: 12px; border-left: 5px solid #3b82f6;">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Meses disponibles para captura:</strong>
                    <?php foreach ($meses_disponibles as $md): ?>
                        <span class="badge bg-primary ms-1"><?= htmlspecialchars($md['label']) ?></span>
                    <?php endforeach; ?>
                    <br><small class="text-muted mt-2 d-block">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Importante:</strong> Si tienes una prórroga activa, solo puedes realizar UNA captura durante ese periodo. No se permiten modificaciones adicionales.
                    </small>
                </div>
            <?php endif; ?>

            <!-- Selector de Jurisdicción -->
            <?php if ($es_admin): ?>
                <div class="jurisdiction-selector">
                    <form method="POST" id="formJurisdiccion">
                        <label style="color: #92400e; font-weight: 700; margin-bottom: 0.5rem; display: block;">
                            <i class="fas fa-sitemap me-2"></i>Seleccionar Jurisdicción
                        </label>
                        <select name="jurisdiccion_sel" class="form-select" onchange="this.form.submit()" required>
                            <option value="">-- Selecciona una jurisdicción --</option>
                            <?php foreach ($todas_jurisdicciones as $juris): ?>
                                <option value="<?= htmlspecialchars($juris) ?>" <?= ($jurisdiccion_trabajo === $juris) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($juris) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
            <?php else: ?>
                <!-- Mostrar jurisdicción del capturista (solo lectura) -->
                <div style="background: linear-gradient(135deg, #dbeafe, #bfdbfe); border: 2px solid #3b82f6; border-radius: 12px; padding: 1.5rem; margin-bottom: 2rem;">
                    <label style="color: #1e40af; font-weight: 700; margin-bottom: 0.5rem; display: block;">
                        <i class="fas fa-map-marker-alt me-2"></i>Tu Jurisdicción
                    </label>
                    <div style="background: white; padding: 12px; border-radius: 10px; font-weight: 600; color: #1e40af; border: 2px solid #3b82f6;">
                        <?= htmlspecialchars($jurisdiccion_trabajo) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($jurisdiccion_trabajo)): ?>
                <!-- Toggle de Vista -->
                <div class="view-toggle">
                    <button onclick="cambiarVista('captura')" id="btn-captura" class="active">
                        <i class="fas fa-edit me-2"></i>Vista de Captura
                    </button>
                    <button onclick="cambiarVista('historial')" id="btn-historial">
                        <i class="fas fa-history me-2"></i>Vista de Historial
                    </button>
                </div>

                <!-- Vista de Captura (SIN efecto libro) -->
                <div id="vista-captura">
                    <div style="background: white; border-radius: 16px; padding: 2rem; box-shadow: 0 10px 40px rgba(0,0,0,0.1);">
                        <h3 style="margin-bottom: 1.5rem; color: #1e40af;">
                            <i class="fas fa-keyboard me-2"></i>Capturar Datos
                        </h3>

                        <!-- Formulario de captura -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <?php if ($es_admin): ?>
                                <input type="hidden" name="jurisdiccion_sel" value="<?= htmlspecialchars($jurisdiccion_trabajo) ?>">
                            <?php endif; ?>

                            <!-- Selector de Municipio, Mes y Año -->
                            <div class="selector-card">
                                <div class="row g-3 mb-4">
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold"><i class="fas fa-map-marker-alt me-2"></i>Municipio/Hospital</label>
                                        <select name="municipio" class="form-select" required>
                                            <option value="">Seleccionar...</option>
                                            <optgroup label="Municipios">
                                                <?php foreach ($municipios as $muni): ?>
                                                    <option value="<?= htmlspecialchars($muni) ?>"><?= htmlspecialchars($muni) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <?php if (!empty($hospitales)): ?>
                                                <optgroup label="Hospitales (solo Agredidos y Tratamientos)">
                                                    <?php foreach ($hospitales as $hosp): ?>
                                                        <option value="<?= htmlspecialchars($hosp) ?>" data-hospital="1"><?= htmlspecialchars($hosp) ?></option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endif; ?>
                                        </select>
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Los hospitales solo registran datos de Agredidos y Tratamientos
                                        </small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold"><i class="fas fa-calendar me-2"></i>Mes</label>
                                        <select name="mes" id="select-mes" class="form-select" required>
                                            <option value="">Cargando...</option>
                                        </select>
                                        <small class="text-muted" id="info-mes">
                                            <i class="fas fa-clock me-1"></i>
                                            Calculando desde fecha de tu equipo...
                                        </small>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label fw-bold"><i class="fas fa-calendar-alt me-2"></i>Año</label>
                                        <select name="anio" id="select-anio" class="form-select" required>
                                            <option value="">Cargando...</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Cards de captura por tipo -->
                            <h5 class="mb-3" style="color: #1e40af; font-weight: 700;">
                                <i class="fas fa-clipboard-list me-2"></i>Datos por Tipo de Actividad
                            </h5>

                            <div class="capture-grid">
                                <!-- Vacunados -->
                                <div class="capture-card vacunados">
                                    <div class="card-icon">
                                        <i class="fas fa-syringe"></i>
                                    </div>
                                    <div class="card-title">Vacunados</div>
                                    <div class="card-subtitle">Perros y gatos vacunados contra la rabia</div>
                                    <input type="number" name="vacunados" min="0" placeholder="0" required>
                                </div>

                                <!-- Esterilizados -->
                                <div class="capture-card esterilizados">
                                    <div class="card-icon">
                                        <i class="fas fa-cut"></i>
                                    </div>
                                    <div class="card-title">Esterilizados</div>
                                    <div class="card-subtitle">Animales esterilizados en el mes</div>
                                    <input type="number" name="esterilizados" min="0" placeholder="0" required>
                                </div>

                                <!-- Control -->
                                <div class="capture-card control">
                                    <div class="card-icon">
                                        <i class="fas fa-clipboard-check"></i>
                                    </div>
                                    <div class="card-title">Control</div>
                                    <div class="card-subtitle">Animales en control poblacional</div>
                                    <input type="number" name="control" min="0" placeholder="0" required>
                                </div>

                                <!-- Muestras -->
                                <div class="capture-card muestras">
                                    <div class="card-icon">
                                        <i class="fas fa-vial"></i>
                                    </div>
                                    <div class="card-title">Muestras</div>
                                    <div class="card-subtitle">Muestras recolectadas para análisis</div>
                                    <input type="number" name="muestras" min="0" placeholder="0" required>
                                </div>

                                <!-- Agredidos -->
                                <div class="capture-card agredidos">
                                    <div class="card-icon">
                                        <i class="fas fa-exclamation-triangle"></i>
                                    </div>
                                    <div class="card-title">Agredidos</div>
                                    <div class="card-subtitle">Personas agredidas por animales</div>
                                    <input type="number" name="agredidos" min="0" placeholder="0" required>
                                </div>

                                <!-- Tratamientos -->
                                <div class="capture-card tratamientos">
                                    <div class="card-icon">
                                        <i class="fas fa-pills"></i>
                                    </div>
                                    <div class="card-title">Tratamientos</div>
                                    <div class="card-subtitle">Tratamientos antirrábicos aplicados</div>
                                    <input type="number" name="tratamientos" min="0" placeholder="0" required>
                                </div>
                            </div>

                            <div class="text-center">
                                <button type="submit" name="guardar_captura" class="btn-guardar">
                                    <i class="fas fa-save"></i>Guardar Captura
                                </button>
                            </div>
                        </form>

                        <script>
                            // Manejar selección de hospital vs municipio y generar opciones de fecha
                            document.addEventListener('DOMContentLoaded', function() {
                                const selectMunicipio = document.querySelector('select[name="municipio"]');
                                const selectMes = document.getElementById('select-mes');
                                const selectAnio = document.getElementById('select-anio');
                                const infoMes = document.getElementById('info-mes');
                                const tiposHospitalOnly = ['agredidos', 'tratamientos'];
                                const todosTipos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];

                                const rolUsuario = '<?= $rol ?>';
                                const anioActivo = <?= $anio_activo ?>;

                                // Nombres de meses
                                const nombresMeses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                                    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
                                ];

                                // Obtener fecha del CLIENTE (computadora del usuario)
                                const fechaCliente = new Date();
                                const mesActualCliente = fechaCliente.getMonth() + 1; // 1-12
                                const anioActualCliente = fechaCliente.getFullYear();
                                const diaActualCliente = fechaCliente.getDate(); // 1-31

                                // REGLA: SOLO durante los primeros 10 días del mes se puede registrar el mes ANTERIOR
                                // Después del día 10, NO se puede registrar (periodo cerrado)
                                let mesDisponible, anioDisponible;

                                if (diaActualCliente <= 10) {
                                    // Primeros 10 días: capturar mes ANTERIOR
                                    mesDisponible = mesActualCliente - 1;
                                    anioDisponible = anioActualCliente;

                                    if (mesDisponible === 0) {
                                        mesDisponible = 12;
                                        anioDisponible = anioActualCliente - 1;
                                    }
                                } else {
                                    // Después del día 10: periodo cerrado (sin prórroga)
                                    mesDisponible = null;
                                    anioDisponible = null;
                                }

                                // Generar opciones de mes y año
                                if (rolUsuario === 'admin') {
                                    // Admin: todos los meses del año activo
                                    selectMes.innerHTML = '<option value="">Seleccionar mes...</option>';
                                    for (let m = 1; m <= 12; m++) {
                                        const option = document.createElement('option');
                                        option.value = m;
                                        option.textContent = nombresMeses[m - 1] + ' ' + anioActivo;
                                        option.setAttribute('data-anio', anioActivo);
                                        selectMes.appendChild(option);
                                    }

                                    // Años disponibles para admin
                                    selectAnio.innerHTML = '<option value="">Seleccionar año...</option>';
                                    const aniosAdmin = [anioActivo];
                                    if (anioActivo > 2020) {
                                        aniosAdmin.push(anioActivo - 1);
                                    }
                                    aniosAdmin.forEach(a => {
                                        const option = document.createElement('option');
                                        option.value = a;
                                        option.textContent = a;
                                        selectAnio.appendChild(option);
                                    });

                                    if (infoMes) {
                                        infoMes.innerHTML = '<i class="fas fa-user-shield me-1"></i>Modo Administrador - Todos los meses disponibles';
                                    }
                                } else {
                                    // Capturista: mes anterior + prórrogas (desde PHP)
                                    const mesesDisponibles = <?= json_encode($meses_disponibles) ?>;

                                    selectMes.innerHTML = '<option value="">Seleccionar mes...</option>';

                                    if (mesesDisponibles.length === 0) {
                                        selectMes.innerHTML = '<option value="" disabled>No hay periodos disponibles</option>';
                                        selectAnio.innerHTML = '<option value="" disabled>No hay años disponibles</option>';
                                        if (infoMes) {
                                            infoMes.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Periodo de registro cerrado. Solo puedes registrar durante los primeros 10 días del mes. Solicita una prórroga al administrador si necesitas registrar.';
                                            infoMes.className = 'alert alert-warning mb-3';
                                        }
                                    } else {
                                        // Agregar opciones de meses disponibles
                                        mesesDisponibles.forEach(md => {
                                            const option = document.createElement('option');
                                            option.value = md.mes;
                                            option.textContent = md.label;
                                            option.setAttribute('data-anio', md.anio);
                                            selectMes.appendChild(option);
                                        });

                                        // Extraer años únicos
                                        const aniosUnicos = [...new Set(mesesDisponibles.map(md => md.anio))];
                                        selectAnio.innerHTML = '<option value="">Seleccionar año...</option>';
                                        aniosUnicos.forEach(a => {
                                            const option = document.createElement('option');
                                            option.value = a;
                                            option.textContent = a;
                                            selectAnio.appendChild(option);
                                        });

                                        if (infoMes) {
                                            const fechaClienteStr = fechaCliente.toLocaleDateString('es-MX', {
                                                day: 'numeric',
                                                month: 'long',
                                                year: 'numeric'
                                            });
                                            if (diaActualCliente <= 10) {
                                                infoMes.innerHTML = `<i class="fas fa-calendar-check me-1"></i>Fecha de tu equipo: ${fechaClienteStr} (primeros 10 días) → Puedes registrar: <strong>${nombresMeses[mesDisponible - 1]} ${anioDisponible}</strong>`;
                                                infoMes.className = 'alert alert-info mb-3';
                                            } else {
                                                // Tiene prórroga activa
                                                infoMes.innerHTML = `<i class="fas fa-calendar-check me-1"></i>Tienes prórroga activa. Puedes registrar los meses mostrados.`;
                                                infoMes.className = 'alert alert-success mb-3';
                                            }
                                        }
                                    }
                                }

                                // Sincronizar mes y año
                                if (selectMes && selectAnio) {
                                    selectMes.addEventListener('change', function() {
                                        const selectedOption = this.options[this.selectedIndex];
                                        const anioAsociado = selectedOption.getAttribute('data-anio');

                                        if (anioAsociado) {
                                            selectAnio.value = anioAsociado;
                                        }
                                    });
                                }

                                // Manejar hospital vs municipio
                                if (selectMunicipio) {
                                    selectMunicipio.addEventListener('change', function() {
                                        const selectedOption = this.options[this.selectedIndex];
                                        const esHospital = selectedOption.getAttribute('data-hospital') === '1';

                                        todosTipos.forEach(tipo => {
                                            const input = document.querySelector(`input[name="${tipo}"]`);
                                            const card = input ? input.closest('.capture-card') : null;

                                            if (input && card) {
                                                if (esHospital && !tiposHospitalOnly.includes(tipo)) {
                                                    input.disabled = true;
                                                    input.required = false;
                                                    input.value = '';
                                                    input.placeholder = 'N/A';
                                                    card.style.opacity = '0.5';
                                                    card.style.pointerEvents = 'none';
                                                } else {
                                                    input.disabled = false;
                                                    input.required = true;
                                                    input.placeholder = '0';
                                                    card.style.opacity = '1';
                                                    card.style.pointerEvents = 'auto';
                                                }
                                            }
                                        });
                                    });
                                }
                            });
                        </script>
                    </div>
                </div>

                <!-- Vista de Historial (CON efecto libro) -->
                <div id="vista-historial" style="display: none;">
                    <div class="book-container">
                        <!-- Navegación de Meses -->
                        <div class="month-nav">
                            <button onclick="cambiarMes(-1)" id="btn-prev">
                                <i class="fas fa-chevron-left me-2"></i>Anterior
                            </button>
                            <div class="month-title" id="month-title">
                                Enero <?= $anio_activo ?>
                            </div>
                            <button onclick="cambiarMes(1)" id="btn-next">
                                Siguiente<i class="fas fa-chevron-right ms-2"></i>
                            </button>
                        </div>

                        <!-- Página del Libro -->
                        <div class="book-page" id="book-page">
                            <!-- Contenedor de tabla con header -->
                            <div class="table-container">
                                <div class="table-header">
                                    <h3>
                                        <i class="fas fa-table"></i>
                                        Historial de Capturas
                                    </h3>
                                    <span class="badge">
                                        <i class="fas fa-calendar-alt me-1"></i>
                                        <span id="badge-mes-anio">Enero <?= $anio_activo ?></span>
                                    </span>
                                </div>
                                <div class="table-body" id="contenido-historial">
                                    <!-- Se llenará dinámicamente -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info" style="border-radius: 12px;">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Selecciona una jurisdicción</strong> para comenzar a capturar datos.
                </div>
            <?php endif; ?>
        </main>
    </div>

    <!-- Modal para Editar Registro -->
    <div class="modal fade" id="modalEditarRegistro" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Editar Captura</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formEditarRegistro">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="municipio" id="edit_municipio">
                    <input type="hidden" name="jurisdiccion" value="<?= htmlspecialchars($jurisdiccion_trabajo) ?>">
                    <input type="hidden" name="anio" value="<?= $anio_activo ?>">
                    <div class="modal-body" style="background: #f8fafc; padding: 24px;">
                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-map-marker-alt me-2 text-primary"></i>Municipio/Hospital</label>
                            <input type="text" class="form-control" id="edit_municipio_display" readonly style="background: #e2e8f0;">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold"><i class="fas fa-calendar me-2 text-success"></i>Seleccionar Mes a Editar</label>
                            <select name="mes" id="edit_mes" class="form-select" required>
                                <option value="">-- Selecciona un mes --</option>
                                <?php if ($rol === 'admin'): ?>
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?= mesClaveDesdeNumero($m) ?>"><?= ucfirst(mesClaveDesdeNumero($m)) ?> <?= $anio_activo ?></option>
                                    <?php endfor; ?>
                                <?php else: ?>
                                    <?php
                                    // Para capturistas: mostrar solo meses que PUEDEN editar
                                    // REGLA: 5 días HÁBILES desde que capturó el mes (fecha_captura del registro)
                                    // O meses con prórroga activa

                                    $meses_editables = [];

                                    // 1. Obtener meses con prórroga activa
                                    $sql_prorrogas = "SELECT mes FROM extensiones 
                                                     WHERE usuario_id = ? AND anio = ? AND fecha_limite > NOW()";
                                    $stmt_prorrogas = mysqli_prepare($conn, $sql_prorrogas);
                                    mysqli_stmt_bind_param($stmt_prorrogas, 'ii', $usuario_id, $anio_activo);
                                    mysqli_stmt_execute($stmt_prorrogas);
                                    $res_prorrogas = mysqli_stmt_get_result($stmt_prorrogas);

                                    while ($row_prorroga = mysqli_fetch_assoc($res_prorrogas)) {
                                        $meses_editables[$row_prorroga['mes']] = '(Prórroga)';
                                    }

                                    // 2. Buscar en bitácora cuándo se capturó cada mes
                                    // Formato detalles: "jurisdiccion|municipio|tipo|año"
                                    $sql_bitacora = "SELECT b.fecha, b.detalles 
                                                    FROM bitacora b
                                                    WHERE b.usuario_id = ? 
                                                    AND b.accion = 'captura'
                                                    AND b.detalles LIKE CONCAT(?, '|%|%|', ?)
                                                    ORDER BY b.fecha ASC";

                                    $stmt_bitacora = mysqli_prepare($conn, $sql_bitacora);
                                    mysqli_stmt_bind_param($stmt_bitacora, 'isi', $usuario_id, $jurisdiccion_trabajo, $anio_activo);
                                    mysqli_stmt_execute($stmt_bitacora);
                                    $res_bitacora = mysqli_stmt_get_result($stmt_bitacora);

                                    // Obtener registros actuales para saber qué meses tienen datos
                                    $sql_registros = "SELECT id, ene, feb, mar, abr, may, jun, jul, ago, sep, oct, nov, dic, fecha_captura
                                                     FROM registros 
                                                     WHERE anio = ? AND jurisdiccion = ? AND usuario_id = ?";
                                    $stmt_registros = mysqli_prepare($conn, $sql_registros);
                                    mysqli_stmt_bind_param($stmt_registros, 'isi', $anio_activo, $jurisdiccion_trabajo, $usuario_id);
                                    mysqli_stmt_execute($stmt_registros);
                                    $res_registros = mysqli_stmt_get_result($stmt_registros);

                                    // Mapear qué meses tienen datos capturados
                                    $meses_con_datos = [];
                                    $fecha_captura_general = null;

                                    while ($row_reg = mysqli_fetch_assoc($res_registros)) {
                                        if (!$fecha_captura_general) {
                                            $fecha_captura_general = $row_reg['fecha_captura'];
                                        }

                                        $meses_nombres = [
                                            'ene' => 1,
                                            'feb' => 2,
                                            'mar' => 3,
                                            'abr' => 4,
                                            'may' => 5,
                                            'jun' => 6,
                                            'jul' => 7,
                                            'ago' => 8,
                                            'sep' => 9,
                                            'oct' => 10,
                                            'nov' => 11,
                                            'dic' => 12
                                        ];

                                        foreach ($meses_nombres as $mes_col => $mes_num) {
                                            if (!empty($row_reg[$mes_col]) && $row_reg[$mes_col] > 0) {
                                                if (!isset($meses_con_datos[$mes_num])) {
                                                    $meses_con_datos[$mes_num] = $row_reg['fecha_captura'];
                                                }
                                            }
                                        }
                                    }

                                    // Calcular 5 días hábiles desde fecha_captura para cada mes con datos
                                    $fecha_actual = new DateTime();

                                    foreach ($meses_con_datos as $mes_num => $fecha_captura_mes) {
                                        if (isset($meses_editables[$mes_num])) {
                                            continue; // Ya tiene prórroga
                                        }

                                        $fecha_captura = new DateTime($fecha_captura_mes);
                                        $dias_habiles = 0;
                                        $temp_fecha = clone $fecha_captura;

                                        // Contar días hábiles desde la captura hasta hoy
                                        while ($temp_fecha < $fecha_actual && $dias_habiles <= 5) {
                                            $temp_fecha->modify('+1 day');
                                            $dia_semana = (int)$temp_fecha->format('N'); // 1=Lun, 7=Dom
                                            if ($dia_semana >= 1 && $dia_semana <= 5) { // Lunes a Viernes
                                                $dias_habiles++;
                                            }
                                        }

                                        // Si han pasado 5 días hábiles o menos, puede editar
                                        if ($dias_habiles <= 5) {
                                            $meses_editables[$mes_num] = '';
                                        }
                                    }

                                    ksort($meses_editables);

                                    if (empty($meses_editables)):
                                    ?>
                                        <option value="" disabled>No hay meses disponibles para editar</option>
                                    <?php else: ?>
                                        <?php foreach ($meses_editables as $m => $etiqueta): ?>
                                            <option value="<?= mesClaveDesdeNumero($m) ?>"><?= ucfirst(mesClaveDesdeNumero($m)) ?> <?= $anio_activo ?> <?= $etiqueta ?></option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div id="edit_campos_container" style="display: none;">
                            <hr>
                            <h6 class="fw-bold mb-3"><i class="fas fa-list me-2"></i>Valores del Mes</h6>
                            <div class="row g-3" id="edit_campos_tipos">
                                <!-- Los campos se llenan dinámicamente -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer" style="background: #f8fafc;">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-warning" id="btn_guardar_edicion" disabled>
                            <i class="fas fa-save me-2"></i>Guardar Cambios
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Datos de registros por mes
        const registrosPorMes = <?= json_encode($registros_por_mes) ?>;
        const municipios = <?= json_encode($municipios) ?>;
        const hospitales = <?= json_encode($hospitales) ?>;
        const jurisdiccionTrabajo = '<?= htmlspecialchars($jurisdiccion_trabajo ?? '') ?>';
        const anioActivo = <?= $anio_activo ?>;
        const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
        const rolUsuario = '<?= $rol ?>';
        const usuarioId = <?= $usuario_id ?>;
        const mesesDisponibles = <?= json_encode($meses_disponibles) ?>;

        // Información adicional para lógica de permisos
        const mesActualReal = <?= date('n') ?>; // Mes actual del sistema (1-12)
        const anioActualReal = <?= date('Y') ?>; // Año actual del sistema

        let mesActual = mesActualReal; // Mes que se está visualizando en historial
        let vistaActual = 'captura';
        let municipioActual = '';
        let esHospitalActual = false;

        const nombresMeses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
        ];

        const tiposPermitidos = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
        const tiposHospitalOnly = ['agredidos', 'tratamientos'];

        // Función auxiliar para verificar si un mes está disponible para edición
        function mesDisponibleParaEdicion(mes, anio) {
            if (rolUsuario === 'admin') return true;

            // Verificar si está en la lista de meses disponibles (incluye prórroga y periodo normal)
            return mesesDisponibles.some(md => md.mes === mes && md.anio === anio);
        }

        // Función para calcular días hábiles entre dos fechas
        function calcularDiasHabiles(fechaInicio, fechaFin) {
            let dias = 0;
            let fecha = new Date(fechaInicio);
            const fin = new Date(fechaFin);

            while (fecha <= fin) {
                const diaSemana = fecha.getDay();
                // 0 = Domingo, 6 = Sábado
                if (diaSemana !== 0 && diaSemana !== 6) {
                    dias++;
                }
                fecha.setDate(fecha.getDate() + 1);
            }

            return dias;
        }

        // Función para verificar si puede actualizar basado en fecha de captura
        function puedeActualizarPorFecha(fechaCaptura) {
            if (!fechaCaptura) return false;

            const hoy = new Date();
            const captura = new Date(fechaCaptura);

            // Calcular días hábiles desde la captura
            const diasHabiles = calcularDiasHabiles(captura, hoy);

            // Puede actualizar si han pasado 5 días hábiles o menos
            return diasHabiles <= 5;
        }

        function cambiarMes(direccion) {
            const page = document.getElementById('book-page');
            page.classList.add('flipping');

            setTimeout(() => {
                mesActual += direccion;
                if (mesActual < 1) mesActual = 1;
                if (mesActual > 12) mesActual = 12;

                // Actualizar título
                document.getElementById('month-title').textContent = nombresMeses[mesActual - 1] + ' ' + anioActivo;

                // Actualizar botones
                document.getElementById('btn-prev').disabled = (mesActual === 1);
                document.getElementById('btn-next').disabled = (mesActual === 12);

                // Mostrar vista actualizada
                mostrarVistaHistorial();
                page.classList.remove('flipping');
            }, 300);
        }

        function cambiarVista(vista) {
            vistaActual = vista;

            // Actualizar botones
            document.getElementById('btn-captura').classList.toggle('active', vista === 'captura');
            document.getElementById('btn-historial').classList.toggle('active', vista === 'historial');

            // Mostrar/ocultar vistas
            document.getElementById('vista-captura').style.display = vista === 'captura' ? 'block' : 'none';
            document.getElementById('vista-historial').style.display = vista === 'historial' ? 'block' : 'none';

            if (vista === 'captura') {
                cargarRegistrosCaptura();
            } else {
                // Inicializar mes actual y botones
                mesActual = <?= date('n') ?>;
                document.getElementById('month-title').textContent = nombresMeses[mesActual - 1] + ' ' + anioActivo;
                document.getElementById('btn-prev').disabled = (mesActual === 1);
                document.getElementById('btn-next').disabled = (mesActual === 12);
                mostrarVistaHistorial();
            }
        }

        function cargarRegistrosCaptura() {
            const tbody = document.getElementById('tabla-registros-captura');
            if (!tbody) {
                console.warn('tabla-registros-captura no encontrado');
                return;
            }
            const registros = registrosPorMes[mesActual] || {};

            let html = '';

            // Agregar municipios
            municipios.forEach(muni => {
                const reg = registros[muni] || {};
                html += generarFilaRegistro(muni, reg, false);
            });

            // Agregar hospitales
            hospitales.forEach(hosp => {
                const reg = registros[hosp] || {};
                html += generarFilaRegistro(hosp, reg, true);
            });

            if (html === '') {
                html = '<tr><td colspan="8" class="text-center text-muted">No hay registros capturados</td></tr>';
            }

            tbody.innerHTML = html;
        }

        function generarFilaRegistro(nombre, registros, esHospital) {
            let html = '<tr>';
            html += `<td><span class="badge ${esHospital ? 'bg-warning text-dark' : 'bg-primary'}">${nombre}</span></td>`;

            tiposPermitidos.forEach(tipo => {
                const esNA = esHospital && !tiposHospitalOnly.includes(tipo);
                const valor = registros.tipos?.[tipo]?.valor || 0;

                if (esNA) {
                    html += '<td class="text-center"><span class="text-muted">N/A</span></td>';
                } else {
                    html += `<td class="text-center"><span class="badge bg-light text-dark">${valor}</span></td>`;
                }
            });

            // Verificar permisos para editar/borrar
            let puedeEditar = true;
            let motivoBloqueo = '';

            if (rolUsuario === 'capturista') {
                // Verificar si tiene prórroga activa para este mes específico
                const tieneProrroga = mesesDisponibles.some(md => md.mes === mesActual && md.anio === anioActivo);

                if (!tieneProrroga) {
                    // Sin prórroga: verificar si está dentro del periodo de captura/actualización
                    // Por ahora bloqueamos si no tiene prórroga (la lógica de 5 días se maneja en el servidor)
                    puedeEditar = false;
                    motivoBloqueo = 'Periodo de edición expirado. Solicita una prórroga si necesitas editar.';
                }
            }

            html += `<td class="text-center">`;

            if (puedeEditar) {
                html += `<div class="btn-group" role="group">
                            <button type="button" class="btn btn-sm btn-warning" onclick="abrirEditarRegistro('${nombre.replace(/'/g, "\\'")}', ${esHospital})" 
                                data-bs-toggle="tooltip" title="Editar registro">
                                <i class="fas fa-edit"></i>
                            </button>`;

                // Solo administradores pueden borrar registros
                <?php if ($es_admin): ?>
                    html += `<button type="button" class="btn btn-sm btn-danger" onclick="confirmarBorrarRegistro('${nombre.replace(/'/g, "\\'")}', ${esHospital})" 
                                data-bs-toggle="tooltip" title="Borrar registro">
                                <i class="fas fa-trash"></i>
                            </button>`;
                <?php endif; ?>

                html += `</div>`;
            } else {
                html += `<span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="${motivoBloqueo}">
                            <button type="button" class="btn btn-sm btn-secondary" disabled style="pointer-events: none;">
                                <i class="fas fa-lock"></i>
                            </button>
                        </span>`;
            }

            html += `</td></tr>`;

            return html;
        }

        function mostrarVistaHistorial() {
            const contenedor = document.getElementById('contenido-historial');
            const registros = registrosPorMes[mesActual] || {};

            // Actualizar badge del mes
            document.getElementById('badge-mes-anio').textContent = nombresMeses[mesActual - 1] + ' ' + anioActivo;

            let html = '<table class="capture-table"><thead><tr>';
            html += '<th><i class="fas fa-map-marker-alt me-2"></i>Municipio/Hospital</th>';
            html += '<th><i class="fas fa-syringe me-1"></i>Vacunados</th>';
            html += '<th><i class="fas fa-cut me-1"></i>Esterilizados</th>';
            html += '<th><i class="fas fa-clipboard-check me-1"></i>Control</th>';
            html += '<th><i class="fas fa-vial me-1"></i>Muestras</th>';
            html += '<th><i class="fas fa-exclamation-triangle me-1"></i>Agredidos</th>';
            html += '<th><i class="fas fa-pills me-1"></i>Tratamientos</th>';
            html += '<th><i class="fas fa-cog me-1"></i>Acciones</th></tr></thead><tbody>';

            // Combinar municipios y hospitales
            const todos = [...municipios, ...hospitales];

            if (todos.length === 0) {
                html += '<tr><td colspan="8" class="text-center text-muted" style="padding: 40px;">No hay datos para este mes</td></tr>';
            } else {
                todos.forEach(nombre => {
                    const reg = registros[nombre] || {};
                    html += generarFilaHistorial(nombre, reg, hospitales.includes(nombre));
                });
            }

            html += '</tbody></table>';
            contenedor.innerHTML = html;

            // Reinicializar tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
        }

        function generarFilaHistorial(nombre, registros, esHospital) {
            let html = '<tr>';
            html += `<td><span class="badge ${esHospital ? 'bg-warning text-dark' : 'bg-primary'}">${nombre}</span></td>`;

            let total = 0;
            let hayDatos = false;
            let fechaCapturaRegistro = null;

            tiposPermitidos.forEach(tipo => {
                const esNA = esHospital && !tiposHospitalOnly.includes(tipo);
                const valor = registros.tipos?.[tipo]?.valor || 0;

                if (esNA) {
                    html += '<td><span class="text-muted" style="font-style: italic; opacity: 0.5;">N/A</span></td>';
                } else {
                    const valorFormateado = parseInt(valor).toLocaleString('es-MX');
                    html += `<td><span class="badge bg-light">${valorFormateado}</span></td>`;
                    total += parseInt(valor);
                    if (valor > 0) {
                        hayDatos = true;
                        // Obtener la fecha de captura del primer tipo con datos
                        if (!fechaCapturaRegistro && registros.tipos?.[tipo]?.fecha_captura) {
                            fechaCapturaRegistro = registros.tipos[tipo].fecha_captura;
                        }
                    }
                }
            });

            // NO mostrar columna de Total - eliminada

            // Agregar columna de acciones con lógica de permisos
            let puedeEditar = false;
            let motivoBloqueo = '';

            if (rolUsuario === 'admin') {
                // Admin siempre puede editar
                puedeEditar = true;
            } else if (rolUsuario === 'capturista' && hayDatos) {
                // Verificar si tiene prórroga activa para este mes específico
                const tieneProrroga = mesesDisponibles.some(md =>
                    md.mes === mesActual &&
                    md.anio === anioActivo &&
                    md.label.includes('Prórroga')
                );

                if (tieneProrroga) {
                    // Con prórroga activa puede editar
                    puedeEditar = true;
                } else {
                    // Sin prórroga: verificar si está dentro de los 5 días hábiles desde la captura
                    if (fechaCapturaRegistro && puedeActualizarPorFecha(fechaCapturaRegistro)) {
                        puedeEditar = true;
                    } else {
                        // Determinar el motivo específico del bloqueo
                        if (fechaCapturaRegistro) {
                            const captura = new Date(fechaCapturaRegistro);
                            const diasTranscurridos = calcularDiasHabiles(captura, new Date());
                            motivoBloqueo = `Periodo de actualización expirado (${diasTranscurridos} días hábiles desde la captura). Solo puedes actualizar dentro de los 5 días hábiles. Solicita una prórroga.`;
                        } else {
                            motivoBloqueo = 'No se puede determinar la fecha de captura. Solicita una prórroga para editar.';
                        }
                    }
                }
            }

            // Si no hay datos capturados, no mostrar botones de acción
            if (!hayDatos) {
                html += `<td><span class="text-muted" style="font-style: italic; font-size: 0.85rem;">Sin datos</span></td>`;
            } else {
                html += `<td>`;

                if (puedeEditar) {
                    html += `<div class="btn-group" role="group">
                                <button type="button" class="btn btn-sm btn-warning" onclick="abrirEditarRegistro('${nombre.replace(/'/g, "\\'")}', ${esHospital})" 
                                    data-bs-toggle="tooltip" title="Editar registro">
                                    <i class="fas fa-edit"></i>
                                </button>`;

                    // Solo administradores pueden borrar registros
                    <?php if ($es_admin): ?>
                        html += `<button type="button" class="btn btn-sm btn-danger" onclick="confirmarBorrarRegistro('${nombre.replace(/'/g, "\\'")}', ${esHospital})" 
                                    data-bs-toggle="tooltip" title="Borrar registro">
                                    <i class="fas fa-trash"></i>
                                </button>`;
                    <?php endif; ?>

                    html += `</div>`;
                } else {
                    html += `<span class="d-inline-block" tabindex="0" data-bs-toggle="tooltip" title="${motivoBloqueo}">
                                <button type="button" class="btn btn-sm btn-secondary" disabled style="pointer-events: none;">
                                    <i class="fas fa-lock"></i>
                                </button>
                            </span>`;
                }

                html += `</td>`;
            }

            html += '</tr>';

            return html;
        }

        // Funciones del modal
        function abrirEditarRegistro(municipio, esHospital) {
            municipioActual = municipio;
            esHospitalActual = esHospital;

            document.getElementById('edit_municipio').value = municipio;
            document.getElementById('edit_municipio_display').value = municipio;
            document.getElementById('edit_mes').value = '';
            document.getElementById('edit_campos_container').style.display = 'none';
            document.getElementById('btn_guardar_edicion').disabled = true;
            document.getElementById('edit_campos_tipos').innerHTML = '';

            const modal = new bootstrap.Modal(document.getElementById('modalEditarRegistro'));
            modal.show();
        }

        function confirmarBorrarRegistro(municipio, esHospital) {
            const tipo = esHospital ? 'hospital' : 'municipio';
            if (confirm(`¿Estás seguro de borrar TODOS los registros del ${tipo} "${municipio}"?\n\nEsta acción NO se puede deshacer.`)) {
                borrarRegistro(municipio);
            }
        }

        function borrarRegistro(municipio) {
            // Borrar registro via AJAX
            console.log('Borrando:', municipio, 'Mes:', mesActual);

            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('municipio', municipio);
            formData.append('jurisdiccion', jurisdiccionTrabajo);
            formData.append('anio', anioActivo);
            formData.append('mes', mesActual);

            fetch('borrar_registro_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        alert(data.msg || 'Registro borrado correctamente');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.msg || 'No se pudo borrar el registro'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error de conexión al intentar borrar');
                });
        }

        // Al cambiar el mes en el modal, cargar los valores
        document.getElementById('edit_mes').addEventListener('change', function() {
            const mes = this.value;
            if (!mes || !municipioActual) {
                document.getElementById('edit_campos_container').style.display = 'none';
                document.getElementById('btn_guardar_edicion').disabled = true;
                return;
            }

            document.getElementById('edit_campos_tipos').innerHTML = '<div class="col-12 text-center"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
            document.getElementById('edit_campos_container').style.display = 'block';

            // Cargar datos del mes via AJAX
            fetch('cargar_datos_ajax.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `municipio=${encodeURIComponent(municipioActual)}&mes=${mesClaveANumero(mes)}&anio=${anioActivo}&jurisdiccion=${encodeURIComponent(jurisdiccionTrabajo)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        let html = '';
                        const valores = data.municipio || {};

                        tiposPermitidos.forEach(tipo => {
                            const esNA = esHospitalActual && !tiposHospitalOnly.includes(tipo);
                            const valor = valores[tipo] || 0;
                            const tipoCapitalized = tipo.charAt(0).toUpperCase() + tipo.slice(1);

                            if (esNA) {
                                html += `<div class="col-md-4">
                                        <label class="form-label">${tipoCapitalized}</label>
                                        <input type="text" class="form-control" value="N/A" disabled style="background: #f1f5f9;">
                                    </div>`;
                            } else {
                                html += `<div class="col-md-4">
                                        <label class="form-label">${tipoCapitalized}</label>
                                        <input type="number" name="valor_${tipo}" class="form-control" value="${valor}" min="0">
                                    </div>`;
                            }
                        });

                        document.getElementById('edit_campos_tipos').innerHTML = html;
                        document.getElementById('btn_guardar_edicion').disabled = false;
                    } else {
                        document.getElementById('edit_campos_tipos').innerHTML = '<div class="col-12 text-danger">Error al cargar datos</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('edit_campos_tipos').innerHTML = '<div class="col-12 text-danger">Error de conexión</div>';
                });
        });

        function mesClaveANumero(clave) {
            const map = {
                'ene': 1,
                'feb': 2,
                'mar': 3,
                'abr': 4,
                'may': 5,
                'jun': 6,
                'jul': 7,
                'ago': 8,
                'sep': 9,
                'oct': 10,
                'nov': 11,
                'dic': 12
            };
            return map[clave] || 0;
        }

        // Guardar edición
        document.getElementById('formEditarRegistro').addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            const btn = document.getElementById('btn_guardar_edicion');
            const textoOriginal = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Guardando...';
            btn.disabled = true;

            fetch('actualizar_registro_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        bootstrap.Modal.getInstance(document.getElementById('modalEditarRegistro')).hide();
                        alert('Registro actualizado correctamente');
                        location.reload();
                    } else {
                        alert('Error: ' + (data.msg || 'Error al actualizar'));
                        btn.innerHTML = textoOriginal;
                        btn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error de conexión');
                    btn.innerHTML = textoOriginal;
                    btn.disabled = false;
                });
        });

        // Inicializar
        document.addEventListener('DOMContentLoaded', function() {
            if (jurisdiccionTrabajo) {
                cargarRegistrosCaptura();

                // Inicializar tooltips
                const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
                [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));
            }
        });
    </script>
</body>

</html>