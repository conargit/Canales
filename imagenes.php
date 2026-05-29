<?php
/**
 * Visual Intelligence Center - Centro de Inteligencia Visual con IA
 * Versión 7.0 - Single File Edition
 */

error_reporting(E_ALL);
ini_set('display_errors', 0); // Ocultar en producción, usar logs
session_start();

// --- SEGURIDAD ---
define('ADMIN_PASS', 'admin123');

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: imagenes.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_pass'])) {
    if ($_POST['login_pass'] === ADMIN_PASS) {
        $_SESSION['authenticated'] = true;
        jsonResponse(['message' => 'Login exitoso']);
    } else {
        jsonResponse(['error' => 'Contraseña incorrecta'], false);
    }
}

// Bloquear acceso si no está autenticado (excepto para login)
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    // Si es una petición AJAX/API
    if (isset($_GET['action']) || isset($_POST['action'])) {
        jsonResponse(['error' => 'No autorizado'], false);
    }
    // Si es carga de página, mostrar formulario de login más abajo en el HTML
}

// --- CONFIGURACIÓN Y DIRECTORIOS ---
$base_dir = 'imagenes/';
$config_file = 'config_ai.json';
$history_file = 'historial_ai.json';

if (!file_exists($base_dir)) mkdir($base_dir, 0775, true);

// --- UTILIDADES ---
function jsonResponse($data, $success = true) {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

function getAIConfig() {
    global $config_file;
    if (!file_exists($config_file)) return [];
    return json_decode(file_get_contents($config_file), true) ?: [];
}

function saveAIConfig($config) {
    global $config_file;
    return file_put_contents($config_file, json_encode($config, JSON_PRETTY_PRINT));
}

function getAIHistory() {
    global $history_file;
    if (!file_exists($history_file)) return [];
    return json_decode(file_get_contents($history_file), true) ?: [];
}

function saveAIHistory($entry) {
    global $history_file;
    $history = getAIHistory();
    array_unshift($history, array_merge(['id' => uniqid(), 'timestamp' => date('Y-m-d H:i:s')], $entry));
    // Limitar a los últimos 50 registros
    $history = array_slice($history, 0, 50);
    return file_put_contents($history_file, json_encode($history, JSON_PRETTY_PRINT));
}

// --- ACCIONES DE ARCHIVOS ---
$action = $_GET['action'] ?? $_POST['action'] ?? null;

if ($action === 'upload') {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Error al subir la imagen'], false);
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, $allowed)) {
        jsonResponse(['error' => 'Formato no permitido. Use JPG, PNG o WEBP.'], false);
    }

    $filename = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "", $_FILES['image']['name']);
    if (move_uploaded_file($_FILES['image']['tmp_name'], $base_dir . $filename)) {
        jsonResponse(['message' => 'Imagen subida con éxito', 'filename' => $filename]);
    } else {
        jsonResponse(['error' => 'No se pudo guardar el archivo'], false);
    }
}

if ($action === 'list') {
    $search = $_GET['search'] ?? '';
    $files = array_diff(scandir($base_dir), ['.', '..']);
    $result = [];

    foreach ($files as $file) {
        if ($search && stripos($file, $search) === false) continue;
        $result[] = [
            'name' => $file,
            'url' => $base_dir . $file,
            'size' => round(filesize($base_dir . $file) / 1024, 2) . ' KB',
            'date' => date("Y-m-d H:i:s", filemtime($base_dir . $file))
        ];
    }
    // Ordenar por fecha descendente
    usort($result, function($a, $b) { return strcmp($b['date'], $a['date']); });
    jsonResponse(['files' => $result]);
}

if ($action === 'delete') {
    $filename = $_POST['filename'] ?? '';
    $path = $base_dir . basename($filename);
    if ($filename && file_exists($path)) {
        unlink($path);
        jsonResponse(['message' => 'Archivo eliminado']);
    }
    jsonResponse(['error' => 'Archivo no encontrado'], false);
}

if ($action === 'rename') {
    $oldname = $_POST['oldname'] ?? '';
    $newname = $_POST['newname'] ?? '';
    $oldpath = $base_dir . basename($oldname);
    $newpath = $base_dir . basename($newname);

    if (file_exists($oldpath) && !file_exists($newpath)) {
        rename($oldpath, $newpath);
        jsonResponse(['message' => 'Archivo renombrado']);
    }
    jsonResponse(['error' => 'Error al renombrar'], false);
}

// --- AI LOGIC ---
function callGemini($key, $imagePath, $prompt) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent?key=$key";
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = mime_content_type($imagePath);

    $payload = [
        "contents" => [[
            "parts" => [
                ["text" => $prompt],
                ["inline_data" => ["mime_type" => $mimeType, "data" => $imageData]]
            ]
        ]],
        "generationConfig" => ["responseModalities" => ["TEXT", "IMAGE"]]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function callDalle($key, $prompt) {
    $url = "https://api.openai.com/v1/images/generations";
    $payload = [
        "model" => "dall-e-3",
        "prompt" => $prompt,
        "n" => 1,
        "size" => "1024x1024",
        "response_format" => "b64_json"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

function callOpenAI($key, $imagePath, $prompt) {
    $url = "https://api.openai.com/v1/chat/completions";
    $imageData = base64_encode(file_get_contents($imagePath));
    $mimeType = mime_content_type($imagePath);

    $payload = [
        "model" => "gpt-4o",
        "messages" => [[
            "role" => "user",
            "content" => [
                ["type" => "text", "text" => $prompt],
                ["type" => "image_url", "image_url" => ["url" => "data:$mimeType;base64,$imageData"]]
            ]
        ]]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

if ($action === 'ai_chat') {
    $config = getAIConfig();
    $filename = $_POST['filename'] ?? '';
    $prompt = $_POST['prompt'] ?? '';
    $type = $_POST['type'] ?? 'chat';
    $path = $base_dir . basename($filename);

    if (!file_exists($path)) jsonResponse(['error' => 'Imagen no encontrada'], false);

    $provider = $config['provider'] ?? 'gemini';
    $key = ($provider === 'gemini') ? ($config['gemini_key'] ?? '') : ($config['openai_key'] ?? '');

    if (!$key) jsonResponse(['error' => 'API Key no configurada para ' . $provider], false);

    // Prompt engineering según el tipo
    $finalPrompt = $prompt;
    $numVariants = 1;

    switch($type) {
        case 'analyze': $finalPrompt = "Analiza detalladamente esta imagen. ¿Qué ves? Describe elementos, ambiente y calidad."; break;
        case 'decor': $finalPrompt = "Actúa como un diseñador de interiores. Dame 5 ideas creativas para decorar o mejorar este espacio/imagen."; break;
        case 'marketing': $finalPrompt = "Genera una estrategia de marketing para esta imagen: un copy persuasivo para Instagram, 10 hashtags y un título gancho."; break;
        case 'seo': $finalPrompt = "Genera un título SEO, una meta-descripción de 150 caracteres y un texto Alt optimizado para esta imagen."; break;
        case 'variants':
            $finalPrompt = "Genera una nueva versión visual de esta imagen con un diseño moderno, premium y optimizado. Mantén la estructura pero mejora el estilo.";
            $numVariants = 3;
            break;
    }

    try {
        $responseText = "";
        $generatedImages = [];

        if ($provider === 'gemini') {
            for ($i = 0; $i < $numVariants; $i++) {
                $response = callGemini($key, $path, $finalPrompt);
                $responseText .= ($response['candidates'][0]['content']['parts'][0]['text'] ?? '') . "\n\n";

                if (isset($response['candidates'][0]['content']['parts'])) {
                    foreach($response['candidates'][0]['content']['parts'] as $part) {
                        if (isset($part['inline_data'])) {
                            $imgData = base64_decode($part['inline_data']['data']);
                            $newFilename = 'ai_' . time() . '_' . $i . '.png';
                            file_put_contents($base_dir . $newFilename, $imgData);
                            $generatedImages[] = $newFilename;
                        }
                    }
                }
                if ($type !== 'variants') break;
            }
        } else {
            $response = callOpenAI($key, $path, $finalPrompt);
            $responseText = $response['choices'][0]['message']['content'] ?? 'Sin respuesta de OpenAI';

            if ($type === 'variants') {
                $dalleResp = callDalle($key, "Basado en esta descripción: $responseText. Genera una imagen fotorrealista de alta calidad siguiendo el estilo solicitado.");
                if (isset($dalleResp['data'][0]['b64_json'])) {
                    $imgData = base64_decode($dalleResp['data'][0]['b64_json']);
                    $newFilename = 'ai_dalle_' . time() . '.png';
                    file_put_contents($base_dir . $newFilename, $imgData);
                    $generatedImages[] = $newFilename;
                }
            }
        }

        saveAIHistory(['filename' => $filename, 'prompt' => $finalPrompt, 'response' => $responseText, 'provider' => $provider, 'result_images' => $generatedImages]);
        jsonResponse(['response' => $responseText, 'new_images' => $generatedImages]);

    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], false);
    }
}

if ($action === 'get_history') {
    jsonResponse(['history' => getAIHistory()]);
}

if ($action === 'save_config') {
    $config = [
        'gemini_key' => $_POST['gemini_key'] ?? '',
        'openai_key' => $_POST['openai_key'] ?? '',
        'provider' => $_POST['provider'] ?? 'gemini'
    ];
    saveAIConfig($config);
    jsonResponse(['message' => 'Configuración guardada']);
}

if ($action === 'get_config') {
    jsonResponse(['config' => getAIConfig()]);
}

// --- RENDERING FRONTEND ---
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Visual Intelligence Center</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts: Orbitron & Montserrat -->
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --neon-cyan: #00f2ff;
            --neon-purple: #bc13fe;
            --dark-bg: #0a0b10;
            --glass: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        body {
            background-color: var(--dark-bg);
            color: #e0e0e0;
            font-family: 'Montserrat', sans-serif;
            overflow-x: hidden;
        }

        .orbitron { font-family: 'Orbitron', sans-serif; }

        .glass-card {
            background: var(--glass);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        .btn-neon-cyan {
            border: 1px solid var(--neon-cyan);
            color: var(--neon-cyan);
            background: transparent;
            transition: 0.3s;
        }
        .btn-neon-cyan:hover {
            background: var(--neon-cyan);
            color: black;
            box-shadow: 0 0 15px var(--neon-cyan);
        }

        .gallery-img {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-radius: 10px;
            cursor: pointer;
            transition: 0.3s;
        }
        .gallery-img:hover {
            transform: scale(1.05);
            filter: brightness(1.2);
        }

        .sidebar {
            height: 100vh;
            border-right: 1px solid var(--glass-border);
            padding: 20px;
        }

        .nav-link {
            color: #aaa;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 5px;
            transition: 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            background: var(--glass);
            color: var(--neon-cyan);
        }

        .search-input {
            background: var(--glass);
            border: 1px solid var(--glass-border);
            color: white;
            border-radius: 20px;
            padding: 10px 20px;
        }
    </style>
</head>
<body>

<?php if (!isset($_SESSION['authenticated'])): ?>
<div class="container d-flex justify-content-center align-items-center vh-100">
    <div class="glass-card text-center" style="max-width: 400px; width: 100%;">
        <h2 class="orbitron mb-4" style="color: var(--neon-cyan);">ACCESO</h2>
        <input type="password" id="loginPass" class="form-control bg-dark text-white border-secondary mb-3" placeholder="Contraseña">
        <button class="btn btn-neon-cyan w-100" onclick="login()">Entrar</button>
    </div>
</div>
<script>
async function login() {
    const pass = document.getElementById('loginPass').value;
    const formData = new FormData();
    formData.append('login_pass', pass);
    const resp = await fetch('imagenes.php', { method: 'POST', body: formData });
    const data = await resp.json();
    if(data.success) location.reload();
    else alert(data.error);
}
</script>
<?php else: ?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <div class="col-md-2 sidebar d-none d-md-block">
            <h3 class="orbitron text-center mb-5" style="color: var(--neon-cyan); font-size: 1.2rem;">VISUAL AI</h3>
            <nav class="nav flex-column">
                <a class="nav-link active" href="#" onclick="showModule('gallery')">Galería</a>
                <a class="nav-link" href="#" onclick="showModule('ai')">IA Asistente</a>
                <a class="nav-link" href="#" onclick="showModule('config')">Configuración</a>
                <a class="nav-link" href="#" onclick="showModule('history')">Historial</a>
                <a class="nav-link mt-5 text-danger" href="?logout=1">Cerrar Sesión</a>
            </nav>
        </div>

        <!-- Main Content -->
        <div class="col-md-10 p-4">
            <!-- Header -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 id="moduleTitle" class="orbitron">Galería Inteligente</h2>
                <div class="d-flex gap-2">
                    <input type="text" id="searchInput" class="search-input" placeholder="Buscar imagen..." onkeyup="loadGallery()">
                    <button class="btn btn-outline-info" onclick="document.getElementById('captureFile').click()">📸 Cámara</button>
                    <button class="btn btn-neon-cyan" onclick="document.getElementById('uploadFile').click()">+ Subir</button>
                    <input type="file" id="uploadFile" hidden onchange="uploadImage(this)">
                    <input type="file" id="captureFile" capture="environment" accept="image/*" hidden onchange="uploadImage(this)">
                </div>
            </div>

            <!-- Modules -->
            <div id="galleryModule" class="module-content">
                <div id="galleryGrid" class="row g-4">
                    <!-- Images will load here -->
                </div>
            </div>

            <div id="aiModule" class="module-content d-none">
                <div class="glass-card">
                    <h3>Centro de Inteligencia</h3>
                    <p>Seleccione una imagen de la galería para comenzar.</p>
                </div>
            </div>

            <div id="configModule" class="module-content d-none">
                <div class="glass-card col-md-6">
                    <h3 class="orbitron mb-4">Configuración API</h3>
                    <form id="configForm">
                        <div class="mb-3">
                            <label class="form-label">Gemini API Key</label>
                            <input type="password" name="gemini_key" class="form-control bg-dark text-white border-secondary">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">OpenAI API Key</label>
                            <input type="password" name="openai_key" class="form-control bg-dark text-white border-secondary">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Proveedor Predeterminado</label>
                            <select name="provider" class="form-select bg-dark text-white border-secondary">
                                <option value="gemini">Google Gemini</option>
                                <option value="openai">OpenAI</option>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-8">
                                <button type="button" class="btn btn-neon-cyan w-100" onclick="saveConfig()">Guardar Cambios</button>
                            </div>
                            <div class="col-4">
                                <button type="button" class="btn btn-outline-danger w-100" onclick="clearConfig()">Limpiar</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div id="historyModule" class="module-content d-none">
                <div class="glass-card">
                    <h3 class="orbitron mb-4">Historial de Consultas</h3>
                    <div id="historyList" class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th>Fecha</th>
                                    <th>Imagen</th>
                                    <th>Tipo/Prompt</th>
                                    <th>Respuesta</th>
                                    <th>Resultado</th>
                                </tr>
                            </thead>
                            <tbody id="historyTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>

<script>
    function showModule(module) {
        document.querySelectorAll('.module-content').forEach(m => m.classList.add('d-none'));
        document.getElementById(module + 'Module').classList.remove('d-none');

        document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));
        event.currentTarget.classList.add('active');

        const titles = {
            'gallery': 'Galería Inteligente',
            'ai': 'IA Vision Studio',
            'config': 'Configuración de Sistemas',
            'history': 'Registro de Actividad'
        };
        document.getElementById('moduleTitle').innerText = titles[module];

        if(module === 'gallery') loadGallery();
        if(module === 'config') loadConfig();
        if(module === 'history') loadHistory();
    }

    async function loadHistory() {
        const resp = await fetch('imagenes.php?action=get_history');
        const data = await resp.json();
        const tbody = document.getElementById('historyTableBody');
        tbody.innerHTML = '';
        data.history.forEach(h => {
                const date = new Date(h.timestamp);
            tbody.innerHTML += `
                <tr>
                        <td class="small">${date.toLocaleString()}</td>
                    <td><img src="imagenes/${h.filename}" style="width: 50px; height: 50px; object-fit: cover; border-radius: 5px;"></td>
                    <td><div class="small text-truncate" style="max-width: 200px;">${h.prompt}</div></td>
                    <td><div class="small text-truncate" style="max-width: 300px;">${h.response}</div></td>
                    <td>
                        ${h.result_images ? h.result_images.map(img => `<img src="imagenes/${img}" style="width: 30px; height: 30px; object-fit: cover; border-radius: 3px; margin-right: 2px;">`).join('') : '-'}
                    </td>
                </tr>
            `;
        });
    }

    async function loadGallery() {
        const search = document.getElementById('searchInput').value;
        const resp = await fetch('imagenes.php?action=list&search=' + search);
        const data = await resp.json();
        const grid = document.getElementById('galleryGrid');
        grid.innerHTML = '';

        data.files.forEach(f => {
            grid.innerHTML += `
                <div class="col-md-3">
                    <div class="glass-card p-2 text-center">
                        <img src="${f.url}" class="gallery-img mb-2" onclick="openAIChat('${f.name}')">
                        <div class="small text-truncate">${f.name}</div>
                        <div class="d-flex justify-content-between mt-2">
                            <button class="btn btn-sm btn-outline-info" title="Renombrar" onclick="renameImage('${f.name}')">✎</button>
                            <a href="${f.url}" download class="btn btn-sm btn-outline-success" title="Descargar">⬇</a>
                            <button class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="deleteImage('${f.name}')">🗑</button>
                        </div>
                    </div>
                </div>
            `;
        });
    }

    async function uploadImage(input) {
        if(!input.files[0]) return;
        const formData = new FormData();
        formData.append('image', input.files[0]);
        formData.append('action', 'upload');

        const resp = await fetch('imagenes.php', { method: 'POST', body: formData });
        const data = await resp.json();
        if(data.success) {
            loadGallery();
        } else {
            alert(data.error);
        }
    }

    async function deleteImage(filename) {
        if(!confirm('¿Eliminar esta imagen?')) return;
        const formData = new FormData();
        formData.append('filename', filename);
        formData.append('action', 'delete');
        await fetch('imagenes.php', { method: 'POST', body: formData });
        loadGallery();
    }

    async function renameImage(oldname) {
        const newname = prompt('Nuevo nombre:', oldname);
        if(!newname || newname === oldname) return;
        const formData = new FormData();
        formData.append('oldname', oldname);
        formData.append('newname', newname);
        formData.append('action', 'rename');
        await fetch('imagenes.php', { method: 'POST', body: formData });
        loadGallery();
    }

    async function saveConfig() {
        const form = document.getElementById('configForm');
        const formData = new FormData(form);
        formData.append('action', 'save_config');
        await fetch('imagenes.php', { method: 'POST', body: formData });
        alert('Configuración guardada');
    }

    async function clearConfig() {
        if(!confirm('¿Eliminar todas las API Keys?')) return;
        const form = document.getElementById('configForm');
        form.gemini_key.value = '';
        form.openai_key.value = '';
        saveConfig();
    }

    async function loadConfig() {
        const resp = await fetch('imagenes.php?action=get_config');
        const data = await resp.json();
        const form = document.getElementById('configForm');
        if(data.config) {
            form.gemini_key.value = data.config.gemini_key || '';
            form.openai_key.value = data.config.openai_key || '';
            form.provider.value = data.config.provider || 'gemini';
        }
    }

    let currentAIFile = '';

    function openAIChat(filename) {
        currentAIFile = filename;
        showModule('ai');
        document.getElementById('aiModule').innerHTML = `
            <div class="row">
                <div class="col-md-6">
                    <div class="glass-card h-100">
                        <div class="text-center mb-3">
                            <img src="imagenes/${filename}" class="img-fluid rounded shadow-lg" style="max-height: 400px;">
                        </div>
                        <h4 class="orbitron text-center">${filename}</h4>
                        <div class="mt-4">
                            <h6 class="small text-uppercase text-muted mb-3">Herramientas Especializadas</h6>
                            <div class="row g-2">
                                <div class="col-6"><button class="btn btn-sm btn-outline-info w-100" onclick="aiAction('analyze')">🔍 Análisis Detallado</button></div>
                                <div class="col-6"><button class="btn btn-sm btn-outline-info w-100" onclick="aiAction('decor')">🏠 Ideas Decoración</button></div>
                                <div class="col-6"><button class="btn btn-sm btn-outline-info w-100" onclick="aiAction('marketing')">📱 Plan Marketing</button></div>
                                <div class="col-6"><button class="btn btn-sm btn-outline-info w-100" onclick="aiAction('seo')">🌐 Optimización SEO</button></div>
                                <div class="col-12 mt-2"><button class="btn btn-neon-cyan w-100" onclick="aiAction('variants')">🎨 Generar Variantes de Diseño</button></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="glass-card d-flex flex-column h-100" style="min-height: 600px;">
                        <div id="chatBox" class="flex-grow-1 overflow-auto mb-3 p-3" style="background: rgba(0,0,0,0.2); border-radius: 10px;">
                            <div class="chat-msg system p-2 mb-2 bg-dark rounded small text-info">
                                [SISTEMA] IA vinculada con éxito. ¿En qué puedo ayudarte hoy con esta imagen?
                            </div>
                        </div>
                        <div id="loadingAI" class="text-center d-none mb-2">
                            <div class="spinner-border spinner-border-sm text-cyan" role="status"></div>
                            <span class="ms-2 small text-cyan">IA procesando...</span>
                        </div>
                        <div class="mt-auto">
                             <div class="input-group">
                                <input type="text" id="chatInput" class="form-control bg-dark text-white border-secondary" placeholder="Escribe tu pregunta o 'Genera una versión moderna'..." onkeypress="if(event.key==='Enter') sendChat()">
                                <button class="btn btn-neon-cyan" onclick="sendChat()">Enviar</button>
                             </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
    }

    async function sendChat(customPrompt = '', type = 'chat') {
        const input = document.getElementById('chatInput');
        const prompt = customPrompt || input.value;
        if(!prompt && type === 'chat') return;

        const chatBox = document.getElementById('chatBox');
        const loading = document.getElementById('loadingAI');

        // User Message
        if(type === 'chat') {
            chatBox.innerHTML += `<div class="chat-msg user p-2 mb-2 bg-secondary rounded small text-white text-end">${prompt}</div>`;
            input.value = '';
        } else {
            chatBox.innerHTML += `<div class="chat-msg system p-2 mb-2 bg-dark rounded small text-warning">[ACCION: ${type.toUpperCase()}] Iniciando procesamiento...</div>`;
        }

        loading.classList.remove('d-none');
        chatBox.scrollTop = chatBox.scrollHeight;

        try {
            const formData = new FormData();
            formData.append('action', 'ai_chat');
            formData.append('filename', currentAIFile);
            formData.append('prompt', prompt);
            formData.append('type', type);

            const resp = await fetch('imagenes.php', { method: 'POST', body: formData });
            const data = await resp.json();

            loading.classList.add('d-none');

            if(data.success) {
                let html = `<div class="chat-msg ai p-2 mb-2 bg-primary bg-opacity-25 rounded small border border-primary">${data.response.replace(/\n/g, '<br>')}</div>`;

                if(data.new_images && data.new_images.length > 0) {
                    html += `<div class="row g-2 mt-2">`;
                    data.new_images.forEach(img => {
                        html += `
                            <div class="col-6 text-center">
                                <img src="imagenes/${img}" class="img-fluid rounded mb-1 border border-cyan" style="max-height: 150px; cursor: pointer;" onclick="openAIChat('${img}')">
                                <div class="x-small text-cyan" style="font-size: 0.7rem;">Nueva Idea</div>
                            </div>
                        `;
                    });
                    html += `</div>`;
                }
                chatBox.innerHTML += html;
            } else {
                chatBox.innerHTML += `<div class="chat-msg error p-2 mb-2 bg-danger bg-opacity-25 rounded small border border-danger">${data.error}</div>`;
            }
        } catch (e) {
            loading.classList.add('d-none');
            chatBox.innerHTML += `<div class="chat-msg error p-2 mb-2 bg-danger bg-opacity-25 rounded small border border-danger">Error crítico: ${e.message}</div>`;
        }
        chatBox.scrollTop = chatBox.scrollHeight;
    }

    function aiAction(type) {
        sendChat('', type);
    }

    // Inicializar
    loadGallery();
</script>
<?php endif; ?>

</body>
</html>
