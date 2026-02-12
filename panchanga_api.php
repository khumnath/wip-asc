<?php
/**
 * Surya Patro - Panchanga API
 * --------------------------
 * Exposes pre-calculated Bikram Sambat and Panchanga data.
 *
 * Usage:
 * - Today: panchanga_api.php
 * - Specific AD Date: panchanga_api.php?date=2024-04-13
 * - Specific BS Date: panchanga_api.php?bs_date=2081-01-01
 */

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// --- API Key Validation ---
$config_path = __DIR__ . "/config/overrides.json";
$config = file_exists($config_path) ? json_decode(file_get_contents($config_path), true) : [];
$valid_keys = $config['apiKeys'] ?? [];

$provided_key = $_GET['key'] ?? '';

if (!empty($valid_keys) && !in_array($provided_key, $valid_keys)) {
    http_response_code(403);
    echo json_encode([
        "error" => "Forbidden: Invalid or missing API key.",
        "message" => "Please provide a valid subscription key using the 'key' parameter."
    ], JSON_PRETTY_PRINT);
    exit;
}
// --------------------------

$data_dir = __DIR__ . "/api/panchanga";

// 1. Determine Target Date
$date_str = isset($_GET['date']) ? $_GET['date'] : (isset($_GET['ad_date']) ? $_GET['ad_date'] : '');
$bs_date_str = isset($_GET['bs_date']) ? $_GET['bs_date'] : (isset($_GET['bs']) ? $_GET['bs'] : '');

$target_ad = '';
$target_bs = '';

if ($date_str === 'today' || $bs_date_str === 'today') {
    $target_ad = date('Y-m-d');
} elseif ($date_str) {
    // Validate AD date format (YYYY-MM-DD)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_str)) {
        $target_ad = $date_str;
    }
} elseif ($bs_date_str) {
    // Validate BS date format (YYYY-MM-DD)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bs_date_str)) {
        $target_bs = $bs_date_str;
    }
} else {
    // Default to today (server time, adjust as needed)
    $target_ad = date('Y-m-d');
}

// 2. Identify Year and Load Data
// If we have target_ad, we need to find which BS year it belongs to.
// Since BS years skip slightly, we check the current AD year - 56 to + 58.
$search_years = [];
if ($target_bs) {
    $search_years[] = explode('-', $target_bs)[0];
} else {
    // Try current and surrounding BS years
    $ad_year = (int)explode('-', $target_ad)[0];
    $search_years[] = $ad_year + 56;
    $search_years[] = $ad_year + 57;
    $search_years[] = $ad_year + 58;
}

$found_item = null;

foreach ($search_years as $year) {
    $file_path = "$data_dir/$year.json";
    if (file_exists($file_path)) {
        $data = json_decode(file_get_contents($file_path), true);
        if ($data) {
            foreach ($data as $item) {
                if ($target_ad && $item['ad_date'] === $target_ad) {
                    $found_item = $item;
                    break 2;
                }
                if ($target_bs && $item['bs_date'] === $target_bs) {
                    $found_item = $item;
                    break 2;
                }
            }
        }
    }
}

// 3. Return Result
if ($found_item) {
    echo json_encode($found_item, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(404);
    echo json_encode([
        "error" => "Data not found for requested date",
        "requested_ad" => $target_ad,
        "requested_bs" => $target_bs
    ]);
}
