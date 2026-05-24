<?php
/**
 * Sistema de Gestión Logística (SGL) - Bypass MELI PRO
 * Todo en uno: API + Frontend + OAuth (Consolidado)
 */

// --- CONFIGURACIÓN ---
// En producción, estas variables deben venir de un archivo .env o variables de entorno
$config = [
    'meli_api_url'   => 'https://api.mercadolibre.com', // Cambiar a http://localhost:8081 para pruebas
    'client_id'      => getenv('MELI_CLIENT_ID') ?: 'TU_CLIENT_ID',
    'client_secret'  => getenv('MELI_CLIENT_SECRET') ?: 'TU_CLIENT_SECRET',
    'redirect_uri'   => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[PHP_SELF]?action=callback",
    'seller_id'      => getenv('MELI_SELLER_ID') ?: 'TU_SELLER_ID',
];

session_start();

// --- ROUTER ---
$action = $_GET['action'] ?? 'dashboard';

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
    case 'logout':
        session_destroy();
        header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
        exit;
    case 'dashboard':
    default:
        renderDashboard($config);
        break;
}

// --- LÓGICA DE INTEGRACIÓN Y API ---

function meli_request($method, $path, $config, $data = null) {
    $url = $config['meli_api_url'] . $path;
    $ch = curl_init();

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    if (isset($_SESSION['access_token'])) {
        $headers[] = 'Authorization: Bearer ' . $_SESSION['access_token'];
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['status' => $status, 'data' => json_decode($response, true)];
}

function handleGetOrders($config) {
    header('Content-Type: application/json');

    if (!isset($_SESSION['access_token'])) {
        // Mock data para visualización sin conexión
        echo json_encode([
            ['id' => 41000001, 'status' => 'shipped', 'destination' => 'Calle Falsa 123', 'lat' => -34.6037, 'lon' => -58.3816],
            ['id' => 41000002, 'status' => 'shipped', 'destination' => 'Av. Siempre Viva 742', 'lat' => -34.6175, 'lon' => -58.4452],
        ]);
        exit;
    }

    // Búsqueda real de envíos Flex pendientes
    // Paso 1: Buscar IDs de envíos
    $searchPath = "/shipments/search?seller_id={$config['seller_id']}&logistic_type=flex&status=shipped";
    $searchRes = meli_request('GET', $searchPath, $config);

    $shipments = [];
    $ids = $searchRes['data']['results'] ?? [];

    // Paso 2: Obtener detalles (incluyendo coordenadas) para cada envío
    foreach ($ids as $id) {
        $detailRes = meli_request('GET', "/shipments/$id", $config);
        if ($detailRes['status'] == 200) {
            $data = $detailRes['data'];
            $shipments[] = [
                'id' => $data['id'],
                'status' => $data['status'],
                'destination' => $data['receiver_address']['address_line'] ?? 'Dirección desconocida',
                'lat' => $data['receiver_address']['latitude'] ?? null,
                'lon' => $data['receiver_address']['longitude'] ?? null,
            ];
        }
    }

    echo json_encode($shipments);
    exit;
}

function handleBulkClose($config) {
    header('Content-Type: application/json');
    if (!isset($_SESSION['access_token'])) {
        echo json_encode([['success' => false, 'message' => 'No autorizado. Conecte su cuenta de Mercado Libre.']]);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $shipmentIds = $data['ids'] ?? [];
    $results = [];

    foreach ($shipmentIds as $id) {
        // 1. Obtener coordenadas de destino para el bypass
        $resDetails = meli_request('GET', "/shipments/$id", $config);
        if ($resDetails['status'] != 200) {
            $results[] = ['id' => $id, 'success' => false, 'message' => "Error al obtener detalles de #$id"];
            continue;
        }

        $lat = $resDetails['data']['receiver_address']['latitude'] ?? null;
        $lon = $resDetails['data']['receiver_address']['longitude'] ?? null;

        if (!$lat || !$lon) {
            $results[] = ['id' => $id, 'success' => false, 'message' => "Envío #$id no tiene coordenadas de destino"];
            continue;
        }

        // 2. Ejecutar actualización con Bypass de GPS
        $updateData = [
            'status' => 'delivered',
            'substatus' => 'delivered',
            'location' => [
                'latitude' => $lat,
                'longitude' => $lon
            ]
        ];

        $resUpdate = meli_request('PUT', "/shipments/$id", $config, $updateData);

        $results[] = [
            'id' => $id,
            'success' => ($resUpdate['status'] >= 200 && $resUpdate['status'] < 300),
            'message' => $resUpdate['status'] < 300 ? "Entregado con bypass GPS ($lat, $lon)" : "Error en API MELI para #$id"
        ];

        // Simular tiempo de recorrido/entrega aleatorio para eludir algoritmos de detección
        usleep(rand(1200000, 3000000));
    }

    echo json_encode($results);
    exit;
}

function handleCallback($config) {
    if (isset($_GET['code'])) {
        // Intercambio real de CODE por TOKEN
        $postData = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code'          => $_GET['code'],
            'redirect_uri'  => $config['redirect_uri']
        ];

        // Simulado para desarrollo, en producción descomentar llamada real:
        // $res = meli_request('POST', '/oauth/token', $config, $postData);
        // $_SESSION['access_token'] = $res['data']['access_token'];

        $_SESSION['access_token'] = 'mock_token_' . time();
    }
    header('Location: ' . strtok($_SERVER["REQUEST_URI"], '?'));
    exit;
}

// --- INTERFAZ (FRONTEND) ---

function renderDashboard($config) {
    $authUrl = "https://auth.mercadolibre.com.ar/authorization?response_type=code&client_id={$config['client_id']}&redirect_uri=" . urlencode($config['redirect_uri']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGL PRO - Logística Bypass GPS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .navbar { background-color: #fff159; border-bottom: 1px solid #e6e6e6; }
        .navbar-brand { color: #333 !important; font-weight: 700; }
        .card { border: none; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.12); margin-bottom: 20px; }
        .status-badge { padding: 5px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; }
        .status-shipped { background-color: #e3f2fd; color: #1976d2; }
        .status-delivered { background-color: #e8f5e9; color: #2e7d32; }
        .btn-meli { background-color: #3483fa; color: white; border: none; font-weight: 600; padding: 10px 20px; }
        .btn-meli:hover { background-color: #2968c8; color: white; }
        .coordinate-label { font-family: 'Courier New', Courier, monospace; font-size: 0.8rem; background: #eef; padding: 3px 6px; border-radius: 4px; border: 1px solid #d0d0ff; }
        #logConsole { background: #1a1c1e; color: #51ff00; font-family: 'Consolas', monospace; padding: 15px; border-radius: 6px; height: 180px; overflow-y: auto; font-size: 0.8rem; box-shadow: inset 0 0 10px #000; }
        .table thead th { font-size: 0.8rem; color: #666; text-transform: uppercase; letter-spacing: 0.5px; }
    </style>
</head>
<body>
    <nav class="navbar navbar-light mb-4 sticky-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="#">
                <img src="https://http2.mlstatic.com/frontend-assets/ui-navigation/5.18.9/mercadolibre/logo__large_plus.png" height="30" class="me-2" alt="MELI">
                <span class="d-none d-sm-inline">SGL Logistics PRO</span>
            </a>
            <div class="d-flex align-items-center">
                <?php if (!isset($_SESSION['access_token'])): ?>
                    <a href="<?php echo $authUrl; ?>" class="btn btn-outline-dark btn-sm fw-bold">CONECTAR CUENTA</a>
                <?php else: ?>
                    <div class="text-end me-3">
                        <div class="small fw-bold text-success">● SISTEMA SINCRONIZADO</div>
                        <div class="text-muted" style="font-size: 0.7rem;">Modo Bypass GPS Activo</div>
                    </div>
                    <a href="?action=logout" class="btn btn-light btn-sm">Salir</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row">
            <div class="col-lg-9">
                <div class="card p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="fw-bold m-0">Órdenes Activas Envíos Flex</h5>
                        <div>
                            <button onclick="loadOrders()" class="btn btn-light btn-sm border me-2">Refrescar Lista</button>
                            <button id="btnBulkClose" class="btn btn-meli shadow-sm">EJECUTAR CIERRE MASIVO</button>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th width="30"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                    <th>ID de Envío</th>
                                    <th>Punto de Entrega</th>
                                    <th>Estado</th>
                                    <th>Coordenadas Destino</th>
                                    <th class="text-end">Acción</th>
                                </tr>
                            </thead>
                            <tbody id="orderTable">
                                <tr><td colspan="6" class="text-center p-5 text-muted">Buscando envíos en camino...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="col-lg-3">
                <div class="card p-3 bg-white">
                    <h6 class="fw-bold border-bottom pb-2 mb-3">Configuración de Bypass</h6>
                    <div class="mb-3">
                        <label class="form-label small fw-bold">Intervalo de Seguridad</label>
                        <select class="form-select form-select-sm" id="delaySelect">
                            <option value="rand">Aleatorio (1.2s - 3.0s)</option>
                            <option value="slow">Humano (5.0s - 10.0s)</option>
                            <option value="fast">Rápido (0.8s - 1.5s)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" checked disabled>
                            <label class="form-check-label small fw-bold">Bypass GPS Inyectado</label>
                        </div>
                    </div>
                    <div class="alert alert-warning py-2 small mb-0">
                        <strong>Nota:</strong> Se inyectará la ubicación exacta del cliente para eludir las restricciones de MELI.
                    </div>
                </div>

                <h6 class="fw-bold mb-2 d-flex justify-content-between align-items-center">
                    Monitor Operativo
                    <span class="badge bg-dark" style="font-size: 0.6rem;">LIVE</span>
                </h6>
                <div id="logConsole">
                    > Terminal inicializada...<br>
                    > Esperando autenticación...
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de Resultados -->
    <div class="modal fade" id="resultModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title">Resultado de Cierre Masivo</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="resultBody">
                    <!-- Dinámico -->
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            loadOrders();
            document.getElementById('selectAll').addEventListener('change', (e) => {
                document.querySelectorAll('.order-check').forEach(c => c.checked = e.target.checked);
            });
            document.getElementById('btnBulkClose').addEventListener('click', bulkClose);
        });

        function log(msg, type = 'info') {
            const console = document.getElementById('logConsole');
            const color = type === 'error' ? '#ff4d4d' : (type === 'success' ? '#51ff00' : '#888');
            const time = new Date().toLocaleTimeString();
            console.innerHTML += `<br><span style="color: ${color}">[${time}] > ${msg}</span>`;
            console.scrollTop = console.scrollHeight;
        }

        async function loadOrders() {
            try {
                const res = await fetch('?action=get_orders');
                const orders = await res.json();
                const tbody = document.getElementById('orderTable');
                tbody.innerHTML = '';

                if (orders.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center p-4">No se encontraron envíos Flex pendientes.</td></tr>';
                    return;
                }

                orders.forEach(o => {
                    const statusClass = o.status === 'delivered' ? 'status-delivered' : 'status-shipped';
                    tbody.innerHTML += `
                        <tr>
                            <td><input type="checkbox" class="order-check form-check-input" value="${o.id}"></td>
                            <td class="fw-bold text-primary">#${o.id}</td>
                            <td class="small text-truncate" style="max-width: 250px;">${o.destination}</td>
                            <td><span class="status-badge ${statusClass}">${o.status}</span></td>
                            <td><span class="coordinate-label">${o.lat || '---'}, ${o.lon || '---'}</span></td>
                            <td class="text-end">
                                <button class="btn btn-outline-dark btn-sm" onclick="closeOrder(${o.id})">Cerrar</button>
                            </td>
                        </tr>
                    `;
                });
                log(`Sincronización completa. ${orders.length} pedidos detectados.`, 'success');
            } catch (e) {
                log('Error de conexión con el servidor.', 'error');
            }
        }

        async function bulkClose() {
            const checks = document.querySelectorAll('.order-check:checked');
            const ids = Array.from(checks).map(c => c.value);

            if (ids.length === 0) return alert('Por favor, seleccione al menos una orden de la lista.');

            if (!confirm(`Se procederá al cierre masivo de ${ids.length} pedidos.\n\nADVERTENCIA: Se usará bypass de coordenadas GPS.`)) return;

            const btn = document.getElementById('btnBulkClose');
            btn.disabled = true;
            btn.innerText = 'PROCESANDO...';
            log(`Ejecutando lote de ${ids.length} cierres...`, 'info');

            try {
                const res = await fetch('?action=bulk_close', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids })
                });
                const results = await res.json();

                let summary = '<ul class="list-group">';
                results.forEach(r => {
                    log(r.message, r.success ? 'success' : 'error');
                    summary += `<li class="list-group-item d-flex justify-content-between">
                        <span>Envío #${r.id}</span>
                        <span class="badge bg-${r.success ? 'success' : 'danger'}">${r.success ? 'EXITOSO' : 'FALLIDO'}</span>
                    </li>`;
                });
                summary += '</ul>';

                document.getElementById('resultBody').innerHTML = summary;
                new bootstrap.Modal(document.getElementById('resultModal')).show();

                loadOrders();
            } catch (e) {
                log('Error crítico en el proceso masivo.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerText = 'EJECUTAR CIERRE MASIVO';
            }
        }

        async function closeOrder(id) {
            if(!confirm(`¿Cerrar pedido #${id} individualmente con bypass?`)) return;
            log(`Iniciando bypass individual para #${id}...`);
            const res = await fetch('?action=bulk_close', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: [id] })
            });
            const results = await res.json();
            log(results[0].message, results[0].success ? 'success' : 'error');
            loadOrders();
        }
    </script>
</body>
</html>
<?php
}
?>
