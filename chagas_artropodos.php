<?php

/**
 * MÓDULO DE ENFERMEDAD DE CHAGAS E INTOXICACIÓN POR ARTRÓPODOS
 * Vista dual: Formulario de captura | Tabla de seguimiento
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/optimizacion.php';
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/config_excel.php';

establecerHeadersNoCache();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

$rol = $_SESSION['rol'];
$usuario_id = $_SESSION['usuario_id'];
$jurisdiccion_usuario = $_SESSION['jurisdiccion'] ?? '';
$esAdmin = ($rol === 'admin');

$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = file_exists($config_file) ? json_decode(file_get_contents($config_file), true) : [];
$anioActual = $config_sistema['anio_activo'] ?? date('Y');

$anioSel = isset($_GET['anio']) ? (int)$_GET['anio'] : $anioActual;
$vista = isset($_GET['vista']) ? $_GET['vista'] : 'captura';

$todasJurisdicciones = jurisdicciones();
$mesesCompletos = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

// Obtener registros
$datos = [];
$mesMaximo = 1;

$sql = "SELECT * FROM chagas_registro WHERE anio = ? ORDER BY jurisdiccion, mes";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $anioSel);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);

while ($row = mysqli_fetch_assoc($res)) {
    $jur = $row['jurisdiccion'];
    $mes = (int)$row['mes'];
    if (!isset($datos[$jur])) $datos[$jur] = [];
    $datos[$jur][$mes] = $row;
    if ($mes > $mesMaximo) $mesMaximo = $mes;
}

// Remanentes año anterior
$anioAnterior = $anioSel - 1;
$sql = "SELECT jurisdiccion, remanente FROM chagas_registro WHERE anio = ? AND mes = 12";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'i', $anioAnterior);
mysqli_stmt_execute($stmt);
$res = mysqli_stmt_get_result($stmt);
$remanentesAnoAnterior = [];
while ($row = mysqli_fetch_assoc($res)) {
    $remanentesAnoAnterior[$row['jurisdiccion']] = (int)$row['remanente'];
}

// Mes seleccionado por defecto
$mesSeleccionado = 1;
if (!$esAdmin && isset($datos[$jurisdiccion_usuario])) {
    $mesesRegistrados = array_keys($datos[$jurisdiccion_usuario]);
    if (!empty($mesesRegistrados)) {
        $ultimoMes = max($mesesRegistrados);
        $mesSeleccionado = ($ultimoMes < 12) ? $ultimoMes + 1 : 12;
    }
} elseif ($esAdmin) {
    $mesSeleccionado = ($mesMaximo < 12) ? $mesMaximo + 1 : 12;
}

// Calcular totales REALES para las tarjetas (FIX CORRECTO)
$totalEntregadoGlobal = 0;
$totalUtilizadoGlobal = 0;
$totalRemanenteGlobal = 0;

foreach ($todasJurisdicciones as $jur) {
    if (!$esAdmin && $jur !== $jurisdiccion_usuario) continue;

    $jurData = $datos[$jur] ?? [];
    $remanentePrevio = $remanentesAnoAnterior[$jur] ?? 0;
    $ultimoRemanenteJur = $remanentePrevio;

    foreach ($jurData as $mes => $reg) {
        $ent = (int)$reg['entregado'];
        $util = (int)$reg['utilizado'];
        $totalEntregadoGlobal += $ent;
        $totalUtilizadoGlobal += $util;
        $ultimoRemanenteJur = $remanentePrevio + $ent - $util;
        $remanentePrevio = $ultimoRemanenteJur;
    }
    $totalRemanenteGlobal += $ultimoRemanenteJur;
}

$mensaje = $_SESSION['mensaje'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

// Para JS
$datosJS = [];
foreach ($datos as $jur => $mesesData) {
    $datosJS[$jur] = [];
    foreach ($mesesData as $mes => $reg) {
        $datosJS[$jur][$mes] = [
            'id' => $reg['id'],
            'mes' => $mes,
            'mes_nombre' => $mesesCompletos[$mes],
            'entregado' => (int)$reg['entregado'],
            'utilizado' => (int)$reg['utilizado'],
            'observaciones' => $reg['observaciones']
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chagas y Artrópodos - Zoonosis</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= time() ?>">
    <link rel="stylesheet" href="assets/css/dark-mode.css?v=<?= time() ?>">
    <style>
        .page-header {
            background: linear-gradient(135deg, #1e40af 0%, #3b82f6 100%);
            border-radius: 16px;
            padding: 1.5rem 2rem;
            color: white;
            margin-bottom: 2rem;
            box-shadow: 0 8px 30px rgba(30, 64, 175, 0.2);
        }

        .page-header h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.5rem;
            font-weight: 700;
            color: white;
        }

        .page-header p {
            margin: 0;
            opacity: 0.9;
            font-size: 0.9rem;
            color: white;
        }

        .main-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px 20px 20px;
        }

        .controls-card {
            background: #fff;
            border-radius: 16px;
            padding: 1rem 1.5rem;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, .05);
            display: flex;
            gap: 16px;
            align-items: center;
            border: 1px solid #e2e8f0;
        }

        .btn-vista {
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            cursor: pointer;
            font-weight: 600;
            transition: all .2s;
            color: #475569;
        }

        .btn-vista.active {
            background: #2563eb;
            color: #fff;
            border-color: #2563eb;
            box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2);
        }

        .year-select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-weight: 600;
            background: white;
        }

        /* Split View Dashboard */
        .split-container {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 0;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }

        .split-left {
            padding: 2.5rem;
            background: white;
        }

        .split-right {
            padding: 2.5rem 2rem;
            background: #f8fafc;
            border-left: 1px solid #e2e8f0;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .field-label {
            font-size: 0.875rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 0.5rem;
            display: block;
        }

        .field-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background: white;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            transition: border-color 0.2s;
            font-weight: 500;
        }

        .field-input:focus {
            border-color: #3b82f6;
            outline: none;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .summary-card {
            padding: 1.25rem;
            border-radius: 14px;
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        }

        .summary-card.green {
            background: linear-gradient(135deg, #059669, #10b981);
        }

        .summary-card.orange {
            background: linear-gradient(135deg, #d97706, #f59e0b);
        }

        .summary-card.blue {
            background: linear-gradient(135deg, #1d4ed8, #3b82f6);
        }

        .summary-value {
            font-size: 1.75rem;
            font-weight: 800;
            margin-top: 0.25rem;
        }

        /* Tabla Estilo Excel */
        .excel-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .8rem;
            min-width: 1800px;
        }

        .excel-table th {
            background: #1e40af;
            color: #fff;
            padding: 10px 6px;
            text-align: center;
            border: 1px solid #111827;
            font-weight: 600;
        }

        .excel-table td {
            padding: 8px 6px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .col-jur {
            background: #f1f5f9;
            text-align: left;
            font-weight: 700;
            position: sticky;
            left: 0;
            z-index: 10;
        }

        .row-total {
            background: #dbeafe;
            font-weight: 800;
        }

        /* Headers Institucionales */
        .inst-header {
            text-align: center;
            font-weight: 700;
            color: #64748b;
            margin: 0;
            font-size: 0.8rem;
            text-transform: uppercase;
        }

        .inst-title {
            text-align: center;
            font-weight: 800;
            color: #1e3a8a;
            margin: 1rem 0 2rem 0;
            font-size: 1.25rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php include __DIR__ . '/partials/sidebar.php'; ?>
        <main class="app-main">
            <div class="page-header">
                <h1 class="page-title"><i class="fas fa-bug"></i> Chagas y Artrópodos</h1>
                <p style="opacity: 0.9">Programa de Prevención y Control de Enfermedad de Chagas e Intoxicación por Artrópodos</p>
            </div>

            <?php if ($mensaje): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($mensaje) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
            <?php if ($error): ?><div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

            <div class="main-container">
                <div class="controls-card">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-weight: 600;">Año:</label>
                        <select class="year-select" onchange="window.location.href='?anio='+this.value">
                            <?php for ($y = date('Y'); $y >= 2020; $y--): ?><option value="<?= $y ?>" <?= $y == $anioSel ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div style="display:flex;gap:8px;margin-left:auto;">
                        <button class="btn-vista <?= $vista === 'captura' ? 'active' : '' ?>" onclick="window.location.href='?anio=<?= $anioSel ?>&vista=captura'">Capturar</button>
                        <button class="btn-vista <?= $vista === 'tabla' ? 'active' : '' ?>" onclick="window.location.href='?anio=<?= $anioSel ?>&vista=tabla'">Ver Tabla</button>
                        <button class="btn btn-success" onclick="window.location.href='export_chagas_excel.php?anio=<?= $anioSel ?>'">Exportar Excel</button>
                    </div>
                </div>

                <?php if ($vista === 'captura'): ?>
                    <div class="split-container">
                        <div class="split-left">
                            <form method="POST" action="guardar_chagas.php">
                                <input type="hidden" name="accion" value="crear">
                                <input type="hidden" name="anio" value="<?= $anioSel ?>">

                                <h2 style="font-weight: 700; margin-bottom: 2rem;">Registro de Actividades</h2>

                                <div class="field-group">
                                    <label class="field-label">Jurisdicción</label>
                                    <?php if ($esAdmin): ?>
                                        <select name="jurisdiccion" class="field-input" required>
                                            <option value="">Seleccione...</option>
                                            <?php foreach ($todasJurisdicciones as $jur): ?><option value="<?= $jur ?>"><?= $jur ?></option><?php endforeach; ?>
                                        </select>
                                    <?php else: ?>
                                        <div class="field-input" style="background:#e2e8f0; font-weight:700"><?= $jurisdiccion_usuario ?></div>
                                        <input type="hidden" name="jurisdiccion" value="<?= $jurisdiccion_usuario ?>">
                                    <?php endif; ?>
                                </div>

                                <div class="field-group">
                                    <label class="field-label">Mes</label>
                                    <select name="mes" class="field-input" required>
                                        <?php
                                        $mesSiguienteCapturista = 1;
                                        if (!$esAdmin) {
                                            $mesesReg = isset($datos[$jurisdiccion_usuario]) ? array_keys($datos[$jurisdiccion_usuario]) : [];
                                            if (!empty($mesesReg)) {
                                                $maxM = max($mesesReg);
                                                $mesSiguienteCapturista = ($maxM < 12) ? $maxM + 1 : 13; // 13 means all filled
                                            }
                                        }

                                        foreach ($mesesCompletos as $num => $nombre):
                                            $disabled = '';
                                            $selected = ($num == $mesSeleccionado) ? 'selected' : '';

                                            if (!$esAdmin) {
                                                // Bloqueo secuencial para capturistas
                                                if ($num != $mesSiguienteCapturista) {
                                                    $disabled = 'disabled';
                                                }
                                            }
                                        ?>
                                            <option value="<?= $num ?>" <?= $selected ?> <?= $disabled ?>><?= $nombre ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!$esAdmin && $mesSiguienteCapturista > 12): ?>
                                        <small class="text-success" style="font-weight:600"><i class="fas fa-check-circle"></i> Todos los meses han sido registrados.</small>
                                    <?php endif; ?>
                                </div>

                                <div class="row">
                                    <?php if ($esAdmin): ?>
                                        <div class="col-12">
                                            <div class="field-group">
                                                <label class="field-label">Entregado</label>
                                                <input type="number" name="entregado" class="field-input" min="0" value="0">
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="col-12">
                                            <div class="field-group">
                                                <label class="field-label">Utilizado</label>
                                                <input type="number" name="utilizado" class="field-input" min="0" value="0" required>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!$esAdmin): ?>
                                    <div class="field-group">
                                        <label class="field-label">Observaciones</label>
                                        <textarea name="observaciones" class="field-input" style="height: 100px" placeholder="Escriba aquí los detalles del registro..."></textarea>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="btn btn-primary w-100 py-3 mt-3" style="border-radius:12px; font-weight:700">Guardar Registro</button>
                            </form>
                        </div>
                        <div class="split-right">
                            <h3 style="font-weight: 700; font-size: 1.2rem">Resumen Anual</h3>
                            <div class="summary-card green"><small>Total Entregado</small>
                                <div class="summary-value"><?= number_format($totalEntregadoGlobal) ?></div>
                            </div>
                            <div class="summary-card orange"><small>Total Utilizado</small>
                                <div class="summary-value"><?= number_format($totalUtilizadoGlobal) ?></div>
                            </div>
                            <div class="summary-card blue"><small>Remanente Actual</small>
                                <div class="summary-value"><?= number_format($totalRemanenteGlobal) ?></div>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <h2 class="inst-title">ESTADÍSTICA DE ENFERMEDAD DE CHAGAS E INTOXICACIÓN POR ARTRÓPODOS - <?= $anioSel ?></h2>

                    <div class="table-responsive">
                        <table class="excel-table">
                            <thead>
                                <tr>
                                    <th rowspan="2">Jurisdicción</th>
                                    <?php for ($m = 1; $m <= 12; $m++): ?><th colspan="3"><?= $mesesCompletos[$m] ?></th><?php endfor; ?>
                                    <th colspan="3">TOTALES ANUALES</th>
                                    <?php if ($esAdmin): ?><th rowspan="2">Acción</th><?php endif; ?>
                                </tr>
                                <tr>
                                    <?php for ($m = 1; $m <= 12; $m++): ?><th>Ent.</th>
                                        <th>Util.</th>
                                        <th>Rem.</th><?php endfor; ?>
                                    <th>Ent.</th>
                                    <th>Util.</th>
                                    <th>Rem.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $granTotEnt = 0;
                                $granTotUtil = 0;
                                $granTotRem = 0;
                                foreach ($todasJurisdicciones as $jur):
                                    if (!$esAdmin && $jur !== $jurisdiccion_usuario) continue;
                                    $jurData = $datos[$jur] ?? [];
                                    $remPrev = $remanentesAnoAnterior[$jur] ?? 0;
                                    $tEnt = 0;
                                    $tUtil = 0;
                                ?>
                                    <tr>
                                        <td class="col-jur"><?= $jur ?></td>
                                        <?php for ($m = 1; $m <= 12; $m++):
                                            $reg = $jurData[$m] ?? null;
                                            if ($reg):
                                                $e = (int)$reg['entregado'];
                                                $u = (int)$reg['utilizado'];
                                                $rem = $remPrev + $e - $u;
                                                $remPrev = $rem;
                                                $tEnt += $e;
                                                $tUtil += $u;
                                        ?>
                                                <td><?= $e ?></td>
                                                <td><?= $u ?></td>
                                                <td style="font-weight:700"><?= $rem ?></td>
                                            <?php else: ?><td>-</td>
                                                <td>-</td>
                                                <td>-</td><?php endif;
                                                    endfor; ?>
                                        <td style="background:#f1f5f9"><?= $tEnt ?></td>
                                        <td style="background:#f1f5f9"><?= $tUtil ?></td>
                                        <td style="background:#e0f2fe; font-weight:800"><?= $remPrev ?></td>
                                        <?php if ($esAdmin): ?>
                                            <td>
                                                <?php if (!empty($jurData)): ?>
                                                    <button class="btn btn-sm btn-outline-primary" onclick="abrirEditar('<?= $jur ?>')"><i class="fas fa-edit"></i></button>
                                                <?php endif; ?>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php
                                    $granTotEnt += $tEnt;
                                    $granTotUtil += $tUtil;
                                    $granTotRem += $remPrev;
                                endforeach; ?>
                                <tr class="row-total">
                                    <td>TOTALES ESTATALES</td>
                                    <?php for ($m = 1; $m <= 12; $m++):
                                        $mEnt = 0;
                                        $mUtil = 0;
                                        $mRem = 0;
                                        foreach ($todasJurisdicciones as $j) {
                                            if (isset($datos[$j][$m])) {
                                                $mEnt += $datos[$j][$m]['entregado'];
                                                $mUtil += $datos[$j][$m]['utilizado'];
                                                $mRem += $datos[$j][$m]['remanente'];
                                            } else {
                                                // Si no hay registro este mes, sumamos el remanente anterior (esto es complejo para el total estatal mensual)
                                                // En este formato, usualmente solo se suman las columnas de actividad.
                                            }
                                        }
                                    ?>
                                        <td><?= $mEnt ?></td>
                                        <td><?= $mUtil ?></td>
                                        <td>-</td>
                                    <?php endfor; ?>
                                    <td><?= $granTotEnt ?></td>
                                    <td><?= $granTotUtil ?></td>
                                    <td><?= $granTotRem ?></td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
            </div>
        <?php endif; ?>
    </div>
    </main>
    </div>

    <!-- Modal Editar -->
    <div class="modal fade" id="modalEditar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Editar Registro - <span id="editJurTitle"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="mesesEditContainer" class="d-flex flex-wrap gap-2 mb-3"></div>
                    <form id="formEditar" method="POST" action="guardar_chagas.php" style="display:none">
                        <input type="hidden" name="accion" value="editar">
                        <input type="hidden" name="id" id="editId">
                        <input type="hidden" name="anio" value="<?= $anioSel ?>">
                        <input type="hidden" name="jurisdiccion" id="editJur">
                        <input type="hidden" name="mes" id="editMes">

                        <div class="mb-3">
                            <label class="form-label">Entregado</label>
                            <input type="number" name="entregado" id="editEnt" class="form-control" min="0">
                        </div>
                        <?php if (!$esAdmin): ?>
                            <div class="mb-3">
                                <label class="form-label">Utilizado</label>
                                <input type="number" name="utilizado" id="editUtil" class="form-control" min="0" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Observaciones</label>
                                <textarea name="observaciones" id="editObs" class="form-control" rows="3"></textarea>
                            </div>
                        <?php endif; ?>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary flex-grow-1">Guardar Cambios</button>
                            <?php if ($esAdmin): ?>
                                <button type="button" class="btn btn-danger" onclick="eliminarRegistro()"><i class="fas fa-trash"></i> Eliminar</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const datosJS = <?= json_encode($datosJS) ?>;
        const esAdmin = <?= $esAdmin ? 'true' : 'false' ?>;

        function abrirEditar(jur) {
            const jurData = datosJS[jur] || {};
            document.getElementById('editJurTitle').innerText = jur;
            const container = document.getElementById('mesesEditContainer');
            container.innerHTML = '';

            Object.keys(jurData).forEach(m => {
                const btn = document.createElement('button');
                btn.className = 'btn btn-outline-secondary btn-sm';
                btn.innerText = jurData[m].mes_nombre;
                btn.onclick = () => cargarMesEditar(jur, m);
                container.appendChild(btn);
            });

            document.getElementById('formEditar').style.display = 'none';
            new bootstrap.Modal(document.getElementById('modalEditar')).show();
        }

        function cargarMesEditar(jur, mes) {
            const d = datosJS[jur][mes];
            document.getElementById('editId').value = d.id;
            document.getElementById('editJur').value = jur;
            document.getElementById('editMes').value = mes;
            document.getElementById('editEnt').value = d.entregado;
            document.getElementById('editUtil').value = d.utilizado;
            document.getElementById('editObs').value = d.observaciones || '';
            document.getElementById('formEditar').style.display = 'block';
        }

        function eliminarRegistro() {
            if (confirm('¿Está seguro de eliminar este registro?')) {
                const form = document.getElementById('formEditar');
                form.querySelector('[name="accion"]').value = 'eliminar';
                form.submit();
            }
        }
    </script>
</body>

</html>