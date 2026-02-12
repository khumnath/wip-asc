<?php
// Shared Helper for PHP APIs (Mirrors Cloudflare Worker Logic)

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

// Handle Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Standard Fetch Function (Mirrors news-proxy worker)
function fetchUrl($url, $customHeaders = []) {
    if (!$url) return false;

    $ch = curl_init($url);

    // Cloudflare Worker Headers
    $headers = array_merge([
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language: en-US,en;q=0.9",
        "Referer: " . (parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST)) . "/"
    ], $customHeaders);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Match worker behavior
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_ENCODING, ''); // Handle limits

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) return false;
    return $response;
}

// Parallel Fetch (for aggregators like news)
function fetchUrlsParallel($urls) {
    if (empty($urls)) return [];

    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($urls as $key => $url) {
        $ch = curl_init($url);
        $headers = [
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36",
            "Referer: " . (parse_url($url, PHP_URL_SCHEME) . "://" . parse_url($url, PHP_URL_HOST)) . "/"
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, '');

        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh);
    } while ($running > 0);

    foreach ($handles as $key => $ch) {
        $results[$key] = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    curl_multi_close($mh);
    return $results;
}

/**
 * Validates, loads `overrides.json`, and returns the target URL(s) for a category.
 */
function getCategoryFeeds($category) {
    if (!file_exists(__DIR__ . '/config/overrides.json')) return [];

    $json = file_get_contents(__DIR__ . '/config/overrides.json');
    $config = json_decode($json, true);

    return $config['newsFeeds'][$category] ?? [];
}
