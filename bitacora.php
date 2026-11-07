<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

$rol = $_SESSION['rol'] ?? null;
if ($rol !== 'admin') {
    header('Location: panel.php');
    exit;
}

$jurisdicciones = jurisdicciones();

$modo = isset($_GET['modo']) ? trim($_GET['modo']) : 'todos';
$jurisdiccionSel = isset($_GET['jurisdiccion']) ? trim($_GET['jurisdiccion']) : '';
$municipioSel = isset($_GET['municipio']) ? trim($_GET['municipio']) : '';
$anioSel = isset($_GET['anio']) ? trim($_GET['anio']) : '';

// Obtener años disponibles en la bitácora
$anios_disponibles = [];
$res_anios = mysqli_query($conn, "SELECT DISTINCT YEAR(fecha) as anio FROM bitacora ORDER BY anio DESC");
while ($row = mysqli_fetch_assoc($res_anios)) {
    $anios_disponibles[] = $row['anio'];
}

$where = '';
$params = [];
$types = '';

if ($modo === 'juris' && $jurisdiccionSel !== '') {
    $where = 'WHERE u.jurisdiccion = ?';
    $params[] = $jurisdiccionSel;
    $types .= 's';
} elseif ($modo === 'juris_muni' && $jurisdiccionSel !== '' && $municipioSel !== '') {
    $where = 'WHERE u.jurisdiccion = ? AND (b.detalles LIKE ? OR b.detalles LIKE ?)';
    $params[] = $jurisdiccionSel;
    $params[] = '%municipio: ' . $municipioSel . '%';
    $params[] = '%' . $municipioSel . '%';
    $types .= 'sss';
}

// Agregar filtro de año
if ($anioSel !== '') {
    if ($where === '') {
        $where = 'WHERE YEAR(b.fecha) = ?';
    } else {
        $where .= ' AND YEAR(b.fecha) = ?';
    }
    $params[] = $anioSel;
    $types .= 'i';
}

$bitacora = [];
$sql = "SELECT b.fecha, b.accion, b.detalles, b.exito, u.nombre, u.jurisdiccion 
        FROM bitacora b 
        LEFT JOIN usuarios u ON u.id = b.usuario_id 
        $where 
        ORDER BY b.fecha DESC";

if ($conn) {
    $stmt = mysqli_prepare($conn, $sql);
    if ($stmt) {
        if ($types) {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($res)) {
            $bitacora[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Bitácora del Sistema</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="assets/css/app.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Estilos para headers de tabla */
        .table-modern thead {
            background: linear-gradient(135deg, #1e40af 0%, #2563eb 100%);
            color: white;
        }
        .table-modern thead th {
            padding: 14px 12px;
            font-weight: 600;
            font-size: 0.9rem;
            text-align: left;
            border-bottom: 2px solid #1e40af;
            color: white !important;
        }
        .table-modern tbody tr {
            border-bottom: 1px solid #e2e8f0;
        }
        .table-modern tbody td {
            padding: 12px;
            font-size: 0.875rem;
        }
        
        /* Estilos para el header de página */
        .page-header * {
            color: white !important;
        }
        
        /* Estilos para el botón de filtros */
        .btn-filtros {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }
        .btn-filtros:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        }
        
        .btn-pdf {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(220, 38, 38, 0.3);
        }
        .btn-pdf:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(220, 38, 38, 0.4);
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .btn-pdf:active {
            transform: translateY(0);
        }
        .btn-pdf i {
            font-size: 1.1rem;
        }
        
        /* RESPONSIVO */
        @media (max-width: 992px) {
            .table{font-size:0.85rem}
            .table th,.table td{padding:8px}
            .btn-pdf{padding:8px 12px;font-size:0.85rem}
        }
        @media (max-width: 576px) {
            .table{font-size:0.75rem}
            .table th,.table td{padding:6px 4px}
            .btn-pdf{width:100%;justify-content:center}
            .card-header{flex-direction:column;gap:0.5rem}
        }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
</head>
<body>
    <div class="app-shell">
    <?php include __DIR__ . '/partials/sidebar.php'; ?>
    <main class="app-main">
        <div class="page-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%); border-radius: 16px; padding: 1.5rem 2rem; color: white; margin-bottom: 2rem;">
            <h1 class="page-title" style="color: white; margin-bottom: 0.5rem;">
                <i class="fas fa-history me-2"></i>
                Bitácora del Sistema
            </h1>
            <p class="page-subtitle" style="color: rgba(255, 255, 255, 0.9); margin: 0;">Consulta todos los movimientos registrados. Filtra por jurisdicción y municipio y descarga en PDF.</p>
        </div>

            <section class="glass-card">
                <form method="get" class="form-grid" style="align-items:end;gap:12px;">
                    <div>
                        <label>Modo</label>
                        <select name="modo" onchange="onModoChange(this.value)">
                            <option value="todos" <?= $modo === 'todos' ? 'selected' : '' ?>>Todos</option>
                            <option value="juris" <?= $modo === 'juris' ? 'selected' : '' ?>>Jurisdicción</option>
                            <option value="juris_muni" <?= $modo === 'juris_muni' ? 'selected' : '' ?>>Jurisdicción y municipio</option>
                        </select>
                    </div>
                    <div id="wrapJuris">
                        <label>Jurisdicción</label>
                        <select name="jurisdiccion" id="jurisdiccion" <?= $modo === 'todos' ? 'disabled' : '' ?> onchange="cargarMunicipios()">
                            <option value="">Selecciona jurisdicción</option>
                            <?php foreach ($jurisdicciones as $j): ?>
                                <option value="<?= htmlspecialchars($j) ?>" <?= $jurisdiccionSel === $j ? 'selected' : '' ?>><?= htmlspecialchars($j) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="wrapMuni">
                        <label>Municipio</label>
                        <select name="municipio" id="municipio" <?= $modo === 'juris_muni' ? '' : 'disabled' ?>>
                            <option value="">Selecciona municipio</option>
                        </select>
                    </div>
                    <div>
                        <label>Año</label>
                        <select name="anio" id="anio">
                            <option value="">Todos los años</option>
                            <?php foreach ($anios_disponibles as $a): ?>
                                <option value="<?= $a ?>" <?= $anioSel == $a ? 'selected' : '' ?>><?= $a ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-filtros">
                            <i class="fas fa-filter me-2"></i>Aplicar filtros
                        </button>
                    </div>
                </form>
            </section>

            <section class="glass-card">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px;">
                    <h3 style="margin:0;">Movimientos registrados</h3>
                    <button type="button" class="btn-pdf" id="btnPdf" title="Descargar PDF">
                        <i class="fas fa-file-pdf"></i> Descargar PDF
                    </button>
                </div>
                <div style="overflow:auto;position:relative;">
                    <table class="table-modern" id="tablaBitacora">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Jurisdicción</th>
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
                                    <td><?= htmlspecialchars($row['jurisdiccion'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['accion']) ?></td>
                                    <td style="max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars($row['detalles'] ?? '') ?>
                                    </td>
                                    <td><?= (int)($row['exito'] ?? 0) === 1 ? 'Éxito' : 'Fallo' ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$bitacora): ?>
                                <tr><td colspan="6" style="text-align:center;color:var(--text-muted);">Sin registros</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
    </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
    <script>
        function onModoChange(val) {
            const juris = document.getElementById('jurisdiccion');
            const muni = document.getElementById('municipio');
            if (val === 'todos') {
                juris.disabled = true;
                muni.disabled = true;
                muni.innerHTML = '<option value="">Selecciona municipio</option>';
            } else if (val === 'juris') {
                juris.disabled = false;
                muni.disabled = true;
                muni.innerHTML = '<option value="">Selecciona municipio</option>';
            } else {
                juris.disabled = false;
                muni.disabled = false;
            }
        }

        function cargarMunicipios() {
            const modo = document.querySelector('select[name=modo]').value;
            const selJ = document.getElementById('jurisdiccion');
            const selM = document.getElementById('municipio');
            if (modo !== 'juris_muni') return;
            const juris = selJ.value;
            if (!juris) return;
            fetch('cargar_municipios.php?jurisdiccion=' + encodeURIComponent(juris))
                .then(r => r.text())
                .then(html => {
                    selM.innerHTML = html;
                    const hasOptions = /<option[^>]+value=\"(?!\")/i.test(html) || /<option[^>]*>[^<]+<\/option>/i.test(html);
                    if (!hasOptions || /No hay municipios/i.test(html)) {
                        selM.disabled = true;
                        selM.innerHTML = '<option value="">No hay municipios</option>';
                    } else {
                        selM.disabled = false;
                        selM.value = <?= json_encode($municipioSel) ?>;
                    }
                })
                .catch(() => {});
        }
        cargarMunicipios();

        // Esperar a que jsPDF cargue
        window.addEventListener('load', function() {
            const btnPdf = document.getElementById('btnPdf');
            if (!btnPdf) return;
            
            btnPdf.addEventListener('click', function() {
                if (typeof window.jspdf === 'undefined') {
                    alert('Error: La librería PDF no se cargó correctamente. Recarga la página.');
                    return;
                }
                
                const { jsPDF } = window.jspdf;
                const rowsNode = document.querySelectorAll('#tablaBitacora tbody tr');
                
                if (!rowsNode || rowsNode.length === 0 || (rowsNode.length === 1 && rowsNode[0].querySelector('td[colspan="6"]'))) {
                    alert('No hay datos para descargar.');
                    return;
                }
                
                try {
                    const doc = new jsPDF({ orientation: 'landscape', unit: 'pt', format: 'a4' });
                    const margin = 36;
                    const pageW = doc.internal.pageSize.getWidth();
                    const pageH = doc.internal.pageSize.getHeight();
                    const wAvail = pageW - margin * 2;
                    const cols = [0.12, 0.18, 0.2, 0.14, 0.28, 0.08].map(r => Math.floor(r * wAvail));
                    const headers = ['Fecha','Usuario','Jurisdicción','Acción','Detalles','Estado'];

                    // Header verde
                    doc.setFillColor(37, 99, 235);
                    doc.rect(0, 0, pageW, 35, 'F');
                    doc.setTextColor(255);
                    doc.setFont('helvetica','bold');
                    doc.setFontSize(14);
                    doc.text('Zoonosis - Bitácora del Sistema', margin, 24);
                    doc.setTextColor(0);

                    let y = 60;
                    doc.setFont('helvetica','normal');
                    doc.setFontSize(10);
                    const filtroModo = <?= json_encode($modo) ?>;
                    const filtroJuris = <?= json_encode($jurisdiccionSel) ?>;
                    const filtroMuni = <?= json_encode($municipioSel) ?>;
                    const filtroAnio = <?= json_encode($anioSel) ?>;
                    let subt = 'Filtro: ';
                    if (filtroModo === 'todos') {
                        subt += 'Todos los registros';
                    } else if (filtroModo === 'juris') {
                        subt += 'Jurisdicción: ' + filtroJuris;
                    } else {
                        subt += 'Jurisdicción: ' + filtroJuris + ' | Municipio: ' + filtroMuni;
                    }
                    if (filtroAnio) {
                        subt += ' | Año: ' + filtroAnio;
                    }
                    doc.text(subt, margin, y);
                    y += 20;

                    // Encabezados de tabla
                    doc.setFontSize(9);
                    doc.setFillColor(241, 245, 249);
                    doc.rect(margin, y, wAvail, 22, 'F');
                    doc.setTextColor(30, 41, 59);
                    doc.setFont('helvetica','bold');
                    let x = margin + 6;
                    headers.forEach((h, i) => { doc.text(h, x, y + 15); x += cols[i]; });
                    y += 26;

                    // Datos
                    const dataRows = Array.from(rowsNode).map(tr => Array.from(tr.children).slice(0,6).map(td => td.textContent.trim()));
                    doc.setFont('helvetica','normal');
                    doc.setTextColor(0);
                    let stripe = false;
                    const lineH = 11;
                    
                    for (const row of dataRows) {
                        const wrapped = row.map((cell, i) => doc.splitTextToSize(cell || '', cols[i] - 10));
                        const maxLines = Math.max(...wrapped.map(w => w.length));
                        const rowH = Math.max(18, maxLines * lineH + 6);
                        
                        if (y + rowH > pageH - margin) {
                            doc.addPage();
                            y = margin;
                            doc.setFont('helvetica','bold');
                            doc.setFontSize(9);
                            doc.setFillColor(241, 245, 249);
                            doc.rect(margin, y, wAvail, 22, 'F');
                            doc.setTextColor(30, 41, 59);
                            let xh = margin + 6;
                            headers.forEach((h, i) => { doc.text(h, xh, y + 15); xh += cols[i]; });
                            y += 26;
                            doc.setFont('helvetica','normal');
                            doc.setTextColor(0);
                        }
                        
                        if (stripe) {
                            doc.setFillColor(248, 250, 252);
                            doc.rect(margin, y - 2, wAvail, rowH, 'F');
                        }
                        
                        let xc = margin + 6;
                        for (let i = 0; i < wrapped.length; i++) {
                            const lines = wrapped[i];
                            let yy = y + 11;
                            lines.forEach(l => { doc.text(l, xc, yy); yy += lineH; });
                            xc += cols[i];
                        }
                        
                        doc.setDrawColor(226, 232, 240);
                        doc.line(margin, y + rowH, margin + wAvail, y + rowH);
                        y += rowH;
                        stripe = !stripe;
                    }
                    
                    // Pie de página
                    const pages = doc.getNumberOfPages();
                    const stamp = new Date().toLocaleString('es-MX');
                    for (let p = 1; p <= pages; p++) {
                        doc.setPage(p);
                        doc.setFontSize(8);
                        doc.setTextColor(100);
                        doc.text('Generado: ' + stamp, margin, pageH - 20);
                        doc.text('Página ' + p + ' de ' + pages, pageW - margin - 60, pageH - 20);
                    }
                    
                    doc.save('bitacora_' + new Date().toISOString().slice(0,10) + '.pdf');
                } catch (e) {
                    console.error('Error generando PDF:', e);
                    alert('Error al generar el PDF: ' + e.message);
                }
            });
        });
    </script>
</body>
</html>

