<?php

/**
 * Obtiene el contenido de ayuda según la página y el rol del usuario
 * Retorna JSON con las capturas y explicaciones correspondientes
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

$page = $_GET['page'] ?? 'panel.php';
$rol = $_SESSION['rol'] ?? 'capturista';

// Debug logging
error_log("=== HELP MODAL REQUEST ===");
error_log("Página solicitada: $page");
error_log("Rol del usuario: $rol");
error_log("IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

// Contenido diferenciado por rol
$contenido = [
    'panel.php' => [
        'titulo' => 'Panel Principal',
        'capturas' => [
            'admin' => ['panel_manual_hq.png'],
            'capturista' => ['panel_capturista_manual_hq.png']
        ],
        'admin' => [
            'descripcion' => 'Vista general del sistema con estadísticas globales de todas las jurisdicciones.',
            'pasos' => [
                '<strong>📊 Estadísticas del Dashboard:</strong> Visualiza el total de capturas, vacunas administradas y material utilizado en tiempo real de TODAS las jurisdicciones del estado',
                '<strong>🎯 Indicadores de Cumplimiento:</strong> Gráficas que muestran el avance de cada jurisdicción respecto a sus metas anuales con código de colores (verde >80%, amarillo 50-80%, rojo <50%)',
                '<strong>⚡ Accesos Rápidos:</strong> Botones directos a las funciones más utilizadas: captura de datos, gestión de metas, extensiones de plazo, bitácora y generación de reportes',
                '<strong>🔔 Alertas Importantes:</strong> Notificaciones sobre fechas límite de captura, jurisdicciones rezagadas, solicitudes de prórroga pendientes y mensajes urgentes',
                '<strong>📍 Menú de Navegación:</strong> Sidebar siempre visible con iconos identificadores para acceder rápidamente a todos los módulos del sistema'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Tu panel personal con el resumen de tu jurisdicción.',
            'pasos' => [
                '<strong>📊 Tus Estadísticas Mensuales:</strong> Visualiza el total de vacunas aplicadas, esterilizaciones realizadas y material utilizado específicamente en TU jurisdicción durante el mes actual',
                '<strong>🎯 Avance vs Meta:</strong> Gráfica de progreso que compara tus dosis aplicadas contra la meta anual asignada, con porcentaje de cumplimiento actualizado en tiempo real',
                '<strong>⚡ Captura Rápida:</strong> Botón de acceso directo al formulario de captura mensual para registrar tus datos del mes actual de forma rápida',
                '<strong>📬 Mensajes del Administrador:</strong> Bandeja de notificaciones con comunicados importantes, recordatorios de fechas límite y respuestas a tus solicitudes de prórroga',
                '<strong>📅 Calendario de Captura:</strong> Indicador visual de los días restantes para completar la captura del mes actual antes del cierre automático'
            ]
        ]
    ],

    'biologico_canino.php' => [
        'titulo' => 'Movimiento de Biológico Canino',
        'capturas' => [
            'admin' => ['biologico_canino_tabla_manual_hq.png'],
            'capturista' => ['biologico_canino_capturista.png']
        ],
        'admin' => [
            'descripcion' => 'Consulta el movimiento de vacuna antirrábica de todas las jurisdicciones.',
            'pasos' => [
                '<strong>Vista Tabla:</strong> Visualiza datos capturados por todos los capturistas',
                '<strong>Filtros:</strong> Filtra por mes para ver movimientos específicos',
                '<strong>Datos Mostrados:</strong> Existencia Inicial, Dosis Recibidas, Administradas, Desperdicio, Existencia Final',
                '<strong>Todas las Jurisdicciones:</strong> Ve el consolidado de todas las jurisdicciones en una sola tabla',
                '<strong>Exportar:</strong> Exporta a Excel para reportes oficiales y auditorías'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Registra el movimiento mensual de vacuna antirrábica de tu jurisdicción.',
            'pasos' => [
                '<strong>Selecciona Mes:</strong> Elige el año y mes a capturar del selector',
                '<strong>Existencia Inicial:</strong> Se carga automáticamente del mes anterior',
                '<strong>Dosis Recibidas:</strong> Ingresa cantidad con su lote y fecha de caducidad',
                '<strong>Registra:</strong> las dosis Administradas y el Desperdicio del mes',
                '<strong>Verifica:</strong> que la Existencia Final coincida con tu inventario físico',
                '<strong>Guarda:</strong> los cambios - se registra en bitácora con fecha y hora'
            ]
        ]
    ],

    'vacuna_humana.php' => [
        'titulo' => 'Vacuna Antirrábica Humana',
        'capturas' => [
            'admin' => [],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Nota importante sobre el proceso.',
            'pasos' => [
                '<strong>ℹ️ Proceso Homologado:</strong> El proceso de captura, edición y exportación des este módulo se realiza <strong>exactamente igual</strong> que en el módulo de <strong>Biológico Canino</strong>.',
                'Por favor, consulta la ayuda de la sección de Biológico Canino para ver los pasos detallados.'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Nota importante sobre el proceso.',
            'pasos' => [
                '<strong>ℹ️ Proceso Homologado:</strong> El proceso de captura es <strong>idéntico</strong> al del módulo de <strong>Biológico Canino</strong>.',
                'Si tienes dudas, revisa la ayuda de esa sección.'
            ]
        ]
    ],

    'inmunoglobulina_humana.php' => [
        'titulo' => 'Inmunoglobulina Humana',
        'capturas' => [
            'admin' => [],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Nota importante sobre el proceso.',
            'pasos' => [
                '<strong>ℹ️ Proceso Homologado:</strong> El proceso de captura, edición y exportación des este módulo se realiza <strong>exactamente igual</strong> que en el módulo de <strong>Biológico Canino</strong>.',
                'Por favor, consulta la ayuda de la sección de Biológico Canino para ver los pasos detallados.'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Nota importante sobre el proceso.',
            'pasos' => [
                '<strong>ℹ️ Proceso Homologado:</strong> El proceso de captura es <strong>idéntico</strong> al del módulo de <strong>Biológico Canino</strong>.',
                'Si tienes dudas, revisa la ayuda de esa sección.'
            ]
        ]
    ],

    'material_curacion.php' => [
        'titulo' => 'Material de Curación',
        'capturas' => [
            'admin' => ['material_curacion_manual_hq.png', 'material_curacion_modal_manual_hq.png', 'material_curacion_tabla_manual_hq.png'],
            'capturista' => ['material_curacion_capturista.png', 'material_curacion_capturista_modal.png']
        ],
        'admin' => [
            'descripcion' => 'Control de inventario de insumos médicos para todas las jurisdicciones.',
            'pasos' => [
                'Click en "Seleccionar Insumo" para elegir el material (jeringas, gasas, alcohol, etc.)',
                'Modo Formulario: Captura individual por jurisdicción y mes',
                'Modo Tabla: Captura masiva de todas las jurisdicciones simultáneamente',
                'Tabla Completa: Vista anual de 12 meses con cálculos automáticos',
                'ENTREGADO: Material que das a cada jurisdicción',
                'UTILIZADO: Material consumido por la jurisdicción',
                'DISPONIBLE = Remanente anterior + Entregado',
                'REMANENTE = Disponible - Utilizado (pasa al siguiente mes)'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Registra el material de curación utilizado en tu jurisdicción.',
            'pasos' => [
                'Click en "Seleccionar Insumo" - se abre modal con catálogo completo',
                'Elige el material que vas a registrar (jeringas, gasas, guantes, etc.)',
                'Selecciona el mes a capturar',
                'Ingresa la cantidad UTILIZADA durante ese mes',
                'El sistema calcula automáticamente el DISPONIBLE y REMANENTE',
                'Consulta la Tabla Completa para ver tu consumo anual'
            ]
        ]
    ],

    'recetas_biologico.php' => [
        'titulo' => 'Generador de Recetas Biológicas',
        'capturas' => [
            'admin' => ['recetas_biologico_manual_hq.png', 'recetas_biologico_formulario_manual_hq.png', 'recetas_biologico_historial_manual_hq.png'],
            'capturista' => ['recetas_biologico_manual_hq.png', 'recetas_biologico_historial_manual_hq.png']
        ],
        'admin' => [
            'descripcion' => 'Genera recetas oficiales de biológicos controlados (Xilacina, Zoletil, etc.).',
            'pasos' => [
                'Selecciona el tipo de insumo (XILACINA, ZOLETIL, ANTIBIÓTICO, ANALGÉSICO)',
                'El folio se asigna automáticamente y es consecutivo',
                'Llena: Recibió (M.V.Z.), Cédula Profesional, Jurisdicción',
                'Ingresa la fecha de emisión',
                'Describe el producto completo (nombre, presentación, contenido)',
                'Cantidad de frascos: Se genera 1 receta por cada 20 frascos',
                'Click en "Generar Recetas PDF" - se crea el documento oficial',
                'El PDF contiene 2 recetas por página listas para imprimir',
                'Sección "Historial": Consulta todas las recetas generadas anteriormente',
                'Filtra por año, tipo de insumo o jurisdicción',
                'Descarga o reimprime recetas del historial'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Consulta las recetas generadas para tu jurisdicción.',
            'pasos' => [
                'Accede a la sección de Historial',
                'Filtra por tu jurisdicción',
                'Consulta las recetas emitidas',
                'Descarga o imprime si necesitas una copia',
                'Verifica folios y fechas de emisión'
            ]
        ]
    ],

    'metas.php' => [
        'titulo' => 'Gestión de Metas',
        'capturas' => [
            'admin' => ['metas_manual_hq.png', 'metas_modal_crear_manual_hq.png', 'metas_modal_editar_manual_hq.png'],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Define las metas anuales de vacunación para cada jurisdicción.',
            'pasos' => [
                '<strong>📊 Vista Principal:</strong> Tabla con todas las metas por jurisdicción, municipio y tipo',
                '<strong>➕ Nueva Meta:</strong> Click en "Nueva Meta" para abrir modal de creación',
                '<strong>📝 Formulario de Creación:</strong> Selecciona jurisdicción, tipo, municipio/hospital y valor de la meta',
                '<strong>✏️ Editar Meta:</strong> Click en icono de editar (lápiz) en la tabla para modificar valores',
                '<strong>🗑️ Eliminar Meta:</strong> Click en icono de eliminar (basura) para borrar metas',
                '<strong>💾 Guardar Cambios:</strong> Las metas se guardan y se notifica a los capturistas'
            ]
        ],
        'capturista' => null // Los capturistas no ven esta página
    ],

    'cerrar_anio.php' => [
        'titulo' => 'Cerrar Año',
        'capturas' => [
            'admin' => ['cerrar_anio_manual_hq.png'],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Cierra el período anual de forma definitiva e irreversible.',
            'pasos' => [
                'Revisa que todas las jurisdicciones hayan completado sus capturas',
                'Verifica que no haya datos pendientes o inconsistencias',
                'Genera y descarga el reporte final del año antes de cerrar',
                'Selecciona el año a cerrar del desplegable',
                'Click en "Cerrar Año" - aparece confirmación de JavaScript',
                'Lee la advertencia: Esta acción es IRREVERSIBLE',
                'Confirma la acción en el diálogo del navegador',
                'El sistema bloquea todas las capturas de ese año',
                'Se genera respaldo automático en la base de datos'
            ]
        ],
        'capturista' => null // Los capturistas no ven esta página
    ],

    'mis_metas.php' => [
        'titulo' => 'Mis Metas',
        'capturas' => [
            'admin' => [],
            'capturista' => ['mis_metas_manual_hq.png']
        ],
        'admin' => null, // Los admins no usan esta vista
        'capturista' => [
            'descripcion' => 'Consulta tu meta anual y tu avance actual.',
            'pasos' => [
                'Visualiza tu meta asignada para el año',
                'Revisa tu avance acumulado hasta la fecha',
                'Consulta el porcentaje de cumplimiento',
                'Identifica si vas adelantado o retrasado',
                'Planifica tus jornadas de vacunación según el avance'
            ]
        ]
    ],

    'capturar_mes.php' => [
        'titulo' => 'Capturar Mes',
        'capturas' => [
            'admin' => ['capturar_mes_manual_hq.png'],
            'capturista' => ['capturar_mes_capturista_manual_hq.png']
        ],
        'admin' => [
            'descripcion' => 'Captura datos mensuales de todas las jurisdicciones.',
            'pasos' => [
                '<strong>Vista Captura:</strong> Formulario para captura individual por jurisdicción',
                '<strong>Selecciona jurisdicción:</strong> año y mes a capturar',
                '<strong>Llena todos los campos:</strong> municipios, hospitales, vacunados, esterilizados, control, muestras, agredidos, tratamientos',
                '<strong>Vista Historial:</strong> Consulta todas las capturas realizadas anteriormente',
                '<strong>Verifica los datos:</strong> que sean coherentes antes de guardar',
                '<strong>Validación automática:</strong> El sistema valida rangos y formatos'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Registra los datos mensuales de tu jurisdicción.',
            'pasos' => [
                '<strong>Selecciona:</strong> el año y mes a capturar',
                '<strong>Llena la información:</strong> de municipios/hospitales atendidos',
                '<strong>Registra:</strong> Vacunados, Esterilizados, Control, Muestras, Agredidos, Tratamientos',
                '<strong>Campos obligatorios:</strong> Todos los campos son obligatorios',
                '<strong>Verifica:</strong> tus datos antes de guardar',
                '<strong>Guardado:</strong> Una vez guardado, se registra en bitácora con fecha y hora'
            ]
        ]
    ],

    'historial_mes.php' => [
        'titulo' => 'Historial de Capturas',
        'capturas' => [
            'admin' => ['historial_mes_manual_hq.png'],
            'capturista' => ['historial_mes_capturista.png']
        ],
        'admin' => [
            'descripcion' => 'Consulta todas las capturas realizadas en el sistema.',
            'pasos' => [
                'Navega por carpetas de jurisdicciones',
                'Filtra por tipo de archivo (Expediente, Oficio, Cronograma)',
                'Revisa archivos PDF subidos por cada jurisdicción',
                'Crea carpetas para organizar documentos',
                'Sube nuevos archivos PDF al sistema',
                'Descarga o elimina archivos según permisos'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Consulta tu historial de archivos y documentos.',
            'pasos' => [
                'Accede a tu carpeta de jurisdicción',
                'Filtra por tipo de documento',
                'Revisa tus archivos subidos',
                'Crea subcarpetas para organizar',
                'Sube nuevos documentos PDF',
                'Descarga archivos cuando los necesites'
            ]
        ]
    ],

    'admin_extensiones.php' => [
        'titulo' => 'Extensiones de Plazo',
        'capturas' => [
            'admin' => ['admin_extensiones_manual_hq.png'],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Gestiona solicitudes de prórroga para captura fuera de período.',
            'pasos' => [
                'Pestaña "Prórrogas Activas": Solicitudes pendientes de aprobar/rechazar',
                'Revisa el motivo de cada solicitud',
                'Consulta quién solicitó y para qué mes',
                'Aprueba con nueva fecha límite o rechaza con motivo',
                'Pestaña "Historial": Consulta todas las prórrogas (aprobadas y rechazadas)',
                'Filtra por jurisdicción, fecha o estado',
                'El capturista recibe notificación de tu decisión',
                'Las extensiones se registran en bitácora'
            ]
        ],
        'capturista' => null // Los capturistas no ven esta página
    ],

    'admin_usuarios.php' => [
        'titulo' => 'Gestión de Usuarios',
        'capturas' => [
            'admin' => ['admin_usuarios.png', 'admin_usuarios_modal.png', 'admin_usuarios_bloqueos.png'],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Administra usuarios, permisos, bloqueos y accesos temporales del sistema.',
            'pasos' => [
                '<strong>👥 Tabla de Usuarios:</strong> Lista completa con nombre, email, rol, jurisdicción y estado (activo/inactivo)',
                '<strong>➕ Nuevo Usuario:</strong> Click en "Nuevo Usuario" para abrir modal de creación con todos los campos requeridos',
                '<strong>✏️ Editar Usuario:</strong> Click en icono de editar (lápiz) para modificar datos, cambiar rol o jurisdicción',
                '<strong>🔒 Bloquear/Desbloquear:</strong> Activa o desactiva usuarios - los bloqueados no pueden iniciar sesión',
                '<strong>⏰ Acceso Temporal:</strong> Otorga permisos temporales con fecha de expiración automática',
                '<strong>🚫 Bloqueos por IP:</strong> Consulta y desbloquea IPs bloqueadas por intentos fallidos de login',
                '<strong>🔍 Filtros Avanzados:</strong> Filtra por rol, estado, jurisdicción o busca por nombre/email',
                '<strong>📊 Estadísticas:</strong> Visualiza total de usuarios activos, inactivos y bloqueados',
                '<strong>🗑️ Eliminar Usuario:</strong> Borra usuarios (requiere confirmación) - se registra en bitácora',
                '<strong>📝 Bitácora Integrada:</strong> Todas las acciones se registran automáticamente con fecha, hora y usuario responsable'
            ]
        ],
        'capturista' => null // Los capturistas no ven esta página
    ],

    'graficas.php' => [
        'titulo' => 'Gráficas y Visualizaciones',
        'capturas' => [
            'admin' => ['graficas_manual_hq.png', 'graficas_detalle_manual_hq.png'],
            'capturista' => ['graficas_capturista_manual_hq.png', 'graficas_capturista_detalle.png']
        ],
        'admin' => [
            'descripcion' => 'Visualiza datos del sistema en gráficas interactivas.',
            'pasos' => [
                '<strong>📊 Vista de Tarjetas:</strong> Grid con tarjetas de cada jurisdicción mostrando gráfica de dona',
                '<strong>🖱️ Click en Tarjeta:</strong> Expande vista detallada con gráficas completas',
                '<strong>📈 Vista Detallada:</strong> Sidebar con jurisdicciones y panel con gráficas de barras, líneas y comparativas',
                '<strong>📥 Exportar:</strong> Botones para exportar a PowerPoint o Excel',
                '<strong>🔍 Filtros:</strong> Selecciona rango de fechas y compara jurisdicciones'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Visualiza tus datos en gráficas.',
            'pasos' => [
                '<strong>📊 Tu Gráfica:</strong> Visualiza tu jurisdicción con gráfica de dona',
                '<strong>🖱️ Click para Detalle:</strong> Expande para ver gráficas completas',
                '<strong>📈 Análisis:</strong> Compara tu avance mes a mes',
                '<strong>📥 Exportar:</strong> Descarga tus gráficas en PowerPoint o Excel'
            ]
        ]
    ],

    'reportes.php' => [
        'titulo' => 'Generación de Reportes',
        'capturas' => [
            'admin' => ['reportes_manual_hq.png'],
            'capturista' => ['reportes_capturista_manual_hq.png']
        ],
        'admin' => [
            'descripcion' => 'Genera reportes oficiales del sistema.',
            'pasos' => [
                'Selecciona el tipo de reporte (mensual, consolidado, comparativo)',
                'Configura filtros: fechas, jurisdicciones, tipo de datos',
                'Elige formato: Excel o PDF',
                'Click en "Generar" - se crea el archivo',
                'Descarga para presentar en reuniones o auditorías'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Genera reportes de tu jurisdicción.',
            'pasos' => [
                'Selecciona el período a reportar',
                'Elige el formato (Excel o PDF)',
                'Genera tu reporte mensual',
                'Descarga para tu archivo'
            ]
        ]
    ],

    'bitacora.php' => [
        'titulo' => 'Bitácora del Sistema',
        'capturas' => [
            'admin' => ['bitacora_manual_hq.png'],
            'capturista' => ['bitacora_capturista_manual_hq.png']
        ],
        'admin' => [
            'descripcion' => 'Audita todas las acciones realizadas en el sistema.',
            'pasos' => [
                'Consulta todos los eventos registrados',
                'Filtra por usuario, fecha o tipo de acción',
                'Revisa inicios de sesión, capturas, cambios',
                'Identifica actividad sospechosa',
                'Exporta para auditorías externas'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Consulta tu historial de actividad.',
            'pasos' => [
                'Revisa tus acciones registradas',
                'Verifica fechas y horas de tus capturas',
                'Confirma que tus cambios se guardaron'
            ]
        ]
    ],

    'mensajes.php' => [
        'titulo' => 'Mensajes del Sistema',
        'capturas' => [
            'admin' => ['mensajes_manual_hq.png'],
            'capturista' => ['mensajes_capturista_manual_hq.png']
        ],
        'admin' => [
            'descripcion' => 'Envía comunicados a los capturistas.',
            'pasos' => [
                'Redacta un nuevo mensaje',
                'Selecciona destinatarios (todos o específicos)',
                'Marca como urgente si es necesario',
                'Envía - los capturistas lo ven en su panel',
                'Consulta mensajes enviados y leídos'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Recibe comunicados del administrador.',
            'pasos' => [
                'Revisa mensajes nuevos (badge rojo)',
                'Lee comunicados importantes',
                'Marca como leído',
                'Consulta mensajes anteriores'
            ]
        ]
    ],

    'mantenimiento.php' => [
        'titulo' => 'Mantenimiento del Sistema',
        'capturas' => [
            'admin' => ['mantenimiento_manual_hq.png'],
            'capturista' => []
        ],
        'admin' => [
            'descripcion' => 'Herramientas administrativas y respaldos.',
            'pasos' => [
                'Genera respaldo de la base de datos',
                'Descarga el archivo .sql',
                'Limpia caché y archivos temporales',
                'Optimiza tablas de la BD',
                'Restaura respaldos si es necesario',
                'Activa/desactiva modo mantenimiento'
            ]
        ],
        'capturista' => null // Los capturistas no ven esta página
    ],

    'test_modal_debug.php' => [
        'titulo' => 'Página de Prueba - Debug',
        'capturas' => [
            'admin' => ['panel.png', 'biologico_canino.png', 'material_curacion.png'],
            'capturista' => ['panel_capturista.png', 'biologico_canino_capturista.png']
        ],
        'admin' => [
            'descripcion' => 'Esta es una página de prueba para verificar el funcionamiento del modal de ayuda con múltiples capturas.',
            'pasos' => [
                '<strong>🧪 Prueba del Carrusel:</strong> Verifica que el carrusel muestre las 3 imágenes correctamente y puedas navegar entre ellas',
                '<strong>🎯 Navegación con Botones:</strong> Usa los botones ← → en la parte superior del carrusel para cambiar entre imágenes',
                '<strong>⌨️ Navegación con Teclado:</strong> Usa las flechas del teclado (← →) para navegar más rápidamente',
                '<strong>🔵 Dots Interactivos:</strong> Haz clic en los puntos (dots) debajo del carrusel para saltar directamente a una imagen',
                '<strong>✅ Formato HTML:</strong> Verifica que este texto se muestre correctamente con negritas, emojis y formato enriquecido'
            ]
        ],
        'capturista' => [
            'descripcion' => 'Página de prueba para verificar el contenido diferenciado por rol.',
            'pasos' => [
                '<strong>📝 Contenido Diferente:</strong> Este contenido es específico para capturistas y es diferente al del administrador',
                '<strong>📊 Verificación de Rol:</strong> Confirma que solo ves las opciones permitidas para tu rol'
            ]
        ]
    ]
];

// Obtener contenido según página y rol
$data = $contenido[$page] ?? null;

if (!$data) {
    echo json_encode(['error' => 'Página no encontrada']);
    exit;
}

// Verificar si el rol tiene acceso a esta página
$rolData = $data[$rol] ?? null;

if ($rolData === null) {
    echo json_encode(['error' => 'No tienes acceso a esta página']);
    exit;
}

// Obtener capturas según el rol
$capturas = $data['capturas'][$rol] ?? [];

// Preparar respuesta
$response = [
    'titulo' => $data['titulo'],
    'capturas' => array_map(function ($img) {
        return 'storage/screenshots/' . $img;
    }, $capturas),
    'descripcion' => $rolData['descripcion'],
    'pasos' => $rolData['pasos'],
    'rol' => $rol
];

// Debug logging de respuesta
error_log("Título: " . $response['titulo']);
error_log("Rol: " . $rol);
error_log("Número de capturas: " . count($response['capturas']));
error_log("Capturas: " . implode(', ', $response['capturas']));
error_log("Número de pasos: " . count($response['pasos']));
error_log("========================");

// Verificar que las capturas existan físicamente
foreach ($response['capturas'] as $captura) {
    if (!file_exists($captura)) {
        error_log("WARNING: Captura no encontrada: $captura");
    }
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
