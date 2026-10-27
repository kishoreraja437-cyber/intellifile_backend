<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

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

$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please send valid JSON data."
    ]);
    exit;
}

$email = trim($data["email"] ?? "");
$userId = intval($data["user_id"] ?? $data["id"] ?? 0);
$currentPassword = $data["current_password"] ?? "";
$newPassword = $data["new_password"] ?? "";
$confirmPassword = $data["confirm_password"] ?? "";

if ($email === "" && $userId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide user_id or email."
    ]);
    exit;
}

if ($currentPassword === "" || $newPassword === "" || $confirmPassword === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Current password, new password and confirmation password are all required."
    ]);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password must be at least 8 characters long."
    ]);
    exit;
}

if ($newPassword !== $confirmPassword) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password and confirmation password do not match."
    ]);
    exit;
}

if ($newPassword === $currentPassword) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password cannot be the same as the current password."
    ]);
    exit;
}

try {
    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $conn->prepare("SELECT id, password FROM users WHERE email = ? LIMIT 1");
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
            "message" => "User account not found."
        ]);
        $conn->close();
        exit;
    }

    // Verify current password
    $isMatch = password_verify($currentPassword, $user["password"]) || ($currentPassword === $user["password"]);

    if (!$isMatch) {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Current password is incorrect."
        ]);
        $conn->close();
        exit;
    }

    $targetUserId = (int) $user["id"];
    $hashedNewPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $update->bind_param("si", $hashedNewPassword, $targetUserId);
    $update->execute();
    $update->close();

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Password changed successfully."
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to update password."
    ]);
}

$conn->close();

