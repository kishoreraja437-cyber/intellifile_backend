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
        "message" => "Only POST requests are allowed"
    ]);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_helper.php";

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Invalid request data. Please provide valid JSON."
    ]);
    exit;
}

// Login fields
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate fields
if ($email === "" || $password === "") {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Email and password are required"
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);
    exit;
}

try {
    // Find user by email
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, password, profile_image, created_at
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    // Verify password (supports bcrypt hash and plaintext fallback for initial test seeds)
    $passwordValid = false;
    if ($user) {
        if (password_verify($password, $user["password"])) {
            $passwordValid = true;
        } elseif ($password === $user["password"]) {
            // Upgrade legacy plain-text password to secure hash automatically
            $passwordValid = true;
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $upgradeStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            if ($upgradeStmt) {
                $upgradeStmt->bind_param("si", $newHash, $user["id"]);
                $upgradeStmt->execute();
                $upgradeStmt->close();
            }
        }
    }

    if ($passwordValid) {
        $imageUrl = resolvePublicImageUrl($user["profile_image"] ?? null);

        // Generate secure authentication session token
        $authToken = bin2hex(random_bytes(32));
        $updateTokenStmt = $conn->prepare("UPDATE users SET auth_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = ?");
        if ($updateTokenStmt) {
            $updateTokenStmt->bind_param("si", $authToken, $user["id"]);
            $updateTokenStmt->execute();
            $updateTokenStmt->close();
        }

        // Save authenticated user to session
        $_SESSION["user_id"] = (int) $user["id"];
        $_SESSION["email"] = $user["email"];

        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Login successful",
            "token" => $authToken,
            "user" => [
                "id" => (int) $user["id"],
                "full_name" => $user["full_name"],
                "email" => $user["email"],
                "profile_image" => $imageUrl,
                "profile_image_path" => $user["profile_image"],
                "created_at" => $user["created_at"]
            ]
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password"
        ]);
    }

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());

    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Login failed. Please try again."
    ]);
}

$conn->close();
