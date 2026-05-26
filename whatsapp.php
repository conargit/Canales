<?php
/**
 * WhatsApp SaaS Platform - AI Agent for Businesses
 * Version: 1.0
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
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die("Error de seguridad: Token CSRF no válido.");
        }
    }
}

// --- DATABASE SETUP ---
function getDb() {
    $dbFile = __DIR__ . '/whatsapp.db';
    $db = new PDO("sqlite:$dbFile");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create tables if they don't exist
    $db->exec("CREATE TABLE IF NOT EXISTS ajustes (
        clave TEXT PRIMARY KEY,
        valor TEXT
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS memoria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tipo TEXT,
        fuente TEXT,
        contenido TEXT,
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS chats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        remitente TEXT,
        mensaje TEXT,
        respuesta TEXT,
        modo TEXT DEFAULT 'auto', -- 'auto' or 'manual'
        fecha DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    return $db;
}

// Initialize DB on each load
try {
    $pdo = getDb();
} catch (Exception $e) {
    die("Error de base de datos: " . $e->getMessage());
}

// --- ACTIONS HANDLER ---
$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    check_csrf();
    $action = $_POST['action'];

    if ($action === 'scrape' && !empty($_POST['url'])) {
        $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);

        // Simple Scraper using CURL and DOMDocument
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html) {
            $doc = new DOMDocument();
            @$doc->loadHTML('<?xml encoding="UTF-8">' . $html);
            $xpath = new DOMXPath($doc);

            // Remove scripts and styles
            foreach ($xpath->query('//script|//style') as $node) {
                $node->parentNode->removeChild($node);
            }

            $text = strip_tags($doc->textContent);
            $text = preg_replace('/\s+/', ' ', $text); // Clean whitespace
            $text = trim($text);

            // Limit content size
            $text = mb_substr($text, 0, 5000);

            $stmt = $pdo->prepare("INSERT INTO memoria (tipo, fuente, contenido) VALUES (?, ?, ?)");
            $stmt->execute(['url', $url, $text]);
            $message = "URL analizada y guardada con éxito.";
        } else {
            $message = "Error al acceder a la URL.";
        }
    }

    if ($action === 'upload' && isset($_FILES['file'])) {
        $file = $_FILES['file'];
        if ($file['error'] === 0) {
            $content = file_get_contents($file['tmp_name']);
            $stmt = $pdo->prepare("INSERT INTO memoria (tipo, fuente, contenido) VALUES (?, ?, ?)");
            $stmt->execute(['file', $file['name'], $content]);
            $message = "Archivo cargado y procesado.";
        } else {
            $message = "Error al subir el archivo.";
        }
    }

    if ($action === 'delete_memory' && isset($_POST['id'])) {
        $stmt = $pdo->prepare("DELETE FROM memoria WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $message = "Fuente eliminada.";
    }

    if ($action === 'save_settings') {
        foreach (['openai_key', 'agent_name', 'agent_tone', 'agent_instructions'] as $key) {
            if (isset($_POST[$key])) {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO ajustes (clave, valor) VALUES (?, ?)");
                $stmt->execute([$key, $_POST[$key]]);
            }
        }
        $message = "Configuración guardada.";
    }

    if ($action === 'webhook_test') {
        // Simular llegada de mensaje de WhatsApp
        $remitente = $_POST['remitente'] ?? '+54 9 11 0000-0000';
        $mensaje_recibido = $_POST['mensaje'] ?? 'Hola, información por favor';

        $respuesta_ai = get_ai_response($mensaje_recibido);

        $stmt = $pdo->prepare("INSERT INTO chats (remitente, mensaje, respuesta, modo) VALUES (?, ?, ?, 'auto')");
        $stmt->execute([$remitente, $mensaje_recibido, $respuesta_ai]);
        $message = "Mensaje simulado procesado.";
    }

    if ($action === 'manual_reply' && isset($_POST['chat_id'])) {
        $stmt = $pdo->prepare("UPDATE chats SET respuesta = ?, modo = 'manual' WHERE id = ?");
        $stmt->execute([$_POST['respuesta'], $_POST['chat_id']]);
        $message = "Respuesta manual enviada.";
    }

    if ($action === 'toggle_mode' && isset($_POST['chat_id'])) {
        $stmt = $pdo->prepare("UPDATE chats SET modo = ? WHERE id = ?");
        $stmt->execute([$_POST['modo'], $_POST['chat_id']]);
    }
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

// Simple Router
function get_page_title($view) {
    switch ($view) {
        case 'dashboard': return 'Panel de Control';
        case 'training': return 'Entrenamiento de IA';
        case 'whatsapp': return 'Conexión WhatsApp';
        case 'chats': return 'Conversaciones';
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
    <title><?php echo get_page_title($view); ?> - WhatsApp AI SaaS</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8f9fa; }
        .sidebar { min-height: 100vh; background: #075E54; color: white; width: 250px; position: fixed; }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); margin: 5px 15px; border-radius: 8px; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background: rgba(255,255,255,0.1); color: white; }
        .sidebar .nav-link i { width: 25px; }
        .main-content { margin-left: 250px; padding: 30px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .navbar-top { background: white; border-bottom: 1px solid #eee; padding: 15px 30px; margin-left: 250px; }
        .badge-wa { background-color: #25D366; color: white; }
    </style>
</head>
<body>

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="p-4">
            <h4 class="fw-bold mb-0"><i class="fab fa-whatsapp me-2"></i>WA AI SaaS</h4>
            <small class="opacity-50">Plataforma de Agentes</small>
        </div>
        <nav class="nav flex-column mt-4">
            <a class="nav-link <?php echo $view == 'dashboard' ? 'active' : ''; ?>" href="?view=dashboard">
                <i class="fas fa-chart-line"></i> Panel de Control
            </a>
            <a class="nav-link <?php echo $view == 'training' ? 'active' : ''; ?>" href="?view=training">
                <i class="fas fa-brain"></i> Entrenamiento IA
            </a>
            <a class="nav-link <?php echo $view == 'whatsapp' ? 'active' : ''; ?>" href="?view=whatsapp">
                <i class="fab fa-whatsapp"></i> WhatsApp QR
            </a>
            <a class="nav-link <?php echo $view == 'chats' ? 'active' : ''; ?>" href="?view=chats">
                <i class="fas fa-comments"></i> Conversaciones
            </a>
            <a class="nav-link <?php echo $view == 'settings' ? 'active' : ''; ?>" href="?view=settings">
                <i class="fas fa-cog"></i> Ajustes
            </a>
        </nav>
    </div>

    <!-- Top Navbar -->
    <div class="navbar-top d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <h5 class="mb-0 fw-semibold text-dark me-3"><?php echo get_page_title($view); ?></h5>
            <?php if ($message): ?>
                <div class="alert alert-info py-1 px-3 mb-0 small alert-dismissible fade show" role="alert">
                    <?php echo $message; ?>
                    <button type="button" class="btn-close py-2" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center">
            <span class="badge badge-wa rounded-pill px-3 py-2 me-3">
                <i class="fas fa-circle text-white me-1" style="font-size: 8px;"></i> AI Activa
            </span>
            <div class="dropdown">
                <button class="btn btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown">
                    <i class="fas fa-user"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#">Mi Perfil</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="#">Cerrar Sesión</a></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <?php
        switch ($view) {
            case 'dashboard':
                include_dashboard();
                break;
            case 'training':
                include_training();
                break;
            case 'whatsapp':
                include_whatsapp();
                break;
            case 'chats':
                include_chats();
                break;
            case 'settings':
                include_settings();
                break;
            default:
                include_dashboard();
                break;
        }
        ?>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <?php
    // --- VIEWS ---

    function include_dashboard() {
        global $pdo;

        $total_msg = $pdo->query("SELECT COUNT(*) FROM chats")->fetchColumn();
        $auto_msg = $pdo->query("SELECT COUNT(*) FROM chats WHERE modo = 'auto'")->fetchColumn();
        $manual_msg = $pdo->query("SELECT COUNT(*) FROM chats WHERE modo = 'manual'")->fetchColumn();
        $fuentes_count = $pdo->query("SELECT COUNT(*) FROM memoria")->fetchColumn();

        $recientes = $pdo->query("SELECT * FROM chats ORDER BY fecha DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);

        $agent_name = $pdo->query("SELECT valor FROM ajustes WHERE clave = 'agent_name'")->fetchColumn() ?: 'Vendedor Pro';

        ?>
        <div class="row g-4">
            <div class="col-md-3">
                <div class="card p-4 text-center">
                    <h6 class="text-muted mb-2">Total Mensajes</h6>
                    <h2 class="fw-bold"><?php echo $total_msg; ?></h2>
                    <span class="text-success small">Histórico total</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-4 text-center">
                    <h6 class="text-muted mb-2">IA Respondidos</h6>
                    <h2 class="fw-bold"><?php echo $auto_msg; ?></h2>
                    <span class="text-success small">Automatización</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-4 text-center">
                    <h6 class="text-muted mb-2">Intervención Humana</h6>
                    <h2 class="fw-bold"><?php echo $manual_msg; ?></h2>
                    <span class="text-warning small">Atención manual</span>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card p-4 text-center">
                    <h6 class="text-muted mb-2">Fuentes Memoria</h6>
                    <h2 class="fw-bold"><?php echo $fuentes_count; ?></h2>
                    <span class="text-primary small">Conocimiento</span>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Actividad Reciente</h5>
                    <div class="table-responsive">
                        <table class="table align-middle">
                            <thead>
                                <tr>
                                    <th>Contacto</th>
                                    <th>Estado</th>
                                    <th>Mensaje</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recientes as $r): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($r['remitente']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $r['modo'] == 'auto' ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                                <?php echo strtoupper($r['modo']); ?>
                                            </span>
                                        </td>
                                        <td class="text-truncate" style="max-width: 200px;"><?php echo htmlspecialchars($r['mensaje']); ?></td>
                                        <td><a href="?view=chats&chat_id=<?php echo $r['id']; ?>" class="btn btn-sm btn-outline-primary">Ver</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recientes)): ?>
                                    <tr><td colspan="4" class="text-center py-3 text-muted">No hay actividad reciente</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card p-4 h-100 text-center">
                    <h5 class="fw-bold mb-4">Estado del Agente</h5>
                    <div class="py-4">
                        <div class="spinner-grow text-success mb-3" role="status"></div>
                        <h6>Agente "<?php echo htmlspecialchars($agent_name); ?>" Online</h6>
                        <p class="text-muted small">El sistema está escuchando mensajes y respondiendo automáticamente usando la memoria disponible.</p>
                        <hr>
                        <div class="d-grid">
                            <a href="?view=settings" class="btn btn-outline-primary btn-sm mb-2">Configurar IA</a>
                            <button class="btn btn-outline-danger btn-sm">Desactivar Sistema</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    function include_training() {
        global $pdo;
        $stmt = $pdo->query("SELECT * FROM memoria ORDER BY fecha DESC");
        $fuentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="row">
            <div class="col-md-6">
                <div class="card p-4 mb-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-link me-2 text-primary"></i>Entrenar por URL</h5>
                    <p class="text-muted small">Ingresa la URL de tu negocio y la IA la analizará para aprender sobre tus servicios.</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="input-group mb-3">
                            <input type="url" name="url" class="form-control" placeholder="https://tu-negocio.com" required>
                            <button class="btn btn-primary" type="submit" name="action" value="scrape">Analizar</button>
                        </div>
                    </form>
                </div>

                <div class="card p-4">
                    <h5 class="fw-bold mb-3"><i class="fas fa-file-alt me-2 text-primary"></i>Subir Documentos</h5>
                    <p class="text-muted small">Sube archivos TXT o MD con información específica, listas de precios o FAQs.</p>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <div class="mb-3">
                            <input class="form-control" type="file" name="file" accept=".txt,.md">
                        </div>
                        <button class="btn btn-primary w-100" type="submit" name="action" value="upload">Cargar Documento</button>
                    </form>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Memoria del Negocio (<?php echo count($fuentes); ?>)</h5>
                    <div class="list-group list-group-flush overflow-auto" style="max-height: 400px;">
                        <?php foreach ($fuentes as $f): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div class="text-truncate" style="max-width: 80%;">
                                    <h6 class="mb-0 text-truncate"><?php echo htmlspecialchars($f['fuente']); ?></h6>
                                    <small class="text-muted"><?php echo $f['tipo'] == 'url' ? 'Sitio Web' : 'Archivo'; ?> • <?php echo $f['fecha']; ?></small>
                                </div>
                                <form method="POST" onsubmit="return confirm('¿Eliminar esta fuente?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                    <button class="btn btn-sm text-danger" name="action" value="delete_memory"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($fuentes)): ?>
                            <div class="text-center py-4 opacity-50">
                                <i class="fas fa-database fa-3x mb-3"></i>
                                <p>Sin información entrenada.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    function include_whatsapp() {
        ?>
        <div class="card mx-auto mb-4" style="max-width: 600px;">
            <div class="card-body text-center p-5">
                <h4 class="fw-bold mb-4">Conecta tu WhatsApp</h4>
                <p class="text-muted mb-5">Escanea el código QR desde tu celular para vincular la cuenta y que la IA pueda responder mensajes.</p>

                <div class="bg-light p-4 rounded mb-4 d-inline-block position-relative">
                    <!-- Placeholder para el QR real -->
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=Simulated_WhatsApp_QR_SaaS" alt="QR Code" class="img-fluid rounded shadow-sm opacity-50">
                    <div class="position-absolute top-50 start-50 translate-middle text-dark fw-bold bg-white p-2 rounded shadow-sm border">
                        <i class="fas fa-qrcode me-2"></i>QR DEMO
                    </div>
                </div>

                <div class="mt-3">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    <span class="text-muted">Esperando escaneo del dispositivo...</span>
                </div>

                <hr class="my-5">

                <div class="row text-start g-3">
                    <div class="col-12">
                        <h6 class="fw-bold"><i class="fas fa-info-circle me-2"></i>Instrucciones:</h6>
                        <ol class="small text-muted">
                            <li>Abre WhatsApp en tu teléfono.</li>
                            <li>Toca en Menú o Configuración y selecciona Dispositivos vinculados.</li>
                            <li>Toca en Vincular un dispositivo.</li>
                            <li>Apunta tu teléfono hacia esta pantalla para escanear el código.</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mx-auto" style="max-width: 600px;">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fas fa-vial me-2"></i>Simulador de Mensajes (Webhook)</h6>
                <form method="POST" class="row g-2">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="webhook_test">
                    <div class="col-md-5">
                        <input type="text" name="remitente" class="form-control form-control-sm" placeholder="Número (+54...)" value="+54 9 11 5555-5555">
                    </div>
                    <div class="col-md-5">
                        <input type="text" name="mensaje" class="form-control form-control-sm" placeholder="Mensaje de prueba..." required>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Enviar</button>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }

    function include_chats() {
        global $pdo;
        $active_chat_id = $_GET['chat_id'] ?? null;

        $stmt = $pdo->query("SELECT * FROM chats ORDER BY fecha DESC");
        $all_chats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $current_chat = null;
        if ($active_chat_id) {
            foreach ($all_chats as $c) {
                if ($c['id'] == $active_chat_id) {
                    $current_chat = $c;
                    break;
                }
            }
        } elseif (!empty($all_chats)) {
            $current_chat = $all_chats[0];
        }

        ?>
        <div class="row g-0 h-100 card flex-row overflow-hidden" style="height: 700px !important;">
            <!-- Lista de Chats -->
            <div class="col-md-4 border-end h-100 overflow-auto">
                <div class="p-3 border-bottom sticky-top bg-white">
                    <input type="text" class="form-control" placeholder="Buscar chat...">
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($all_chats as $chat): ?>
                        <a href="?view=chats&chat_id=<?php echo $chat['id']; ?>"
                           class="list-group-item list-group-item-action p-3 <?php echo $current_chat['id'] == $chat['id'] ? 'active' : ''; ?>">
                            <div class="d-flex w-100 justify-content-between">
                                <h6 class="mb-1 fw-bold"><?php echo htmlspecialchars($chat['remitente']); ?></h6>
                                <small class="<?php echo $current_chat['id'] == $chat['id'] ? 'text-white' : 'text-muted'; ?>">
                                    <?php echo date('H:i', strtotime($chat['fecha'])); ?>
                                </small>
                            </div>
                            <p class="mb-1 small opacity-75 text-truncate"><?php echo htmlspecialchars($chat['mensaje']); ?></p>
                            <span class="badge <?php echo $chat['modo'] == 'auto' ? 'bg-success' : 'bg-warning text-dark'; ?> px-2 py-1">
                                <?php echo strtoupper($chat['modo']); ?>
                            </span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (empty($all_chats)): ?>
                        <div class="text-center py-5 opacity-50">
                            <p>No hay conversaciones aún.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- Chat Abierto -->
            <div class="col-md-8 d-flex flex-column h-100">
                <?php if ($current_chat): ?>
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-white">
                        <div>
                            <h6 class="mb-0 fw-bold"><?php echo htmlspecialchars($current_chat['remitente']); ?></h6>
                            <small class="<?php echo $current_chat['modo'] == 'auto' ? 'text-success' : 'text-warning'; ?>">
                                <i class="fas fa-circle" style="font-size: 8px;"></i>
                                <?php echo $current_chat['modo'] == 'auto' ? 'Atendido por IA' : 'Modo Manual'; ?>
                            </small>
                        </div>
                        <div class="d-flex">
                            <form method="POST" class="me-2">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>">
                                <input type="hidden" name="action" value="toggle_mode">
                                <input type="hidden" name="modo" value="<?php echo $current_chat['modo'] == 'auto' ? 'manual' : 'auto'; ?>">
                                <button type="submit" class="btn btn-sm <?php echo $current_chat['modo'] == 'auto' ? 'btn-outline-warning' : 'btn-outline-success'; ?>">
                                    <?php echo $current_chat['modo'] == 'auto' ? 'Pausar IA' : 'Activar IA'; ?>
                                </button>
                            </form>
                            <button class="btn btn-sm btn-outline-danger">Cerrar Caso</button>
                        </div>
                    </div>
                    <div class="flex-grow-1 p-4 bg-light overflow-auto">
                        <div class="d-flex flex-column gap-4">
                            <div class="align-self-start bg-white p-3 rounded shadow-sm" style="max-width: 80%;">
                                <small class="text-muted d-block mb-1">Cliente - <?php echo date('H:i', strtotime($current_chat['fecha'])); ?></small>
                                <?php echo htmlspecialchars($current_chat['mensaje']); ?>
                            </div>
                            <?php if ($current_chat['respuesta']): ?>
                                <div class="align-self-end <?php echo $current_chat['modo'] == 'auto' ? 'bg-success text-white' : 'bg-primary text-white'; ?> p-3 rounded shadow-sm" style="max-width: 80%;">
                                    <small class="text-white-50 d-block mb-1">
                                        <?php echo $current_chat['modo'] == 'auto' ? 'IA' : 'Operador'; ?>
                                    </small>
                                    <?php echo nl2br(htmlspecialchars($current_chat['respuesta'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="p-3 border-top bg-white">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <input type="hidden" name="action" value="manual_reply">
                            <input type="hidden" name="chat_id" value="<?php echo $current_chat['id']; ?>">
                            <div class="input-group">
                                <input type="text" name="respuesta" class="form-control" placeholder="Escribe un mensaje manual..." required>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button>
                            </div>
                        </form>
                        <small class="text-muted mt-2 d-block">Al enviar un mensaje manual, el modo cambiará a MANUAL automáticamente.</small>
                    </div>
                <?php else: ?>
                    <div class="flex-grow-1 d-flex align-items-center justify-content-center bg-light">
                        <div class="text-center opacity-25">
                            <i class="fas fa-comments fa-5x mb-3"></i>
                            <h4>Selecciona un chat</h4>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    function include_settings() {
        global $pdo;
        $stmt = $pdo->query("SELECT * FROM ajustes");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['clave']] = $row['valor'];
        }
        ?>
        <div class="card mx-auto" style="max-width: 800px;">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-4">Configuración del Agente</h5>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="action" value="save_settings">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Nombre del Agente</label>
                            <input type="text" name="agent_name" class="form-control" value="<?php echo htmlspecialchars($settings['agent_name'] ?? 'Vendedor Pro'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tono de Voz</label>
                            <?php $tone = $settings['agent_tone'] ?? 'profesional'; ?>
                            <select class="form-select" name="agent_tone">
                                <option value="profesional" <?php echo $tone == 'profesional' ? 'selected' : ''; ?>>Profesional y Educado</option>
                                <option value="amigable" <?php echo $tone == 'amigable' ? 'selected' : ''; ?>>Amigable y Cercano</option>
                                <option value="ventas" <?php echo $tone == 'ventas' ? 'selected' : ''; ?>>Persuasivo y Directo</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Instrucciones de Personalidad (System Prompt)</label>
                            <textarea class="form-control" name="agent_instructions" rows="3" placeholder="Eres un vendedor experto en tecnología..."><?php echo htmlspecialchars($settings['agent_instructions'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-4 pt-3 border-top">Conectividad API</h5>
                    <div class="mb-4">
                        <label class="form-label">OpenAI API Key</label>
                        <input type="password" name="openai_key" class="form-control" placeholder="sk-..." value="<?php echo htmlspecialchars($settings['openai_key'] ?? ''); ?>">
                        <small class="text-muted">Tu clave se guarda de forma segura en la base de datos local.</small>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <button type="submit" class="btn btn-primary px-5">Guardar Cambios</button>
                        <span class="text-muted small">Versión 1.0.0</span>
                    </div>
                </form>
            </div>
        </div>
        <?php
    }
    ?>
</body>
</html>
