<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_helper.php";

$userId = intval($_GET["user_id"] ?? $_GET["id"] ?? 0);
$email = trim($_GET["email"] ?? "");

if ($userId <= 0 && $email === "") {
    $userId = getAuthenticatedUserId($conn) ?? 0;
}

if ($userId <= 0 && $email === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide user_id or email."
    ]);
    exit;
}

try {
    // Ensure table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS user_settings (
          id INT(11) NOT NULL AUTO_INCREMENT,
          user_id INT(11) NOT NULL,
          theme_mode ENUM('LIGHT', 'DARK', 'SYSTEM') DEFAULT 'SYSTEM',
          important_alerts TINYINT(1) DEFAULT 1,
          deadline_reminders TINYINT(1) DEFAULT 1,
          new_doc_alerts TINYINT(1) DEFAULT 1,
          quiet_hours TINYINT(1) DEFAULT 0,
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY user_id_unique (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // Locate user id
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

    // Fetch settings
    $stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    $stmt->close();

    // Default settings fallback
    if (!$settings) {
        $settings = [
            "theme_mode" => "SYSTEM",
            "important_alerts" => 1,
            "deadline_reminders" => 1,
            "new_doc_alerts" => 1,
            "quiet_hours" => 0
        ];
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Settings fetched successfully.",
        "settings" => [
            "user_id" => $userId,
            "theme_mode" => $settings["theme_mode"] ?? "SYSTEM",
            "important_alerts" => (bool) ($settings["important_alerts"] ?? 1),
            "deadline_reminders" => (bool) ($settings["deadline_reminders"] ?? 1),
            "new_doc_alerts" => (bool) ($settings["new_doc_alerts"] ?? 1),
            "quiet_hours" => (bool) ($settings["quiet_hours"] ?? 0)
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch settings."
    ]);
}

$conn->close();

