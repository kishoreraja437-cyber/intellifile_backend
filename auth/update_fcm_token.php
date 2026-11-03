<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_helper.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$fcmToken = trim($data["fcm_token"] ?? $data["token"] ?? "");
$deviceName = trim($data["device_name"] ?? "Android Device");

if ($fcmToken === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "FCM token is required."
    ]);
    exit;
}

$userId = intval($data["user_id"] ?? $data["id"] ?? getAuthenticatedUserId($conn) ?? 0);
$email = strtolower(trim($data["email"] ?? ""));

if ($userId <= 0 && $email === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please authenticate or provide user_id / email."
    ]);
    exit;
}

try {
    // Resolve user ID if email was provided
    if ($userId <= 0) {
        $uStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uStmt->bind_param("s", $email);
        $uStmt->execute();
        $uRes = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();

        if (!$uRes) {
            http_response_code(404);
            echo json_encode([
                "success" => false,
                "message" => "User not found."
            ]);
            $conn->close();
            exit;
        }
        $userId = (int) $uRes["id"];
    }

    // 1. Update users table
    $upStmt = $conn->prepare("UPDATE users SET fcm_token = ?, fcm_updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $upStmt->bind_param("si", $fcmToken, $userId);
    $upStmt->execute();
    $upStmt->close();

    // 2. Insert or update user_fcm_tokens table
    $conn->query("
        CREATE TABLE IF NOT EXISTS user_fcm_tokens (
            id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            fcm_token VARCHAR(500) NOT NULL,
            device_name VARCHAR(100) DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_fcm (user_id, fcm_token(191)),
            KEY idx_fcm_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $tokenStmt = $conn->prepare("
        INSERT INTO user_fcm_tokens (user_id, fcm_token, device_name)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE device_name = VALUES(device_name), updated_at = CURRENT_TIMESTAMP
    ");
    $tokenStmt->bind_param("iss", $userId, $fcmToken, $deviceName);
    $tokenStmt->execute();
    $tokenStmt->close();

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "FCM push notification token registered successfully.",
        "user_id" => $userId
    ]);

} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

