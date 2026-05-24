<?php
/**
 * SGL PRO ENTERPRISE - SaaS Logistics Management System
 * Mercado Libre Flex GPS Bypass Solution
 *
 * Version: 2.1 Premium (Secured & Fully Functional)
 * Consolidado en un único archivo mercadopago.php
 */

session_start();

// --- CONFIGURACIÓN ---
$config = [
    'app_name'       => 'SGL PRO Enterprise',
    'meli_api_url'   => 'https://api.mercadolibre.com',
    'auth_url'       => 'https://auth.mercadolibre.com.ar',
    'client_id'      => getenv('MELI_CLIENT_ID') ?: 'TU_CLIENT_ID',
    'client_secret'  => getenv('MELI_CLIENT_SECRET') ?: 'TU_CLIENT_SECRET',
    'redirect_uri'   => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . strtok($_SERVER["REQUEST_URI"], '?'),
    'seller_id'      => getenv('MELI_SELLER_ID') ?: '',
];

// --- ROUTER ---
$action = $_GET['action'] ?? 'home';

switch ($action) {
    case 'get_orders':
        handleGetOrders($config);
        break;
    case 'bulk_close':
        handleBulkClose($config);
        break;
    case 'callback':
        handleCallback($config);
        break;
    case 'demo':
        $_SESSION['is_demo'] = true;
        $_SESSION['access_token'] = 'demo_token_' . time();
        header('Location: mercadopago.php?action=dashboard');
        exit;
    case 'logout':
        session_destroy();
        header('Location: mercadopago.php');
        exit;
    case 'dashboard':
        if (!isset($_SESSION['access_token'])) {
            header('Location: mercadopago.php');
            exit;
        }
        renderDashboard($config);
        break;
    case 'home':
    default:
        renderHome($config);
        break;
}

// --- LÓGICA DE INTEGRACIÓN ---

function meli_request($method, $path, $config, $data = null) {
    if (isset($_SESSION['is_demo']) && $_SESSION['is_demo']) {
        return mock_meli_response($method, $path, $data);
    }

    $url = (strpos($path, 'http') === 0) ? $path : $config['meli_api_url'] . $path;
    $ch = curl_init();
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (isset($_SESSION['access_token']) && strpos($path, '/oauth/token') === false) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['access_token'];
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($data) {
        $payload = is_array($data) ? json_encode($data) : $data;
        if (strpos($path, '/oauth/token') !== false) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            $headers[0] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'data' => json_decode($response, true)];
}

function handleCallback($config) {
    if (isset($_GET['code'])) {
        $postData = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code'          => $_GET['code'],
            'redirect_uri'  => $config['redirect_uri']
        ];

        $res = meli_request('POST', '/oauth/token', $config, $postData);

        if ($res['status'] == 200 && isset($res['data']['access_token'])) {
            $_SESSION['access_token'] = $res['data']['access_token'];
            $_SESSION['refresh_token'] = $res['data']['refresh_token'] ?? null;
            $_SESSION['is_demo'] = false;
        } else {
            // Error en la autenticación real
            $_SESSION['auth_error'] = $res['data']['message'] ?? 'Error desconocido en OAuth';
            header('Location: mercadopago.php');
            exit;
        }
    }
    header('Location: mercadopago.php?action=dashboard');
    exit;
}

function handleGetOrders($config) {
    header('Content-Type: application/json');
    $searchRes = meli_request('GET', '/shipments/search?logistic_type=flex&status=shipped', $config);
    $ids = $searchRes['data']['results'] ?? [];
    $shipments = [];
    foreach ($ids as $id) {
        $detail = meli_request('GET', "/shipments/$id", $config);
        if ($detail['status'] == 200) {
            $shipments[] = [
                'id' => $detail['data']['id'],
                'status' => $detail['data']['status'],
                'destination' => $detail['data']['receiver_address']['address_line'] ?? 'Sin dirección',
                'lat' => $detail['data']['receiver_address']['latitude'] ?? 0,
                'lon' => $detail['data']['receiver_address']['longitude'] ?? 0
            ];
        }
    }
    echo json_encode($shipments);
    exit;
}

function handleBulkClose($config) {
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    $results = [];
    foreach ($ids as $id) {
        $detail = meli_request('GET', "/shipments/$id", $config);
        if ($detail['status'] != 200) continue;

        $lat = $detail['data']['receiver_address']['latitude'];
        $lon = $detail['data']['receiver_address']['longitude'];

        $update = meli_request('PUT', "/shipments/$id", $config, [
            'status' => 'delivered', 'substatus' => 'delivered',
            'location' => ['latitude' => $lat, 'longitude' => $lon]
        ]);

        $results[] = [
            'id' => $id, 'success' => ($update['status'] < 300),
            'message' => $update['status'] < 300 ? "Bypass GPS exitoso en ($lat, $lon)" : "Error en el cierre de #$id"
        ];
        usleep(rand(1200000, 3000000));
    }
    echo json_encode($results);
    exit;
}

function mock_meli_response($method, $path, $data) {
    if (strpos($path, '/shipments/search') !== false) {
        return ['status' => 200, 'data' => ['results' => [5001, 5002, 5003, 5004, 5005]]];
    }
    if (preg_match('/\/shipments\/(\d+)/', $path, $matches)) {
        $id = $matches[1];
        if ($method === 'GET') {
            $destinations = [
                5001 => ['addr' => 'Av. Corrientes 1234, CABA', 'lat' => -34.6037, 'lon' => -58.3816],
                5002 => ['addr' => 'Sarmiento 151, CABA', 'lat' => -34.6075, 'lon' => -58.3712],
                5003 => ['addr' => 'Av. Santa Fe 2500, CABA', 'lat' => -34.5915, 'lon' => -58.4022],
                5004 => ['addr' => 'Florida 10, CABA', 'lat' => -34.6080, 'lon' => -58.3745],
                5005 => ['addr' => 'Juramento 2100, CABA', 'lat' => -34.5612, 'lon' => -58.4556],
            ];
            $dest = $destinations[$id] ?? ['addr' => 'Dirección Mock', 'lat' => -34.6, 'lon' => -58.4];
            return ['status' => 200, 'data' => [
                'id' => $id, 'status' => 'shipped', 'logistic_type' => 'flex',
                'receiver_address' => ['address_line' => $dest['addr'], 'latitude' => $dest['lat'], 'longitude' => $dest['lon']]
            ]];
        }
        return ['status' => 200, 'data' => ['status' => 'delivered']];
    }
    return ['status' => 404, 'data' => []];
}

// --- INTERFACES ---

function renderHome($config) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($config['app_name']); ?> | SaaS Logistics</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', system-ui; background: #0f172a; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .hero-card { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 3.5rem; text-align: center; max-width: 650px; }
        .btn-premium { background: #3b82f6; color: white; border: none; padding: 14px 40px; border-radius: 12px; font-weight: 700; text-decoration: none; display: inline-block; transition: 0.3s; }
        .btn-premium:hover { background: #2563eb; transform: scale(1.02); }
        .btn-demo { background: transparent; border: 1px solid #475569; color: #94a3b8; padding: 14px 40px; border-radius: 12px; font-weight: 700; text-decoration: none; display: inline-block; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="hero-card shadow-lg">
        <div class="mb-4 text-primary fw-bold">ENTERPRISE SOLUTIONS</div>
        <h1 class="display-4 fw-bold mb-3">SGL PRO</h1>
        <p class="lead text-secondary mb-5">El software definitivo para flotas Flex. Optimiza tus entregas con nuestra tecnología de bypass inteligente.</p>

        <?php if (isset($_SESSION['auth_error'])): ?>
            <div class="alert alert-danger small mb-4"><?php echo htmlspecialchars($_SESSION['auth_error']); unset($_SESSION['auth_error']); ?></div>
        <?php endif; ?>

        <div class="d-grid gap-2">
            <a href="<?php echo htmlspecialchars($config['auth_url'] . "/authorization?response_type=code&client_id={$config['client_id']}&redirect_uri=" . urlencode($config['redirect_uri'])); ?>" class="btn btn-premium">Conectar Cuenta Real</a>
            <a href="?action=demo" class="btn btn-demo">Explorar Demo Gratuita</a>
        </div>
    </div>
</body>
</html>
<?php
}

function renderDashboard($config) {
    $mode = isset($_SESSION['is_demo']) && $_SESSION['is_demo'] ? 'MODO DEMO' : 'CUENTA REAL';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard | SGL PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8fafc; font-family: 'Segoe UI', system-ui; }
        .sidebar { background: #1e293b; color: #fff; min-height: 100vh; padding: 2rem 1.5rem; }
        .card { border-radius: 16px; border: 1px solid #e2e8f0; }
        #console { background: #0f172a; color: #10b981; font-family: monospace; padding: 1.5rem; border-radius: 12px; height: 350px; overflow-y: auto; font-size: 0.85rem; }
        .status-pill { padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; }
        .pill-shipped { background: #e0f2fe; color: #0369a1; }
        .badge-mode { background: <?php echo $_SESSION['is_demo'] ? '#f59e0b' : '#10b981'; ?>; color: #fff; font-size: 0.65rem; padding: 3px 8px; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-lg-2 sidebar">
                <h4 class="fw-bold mb-5">SGL PRO</h4>
                <nav class="nav flex-column gap-2">
                    <a class="nav-link text-white bg-primary rounded-3 px-3 py-2" href="#"><i class="bi bi-grid me-2"></i> Dashboard</a>
                    <a class="nav-link text-secondary px-3 py-2" href="#"><i class="bi bi-truck me-2"></i> Envíos</a>
                    <a class="nav-link text-secondary px-3 py-2" href="#"><i class="bi bi-gear me-2"></i> Ajustes</a>
                    <a class="nav-link text-danger mt-5 px-3 py-2" href="?action=logout"><i class="bi bi-power me-2"></i> Salir</a>
                </nav>
            </div>
            <div class="col-lg-10 p-5">
                <div class="d-flex justify-content-between align-items-center mb-5">
                    <h2 class="fw-bold m-0">Operaciones Flex <span class="badge-mode ms-2"><?php echo $mode; ?></span></h2>
                    <button id="btnBulkClose" class="btn btn-primary btn-lg rounded-3 fw-bold px-4">Cierre Masivo Inteligente</button>
                </div>

                <div class="row">
                    <div class="col-lg-8">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white py-3"><h6 class="m-0 fw-bold">Pedidos en Tránsito</h6></div>
                            <div class="table-responsive">
                                <table class="table align-middle mb-0">
                                    <thead class="bg-light">
                                        <tr>
                                            <th width="40" class="ps-4"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                            <th>ID Envío</th>
                                            <th>Dirección</th>
                                            <th>Estado</th>
                                            <th>Bypass GPS</th>
                                        </tr>
                                    </thead>
                                    <tbody id="orderTable"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <h6 class="fw-bold mb-3">Monitor de Bypass</h6>
                        <div id="console">> Sistema listo para operar.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            loadOrders();
            document.getElementById('selectAll').addEventListener('change', e => {
                document.querySelectorAll('.order-check').forEach(c => c.checked = e.target.checked);
            });
            document.getElementById('btnBulkClose').addEventListener('click', bulkClose);
        });

        function log(msg, type = 'info') {
            const div = document.createElement('div');
            div.style.color = type === 'error' ? '#ef4444' : (type === 'success' ? '#10b981' : '#fff');
            div.textContent = `[${new Date().toLocaleTimeString()}] > ${msg}`;
            const con = document.getElementById('console');
            con.appendChild(div);
            con.scrollTop = con.scrollHeight;
        }

        async function loadOrders() {
            log('Sincronizando órdenes...');
            const res = await fetch('?action=get_orders');
            const orders = await res.json();
            const tbody = document.getElementById('orderTable');
            tbody.innerHTML = '';
            orders.forEach(o => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td class="ps-4"><input type="checkbox" class="order-check form-check-input" value="${o.id}"></td>
                    <td class="fw-bold text-primary">#${o.id}</td>
                    <td class="small text-secondary text-truncate" style="max-width: 200px"></td>
                    <td><span class="status-pill pill-shipped">${o.status.toUpperCase()}</span></td>
                    <td><code class="small text-muted"></code></td>
                `;
                tr.cells[2].textContent = o.destination;
                tr.cells[4].querySelector('code').textContent = `${o.lat.toFixed(4)}, ${o.lon.toFixed(4)}`;
                tbody.appendChild(tr);
            });
            log(`${orders.length} pedidos detectados.`, 'success');
        }

        async function bulkClose() {
            const ids = Array.from(document.querySelectorAll('.order-check:checked')).map(c => c.value);
            if (!ids.length) return;
            if (!confirm('¿Cerrar seleccionados con bypass GPS?')) return;

            const btn = document.getElementById('btnBulkClose');
            btn.disabled = true; btn.textContent = 'PROCESANDO...';
            log(`Iniciando cierre de ${ids.length} pedidos.`);

            try {
                const res = await fetch('?action=bulk_close', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ ids })
                });
                const results = await res.json();
                results.forEach(r => log(r.message, r.success ? 'success' : 'error'));
                loadOrders();
            } finally {
                btn.disabled = false; btn.textContent = 'Cierre Masivo Inteligente';
            }
        }
    </script>
</body>
</html>
<?php
}
?>
