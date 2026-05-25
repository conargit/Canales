<?php
/**
 * Plataforma IPTV Unificada v7.2 "Hardened & Extreme"
 * Sistema profesional ultra-rápido para reproducción de listas M3U/M3U8, VOD y Portales.
 * Mejoras: Seguridad XSS/SSRF, Protección de Memoria, Modo TV.
 */

session_start();

// Definir Token de Acceso Seguro (Cambiar para producción)
if (!isset($_SESSION['iptv_token'])) {
    $_SESSION['iptv_token'] = bin2hex(random_bytes(8));
}
$admin_token = getenv('IPTV_ADMIN_TOKEN') ?: 'IPTV_SECURE_2026';

/**
 * Validación SSRF Avanzada (Resolución de IP)
 */
function es_url_segura($url) {
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['host'])) return false;
    if (!in_array($parsed['scheme'] ?? '', ['http', 'https'])) return false;

    $ip = gethostbyname($parsed['host']);
    if (!$ip || $ip === $parsed['host']) return false; // No resolvió

    $ip_long = ip2long($ip);
    if ($ip_long === false) return false;

    // Rangos Privados/Locales
    $privados = [
        ['0.0.0.0', '0.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['127.0.0.0', '127.255.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
    ];

    foreach ($privados as $r) {
        if ($ip_long >= ip2long($r[0]) && $ip_long <= ip2long($r[1])) return false;
    }
    return true;
}

// Inicialización de Base de Datos SQLite
$db_file = 'iptv_channels.db';
$db = new PDO("sqlite:$db_file");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("PRAGMA journal_mode = WAL;");

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

/**
 * Lógica de Verificación de Enlaces
 */
if (isset($_GET['action']) && $_GET['action'] === 'check' && isset($_GET['url'])) {
    header('Content-Type: application/json');
    $url = trim(explode(' ', $_GET['url'])[0]);
    if (!es_url_segura($url)) { echo json_encode(['status' => 'error']); exit; }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'Mozilla/5.0'
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode(['status' => ($code >= 200 && $code < 400) ? 'online' : 'offline']);
    exit;
}

/**
 * Motor de Deep Scan (Optimizado)
 */
if (isset($_GET['action']) && $_GET['action'] === 'deep_scan' && isset($_GET['q'])) {
    header('Content-Type: application/json');
    $q = strtolower($_GET['q']);
    $found = [];
    $seeds = [
        'https://raw.githubusercontent.com/iptv-org/iptv/master/index.m3u',
        'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8'
    ];
    foreach ($seeds as $s) {
        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $content = @file_get_contents($s, false, $ctx, 0, 500000); // Max 500KB para evitar cuelgues
        if ($content) {
            $lines = explode("\n", $content);
            $info = null;
            foreach ($lines as $line) {
                if (strpos($line, '#EXTINF:') === 0 && stripos($line, $q) !== false) {
                    $nombre = trim(substr($line, strrpos($line, ',') + 1));
                    $info = ['n' => $nombre];
                } elseif (strpos($line, 'http') === 0 && $info) {
                    $found[] = ['nombre' => $info['n'], 'url' => $line];
                    $info = null;
                    if (count($found) > 12) break 2;
                }
            }
        }
    }
    echo json_encode(['status' => 'complete', 'results' => $found]);
    exit;
}

/**
 * Importación Masiva
 */
if (isset($_POST['action']) && $_POST['action'] === 'bulk_import') {
    if (!isset($_POST['token']) || $_POST['token'] !== $admin_token) die('Acceso Denegado');
    set_time_limit(120);
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO channels (nombre, logo, grupo, url, fuente_key, source_file) VALUES (?, ?, ?, ?, ?, ?)");

    if (!empty($_POST['bulk_text'])) {
        $lines = explode("\n", $_POST['bulk_text']);
        $info = null;
        foreach ($lines as $linea) {
            $linea = trim($linea);
            if (empty($linea)) continue;
            if (strpos($linea, '#EXTINF:') === 0) {
                $nombre = (strpos($linea, ',') !== false) ? trim(substr($linea, strrpos($linea, ',') + 1)) : 'Canal Pegado';
                $logo = preg_match('/tvg-logo="([^"]*)"/', $linea, $m) ? $m[1] : '';
                $grupo = preg_match('/group-title="([^"]*)"/', $linea, $m) ? $m[1] : 'Bulk';
                $info = ['n' => $nombre, 'l' => $logo, 'g' => $grupo];
            } elseif (strpos($linea, 'http') === 0) {
                $stmt->execute([$info['n'] ?? 'Canal', $info['l'] ?? '', $info['g'] ?? 'Bulk', $linea, 'Bulk', 'Paste']);
                $info = null;
            }
        }
    }
    $db->commit();
    header('Location: index.php?msg=Ok'); exit;
}

$fuentes_config = [
    'Global' => 'https://iptv-org.github.io/iptv/index.m3u',
    'Samsung+' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlists/playlist_samsung_tv_plus_us.m3u8',
    'España' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlists/playlist_spain.m3u8'
];

if (isset($_GET['action']) && $_GET['action'] === 'sync') {
    if (!isset($_GET['token']) || $_GET['token'] !== $admin_token) die('Token Inválido');
    $db->exec("DELETE FROM channels");
    $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO channels (nombre, logo, grupo, url, fuente_key, source_file) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($fuentes_config as $k => $u) {
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $content = @file_get_contents($u, false, $ctx, 0, 1000000);
        if (!$content) continue;
        $lines = explode("\n", $content);
        $info = null;
        foreach ($lines as $linea) {
            $linea = trim($linea);
            if (strpos($linea, '#EXTINF:') === 0) {
                $nombre = (strpos($linea, ',') !== false) ? trim(substr($linea, strrpos($linea, ',') + 1)) : 'Canal';
                $logo = preg_match('/tvg-logo="([^"]*)"/', $linea, $m) ? $m[1] : '';
                $grupo = preg_match('/group-title="([^"]*)"/', $linea, $m) ? $m[1] : 'Sync';
                $info = ['n' => $nombre, 'l' => $logo, 'g' => $grupo];
            } elseif (strpos($linea, 'http') === 0) {
                $stmt->execute([$info['n'] ?? 'Canal', $info['l'] ?? '', $info['g'] ?? 'Sync', $linea, $k, basename($u)]);
                $info = null;
            }
        }
    }
    $db->commit();
    header('Location: index.php?msg=Sync'); exit;
}

$q = $_GET['q'] ?? '';
$p = (int)($_GET['p'] ?? 1);
$limit = 48;
$offset = ($p - 1) * $limit;
$where = $q ? "WHERE nombre LIKE :q OR grupo LIKE :q" : "";
$stmt = $db->prepare("SELECT * FROM channels $where ORDER BY id DESC LIMIT :limit OFFSET :offset");
if ($q) $stmt->bindValue(':q', "%$q%");
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$canales = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = $db->query("SELECT COUNT(*) FROM channels $where")->fetchColumn();
$paginas = ceil($total / $limit);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IPTV EXTREME 7.2</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #0a0a0a; color: #fff; font-family: sans-serif; overflow-x: hidden; }
        .navbar { background: #000; border-bottom: 2px solid #0d6efd; position: sticky; top: 0; z-index: 1100; }
        .video-wrapper { background: #000; aspect-ratio: 16/9; position: sticky; top: 56px; z-index: 1000; }
        video { width: 100%; height: 100%; }
        .channel-card { background: #161616; border: 1px solid #333; border-radius: 12px; cursor: pointer; transition: 0.2s; height: 100%; }
        .channel-card:hover, .channel-card.focused { border-color: #0d6efd; transform: scale(1.03); background: #222; }
        .focused { outline: 3px solid #0d6efd; }
        .active { border-color: #ffc107; background: #1a1a00; }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; background: #444; }
        .online { background: #28a745; box-shadow: 0 0 8px #28a745; }
        .offline { background: #dc3545; }
        @media (max-width: 992px) { .video-wrapper { position: fixed; width: 100%; } .main-content { padding-top: 56.25vw; } }
    </style>
</head>
<body>

<nav class="navbar navbar-dark px-3">
    <a class="navbar-brand fw-bold" href="#"><i class="fas fa-bolt text-warning me-2"></i>EXTREME 7.2</a>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkModal"><i class="fas fa-plus"></i></button>
        <button class="btn btn-sm btn-outline-warning" onclick="location.href='?action=sync&token=<?=$admin_token?>'"><i class="fas fa-sync"></i></button>
        <form action="" method="GET"><input class="form-control form-control-sm bg-dark text-white" type="search" name="q" placeholder="Buscar..." value="<?=htmlspecialchars($q)?>"></form>
    </div>
</nav>

<div class="container-fluid main-content">
    <div class="row">
        <div class="col-lg-7 p-0">
            <div class="video-wrapper">
                <video id="player" controls autoplay crossorigin playsinline></video>
                <div class="p-3 position-absolute top-0 w-100" style="background:linear-gradient(to bottom,rgba(0,0,0,0.8),transparent)">
                    <h5 id="now-playing">Seleccione un canal</h5>
                </div>
            </div>
            <div class="p-3">
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-info" onclick="deepScan('hbo')">HBO</button>
                    <button class="btn btn-sm btn-outline-info" onclick="deepScan('cine')">Cine</button>
                    <button class="btn btn-sm btn-outline-info" onclick="deepScan('deportes')">Deportes</button>
                </div>
                <div id="deep-results" class="row g-2"></div>
            </div>
        </div>
        <div class="col-lg-5 p-3" style="height:calc(100vh - 56px); overflow-y:auto">
            <div class="row g-2" id="main-grid">
                <?php foreach ($canales as $c): ?>
                <div class="col-6">
                    <div class="channel-card p-2" data-url="<?=htmlspecialchars($c['url'])?>" data-name="<?=htmlspecialchars($c['nombre'])?>" onclick="playChannel(this)">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <div class="status-dot" data-url="<?=htmlspecialchars($c['url'])?>"></div>
                            <div class="text-truncate small fw-bold"><?=htmlspecialchars($c['nombre'])?></div>
                        </div>
                        <div class="text-end"><small class="text-muted" style="font-size:9px"><?=htmlspecialchars($c['grupo'])?></small></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3 d-flex justify-content-center">
                <nav><ul class="pagination pagination-sm">
                    <?php for($i=max(1,$p-2);$i<=min($paginas,$p+2);$i++): ?>
                    <li class="page-item <?=$i==$p?'active':''?>"><a class="page-link bg-dark text-white border-secondary" href="?q=<?=urlencode($q)?>&p=<?=$i?>"><?=$i?></a></li>
                    <?php endfor; ?>
                </ul></nav>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="bulkModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content bg-dark text-white border-primary">
        <div class="modal-header"><h5 class="modal-title">Carga Masiva</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="bulk_import">
            <input type="hidden" name="token" value="<?=$admin_token?>">
            <div class="modal-body"><textarea name="bulk_text" class="form-control bg-black text-info" rows="8" placeholder="Extinf + URL..."></textarea></div>
            <div class="modal-footer"><button type="submit" class="btn btn-primary w-100">Importar</button></div>
        </form>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const player = document.getElementById('player');
    const nowPlaying = document.getElementById('now-playing');
    let hls = null;
    let focusIndex = -1;

    function playChannel(el) {
        const url = el.getAttribute('data-url').split(' ')[0].trim();
        nowPlaying.textContent = el.getAttribute('data-name');
        if (hls) hls.destroy();
        if (Hls.isSupported() && (url.includes('.m3u8') || !url.includes('.'))) {
            hls = new Hls(); hls.loadSource(url); hls.attachMedia(player);
            hls.on(Hls.Events.MANIFEST_PARSED, () => player.play());
        } else { player.src = url; player.play().catch(()=>{}); }
        document.querySelectorAll('.channel-card').forEach(c => c.classList.remove('active'));
        el.classList.add('active');
        checkStatus(url, el.querySelector('.status-dot'));
    }

    async function checkStatus(url, dot) {
        if (!dot) return;
        dot.className = 'status-dot';
        try {
            const res = await fetch(`index.php?action=check&url=${encodeURIComponent(url)}`);
            const data = await res.json();
            dot.className = 'status-dot ' + (data.status === 'online' ? 'online' : 'offline');
        } catch { dot.className = 'status-dot offline'; }
    }

    async function deepScan(term) {
        const resArea = document.getElementById('deep-results');
        resArea.innerHTML = '<div class="col-12 small text-info">Escaneando...</div>';
        try {
            const res = await fetch(`index.php?action=deep_scan&q=${encodeURIComponent(term)}`);
            const data = await res.json();
            resArea.innerHTML = '';
            data.results.forEach(m => {
                const col = document.createElement('div');
                col.className = 'col-6 col-sm-4';
                const card = document.createElement('div');
                card.className = 'channel-card p-2 border-info';
                card.onclick = () => playChannel(card);
                card.setAttribute('data-url', m.url);
                card.setAttribute('data-name', m.nombre);

                const title = document.createElement('div');
                title.className = 'small fw-bold text-truncate';
                title.textContent = m.nombre;

                const sub = document.createElement('small');
                sub.className = 'text-muted';
                sub.style.fontSize = '8px';
                sub.textContent = 'LIVE SCAN';

                card.appendChild(title);
                card.appendChild(sub);
                col.appendChild(card);
                resArea.appendChild(col);
            });
        } catch { resArea.innerHTML = 'Error'; }
    }

    document.addEventListener('keydown', (e) => {
        const cards = document.querySelectorAll('.channel-card');
        if (e.key === 'ArrowDown' || e.key === 'ArrowRight') focusIndex = (focusIndex + 1) % cards.length;
        else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') focusIndex = (focusIndex - 1 + cards.length) % cards.length;
        else if (e.key === 'Enter' && focusIndex >= 0) cards[focusIndex].click();
        else return;
        cards.forEach(c => c.classList.remove('focused'));
        if (focusIndex >= 0) { cards[focusIndex].classList.add('focused'); cards[focusIndex].scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
</script>
</body>
</html>
