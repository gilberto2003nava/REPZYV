<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Cargar año activo del config
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

$usuarioId = (int)$_SESSION['usuario_id'];
$rol = $_SESSION['rol'] ?? 'capturista';

// Manejar acciones especiales
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Acción: Obtener años disponibles
if ($action === 'get_years') {
    $sql = "SELECT DISTINCT anio FROM registros ORDER BY anio DESC";
    $result = mysqli_query($conn, $sql);
    $years = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $years[] = (int)$row['anio'];
    }
    echo json_encode(['years' => $years]);
    exit;
}

// Acción: Obtener jurisdicciones disponibles
if ($action === 'get_jurisdicciones') {
    $sql = "SELECT DISTINCT jurisdiccion FROM registros WHERE jurisdiccion IS NOT NULL AND jurisdiccion != '' ORDER BY CAST(SUBSTRING_INDEX(jurisdiccion, ' ', 1) AS UNSIGNED)";
    $result = mysqli_query($conn, $sql);
    $jurisdicciones = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $jurisdicciones[] = $row['jurisdiccion'];
    }
    echo json_encode(['jurisdicciones' => $jurisdicciones]);
    exit;
}

// Acción: Obtener municipios por jurisdicción
if ($action === 'get_municipios') {
    $jurisdiccion = isset($_GET['jurisdiccion']) ? trim($_GET['jurisdiccion']) : '';
    $sql = "SELECT DISTINCT municipio FROM registros WHERE jurisdiccion = ? AND municipio IS NOT NULL AND municipio != '' ORDER BY municipio";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 's', $jurisdiccion);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $municipios = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $municipios[] = $row['municipio'];
    }
    echo json_encode(['municipios' => $municipios]);
    exit;
}

// Handle 'anio' input (single, comma-separated, or array)
$aniosInput = isset($_GET['anio']) ? $_GET['anio'] : $anio_activo;
$anios = [];

if (is_array($aniosInput)) {
    $anios = array_map('intval', $aniosInput);
} elseif (is_string($aniosInput)) {
    if (strpos($aniosInput, ',') !== false) {
        $anios = array_map('intval', explode(',', $aniosInput));
    } else {
        $anios = [(int)$aniosInput];
    }
} else {
    $anios = [(int)$aniosInput];
}
// Default to active year if empty
if (empty($anios)) {
    $anios = [(int)$anio_activo];
}

$jurisdiccionSel = isset($_GET['jurisdiccion']) ? trim($_GET['jurisdiccion']) : '';
$municipioSel = isset($_GET['municipio']) ? trim($_GET['municipio']) : '';

$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
$columnasMeses = implode(',', array_map(fn($m) => "r.$m", $meses));

$types = ['vacunados', 'esterilizados', 'control', 'muestras', 'agredidos', 'tratamientos'];
$titles = [
    'vacunados' => 'ANIMALES VACUNADOS',
    'esterilizados' => 'ANIMALES ESTERILIZADOS',
    'control' => 'ANIMALES EN CONTROL',
    'muestras' => 'MUESTRAS ANTIRRÁBICAS',
    'agredidos' => 'PERSONAS AGREDIDAS',
    'tratamientos' => 'TRATAMIENTOS ANTIRRÁBICOS'
];

$sheetsData = [];
$evaluacionPorTipo = [];
$granTotalGlobalEval = ['meta' => 0, 'avance' => 0];
$evaluacionGlobal = [];

foreach ($types as $tipo) {
    // Build query - Use LEFT JOIN to include metas without registros
    $conds = ['m.tipo = ?'];
    $params = [$tipo];
    $typesStr = 's';

    // Add years condition
    if (!empty($anios)) {
        $placeholders = implode(',', array_fill(0, count($anios), '?'));
        $conds[] = "m.anio IN ($placeholders)";
        foreach ($anios as $a) {
            $params[] = $a;
            $typesStr .= 'i';
        }
    }

    if ($rol !== 'admin') {
        $conds[] = 'm.jurisdiccion = ?';
        $params[] = $_SESSION['jurisdiccion'] ?? '';
        $typesStr .= 's';
    }
    if ($jurisdiccionSel !== '') {
        $conds[] = 'm.jurisdiccion = ?';
        $params[] = $jurisdiccionSel;
        $typesStr .= 's';
    }
    if ($municipioSel !== '') {
        $conds[] = 'm.municipio = ?';
        $params[] = $municipioSel;
        $typesStr .= 's';
    }

    // LEFT JOIN: metas as base, registros as optional
    // This ensures municipalities with metas but no captures are included
    $sql = "SELECT 
                m.anio, 
                m.tipo, 
                m.jurisdiccion, 
                m.municipio, 
                m.meta as meta_anual,
                COALESCE(r.ene, 0) as ene,
                COALESCE(r.feb, 0) as feb,
                COALESCE(r.mar, 0) as mar,
                COALESCE(r.abr, 0) as abr,
                COALESCE(r.may, 0) as may,
                COALESCE(r.jun, 0) as jun,
                COALESCE(r.jul, 0) as jul,
                COALESCE(r.ago, 0) as ago,
                COALESCE(r.sep, 0) as sep,
                COALESCE(r.oct, 0) as oct,
                COALESCE(r.nov, 0) as nov,
                COALESCE(r.dic, 0) as dic
            FROM metas m
            LEFT JOIN registros r ON (
                m.jurisdiccion = r.jurisdiccion 
                AND m.municipio = r.municipio 
                AND m.tipo = r.tipo 
                AND m.anio = r.anio
            )
            WHERE " . implode(' AND ', $conds) . " 
            ORDER BY CAST(SUBSTRING_INDEX(m.jurisdiccion, ' ', 1) AS UNSIGNED), m.anio DESC, m.municipio";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $typesStr, ...$params);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $filas = mysqli_fetch_all($res, MYSQLI_ASSOC);

    // Process Data
    $groups = [];
    $granTotalMeta = 0;
    $granTotalMeses = array_fill_keys($meses, 0);
    $granTotalVertical = 0;

    // Evaluacion for this specific type
    $evalType = [];

    foreach ($filas as $f) {
        $j = $f['jurisdiccion'] ?: 'Sin jurisdicción';

        if (!isset($groups[$j])) {
            $groups[$j] = [
                'rows' => [],
                'subtotal' => [
                    'meta' => 0,
                    'meses' => array_fill_keys($meses, 0),
                    'total_vertical' => 0
                ]
            ];
        }

        $totalVertical = 0;
        foreach ($meses as $m) {
            $val = (int)($f[$m] ?? 0);
            $totalVertical += $val;
            $groups[$j]['subtotal']['meses'][$m] += $val;
        }
        $f['total_vertical'] = $totalVertical;
        $meta = (int)$f['meta_anual'];
        $f['logro_pct'] = $meta > 0 ? round(min(100, ($totalVertical / $meta) * 100), 2) : 0;

        // Add row
        $groups[$j]['rows'][] = $f;

        // Add to subtotal
        $groups[$j]['subtotal']['meta'] += $meta;
        $groups[$j]['subtotal']['total_vertical'] += $totalVertical;
    }

    foreach ($groups as $j => &$g) {
        $subMeta = $g['subtotal']['meta'];
        $subTotal = $g['subtotal']['total_vertical'];
        $g['subtotal']['logro_pct'] = $subMeta > 0 ? round(min(100, ($subTotal / $subMeta) * 100), 2) : 0;

        $granTotalMeta += $subMeta;
        $granTotalVertical += $subTotal;
        foreach ($meses as $m) {
            $granTotalMeses[$m] += $g['subtotal']['meses'][$m];
        }

        // Per Type Evaluation (Aggregated across selected years) - Include monthly data
        if (!isset($evalType[$j])) {
            $evalType[$j] = [
                'meta' => 0, 
                'avance' => 0,
                'meses' => array_fill_keys($meses, 0)
            ];
        }
        $evalType[$j]['meta'] += $subMeta;
        $evalType[$j]['avance'] += $subTotal;
        foreach ($meses as $m) {
            $evalType[$j]['meses'][$m] += $g['subtotal']['meses'][$m];
        }

        // Global Evaluation Accumulation
        if (!isset($evaluacionGlobal[$j])) $evaluacionGlobal[$j] = ['meta' => 0, 'avance' => 0];
        $evaluacionGlobal[$j]['meta'] += $subMeta;
        $evaluacionGlobal[$j]['avance'] += $subTotal;
    }
    unset($g);

    $granLogro = $granTotalMeta > 0 ? round(min(100, ($granTotalVertical / $granTotalMeta) * 100), 2) : 0;

    // Use simplified title if multiple years 
    $titleSuffix = (count($anios) === 1) ? $anios[0] : implode('-', $anios);

    $sheetsData[$tipo] = [
        'title' => $titles[$tipo] . " $titleSuffix",
        'groups' => $groups,
        'granTotal' => [
            'meta' => $granTotalMeta,
            'meses' => $granTotalMeses,
            'total_vertical' => $granTotalVertical,
            'logro_pct' => $granLogro
        ]
    ];

    // Format Eval Type Data - Include monthly data like original Excel
    $evalTypeFinal = [];
    $totalEvalType = ['meta' => 0, 'avance' => 0, 'meses' => array_fill_keys($meses, 0)];
    foreach ($evalType as $j => $d) {
        $l = $d['meta'] > 0 ? round(min(100, ($d['avance'] / $d['meta']) * 100), 2) : 0;
        $row = [
            'jurisdiccion' => $j, 
            'meta' => $d['meta'], 
            'avance' => $d['avance'], 
            'logro_pct' => $l
        ];
        // Add monthly data
        foreach ($meses as $m) {
            $row[$m] = $d['meses'][$m];
            $totalEvalType['meses'][$m] += $d['meses'][$m];
        }
        $evalTypeFinal[] = $row;
        $totalEvalType['meta'] += $d['meta'];
        $totalEvalType['avance'] += $d['avance'];
    }
    $totalEvalType['logro_pct'] = $totalEvalType['meta'] > 0 ? round(min(100, ($totalEvalType['avance'] / $totalEvalType['meta']) * 100), 2) : 0;

    $evaluacionPorTipo[$tipo] = [
        'title' => $titles[$tipo],
        'data' => $evalTypeFinal,
        'total' => $totalEvalType
    ];
}

// Finalize Global Evaluation Data
$evaluacionFinal = [];
foreach ($evaluacionGlobal as $j => $data) {
    $logro = $data['meta'] > 0 ? round(min(100, ($data['avance'] / $data['meta']) * 100), 2) : 0;
    $evaluacionFinal[] = [
        'jurisdiccion' => $j,
        'meta' => $data['meta'],
        'avance' => $data['avance'],
        'logro_pct' => $logro
    ];
    $granTotalGlobalEval['meta'] += $data['meta'];
    $granTotalGlobalEval['avance'] += $data['avance'];
}
$granTotalGlobalEval['logro_pct'] = $granTotalGlobalEval['meta'] > 0 ? round(min(100, ($granTotalGlobalEval['avance'] / $granTotalGlobalEval['meta']) * 100), 2) : 0;


$response = [
    'anios' => $anios,
    'meses' => $meses,
    'sheets' => $sheetsData,
    'evaluacionPorTipo' => $evaluacionPorTipo,
    'evaluacionGlobal' => [
        'title' => 'CONCENTRADO ESTATAL',
        'data' => $evaluacionFinal,
        'total' => $granTotalGlobalEval
    ]
];

echo json_encode($response);
