<?php
/**
 * SGL PRO ENTERPRISE - v3.7 Final Pro
 * Sistema de Gestión Logística SaaS para Mercado Libre Flex
 *
 * Versión: 3.7 (Módulo de Ajustes Funcional & Configuración Dinámica)
 * Integrado con MySQL 'qualityexpress' y Tabla 'operaciones'
 */

session_start();

// --- CONFIGURACIÓN DE BASE DE DATOS (MySQL) ---
$db_host = 'localhost';
$db_user = 'qualityexpress';
$db_pass = 'jplr1982';
$db_name = 'qualityexpress';

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
        manejarObtenerPedidos();
        break;
    case 'cerrar_individual':
        manejarCerrarIndividual($db);
        break;
    case 'api_guardar_ajustes':
        manejarGuardarAjustes();
        break;
    case 'obtener_bd':
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

function registrarEnOperaciones($db, $id_meli, $comprador, $direccion, $estado, $lat, $lon, $accion, $resultado, $detalle) {
    if (!$db) return;
    $sql = "INSERT INTO operaciones (id_meli, tipo, origen, modo, comprador, direccion, estado, latitud, longitud, accion, resultado, detalle, ip, user_agent)
            VALUES (:id_meli, 'pedido', 'mercadolibre', :modo, :comprador, :direccion, :estado, :latitud, :longitud, :accion, :resultado, :detalle, :ip, :user_agent)
            ON DUPLICATE KEY UPDATE estado = VALUES(estado), accion = VALUES(accion), resultado = VALUES(resultado), detalle = VALUES(detalle), fecha_actualizacion = CURRENT_TIMESTAMP";
    try {
        $db->prepare($sql)->execute([
            ':id_meli' => $id_meli, ':modo' => 'real', ':comprador' => $comprador, ':direccion' => $direccion, ':estado' => $estado,
            ':latitud' => $lat, ':longitud' => $lon, ':accion' => $accion, ':resultado' => $resultado,
            ':detalle' => json_encode($detalle), ':ip' => $_SERVER['REMOTE_ADDR'] ?? '', ':user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {}
}

function manejarObtenerPedidos() {
    header('Content-Type: application/json');

    $modo = (isset($_SESSION['access_token']) && strpos($_SESSION['access_token'], 'demo_') !== 0) ? 'real' : 'demo';

    if ($modo === 'real') {
        // En un entorno real, buscaríamos envíos Flex activos
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

    // Fallback: Mock Data para Demo o error
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
        // BYPASS GPS: Inyectamos coordenadas del destino en la llamada API
        $payload = [
            'status' => 'delivered',
            'location' => [
                'latitude' => $d['lat'],
                'longitude' => $d['lon']
            ]
        ];
        $res = meliRequest("/shipments/{$d['id']}", 'PUT', $payload);

        if (isset($res['id']) || (isset($res['status']) && $res['status'] === 'delivered')) {
            $resultado = 'ok';
        } else {
            $resultado = 'error';
        }
        $detalle = $res;
    }

    registrarEnOperaciones($db, $d['id'], $d['comprador'], $d['destino'], 'delivered', $d['lat'], $d['lon'], 'cierre_bypass', $resultado, $detalle);

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
        <p class="text-secondary mb-5 fs-5">Plataforma Logística Enterprise v3.7.<br>Gestión de Credenciales & Automatización.</p>
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
    <meta charset="UTF-8"><title>SGL PRO v3.7 | <?php echo ucfirst($pagina); ?></title>
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
                    <span class="badge bg-white text-dark border p-2 rounded-3 shadow-sm d-flex align-items-center">v3.7 Enterprise</span>
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
                        <div id="consola">> Listo. Intervalo configurado: <?php echo $ajustes['intervalo_cierre']; ?>s.</div>
                    </div>
                </div>

            <?php elseif ($pagina == 'ajustes'): ?>
                <div class="row g-4">
                    <div class="col-lg-6">
                        <div class="card p-4 shadow-sm">
                            <h5 class="fw-bold mb-4"><i class="bi bi-shield-lock me-2"></i>Credenciales Mercado Libre</h5>
                            <form id="formAjustes">
                                <div class="mb-3"><label class="small fw-bold">CLIENT_ID (App ID)</label><input type="text" name="client_id" class="form-control" value="<?php echo $ajustes['client_id']; ?>"></div>
                                <div class="mb-3"><label class="small fw-bold">CLIENT_SECRET (Secret Key)</label><input type="password" name="client_secret" class="form-control" value="<?php echo $ajustes['client_secret']; ?>"></div>
                                <hr class="my-4">
                                <h5 class="fw-bold mb-4"><i class="bi bi-clock-history me-2"></i>Tiempos de Operación</h5>
                                <div class="mb-4"><label class="small fw-bold">Intervalo de Cierre Masivo (Segundos)</label><input type="number" name="intervalo_cierre" class="form-control" value="<?php echo $ajustes['intervalo_cierre']; ?>"><div class="form-text">Tiempo de espera entre el cierre de cada pedido.</div></div>
                                <button type="submit" class="btn btn-primary fw-bold w-100 py-2 rounded-3 shadow-sm">GUARDAR CONFIGURACIÓN</button>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="card p-4 bg-primary text-white shadow-lg border-0">
                            <h6 class="fw-bold mb-3"><i class="bi bi-info-circle-fill me-2"></i>Configuración de Sistema</h6>
                            <p class="small opacity-75">Las credenciales configuradas se utilizan para el flujo de autorización OAuth2. Asegúrese de que su App en Mercado Libre tenga configurada la Redirect URI correcta para que la conexión sea exitosa.</p>
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
            tbody.innerHTML = envios.map(e => `
                <tr>
                    <td class="ps-4"><input type="checkbox" class="check-item form-check-input" value="${e.id || e.id_meli}"></td>
                    <td class="fw-bold">#${e.id || e.id_meli}</td>
                    <td><div class="small fw-bold">${e.comprador}</div><div class="small text-muted">${e.destino || e.direccion}</div></td>
                    <td><span id="st-${e.id || e.id_meli}" class="status-badge st-${e.estado}">${e.estado=='shipped'?'EN CAMINO':'CERRADO'}</span></td>
                    ${'<?php echo $pagina; ?>' == 'panel' ? `<td><button onclick="cerrarSingle(${e.id})" class="btn btn-sm btn-outline-primary fw-bold px-3 rounded-pill">Cerrar</button></td>` : ''}
                    ${'<?php echo $pagina; ?>' == 'bd' ? `<td><small class="fw-bold">${e.accion}</small></td><td><small>${e.fecha_registro}</small></td>` : ''}
                </tr>`).join('');
        }

        async function procesarCierre(id) {
            const e = envios.find(x => (x.id || x.id_meli) == id);
            log(`Iniciando cierre de #${id} con Bypass GPS...`);
            const res = await fetch('?action=cerrar_individual', {
                method: 'POST', body: JSON.stringify({ ...e, csrf_token: CSRF })
            });
            const r = await res.json();
            if (r.success) {
                const badge = document.getElementById(`st-${id}`);
                if (badge) { badge.className = 'status-badge st-delivered'; badge.textContent = 'CERRADO'; }
                log(`Éxito en #${id}: Coordenadas registradas en MySQL.`, 'success');
            }
            return r.success;
        }

        async function cierreMasivo() {
            const sel = Array.from(document.querySelectorAll('.check-item:checked')).map(c => c.value);
            if (!sel.length) return alert('Seleccione pedidos.');
            const btn = document.getElementById('btnBulk');
            btn.disabled = true;
            log(`Ejecutando proceso masivo (${sel.length} pedidos)...`);
            for (let i = 0; i < sel.length; i++) {
                await procesarCierre(sel[i]);
                if (i < sel.length - 1 && INT_CONFIG > 0) {
                    log(`Espera de seguridad: ${INT_CONFIG}s...`);
                    await new Promise(r => setTimeout(r, INT_CONFIG * 1000));
                }
            }
            btn.disabled = false;
            log('Operación finalizada.', 'success');
        }

        function log(msg, type='info') {
            const c = document.getElementById('consola'); if(!c) return;
            const d = document.createElement('div');
            d.style.color = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#94a3b8');
            d.textContent = `[${new Date().toLocaleTimeString()}] > ${msg}`;
            c.appendChild(d); c.scrollTop = c.scrollHeight;
        }

        if(document.getElementById('formAjustes')) document.getElementById('formAjustes').onsubmit = async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const config = { client_id: fd.get('client_id'), client_secret: fd.get('client_secret'), intervalo_cierre: fd.get('intervalo_cierre') };
            const res = await fetch('?action=api_guardar_ajustes', { method:'POST', body: JSON.stringify({ config, csrf_token: CSRF }) });
            if((await res.json()).success) alert('¡Ajustes guardados con éxito!');
        };

        document.getElementById('selectAll').addEventListener('change', e => { document.querySelectorAll('.check-item').forEach(c => c.checked = e.target.checked); });
        init();
    </script>
</body>
</html>
<?php } ?>
