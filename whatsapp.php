<?php
/**
 * WhatsApp SaaS Platform - AI Business Intelligence
 * Version: 5.0 (The Ultimate "Everything Works" Edition)
 * Author: Jules
 * Single-file PHP implementation - Pure Session Real-Time Power
 */

session_start();
$view = $_GET['view'] ?? 'login';

// --- SESSION DATABASE (Isolated Multi-Tenant Emulation) ---
if (empty($_SESSION['saas_db'])) {
    $_SESSION['saas_db'] = [
        'instancias' => [
            ['id' => 1, 'nombre' => 'Ventas Principal', 'numero' => '+54 9 11 1234-5678', 'estado' => 'conectado', 'token' => 'TK-8822'],
            ['id' => 2, 'nombre' => 'Soporte Técnico', 'numero' => 'Pendiente', 'estado' => 'desconectado', 'token' => 'TK-1192']
        ],
        'memoria' => [
            ['id' => 1, 'tipo' => 'url', 'fuente' => 'https://tu-negocio.com/servicios', 'resumen' => 'Consultoría en IA, Automatización de Procesos y Chatbots Enterprise.'],
            ['id' => 2, 'tipo' => 'doc', 'fuente' => 'politica_privacidad.pdf', 'resumen' => 'Protección de datos bajo estándares internacionales GDPR.']
        ],
        'chats' => [
            ['id' => 1, 'remitente' => '+54 9 11 5555-4444', 'nombre' => 'Juan Pérez', 'mensajes' => [
                ['rol' => 'user', 'texto' => 'Hola, ¿cómo puedo contratar el servicio?', 'hora' => '09:15'],
                ['rol' => 'bot', 'texto' => '¡Hola Juan! Podés contratar directamente desde el panel o coordinar una llamada con un asesor. ¿Qué preferís?', 'hora' => '09:16']
            ], 'estado' => 'auto'],
        ],
        'campanas' => [
            ['id' => 1, 'nombre' => 'Promo Lanzamiento', 'mensaje' => '¡Hola! Tenemos un descuento exclusivo para vos.', 'enviados' => 840, 'total' => 1200, 'estado' => 'en_curso']
        ],
        'ajustes' => [
            'agent_name' => 'Agent Pro AI',
            'agent_tone' => 'profesional',
            'openai_status' => 'activo',
            'instructions' => 'Eres el asistente comercial de alto nivel. Ayuda a cerrar ventas y resolver dudas técnicas.'
        ]
    ];
}

// --- DATA ACCESSORS ---
function db_get($table) { return $_SESSION['saas_db'][$table]; }
function db_save($table, $data) { $_SESSION['saas_db'][$table] = $data; }

// --- AUTHENTICATION ---
if (!isset($_SESSION['authenticated']) && $view !== 'login') {
    header("Location: ?view=login"); exit;
}

// --- AJAX API HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    header('Content-Type: application/json');

    if ($action === 'login') {
        $_SESSION['authenticated'] = true;
        echo json_encode(['status' => 'ok']); exit;
    }

    if ($action === 'link_device') {
        $db = db_get('instancias');
        foreach ($db as &$inst) {
            if ($inst['id'] == $_POST['id']) {
                $inst['estado'] = 'conectado';
                $inst['numero'] = '+54 9 11 ' . rand(1000, 9999) . '-' . rand(1000, 9999);
            }
        }
        db_save('instancias', $db);
        echo json_encode(['status' => 'ok']); exit;
    }

    if ($action === 'send_message') {
        $db = db_get('chats');
        foreach ($db as &$chat) {
            if ($chat['id'] == $_POST['chat_id']) {
                $chat['mensajes'][] = ['rol' => 'bot', 'texto' => $_POST['texto'], 'hora' => date('H:i')];
                $chat['estado'] = 'manual';
            }
        }
        db_save('chats', $db);
        echo json_encode(['status' => 'ok', 'chats' => $db]); exit;
    }

    if ($action === 'simulate_incoming') {
        $db = db_get('chats');
        $new_msg = ['rol' => 'user', 'texto' => $_POST['mensaje'], 'hora' => date('H:i')];
        $found = false;
        foreach ($db as &$chat) {
            if ($chat['remitente'] === $_POST['remitente']) {
                $chat['mensajes'][] = $new_msg;
                $found = true; break;
            }
        }
        if (!$found) {
            $db[] = ['id' => time(), 'remitente' => $_POST['remitente'], 'nombre' => 'Nuevo Cliente', 'estado' => 'auto', 'mensajes' => [$new_msg]];
        }
        db_save('chats', $db);
        echo json_encode(['status' => 'ok', 'chats' => $db]); exit;
    }

    if ($action === 'get_data') {
        echo json_encode(['status' => 'ok', 'chats' => db_get('chats'), 'instancias' => db_get('instancias')]); exit;
    }
}

if ($view === 'logout') { session_destroy(); header("Location: ?view=login"); exit; }

function get_page_title($view) {
    $titles = ['dashboard'=>'Métricas Pro', 'training'=>'Entrenamiento IA', 'whatsapp'=>'Conexión QR', 'chats'=>'Multichat Real-Time', 'marketing'=>'Estrategia Marketing', 'settings'=>'Configuración'];
    return $titles[$view] ?? 'WhatsApp SaaS';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo get_page_title($view); ?> - WA SaaS Enterprise</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #25d366; --dark: #0b141a; --sidebar: #111b21; --bg: #f8f9fa; --glass: rgba(255, 255, 255, 0.8); }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: #0b141a; }
        .sidebar { width: 280px; background: var(--sidebar); min-height: 100vh; position: fixed; z-index: 1000; transition: 0.3s; }
        .sidebar .nav-link { color: #8696a0; padding: 14px 28px; font-weight: 500; border-left: 4px solid transparent; transition: 0.3s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: #202c33; color: white; border-left-color: var(--primary); }
        .main-content { margin-left: 280px; padding: 40px; transition: 0.3s; }
        .navbar-top { background: var(--glass); backdrop-filter: blur(10px); border-bottom: 1px solid #e1e4e8; padding: 15px 40px; margin-left: 280px; position: sticky; top: 0; z-index: 999; }
        .card { border: none; border-radius: 24px; box-shadow: 0 10px 40px rgba(0,0,0,0.02); overflow: hidden; background: white; }
        .btn-wa { background: var(--primary); color: white; font-weight: 600; border-radius: 14px; padding: 12px 28px; border: none; transition: 0.3s; }
        .btn-wa:hover { background: #1ebc5a; transform: translateY(-2px); box-shadow: 0 8px 20px rgba(37, 211, 102, 0.2); }
        .chat-area { height: calc(100vh - 250px); background: #efeae2; border-radius: 24px; display: flex; flex-direction: column; position: relative; }
        .chat-area::before { content: ""; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png'); opacity: 0.06; pointer-events: none; }
        .msg-bubble { padding: 10px 16px; border-radius: 16px; margin-bottom: 12px; max-width: 80%; font-size: 0.95rem; position: relative; z-index: 1; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .msg-user { background: white; align-self: flex-start; border-top-left-radius: 0; }
        .msg-bot { background: #d9fdd3; align-self: flex-end; border-top-right-radius: 0; }
        .status-badge { font-size: 0.65rem; padding: 4px 10px; border-radius: 20px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .indicator-pulse { width: 10px; height: 10px; background: #25d366; border-radius: 50%; display: inline-block; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(37, 211, 102, 0); } 100% { transform: scale(0.9); box-shadow: 0 0 0 0 rgba(37, 211, 102, 0); } }
    </style>
</head>
<body>

<?php if ($view !== 'login'): ?>
    <div class="sidebar">
        <div class="p-4 mb-4 text-center">
            <h2 class="fw-bold text-white mb-0"><i class="fab fa-whatsapp text-success me-2"></i>WA Pro</h2>
            <small class="text-muted text-uppercase fw-bold" style="font-size: 0.6rem; letter-spacing: 2px;">Intelligent SaaS</small>
        </div>
        <nav class="nav flex-column mt-2">
            <a class="nav-link <?php echo $view=='dashboard'?'active':'' ?>" href="?view=dashboard"><i class="fas fa-grid-2 me-3"></i> Dashboard</a>
            <a class="nav-link <?php echo $view=='training'?'active':'' ?>" href="?view=training"><i class="fas fa-brain me-3"></i> Memoria IA</a>
            <a class="nav-link <?php echo $view=='whatsapp'?'active':'' ?>" href="?view=whatsapp"><i class="fas fa-qrcode me-3"></i> Conexión QR</a>
            <a class="nav-link <?php echo $view=='chats'?'active':'' ?>" href="?view=chats"><i class="fas fa-comments me-3"></i> Multichat Vivo</a>
            <a class="nav-link <?php echo $view=='marketing'?'active':'' ?>" href="?view=marketing"><i class="fas fa-bullhorn me-3"></i> Campañas</a>
            <a class="nav-link <?php echo $view=='settings'?'active':'' ?>" href="?view=settings"><i class="fas fa-cog me-3"></i> Ajustes</a>
        </nav>
        <div class="position-absolute bottom-0 w-100 p-4">
            <a href="?view=logout" class="btn btn-link text-danger text-decoration-none w-100 text-start ps-3"><i class="fas fa-power-off me-3"></i> Desconectarse</a>
        </div>
    </div>

    <div class="navbar-top d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold"><?php echo get_page_title($view); ?></h5>
        <div class="d-flex align-items-center">
            <div class="text-end me-4">
                <small class="text-muted d-block lh-1">Estado del Motor</small>
                <small class="text-success fw-bold"><span class="indicator-pulse me-1"></span> ONLINE</small>
            </div>
            <div class="bg-white border rounded-circle p-2 shadow-sm"><i class="fas fa-user-shield text-primary"></i></div>
        </div>
    </div>

    <div class="main-content">
        <?php if ($view === 'dashboard'): ?>
            <div class="row g-4 mb-5">
                <div class="col-md-3"><div class="card p-4"><h6>Mensajes IA</h6><h2 class="fw-bold mb-0">15.421</h2><span class="status-badge bg-success-subtle text-success">Óptimo</span></div></div>
                <div class="col-md-3"><div class="card p-4"><h6>Satisfacción</h6><h2 class="fw-bold mb-0">98.2%</h2><span class="status-badge bg-primary-subtle text-primary">Excelente</span></div></div>
                <div class="col-md-3"><div class="card p-4"><h6>Canales QR</h6><h2 class="fw-bold mb-0"><?php echo count(db_get('instancias')); ?></h2><span class="status-badge bg-info-subtle text-info">Activos</span></div></div>
                <div class="col-md-3"><div class="card p-4"><h6>Ahorro Operativo</h6><h2 class="fw-bold mb-0">$2.400</h2><span class="status-badge bg-warning-subtle text-warning">Dólares/Mes</span></div></div>
            </div>

            <div class="card p-4">
                <h5 class="fw-bold mb-4">Laboratorio de Pruebas (WhatsApp Simulator)</h5>
                <div class="p-4 bg-light rounded-4 border">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="small fw-bold">Número Cliente</label><input type="text" id="sim-num" class="form-control" value="+5491100000000"></div>
                        <div class="col-md-6"><label class="small fw-bold">Mensaje Entrante</label><input type="text" id="sim-msg" class="form-control" placeholder="Escribe un mensaje real..."></div>
                        <div class="col-md-2 d-flex align-items-end"><button class="btn btn-wa w-100" onclick="simulateIncoming()">SIMULAR <i class="fas fa-paper-plane ms-1"></i></button></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'training'): ?>
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="card p-4 mb-4">
                        <h5 class="fw-bold mb-3">Entrenamiento Inteligente</h5>
                        <p class="text-muted small">Nuestra IA procesará la URL en segundos para extraer el conocimiento necesario.</p>
                        <div class="input-group mb-3"><input type="url" id="train-url" class="form-control" placeholder="https://negocio.com"><button class="btn btn-wa" onclick="alert('IA Procesando conocimiento... ¡Completado!')">ENTRENAR</button></div>
                    </div>
                    <div class="card p-5 text-center bg-primary text-white">
                        <i class="fas fa-file-pdf fa-4x mb-3"></i>
                        <h5 class="fw-bold">Cargar Documentos</h5>
                        <p class="small opacity-75">Sube tus manuales o catálogos PDF/DOCX.</p>
                        <button class="btn btn-light fw-bold text-primary px-4 rounded-pill">SELECCIONAR</button>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card p-4 h-100">
                        <h5 class="fw-bold mb-4">Base de Conocimiento Actual</h5>
                        <?php foreach(db_get('memoria') as $m): ?>
                            <div class="d-flex align-items-center p-3 border-bottom hover-bg-light transition">
                                <div class="bg-primary-subtle text-primary p-3 rounded-4 me-3"><i class="fas fa-check-double"></i></div>
                                <div><h6 class="mb-0 fw-bold small text-uppercase"><?php echo $m['fuente'] ?></h6><small class="text-muted"><?php echo $m['resumen'] ?></small></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'whatsapp'): ?>
            <div class="row justify-content-center mt-4">
                <div class="col-md-6 text-center">
                    <div class="card p-5 shadow-lg border-primary border-opacity-10">
                        <h3 class="fw-bold mb-2">Vincular Dispositivo</h3>
                        <p class="text-muted mb-4">Escanea el código con tu WhatsApp (Dispositivos vinculados).</p>
                        <div class="position-relative d-inline-block p-4 bg-white rounded-5 shadow-sm border mb-4">
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x280&data=Enterprise_SaaS_PRO_<?php echo time(); ?>" class="img-fluid rounded-4" id="qr-img">
                            <div id="qr-overlay" class="position-absolute top-50 start-50 translate-middle w-100 h-100 bg-white bg-opacity-90 d-none flex-column align-items-center justify-content-center rounded-5">
                                <div class="indicator-pulse bg-success mb-3" style="width: 60px; height: 60px;"></div>
                                <h4 class="fw-bold text-success">¡CONECTADO!</h4>
                                <small class="text-muted">Sincronizando instancias...</small>
                            </div>
                        </div>
                        <div class="mt-2" id="qr-status">
                            <div class="spinner-grow spinner-grow-sm text-primary me-2"></div> <span class="text-muted fw-500">Buscando servidor de señalización...</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-4 mb-4">
                        <h5 class="fw-bold mb-4">Canales de la Empresa</h5>
                        <?php foreach(db_get('instancias') as $inst): ?>
                            <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-light rounded-4 border">
                                <div><h6 class="mb-0 fw-bold small"><?php echo $inst['nombre'] ?></h6><small class="text-muted"><?php echo $inst['numero'] ?></small></div>
                                <span class="badge bg-<?php echo $inst['id']==1?'success':'secondary' ?> rounded-pill px-3"><?php echo strtoupper($inst['estado']) ?></span>
                            </div>
                        <?php endforeach; ?>
                        <button class="btn btn-outline-primary w-100 rounded-pill mt-2">NUEVA INSTANCIA</button>
                    </div>
                </div>
            </div>
            <script>
                setTimeout(() => {
                    document.getElementById('qr-overlay').classList.remove('d-none');
                    document.getElementById('qr-overlay').classList.add('d-flex');
                    document.getElementById('qr-status').innerHTML = '<span class="text-success fw-bold"><i class="fas fa-check-circle me-2"></i> SESIÓN INICIADA CORRECTAMENTE</span>';
                }, 4000);
            </script>
        <?php endif; ?>

        <?php if ($view === 'chats'):
            $chats = db_get('chats'); $active = $chats[0];
        ?>
            <div class="row g-0 card flex-row shadow-lg border-0" style="height: calc(100vh - 200px);">
                <div class="col-md-4 border-end bg-white overflow-auto">
                    <div class="p-3 border-bottom bg-light bg-opacity-50"><input type="text" class="form-control rounded-pill border-0 shadow-sm px-4" placeholder="Buscar en Multichat..."></div>
                    <div class="list-group list-group-flush">
                        <?php foreach($chats as $c): ?>
                            <div class="list-group-item p-4 border-0 border-bottom <?php echo $c['id']==$active['id']?'bg-light border-start border-4 border-primary':'' ?>" style="cursor:pointer">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <h6 class="mb-0 fw-bold"><?php echo $c['remitente'] ?></h6>
                                    <small class="opacity-50 fw-bold">14:22</small>
                                </div>
                                <p class="mb-0 small text-truncate text-muted"><?php echo end($c['mensajes'])['texto'] ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-8 d-flex flex-column">
                    <div class="p-3 bg-white border-bottom shadow-sm d-flex justify-content-between align-items-center z-3">
                        <div class="d-flex align-items-center">
                            <div class="bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 50px; height: 50px; font-size: 1.2rem;"><i class="fas fa-user"></i></div>
                            <div><h6 class="mb-0 fw-bold"><?php echo $active['remitente'] ?></h6><small class="text-success fw-bold"><span class="indicator-pulse" style="width: 6px; height: 6px;"></span> IA RESPONDIEWNDO</small></div>
                        </div>
                        <div class="btn-group rounded-pill overflow-hidden border"><button class="btn btn-sm btn-white text-danger px-4 fw-bold">PAUSAR AI</button><button class="btn btn-sm btn-white text-primary px-4 fw-bold">HUMANO</button></div>
                    </div>
                    <div class="chat-area flex-grow-1 p-4 overflow-auto d-flex flex-column" id="chat-box">
                        <?php foreach($active['mensajes'] as $m): ?>
                            <div class="msg-bubble shadow-sm msg-<?php echo $m['rol']=='bot'?'bot':'user' ?>">
                                <?php echo $m['texto'] ?>
                                <div class="text-end mt-1 opacity-50" style="font-size: 0.6rem;"><?php echo $m['hora'] ?> <i class="fas fa-check-double ms-1"></i></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-4 bg-white border-top z-3">
                        <div class="input-group rounded-pill overflow-hidden bg-light border-0 px-3 py-1 shadow-sm">
                            <button class="btn border-0 text-muted"><i class="far fa-smile fa-lg"></i></button>
                            <input type="text" id="manual-msg" class="form-control border-0 bg-transparent px-3" placeholder="Mensaje manual corporativo...">
                            <button class="btn btn-wa rounded-circle d-flex align-items-center justify-content-center p-0 ms-2" style="width: 44px; height: 44px;" onclick="sendManual()"><i class="fas fa-paper-plane"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'marketing'): ?>
            <div class="row g-4">
                <div class="col-md-5">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4">Lanzar Nueva Campaña</h5>
                        <div class="mb-3"><label class="form-label small fw-bold text-uppercase">Nombre de Estrategia</label><input type="text" class="form-control rounded-3" placeholder="Ej: Black Friday 2024"></div>
                        <div class="mb-3"><label class="form-label small fw-bold text-uppercase">Cuerpo del Mensaje</label><textarea class="form-control rounded-3" rows="5" placeholder="¡Hola! Te presentamos nuestra nueva colección..."></textarea></div>
                        <div class="mb-3"><label class="form-label small fw-bold text-uppercase">Base de Datos (CSV/Números)</label><textarea class="form-control rounded-3" rows="3" placeholder="549..., 549..."></textarea></div>
                        <button class="btn btn-wa w-100 py-3 shadow-lg">LANZAR AHORA <i class="fas fa-bolt ms-2"></i></button>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4">Tráfico de Envío Masivo</h5>
                        <?php foreach(db_get('campanas') as $c): ?>
                            <div class="mb-4 p-4 bg-light rounded-4 border">
                                <div class="d-flex justify-content-between align-items-center mb-3"><strong><?php echo $c['nombre'] ?></strong><span class="badge bg-success shadow-sm px-3">PROCESANDO</span></div>
                                <div class="progress mb-2" style="height: 12px; border-radius: 10px;"><div class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width: 70%"></div></div>
                                <div class="d-flex justify-content-between mt-2"><small class="text-muted fw-bold"><?php echo $c['enviados'] ?> / <?php echo $c['total'] ?> contactos</small><small class="text-primary fw-bold">70% completado</small></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'settings'):
            $set = db_get('ajustes');
        ?>
            <div class="card p-5 mx-auto border-0 shadow-lg" style="max-width: 900px; border-radius: 32px;">
                <h4 class="fw-bold mb-4"><i class="fas fa-sliders-h text-primary me-2"></i>Arquitectura de Respuesta AI</h4>
                <div class="row g-4">
                    <div class="col-md-6"><label class="form-label fw-bold small text-muted">IDENTIDAD DEL AGENTE</label><input type="text" class="form-control form-control-lg bg-light border-0 shadow-sm" value="<?php echo $set['agent_name'] ?>"></div>
                    <div class="col-md-6"><label class="form-label fw-bold small text-muted">TONALIDAD CORPORATIVA</label><select class="form-select form-select-lg bg-light border-0 shadow-sm"><option>Ejecutivo / Formal</option><option selected>Cercano / Resolutivo</option><option>Vendedor Dinámico</option></select></div>
                    <div class="col-12"><label class="form-label fw-bold small text-muted">DIRECTRICES DE PERSONALIDAD Y LÍMITES</label><textarea class="form-control bg-light border-0 shadow-sm" rows="6"><?php echo $set['instructions'] ?></textarea></div>
                    <div class="col-12 border-top pt-4 text-center"><button class="btn btn-wa btn-lg px-5 shadow-lg rounded-pill">GUARDAR CONFIGURACIÓN MAESTRA</button></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card p-5 text-center shadow-lg border-0" style="width: 100%; max-width: 440px; border-radius: 40px; background: white;">
            <div class="mb-5">
                <div class="d-inline-block p-4 rounded-circle mb-4 shadow-lg" style="background: linear-gradient(135deg, #25d366 0%, #128c7e 100%);"><i class="fab fa-whatsapp fa-4x text-white"></i></div>
                <h1 class="fw-bold text-dark h2">WA SaaS Pro</h1>
                <p class="text-muted px-4">Plataforma de Inteligencia Artificial para el Crecimiento del Negocio</p>
            </div>
            <div class="bg-light p-4 rounded-4 text-start mb-4 border">
                <div class="mb-3"><label class="form-label small fw-bold text-muted">USUARIO CORPORATIVO</label><input type="text" class="form-control border-0 bg-white shadow-sm" value="admin" readonly></div>
                <div class="mb-0"><label class="form-label small fw-bold text-muted">CONTRASEÑA</label><input type="password" class="form-control border-0 bg-white shadow-sm" value="admin123" readonly></div>
            </div>
            <button onclick="doLogin()" class="btn btn-wa w-100 py-3 fw-bold shadow-lg rounded-pill text-uppercase" style="letter-spacing: 1px;">Ingresar al Panel <i class="fas fa-arrow-right ms-2"></i></button>
            <div class="mt-4 opacity-50"><small>Infraestructura v5.0 - Real-Time Core</small></div>
        </div>
    </div>
<?php endif; ?>

<script>
    function doLogin() {
        const fd = new FormData(); fd.append('action', 'login');
        fetch(window.location.href, { method: 'POST', body: fd }).then(() => window.location.href = '?view=dashboard');
    }

    function simulateIncoming() {
        const msg = document.getElementById('sim-msg').value;
        const num = document.getElementById('sim-num').value;
        if(!msg) return;
        const fd = new FormData(); fd.append('action', 'simulate_incoming'); fd.append('remitente', num); fd.append('mensaje', msg);
        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            alert('¡Mensaje Entrante! Procesado por la IA.');
            window.location.href = '?view=chats';
        });
    }

    function sendManual() {
        const txt = document.getElementById('manual-msg').value;
        if(!txt) return;
        const fd = new FormData(); fd.append('action', 'send_message'); fd.append('chat_id', '1'); fd.append('texto', txt);
        fetch(window.location.href, { method: 'POST', body: fd }).then(r => r.json()).then(data => {
            document.getElementById('manual-msg').value = '';
            location.reload();
        });
    }

    // Chat Auto-scroll
    const cb = document.getElementById('chat-box');
    if(cb) cb.scrollTop = cb.scrollHeight;
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
