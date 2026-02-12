<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/api_helper.php';

/**
 * Vegetable Price API for Surya Patro
 * Scrapes https://kalimatimarket.gov.np/price
 */

function scrapeVegetablePrices() {
    $dateParam = isset($_GET['date']) ? $_GET['date'] : '';
    $url = "https://kalimatimarket.gov.np/price";
    if ($dateParam) {
        $url .= "?date=" . urlencode($dateParam);
    }

    // Use shared fetchUrl helper
    $html = fetchUrl($url);

    if (!$html) {
        return ["status" => "error", "message" => "Failed to fetch Kalimati page"];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Extract Date
    $date = "";
    // Search for "वि.सं." in text nodes
    $textNodes = $xpath->query("//text()");
    foreach ($textNodes as $node) {
        $text = trim($node->textContent);
        if (str_contains($text, "वि.सं.")) {
            // Match until we hit "कृषि" or long whitespace
            if (preg_match('/(वि\.सं\..*?)(?=कृषि|\n|$)/u', $text, $matches)) {
                $date = trim($matches[1]);
                break;
            }
        }
    }

    if (!$date) $date = date('Y-m-d');

    // Find Price Table
    $tables = $dom->getElementsByTagName('table');
    $targetTable = null;
    foreach ($tables as $table) {
        $text = $table->textContent;
        if ((str_contains($text, "Minimum") && str_contains($text, "Maximum")) ||
            (str_contains($text, "Unit") && str_contains($text, "Average")) ||
            (str_contains($text, "न्यूनतम") && str_contains($text, "अधिकतम"))) {
            $targetTable = $table;
            break;
        }
    }

    $items = [];
    if ($targetTable) {
        $rows = $targetTable->getElementsByTagName('tr');
        foreach ($rows as $index => $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length < 5) continue;

            $name = trim($cells->item(0)->textContent);
            $unit = trim($cells->item(1)->textContent);
            $low = trim($cells->item(2)->textContent);
            $high = trim($cells->item(3)->textContent);
            $avg = trim($cells->item(4)->textContent);

            // Skip header if it contains text like "Vegetable"
            if (!$name || $name === "Vegetable" || str_contains($name, "Minimum") || str_contains($name, "न्यूनतम")) continue;

            $items[] = [
                "id" => "veg-" . $index . "-" . time(),
                "name" => $name,
                "unit" => $unit,
                "low" => $low,
                "high" => $high,
                "avg" => $avg
            ];
        }
    }

    return [
        "status" => "true",
        "date" => $date,
        "items" => $items
    ];
}

echo json_encode(scrapeVegetablePrices(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
