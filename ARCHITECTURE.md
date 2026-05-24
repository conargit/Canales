# Arquitectura del Sistema de Gestión Logística (SGL) - Versión PHP Compacta

## Resumen
El sistema está consolidado en un único archivo `index.php` para facilitar su despliegue y uso. Maneja la lógica de servidor (API) y la interfaz de usuario (Frontend) en el mismo punto de entrada.

## Componentes (Consolidados en index.php)

### 1. Lógica de Servidor (PHP)
- **Router**: Sistema simple que detecta si la petición es para una acción de API (ej: `?action=get_orders`) o para cargar la UI.
- **Meli Integration**: Funciones `get_meli_token()`, `get_shipment_details()` y `update_shipment_status()`.
- **Bypass Logic**: Extracción de coordenadas de destino del comprador e inyección en la actualización de estado a `delivered`.

### 2. Frontend (HTML/JS/CSS)
- Interfaz basada en Bootstrap para visualización de órdenes.
- Lógica en JavaScript (Vanilla JS) para manejar peticiones asíncronas (`fetch`) al mismo `index.php`.

### 3. Simulación (mock_meli.php)
- Archivo separado para simular la API de Mercado Libre durante el desarrollo y pruebas.

## Flujo de Trabajo del Bypass
1. El usuario selecciona órdenes y pulsa "Cierre Masivo".
2. El sistema (PHP) pide los detalles de cada orden a la API de MELI.
3. PHP extrae `latitude` y `longitude` del campo `receiver_address`.
4. PHP envía una petición `PUT` a MELI marcando como `delivered` e incluyendo las coordenadas extraídas en el objeto de localización de la entrega.
