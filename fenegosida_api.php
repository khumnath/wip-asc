<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

/**
 * FENEGOSIDA Official Rate Scraper
 * Federation of Nepal Gold and Silver Dealers Association
 * Provides official Nepal market rates in NPR per Tola
 */

function fetchFENEGOSIDA() {
    $url = "https://www.fenegosida.org/rate-history.php";

    // Use shell curl with full browser headers
    $command = "curl -L -s '$url' " .
        "-H 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' " .
        "-H 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8' " .
        "-H 'Accept-Language: en-US,en;q=0.9' " .
        "-H 'Connection: keep-alive'";

    $html = shell_exec($command);

    if (!$html || strlen($html) < 100 || str_contains($html, 'Not Acceptable')) {
        // Fallback: try with minimal headers
        $html = shell_exec("curl -L -s -A 'Mozilla/5.0' '$url'");
    }

    if (!$html || strlen($html) < 100) {
        return ["error" => "Failed to fetch FENEGOSIDA data"];
    }

    $result = [
        "date" => date('Y-m-d'),
        "current" => [],
        "history" => null
    ];

    // Debug: save HTML for inspection
    // file_put_contents(__DIR__ . '/fenegosida_debug.html', $html);

    // Parse rates from the page
    // Try multiple patterns to catch different formats

    // Pattern 1: Look for "Fine" or "छापावाल" followed by price
    if (preg_match('/(?:Fine|छाप).*?(?:Gold|सुन).*?(\d{1,3},?\d{3,})/iu', $html, $matches)) {
        $price = (float)str_replace(',', '', $matches[1]);
        if ($price >= 100000 && $price <= 200000) {
            $result["current"][] = [
                "name" => "Fine Gold (24K)",
                "nameNe" => "छापावाल सुन (२४ क्यारेट)",
                "unit" => "tola",
                "price" => $price
            ];
        }
    }

    // Pattern 2: Look for Silver/चाँदी
    if (preg_match('/(?:Silver|चाँदी).*?(\d{1,},?\d{3,})/iu', $html, $matches)) {
        $price = (float)str_replace(',', '', $matches[1]);
        if ($price >= 1000 && $price <= 5000) {
            $result["current"][] = [
                "name" => "Silver",
                "nameNe" => "चाँदी",
                "unit" => "tola",
                "price" => $price
            ];
        }
    }

    // Pattern 3: Extract from table cells
    if (count($result["current"]) < 2) {
        preg_match_all('/<td[^>]*>([0-9,]{5,})<\/td>/i', $html, $tableCells);
        if (!empty($tableCells[1])) {
            $prices = array_map(function($p) {
                return (float)str_replace(',', '', $p);
            }, $tableCells[1]);

            $uniquePrices = array_unique($prices);
            foreach ($uniquePrices as $price) {
                // Gold: 100k-200k range
                if ($price >= 100000 && $price <= 200000 && !array_filter($result["current"], fn($r) => $r["name"] === "Fine Gold (24K)")) {
                    $result["current"][] = [
                        "name" => "Fine Gold (24K)",
                        "nameNe" => "छापावाल सुन (२४ क्यारेट)",
                        "unit" => "tola",
                        "price" => $price
                    ];
                }
                // Silver: 1k-5k range
                elseif ($price >= 1000 && $price <= 5000 && !array_filter($result["current"], fn($r) => $r["name"] === "Silver")) {
                    $result["current"][] = [
                        "name" => "Silver",
                        "nameNe" => "चाँदी",
                        "unit" => "tola",
                        "price" => $price
                    ];
                }
            }
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

echo json_encode(fetchFENEGOSIDA(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
