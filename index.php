<?php
/**
 * IA Vision - Sistema de Simulación de Revestimientos
 * Versión MVP (Sin MySQL)
 */

// Configuración de visualización de errores (para desarrollo)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Directorios
$upload_dir = 'uploads/';
$results_dir = 'resultados/';

// Asegurar que los directorios existen
if (!file_exists($upload_dir)) mkdir($upload_dir, 0775, true);
if (!file_exists($results_dir)) mkdir($results_dir, 0775, true);

/**
 * Función para llamar a la API de Gemini (Imagen 3 / 2.0 Flash Image Generation)
 */
function callGeminiAPI($image_path, $revestimiento, $estilo, $color, $variantes) {
    // NOTA: En un entorno real, la API KEY debería estar en una variable de entorno
    // Para esta prueba, si no existe la variable, se puede manejar un error o usar una por defecto si se provee.
    $api_key = getenv('GEMINI_API_KEY') ?: 'TU_API_KEY_AQUI';

    $model_id = "gemini-2.0-flash-preview-image-generation";
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model_id}:generateContent?key={$api_key}";

    // Leer la imagen y convertirla a base64
    $image_data = base64_encode(file_get_contents($image_path));
    $mime_type = mime_content_type($image_path);

    // Construir el prompt siguiendo las reglas de IA
    $prompt = "Actúa como un experto en diseño arquitectónico y revestimientos.
    Transforma la imagen adjunta aplicando un revestimiento de '{$revestimiento}' con un estilo '{$estilo}' y un esquema de color predominante '{$color}'.

    REGLAS ESTRICTAS:
    1. Detecta superficies revestibles (paredes, fachadas, muros).
    2. MANTÉN la perspectiva, iluminación, sombras, muebles, puertas, ventanas, arquitectura y distribución original exactamente igual.
    3. SOLO modifica texturas, materiales, acabados y colores de los revestimientos.
    4. El resultado debe parecer una fotografía real de alta calidad, profesional y apta para presentación comercial.
    5. Genera variantes realistas y coherentes.

    IMPORTANTE: No alteres la estructura ni el mobiliario.";

    // Cuerpo de la solicitud
    $payload = [
        "contents" => [
            [
                "parts" => [
                    ["text" => $prompt],
                    [
                        "inline_data" => [
                            "mime_type" => $mime_type,
                            "data" => $image_data
                        ]
                    ]
                ]
            ]
        ],
        "generationConfig" => [
            "responseModalities" => ["TEXT", "IMAGE"],
            "candidateCount" => 1 // Nota: Gemini suele generar 1 candidato por llamada para imágenes actualmente
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new Exception("Error de cURL: " . curl_error($ch));
    }
    curl_close($ch);

    if ($http_code !== 200) {
        $err_data = json_decode($response, true);
        $err_msg = $err_data['error']['message'] ?? "Error desconocido de la API (HTTP $http_code)";
        throw new Exception("Error Gemini API: " . $err_msg);
    }

    return json_decode($response, true);
}

// Procesar solicitud POST (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    try {
        // 1. Validar subida de imagen
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Error al subir la imagen.");
        }

        $image_tmp = $_FILES['image']['tmp_name'];
        $image_name = time() . '_' . basename($_FILES['image']['name']);
        $target_file = $upload_dir . $image_name;

        if (!move_uploaded_file($image_tmp, $target_file)) {
            throw new Exception("No se pudo guardar la imagen subida.");
        }

        // Obtener parámetros del formulario
        $revestimiento = $_POST['revestimiento'] ?? 'Piedra natural';
        $estilo = $_POST['estilo'] ?? 'Moderno';
        $color = $_POST['color'] ?? 'Natural';
        $variantes = min(5, max(1, intval($_POST['variantes'] ?? 3)));

        // 2. Llamar a la API de Gemini (múltiples veces según variantes si es necesario,
        // aunque el MVP pide mostrar variantes, Gemini usualmente genera 1 por vez)
        $results = [];
        for ($i = 1; $i <= $variantes; $i++) {
            $api_response = callGeminiAPI($target_file, $revestimiento, $estilo, $color, $variantes);

            // 3. Procesar respuesta y guardar imagen
            if (isset($api_response['candidates'][0]['content']['parts'])) {
                foreach ($api_response['candidates'][0]['content']['parts'] as $part) {
                    if (isset($part['inline_data'])) {
                        $img_data = base64_decode($part['inline_data']['data']);
                        $result_filename = 'resultado_' . time() . "_{$i}.png";
                        $result_path = $results_dir . $result_filename;
                        file_put_contents($result_path, $img_data);
                        $results[] = $result_path;
                    }
                }
            }
        }

        if (empty($results)) {
            throw new Exception("La IA no generó ninguna imagen en esta respuesta.");
        }

        echo json_encode([
            'success' => true,
            'message' => 'Propuestas generadas con éxito.',
            'original' => $target_file,
            'results' => $results
        ]);
        exit;

    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IA Vision - Simulación de Revestimientos</title>
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text: #1e293b;
            --text-light: #64748b;
            --border: #e2e8f0;
            --radius: 8px;
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        header {
            text-align: center;
            margin-bottom: 40px;
        }

        h1 {
            font-size: 2.5rem;
            color: var(--primary);
            margin-bottom: 10px;
        }

        p.subtitle {
            color: var(--text-light);
            font-size: 1.1rem;
        }

        .main-card {
            background: var(--card-bg);
            padding: 30px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 40px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        label {
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        input[type="text"],
        input[type="number"],
        input[type="file"],
        select {
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            font-size: 1rem;
            outline: none;
            transition: border-color 0.2s;
        }

        input:focus, select:focus {
            border-color: var(--primary);
        }

        .full-width {
            grid-column: 1 / -1;
        }

        button {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 15px 30px;
            font-size: 1.1rem;
            font-weight: bold;
            border-radius: var(--radius);
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
            width: 100%;
        }

        button:hover {
            background-color: var(--primary-hover);
        }

        button:active {
            transform: scale(0.98);
        }

        button:disabled {
            background-color: var(--text-light);
            cursor: not-allowed;
        }

        .gallery-section {
            margin-top: 50px;
        }

        .gallery-title {
            margin-bottom: 20px;
            border-bottom: 2px solid var(--border);
            padding-bottom: 10px;
        }

        .results-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
        }

        .image-card {
            background: white;
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .image-card img {
            width: 100%;
            height: 250px;
            object-fit: cover;
            display: block;
        }

        .image-card .label {
            padding: 10px;
            text-align: center;
            font-weight: 600;
            font-size: 0.9rem;
            background: #f1f5f9;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(255,255,255,0.8);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <p>Generando propuestas con IA... por favor espere.</p>
</div>

<div class="container">
    <header>
        <h1>IA Vision</h1>
        <p class="subtitle">Simulador Inteligente de Revestimientos Arquitectónicos</p>
    </header>

    <div class="main-card">
        <form id="iaForm" enctype="multipart/form-data">
            <div class="form-grid">
                <div class="form-group full-width">
                    <label for="image">Subir Fotografía (Fachada, Pared, Ambiente, etc.)</label>
                    <input type="file" id="image" name="image" accept="image/*" required>
                </div>

                <div class="form-group">
                    <label for="revestimiento">Tipo de Revestimiento</label>
                    <select id="revestimiento" name="revestimiento">
                        <option value="Piedra natural">Piedra natural</option>
                        <option value="Piedra premium">Piedra premium</option>
                        <option value="Piedra moderna">Piedra moderna</option>
                        <option value="Microcemento">Microcemento</option>
                        <option value="Marmol">Mármol</option>
                        <option value="Granito">Granito</option>
                        <option value="Madera">Madera</option>
                        <option value="Ceramica">Cerámica</option>
                        <option value="Porcelanato">Porcelanato</option>
                        <option value="Panel decorativo">Panel decorativo</option>
                        <option value="Industrial">Industrial</option>
                        <option value="Minimalista">Minimalista</option>
                        <option value="Contemporaneo">Contemporáneo</option>
                        <option value="Lujo">Lujo</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="estilo">Estilo</label>
                    <select id="estilo" name="estilo">
                        <option value="Moderno">Moderno</option>
                        <option value="Premium">Premium</option>
                        <option value="Industrial">Industrial</option>
                        <option value="Minimalista">Minimalista</option>
                        <option value="Exclusivo">Exclusivo</option>
                        <option value="Elegante">Elegante</option>
                        <option value="Contemporaneo">Contemporáneo</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="color">Color Predominante</label>
                    <input type="text" id="color" name="color" placeholder="Ej: Gris ceniza, Beige, Blanco perla" value="Natural">
                </div>

                <div class="form-group">
                    <label for="variantes">Cantidad de Variantes (1-5)</label>
                    <input type="number" id="variantes" name="variantes" min="1" max="5" value="3">
                </div>
            </div>

            <button type="submit" id="submitBtn">GENERAR PROPUESTAS</button>
        </form>
    </div>

    <div class="gallery-section" id="gallerySection" style="display: none;">
        <h2 class="gallery-title">Propuestas Generadas</h2>
        <div class="results-grid" id="resultsGrid">
            <!-- Los resultados se cargarán aquí -->
        </div>
    </div>
</div>

<script>
    document.getElementById('iaForm').addEventListener('submit', async function(e) {
        e.preventDefault();

        const form = e.target;
        const formData = new FormData(form);
        const submitBtn = document.getElementById('submitBtn');
        const loadingOverlay = document.getElementById('loadingOverlay');
        const gallerySection = document.getElementById('gallerySection');
        const resultsGrid = document.getElementById('resultsGrid');

        // Reset UI
        submitBtn.disabled = true;
        loadingOverlay.style.display = 'flex';
        gallerySection.style.display = 'none';
        resultsGrid.innerHTML = '';

        try {
            const response = await fetch('index.php', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (data.success) {
                // Mostrar imagen original
                addImageToGrid(data.original, 'Imagen Original');

                // Mostrar variantes
                data.results.forEach((path, index) => {
                    addImageToGrid(path, 'Propuesta IA #' + (index + 1));
                });

                gallerySection.style.display = 'block';
                gallerySection.scrollIntoView({ behavior: 'smooth' });
            } else {
                alert('Error: ' + (data.error || 'Ocurrió un error inesperado.'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Error crítico al procesar la solicitud. Verifique la conexión o la configuración de la API.');
        } finally {
            submitBtn.disabled = false;
            loadingOverlay.style.display = 'none';
        }
    });

    function addImageToGrid(src, label) {
        const resultsGrid = document.getElementById('resultsGrid');

        const card = document.createElement('div');
        card.className = 'image-card';

        const img = document.createElement('img');
        img.src = src + '?t=' + new Date().getTime(); // Anti-cache
        img.alt = label;

        const labelDiv = document.createElement('div');
        labelDiv.className = 'label';
        labelDiv.textContent = label;

        card.appendChild(img);
        card.appendChild(labelDiv);
        resultsGrid.appendChild(card);
    }
</script>

</body>
</html>
