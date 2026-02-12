<?php
/**
 * Surya Patro - Consultation Form Backend
 * --------------------------------------
 * Receives JSON data from ParamarshaPage.tsx and sends it to the Admin.
 */

// 1. Security & CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 2. Configuration
$requests_dir = __DIR__ . "/requests";
$premium_users_file = __DIR__ . "/config/premium_users.json";
$admin_email = "admin@example.com";

// 3. Ensure requests directory exists and is protected
if (!file_exists($requests_dir)) {
    mkdir($requests_dir, 0755, true);
    // Create .htaccess to protect data
    file_put_contents($requests_dir . "/.htaccess", "Deny from all");
}

// 4. Process Input
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["error" => "Only POST method allowed"]);
    exit;
}

$raw_input = file_get_contents("php://input");
$payload = json_decode($raw_input, true);

if (!$payload || !isset($payload['contact'])) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid payload"]);
    exit;
}

// 5. Enhance Payload
$id = time() . "_" . bin2hex(random_bytes(4));
$payload['id'] = $id;
$payload['status'] = 'pending';
$payload['created_at'] = date('Y-m-d H:i:s');

// 5a. Determine Category
$is_premium = isset($payload['is_premium']) && $payload['is_premium'] === true;
$is_subscribed = false;

// Check if email/phone matches a subscribed user
if (file_exists($premium_users_file)) {
    $subscribed_users = json_decode(file_get_contents($premium_users_file), true);
    if ($subscribed_users) {
        foreach ($subscribed_users as $user) {
            if ((!empty($payload['contact']['email']) && $user['email'] === $payload['contact']['email']) ||
                (!empty($payload['contact']['phone']) && $user['phone'] === $payload['contact']['phone'])) {
                $is_subscribed = true;
                break;
            }
        }
    }
}

$payload['is_premium'] = $is_premium;
$payload['is_subscribed'] = $is_subscribed;

// 6. Save to File
$file_path = $requests_dir . "/" . $id . ".json";
if (file_put_contents($file_path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))) {
    echo json_encode(["success" => true, "id" => $id, "message" => "Request stored successfully"]);
} else {
    http_response_code(500);
    echo json_encode(["success" => false, "error" => "Failed to save request"]);
}
?>
