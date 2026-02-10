<?php
// proxy.php - A simple CORS proxy (CURL-free version)

// 1. Allow Access from ANY origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: *");

// 2. Handle Preflight
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

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Error: Invalid URL";
    exit;
}

$startTime = microtime(true);

// 4. Fetch using file_get_contents
$urlOrigin = parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST) . "/";
$headers = [
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
    "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8",
    "Accept-Language: en-US,en;q=0.9",
    "Referer: " . $urlOrigin,
    "Connection: close"
];

$options = [
    "http" => [
        "method" => "GET",
        "header" => implode("\r\n", $headers) . "\r\n",
        "follow_location" => 1,
        "timeout" => 30, // Increased to match client
        "ignore_errors" => true
    ]
];

$context = stream_context_create($options);

if (!ini_get('allow_url_fopen')) {
    http_response_code(500);
    echo "Error: PHP 'allow_url_fopen' is disabled.";
    exit;
}

$response = @file_get_contents($url, false, $context);
$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);

if ($response === false) {
    $error = error_get_last();
    http_response_code(500);
    echo "Proxy Error: Fetch failed for $url after {$duration}s. " . ($error['message'] ?? '');
    exit;
}

// 5. Forward the status and content type
if (isset($http_response_header)) {
    foreach ($http_response_header as $header) {
        if (strpos(strtolower($header), 'content-type:') === 0) {
            header($header);
        }
        if (strpos(strtolower($header), 'http/') === 0) {
            // Extract status code: HTTP/1.1 200 OK -> 200
            $parts = explode(' ', $header);
            if (isset($parts[1])) {
                http_response_code((int)$parts[1]);
            }
        }
    }
}

echo $response;
?>
