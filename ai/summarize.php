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
$doc = null;

if ($docId > 0) {
    $stmt = $conn->prepare("SELECT id, title, content_text, file_path, format, file_size, total_pages FROM documents WHERE id = ? AND user_id = ? LIMIT 1");
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

    if ($contentText === "") {
        $contentText = trim($doc["content_text"] ?? "");
    }

    // If still empty but file exists on disk, attempt on-demand extraction
    if ($contentText === "" && !empty($doc["file_path"])) {
        $diskPath = __DIR__ . "/../" . ltrim($doc["file_path"], "/\\");
        if (file_exists($diskPath)) {
            $ext = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
            $extracted = extractDocumentText($diskPath, $ext);
            if (!empty($extracted["text"])) {
                $contentText = trim($extracted["text"]);
                // Cache back into database
                $updStmt = $conn->prepare("UPDATE documents SET content_text = ? WHERE id = ?");
                $updStmt->bind_param("si", $contentText, $docId);
                $updStmt->execute();
                $updStmt->close();
            }
        }
    }
}

if ($contentText === "") {
    sendJsonResponse(200, [
        "success" => true,
        "document_id" => $docId > 0 ? $docId : null,
        "title" => $title ?: "Document",
        "summary" => "This document is securely stored in your AI Vault. No embedded text layers were detected for semantic extraction (it may be a scanned image or binary asset).",
        "bullet_points" => [
            "Stored securely in your AI Vault",
            "Document format: " . ($doc["format"] ?? "Document"),
            "File size: " . ($doc["file_size"] ?? "Unknown"),
            "Total pages: " . ($doc["total_pages"] ?? 1)
        ],
        "sentence_count" => 1,
        "source" => "document_metadata"
    ]);
}

$summaryResult = aiSummarizeDocument($title, $contentText);

$summaryText = trim($summaryResult["summary"] ?? "");
$bulletPoints = $summaryResult["bullet_points"] ?? [];

// If bullet points are empty, create structured points from summary or text sentences
if (empty($bulletPoints)) {
    $rawSentences = preg_split('/(?<=[.!?\n])\s+/', $contentText, -1, PREG_SPLIT_NO_EMPTY);
    $cleanSentences = [];
    foreach ($rawSentences as $s) {
        $trimmed = trim($s, " \t\n\r\0\x0B-•*");
        if (mb_strlen($trimmed) > 15 && mb_strlen($trimmed) < 250) {
            $cleanSentences[] = $trimmed;
        }
        if (count($cleanSentences) >= 5) break;
    }
    $bulletPoints = $cleanSentences;
}

if ($summaryText === "") {
    $summaryText = mb_substr($contentText, 0, 300) . (mb_strlen($contentText) > 300 ? "..." : "");
}

sendJsonResponse(200, [
    "success" => true,
    "document_id" => $docId > 0 ? $docId : null,
    "title" => $title,
    "summary" => $summaryText,
    "bullet_points" => $bulletPoints,
    "sentence_count" => count($bulletPoints),
    "source" => $summaryResult["source"] ?? "python_ai"
]);


