<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/**
 * GoldNepal.com Scraper for Surya Patro
 * Fetches current gold/silver rates and historical trend data.
 */

function fetchContent($url) {
    // PHP curl extension might be missing, so use shell curl which we verified works
    $url = escapeshellarg($url);
    $ua = escapeshellarg("Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36");
    $command = "curl -L -s -A $ua $url";
    return shell_exec($command);
}

function scrapeGoldNepal() {
    $url = "https://goldnepal.com/";
    $html = fetchContent($url);

    if (!$html) {
        return ["error" => "Failed to fetch content from GoldNepal"];
    }

    // DEBUG: Save HTML to examine it
    // file_put_contents(__DIR__ . '/gold_debug.html', $html);

    $result = [
        "date" => date('Y-m-d'),
        "dateNe" => date('Y-m-d'),
        "current" => [],
        "history" => null
    ];

    // Clean HTML to make regex more stable
    $cleanText = strip_tags($html);
    $cleanText = preg_replace('/\s+/', ' ', $cleanText);

    // 1. Extract Current Rates using Text Patterns (more stable than table parsing)
    // 24K Gold (Fine)
    if (preg_match('/24K\s*Gold\s*per\s*tola.*?Rs\.\s*([0-9,]+)/i', $cleanText, $matches)) {
        $price = (float)str_replace(',', '', $matches[1]);
        $result["current"][] = ["name" => "Fine Gold (24K)", "nameNe" => "छापावाल सुन (२४ क्यारेट)", "unit" => "tola", "price" => $price];
    }

    // 22K Gold (Tejabi)
    if (preg_match('/22\s*carat\s*\(22k\)\s*gold\s*rate\s*per\s*tola.*?Rs\.\s*([0-9,]+)/i', $cleanText, $matches)) {
        $price = (float)str_replace(',', '', $matches[1]);
        $result["current"][] = ["name" => "Tejabi Gold (22K)", "nameNe" => "तेजाबी सुन (२२ क्यारेट)", "unit" => "tola", "price" => $price];
    }

    // Silver
    if (preg_match('/1\s*Tola\s*silver\s*price.*?Rs\.\s*([0-9,]+)/i', $cleanText, $matches)) {
        $price = (float)str_replace(',', '', $matches[1]);
        $result["current"][] = ["name" => "Silver", "nameNe" => "चाँदी", "unit" => "tola", "price" => $price];
    }

    // Fallback: If text patterns fail, try the table regex which also worked for history
    if (empty($result["current"])) {
        if (preg_match('/24K<\/td>\s*<td[^>]*>([0-9,]+)<\/td>/i', $html, $matches)) {
            $price = (float)str_replace(',', '', $matches[1]);
            $result["current"][] = ["name" => "Fine Gold (24K)", "nameNe" => "छापावाल सुन (२४ क्यारेट)", "unit" => "tola", "price" => $price];
        }
    }

    // 2. Extract History Data from Script
    if (preg_match('/const\s+data\s*=\s*(\{.*?"labels":\[.*?\].*?\});/s', $html, $matches)) {
        $historyData = json_decode($matches[1], true);
        if ($historyData && isset($historyData["labels"])) {
            $gold = $historyData["goldPrices"] ?? ($historyData["datasets"][0]["data"] ?? []);
            $silver = $historyData["silverPrices"] ?? ($historyData["datasets"][1]["data"] ?? []);

            // Convert strings like "91800.00" to floats if necessary
            $gold = array_map(function($v) { return (float)str_replace(',', '', $v); }, $gold);
            $silver = array_map(function($v) { return (float)str_replace(',', '', $v); }, $silver);

            $result["history"] = [
                "labels" => $historyData["labels"],
                "gold" => $gold,
                "silver" => $silver
            ];
        }
    }

    // Calculate 10g rates
    $currentWith10g = [];
    foreach ($result["current"] as $rate) {
        $currentWith10g[] = $rate;
        $price10g = round($rate["price"] / 1.16638, 2);
        $currentWith10g[] = [
            "name" => $rate["name"],
            "nameNe" => $rate["nameNe"],
            "unit" => "10g",
            "price" => $price10g
        ];
    }
    $result["current"] = $currentWith10g;

    return $result;
}

echo json_encode(scrapeGoldNepal(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
