<?php
// proxy.php - A robust CORS proxy (cURL-powered version)

// 1. Allow Access from ANY origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");

// 2. Browser Caching - Mirroring news-proxy behavior (5 minutes)
header("Cache-Control: public, max-age=300");

// 3. Handle Preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/api_helper.php';

// 4. Get the URL to fetch
$url = isset($_GET['url']) ? $_GET['url'] : '';

if (!$url) {
    http_response_code(400);
    echo "Error: Missing ?url= parameter";
    exit;
}

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Error: Invalid URL";
    exit;
}

// 5. Fetch using cURL (via shared helper)
// This is faster and more reliable than file_get_contents
$response = fetchUrl($url);

if ($response === false) {
    http_response_code(500);
    echo "Proxy Error: Fetch failed for $url. Check if the target site is blocking requests.";
    exit;
}

// Note: fetchUrl already handles the User-Agent and Referer headers correctly
echo $response;
?>
