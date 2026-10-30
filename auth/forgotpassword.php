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

// Read JSON input from Postman or client
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON request."
    ]);
    exit;
}

$email = trim($data["email"] ?? "");
$new_password = $data["new_password"] ?? $data["password"] ?? "";
$confirm_password = $data["confirm_password"] ?? $new_password;

// Validate email
if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address."
    ]);
    exit;
}

// Validate password
if (strlen($new_password) < 8) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Password must contain at least 8 characters."
    ]);
    exit;
}

if ($new_password !== $confirm_password) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "New password and confirm password do not match."
    ]);
    exit;
}

try {
    // Check whether the email exists (case-insensitive)
    $stmt = $conn->prepare(
        "SELECT id FROM users WHERE LOWER(TRIM(email)) = LOWER(?) LIMIT 1"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Email address not found."
        ]);
        $conn->close();
        exit;
    }

    // Hash the new password
    $hashed_password = password_hash(
        $new_password,
        PASSWORD_DEFAULT
    );

    $user_id = (int) $user["id"];

    // Update password in database
    $update = $conn->prepare(
        "UPDATE users SET password = ? WHERE id = ?"
    );

    $update->bind_param("si", $hashed_password, $user_id);

    if ($update->execute()) {
        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Password updated successfully."
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Password update failed. Please try again."
        ]);
    }

    $update->close();

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred while updating the password."
    ]);
}

$conn->close();
