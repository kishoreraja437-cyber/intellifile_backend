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
$priority = strtoupper(trim($input["priority"] ?? ""));

if ($docId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Valid document_id is required."
    ]);
}

if (!in_array($priority, ["IMPORTANT", "NORMAL", "LOW"])) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Priority must be one of: IMPORTANT, NORMAL, LOW."
    ]);
}

try {
    $stmt = $conn->prepare("UPDATE documents SET priority = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sii", $priority, $docId, $userId);
    $stmt->execute();

    if ($stmt->affected_rows >= 0) {
        $stmt->close();

        // Fetch updated document details
        $fetch = $conn->prepare("SELECT id, title, format, file_size, priority FROM documents WHERE id = ?");
        $fetch->bind_param("i", $docId);
        $fetch->execute();
        $updatedDoc = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        sendJsonResponse(200, [
            "success" => true,
            "message" => "Priority updated successfully.",
            "document" => [
                "id" => (int) $updatedDoc["id"],
                "fileName" => $updatedDoc["title"],
                "priority" => $updatedDoc["priority"]
            ]
        ]);
    } else {
        $stmt->close();
        sendJsonResponse(404, [
            "success" => false,
            "message" => "Document not found or access denied."
        ]);
    }

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to update priority."
    ]);
}

