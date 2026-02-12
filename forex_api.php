<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/api_helper.php';

/**
 * Forex API for Surya Patro
 * Proxies and formats NRB Forex rates
 */

function getForexRates() {
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $perPage = $_GET['per_page'] ?? 10;
    $page = $_GET['page'] ?? 1;

    $url = "https://www.nrb.org.np/api/forex/v1/rates?from=$from&to=$to&per_page=$perPage&page=$page";
    // Use shared fetchUrl helper
    $json = fetchUrl($url);

    if (!$json) {
        return ["status" => ["code" => 500], "message" => "Failed to fetch NRB rates"];
    }

    return json_decode($json, true);
}

echo json_encode(getForexRates(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
