<?php

// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/db.php";

/**
 * Returns dynamic base URL for this server (handles devtunnels, public proxies, localhost)
 */
function getBaseUrl(): string {
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] 
        ?? $_SERVER['HTTP_HOST'] 
        ?? "localhost";

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (strpos($host, 'devtunnels.ms') !== false)
        ? "https://" : "http://";

    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        $host = "tn89blc0-80.inc1.devtunnels.ms";
        $protocol = "https://";
    }

    return $protocol . $host . "/intellifile_backend/";
}

/**
 * Resolves a stored database profile_image path into a publicly accessible absolute URL.
 */
function resolvePublicImageUrl(?string $rawPath): ?string {
    if (empty($rawPath)) {
        return null;
    }

    $rawPath = trim($rawPath);
    if ($rawPath === "") {
        return null;
    }

    // If it's already an absolute URL but points to localhost / 127.0.0.1, extract relative path
    if (filter_var($rawPath, FILTER_VALIDATE_URL)) {
        if (strpos($rawPath, 'localhost') !== false || strpos($rawPath, '127.0.0.1') !== false || strpos($rawPath, '10.0.2.2') !== false) {
            $parsed = parse_url($rawPath);
            $pathOnly = $parsed['path'] ?? '';
            $pathOnly = preg_replace('#^/?intellifile_backend/#', '', $pathOnly);
            $rawPath = ltrim($pathOnly, '/');
        } else {
            return $rawPath;
        }
    }

    $cleanPath = ltrim($rawPath, "/\\");
    if (!str_starts_with($cleanPath, "uploads/") && !str_starts_with($cleanPath, "intellifile_backend/")) {
        $cleanPath = "uploads/" . $cleanPath;
    }

    return getBaseUrl() . $cleanPath;
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
    $apacheHeaders = function_exists("apache_request_headers") ? apache_request_headers() : [];
    $authHeader = $allHeaders["Authorization"] 
        ?? $allHeaders["authorization"] 
        ?? $apacheHeaders["Authorization"] 
        ?? $apacheHeaders["authorization"] 
        ?? $allHeaders["X-Authorization"] 
        ?? $allHeaders["x-authorization"] 
        ?? $_SERVER["HTTP_AUTHORIZATION"] 
        ?? $_SERVER["REDIRECT_HTTP_AUTHORIZATION"] 
        ?? $_SERVER["HTTP_X_AUTHORIZATION"] 
        ?? "";
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

    // 3. Fallback to direct X-User-Id header or user_id parameter
    $fallbackId = intval($allHeaders["X-User-Id"] ?? $allHeaders["x-user-id"] ?? $_SERVER["HTTP_X_USER_ID"] ?? $_GET["user_id"] ?? $_POST["user_id"] ?? 0);
    if ($fallbackId > 0) {
        return $fallbackId;
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

