<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Handle preflight requests
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed"
    ]);
    exit;
}

// Connect to MySQL database
require_once __DIR__ . "/../config/db.php";

// Read JSON request body
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON request"
    ]);
    exit;
}

// Get signup fields
$full_name = trim($data["full_name"] ?? $data["name"] ?? "");
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate required fields
if ($full_name === "" || $email === "" || $password === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Full name, email and password are required"
    ]);
    exit;
}

// Validate full name
if (strlen($full_name) > 100) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Full name must not exceed 100 characters"
    ]);
    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);
    exit;
}

// Validate password
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Password must contain at least 8 characters"
    ]);
    exit;
}

// Hash password before storing
$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // Check if email already registered
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $checkStmt->bind_param("s", $email);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();

    if ($checkResult->num_rows > 0) {
        $checkStmt->close();
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "This email is already registered"
        ]);
        $conn->close();
        exit;
    }
    $checkStmt->close();

    // Insert user into database
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)"
    );

    $stmt->bind_param("sss", $full_name, $email, $password_hash);
    $stmt->execute();
    $newUserId = $conn->insert_id;
    $stmt->close();

    // Optionally initialize default settings if user_settings table exists
    try {
        $settingsCheck = $conn->query("SHOW TABLES LIKE 'user_settings'");
        if ($settingsCheck && $settingsCheck->num_rows > 0) {
            $setStmt = $conn->prepare(
                "INSERT IGNORE INTO user_settings (user_id, theme_mode, important_alerts, deadline_reminders, new_doc_alerts, quiet_hours) 
                 VALUES (?, 'SYSTEM', 1, 1, 1, 0)"
            );
            if ($setStmt) {
                $setStmt->bind_param("i", $newUserId);
                $setStmt->execute();
                $setStmt->close();
            }
        }
    } catch (Exception $ignored) {
        // Continue even if settings table not created yet
    }

    // Signup successful
    http_response_code(201);
    echo json_encode([
        "success" => true,
        "message" => "Account created successfully",
        "user" => [
            "id" => (int) $newUserId,
            "full_name" => $full_name,
            "email" => $email,
            "profile_image" => null
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        http_response_code(409);
        echo json_encode([
            "success" => false,
            "message" => "This email is already registered"
        ]);
    } else {
        error_log($e->getMessage());
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Signup failed. Please try again."
        ]);
    }
}

$conn->close();
