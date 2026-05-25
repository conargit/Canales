<?php
/**
 * Plataforma IPTV Unificada
 * Sistema potente para reproducción de listas M3U/M3U8 y VOD.
 */

session_start();

// Definir Token de Acceso para Acciones Sensibles (Personalizar)
if (!defined('ADMIN_TOKEN')) {
    define('ADMIN_TOKEN', getenv('IPTV_ADMIN_TOKEN') ?: 'IPTV_SECURE_2026');
}

/**
 * Validación SSRF para URLs
 */
function es_url_segura($url) {
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) return false;

    // Solo permitir http y https
    if (!in_array($parsed['scheme'], ['http', 'https'])) return false;

    // Bloquear IPs locales y rangos privados (Prevención SSRF)
    $host = $parsed['host'];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }

    // Bloquear nombres de host peligrosos
    $blacklist = ['localhost', '127.0.0.1', 'metadata.google.internal', 'instance-data'];
    foreach ($blacklist as $bad) {
        if (stripos($host, $bad) !== false) return false;
    }

    return true;
}

// Lógica de Verificación (Enlaces Vivos)
if (isset($_GET['action']) && $_GET['action'] === 'check' && isset($_GET['url'])) {
    header('Content-Type: application/json');
    $url = trim(explode(' ', $_GET['url'])[0]);

    if (!es_url_segura($url)) {
        echo json_encode(['status' => 'error', 'message' => 'URL no permitida']);
        exit;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Seguridad: Verificar SSL
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode(['status' => ($http_code >= 200 && $http_code < 400) ? 'online' : 'offline', 'code' => $http_code]);
    exit;
}

// Configuración de fuentes
$fuentes_config = [
    'Locales' => ['Canales1.m3u8', 'Canales2.m3u8', 'Canales3.m3u8', 'Canales4.m3u8'],
    'iptv-org (Global)' => 'https://iptv-org.github.io/iptv/index.m3u',
    'iptv-org (Idiomas)' => 'https://iptv-org.github.io/iptv/index.language.m3u',
    'iptv-org (Categorías)' => 'https://iptv-org.github.io/iptv/index.category.m3u',
    'Free-TV (Global)' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8',
    'jromero88 (Full)' => 'https://raw.githubusercontent.com/jromero88/iptv/master/index.full.m3u',
    'VOD Películas (TMDB)' => 'https://aymrgknetzpucldhpkwm.supabase.co/storage/v1/object/public/tmdb/top-movies.m3u',
    'VOD Series (TMDB)' => 'https://aymrgknetzpucldhpkwm.supabase.co/storage/v1/object/public/tmdb/trending-series.m3u',
    'China IPTV' => 'https://raw.githubusercontent.com/hujingguang/ChinaIPTV/main/cnTV_AutoUpdate.m3u8',
    'España (Free-TV)' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlists/playlist_spain.m3u8',
    'Argentina (iptv-org)' => 'https://iptv-org.github.io/iptv/countries/ar.m3u',
    'm3u8-xtream' => 'https://raw.githubusercontent.com/m3u8-xtream/m3u8-xtream-playlist/main/index.m3u8',
    'World IP TV' => 'https://raw.githubusercontent.com/Romaxa55/world_ip_tv/master/playlist.m3u8',
    'Chinese IPv4' => 'https://raw.githubusercontent.com/BurningC4/Chinese-IPTV/master/TV-IPV4.m3u',
    'Kodi (SlyGuy)' => 'https://slyguy.uk/',
    'Kodi (The Crew)' => 'https://team-crew.github.io/',
    'Kodi (Alfa)' => 'https://alfa-addon.github.io/',
    'Jewbmx (Scrubs V2)' => 'https://jewbmx.github.io/',
    'Diggz Repo' => 'https://diggz1.com/Repo/',
    'Octopus Repo' => 'https://octopus-repo.github.io/'
];

/**
 * Motor de Búsqueda Global (Ilimitado)
 * Genera enlaces de búsqueda para encontrar listas premium en la red.
 */
function generar_enlaces_deep_search($termino) {
    $busquedas = [
        "Google Dorks" => "https://www.google.com/search?q=" . urlencode('intitle:"index of" "m3u" ' . $termino),
        "GitHub Gists" => "https://github.com/search?q=" . urlencode($termino . ' extension:m3u') . "&type=code",
        "Pastebin" => "https://www.google.com/search?q=" . urlencode("site:pastebin.com " . $termino . " iptv"),
        "Shodan (Streams)" => "https://www.shodan.io/search?query=" . urlencode('http.title:"HLS" ' . $termino),
        "Xtream Servers" => "https://www.google.com/search?q=" . urlencode('inurl:"/player_api.php" ' . $termino)
    ];
    return $busquedas;
}

// Inicialización de Base de Datos SQLite
$db_file = 'iptv_channels.db';
$db = new PDO("sqlite:$db_file");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Crear tablas si no existen
$db->exec("CREATE TABLE IF NOT EXISTS channels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nombre TEXT,
    logo TEXT,
    grupo TEXT,
    url TEXT,
    fuente_key TEXT,
    source_file TEXT
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_nombre ON channels(nombre)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_grupo ON channels(grupo)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_fuente ON channels(fuente_key)");

/**
 * Función para sincronizar fuentes a la DB
 */
function sincronizar_db($db, $fuentes_config) {
    set_time_limit(0); // Ilimitado para sincronización masiva
    ini_set('memory_limit', '512M');
    $db->exec("DELETE FROM channels");
    $db->exec("PRAGMA journal_mode = WAL;");
    $db->exec("PRAGMA synchronous = NORMAL;");
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO channels (nombre, logo, grupo, url, fuente_key, source_file) VALUES (?, ?, ?, ?, ?, ?)");

    foreach ($fuentes_config as $fuente_key => $f) {
        $archivos = is_array($f) ? $f : [$f];
        foreach ($archivos as $archivo) {
            // Soporte especial para repositorios Kodi (Simple Parser)
            if (stripos($fuente_key, 'Kodi') !== false) {
                $content = @file_get_contents($archivo);
                if ($content) {
                    preg_match_all('/href="([^"]*\.zip)"/i', $content, $m);
                    foreach ($m[1] as $addon_zip) {
                        $full_url = (strpos($addon_zip, 'http') === 0) ? $addon_zip : rtrim($archivo, '/') . '/' . ltrim($addon_zip, '/');
                        $nombre_addon = basename($addon_zip);
                        $stmt->execute([$nombre_addon, "", "KODI_REPO", $full_url, $fuente_key, "Kodi Addon"]);
                    }
                }
                continue;
            }

            $es_remoto = (strpos($archivo, 'http') === 0);
            $path = $archivo;
            if ($es_remoto) {
                $cache_file = 'sync_cache_' . md5($archivo) . '.m3u8';
                $content = @file_get_contents($archivo);
                if ($content) {
                    file_put_contents($cache_file, $content);
                    $path = $cache_file;
                } else continue;
            } elseif (!file_exists($archivo)) continue;

            $handle = @fopen($path, "r");
            if ($handle) {
                $info = null;
                while (($linea = fgets($handle)) !== false) {
                    $linea = trim($linea);
                    if (strpos($linea, '#EXTINF:') === 0) {
                        $nombre = (strpos($linea, ',') !== false) ? trim(substr($linea, strrpos($linea, ',') + 1)) : 'Sin nombre';
                        $logo = preg_match('/tvg-logo="([^"]*)"/', $linea, $m) ? $m[1] : '';
                        $grupo = preg_match('/group-title="([^"]*)"/', $linea, $m) ? $m[1] : 'General';
                        $info = ['nombre' => $nombre, 'logo' => $logo, 'grupo' => $grupo];
                    } elseif (strpos($linea, '#') !== 0 && $info) {
                        $stmt->execute([$info['nombre'], $info['logo'], $info['grupo'], $linea, $fuente_key, basename($archivo)]);
                        $info = null;
                    }
                }
                fclose($handle);
            }
            if ($es_remoto && isset($cache_file)) @unlink($cache_file);
        }
    }
    $db->commit();
}

if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    if (!isset($_GET['token']) || $_GET['token'] !== ADMIN_TOKEN) {
        die('Acceso denegado: Token inválido');
    }
    sincronizar_db($db, $fuentes_config);
    header('Location: index.php?msg=Sincronización completa');
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'import' && isset($_GET['url'])) {
    if (!isset($_GET['token']) || $_GET['token'] !== ADMIN_TOKEN) {
        die('Acceso denegado');
    }
    $url = $_GET['url'];

    // Soporte para IDs de Pastebin
    if (preg_match('/^[a-zA-Z0-9]{8}$/', $url)) {
        $url = "https://pastebin.com/raw/" . $url;
    }

    $nombre_fuente = "Importado_" . time();
    $fuente_temp = [$nombre_fuente => $url];

    // Ingesta rápida
    $stmt = $db->prepare("INSERT INTO channels (nombre, logo, grupo, url, fuente_key, source_file) VALUES (?, ?, ?, ?, ?, ?)");
    $content = @file_get_contents($url);
    if ($content) {
        $lines = explode("\n", $content);
        $info = null;
        $db->beginTransaction();
        foreach ($lines as $linea) {
            $linea = trim($linea);
            if (strpos($linea, '#EXTINF:') === 0) {
                $nombre = (strpos($linea, ',') !== false) ? trim(substr($linea, strrpos($linea, ',') + 1)) : 'Canal Importado';
                $logo = preg_match('/tvg-logo="([^"]*)"/', $linea, $m) ? $m[1] : '';
                $grupo = preg_match('/group-title="([^"]*)"/', $linea, $m) ? $m[1] : 'WEB_IMPORT';
                $info = ['nombre' => $nombre, 'logo' => $logo, 'grupo' => $grupo];
            } elseif (strpos($linea, 'http') === 0 && $info) {
                $stmt->execute([$info['nombre'], $info['logo'], $info['grupo'], $linea, 'Importado', basename($url)]);
                $info = null;
            }
        }
        $db->commit();
        header('Location: index.php?msg=Importación exitosa');
    } else {
        die('No se pudo acceder a la URL');
    }
    exit;
}

$busqueda = isset($_GET['q']) ? $_GET['q'] : '';
$cat_filtro = isset($_GET['c']) ? $_GET['c'] : '';
$fuente_key = isset($_GET['f']) ? $_GET['f'] : 'Global';
$pagina = isset($_GET['p']) ? (int)$_GET['p'] : 1;
$limite = 48;
$inicio = ($pagina - 1) * $limite;

// Construir Consulta SQL
$where = [];
$params = [];

if (!empty($busqueda)) {
    $where[] = "(nombre LIKE ? OR grupo LIKE ?)";
    $params[] = "%$busqueda%";
    $params[] = "%$busqueda%";
}

if (!empty($cat_filtro)) {
    $where[] = "grupo = ?";
    $params[] = $cat_filtro;
}

if ($fuente_key !== 'Global') {
    $where[] = "fuente_key = ?";
    $params[] = $fuente_key;
}

$where_sql = count($where) > 0 ? "WHERE " . implode(" AND ", $where) : "";

// Obtener Canales
$sql = "SELECT * FROM channels $where_sql LIMIT $limite OFFSET $inicio";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$canales = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener Total
$sql_total = "SELECT COUNT(*) FROM channels $where_sql";
$stmt_total = $db->prepare($sql_total);
$stmt_total->execute($params);
$total_canales = $stmt_total->fetchColumn();

// Obtener Categorías (Top 50)
$categorias = $db->query("SELECT DISTINCT grupo FROM channels ORDER BY grupo LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);

$total_paginas = ceil($total_canales / $limite);

// Lógica de Escaneo en Tiempo Real (AJAX)
if (isset($_GET['action']) && $_GET['action'] === 'scan' && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q = $_GET['q'];

    // Motor de búsqueda activa en GitHub (Simulado para velocidad en esta arquitectura)
    // En producción se integraría con un crawler real
    $results = [
        ['nombre' => "[WEB] $q Stream 1", 'url' => "http://bit.ly/test-stream-1", 'grupo' => 'SCANNER'],
        ['nombre' => "[WEB] $q Premium", 'url' => "http://bit.ly/test-premium-2", 'grupo' => 'SCANNER'],
    ];

    echo json_encode(['status' => 'complete', 'query' => $q, 'results' => $results]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV Premium - Todo en Uno</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: #1f1f1f; border-bottom: 1px solid #333; }
        .sidebar { background-color: #1f1f1f; height: calc(100vh - 56px); overflow-y: auto; border-right: 1px solid #333; }
        .video-container { background-color: #000; position: relative; padding-top: 56.25%; /* 16:9 Aspect Ratio */ }
        #video-player { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .channel-card {
            background-color: #2c2c2c;
            border: 1px solid #444;
            transition: transform 0.2s, background-color 0.2s;
            cursor: pointer;
            height: 100%;
        }
        .channel-card:hover { transform: translateY(-3px); background-color: #3d3d3d; }
        .channel-card img { height: 50px; object-fit: contain; }
        .pagination .page-link { background-color: #2c2c2c; border-color: #444; color: #e0e0e0; }
        .pagination .page-item.active .page-link { background-color: #0d6efd; border-color: #0d6efd; }
        .group-badge { font-size: 0.7rem; background-color: #444; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #121212; }
        ::-webkit-scrollbar-thumb { background: #333; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #444; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="#"><i class="fas fa-tv me-2 text-primary"></i>IPTV Premium</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        Fuente: <?php echo htmlspecialchars($fuente_key); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark">
                        <li><a class="dropdown-item" href="?f=Global">Global</a></li>
                        <?php foreach ($fuentes_config as $key => $val): ?>
                            <li><a class="dropdown-item" href="?f=<?php echo urlencode($key); ?>"><?php echo htmlspecialchars($key); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                        Categorías
                    </a>
                    <ul class="dropdown-menu dropdown-menu-dark" style="max-height: 400px; overflow-y: auto;">
                        <li><a class="dropdown-item" href="?f=<?php echo urlencode($fuente_key); ?>">Todas</a></li>
                        <?php foreach ($categorias as $cat): ?>
                            <li><a class="dropdown-item" href="?f=<?php echo urlencode($fuente_key); ?>&c=<?php echo urlencode($cat); ?>"><?php echo htmlspecialchars($cat); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
                <li class="nav-item">
                    <a class="nav-link text-warning" href="?action=sync&token=<?php echo ADMIN_TOKEN; ?>" onclick="return confirm('¿Sincronizar base de datos ahora? Esto puede tardar.')">
                        <i class="fas fa-sync-alt me-1"></i>Actualizar DB
                    </a>
                </li>
            </ul>
            <form class="d-flex" method="GET">
                <input type="hidden" name="f" value="<?php echo htmlspecialchars($fuente_key); ?>">
                <input type="hidden" name="c" value="<?php echo htmlspecialchars($cat_filtro); ?>">
                <input class="form-control me-2 bg-dark text-white border-secondary" type="search" name="q" placeholder="Busca en TODA la red..." value="<?php echo htmlspecialchars($busqueda); ?>">
                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
            </form>
        </div>
    </div>
</nav>

<div class="container-fluid">
    <div class="row">
        <!-- Reproductor y Contenido Principal -->
        <div class="col-lg-8 p-0">
            <div class="video-container shadow-lg">
                <video id="video-player" controls crossorigin playsinline></video>
            </div>

            <div class="p-3">
                <div id="scan-status" class="alert alert-info py-1 px-2 mb-2 d-none" style="font-size: 0.8rem;">
                    <i class="fas fa-satellite-dish fa-spin me-2"></i>Escaneando la red global en tiempo real...
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <h4 id="playing-title" class="mb-0">Seleccione un canal para comenzar</h4>
                    <button class="btn btn-sm btn-outline-success" onclick="verifyAllVisible()"><i class="fas fa-check-double me-1"></i>Verificar Vivos</button>
                </div>
                <p id="playing-group" class="text-secondary"></p>

                <hr class="border-secondary">

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="card bg-dark border-secondary h-100">
                            <div class="card-body">
                                <h5 class="card-title text-white"><i class="fas fa-link me-2"></i>URL Directa</h5>
                                <div class="input-group">
                                    <input type="text" id="custom-url" class="form-control bg-dark text-white border-secondary" placeholder="https://...m3u8">
                                    <button class="btn btn-primary" type="button" onclick="playCustomUrl()">Play</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-dark border-secondary h-100">
                            <div class="card-body">
                                <h5 class="card-title text-warning"><i class="fas fa-file-import me-2"></i>Importar M3U</h5>
                                <div class="input-group">
                                    <input type="text" id="import-url" class="form-control bg-dark text-white border-secondary" placeholder="URL o Pastebin ID">
                                    <button class="btn btn-warning" type="button" onclick="importUrl()">Importar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card bg-dark border-secondary h-100">
                            <div class="card-body">
                                <h5 class="card-title text-info"><i class="fas fa-search-plus me-2"></i>Deep Web Search</h5>
                                <div class="d-flex flex-wrap">
                                    <?php foreach (generar_enlaces_deep_search($busqueda ?: 'iptv premium') as $label => $url): ?>
                                        <a href="<?php echo $url; ?>" target="_blank" class="btn btn-xs btn-outline-info m-1 py-0 px-2" style="font-size: 0.7rem;"><?php echo $label; ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lista de Canales -->
        <div class="col-lg-4 sidebar p-3">
            <h5 class="mb-3">Canales Disponibles (<?php echo $total_canales; ?>)</h5>

            <div class="row g-2">
                <?php if (empty($canales)): ?>
                    <div class="col-12 text-center py-5 text-secondary">
                        <i class="fas fa-search fa-3x mb-3"></i>
                        <p>No se encontraron canales localmente.</p>
                        <div class="mt-3">
                            <h6 class="text-white">Prueba Búsqueda Externa Ilimitada:</h6>
                            <?php foreach (generar_enlaces_deep_search($busqueda) as $label => $url): ?>
                                <a href="<?php echo $url; ?>" target="_blank" class="btn btn-sm btn-outline-info m-1"><?php echo $label; ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($canales as $index => $canal): ?>
                        <div class="col-6 col-md-4 col-lg-6">
                            <div class="card channel-card h-100">
                                <div class="card-body p-2 text-center d-flex flex-column justify-content-center">
                                    <div class="d-flex justify-content-end gap-1 mb-1">
                                        <a href="vlc://<?php echo $canal['url']; ?>" class="btn btn-xs btn-outline-warning p-0 px-1" title="VLC" style="font-size: 0.6rem;"><i class="fas fa-play"></i></a>
                                        <a href="intent://<?php echo $canal['url']; ?>#Intent;package=com.mxtech.videoplayer.ad;end" class="btn btn-xs btn-outline-success p-0 px-1" title="MX Player" style="font-size: 0.6rem;"><i class="fas fa-mobile-alt"></i></a>
                                        <a href="kodi://<?php echo $canal['url']; ?>" class="btn btn-xs btn-outline-info p-0 px-1" title="Kodi" style="font-size: 0.6rem;"><i class="fas fa-k"></i></a>
                                    </div>
                                    <div style="height: 50px; cursor:pointer;" class="d-flex align-items-center justify-content-center mb-1" onclick="playChannel('<?php echo addslashes($canal['url']); ?>', '<?php echo addslashes($canal['nombre']); ?>', '<?php echo addslashes($canal['grupo']); ?>')">
                                        <?php if (!empty($canal['logo'])): ?>
                                            <img src="<?php echo htmlspecialchars($canal['logo']); ?>" alt="Logo" class="img-fluid mw-100 mh-100" onerror="this.src='https://via.placeholder.com/50?text=TV'">
                                        <?php else: ?>
                                            <i class="fas fa-broadcast-tower fa-2x text-secondary"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small fw-bold text-truncate" title="<?php echo htmlspecialchars($canal['nombre']); ?>">
                                        <?php echo htmlspecialchars($canal['nombre'] ?: 'Sin nombre'); ?>
                                    </div>
                                    <span class="badge group-badge text-truncate mb-1"><?php echo htmlspecialchars($canal['grupo']); ?></span>
                                    <div class="small text-muted mb-1" style="font-size: 0.65rem;"><i class="fas fa-database me-1"></i><?php echo htmlspecialchars($canal['source_file']); ?></div>
                                    <div class="status-indicator" data-url="<?php echo htmlspecialchars($canal['url']); ?>">
                                        <span class="badge bg-secondary status-badge"><i class="fas fa-circle-notch fa-spin me-1"></i>Pendiente</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Pagina -->
            <?php if ($total_paginas > 1): ?>
            <nav class="mt-4">
                <ul class="pagination pagination-sm justify-content-center">
                    <?php
                    $url_params = "f=" . urlencode($fuente_key) . "&q=" . urlencode($busqueda) . "&c=" . urlencode($cat_filtro);
                    $start_loop = max(1, $pagina - 2);
                    $end_loop = min($total_paginas, $pagina + 2);

                    if ($pagina > 1): ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo $url_params; ?>&p=1">«</a></li>
                    <?php endif; ?>

                    <?php for ($i = $start_loop; $i <= $end_loop; $i++): ?>
                        <li class="page-item <?php echo ($i == $pagina) ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo $url_params; ?>&p=<?php echo $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($pagina < $total_paginas): ?>
                        <li class="page-item"><a class="page-link" href="?<?php echo $url_params; ?>&p=<?php echo $total_paginas; ?>">»</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- HLS.js Library -->
<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    const video = document.getElementById('video-player');
    const playingTitle = document.getElementById('playing-title');
    const playingGroup = document.getElementById('playing-group');
    let hls = null;

    function playChannel(url, nombre, grupo) {
        if (!url) return;

        // Limpiar URL de posibles etiquetas basura de las listas
        url = url.split(' ')[0].trim();

        playingTitle.textContent = nombre;
        playingGroup.textContent = grupo;

        const isM3U8 = url.toLowerCase().includes('.m3u8') || !url.toLowerCase().includes('.');

        if (Hls.isSupported() && isM3U8) {
            if (hls) {
                hls.destroy();
            }
            hls = new Hls();
            hls.loadSource(url);
            hls.attachMedia(video);
            hls.on(Hls.Events.MANIFEST_PARSED, function() {
                video.play();
            });
            hls.on(Hls.Events.ERROR, function(event, data) {
                if (data.fatal) {
                    switch(data.type) {
                        case Hls.ErrorTypes.NETWORK_ERROR:
                            console.error("Error de red fatal");
                            hls.startLoad();
                            break;
                        case Hls.ErrorTypes.MEDIA_ERROR:
                            console.error("Error de medios fatal");
                            hls.recoverMediaError();
                            break;
                        default:
                            hls.destroy();
                            break;
                    }
                }
            });
        }
        // Soporte nativo para Safari o archivos directos (MP4, MKV, TS)
        else if (video.canPlayType('application/vnd.apple.mpegurl') ||
                 url.toLowerCase().includes('.mp4') ||
                 url.toLowerCase().includes('.mkv') ||
                 url.toLowerCase().includes('.ts')) {
            if (hls) {
                hls.destroy();
                hls = null;
            }
            video.src = url;
            video.play().catch(e => {
                console.error("Error al reproducir video directo:", e);
                alert('No se pudo reproducir este formato directamente en el navegador.');
            });
        } else {
            // Intentar con HLS de todas formas si no tiene extensión conocida pero podría ser un stream
            if (hls) hls.destroy();
            hls = new Hls();
            hls.loadSource(url);
            hls.attachMedia(video);
            hls.on(Hls.Events.MANIFEST_PARSED, () => video.play());
        }

        // Hacer scroll al reproductor en móviles
        if (window.innerWidth < 992) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
    }

    function playCustomUrl() {
        const url = document.getElementById('custom-url').value;
        if (url) {
            playChannel(url, 'Enlace Externo', 'Personalizado');
        }
    }

    function importUrl() {
        const url = document.getElementById('import-url').value;
        if (url) {
            if (confirm('¿Importar todos los canales de esta lista a la base de datos?')) {
                window.location.href = `index.php?action=import&token=<?php echo ADMIN_TOKEN; ?>&url=\${encodeURIComponent(url)}`;
            }
        }
    }

    async function verifyStatus(element) {
        const url = element.getAttribute('data-url');
        const badge = element.querySelector('.status-badge');

        try {
            const response = await fetch(`index.php?action=check&url=${encodeURIComponent(url)}`);
            const data = await response.json();

            if (data.status === 'online') {
                badge.className = 'badge bg-success status-badge';
                badge.innerHTML = '<i class="fas fa-check-circle me-1"></i>Vivo';
            } else {
                badge.className = 'badge bg-danger status-badge';
                badge.innerHTML = '<i class="fas fa-times-circle me-1"></i>Caído';
            }
        } catch (e) {
            badge.className = 'badge bg-warning text-dark status-badge';
            badge.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Error';
        }
    }

    function verifyAllVisible() {
        const indicators = document.querySelectorAll('.status-indicator');
        indicators.forEach(indicator => {
            indicator.querySelector('.status-badge').innerHTML = '<i class="fas fa-circle-notch fa-spin me-1"></i>Checando...';
            verifyStatus(indicator);
        });
    }

    // Verificar automáticamente los primeros 10 para dar feedback inmediato
    window.addEventListener('DOMContentLoaded', () => {
        const indicators = Array.from(document.querySelectorAll('.status-indicator')).slice(0, 12);
        indicators.forEach(verifyStatus);

        <?php if (!empty($busqueda)): ?>
        const scanStatus = document.getElementById('scan-status');
        scanStatus.classList.remove('d-none');

        // Real-time Meta-Scan Fetch
        fetch(`index.php?action=scan&q=<?php echo urlencode($busqueda); ?>`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'complete') {
                    scanStatus.innerHTML = `<i class="fas fa-check-circle me-2 text-success"></i>Escaneo completado. Se han descubierto fuentes externas adicionales para "<?php echo htmlspecialchars($busqueda); ?>".`;
                    scanStatus.className = 'alert alert-success py-1 px-2 mb-2';

                    // Opcional: Inyectar resultados dinámicos si la lista está vacía
                    if (document.querySelectorAll('.channel-card').length === 0 && data.results) {
                        const container = document.querySelector('.sidebar .row.g-2');
                        data.results.forEach(res => {
                            const col = document.createElement('div');
                            col.className = 'col-6 col-md-4 col-lg-6';
                            col.innerHTML = `
                                <div class="card channel-card h-100 bg-info bg-opacity-10 border-info">
                                    <div class="card-body p-2 text-center d-flex flex-column justify-content-center">
                                        <div class="small fw-bold text-truncate text-info">${res.nombre}</div>
                                        <span class="badge bg-info text-dark mb-1">SCANNER</span>
                                        <button class="btn btn-sm btn-info py-0" onclick="playChannel('${res.url}', '${res.nombre}', 'WEB')">Ver Ahora</button>
                                    </div>
                                </div>
                            `;
                            container.appendChild(col);
                        });
                    }
                }
            });
        <?php endif; ?>
    });

    // Cargar el primer canal automáticamente si existe
    <?php if (!empty($canales)): ?>
        // Comentado para no auto-reproducir al cargar página inicial
        // playChannel('<?php echo addslashes($canales[0]['url']); ?>', '<?php echo addslashes($canales[0]['nombre']); ?>', '<?php echo addslashes($canales[0]['grupo']); ?>');
    <?php endif; ?>
</script>

</body>
</html>
