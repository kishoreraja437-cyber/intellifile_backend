<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST or DELETE methods are allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";

// Read JSON input or POST form data
$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);

if (!is_array($data)) {
    $data = $_POST;
}

$email = trim($data["email"] ?? "");
$userId = intval($data["user_id"] ?? $data["id"] ?? 0);

if ($email === "" && $userId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide user_id or email."
    ]);
    exit;
}

try {
    // Locate the user
    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found."
        ]);
        $conn->close();
        exit;
    }

    $targetUserId = (int) $user["id"];
    $currentImagePath = $user["profile_image"];

    // Update database to NULL
    $updateStmt = $conn->prepare("UPDATE users SET profile_image = NULL WHERE id = ?");
    $updateStmt->bind_param("i", $targetUserId);
    $updateStmt->execute();
    $updateStmt->close();

    // Delete existing file from disk if present
    if (!empty($currentImagePath)) {
        $fullPath = __DIR__ . "/../" . ltrim($currentImagePath, "/\\");
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Profile photo removed successfully.",
        "profile_image" => null
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to remove profile photo."
    ]);
}

$conn->close();

