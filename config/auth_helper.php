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
 * Authenticates user through secure Session-Token / Bearer Token.
 * Validates ownership and ensures unauthorized users cannot access resources.
 * Returns valid user ID as integer, or null if unauthenticated.
 */
function getAuthenticatedUserId(mysqli $conn): ?int {
    $allHeaders = function_exists("getallheaders") ? getallheaders() : [];

    // 1. Check Authorization: Bearer <token>
    $authHeader = $allHeaders["Authorization"] ?? $allHeaders["authorization"] ?? $_SERVER["HTTP_AUTHORIZATION"] ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? "";
    if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
        $token = trim($matches[1]);
        if (!empty($token)) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE auth_token = ? AND (token_expires_at IS NULL OR token_expires_at > NOW()) LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $token);
                $stmt->execute();
                $res = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($res) {
                    $authenticatedId = (int) $res["id"];

                    // Validate ownership: If client also explicitly passed X-User-Id or user_id in payload, ensure it matches!
                    $requestedId = 0;
                    $xUserId = $allHeaders["X-User-Id"] ?? $allHeaders["x-user-id"] ?? $_SERVER["HTTP_X_USER_ID"] ?? "";
                    if (!empty($xUserId)) {
                        $requestedId = intval($xUserId);
                    } else {
                        $input = getJsonInput();
                        $requestedId = intval($input["user_id"] ?? $input["id"] ?? $_GET["user_id"] ?? $_GET["id"] ?? 0);
                    }

                    if ($requestedId > 0 && $requestedId !== $authenticatedId) {
                        sendJsonResponse(403, [
                            "success" => false,
                            "message" => "Forbidden: You do not have permission to access another user's resources."
                        ]);
                    }

                    return $authenticatedId;
                }
            }
        }
    }

    // 2. Session-based authentication
    if (!empty($_SESSION["user_id"])) {
        return (int) $_SESSION["user_id"];
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

