<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST or PUT methods are allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_helper.php";

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) {
    $data = $_POST;
}

$userId = intval($data["user_id"] ?? $data["id"] ?? getAuthenticatedUserId($conn) ?? 0);
$email = strtolower(trim($data["email"] ?? ""));

if ($userId <= 0 && $email === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide user_id or email, or authenticate with session token."
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

    // Fetch existing settings
    $curStmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ? LIMIT 1");
    $curStmt->bind_param("i", $userId);
    $curStmt->execute();
    $existing = $curStmt->get_result()->fetch_assoc();
    $curStmt->close();

    $themeMode = strtoupper(trim($data["theme_mode"] ?? $existing["theme_mode"] ?? "SYSTEM"));
    if (!in_array($themeMode, ["LIGHT", "DARK", "SYSTEM"])) {
        $themeMode = "SYSTEM";
    }

    $importantAlerts = isset($data["important_alerts"]) ? ($data["important_alerts"] ? 1 : 0) : intval($existing["important_alerts"] ?? 1);
    $deadlineReminders = isset($data["deadline_reminders"]) ? ($data["deadline_reminders"] ? 1 : 0) : intval($existing["deadline_reminders"] ?? 1);
    $newDocAlerts = isset($data["new_doc_alerts"]) ? ($data["new_doc_alerts"] ? 1 : 0) : intval($existing["new_doc_alerts"] ?? 1);
    $quietHours = isset($data["quiet_hours"]) ? ($data["quiet_hours"] ? 1 : 0) : intval($existing["quiet_hours"] ?? 0);

    // Upsert settings
    $saveStmt = $conn->prepare("
        INSERT INTO user_settings (user_id, theme_mode, important_alerts, deadline_reminders, new_doc_alerts, quiet_hours)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            theme_mode = VALUES(theme_mode),
            important_alerts = VALUES(important_alerts),
            deadline_reminders = VALUES(deadline_reminders),
            new_doc_alerts = VALUES(new_doc_alerts),
            quiet_hours = VALUES(quiet_hours)
    ");

    $saveStmt->bind_param("isiiii", $userId, $themeMode, $importantAlerts, $deadlineReminders, $newDocAlerts, $quietHours);
    $saveStmt->execute();
    $saveStmt->close();

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Settings updated successfully.",
        "settings" => [
            "user_id" => $userId,
            "theme_mode" => $themeMode,
            "important_alerts" => (bool) $importantAlerts,
            "deadline_reminders" => (bool) $deadlineReminders,
            "new_doc_alerts" => (bool) $newDocAlerts,
            "quiet_hours" => (bool) $quietHours
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to save settings."
    ]);
}

$conn->close();

