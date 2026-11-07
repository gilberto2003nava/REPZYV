<?php
session_start();
require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

require_once 'partials/check_session.php';

require_once __DIR__ . '/includes/check_mantenimiento.php';

$usuarioId = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$jurisdiccionUser = $_SESSION['jurisdiccion'] ?? '';

// --- Navigation Logic ---
$view = $_GET['view'] ?? 'root'; // root, jurisdiction, folder
$currentId = $_GET['id'] ?? '';
$filterType = $_GET['tipo'] ?? ''; // Filter from sidebar

// Security Check: Redirect Capturistas to their own jurisdiction if they try to access others or root
if ($rol !== 'admin') {
    if ($view === 'root' || ($view === 'jurisdiction' && $currentId !== $jurisdiccionUser)) {
        // Force redirect to their own jurisdiction
        $view = 'jurisdiction';
        $currentId = $jurisdiccionUser;
    }
    // If viewing a folder, we must verify it belongs to their jurisdiction
    if ($view === 'folder') {
        $stmtCheck = mysqli_prepare($conn, "SELECT jurisdiccion FROM carpetas WHERE id = ?");
        mysqli_stmt_bind_param($stmtCheck, "i", $currentId);
        mysqli_stmt_execute($stmtCheck);
        $resCheck = mysqli_stmt_get_result($stmtCheck);
        $folderData = mysqli_fetch_assoc($resCheck);
        if (!$folderData || $folderData['jurisdiccion'] !== $jurisdiccionUser) {
            header('Location: historial_mes.php'); // Reset to safe state
            exit;
        }
    }
}

// --- Breadcrumb & Context Data ---
$breadcrumbs = [];
$currentJurisdiction = '';
$currentFolderId = null;

if ($view === 'root') {
    $breadcrumbs[] = ['label' => 'Inicio', 'url' => '?view=root', 'active' => empty($filterType)];
    if ($filterType) {
        $breadcrumbs[] = ['label' => 'Filtro: ' . ucfirst($filterType), 'url' => '#', 'active' => true];
    }
} elseif ($view === 'jurisdiction') {
    $currentJurisdiction = $currentId;
    if ($rol === 'admin') {
        $breadcrumbs[] = ['label' => 'Inicio', 'url' => '?view=root', 'active' => false];
    }
    $breadcrumbs[] = ['label' => $currentJurisdiction, 'url' => '?view=jurisdiction&id=' . urlencode($currentJurisdiction), 'active' => empty($filterType)];
    if ($filterType) {
        $breadcrumbs[] = ['label' => 'Filtro: ' . ucfirst($filterType), 'url' => '#', 'active' => true];
    }
} elseif ($view === 'folder') {
    $folderId = (int)$currentId;
    $currentFolderId = $folderId;

    // Get Folder Info
    $stmtF = mysqli_prepare($conn, "SELECT nombre, jurisdiccion FROM carpetas WHERE id = ?");
    mysqli_stmt_bind_param($stmtF, "i", $folderId);
    mysqli_stmt_execute($stmtF);
    $resF = mysqli_stmt_get_result($stmtF);
    $folderInfo = mysqli_fetch_assoc($resF);

    if ($folderInfo) {
        $currentJurisdiction = $folderInfo['jurisdiccion'];
        if ($rol === 'admin') {
            $breadcrumbs[] = ['label' => 'Inicio', 'url' => '?view=root', 'active' => false];
        }
        $breadcrumbs[] = ['label' => $folderInfo['jurisdiccion'], 'url' => '?view=jurisdiction&id=' . urlencode($folderInfo['jurisdiccion']), 'active' => false];

        $folderUrl = '?view=folder&id=' . $folderId;
        $breadcrumbs[] = ['label' => $folderInfo['nombre'], 'url' => $folderUrl . ($filterType ? '&tipo=' . $filterType : ''), 'active' => empty($filterType)];

        if ($filterType) {
            $breadcrumbs[] = ['label' => 'Filtro: ' . ucfirst($filterType), 'url' => '#', 'active' => true];
        }
    } else {
        // Folder not found
        header('Location: historial_mes.php');
        exit;
    }
}

// --- Handle Form Actions ---
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_folder') {
        $nombre = trim($_POST['nombre_carpeta']);
        $juris = $_POST['jurisdiccion_target'];

        // Permission Check
        if ($rol === 'admin' || ($rol === 'capturista' && $juris === $jurisdiccionUser)) {
            if (!empty($nombre)) {
                $stmt = mysqli_prepare($conn, "INSERT INTO carpetas (nombre, jurisdiccion, usuario_id) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "ssi", $nombre, $juris, $usuarioId);
                if (mysqli_stmt_execute($stmt)) {
                    $msg = 'Carpeta creada correctamente.';
                } else {
                    $err = 'Error al crear carpeta.';
                }
            } else {
                $err = 'El nombre de la carpeta es requerido.';
            }
        } else {
            $err = 'No tienes permisos para crear carpetas aquí.';
        }
    } elseif ($action === 'upload') {
        $tipo = $_POST['tipo'] ?? 'expediente'; // expediente, oficio, cronograma
        $jurisUpload = $_POST['jurisdiccion_upload'];
        $folderUpload = !empty($_POST['carpeta_upload']) ? $_POST['carpeta_upload'] : null;

        // Validar permisos
        $permiso = false;
        if ($rol === 'admin') $permiso = true;
        if ($rol === 'capturista' && $jurisUpload === $jurisdiccionUser) {
            // Si sube a una carpeta, verificar que sea de su juris
            if ($folderUpload) {
                $stmtC = mysqli_prepare($conn, "SELECT jurisdiccion FROM carpetas WHERE id = ?");
                mysqli_stmt_bind_param($stmtC, "i", $folderUpload);
                mysqli_stmt_execute($stmtC);
                $resC = mysqli_stmt_get_result($stmtC);
                $f = mysqli_fetch_assoc($resC);
                if ($f && $f['jurisdiccion'] === $jurisdiccionUser) $permiso = true;
            } else {
                $permiso = true;
            }
        }

        if ($permiso) {
            if (!empty($_FILES['archivo']['name'])) {
                $file = $_FILES['archivo'];
                if ($file['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if ($ext === 'pdf') {
                        $storageDir = __DIR__ . '/storage/pdfs';
                        if (!is_dir($storageDir)) mkdir($storageDir, 0775, true);

                        $safeName = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                        $newName = $safeName . '_' . date('Ymd_His') . '.pdf';
                        $target = $storageDir . '/' . $newName;

                        if (move_uploaded_file($file['tmp_name'], $target)) {
                            // Insert DB
                            // Note: tipo is determined by user selection. 
                            // If inside a folder, typically it's part of an expediente, but we respect user choice or force it.
                            // Let's respect user choice but maybe default to 'expediente' inside folders.

                            $query = "INSERT INTO archivos (usuario_id, nombre_archivo, nombre_original, tipo, jurisdiccion, carpeta_id) VALUES (?, ?, ?, ?, ?, ?)";
                            $stmt = mysqli_prepare($conn, $query);
                            mysqli_stmt_bind_param($stmt, "issssi", $usuarioId, $newName, $file['name'], $tipo, $jurisUpload, $folderUpload);

                            if (mysqli_stmt_execute($stmt)) {
                                $msg = 'Archivo subido correctamente.';
                            } else {
                                $err = 'Error BD: ' . mysqli_error($conn);
                            }
                        } else {
                            $err = 'Error al mover archivo al servidor.';
                        }
                    } else {
                        $err = 'Solo se permiten archivos PDF.';
                    }
                } else {
                    $err = 'Error de subida: ' . $file['error'];
                }
            }
        } else {
            $err = 'No tienes permiso para subir archivos aquí.';
        }
    } elseif ($action === 'delete_file') {
        $idArchivo = (int)$_POST['id'];
        // Check ownership/permissions
        $stmt = mysqli_prepare($conn, "SELECT * FROM archivos WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $idArchivo);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $archivo = mysqli_fetch_assoc($res);

        if ($archivo) {
            if ($rol === 'admin' || ($rol === 'capturista' && $archivo['jurisdiccion'] === $jurisdiccionUser)) {
                $path = __DIR__ . '/storage/pdfs/' . $archivo['nombre_archivo'];
                if (file_exists($path)) @unlink($path);

                $del = mysqli_prepare($conn, "DELETE FROM archivos WHERE id = ?");
                mysqli_stmt_bind_param($del, "i", $idArchivo);
                mysqli_stmt_execute($del);
                $msg = 'Archivo eliminado.';
            } else {
                $err = 'No tienes permiso para eliminar este archivo.';
            }
        }
    } elseif ($action === 'delete_folder') {
        // Implement folder deletion (and potentially recursive file deletion or orphan handling)
        // For safety, let's only allow deleting empty folders for now, or delete all contents.
        // Let's delete contents for user convenience but warn them.
        $idCarpeta = (int)$_POST['id'];

        // Check ownership
        $stmt = mysqli_prepare($conn, "SELECT jurisdiccion FROM carpetas WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $idCarpeta);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $carpeta = mysqli_fetch_assoc($res);

        if ($carpeta && ($rol === 'admin' || ($rol === 'capturista' && $carpeta['jurisdiccion'] === $jurisdiccionUser))) {
            // Delete files physically ?? Or just DB? Better delete physically.
            $stmtFiles = mysqli_prepare($conn, "SELECT nombre_archivo FROM archivos WHERE carpeta_id = ?");
            mysqli_stmt_bind_param($stmtFiles, "i", $idCarpeta);
            mysqli_stmt_execute($stmtFiles);
            $resFiles = mysqli_stmt_get_result($stmtFiles);
            while ($f = mysqli_fetch_assoc($resFiles)) {
                $path = __DIR__ . '/storage/pdfs/' . $f['nombre_archivo'];
                if (file_exists($path)) @unlink($path);
            }

            // Delete Info from DB
            $delFiles = mysqli_prepare($conn, "DELETE FROM archivos WHERE carpeta_id = ?");
            mysqli_stmt_bind_param($delFiles, "i", $idCarpeta);
            mysqli_stmt_execute($delFiles);

            $delFolder = mysqli_prepare($conn, "DELETE FROM carpetas WHERE id = ?");
            mysqli_stmt_bind_param($delFolder, "i", $idCarpeta);
            mysqli_stmt_execute($delFolder);

            $msg = 'Carpeta y contenido eliminados.';
        } else {
            $err = 'No tienes permiso.';
        }
    }
}

// --- Fetch Data for Views ---
$items = [];
$folders = [];
$filterType = $_GET['tipo'] ?? '';

if ($view === 'root') {
    // 1. List Jurisdictions (Folders) - Always show these unless maybe we want to hide them when filtering?
    // Let's keep showing them for navigation, and show filtered files BELOW.
    $allJuris = jurisdicciones();
    foreach ($allJuris as $j) {
        $folders[] = [
            'type' => 'jurisdiction',
            'name' => $j,
            'url' => '?view=jurisdiction&id=' . urlencode($j) . ($filterType ? '&tipo=' . $filterType : ''),
            'icon' => 'fa-folder'
        ];
    }

    // 2. If Filter is active, Show All Matching Files (Global Search for Admin)
    if ($filterType) {
        $sql = "SELECT * FROM archivos WHERE tipo = ?";
        $params = "s";
        $args = [$filterType];

        if ($rol !== 'admin') {
            $sql .= " AND jurisdiccion = ?";
            $params .= "s";
            $args[] = $jurisdiccionUser;
        }

        $sql .= " ORDER BY fecha_subida DESC";

        $stmtFiles = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmtFiles, $params, ...$args);
        mysqli_stmt_execute($stmtFiles);
        $resFiles = mysqli_stmt_get_result($stmtFiles);
        while ($row = mysqli_fetch_assoc($resFiles)) {
            $items[] = $row;
        }
    }
} elseif ($view === 'jurisdiction') {
    // 1. Get Sub-folders (Carpetas created by users)
    // Only show folders if NO filter is active? Or show them anyway?
    // If I filter by "Expediente", I might want to see folders that contain expedientes? Too complex.
    // Let's just show folders as usual.
    $stmt = mysqli_prepare($conn, "SELECT * FROM carpetas WHERE jurisdiccion = ? ORDER BY created_at DESC");
    mysqli_stmt_bind_param($stmt, "s", $currentJurisdiction);
    mysqli_stmt_execute($stmt);
    $resFolders = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($resFolders)) {
        $folders[] = [
            'type' => 'folder',
            'id' => $row['id'],
            'name' => $row['nombre'],
            'url' => '?view=folder&id=' . $row['id'] . ($filterType ? '&tipo=' . $filterType : ''),
            'icon' => 'fa-folder',
            'can_delete' => true
        ];
    }

    // 2. Get Files
    $sql = "SELECT * FROM archivos WHERE jurisdiccion = ? AND carpeta_id IS NULL";
    $params = "s";
    $args = [$currentJurisdiction];

    if ($filterType) {
        $sql .= " AND tipo = ?";
        $params .= "s";
        $args[] = $filterType;
    }

    $sql .= " ORDER BY fecha_subida DESC";

    $stmtFiles = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmtFiles, $params, ...$args);
    mysqli_stmt_execute($stmtFiles);
    $resFiles = mysqli_stmt_get_result($stmtFiles);
    while ($row = mysqli_fetch_assoc($resFiles)) {
        $items[] = $row;
    }
} elseif ($view === 'folder') {
    // Get Files inside this folder
    $sql = "SELECT * FROM archivos WHERE carpeta_id = ?";
    $params = "i";
    $args = [$currentFolderId];

    if ($filterType) {
        $sql .= " AND tipo = ?";
        $params .= "s";
        $args[] = $filterType;
    }

    $sql .= " ORDER BY fecha_subida DESC";

    $stmtFiles = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmtFiles, $params, ...$args);
    mysqli_stmt_execute($stmtFiles);
    $resFiles = mysqli_stmt_get_result($stmtFiles);
    while ($row = mysqli_fetch_assoc($resFiles)) {
        $items[] = $row;
    }
}

?>
<!doctype html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>Historial - Zoonosis</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= file_exists('assets/css/app.css') ? filemtime('assets/css/app.css') : '1.0' ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= file_exists('assets/css/dark-mode.css') ? filemtime('assets/css/dark-mode.css') : '1.0' ?>">
    <!-- Tabler Icons CDN - Iconos profesionales y consistentes -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons@latest/tabler-icons.min.css">
    <!-- Icon Manager JS -->
    <script src="assets/js/icon-manager.js" defer></script>
    <style>
        body {
            margin: 0;
            padding: 0;
        }

        .app-shell {
            margin: 0;
            padding: 0;
        }

        .app-main {
            margin: 0;
            padding: 40px;
        }

        /* Explorer Grid */
        .explorer-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
            gap: 1.5rem;
            margin-top: 1.5rem;
        }

        /* Realistic Folder Design */
        .folder-item {
            position: relative;
            background: linear-gradient(145deg, #fefce8 0%, #fef9c3 50%, #fef08a 100%);
            border: 2px solid #facc15;
            border-radius: 0 16px 16px 16px;
            padding: 20px 16px 18px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 150px;
            box-shadow: 0 4px 12px rgba(250, 204, 21, 0.2);
            margin-top: 16px;
        }

        /* Folder Tab - La pestaña superior */
        .folder-item::before {
            content: '';
            position: absolute;
            top: -14px;
            left: -2px;
            width: 45%;
            height: 16px;
            background: linear-gradient(135deg, #fde047 0%, #facc15 100%);
            border-radius: 10px 10px 0 0;
            border: 2px solid #facc15;
            border-bottom: none;
            transition: all 0.3s;
        }

        .folder-item:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 16px 32px rgba(250, 204, 21, 0.35);
            background: linear-gradient(145deg, #fef9c3 0%, #fef08a 50%, #fde047 100%);
            border-color: #eab308;
        }

        .folder-item:hover::before {
            background: linear-gradient(135deg, #facc15 0%, #eab308 100%);
            border-color: #eab308;
        }

        /* Jurisdiction Folder - Azul */
        .folder-item.jurisdiction {
            background: linear-gradient(145deg, #eff6ff 0%, #dbeafe 50%, #bfdbfe 100%);
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.2);
        }

        .folder-item.jurisdiction::before {
            background: linear-gradient(135deg, #60a5fa 0%, #3b82f6 100%);
            border-color: #3b82f6;
        }

        .folder-item.jurisdiction:hover {
            background: linear-gradient(145deg, #dbeafe 0%, #bfdbfe 50%, #93c5fd 100%);
            border-color: #2563eb;
            box-shadow: 0 16px 32px rgba(59, 130, 246, 0.35);
        }

        .folder-item.jurisdiction:hover::before {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border-color: #2563eb;
        }

        /* Folder Number Badge */
        .folder-number {
            position: absolute;
            top: 8px;
            right: 10px;
            min-width: 26px;
            height: 26px;
            padding: 0 6px;
            border-radius: 8px;
            background: rgba(0, 0, 0, 0.15);
            color: #1f2937;
            font-size: 0.75rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .folder-item.jurisdiction .folder-number {
            background: rgba(255, 255, 255, 0.4);
            color: #1e40af;
        }

        /* Folder Icon */
        .folder-icon-container {
            width: 64px;
            height: 64px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            transition: all 0.3s;
            border: 1px solid rgba(0, 0, 0, 0.08);
        }

        .folder-item:hover .folder-icon-container {
            transform: scale(1.1);
            background: rgba(255, 255, 255, 0.8);
        }

        .folder-icon-container i {
            font-size: 1.8rem;
            color: #b45309;
        }

        .folder-item.jurisdiction .folder-icon-container i {
            color: #1d4ed8;
        }

        /* Icono SVG de Zoonosis */
        .zoonosis-icon {
            width: 44px;
            height: 44px;
            transition: transform 0.3s ease;
        }

        .folder-item:hover .zoonosis-icon {
            transform: scale(1.1);
        }

        /* Folder Name */
        .folder-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1f2937;
            word-break: break-word;
            line-height: 1.3;
            margin-top: 4px;
            max-width: 100%;
        }

        /* Folder Actions */
        .folder-actions {
            position: absolute;
            top: 6px;
            left: 8px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .folder-item:hover .folder-actions {
            opacity: 1;
        }

        .btn-folder-delete {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.8);
            color: #dc2626;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-folder-delete:hover {
            background: #dc2626;
            color: #fff;
        }

        /* Sub-sidebar Navigation */
        .sub-sidebar-card {
            position: sticky;
            top: 20px;
        }

        .sub-sidebar-nav .nav-link {
            padding: 12px 16px;
            border-radius: 10px;
            color: #475569;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 4px;
        }

        .sub-sidebar-nav .nav-link:hover {
            background: #f1f5f9;
            color: #1e40af;
        }

        .sub-sidebar-nav .nav-link.active {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: #fff;
        }

        .sub-sidebar-nav .nav-link.active i {
            color: #fff !important;
        }

        /* Navigation History */
        .nav-history {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            border: 1px solid #e2e8f0;
        }

        .nav-history-title {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-bottom: 12px;
        }

        .nav-history-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 8px;
            color: #475569;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.2s;
            margin-bottom: 4px;
        }

        .nav-history-item:hover {
            background: #e2e8f0;
            color: #1e40af;
        }

        .nav-history-item.current {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .nav-history-item i {
            width: 20px;
            text-align: center;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .row.g-4 {
                flex-direction: column;
            }

            .col-lg-3.col-md-4 {
                order: 2;
                margin-top: 1rem;
            }

            .col-lg-9.col-md-8 {
                order: 1;
            }

            .sub-sidebar-card {
                position: static;
            }
        }

        @media (max-width: 768px) {
            .explorer-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 1rem;
            }

            .folder-item {
                min-height: 130px;
                padding: 16px 12px 14px;
            }

            .folder-icon-container {
                width: 52px;
                height: 52px;
            }

            .folder-icon-container i {
                font-size: 1.5rem;
            }

            /* Botones apilados en móvil */
            .d-flex.justify-content-between.align-items-center.mb-4 {
                flex-direction: column;
                gap: 1rem;
                align-items: stretch !important;
            }

            .d-flex.justify-content-between.align-items-center.mb-4 .d-flex.gap-2 {
                justify-content: center;
                width: 100%;
            }

            .d-flex.justify-content-between.align-items-center.mb-4 .d-flex.gap-2 .btn {
                flex: 1;
            }
        }

        @media (max-width: 576px) {
            .explorer-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .folder-item {
                min-height: 115px;
                padding: 14px 10px 12px;
                margin-top: 14px;
            }

            .folder-item::before {
                height: 14px;
                top: -12px;
            }

            .folder-name {
                font-size: 0.75rem;
            }

            .folder-actions {
                opacity: 1;
            }

            .folder-icon-container {
                width: 44px;
                height: 44px;
            }

            .folder-icon-container i {
                font-size: 1.2rem;
            }
        }

        /* ═══════════════════════════════════════════════════════════ */
        /* TARJETAS DE ARCHIVOS CON ANIMACIÓN DE VISTA PREVIA */
        /* ═══════════════════════════════════════════════════════════ */
        .files-container {
            position: relative;
            min-height: 400px;
        }

        /* Grid de tarjetas */
        .files-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1.25rem;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .files-grid.preview-active {
            grid-template-columns: 220px 1fr;
            gap: 0;
        }

        /* Tarjeta de archivo */
        .file-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8fafc 100%);
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 20px 16px;
            text-align: center;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 180px;
            position: relative;
            overflow: hidden;
        }

        .file-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
            opacity: 0;
            transition: opacity 0.3s;
        }

        .file-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.12);
            border-color: #cbd5e1;
        }

        .file-card:hover::before {
            opacity: 1;
        }

        /* Tarjeta seleccionada - se mueve a la izquierda */
        .file-card.selected {
            position: absolute;
            left: 0;
            top: 0;
            width: 200px;
            z-index: 10;
            border-color: #3b82f6;
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.25);
            background: linear-gradient(145deg, #eff6ff 0%, #dbeafe 100%);
        }

        .file-card.selected::before {
            opacity: 1;
            background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
        }

        /* Ocultar otras tarjetas cuando hay una seleccionada */
        .file-card.hidden {
            opacity: 0;
            transform: scale(0.8) translateX(50px);
            pointer-events: none;
            position: absolute;
        }

        /* Icono del archivo */
        .file-card-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(145deg, #fef2f2 0%, #fee2e2 100%);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            transition: all 0.3s;
            border: 1px solid #fecaca;
        }

        .file-card:hover .file-card-icon {
            transform: scale(1.1);
            background: linear-gradient(145deg, #fee2e2 0%, #fecaca 100%);
        }

        .file-card.selected .file-card-icon {
            background: linear-gradient(145deg, #dbeafe 0%, #bfdbfe 100%);
            border-color: #93c5fd;
        }

        .file-card-icon i {
            font-size: 1.8rem;
            color: #dc2626;
        }

        .file-card.selected .file-card-icon i {
            color: #2563eb;
        }

        /* Nombre del archivo */
        .file-card-name {
            font-size: 0.85rem;
            font-weight: 600;
            color: #1f2937;
            word-break: break-word;
            line-height: 1.3;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        /* Badge de tipo */
        .file-card-type {
            font-size: 0.7rem;
            padding: 4px 10px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #475569;
            font-weight: 500;
            margin-bottom: 6px;
        }

        /* Fecha */
        .file-card-date {
            font-size: 0.7rem;
            color: #94a3b8;
        }

        /* Acciones de la tarjeta */
        .file-card-actions {
            position: absolute;
            top: 8px;
            right: 8px;
            display: flex;
            gap: 4px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .file-card:hover .file-card-actions,
        .file-card.selected .file-card-actions {
            opacity: 1;
        }

        .file-card-actions .btn-action {
            width: 28px;
            height: 28px;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.9);
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: #64748b;
        }

        .file-card-actions .btn-action:hover {
            background: #fff;
            color: #1f2937;
            transform: scale(1.1);
        }

        .file-card-actions .btn-action.delete:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        /* ═══════════════════════════════════════════════════════════ */
        /* PANEL DE VISTA PREVIA (aparece al seleccionar) */
        /* ═══════════════════════════════════════════════════════════ */
        .preview-panel {
            position: absolute;
            left: 240px;
            top: 0;
            right: 0;
            bottom: 0;
            background: #fff;
            border-radius: 16px;
            border: 2px solid #e2e8f0;
            display: none;
            flex-direction: column;
            overflow: hidden;
            opacity: 0;
            transform: translateX(30px);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .preview-panel.active {
            display: flex;
            opacity: 1;
            transform: translateX(0);
        }

        .preview-header {
            padding: 16px 20px;
            background: linear-gradient(145deg, #f8fafc 0%, #f1f5f9 100%);
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .preview-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-title i {
            color: #3b82f6;
        }

        .btn-close-preview {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #fff;
            border: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
            color: #64748b;
        }

        .btn-close-preview:hover {
            background: #fef2f2;
            color: #dc2626;
            border-color: #fecaca;
        }

        .preview-content {
            flex: 1;
            padding: 16px;
            background: #f8fafc;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .preview-content iframe {
            width: 100%;
            height: 100%;
            min-height: 350px;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .preview-info {
            padding: 16px 20px;
            background: #fff;
            border-top: 1px solid #e2e8f0;
        }

        .preview-filename {
            font-weight: 600;
            color: #1f2937;
            font-size: 0.95rem;
            margin-bottom: 8px;
            word-break: break-word;
        }

        .preview-meta {
            display: flex;
            gap: 20px;
            font-size: 0.8rem;
            color: #64748b;
        }

        .preview-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .preview-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
        }

        .preview-actions .btn {
            flex: 1;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .files-grid.preview-active {
                grid-template-columns: 1fr;
            }

            .file-card.selected {
                position: relative;
                width: 100%;
                margin-bottom: 16px;
            }

            .preview-panel {
                position: relative;
                left: 0;
                margin-top: 16px;
            }
        }

        @media (max-width: 576px) {
            .files-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
            }

            .file-card {
                min-height: 150px;
                padding: 16px 12px;
            }

            .file-card-icon {
                width: 48px;
                height: 48px;
            }

            .file-card-icon i {
                font-size: 1.4rem;
            }

            .file-card-name {
                font-size: 0.75rem;
            }

            .file-card-actions {
                opacity: 1;
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>

        <main class="app-main">
            <div class="container-fluid">

                <!-- Header & Breadcrumbs -->
                <div class="d-flex justify-content-between align-items-center mb-4" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white;">
                    <div>
                        <h2 class="fw-bold mb-2" style="color: white;">
                            <i class="fas fa-folder-open me-2"></i>Historial y Archivos
                        </h2>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb mb-0" style="--bs-breadcrumb-divider-color: rgba(255,255,255,0.6);">
                                <?php foreach ($breadcrumbs as $crumb): ?>
                                    <?php if ($crumb['active']): ?>
                                        <li class="breadcrumb-item active" aria-current="page" style="color: rgba(255,255,255,0.9);"><?= htmlspecialchars($crumb['label']) ?></li>
                                    <?php else: ?>
                                        <li class="breadcrumb-item"><a href="<?= $crumb['url'] ?>" class="text-decoration-none" style="color: rgba(255,255,255,0.8);"><?= htmlspecialchars($crumb['label']) ?></a></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ol>
                        </nav>
                    </div>

                    <div class="d-flex gap-2">
                        <?php if ($view === 'jurisdiction'): ?>
                            <button class="btn btn-light shadow-sm" data-bs-toggle="modal" data-bs-target="#newFolderModal">
                                <i class="fas fa-folder-plus me-2"></i>Nueva Carpeta
                            </button>
                        <?php endif; ?>

                        <?php if ($view === 'jurisdiction' || $view === 'folder'): ?>
                            <button class="btn btn-warning shadow-sm btn-glow" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                <i class="fas fa-cloud-upload-alt me-2"></i>Subir Archivo
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($msg): ?>
                    <div class="alert alert-success alert-dismissible fade show shadow-sm">
                        <?= htmlspecialchars($msg) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($err): ?>
                    <div class="alert alert-danger alert-dismissible fade show shadow-sm">
                        <?= htmlspecialchars($err) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row g-4">
                    <!-- Sub Sidebar (Filters + Navigation) -->
                    <div class="col-lg-3 col-md-4">
                        <div class="glass-card p-3 sub-sidebar-card">
                            <!-- Navigation History -->
                            <?php if ($view !== 'root' || $rol !== 'admin'): ?>
                                <div class="nav-history">
                                    <div class="nav-history-title">
                                        <i class="fas fa-route me-1"></i> Navegación
                                    </div>
                                    <?php if ($rol === 'admin'): ?>
                                        <a href="?view=root" class="nav-history-item <?= $view === 'root' ? 'current' : '' ?>">
                                            <i class="fas fa-home"></i>
                                            <span>Inicio</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!empty($currentJurisdiction)): ?>
                                        <a href="?view=jurisdiction&id=<?= urlencode($currentJurisdiction) ?>" class="nav-history-item <?= $view === 'jurisdiction' ? 'current' : '' ?>">
                                            <i class="fas fa-building"></i>
                                            <span><?= htmlspecialchars($currentJurisdiction) ?></span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if ($view === 'folder' && isset($folderInfo)): ?>
                                        <a href="?view=folder&id=<?= $currentFolderId ?>" class="nav-history-item current">
                                            <i class="fas fa-folder-open"></i>
                                            <span><?= htmlspecialchars($folderInfo['nombre']) ?></span>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <h6 class="text-uppercase text-muted small fw-bold mb-3 mt-3">
                                <i class="fas fa-filter me-1"></i> Filtros
                            </h6>
                            <nav class="nav flex-column sub-sidebar-nav">
                                <a class="nav-link <?= empty($filterType) ? 'active' : '' ?>" href="?view=<?= $view ?>&id=<?= urlencode($currentId) ?>">
                                    <i class="fas fa-layer-group me-2"></i> Todos
                                </a>
                                <a class="nav-link <?= $filterType === 'expediente' ? 'active' : '' ?>"
                                    href="?view=<?= $view ?>&id=<?= urlencode($currentId) ?>&tipo=expediente">
                                    <i class="fas fa-folder-open me-2"></i> Expedientes
                                </a>
                                <a class="nav-link <?= $filterType === 'oficio' ? 'active' : '' ?>"
                                    href="?view=<?= $view ?>&id=<?= urlencode($currentId) ?>&tipo=oficio">
                                    <i class="fas fa-file-signature me-2"></i> Oficios
                                </a>
                                <a class="nav-link <?= $filterType === 'cronograma' ? 'active' : '' ?>"
                                    href="?view=<?= $view ?>&id=<?= urlencode($currentId) ?>&tipo=cronograma">
                                    <i class="fas fa-calendar-alt me-2"></i> Cronogramas
                                </a>
                            </nav>

                            <div class="mt-4 p-3 bg-light rounded-3 border">
                                <h6 class="small fw-bold mb-2">
                                    <i class="fas fa-chart-pie me-1 text-primary"></i> Resumen
                                </h6>
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Carpetas:</span>
                                    <strong class="text-primary"><?= count($folders ?? []) ?></strong>
                                </div>
                                <div class="d-flex justify-content-between small text-muted">
                                    <span>Archivos:</span>
                                    <strong class="text-success"><?= count($items ?? []) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Content -->
                    <div class="col-lg-9 col-md-8">

                        <!-- Folders Grid -->
                        <?php if (!empty($folders)): ?>
                            <div class="d-flex align-items-center mb-3">
                                <h5 class="text-muted small fw-bold text-uppercase mb-0 me-2">
                                    <i class="fas fa-folder me-1"></i> Carpetas
                                </h5>
                                <hr class="flex-grow-1 border-muted opacity-25">
                            </div>

                            <div class="explorer-grid mb-4">
                                <?php
                                $folderIndex = 1;
                                foreach ($folders as $folder):
                                    // Determinar si es jurisdicción o carpeta personalizada
                                    $isJurisdiction = ($folder['type'] === 'jurisdiction');
                                    $folderClass = $isJurisdiction ? 'jurisdiction' : '';

                                    // Iconos según nombre de carpeta
                                    $folderName = strtolower($folder['name']);
                                    $useSvgIcon = false;

                                    if ($isJurisdiction) {
                                        $useSvgIcon = true; // Usar SVG personalizado para jurisdicciones
                                        $icon = '';
                                    } elseif (strpos($folderName, 'expediente') !== false) {
                                        $icon = 'fa-folder-open';
                                    } elseif (strpos($folderName, 'oficio') !== false) {
                                        $icon = 'fa-file-signature';
                                    } elseif (strpos($folderName, 'cronograma') !== false || strpos($folderName, 'calendario') !== false) {
                                        $icon = 'fa-calendar-alt';
                                    } elseif (strpos($folderName, 'vacuna') !== false) {
                                        $icon = 'fa-syringe';
                                    } elseif (strpos($folderName, 'esteriliz') !== false) {
                                        $icon = 'fa-cut';
                                    } elseif (strpos($folderName, 'control') !== false) {
                                        $icon = 'fa-clipboard-check';
                                    } elseif (strpos($folderName, 'muestra') !== false) {
                                        $icon = 'fa-vial';
                                    } elseif (strpos($folderName, 'agredido') !== false || strpos($folderName, 'mordedura') !== false) {
                                        $icon = 'fa-exclamation-triangle';
                                    } elseif (strpos($folderName, 'tratamiento') !== false) {
                                        $icon = 'fa-pills';
                                    } elseif (strpos($folderName, 'reporte') !== false || strpos($folderName, 'informe') !== false) {
                                        $icon = 'fa-chart-bar';
                                    } elseif (strpos($folderName, 'documento') !== false) {
                                        $icon = 'fa-file-alt';
                                    } elseif (strpos($folderName, 'imagen') !== false || strpos($folderName, 'foto') !== false) {
                                        $icon = 'fa-images';
                                    } elseif (strpos($folderName, 'backup') !== false || strpos($folderName, 'respaldo') !== false) {
                                        $icon = 'fa-database';
                                    } else {
                                        $icon = 'fa-folder';
                                    }
                                ?>
                                    <div class="folder-item <?= $folderClass ?>" onclick="window.location='<?= $folder['url'] ?>'">
                                        <span class="folder-number"><?= $folderIndex ?></span>

                                        <?php if (isset($folder['can_delete']) && $folder['can_delete']): ?>
                                            <div class="folder-actions" onclick="event.stopPropagation()">
                                                <button class="btn-folder-delete" title="Eliminar" onclick="deleteFolder(<?= $folder['id'] ?>)">
                                                    <i class="fas fa-trash-alt fa-sm"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>

                                        <div class="folder-icon-container" data-folder-name="<?= htmlspecialchars($folder['name']) ?>">
                                            <!-- Icono cargado por JS -->
                                        </div>
                                        <span class="folder-name"><?= htmlspecialchars($folder['name']) ?></span>
                                    </div>
                                <?php
                                    $folderIndex++;
                                endforeach;
                                ?>
                            </div>
                        <?php endif; ?>

                        <!-- Files con Panel de Vista Previa estilo Windows -->
                        <?php if ($view !== 'root' || !empty($filterType)): ?>
                            <div class="d-flex align-items-center mb-3">
                                <h5 class="text-muted small fw-bold text-uppercase mb-0 me-2">
                                    <i class="fas fa-file-pdf me-1"></i> Archivos <?= $filterType ? '(' . ucfirst($filterType) . ')' : '' ?>
                                </h5>
                                <hr class="flex-grow-1 border-muted opacity-25">
                            </div>

                            <?php if (empty($items)): ?>
                                <div class="text-center py-5 text-muted">
                                    <div class="mb-2">
                                        <i class="fas fa-file-pdf fa-3x opacity-25"></i>
                                    </div>
                                    <p class="mb-0 small">No hay archivos en esta ubicación.</p>
                                </div>
                            <?php else: ?>
                                <div class="files-container" id="filesContainer">
                                    <!-- Grid de Tarjetas -->
                                    <div class="files-grid" id="filesGrid">
                                        <?php foreach ($items as $index => $item): ?>
                                            <div class="file-card"
                                                data-file="storage/pdfs/<?= htmlspecialchars($item['nombre_archivo']) ?>"
                                                data-name="<?= htmlspecialchars($item['nombre_original']) ?>"
                                                data-type="<?= ucfirst($item['tipo']) ?>"
                                                data-date="<?= date('d/m/Y H:i', strtotime($item['fecha_subida'])) ?>"
                                                data-id="<?= $item['id'] ?>"
                                                onclick="selectFileCard(this)">

                                                <div class="file-card-actions" onclick="event.stopPropagation()">
                                                    <a href="storage/pdfs/<?= htmlspecialchars($item['nombre_archivo']) ?>" target="_blank" class="btn-action" title="Abrir">
                                                        <i class="fas fa-external-link-alt"></i>
                                                    </a>
                                                    <a href="storage/pdfs/<?= htmlspecialchars($item['nombre_archivo']) ?>" download class="btn-action" title="Descargar">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <button class="btn-action delete" onclick="deleteFile(<?= $item['id'] ?>)" title="Eliminar">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>

                                                <div class="file-card-icon">
                                                    <i class="fas fa-file-pdf"></i>
                                                </div>
                                                <span class="file-card-name"><?= htmlspecialchars($item['nombre_original']) ?></span>
                                                <span class="file-card-type"><?= ucfirst($item['tipo']) ?></span>
                                                <span class="file-card-date"><?= date('d M Y', strtotime($item['fecha_subida'])) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <!-- Panel de Vista Previa (aparece al seleccionar) -->
                                    <div class="preview-panel" id="previewPanel">
                                        <div class="preview-header">
                                            <span class="preview-title">
                                                <i class="fas fa-eye"></i> Vista Previa
                                            </span>
                                            <button class="btn-close-preview" onclick="closePreview()" title="Cerrar">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        <div class="preview-content">
                                            <iframe id="previewFrame" src="" frameborder="0"></iframe>
                                        </div>
                                        <div class="preview-info">
                                            <div class="preview-filename" id="previewName"></div>
                                            <div class="preview-meta">
                                                <span><i class="fas fa-tag"></i> <span id="previewType"></span></span>
                                                <span><i class="fas fa-calendar"></i> <span id="previewDate"></span></span>
                                            </div>
                                            <div class="preview-actions">
                                                <a id="previewOpenBtn" href="#" target="_blank" class="btn btn-outline-primary btn-sm">
                                                    <i class="fas fa-external-link-alt me-1"></i> Abrir
                                                </a>
                                                <a id="previewDownloadBtn" href="#" download class="btn btn-primary btn-sm">
                                                    <i class="fas fa-download me-1"></i> Descargar
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Modals -->
    <!-- New Folder Modal -->
    <div class="modal fade" id="newFolderModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post">
                    <div class="modal-header">
                        <h5 class="modal-title">Nueva Carpeta</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="create_folder">
                        <input type="hidden" name="jurisdiccion_target" value="<?= htmlspecialchars($currentJurisdiction) ?>">
                        <div class="mb-3">
                            <label class="form-label">Nombre de la Carpeta</label>
                            <input type="text" name="nombre_carpeta" class="form-control" required placeholder="Ej. Expediente 2024-A">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Crear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Modal -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" enctype="multipart/form-data">
                    <div class="modal-header">
                        <h5 class="modal-title">Subir Archivo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="jurisdiccion_upload" value="<?= htmlspecialchars($currentJurisdiction) ?>">
                        <?php if ($currentFolderId): ?>
                            <input type="hidden" name="carpeta_upload" value="<?= $currentFolderId ?>">
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Tipo de documento</label>
                            <select name="tipo" class="form-select">
                                <option value="expediente" <?= $currentFolderId ? 'selected' : '' ?>>Expediente</option>
                                <option value="cronograma">Cronograma</option>
                                <option value="oficio">Oficio</option>
                                <option value="otro">Otro</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Seleccionar PDF</label>
                            <input type="file" name="archivo" class="form-control" accept="application/pdf" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Subir</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Forms -->
    <form id="deleteFileForm" method="post" style="display:none;">
        <input type="hidden" name="action" value="delete_file">
        <input type="hidden" name="id" id="deleteFileId">
    </form>
    <form id="deleteFolderForm" method="post" style="display:none;">
        <input type="hidden" name="action" value="delete_folder">
        <input type="hidden" name="id" id="deleteFolderId">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variable para guardar la tarjeta seleccionada
        let selectedCard = null;

        function deleteFile(id) {
            if (confirm('¿Eliminar este archivo permanentemente?')) {
                document.getElementById('deleteFileId').value = id;
                document.getElementById('deleteFileForm').submit();
            }
        }

        function deleteFolder(id) {
            if (confirm('¿Eliminar esta carpeta y TODO su contenido?')) {
                document.getElementById('deleteFolderId').value = id;
                document.getElementById('deleteFolderForm').submit();
            }
        }

        // Función para seleccionar tarjeta y mostrar vista previa con animación
        function selectFileCard(card) {
            const container = document.getElementById('filesContainer');
            const grid = document.getElementById('filesGrid');
            const previewPanel = document.getElementById('previewPanel');
            const allCards = document.querySelectorAll('.file-card');

            // Si ya hay una tarjeta seleccionada y es la misma, cerrar
            if (selectedCard === card) {
                closePreview();
                return;
            }

            // Guardar referencia
            selectedCard = card;

            // Ocultar todas las tarjetas excepto la seleccionada
            allCards.forEach(c => {
                if (c !== card) {
                    c.classList.add('hidden');
                } else {
                    c.classList.remove('hidden');
                    c.classList.add('selected');
                }
            });

            // Activar el grid en modo preview
            grid.classList.add('preview-active');

            // Actualizar y mostrar el panel de vista previa
            const previewFrame = document.getElementById('previewFrame');
            const previewName = document.getElementById('previewName');
            const previewType = document.getElementById('previewType');
            const previewDate = document.getElementById('previewDate');
            const previewOpenBtn = document.getElementById('previewOpenBtn');
            const previewDownloadBtn = document.getElementById('previewDownloadBtn');

            if (previewFrame) previewFrame.src = card.dataset.file;
            if (previewName) previewName.textContent = card.dataset.name;
            if (previewType) previewType.textContent = card.dataset.type;
            if (previewDate) previewDate.textContent = card.dataset.date;
            if (previewOpenBtn) previewOpenBtn.href = card.dataset.file;
            if (previewDownloadBtn) previewDownloadBtn.href = card.dataset.file;

            // Mostrar panel con animación
            setTimeout(() => {
                previewPanel.classList.add('active');
            }, 100);
        }

        // Función para cerrar la vista previa
        function closePreview() {
            const grid = document.getElementById('filesGrid');
            const previewPanel = document.getElementById('previewPanel');
            const allCards = document.querySelectorAll('.file-card');

            // Ocultar panel
            previewPanel.classList.remove('active');

            // Restaurar todas las tarjetas
            setTimeout(() => {
                allCards.forEach(c => {
                    c.classList.remove('hidden', 'selected');
                });
                grid.classList.remove('preview-active');

                // Limpiar iframe
                document.getElementById('previewFrame').src = '';
            }, 300);

            selectedCard = null;
        }

        // Cerrar con tecla Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && selectedCard) {
                closePreview();
            }
        });
    </script>
</body>

</html>