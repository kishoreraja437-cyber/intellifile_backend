<?php

require_once __DIR__ . "/../config/auth_helper.php";
require_once __DIR__ . "/../config/ai_helper.php";
require_once __DIR__ . "/../config/text_extractor.php";

handleCorsPreflight();

$userId = requireAuth($conn);

$input = getJsonInput();
$docId = intval($_GET["document_id"] ?? $_GET["id"] ?? $input["document_id"] ?? $input["id"] ?? 0);
$retry = !empty($_GET["retry"]) || !empty($input["retry"]);

if ($docId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Valid document_id is required."
    ]);
}

$stmt = $conn->prepare("SELECT id, title, format, file_size, total_pages, file_path, content_text, insight_status FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
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

// If already ready and retry not requested, return stored insight
if (!$retry && ($doc["insight_status"] ?? "") === "READY") {
    $existing = getDocumentInsight($conn, $docId);
    if ($existing && !empty($existing["overview"])) {
        sendJsonResponse(200, array_merge([
            "success" => true,
            "document_id" => $docId,
            "title" => $doc["title"],
            "source" => "database_cache"
        ], $existing));
    }
}

// Generate or regenerate
$contentText = trim($doc["content_text"] ?? "");
if ($contentText === "" && !empty($doc["file_path"])) {
    $diskPath = __DIR__ . "/../" . ltrim($doc["file_path"], "/\\");
    if (file_exists($diskPath)) {
        $ext = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
        $extracted = extractDocumentText($diskPath, $ext);
        if (!empty($extracted["text"])) {
            $contentText = trim($extracted["text"]);
            $updStmt = $conn->prepare("UPDATE documents SET content_text = ? WHERE id = ?");
            $updStmt->bind_param("si", $contentText, $docId);
            $updStmt->execute();
            $updStmt->close();
        }
    }
}

updateDocumentInsightStatus($conn, $docId, "GENERATING");
$insight = aiGenerateDocumentInsight($doc["title"], $doc["format"], $contentText);
saveDocumentInsight($conn, $docId, $insight);
$persisted = getDocumentInsight($conn, $docId) ?: $insight;

sendJsonResponse(200, array_merge([
    "success" => true,
    "document_id" => $docId,
    "title" => $doc["title"],
    "source" => "generated"
], $persisted));
