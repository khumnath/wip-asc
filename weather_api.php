<?php
/**
 * Nepal Weather API
 * Fetches data from DHT (Department of Hydrology and Meteorology)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$PLACES_MAP = [
    'Dadeldhura' => 'डढेलधुरा', 'Dipayal' => 'दिपाएल', 'Dhangadi' => 'धनगढी', 'Birendranagar' => 'बिरेन्द्रनगर',
    'Nepalgunj' => 'नेपालगञ्ज', 'Jumla' => 'जुम्ला', 'Dang' => 'दाङ', 'Pokhara' => 'पोखरा',
    'Bhairahawa' => 'भैरहवा', 'Simara' => 'सिमारा', 'Kathmandu' => 'काठमाडौं', 'Okhaldhunga' => 'ओखल्ढुङ्गा',
    'Taplejung' => 'ताप्लेजुङ', 'Dhankuta' => 'धनकुटा', 'Biratnagar' => 'बिराटनगर', 'Jomsom' => 'जोम्सोम',
    'Dharan' => 'धरान', 'Lumle' => 'लुम्ले', 'Janakpur' => 'जनकपुर', 'Jiri' => 'जिरी',
    'Ghorahi' => 'घोराही', 'Chandragadi Airport' => 'चन्द्रगढी विमानस्थल', 'Dhangadhi' => 'धनगढी'
];

function toDevanagari($number) {
    if ($number === null || $number === "") return "-";
    if (!is_numeric($number)) return $number;
    $eng = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $nep = ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९'];
    return str_replace($eng, $nep, (string)$number);
}

function fetch_weather($lang = 'en', $date = null, $type = 'observation', $offset = 1) {
    global $PLACES_MAP;

    $is_today = empty($date) || $date === date('Y-m-d');

    $api_url = "https://dhm.gov.np/mfd/api/manual-observation";
    if ($type === 'forecast') {
        $api_url = "https://dhm.gov.np/mfd/api/weather";
    } else if (!$is_today) {
        $api_url = "https://dhm.gov.np/mfd/api/report?date=" . urlencode($date);
    }

    $opts = [
        "http" => ["header" => "User-Agent: Mozilla/5.0\r\n", "timeout" => 10],
        "ssl" => ["verify_peer" => false, "verify_peer_name" => false]
    ];
    $context = stream_context_create($opts);
    $response = @file_get_contents($api_url, false, $context);

    if (!$response) return ["status" => "false", "msg" => "Failed to fetch data"];

    $data = json_decode($response, true);
    if (!$data) return ["status" => "false", "msg" => "Invalid response"];

    $stations = [];
    $issue_date = $data['issue_date'] ?? $data['datetime'] ?? null;
    $actual_type = $type;

    if (!$is_today && $type === 'observation') {
        if (isset($data['manual_observation']) && count($data['manual_observation']) > 0) {
            $obs = $data['manual_observation'][0];
            $stations = $obs['stations'] ?? [];
            $issue_date = $obs['issue_date'] ?? $issue_date;
        }
    } else {
        $stations = $data['stations'] ?? [];
    }

    $weather_list = [];
    foreach ($stations as $station) {
        $name = $station['name'] ?? $station['nepali_name'] ?? "";
        $place = ($lang === 'np' && isset($PLACES_MAP[$name])) ? $PLACES_MAP[$name] : ($station['nepali_name'] ?? $name);

        if ($type === 'forecast') {
            $forecast = null;
            if (isset($station['manual_forecast'])) {
                foreach ($station['manual_forecast'] as $f) {
                    if ($f['day'] == $offset) {
                        $forecast = $f;
                        break;
                    }
                }
            }

            if ($forecast) {
                $prob = $forecast['rain_probability'] ?? null;
                $rain_status = "-";
                if ($prob === 0) {
                    $rain_status = "हुने छैन";
                } else if ($prob > 0 && $prob <= 30) {
                    $rain_status = "हल्का वर्षाको सम्भावना";
                } else if ($prob > 30) {
                    $rain_status = "हुनेछ (" . toDevanagari($prob) . "%)";
                }

                $weather_list[] = [
                    "place" => $place,
                    "placeEn" => $name,
                    "tempRange" => toDevanagari($forecast['from_temperature'] ?? '-') . " - " . toDevanagari($forecast['to_temperature'] ?? '-') . "°C",
                    "conditionNp" => $forecast['weather']['nepali_name'] ?? "-",
                    "rainProb" => $rain_status,
                    "type" => "forecast",
                    "status" => "true"
                ];
            }
        } else {
            $max = $station['max_temperature'];
            $min = $station['min_temperature'];
            $rain = $station['rainfall'];

            $rain_str = "-";
            if ($rain === "Traces") {
                $rain_str = "फाटफुट";
            } else if ($rain === 0 || $rain === "0") {
                $rain_str = (!$is_today) ? "भएन" : "हुदैन";
            } else if ($rain !== null && $rain !== "") {
                $rVal = (float)$rain;
                $intensity = "";
                if ($rVal > 0 && $rVal <= 2) $intensity = " (हल्का)";
                else if ($rVal > 2 && $rVal <= 10) $intensity = " (मध्यम)";
                else if ($rVal > 10) $intensity = " (भारी)";

                $rain_str = toDevanagari($rain) . " mm" . $intensity;
            }

            $weather_list[] = [
                "place" => $place,
                "placeEn" => $name,
                "max" => ($max !== null) ? toDevanagari($max) . "°C" : "-",
                "min" => ($min !== null) ? toDevanagari($min) . "°C" : "-",
                "rain" => $rain_str,
                "type" => "observation",
                "status" => "true"
            ];
        }
    }

    // FALLBACK: If Today's observation is empty, try fetch Forecast (offset 1)
    if ($is_today && $type === 'observation' && empty($weather_list)) {
        return fetch_weather($lang, null, 'forecast', 1);
    }

    return [
        "status" => "true",
        "weather" => $weather_list,
        "issue_date" => $issue_date,
        "type" => $actual_type
    ];
}

$lang = isset($_GET['placenp']) ? 'np' : 'en';
$date = $_GET['date'] ?? null;
$type = $_GET['type'] ?? 'observation';
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 1;
echo json_encode(fetch_weather($lang, $date, $type, $offset), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
