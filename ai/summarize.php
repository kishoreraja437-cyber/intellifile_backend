<?php

require_once __DIR__ . "/../config/auth_helper.php";
require_once __DIR__ . "/../config/ai_helper.php";

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
$title = trim($input["title"] ?? "");
$contentText = trim($input["content_text"] ?? "");

if ($docId > 0) {
    $stmt = $conn->prepare("SELECT title, content_text FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $docId, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $doc = $res->fetch_assoc();
    $stmt->close();

    if (!$doc) {
        sendJsonResponse(404, [
            "success" => false,
            "message" => "Document not found or access denied."
        ]);
    }

    $title = $doc["title"];
    $contentText = $doc["content_text"] ?? "";
}

if ($contentText === "") {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Document has no text content available to summarize. Please provide content_text."
    ]);
}

$summaryResult = aiSummarizeDocument($title, $contentText);

sendJsonResponse(200, [
    "success" => true,
    "document_id" => $docId > 0 ? $docId : null,
    "title" => $title,
    "summary" => $summaryResult["summary"] ?? "",
    "bullet_points" => $summaryResult["bullet_points"] ?? [],
    "source" => $summaryResult["source"] ?? "python_ai"
]);

