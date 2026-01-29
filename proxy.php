<?php
// proxy.php - A simple CORS proxy

// 1. Allow Access from ANY origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");

// 2. Handle Preflight (Browser checks permission first)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 3. Get the URL to fetch
$url = isset($_GET['url']) ? $_GET['url'] : '';

if (!$url) {
    http_response_code(400);
    echo "Error: Missing ?url= parameter";
    exit;
}

// Security: Basic filter to ensure it's a URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Error: Invalid URL";
    exit;
}

// 4. Initialize CURL to fetch the data
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// Fake a User Agent so sites don't block us
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

// Execute
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Check for errors
if (curl_errno($ch)) {
    http_response_code(500);
    echo 'Curl Error: ' . curl_error($ch);
    exit;
}

// 5. Send the content back to your App
http_response_code($httpCode);

// Forward the content type (so your App knows if it's XML or JSON)
$info = curl_getinfo($ch);
if (isset($info['content_type'])) {
    header('Content-Type: ' . $info['content_type']);
}

echo $response;
curl_close($ch);
?>
