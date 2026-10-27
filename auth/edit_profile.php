<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// Allow PUT and POST requests
if ($_SERVER["REQUEST_METHOD"] !== "PUT" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only PUT or POST methods are allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";

// Read JSON request body
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please send valid JSON data."
    ]);
    exit;
}

// User identifier: user_id OR current_email
$userId = intval($data["user_id"] ?? $data["id"] ?? 0);
$currentEmail = trim($data["current_email"] ?? "");

// Updated profile values
$fullName = trim($data["full_name"] ?? $data["name"] ?? "");
$newEmail = trim($data["email"] ?? "");

if ($userId <= 0 && $currentEmail === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide user_id or current_email to identify the account."
    ]);
    exit;
}

if ($fullName === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Full name cannot be empty."
    ]);
    exit;
}

if ($newEmail !== "" && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide a valid email address."
    ]);
    exit;
}

try {
    // Locate the user record first
    if ($userId > 0) {
        $findStmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE id = ? LIMIT 1");
        $findStmt->bind_param("i", $userId);
    } else {
        $findStmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE email = ? LIMIT 1");
        $findStmt->bind_param("s", $currentEmail);
    }

    $findStmt->execute();
    $findResult = $findStmt->get_result();
    $user = $findResult->fetch_assoc();
    $findStmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found. Please verify your user ID or email."
        ]);
        $conn->close();
        exit;
    }

    $targetUserId = (int) $user["id"];
    // If no new email provided, keep existing
    $targetEmail = ($newEmail !== "") ? $newEmail : $user["email"];

    // Check if the updated email is already taken by another user
    if ($targetEmail !== $user["email"]) {
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
        $check->bind_param("si", $targetEmail, $targetUserId);
        $check->execute();
        $dupResult = $check->get_result();

        if ($dupResult->num_rows > 0) {
            $check->close();
            http_response_code(409);
            echo json_encode([
                "success" => false,
                "message" => "This email address is already registered to another account."
            ]);
            $conn->close();
            exit;
        }
        $check->close();
    }

    // Update user record
    $updateStmt = $conn->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ?");
    $updateStmt->bind_param("ssi", $fullName, $targetEmail, $targetUserId);
    $updateStmt->execute();
    $updateStmt->close();

    // Prepare profile image URL
    $imageUrl = null;
    if (!empty($user["profile_image"])) {
        if (filter_var($user["profile_image"], FILTER_VALIDATE_URL)) {
            $imageUrl = $user["profile_image"];
        } else {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'] ?? "localhost";
            $imageUrl = $protocol . $host . "/intellifile_backend/" . ltrim($user["profile_image"], "/");
        }
    }

    http_response_code(200);
    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully.",
        "profile" => [
            "id" => $targetUserId,
            "full_name" => $fullName,
            "email" => $targetEmail,
            "profile_image" => $imageUrl,
            "profile_image_path" => $user["profile_image"]
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Failed to update profile. Please try again."
    ]);
}

$conn->close();
