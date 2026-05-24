<?php
/**
 * SGL PRO ENTERPRISE - Sistema de Gestión Logística SaaS
 * Gestión Masiva, Exportación y Auditoría
 *
 * Versión: 3.1 Final (Automatización Masiva y Seguridad Reforzada)
 * Consolidado en un único archivo mercadopago.php
 */

session_start();

// --- PROTECCIÓN DE ACCESO DIRECTO A LA BD ---
if (strpos($_SERVER['REQUEST_URI'], '.db') !== false) {
    header("HTTP/1.1 403 Forbidden");
    exit("Acceso denegado.");
}

// --- CONFIGURACIÓN DE BASE DE DATOS (SQLite) ---
$db_file = 'sgl_pro_pedidos.db';
try {
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS pedidos (
        id_meli INTEGER PRIMARY KEY,
        comprador TEXT,
        direccion TEXT,
        estado TEXT,
        latitud REAL,
        longitud REAL,
        fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("Error en BD: " . $e->getMessage());
}

// --- SEGURIDAD: TOKEN CSRF ---
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- CONFIGURACIÓN ---
if (!isset($_SESSION['configuracion'])) {
    $_SESSION['configuracion'] = [
        'intervalo_defecto' => 30,
        'modo_oscuro' => true
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
    case 'guardar_bd':
        manejarGuardarBD($db);
        break;
    case 'obtener_bd':
        manejarObtenerBD($db);
        break;
    case 'guardar_ajustes':
        manejarGuardarAjustes();
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
    case 'bd':
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

function verificarCSRF($datos) {
    if (!isset($datos['csrf_token']) || $datos['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Error de seguridad CSRF']);
        exit;
    }
}

function manejarGuardarBD($db) {
    header('Content-Type: application/json');
    $datos = json_decode(file_get_contents('php://input'), true);
    verificarCSRF($datos);

    if (!isset($datos['pedidos']) || !is_array($datos['pedidos'])) {
        echo json_encode(['success' => false, 'message' => 'Sin datos']);
        exit;
    }

    $stmt = $db->prepare("INSERT OR REPLACE INTO pedidos (id_meli, comprador, direccion, estado, latitud, longitud) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($datos['pedidos'] as $p) {
        $stmt->execute([$p['id'], $p['comprador'], $p['destino'], $p['estado'], $p['lat'], $p['lon']]);
    }
    echo json_encode(['success' => true, 'message' => count($datos['pedidos']) . ' pedidos registrados en base de datos.']);
    exit;
}

function manejarObtenerBD($db) {
    header('Content-Type: application/json');
    $stmt = $db->query("SELECT * FROM pedidos ORDER BY fecha_registro DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

function manejarGuardarAjustes() {
    header('Content-Type: application/json');
    $datos = json_decode(file_get_contents('php://input'), true);
    verificarCSRF($datos);

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
    if (isset($_SESSION['access_token'])) {
        $cabeceras[] = 'Authorization: Bearer ' . $_SESSION['access_token'];
    }
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $metodo);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $cabeceras);
    if ($datos) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datos));
    }
    $respuesta = curl_exec($ch);
    $estado = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['estado' => $estado, 'datos' => json_decode($respuesta, true)];
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
    verificarCSRF($datos);

    $id = $datos['id'] ?? null;
    $detalle = meli_request('GET', "/shipments/$id", $config);
    if ($detalle['estado'] != 200) {
        echo json_encode(['success' => false, 'message' => "Fallo al obtener pedido"]);
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
    echo json_encode(['id' => $id, 'success' => ($actualizacion['estado'] < 300)]);
    exit;
}

function respuesta_mock_meli($metodo, $ruta, $datos) {
    if (strpos($ruta, '/shipments/search') !== false) {
        return ['estado' => 200, 'datos' => ['results' => array_keys($_SESSION['datos_demo'])]];
    }
    if (preg_match('/\/shipments\/(\d+)/', $ruta, $matches)) {
        $id = (int)$matches[1];
        if ($metodo === 'GET') {
            $dest = $_SESSION['datos_demo'][$id] ?? ['dir' => 'Mock', 'lat' => 0, 'lon' => 0, 'estado' => 'shipped', 'comprador' => 'P'];
            return ['estado' => 200, 'datos' => [
                'id' => $id, 'status' => $dest['estado'], 'receiver_address' => ['address_line' => $dest['dir'], 'latitude' => $dest['lat'], 'longitude' => $dest['lon'], 'receiver_name' => $dest['comprador']]
            ]];
        }
        return ['estado' => 200, 'datos' => ['status' => 'delivered']];
    }
    return ['estado' => 404, 'datos' => []];
}

// --- INTERFACES ---

function renderizarInicio($config) {
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>SGL PRO v3.1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0f172a; color: #fff; height: 100vh; display: flex; align-items: center; justify-content: center; }
        .btn-p { background: #3b82f6; color: white; padding: 12px 40px; border-radius: 12px; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <div class="text-center p-5 bg-dark rounded-4 shadow-lg" style="max-width: 500px">
        <h1 class="fw-bold mb-4">SGL PRO</h1>
        <p class="text-secondary mb-5">Sistema Enterprise de Gestión Logística.<br>Auditoría y Automatización de Cierres.</p>
        <a href="?action=demo" class="btn-p">Entrar al Sistema</a>
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
    <meta charset="UTF-8"><title>SGL PRO v3.1</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background: #f8fafc; font-family: 'Segoe UI', system-ui; }
        .sidebar { background: #1e293b; color: #fff; min-height: 100vh; padding: 2rem 1rem; position: fixed; width: 240px; }
        .main { margin-left: 240px; padding: 3rem; }
        .nav-link { color: #94a3b8; padding: 12px 16px; border-radius: 12px; display: flex; align-items: center; text-decoration: none; margin-bottom: 8px; transition: 0.3s; }
        .nav-link:hover, .nav-link.active { background: #3b82f6; color: #fff; }
        .card { border-radius: 16px; border: none; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        #consola { background: #0f172a; color: #10b981; font-family: monospace; padding: 1rem; border-radius: 12px; height: 350px; overflow-y: auto; font-size: 0.8rem; }
        .status-pill { padding: 4px 12px; border-radius: 20px; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; }
        .pill-open { background: #eff6ff; color: #1d4ed8; }
        .pill-closed { background: #f0fdf4; color: #15803d; }
        .pill-loading { background: #fffbeb; color: #b45309; animation: pulse 1.5s infinite; }
        @keyframes pulse { 50% { opacity: 0.5; } }
    </style>
</head>
<body>
    <input type="hidden" id="csrfToken" value="<?php echo $_SESSION['csrf_token']; ?>">
    <div class="sidebar">
        <h4 class="fw-bold mb-5 px-3 text-primary">SGL PRO</h4>
        <nav class="nav flex-column">
            <a class="nav-link <?php echo $pagina == 'panel' ? 'active' : ''; ?>" href="?action=panel"><i class="bi bi-grid-fill me-3"></i> Dashboard</a>
            <a class="nav-link <?php echo $pagina == 'envios' ? 'active' : ''; ?>" href="?action=envios"><i class="bi bi-truck me-3"></i> Envíos</a>
            <a class="nav-link <?php echo $pagina == 'bd' ? 'active' : ''; ?>" href="?action=bd"><i class="bi bi-database-fill me-3"></i> Base de Datos</a>
            <a class="nav-link <?php echo $pagina == 'ajustes' ? 'active' : ''; ?>" href="?action=ajustes"><i class="bi bi-gear-fill me-3"></i> Ajustes</a>
            <a class="nav-link text-danger mt-5" href="?action=salir"><i class="bi bi-power me-3"></i> Salir</a>
        </nav>
    </div>

    <div class="main">
        <div class="d-flex justify-content-between align-items-center mb-5">
            <div>
                <h2 class="fw-bold m-0"><?php echo ucfirst($pagina == 'panel' ? 'Panel de Control' : ($pagina == 'envios' ? 'Gestión' : ($pagina == 'bd' ? 'Base de Datos' : 'Configuración'))); ?></h2>
                <span class="badge bg-primary-subtle text-primary mt-1"><?php echo $modo; ?></span>
            </div>
            <div class="d-flex gap-2">
                <button onclick="exportarExcel()" class="btn btn-success fw-bold"><i class="bi bi-file-earmark-excel me-2"></i>Excel</button>
                <button onclick="exportarTXT()" class="btn btn-secondary fw-bold"><i class="bi bi-file-earmark-text me-2"></i>TXT</button>
                <button onclick="guardarEnBD()" class="btn btn-dark fw-bold"><i class="bi bi-cloud-arrow-up me-2"></i>Guardar en BD</button>
                <?php if ($pagina == 'panel'): ?>
                <button id="btnBulk" onclick="cierreMasivo()" class="btn btn-primary fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>Cierre Automático</button>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pagina == 'panel' || $pagina == 'envios'): ?>
        <div class="row">
            <div class="col-lg-8">
                <div class="card overflow-hidden">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr>
                                <th width="40" class="ps-4"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                                <th>ID Envío</th>
                                <th>Comprador</th>
                                <th>Estado</th>
                                <?php if ($pagina == 'panel'): ?><th>Acción</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="tablaPedidos"></tbody>
                    </table>
                </div>
            </div>
            <div class="col-lg-4">
                <h6 class="fw-bold mb-3">Monitor de Auditoría</h6>
                <div id="consola">> Listo.</div>
            </div>
        </div>

        <?php elseif ($pagina == 'bd'): ?>
        <div class="card overflow-hidden">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th width="40" class="ps-4"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th>ID MELI</th>
                        <th>Comprador</th>
                        <th>Dirección</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                    </tr>
                </thead>
                <tbody id="tablaBD"></tbody>
            </table>
        </div>

        <?php elseif ($pagina == 'ajustes'): ?>
        <div class="card p-4">
            <h5 class="fw-bold mb-4">Ajustes Generales</h5>
            <form id="formAjustes">
                <div class="mb-3">
                    <label class="form-label fw-bold small">Intervalo entre cierres masivos (segundos)</label>
                    <input type="number" name="intervalo_defecto" class="form-control" value="<?php echo $ajustes['intervalo_defecto']; ?>">
                </div>
                <button type="submit" class="btn btn-primary fw-bold px-4">Guardar Cambios</button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const CSRF = document.getElementById('csrfToken').value;
        const INTERVALO = <?php echo $ajustes['intervalo_defecto']; ?>;
        let pedidosActuales = [];

        async function cargarDatos() {
            const endpoint = '<?php echo $pagina == "bd" ? "?action=obtener_bd" : "?action=obtener_pedidos"; ?>';
            const res = await fetch(endpoint);
            pedidosActuales = await res.json();
            renderTablas();
        }

        function renderTablas() {
            const tbody = document.getElementById('tablaPedidos') || document.getElementById('tablaBD');
            if (!tbody) return;
            tbody.innerHTML = '';

            pedidosActuales.forEach(p => {
                const tr = document.createElement('tr');
                const id = p.id || p.id_meli;
                const status = p.estado;

                tr.innerHTML = `
                    <td class="ps-4"><input type="checkbox" class="check-p form-check-input" value="${id}"></td>
                    <td class="fw-bold text-primary">#${id}</td>
                    <td><div class="small fw-bold">${p.comprador}</div><div class="small text-muted">${p.destino || p.direccion}</div></td>
                    <td><span id="st-${id}" class="status-pill ${status === 'shipped' ? 'pill-open' : 'pill-closed'}">${status === 'shipped' ? 'ABIERTA' : 'CERRADA'}</span></td>
                    ${'<?php echo $pagina; ?>' == 'panel' && status == 'shipped' ? `<td><button onclick="cerrarPed(${id})" class="btn btn-sm btn-outline-primary fw-bold">Cerrar</button></td>` : ''}
                    ${'<?php echo $pagina; ?>' == 'bd' ? `<td><small class="text-muted">${p.fecha_registro}</small></td>` : ''}
                `;
                tbody.appendChild(tr);
            });
        }

        async function procesarCierre(id) {
            const st = document.getElementById(`st-${id}`);
            if (st) { st.className = 'status-pill pill-loading'; st.textContent = 'PROCESANDO...'; }

            const res = await fetch('?action=cerrar_individual', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ id, csrf_token: CSRF })
            });
            const r = await res.json();
            if (r.success) {
                if (st) { st.className = 'status-pill pill-closed'; st.textContent = 'CERRADA'; }
                log(`Envío #${id} CERRADO correctamente.`, 'success');
            } else {
                if (st) { st.className = 'status-pill bg-danger text-white'; st.textContent = 'ERROR'; }
                log(`Error en #${id}: ${r.message}`, 'error');
            }
            return r.success;
        }

        async function cerrarPed(id) {
            log(`Iniciando cierre individual de #${id}...`);
            await procesarCierre(id);
        }

        async function cierreMasivo() {
            const ids = Array.from(document.querySelectorAll('.check-p:checked')).map(c => c.value);
            if (!ids.length) return alert('Seleccione pedidos abiertos.');

            if (!confirm(`Se cerrarán ${ids.length} pedidos secuencialmente. ¿Desea continuar?`)) return;

            const btn = document.getElementById('btnBulk');
            btn.disabled = true;
            log(`Iniciando cierre masivo de ${ids.length} pedidos...`);

            for (let i = 0; i < ids.length; i++) {
                await procesarCierre(ids[i]);
                if (i < ids.length - 1 && INTERVALO > 0) {
                    log(`Espera de seguridad: ${INTERVALO}s...`);
                    await new Promise(r => setTimeout(r, INTERVALO * 1000));
                }
            }
            btn.disabled = false;
            log('Operación masiva finalizada.', 'success');
        }

        async function guardarEnBD() {
            const seleccionados = getSeleccionados();
            if (!seleccionados.length) return alert('Seleccione pedidos.');

            log(`Guardando ${seleccionados.length} registros en BD...`);
            const res = await fetch('?action=guardar_bd', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ pedidos: seleccionados, csrf_token: CSRF })
            });
            const r = await res.json();
            if (r.success) log(r.message, 'success');
            else log(r.message, 'error');
        }

        function getSeleccionados() {
            const checks = document.querySelectorAll('.check-p:checked');
            const ids = Array.from(checks).map(c => c.value);
            return pedidosActuales.filter(p => ids.includes(String(p.id || p.id_meli)));
        }

        function exportarExcel() {
            const items = getSeleccionados();
            if (!items.length) return alert('Seleccione pedidos.');
            let csv = 'ID MELI;COMPRADOR;DIRECCION;ESTADO;LAT;LON\n';
            items.forEach(p => { csv += `${p.id || p.id_meli};${p.comprador};${p.destino || p.direccion};${p.estado};${p.lat || p.latitud};${p.lon || p.longitud}\n`; });
            descargarArchivo(csv, 'sgl_pro_auditoria.csv', 'text/csv');
        }

        function exportarTXT() {
            const items = getSeleccionados();
            if (!items.length) return alert('Seleccione pedidos.');
            let txt = 'REPORTE SGL PRO\n' + '-'.repeat(30) + '\n';
            items.forEach(p => { txt += `#${p.id || p.id_meli} | ${p.comprador} | ${p.estado}\n`; });
            descargarArchivo(txt, 'sgl_pro_auditoria.txt', 'text/plain');
        }

        function descargarArchivo(cont, nom, tipo) {
            const blob = new Blob([cont], { type: tipo });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = nom; a.click();
            URL.revokeObjectURL(url);
        }

        function log(msg, type = 'info') {
            const c = document.getElementById('consola');
            if (!c) return;
            const d = document.createElement('div');
            d.style.color = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#94a3b8');
            d.textContent = `[${new Date().toLocaleTimeString()}] > ${msg}`;
            c.appendChild(d);
            c.scrollTop = c.scrollHeight;
        }

        if (document.getElementById('formAjustes')) {
            document.getElementById('formAjustes').onsubmit = async (e) => {
                e.preventDefault();
                const ajustes = { intervalo_defecto: new FormData(e.target).get('intervalo_defecto') };
                const res = await fetch('?action=guardar_ajustes', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ ajustes, csrf_token: CSRF })
                });
                if ((await res.json()).success) alert('¡Ajustes guardados!');
            };
        }

        document.getElementById('selectAll').addEventListener('change', e => {
            document.querySelectorAll('.check-p').forEach(c => c.checked = e.target.checked);
        });

        cargarDatos();
    </script>
</body>
</html>
<?php
}
?>
