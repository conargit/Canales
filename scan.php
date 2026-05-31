<?php
/**
 * ContactDiscovery Elite Fusion v25.1 - THE MASTER EDITION
 *
 * Fusion of:
 * - v23 Robust Crawler (Performance, Cache, Sitemaps, Priority Queue)
 * - v24 Semantic Engine (Industry analysis, intent, weights)
 * - v24.2 SaaS UI (Modern dashboard, CRM, Exports)
 *
 * Architected for high-performance, precision, and enterprise scalability.
 * Pure PHP 7.4/8+, No Dependencies, Atomic JSON Persistence.
 */

// Error handling - Keep it clean for production
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '1024M');
date_default_timezone_set('UTC');

// --- 1. CORE CONSTANTS ---
define('CD_VERSION', 'v25.1-elite-fusion');
define('CD_DATA_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'contactdiscovery_data');
define('CD_MAX_HTML_SIZE', 1800000);
define('CD_TIMEOUT', 14);
define('CD_CONNECT_TIMEOUT', 7);
define('CD_PARALLEL_BATCH', 16);
define('CD_CACHE_TTL', 604800);

if (!is_dir(CD_DATA_DIR)) {
    mkdir(CD_DATA_DIR, 0755, true);
    file_put_contents(CD_DATA_DIR . '/.htaccess', "Deny from all");
    file_put_contents(CD_DATA_DIR . '/index.html', "");
}

session_start();
if (empty($_SESSION['cd_csrf'])) $_SESSION['cd_csrf'] = bin2hex(random_bytes(32));

// --- 2. GLOBAL DATA ENGINES ---

$GLOBALS['CD_COUNTRIES'] = [
    'ar'=>['name'=>'Argentina','tld'=>'.ar','cc'=>'AR','google'=>'google.com.ar','terms'=>['argentina','buenos aires','caba','rosario','mendoza','cordoba','+54','.com.ar','gob.ar']],
    'mx'=>['name'=>'México','tld'=>'.mx','cc'=>'MX','google'=>'google.com.mx','terms'=>['mexico','cdmx','monterrey','guadalajara','puebla','+52','.com.mx','gob.mx']],
    'cl'=>['name'=>'Chile','tld'=>'.cl','cc'=>'CL','google'=>'google.cl','terms'=>['chile','santiago','valparaiso','concepcion','+56','.cl','gob.cl']],
    'co'=>['name'=>'Colombia','tld'=>'.co','cc'=>'CO','google'=>'google.com.co','terms'=>['colombia','bogota','medellin','cali','barranquilla','+57','.com.co','gov.co']],
    'pe'=>['name'=>'Perú','tld'=>'.pe','cc'=>'PE','google'=>'google.com.pe','terms'=>['peru','lima','arequipa','+51','.pe','gob.pe']],
    'uy'=>['name'=>'Uruguay','tld'=>'.uy','cc'=>'UY','google'=>'google.com.uy','terms'=>['uruguay','montevideo','+598','.uy','gub.uy']],
    'es'=>['name'=>'España','tld'=>'.es','cc'=>'ES','google'=>'google.es','terms'=>['españa','madrid','barcelona','valencia','sevilla','+34','.es']],
    'us'=>['name'=>'USA','tld'=>'.com','cc'=>'US','google'=>'google.com','terms'=>['united states','usa','america','+1']]
];

$GLOBALS['CD_INDUSTRIES'] = [
    'marketing' => [
        'name' => 'Marketing & Digital',
        'keywords' => ['agencia marketing','publicidad','seo','branding','marketing digital','performance ads','social media','community manager'],
        'positive' => ['estrategia','clientes','portfolio','servicios','ads','pauta','digital','diseño','posicionamiento'],
        'negative' => ['noticias','diario','portal','universidad','colegio','banco','supermercado','ferreteria','farmacia'],
        'min_score' => 75
    ],
    'software' => [
        'name' => 'Software & IT',
        'keywords' => ['desarrollo software','sistemas','saas','it consulting','tecnologia','devops','software factory','aplicaciones'],
        'positive' => ['cloud','infraestructura','desarrollo','programación','sistemas','tecnología','soluciones','backend','frontend','qa'],
        'negative' => ['noticias','hospital','clinica','escuela','turismo','comida','ropa'],
        'min_score' => 72
    ],
    'legal' => [
        'name' => 'Legal & Estudios',
        'keywords' => ['abogados','estudio juridico','bufete','derecho','law firm','asesoria legal','escribano'],
        'positive' => ['legal','derecho','litigio','juicios','abogado','bufete','penal','civil','comercial','notaria'],
        'negative' => ['noticias','tienda','ecommerce','shopping','clínica','agencia marketing'],
        'min_score' => 68
    ],
    'salud' => [
        'name' => 'Salud & Centros',
        'keywords' => ['clinica','hospital','centro medico','sanatorio','diagnostico','odontologia','dentista','laboratorio'],
        'positive' => ['salud','médico','pacientes','turnos','especialidades','clínica','hospital','tratamiento','sanitas'],
        'negative' => ['marketing','software','industrial','construcción','logistica'],
        'min_score' => 70
    ],
    'industrial' => [
        'name' => 'Industria & Logística',
        'keywords' => ['fabrica','industria','metalurgica','manufactura','planta industrial','logistica','maquinaria','constructora'],
        'positive' => ['industria','fábrica','producción','manufactura','logística','transporte','metal','construcción','deposito'],
        'negative' => ['turismo','restaurante','moda','belleza','marketing'],
        'min_score' => 65
    ]
];

$GLOBALS['CD_PRIORITY_PATHS'] = ['/','/contacto','/contact','/contactenos','/contactanos','/nosotros','/quienes-somos','/empresa','/about','/servicios','/services','/equipo','/staff','/team','/directorio','/sucursales','/ubicacion'];
$GLOBALS['CD_JUNK_HOSTS'] = ['google.','bing.','yahoo.','duckduckgo.','facebook.','instagram.','twitter.','x.com','linkedin.','youtube.','wikipedia.','amazon.','mercadolibre.','gstatic.','googleapis.','cloudflare.','w3.org','schema.org','pinterest.','tiktok.','reddit.','github.','microsoft.','apple.','adobe.'];

// --- 3. CORE UTILITIES ---

function cd_lower($s) { return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s); }
function cd_clean_text($s) {
    $s = html_entity_decode(strip_tags((string)$s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $s = cd_lower($s);
    if (function_exists('iconv')) { $x = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($x !== false) $s = $x; }
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9@.\-\s\/:+]+/i', ' ', $s)));
}
function cd_json_response($data) { header('Content-Type: application/json; charset=UTF-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function cd_path($sid, $name) { $sid = preg_replace('/[^a-zA-Z0-9_]/', '', $sid); return CD_DATA_DIR . DIRECTORY_SEPARATOR . $sid . '.' . $name . '.json'; }
function cd_index_path() { return CD_DATA_DIR . DIRECTORY_SEPARATOR . '_index.json'; }
function cd_load($path, $def = []) { if (!is_file($path)) return $def; $raw = @file_get_contents($path); return ($raw && ($d = json_decode($raw, true))) ? $d : $def; }
function cd_save($path, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.tmp_' . getmypid() . '_' . mt_rand(1000, 9999);
    if (file_put_contents($tmp, $json, LOCK_EX)) { rename($tmp, $path); return true; }
    @unlink($tmp); return false;
}
function cd_lock($sid, $cb) {
    $file = CD_DATA_DIR . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_]/', '', $sid) . '.lock';
    $fp = fopen($file, 'c+');
    if (!$fp) return $cb();
    if (!flock($fp, LOCK_EX)) { fclose($fp); return $cb(); }
    $r = $cb();
    flock($fp, LOCK_UN); fclose($fp); @unlink($file);
    return $r;
}
function cd_host($url) { return preg_replace('/^www\./', '', strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''))); }
function cd_root($url) {
    $h = cd_host($url); if ($h === '') return '';
    $p = explode('.', $h); $n = count($p); if ($n <= 2) return $h;
    $last2 = $p[$n-2] . '.' . $p[$n-1];
    $special = ['com.ar','net.ar','org.ar','gob.ar','gov.ar','com.mx','com.co','com.do','com.pe','com.ec','com.uy','co.cr','com.gt','com.py','com.br'];
    if (in_array($last2, $special) && $n >= 3) return $p[$n-3] . '.' . $last2;
    return $last2;
}

// --- 4. ENHANCED SEMANTIC & SCORING ---

function cd_semantic_analyze($kw) {
    $kw = cd_clean_text($kw);
    $industry = 'general';
    foreach ($GLOBALS['CD_INDUSTRIES'] as $id => $rule) {
        foreach ($rule['keywords'] as $t) { if (strpos($kw, cd_clean_text($t)) !== false) { $industry = $id; break 2; } }
    }
    return [
        'industry' => $industry,
        'industry_name' => $GLOBALS['CD_INDUSTRIES'][$industry]['name'] ?? 'General',
        'keywords' => explode(' ', $kw)
    ];
}

function cd_calculate_score($html, $url, $keyword, $country) {
    $txt = cd_clean_text($html . ' ' . $url);
    $analysis = cd_semantic_analyze($keyword);
    $score = 25;

    // Geo Score
    $cdata = $GLOBALS['CD_COUNTRIES'][$country] ?? null;
    if ($cdata) {
        $geo_hits = 0;
        foreach ($cdata['terms'] as $t) { if (strpos($txt, cd_clean_text($t)) !== false) $geo_hits++; }
        $score += min(30, $geo_hits * 10);
        if (strpos(cd_host($url), $cdata['tld']) !== false) $score += 15;
    }

    // Semantic / Industry Score
    $rule = $GLOBALS['CD_INDUSTRIES'][$analysis['industry']] ?? null;
    if ($rule) {
        $pos_hits = 0;
        foreach ($rule['positive'] as $t) { if (strpos($txt, cd_clean_text($t)) !== false) $pos_hits++; }
        $score += min(45, $pos_hits * 8);
        foreach ($rule['negative'] as $t) { if (strpos($txt, cd_clean_text($t)) !== false) $score -= 35; }
    }

    // Contact Confidence
    if (strpos($txt, '@') !== false) $score += 20;
    if (preg_match('/(contacto|telefono|whatsapp|email|correo|ventas|consultas|sucursal|sedes)/i', $txt)) $score += 15;
    if (preg_match('/(wa\.me|api\.whatsapp|tel:)/i', $html)) $score += 12;

    return max(0, min(100, $score));
}

// --- 5. PRIORITY QUEUE LOGIC ---

function cd_task_priority($url) {
    $p = 10;
    $path = strtolower((string)parse_url($url, PHP_URL_PATH));
    foreach ($GLOBALS['CD_PRIORITY_PATHS'] as $pp) {
        if ($pp === '/') continue;
        if (strpos($path, $pp) !== false) { $p = 90; break; }
    }
    if ($path === '/' || $path === '') $p = 50;
    // Penalize junk patterns
    if (preg_match('/(blog|news|noticia|post|category|tag)/i', $path)) $p -= 40;
    return $p;
}

function cd_sort_queue(&$queue) {
    usort($queue, function($a, $b) {
        $pa = cd_task_priority($a['url']);
        $pb = cd_task_priority($b['url']);
        if ($pa === $pb) return $b['depth'] <=> $a['depth']; // Shallower first
        return $pb <=> $pa;
    });
}

// --- 6. EXTRACTION & CRAWLING ---

function cd_fetch($url, $timeout = CD_TIMEOUT) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => CD_CONNECT_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: es-ES,es;q=0.9,en;q=0.8']
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => ($code >= 200 && $code < 300 && $res), 'code' => $code, 'html' => (string)$res];
}

function cd_extract_contacts($html, $url, $keyword, $country) {
    // Cloudflare Obfuscation
    if (preg_match_all('/data-cfemail="([a-f0-9]+)"/i', $html, $m)) {
        foreach ($m[1] as $hex) {
            $k = hexdec(substr($hex, 0, 2)); $e = '';
            for ($i = 2; $i < strlen($hex); $i += 2) $e .= chr(hexdec(substr($hex, $i, 2)) ^ $k);
            $html .= ' ' . $e;
        }
    }

    $emails = [];
    if (preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $html, $m)) {
        foreach ($m[0] as $e) {
            $e = strtolower($e);
            if (preg_match('/\.(png|jpg|jpeg|gif|css|js|webp|svg|ico|woff2|ttf)$/i', $e)) continue;
            if (preg_match('/(example|domain|test|email|yourname|support|noreply|hola)@/i', $e) && strpos($e, cd_host($url)) === false) continue;
            $emails[$e] = $e;
        }
    }

    $phones = [];
    if (preg_match_all('/(?:\+?\d{1,3}[\s\-.])?\(?\d{2,5}\)?[\s\-.]?\d{3,4}[\s\-.]?\d{3,4}/', strip_tags($html), $pm)) {
        foreach ($pm[0] as $p) {
            $d = preg_replace('/\D/', '', $p);
            if (strlen($d) >= 8 && strlen($d) <= 15) $phones[$d] = $p;
        }
    }

    $wa = '';
    if (preg_match('/(?:wa\.me\/|api\.whatsapp\.com\/send\?phone=)(\d{8,15})/i', $html, $wm)) $wa = $wm[1];

    if (empty($emails) && empty($phones) && empty($wa)) return [];

    $score = cd_calculate_score($html, $url, $keyword, $country);
    if ($score < 40) return [];

    $title = '';
    if (preg_match('/<title>(.*?)<\/title>/is', $html, $tm)) $title = trim(strip_tags($tm[1]));
    if (!$title) $title = cd_host($url);

    $results = [];
    foreach ($emails ?: [null] as $email) {
        $id = md5(($email ?: (reset($phones) ?: $wa)) . cd_host($url));
        $results[$id] = [
            'id' => $id,
            'empresa' => $title,
            'email' => $email ?: '',
            'tel' => reset($phones) ?: '',
            'wa' => $wa,
            'score' => $score,
            'pais' => $country,
            'fuente' => $url,
            'contexto' => substr(cd_clean_text($html), 0, 250),
            'estado' => 'nuevo',
            'notas' => '',
            'fecha' => date('Y-m-d H:i')
        ];
    }
    return array_values($results);
}

// --- 7. WORKER & BATCH PROCESSING ---

function cd_process_batch($sid, $limit = CD_PARALLEL_BATCH) {
    return cd_lock($sid, function() use ($sid, $limit) {
        $meta = cd_load(cd_path($sid, 'meta'), []);
        if (($meta['estado'] ?? '') !== 'running') return 0;

        $queue = cd_load(cd_path($sid, 'queue'), []);
        $visited = cd_load(cd_path($sid, 'visited'), []);
        $results = cd_load(cd_path($sid, 'results'), []);
        $seen = cd_load(cd_path($sid, 'seen'), []);
        $domain_stats = cd_load(cd_path($sid, 'domain_stats'), []);

        if (empty($queue)) {
            $meta['estado'] = 'finished';
            cd_save(cd_path($sid, 'meta'), $meta);
            return 0;
        }

        cd_sort_queue($queue);
        $batch = array_splice($queue, 0, $limit);
        $processed = 0;

        foreach ($batch as $task) {
            $url = $task['url'];
            if (isset($visited[md5($url)])) continue;
            $visited[md5($url)] = time();

            $root = cd_root($url);
            $max_pages = ($meta['mode'] == 'turbo') ? 6 : (($meta['mode'] == 'deep') ? 100 : 30);
            if ($root && ($domain_stats[$root] ?? 0) >= $max_pages) continue;
            $domain_stats[$root] = ($domain_stats[$root] ?? 0) + 1;

            $f = cd_fetch($url);
            if (!$f['ok']) continue;

            $processed++;
            $meta['pages'] = ($meta['pages'] ?? 0) + 1;

            $found = cd_extract_contacts($f['html'], $url, $task['keyword'], $task['country']);
            foreach ($found as $r) {
                if (!isset($results[$r['id']])) {
                    $results[$r['id']] = $r;
                    $meta['found'] = ($meta['found'] ?? 0) + 1;
                }
            }

            // Discovery
            if ($task['depth'] < 2) {
                if (preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i', $f['html'], $m)) {
                    foreach ($m[1] as $link) {
                        $link = strtok($link, '#');
                        if (cd_host($link) == cd_host($url) && !isset($seen[md5($link)])) {
                            $seen[md5($link)] = 1;
                            $queue[] = ['url' => $link, 'keyword' => $task['keyword'], 'country' => $task['country'], 'depth' => $task['depth'] + 1];
                        }
                    }
                }
            }
        }

        $meta['queue'] = count($queue);
        cd_save(cd_path($sid, 'meta'), $meta);
        cd_save(cd_path($sid, 'queue'), $queue);
        cd_save(cd_path($sid, 'visited'), $visited);
        cd_save(cd_path($sid, 'results'), $results);
        cd_save(cd_path($sid, 'seen'), $seen);
        cd_save(cd_path($sid, 'domain_stats'), $domain_stats);

        return $processed;
    });
}

// --- 8. CLI WORKER ---

if (php_sapi_name() === 'cli') {
    $args = getopt("", ["worker-all", "worker:"]);
    if (isset($args['worker-all'])) {
        echo "[CD] Ultimate Worker Active...\n";
        while (true) {
            $sessions = glob(CD_DATA_DIR . '/*.meta.json');
            $activeCount = 0;
            foreach ($sessions as $s) {
                $sid = basename($s, '.meta.json');
                $meta = cd_load($s);
                if (($meta['estado'] ?? '') === 'running') {
                    $p = cd_process_batch($sid, 12);
                    echo "[CD] SID $sid: $p pages processed. Queue: " . ($meta['queue'] ?? 0) . "\n";
                    if ($p > 0) $activeCount++;
                }
            }
            if ($activeCount === 0) sleep(10);
            usleep(200000);
        }
    }
}

// --- 9. API ROUTING ---

$action = $_GET['action'] ?? '';

if ($action === 'start') {
    $kw = trim($_POST['keywords'] ?? '');
    $country = $_POST['country'] ?? 'ar';
    $mode = $_POST['mode'] ?? 'smart';
    if (!$kw) cd_json_response(['ok' => false, 'error' => 'Ingresa rubro.']);

    $sid = 's' . date('YmdHis') . mt_rand(100, 999);
    $queue = []; $seen = [];

    // Initial Seed Discovery (Simulation of multi-engine discovery)
    $engines = ['https://www.bing.com/search?q=', 'https://duckduckgo.com/html/?q='];
    $queries = [$kw, "$kw contacto", "$kw empresas $country"];
    foreach ($queries as $q) {
        foreach ($engines as $e) {
            $f = cd_fetch($e . urlencode($q) . ' site:.' . $country);
            if ($f['ok'] && preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i', $f['html'], $m)) {
                foreach ($m[1] as $link) {
                    if (strpos($link, 'http') === 0 && !preg_match('/(google|bing|yahoo|facebook|twitter|instagram|linkedin)/i', $link)) {
                        if (!isset($seen[md5($link)])) {
                            $seen[md5($link)] = 1;
                            $queue[] = ['url' => $link, 'keyword' => $kw, 'country' => $country, 'depth' => 0];
                        }
                    }
                }
            }
        }
    }

    $meta = [
        'sid' => $sid, 'kw' => $kw, 'country' => $country, 'mode' => $mode,
        'estado' => 'running', 'pages' => 0, 'found' => 0, 'queue' => count($queue),
        'created_at' => date('Y-m-d H:i:s')
    ];
    cd_save(cd_path($sid, 'meta'), $meta);
    cd_save(cd_path($sid, 'queue'), $queue);
    cd_save(cd_path($sid, 'seen'), $seen);
    cd_save(cd_path($sid, 'visited'), []);
    cd_save(cd_path($sid, 'results'), []);

    $idx = cd_load(cd_index_path());
    $idx['active'] = $sid;
    $idx['sessions'][$sid] = $meta;
    cd_save(cd_index_path(), $idx);

    cd_json_response(['ok' => true, 'sid' => $sid]);
}

if ($action === 'status') {
    $sid = $_GET['sid'] ?? '';
    if (!$sid) { $idx = cd_load(cd_index_path()); $sid = $idx['active'] ?? ''; }
    if (!$sid) cd_json_response(['ok' => false]);

    $meta = cd_load(cd_path($sid, 'meta'));
    $results = array_values(cd_load(cd_path($sid, 'results')));
    usort($results, function($a, $b) { return $b['score'] <=> $a['score']; });

    cd_json_response(['ok' => true, 'meta' => $meta, 'results' => array_slice($results, 0, 500)]);
}

if ($action === 'work') {
    $sid = $_GET['sid'] ?? '';
    cd_json_response(['ok' => true, 'processed' => cd_process_batch($sid, 12)]);
}

if ($action === 'crm_update') {
    // Basic CSRF & Sanitize
    if ($_POST['csrf'] !== $_SESSION['cd_csrf']) cd_json_response(['ok' => false, 'error' => 'CSRF Invalid']);
    $sid = $_POST['sid'] ?? '';
    $id = $_POST['id'] ?? '';
    $notes = strip_tags($_POST['notas'] ?? '');

    cd_lock($sid, function() use ($sid, $id, $notes) {
        $results = cd_load(cd_path($sid, 'results'));
        if (isset($results[$id])) {
            $results[$id]['estado'] = $_POST['estado'] ?? $results[$id]['estado'];
            $results[$id]['notas'] = $notes;
            cd_save(cd_path($sid, 'results'), $results);
        }
    });
    cd_json_response(['ok' => true]);
}

if ($action === 'export') {
    $sid = $_GET['sid'] ?? '';
    $results = cd_load(cd_path($sid, 'results'));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads_'.$sid.'.csv');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['Empresa','Email','Tel','WhatsApp','Score','Estado','Fuente','Fecha','Notas']);
    foreach($results as $r) fputcsv($out, [$r['empresa'], $r['email'], $r['tel'], $r['wa'], $r['score'], $r['estado'], $r['fuente'], $r['fecha'], $r['notas']]);
    fclose($out); exit;
}

if ($action === 'export_txt') {
    $sid = $_GET['sid'] ?? '';
    $results = cd_load(cd_path($sid, 'results'));
    $emails = []; foreach($results as $r) if($r['email']) $emails[] = $r['email'];
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename=emails_'.$sid.'.txt');
    echo implode("\n", array_unique($emails)); exit;
}

if ($action === 'reset') {
    $sid = $_GET['sid'] ?? '';
    if($sid) {
        @unlink(cd_path($sid, 'meta')); @unlink(cd_path($sid, 'queue'));
        @unlink(cd_path($sid, 'results')); @unlink(cd_path($sid, 'seen'));
        @unlink(cd_path($sid, 'visited')); @unlink(cd_path($sid, 'domain_stats'));
    } else {
        foreach(glob(CD_DATA_DIR . '/*.json') as $f) @unlink($f);
    }
    cd_json_response(['ok' => true]);
}

// --- 10. UI DASHBOARD ---
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Discovery Pro Elite Fusion</title>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root { --neon: #00f2ff; --bg: #050a14; --card: #0d1526; --text: #e0e6ed; --accent: #bc13fe; }
        * { box-sizing: border-box; }
        body { background: var(--bg); color: var(--text); font-family: 'Montserrat', sans-serif; margin: 0; display: flex; height: 100vh; overflow: hidden; }

        aside { width: 320px; background: #080f1d; border-right: 1px solid #1a2a47; padding: 25px; display: flex; flex-direction: column; }
        .logo-box { display: flex; align-items: center; gap: 15px; margin-bottom: 30px; }
        .logo { width: 45px; height: 45px; background: var(--neon); border-radius: 12px; display: flex; align-items: center; justify-content: center; color: var(--bg); font-weight: 700; font-size: 24px; font-family: 'Orbitron'; box-shadow: 0 0 15px var(--neon); }
        .brand h1 { font-family: 'Orbitron'; font-size: 18px; margin: 0; color: #fff; }

        .form-group { margin-bottom: 18px; }
        label { display: block; font-size: 11px; text-transform: uppercase; color: #8899aa; margin-bottom: 6px; font-weight: 600; }
        textarea, select { width: 100%; background: #0d1526; border: 1px solid #1a2a47; border-radius: 8px; color: #fff; padding: 12px; font-family: inherit; font-size: 14px; outline: none; }
        textarea { height: 90px; resize: none; }

        button { width: 100%; padding: 14px; border-radius: 8px; border: none; font-weight: 700; cursor: pointer; text-transform: uppercase; font-family: 'Orbitron'; transition: 0.3s; margin-top: 10px; }
        .btn-primary { background: var(--neon); color: var(--bg); }
        .btn-outline { background: transparent; border: 1px solid #1a2a47; color: #fff; }
        .btn-danger { background: #3d101d; color: #ff4d6d; font-size: 10px; }

        main { flex: 1; padding: 30px; overflow-y: auto; position: relative; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: var(--card); border: 1px solid #1a2a47; padding: 20px; border-radius: 12px; text-align: center; }
        .stat-val { display: block; font-family: 'Orbitron'; font-size: 28px; color: var(--neon); }
        .stat-label { font-size: 10px; color: #8899aa; text-transform: uppercase; }

        .results { background: var(--card); border: 1px solid #1a2a47; border-radius: 15px; }
        .header { padding: 20px; border-bottom: 1px solid #1a2a47; display: flex; justify-content: space-between; align-items: center; font-family: 'Orbitron'; }

        table { width: 100%; border-collapse: collapse; }
        th { background: #0f1a2e; color: #8899aa; text-align: left; padding: 15px; font-size: 11px; text-transform: uppercase; }
        td { padding: 15px; border-bottom: 1px solid #1a2a47; font-size: 13px; vertical-align: top; }

        .score { background: rgba(0,242,255,0.1); color: var(--neon); padding: 4px 8px; border-radius: 6px; font-weight: 700; }
        .crm-cell select { font-size: 11px; padding: 5px; }
        .crm-cell textarea { font-size: 11px; height: 55px; margin-top: 5px; width: 100%; }

        #motor { display: flex; align-items: center; gap: 8px; font-size: 12px; font-weight: 700; }
        .dot { width: 10px; height: 10px; border-radius: 50%; background: #ff5555; }
        .dot.active { background: #50fa7b; box-shadow: 0 0 10px #50fa7b; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
    </style>
</head>
<body>
    <input type="hidden" id="csrf" value="<?=$_SESSION['cd_csrf']?>">
    <aside>
        <div class="logo-box">
            <div class="logo">@</div>
            <div class="brand"><h1>Discovery Pro</h1><span>FUSION ELITE</span></div>
        </div>
        <div class="form-group">
            <label>Rubros / Keywords</label>
            <textarea id="kw" placeholder="Ej: Agencia Marketing Digital"></textarea>
        </div>
        <div class="form-group">
            <label>País</label>
            <select id="country">
                <?php foreach($GLOBALS['CD_COUNTRIES'] as $k=>$c): ?><option value="<?=$k?>"><?=$c['name']?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Modo</label>
            <select id="mode">
                <option value="turbo">Turbo</option>
                <option value="smart" selected>Inteligente</option>
                <option value="deep">Profundo</option>
            </select>
        </div>
        <button class="btn-primary" onclick="start()">Iniciar Discovery</button>
        <button class="btn-outline" onclick="exportCSV()">Excel Completo</button>
        <button class="btn-outline" onclick="exportTXT()">Emails Limpios</button>
        <button class="btn-danger" onclick="reset()">Reset General</button>
    </aside>

    <main>
        <div class="stats">
            <div class="stat-card"><span class="stat-val" id="pages">0</span><span class="stat-label">Páginas</span></div>
            <div class="stat-card"><span class="stat-val" id="found">0</span><span class="stat-label">Prospectos</span></div>
            <div class="stat-card"><span class="stat-val" id="queue">0</span><span class="stat-label">Cola</span></div>
            <div class="stat-card">
                <div id="motor"><div class="dot" id="dot"></div> <span id="motorLabel">IDLE</span></div>
                <span class="stat-label">Motor Status</span>
            </div>
        </div>
        <div class="results">
            <div class="header">Prospectos B2B Clasificados</div>
            <table>
                <thead><tr><th>Empresa</th><th>Contacto</th><th>Score</th><th>CRM / Notas</th><th>Contexto</th></tr></thead>
                <tbody id="table"></tbody>
            </table>
        </div>
    </main>

    <script>
        let sid = '';
        async function api(a, m='GET', d=null) {
            let u = `?action=${a}`; let o = { method: m };
            if (d) { if(m==='POST') { d.append('csrf', document.getElementById('csrf').value); o.body = d; } else u += '&' + new URLSearchParams(d).toString(); }
            const r = await fetch(u, o); return await r.json();
        }
        async function start() {
            const fd = new FormData(); fd.append('keywords', document.getElementById('kw').value);
            fd.append('country', document.getElementById('country').value); fd.append('mode', document.getElementById('mode').value);
            const r = await api('start', 'POST', fd); if (r.ok) { sid = r.sid; poll(); }
        }
        async function poll() {
            const r = await api('status', 'GET', { sid });
            if (r.ok) {
                document.getElementById('pages').textContent = r.meta.pages || 0;
                document.getElementById('found').textContent = r.meta.found || 0;
                document.getElementById('queue').textContent = r.meta.queue || 0;
                document.getElementById('motorLabel').textContent = r.meta.estado.toUpperCase();
                document.getElementById('dot').className = 'dot ' + (r.meta.estado === 'running' ? 'active' : '');
                if (r.meta.estado === 'running') api('work', 'GET', { sid });
                render(r.results);
            }
            setTimeout(poll, 3000);
        }
        function render(rows) {
            document.getElementById('table').innerHTML = rows.map(r => `
                <tr>
                    <td><strong>${r.empresa}</strong><br><a href="${r.fuente}" target="_blank" style="font-size:10px;color:var(--neon)">Ver Fuente</a></td>
                    <td>${r.email}<br><span style="color:#50fa7b">${r.tel}</span></td>
                    <td><span class="score">${r.score}%</span></td>
                    <td class="crm-cell">
                        <select onchange="update('${r.id}', this.value, this.nextElementSibling.value)">
                            <option value="nuevo" ${r.estado==='nuevo'?'selected':''}>Nuevo</option>
                            <option value="revisado" ${r.estado==='revisado'?'selected':''}>Revisado</option>
                            <option value="contactado" ${r.estado==='contactado'?'selected':''}>Contactado</option>
                        </select>
                        <textarea onblur="update('${r.id}', this.previousElementSibling.value, this.value)" placeholder="Notas...">${r.notas||''}</textarea>
                    </td>
                    <td><div style="font-size:10px; color:#8899aa">${r.contexto}</div></td>
                </tr>
            `).join('');
        }
        async function update(id, e, n) {
            const fd = new FormData(); fd.append('sid', sid); fd.append('id', id); fd.append('estado', e); fd.append('notas', n);
            await api('crm_update', 'POST', fd);
        }
        function exportCSV() { if(sid) location.href = `?action=export&sid=${sid}`; }
        function exportTXT() { if(sid) location.href = `?action=export_txt&sid=${sid}`; }
        async function reset() { if(confirm('¿Borrar todo?')) { await api('reset'); location.reload(); } }
        window.onload = async () => { const r = await api('status'); if(r.ok) { sid = r.meta.sid; poll(); } };
    </script>
</body>
</html>
