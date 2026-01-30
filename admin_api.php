<?php
/**
 * Surya Patro - Admin API
 * -----------------------
 * Handles CRUD operations for consultation requests.
 */

// 1. Security & CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// 2. Configuration
$requests_dir = __DIR__ . "/requests";
$config_file = __DIR__ . "/admin_config.php";

// Load external config if exists, otherwise use default
if (file_exists($config_file)) {
    include $config_file;
} else {
    $admin_password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // Default: 'password'
}

// 3. Authentication Helper
function is_authorized() {
    global $admin_password_hash;

    // Get headers across different server environments
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }

    $auth = isset($headers['Authorization']) ? $headers['Authorization'] : '';

    // Fallback for specific server configs where Authorization header is stripped
    if (empty($auth) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (strpos($auth, 'Bearer ') === 0) {
        $token = substr($auth, 7);
        return $token === $admin_password_hash;
    }
    return false;
}

// 4. Process Request
$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// 4a. Login Action (No auth required)
if ($method === 'POST' && $action === 'login') {
    $input = json_decode(file_get_contents("php://input"), true);
    $password = isset($input['password']) ? $input['password'] : '';

    if (password_verify($password, $admin_password_hash)) {
        echo json_encode(["success" => true, "token" => $admin_password_hash]);
    } else {
        http_response_code(401);
        echo json_encode(["error" => "Invalid password"]);
    }
    exit;
}

// 4b. Protected Actions (Auth required)
if (!is_authorized()) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

if ($method === 'GET' && $action === 'list') {
    $files = glob($requests_dir . "/*.json");
    $items = [];
    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if ($data) $items[] = $data;
    }
    // Sort by created_at desc
    usort($items, function($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });
    echo json_encode($items);
}
elseif ($method === 'POST' && $action === 'update') {
    $input = json_decode(file_get_contents("php://input"), true);
    $id = isset($input['id']) ? $input['id'] : '';

    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Missing ID"]);
        exit;
    }

    $file_path = $requests_dir . "/" . $id . ".json";
    if (file_exists($file_path)) {
        $existing_data = json_decode(file_get_contents($file_path), true);
        $new_data = array_merge($existing_data, $input);
        file_put_contents($file_path, json_encode($new_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        echo json_encode(["success" => true]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Request not found"]);
    }
}
elseif ($method === 'DELETE' && $action === 'delete') {
    $id = isset($_GET['id']) ? $_GET['id'] : '';
    if (!$id) {
        http_response_code(400);
        echo json_encode(["error" => "Missing ID"]);
        exit;
    }

    $file_path = $requests_dir . "/" . $id . ".json";
    if (file_exists($file_path)) {
        unlink($file_path);
        echo json_encode(["success" => true]);
    } else {
        http_response_code(404);
        echo json_encode(["error" => "Request not found"]);
    }
}
elseif ($method === 'POST' && $action === 'change_password') {
    $input = json_decode(file_get_contents("php://input"), true);
    $new_password = isset($input['new_password']) ? $input['new_password'] : '';

    if (strlen($new_password) < 6) {
        http_response_code(400);
        echo json_encode(["error" => "Password must be at least 6 characters"]);
        exit;
    }

    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
    $config_content = "<?php\n"
                    . "// Surya Patro - Admin Configuration\n"
                    . "// This file is automatically updated by the Admin Panel.\n"
                    . "\$admin_password_hash = '" . $new_hash . "';\n"
                    . "?>";

    if (file_put_contents($config_file, $config_content)) {
        echo json_encode(["success" => true]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Failed to update configuration file"]);
    }
}
else {
    http_response_code(404);
    echo json_encode(["error" => "Unknown action"]);
}
?>
