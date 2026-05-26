<?php
/**
 * WhatsApp SaaS Platform - AI Agent for Businesses
 * Version: 2.0 (Zero-DB Demo Edition)
 * Author: Jules
 * Single-file PHP implementation - ALL IN SESSION
 */

session_start();
$view = $_GET['view'] ?? 'login';

// --- SESSION DATABASE EMULATION ---
if (empty($_SESSION['db'])) {
    $_SESSION['db'] = [
        'ajustes' => [
            ['clave' => 'agent_name', 'valor' => 'Asistente Demo', 'id_sucursal' => 1],
            ['clave' => 'agent_tone', 'valor' => 'profesional', 'id_sucursal' => 1],
            ['clave' => 'webhook_token', 'valor' => bin2hex(random_bytes(16)), 'id_sucursal' => 0]
        ],
        'usuarios' => [
            ['id' => 1, 'nombre' => 'admin', 'password' => 'admin123', 'id_rol' => 1, 'id_sucursal' => 1, 'activo' => 1]
        ],
        'instancias_wa' => [
            ['id' => 1, 'id_sucursal' => 1, 'nombre_identificador' => 'Ventas Demo', 'instance_name' => 'demo_inst_1', 'gateway_url' => 'https://api.demo.com', 'api_key' => 'key_123', 'webhook_token' => 'tk_demo_1', 'estado' => 'conectado']
        ],
        'memoria' => [],
        'chats' => [],
        'campanas' => [],
        'roles' => [
            ['id' => 1, 'nombre' => 'Admin'],
            ['id' => 2, 'nombre' => 'Operador']
        ],
        'sucursales' => [
            ['id' => 1, 'nombre' => 'Empresa Principal', 'activo' => 1]
        ]
    ];
}

function db_query($table) { return $_SESSION['db'][$table] ?? []; }
function db_insert($table, $data) {
    if (!isset($data['id'])) {
        $maxId = 0;
        foreach ($_SESSION['db'][$table] as $row) { if (isset($row['id']) && $row['id'] > $maxId) $maxId = $row['id']; }
        $data['id'] = $maxId + 1;
    }
    $_SESSION['db'][$table][] = $data;
    return $data['id'];
}
function db_update($table, $keyField, $keyValue, $newData) {
    foreach ($_SESSION['db'][$table] as &$row) {
        if ($row[$keyField] == $keyValue) {
            $row = array_merge($row, $newData);
            return true;
        }
    }
    return false;
}
function db_delete($table, $keyField, $keyValue) {
    foreach ($_SESSION['db'][$table] as $k => $row) {
        if ($row[$keyField] == $keyValue) {
            unset($_SESSION['db'][$table][$k]);
            $_SESSION['db'][$table] = array_values($_SESSION['db'][$table]);
            return true;
        }
    }
    return false;
}

// --- AUTHENTICATION ---
function is_logged_in() { return isset($_SESSION['user_id']); }
function check_auth() {
    global $view;
    if (!is_logged_in() && $view !== 'login' && !isset($_GET['action'])) {
        header("Location: ?view=login"); exit;
    }
}

// Simple CSRF Protection
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
function check_csrf() {
    if (isset($_GET['action']) && $_GET['action'] === 'webhook') return;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) die("Error de seguridad: Token CSRF no vÃ¡lido.");
    }
}

// --- HELPERS ---
function get_setting($clave, $id_sucursal = null) {
    $id_sucursal = $id_sucursal ?? ($_SESSION['user_sucursal'] ?? 1);
    foreach ($_SESSION['db']['ajustes'] as $row) {
        if ($row['clave'] === $clave && ($row['id_sucursal'] == $id_sucursal || $row['id_sucursal'] == 0)) return $row['valor'];
    }
    return null;
}

function set_setting($clave, $valor, $id_sucursal = null) {
    $id_sucursal = $id_sucursal ?? ($_SESSION['user_sucursal'] ?? 1);
    $found = false;
    foreach ($_SESSION['db']['ajustes'] as &$row) {
        if ($row['clave'] === $clave && $row['id_sucursal'] == $id_sucursal) {
            $row['valor'] = $valor; $found = true; break;
        }
    }
    if (!$found) db_insert('ajustes', ['clave' => $clave, 'valor' => $valor, 'id_sucursal' => $id_sucursal]);
}

function gateway_call($endpoint, $method = 'GET', $body = null, $instance_id = null) {
    // Simulated Gateway for Demo
    return ['status' => 'success', 'message' => 'Simulated API call to ' . $endpoint];
}

// --- AI LOGIC (RAG) ---
function get_ai_response($userMessage, $id_sucursal) {
    $context = "";
    foreach ($_SESSION['db']['memoria'] as $m) {
        if ($m['id_sucursal'] == $id_sucursal) {
            if (stripos($userMessage, 'hola') !== false) $context .= "InformaciÃ³n: Somos una empresa demo.\n";
            $context .= $m['contenido'] . "\n";
        }
    }
    return "Hola! Esta es una respuesta simulada por IA para: \"$userMessage\". En un entorno real, usarÃ­amos OpenAI con este contexto: " . mb_substr($context, 0, 50) . "...";
}

// --- ACTIONS HANDLER ---
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_csrf();
    $action = $_POST['action'];

    if ($action === 'login') {
        $nombre = $_POST['nombre'] ?? ''; $pass = $_POST['password'] ?? '';
        foreach ($_SESSION['db']['usuarios'] as $u) {
            if ($u['nombre'] === $nombre && $u['password'] === $pass && $u['activo'] == 1) {
                $_SESSION['user_id'] = $u['id']; $_SESSION['user_name'] = $u['nombre'];
                $_SESSION['user_role'] = $u['id_rol']; $_SESSION['user_sucursal'] = $u['id_sucursal'];
                header("Location: ?view=dashboard"); exit;
            }
        }
        $message = "Credenciales incorrectas.";
    }

    if ($action === 'login_demo') {
        $_SESSION['user_id'] = 999; $_SESSION['user_name'] = 'Invitado Demo';
        $_SESSION['user_role'] = 1; $_SESSION['user_sucursal'] = 1;
        header("Location: ?view=dashboard"); exit;
    }

    if ($action === 'logout') { session_destroy(); header("Location: ?view=login"); exit; }

    if ($action === 'add_instance') {
        db_insert('instancias_wa', [
            'id_sucursal' => $_SESSION['user_sucursal'],
            'nombre_identificador' => $_POST['nombre_identificador'],
            'instance_name' => $_POST['instance_name'],
            'gateway_url' => $_POST['gateway_url'],
            'api_key' => $_POST['api_key'],
            'webhook_token' => bin2hex(random_bytes(8)),
            'estado' => 'conectado'
        ]);
        $message = "Instancia agregada (Simulado).";
    }

    if ($action === 'delete_instance') { db_delete('instancias_wa', 'id', $_POST['id']); $message = "Instancia eliminada."; }

    if ($action === 'scrape') {
        db_insert('memoria', ['id_sucursal' => $_SESSION['user_sucursal'], 'tipo' => 'url', 'fuente' => $_POST['url'], 'contenido' => 'Contenido extraÃ­do de ' . $_POST['url'], 'fecha' => date('Y-m-d H:i:s')]);
        $message = "URL analizada (Simulado).";
    }

    if ($action === 'webhook_test') {
        $respuesta = get_ai_response($_POST['mensaje'], $_SESSION['user_sucursal']);
        db_insert('chats', [
            'id_instancia' => 1, 'remitente' => $_POST['remitente'], 'mensaje' => $_POST['mensaje'],
            'respuesta' => $respuesta, 'modo' => 'auto', 'fecha' => date('Y-m-d H:i:s')
        ]);
        $message = "Mensaje procesado en tiempo real.";
    }
}

function get_page_title($view) {
    $titles = ['dashboard'=>'Panel', 'training'=>'Entrenamiento', 'whatsapp'=>'ConexiÃ³n', 'chats'=>'Conversaciones', 'campanas'=>'CampaÃ±as', 'users'=>'Usuarios', 'instances'=>'Instancias', 'login'=>'Demo Login'];
    return $titles[$view] ?? 'WhatsApp AI Demo';
}

check_auth();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo get_page_title($view); ?> - DEMO SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #f0f2f5; font-family: 'Segoe UI', sans-serif; }
        .sidebar { min-height: 100vh; background: #111b21; color: white; width: 260px; position: fixed; }
        .main-content { margin-left: 260px; padding: 25px; }
        .demo-banner { background: #ff9800; color: black; text-align: center; font-weight: bold; padding: 5px; position: sticky; top: 0; z-index: 9999; }
        .nav-link { color: #aebac1; padding: 12px 20px; border-radius: 0; }
        .nav-link:hover, .nav-link.active { background: #2a3942; color: white; }
        .card { border: none; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <?php if ($view !== 'login'): ?>
    <div class="demo-banner"><i class="fas fa-exclamation-triangle me-2"></i> MODO DEMOSTRACIÃ“N - LOS DATOS SE BORRAN AL CERRAR EL NAVEGADOR - SIN BASE DE DATOS</div>
    <div class="sidebar">
        <div class="p-4 border-bottom border-secondary mb-3">
            <h5 class="fw-bold mb-0 text-success"><i class="fab fa-whatsapp me-2"></i>WA SaaS Demo</h5>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link <?php echo $view=='dashboard'?'active':'' ?>" href="?view=dashboard"><i class="fas fa-home me-2"></i> Inicio</a>
            <a class="nav-link <?php echo $view=='training'?'active':'' ?>" href="?view=training"><i class="fas fa-brain me-2"></i> Entrenamiento IA</a>
            <a class="nav-link <?php echo $view=='instances'?'active':'' ?>" href="?view=instances"><i class="fas fa-server me-2"></i> Instancias WA</a>
            <a class="nav-link <?php echo $view=='chats'?'active':'' ?>" href="?view=chats"><i class="fas fa-comments me-2"></i> Chats en Vivo</a>
            <a class="nav-link <?php echo $view=='campanas'?'active':'' ?>" href="?view=campanas"><i class="fas fa-bullhorn me-2"></i> CampaÃ±as</a>
            <hr class="mx-3 border-secondary">
            <form method="POST" class="px-3"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><button type="submit" name="action" value="logout" class="btn btn-outline-danger btn-sm w-100 mt-2">Salir</button></form>
        </nav>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold"><?php echo get_page_title($view); ?></h4>
            <span class="badge bg-success rounded-pill px-3 py-2"><i class="fas fa-bolt me-1"></i> Tiempo Real Activo</span>
        </div>

        <?php if ($message): ?> <div class="alert alert-info"><?php echo $message; ?></div> <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <div class="row g-4">
                <div class="col-md-3"><div class="card p-4 text-center"><h6>Mensajes</h6><h2 class="fw-bold"><?php echo count(db_query('chats')); ?></h2></div></div>
                <div class="col-md-3"><div class="card p-4 text-center"><h6>Instancias</h6><h2 class="fw-bold"><?php echo count(db_query('instancias_wa')); ?></h2></div></div>
                <div class="col-md-3"><div class="card p-4 text-center"><h6>Fuentes IA</h6><h2 class="fw-bold"><?php echo count(db_query('memoria')); ?></h2></div></div>
                <div class="col-md-3"><div class="card p-4 text-center"><h6>CampaÃ±as</h6><h2 class="fw-bold"><?php echo count(db_query('campanas')); ?></h2></div></div>
                <div class="col-md-8">
                    <div class="card p-4">
                        <h5 class="fw-bold mb-4">Simulador de WhatsApp</h5>
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="webhook_test">
                            <div class="col-md-4"><input type="text" name="remitente" class="form-control" value="+5491155555555" required></div>
                            <div class="col-md-6"><input type="text" name="mensaje" class="form-control" placeholder="Escribe un mensaje como si fueras el cliente..." required></div>
                            <div class="col-md-2"><button type="submit" class="btn btn-success w-100">Enviar</button></div>
                        </form>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card p-4 h-100 text-center">
                        <h5 class="fw-bold">Estado IA</h5>
                        <div class="py-4"><i class="fas fa-robot text-success fa-3x mb-3"></i><br><strong>Agente Online</strong></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'training'): ?>
            <div class="row">
                <div class="col-md-6"><div class="card p-4 mb-4"><h5>Entrenar por URL</h5><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="input-group"><input type="url" name="url" class="form-control" required><button class="btn btn-primary" type="submit" name="action" value="scrape">Analizar</button></div></form></div></div>
                <div class="col-md-6"><div class="card p-4"><h5>Memoria (<?php echo count(db_query('memoria')); ?>)</h5><div class="list-group list-group-flush"><?php foreach(db_query('memoria') as $m): ?><div class="list-group-item small"><?php echo $m['fuente'] ?></div><?php endforeach; ?></div></div></div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'instances'): ?>
            <div class="card p-4">
                <h5 class="fw-bold mb-4">Instancias de WhatsApp</h5>
                <table class="table">
                    <thead><tr><th>Nombre</th><th>Estado</th><th>AcciÃ³n</th></tr></thead>
                    <tbody>
                        <?php foreach(db_query('instancias_wa') as $i): ?>
                        <tr><td><?php echo $i['nombre_identificador'] ?></td><td><span class="badge bg-success">Conectado</span></td><td><button class="btn btn-sm btn-danger">Eliminar</button></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($view === 'chats'): ?>
            <div class="row g-0 card flex-row overflow-hidden" style="height: 600px;">
                <div class="col-md-4 border-end overflow-auto bg-white">
                    <div class="list-group list-group-flush">
                        <?php foreach(array_reverse(db_query('chats')) as $c): ?>
                        <div class="list-group-item"><strong><?php echo $c['remitente'] ?></strong><br><small class="text-muted"><?php echo mb_substr($c['mensaje'], 0, 30) ?>...</small></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-8 d-flex flex-column bg-light">
                    <div class="flex-grow-1 p-4 overflow-auto">
                        <?php if (empty(db_query('chats'))): ?><div class="text-center mt-5 text-muted">No hay mensajes. Usa el simulador en Inicio.</div><?php endif; ?>
                        <?php foreach(db_query('chats') as $c): ?>
                            <div class="mb-3 text-start"><span class="d-inline-block p-2 bg-white rounded shadow-sm"><?php echo $c['mensaje'] ?></span></div>
                            <div class="mb-3 text-end"><span class="d-inline-block p-2 bg-success text-white rounded shadow-sm"><?php echo $c['respuesta'] ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="p-3 bg-white border-top"><div class="input-group"><input type="text" class="form-control" placeholder="Responder manualmente..."><button class="btn btn-primary"><i class="fas fa-paper-plane"></i></button></div></div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php else: ?>
    <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
        <div class="card p-5 text-center shadow-lg" style="width: 100%; max-width: 400px;">
            <h2 class="fw-bold text-success mb-3"><i class="fab fa-whatsapp"></i> WA SaaS</h2>
            <p class="text-muted mb-4">DemostraciÃ³n Profesional en Tiempo Real</p>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <button type="submit" name="action" value="login_demo" class="btn btn-success w-100 py-3 fw-bold shadow">ENTRAR A LA DEMOSTRACIÃ“N</button>
            </form>
            <div class="mt-4 small text-muted border-top pt-3">
                <i class="fas fa-info-circle me-1"></i> Este sistema no requiere base de datos para la demo.
            </div>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>
