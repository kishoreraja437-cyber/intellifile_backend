<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);
}

$userId = requireAuth($conn);
$input = getJsonInput();

$docId = intval($input["document_id"] ?? $input["id"] ?? 0);
$recipientName = trim($input["recipient_name"] ?? "");
$recipientEmail = trim($input["recipient_email"] ?? "");
$permission = trim($input["permission"] ?? "View Only");

if (!in_array($permission, ["View Only", "Can Edit", "Full Access"])) {
    $permission = "View Only";
}

if ($docId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Valid document_id is required."
    ]);
}

try {
    // Ensure document_shares table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS `document_shares` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `document_id` int(11) NOT NULL,
          `shared_by_user_id` int(11) NOT NULL,
          `recipient_name` varchar(100) DEFAULT NULL,
          `recipient_email` varchar(150) DEFAULT NULL,
          `permission` enum('View Only','Can Edit','Full Access') NOT NULL DEFAULT 'View Only',
          `share_token` varchar(64) NOT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          UNIQUE KEY `share_token` (`share_token`),
          KEY `idx_share_doc` (`document_id`),
          KEY `idx_share_user` (`shared_by_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    // Verify document exists and belongs to user
    $check = $conn->prepare("SELECT id, title, file_size, format FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
    $check->bind_param("ii", $docId, $userId);
    $check->execute();
    $doc = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$doc) {
        sendJsonResponse(404, [
            "success" => false,
            "message" => "Document not found or access denied."
        ]);
    }

    $shareToken = bin2hex(random_bytes(24));
    $shareUrl = getBaseUrl() . "documents/detail.php?share_token=" . $shareToken;

    $stmt = $conn->prepare("
        INSERT INTO document_shares (document_id, shared_by_user_id, recipient_name, recipient_email, permission, share_token)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iissss", $docId, $userId, $recipientName, $recipientEmail, $permission, $shareToken);
    $stmt->execute();
    $shareId = $conn->insert_id;
    $stmt->close();

    sendJsonResponse(201, [
        "success" => true,
        "message" => "Document share link generated successfully.",
        "share" => [
            "id" => $shareId,
            "document_id" => $docId,
            "document_title" => $doc["title"],
            "recipient_name" => $recipientName,
            "recipient_email" => $recipientEmail,
            "permission" => $permission,
            "share_token" => $shareToken,
            "share_url" => $shareUrl
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to share document."
    ]);
}

