<?php
/**
 * WhatsApp SaaS Platform - AI Agent for Businesses
 * Version: 1.2 (MySQL Edition)
 * Author: Jules
 * Single-file PHP implementation
 */

// --- CONFIGURATION & ROUTING ---
session_start();
$view = $_GET['view'] ?? 'dashboard';

// --- AUTHENTICATION ---
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function check_auth() {
    global $view;
    if (!is_logged_in() && $view !== 'login' && !isset($_GET['action'])) {
        header("Location: ?view=login");
        exit;
    }
}

// Simple CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function check_csrf() {
    // Bypass CSRF for Webhook
    if (isset($_GET['action']) && $_GET['action'] === 'webhook') return;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die("Error de seguridad: Token CSRF no válido.");
        }
    }
}

// --- DATABASE SETUP (SQLite) ---
function getDb() {
    try {
        $db = new PDO("sqlite:whatsapp.db");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 1. Core Tables
        $db->exec("CREATE TABLE IF NOT EXISTS sucursales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            id_rol INTEGER,
            id_sucursal INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS ajustes (
            clave TEXT PRIMARY KEY,
            valor TEXT,
            id_sucursal INTEGER DEFAULT 0
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS instancias_wa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_sucursal INTEGER,
            nombre_identificador TEXT,
            instance_name TEXT,
            gateway_url TEXT,
            api_key TEXT,
            webhook_token TEXT,
            estado TEXT DEFAULT 'desconectado'
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS memoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_sucursal INTEGER DEFAULT 1,
            tipo TEXT,
            fuente TEXT,
            contenido TEXT,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS chats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_instancia INTEGER DEFAULT 1,
            remitente TEXT,
            mensaje TEXT,
            respuesta TEXT,
            modo TEXT DEFAULT 'auto',
            id_usuario_asignado INTEGER DEFAULT 0,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS campanas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_instancia INTEGER DEFAULT 1,
            nombre TEXT,
            mensaje TEXT,
            destinatarios TEXT,
            estado TEXT DEFAULT 'pendiente',
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // 2. Initial Seeding
        $rolesCount = $db->query("SELECT COUNT(*) FROM roles")->fetchColumn();
        if ($rolesCount == 0) {
            $db->exec("INSERT INTO roles (nombre) VALUES ('Admin'), ('Operador')");
        }

        $sucursalCount = $db->query("SELECT COUNT(*) FROM sucursales")->fetchColumn();
        if ($sucursalCount == 0) {
            $db->exec("INSERT INTO sucursales (nombre) VALUES ('Empresa Principal')");
        }

        $userCount = $db->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
        if ($userCount == 0) {
            $pass = password_hash('admin123', PASSWORD_DEFAULT);
            $db->exec("INSERT INTO usuarios (nombre, email, password, id_rol, id_sucursal) VALUES ('Administrador', 'admin@admin.com', '$pass', 1, 1)");
        }

        // Seed webhook token if not exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM ajustes WHERE clave = 'webhook_token'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('webhook_token', ?)")->execute([bin2hex(random_bytes(16))]);
        }

        return $db;
    } catch (PDOException $e) {
        die("Error de conexión: " . $e->getMessage());
    }
}

// Initialize DB
$pdo = getDb();

// --- HELPERS ---
function get_setting($clave, $id_sucursal = null) {
    global $pdo;
    if ($id_sucursal === null && isset($_SESSION['user_sucursal'])) {
        $id_sucursal = $_SESSION['user_sucursal'];
    }
    $id_sucursal = $id_sucursal ?? 0;

    $stmt = $pdo->prepare("SELECT valor FROM ajustes WHERE clave = ? AND id_sucursal = ?");
    $stmt->execute([$clave, $id_sucursal]);
    $val = $stmt->fetchColumn();

    // Fallback to global setting (id_sucursal = 0) if not found for branch
    if ($val === false && $id_sucursal != 0) {
        $stmt = $pdo->prepare("SELECT valor FROM ajustes WHERE clave = ? AND id_sucursal = 0");
        $stmt->execute([$clave]);
        $val = $stmt->fetchColumn();
    }

    return $val;
}

function set_setting($clave, $valor, $id_sucursal = null) {
    global $pdo;
    if ($id_sucursal === null && isset($_SESSION['user_sucursal'])) {
        $id_sucursal = $_SESSION['user_sucursal'];
    }
    $id_sucursal = $id_sucursal ?? 0;

    $stmt = $pdo->prepare("INSERT INTO ajustes (clave, valor, id_sucursal) VALUES (?, ?, ?) ON CONFLICT(clave) DO UPDATE SET valor = excluded.valor");
    $stmt->execute([$clave, $valor, $id_sucursal]);
}

function gateway_call($endpoint, $method = 'GET', $body = null, $instance_id = null) {
    global $pdo;

    if ($instance_id) {
        $stmt = $pdo->prepare("SELECT * FROM instancias_wa WHERE id = ?");
        $stmt->execute([$instance_id]);
        $inst = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($inst) {
            $url = $inst['gateway_url'];
            $token = $inst['api_key'];
        }
    } else {
        $url = get_setting('gateway_url');
        $token = get_setting('gateway_token');
    }

    if (!$url || !$token) return null;

    $ch = curl_init(rtrim($url, '/') . '/' . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'apikey: ' . $token
    ]);

    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// --- AI LOGIC (RAG) ---
function get_ai_response($userMessage, $id_sucursal) {
    global $pdo;

    // 1. Get Settings for this branch
    $apiKey = get_setting('openai_key', $id_sucursal);
    if (empty($apiKey)) return "Error: OpenAI API Key no configurada para esta sucursal.";

    // 2. Search Context (Simple Keyword Search for RAG) - Branch Isolated
    $context = "";
    $words = explode(' ', $userMessage);
    $searchTerms = array_filter($words, function($w) { return strlen($w) > 3; });

    if (!empty($searchTerms)) {
        $queryParts = [];
        $params = [$id_sucursal];
        foreach ($searchTerms as $term) {
            $queryParts[] = "contenido LIKE ?";
            $params[] = "%$term%";
        }
        $stmt = $pdo->prepare("SELECT contenido FROM memoria WHERE id_sucursal = ? AND (" . implode(" OR ", $queryParts) . ") LIMIT 5");
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $context = implode("\n\n", $results);
    }

    // 3. Prepare AI Request
    $agentName = get_setting('agent_name', $id_sucursal) ?: 'Asistente';
    $agentTone = get_setting('agent_tone', $id_sucursal) ?: 'profesional';
    $instructions = get_setting('agent_instructions', $id_sucursal) ?: 'Eres un asistente útil.';

    $systemPrompt = "Tu nombre es $agentName. Tono: $agentTone. $instructions\n\n"
                  . "IMPORTANTE: Utiliza EXCLUSIVAMENTE el siguiente contexto del negocio para responder. "
                  . "Si la información no está en el contexto, indica amablemente que no tienes esa información o deriva a un humano.\n\n"
                  . "Contexto del negocio:\n$context";

    $data = [
        'model' => 'gpt-4o-mini', // Upgraded to 4o-mini for better reasoning in SaaS
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ],
        'temperature' => 0.4 // Lower temperature for more consistent business responses
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $json = json_decode($response, true);
        return $json['choices'][0]['message']['content'] ?? "Error en respuesta.";
    } else {
        return "Error API ($httpCode): " . $response;
    }
}

// --- WEBHOOK RECEIVER (Real-time messages) ---
if (isset($_GET['action']) && $_GET['action'] === 'webhook') {
    $token_req = $_GET['token'] ?? '';

    // Find instance by webhook_token
    $stmtI = $pdo->prepare("SELECT * FROM instancias_wa WHERE webhook_token = ?");
    $stmtI->execute([$token_req]);
    $inst = $stmtI->fetch(PDO::FETCH_ASSOC);

    if (!$inst) {
        http_response_code(401);
        die("Unauthorized - Invalid Token");
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data) {
        // Logic for Evolution API / similar gateways
        $mensaje = $data['data']['message']['conversation'] ?? $data['data']['message']['extendedTextMessage']['text'] ?? '';
        $remitente = explode('@', $data['data']['key']['remoteJid'] ?? '')[0] ?? 'Desconocido';

        if (!empty($mensaje) && !empty($remitente)) {
            // Get response branch-isolated
            $respuesta_ai = get_ai_response($mensaje, $inst['id_sucursal']);

            $stmt = $pdo->prepare("INSERT INTO chats (id_instancia, remitente, mensaje, respuesta, modo) VALUES (?, ?, ?, ?, 'auto')");
            $stmt->execute([$inst['id'], $remitente, $mensaje, $respuesta_ai]);

            // Auto-send response via Gateway
            gateway_call("message/sendText/{$inst['instance_name']}", 'POST', [
                'number' => $remitente,
                'text' => $respuesta_ai
            ], $inst['id']);
        }
    }
    http_response_code(200);
    exit;
}

// --- ACTIONS HANDLER ---
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_csrf();
    $action = $_POST['action'];

    // Login Action
    if ($action === 'login') {
        $email = $_POST['email'] ?? '';
        $pass = $_POST['password'] ?? '';
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['nombre'];
            $_SESSION['user_role'] = $user['id_rol'];
            $_SESSION['user_sucursal'] = $user['id_sucursal'];
            header("Location: ?view=dashboard");
            exit;
        } else {
            $message = "Credenciales incorrectas.";
            $view = 'login';
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header("Location: ?view=login");
        exit;
    }

    // Instance Management
    if ($action === 'add_instance' && !empty($_POST['instance_name'])) {
        $stmt = $pdo->prepare("INSERT INTO instancias_wa (id_sucursal, nombre_identificador, instance_name, gateway_url, api_key, webhook_token) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_sucursal'],
            $_POST['nombre_identificador'],
            $_POST['instance_name'],
            $_POST['gateway_url'],
            $_POST['api_key'],
            bin2hex(random_bytes(16))
        ]);
        $message = "Instancia agregada.";
    }

    if ($action === 'delete_instance' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM instancias_wa WHERE id = ? AND id_sucursal = ?")->execute([$_POST['id'], $_SESSION['user_sucursal']]);
        $message = "Instancia eliminada.";
    }

    // User Management
    if ($action === 'add_user' && !empty($_POST['email'])) {
        $pass = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, id_rol, id_sucursal) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['nombre'], $_POST['email'], $pass, $_POST['id_rol'], $_SESSION['user_sucursal']]);
        $message = "Usuario agregado.";
    }

    if ($action === 'delete_user' && isset($_POST['id'])) {
        if ($_POST['id'] != $_SESSION['user_id']) {
            $pdo->prepare("DELETE FROM usuarios WHERE id = ? AND id_sucursal = ?")->execute([$_POST['id'], $_SESSION['user_sucursal']]);
            $message = "Usuario eliminado.";
        } else {
            $message = "No puedes eliminarte a ti mismo.";
        }
    }

    if ($action === 'scrape' && !empty($_POST['url'])) {
        $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html) {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($doc);
            foreach ($xpath->query('//script|//style') as $node) { $node->parentNode->removeChild($node); }
            $text = trim(preg_replace('/\s+/', ' ', strip_tags($doc->textContent)));
            $text = mb_substr($text, 0, 10000); // Increased for MySQL
            $stmt = $pdo->prepare("INSERT INTO memoria (id_sucursal, tipo, fuente, contenido) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_SESSION['user_sucursal'], 'url', $url, $text]);
            $message = "URL analizada y guardada con éxito.";
        } else {
            $message = "Error al acceder a la URL.";
        }
    }

    if ($action === 'upload' && isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
        $stmt = $pdo->prepare("INSERT INTO memoria (id_sucursal, tipo, fuente, contenido) VALUES (?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_sucursal'], 'file', $_FILES['file']['name'], file_get_contents($_FILES['file']['tmp_name'])]);
        $message = "Archivo cargado.";
    }

    if ($action === 'delete_memory' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM memoria WHERE id = ? AND id_sucursal = ?")->execute([$_POST['id'], $_SESSION['user_sucursal']]);
        $message = "Fuente eliminada.";
    }

    if ($action === 'save_settings') {
        foreach (['openai_key', 'agent_name', 'agent_tone', 'agent_instructions'] as $key) {
            if (isset($_POST[$key])) {
                set_setting($key, $_POST[$key]);
            }
        }
        $message = "Configuración guardada.";
    }

    if ($action === 'delete_campana' && isset($_POST['id'])) {
        // SQLite doesn't support JOIN in DELETE directly easily without subqueries
        $pdo->prepare("DELETE FROM campanas WHERE id = ? AND id_instancia IN (SELECT id FROM instancias_wa WHERE id_sucursal = ?)")->execute([$_POST['id'], $_SESSION['user_sucursal']]);
        $message = "Campaña eliminada.";
    }

    if ($action === 'webhook_test') {
        $remitente = $_POST['remitente'] ?? '+54 9 11 0000-0000';
        $mensaje_recibido = $_POST['mensaje'] ?? 'Hola';
        $respuesta_ai = get_ai_response($mensaje_recibido, $_SESSION['user_sucursal']);

        // Find first instance to simulate
        $stmtI = $pdo->prepare("SELECT id FROM instancias_wa WHERE id_sucursal = ? LIMIT 1");
        $stmtI->execute([$_SESSION['user_sucursal']]);
        $iid = $stmtI->fetchColumn() ?: 1;

        $pdo->prepare("INSERT INTO chats (id_instancia, remitente, mensaje, respuesta, modo) VALUES (?, ?, ?, ?, 'auto')")->execute([$iid, $remitente, $mensaje_recibido, $respuesta_ai]);
        $message = "Mensaje simulado procesado.";
    }

    if ($action === 'manual_reply' && isset($_POST['chat_id'])) {
        $stmtC = $pdo->prepare("SELECT c.*, i.instance_name, i.id as iid FROM chats c JOIN instancias_wa i ON c.id_instancia = i.id WHERE c.id = ?");
        $stmtC->execute([$_POST['chat_id']]);
        $chat = $stmtC->fetch(PDO::FETCH_ASSOC);

        if ($chat) {
            $pdo->prepare("UPDATE chats SET respuesta = ?, modo = 'manual' WHERE id = ?")->execute([$_POST['respuesta'], $_POST['chat_id']]);
            gateway_call("message/sendText/{$chat['instance_name']}", 'POST', [
                'number' => $chat['remitente'],
                'text' => $_POST['respuesta']
            ], $chat['iid']);
            $message = "Respuesta manual enviada.";
        }
    }

    if ($action === 'toggle_mode' && isset($_POST['chat_id'])) {
        $pdo->prepare("UPDATE chats SET modo = ? WHERE id = ?")->execute([$_POST['modo'], $_POST['chat_id']]);
    }

    if ($action === 'add_asesor' && !empty($_POST['nombre'])) {
        $pdo->prepare("INSERT INTO asesores (nombre, email) VALUES (?, ?)")->execute([$_POST['nombre'], $_POST['email'] ?? '']);
        $message = "Asesor agregado.";
    }

    if ($action === 'delete_asesor' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM asesores WHERE id = ?")->execute([$_POST['id']]);
        $message = "Asesor eliminado.";
    }

    if ($action === 'assign_asesor' && isset($_POST['chat_id'])) {
        $pdo->prepare("UPDATE chats SET id_usuario_asignado = ?, modo = 'manual' WHERE id = ?")->execute([$_POST['id_asesor'], $_POST['chat_id']]);
        $message = "Chat asignado.";
    }

    if ($action === 'create_campana' && !empty($_POST['nombre'])) {
        $pdo->prepare("INSERT INTO campanas (id_instancia, nombre, mensaje, destinatarios) VALUES (?, ?, ?, ?)")->execute([$_POST['id_instancia'], $_POST['nombre'], $_POST['mensaje'], $_POST['destinatarios']]);
        $message = "Campaña creada.";
    }

    if ($action === 'send_campana' && isset($_POST['id'])) {
        $stmtC = $pdo->prepare("SELECT c.*, i.instance_name FROM campanas c JOIN instancias_wa i ON c.id_instancia = i.id WHERE c.id = ? AND i.id_sucursal = ?");
        $stmtC->execute([$_POST['id'], $_SESSION['user_sucursal']]);
        $camp = $stmtC->fetch(PDO::FETCH_ASSOC);

        if ($camp) {
            $numeros = preg_split('/[\s,]+/', $camp['destinatarios']);
            $count = 0;
            foreach ($numeros as $num) {
                $num = trim($num);
                if (!empty($num)) {
                    gateway_call("message/sendText/{$camp['instance_name']}", 'POST', [
                        'number' => $num,
                        'text' => $camp['mensaje']
                    ], $camp['id_instancia']);
                    $count++;
                }
            }
            $pdo->prepare("UPDATE campanas SET estado = 'completada' WHERE id = ?")->execute([$camp['id']]);
            $message = "Campaña enviada a $count números.";
        }
    }
}

function get_page_title($view) {
    switch ($view) {
        case 'dashboard': return 'Panel de Control';
        case 'training': return 'Entrenamiento de IA';
        case 'whatsapp': return 'Conexión WhatsApp';
        case 'chats': return 'Conversaciones';
        case 'campanas': return 'Campañas Masivas';
        case 'asesores': return 'Asesores Comerciales';
        case 'settings': return 'Ajustes';
        case 'users': return 'Gestión de Usuarios';
        case 'instances': return 'Instancias WhatsApp';
        case 'login': return 'Iniciar Sesión';
        default: return 'WhatsApp AI';
    }
}

check_auth();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo get_page_title($view); ?> - WA AI SaaS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: #075E54; color: white; width: 250px; position: fixed; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); margin: 5px 15px; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .main-content { margin-left: 250px; padding: 30px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .navbar-top { background: white; border-bottom: 1px solid #eee; padding: 15px 30px; margin-left: 250px; }
        .badge-wa { background-color: #25D366; color: white; }
    </style>
</head>
<body>
    <?php if ($view !== 'login'): ?>
    <div class="sidebar">
        <div class="p-4">
            <h4 class="fw-bold mb-0"><i class="fab fa-whatsapp me-2"></i>WA AI SaaS</h4>
            <small class="opacity-50">Plataforma de Agentes</small>
        </div>
        <nav class="nav flex-column mt-4">
            <a class="nav-link <?php echo $view == 'dashboard' ? 'active' : ''; ?>" href="?view=dashboard"><i class="fas fa-chart-line"></i> Panel de Control</a>
            <a class="nav-link <?php echo $view == 'training' ? 'active' : ''; ?>" href="?view=training"><i class="fas fa-brain"></i> Entrenamiento IA</a>
            <a class="nav-link <?php echo $view == 'instances' ? 'active' : ''; ?>" href="?view=instances"><i class="fas fa-server"></i> Instancias WA</a>
            <a class="nav-link <?php echo $view == 'whatsapp' ? 'active' : ''; ?>" href="?view=whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp QR</a>
            <a class="nav-link <?php echo $view == 'chats' ? 'active' : ''; ?>" href="?view=chats"><i class="fas fa-comments"></i> Conversaciones</a>
            <a class="nav-link <?php echo $view == 'campanas' ? 'active' : ''; ?>" href="?view=campanas"><i class="fas fa-bullhorn"></i> Campañas</a>
            <?php if ($_SESSION['user_role'] == 1): ?>
            <a class="nav-link <?php echo $view == 'users' ? 'active' : ''; ?>" href="?view=users"><i class="fas fa-users-cog"></i> Usuarios</a>
            <a class="nav-link <?php echo $view == 'settings' ? 'active' : ''; ?>" href="?view=settings"><i class="fas fa-cog"></i> Ajustes</a>
            <?php endif; ?>
        </nav>
    </div>

    <div class="navbar-top d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h5 class="mb-0 fw-semibold text-dark me-3"><?php echo get_page_title($view); ?></h5>
            <?php if ($message): ?> <div class="alert alert-info py-1 px-3 mb-0 small alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close py-2" data-bs-alert="alert"></button></div> <?php endif; ?>
        </div>
        <div class="d-flex align-items-center">
            <span class="badge badge-wa rounded-pill px-3 py-2 me-3"><i class="fas fa-circle text-white me-1" style="font-size: 8px;"></i> AI Activa</span>
            <div class="dropdown">
                <button class="btn btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown"><i class="fas fa-user"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text fw-bold"><?php echo $_SESSION['user_name']; ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" name="action" value="logout" class="dropdown-item text-danger">Cerrar Sesión</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="main-content">
        <?php
        // Role-based Access Control
        $isAdmin = ($_SESSION['user_role'] == 1);

        switch ($view) {
            case 'dashboard': include_dashboard(); break;
            case 'training': include_training(); break;
            case 'whatsapp': include_whatsapp(); break;
            case 'chats': include_chats(); break;
            case 'campanas': include_campanas(); break;
            case 'instances': include_instances(); break;
            case 'users': if ($isAdmin) include_users(); else include_dashboard(); break;
            case 'settings': if ($isAdmin) include_settings(); else include_dashboard(); break;
            default: include_dashboard(); break;
        }
        ?>
    </div>
    <?php else: include_login(); endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php
    function include_login() {
        global $message;
        ?>
        <div class="container d-flex align-items-center justify-content-center" style="min-height: 100vh;">
            <div class="card p-5 shadow-lg" style="width: 100%; max-width: 400px;">
                <div class="text-center mb-4">
                    <h2 class="fw-bold text-success"><i class="fab fa-whatsapp me-2"></i>WA AI SaaS</h2>
                    <p class="text-muted">Ingresa a tu plataforma</p>
                </div>
                <?php if ($message): ?> <div class="alert alert-danger py-2 small"><?php echo $message; ?></div> <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" required placeholder="admin@admin.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Contraseña</label>
                        <input type="password" name="password" class="form-control" required placeholder="admin123">
                    </div>
                    <button type="submit" class="btn btn-success w-100 py-2 fw-bold">Entrar</button>
                </form>
            </div>
        </div>
        <?php
    }

    function include_dashboard() {
        global $pdo;
        $sid = $_SESSION['user_sucursal'];

        $total_msg = $pdo->prepare("SELECT COUNT(*) FROM chats c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ?");
        $total_msg->execute([$sid]);
        $total_msg = $total_msg->fetchColumn();

        $auto_msg = $pdo->prepare("SELECT COUNT(*) FROM chats c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ? AND c.modo = 'auto'");
        $auto_msg->execute([$sid]);
        $auto_msg = $auto_msg->fetchColumn();

        $fuentes_count = $pdo->prepare("SELECT COUNT(*) FROM memoria WHERE id_sucursal = ?");
        $fuentes_count->execute([$sid]);
        $fuentes_count = $fuentes_count->fetchColumn();

        $campanas_count = $pdo->prepare("SELECT COUNT(*) FROM campanas c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ?");
        $campanas_count->execute([$sid]);
        $campanas_count = $campanas_count->fetchColumn();

        $users_count = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_sucursal = ?");
        $users_count->execute([$sid]);
        $users_count = $users_count->fetchColumn();

        $recientes = $pdo->prepare("SELECT c.* FROM chats c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ? ORDER BY c.fecha DESC LIMIT 5");
        $recientes->execute([$sid]);
        $recientes = $recientes->fetchAll(PDO::FETCH_ASSOC);

        $agent_name = $pdo->prepare("SELECT valor FROM ajustes WHERE clave = 'agent_name' AND id_sucursal = ?");
        $agent_name->execute([$sid]);
        $agent_name = $agent_name->fetchColumn() ?: 'Vendedor Pro';
        ?>
        <div class="row g-4 text-center">
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">Total Mensajes</h6><h2 class="fw-bold"><?php echo $total_msg; ?></h2></div></div>
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">IA Respondidos</h6><h2 class="fw-bold"><?php echo $auto_msg; ?></h2></div></div>
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">Usuarios</h6><h2 class="fw-bold"><?php echo $users_count; ?></h2></div></div>
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">Campañas</h6><h2 class="fw-bold"><?php echo $campanas_count; ?></h2></div></div>
            <div class="col-md-8 text-start"><div class="card p-4"><h5 class="fw-bold mb-4">Actividad Reciente</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Contacto</th><th>Estado</th><th>Mensaje</th><th>Acción</th></tr></thead><tbody><?php foreach ($recientes as $r): ?><tr><td><?php echo htmlspecialchars($r['remitente']); ?></td><td><span class="badge <?php echo $r['modo'] == 'auto' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo strtoupper($r['modo']); ?></span></td><td class="text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($r['mensaje']); ?></td><td><a href="?view=chats&chat_id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Ver</a></td></tr><?php endforeach; ?></tbody></table></div></div></div>
            <div class="col-md-4"><div class="card p-4 h-100"><h5 class="fw-bold mb-4">Estado del Agente</h5><div class="py-4"><div class="spinner-grow text-success mb-3"></div><h6>Agente "<?php echo htmlspecialchars($agent_name); ?>" Online</h6><hr><div class="d-grid"><a href="?view=settings" class="btn btn-outline-primary btn-sm mb-2">Configurar</a><button class="btn btn-outline-danger btn-sm">Desactivar</button></div></div></div></div>
        </div>
        <?php
    }

    function include_training() {
        global $pdo;
        $sid = $_SESSION['user_sucursal'];
        $stmt = $pdo->prepare("SELECT * FROM memoria WHERE id_sucursal = ? ORDER BY fecha DESC");
        $stmt->execute([$sid]);
        $fuentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card p-4 mb-4"><h5 class="fw-bold mb-3">Entrenar por URL</h5><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="input-group mb-3"><input type="url" name="url" class="form-control" placeholder="https://tu-negocio.com" required><button class="btn btn-primary" type="submit" name="action" value="scrape">Analizar</button></div></form></div>
                <div class="card p-4"><h5 class="fw-bold mb-3">Subir Documentos</h5><p class="small text-muted mb-2">Formatos soportados: .txt, .md, .json</p><form method="POST" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><div class="mb-3"><input class="form-control" type="file" name="file" accept=".txt,.md,.json"></div><button class="btn btn-primary w-100" type="submit" name="action" value="upload">Cargar</button></form></div>
            </div>
            <div class="col-md-6"><div class="card p-4"><h5 class="fw-bold mb-4">Memoria del Negocio (<?php echo count($fuentes); ?>)</h5><div class="list-group list-group-flush overflow-auto" style="max-height: 400px;"><?php foreach ($fuentes as $f): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div class="text-truncate" style="max-width: 80%;"><h6 class="mb-0 text-truncate"><?php echo htmlspecialchars($f['fuente']); ?></h6><small class="text-muted"><?php echo $f['tipo']; ?> • <?php echo $f['fecha']; ?></small></div><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $f['id']; ?>"><button class="btn btn-sm text-danger" name="action" value="delete_memory"><i class="fas fa-trash"></i></button></form></div><?php endforeach; ?></div></div></div>
        </div>
        <?php
    }

    function include_whatsapp() {
        global $pdo;
        $instance_id = $_GET['instance_id'] ?? null;
        $inst = null;

        if ($instance_id) {
            $stmt = $pdo->prepare("SELECT * FROM instancias_wa WHERE id = ? AND id_sucursal = ?");
            $stmt->execute([$instance_id, $_SESSION['user_sucursal']]);
            $inst = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        $qr_image = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=Selecciona_Una_Instancia";
        $instance_name = "N/A";

        if ($inst) {
            $instance_name = $inst['instance_name'];
            $data = gateway_call("instance/connect/{$inst['instance_name']}", 'GET', null, $inst['id']);
            if (isset($data['base64'])) {
                $qr_image = $data['base64'];
            } else {
                $qr_image = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=Error_QR_Check_Instance_Settings";
            }
        }
        ?>
        <div class="card mx-auto mb-4 text-center p-5" style="max-width: 600px;">
            <h4 class="fw-bold mb-4">Conecta tu WhatsApp</h4>
            <?php if (!$inst): ?>
                <div class="alert alert-warning">Por favor, selecciona una instancia desde el menú "Instancias WA".</div>
            <?php else: ?>
                <div class="bg-light p-4 rounded mb-4 d-inline-block"><img src="<?php echo $qr_image; ?>" class="img-fluid rounded shadow-sm" style="max-width: 300px;"></div>
                <div class="mt-3"><span class="text-muted">Escanea para vincular: <strong><?php echo htmlspecialchars($inst['nombre_identificador']); ?></strong> (<?php echo htmlspecialchars($inst['instance_name']); ?>)</span></div>
            <?php endif; ?>
        </div>
        <div class="card mx-auto p-4" style="max-width: 600px;"><h6 class="fw-bold mb-3">Simulador de Webhook</h6><form method="POST" class="row g-2"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="webhook_test"><div class="col-md-5"><input type="text" name="remitente" class="form-control" value="+5491155555555"></div><div class="col-md-5"><input type="text" name="mensaje" class="form-control" placeholder="Mensaje..." required></div><div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Enviar</button></div></form></div>
        <?php
    }

    function include_chats() {
        global $pdo;
        $active_chat_id = $_GET['chat_id'] ?? null;
        $sid = $_SESSION['user_sucursal'];
        $uid = $_SESSION['user_id'];
        $role = $_SESSION['user_role'];

        // Filter: Admins see all, Operators see only assigned OR unassigned chats
        if ($role == 1) {
            $stmt = $pdo->prepare("SELECT c.*, i.nombre_identificador as instancia_tag FROM chats c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ? ORDER BY c.fecha DESC");
            $stmt->execute([$sid]);
        } else {
            $stmt = $pdo->prepare("SELECT c.*, i.nombre_identificador as instancia_tag FROM chats c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ? AND (c.id_usuario_asignado = ? OR c.id_usuario_asignado = 0) ORDER BY c.fecha DESC");
            $stmt->execute([$sid, $uid]);
        }
        $all_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $current_chat = null;
        if ($active_chat_id) {
            foreach ($all_chats as $c) { if ($c['id'] == $active_chat_id) { $current_chat = $c; break; } }
        } elseif (!empty($all_chats)) {
            $current_chat = $all_chats[0];
        }
        ?>
        <div class="row g-0 h-100 card flex-row overflow-hidden" style="height: 700px !important;">
            <div class="col-md-4 border-end h-100 overflow-auto"><div class="list-group list-group-flush"><?php foreach ($all_chats as $chat): ?><a href="?view=chats&chat_id=<?php echo $chat['id']; ?>" class="list-group-item list-group-item-action p-3 <?php echo ($current_chat && $current_chat['id'] == $chat['id']) ? 'active' : ''; ?>"><div class="d-flex justify-content-between"><h6><?php echo htmlspecialchars($chat['remitente']); ?></h6><small class="badge bg-light text-dark"><?php echo $chat['instancia_tag']; ?></small></div><p class="mb-1 small opacity-75 text-truncate"><?php echo htmlspecialchars($chat['mensaje']); ?></p></a><?php endforeach; ?></div></div>
            <div class="col-md-8 d-flex flex-column h-100"><?php if ($current_chat): ?>
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white"><div><h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($current_chat['remitente']); ?></h6><small class="text-success"><?php echo strtoupper($current_chat['modo']); ?></small></div><div class="d-flex align-items-center"><form method="POST" class="me-2 d-flex"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="assign_asesor"><input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>"><select name="id_asesor" class="form-select form-select-sm" onchange="this.form.submit()"><option value="0">Sin Asignar</option><?php $stmtA = $pdo->prepare("SELECT * FROM usuarios WHERE id_sucursal = ? ORDER BY nombre ASC"); $stmtA->execute([$_SESSION['user_sucursal']]); while ($rowA = $stmtA->fetch(PDO::FETCH_ASSOC)) { $sel = $current_chat['id_usuario_asignado'] == $rowA['id'] ? 'selected' : ''; echo "<option value='{$rowA['id']}' $sel>{$rowA['nombre']}</option>"; } ?></select></form><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>"><input type="hidden" name="action" value="toggle_mode"><input type="hidden" name="modo" value="<?php echo $current_chat['modo'] == 'auto' ? 'manual' : 'auto'; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><?php echo $current_chat['modo'] == 'auto' ? 'Pausar IA' : 'Activar IA'; ?></button></form></div></div>
                <div class="flex-grow-1 p-4 bg-light overflow-auto"><div class="d-flex flex-column gap-3"><div class="align-self-start bg-white p-3 rounded shadow-sm"><?php echo htmlspecialchars($current_chat['mensaje']); ?></div><?php if ($current_chat['respuesta']): ?><div class="align-self-end bg-success text-white p-3 rounded shadow-sm"><?php echo nl2br(htmlspecialchars($current_chat['respuesta'])); ?></div><?php endif; ?></div></div>
                <div class="p-3 border-top bg-white"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="manual_reply"><input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>"><div class="input-group"><input type="text" name="respuesta" class="form-control" placeholder="Responder manual..." required><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button></div></form></div>
            <?php endif; ?></div>
        </div>
        <?php
    }

    function include_campanas() {
        global $pdo;
        $sid = $_SESSION['user_sucursal'];
        $stmt = $pdo->prepare("SELECT c.*, i.nombre_identificador as instancia_tag FROM campanas c JOIN instancias_wa i ON c.id_instancia = i.id WHERE i.id_sucursal = ? ORDER BY c.fecha DESC");
        $stmt->execute([$sid]);
        $campanas = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmtI = $pdo->prepare("SELECT * FROM instancias_wa WHERE id_sucursal = ?");
        $stmtI->execute([$sid]);
        $instancias = $stmtI->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row"><div class="col-md-5"><div class="card p-4 shadow-sm border-0"><h5 class="fw-bold mb-4"><i class="fas fa-plus-circle text-primary me-2"></i>Nueva Campaña</h5><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="create_campana"><div class="mb-3"><label class="form-label fw-semibold">Instancia Emisora</label><select name="id_instancia" class="form-select"><?php foreach($instancias as $i): ?><option value="<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['nombre_identificador']); ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label fw-semibold">Nombre de la Campaña</label><input type="text" name="nombre" class="form-control" placeholder="Ej: Promo Verano 2024" required></div><div class="mb-3"><label class="form-label fw-semibold">Mensaje (Soporta Emojis)</label><textarea name="mensaje" class="form-control" rows="4" placeholder="¡Hola! Te escribimos de..." required></textarea></div><div class="mb-3"><label class="form-label fw-semibold">Destinatarios (Uno por línea o comas)</label><textarea name="destinatarios" class="form-control" rows="4" placeholder="+5491100000000&#10;+5491111111111"></textarea></div><button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="fas fa-save me-2"></i>Crear y Guardar</button></form></div></div>
        <div class="col-md-7"><div class="card p-4 h-100 shadow-sm border-0"><h5 class="fw-bold mb-4"><i class="fas fa-history text-muted me-2"></i>Historial de Envíos</h5><div class="list-group list-group-flush"><?php foreach ($campanas as $c): ?><div class="list-group-item d-flex justify-content-between align-items-center px-0 py-3"><div><h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($c['nombre']); ?></h6><div class="d-flex align-items-center gap-2 mt-1"><span class="badge bg-light text-dark border"><?php echo $c['instancia_tag']; ?></span><small class="text-muted"><?php echo $c['fecha']; ?></small>•<span class="badge <?php echo $c['estado'] == 'completada' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning'; ?>"><?php echo strtoupper($c['estado']); ?></span></div></div><div class="d-flex gap-1"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button class="btn btn-sm btn-outline-success" name="action" value="send_campana" title="Enviar ahora" <?php echo $c['estado'] == 'completada' ? 'disabled' : ''; ?>><i class="fas fa-paper-plane"></i></button></form><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button class="btn btn-sm btn-outline-danger" name="action" value="delete_campana" title="Eliminar"><i class="fas fa-trash"></i></button></form></div></div><?php endforeach; ?></div></div></div></div>
        <?php
    }

    function include_instances() {
        global $pdo;
        $instances = $pdo->prepare("SELECT * FROM instancias_wa WHERE id_sucursal = ?");
        $instances->execute([$_SESSION['user_sucursal']]);
        $instances = $instances->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row">
            <div class="col-md-4">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Nueva Instancia</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_instance">
                        <div class="mb-3"><label class="form-label">Nombre Identificador</label><input type="text" name="nombre_identificador" class="form-control" placeholder="Ej: Ventas Principal" required></div>
                        <div class="mb-3"><label class="form-label">Nombre en Gateway</label><input type="text" name="instance_name" class="form-control" placeholder="Ej: instancia_01" required></div>
                        <div class="mb-3"><label class="form-label">URL Gateway</label><input type="url" name="gateway_url" class="form-control" placeholder="https://api.tu-gateway.com" required></div>
                        <div class="mb-3"><label class="form-label">API Key</label><input type="password" name="api_key" class="form-control" required></div>
                        <button type="submit" class="btn btn-primary w-100">Guardar Instancia</button>
                    </form>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Mis Instancias</h5>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead><tr><th>Nombre</th><th>Estado</th><th>Acciones</th></tr></thead>
                            <tbody>
                                <?php foreach ($instances as $inst): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($inst['nombre_identificador']); ?></strong><br><small class="text-muted"><?php echo htmlspecialchars($inst['instance_name']); ?></small></td>
                                    <td><span class="badge bg-secondary"><?php echo strtoupper($inst['estado']); ?></span></td>
                                    <td>
                                        <a href="?view=whatsapp&instance_id=<?php echo $inst['id']; ?>" class="btn btn-sm btn-success"><i class="fab fa-whatsapp"></i> Conectar</a>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="id" value="<?php echo $inst['id']; ?>">
                                            <button type="submit" name="action" value="delete_instance" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar instancia?')"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    function include_users() {
        global $pdo;
        $users = $pdo->prepare("SELECT u.*, r.nombre as rol_nombre FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE u.id_sucursal = ?");
        $users->execute([$_SESSION['user_sucursal']]);
        $users = $users->fetchAll(PDO::FETCH_ASSOC);
        $roles = $pdo->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row">
            <div class="col-md-4">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Nuevo Usuario</h5>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="add_user">
                        <div class="mb-3"><label class="form-label">Nombre</label><input type="text" name="nombre" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
                        <div class="mb-3"><label class="form-label">Contraseña</label><input type="password" name="password" class="form-control" required></div>
                        <div class="mb-3">
                            <label class="form-label">Rol</label>
                            <select name="id_rol" class="form-select">
                                <?php foreach ($roles as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Crear Usuario</button>
                    </form>
                </div>
            </div>
            <div class="col-md-8">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Usuarios de la Empresa</h5>
                    <table class="table">
                        <thead><tr><th>Nombre</th><th>Email</th><th>Rol</th><th>Acción</th></tr></thead>
                        <tbody>
                            <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($u['nombre']); ?></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td><span class="badge bg-info"><?php echo $u['rol_nombre']; ?></span></td>
                                <td>
                                    <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                                        <button type="submit" name="action" value="delete_user" class="btn btn-sm text-danger"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    function include_settings() {
        global $pdo;
        $sid = $_SESSION['user_sucursal'];
        $openai_key = get_setting('openai_key', $sid);
        $agent_name = get_setting('agent_name', $sid) ?: 'Vendedor Pro';
        $agent_tone = get_setting('agent_tone', $sid) ?: 'profesional';
        $agent_instructions = get_setting('agent_instructions', $sid);

        $stmtI = $pdo->prepare("SELECT * FROM instancias_wa WHERE id_sucursal = ?");
        $stmtI->execute([$sid]);
        $instancias = $stmtI->fetchAll(PDO::FETCH_ASSOC);

        ?>
        <div class="card mx-auto p-4 mb-4" style="max-width: 800px;">
            <h5 class="fw-bold mb-4">Configuración Agente AI (Personalizado)</h5>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="save_settings">
                <div class="mb-3"><label class="form-label">Nombre Agente</label><input type="text" name="agent_name" class="form-control" value="<?php echo htmlspecialchars($agent_name); ?>"></div>
                <div class="mb-3"><label class="form-label">Tono de Voz</label><select name="agent_tone" class="form-select"><option value="profesional" <?php echo $agent_tone == 'profesional' ? 'selected' : ''; ?>>Profesional</option><option value="amigable" <?php echo $agent_tone == 'amigable' ? 'selected' : ''; ?>>Amigable</option><option value="divertido" <?php echo $agent_tone == 'divertido' ? 'selected' : ''; ?>>Divertido/Informal</option></select></div>
                <div class="mb-3"><label class="form-label">Instrucciones Base</label><textarea name="agent_instructions" class="form-control" rows="3"><?php echo htmlspecialchars($agent_instructions); ?></textarea></div>
                <div class="mb-3"><label class="form-label">OpenAI API Key</label><input type="password" name="openai_key" class="form-control" value="<?php echo htmlspecialchars($openai_key); ?>"></div>
                <button type="submit" class="btn btn-primary px-5">Guardar Configuración</button>
            </form>
        </div>

        <div class="card mx-auto p-4" style="max-width: 800px;">
            <h5 class="fw-bold mb-4">Webhooks de mis Instancias</h5>
            <p class="small text-muted">Configura estos Webhooks en tu Gateway para cada instancia:</p>
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Instancia</th><th>URL del Webhook</th></tr></thead>
                    <tbody>
                        <?php foreach($instancias as $i):
                            $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?action=webhook&token={$i['webhook_token']}";
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($i['nombre_identificador']); ?></strong></td>
                            <td>
                                <div class="input-group">
                                    <input type="text" class="form-control form-control-sm" value="<?php echo $url; ?>" readonly>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('<?php echo $url; ?>')">Copiar</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    ?>
</body>
</html>
