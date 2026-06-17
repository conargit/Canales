<?php
/**
 * ================================================================
 *  SISTEMA COMPLETO DE GESTIÓN DE IMÁGENES CON IA — imagenes.php
 *  Multi-AI: Google Gemini + OpenAI + Anthropic Claude
 *  Generación de imágenes + Cámara + Análisis Visual + CRUD
 *  TODO EN UN SOLO ARCHIVO
 * ================================================================
 */

ob_start();

// ── Error handlers ──
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (isset($_GET['action']) || isset($_POST['action'])) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>"PHP Error [$errno]: $errstr en ".basename($errfile).":$errline"]);
        exit;
    }
    return false;
});

register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'PHP Fatal: '.$err['message'].' en '.basename($err['file']).':'.$err['line']]);
        exit;
    }
});

@ini_set('upload_max_filesize','500M');
@ini_set('post_max_size','600M');
@ini_set('max_execution_time','300');
@ini_set('max_input_time','300');
@ini_set('memory_limit','512M');
@ini_set('display_errors','0');
error_reporting(0);

if (session_status() === PHP_SESSION_NONE) @session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// ──────────────────────────────────────────────────────────────
// CONFIGURACIÓN
// ──────────────────────────────────────────────────────────────
$sd = dirname($_SERVER['SCRIPT_NAME']);
if ($sd === '/' || $sd === '\\') $sd = '';

$CONFIG = [
    'upload_dir'    => __DIR__ . '/imagenes/',
    'upload_url'    => $sd . '/imagenes/',
    'generated_dir' => __DIR__ . '/imagenes/generated/',
    'generated_url' => $sd . '/imagenes/generated/',
    'max_file_size' => 0,
    'blocked_exts'  => ['php','phtml','php3','php4','php5','php7','php8','pht','phar',
                        'shtml','htaccess','htpasswd','asp','aspx','jsp','cgi','pl',
                        'py','sh','bat','cmd','exe','msi','com','vbs','jar'],
    'items_per_page'=> 999,

    // ═══════════════════════════════════════════════════════
    // CLAVES API — Configura las que tengas
    // ══════════════════════════════════════════════════â••═════
    // Google Gemini (GRATIS con límites): https://aistudio.google.com/apikey
    'gemini_api_key' => '',

    // OpenAI (GPT-4o Vision + DALL-E 3): https://platform.openai.com/api-keys
    'openai_api_key' => '',

    // Anthropic Claude (Vision): https://console.anthropic.com/
    'claude_api_key' => '',

    // Modelos por defecto
    'gemini_model'     => 'gemini-2.0-flash-exp',
    'openai_model'     => 'gpt-4o-mini',
    'claude_model'     => 'claude-3-5-sonnet-20241022',
    'dalle_model'      => 'dall-e-3',
    'ai_max_tokens'    => 2048,
];

// ── Cargar configuración guardada (sobreescribe defaults) ──
$cfg_file = __DIR__ . '/config_ai.json';
if (file_exists($cfg_file)) {
    $saved = @json_decode(file_get_contents($cfg_file), true);
    if (is_array($saved)) {
        foreach ($saved as $k => $v) {
            if (isset($CONFIG[$k])) $CONFIG[$k] = $v;
        }
    }
}

// ── Crear directorios ──
foreach ([$CONFIG['upload_dir'], $CONFIG['generated_dir']] as $dir) {
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

// ──────────────────────────────────────────────────────────────
// FUNCIONES UTILITARIAS
// ──────────────────────────────────────────────────────────────
function json_out($data, $code = 200) {
    while (ob_get_level()) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getAIConfig() {
    global $cfg_file;
    if (!file_exists($cfg_file)) return [];
    return json_decode(file_get_contents($cfg_file), true) ?: [];
}

function sanitize_filename($name) {
    $name = preg_replace('/[<>"\'|\\\\\/:\*\?]/', '', $name);
    $name = preg_replace('/\s+/', '_', $name);
    $name = trim($name, '._- ');
    return empty($name) ? 'imagen' : $name;
}

function format_size($bytes) {
    if ($bytes <= 0) return '0 B';
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024)       return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function get_image_info($filepath, $filename) {
    global $CONFIG;
    $full = $filepath . $filename;
    if (!file_exists($full)) return null;
    $info = [
        'name'      => $filename,
        'size'      => @filesize($full),
        'size_fmt'  => format_size(@filesize($full)),
        'modified'  => date('d/m/Y H:i', @filemtime($full)),
        'url'       => (strpos($filepath, 'generated') !== false ? $CONFIG['generated_url'] : $CONFIG['upload_url']) . rawurlencode($filename),
        'dimensions'=> '—',
        'width'     => 0,
        'height'    => 0,
        'ext'       => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
        'generated' => strpos($filepath, 'generated') !== false,
    ];
    if ($info['ext'] === 'svg' || $info['ext'] === 'svgz') {
        $info['dimensions'] = 'SVG';
    } else {
        $dim = @getimagesize($full);
        if ($dim) {
            $info['width']  = $dim[0];
            $info['height'] = $dim[1];
            $info['dimensions'] = $dim[0] . ' x ' . $dim[1];
        }
    }
    return $info;
}

function get_all_images() {
    global $CONFIG;
    $images = [];
    foreach ([$CONFIG['upload_dir'], $CONFIG['generated_dir']] as $dir) {
        if (!is_dir($dir)) continue;
        $files = @scandir($dir, SCANDIR_SORT_DESCENDING);
        if (!$files) continue;
        $skip = ['.','..','.htaccess','index.php','.user.ini','.config.json'];
        foreach ($files as $f) {
            if (in_array($f, $skip) || is_dir($dir . $f)) continue;
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if (in_array($ext, $CONFIG['blocked_exts'])) continue;
            $img = get_image_info($dir, $f);
            if ($img) $images[] = $img;
        }
    }
    return $images;
}

function ai_providers() {
    global $CONFIG;
    $p = [];
    if (!empty($CONFIG['gemini_api_key']))  $p[] = 'gemini';
    if (!empty($CONFIG['openai_api_key']))  $p[] = 'openai';
    if (!empty($CONFIG['claude_api_key']))  $p[] = 'claude';
    return $p;
}

function first_ai_provider() {
    $p = ai_providers();
    return $p ? $p[0] : null;
}

// ──────────────────────────────────────────────────────────────
// ANÁLISIS CON GOOGLE GEMINI
// ──────────────────────────────────────────────────────────────
function analyze_gemini($filepath, $filename, $prompt_extra = '') {
    global $CONFIG;
    if (empty($CONFIG['gemini_api_key']))
        return ['success'=>false,'message'=>'Gemini API Key no configurada.'];

    $imageData = @file_get_contents($filepath);
    if ($imageData === false) return ['success'=>false,'message'=>'No se pudo leer la imagen.'];
    if (strlen($imageData) > 18*1024*1024) return ['success'=>false,'message'=>'Imagen muy grande para Gemini (>18MB).'];

    $base64 = base64_encode($imageData);
    $mimeType = @mime_content_type($filepath) ?: 'image/jpeg';

    $prompt = 'Eres un experto creativo en fotografía, diseño gráfico, marketing digital y arte visual. Analiza esta imagen en detalle y responde SIEMPRE en español con este formato:

📋 DESCRIPCIÓN:
[Descripción detallada y completa de lo que se ve en la imagen: sujetos, objetos, escena, ambiente]

💡 TÍTULOS SUGERIDOS:
[5 títulos creativos y atractivos para la imagen, numerados]

📱 USOS RECOMENDADOS:
[5 sugerencias específicas de dónde y cómo usar esta imagen: redes sociales, web, impresión, publicidad, etc.]

🏷️ TEXTO ALT (SEO):
[Texto alternativo optimizado para SEO, conciso y descriptivo]

🎨 ESTILO Y COMPOSICIÓN:
[Análisis del estilo visual, colores dominantes, composición, iluminación, perspectiva, técnica]

✍️ CAPTIONS PARA REDES:
[3 pies de foto listos para usar en Instagram/Facebook/LinkedIn con emojis]

🔄 MEJORAS SUGERIDAS:
[3-5 ideas concretas para mejorar o transformar esta imagen: edición, composición, estilo]';

    if ($prompt_extra) $prompt .= "\n\nContexto adicional del usuario: $prompt_extra";

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $base64]]
            ]
        ]],
        'generationConfig' => [
            'temperature' => 0.8,
            'maxOutputTokens' => $CONFIG['ai_max_tokens'],
        ]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$CONFIG['gemini_model']}:generateContent?key={$CONFIG['gemini_api_key']}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['success'=>false,'message'=>'cURL Error: '.$curlErr];
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['success'=>false,'message'=>"Gemini Error: $msg"];
    }
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (empty($text)) return ['success'=>false,'message'=>'Gemini no generó respuesta.'];

    return ['success'=>true,'type'=>'ai','provider'=>'gemini','model'=>$CONFIG['gemini_model'],'ideas'=>[$text]];
}

// ──────────────────────────────────────────────────────────────
// ANÁLISIS CON OPENAI (GPT-4o Vision)
// ──────────────────────────────────────────────────────────────
function analyze_openai($filepath, $filename, $prompt_extra = '') {
    global $CONFIG;
    if (empty($CONFIG['openai_api_key']))
        return ['success'=>false,'message'=>'OpenAI API Key no configurada.'];

    $imageData = @file_get_contents($filepath);
    if ($imageData === false) return ['success'=>false,'message'=>'No se pudo leer la imagen.'];
    if (strlen($imageData) > 15*1024*1024) return ['success'=>false,'message'=>'Imagen muy grande para OpenAI (>15MB).'];

    $base64 = base64_encode($imageData);
    $mimeType = @mime_content_type($filepath) ?: 'image/jpeg';

    $prompt = 'Eres un experto creativo en fotografía, diseño gráfico, marketing digital y arte visual. Analiza esta imagen en detalle y responde SIEMPRE en español con este formato:

📋 DESCRIPCIÓN:
[Descripción detallada y completa de lo que se ve en la imagen]

💡 TÍTULOS SUGERIDOS:
[5 títulos creativos y atractivos para la imagen]

📱 USOS RECOMENDADOS:
[5 sugerencias específicas de dónde y cómo usar esta imagen]

🏷️ TEXTO ALT (SEO):
[Texto alternativo optimizado para SEO]

🎨 ESTILO Y COMPOSICIÓN:
[Análisis del estilo visual, colores dominantes, composición, iluminación]

✍️ CAPTIONS PARA REDES:
[3 pies de foto listos para usar en Instagram/Facebook/LinkedIn con emojis]

🔄 MEJORAS SUGERIDAS:
[3-5 ideas concretas para mejorar o transformar esta imagen]';

    if ($prompt_extra) $prompt .= "\n\nContexto adicional del usuario: $prompt_extra";

    $payload = [
        'model' => $CONFIG['openai_model'],
        'messages' => [
            ['role'=>'system','content'=>$prompt],
            ['role'=>'user','content'=>[
                ['type'=>'text','text'=>'Analiza esta imagen y genera ideas creativas:'],
                ['type'=>'image_url','image_url'=>['url'=>"data:{$mimeType};base64,{$base64}",'detail'=>'auto']]
            ]]
        ],
        'max_tokens' => $CONFIG['ai_max_tokens'],
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$CONFIG['openai_api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['success'=>false,'message'=>'cURL Error: '.$curlErr];
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['success'=>false,'message'=>"OpenAI Error: $msg"];
    }
    $text = $data['choices'][0]['message']['content'] ?? '';
    if (empty($text)) return ['success'=>false,'message'=>'OpenAI no generó respuesta.'];

    return ['success'=>true,'type'=>'ai','provider'=>'openai','model'=>$CONFIG['openai_model'],'tokens'=>$data['usage']??null,'ideas'=>[$text]];
}

// ──────────────────────────────────────────────────────────────
// ANÁLISIS CON ANTHROPIC CLAUDE
// ──────────────────────────────────────────────────────────────
function analyze_claude($filepath, $filename, $prompt_extra = '') {
    global $CONFIG;
    if (empty($CONFIG['claude_api_key']))
        return ['success'=>false,'message'=>'Claude API Key no configurada.'];

    $imageData = @file_get_contents($filepath);
    if ($imageData === false) return ['success'=>false,'message'=>'No se pudo leer la imagen.'];
    if (strlen($imageData) > 15*1024*1024) return ['success'=>false,'message'=>'Imagen muy grande para Claude (>15MB).'];

    $base64 = base64_encode($imageData);
    $mimeType = @mime_content_type($filepath) ?: 'image/jpeg';

    $prompt = 'Eres un experto creativo en fotografía, diseño gráfico, marketing digital y arte visual. Analiza esta imagen en detalle y responde SIEMPRE en español con este formato:

📋 DESCRIPCIÓN:
[Descripción detallada y completa de lo que se ve en la imagen]

💡 TÍTULOS SUGERIDOS:
[5 títulos creativos y atractivos para la imagen]

📱 USOS RECOMENDADOS:
[5 sugerencias específicas de dónde y cómo usar esta imagen]

🏷️ TEXTO ALT (SEO):
[Texto alternativo optimizado para SEO]

🎨 ESTILO Y COMPOSICIÓN:
[Análisis del estilo visual, colores dominantes, composición, iluminación]

✍️ CAPTIONS PARA REDES:
[3 pies de foto listos para usar en Instagram/Facebook/LinkedIn con emojis]

🔄 MEJORAS SUGERIDAS:
[3-5 ideas concretas para mejorar o transformar esta imagen]';

    if ($prompt_extra) $prompt .= "\n\nContexto adicional del usuario: $prompt_extra";

    $payload = [
        'model' => $CONFIG['claude_model'],
        'max_tokens' => $CONFIG['ai_max_tokens'],
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type'=>'image','source'=>['type'=>'base64','media_type'=>$mimeType,'data'=>$base64]],
                ['type'=>'text','text'=>$prompt]
            ]
        ]]
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-key: '.$CONFIG['claude_api_key'],
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['success'=>false,'message'=>'cURL Error: '.$curlErr];
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['success'=>false,'message'=>"Claude Error: $msg"];
    }
    $text = $data['content'][0]['text'] ?? '';
    if (empty($text)) return ['success'=>false,'message'=>'Claude no generó respuesta.'];

    return ['success'=>true,'type'=>'ai','provider'=>'claude','model'=>$CONFIG['claude_model'],'tokens'=>['input'=>$data['usage']['input_tokens']??0,'output'=>$data['usage']['output_tokens']??0],'ideas'=>[$text]];
}

// ──────────────────────────────────────────────────────────────
// ANÁLISIS BÁSICO (sin IA)
// ──────────────────────────────────────────────────────────────
function analyze_basic($filepath, $filename) {
    $info = get_image_info($filepath, $filename);
    if (!$info) return ['success'=>false,'message'=>'No se pudo leer la imagen.'];

    $ideas = [];
    $ext = $info['ext'];
    $ratio = $info['width'] / max(1, $info['height']);
    if ($ratio > 1.5) $orient = 'panorámica horizontal';
    elseif ($ratio > 1.1) $orient = 'horizontal';
    elseif ($ratio < 0.66) $orient = 'panorámica vertical';
    elseif ($ratio < 0.9) $orient = 'vertical';
    else $orient = 'cuadrada';

    $ideas[] = "Formato: " . strtoupper($ext);
    $ideas[] = "Orientación: " . $orient . " (" . $info['dimensions'] . ")";
    $ideas[] = "Tamaño: " . $info['size_fmt'];

    if ($ext === 'svg') {
        $ideas[] = 'Ideal para logos, iconos e ilustraciones vectoriales';
    } elseif ($ext === 'gif') {
        $ideas[] = 'Puede ser animada — ideal para memes o reacciones';
    } elseif ($ext === 'webp') {
        $ideas[] = 'Formato moderno web — excelente rendimiento';
    } elseif (in_array($ext, ['jpg','jpeg','png'])) {
        if ($info['width'] >= 3000 || $info['height'] >= 3000)
            $ideas[] = 'Alta resolución — ideal para impresión o fondos';
        elseif ($info['width'] >= 1200 || $info['height'] >= 1200)
            $ideas[] = 'Buena resolución — adecuada para banners y publicaciones';
        else
            $ideas[] = 'Resolución media — ideal para thumbnails o avatares';
    }
    if ($orient === 'panorámica horizontal') $ideas[] = 'Perfecta para cabeceras web, banners o portadas';
    if ($orient === 'vertical' || $orient === 'panorámica vertical') $ideas[] = 'Ideal para Stories Instagram, TikTok, Pinterest';
    if ($orient === 'cuadrada') $ideas[] = 'Perfecta para publicaciones de Instagram o miniaturas';
    if ($info['size'] > 5*1024*1024) $ideas[] = 'Archivo pesado — considerar comprimir';

    return ['success'=>true,'type'=>'basic','ideas'=>$ideas,'ai_available'=>count(ai_providers())>0];
}

// ──────────────────────────────────────────────────────────────
// GENERACIÓN DE IMÁGENES CON DALL-E 3
// ──────────────────────────────────────────────────────────────
function generate_image_openai($prompt, $size = '1024x1024') {
    global $CONFIG;
    if (empty($CONFIG['openai_api_key']))
        return ['success'=>false,'message'=>'OpenAI API Key no configurada para generar imágenes.'];

    $payload = [
        'model'  => $CONFIG['dalle_model'],
        'prompt' => $prompt,
        'n'      => 1,
        'size'   => $size,
        'quality'=> 'standard',
        'response_format' => 'b64_json',
    ];

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Bearer '.$CONFIG['openai_api_key']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['success'=>false,'message'=>'cURL Error: '.$curlErr];
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['success'=>false,'message'=>"DALL-E Error: $msg"];
    }

    $b64 = $data['data'][0]['b64_json'] ?? '';
    if (empty($b64)) return ['success'=>false,'message'=>'DALL-E no generó imagen.'];

    // Guardar imagen generada
    $imgData = base64_decode($b64);
    $safeName = sanitize_filename(substr($prompt, 0, 40)) . '_' . time() . '.png';
    $savePath = $CONFIG['generated_dir'] . $safeName;
    if (@file_put_contents($savePath, $imgData) === false)
        return ['success'=>false,'message'=>'No se pudo guardar la imagen generada.'];

    @chmod($savePath, 0644);
    $info = get_image_info($CONFIG['generated_dir'], $safeName);

    return ['success'=>true,'provider'=>'openai','model'=>$CONFIG['dalle_model'],'image'=>$info,'revised_prompt'=>$data['data'][0]['revised_prompt']??''];
}

// ──────────────────────────────────────────────────────────────
// GENERACIÓN DE IMÁGENES CON GEMINI (Imagen 3 / 2.0 Flash)
// ──────────────────────────────────────────────────────────────
function generate_image_gemini($prompt, $size = '1024x1024') {
    global $CONFIG;
    if (empty($CONFIG['gemini_api_key']))
        return ['success'=>false,'message'=>'Gemini API Key no configurada para generar imágenes.'];

    $payload = [
        'contents' => [[
            'parts' => [['text' => "Generate an image: $prompt"]]
        ]],
        'generationConfig' => [
            'responseModalities' => ['TEXT', 'IMAGE'],
        ]
    ];

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$CONFIG['gemini_model']}:generateContent?key={$CONFIG['gemini_api_key']}");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) return ['success'=>false,'message'=>'cURL Error: '.$curlErr];
    $data = json_decode($response, true);
    if ($httpCode !== 200) {
        $msg = $data['error']['message'] ?? "HTTP $httpCode";
        return ['success'=>false,'message'=>"Gemini Image Error: $msg"];
    }

    // Buscar imagen en la respuesta
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $imgB64 = null;
    $mimeType = 'image/png';
    foreach ($parts as $part) {
        if (isset($part['inline_data'])) {
            $imgB64 = $part['inline_data']['data'];
            $mimeType = $part['inline_data']['mime_type'] ?? 'image/png';
            break;
        }
    }

    if (empty($imgB64)) {
        $text = '';
        foreach ($parts as $part) {
            if (isset($part['text'])) $text .= $part['text'];
        }
        return ['success'=>false,'message'=>'Gemini no generó imagen. '.($text ? 'Respuesta: '.substr($text,0,200) : 'El modelo puede no soportar generación de imágenes.')];
    }

    $imgData = base64_decode($imgB64);
    $ext = ($mimeType === 'image/jpeg') ? 'jpg' : 'png';
    $safeName = sanitize_filename(substr($prompt, 0, 40)) . '_' . time() . '.' . $ext;
    $savePath = $CONFIG['generated_dir'] . $safeName;
    if (@file_put_contents($savePath, $imgData) === false)
        return ['success'=>false,'message'=>'No se pudo guardar la imagen generada.'];

    @chmod($savePath, 0644);
    $info = get_image_info($CONFIG['generated_dir'], $safeName);

    return ['success'=>true,'provider'=>'gemini','model'=>$CONFIG['gemini_model'],'image'=>$info];
}

// ──────────────────────────────────────────────────────────────
// SEGURIDAD (DESACTIVADA POR SOLICITUD)
// ──────────────────────────────────────────────────────────────
$_SESSION['authenticated'] = true;

// ──────────────────────────────────────────────────────────────
// ROUTER AJAX
// ──────────────────────────────────────────────────────────────
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : null);

if ($action) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrf = isset($_POST['csrf_token']) ? $_POST['csrf_token'] :
                (isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '');
        if ($csrf && isset($_SESSION['csrf_token']) && !hash_equals($_SESSION['csrf_token'], $csrf)) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            json_out(['success'=>false,'message'=>'Token expirado. Recarga la página.'], 403);
        }
    }

    switch ($action) {

        case 'ping':
            json_out(['success'=>true,'time'=>date('H:i:s'),'providers'=>ai_providers(),'php'=>PHP_VERSION]);

        case 'upload':
            if (empty($_FILES['images'])) json_out(['success'=>false,'message'=>'No se recibieron archivos.'], 400);
            $results = []; $errors = [];
            $files = $_FILES['images'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $name  = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
                $tmp   = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $size  = is_array($files['size'])     ? $files['size'][$i]     : $files['size'];
                $error = is_array($files['error'])    ? $files['error'][$i]    : $files['error'];
                if ($error !== UPLOAD_ERR_OK) {
                    $errors[] = ['file'=>$name,'message'=>'Error '.$error]; continue;
                }
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, $CONFIG['blocked_exts'])) { $errors[] = ['file'=>$name,'message'=>'Bloqueado.']; continue; }
                $safe = sanitize_filename(pathinfo($name, PATHINFO_FILENAME));
                $final = $safe . '.' . $ext;
                $c = 1;
                while (file_exists($CONFIG['upload_dir'] . $final)) { $final = $safe . '_' . $c . '.' . $ext; $c++; }
                if (@move_uploaded_file($tmp, $CONFIG['upload_dir'] . $final)) {
                    @chmod($CONFIG['upload_dir'].$final,0644);
                    $results[] = get_image_info($CONFIG['upload_dir'], $final);
                } else {
                    $errors[] = ['file'=>$name,'message'=>'Error al mover.'];
                }
            }
            json_out(['success'=>count($results)>0,'uploaded'=>$results,'errors'=>$errors]);

        case 'upload_camera':
            $b64data = trim($_POST['image_data'] ?? '');
            if (empty($b64data)) json_out(['success'=>false,'message'=>'Vacio.'], 400);
            if (preg_match('/^data:image\/(\w+);base64,(.+)$/s', $b64data, $m)) {
                $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
                $b64data = $m[2];
            } else { $ext = 'png'; }
            $imgData = @base64_decode($b64data);
            $safe = sanitize_filename('camera_' . date('Ymd_His'));
            $final = $safe . '.' . $ext;
            if (@file_put_contents($CONFIG['upload_dir'] . $final, $imgData) === false) json_out(['success'=>false], 500);
            @chmod($CONFIG['upload_dir'].$final, 0644);
            json_out(['success'=>true,'image'=>get_image_info($CONFIG['upload_dir'], $final)]);

        case 'list':
            $images = get_all_images();
            $search = trim($_GET['search']??'');
            if ($search !== '') {
                $images = array_values(array_filter($images, function($img) use ($search) { return stripos($img['name'],$search)!==false; }));
            }
            json_out(['success'=>true,'images'=>$images,'total'=>count($images)]);

        case 'rename':
            $on = trim($_POST['old_name']??''); $nn = trim($_POST['new_name']??'');
            $is_gen = !empty($_POST['generated']);
            $dir = $is_gen ? $CONFIG['generated_dir'] : $CONFIG['upload_dir'];
            $ext = strtolower(pathinfo($on,PATHINFO_EXTENSION)); $safe = sanitize_filename($nn); $final = $safe.'.'.$ext;
            if(@rename($dir.$on,$dir.$final)) json_out(['success'=>true,'new_name'=>$final]);
            else json_out(['success'=>false],500);

        case 'delete':
            $names = $_POST['names']??[]; if(is_string($names))$names=[$names];
            $gens = $_POST['generated']??[]; if(is_string($gens))$gens=[$gens];
            $d=0;
            foreach($names as $i=>$n){
                $dir = !empty($gens[$i]) ? $CONFIG['generated_dir'] : $CONFIG['upload_dir'];
                if(@unlink($dir.$n))$d++;
            }
            json_out(['success'=>$d>0,'deleted'=>$d]);

        case 'stats':
            $images = get_all_images(); $ts = array_sum(array_column($images,'size'));
            json_out(['success'=>true,'total_files'=>count($images),'total_size'=>format_size($ts), 'providers'=>ai_providers(),
                'gemini_set'=>!empty($CONFIG['gemini_api_key']),'openai_set'=>!empty($CONFIG['openai_api_key']),'claude_set'=>!empty($CONFIG['claude_api_key']),
            ]);

        case 'analyze':
            $name = trim($_GET['name'] ?? '');
            $provider = trim($_GET['provider'] ?? '');
            $prompt_extra = trim($_GET['prompt'] ?? '');
            $is_gen = !empty($_GET['generated']);
            $dir = $is_gen ? $CONFIG['generated_dir'] : $CONFIG['upload_dir'];
            $filepath = $dir . $name;
            $providers = ai_providers();
            if (empty($providers)) json_out(analyze_basic($filepath, $name));
            $use = ($provider && in_array($provider, $providers)) ? $provider : $providers[0];
            $result = match($use) {
                'gemini' => analyze_gemini($filepath, $name, $prompt_extra),
                'openai' => analyze_openai($filepath, $name, $prompt_extra),
                'claude' => analyze_claude($filepath, $name, $prompt_extra),
                default  => analyze_basic($filepath, $name),
            };
            json_out($result);

        case 'generate':
            $prompt = trim($_POST['prompt'] ?? '');
            $provider = trim($_POST['provider'] ?? 'openai');
            $size = trim($_POST['size'] ?? '1024x1024');
            $result = ($provider === 'gemini') ? generate_image_gemini($prompt, $size) : generate_image_openai($prompt, $size);
            json_out($result);

        case 'save_config':
            $new_config = [
                'gemini_api_key' => trim($_POST['gemini_api_key'] ?? ''),
                'openai_api_key' => trim($_POST['openai_api_key'] ?? ''),
                'claude_api_key' => trim($_POST['claude_api_key'] ?? ''),
                'gemini_model'   => trim($_POST['gemini_model'] ?? $CONFIG['gemini_model']),
                'openai_model'   => trim($_POST['openai_model'] ?? $CONFIG['openai_model']),
                'claude_model'   => trim($_POST['claude_model'] ?? $CONFIG['claude_model']),
                'dalle_model'    => trim($_POST['dalle_model'] ?? $CONFIG['dalle_model']),
                'ai_max_tokens'  => intval($_POST['ai_max_tokens'] ?? $CONFIG['ai_max_tokens']),
            ];
            @file_put_contents($cfg_file, json_encode($new_config, JSON_PRETTY_PRINT));
            json_out(['success'=>true,'providers'=>ai_providers()]);

        case 'get_config':
            json_out(['success'=>true,'config'=>getAIConfig()]);

        default: json_out(['success'=>false],400);
    }
}

$csrf = $_SESSION['csrf_token'];
while (ob_get_level()) ob_end_clean();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Visual Intelligence Center con IA</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0a0b10;--bg-alt:#111218;--fg:#e0e0e0;--fg-sec:#aaa;
  --accent:#00f2ff;--accent-hover:#00d1db;--accent-fg:#000;
  --border:rgba(255,255,255,0.1);--radius:10px;--radius-lg:15px;
  --glass:rgba(255,255,255,0.05);
  --success:#16a34a;--error:#dc2626;--gemini:#4285f4;--openai:#10a37f;--claude:#d97706;
  --shadow:0 8px 32px 0 rgba(0,0,0,0.37);
  --font:'Montserrat', sans-serif;
}
body{font-family:var(--font);background:var(--bg);color:var(--fg);line-height:1.6;min-height:100vh;overflow-x:hidden}
.glass-card{background:var(--glass);backdrop-filter:blur(10px);border:1px solid var(--border);border-radius:var(--radius-lg);padding:20px;box-shadow:var(--shadow)}
.orbitron{font-family:'Orbitron', sans-serif}
.tabs{display:flex;gap:10px;border-bottom:1px solid var(--border);margin-bottom:20px;padding-bottom:10px}
.tab-btn{padding:10px 15px;background:none;border:none;color:var(--fg-sec);cursor:pointer;font-weight:600;transition:0.3s}
.tab-btn.active{color:var(--accent);border-bottom:2px solid var(--accent)}
.tab-panel{display:none}
.tab-panel.active{display:block}
.app{max-width:1400px;margin:0 auto;padding:20px}
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px}
.header h1{font-size:1.8rem;color:var(--accent)}
.stats-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:15px;margin-bottom:30px}
.stat-card{background:var(--glass);padding:15px;border-radius:var(--radius);text-align:center;border:1px solid var(--border)}
.stat-value{font-size:1.2rem;font-weight:700;display:block}
.stat-label{font-size:0.7rem;text-transform:uppercase;color:var(--fg-sec)}
.upload-zone{border:2px dashed var(--border);padding:40px;text-align:center;border-radius:var(--radius-lg);cursor:pointer;transition:0.3s;background:var(--glass)}
.upload-zone:hover{border-color:var(--accent);background:rgba(0,242,255,0.05)}
.gallery-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:20px}
.image-card{background:var(--glass);border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);transition:0.3s}
.image-card:hover{transform:translateY(-5px);border-color:var(--accent)}
.card-img{width:100%;aspect-ratio:1;object-fit:cover;cursor:pointer}
.card-body{padding:15px}
.card-name{font-size:0.9rem;font-weight:600;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card-actions{display:flex;justify-content:space-between;margin-top:10px}
.btn{padding:8px 15px;border:none;border-radius:var(--radius);cursor:pointer;font-weight:600;transition:0.3s;display:inline-flex;align-items:center;gap:5px;font-size:0.8rem}
.btn-primary{background:var(--accent);color:#000}
.btn-primary:hover{box-shadow:0 0 15px var(--accent)}
.btn-secondary{background:var(--glass);color:var(--fg);border:1px solid var(--border)}
.btn-danger{background:rgba(220,38,38,0.2);color:#ff4d4d;border:1px solid rgba(220,38,38,0.5)}
.btn-danger:hover{background:var(--error);color:#fff}
.input-field{background:var(--bg-alt);border:1px solid var(--border);color:var(--fg);padding:10px;border-radius:var(--radius);width:100%;outline:none}
.input-field:focus{border-color:var(--accent)}
.camera-container{width:100%;max-width:600px;margin:0 auto;border-radius:var(--radius-lg);overflow:hidden;background:#000;position:relative}
#cameraVideo{width:100%;display:block}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.8);backdrop-filter:blur(5px);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
.modal-overlay.active{display:flex}
.modal{background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius-lg);padding:30px;max-width:800px;width:100%;position:relative;max-height:90vh;overflow-y:auto}
.ideas-content{background:rgba(0,0,0,0.3);padding:20px;border-radius:var(--radius);font-size:0.9rem;white-space:pre-wrap}
</style>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;600&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>

<?php if (true): // Autenticación saltada por solicitud ?>

<div class="app">
    <header class="header">
        <div>
            <h1 class="orbitron">VISUAL AI</h1>
            <p style="color:var(--fg-sec);font-size:0.8rem">Inteligencia Visual Avanzada v7.0</p>
        </div>
        <div style="display:flex;gap:15px;align-items:center">
            <span id="aiStatus" style="font-size:0.7rem;text-transform:uppercase"></span>
        </div>
    </header>

    <div class="stats-bar">
        <div class="stat-card"><span class="stat-value" id="statFiles">-</span><span class="stat-label">Archivos</span></div>
        <div class="stat-card"><span class="stat-value" id="statSize">-</span><span class="stat-label">Almacenamiento</span></div>
        <div class="stat-card"><span class="stat-value" id="statProvs">-</span><span class="stat-label">Motores IA</span></div>
    </div>

    <div class="tabs">
        <button class="tab-btn active" onclick="showTab('gallery')">Galería</button>
        <button class="tab-btn" onclick="showTab('camera')">Cámara</button>
        <button class="tab-btn" onclick="showTab('generate')">Generar</button>
        <button class="tab-btn" onclick="showTab('config')">Config</button>
    </div>

    <!-- PANEL GALERIA -->
    <div id="tab-gallery" class="tab-panel active">
        <div class="upload-zone" onclick="document.getElementById('fileInput').click()">
            <h3 class="orbitron">ARRASTRA O HAZ CLIC</h3>
            <p>Sube tus fotos para analizar con IA</p>
            <input type="file" id="fileInput" multiple accept="image/*" style="display:none" onchange="handleFiles(this.files)">
        </div>

        <div style="margin: 20px 0; display:flex; gap:10px">
            <input type="text" id="searchInput" class="input-field" placeholder="Buscar imagen..." onkeyup="searchImages()">
        </div>

        <div id="galleryGrid" class="gallery-grid"></div>
    </div>

    <!-- PANEL CAMARA -->
    <div id="tab-camera" class="tab-panel">
        <div class="glass-card text-center">
            <div class="camera-container mb-3">
                <video id="cameraVideo" autoplay playsinline></video>
                <canvas id="cameraCanvas" style="display:none"></canvas>
            </div>
            <div style="margin-top:20px; display:flex; justify-content:center; gap:10px">
                <button class="btn btn-primary" onclick="capturePhoto()">CAPTURAR</button>
                <button class="btn btn-secondary" onclick="toggleCamera()">ABRIR/CERRAR</button>
            </div>
            <div id="cameraPreview" style="margin-top:20px; display:flex; gap:10px; overflow-x:auto"></div>
        </div>
    </div>

    <!-- PANEL GENERAR -->
    <div id="tab-generate" class="tab-panel">
        <div class="glass-card">
            <h3 class="orbitron mb-3">CREAR CON IA</h3>
            <textarea id="genPrompt" class="input-field" rows="4" placeholder="Describe la imagen..."></textarea>
            <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin-top:15px">
                <select id="genProvider" class="input-field">
                    <option value="openai">OpenAI DALL-E 3</option>
                    <option value="gemini">Google Gemini</option>
                </select>
                <button class="btn btn-primary" onclick="generateImage()">GENERAR IMAGEN</button>
            </div>
            <div id="genResult" style="margin-top:20px; text-align:center"></div>
        </div>
    </div>

    <!-- PANEL CONFIG -->
    <div id="tab-config" class="tab-panel">
        <div class="glass-card" style="max-width:600px">
            <h3 class="orbitron mb-3">API CONFIG</h3>
            <div style="display:flex; flex-direction:column; gap:15px">
                <div>
                    <label class="stat-label">Gemini Key</label>
                    <input type="password" id="cfgGeminiKey" class="input-field" placeholder="AIza...">
                </div>
                <div>
                    <label class="stat-label">OpenAI Key</label>
                    <input type="password" id="cfgOpenaiKey" class="input-field" placeholder="sk-...">
                </div>
                <div>
                    <label class="stat-label">Claude Key</label>
                    <input type="password" id="cfgClaudeKey" class="input-field" placeholder="sk-ant-...">
                </div>
                <button class="btn btn-primary" onclick="saveConfig()">GUARDAR CAMBIOS</button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL IDEAS -->
<div class="modal-overlay" id="ideasModal">
    <div class="modal">
        <h2 id="ideasTitle" class="orbitron">Análisis Inteligente</h2>
        <div id="ideasProviders" style="display:flex; gap:10px; margin: 15px 0; flex-wrap:wrap"></div>

        <div style="margin-bottom:15px">
            <label class="stat-label">Acciones Rápidas:</label>
            <div style="display:flex; gap:5px; flex-wrap:wrap; margin-top:5px">
                <button class="btn btn-secondary btn-xs" onclick="setQuickPrompt('Analiza detalladamente este espacio y dime cómo hacerlo más moderno y premium.')">✨ Modernizar</button>
                <button class="btn btn-secondary btn-xs" onclick="setQuickPrompt('Dame 5 ideas creativas de decoración para este rincón.')">🏠 Decoración</button>
                <button class="btn btn-secondary btn-xs" onclick="setQuickPrompt('¿Qué paleta de colores combinaría mejor con lo que se ve en esta foto?')">🎨 Colores</button>
                <button class="btn btn-secondary btn-xs" onclick="setQuickPrompt('Genera un título SEO y una meta-descripción para esta imagen.')">🌐 SEO</button>
                <button class="btn btn-secondary btn-xs" onclick="setQuickPrompt('Escribe un copy persuasivo para Instagram sobre esta imagen.')">📱 Marketing</button>
            </div>
        </div>

        <textarea id="ideasCustomPrompt" class="input-field" rows="2" placeholder="O escribe tu propia pregunta aquí..."></textarea>

        <div id="ideasContent" class="ideas-content mt-3"></div>

        <div id="loadingIdeas" style="display:none; text-align:center; padding:20px">
            <div class="spinner"></div><br><span style="font-size:0.8rem">Procesando...</span>
        </div>

        <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center">
            <div id="genVariantContainer" style="display:none">
                <button class="btn btn-primary" style="background:var(--gemini)" onclick="generateFromAnalysis()">🎨 GENERAR NUEVA VERSIÓN</button>
            </div>
            <div style="display:flex; gap:10px">
                <button class="btn btn-secondary" onclick="closeModal('ideasModal')">CERRAR</button>
                <button class="btn btn-primary" id="btnRetryIdeas">REINTENTAR</button>
            </div>
        </div>
    </div>
</div>

<script>
let currentImage = '';
let isGen = false;
let cameraStream = null;

async function apiCall(action, data = {}, method = 'GET') {
    const url = new URL(window.location.href);
    url.searchParams.set('action', action);

    let options = { method };
    if (method === 'POST') {
        const fd = data instanceof FormData ? data : new FormData();
        if (!(data instanceof FormData)) {
            for (const k in data) fd.append(k, data[k]);
        }
        fd.append('csrf_token', '<?php echo $csrf; ?>');
        options.body = fd;
    } else if (Object.keys(data).length > 0) {
        for (const k in data) url.searchParams.set(k, data[k]);
    }

    const resp = await fetch(url);
    return await resp.json();
}

function showTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    event.target.classList.add('active');
    document.getElementById('tab-' + tab).classList.add('active');
    if (tab === 'gallery') loadGallery();
    if (tab !== 'camera') stopCamera();
}

async function loadGallery() {
    const r = await apiCall('list');
    const grid = document.getElementById('galleryGrid');
    grid.innerHTML = '';
    r.images.forEach(img => {
        grid.innerHTML += `
            <div class="image-card">
                <img src="${img.url}" class="card-img" onclick="openIdeas('${img.name}', ${img.generated})">
                <div class="card-body">
                    <span class="card-name">${img.name}</span>
                    <div class="card-meta" style="font-size:0.7rem; color:var(--fg-sec)">${img.size_fmt} | ${img.dimensions}</div>
                    <div class="card-actions">
                        <button class="btn btn-primary btn-sm" onclick="openIdeas('${img.name}', ${img.generated})">IA</button>
                        <a href="${img.url}" download class="btn btn-secondary btn-sm">⬇</a>
                        <button class="btn btn-danger btn-sm" onclick="deleteImg('${img.name}', ${img.generated})">X</button>
                    </div>
                </div>
            </div>
        `;
    });
    updateStats();
}

async function updateStats() {
    const r = await apiCall('stats');
    document.getElementById('statFiles').textContent = r.total_files;
    document.getElementById('statSize').textContent = r.total_size;
    document.getElementById('statProvs').textContent = r.providers.length;
}

async function handleFiles(files) {
    const fd = new FormData();
    for (const f of files) fd.append('images[]', f);
    await apiCall('upload', fd, 'POST');
    loadGallery();
}

async function deleteImg(name, gen) {
    if (!confirm('Eliminar?')) return;
    await apiCall('delete', { 'names[]': name, 'generated[]': gen ? 1 : 0 }, 'POST');
    loadGallery();
}

async function openIdeas(name, generated) {
    currentImage = name;
    isGen = generated;
    document.getElementById('ideasTitle').textContent = name;
    document.getElementById('ideasContent').textContent = 'Selecciona un motor de IA para analizar...';

    const stats = await apiCall('stats');
    const provs = stats.providers;
    const bar = document.getElementById('ideasProviders');
    bar.innerHTML = '';

    provs.forEach(p => {
        const btn = document.createElement('button');
        btn.className = 'btn btn-secondary';
        btn.textContent = p.toUpperCase();
        btn.onclick = () => doAnalyze(p);
        bar.appendChild(btn);
    });

    document.getElementById('ideasModal').classList.add('active');
}

let lastAnalysisProvider = '';

async function doAnalyze(provider) {
    lastAnalysisProvider = provider;
    const content = document.getElementById('ideasContent');
    const loader = document.getElementById('loadingIdeas');
    const genBtn = document.getElementById('genVariantContainer');

    content.style.display = 'none';
    loader.style.display = 'block';
    genBtn.style.display = 'none';

    const prompt = document.getElementById('ideasCustomPrompt').value;
    try {
        const r = await apiCall('analyze', { name: currentImage, provider, generated: isGen ? 1 : 0, prompt });
        loader.style.display = 'none';
        content.style.display = 'block';
        content.innerHTML = r.ideas[0].replace(/\n/g, '<br>').replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        genBtn.style.display = 'block';
    } catch (e) {
        loader.style.display = 'none';
        content.style.display = 'block';
        content.textContent = 'Error: ' + e.message;
    }
}

function setQuickPrompt(p) {
    document.getElementById('ideasCustomPrompt').value = p;
    if(lastAnalysisProvider) doAnalyze(lastAnalysisProvider);
    else {
        // click the first provider button if available
        const first = document.querySelector('#ideasProviders button');
        if(first) first.click();
    }
}

async function generateFromAnalysis() {
    const analysis = document.getElementById('ideasContent').innerText;
    const prompt = "Basado en este análisis: " + analysis.substring(0, 500) + "... Genera una nueva imagen mejorada fotorrealista.";
    showTab('generate');
    document.getElementById('genPrompt').value = prompt;
    generateImage();
}

async function generateImage() {
    const prompt = document.getElementById('genPrompt').value;
    const provider = document.getElementById('genProvider').value;
    const res = document.getElementById('genResult');
    res.innerHTML = 'Generando imagen...';
    const r = await apiCall('generate', { prompt, provider }, 'POST');
    if (r.success) {
        res.innerHTML = `<img src="${r.image.url}" style="max-width:100%; border-radius:10px">`;
        loadGallery();
    } else {
        res.textContent = 'Error: ' + r.message;
    }
}

async function toggleCamera() {
    if (cameraStream) { stopCamera(); } else { startCamera(); }
}

async function startCamera() {
    cameraStream = await navigator.mediaDevices.getUserMedia({ video: true });
    document.getElementById('cameraVideo').srcObject = cameraStream;
}

function stopCamera() {
    if (cameraStream) {
        cameraStream.getTracks().forEach(t => t.stop());
        cameraStream = null;
        document.getElementById('cameraVideo').srcObject = null;
    }
}

async function capturePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const data = canvas.toDataURL('image/jpeg');
    const r = await apiCall('upload_camera', { image_data: data }, 'POST');
    loadGallery();
}

function closeModal(id) { document.getElementById(id).classList.remove('active'); }

async function saveConfig() {
    const gemini = document.getElementById('cfgGeminiKey').value;
    const openai = document.getElementById('cfgOpenaiKey').value;
    const claude = document.getElementById('cfgClaudeKey').value;
    await apiCall('save_config', { gemini_api_key: gemini, openai_api_key: openai, claude_api_key: claude }, 'POST');
    alert('Configuración guardada');
}

loadGallery();
</script>
<?php endif; ?>
</body>
</html>
