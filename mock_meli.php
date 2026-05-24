<?php
/**
 * Mock de la API de Mercado Libre
 * Simula endpoints para pruebas de bypass GPS
 */

$requestUri = $_SERVER['REQUEST_URI'];
$method = $_SERVER['REQUEST_METHOD'];

header('Content-Type: application/json');

// Logs para verificar bypass
$logFile = 'mock_meli.log';

function logRequest($message) {
    global $logFile;
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL, FILE_APPEND);
}

// Endpoint de OAuth
if (strpos($requestUri, '/oauth/token') !== false) {
    echo json_encode([
        'access_token' => 'mock_access_token_123',
        'token_type' => 'bearer',
        'expires_in' => 21600,
        'scope' => 'offline_access read write',
        'refresh_token' => 'mock_refresh_token_456'
    ]);
    exit;
}

// Endpoint de Shipments
if (preg_match('/\/shipments\/(\d+)/', $requestUri, $matches)) {
    $shipmentId = $matches[1];

    if ($method === 'GET') {
        // Devolver datos simulados con coordenadas de destino
        echo json_encode([
            'id' => $shipmentId,
            'status' => 'shipped',
            'receiver_address' => [
                'address_line' => 'Calle Falsa 123',
                'latitude' => -34.6037,
                'longitude' => -58.3816
            ],
            'logistic_type' => 'flex'
        ]);
        exit;
    }

    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true);
        $status = $input['status'] ?? '';
        $location = $input['location'] ?? null;

        if ($status === 'delivered') {
            $lat = $location['latitude'] ?? 'MISSING';
            $lon = $location['longitude'] ?? 'MISSING';
            logRequest("SHIPMENT $shipmentId UPDATED TO DELIVERED. COORDINATES: LAT $lat, LON $lon");

            echo json_encode([
                'id' => $shipmentId,
                'status' => 'delivered',
                'substatus' => 'delivered'
            ]);
        } else {
            echo json_encode(['error' => 'Invalid status']);
        }
        exit;
    }
}

http_response_code(404);
echo json_encode(['error' => 'Endpoint not found']);
