<?php
/**
 * TÉRMINOS Y CONDICIONES DE USO
 * Página completa para consulta
 */
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Términos y Condiciones - Sistema Zoonosis</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); min-height: 100vh; }
        .terms-wrapper { max-width: 900px; margin: 0 auto; padding: 2rem; }
        .terms-card { background: white; border-radius: 24px; overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,0.1); }
        .terms-header {
            background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
            padding: 3rem 2rem; text-align: center; color: white;
        }
        .terms-header h1 { margin: 0 0 0.5rem; font-size: 2rem; font-weight: 800; }
        .terms-header p { margin: 0; opacity: 0.9; font-size: 1.1rem; }
        .terms-body { padding: 2.5rem; }
        .dev-box {
            background: linear-gradient(135deg, #dbeafe, #e0e7ff);
            border-radius: 16px; padding: 1.5rem 2rem;
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 2rem; border: 2px solid #bfdbfe;
        }
        .dev-box .label { color: #64748b; font-size: 0.9rem; }
        .dev-box .name { font-weight: 700; color: #1e40af; font-size: 1.25rem; }
        .section { margin-bottom: 2rem; }
        .section h2 {
            display: flex; align-items: center; gap: 12px;
            font-size: 1.15rem; font-weight: 700; color: #1e293b;
            margin-bottom: 1rem; padding-bottom: 0.75rem;
            border-bottom: 2px solid #e2e8f0;
        }
        .section h2 .num {
            width: 32px; height: 32px;
            background: linear-gradient(135deg, #0891b2, #0e7490);
            color: white; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; font-weight: 700;
        }
        .section p { color: #475569; line-height: 1.8; margin-bottom: 0.75rem; text-align: justify; }
        .section ul { padding-left: 1.5rem; margin: 0.75rem 0; }
        .section ul li { color: #475569; line-height: 1.7; margin-bottom: 0.5rem; }
        .legal-note {
            background: #f0fdf4; border-left: 4px solid #22c55e;
            padding: 0.75rem 1rem; border-radius: 0 10px 10px 0;
            font-size: 0.9rem; color: #166534; margin-top: 0.75rem;
        }
        .warning-note {
            background: #fef2f2; border-left: 4px solid #ef4444;
            padding: 0.75rem 1rem; border-radius: 0 10px 10px 0;
            font-size: 0.9rem; color: #991b1b; margin-top: 0.75rem;
        }
        .contact-card {
            background: linear-gradient(135deg, #ecfdf5, #d1fae5);
            border: 2px solid #34d399; border-radius: 16px;
            padding: 1.5rem; display: flex; align-items: center; gap: 1.5rem;
            margin-top: 1rem;
        }
        .contact-card .icon {
            width: 60px; height: 60px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 14px;
            display: flex; align-items: center; justify-content: center;
        }
        .contact-card .icon i { font-size: 1.5rem; color: white; }
        .contact-card .info .label { font-size: 0.85rem; color: #065f46; display: block; }
        .contact-card .info .number { font-size: 1.5rem; font-weight: 700; color: #047857; }
        .terms-footer {
            background: #f8fafc; padding: 2rem;
            border-top: 1px solid #e2e8f0; text-align: center;
        }
        .terms-footer p { color: #64748b; font-size: 0.9rem; margin: 0 0 1rem; }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 12px 28px;
            background: linear-gradient(135deg, #0891b2, #0e7490);
            color: white; text-decoration: none; border-radius: 12px;
            font-weight: 600; transition: all 0.3s;
        }
        .btn-back:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(8,145,178,0.4); }
    </style>
</head>
<body>
<div class="terms-wrapper">
    <div class="terms-card">
        <div class="terms-header">
            <h1><i class="fas fa-file-contract"></i> Términos y Condiciones</h1>
            <p>Contrato de Licencia y Deslinde de Responsabilidad Legal</p>
        </div>
        <div class="terms-body">
            <div class="dev-box">
                <span class="label">Desarrollador del Sistema</span>
                <span class="name">Gilberto Velázquez Nava</span>
            </div>

            <div class="section">
                <h2><span class="num">1</span> Aceptación del Contrato</h2>
                <p>Al acceder y utilizar el Sistema de Control de Zoonosis, el usuario acepta de manera expresa, voluntaria e incondicional todos los términos aquí establecidos, de conformidad con los artículos 1792 al 1859 del <strong>Código Civil Federal</strong> y el artículo 1803 sobre consentimiento expreso o tácito.</p>
                <div class="legal-note"><strong>Fundamento:</strong> Código Civil Federal, Libro Cuarto, Primera Parte - De los Contratos.</div>
            </div>

            <div class="section">
                <h2><span class="num">2</span> Deslinde de Responsabilidad</h2>
                <p>El desarrollador <strong>Gilberto Velázquez Nava</strong> se deslinda expresamente de toda responsabilidad por:</p>
                <ul>
                    <li>Uso indebido, malintencionado o negligente del Sistema por parte de los usuarios</li>
                    <li>Veracidad, exactitud o integridad de los datos capturados por los usuarios</li>
                    <li>Decisiones administrativas, médicas u operativas basadas en información del Sistema</li>
                    <li>Pérdida, corrupción o eliminación de información por cualquier causa</li>
                    <li>Daños directos, indirectos, incidentales, especiales o consecuentes</li>
                    <li>Interrupciones, fallas técnicas o indisponibilidad del servicio</li>
                    <li>Accesos no autorizados, hackeos o ataques informáticos</li>
                </ul>
                <div class="legal-note"><strong>Fundamento:</strong> Artículos 2104 al 2118 del Código Civil Federal (Obligaciones que nacen de Actos Ilícitos).</div>
            </div>

            <div class="section">
                <h2><span class="num">3</span> Limitación de Responsabilidad</h2>
                <p>El Sistema se proporciona <strong>"TAL CUAL"</strong> (AS IS) y <strong>"SEGÚN DISPONIBILIDAD"</strong> (AS AVAILABLE), sin garantías de ningún tipo, expresas o implícitas. En ningún caso la responsabilidad total del desarrollador excederá la cantidad de $0.00 (cero pesos 00/100 M.N.).</p>
                <div class="legal-note"><strong>Fundamento:</strong> Artículo 2117 del Código Civil Federal - La responsabilidad civil puede ser regulada por convenio de las partes.</div>
            </div>

            <div class="section">
                <h2><span class="num">4</span> Obligaciones del Usuario</h2>
                <p>El usuario se obliga a:</p>
                <ul>
                    <li>Proporcionar información veraz, completa y actualizada</li>
                    <li>Mantener la confidencialidad de sus credenciales de acceso</li>
                    <li>No compartir su cuenta con terceros bajo ninguna circunstancia</li>
                    <li>Utilizar el Sistema únicamente para fines lícitos e institucionales</li>
                    <li>Cumplir con todas las leyes y normativas aplicables</li>
                    <li>Notificar inmediatamente cualquier uso no autorizado de su cuenta</li>
                </ul>
                <div class="legal-note"><strong>Fundamento:</strong> Artículos 1796 y 1797 del Código Civil Federal.</div>
            </div>

            <div class="section">
                <h2><span class="num">5</span> Protección de Datos Personales</h2>
                <p>El tratamiento de datos personales se realiza conforme a la legislación mexicana aplicable:</p>
                <ul>
                    <li>Ley General de Protección de Datos Personales en Posesión de Sujetos Obligados</li>
                    <li>Ley Federal de Protección de Datos Personales en Posesión de los Particulares</li>
                    <li>Artículos 6 y 16 de la Constitución Política de los Estados Unidos Mexicanos</li>
                </ul>
            </div>

            <div class="section">
                <h2><span class="num">6</span> Propiedad Intelectual</h2>
                <p>El Sistema, incluyendo código fuente, diseño, estructura, algoritmos e interfaces, es propiedad exclusiva de <strong>Gilberto Velázquez Nava</strong>. Quedan reservados todos los derechos de autor conforme a la <strong>Ley Federal del Derecho de Autor</strong> (artículos 101 al 114).</p>
                <div class="warning-note"><strong>⚠️ Aviso Legal:</strong> La violación a los derechos de propiedad intelectual constituye un delito sancionado con pena de prisión de 3 a 10 años y multa de hasta 30,000 días de salario mínimo (Art. 424 Código Penal Federal).</div>
            </div>

            <div class="section">
                <h2><span class="num">7</span> Cláusula de Indemnización</h2>
                <p>El usuario se obliga a indemnizar, defender y mantener indemne al desarrollador <strong>Gilberto Velázquez Nava</strong> de cualquier reclamación, demanda, daño, pérdida, costo o gasto que surja del uso del Sistema.</p>
                <div class="legal-note"><strong>Fundamento:</strong> Artículos 2104, 2108 y 2109 del Código Civil Federal.</div>
            </div>

            <div class="section">
                <h2><span class="num">8</span> Conductas Prohibidas</h2>
                <p>Queda estrictamente prohibido:</p>
                <ul>
                    <li>Acceder o intentar acceder a cuentas de otros usuarios</li>
                    <li>Falsificar, alterar o manipular información del Sistema</li>
                    <li>Realizar ingeniería inversa, descompilar o desensamblar el Sistema</li>
                    <li>Introducir virus, troyanos, malware o código malicioso</li>
                    <li>Extraer información masiva sin autorización (scraping)</li>
                </ul>
                <div class="warning-note"><strong>⚠️ Consecuencias:</strong> Violaciones sancionadas conforme a los artículos 211 bis 1 al 211 bis 7 del Código Penal Federal (Delitos Informáticos).</div>
            </div>

            <div class="section">
                <h2><span class="num">9</span> Jurisdicción y Ley Aplicable</h2>
                <p>Este contrato se rige por las leyes de los <strong>Estados Unidos Mexicanos</strong>. Para cualquier controversia, las partes se someten expresamente a los tribunales competentes del <strong>Estado de México</strong>, renunciando a cualquier otro fuero.</p>
                <div class="legal-note"><strong>Fundamento:</strong> Artículo 1093 del Código de Comercio y artículos 156 y 157 del Código Federal de Procedimientos Civiles.</div>
            </div>

            <div class="section">
                <h2><span class="num">10</span> Contacto para Soporte Técnico</h2>
                <p>Para reportar errores, fallas o solicitar soporte técnico:</p>
                <div class="contact-card">
                    <div class="icon"><i class="fas fa-phone-alt"></i></div>
                    <div class="info">
                        <span class="label">Línea de soporte técnico</span>
                        <span class="number">722 172 5762</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="terms-footer">
            <p>Última actualización: Enero 2026 | Versión 1.0</p>
            <a href="javascript:history.back()" class="btn-back"><i class="fas fa-arrow-left"></i> Regresar</a>
        </div>
    </div>
</div>
</body>
</html>
