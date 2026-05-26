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

// --- DATABASE SETUP (MySQL) ---
function getDb() {
    $host = getenv('DB_HOST') ?: 'localhost';
    $dbname = getenv('DB_NAME') ?: 'whatsapp';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    try {
        $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4";
        $db = new PDO($dsn, $user, $pass);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables if they don't exist
        $db->exec("CREATE TABLE IF NOT EXISTS ajustes (
            clave VARCHAR(255) PRIMARY KEY,
            valor TEXT
        ) ENGINE=InnoDB");

        // Seed webhook token if not exists
        $stmt = $db->prepare("SELECT COUNT(*) FROM ajustes WHERE clave = 'webhook_token'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $db->prepare("INSERT INTO ajustes (clave, valor) VALUES ('webhook_token', ?)")->execute([bin2hex(random_bytes(16))]);
        }

        $db->exec("CREATE TABLE IF NOT EXISTS memoria (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo VARCHAR(50),
            fuente VARCHAR(255),
            contenido LONGTEXT,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $db->exec("CREATE TABLE IF NOT EXISTS chats (
            id INT AUTO_INCREMENT PRIMARY KEY,
            remitente VARCHAR(50),
            mensaje TEXT,
            respuesta TEXT,
            modo VARCHAR(20) DEFAULT 'auto',
            id_asesor INT DEFAULT 0,
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $db->exec("CREATE TABLE IF NOT EXISTS campanas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(255),
            mensaje TEXT,
            destinatarios TEXT,
            estado VARCHAR(50) DEFAULT 'pendiente',
            fecha DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");

        $db->exec("CREATE TABLE IF NOT EXISTS asesores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(255),
            email VARCHAR(255),
            estado VARCHAR(50) DEFAULT 'activo'
        ) ENGINE=InnoDB");

        return $db;
    } catch (PDOException $e) {
        die("Error de conexión MySQL: " . $e->getMessage() . ". Asegúrese de que la base de datos '$dbname' existe.");
    }
}

// Initialize DB
$pdo = getDb();

// --- HELPERS ---
function get_setting($clave) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT valor FROM ajustes WHERE clave = ?");
    $stmt->execute([$clave]);
    return $stmt->fetchColumn();
}

function gateway_call($endpoint, $method = 'GET', $body = null) {
    $url = get_setting('gateway_url');
    $token = get_setting('gateway_token');

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
function get_ai_response($userMessage) {
    global $pdo;

    // 1. Get Settings
    $settings = [];
    $stmt = $pdo->query("SELECT * FROM ajustes");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['clave']] = $row['valor'];
    }

    $apiKey = $settings['openai_key'] ?? '';
    if (empty($apiKey)) return "Error: OpenAI API Key no configurada.";

    // 2. Search Context (Simple Keyword Search for RAG)
    $context = "";
    $words = explode(' ', $userMessage);
    $searchTerms = array_filter($words, function($w) { return strlen($w) > 3; });

    if (!empty($searchTerms)) {
        $queryParts = [];
        $params = [];
        foreach ($searchTerms as $term) {
            $queryParts[] = "contenido LIKE ?";
            $params[] = "%$term%";
        }
        $stmt = $pdo->prepare("SELECT contenido FROM memoria WHERE " . implode(" OR ", $queryParts) . " LIMIT 3");
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $context = implode("\n\n", $results);
    }

    // 3. Prepare AI Request
    $agentName = $settings['agent_name'] ?? 'Asistente';
    $agentTone = $settings['agent_tone'] ?? 'profesional';
    $instructions = $settings['agent_instructions'] ?? 'Eres un asistente útil.';

    $systemPrompt = "Tu nombre es $agentName. Tono: $agentTone. $instructions\n\nContexto del negocio:\n$context";

    $data = [
        'model' => 'gpt-3.5-turbo',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage]
        ]
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
    $stmtT = $pdo->prepare("SELECT valor FROM ajustes WHERE clave = 'webhook_token'");
    $stmtT->execute();
    $real_token = $stmtT->fetchColumn();

    if ($token_req !== $real_token) {
        http_response_code(401);
        die("Unauthorized");
    }

    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if ($data) {
        // Logic depends on the Gateway (Evolution API, etc)
        // Usually: data['data']['message']['conversation'] and data['data']['key']['remoteJid']
        $mensaje = $data['data']['message']['conversation'] ?? $data['data']['message']['extendedTextMessage']['text'] ?? '';
        $remitente = explode('@', $data['data']['key']['remoteJid'] ?? '')[0] ?? 'Desconocido';

        if (!empty($mensaje) && !empty($remitente)) {
            $respuesta_ai = get_ai_response($mensaje);
            $stmt = $pdo->prepare("INSERT INTO chats (remitente, mensaje, respuesta, modo) VALUES (?, ?, ?, 'auto')");
            $stmt->execute([$remitente, $mensaje, $respuesta_ai]);

            // Here we would call the Gateway SEND API
            // gateway_send($remitente, $respuesta_ai);
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
            $stmt = $pdo->prepare("INSERT INTO memoria (tipo, fuente, contenido) VALUES (?, ?, ?)");
            $stmt->execute(['url', $url, $text]);
            $message = "URL analizada y guardada con éxito.";
        } else {
            $message = "Error al acceder a la URL.";
        }
    }

    if ($action === 'upload' && isset($_FILES['file']) && $_FILES['file']['error'] === 0) {
        $stmt = $pdo->prepare("INSERT INTO memoria (tipo, fuente, contenido) VALUES (?, ?, ?)");
        $stmt->execute(['file', $_FILES['file']['name'], file_get_contents($_FILES['file']['tmp_name'])]);
        $message = "Archivo cargado.";
    }

    if ($action === 'delete_memory' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM memoria WHERE id = ?")->execute([$_POST['id']]);
        $message = "Fuente eliminada.";
    }

    if ($action === 'save_settings') {
        foreach (['openai_key', 'agent_name', 'agent_tone', 'agent_instructions', 'gateway_url', 'gateway_token', 'gateway_instance'] as $key) {
            if (isset($_POST[$key])) {
                $pdo->prepare("INSERT INTO ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")->execute([$key, $_POST[$key]]);
            }
        }
        $message = "Configuración guardada.";
    }

    if ($action === 'webhook_test') {
        $remitente = $_POST['remitente'] ?? '+54 9 11 0000-0000';
        $mensaje_recibido = $_POST['mensaje'] ?? 'Hola';
        $respuesta_ai = get_ai_response($mensaje_recibido);
        $pdo->prepare("INSERT INTO chats (remitente, mensaje, respuesta, modo) VALUES (?, ?, ?, 'auto')")->execute([$remitente, $mensaje_recibido, $respuesta_ai]);
        $message = "Mensaje simulado procesado.";
    }

    if ($action === 'manual_reply' && isset($_POST['chat_id'])) {
        $pdo->prepare("UPDATE chats SET respuesta = ?, modo = 'manual' WHERE id = ?")->execute([$_POST['respuesta'], $_POST['chat_id']]);
        $message = "Respuesta manual enviada.";
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
        $pdo->prepare("UPDATE chats SET id_asesor = ?, modo = 'manual' WHERE id = ?")->execute([$_POST['id_asesor'], $_POST['chat_id']]);
        $message = "Chat asignado.";
    }

    if ($action === 'create_campana' && !empty($_POST['nombre'])) {
        $pdo->prepare("INSERT INTO campanas (nombre, mensaje, destinatarios) VALUES (?, ?, ?)")->execute([$_POST['nombre'], $_POST['mensaje'], $_POST['destinatarios']]);
        $message = "Campaña creada.";
    }

    if ($action === 'delete_campana' && isset($_POST['id'])) {
        $pdo->prepare("DELETE FROM campanas WHERE id = ?")->execute([$_POST['id']]);
        $message = "Campaña eliminada.";
    }

    if ($action === 'send_campana' && isset($_POST['id'])) {
        $stmtC = $pdo->prepare("SELECT * FROM campanas WHERE id = ?");
        $stmtC->execute([$_POST['id']]);
        $camp = $stmtC->fetch(PDO::FETCH_ASSOC);

        if ($camp) {
            $numeros = preg_split('/[\s,]+/', $camp['destinatarios']);
            $count = 0;
            foreach ($numeros as $num) {
                $num = trim($num);
                if (!empty($num)) {
                    gateway_call("message/sendText/" . get_setting('gateway_instance'), 'POST', [
                        'number' => $num,
                        'text' => $camp['mensaje']
                    ]);
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
        default: return 'WhatsApp AI';
    }
}

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
    <div class="sidebar">
        <div class="p-4">
            <h4 class="fw-bold mb-0"><i class="fab fa-whatsapp me-2"></i>WA AI SaaS</h4>
            <small class="opacity-50">Plataforma de Agentes</small>
        </div>
        <nav class="nav flex-column mt-4">
            <a class="nav-link <?php echo $view == 'dashboard' ? 'active' : ''; ?>" href="?view=dashboard"><i class="fas fa-chart-line"></i> Panel de Control</a>
            <a class="nav-link <?php echo $view == 'training' ? 'active' : ''; ?>" href="?view=training"><i class="fas fa-brain"></i> Entrenamiento IA</a>
            <a class="nav-link <?php echo $view == 'whatsapp' ? 'active' : ''; ?>" href="?view=whatsapp"><i class="fab fa-whatsapp"></i> WhatsApp QR</a>
            <a class="nav-link <?php echo $view == 'chats' ? 'active' : ''; ?>" href="?view=chats"><i class="fas fa-comments"></i> Conversaciones</a>
            <a class="nav-link <?php echo $view == 'campanas' ? 'active' : ''; ?>" href="?view=campanas"><i class="fas fa-bullhorn"></i> Campañas</a>
            <a class="nav-link <?php echo $view == 'asesores' ? 'active' : ''; ?>" href="?view=asesores"><i class="fas fa-users"></i> Asesores</a>
            <a class="nav-link <?php echo $view == 'settings' ? 'active' : ''; ?>" href="?view=settings"><i class="fas fa-cog"></i> Ajustes</a>
        </nav>
    </div>

    <div class="navbar-top d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h5 class="mb-0 fw-semibold text-dark me-3"><?php echo get_page_title($view); ?></h5>
            <?php if ($message): ?> <div class="alert alert-info py-1 px-3 mb-0 small alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button></div> <?php endif; ?>
        </div>
        <div class="d-flex align-items-center">
            <span class="badge badge-wa rounded-pill px-3 py-2 me-3"><i class="fas fa-circle text-white me-1" style="font-size: 8px;"></i> AI Activa</span>
            <div class="dropdown">
                <button class="btn btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown"><i class="fas fa-user"></i></button>
                <ul class="dropdown-menu dropdown-menu-end"><li><a class="dropdown-item" href="#">Mi Perfil</a></li><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="#">Cerrar Sesión</a></li></ul>
            </div>
        </div>
    </div>

    <div class="main-content">
        <?php
        switch ($view) {
            case 'dashboard': include_dashboard(); break;
            case 'training': include_training(); break;
            case 'whatsapp': include_whatsapp(); break;
            case 'chats': include_chats(); break;
            case 'campanas': include_campanas(); break;
            case 'asesores': include_asesores(); break;
            case 'settings': include_settings(); break;
            default: include_dashboard(); break;
        }
        ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php
    function include_dashboard() {
        global $pdo;
        $total_msg = $pdo->query("SELECT COUNT(*) FROM chats")->fetchColumn();
        $auto_msg = $pdo->query("SELECT COUNT(*) FROM chats WHERE modo = 'auto'")->fetchColumn();
        $fuentes_count = $pdo->query("SELECT COUNT(*) FROM memoria")->fetchColumn();
        $campanas_count = $pdo->query("SELECT COUNT(*) FROM campanas")->fetchColumn();
        $asesores_count = $pdo->query("SELECT COUNT(*) FROM asesores")->fetchColumn();
        $recientes = $pdo->query("SELECT * FROM chats ORDER BY fecha DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $agent_name = $pdo->query("SELECT valor FROM ajustes WHERE clave = 'agent_name'")->fetchColumn() ?: 'Vendedor Pro';
        ?>
        <div class="row g-4 text-center">
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">Total Mensajes</h6><h2 class="fw-bold"><?php echo $total_msg; ?></h2></div></div>
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">IA Respondidos</h6><h2 class="fw-bold"><?php echo $auto_msg; ?></h2></div></div>
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">Asesores</h6><h2 class="fw-bold"><?php echo $asesores_count; ?></h2></div></div>
            <div class="col-md-3"><div class="card p-4"><h6 class="text-muted mb-2">Campañas</h6><h2 class="fw-bold"><?php echo $campanas_count; ?></h2></div></div>
            <div class="col-md-8 text-start"><div class="card p-4"><h5 class="fw-bold mb-4">Actividad Reciente</h5><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Contacto</th><th>Estado</th><th>Mensaje</th><th>Acción</th></tr></thead><tbody><?php foreach ($recientes as $r): ?><tr><td><?php echo htmlspecialchars($r['remitente']); ?></td><td><span class="badge <?php echo $r['modo'] == 'auto' ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo strtoupper($r['modo']); ?></span></td><td class="text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($r['mensaje']); ?></td><td><a href="?view=chats&chat_id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Ver</a></td></tr><?php endforeach; ?></tbody></table></div></div></div>
            <div class="col-md-4"><div class="card p-4 h-100"><h5 class="fw-bold mb-4">Estado del Agente</h5><div class="py-4"><div class="spinner-grow text-success mb-3"></div><h6>Agente "<?php echo htmlspecialchars($agent_name); ?>" Online</h6><hr><div class="d-grid"><a href="?view=settings" class="btn btn-outline-primary btn-sm mb-2">Configurar</a><button class="btn btn-outline-danger btn-sm">Desactivar</button></div></div></div></div>
        </div>
        <?php
    }

    function include_training() {
        global $pdo; $fuentes = $pdo->query("SELECT * FROM memoria ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
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
        $gateway_url = get_setting('gateway_url');
        $instance = get_setting('gateway_instance');

        $qr_image = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=Configure_Gateway_First";

        if ($gateway_url && $instance) {
            $data = gateway_call("instance/connect/$instance");
            if (isset($data['base64'])) {
                $qr_image = $data['base64'];
            } else {
                $qr_image = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=Error_Fetching_QR_Check_Settings";
            }
        }
        ?>
        <div class="card mx-auto mb-4 text-center p-5" style="max-width: 600px;"><h4 class="fw-bold mb-4">Conecta tu WhatsApp</h4><div class="bg-light p-4 rounded mb-4 d-inline-block"><img src="<?php echo $qr_image; ?>" class="img-fluid rounded shadow-sm" style="max-width: 300px;"></div><div class="mt-3"><span class="text-muted"><?php echo $gateway_url ? "Escanea para vincular $instance" : "Configura el Gateway en Ajustes"; ?></span></div></div>
        <div class="card mx-auto p-4" style="max-width: 600px;"><h6 class="fw-bold mb-3">Simulador de Webhook</h6><form method="POST" class="row g-2"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="webhook_test"><div class="col-md-5"><input type="text" name="remitente" class="form-control" value="+5491155555555"></div><div class="col-md-5"><input type="text" name="mensaje" class="form-control" placeholder="Mensaje..." required></div><div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Enviar</button></div></form></div>
        <?php
    }

    function include_chats() {
        global $pdo; $active_chat_id = $_GET['chat_id'] ?? null;
        $all_chats = $pdo->query("SELECT * FROM chats ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
        $current_chat = null; if ($active_chat_id) { foreach ($all_chats as $c) { if ($c['id'] == $active_chat_id) { $current_chat = $c; break; } } } elseif (!empty($all_chats)) { $current_chat = $all_chats[0]; }
        ?>
        <div class="row g-0 h-100 card flex-row overflow-hidden" style="height: 700px !important;">
            <div class="col-md-4 border-end h-100 overflow-auto"><div class="list-group list-group-flush"><?php foreach ($all_chats as $chat): ?><a href="?view=chats&chat_id=<?php echo $chat['id']; ?>" class="list-group-item list-group-item-action p-3 <?php echo $current_chat['id'] == $chat['id'] ? 'active' : ''; ?>"><h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($chat['remitente']); ?></h6><p class="mb-1 small opacity-75 text-truncate"><?php echo htmlspecialchars($chat['mensaje']); ?></p></a><?php endforeach; ?></div></div>
            <div class="col-md-8 d-flex flex-column h-100"><?php if ($current_chat): ?>
                <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white"><div><h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($current_chat['remitente']); ?></h6><small class="text-success"><?php echo strtoupper($current_chat['modo']); ?></small></div><div class="d-flex align-items-center"><form method="POST" class="me-2 d-flex"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="assign_asesor"><input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>"><select name="id_asesor" class="form-select form-select-sm" onchange="this.form.submit()"><option value="0">Sin Asesor</option><?php $stmtA = $pdo->query("SELECT * FROM asesores ORDER BY nombre ASC"); while ($rowA = $stmtA->fetch(PDO::FETCH_ASSOC)) { $sel = $current_chat['id_asesor'] == $rowA['id'] ? 'selected' : ''; echo "<option value='{$rowA['id']}' $sel>{$rowA['nombre']}</option>"; } ?></select></form><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>"><input type="hidden" name="action" value="toggle_mode"><input type="hidden" name="modo" value="<?php echo $current_chat['modo'] == 'auto' ? 'manual' : 'auto'; ?>"><button type="submit" class="btn btn-sm btn-outline-warning"><?php echo $current_chat['modo'] == 'auto' ? 'Pausar IA' : 'Activar IA'; ?></button></form></div></div>
                <div class="flex-grow-1 p-4 bg-light overflow-auto"><div class="d-flex flex-column gap-3"><div class="align-self-start bg-white p-3 rounded shadow-sm"><?php echo htmlspecialchars($current_chat['mensaje']); ?></div><?php if ($current_chat['respuesta']): ?><div class="align-self-end bg-success text-white p-3 rounded shadow-sm"><?php echo nl2br(htmlspecialchars($current_chat['respuesta'])); ?></div><?php endif; ?></div></div>
                <div class="p-3 border-top bg-white"><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="manual_reply"><input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>"><div class="input-group"><input type="text" name="respuesta" class="form-control" placeholder="Responder manual..." required><button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button></div></form></div>
            <?php endif; ?></div>
        </div>
        <?php
    }

    function include_campanas() {
        global $pdo; $campanas = $pdo->query("SELECT * FROM campanas ORDER BY fecha DESC")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row"><div class="col-md-5"><div class="card p-4"><h5 class="fw-bold mb-4">Nueva Campaña</h5><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="create_campana"><div class="mb-3"><label class="form-label">Nombre</label><input type="text" name="nombre" class="form-control" required></div><div class="mb-3"><label class="form-label">Mensaje</label><textarea name="mensaje" class="form-control" rows="3" required></textarea></div><div class="mb-3"><label class="form-label">Destinatarios</label><textarea name="destinatarios" class="form-control" rows="3" placeholder="+549..."></textarea></div><button type="submit" class="btn btn-primary w-100">Crear Campaña</button></form></div></div>
        <div class="col-md-7"><div class="card p-4 h-100"><h5 class="fw-bold mb-4">Historial</h5><div class="list-group list-group-flush"><?php foreach ($campanas as $c): ?><div class="list-group-item d-flex justify-content-between align-items-center"><div><h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($c['nombre']); ?></h6><small><?php echo $c['fecha']; ?> • <?php echo strtoupper($c['estado']); ?></small></div><div class="d-flex"><form method="POST" class="me-1"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button class="btn btn-sm btn-success" name="action" value="send_campana" <?php echo $c['estado'] == 'completada' ? 'disabled' : ''; ?>><i class="fas fa-paper-plane"></i></button></form><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $c['id']; ?>"><button class="btn btn-sm text-danger" name="action" value="delete_campana"><i class="fas fa-trash"></i></button></form></div></div><?php endforeach; ?></div></div></div></div>
        <?php
    }

    function include_asesores() {
        global $pdo; $asesores = $pdo->query("SELECT * FROM asesores ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row"><div class="col-md-4"><div class="card p-4"><h5 class="fw-bold mb-4">Agregar Asesor</h5><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="add_asesor"><div class="mb-3"><label class="form-label">Nombre</label><input type="text" name="nombre" class="form-control" required></div><button type="submit" class="btn btn-primary w-100">Guardar</button></form></div></div>
        <div class="col-md-8"><div class="card p-4"><h5 class="fw-bold mb-4">Asesores</h5><table class="table"><thead><tr><th>Nombre</th><th>Acción</th></tr></thead><tbody><?php foreach ($asesores as $a): ?><tr><td><?php echo htmlspecialchars($a['nombre']); ?></td><td><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="id" value="<?php echo $a['id']; ?>"><button type="submit" name="action" value="delete_asesor" class="btn btn-sm text-danger"><i class="fas fa-trash"></i></button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
        <?php
    }

    function include_settings() {
        global $pdo; $stmt = $pdo->query("SELECT * FROM ajustes"); $settings = []; while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) { $settings[$row['clave']] = $row['valor']; }
        $webhook_token = $settings['webhook_token'] ?? '';
        $full_webhook_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?action=webhook&token=$webhook_token";
        ?>
        <div class="card mx-auto p-4 mb-4" style="max-width: 800px;"><h5 class="fw-bold mb-4">Configuración Agente AI</h5><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>"><input type="hidden" name="action" value="save_settings"><div class="mb-3"><label class="form-label">Nombre Agente</label><input type="text" name="agent_name" class="form-control" value="<?php echo htmlspecialchars($settings['agent_name'] ?? 'Vendedor Pro'); ?>"></div><div class="mb-3"><label class="form-label">OpenAI Key</label><input type="password" name="openai_key" class="form-control" value="<?php echo htmlspecialchars($settings['openai_key'] ?? ''); ?>"></div><hr><div class="mb-3"><label class="form-label">Gateway URL</label><input type="url" name="gateway_url" class="form-control" value="<?php echo htmlspecialchars($settings['gateway_url'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Gateway Instance</label><input type="text" name="gateway_instance" class="form-control" value="<?php echo htmlspecialchars($settings['gateway_instance'] ?? ''); ?>"></div><div class="mb-3"><label class="form-label">Gateway API Token</label><input type="password" name="gateway_token" class="form-control" value="<?php echo htmlspecialchars($settings['gateway_token'] ?? ''); ?>"></div><button type="submit" class="btn btn-primary px-5">Guardar Todo</button></form></div>
        <div class="card mx-auto p-4" style="max-width: 800px;"><h6 class="fw-bold mb-3">Configuración de Webhook (Gateway)</h6><p class="small text-muted">Copia esta URL en la configuración de tu Gateway (Evolution API, etc.) para recibir mensajes en tiempo real:</p><div class="input-group"><input type="text" class="form-control form-control-sm" value="<?php echo $full_webhook_url; ?>" readonly><button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText('<?php echo $full_webhook_url; ?>')">Copiar</button></div></div>
        <?php
    }
    ?>
</body>
</html>
