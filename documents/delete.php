<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST or DELETE methods are allowed."
    ]);
}

$userId = requireAuth($conn);
$input = getJsonInput();

$docId = intval($input["document_id"] ?? $input["id"] ?? $_GET["id"] ?? 0);

if ($docId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Valid document_id is required."
    ]);
}

try {
    $stmt = $conn->prepare("SELECT id, title, file_path FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $docId, $userId);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        sendJsonResponse(404, [
            "success" => false,
            "message" => "Document not found or access denied."
        ]);
    }

    // Delete record from database
    $delete = $conn->prepare("DELETE FROM documents WHERE id = ? AND user_id = ?");
    $delete->bind_param("ii", $docId, $userId);
    $delete->execute();
    $delete->close();

    // Delete physical file if present
    if (!empty($doc["file_path"])) {
        $fullPath = __DIR__ . "/../" . ltrim($doc["file_path"], "/\\");
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    sendJsonResponse(200, [
        "success" => true,
        "message" => "Document '{$doc['title']}' deleted successfully."
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to delete document."
    ]);
}

