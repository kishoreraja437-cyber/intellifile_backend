<?php

header("Content-Type: application/json");

require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);

    exit;
}

// Read JSON input from Postman
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
$new_password = $data["new_password"] ?? "";
$confirm_password = $data["confirm_password"] ?? "";

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
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

// Check password confirmation
if ($new_password !== $confirm_password) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "New password and confirm password do not match."
    ]);

    exit;
}

// Check whether the email exists
$stmt = $conn->prepare(
    "SELECT id FROM users WHERE email = ? LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed."
    ]);

    exit;
}

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

    exit;
}

// Hash the new password
$hashed_password = password_hash(
    $new_password,
    PASSWORD_DEFAULT
);

// Update password in database
$update = $conn->prepare(
    "UPDATE users SET password = ? WHERE id = ?"
);

if (!$update) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to prepare password update."
    ]);

    exit;
}

$user_id = (int) $user["id"];

$update->bind_param(
    "si",
    $hashed_password,
    $user_id
);

if ($update->execute()) {
    echo json_encode([
        "success" => true,
        "message" => "Password updated successfully."
    ]);
} else {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Password update failed."
    ]);
}

$update->close();
$conn->close();

?>