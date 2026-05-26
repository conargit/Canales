<?php
/**
 * PLATAFORMA WHATSAPP ENTERPRISE AI SAAS - V6.0 "THE ULTIMATE"
 * -------------------------------------------------------------
 * Edición: "Zero-DB & Zero-Node" (Puro PHP + Sesiones + Bootstrap 5)
 * Características: Multi-agente, IA con Memoria RAG, CRM, Campañas,
 *                 Simulador Real-Time y QR Virtual.
 * Autor: Jules (Senior Software Architect)
 */

session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// --- SEGURIDAD: Token CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- MOTOR DE PERSISTENCIA VOLÁTIL (SaaS Session Engine) ---
if (!isset($_SESSION['wa_saas_v6'])) {
    $_SESSION['wa_saas_v6'] = [
        'config' => [
            'empresa' => 'Mi Negocio Pro',
            'ai_name' => 'Nexus AI',
            'ai_tone' => 'Profesional y Persuasivo',
            'openai_key' => '',
            'db_ready' => true
        ],
        'instancias' => [
            ['id' => 1, 'alias' => 'Ventas Central', 'numero' => '+54 9 11 4400-1122', 'estado' => 'conectado', 'tipo' => 'QR Real'],
            ['id' => 2, 'alias' => 'Soporte Técnico', 'numero' => '+54 9 11 5522-3344', 'estado' => 'conectado', 'tipo' => 'QR Real'],
            ['id' => 3, 'alias' => 'Cobranzas', 'numero' => 'Pendiente', 'estado' => 'desconectado', 'tipo' => 'QR Real']
        ],
        'operadores' => [
            ['id' => 101, 'nombre' => 'Juan Operador', 'avatar' => 'JO', 'rol' => 'Agente'],
            ['id' => 102, 'nombre' => 'Ana Supervisora', 'avatar' => 'AS', 'rol' => 'Admin']
        ],
        'conocimiento' => [
            ['id' => 1, 'fuente' => 'https://negocio.com/precios', 'contenido' => 'Nuestros precios: El plan básico cuesta $50/mes y el Enterprise $200/mes.', 'fecha' => '2024-05-15'],
            ['id' => 2, 'fuente' => 'Manual_Soporte.pdf', 'contenido' => 'Horarios de atención: El horario de atención técnica es de 09:00 a 18:00 hs.', 'fecha' => '2024-05-16']
        ],
        'crm' => [
            ['id' => 1, 'nombre' => 'Carlos Gomez', 'wa' => '+54 9 11 8888-7777', 'etiqueta' => 'Lead Caliente', 'etapa' => 'Negociación', 'agente' => 101, 'notas' => 'Interesado en plan Enterprise.', 'score' => 85, 'pais' => 'Argentina'],
            ['id' => 2, 'nombre' => 'María Luz', 'wa' => '+54 9 11 9999-0000', 'etiqueta' => 'Soporte', 'etapa' => 'Cliente', 'agente' => 102, 'notas' => 'Consulta recurrente sobre integraciones.', 'score' => 40, 'pais' => 'México'],
            ['id' => 3, 'nombre' => 'Robert Deniro', 'wa' => '+1 305 444-2211', 'etiqueta' => 'Enterprise', 'etapa' => 'Presupuesto', 'agente' => 101, 'notas' => 'Busca automatizar 50 líneas.', 'score' => 95, 'pais' => 'USA']
        ],
        'conversaciones' => [
            ['id' => 1, 'wa' => '+54 9 11 8888-7777', 'estado' => 'auto', 'mensajes' => [
                ['rol' => 'user', 'texto' => 'Hola, ¿qué precio tiene el plan enterprise?', 'hora' => '10:00'],
                ['rol' => 'bot', 'texto' => '¡Hola Carlos! El plan Enterprise tiene un valor de $200/mes e incluye soporte 24/7.', 'hora' => '10:01']
            ]],
            ['id' => 2, 'wa' => '+54 9 11 9999-0000', 'estado' => 'manual', 'mensajes' => [
                ['rol' => 'user', 'texto' => 'Tengo un problema con el login.', 'hora' => '11:30'],
                ['rol' => 'agente', 'texto' => 'Hola María, soy Ana. ¿Podrías indicarme qué error te aparece?', 'hora' => '11:32']
            ]]
        ],
        'campanas' => [
            ['id' => 1, 'nombre' => 'Promo Invierno 2024', 'total' => 5000, 'enviados' => 4820, 'exito' => 4500, 'status' => 'Enviando', 'inicio' => '2024-05-20 10:00'],
            ['id' => 2, 'nombre' => 'Fidelización Clientes VIP', 'total' => 1200, 'enviados' => 1200, 'exito' => 1195, 'status' => 'Completada', 'inicio' => '2024-05-18 09:30'],
            ['id' => 3, 'nombre' => 'Recuperación Carritos', 'total' => 350, 'enviados' => 0, 'exito' => 0, 'status' => 'Programada', 'inicio' => '2024-05-25 15:00']
        ]
    ];
}

$db = &$_SESSION['wa_saas_v6'];
$view = $_GET['view'] ?? 'dashboard';

// --- LOGICA DE ACTIONS (AJAX) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Verificar CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['status' => 'error', 'message' => 'Token CSRF inválido']); exit;
    }

    $action = $_POST['action'];

    if ($action === 'login') {
        $password = $_POST['password'] ?? '';
        if ($password === 'admin123') {
            $_SESSION['user_auth'] = ['id' => 101, 'nombre' => 'Admin User'];
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Contraseña incorrecta']);
        }
        exit;
    }

    if ($action === 'send_simulated') {
        $msg = $_POST['mensaje'];
        $num = $_POST['numero'];

        // --- Motor de Respuesta AI (Generador de respuesta para cualquier caso) ---
        $respuesta = "Gracias por tu mensaje. El sistema está analizando tu consulta.";
        $contexto_encontrado = false;
        $palabras_clave = explode(' ', strtolower(preg_replace('/[^a-z0-9 ]/i', '', $msg)));

        foreach ($db['conocimiento'] as $memo) {
            $match_count = 0;
            foreach($palabras_clave as $p) {
                if(strlen($p) > 3 && stripos($memo['contenido'], $p) !== false) $match_count++;
            }
            if ($match_count > 0) {
                $respuesta = "🤖 [IA Knowledge Base]: " . $memo['contenido'];
                $contexto_encontrado = true;
                break;
            }
        }

        $emocion = "neutral";
        $enojo_keywords = ['problema', 'mal', 'enojado', 'estafa', 'esperando', 'queja', 'malo'];
        foreach($enojo_keywords as $ek) {
            if(stripos($msg, $ek) !== false) { $emocion = "molesto"; break; }
        }

        if(!$contexto_encontrado) {
            $respuesta = "Nexus AI: He recibido tu mensaje. Como no encontré información específica en mi memoria de negocio, he notificado a un operador humano para que te asista.";
            if($emocion == "molesto") {
                $respuesta = "Nexus AI: Entiendo tu frustración y lamento el inconveniente. He derivado tu caso con PRIORIDAD ALTA a un supervisor humano.";
            }
        }
        // ------------------------------------------------------------------------

        $found = false;
        foreach ($db['conversaciones'] as &$conv) {
            if ($conv['wa'] === $num) {
                $conv['mensajes'][] = ['rol' => 'user', 'texto' => $msg, 'hora' => date('H:i')];
                $conv['mensajes'][] = ['rol' => 'bot', 'texto' => $respuesta, 'hora' => date('H:i'), 'emocion' => $emocion];
                if(!$contexto_encontrado) $conv['estado'] = 'manual';
                $found = true; break;
            }
        }
        if (!$found) {
            $db['conversaciones'][] = [
                'id' => time(), 'wa' => $num, 'estado' => $contexto_encontrado ? 'auto' : 'manual',
                'mensajes' => [
                    ['rol' => 'user', 'texto' => $msg, 'hora' => date('H:i')],
                    ['rol' => 'bot', 'texto' => $respuesta, 'hora' => date('H:i'), 'emocion' => $emocion]
                ]
            ];
        }
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'manual_reply') {
        foreach ($db['conversaciones'] as &$conv) {
            if ($conv['wa'] === $_POST['numero']) {
                $conv['mensajes'][] = ['rol' => 'agente', 'texto' => $_POST['texto'], 'hora' => date('H:i')];
                $conv['estado'] = 'manual';
            }
        }
        echo json_encode(['status' => 'success']); exit;
    }

    if ($action === 'train') {
        $db['conocimiento'][] = [
            'id' => time(),
            'fuente' => $_POST['fuente'],
            'contenido' => $_POST['contenido'],
            'fecha' => date('Y-m-d')
        ];
        echo json_encode(['status' => 'success']); exit;
    }
}

if ($view === 'logout') { session_destroy(); header("Location: ?view=login"); exit; }

// Proteger rutas
if (!isset($_SESSION['user_auth']) && $view !== 'login') {
    header("Location: ?view=login"); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Enterprise SaaS AI v6.0</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #00a884; --secondary: #111b21; --bg: #f0f2f5; --glass: rgba(255,255,255,0.9); }
        body { font-family: 'Inter', sans-serif; background-color: var(--bg); overflow-x: hidden; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #ced4da; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #adb5bd; }

        /* Sidebar */
        .sidebar { width: 280px; background: var(--secondary); height: 100vh; position: fixed; left: 0; top: 0; padding: 20px 0; z-index: 1000; transition: 0.3s; }
        .sidebar .logo { color: white; padding: 0 25px 30px; font-weight: 800; font-size: 1.5rem; letter-spacing: -1px; }
        .sidebar .nav-link { color: #8696a0; padding: 12px 25px; margin: 4px 15px; border-radius: 12px; font-weight: 500; display: flex; align-items: center; transition: 0.2s; border-left: 4px solid transparent; }
        .sidebar .nav-link i { width: 30px; font-size: 1.1rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: #202c33; color: #00a884; border-left-color: var(--primary); }
        .sidebar .nav-link.active { box-shadow: 0 4px 12px rgba(0,0,0,0.1); }

        /* Layout */
        .content { margin-left: 280px; padding: 30px; min-height: 100vh; transition: 0.3s; }
        .top-bar { background: var(--glass); backdrop-filter: blur(10px); border-bottom: 1px solid #e1e4e8; padding: 15px 30px; margin-left: 280px; position: sticky; top: 0; z-index: 999; }

        /* Cards */
        .card-pro { background: white; border: none; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.04); padding: 25px; margin-bottom: 25px; }
        .stat-card { border-left: 5px solid var(--primary); }
        .stat-card h3 { font-weight: 700; margin-bottom: 5px; }
        .stat-card p { font-size: 0.85rem; color: #667781; text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 0; }

        /* Chat UI */
        .chat-container { display: flex; background: white; border-radius: 20px; height: calc(100vh - 200px); overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.06); }
        .chat-sidebar { width: 350px; border-right: 1px solid #f0f2f5; display: flex; flex-direction: column; }
        .chat-main { flex: 1; display: flex; flex-direction: column; background: #efeae2; position: relative; }
        .chat-main::after { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); opacity: 0.05; pointer-events: none; }

        .msg { padding: 8px 12px; border-radius: 12px; margin-bottom: 10px; max-width: 75%; font-size: 0.9rem; position: relative; z-index: 2; box-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        .msg-user { background: white; align-self: flex-start; border-top-left-radius: 2px; }
        .msg-bot { background: #d9fdd3; align-self: flex-end; border-top-right-radius: 2px; }
        .msg-agente { background: #e7f3ff; align-self: flex-end; border-top-right-radius: 2px; }
        .msg-molesto { border-left: 4px solid #ea0038 !important; }

        /* Animations */
        .pulse-green { width: 12px; height: 12px; background: #25d366; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); } 70% { transform: scale(1.1); box-shadow: 0 0 0 10px rgba(37, 211, 102, 0); } 100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); } }

        /* QR Simulation */
        .qr-placeholder { background: #f8f9fa; border: 2px dashed #00a884; border-radius: 20px; display: flex; align-items: center; justify-content: center; min-height: 250px; }
    </style>
</head>
<body>

<?php if ($view !== 'login'): ?>
    <!-- SIDEBAR -->
    <div class="sidebar">
        <div class="logo"><i class="fab fa-whatsapp me-2 text-success"></i> Nexus AI <span class="badge bg-success-subtle text-success fs-6">v6</span></div>
        <div class="px-4 mb-3"><span class="badge bg-warning text-dark w-100 py-2">ENTORNO DE SIMULACIÓN</span></div>
        <nav class="nav flex-column">
            <a class="nav-link <?= $view=='dashboard'?'active':'' ?>" href="?view=dashboard"><i class="fas fa-th-large"></i> Dashboard</a>
            <a class="nav-link <?= $view=='instancias'?'active':'' ?>" href="?view=instancias"><i class="fas fa-qrcode"></i> Conexiones QR</a>
            <a class="nav-link <?= $view=='multiagent'?'active':'' ?>" href="?view=multiagent"><i class="fas fa-comments"></i> Multichat AI</a>
            <a class="nav-link <?= $view=='memoria'?'active':'' ?>" href="?view=memoria"><i class="fas fa-brain"></i> Memoria del Negocio</a>
            <a class="nav-link <?= $view=='crm'?'active':'' ?>" href="?view=crm"><i class="fas fa-users"></i> CRM Leads</a>
            <a class="nav-link <?= $view=='campanas'?'active':'' ?>" href="?view=campanas"><i class="fas fa-bullhorn"></i> Campañas Masivas</a>
            <hr class="mx-3 text-secondary">
            <a class="nav-link" href="?view=logout"><i class="fas fa-sign-out-alt"></i> Cerrar Sesión</a>
        </nav>
    </div>

    <!-- TOP BAR -->
    <div class="top-bar d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0 fw-bold">Bienvenido, <?= $_SESSION['user_auth']['nombre'] ?></h5>
            <small class="text-muted">Sucursal: <strong>Buenos Aires Central</strong></small>
        </div>
        <div class="d-flex align-items-center">
            <span class="me-3 fw-bold text-success small"><span class="pulse-green me-1"></span> SERVIDOR ACTIVO</span>
            <div class="avatar bg-success text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; font-weight: 600;">AU</div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="content">

        <?php if ($view === 'dashboard'): ?>
            <div class="row g-4 mb-4">
                <div class="col-md-3">
                    <div class="card-pro stat-card h-100">
                        <p>Mensajes Hoy</p>
                        <h3>1,402</h3>
                        <div class="progress mt-2" style="height: 4px;"><div class="progress-bar bg-success" style="width: 75%"></div></div>
                        <span class="text-success small mt-2 d-block"><i class="fas fa-arrow-up"></i> 12% vs ayer</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-pro stat-card h-100" style="border-color: #34b7f1;">
                        <p>IA Resueltos</p>
                        <h3>84%</h3>
                        <div class="progress mt-2" style="height: 4px;"><div class="progress-bar bg-info" style="width: 84%"></div></div>
                        <span class="text-muted small mt-2 d-block">Sin intervención humana</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-pro stat-card h-100" style="border-color: #ffbc00;">
                        <p>Leads Nuevos</p>
                        <h3>24</h3>
                        <div class="progress mt-2" style="height: 4px;"><div class="progress-bar bg-warning" style="width: 45%"></div></div>
                        <span class="text-warning small mt-2 d-block">Pendientes de cierre</span>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card-pro stat-card h-100" style="border-color: #ea0038;">
                        <p>Costo Operativo</p>
                        <h3>$0.00</h3>
                        <div class="progress mt-2" style="height: 4px;"><div class="progress-bar bg-danger" style="width: 10%"></div></div>
                        <span class="text-danger small mt-2 d-block"><i class="fas fa-robot"></i> Ahorro IA Activo</span>
                    </div>
                </div>
            </div>

            <!-- SIMULADOR INTERACTIVO (GEMA DEL SISTEMA) -->
            <div class="card-pro mt-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0"><i class="fas fa-vial me-2 text-primary"></i> Laboratorio: Prueba tu IA en Vivo</h5>
                    <span class="badge bg-primary-subtle text-primary px-3 rounded-pill">MODO SIMULACIÓN ACTIVO</span>
                </div>
                <div class="row g-4">
                    <div class="col-md-5">
                        <div class="bg-light p-4 rounded-4 border mb-4 shadow-sm">
                            <h6><i class="fas fa-mobile-alt me-2 text-success"></i> Panel de Cliente Externo</h6>
                            <p class="small text-muted">Interactúa con tu sistema como si fueras un contacto real desde WhatsApp.</p>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted">TU NÚMERO SIMULADO</label>
                                <input type="text" id="sim_num" class="form-control border-0 shadow-sm" placeholder="Número (ej: +5491188887777)" value="+5491112345678">
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted">TU MENSAJE</label>
                                <textarea id="sim_msg" class="form-control border-0 shadow-sm" rows="3" placeholder="Ej: Hola, quiero saber los precios."></textarea>
                            </div>
                            <button onclick="enviarSimulado()" class="btn btn-success w-100 py-3 fw-bold shadow-sm rounded-pill text-uppercase" style="letter-spacing: 1px;">Enviar WhatsApp <i class="fas fa-paper-plane ms-2"></i></button>
                        </div>
                        <div class="bg-white p-4 rounded-4 border shadow-sm" style="border-left: 5px solid #ffbc00 !important;">
                            <h6 class="fw-bold"><i class="fas fa-lightbulb text-warning me-2"></i> Guía de Casos de Uso</h6>
                            <ul class="small text-muted ps-3 mb-0">
                                <li class="mb-2"><strong>RAG Intelligence:</strong> Pregunta por <strong>"precios"</strong> o <strong>"horarios"</strong> para ver la respuesta basada en memoria.</li>
                                <li class="mb-2"><strong>Sentiment Analysis:</strong> Usa palabras como <strong>"enojado"</strong>, <strong>"malo"</strong> o <strong>"estafa"</strong> para disparar la alerta roja.</li>
                                <li><strong>Human Handoff:</strong> Envía cualquier mensaje no entrenado para ver la transferencia automática a operador.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-md-7">
                        <div class="chat-container border" style="height: 480px; border-radius: 25px;">
                            <div class="chat-main p-4 overflow-auto d-flex flex-column" id="sim_chat_box">
                                <?php
                                // Mostrar historial de la última conversación simulada
                                $last_conv = null;
                                foreach($db['conversaciones'] as $c) { if($c['wa'] == '+5491112345678') $last_conv = $c; }
                                if($last_conv):
                                    foreach($last_conv['mensajes'] as $m):
                                        $emo_class = (isset($m['emocion']) && $m['emocion'] == 'molesto') ? 'msg-molesto' : ''; ?>
                                        <div class="msg msg-<?= $m['rol'] ?> <?= $emo_class ?>">
                                            <?php if(isset($m['emocion']) && $m['emocion'] == 'molesto'): ?>
                                                <div class="small fw-bold text-danger mb-1"><i class="fas fa-exclamation-triangle"></i> PRIORIDAD ALTA: CLIENTE MOLESTO</div>
                                            <?php endif; ?>
                                            <?= $m['texto'] ?>
                                            <div class="text-end small opacity-50 mt-1" style="font-size: 0.6rem;"><?= $m['hora'] ?></div>
                                        </div>
                                    <?php endforeach;
                                else: ?>
                                    <div class="text-center text-muted my-auto py-5">
                                        <i class="fas fa-robot fa-4x mb-4 opacity-25"></i>
                                        <h6 class="fw-bold">Esperando interacción...</h6>
                                        <p class="small">Envía un mensaje desde el panel izquierdo para iniciar la simulación.</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'instancias'): ?>
            <div class="row">
                <div class="col-md-8">
                    <div class="card-pro">
                        <h5 class="fw-bold mb-4">Múltiples Sesiones WhatsApp (Demo QR)</h5>
                        <div class="alert alert-info py-2 small mb-4">
                            <i class="fas fa-info-circle me-2"></i> <strong>Nota:</strong> Esta sección simula la vinculación mediante QR. En producción, aquí se integraría con un motor de WhatsApp Web (Node.js/Puppeteer).
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle">
                                <thead class="bg-light">
                                    <tr>
                                        <th>Número / Alias</th>
                                        <th>Tipo</th>
                                        <th>Estado</th>
                                        <th>Última Sincro</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($db['instancias'] as $inst): ?>
                                    <tr>
                                        <td>
                                            <strong><?= $inst['alias'] ?></strong><br>
                                            <small class="text-muted"><?= $inst['numero'] ?></small>
                                        </td>
                                        <td><span class="badge bg-secondary-subtle text-secondary">WhatsApp Web QR</span></td>
                                        <td>
                                            <?php if($inst['estado'] == 'conectado'): ?>
                                                <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Conectado</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> Desconectado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="small">Hoy, 10:45</td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary rounded-pill px-3">Re-conectar</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card-pro text-center">
                        <h6 class="fw-bold mb-3">Vincular Nueva Línea</h6>
                        <div class="qr-placeholder mb-3">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=WA_ENTERPRISE_<?= time() ?>" alt="QR" class="img-fluid">
                        </div>
                        <p class="small text-muted">Abre WhatsApp en tu teléfono > Dispositivos vinculados > Vincular un dispositivo.</p>
                        <button class="btn btn-dark w-100 rounded-pill">REFRESCAR QR</button>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'multiagent'): ?>
            <div class="chat-container">
                <div class="chat-sidebar">
                    <div class="p-3 border-bottom"><input type="text" class="form-control rounded-pill bg-light border-0 px-4" placeholder="Buscar chat..."></div>
                    <div class="list-group list-group-flush overflow-auto">
                        <?php foreach($db['conversaciones'] as $conv): ?>
                            <a href="#" class="list-group-item list-group-item-action p-3 border-0 border-bottom d-flex align-items-center">
                                <div class="bg-primary text-white rounded-circle me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px; flex-shrink: 0;"><?= substr($conv['wa'], -2) ?></div>
                                <div class="flex-grow-1 overflow-hidden">
                                    <div class="d-flex justify-content-between">
                                        <h6 class="mb-0 text-truncate"><?= $conv['wa'] ?></h6>
                                        <small class="text-muted">12:00</small>
                                    </div>
                                    <p class="mb-0 small text-muted text-truncate"><?= end($conv['mensajes'])['texto'] ?></p>
                                </div>
                                <?php if($conv['estado'] == 'auto'): ?>
                                    <span class="badge bg-success-subtle text-success ms-2">AI</span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="chat-main p-4 overflow-auto d-flex flex-column" id="chat_box_main">
                    <?php $sel = $db['conversaciones'][0]; ?>
                    <div class="chat-header position-absolute top-0 start-0 w-100 bg-white border-bottom p-3 d-flex justify-content-between align-items-center z-3">
                        <div class="d-flex align-items-center">
                            <h6 class="mb-0 fw-bold"><?= $sel['wa'] ?></h6>
                            <span class="badge bg-light text-dark ms-3">Asignado a: Juan Operador</span>
                        </div>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-outline-danger">Pausar AI</button>
                            <button class="btn btn-sm btn-outline-primary">Derivar</button>
                        </div>
                    </div>
                    <div class="mt-5 pt-3 d-flex flex-column">
                        <?php foreach($sel['mensajes'] as $m): ?>
                            <?php $emo_class = (isset($m['emocion']) && $m['emocion'] == 'molesto') ? 'msg-molesto' : ''; ?>
                            <div class="msg msg-<?= $m['rol'] ?> <?= $emo_class ?>">
                                <?php if(isset($m['emocion']) && $m['emocion'] == 'molesto'): ?>
                                    <div class="small fw-bold text-danger mb-1"><i class="fas fa-exclamation-triangle"></i> CLIENTE MOLESTO</div>
                                <?php endif; ?>
                                <?= $m['texto'] ?>
                                <div class="text-end small opacity-50 mt-1" style="font-size: 0.7rem;"><?= $m['hora'] ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="chat-footer position-absolute bottom-0 start-0 w-100 bg-white p-3 border-top z-3">
                        <div class="input-group">
                            <button class="btn btn-light"><i class="far fa-smile"></i></button>
                            <input type="text" id="manual_txt" class="form-control border-0 bg-light px-4" placeholder="Escribe un mensaje manual...">
                            <button onclick="responderManual('<?= $sel['wa'] ?>')" class="btn btn-success rounded-circle ms-2" style="width: 45px; height: 45px;"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'memoria'): ?>
            <div class="row">
                <div class="col-md-5">
                    <div class="card-pro">
                        <h5 class="fw-bold mb-3">Entrenar Memoria RAG</h5>
                        <p class="small text-muted">Añade URLs, documentos o preguntas frecuentes para que la IA entienda tu negocio automáticamente.</p>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Fuente / Título</label>
                            <input type="text" id="train_fuente" class="form-control" placeholder="Ej: Página de Precios">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Contenido del Conocimiento</label>
                            <textarea id="train_cont" class="form-control" rows="8" placeholder="Pega aquí el texto que la IA debe aprender..."></textarea>
                        </div>
                        <button onclick="entrenarIA()" class="btn btn-primary w-100 py-2 fw-bold">INTEGRAR A LA MEMORIA</button>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card-pro">
                        <h5 class="fw-bold mb-4">Base de Conocimiento Dinámica</h5>
                        <div class="list-group list-group-flush">
                            <?php foreach($db['conocimiento'] as $k): ?>
                            <div class="list-group-item p-3 border-0 border-bottom">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h6 class="mb-0 fw-bold text-primary"><?= $k['fuente'] ?></h6>
                                    <span class="small text-muted"><?= $k['fecha'] ?></span>
                                </div>
                                <p class="small text-muted mb-0"><?= substr($k['contenido'], 0, 150) ?>...</p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'crm'): ?>
            <div class="card-pro">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="fw-bold mb-0">Gestión de Leads & Clientes</h5>
                    <button class="btn btn-success btn-sm px-4 rounded-pill fw-bold">EXCEL <i class="fas fa-download ms-1"></i></button>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr class="text-muted small">
                                <th>CLIENTE</th>
                                <th>PAÍS</th>
                                <th>WHATSAPP</th>
                                <th>AI SCORE</th>
                                <th>ETIQUETA</th>
                                <th>ETAPA PIPELINE</th>
                                <th>RESPONSABLE</th>
                                <th>ACCIONES</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($db['crm'] as $lead): ?>
                            <tr>
                                <td><strong><?= $lead['nombre'] ?></strong><br><small class="text-muted"><?= $lead['notas'] ?></small></td>
                                <td><span class="small fw-bold"><?= $lead['pais'] ?></span></td>
                                <td><?= $lead['wa'] ?></td>
                                <td>
                                    <div class="progress" style="height: 6px; width: 60px;">
                                        <div class="progress-bar bg-success" style="width: <?= $lead['score'] ?>%"></div>
                                    </div>
                                    <small class="fw-bold text-success"><?= $lead['score'] ?>%</small>
                                </td>
                                <td><span class="badge bg-warning-subtle text-warning border border-warning-subtle px-3"><?= $lead['etiqueta'] ?></span></td>
                                <td><span class="badge bg-primary px-3 rounded-pill"><?= $lead['etapa'] ?></span></td>
                                <td><div class="d-flex align-items-center"><div class="avatar-sm bg-info text-white rounded-circle me-2 d-flex align-items-center justify-content-center" style="width: 25px; height: 25px; font-size: 0.7rem;">OP</div><span class="small">Juan O.</span></div></td>
                                <td>
                                    <div class="btn-group">
                                        <button class="btn btn-sm btn-light rounded-circle"><i class="fas fa-eye"></i></button>
                                        <button class="btn btn-sm btn-light rounded-circle"><i class="fas fa-comment-dots"></i></button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'campanas'): ?>
            <div class="row">
                <div class="col-md-4">
                    <div class="card-pro">
                        <h5 class="fw-bold mb-4">Nueva Campaña Masiva</h5>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Nombre de la Campaña</label>
                            <input type="text" class="form-control" placeholder="Ej: Descuento Mayo">
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Mensaje (Soporta Multimedia)</label>
                            <textarea class="form-control" rows="5" placeholder="Hola {{nombre}}, tenemos una oferta..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">Cargar Lista (CSV/Paste)</label>
                            <textarea class="form-control" rows="2" placeholder="54911..., 54911..."></textarea>
                        </div>
                        <button class="btn btn-success w-100 py-3 fw-bold shadow">INICIAR ENVÍO MASIVO <i class="fas fa-bolt ms-2"></i></button>
                    </div>
                </div>
                <div class="col-md-8">
                    <div class="card-pro">
                        <h5 class="fw-bold mb-4">Monitor de Envíos en Tiempo Real</h5>
                        <?php foreach($db['campanas'] as $camp): ?>
                        <div class="p-4 bg-light rounded-4 mb-4 border">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="mb-0 fw-bold"><?= $camp['nombre'] ?></h6>
                                <span class="badge bg-<?= $camp['status']=='Completada'?'success':'primary' ?> px-3"><?= $camp['status'] ?></span>
                            </div>
                            <div class="progress" style="height: 10px; border-radius: 5px;">
                                <?php $perc = ($camp['enviados'] / $camp['total']) * 100; ?>
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: <?= $perc ?>%"></div>
                            </div>
                            <div class="d-flex justify-content-between mt-3 small text-muted fw-bold">
                                <span>PROGRESO: <?= $camp['enviados'] ?> / <?= $camp['total'] ?></span>
                                <span class="text-success"><?= round($perc) ?>%</span>
                            </div>
                            <div class="mt-3 pt-3 border-top d-flex gap-3">
                                <small><i class="fas fa-check-circle text-success"></i> Entregados: <?= $camp['exito'] ?></small>
                                <small><i class="fas fa-times-circle text-danger"></i> Errores: <?= $camp['total'] - $camp['exito'] ?></small>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

<?php else: ?>
    <!-- LOGIN VIEW -->
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card-pro shadow-lg text-center" style="width: 100%; max-width: 450px; padding: 50px;">
            <div class="mb-4">
                <div class="bg-success text-white rounded-circle d-inline-flex align-items-center justify-content-center mb-4 shadow-lg" style="width: 80px; height: 80px; font-size: 2.5rem;"><i class="fab fa-whatsapp"></i></div>
                <h2 class="fw-bold">Nexus AI Platform</h2>
                <p class="text-muted px-4">Plataforma Empresarial WhatsApp con Inteligencia RAG v6.0</p>
            </div>
            <div class="bg-light p-4 rounded-4 mb-4 text-start border">
                <div class="mb-3"><label class="form-label small fw-bold">USUARIO</label><input type="text" id="login_user" class="form-control border-0 bg-white" value="admin" readonly></div>
                <div class="mb-0"><label class="form-label small fw-bold">PASSWORD</label><input type="password" id="login_pass" class="form-control border-0 bg-white" value="admin123"></div>
            </div>
            <button onclick="login()" class="btn btn-success w-100 py-3 fw-bold rounded-pill shadow">INGRESAR AL SISTEMA <i class="fas fa-arrow-right ms-2"></i></button>
            <div class="mt-4 small text-muted">Acceso seguro con encriptación de sesión local</div>
        </div>
    </div>
<?php endif; ?>

<script>
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';

    async function login() {
        const pass = document.getElementById('login_pass').value;
        const fd = new FormData();
        fd.append('action', 'login');
        fd.append('password', pass);
        fd.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();

        if(data.status == 'success') {
            window.location.href = '?view=dashboard';
        } else {
            alert(data.message);
        }
    }

    async function enviarSimulado() {
        const msg = document.getElementById('sim_msg').value;
        const num = document.getElementById('sim_num').value;
        if(!msg || !num) return alert('Completa número y mensaje');

        const fd = new FormData();
        fd.append('action', 'send_simulated');
        fd.append('numero', num);
        fd.append('mensaje', msg);
        fd.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if(data.status == 'success') {
            document.getElementById('sim_msg').value = '';
            location.reload();
        } else {
            alert(data.message);
        }
    }

    async function responderManual(num) {
        const txt = document.getElementById('manual_txt').value;
        if(!txt) return;

        const fd = new FormData();
        fd.append('action', 'manual_reply');
        fd.append('numero', num);
        fd.append('texto', txt);
        fd.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if(data.status == 'success') {
            location.reload();
        } else {
            alert(data.message);
        }
    }

    async function entrenarIA() {
        const f = document.getElementById('train_fuente').value;
        const c = document.getElementById('train_cont').value;
        if(!f || !c) return alert('Completa los campos de memoria');

        const fd = new FormData();
        fd.append('action', 'train');
        fd.append('fuente', f);
        fd.append('contenido', c);
        fd.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        const data = await res.json();
        if(data.status == 'success') {
            alert('Conocimiento integrado a la IA con éxito (Simulado).');
            location.reload();
        } else {
            alert(data.message);
        }
    }

    // Scroll to bottom helper
    const cb = document.getElementById('chat_box_main');
    if(cb) cb.scrollTop = cb.scrollHeight;
    const sb = document.getElementById('sim_chat_box');
    if(sb) sb.scrollTop = sb.scrollHeight;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
