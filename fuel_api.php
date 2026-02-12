<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/api_helper.php';

/**
 * Fuel Price API for Surya Patro
 * Scrapes https://noc.org.np/retailprice
 */

function scrapeFuelPrices() {
    $url = "https://noc.org.np/retailprice";
    // Use shared fetchUrl helper
    $html = fetchUrl($url);

    if (!$html) {
        return ["status" => "error", "message" => "Failed to fetch NOC page"];
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) {
        return ["status" => "error", "message" => "Price table not found"];
    }

    $table = $tables->item(0);
    $rows = $table->getElementsByTagName('tr');
    $history = [];

    // Skip the header row
    for ($i = 1; $i < $rows->length; $i++) {
        $row = $rows->item($i);
        $cells = $row->getElementsByTagName('td');
        if ($cells->length < 7) continue;

        $dateText = trim($cells->item(0)->textContent);

        // Try multiple date formats
        if (preg_match('/\((\d{4}[\.-]\d{2}[\.-]\d{2})\)/', $dateText, $matches)) {
             $adDate = str_replace('.', '-', $matches[1]);
        } elseif (preg_match('/(\d{4}[\.-]\d{2}[\.-]\d{2})/', $dateText, $matches)) {
             $adDate = str_replace('.', '-', $matches[1]);
        } else {
            continue;
        }

        $history[] = [
            "date" => $adDate,
            "prices" => [
                (float)trim($cells->item(2)->textContent), // Petrol
                (float)trim($cells->item(3)->textContent), // Diesel
                (float)trim($cells->item(4)->textContent), // Kerosene
                (float)trim($cells->item(5)->textContent), // LPG
                (float)trim($cells->item(6)->textContent)  // ATF
            ]
        ];
    }

    if (empty($history)) {
        return ["status" => "error", "message" => "No price data extracted"];
    }

    // Sort by date descending
    usort($history, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });

    $current = $history[0];
    $previous = isset($history[1]) ? $history[1] : $history[0];

    $products = [
        ["p" => "Petrol", "n" => "पेट्रोल", "u" => "Litre", "un" => "प्रति लिटर", "idx" => 0],
        ["p" => "Diesel", "n" => "डिजेल", "u" => "Litre", "un" => "प्रति लिटर", "idx" => 1],
        ["p" => "Kerosene", "n" => "मट्टितेल", "u" => "Litre", "un" => "प्रति लिटर", "idx" => 2],
        ["p" => "LPG (Gas)", "n" => "एलपिजी ग्यास", "u" => "Cylinder", "un" => "प्रति सिलिण्डर", "idx" => 3],
        ["p" => "ATF (Aviation Fuel)", "n" => "हवाई इन्धन", "u" => "Litre", "un" => "प्रति लिटर", "idx" => 4]
    ];

    $rates = [];
    foreach ($products as $item) {
        $price = $current['prices'][$item['idx']];
        $prevPrice = $previous['prices'][$item['idx']];

        if ($price > 0) {
            $rates[] = [
                "product" => $item['p'],
                "productNe" => $item['n'],
                "price" => $price,
                "prevPrice" => ($price != $prevPrice) ? $prevPrice : null,
                "unit" => $item['u'],
                "unitNe" => $item['un']
            ];
        }
    }

    return [
        "status" => "true",
        "date" => $current['date'],
        "rates" => $rates
    ];
}

echo json_encode(scrapeFuelPrices(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
