<?php
/**
 * Plataforma IPTV Unificada
 * Sistema potente para reproducción de listas M3U/M3U8 y VOD.
 */

session_start();

// Configuración de fuentes
$fuentes_predeterminadas = [
    'Locales' => ['Canales1.m3u8', 'Canales2.m3u8', 'Canales3.m3u8', 'Canales4.m3u8'],
    'iptv-org (Global)' => 'https://iptv-org.github.io/iptv/index.m3u',
    'iptv-org (Por Idioma)' => 'https://iptv-org.github.io/iptv/index.language.m3u',
    'iptv-org (Categorías)' => 'https://iptv-org.github.io/iptv/index.category.m3u',
    'Free-TV (Global)' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlist.m3u8',
    'jromero88 (Full)' => 'https://raw.githubusercontent.com/jromero88/iptv/master/index.full.m3u',
    'VOD Películas (TMDB)' => 'https://aymrgknetzpucldhpkwm.supabase.co/storage/v1/object/public/tmdb/top-movies.m3u',
    'VOD Series (TMDB)' => 'https://aymrgknetzpucldhpkwm.supabase.co/storage/v1/object/public/tmdb/trending-series.m3u',
    'China IPTV' => 'https://raw.githubusercontent.com/hujingguang/ChinaIPTV/main/cnTV_AutoUpdate.m3u8',
    'España (Free-TV)' => 'https://raw.githubusercontent.com/Free-TV/IPTV/master/playlists/playlist_spain.m3u8',
    'Argentina (iptv-org)' => 'https://iptv-org.github.io/iptv/countries/ar.m3u'
];

/**
 * Función para obtener canales de forma eficiente y categorías
 */
function obtener_canales($fuente, $busqueda = '', $categoria_filtro = '', $pagina = 1, $limite = 48) {
    $canales = [];
    $categorias = ['General'];
    $contador = 0;
    $inicio = ($pagina - 1) * $limite;
    $fin = $inicio + $limite;

    $archivos = is_array($fuente) ? $fuente : [$fuente];

    foreach ($archivos as $archivo) {
        $es_remoto = (strpos($archivo, 'http') === 0);

        if (!$es_remoto && !file_exists($archivo)) continue;

        // Cache simple para archivos remotos
        $path_archivo = $archivo;
        if ($es_remoto) {
            $cache_file = 'cache_' . md5($archivo) . '.m3u8';
            if (!file_exists($cache_file) || (time() - filemtime($cache_file) > 3600)) {
                $content = @file_get_contents($archivo);
                if ($content) file_put_contents($cache_file, $content);
            }
            if (file_exists($cache_file)) $path_archivo = $cache_file;
        }

        $handle = @fopen($path_archivo, "r");
        if ($handle) {
            $info_actual = null;
            while (($linea = fgets($handle)) !== false) {
                $linea = trim($linea);
                if (empty($linea)) continue;

                if (strpos($linea, '#EXTINF:') === 0) {
                    $nombre = (strpos($linea, ',') !== false) ? trim(substr($linea, strrpos($linea, ',') + 1)) : 'Sin nombre';
                    $logo = preg_match('/tvg-logo="([^"]*)"/', $linea, $m) ? $m[1] : '';
                    $grupo = preg_match('/group-title="([^"]*)"/', $linea, $m) ? $m[1] : 'General';

                    // Coleccionar categorías únicas (solo los primeros 2000 canales para no saturar memoria)
                    if (count($categorias) < 50 && !in_array($grupo, $categorias)) {
                        $categorias[] = $grupo;
                    }

                    $info_actual = ['nombre' => $nombre, 'logo' => $logo, 'grupo' => $grupo];
                } elseif (strpos($linea, '#') !== 0 && $info_actual) {
                    $url = $linea;
                    $mostrar = true;

                    if (!empty($categoria_filtro) && $info_actual['grupo'] !== $categoria_filtro) {
                        $mostrar = false;
                    }

                    if ($mostrar && !empty($busqueda)) {
                        if (stripos($info_actual['nombre'], $busqueda) === false &&
                            stripos($info_actual['grupo'], $busqueda) === false) {
                            $mostrar = false;
                        }
                    }

                    if ($mostrar) {
                        if ($contador >= $inicio && $contador < $fin) {
                            $info_actual['url'] = $url;
                            $canales[] = $info_actual;
                        }
                        $contador++;
                    }
                    $info_actual = null;
                }
            }
            fclose($handle);
        }
    }

    return ['canales' => $canales, 'total' => $contador, 'categorias' => $categorias];
}

$busqueda = isset($_GET['q']) ? $_GET['q'] : '';
$cat_filtro = isset($_GET['c']) ? $_GET['c'] : '';
$fuente_key = isset($_GET['f']) ? $_GET['f'] : 'Locales';
$pagina = isset($_GET['p']) ? (int)$_GET['p'] : 1;
if ($pagina < 1) $pagina = 1;

$fuente_actual = isset($fuentes_predeterminadas[$fuente_key]) ? $fuentes_predeterminadas[$fuente_key] : $fuentes_predeterminadas['Locales'];

$resultado = obtener_canales($fuente_actual, $busqueda, $cat_filtro, $pagina);
$canales = $resultado['canales'];
$total_canales = $resultado['total'];
$categorias = $resultado['categorias'];
$total_paginas = ceil($total_canales / 48);

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
                        <?php foreach ($fuentes_predeterminadas as $key => $val): ?>
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
            </ul>
            <form class="d-flex" method="GET">
                <input type="hidden" name="f" value="<?php echo htmlspecialchars($fuente_key); ?>">
                <input type="hidden" name="c" value="<?php echo htmlspecialchars($cat_filtro); ?>">
                <input class="form-control me-2 bg-dark text-white border-secondary" type="search" name="q" placeholder="Buscar canal..." value="<?php echo htmlspecialchars($busqueda); ?>">
                <button class="btn btn-outline-primary" type="submit">Buscar</button>
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
                <h4 id="playing-title">Seleccione un canal para comenzar</h4>
                <p id="playing-group" class="text-secondary"></p>

                <hr class="border-secondary">

                <div class="card bg-dark border-secondary mb-3">
                    <div class="card-body">
                        <h5 class="card-title text-white">Reproducir URL personalizada</h5>
                        <div class="input-group">
                            <input type="text" id="custom-url" class="form-control bg-dark text-white border-secondary" placeholder="https://ejemplo.com/lista.m3u8">
                            <button class="btn btn-primary" type="button" onclick="playCustomUrl()">Reproducir</button>
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
                        <p>No se encontraron canales.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($canales as $index => $canal): ?>
                        <div class="col-6 col-md-4 col-lg-6">
                            <div class="card channel-card h-100" onclick="playChannel('<?php echo addslashes($canal['url']); ?>', '<?php echo addslashes($canal['nombre']); ?>', '<?php echo addslashes($canal['grupo']); ?>')">
                                <div class="card-body p-2 text-center d-flex flex-column justify-content-center">
                                    <div style="height: 50px;" class="d-flex align-items-center justify-content-center mb-1">
                                        <?php if (!empty($canal['logo'])): ?>
                                            <img src="<?php echo htmlspecialchars($canal['logo']); ?>" alt="Logo" class="img-fluid mw-100 mh-100" onerror="this.src='https://via.placeholder.com/50?text=TV'">
                                        <?php else: ?>
                                            <i class="fas fa-broadcast-tower fa-2x text-secondary"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small fw-bold text-truncate" title="<?php echo htmlspecialchars($canal['nombre']); ?>">
                                        <?php echo htmlspecialchars($canal['nombre'] ?: 'Sin nombre'); ?>
                                    </div>
                                    <span class="badge group-badge text-truncate"><?php echo htmlspecialchars($canal['grupo']); ?></span>
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

    // Cargar el primer canal automáticamente si existe
    <?php if (!empty($canales)): ?>
        // Comentado para no auto-reproducir al cargar página inicial
        // playChannel('<?php echo addslashes($canales[0]['url']); ?>', '<?php echo addslashes($canales[0]['nombre']); ?>', '<?php echo addslashes($canales[0]['grupo']); ?>');
    <?php endif; ?>
</script>

</body>
</html>
