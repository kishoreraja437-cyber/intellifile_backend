<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);
}

$userId = requireAuth($conn);
$docId = intval($_GET["id"] ?? $_GET["document_id"] ?? 0);

if ($docId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Valid document id is required."
    ]);
}

try {
    $stmt = $conn->prepare("
        SELECT id, user_id, title, format, file_size, file_path, category, location, priority, is_bookmarked, is_saved, total_pages, content_text, created_at, last_accessed 
        FROM documents 
        WHERE id = ? AND user_id = ? 
        LIMIT 1
    ");

    $stmt->bind_param("ii", $docId, $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $doc = $result->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        sendJsonResponse(404, [
            "success" => false,
            "message" => "Document not found or access denied."
        ]);
    }

    // Touch last_accessed
    $conn->query("UPDATE documents SET last_accessed = CURRENT_TIMESTAMP WHERE id = {$docId}");

    $fileUrl = !empty($doc["file_path"]) ? (getBaseUrl() . $doc["file_path"]) : null;

    sendJsonResponse(200, [
        "success" => true,
        "document" => [
            "id" => (int) $doc["id"],
            "fileName" => $doc["title"],
            "fileSize" => $doc["file_size"],
            "fileType" => $doc["format"] . " Document",
            "format" => $doc["format"],
            "fileUrl" => $fileUrl,
            "createdDate" => date("M j, Y", strtotime($doc["created_at"])),
            "modifiedDate" => date("M j, Y", strtotime($doc["last_accessed"])),
            "category" => $doc["category"],
            "location" => $doc["location"],
            "priority" => $doc["priority"],
            "isBookmarked" => (bool) $doc["is_bookmarked"],
            "isSaved" => (bool) $doc["is_saved"],
            "totalPages" => (int) $doc["total_pages"],
            "hasContentText" => !empty($doc["content_text"]),
            "contentText" => $doc["content_text"] ?? "",
            "content_text" => $doc["content_text"] ?? ""
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to retrieve document details."
    ]);
}

