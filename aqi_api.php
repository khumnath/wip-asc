<?php
/**
 * Nepal Air Quality API - Proxies real-time AQI data from pollution.gov.np
 *
 * Uses Jina AI's browser rendering service to get JavaScript-rendered content.
 * Refactored to use shared helper logic similar to Cloudflare Worker.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: public, max-age=900'); // Cache for 15 minutes

require_once __DIR__ . '/api_helper.php';

// Try to get cached data first (simple file cache for Jina speed)
$cache_file = sys_get_temp_dir() . '/nepal_aqi_cache.json';
$cache_duration = 900; // 15 minutes in seconds

if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_duration) {
    if (isset($_GET['refresh']) && $_GET['refresh'] === 'true') {
        // Force refresh requested, ignore cache
    } else {
        echo file_get_contents($cache_file);
        exit;
    }
}

// Function to convert Devanagari numerals
function toDevanagari($num) {
    if ($num === null || $num === "-") return "-";
    $devanagari = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
    return str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $devanagari, strval($num));
}

// Get AQI category
function get_aqi_category($aqi) {
    if ($aqi === null || $aqi === "-") return ["label" => "Unknown", "labelNp" => "अज्ञात", "color" => "#999999"];
    if ($aqi <= 50) return ["label" => "Good", "labelNp" => "राम्रो", "color" => "#00e400"];
    if ($aqi <= 100) return ["label" => "Moderate", "labelNp" => "मध्यम", "color" => "#ffff00"];
    if ($aqi <= 150) return ["label" => "Unhealthy for Sensitive", "labelNp" => "संवेदनशीलका लागि हानिकारक", "color" => "#ff7e00"];
    if ($aqi <= 200) return ["label" => "Unhealthy", "labelNp" => "अस्वस्थ", "color" => "#ff0000"];
    if ($aqi <= 300) return ["label" => "Very Unhealthy", "labelNp" => "धेरै अस्वस्थ", "color" => "#8f3f97"];
    return ["label" => "Hazardous", "labelNp" => "खतरनाक", "color" => "#7e0023"];
}

// Nepal station name to Nepali mapping
$name_map = [
    "Achaam" => "आछाम",
    "Bhaisipati" => "भैसेपाटी",
    "Bhaktapur" => "भक्तपुर",
    "Bharatpur" => "भरतपुर",
    "Bhimdatta (Mahendranagar)" => "भीमदत्त (महेन्द्रनगर)",
    "Biratnagar" => "बिराटनगर",
    "DHM, Pkr" => "जल तथा मौसम विज्ञान विभाग, पोखरा",
    "Damak" => "दमक",
    "Dang" => "दाङ्ग",
    "Deukhuri, Dang" => "देउखुरी, दाङ",
    "Dhangadhi" => "धनगढी",
    "Dhankuta" => "धनकुटा",
    "Dhulikhel" => "धुलिखेल",
    "GBS, Pkr" => "गण्डकी बोर्डिंग स्कूल, पोखरा",
    "Hetauda" => "हेटौडा",
    "Ilam" => "इलाम",
    "Janakpur" => "जनकपुर",
    "Jhumka" => "झुम्का",
    "Khumaltar" => "खुमाल्टार",
    "Lumbini" => "लुम्बिनी",
    "Mustang" => "मुस्ताङ",
    "Nepalgunj" => "नेपालगन्ज",
    "PU Pkr" => "पीयू/पोखरा विश्वविद्यालय",
    "Pulchowk" => "पुल्चोक",
    "Rara" => "रारा",
    "Ratnapark" => "रत्नपार्क",
    "Sauraha" => "सौराहा",
    "Shankapark" => "शंखपार्क",
    "Simara" => "सिमरा",
    "Surkhet" => "सुर्खेत",
    "TU Kirtipur" => "त्र.वि.वि, कीर्तिपुर",
    "US Embassy" => "अमेरिकी दूतावास"
];

// Fetch and parse AQI data using Jina AI render service
function fetch_aqi_data() {
    // Use Jina AI's rendering service (converts JS-rendered pages to markdown)
    $url = "https://r.jina.ai/https://pollution.gov.np/portal/";
    $content = fetchUrl($url); // Use helper

    if (!$content) {
        return null;
    }

    // Parse the Markdown content for AQI data
    $data = parse_aqi_markdown($content);
    return $data;
}

// Parse Jina AI's Markdown output for AQI values
function parse_aqi_markdown($content) {
    global $name_map;
    $results = [];

    // Find the "Stations (AQI)" section
    if (preg_match('/##### Stations \(AQI\).*?(?=\n#####|\z)/s', $content, $section)) {
        $aqi_section = $section[0];

        // Pattern: "*   StationName\n\nVALUE" where VALUE can be number or "-"
        preg_match_all('/\*\s+([^\n]+)\n\n(\d+|-)\s*\n?/s', $aqi_section, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $name = trim($match[1]);
            $value = trim($match[2]);
            $aqi = is_numeric($value) ? intval($value) : null;

            $category = get_aqi_category($aqi);

            $results[] = [
                "name" => $name,
                "nameNp" => $name_map[$name] ?? $name,
                "aqi" => $aqi,
                "aqiNp" => $aqi !== null ? toDevanagari($aqi) : "-",
                "category" => $category["label"],
                "categoryNp" => $category["labelNp"],
                "color" => $category["color"],
                "status" => $aqi !== null ? "active" : "offline"
            ];
        }
    }

    return $results;
}

// Fetch station metadata from the REST API (for coordinates)
function fetch_station_metadata() {
    global $name_map;

    $url = "https://pollution.gov.np/gss/api/station";
    $response = fetchUrl($url);

    if (!$response) return [];

    $stations = json_decode($response, true);
    if (!$stations) return [];

    $result = [];
    foreach ($stations as $station) {
        $nepali_name = "";
        foreach ($station['meta_data'] ?? [] as $meta) {
            if ($meta['name'] === 'Nepali Name') {
                $nepali_name = $meta['value'] ?? "";
                break;
            }
        }

        // Check if station has PM2.5 sensor
        $has_pm25 = false;
        foreach ($station['data_source'] ?? [] as $ds) {
            foreach ($ds['parameters'] ?? [] as $p) {
                if ($p['parameter_code'] === 'PM2.5_I') {
                    $has_pm25 = true;
                    break 2;
                }
            }
        }

        if ($has_pm25) {
            $result[$station['name']] = [
                "id" => $station['id'],
                "name" => $station['name'],
                "nameNp" => $nepali_name ?: ($name_map[$station['name']] ?? $station['name']),
                "lat" => $station['latitude'],
                "lon" => $station['longitude'],
                "elevation" => $station['elevation'] ?? null
            ];
        }
    }

    return $result;
}

// Main execution
$aqi_data = fetch_aqi_data();
$station_metadata = fetch_station_metadata();

$final_results = [];

if (!empty($aqi_data)) {
    // Merge AQI values with station metadata
    foreach ($aqi_data as $aqi_item) {
        $name = $aqi_item['name'];
        $meta = $station_metadata[$name] ?? null;

        $final_results[] = array_merge($aqi_item, [
            "lat" => $meta['lat'] ?? null,
            "lon" => $meta['lon'] ?? null,
            "elevation" => $meta['elevation'] ?? null
        ]);
    }
} else {
    // Fallback: just return station metadata without AQI values
    foreach ($station_metadata as $name => $meta) {
        $final_results[] = [
            "name" => $name,
            "nameNp" => $meta['nameNp'],
            "aqi" => null,
            "aqiNp" => "-",
            "category" => "Unknown",
            "categoryNp" => "अज्ञात",
            "color" => "#999999",
            "status" => "unknown",
            "lat" => $meta['lat'],
            "lon" => $meta['lon'],
            "elevation" => $meta['elevation']
        ];
    }
}

// Sort by AQI (highest first, nulls at end)
usort($final_results, function($a, $b) {
    if ($a['aqi'] === null && $b['aqi'] === null) return 0;
    if ($a['aqi'] === null) return 1;
    if ($b['aqi'] === null) return -1;
    return $b['aqi'] - $a['aqi'];
});

$response = [
    "status" => "true",
    "stations" => $final_results,
    "source" => "Department of Environment, Nepal",
    "updated" => date('Y-m-d H:i:s'),
    "count" => count($final_results),
    "hasLiveData" => !empty($aqi_data)
];

// Cache logic (Must use sys_get_temp_dir() or similar)
if (!empty($final_results)) {
     file_put_contents($cache_file, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
