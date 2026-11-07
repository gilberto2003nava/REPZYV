<?php
/**
 * EXPORTADOR EXCEL COMPLETO - COLATERALES
 * Genera archivo Excel con formato idéntico al original
 * Incluye 6 hojas de datos + 1 hoja de evaluación estatal
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/includes/conexion.php';
require_once __DIR__ . '/includes/funciones.php';
require_once __DIR__ . '/includes/config_excel.php';
require_once __DIR__ . '/includes/calculadora_colaterales.php';

if (!isset($_SESSION['usuario_id'])) {
    header('Location: inicio.php');
    exit;
}

// Cargar año activo del config
$config_file = __DIR__ . '/config_sistema.json';
$config_sistema = [];
if (file_exists($config_file)) {
    $config_sistema = json_decode(file_get_contents($config_file), true) ?? [];
}
$anio_activo = $config_sistema['anio_activo'] ?? date('Y');

// ============================================================================
// CONFIGURACIÓN Y PARÁMETROS
// ============================================================================

$usuarioId = (int)$_SESSION['usuario_id'];
$rol = $_SESSION['rol'] ?? 'capturista';

// Parámetros de entrada
$aniosInput = isset($_GET['anio']) ? $_GET['anio'] : $anio_activo;
$anios = [];
if (is_array($aniosInput)) {
    $anios = array_map('intval', $aniosInput);
} else {
    $anios = array_map('intval', explode(',', (string)$aniosInput));
}
if (empty($anios)) {
    $anios = [(int)$anio_activo];
}

$jurisdiccionSel = isset($_GET['jurisdiccion']) ? trim($_GET['jurisdiccion']) : '';
$municipioSel = isset($_GET['municipio']) ? trim($_GET['municipio']) : '';

// ============================================================================
// CLASE PRINCIPAL DEL EXPORTADOR
// ============================================================================

class ExportadorColateralesCompleto {
    
    private $calculadora;
    private $anios;
    private $jurisdiccionSel;
    private $municipioSel;
    private $rol;
    private $usuarioId;
    private $tiposConfig;
    private $meses;
    
    public function __construct($conn, $anios, $jurisdiccionSel, $municipioSel, $rol, $usuarioId) {
        $this->calculadora = new CalculadoraColaterales($conn);
        $this->anios = $anios;
        $this->jurisdiccionSel = $jurisdiccionSel;
        $this->municipioSel = $municipioSel;
        $this->rol = $rol;
        $this->usuarioId = $usuarioId;
        $this->tiposConfig = ConfigExcel::getTiposConfig();
        $this->meses = ConfigExcel::getMeses();
    }
    
    /**
     * Obtiene datos procesados de un tipo específico
     */
    public function obtenerDatosTipo($tipo) {
        $filas = $this->calculadora->obtenerDatosBase(
            $tipo, 
            $this->anios, 
            $this->jurisdiccionSel, 
            $this->municipioSel, 
            $this->rol, 
            $this->usuarioId
        );
        
        return $this->calculadora->procesarDatosCompletos($filas);
    }
    
    /**
     * Registra la descarga en bitácora
     */
    public function registrarDescarga($conn) {
        $strAnios = implode(',', $this->anios);
        $detalles = "Completo|Años:$strAnios|Juris:{$this->jurisdiccionSel}|Muni:{$this->municipioSel}";
        $stmt = mysqli_prepare($conn, "INSERT INTO bitacora (usuario_id, accion, detalles, exito) VALUES (?, 'descarga_excel', ?, 1)");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'is', $this->usuarioId, $detalles);
            mysqli_stmt_execute($stmt);
        }
    }
    
    /**
     * Genera el archivo Excel completo
     */
    public function generarExcel() {
        $filename = ConfigExcel::generarNombreArchivo($this->anios);
        
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo $this->generarHTMLExcel();
    }
    
    /**
     * Genera el HTML completo del Excel
     */
    private function generarHTMLExcel() {
        $html = $this->generarEncabezadoHTML();
        
        // Generar hojas individuales
        foreach ($this->tiposConfig as $tipo => $config) {
            $datos = $this->obtenerDatosTipo($tipo);
            $html .= $this->generarHojaIndividual($tipo, $config, $datos);
        }
        
        // Generar hoja de evaluación estatal
        $html .= $this->generarHojaEvaluacionEstatal();
        
        $html .= "</body></html>";
        
        return $html;
    }
    
    /**
     * Genera el encabezado HTML con estilos y configuración de hojas
     */
    private function generarEncabezadoHTML() {
        $hojas = [];
        foreach ($this->tiposConfig as $config) {
            $hojas[] = '<x:ExcelWorksheet><x:Name>' . $config['sheet_name'] . '</x:Name></x:ExcelWorksheet>';
        }
        $hojas[] = '<x:ExcelWorksheet><x:Name>EVALUACION ESTATAL</x:Name></x:ExcelWorksheet>';
        
        return '
<html xmlns:x="urn:schemas-microsoft-com:office:excel">
<head>
    <meta charset="utf-8">
    <!--[if gte mso 9]><xml>
    <x:ExcelWorkbook>
        <x:ExcelWorksheets>
            ' . implode('', $hojas) . '
        </x:ExcelWorksheets>
    </x:ExcelWorkbook>
    </xml><![endif]-->
    <style>' . ConfigExcel::getEstilosCSS() . '</style>
</head>
<body>';
    }
    
    /**
     * Genera una hoja individual de datos
     */
    private function generarHojaIndividual($tipo, $config, $datos) {
        $html = '<div>';
        
        // Encabezados institucionales
        $titulo = $config['titulo'] . ' ' . implode('-', $this->anios);
        $html .= $this->generarEncabezadosInstitucionales($titulo);
        
        // Tabla de datos
        $html .= '<table>';
        $html .= $this->generarEncabezadosColumnas();
        $html .= '<tbody>';
        
        foreach ($datos['grupos'] as $jurisdiccion => $grupo) {
            $html .= $this->generarFilasJurisdiccion($jurisdiccion, $grupo);
        }
        
        // Gran total
        $html .= $this->generarFilaGranTotal($datos['granTotal']);
        
        $html .= '</tbody></table></div>';
        
        return $html;
    }
    
    /**
     * Genera las filas de una jurisdicción con subtotal
     */
    private function generarFilasJurisdiccion($jurisdiccion, $grupo) {
        $html = '';
        $numFilas = count($grupo['filas']);
        $primeraFila = true;
        
        foreach ($grupo['filas'] as $fila) {
            $html .= '<tr>';
            
            // Celda de jurisdicción con rowspan
            if ($primeraFila) {
                $html .= '<td class="jurisdiccion" rowspan="' . ($numFilas + 1) . '">' . htmlspecialchars($jurisdiccion) . '</td>';
                $primeraFila = false;
            }
            
            $html .= '<td>' . htmlspecialchars($fila['municipio']) . '</td>';
            $html .= '<td class="numero">' . ConfigExcel::formatearNumero($fila['meta_anual']) . '</td>';
            
            foreach ($this->meses as $mes) {
                $html .= '<td class="numero">' . ConfigExcel::formatearNumero($fila[$mes] ?? 0) . '</td>';
            }
            
            $html .= '<td class="numero subtotal">' . ConfigExcel::formatearNumero($fila['total_vertical']) . '</td>';
            $html .= '<td class="numero subtotal">' . ConfigExcel::formatearPorcentaje($fila['logro_pct']) . '</td>';
            $html .= '</tr>';
        }
        
        // Fila de subtotal por jurisdicción
        $html .= '<tr class="subtotal">';
        $html .= '<td>TOTAL</td>';
        $html .= '<td class="numero">' . ConfigExcel::formatearNumero($grupo['subtotal']['meta']) . '</td>';
        
        foreach ($this->meses as $mes) {
            $html .= '<td class="numero">' . ConfigExcel::formatearNumero($grupo['subtotal']['meses'][$mes]) . '</td>';
        }
        
        $html .= '<td class="numero">' . ConfigExcel::formatearNumero($grupo['subtotal']['total_vertical']) . '</td>';
        $html .= '<td class="numero">' . ConfigExcel::formatearPorcentaje($grupo['subtotal']['logro_pct']) . '</td>';
        $html .= '</tr>';
        
        return $html;
    }
    
    /**
     * Genera la fila de gran total
     */
    private function generarFilaGranTotal($granTotal) {
        $html = '<tr class="gran-total">';
        $html .= '<td colspan="2" class="centro">GRAN TOTAL</td>';
        $html .= '<td class="numero">' . ConfigExcel::formatearNumero($granTotal['meta']) . '</td>';
        
        foreach ($this->meses as $mes) {
            $html .= '<td class="numero">' . ConfigExcel::formatearNumero($granTotal['meses'][$mes]) . '</td>';
        }
        
        $html .= '<td class="numero">' . ConfigExcel::formatearNumero($granTotal['total_vertical']) . '</td>';
        $html .= '<td class="numero">' . ConfigExcel::formatearPorcentaje($granTotal['logro_pct']) . '</td>';
        $html .= '</tr>';
        
        return $html;
    }
    
    /**
     * Genera los encabezados institucionales
     */
    private function generarEncabezadosInstitucionales($titulo) {
        $numColumnas = 3 + count($this->meses) + 2; // JURIS + MUNICIPIO + META + 12 meses + TOTAL + LOGRO
        $encabezados = ConfigExcel::getEncabezadosInstitucionales();
        
        $html = '<table>';
        
        foreach ($encabezados as $encabezado) {
            $html .= '<tr><td colspan="' . $numColumnas . '" class="header-institucional">' . $encabezado . '</td></tr>';
        }
        
        $html .= '<tr><td colspan="' . $numColumnas . '" class="titulo-hoja">' . $titulo . '</td></tr>';
        $html .= '</table>';
        
        return $html;
    }
    
    /**
     * Genera los encabezados de las columnas
     */
    private function generarEncabezadosColumnas() {
        $html = '<thead><tr>';
        $html .= '<th class="encabezado-columna">JURISDICCIÓN</th>';
        $html .= '<th class="encabezado-columna">MUNICIPIO</th>';
        $html .= '<th class="encabezado-columna">META ANUAL</th>';
        
        foreach ($this->meses as $mes) {
            $html .= '<th class="encabezado-columna">' . strtoupper($mes) . '</th>';
        }
        
        $html .= '<th class="encabezado-columna subtotal">TOTAL</th>';
        $html .= '<th class="encabezado-columna subtotal">% LOGRO</th>';
        $html .= '</tr></thead>';
        
        return $html;
    }
    
    /**
     * Genera la hoja de evaluación estatal
     */
    private function generarHojaEvaluacionEstatal() {
        // Obtener subtotales de todas las hojas
        $datosEvaluacion = [];
        foreach ($this->tiposConfig as $tipo => $config) {
            $subtotales = $this->calculadora->obtenerSubtotalesPorJurisdiccion(
                $tipo, 
                $this->anios, 
                $this->jurisdiccionSel, 
                $this->municipioSel, 
                $this->rol, 
                $this->usuarioId
            );
            
            $datosEvaluacion[$tipo] = [
                'titulo' => $config['titulo'],
                'subtotales' => $subtotales
            ];
        }
        
        $html = '<div>';
        $html .= $this->generarEncabezadosInstitucionales('EVALUACIÓN ESTATAL ' . implode('-', $this->anios));
        
        // Generar las tablas en formato de 3 columnas
        $html .= $this->generarTablasEvaluacionEstatal($datosEvaluacion);
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Genera las tablas de evaluación estatal en formato de 3 columnas
     */
    private function generarTablasEvaluacionEstatal($datosEvaluacion) {
        $configEval = ConfigExcel::getConfigEvaluacionEstatal();
        $html = '<table style="width:100%; border-collapse: separate; border-spacing: 10px;">';
        
        foreach ($configEval['tipos_por_seccion'] as $seccion) {
            $html .= '<tr>';
            
            foreach ($seccion as $tipo) {
                if (isset($datosEvaluacion[$tipo])) {
                    $html .= '<td class="evaluacion-tabla">';
                    $html .= $this->generarTablaEvaluacionIndividual($datosEvaluacion[$tipo]);
                    $html .= '</td>';
                }
            }
            
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        
        return $html;
    }
    
    /**
     * Genera una tabla individual de evaluación con 12 meses (formato original Excel)
     */
    private function generarTablaEvaluacionIndividual($datos) {
        $html = '<table style="width:100%; border-collapse:collapse;">';
        
        // Título
        $html .= '<tr><td colspan="16" class="evaluacion-titulo">' . $datos['titulo'] . '</td></tr>';
        
        // Encabezados con 12 meses
        $html .= '<tr>';
        $html .= '<th class="encabezado-columna">Jurisdicción Sanitaria</th>';
        $html .= '<th class="encabezado-columna">Meta Anual</th>';
        foreach ($this->meses as $mes) {
            $html .= '<th class="encabezado-columna">' . strtoupper(substr($mes, 0, 3)) . '</th>';
        }
        $html .= '<th class="encabezado-columna subtotal">Total</th>';
        $html .= '<th class="encabezado-columna subtotal">% Logro</th>';
        $html .= '</tr>';
        
        // Datos por jurisdicción
        $totalMeta = 0;
        $totalAvance = 0;
        $totalMeses = array_fill_keys($this->meses, 0);
        
        foreach ($datos['subtotales'] as $jurisdiccion => $subtotal) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($jurisdiccion) . '</td>';
            $html .= '<td class="numero">' . ConfigExcel::formatearNumero($subtotal['meta']) . '</td>';
            
            // Meses
            foreach ($this->meses as $mes) {
                $valorMes = isset($subtotal['meses'][$mes]) ? $subtotal['meses'][$mes] : 0;
                $html .= '<td class="numero">' . ConfigExcel::formatearNumero($valorMes) . '</td>';
                $totalMeses[$mes] += $valorMes;
            }
            
            $html .= '<td class="numero subtotal">' . ConfigExcel::formatearNumero($subtotal['total_vertical']) . '</td>';
            $html .= '<td class="numero subtotal">' . ConfigExcel::formatearPorcentaje($subtotal['logro_pct']) . '%</td>';
            $html .= '</tr>';
            
            $totalMeta += $subtotal['meta'];
            $totalAvance += $subtotal['total_vertical'];
        }
        
        // Total
        $totalLogro = $totalMeta > 0 ? ($totalAvance / $totalMeta) * 100 : 0;
        $html .= '<tr class="subtotal">';
        $html .= '<td><strong>Total</strong></td>';
        $html .= '<td class="numero"><strong>' . ConfigExcel::formatearNumero($totalMeta) . '</strong></td>';
        
        foreach ($this->meses as $mes) {
            $html .= '<td class="numero"><strong>' . ConfigExcel::formatearNumero($totalMeses[$mes]) . '</strong></td>';
        }
        
        $html .= '<td class="numero"><strong>' . ConfigExcel::formatearNumero($totalAvance) . '</strong></td>';
        $html .= '<td class="numero"><strong>' . ConfigExcel::formatearPorcentaje($totalLogro) . '%</strong></td>';
        $html .= '</tr>';
        
        $html .= '</table>';
        
        return $html;
    }
}

// ============================================================================
// EJECUCIÓN PRINCIPAL
// ============================================================================

try {
    $exportador = new ExportadorColateralesCompleto(
        $conn, 
        $anios, 
        $jurisdiccionSel, 
        $municipioSel, 
        $rol, 
        $usuarioId
    );
    
    // Registrar descarga en bitácora
    $exportador->registrarDescarga($conn);
    
    $exportador->generarExcel();
    
} catch (Exception $e) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "Error al generar el archivo Excel: " . htmlspecialchars($e->getMessage());
}

exit;
?>