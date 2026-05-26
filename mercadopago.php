<?php
/**
 * SGL PRO ENTERPRISE - v4.0 Final Perfecto
 * Sistema de Gestión Logística SaaS para Mercado Libre Flex
 *
 * Versión: 4.0 (Motor GPS Dual, Seguridad Hardened & Auditoría Total)
 * Todo en un solo archivo: mercadopago.php
 */

session_start();

// --- CONFIGURACIÓN DE BASE DE DATOS (MySQL) ---
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_user = getenv('DB_USER') ?: 'qualityexpress';
$db_pass = getenv('DB_PASS') ?: 'jplr1982';
$db_name = getenv('DB_NAME') ?: 'qualityexpress';

try {
    $db = new PDO(
        "mysql:host=$db_host;dbname=$db_name;charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    // Verificación de Esquema (Migración Automática Segura)
    $db->exec("CREATE TABLE IF NOT EXISTS operaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_meli VARCHAR(50) UNIQUE,
        tipo VARCHAR(20),
        origen VARCHAR(20),
        modo VARCHAR(10),
        comprador VARCHAR(100),
        direccion TEXT,
        estado VARCHAR(20),
        latitud DECIMAL(10,8),
        longitud DECIMAL(11,8),
        latitud_real DECIMAL(10,8),
        longitud_real DECIMAL(11,8),
        accion VARCHAR(50),
        resultado VARCHAR(20),
        detalle JSON,
        ip VARCHAR(45),
        user_agent TEXT,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Migración para tablas existentes: aseguramos columnas de auditoría GPS
    $cols = $db->query("SHOW COLUMNS FROM operaciones LIKE 'latitud_real'")->fetch();
    if (!$cols) {
        $db->exec("ALTER TABLE operaciones ADD COLUMN latitud_real DECIMAL(10,8), ADD COLUMN longitud_real DECIMAL(11,8)");
    }
} catch (Exception $e) {
    $db = null;
}

// --- SEGURIDAD: TOKEN CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- CARGA DINÁMICA DE CONFIGURACIÓN ---
if (!isset($_SESSION['configuracion'])) {
    $_SESSION['configuracion'] = [
        'client_id' => getenv('MELI_CLIENT_ID') ?: '848316273415124',
        'client_secret' => getenv('MELI_CLIENT_SECRET') ?: 'SECRET_KEY',
        'intervalo_cierre' => 15,
        'validar_gps' => false,
        'radio_maximo' => 200,
        'modo_oscuro' => true
    ];
}

$config = [
    'nombre_app'   => 'SGL PRO Enterprise',
    'auth_url'     => 'https://auth.mercadolibre.com.ar',
    'redirect_uri' => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . strtok($_SERVER["REQUEST_URI"], '?'),
];

// --- CALLBACK OAUTH2 ---
if (isset($_GET['code'])) {
    $ch = curl_init("https://api.mercadolibre.com/oauth/token");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type'    => 'authorization_code',
        'client_id'     => $_SESSION['configuracion']['client_id'],
        'client_secret' => $_SESSION['configuracion']['client_secret'],
        'code'          => $_GET['code'],
        'redirect_uri'  => $config['redirect_uri']
    ]));
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (isset($res['access_token'])) {
        $_SESSION['access_token'] = $res['access_token'];
        $_SESSION['usuario'] = 'Empresa Real';
        header('Location: mercadopago.php?action=panel');
        exit;
    }
}

// --- ENRUTADOR ---
$accion = $_GET['action'] ?? 'inicio';

switch ($accion) {
    case 'obtener_pedidos':
        if (!isset($_SESSION['access_token'])) { echo json_encode([]); exit; }
        manejarObtenerPedidos();
        break;
    case 'cerrar_individual':
        if (!isset($_SESSION['access_token'])) { echo json_encode(['success'=>false]); exit; }
        manejarCerrarIndividual($db);
        break;
    case 'api_guardar_ajustes':
        if (!isset($_SESSION['access_token'])) { echo json_encode(['success'=>false]); exit; }
        manejarGuardarAjustes();
        break;
    case 'obtener_bd':
        if (!isset($_SESSION['access_token'])) { echo json_encode([]); exit; }
        manejarObtenerBD($db);
        break;
    case 'demo':
        $_SESSION['access_token'] = 'demo_' . time();
        $_SESSION['usuario'] = 'Operador Maestro';
        header('Location: mercadopago.php?action=panel');
        exit;
    case 'salir':
        session_destroy();
        header('Location: mercadopago.php');
        exit;
    case 'panel':
    case 'envios':
    case 'bd':
    case 'ajustes':
        if (!isset($_SESSION['access_token'])) { header('Location: mercadopago.php'); exit; }
        renderizarInterfaz($config, $accion);
        break;
    default:
        renderizarInicio($config);
        break;
}

// --- LÓGICA DE NEGOCIO ---

function meliRequest($path, $method = 'GET', $body = null) {
    $ch = curl_init("https://api.mercadolibre.com" . $path);
    $headers = ["Authorization: Bearer " . ($_SESSION['access_token'] ?? ''), "Content-Type: application/json"];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

function manejarGuardarAjustes() {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    if (($d['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false, 'msg'=>'Token inválido']); exit;
    }
    $_SESSION['configuracion'] = array_merge($_SESSION['configuracion'], $d['config']);
    echo json_encode(['success'=>true, 'msg'=>'Ajustes guardados correctamente.']);
    exit;
}

function registrarEnOperaciones($db, $id_meli, $comprador, $direccion, $estado, $lat, $lon, $accion, $resultado, $detalle, $lat_real = null, $lon_real = null) {
    if (!$db) return;
    $sql = "INSERT INTO operaciones (id_meli, tipo, origen, modo, comprador, direccion, estado, latitud, longitud, latitud_real, longitud_real, accion, resultado, detalle, ip, user_agent)
            VALUES (:id_meli, 'pedido', 'mercadolibre', :modo, :comprador, :direccion, :estado, :latitud, :longitud, :lat_real, :lon_real, :accion, :resultado, :detalle, :ip, :user_agent)
            ON DUPLICATE KEY UPDATE estado = VALUES(estado), accion = VALUES(accion), resultado = VALUES(resultado), detalle = VALUES(detalle),
                                    latitud_real = VALUES(latitud_real), longitud_real = VALUES(longitud_real), fecha_actualizacion = CURRENT_TIMESTAMP";
    try {
        $db->prepare($sql)->execute([
            ':id_meli' => $id_meli, ':modo' => 'real', ':comprador' => $comprador, ':direccion' => $direccion, ':estado' => $estado,
            ':latitud' => $lat, ':longitud' => $lon, ':lat_real' => $lat_real, ':lon_real' => $lon_real, ':accion' => $accion, ':resultado' => $resultado,
            ':detalle' => json_encode($detalle), ':ip' => $_SERVER['REMOTE_ADDR'] ?? '', ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {}
}

function manejarObtenerPedidos() {
    header('Content-Type: application/json');
    $modo = (isset($_SESSION['access_token']) && strpos($_SESSION['access_token'], 'demo_') !== 0) ? 'real' : 'demo';
    if ($modo === 'real') {
        $res = meliRequest("/shipments/search?status=shipped&shipping_method=flex");
        if (isset($res['results'])) {
            $pedidos = array_map(function($s) {
                return [
                    'id' => $s['id'],
                    'comprador' => $s['receiver_address']['receiver_name'] ?? 'N/A',
                    'destino' => $s['receiver_address']['address_line'] ?? 'N/A',
                    'estado' => $s['status'],
                    'lat' => $s['receiver_address']['latitude'] ?? 0,
                    'lon' => $s['receiver_address']['longitude'] ?? 0
                ];
            }, $res['results']);
            echo json_encode($pedidos);
            exit;
        }
    }
    // Fallback Mock
    $pedidos = [
        ['id'=>10201, 'comprador'=>'Juan Pérez', 'destino'=>'Av. Corrientes 1234', 'estado'=>'shipped', 'lat'=>-34.6037, 'lon'=>-58.3816],
        ['id'=>10202, 'comprador'=>'Marta Gómez', 'destino'=>'Sarmiento 151', 'estado'=>'shipped', 'lat'=>-34.6075, 'lon'=>-58.3712],
        ['id'=>10203, 'comprador'=>'Carlos Ruiz', 'destino'=>'Florida 10', 'estado'=>'shipped', 'lat'=>-34.6080, 'lon'=>-58.3745],
    ];
    echo json_encode($pedidos);
    exit;
}

function manejarCerrarIndividual($db) {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true);
    if (($d['csrf_token'] ?? '') !== $_SESSION['csrf_token']) {
        echo json_encode(['success'=>false]); exit;
    }
    $modo = (isset($_SESSION['access_token']) && strpos($_SESSION['access_token'], 'demo_') !== 0) ? 'real' : 'demo';
    $resultado = 'ok';
    $detalle = ['api' => 'shipped->delivered'];
    if ($modo === 'real') {
        // BYPASS GPS: Inyectamos coordenadas del destino siempre para Mercado Libre
        $payload = [
            'status' => 'delivered',
            'sub_status' => 'fulfilled',
            'location' => [
                'latitude' => (float)$d['lat'],
                'longitude' => (float)$d['lon']
            ]
        ];
        $res = meliRequest("/shipments/{$d['id']}", 'PUT', $payload);
        $resultado = (isset($res['id']) || (isset($res['status']) && $res['status'] === 'delivered')) ? 'ok' : 'error';
        $detalle = $res;
    }
    $lat_real = $d['gps_real']['lat'] ?? null;
    $lon_real = $d['gps_real']['lon'] ?? null;
    registrarEnOperaciones($db, $d['id'], $d['comprador'], $d['destino'], 'delivered', $d['lat'], $d['lon'], 'cierre_bypass', $resultado, $detalle, $lat_real, $lon_real);
    echo json_encode(['success' => ($resultado === 'ok'), 'id' => $d['id'], 'modo' => $modo, 'res' => $detalle]);
    exit;
}

function manejarObtenerBD($db) {
    header('Content-Type: application/json');
    if (!$db) { echo json_encode([]); exit; }
    echo json_encode($db->query("SELECT * FROM operaciones ORDER BY fecha_registro DESC LIMIT 100")->fetchAll());
    exit;
}

// --- INTERFACES ---

function renderizarInicio($config) {
    $meli_auth = $config['auth_url'] . "/authorization?response_type=code&client_id=" . $_SESSION['configuracion']['client_id'] . "&redirect_uri=" . urlencode($config['redirect_uri']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>SGL PRO | Acceso</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0f172a; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .hero { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(15px); padding: 4rem; border-radius: 30px; text-align: center; border: 1px solid rgba(255,255,255,0.1); }
    </style>
</head>
<body>
    <div class="hero shadow-lg">
        <h1 class="display-4 fw-bold mb-4">SGL <span class="text-primary">PRO</span></h1>
        <p class="text-secondary mb-5 fs-5">Plataforma Logística Enterprise v4.0.<br>Gestión de Credenciales & Automatización.</p>
        <div class="d-flex flex-column gap-3">
            <a href="<?php echo $meli_auth; ?>" class="btn btn-primary btn-lg px-5 py-3 rounded-pill fw-bold">CONECTAR EMPRESA REAL</a>
            <a href="?action=demo" class="btn btn-outline-light btn-lg px-5 py-3 rounded-pill fw-bold">INICIAR MODO DEMO</a>
        </div>
    </div>
</body>
</html>
<?php
}

function renderizarInterfaz($config, $pagina) {
    $ajustes = $_SESSION['configuracion'];
    $modo = (isset($_SESSION['access_token']) && strpos($_SESSION['access_token'], 'demo_') !== 0) ? 'REAL' : 'DEMO';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>SGL PRO v4.0 | <?php echo ucfirst($pagina); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root { --sidebar: #0f172a; --primary: #3b82f6; --bg: #f8fafc; }
        body { background: var(--bg); font-family: 'Inter', system-ui; overflow: hidden; }
        .layout { display: flex; height: 100vh; }
        .sidebar { width: 260px; background: var(--sidebar); color: #fff; display: flex; flex-direction: column; }
        .main { flex: 1; overflow-y: auto; padding: 3rem; }
        .nav-link { color: #94a3b8; padding: 14px 20px; border-radius: 12px; display: flex; align-items: center; text-decoration: none; margin: 4px 15px; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { background: rgba(59, 130, 246, 0.1); color: var(--primary); }
        .nav-link.active { background: var(--primary); color: #fff; }
        .card { border-radius: 20px; border: none; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        #consola { background: #020617; color: #10b981; font-family: monospace; padding: 1.2rem; border-radius: 15px; height: 350px; overflow-y: auto; font-size: 0.85rem; }
        .status-badge { padding: 6px 14px; border-radius: 30px; font-size: 10px; font-weight: 800; text-transform: uppercase; }
        .st-shipped { background: #eff6ff; color: #1d4ed8; }
        .st-delivered { background: #f0fdf4; color: #15803d; }
    </style>
</head>
<body>
    <input type="hidden" id="csrfToken" value="<?php echo $_SESSION['csrf_token']; ?>">
    <div class="layout">
        <aside class="sidebar">
            <div class="p-4 brand fw-bold fs-4">SGL <span class="text-primary">PRO</span></div>
            <nav class="flex-grow-1">
                <a class="nav-link <?php echo $pagina == 'panel' ? 'active' : ''; ?>" href="?action=panel"><i class="bi bi-grid-fill me-3"></i> Dashboard</a>
                <a class="nav-link <?php echo $pagina == 'envios' ? 'active' : ''; ?>" href="?action=envios"><i class="bi bi-truck me-3"></i> Envíos Flex</a>
                <a class="nav-link <?php echo $pagina == 'bd' ? 'active' : ''; ?>" href="?action=bd"><i class="bi bi-database-fill me-3"></i> Auditoría BD</a>
                <a class="nav-link <?php echo $pagina == 'ajustes' ? 'active' : ''; ?>" href="?action=ajustes"><i class="bi bi-gear-fill me-3"></i> Ajustes</a>
            </nav>
            <div class="p-4"><a href="?action=salir" class="nav-link text-danger"><i class="bi bi-power me-3"></i> Salir</a></div>
        </aside>

        <main class="main">
            <div class="d-flex justify-content-between align-items-center mb-5">
                <h2 class="fw-bold m-0"><?php echo ucfirst($pagina == 'panel' ? 'Panel Operativo' : $pagina); ?></h2>
                <div class="d-flex gap-2">
                    <?php if($pagina == 'panel'): ?>
                        <button id="btnBulk" onclick="cierreMasivo()" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm"><i class="bi bi-rocket-takeoff me-2"></i>EJECUTAR CIERRE AUTOMÁTICO</button>
                    <?php endif; ?>
                    <span class="badge <?php echo $modo === 'REAL' ? 'bg-success' : 'bg-warning text-dark'; ?> border p-2 rounded-3 shadow-sm d-flex align-items-center fw-bold">MODO <?php echo $modo; ?></span>
                    <span class="badge bg-white text-dark border p-2 rounded-3 shadow-sm d-flex align-items-center">v4.0 Perfecto</span>
                </div>
            </div>

            <?php if ($pagina == 'panel' || $pagina == 'envios'): ?>
                <div class="row g-4">
                    <div class="col-lg-8">
                        <div class="card overflow-hidden">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="bg-light"><tr><th class="ps-4" width="40"><input type="checkbox" id="selectAll" class="form-check-input"></th><th>ID Envío</th><th>Cliente</th><th>Estado</th><?php if($pagina == 'panel'): ?><th>Acción</th><?php endif; ?></tr></thead>
                                <tbody id="tablaMain"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-terminal-fill me-2"></i>Monitor Real-Time</h6>
                        <div id="consola">> Listo. Intervalo: <?php echo $ajustes['intervalo_cierre']; ?>s.</div>
                    </div>
                </div>

            <?php elseif ($pagina == 'ajustes'): ?>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card p-4 shadow-sm">
                            <h5 class="fw-bold mb-4"><i class="bi bi-shield-lock me-2"></i>Credenciales Mercado Libre</h5>
                            <form id="formAjustes">
                <div class="mb-3"><label class="small fw-bold">CLIENT_ID</label><input type="text" name="client_id" class="form-control" value="<?php echo htmlspecialchars($ajustes['client_id']); ?>"></div>
                <div class="mb-3"><label class="small fw-bold">CLIENT_SECRET</label><input type="password" name="client_secret" class="form-control" value="<?php echo htmlspecialchars($ajustes['client_secret']); ?>"></div>
                                <hr class="my-4">
                                <h5 class="fw-bold mb-4"><i class="bi bi-geo-alt-fill me-2"></i>Control de Geolocalización</h5>
                                <div class="form-check form-switch mb-3">
                                    <input class="form-check-input" type="checkbox" name="validar_gps" id="validarGPS" <?php echo ($ajustes['validar_gps'] ?? false) ? 'checked' : ''; ?>>
                                    <label class="form-check-label fw-bold small" for="validarGPS">Validación de GPS Estricta</label>
                                </div>
                <div class="mb-4"><label class="small fw-bold">Radio Máximo (Metros)</label><input type="number" name="radio_maximo" class="form-control" value="<?php echo htmlspecialchars($ajustes['radio_maximo'] ?? 200); ?>"></div>
                                <button type="submit" class="btn btn-primary fw-bold w-100 py-2 rounded-3 shadow-sm">GUARDAR CONFIGURACIÓN</button>
                            </form>
                        </div>
                    </div>
                </div>

            <?php elseif ($pagina == 'bd'): ?>
                <div class="card shadow-sm overflow-hidden">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light"><tr><th>ID MELI</th><th>Comprador</th><th>Estado</th><th>Acción Auditada</th><th>Fecha</th></tr></thead>
                        <tbody id="tablaBD"></tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const CSRF = document.getElementById('csrfToken').value;
        const INT_CONFIG = <?php echo $ajustes['intervalo_cierre']; ?>;
        const GPS_STRICT = <?php echo ($ajustes['validar_gps'] ?? false) ? 'true' : 'false'; ?>;
        const MAX_RADIO = <?php echo $ajustes['radio_maximo'] ?? 200; ?>;
        let envios = [];

        async function init() {
            const endpoint = '<?php echo $pagina == "bd" ? "?action=obtener_bd" : "?action=obtener_pedidos"; ?>';
            const res = await fetch(endpoint);
            envios = await res.json();
            render();
        }

        function render() {
            const tbody = document.getElementById('tablaMain') || document.getElementById('tablaBD');
            if (!tbody) return;
            tbody.textContent = "";
            envios.forEach(e => {
                const tr = document.createElement('tr');
                const id = e.id || e.id_meli;
                const td1 = document.createElement('td'); td1.className = "ps-4";
                const chk = document.createElement('input'); chk.type = "checkbox"; chk.className = "check-item form-check-input"; chk.value = id;
                td1.appendChild(chk);
                const td2 = document.createElement('td'); td2.className = "fw-bold"; td2.textContent = `#${id}`;
                const td3 = document.createElement('td');
                const div1 = document.createElement('div'); div1.className = "small fw-bold"; div1.textContent = e.comprador;
                const div2 = document.createElement('div'); div2.className = "small text-muted"; div2.textContent = e.destino || e.direccion;
                td3.append(div1, div2);
                const td4 = document.createElement('td');
                const span = document.createElement('span'); span.id = `st-${id}`; span.className = `status-badge st-${e.estado}`; span.textContent = e.estado === 'shipped' ? 'EN CAMINO' : 'CERRADO';
                td4.appendChild(span);
                tr.append(td1, td2, td3, td4);
                if ('<?php echo $pagina; ?>' === 'panel') {
                    const td5 = document.createElement('td');
                    const btn = document.createElement('button'); btn.className = "btn btn-sm btn-outline-primary fw-bold px-3 rounded-pill"; btn.textContent = "Cerrar";
                    btn.onclick = () => procesarCierre(id);
                    td5.appendChild(btn); tr.appendChild(td5);
                }
                if ('<?php echo $pagina; ?>' === 'bd') {
                    const td5 = document.createElement('td'); const sm1 = document.createElement('small'); sm1.className = "fw-bold"; sm1.textContent = e.accion; td5.appendChild(sm1);
                    const td6 = document.createElement('td'); const sm2 = document.createElement('small'); sm2.textContent = e.fecha_registro; td6.appendChild(sm2);
                    tr.append(td5, td6);
                }
                tbody.appendChild(tr);
            });
        }

        async function getPosicion() {
            return new Promise((resolve) => {
                if (!navigator.geolocation) return resolve(null);
                navigator.geolocation.getCurrentPosition(
                    p => resolve({ lat: p.coords.latitude, lon: p.coords.longitude }),
                    e => { console.warn("GPS bloqueado:", e.message); resolve(null); },
                    { enableHighAccuracy: true, timeout: 5000 }
                );
            });
        }

        function calcularDistancia(lat1, lon1, lat2, lon2) {
            const R = 6371e3;
            const p1 = lat1 * Math.PI/180;
            const p2 = lat2 * Math.PI/180;
            const dLat = (lat2-lat1) * Math.PI/180;
            const dLon = (lon2-lon1) * Math.PI/180;
            const a = Math.sin(dLat/2) * Math.sin(dLat/2) + Math.cos(p1) * Math.cos(p2) * Math.sin(dLon/2) * Math.sin(dLon/2);
            return R * (2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)));
        }

        async function procesarCierre(id) {
            const e = envios.find(x => (x.id || x.id_meli) == id);
            log(`Cerrando #${id} [${GPS_STRICT ? 'Modo Estricto' : 'Modo Bypass'}]...`);
            let gps = null;
            if (GPS_STRICT) {
                gps = await getPosicion();
                if (gps) {
                    const dist = calcularDistancia(gps.lat, gps.lon, e.lat, e.lon);
                    log(`GPS Real: ${gps.lat.toFixed(4)}, ${gps.lon.toFixed(4)} (Dist: ${Math.round(dist)}m)`, 'info');
                    if (dist > MAX_RADIO) {
                        log(`BLOQUEADO: Muy lejos (${Math.round(dist)}m > ${MAX_RADIO}m).`, 'error');
                        alert(`Demasiado lejos (${Math.round(dist)}m).`); return false;
                    }
                } else {
                    log(`ERROR: GPS obligatorio.`, 'error'); alert("GPS obligatorio."); return false;
                }
            } else {
                log(`Bypass Activo: Inyectando coordenadas de destino...`, 'warning');
                gps = await getPosicion(); // Audit only
            }
            const res = await fetch('?action=cerrar_individual', {
                method: 'POST', body: JSON.stringify({ ...e, csrf_token: CSRF, gps_real: gps })
            });
            const r = await res.json();
            if (r.success) {
                const badge = document.getElementById(`st-${id}`);
                if (badge) { badge.className = 'status-badge st-delivered'; badge.textContent = 'CERRADO'; }
                log(`Éxito en #${id}: Pedido cerrado.`, 'success');
            }
            return r.success;
        }

        async function cierreMasivo() {
            const sel = Array.from(document.querySelectorAll('.check-item:checked')).map(c => c.value);
            if (!sel.length) return alert('Seleccione pedidos.');
            const btn = document.getElementById('btnBulk'); btn.disabled = true;
            log(`Proceso masivo (${sel.length} pedidos)...`);
            for (let i = 0; i < sel.length; i++) {
                await procesarCierre(sel[i]);
                if (i < sel.length - 1 && INT_CONFIG > 0) { await new Promise(r => setTimeout(r, INT_CONFIG * 1000)); }
            }
            btn.disabled = false; log('Finalizado.', 'success');
        }

        function log(msg, type='info') {
            const c = document.getElementById('consola'); if(!c) return;
            const d = document.createElement('div');
            d.style.color = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : (type === 'warning' ? '#f59e0b' : '#94a3b8'));
            d.textContent = `[${new Date().toLocaleTimeString()}] > ${msg}`;
            c.appendChild(d); c.scrollTop = c.scrollHeight;
        }

        if(document.getElementById('formAjustes')) document.getElementById('formAjustes').onsubmit = async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const config = { client_id: fd.get('client_id'), client_secret: fd.get('client_secret'), intervalo_cierre: fd.get('intervalo_cierre'), validar_gps: fd.get('validar_gps') === 'on', radio_maximo: fd.get('radio_maximo') };
            const res = await fetch('?action=api_guardar_ajustes', { method:'POST', body: JSON.stringify({ config, csrf_token: CSRF }) });
            if((await res.json()).success) alert('¡Ajustes guardados!');
        };

        document.getElementById('selectAll').addEventListener('change', e => { document.querySelectorAll('.check-item').forEach(c => c.checked = e.target.checked); });
        init();
    </script>
</body>
</html>
<?php } ?>
