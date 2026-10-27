<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "PUT") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST or PUT methods are allowed."
    ]);
}

$userId = requireAuth($conn);
$input = getJsonInput();

$docId = intval($input["document_id"] ?? $input["id"] ?? 0);

if ($docId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Valid document_id is required."
    ]);
}

try {
    // Check ownership
    $check = $conn->prepare("SELECT id, title, is_bookmarked, is_saved FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
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

    $newBookmarked = isset($input["is_bookmarked"]) ? ($input["is_bookmarked"] ? 1 : 0) : ($doc["is_bookmarked"] ? 0 : 1);
    $newSaved = isset($input["is_saved"]) ? ($input["is_saved"] ? 1 : 0) : (int) $doc["is_saved"];

    $update = $conn->prepare("UPDATE documents SET is_bookmarked = ?, is_saved = ? WHERE id = ?");
    $update->bind_param("iii", $newBookmarked, $newSaved, $docId);
    $update->execute();
    $update->close();

    sendJsonResponse(200, [
        "success" => true,
        "message" => "Bookmark status updated successfully.",
        "document" => [
            "id" => $docId,
            "title" => $doc["title"],
            "is_bookmarked" => (bool) $newBookmarked,
            "is_saved" => (bool) $newSaved
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to update bookmark status."
    ]);
}

