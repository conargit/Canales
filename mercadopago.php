<?php
/**
 * SGL PRO ENTERPRISE - Sistema de Gestión Logística SaaS
 * Solución de Bypass GPS para Mercado Libre Flex
 *
 * Versión: 2.7 Pro (Control Total de Estados y Filtros Dinámicos)
 * Consolidado en un único archivo mercadopago.php
 */

session_start();

// --- SEGURIDAD: TOKEN CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- CONFIGURACIÓN POR DEFECTO ---
if (!isset($_SESSION['configuracion'])) {
    $_SESSION['configuracion'] = [
        'intervalo_defecto' => 30,
        'sincronizacion_auto' => true,
        'modo_oscuro' => true,
        'precision_bypass' => 'alta'
    ];
}

$config = [
    'nombre_app'     => 'SGL PRO Enterprise',
    'meli_api_url'   => 'https://api.mercadolibre.com',
    'auth_url'       => 'https://auth.mercadolibre.com.ar',
    'client_id'      => getenv('MELI_CLIENT_ID') ?: '848316273415124',
    'client_secret'  => getenv('MELI_CLIENT_SECRET') ?: 'CLAVE_SECRETA',
    'redirect_uri'   => (isset($_SERVER['HTTPS']) ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . strtok($_SERVER["REQUEST_URI"], '?'),
];

// --- ENRUTADOR ---
$accion = $_GET['action'] ?? 'inicio';

switch ($accion) {
    case 'obtener_pedidos':
        manejarObtenerPedidos($config);
        break;
    case 'cerrar_individual':
        manejarCerrarIndividual($config);
        break;
    case 'guardar_ajustes':
        manejarGuardarAjustes();
        break;
    case 'callback':
        manejarCallback($config);
        break;
    case 'demo':
        $_SESSION['es_demo'] = true;
        $_SESSION['access_token'] = 'token_demo_' . time();
        if (!isset($_SESSION['datos_demo'])) {
            $_SESSION['datos_demo'] = [
                5001 => ['dir' => 'Av. Corrientes 1234, CABA', 'lat' => -34.6037, 'lon' => -58.3816, 'estado' => 'shipped', 'comprador' => 'Juan Pérez'],
                5002 => ['dir' => 'Sarmiento 151, CABA', 'lat' => -34.6075, 'lon' => -58.3712, 'estado' => 'shipped', 'comprador' => 'María García'],
                5003 => ['dir' => 'Av. Santa Fe 2500, CABA', 'lat' => -34.5915, 'lon' => -58.4022, 'estado' => 'shipped', 'comprador' => 'Carlos López'],
                5004 => ['dir' => 'Florida 10, CABA', 'lat' => -34.6080, 'lon' => -58.3745, 'estado' => 'shipped', 'comprador' => 'Ana Martínez'],
                5005 => ['dir' => 'Juramento 2100, CABA', 'lat' => -34.5612, 'lon' => -58.4556, 'estado' => 'shipped', 'comprador' => 'Roberto Gómez'],
            ];
        }
        header('Location: mercadopago.php?action=panel');
        exit;
    case 'salir':
        session_destroy();
        header('Location: mercadopago.php');
        exit;
    case 'panel':
    case 'envios':
    case 'ajustes':
        if (!isset($_SESSION['access_token'])) {
            header('Location: mercadopago.php');
            exit;
        }
        renderizarInterfaz($config, $accion);
        break;
    case 'inicio':
    default:
        renderizarInicio($config);
        break;
}

// --- LÓGICA DE NEGOCIO ---

function manejarGuardarAjustes() {
    header('Content-Type: application/json');
    $datos = json_decode(file_get_contents('php://input'), true);
    if (isset($datos['ajustes'])) {
        $_SESSION['configuracion'] = array_merge($_SESSION['configuracion'], $datos['ajustes']);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false]);
    }
    exit;
}

function meli_request($metodo, $ruta, $config, $datos = null) {
    if (isset($_SESSION['es_demo']) && $_SESSION['es_demo']) {
        return respuesta_mock_meli($metodo, $ruta, $datos);
    }
    $url = (strpos($ruta, 'http') === 0) ? $ruta : $config['meli_api_url'] . $ruta;
    $ch = curl_init();
    $cabeceras = ['Content-Type: application/json', 'Accept: application/json'];
    if (isset($_SESSION['access_token']) && strpos($ruta, '/oauth/token') === false) {
        $cabeceras[] = 'Authorization: Bearer ' . $_SESSION['access_token'];
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metodo);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
    if ($datos) {
        if (strpos($ruta, '/oauth/token') !== false) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($datos));
            $cabeceras[0] = 'Content-Type: application/x-www-form-urlencoded';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos));
        }
    }
    $respuesta = curl_exec($ch);
    $estado = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['estado' => $estado, 'datos' => json_decode($respuesta, true)];
}

function manejarCallback($config) {
    if (isset($_GET['code'])) {
        $datosPost = [
            'grant_type'    => 'authorization_code',
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'code'          => $_GET['code'],
            'redirect_uri'  => $config['redirect_uri']
        ];
        $res = meli_request('POST', '/oauth/token', $config, $datosPost);
        if ($res['estado'] == 200 && isset($res['datos']['access_token'])) {
            $_SESSION['access_token'] = $res['datos']['access_token'];
            $_SESSION['es_demo'] = false;
        }
    }
    header('Location: mercadopago.php?action=panel');
    exit;
}

function manejarObtenerPedidos($config) {
    header('Content-Type: application/json');
    if (isset($_SESSION['es_demo']) && $_SESSION['es_demo']) {
        $envios = [];
        foreach ($_SESSION['datos_demo'] as $id => $d) {
            $envios[] = [
                'id' => $id, 'estado' => $d['estado'], 'destino' => $d['dir'],
                'lat' => $d['lat'], 'lon' => $d['lon'], 'comprador' => $d['comprador']
            ];
        }
    } else {
        $resBusqueda = meli_request('GET', '/shipments/search?logistic_type=flex&status=shipped', $config);
        $ids = $resBusqueda['datos']['results'] ?? [];
        $envios = [];
        foreach ($ids as $id) {
            $detalle = meli_request('GET', "/shipments/$id", $config);
            if ($detalle['estado'] == 200) {
                $envios[] = [
                    'id' => $detalle['datos']['id'],
                    'estado' => $detalle['datos']['status'],
                    'destino' => $detalle['datos']['receiver_address']['address_line'] ?? 'Sin dirección',
                    'lat' => $detalle['datos']['receiver_address']['latitude'] ?? 0,
                    'lon' => $detalle['datos']['receiver_address']['longitude'] ?? 0,
                    'comprador' => $detalle['datos']['receiver_address']['receiver_name'] ?? 'Comprador'
                ];
            }
        }
    }
    echo json_encode($envios);
    exit;
}

function manejarCerrarIndividual($config) {
    header('Content-Type: application/json');
    $datos = json_decode(file_get_contents('php://input'), true);
    if (!isset($datos['csrf_token']) || $datos['csrf_token'] !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'message' => 'Error de seguridad (CSRF)']);
        exit;
    }
    $id = $datos['id'] ?? null;
    $detalle = meli_request('GET', "/shipments/$id", $config);
    if ($detalle['estado'] != 200) {
        echo json_encode(['success' => false, 'message' => "Error al obtener detalles de #$id"]);
        exit;
    }
    $lat = $detalle['datos']['receiver_address']['latitude'];
    $lon = $detalle['datos']['receiver_address']['longitude'];
    $actualizacion = meli_request('PUT', "/shipments/$id", $config, [
        'status' => 'delivered', 'substatus' => 'delivered',
        'location' => ['latitude' => $lat, 'longitude' => $lon]
    ]);
    if ($actualizacion['estado'] < 300 && isset($_SESSION['es_demo']) && $_SESSION['es_demo']) {
        $_SESSION['datos_demo'][$id]['estado'] = 'delivered';
    }
    echo json_encode([
        'id' => $id, 'success' => ($actualizacion['estado'] < 300),
        'message' => $actualizacion['estado'] < 300 ? "Bypass GPS exitoso" : "Error en API"
    ]);
    exit;
}

function respuesta_mock_meli($metodo, $ruta, $datos) {
    if (strpos($ruta, '/shipments/search') !== false) {
        $resultados = array_keys($_SESSION['datos_demo']);
        return ['estado' => 200, 'datos' => ['results' => $resultados]];
    }
    if (preg_match('/\/shipments\/(\d+)/', $ruta, $matches)) {
        $id = (int)$matches[1];
        if ($metodo === 'GET') {
            $dest = $_SESSION['datos_demo'][$id] ?? ['dir' => 'Dirección Mock', 'lat' => -34.6, 'lon' => -58.4, 'estado' => 'shipped', 'comprador' => 'Prueba'];
            return ['estado' => 200, 'datos' => [
                'id' => $id, 'status' => $dest['estado'], 'logistic_type' => 'flex',
                'receiver_address' => ['address_line' => $dest['dir'], 'latitude' => $dest['lat'], 'longitude' => $dest['lon'], 'receiver_name' => $dest['comprador']]
            ]];
        }
        if ($metodo === 'PUT') {
            return ['estado' => 200, 'datos' => ['status' => 'delivered']];
        }
    }
    return ['estado' => 404, 'datos' => []];
}

// --- INTERFACES ---

function renderizarInicio($config) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SGL PRO | Inicio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', system-ui; background: #0f172a; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .hero-card { background: rgba(30, 41, 59, 0.7); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.1); border-radius: 24px; padding: 4rem; text-align: center; max-width: 650px; }
        .btn-premium { background: #3b82f6; color: white; border: none; padding: 14px 40px; border-radius: 12px; font-weight: 700; text-decoration: none; display: inline-block; transition: 0.3s; }
        .btn-premium:hover { background: #2563eb; transform: scale(1.02); }
    </style>
</head>
<body>
    <div class="hero-card shadow-lg">
        <h1 class="display-4 fw-bold mb-3">SGL PRO</h1>
        <p class="lead text-secondary mb-5">Gestión logística profesional con automatización de cierres.</p>
        <div class="d-grid gap-2">
            <a href="?action=demo" class="btn btn-premium">Iniciar Sistema (Demo)</a>
        </div>
    </div>
</body>
</html>
<?php
}

function renderizarInterfaz($config, $pagina) {
    $modo = isset($_SESSION['es_demo']) && $_SESSION['es_demo'] ? 'MODO DEMO' : 'CUENTA REAL';
    $ajustes = $_SESSION['configuracion'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($pagina); ?> | SGL PRO</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8fafc; font-family: 'Segoe UI', system-ui; }
        .sidebar { background: #1e293b; color: #fff; min-height: 100vh; padding: 2rem 1rem; position: fixed; width: 240px; }
        .main-content { margin-left: 240px; padding: 3rem; }
        .nav-link { color: #94a3b8; padding: 12px 16px; border-radius: 12px; transition: 0.3s; display: flex; align-items: center; text-decoration: none; margin-bottom: 8px; }
        .nav-link:hover { background: rgba(255,255,255,0.05); color: #fff; }
        .nav-link.active { background: #3b82f6; color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3); }
        .card { border-radius: 16px; border: none; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 24px; }
        #consola { background: #0f172a; color: #10b981; font-family: 'Fira Code', monospace; padding: 1.5rem; border-radius: 12px; height: 350px; overflow-y: auto; font-size: 0.85rem; }
        .status-pill { padding: 4px 12px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; border: 1px solid transparent; }
        .pill-abierta { background: #eff6ff; color: #1d4ed8; border-color: #dbeafe; }
        .pill-cerrada { background: #f0fdf4; color: #15803d; border-color: #dcfce7; }
        .pill-procesando { background: #fffbeb; color: #b45309; border-color: #fef3c7; animation: blinker 1.5s linear infinite; }
        @keyframes blinker { 50% { opacity: 0.4; } }
        .btn-filter { font-size: 0.85rem; font-weight: 600; padding: 6px 16px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; color: #64748b; }
        .btn-filter.active { background: #3b82f6; color: #fff; border-color: #3b82f6; }
    </style>
</head>
<body>
    <input type="hidden" id="csrfToken" value="<?php echo $_SESSION['csrf_token']; ?>">

    <div class="sidebar">
        <div class="px-3 mb-5">
            <h4 class="fw-bold m-0"><i class="bi bi-rocket-takeoff-fill text-primary me-2"></i>SGL PRO</h4>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link <?php echo $pagina == 'panel' ? 'active' : ''; ?>" href="?action=panel"><i class="bi bi-grid-fill me-3"></i> Panel de Control</a>
            <a class="nav-link <?php echo $pagina == 'envios' ? 'active' : ''; ?>" href="?action=envios"><i class="bi bi-truck me-3"></i> Gestión de Envíos</a>
            <a class="nav-link <?php echo $pagina == 'ajustes' ? 'active' : ''; ?>" href="?action=ajustes"><i class="bi bi-gear-fill me-3"></i> Ajustes</a>
            <div style="margin-top: 4rem"></div>
            <a class="nav-link text-danger" href="?action=salir"><i class="bi bi-power me-3"></i> Salir</a>
        </nav>
    </div>

    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold m-0"><?php echo ($pagina == 'panel' ? 'Panel Principal' : ($pagina == 'envios' ? 'Gestión de Operaciones' : 'Configuración')); ?></h2>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle mt-1"><?php echo $modo; ?></span>
            </div>
            <?php if ($pagina == 'panel' || $pagina == 'envios'): ?>
            <div class="d-flex gap-2">
                <select id="selectIntervalo" class="form-select" style="width: 180px; font-size: 0.9rem;">
                    <option value="0" <?php echo $ajustes['intervalo_defecto'] == 0 ? 'selected' : ''; ?>>Cierre Turbo</option>
                    <option value="15" <?php echo $ajustes['intervalo_defecto'] == 15 ? 'selected' : ''; ?>>Espera 15s</option>
                    <option value="30" <?php echo $ajustes['intervalo_defecto'] == 30 ? 'selected' : ''; ?>>Espera 30s</option>
                </select>
                <button id="btnCierreMasivo" class="btn btn-primary fw-bold px-4 shadow-sm" style="font-size: 0.9rem;">Cerrar Seleccionadas</button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($pagina == 'panel'): ?>
        <div class="row">
            <div class="col-lg-8">
                <div class="card p-0 overflow-hidden">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th width="40" class="ps-4"><input type="checkbox" id="seleccionarTodo" class="form-check-input"></th>
                                <th>ID</th>
                                <th>Comprador / Dirección</th>
                                <th>Estado</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody id="tablaPedidos"></tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-4">
                <h6 class="fw-bold mb-3">Monitor en Tiempo Real</h6>
                <div id="consola">> Listo.</div>
            </div>
        </div>

        <?php elseif ($pagina == 'envios'): ?>
        <div class="d-flex gap-2 mb-4">
            <button class="btn-filter active" onclick="filtrarPedidos('todos')">Todos</button>
            <button class="btn-filter" onclick="filtrarPedidos('shipped')">Operaciones ABIERTAS</button>
            <button class="btn-filter" onclick="filtrarPedidos('delivered')">Operaciones CERRADAS</button>
        </div>
        <div class="card shadow-sm p-0 overflow-hidden">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th width="40" class="ps-4"><input type="checkbox" id="seleccionarTodo" class="form-check-input"></th>
                        <th>ID</th>
                        <th>Comprador</th>
                        <th>Dirección de Entrega</th>
                        <th>Estado Logístico</th>
                        <th>Coordenadas</th>
                    </tr>
                </thead>
                <tbody id="tablaHistorial"></tbody>
            </table>
        </div>

        <?php elseif ($pagina == 'ajustes'): ?>
        <div class="row">
            <div class="col-lg-6">
                <div class="card p-4">
                    <h5 class="fw-bold mb-4">Ajustes del Sistema</h5>
                    <form id="formularioAjustes">
                        <div class="mb-4">
                            <label class="form-label fw-bold small">Intervalo entre cierres (segundos)</label>
                            <input type="number" name="intervalo_defecto" class="form-control" value="<?php echo $ajustes['intervalo_defecto']; ?>">
                        </div>
                        <div class="mb-4 form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="sincronizacion_auto" <?php echo $ajustes['sincronizacion_auto'] ? 'checked' : ''; ?>>
                            <label class="form-check-label small fw-bold">Actualizar automáticamente al iniciar</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold py-2">Guardar Cambios</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const TOKEN_CSRF = document.getElementById('csrfToken').value;
        let todosLosPedidos = [];

        document.addEventListener('DOMContentLoaded', () => {
            if (document.getElementById('tablaPedidos') || document.getElementById('tablaHistorial')) cargarPedidos();

            if (document.getElementById('seleccionarTodo')) {
                document.body.addEventListener('change', e => {
                    if (e.target.id === 'seleccionarTodo') {
                        document.querySelectorAll('.pedido-check').forEach(c => c.checked = e.target.checked);
                    }
                });
            }

            if (document.getElementById('btnCierreMasivo')) {
                document.getElementById('btnCierreMasivo').addEventListener('click', cierreMasivo);
            }

            if (document.getElementById('formularioAjustes')) {
                document.getElementById('formularioAjustes').addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const fd = new FormData(e.target);
                    const ajustes = {
                        intervalo_defecto: fd.get('intervalo_defecto'),
                        sincronizacion_auto: fd.get('sincronizacion_auto') === 'on'
                    };
                    const r = await fetch('?action=guardar_ajustes', {
                        method: 'POST',
                        body: JSON.stringify({ ajustes })
                    });
                    if ((await r.json()).success) alert('¡Ajustes guardados!');
                });
            }
        });

        function registrarLog(msg, tipo = 'info') {
            const cons = document.getElementById('consola');
            if (!cons) return;
            const d = document.createElement('div');
            d.style.color = tipo === 'error' ? '#ef4444' : (tipo === 'success' ? '#10b981' : '#94a3b8');
            d.textContent = `[${new Date().toLocaleTimeString()}] > ${msg}`;
            cons.appendChild(d);
            cons.scrollTop = cons.scrollHeight;
        }

        async function cargarPedidos() {
            try {
                const r = await fetch('?action=obtener_pedidos');
                todosLosPedidos = await r.json();
                renderizarTablas(todosLosPedidos);
            } catch (e) {
                registrarLog('Error al conectar con el servidor.', 'error');
            }
        }

        function renderizarTablas(pedidos) {
            const tCuerpo = document.getElementById('tablaPedidos');
            const tHistorial = document.getElementById('tablaHistorial');

            if (tCuerpo) {
                tCuerpo.innerHTML = '';
                pedidos.filter(p => p.estado === 'shipped').forEach(p => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="ps-4"><input type="checkbox" class="pedido-check form-check-input" value="${p.id}"></td>
                        <td class="fw-bold text-primary">#${p.id}</td>
                        <td><div class="small fw-bold">${p.comprador}</div><div class="text-muted small">${p.destino}</div></td>
                        <td><span id="estado-${p.id}" class="status-pill pill-abierta">ABIERTA</span></td>
                        <td><button onclick="cerrarIndividual(${p.id})" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">Cerrar</button></td>
                    `;
                    tCuerpo.appendChild(tr);
                });
            }

            if (tHistorial) {
                tHistorial.innerHTML = '';
                pedidos.forEach(p => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="ps-4"><input type="checkbox" class="pedido-check form-check-input" value="${p.id}" ${p.estado === 'delivered' ? 'disabled' : ''}></td>
                        <td class="fw-bold">#${p.id}</td>
                        <td class="small">${p.comprador}</td>
                        <td class="small text-secondary">${p.destino}</td>
                        <td><span id="estado-h-${p.id}" class="status-pill pill-${p.estado === 'shipped' ? 'abierta' : 'cerrada'}">${p.estado === 'shipped' ? 'ABIERTA' : 'CERRADA'}</span></td>
                        <td><code class="small">${parseFloat(p.lat).toFixed(4)}, ${parseFloat(p.lon).toFixed(4)}</code></td>
                    `;
                    tHistorial.appendChild(tr);
                });
            }
        }

        function filtrarPedidos(filtro) {
            document.querySelectorAll('.btn-filter').forEach(b => b.classList.remove('active'));
            event.target.classList.add('active');
            if (filtro === 'todos') {
                renderizarTablas(todosLosPedidos);
            } else {
                renderizarTablas(todosLosPedidos.filter(p => p.estado === filtro));
            }
        }

        async function cerrarIndividual(id) {
            registrarLog(`Iniciando cierre del envío #${id}...`);
            await procesarCierre(id);
        }

        async function procesarCierre(id) {
            const els = [document.getElementById(`estado-${id}`), document.getElementById(`estado-h-${id}`)];
            els.forEach(el => { if(el) { el.className = 'status-pill pill-procesando'; el.textContent = 'CERRANDO...'; } });

            try {
                const r = await fetch('?action=cerrar_individual', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id, csrf_token: TOKEN_CSRF })
                });
                const resultado = await r.json();
                if (resultado.success) {
                    els.forEach(el => { if(el) { el.className = 'status-pill pill-cerrada'; el.textContent = 'CERRADA'; } });
                    registrarLog(`Envío #${id} CERRADO correctamente.`, 'success');
                    const p = todosLosPedidos.find(x => x.id == id);
                    if (p) p.estado = 'delivered';
                } else {
                    els.forEach(el => { if(el) { el.className = 'status-pill bg-danger text-white'; el.textContent = 'ERROR'; } });
                    registrarLog(`Error en #${id}: ${resultado.message}`, 'error');
                }
            } catch (e) {
                registrarLog(`Fallo crítico en #${id}`, 'error');
            }
        }

        async function cierreMasivo() {
            const ids = Array.from(document.querySelectorAll('.pedido-check:checked:not(:disabled)')).map(c => c.value);
            if (!ids.length) return alert('Seleccione al menos una operación ABIERTA.');
            const intervalo = parseInt(document.getElementById('selectIntervalo').value);

            if (!confirm(`Se ejecutarán ${ids.length} cierres automáticos. ¿Continuar?`)) return;

            const btn = document.getElementById('btnCierreMasivo');
            btn.disabled = true;
            registrarLog(`Iniciando cierre masivo de ${ids.length} operaciones...`);

            for (let i = 0; i < ids.length; i++) {
                await procesarCierre(ids[i]);
                if (i < ids.length - 1 && intervalo > 0) {
                    registrarLog(`Pausa de seguridad: ${intervalo}s...`);
                    await new Promise(res => setTimeout(res, intervalo * 1000));
                }
            }

            btn.disabled = false;
            registrarLog('Proceso masivo finalizado.', 'success');
            setTimeout(() => renderizarTablas(todosLosPedidos), 1000);
        }
    </script>
</body>
</html>
<?php
}
?>
