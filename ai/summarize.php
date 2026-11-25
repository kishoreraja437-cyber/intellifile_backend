<?php

require_once __DIR__ . "/../config/auth_helper.php";
require_once __DIR__ . "/../config/ai_helper.php";
require_once __DIR__ . "/../config/text_extractor.php";

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
$forceRetry = !empty($input["retry"]) || !empty($input["force"]);
$doc = null;

if ($docId > 0) {
    $stmt = $conn->prepare("SELECT id, title, content_text, file_path, format, file_size, total_pages, insight_status FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
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

    if (empty($title)) {
        $title = $doc["title"];
    }

    // 1. If insight is already READY and retry is not requested, return saved insight immediately
    if (!$forceRetry && ($doc["insight_status"] ?? "") === "READY") {
        $existing = getDocumentInsight($conn, $docId);
        if ($existing && !empty($existing["overview"])) {
            sendJsonResponse(200, array_merge([
                "success" => true,
                "document_id" => $docId,
                "title" => $title,
                "source" => "database_cache"
            ], $existing));
        }
    }

    if ($contentText === "") {
        $contentText = trim($doc["content_text"] ?? "");
    }

    // If still empty but file exists on disk, extract on-demand
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
}

$docFormat = $doc["format"] ?? (pathinfo($title, PATHINFO_EXTENSION) ?: "Document");

// Generate Universal 6-Section Insight
updateDocumentInsightStatus($conn, $docId, "GENERATING");
$insight = aiGenerateDocumentInsight($title, $docFormat, $contentText);

if ($docId > 0) {
    saveDocumentInsight($conn, $docId, $insight);
}

sendJsonResponse(200, [
    "success" => true,
    "document_id" => $docId > 0 ? $docId : null,
    "title" => $title,
    "format" => $docFormat,
    "status" => "READY",
    "document_overview" => $insight["document_overview"] ?? $insight["overview"] ?? "",
    "overview" => $insight["overview"] ?? "",
    "main_topics" => $insight["main_topics"] ?? [],
    "key_points" => $insight["key_points"] ?? [],
    "important_terms" => $insight["important_terms"] ?? [],
    "important_insights" => $insight["important_insights"] ?? $insight["important_details"] ?? [],
    "important_details" => $insight["important_details"] ?? [],
    "conclusion" => $insight["conclusion"] ?? "",
    "summary" => $insight["overview"] ?? "",
    "bullet_points" => $insight["key_points"] ?? [],
    "sentence_count" => count($insight["key_points"] ?? []),
    "source" => $insight["source"] ?? "neural_engine"
]);
