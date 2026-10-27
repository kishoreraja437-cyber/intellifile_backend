<?php

// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/db.php";

/**
 * Returns dynamic base URL for this server (handles localhost, 10.0.2.2 for Android emulators, LAN IPs)
 */
function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? "localhost";
    return $protocol . $host . "/intellifile_backend/";
}

/**
 * Standardized JSON response helper with CORS headers
 */
function sendJsonResponse(int $statusCode, array $data): void {
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-Requested-With");

    echo json_encode($data);
    exit;
}

/**
 * Handles CORS Preflight OPTIONS requests
 */
function handleCorsPreflight(): void {
    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Id, X-Requested-With");
        http_response_code(204);
        exit;
    }
}

/**
 * Reads and decodes JSON input from php://input, falling back to $_POST
 */
function getJsonInput(): array {
    $raw = file_get_contents("php://input");
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return $decoded;
    }
    return is_array($_POST) ? $_POST : [];
}

/**
 * Authenticates user through Session, Header, or Request parameter.
 * Returns valid user ID as integer, or null if unauthenticated.
 */
function getAuthenticatedUserId(mysqli $conn): ?int {
    // 1. Session-based authentication
    if (!empty($_SESSION["user_id"])) {
        return (int) $_SESSION["user_id"];
    }

    // 2. Custom header (e.g. X-User-Id)
    if (!empty($_SERVER["HTTP_X_USER_ID"])) {
        $headerUserId = intval($_SERVER["HTTP_X_USER_ID"]);
        if ($headerUserId > 0) {
            return $headerUserId;
        }
    }

    // 3. Request payload or query parameter
    $input = getJsonInput();
    $userId = intval($input["user_id"] ?? $input["id"] ?? $_GET["user_id"] ?? $_GET["id"] ?? 0);
    if ($userId > 0) {
        return $userId;
    }

    // 4. Lookup by email if email is provided in session or request
    $email = trim($_SESSION["email"] ?? $input["email"] ?? $_GET["email"] ?? "");
    if ($email !== "" && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $res = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($res) {
                return (int) $res["id"];
            }
        }
    }

    return null;
}

/**
 * Enforces authentication: terminates request with 401 if not authenticated
 */
function requireAuth(mysqli $conn): int {
    $userId = getAuthenticatedUserId($conn);
    if (!$userId) {
        sendJsonResponse(401, [
            "success" => false,
            "message" => "Unauthorized. Please log in or provide user_id."
        ]);
    }
    return $userId;
}

