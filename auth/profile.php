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

// Accept lookup via email or user_id / id, falling back to authenticated session or Bearer token
$email = trim($_GET["email"] ?? "");
$userId = intval($_GET["user_id"] ?? $_GET["id"] ?? 0);

if ($userId <= 0 && $email === "") {
    $userId = getAuthenticatedUserId($conn) ?? 0;
}

if ($email === "" && $userId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide a user email or user_id."
    ]);
    exit;
}

if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide a valid email address."
    ]);
    exit;
}

try {
    if ($userId > 0) {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, profile_image, created_at
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $conn->prepare(
            "SELECT id, full_name, email, profile_image, created_at
             FROM users
             WHERE email = ?
             LIMIT 1"
        );
        $stmt->bind_param("s", $email);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if ($user) {
        $imageUrl = resolvePublicImageUrl($user["profile_image"] ?? null);

        http_response_code(200);
        $userData = [
            "id" => (int) $user["id"],
            "full_name" => $user["full_name"],
            "email" => $user["email"],
            "profile_image" => $imageUrl,
            "profile_image_path" => $user["profile_image"],
            "created_at" => $user["created_at"]
        ];
        echo json_encode([
            "success" => true,
            "message" => "Profile fetched successfully.",
            "user" => $userData,
            "profile" => $userData
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found."
        ]);
    }

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to fetch user profile."
    ]);
}

$conn->close();
