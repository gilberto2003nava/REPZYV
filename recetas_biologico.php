<?php

/**
 * GENERADOR DE RECETAS - BIOLÓGICO CANINO
 */
session_start();
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';

establecerHeadersNoCache();

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] !== 'admin') {
    header('Location: inicio.php');
    exit;
}

$jurisdicciones = jurisdicciones();

// Crear tablas
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS recetas_biologico (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jurisdiccion VARCHAR(100) NOT NULL,
    folio INT NOT NULL,
    folio_formateado VARCHAR(10) NOT NULL,
    producto VARCHAR(300) DEFAULT '',
    cantidad_frascos INT NOT NULL DEFAULT 20,
    fecha_emision DATE NOT NULL,
    anio INT NOT NULL,
    receptor_nombre VARCHAR(200) DEFAULT '',
    receptor_cedula VARCHAR(50) DEFAULT '',
    direccion TEXT,
    usuario_id INT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_jurisdiccion (jurisdiccion),
    INDEX idx_folio (folio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

mysqli_query($conn, "ALTER TABLE recetas_biologico ADD COLUMN IF NOT EXISTS producto VARCHAR(300) DEFAULT '' AFTER folio_formateado");
mysqli_query($conn, "ALTER TABLE recetas_biologico ADD COLUMN IF NOT EXISTS receptor_cedula VARCHAR(50) DEFAULT '' AFTER receptor_nombre");
mysqli_query($conn, "ALTER TABLE recetas_biologico ADD COLUMN IF NOT EXISTS direccion TEXT AFTER receptor_cedula");
mysqli_query($conn, "ALTER TABLE recetas_biologico ADD COLUMN IF NOT EXISTS tipo_insumo VARCHAR(50) DEFAULT 'GENERAL' AFTER producto");
mysqli_query($conn, "ALTER TABLE recetas_biologico ADD INDEX IF NOT EXISTS idx_tipo_insumo (tipo_insumo)");

// Tipos de insumo disponibles
$tiposInsumo = ['XILACINA', 'ZOLETIL', 'ANTIBIÓTICO', 'ANALGÉSICO'];

// Direcciones de las jurisdicciones (19 jurisdicciones)
$direccionesJurisdicciones = [
    'ATLACOMULCO' => 'Av. Morelos Ote. No. 101, Altacomulco, México, C.P. 50450',
    'IXTLAHUACA' => 'Nicolás Bravo s/n, Ixtlahuaca, México, C.P. 50740',
    'JILOTEPEC' => 'Ignacio Allende s/n, Jilotepec, México, C.P. 54240',
    'TENANGO DEL VALLE' => 'Boulevard Narciso Bassols s/n, Tenango del Valle, México, C.P. 52160',
    'TOLUCA' => 'Santos Degollado No. 700, Toluca, México, C.P. 50060',
    'XONACATLÁN' => 'Emiliano Zapata No. 4, esq. 1 de mayo, Lerma, México, C.P. 52000',
    'TEJUPILCO' => 'Av. 27 de septiembre No. 47, Tejupilco, México, C.P. 51400',
    'TENANCINGO' => 'Montezuma Pte. No. 202, Tenancingo, México, C.P. 52400',
    'VALLE DE BRAVO' => 'Carretera del manguito s/n, Valle de Bravo, México, C.P. 51200',
    'ATIZAPÁN DE ZARAGOZA' => 'Av. 1ro. de mayo No. 2, Col. La Higuera, Atizapán de Zaragoza, México, C.P. 52940',
    'CUAUTITLÁN' => 'Prolongación Av. Venustiano Carranza, Cuautitlán, México, C.P. 54879',
    'NAUCALPAN' => 'Av. Corona No. 100, Naucalpan, México, C.P. 53410',
    'TEOTIHUACÁN' => 'Av. México s/n, Teotihuacán, México, C.P. 55800',
    'TLALNEPANTLA' => 'Calle Guerrero No. 27, Col. San Javier No. 5, Tlalnepantla, México, C.P. 54030',
    'ZUMPANGO' => 'Plaza Zaragoza No. 4, Zumpango, México, C.P. 55600',
    'AMECAMECA' => 'Calle Oriente 5 s/n, Col. San Miguel Xico, 2da. Sección, Valle de Chalco, México, C.P. 56613',
    'ECATEPEC' => 'Calle Rufino Tamayo No. 67, Ecatepec, México, C.P. 55139',
    'NEZAHUALCÓYOTL' => 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295',
    'TEXCOCO' => 'Camino a Papalotla No. 17, Chiaulla, México, C.P. 56030'
];

// Obtener último folio GLOBAL por año y tipo de insumo
$anioActual = (int)date('Y');
$anioFiltro = isset($_GET['anio']) ? (int)$_GET['anio'] : $anioActual;
$tipoInsumoFiltro = isset($_GET['tipo']) ? trim($_GET['tipo']) : 'TODOS';

// Obtener últimos folios por tipo de insumo
$ultimosFolios = [];
foreach ($tiposInsumo as $tipo) {
    $res = mysqli_query($conn, "SELECT MAX(folio) as ultimo FROM recetas_biologico WHERE anio = $anioFiltro AND tipo_insumo = '$tipo'");
    if ($res) {
        $row = mysqli_fetch_assoc($res);
        $ultimosFolios[$tipo] = (int)($row['ultimo'] ?? 0);
    }
}

// Obtener años disponibles
$resAnios = mysqli_query($conn, "SELECT DISTINCT anio FROM recetas_biologico ORDER BY anio DESC");
$aniosDisponibles = [];
if ($resAnios) {
    while ($rowAnio = mysqli_fetch_assoc($resAnios)) {
        $aniosDisponibles[] = $rowAnio['anio'];
    }
}
if (!in_array($anioActual, $aniosDisponibles)) {
    $aniosDisponibles[] = $anioActual;
}
sort($aniosDisponibles);
$aniosDisponibles = array_reverse($aniosDisponibles);

// Obtener historial
$whereHistorial = "anio = $anioFiltro";
if ($tipoInsumoFiltro !== 'TODOS') {
    $whereHistorial .= " AND tipo_insumo = '" . mysqli_real_escape_string($conn, $tipoInsumoFiltro) . "'";
}

$sqlHist = "SELECT lote_id, jurisdiccion, tipo_insumo, MIN(folio) as f1, MAX(folio) as f2, 
           COUNT(*) as num_recetas, SUM(cantidad_frascos) as frascos, 
           MAX(fecha_emision) as fecha_emision,
           (SELECT receptor_nombre FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as receptor_nombre,
           (SELECT receptor_cedula FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as receptor_cedula,
           (SELECT producto FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as producto,
           (SELECT direccion FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as direccion,
           (SELECT cantidad_frascos FROM recetas_biologico r2 WHERE r2.lote_id = r.lote_id LIMIT 1) as cantidad_frascos_primera,
           MAX(created_at) as fecha_creacion
           FROM recetas_biologico r 
           WHERE $whereHistorial AND lote_id IS NOT NULL
           GROUP BY lote_id
           ORDER BY fecha_creacion DESC 
           LIMIT 30";
$resHist = mysqli_query($conn, $sqlHist);
$historialData = [];
if ($resHist) {
    while ($h = mysqli_fetch_assoc($resHist)) {
        $historialData[] = $h;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Recetas</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column !important;
                gap: 0.75rem !important;
                padding: 1rem !important;
            }

            .header-left {
                width: 100%;
                text-align: center;
            }

            .header-left h1 {
                font-size: 1.3rem !important;
                margin-bottom: 0.25rem !important;
            }

            .header-left p {
                font-size: 0.8rem !important;
            }

            .header-right {
                width: 100%;
                display: flex !important;
                flex-direction: column !important;
                gap: 0.75rem !important;
            }

            .header-selectors {
                display: flex !important;
                flex-direction: row !important;
                gap: 0.5rem !important;
                width: 100%;
                align-items: stretch !important;
            }

            .header-selectors>select {
                flex: 1 !important;
                width: auto !important;
                min-width: 0 !important;
                padding: 0.6rem !important;
                font-size: 0.8rem !important;
                border-radius: 8px !important;
            }

            .header-right>div {
                width: 100% !important;
                position: relative !important;
                display: block !important;
            }

            .header-right>div>button {
                width: 100% !important;
            }

            .header-toggle-btn {
                display: none !important;
            }

            .folios-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.5rem !important;
                margin-bottom: 1.5rem !important;
            }

            .folio-card {
                padding: 0.75rem !important;
            }

            .folio-card-label {
                font-size: 0.6rem !important;
            }

            .folio-card-value {
                font-size: 1rem !important;
            }

            #accionesMenu {
                position: absolute !important;
                top: 100% !important;
                right: 0 !important;
                background: white !important;
                border: 1px solid #e2e8f0 !important;
                border-radius: 8px !important;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
                z-index: 2000 !important;
                min-width: 200px !important;
                margin-top: 0.5rem !important;
                overflow: hidden !important;
            }

            #accionesMenu button {
                padding: 0.75rem 1rem !important;
                font-size: 0.9rem !important;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 0.75rem !important;
                gap: 0.5rem !important;
            }

            .header-left h1 {
                font-size: 1.1rem !important;
            }

            .header-left p {
                font-size: 0.75rem !important;
            }

            .header-selectors select {
                padding: 0.5rem !important;
                font-size: 0.75rem !important;
            }

            .folios-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.4rem !important;
            }

            .folio-card {
                padding: 0.5rem !important;
            }

            .folio-card-label {
                font-size: 0.55rem !important;
            }

            .folio-card-value {
                font-size: 0.9rem !important;
            }
        }

        @media (max-width: 480px) {
            .page-header {
                padding: 0.6rem !important;
                gap: 0.5rem !important;
            }

            .header-left h1 {
                font-size: 0.95rem !important;
            }

            .header-left p {
                font-size: 0.6rem !important;
            }

            .header-toggle-btn {
                padding: 0.5rem 0.6rem !important;
                font-size: 0.75rem !important;
            }

            .header-selectors select {
                font-size: 0.75rem !important;
                padding: 0.4rem 0.5rem !important;
            }

            .header-selectors button {
                font-size: 0.7rem !important;
                padding: 0.4rem 0.6rem !important;
            }

            .folios-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.25rem !important;
            }

            .folio-card {
                padding: 0.4rem 0.5rem !important;
            }

            .folio-card-label {
                font-size: 0.5rem !important;
            }

            .folio-card-value {
                font-size: 0.8rem !important;
            }
        }
    </style>
</head>

<body>
    <div class="toast" id="toast"></div>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">

            <!-- Header con botones de vista -->
            <div class="page-header" style="background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:12px;padding:1rem 1.25rem;color:white;margin-bottom:1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;">
                <div class="header-left" style="flex-shrink:0;min-width:0;">
                    <h1 style="margin:0;font-size:1.2rem;white-space:nowrap;"><i class="fas fa-file-prescription"></i> Recetas</h1>
                    <p style="margin:0.15rem 0 0;opacity:0.85;font-size:0.75rem;color:white;">Año <?= $anioFiltro ?></p>
                </div>
                <div class="header-right" style="display:flex;gap:0.3rem;align-items:center;flex-wrap:nowrap;flex-shrink:0;">
                    <!-- Botón toggle para móvil -->
                    <button class="header-toggle-btn" onclick="toggleHeaderSelectors()" style="display:none;padding:0.5rem 0.8rem;">
                        <i class="fas fa-sliders-h"></i>
                        <i class="fas fa-chevron-down header-toggle-icon" style="margin-left:0.3rem;"></i>
                    </button>

                    <!-- Selectores (se ocultan en móvil) -->
                    <div class="header-selectors" style="display:flex;gap:0.3rem;align-items:center;flex-wrap:nowrap;">
                        <select id="filtroAnio" onchange="cambiarFiltro()" style="padding:0.4rem 0.8rem;border-radius:5px;border:2px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.95);color:#3b82f6;font-weight:600;cursor:pointer;font-size:0.75rem;min-width:80px;width:auto;">
                            <?php foreach ($aniosDisponibles as $anio): ?>
                                <option value="<?= $anio ?>" <?= $anio == $anioFiltro ? 'selected' : '' ?>><?= $anio ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filtroTipo" onchange="cambiarFiltro()" style="padding:0.4rem 0.8rem;border-radius:5px;border:2px solid rgba(255,255,255,0.3);background:rgba(255,255,255,0.95);color:#3b82f6;font-weight:600;cursor:pointer;font-size:0.75rem;min-width:100px;width:auto;">
                            <option value="TODOS" <?= $tipoInsumoFiltro === 'TODOS' ? 'selected' : '' ?>>Todos</option>
                            <?php foreach ($tiposInsumo as $tipo): ?>
                                <option value="<?= $tipo ?>" <?= $tipoInsumoFiltro === $tipo ? 'selected' : '' ?>><?= $tipo ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Botón de Acciones -->
                    <div style="position:relative;display:inline-block;width:100%;">
                        <button onclick="toggleAccionesMenu()" class="btn-header" style="padding:0.5rem 1rem;font-size:0.8rem;white-space:nowrap;background:rgba(255,255,255,0.2);border:2px solid rgba(255,255,255,0.3);width:100%;border-radius:8px;">
                            <i class="fas fa-ellipsis-v"></i> Acciones
                        </button>
                        <div id="accionesMenu" style="display:none;position:absolute;top:100%;right:0;background:white;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15);z-index:1000;min-width:200px;margin-top:0.5rem;overflow:hidden;">
                            <button onclick="cambiarVista('formulario'); cerrarAccionesMenu();" style="width:100%;text-align:left;padding:0.75rem 1rem;border:none;background:none;cursor:pointer;font-size:0.85rem;color:#1e293b;display:flex;align-items:center;gap:0.5rem;transition:background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='none'">
                                <i class="fas fa-plus-circle" style="color:#3b82f6;"></i> Nueva Receta
                            </button>
                            <button onclick="cambiarVista('historial'); cerrarAccionesMenu();" style="width:100%;text-align:left;padding:0.75rem 1rem;border:none;background:none;cursor:pointer;font-size:0.85rem;color:#1e293b;display:flex;align-items:center;gap:0.5rem;transition:background 0.2s;" onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background='none'">
                                <i class="fas fa-history" style="color:#10b981;"></i> Historial
                            </button>
                            <div style="height:1px;background:#e2e8f0;"></div>
                            <button onclick="reiniciarAnio(); cerrarAccionesMenu();" style="width:100%;text-align:left;padding:0.75rem 1rem;border:none;background:none;cursor:pointer;font-size:0.85rem;color:#1e293b;display:flex;align-items:center;gap:0.5rem;transition:background 0.2s;" onmouseover="this.style.background='#fef3c7'" onmouseout="this.style.background='none'">
                                <i class="fas fa-calendar-plus" style="color:#f59e0b;"></i> Nuevo Año
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tarjetas de folios por tipo -->
            <div class="folios-grid" style="display:grid;grid-template-columns:repeat(4,1fr);gap:0.5rem;margin-bottom:2rem;">
                <?php foreach ($tiposInsumo as $tipo): ?>
                    <div class="folio-card" style="background:white;padding:0.6rem 0.8rem;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);border:1px solid #e2e8f0;">
                        <div class="folio-card-label" style="font-size:0.65rem;color:#64748b;text-transform:uppercase;letter-spacing:0.3px;margin-bottom:0.2rem;font-weight:600;"><?= $tipo ?></div>
                        <div class="folio-card-value" style="font-family:monospace;font-size:1.1rem;font-weight:700;color:#3b82f6;">
                            <?= str_pad($ultimosFolios[$tipo] + 1, 6, '0', STR_PAD_LEFT) ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="max-width:900px;margin:0 auto;">

                <!-- ========== VISTA FORMULARIO ========== -->
                <div id="vistaFormulario">
                    <div style="background:#fffef8;border:3px solid #1e3a5f;border-radius:8px;box-shadow:0 15px 50px rgba(0,0,0,0.12);overflow:hidden;">

                        <!-- Encabezado -->
                        <div style="background:linear-gradient(180deg,#f8f6f0,#fffef8);text-align:center;padding:2rem;border-bottom:2px solid #1e3a5f;position:relative;">
                            <div style="position:absolute;top:1rem;left:1.5rem;text-align:left;">
                                <label style="display:block;font-size:0.7rem;color:#5a6c7d;text-transform:uppercase;letter-spacing:1px;margin-bottom:0.3rem;font-weight:700;">
                                    <i class="fas fa-syringe"></i> Insumo
                                </label>
                                <select id="tipo_insumo_header" onchange="actualizarFolio()" style="padding:0.6rem 0.8rem;border:2px solid #3b82f6;border-radius:6px;font-size:0.8rem;background:white;font-weight:600;color:#3b82f6;min-width:160px;">
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($tiposInsumo as $tipo): ?>
                                        <option value="<?= $tipo ?>"><?= $tipo ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="position:absolute;top:1rem;right:1.5rem;text-align:right;">
                                <div style="font-size:0.75rem;color:#5a6c7d;text-transform:uppercase;letter-spacing:1px;">Próximo Folio</div>
                                <div id="folioActual" style="font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:700;color:#3b82f6;background:#f0f4f8;padding:4px 16px;border-radius:6px;border:2px solid #3b82f6;margin-top:0.25rem;">------</div>
                                <div id="tipoInsumoLabel" style="font-size:0.7rem;color:#5a6c7d;margin-top:0.25rem;font-weight:600;">Selecciona tipo</div>
                            </div>
                            <div style="width:70px;height:70px;background:linear-gradient(135deg,#3b82f6,#60a5fa);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;color:white;font-size:1.8rem;">
                                <i class="fas fa-prescription"></i>
                            </div>
                            <h2 style="font-family:'Playfair Display',serif;font-size:1.8rem;color:#3b82f6;margin:0 0 0.25rem;">RECETA DE BIOLÓGICO</h2>
                            <p style="color:#5a6c7d;font-style:italic;margin:0;">Instituto de Salud del Estado de México</p>
                        </div>

                        <!-- Formulario -->
                        <form id="formRecetas" onsubmit="generarRecetas(event)" style="padding:2rem;">
                            <!-- Campo oculto para tipo de insumo (se sincroniza con el select del header) -->
                            <input type="hidden" id="tipo_insumo" name="tipo_insumo" value="">

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;">

                                <div style="grid-column: 1 / -1; display:flex; gap:1rem; align-items:end;">
                                    <div style="flex:1;">
                                        <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                            <i class="fas fa-user-check"></i> Recibió
                                        </label>
                                        <input type="text" id="receptor" name="receptor" placeholder="M.V.Z. Nombre Completo" list="receptoresList"
                                            onchange="autocompletarReceptor(this, document.getElementById('cedula'))"
                                            style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;">
                                        <datalist id="receptoresList"></datalist>
                                    </div>
                                    <div style="width:160px;flex-shrink:0;">
                                        <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                            <i class="fas fa-id-card"></i> Cédula Prof.
                                        </label>
                                        <input type="text" id="cedula" name="cedula" placeholder="11673950"
                                            style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;">
                                    </div>
                                </div>

                                <div>
                                    <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                        <i class="fas fa-hospital"></i> Jurisdicción
                                    </label>
                                    <select id="jurisdiccion" name="jurisdiccion" required onchange="llenarDireccion()" style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;background:white;">
                                        <option value="">Seleccionar...</option>
                                        <?php foreach ($jurisdicciones as $jur): ?>
                                            <option value="<?= htmlspecialchars($jur) ?>"><?= htmlspecialchars($jur) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                        <i class="fas fa-calendar"></i> Fecha
                                    </label>
                                    <input type="date" id="fecha" name="fecha" value="<?= date('Y-m-d') ?>" required
                                        style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;">
                                </div>

                                <div>
                                    <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                        <i class="fas fa-pills"></i> Producto
                                    </label>
                                    <input type="text" id="producto" name="producto" required placeholder="Ej: Zoletil 100, Frasco 5ml" list="productosList"
                                        style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;">
                                    <datalist id="productosList"></datalist>
                                    <div style="margin-top:0.5rem;padding:8px 12px;background:#fef3c7;border-left:3px solid #f59e0b;border-radius:4px;font-size:0.8rem;color:#92400e;">
                                        <i class="fas fa-info-circle"></i> <strong>Importante:</strong> Separa con una coma (,) el nombre del producto del resto
                                        <br><span style="font-size:0.75rem;opacity:0.9;">Ejemplo: Amoxicilina Trihidrato, 150 mg (A.M.X. L-A), Frasco con 100 ml</span>
                                    </div>
                                </div>

                                <div>
                                    <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                        <i class="fas fa-boxes"></i> Cantidad de Frascos
                                    </label>
                                    <input type="number" id="cantidad" name="cantidad" min="1" max="10000" placeholder="Ej: 60" required
                                        oninput="calcularRecetas()" style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;">
                                    <div style="margin-top:0.5rem;padding:6px 10px;background:#e0f2fe;border-left:3px solid #0284c7;border-radius:4px;font-size:0.75rem;color:#075985;">
                                        <i class="fas fa-info-circle"></i> Se generará 1 receta por cada 20 frascos
                                    </div>
                                </div>

                                <div style="grid-column:1/-1;">
                                    <label style="display:block;font-weight:600;color:#3b82f6;margin-bottom:0.5rem;text-transform:uppercase;font-size:0.85rem;">
                                        <i class="fas fa-map-marker-alt"></i> Dirección de la Jurisdicción
                                    </label>
                                    <input type="text" id="direccion" name="direccion" required placeholder="Ej: Santos Degollado No. 700, Col. Santa Clara, C.P. 50060"
                                        style="width:100%;padding:14px;border:2px solid #d4d0c8;border-radius:6px;font-size:1rem;">
                                </div>
                            </div>

                            <!-- Resumen -->
                            <div id="resumenCard" style="display:none;background:linear-gradient(135deg,#f0f4f8,#e8eef3);border:2px dashed #3b82f6;border-radius:8px;padding:1.5rem;margin-top:1.5rem;">
                                <h4 style="font-family:'Playfair Display',serif;color:#3b82f6;margin:0 0 1rem;"><i class="fas fa-clipboard-check"></i> Resumen</h4>
                                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;">
                                    <div style="background:white;padding:1rem;border-radius:6px;border-left:4px solid #3b82f6;">
                                        <div style="font-size:0.75rem;color:#5a6c7d;text-transform:uppercase;">Frascos/Receta</div>
                                        <div style="font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:700;color:#3b82f6;">20</div>
                                    </div>
                                    <div style="background:white;padding:1rem;border-radius:6px;border-left:4px solid #3b82f6;">
                                        <div style="font-size:0.75rem;color:#5a6c7d;text-transform:uppercase;">Total Recetas</div>
                                        <div style="font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:700;color:#3b82f6;" id="totalRecetas">0</div>
                                    </div>
                                    <div style="background:white;padding:1rem;border-radius:6px;border-left:4px solid #3b82f6;">
                                        <div style="font-size:0.75rem;color:#5a6c7d;text-transform:uppercase;">Último Folio</div>
                                        <div style="font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:700;color:#3b82f6;" id="ultimoFolio">000000</div>
                                    </div>
                                    <div style="background:linear-gradient(135deg,#3b82f6,#60a5fa);padding:1rem;border-radius:6px;">
                                        <div style="font-size:0.75rem;color:rgba(255,255,255,0.8);text-transform:uppercase;">Nuevos Folios</div>
                                        <div style="font-family:'Playfair Display',serif;font-size:1.2rem;font-weight:700;color:white;" id="rangoFolios">-</div>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" id="btnGenerar" disabled
                                style="width:100%;margin-top:1.5rem;padding:18px;background:#9ca3af;color:white;border:none;border-radius:8px;font-family:'Playfair Display',serif;font-size:1.2rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;text-transform:uppercase;letter-spacing:1px;">
                                <i class="fas fa-file-pdf"></i> Generar Recetas PDF
                            </button>
                        </form>

                        <div style="background:#1e3a5f;color:white;padding:1rem;text-align:center;font-size:0.85rem;">
                            <i class="fas fa-shield-alt"></i> Material exclusivo para esterilización quirúrgica gratuita - ISEM
                        </div>
                    </div>
                </div>

                <!-- ========== VISTA HISTORIAL ========== -->
                <div id="vistaHistorial" style="display:none;">
                    <div style="background:white;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.08);overflow:hidden;">
                        <div style="background:#f8fafc;padding:1.25rem 1.5rem;border-bottom:1px solid #e2e8f0;">
                            <h3 style="margin:0;font-size:1.1rem;color:#1e293b;"><i class="fas fa-history"></i> Historial de Recetas</h3>
                        </div>

                        <!-- Vista Tarjetas -->
                        <div id="historialTarjetas" style="padding:1.5rem;max-height:500px;overflow-y:auto;">
                            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1rem;">
                                <?php if (!empty($historialData)): foreach ($historialData as $h): ?>
                                        <div onclick="verPDFDirecto('<?= htmlspecialchars(addslashes($h['jurisdiccion'])) ?>',<?= $h['f1'] ?>,<?= $h['f2'] ?>,'<?= htmlspecialchars($h['tipo_insumo'] ?? 'GENERAL') ?>')" style="background:#f8fafc;border-radius:10px;padding:1rem;border:1px solid #e2e8f0;transition:all 0.3s ease;cursor:pointer;" onmouseover="this.style.boxShadow='0 8px 16px rgba(0,0,0,0.12)';this.style.transform='translateY(-2px)'" onmouseout="this.style.boxShadow='none';this.style.transform='translateY(0)'">
                                            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:0.75rem;">
                                                <div>
                                                    <span style="background:linear-gradient(135deg,#1e3a5f,#2d5a87);color:white;padding:4px 10px;border-radius:15px;font-size:0.75rem;font-family:monospace;display:inline-block;margin-bottom:0.25rem;">
                                                        <?= str_pad($h['f1'], 6, '0', STR_PAD_LEFT) ?><?= $h['f1'] != $h['f2'] ? ' - ' . str_pad($h['f2'], 6, '0', STR_PAD_LEFT) : '' ?>
                                                    </span>
                                                    <div style="font-size:0.7rem;color:#64748b;margin-top:0.25rem;">
                                                        <i class="fas fa-syringe"></i> <?= htmlspecialchars($h['tipo_insumo']) ?>
                                                        <?php if ($h['num_recetas'] > 1): ?>
                                                            <span style="margin-left:0.5rem;"><i class="fas fa-layer-group"></i> <?= $h['num_recetas'] ?> recetas</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <span style="color:#64748b;font-size:0.8rem;"><?= date('d/m/Y', strtotime($h['fecha_emision'])) ?></span>
                                            </div>
                                            <h4 style="margin:0 0 0.5rem;color:#1e293b;font-size:0.95rem;"><?= htmlspecialchars($h['jurisdiccion']) ?></h4>
                                            <p style="margin:0 0 0.75rem;color:#64748b;font-size:0.85rem;">
                                                <i class="fas fa-boxes"></i> <?= number_format($h['frascos']) ?> frascos
                                                <?php if (!empty($h['receptor_nombre'])): ?><br><i class="fas fa-user"></i> <?= htmlspecialchars(substr($h['receptor_nombre'], 0, 30)) ?><?php if (strlen($h['receptor_nombre']) > 30) echo '...'; ?><?php endif; ?>
                                            </p>
                                            <div style="display:flex;gap:6px;">
                                                <button
                                                    data-lote="<?= htmlspecialchars($h['lote_id']) ?>"
                                                    onclick="event.stopPropagation(); abrirModalEditarLoteV2(this)"
                                                    style="flex:1;background:#10b981;color:white;border:none;padding:8px;border-radius:6px;cursor:pointer;font-size:0.8rem;"
                                                    title="Editar lote">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); descargarPDFDirecto('<?= htmlspecialchars(addslashes($h['jurisdiccion'])) ?>',<?= $h['f1'] ?>,<?= $h['f2'] ?>,'<?= htmlspecialchars($h['tipo_insumo'] ?? 'GENERAL') ?>')"
                                                    style="flex:1;background:#3b82f6;color:white;border:none;padding:8px;border-radius:6px;cursor:pointer;font-size:0.8rem;"
                                                    title="Descargar PDF">
                                                    <i class="fas fa-download"></i>
                                                </button>
                                                <button onclick="event.stopPropagation(); borrarRecetas(<?= $h['f1'] ?>,<?= $h['f2'] ?>,'<?= htmlspecialchars($h['tipo_insumo'] ?? 'GENERAL') ?>')"
                                                    style="flex:1;background:#ef4444;color:white;border:none;padding:8px;border-radius:6px;cursor:pointer;font-size:0.8rem;"
                                                    title="Eliminar">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach;
                                else: ?>
                                    <div style="grid-column:1/-1;text-align:center;padding:3rem;color:#94a3b8;">
                                        <i class="fas fa-inbox" style="font-size:2.5rem;display:block;margin-bottom:1rem;"></i>
                                        No hay recetas generadas
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Vista Previa PDF -->
                <div id="modalPreviewPDF" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;">
                    <div id="panelPreview" style="position:absolute;top:0;right:-60%;bottom:0;width:60%;background:white;box-shadow:-4px 0 20px rgba(0,0,0,0.3);display:flex;flex-direction:column;transition:right 0.3s ease;">
                        <div style="background:linear-gradient(135deg,#1e3a5f,#2d5a87);color:white;padding:1rem 1.5rem;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">
                            <h3 style="margin:0;font-size:1.1rem;"><i class="fas fa-file-pdf"></i> Vista Previa</h3>
                            <button onclick="cerrarPreviewPDF()" style="background:none;border:none;color:white;font-size:1.5rem;cursor:pointer;padding:0;width:32px;height:32px;display:flex;align-items:center;justify-content:center;border-radius:4px;transition:background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='none'">&times;</button>
                        </div>
                        <div style="flex:1;overflow:hidden;background:#525659;display:flex;align-items:center;justify-content:center;">
                            <iframe id="pdfPreviewFrame" style="width:100%;height:100%;border:none;"></iframe>
                        </div>
                    </div>
                </div>

                <!-- Modal Editar Receta -->
                <div id="modalEditar" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;padding:1rem;">
                    <div style="background:white;border-radius:12px;max-width:700px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 25px 50px rgba(0,0,0,0.25);">
                        <div style="background:linear-gradient(135deg,#10b981,#059669);color:white;padding:1rem 1.25rem;border-radius:12px 12px 0 0;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;">
                            <h3 style="margin:0;font-size:1rem;"><i class="fas fa-edit"></i> Editar Receta</h3>
                            <button onclick="cerrarModalEditar()" style="background:none;border:none;color:white;font-size:1.3rem;cursor:pointer;padding:0;width:28px;height:28px;display:flex;align-items:center;justify-content:center;">&times;</button>
                        </div>
                        <form id="formEditar" onsubmit="guardarEdicion(event)" style="padding:1.5rem;">
                            <input type="hidden" id="edit_lote_id" name="lote_id">
                            <input type="hidden" id="edit_tipo_insumo" name="tipo_insumo">

                            <!-- Folio (solo lectura) -->
                            <div style="margin-bottom:1rem;background:#f8fafc;padding:12px;border-radius:8px;border:2px solid #e2e8f0;">
                                <label style="display:block;font-weight:600;color:#64748b;margin-bottom:0.25rem;font-size:0.75rem;text-transform:uppercase;">
                                    <i class="fas fa-hashtag"></i> Rango de Folios
                                </label>
                                <div id="edit_folio_display" style="font-family:monospace;font-size:1.2rem;font-weight:700;color:#3b82f6;">000000</div>
                                <div style="font-size:0.7rem;color:#64748b;margin-top:0.25rem;">
                                    <i class="fas fa-info-circle"></i> Se actualizarán <span id="edit_num_recetas">1</span> receta(s) del mismo lote
                                </div>
                            </div>

                            <div style="margin-bottom:1rem;">
                                <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                    <i class="fas fa-hospital"></i> Jurisdicción
                                </label>
                                <select id="edit_jurisdiccion" name="jurisdiccion" required onchange="llenarDireccionEditar()" style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($jurisdicciones as $jur): ?>
                                        <option value="<?= htmlspecialchars($jur) ?>"><?= htmlspecialchars($jur) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr auto;gap:1rem;margin-bottom:1rem;">
                                <div>
                                    <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                        <i class="fas fa-user-check"></i> Recibió
                                    </label>
                                    <input type="text" id="edit_receptor" name="receptor" placeholder="M.V.Z. Nombre Completo" list="receptoresListEdit"
                                        onchange="autocompletarReceptor(this, document.getElementById('edit_cedula'))"
                                        style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                                    <datalist id="receptoresListEdit"></datalist>
                                </div>
                                <div style="width:140px;">
                                    <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                        <i class="fas fa-id-card"></i> Cédula
                                    </label>
                                    <input type="text" id="edit_cedula" name="cedula" placeholder="11673950"
                                        style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                                </div>
                            </div>

                            <div style="margin-bottom:1rem;">
                                <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                    <i class="fas fa-pills"></i> Producto
                                </label>
                                <input type="text" id="edit_producto" name="producto" required placeholder="Ej: Zoletil 100, Frasco 5ml" list="productosListEdit"
                                    style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                                <datalist id="productosListEdit"></datalist>
                                <small style="color:#f59e0b;font-size:0.75rem;display:block;margin-top:0.25rem;">
                                    <i class="fas fa-exclamation-triangle"></i> Separa con coma (,) el nombre del producto del resto
                                </small>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1rem;">
                                <div>
                                    <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                        <i class="fas fa-calendar"></i> Fecha de Emisión
                                    </label>
                                    <input type="date" id="edit_fecha" name="fecha" required
                                        style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                                </div>
                                <div>
                                    <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                        <i class="fas fa-boxes"></i> Cantidad Total de Frascos Entregados
                                    </label>
                                    <input type="number" id="edit_cantidad" name="cantidad_frascos" min="1" max="10000" required
                                        style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                                    <small style="color:#64748b;font-size:0.75rem;display:block;margin-top:0.25rem;">
                                        <i class="fas fa-info-circle"></i> Total máximo de frascos entregados en todas las <span id="edit_num_recetas">1</span> receta(s) del lote
                                    </small>
                                </div>
                            </div>

                            <div style="margin-bottom:1rem;">
                                <label style="display:block;font-weight:600;color:#1e293b;margin-bottom:0.5rem;font-size:0.85rem;">
                                    <i class="fas fa-map-marker-alt"></i> Dirección
                                </label>
                                <input type="text" id="edit_direccion" name="direccion" placeholder="Ej: Santos Degollado No. 700, Col. Santa Clara, C.P. 50060"
                                    style="width:100%;padding:12px;border:2px solid #e2e8f0;border-radius:8px;font-size:0.9rem;">
                            </div>

                            <div style="background:#e0f2fe;border-left:4px solid #0284c7;padding:12px;border-radius:6px;margin-bottom:1.5rem;font-size:0.85rem;color:#075985;">
                                <i class="fas fa-info-circle"></i> <strong>Nota:</strong> Los cambios se aplicarán a todas las <span id="edit_num_recetas">1</span> receta(s) del lote. Después de guardar, puedes regenerar el PDF con los datos actualizados.
                            </div>

                            <div style="display:flex;gap:0.75rem;">
                                <button type="button" onclick="cerrarModalEditar()"
                                    style="flex:1;padding:12px;background:#e2e8f0;color:#475569;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.9rem;">
                                    <i class="fas fa-times"></i> Cancelar
                                </button>
                                <button type="submit" id="btnGuardarEdicion"
                                    style="flex:2;padding:12px;background:linear-gradient(135deg,#10b981,#059669);color:white;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:0.9rem;">
                                    <i class="fas fa-save"></i> Guardar Cambios
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </main>
    </div>

    <script>
        // ========== RESTAURAR VISTA AL CARGAR ==========
        // Verificar si hay parámetro 'vista' en la URL y cambiar a esa vista
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            const vistaParam = urlParams.get('vista');

            if (vistaParam === 'historial') {
                // Esperar a que el DOM esté listo
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        cambiarVista('historial');
                    });
                } else {
                    cambiarVista('historial');
                }
            }
        })();

        // Función para toggle de selectores en móvil
        function toggleHeaderSelectors() {
            const btn = document.querySelector('.header-toggle-btn');
            const selectors = document.querySelector('.header-selectors');
            if (btn && selectors) {
                btn.classList.toggle('active');
                selectors.classList.toggle('show');
            }
        }

        // Función para toggle del menú de acciones
        function toggleAccionesMenu() {
            const menu = document.getElementById('accionesMenu');
            if (menu) {
                menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
            }
        }

        // Función para cerrar el menú de acciones
        function cerrarAccionesMenu() {
            const menu = document.getElementById('accionesMenu');
            if (menu) {
                menu.style.display = 'none';
            }
        }

        // Cerrar menú de acciones al hacer clic fuera
        document.addEventListener('click', function(event) {
            const menu = document.getElementById('accionesMenu');
            const btn = event.target.closest('button[onclick*="toggleAccionesMenu"]');
            if (menu && !btn && !event.target.closest('#accionesMenu')) {
                menu.style.display = 'none';
            }
        });

        // Mostrar/ocultar botón toggle según el tamaño de pantalla
        function updateHeaderResponsive() {
            const toggleBtn = document.querySelector('.header-toggle-btn');
            const selectors = document.querySelector('.header-selectors');

            if (!toggleBtn || !selectors) return;

            if (window.innerWidth <= 768) {
                toggleBtn.style.display = 'flex';
                selectors.style.display = 'none';
                selectors.classList.remove('show');
                toggleBtn.classList.remove('active');
            } else {
                toggleBtn.style.display = 'none';
                selectors.style.display = 'flex';
                selectors.classList.remove('show');
                toggleBtn.classList.remove('active');
            }
        }

        // Ejecutar al cargar y al redimensionar
        window.addEventListener('load', function() {
            updateHeaderResponsive();
            // Cargar dirección si ya hay una jurisdicción seleccionada
            llenarDireccion();
            // Cargar datos para autocompletado
            cargarDatosAutocompletado();
        });
        window.addEventListener('resize', updateHeaderResponsive);

        // ========== CARGAR DATOS PARA AUTOCOMPLETADO ==========
        let receptoresData = [];
        let productosData = [];

        async function cargarDatosAutocompletado() {
            try {
                // Cargar receptores
                const resReceptores = await fetch('cargar_datos_recetas.php?tipo=receptores');
                const dataReceptores = await resReceptores.json();
                if (dataReceptores.success) {
                    receptoresData = dataReceptores.data;

                    // Poblar datalists de receptores
                    const datalistReceptores = document.getElementById('receptoresList');
                    const datalistReceptoresEdit = document.getElementById('receptoresListEdit');

                    receptoresData.forEach(r => {
                        const option = document.createElement('option');
                        option.value = r.nombre;
                        datalistReceptores.appendChild(option);

                        const optionEdit = document.createElement('option');
                        optionEdit.value = r.nombre;
                        datalistReceptoresEdit.appendChild(optionEdit);
                    });
                }

                // Cargar productos
                const resProductos = await fetch('cargar_datos_recetas.php?tipo=productos');
                const dataProductos = await resProductos.json();
                if (dataProductos.success) {
                    productosData = dataProductos.data;

                    // Poblar datalists de productos
                    const datalistProductos = document.getElementById('productosList');
                    const datalistProductosEdit = document.getElementById('productosListEdit');

                    productosData.forEach(p => {
                        const option = document.createElement('option');
                        option.value = p;
                        datalistProductos.appendChild(option);

                        const optionEdit = document.createElement('option');
                        optionEdit.value = p;
                        datalistProductosEdit.appendChild(optionEdit);
                    });
                }
            } catch (err) {
                console.error('Error al cargar datos de autocompletado:', err);
            }
        }

        // Función para autocompletar receptor y cédula
        function autocompletarReceptor(inputReceptor, inputCedula) {
            const valorBuscado = inputReceptor.value.toUpperCase();
            const receptor = receptoresData.find(r => r.nombre.toUpperCase() === valorBuscado);

            if (receptor && inputCedula) {
                inputCedula.value = receptor.cedula;
            }
        }

        // Direcciones de las jurisdicciones
        const direccionesJurisdicciones = {
            'ATLACOMULCO': 'Av. Morelos Ote. No. 101, Altacomulco, México, C.P. 50450',
            'IXTLAHUACA': 'Nicolás Bravo s/n, Ixtlahuaca, México, C.P. 50740',
            'JILOTEPEC': 'Ignacio Allende s/n, Jilotepec, México, C.P. 54240',
            'TENANGO DEL VALLE': 'Boulevard Narciso Bassols s/n, Tenango del Valle, México, C.P. 52160',
            'TOLUCA': 'Santos Degollado No. 700, Toluca, México, C.P. 50060',
            'XONACATLÁN': 'Emiliano Zapata No. 4, esq. 1 de mayo, Lerma, México, C.P. 52000',
            'TEJUPILCO': 'Av. 27 de septiembre No. 47, Tejupilco, México, C.P. 51400',
            'TENANCINGO': 'Montezuma Pte. No. 202, Tenancingo, México, C.P. 52400',
            'VALLE DE BRAVO': 'Carretera del manguillo s/n, Valle de Bravo, México, C.P. 51200',
            'ATIZAPÁN DE ZARAGOZA': 'Av. 1ro. de mayo No. 2, Col. La Higuera, Atizapán de Zaragoza, México, C.P. 52940',
            'CUAUTITLÁN': 'Prolongación Av. Venustiano Carranza, Cuautitlán, México, C.P. 54879',
            'NAUCALPAN': 'Av. Corona No. 100, Naucalpan, México, C.P. 53410',
            'TEOTIHUACÁN': 'Av. México s/n, Teotihuacán, México, C.P. 55800',
            'TLALNEPANTLA': 'Calle Guerrero No. 27, Col. San Javier No. 5, Tlalnepantla, México, C.P. 54030',
            'ZUMPANGO': 'Plaza Zaragoza No. 4, Zumpango, México, C.P. 55600',
            'AMECAMECA': 'Calle Oriente 5 s/n, Col. San Miguel Xico, 2da. Sección, Valle de Chalco, México, C.P. 56613',
            'ECATEPEC': 'Calle Rufino Tamayo No. 67, Ecatepec, México, C.P. 55139',
            'NEZAHUALCÓYOTL': 'Av. Rivapalacios No. 25, Col. Pavón, Nezahualcóyotl, México, C.P. 55295',
            'TEXCOCO': 'Camino a Papalotla No. 17, Chiaulla, México, C.P. 56030'
        };

        // Función para normalizar texto (quitar acentos)
        function normalizarTexto(texto) {
            return texto.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toUpperCase();
        }

        // Función para llenar automáticamente la dirección
        function llenarDireccion() {
            const jurisdiccionSelect = document.getElementById('jurisdiccion');
            const direccionInput = document.getElementById('direccion');

            if (!jurisdiccionSelect || !direccionInput) return;

            const jurisdiccionSeleccionada = jurisdiccionSelect.value.trim();

            if (!jurisdiccionSeleccionada) {
                direccionInput.value = '';
                return;
            }

            // Extraer solo el nombre de la jurisdicción (sin el número al inicio)
            let nombreJuris = jurisdiccionSeleccionada;
            const partes = jurisdiccionSeleccionada.split(' ');

            // Si la primera parte es un número, removerla
            if (!isNaN(parseInt(partes[0]))) {
                nombreJuris = partes.slice(1).join(' ').trim();
            }

            console.log('Jurisdicción seleccionada:', jurisdiccionSeleccionada);
            console.log('Nombre extraído:', nombreJuris);

            // Normalizar el nombre para buscar sin acentos
            const nombreNormalizado = normalizarTexto(nombreJuris);

            // Buscar la dirección correspondiente (comparando sin acentos)
            let direccionEncontrada = '';
            for (const [key, value] of Object.entries(direccionesJurisdicciones)) {
                if (normalizarTexto(key) === nombreNormalizado) {
                    direccionEncontrada = value;
                    break;
                }
            }

            console.log('Dirección encontrada:', direccionEncontrada);

            if (direccionEncontrada) {
                direccionInput.value = direccionEncontrada;
            } else {
                direccionInput.value = '';
            }
        }

        // Función para llenar automáticamente la dirección en el modal de editar
        function llenarDireccionEditar() {
            const jurisdiccionSelect = document.getElementById('edit_jurisdiccion');
            const direccionInput = document.getElementById('edit_direccion');

            if (!jurisdiccionSelect || !direccionInput) return;

            const jurisdiccionSeleccionada = jurisdiccionSelect.value.trim();

            if (!jurisdiccionSeleccionada) {
                direccionInput.value = '';
                return;
            }

            // Extraer solo el nombre de la jurisdicción (sin el número al inicio)
            let nombreJuris = jurisdiccionSeleccionada;
            const partes = jurisdiccionSeleccionada.split(' ');

            // Si la primera parte es un número, removerla
            if (!isNaN(parseInt(partes[0]))) {
                nombreJuris = partes.slice(1).join(' ').trim();
            }

            // Normalizar el nombre para buscar sin acentos
            const nombreNormalizado = normalizarTexto(nombreJuris);

            // Buscar la dirección correspondiente (comparando sin acentos)
            let direccionEncontrada = '';
            for (const [key, value] of Object.entries(direccionesJurisdicciones)) {
                if (normalizarTexto(key) === nombreNormalizado) {
                    direccionEncontrada = value;
                    break;
                }
            }

            if (direccionEncontrada) {
                direccionInput.value = direccionEncontrada;
            } else {
                direccionInput.value = '';
            }
        }

        const FRASCOS = 20;
        const ultimosFoliosPorTipo = <?= json_encode($ultimosFolios) ?>;
        const anioActual = <?= $anioFiltro ?>;

        // Variable para rastrear si se editó una receta
        let recetaEditada = false;

        // ========== ACTUALIZAR FOLIO SEGÚN TIPO ==========
        function actualizarFolio() {
            const tipoHeader = document.getElementById('tipo_insumo_header').value;
            const tipoHidden = document.getElementById('tipo_insumo');
            const folioDiv = document.getElementById('folioActual');
            const tipoLabel = document.getElementById('tipoInsumoLabel');

            // Sincronizar el campo hidden con el select del header
            tipoHidden.value = tipoHeader;

            if (tipoHeader && ultimosFoliosPorTipo[tipoHeader] !== undefined) {
                const proximoFolio = ultimosFoliosPorTipo[tipoHeader] + 1;
                folioDiv.textContent = proximoFolio.toString().padStart(6, '0');
                tipoLabel.textContent = tipoHeader;
            } else {
                folioDiv.textContent = '------';
                tipoLabel.textContent = 'Selecciona tipo';
            }

            calcularRecetas();
        }

        // ========== CAMBIAR FILTROS ==========
        function cambiarFiltro() {
            const anio = document.getElementById('filtroAnio').value;
            const tipo = document.getElementById('filtroTipo').value;

            // Detectar vista actual
            const histDiv = document.getElementById('vistaHistorial');
            const vistaActual = (histDiv.style.display !== 'none') ? 'historial' : 'formulario';

            window.location.href = '?anio=' + anio + '&tipo=' + tipo + '&vista=' + vistaActual;
        }

        // ========== RECARGAR PRESERVANDO VISTA ==========
        function recargarConVista() {
            const urlParams = new URLSearchParams(window.location.search);
            const histDiv = document.getElementById('vistaHistorial');
            const vistaActual = (histDiv.style.display !== 'none') ? 'historial' : 'formulario';

            // Agregar o actualizar el parámetro vista
            urlParams.set('vista', vistaActual);

            window.location.href = '?' + urlParams.toString();
        }

        // ========== CAMBIO DE VISTAS PRINCIPALES ==========
        function cambiarVista(vista) {
            const formDiv = document.getElementById('vistaFormulario');
            const histDiv = document.getElementById('vistaHistorial');
            const btnForm = document.getElementById('btnVistaForm');
            const btnHist = document.getElementById('btnVistaHist');

            if (!formDiv || !histDiv) return; // Validación

            if (vista === 'formulario') {
                formDiv.style.display = 'block';
                histDiv.style.display = 'none';
                if (btnForm) btnForm.classList.add('activo');
                if (btnHist) btnHist.classList.remove('activo');
            } else {
                formDiv.style.display = 'none';
                histDiv.style.display = 'block';
                if (btnForm) btnForm.classList.remove('activo');
                if (btnHist) btnHist.classList.add('activo');
            }
        }


        // ========== FORMULARIO ==========
        function calcularRecetas() {
            const tipo = document.getElementById('tipo_insumo').value.trim();
            const jur = document.getElementById('jurisdiccion').value.trim();
            const cantFrascos = parseInt(document.getElementById('cantidad').value) || 0;
            const card = document.getElementById('resumenCard');
            const btn = document.getElementById('btnGenerar');

            if (!tipo || !jur || cantFrascos < 1) {
                card.style.display = 'none';
                btn.disabled = true;
                btn.style.background = '#9ca3af';
                return;
            }

            const ultimoFolio = ultimosFoliosPorTipo[tipo] || 0;
            const numRecetas = Math.ceil(cantFrascos / FRASCOS); // Dividir entre 20
            const inicio = ultimoFolio + 1;
            const fin = ultimoFolio + numRecetas;

            document.getElementById('totalRecetas').textContent = numRecetas;
            document.getElementById('ultimoFolio').textContent = ultimoFolio.toString().padStart(6, '0');
            document.getElementById('rangoFolios').textContent = inicio.toString().padStart(6, '0') + ' → ' + fin.toString().padStart(6, '0');

            card.style.display = 'block';
            btn.disabled = false;
            btn.style.background = 'linear-gradient(135deg,#1e3a5f,#2d5a87)';
        }

        document.getElementById('tipo_insumo_header').addEventListener('change', calcularRecetas);
        document.getElementById('jurisdiccion').addEventListener('change', function() {
            calcularRecetas();
            llenarDireccion();
        });
        document.getElementById('cantidad').addEventListener('input', calcularRecetas);

        async function generarRecetas(e) {
            e.preventDefault();
            const btn = document.getElementById('btnGenerar');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generando...';

            try {
                const res = await fetch('generar_recetas_js.php', {
                    method: 'POST',
                    body: new FormData(document.getElementById('formRecetas'))
                });
                const data = await res.json();

                if (data.success) {
                    // Generar PDF con JavaScript
                    generarPDFRecetas(data.recetas);

                    // Actualizar el contador de folios en memoria
                    if (data.tipo_insumo && ultimosFoliosPorTipo[data.tipo_insumo] !== undefined) {
                        ultimosFoliosPorTipo[data.tipo_insumo] = data.ultimo_folio;
                    }

                    // Recargar la página después de un momento para actualizar todo
                    setTimeout(() => location.reload(), 1500);
                } else {
                    alert(data.error || 'Error al generar');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-file-pdf"></i> Generar Recetas PDF';
                }
            } catch (err) {
                alert('Error de conexión');
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-file-pdf"></i> Generar Recetas PDF';
            }
        }

        // ========== GENERAR PDF CON JAVASCRIPT ==========
        function generarPDFRecetas(recetas) {
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF('p', 'mm', 'letter');

            const recetasPorPagina = 2;
            const alturaReceta = 135; // mm
            const margenTop = 10;

            for (let i = 0; i < recetas.length; i += recetasPorPagina) {
                if (i > 0) doc.addPage();

                for (let j = 0; j < recetasPorPagina && (i + j) < recetas.length; j++) {
                    const receta = recetas[i + j];
                    const yPos = margenTop + (j * alturaReceta);

                    // Folio (arriba derecha) - con 6 dígitos
                    doc.setFontSize(11);
                    doc.setFont('helvetica', 'bold');
                    const folioFormateado = receta.folio.toString().padStart(6, '0');
                    doc.text('Folio ' + folioFormateado, 200, yPos + 10, {
                        align: 'right'
                    });

                    // Nombre del doctor (grande, negrita, itálica) - 2 líneas si es necesario
                    doc.setFontSize(16);
                    doc.setFont('helvetica', 'bolditalic');
                    const nombreCompleto = (receta.receptor_nombre || 'LUIS ARMANDO MAGDALENO TORRES').toUpperCase();
                    const anchoMaximo = 180;
                    const lineasNombre = doc.splitTextToSize(nombreCompleto, anchoMaximo);

                    if (lineasNombre.length > 1) {
                        doc.text(lineasNombre[0], 20, yPos + 18);
                        doc.text(lineasNombre[1], 20, yPos + 26);
                    } else {
                        doc.text(nombreCompleto, 20, yPos + 21);
                    }

                    // Cargo y cédula en la misma línea
                    const yPosLinea2 = lineasNombre.length > 1 ? yPos + 34 : yPos + 30;
                    doc.setFontSize(10);
                    doc.setFont('helvetica', 'normal');
                    doc.text('Médico Veterinario Zootecnista', 20, yPosLinea2);
                    doc.text('Céd. Prof. ' + (receta.receptor_cedula || '11673950'), 200, yPosLinea2, {
                        align: 'right'
                    });

                    // Línea divisoria horizontal completa
                    const yPosLinea = yPosLinea2 + 4;
                    doc.setLineWidth(0.8);
                    doc.line(20, yPosLinea, 200, yPosLinea);

                    // Producto en 2 líneas - dividir el texto del producto
                    const yPosProducto = yPosLinea + 11;
                    doc.setFontSize(10);
                    doc.setFont('helvetica', 'normal');
                    const productoCompleto = receta.producto || 'Amoxicilina Trihidrato de Amoxicilina, 150 mg (A.M.X. L-A), Frasco con 100 ml';

                    // Dividir el producto en 2 líneas:
                    // Línea 1: Nombre del producto (hasta la primera coma)
                    // Línea 2: Ingrediente activo + presentación (después de la primera coma)
                    let linea1Prod = productoCompleto;
                    let linea2Prod = '';

                    const primeraComa = productoCompleto.indexOf(',');
                    if (primeraComa !== -1) {
                        linea1Prod = productoCompleto.substring(0, primeraComa).trim();
                        linea2Prod = productoCompleto.substring(primeraComa + 1).trim();
                    }

                    doc.text(linea1Prod, 20, yPosProducto);
                    if (linea2Prod) {
                        doc.text(linea2Prod, 20, yPosProducto + 6);
                    }

                    // Fecha a la derecha del producto
                    doc.setFontSize(9);
                    // Formatear fecha correctamente
                    let fechaFormateada = '';
                    if (receta.fecha_emision && receta.fecha_emision !== '0000-00-00' && receta.fecha_emision.trim() !== '') {
                        // Si viene en formato YYYY-MM-DD
                        if (typeof receta.fecha_emision === 'string' && receta.fecha_emision.includes('-')) {
                            const partes = receta.fecha_emision.split('-');
                            if (partes.length === 3 && partes[0] !== '0000') {
                                const [year, month, day] = partes;
                                fechaFormateada = `${day.padStart(2, '0')}/${month.padStart(2, '0')}/${year}`;
                            }
                        } else {
                            fechaFormateada = receta.fecha_emision;
                        }
                    }

                    // Si no se pudo formatear, usar fecha actual
                    if (!fechaFormateada) {
                        const hoy = new Date();
                        fechaFormateada = `${hoy.getDate().toString().padStart(2, '0')}/${(hoy.getMonth() + 1).toString().padStart(2, '0')}/${hoy.getFullYear()}`;
                    }
                    doc.text('Fecha: ' + fechaFormateada, 200, yPosProducto, {
                        align: 'right'
                    });

                    // Cantidad autorizada (puede variar según el remanente)
                    const yPosCantidad = yPosProducto + 22;
                    doc.setFontSize(11);
                    doc.setFont('helvetica', 'normal');
                    const cantidadFrascos = receta.cantidad_frascos || 20;
                    const textoFrasco = cantidadFrascos === 1 ? 'frasco' : 'frascos';
                    const cantidadTexto = 'Cantidad autorizada: ' + cantidadFrascos + ' ' + textoFrasco;
                    doc.text(cantidadTexto, 20, yPosCantidad);

                    // Firmas
                    const yPosFirmas = yPosCantidad + 13;
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text('Autorizó', 60, yPosFirmas, {
                        align: 'center'
                    });
                    doc.text('Recibió', 160, yPosFirmas, {
                        align: 'center'
                    });

                    // Líneas para firmas
                    const yPosLineasFirma = yPosFirmas + 9;
                    doc.setLineWidth(0.5);
                    doc.line(30, yPosLineasFirma, 90, yPosLineasFirma);
                    doc.line(130, yPosLineasFirma, 190, yPosLineasFirma);

                    // Nombres bajo las firmas
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9);
                    doc.text('M.V.Z. JOSÉ ADOLFO ARAÚJO CÁRDENAS', 60, yPosLineasFirma + 5, {
                        align: 'center'
                    });
                    doc.text('M.V.Z. ' + (receta.receptor_nombre || 'Luis Armando Magdaleno Torres'), 160, yPosLineasFirma + 5, {
                        align: 'center'
                    });

                    // Footer - Jurisdicción (sin línea antes, con más espacio)
                    const yPosLineaFooter = yPosLineasFirma + 16;
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(12);
                    // Extraer solo el nombre de la jurisdicción (sin el número) y agregar "JURISDICCIÓN SANITARIA"
                    let nombreJuris = receta.jurisdiccion || '5 TOLUCA';
                    const partes = nombreJuris.split(' ');
                    if (partes.length > 1 && !isNaN(partes[0])) {
                        // Si empieza con número, quitarlo
                        nombreJuris = partes.slice(1).join(' ');
                    }
                    const textoJuris = 'JURISDICCIÓN SANITARIA ' + nombreJuris.toUpperCase();

                    // Ajustar tamaño de fuente si el texto es muy largo
                    const anchoMaxJuris = 180;
                    let fontSize = 12;
                    doc.setFontSize(fontSize);
                    let anchoTexto = doc.getTextWidth(textoJuris);

                    while (anchoTexto > anchoMaxJuris && fontSize > 8) {
                        fontSize -= 0.5;
                        doc.setFontSize(fontSize);
                        anchoTexto = doc.getTextWidth(textoJuris);
                    }

                    doc.text(textoJuris, 110, yPosLineaFooter, {
                        align: 'center'
                    });

                    // Dirección
                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(8);
                    doc.text(receta.direccion || 'Santos Degollado No. 700, Colonia Santa Clara, Estado de México C.P. 50060', 110, yPosLineaFooter + 5, {
                        align: 'center'
                    });

                    // Línea sólida antes del aviso
                    const yPosLineaAviso = yPosLineaFooter + 9;
                    doc.setLineWidth(0.8);
                    doc.line(20, yPosLineaAviso, 200, yPosLineaAviso);

                    // Aviso final (SIN fondo negro, solo texto)
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(8);
                    doc.text('MATERIAL EXCLUSIVO PARA LAS ACTIVIDADES DE ESTERILIZACIÓN', 110, yPosLineaAviso + 4, {
                        align: 'center'
                    });
                    doc.text('QUIRÚRGICA GRATUITA DE PERROS Y GATOS DEL ISEM', 110, yPosLineaAviso + 8, {
                        align: 'center'
                    });

                    // Línea punteada DESPUÉS del aviso (solo para la primera receta si hay 2 en la página)
                    if (j === 0 && (i + 1) < recetas.length) {
                        const yPosLineaPunteada = yPosLineaAviso + 12;
                        doc.setLineWidth(0.5);
                        doc.setLineDashPattern([3, 3], 0);
                        doc.line(20, yPosLineaPunteada, 200, yPosLineaPunteada);
                        doc.setLineDashPattern([], 0);
                    }
                }
            }

            // Descargar PDF
            const nombreArchivo = 'recetas_' + new Date().toISOString().slice(0, 10) + '.pdf';
            doc.save(nombreArchivo);
            alert('PDF generado correctamente');
        }

        // ========== ACCIONES HISTORIAL ==========
        async function verPDF(jur, f1, f2, tipo) {
            try {
                const res = await fetch('generar_recetas_js.php?ver=1&jurisdiccion=' + encodeURIComponent(jur) + '&folio_inicio=' + f1 + '&folio_fin=' + f2 + '&tipo_insumo=' + encodeURIComponent(tipo));
                const data = await res.json();

                if (data.success && data.recetas.length > 0) {
                    generarPDFRecetas(data.recetas);
                } else {
                    alert('No se encontraron recetas');
                }
            } catch (err) {
                alert('Error al cargar recetas');
            }
        }

        async function borrarRecetas(f1, f2, tipo) {
            if (!confirm('¿Borrar recetas del folio ' + f1.toString().padStart(6, '0') + ' al ' + f2.toString().padStart(6, '0') + ' (' + tipo + ')?\n\nEsto ajustará el contador de folios.')) return;

            try {
                const res = await fetch('generar_recetas_js.php?borrar=1&folio_inicio=' + f1 + '&folio_fin=' + f2 + '&tipo_insumo=' + encodeURIComponent(tipo));
                const data = await res.json();

                if (data.success) {
                    alert('Recetas eliminadas correctamente');
                    recargarConVista();
                } else {
                    alert(data.error || 'Error al borrar');
                }
            } catch (err) {
                alert('Error de conexión');
            }
        }

        async function reiniciarAnio() {
            const anioNuevo = parseInt(prompt('Ingresa el año a iniciar (ej: ' + (anioActual + 1) + '):', (anioActual + 1)));

            if (!anioNuevo || isNaN(anioNuevo) || anioNuevo < 2020 || anioNuevo > 2100) {
                alert('Año inválido');
                return;
            }

            if (!confirm('¿Iniciar el año ' + anioNuevo + '?\n\nEsto creará un nuevo contador de folios comenzando en 000001.\nLos registros de años anteriores se conservarán.')) return;

            try {
                const res = await fetch('generar_recetas_js.php?reiniciar_anio=1&anio=' + anioNuevo);
                const data = await res.json();

                if (data.success) {
                    alert('Año ' + anioNuevo + ' iniciado correctamente.\nPróximo folio: 000001');
                    window.location.href = '?anio=' + anioNuevo;
                } else {
                    alert(data.error || 'Error al iniciar año');
                }
            } catch (err) {
                alert('Error de conexión');
            }
        }

        // ========== VER PDF DIRECTAMENTE ==========
        async function verPDFDirecto(jurisdiccion, f1, f2, tipoInsumo = 'GENERAL') {
            try {
                const res = await fetch(`generar_recetas_js.php?ver=1&jurisdiccion=${encodeURIComponent(jurisdiccion)}&folio_inicio=${f1}&folio_fin=${f2}&tipo_insumo=${encodeURIComponent(tipoInsumo)}`);
                const data = await res.json();

                console.log('Respuesta del servidor:', data); // Debug

                if (data.success && data.recetas && data.recetas.length > 0) {
                    mostrarPDFRecetas(data.recetas, false); // false = no descargar, solo mostrar
                } else {
                    alert('No se encontraron recetas');
                }
            } catch (err) {
                console.error('Error:', err);
                alert('Error al cargar recetas');
            }
        }

        // ========== DESCARGAR PDF DIRECTAMENTE ==========
        async function descargarPDFDirecto(jurisdiccion, f1, f2, tipoInsumo = 'GENERAL') {
            try {
                const res = await fetch(`generar_recetas_js.php?ver=1&jurisdiccion=${encodeURIComponent(jurisdiccion)}&folio_inicio=${f1}&folio_fin=${f2}&tipo_insumo=${encodeURIComponent(tipoInsumo)}`);
                const data = await res.json();

                if (data.success && data.recetas && data.recetas.length > 0) {
                    mostrarPDFRecetas(data.recetas, true); // true = descargar automáticamente
                } else {
                    alert('No se encontraron recetas');
                }
            } catch (err) {
                console.error('Error:', err);
                alert('Error al cargar recetas');
            }
        }

        // ========== GENERAR Y MOSTRAR/DESCARGAR PDF ==========
        function mostrarPDFRecetas(recetas, descargar = false) {
            // Generar PDF con jsPDF
            const {
                jsPDF
            } = window.jspdf;
            const doc = new jsPDF('p', 'mm', 'letter');

            const recetasPorPagina = 2;
            const alturaReceta = 135;
            const margenTop = 10;

            for (let i = 0; i < recetas.length; i += recetasPorPagina) {
                if (i > 0) doc.addPage();

                for (let j = 0; j < recetasPorPagina && (i + j) < recetas.length; j++) {
                    const receta = recetas[i + j];
                    const yPos = margenTop + (j * alturaReceta);

                    // Folio
                    doc.setFontSize(11);
                    doc.setFont('helvetica', 'bold');
                    const folioFormateado = receta.folio.toString().padStart(6, '0');
                    doc.text('Folio ' + folioFormateado, 200, yPos + 10, {
                        align: 'right'
                    });

                    // Nombre del doctor
                    doc.setFontSize(16);
                    doc.setFont('helvetica', 'bolditalic');
                    const nombreCompleto = (receta.receptor_nombre || 'M.V.Z.').toUpperCase();
                    const anchoMaximo = 180;
                    const lineasNombre = doc.splitTextToSize(nombreCompleto, anchoMaximo);

                    if (lineasNombre.length > 1) {
                        doc.text(lineasNombre[0], 20, yPos + 18);
                        doc.text(lineasNombre[1], 20, yPos + 26);
                    } else {
                        doc.text(nombreCompleto, 20, yPos + 21);
                    }

                    // Cargo y cédula
                    const yPosLinea2 = lineasNombre.length > 1 ? yPos + 34 : yPos + 30;
                    doc.setFontSize(10);
                    doc.setFont('helvetica', 'normal');
                    doc.text('Médico Veterinario Zootecnista', 20, yPosLinea2);
                    doc.text('Céd. Prof. ' + (receta.receptor_cedula || '00000000'), 200, yPosLinea2, {
                        align: 'right'
                    });

                    // Línea divisoria
                    const yPosLinea = yPosLinea2 + 4;
                    doc.setLineWidth(0.8);
                    doc.line(20, yPosLinea, 200, yPosLinea);

                    // Producto
                    const yPosProducto = yPosLinea + 11;
                    doc.setFontSize(10);
                    doc.setFont('helvetica', 'normal');
                    const productoCompleto = receta.producto || 'Producto no especificado';

                    let linea1Prod = productoCompleto;
                    let linea2Prod = '';

                    // Estrategia de salto de línea:
                    // 1. Si hay coma, dividir por coma
                    // 2. Si hay paréntesis de apertura, dividir ahí
                    // 3. Si el texto es muy largo (>60 caracteres), dividir en el último espacio antes de 60

                    const primeraComa = productoCompleto.indexOf(',');
                    const primerParentesis = productoCompleto.indexOf('(');

                    if (primeraComa !== -1) {
                        // Dividir por coma
                        linea1Prod = productoCompleto.substring(0, primeraComa).trim();
                        linea2Prod = productoCompleto.substring(primeraComa + 1).trim();
                    } else if (primerParentesis !== -1 && primerParentesis > 10) {
                        // Dividir antes del paréntesis
                        linea1Prod = productoCompleto.substring(0, primerParentesis).trim();
                        linea2Prod = productoCompleto.substring(primerParentesis).trim();
                    } else if (productoCompleto.length > 60) {
                        // Buscar el último espacio antes de 60 caracteres
                        const ultimoEspacio = productoCompleto.substring(0, 60).lastIndexOf(' ');
                        if (ultimoEspacio > 20) {
                            linea1Prod = productoCompleto.substring(0, ultimoEspacio).trim();
                            linea2Prod = productoCompleto.substring(ultimoEspacio + 1).trim();
                        }
                    }

                    doc.text(linea1Prod, 20, yPosProducto);
                    if (linea2Prod) {
                        doc.text(linea2Prod, 20, yPosProducto + 6);
                    }

                    // Fecha
                    doc.setFontSize(9);
                    // Formatear fecha correctamente
                    let fechaFormateada = '';
                    if (receta.fecha_emision && receta.fecha_emision !== '0000-00-00' && receta.fecha_emision.trim() !== '') {
                        // Si viene en formato YYYY-MM-DD
                        if (typeof receta.fecha_emision === 'string' && receta.fecha_emision.includes('-')) {
                            const partes = receta.fecha_emision.split('-');
                            if (partes.length === 3 && partes[0] !== '0000') {
                                const [year, month, day] = partes;
                                fechaFormateada = `${day.padStart(2, '0')}/${month.padStart(2, '0')}/${year}`;
                            }
                        } else {
                            fechaFormateada = receta.fecha_emision;
                        }
                    }

                    // Si no se pudo formatear, usar fecha actual
                    if (!fechaFormateada) {
                        const hoy = new Date();
                        fechaFormateada = `${hoy.getDate().toString().padStart(2, '0')}/${(hoy.getMonth() + 1).toString().padStart(2, '0')}/${hoy.getFullYear()}`;
                    }
                    doc.text('Fecha: ' + fechaFormateada, 200, yPosProducto, {
                        align: 'right'
                    });

                    // Cantidad autorizada
                    const yPosCantidad = yPosProducto + 22;
                    doc.setFontSize(11);
                    doc.setFont('helvetica', 'normal');
                    const cantidadFrascos = receta.cantidad_frascos || 20;
                    const textoFrasco = cantidadFrascos === 1 ? 'frasco' : 'frascos';
                    doc.text('Cantidad autorizada: ' + cantidadFrascos + ' ' + textoFrasco, 20, yPosCantidad);

                    // Firmas
                    const yPosFirmas = yPosCantidad + 13;
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(10);
                    doc.text('Autorizó', 60, yPosFirmas, {
                        align: 'center'
                    });
                    doc.text('Recibió', 160, yPosFirmas, {
                        align: 'center'
                    });

                    const yPosLineasFirma = yPosFirmas + 9;
                    doc.setLineWidth(0.5);
                    doc.line(30, yPosLineasFirma, 90, yPosLineasFirma);
                    doc.line(130, yPosLineasFirma, 190, yPosLineasFirma);

                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(9);
                    doc.text('M.V.Z. JOSÉ ADOLFO ARAÚJO CÁRDENAS', 60, yPosLineasFirma + 5, {
                        align: 'center'
                    });
                    doc.text((receta.receptor_nombre || 'M.V.Z.'), 160, yPosLineasFirma + 5, {
                        align: 'center'
                    });

                    // Footer
                    const yPosLineaFooter = yPosLineasFirma + 16;
                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(12);
                    let nombreJuris = receta.jurisdiccion || 'JURISDICCIÓN';
                    const partes = nombreJuris.split(' ');
                    if (partes.length > 1 && !isNaN(partes[0])) {
                        nombreJuris = partes.slice(1).join(' ');
                    }
                    const textoJuris = 'JURISDICCIÓN SANITARIA ' + nombreJuris.toUpperCase();

                    let fontSize = 12;
                    doc.setFontSize(fontSize);
                    let anchoTexto = doc.getTextWidth(textoJuris);
                    while (anchoTexto > 180 && fontSize > 8) {
                        fontSize -= 0.5;
                        doc.setFontSize(fontSize);
                        anchoTexto = doc.getTextWidth(textoJuris);
                    }

                    doc.text(textoJuris, 110, yPosLineaFooter, {
                        align: 'center'
                    });

                    doc.setFont('helvetica', 'normal');
                    doc.setFontSize(8);
                    doc.text(receta.direccion || 'Dirección no especificada', 110, yPosLineaFooter + 5, {
                        align: 'center'
                    });

                    const yPosLineaAviso = yPosLineaFooter + 9;
                    doc.setLineWidth(0.8);
                    doc.line(20, yPosLineaAviso, 200, yPosLineaAviso);

                    doc.setFont('helvetica', 'bold');
                    doc.setFontSize(8);
                    doc.text('MATERIAL EXCLUSIVO PARA LAS ACTIVIDADES DE ESTERILIZACIÓN', 110, yPosLineaAviso + 4, {
                        align: 'center'
                    });
                    doc.text('QUIRÚRGICA GRATUITA DE PERROS Y GATOS DEL ISEM', 110, yPosLineaAviso + 8, {
                        align: 'center'
                    });

                    if (j === 0 && (i + 1) < recetas.length) {
                        const yPosLineaPunteada = yPosLineaAviso + 12;
                        doc.setLineWidth(0.5);
                        doc.setLineDashPattern([3, 3], 0);
                        doc.line(20, yPosLineaPunteada, 200, yPosLineaPunteada);
                        doc.setLineDashPattern([], 0);
                    }
                }
            }

            if (descargar) {
                const folioI = recetas[0].folio.toString().padStart(6, '0');
                const folioF = recetas[recetas.length - 1].folio.toString().padStart(6, '0');
                doc.save(`recetas_${folioI}_${folioF}.pdf`);
            } else {
                const pdfBlob = doc.output('blob');
                const pdfUrl = URL.createObjectURL(pdfBlob);
                document.getElementById('pdfPreviewFrame').src = pdfUrl;
                const modal = document.getElementById('modalPreviewPDF');
                const panel = document.getElementById('panelPreview');
                modal.style.display = 'block';
                setTimeout(() => {
                    panel.style.right = '0';
                }, 10);
            }
        }



        function cerrarPreviewPDF() {
            const panel = document.getElementById('panelPreview');
            const modal = document.getElementById('modalPreviewPDF');

            // Animar salida del panel
            panel.style.right = '-60%';

            // Esperar a que termine la animación antes de ocultar
            setTimeout(() => {
                const iframe = document.getElementById('pdfPreviewFrame');
                if (iframe.src) {
                    URL.revokeObjectURL(iframe.src);
                    iframe.src = '';
                }
                modal.style.display = 'none';

                // Si se editó una receta, preguntar si quiere recargar
                if (recetaEditada) {
                    const recargar = confirm('¿Deseas recargar la página para ver los cambios en el historial?');
                    if (recargar) {
                        recargarConVista();
                    } else {
                        // Resetear la bandera
                        recetaEditada = false;
                    }
                }
            }, 300);
        }

        // ========== MODAL EDITAR LOTE DE RECETAS V2 (CON SQL) ==========
        async function abrirModalEditarLoteV2(button) {
            const loteId = button.dataset.lote;

            if (!loteId) {
                alert('Error: No se pudo obtener el ID del lote');
                return;
            }

            const modal = document.getElementById('modalEditar');

            try {
                // Obtener datos frescos del lote desde la base de datos
                const response = await fetch(`obtener_lote_receta.php?lote_id=${encodeURIComponent(loteId)}`);
                const result = await response.json();

                console.log('Datos recibidos del servidor:', result); // DEBUG

                if (!result.success) {
                    alert('Error al cargar datos: ' + (result.error || 'Error desconocido'));
                    return;
                }

                const data = result.data;

                console.log('Datos a cargar en el modal:', data); // DEBUG

                // Limpiar el tipo de insumo
                const tipoLimpio = (data.tipo_insumo || 'GENERAL').replace(/['"]/g, '').trim();

                // Llenar los campos del formulario SIN disparar eventos onchange
                document.getElementById('edit_lote_id').value = data.lote_id;
                document.getElementById('edit_folio_display').textContent =
                    data.folio_inicio.toString().padStart(6, '0') +
                    (data.num_recetas > 1 ? ' - ' + data.folio_fin.toString().padStart(6, '0') : '');

                // Actualizar todos los spans con el número de recetas
                document.querySelectorAll('#edit_num_recetas').forEach(span => {
                    span.textContent = data.num_recetas;
                });

                document.getElementById('edit_jurisdiccion').value = data.jurisdiccion;

                // IMPORTANTE: Llenar receptor y cédula SIN disparar onchange
                // para evitar que autocompletarReceptor sobrescriba con datos cacheados
                const receptorInput = document.getElementById('edit_receptor');
                const cedulaInput = document.getElementById('edit_cedula');

                // Remover temporalmente el evento onchange
                const onchangeOriginal = receptorInput.onchange;
                receptorInput.onchange = null;

                // Llenar los valores
                receptorInput.value = data.receptor_nombre || '';
                cedulaInput.value = data.receptor_cedula || '';

                // Restaurar el evento onchange
                receptorInput.onchange = onchangeOriginal;

                document.getElementById('edit_producto').value = data.producto || '';
                document.getElementById('edit_fecha').value = data.fecha_emision || '';
                document.getElementById('edit_tipo_insumo').value = tipoLimpio;
                document.getElementById('edit_direccion').value = data.direccion || '';

                // MOSTRAR CANTIDAD TOTAL (ya viene sumada desde la BD)
                const cantidadTotal = data.cantidad_frascos || 20;
                document.getElementById('edit_cantidad').value = cantidadTotal;

                // Guardar datos para referencia
                modal.dataset.numRecetas = data.num_recetas;

                console.log('✓ Campos llenados - Receptor:', data.receptor_nombre, 'Cédula:', data.receptor_cedula, 'Cantidad total:', cantidadTotal); // DEBUG

                // Guardar datos para regenerar PDF
                modal.dataset.folioInicio = data.folio_inicio;
                modal.dataset.folioFin = data.folio_fin;
                modal.dataset.jurisdiccion = data.jurisdiccion;
                modal.dataset.tipoInsumo = tipoLimpio;

                // Llenar dirección correcta basada en la jurisdicción
                llenarDireccionEditar();

                modal.style.display = 'flex';

            } catch (error) {
                console.error('Error:', error);
                alert('Error al cargar los datos del lote');
            }
        }

        function cerrarModalEditar() {
            document.getElementById('modalEditar').style.display = 'none';
        }

        async function guardarEdicion(e) {
            e.preventDefault();
            const btn = document.getElementById('btnGuardarEdicion');
            const textoOriginal = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';

            try {
                const formData = new FormData(document.getElementById('formEditar'));
                const modal = document.getElementById('modalEditar');

                // La cantidad ya es el total, no necesita conversión
                const cantidadTotal = parseInt(formData.get('cantidad_frascos'));
                const numRecetas = parseInt(modal.dataset.numRecetas);

                console.log('Cantidad total a distribuir:', cantidadTotal);
                console.log('Número de recetas:', numRecetas);
                console.log('Distribución: 20 por receta, sobrante en la última');

                const res = await fetch('actualizar_receta_ajax.php', {
                    method: 'POST',
                    body: formData
                });

                const responseText = await res.text();

                // Si hay error de JSON, mostrar la respuesta completa
                let data;
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    alert('Error al procesar respuesta del servidor:\n\n' + responseText.substring(0, 500));
                    btn.disabled = false;
                    btn.innerHTML = textoOriginal;
                    return;
                }

                if (data.success) {
                    const modal = document.getElementById('modalEditar');
                    const folioInicio = modal.dataset.folioInicio;
                    const folioFin = modal.dataset.folioFin;
                    const jurisdiccion = modal.dataset.jurisdiccion;
                    const tipoInsumo = modal.dataset.tipoInsumo;

                    cerrarModalEditar();

                    // Marcar que se editó una receta
                    recetaEditada = true;

                    // Mostrar mensaje de éxito con opción de regenerar PDF
                    const mensaje = data.filas_afectadas > 1 ?
                        `✓ Se actualizaron ${data.filas_afectadas} recetas correctamente` :
                        '✓ Receta actualizada correctamente';
                    const regenerar = confirm(mensaje + '\n\n¿Deseas regenerar el PDF con los datos actualizados?');

                    if (regenerar) {
                        // Regenerar PDF y esperar a que termine
                        await verPDFDirecto(jurisdiccion, parseInt(folioInicio), parseInt(folioFin), tipoInsumo);
                        // No recargar automáticamente, esperar a que el usuario cierre el PDF
                    } else {
                        // Solo recargar si no se regeneró el PDF
                        setTimeout(() => recargarConVista(), 500);
                    }
                } else {
                    alert('Error: ' + (data.error || 'No se pudo actualizar'));
                    btn.disabled = false;
                    btn.innerHTML = textoOriginal;
                }
            } catch (err) {
                alert('Error de conexión: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = textoOriginal;
            }
        }

        // Cerrar modal con Escape o click en el fondo oscuro
        document.getElementById('modalPreviewPDF').addEventListener('click', function(e) {
            if (e.target === this) cerrarPreviewPDF();
        });
        document.getElementById('modalEditar').addEventListener('click', function(e) {
            if (e.target === this) cerrarModalEditar();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                cerrarPreviewPDF();
                cerrarModalEditar();
            }
        });
    </script>

    <style>
        .btn-header {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.2s;
            backdrop-filter: blur(10px);
        }

        .btn-header:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }

        .btn-header.activo {
            background: rgba(255, 255, 255, 0.9);
            color: #1e3a5f;
            font-weight: 600;
        }

        .btn-vista {
            background: #e2e8f0;
            color: #475569;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: all 0.2s;
        }

        .btn-vista:hover {
            background: #cbd5e1;
        }

        .btn-vista.activo {
            background: linear-gradient(135deg, #1e3a5f, #2d5a87);
            color: white;
        }

        select:focus,
        input:focus {
            border-color: #1e3a5f !important;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 58, 95, 0.15);
        }

        button:hover:not(:disabled) {
            transform: translateY(-1px);
        }

        @media(max-width:768px) {
            .page-header {
                flex-direction: column !important;
                text-align: center;
            }

            div[style*="grid-template-columns:1fr 1fr"] {
                grid-template-columns: 1fr !important;
            }

            div[style*="grid-template-columns:repeat(4"] {
                grid-template-columns: repeat(2, 1fr) !important;
            }

            div[style*="grid-template-columns:repeat(auto-fill"] {
                grid-template-columns: 1fr !important;
            }
        }
    </style>

    <?php include __DIR__ . '/partials/help_modal.php'; ?>
</body>

</html>