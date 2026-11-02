<?php

/**
 * IntelliFile AI Microservice Bridge
 * Connects PHP backend to local Python AI service on http://127.0.0.1:5000
 */

define("AI_SERVICE_URL", "http://127.0.0.1:5000");

/**
 * Sends an HTTP POST request to local Python AI microservice.
 * Returns decoded associative array, or null if service is offline.
 */
function callPythonAiService(string $endpoint, array $payload, int $timeoutSeconds = 3): ?array {
    $url = rtrim(AI_SERVICE_URL, "/") . "/" . ltrim($endpoint, "/");
    $jsonData = json_encode($payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonData,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "Content-Length: " . strlen($jsonData)
        ],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log("[AI BRIDGE] Failed connecting to {$url}: {$error} (HTTP {$httpCode})");
        return null;
    }

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Predicts category and priority for a document using local Python ML model
 */
function aiPredictCategoryAndPriority(string $title, string $contentText): array {
    $res = callPythonAiService("predict_category_priority", [
        "title" => $title,
        "content_text" => $contentText
    ]);

    if ($res && !empty($res["success"])) {
        return [
            "category" => $res["category"] ?? "General",
            "priority" => $res["priority"] ?? "NORMAL",
            "confidence" => $res["category_confidence"] ?? 0.8,
            "source" => "python_ml"
        ];
    }

    // Heuristic fallback if Python service is offline
    $lower = strtolower($title . " " . $contentText);
    $category = "General";
    if (str_contains($lower, "assignment") || str_contains($lower, "homework") || str_contains($lower, "lab record")) {
        $category = "Assignment";
    } elseif (str_contains($lower, "notes") || str_contains($lower, "study guide") || str_contains($lower, "lecture")) {
        $category = "Notes";
    } elseif (str_contains($lower, "project") || str_contains($lower, "report") || str_contains($lower, "thesis")) {
        $category = "Project Report";
    } elseif (str_contains($lower, "resume") || str_contains($lower, "cv") || str_contains($lower, "curriculum vitae")) {
        $category = "Resume";
    } elseif (str_contains($lower, "paper") || str_contains($lower, "ieee") || str_contains($lower, "journal")) {
        $category = "Research Paper";
    }

    $priority = "NORMAL";
    if (str_contains($lower, "urgent") || str_contains($lower, "due tomorrow") || str_contains($lower, "exam") || str_contains($lower, "deadline")) {
        $priority = "IMPORTANT";
    } elseif (str_contains($lower, "reference") || str_contains($lower, "archive") || str_contains($lower, "optional")) {
        $priority = "LOW";
    }

    return [
        "category" => $category,
        "priority" => $priority,
        "confidence" => 0.6,
        "source" => "heuristic_fallback"
    ];
}

/**
 * Performs local RAG / Extractive QA across documents using Python QA engine
 */
function aiPerformDocumentQA(string $query, array $documents): array {
    $res = callPythonAiService("document_qa", [
        "query" => $query,
        "documents" => $documents
    ], 5);

    if ($res && !empty($res["success"])) {
        return [
            "answer" => $res["answer"],
            "category" => $res["category"] ?? "GENERAL",
            "matched_files" => $res["matched_files"] ?? [],
            "source" => "python_nlp"
        ];
    }

    return [
        "answer" => null,
        "category" => "GENERAL",
        "matched_files" => [],
        "source" => "fallback"
    ];
}

/**
 * Summarizes document text into key points using Python AI Microservice
 */
function aiSummarizeDocument(string $title, string $contentText): array {
    $res = callPythonAiService("summarize", [
        "title" => $title,
        "content_text" => $contentText
    ], 5);

    if ($res && !empty($res["success"])) {
        return $res;
    }

    return [
        "success" => true,
        "title" => $title,
        "summary" => mb_substr($contentText, 0, 300) . (strlen($contentText) > 300 ? "..." : ""),
        "bullet_points" => [],
        "source" => "fallback"
    ];
}

/**
 * Extracts text and page count from PDF using local Python AI microservice (pypdf)
 */
function aiExtractPdfText(string $filePath): ?array {
    $realPath = realpath($filePath);
    if (!$realPath || !file_exists($realPath)) {
        return [
            "success" => false,
            "error" => "File not found: " . $filePath,
            "text" => "",
            "page_count" => 0
        ];
    }

    $res = callPythonAiService("extract_text", [
        "file_path" => $realPath
    ], 10);

    return $res;
}

/**
 * Executes Hybrid RAG + SLM Chat query through local Python microservice.
 * Enforces user_id isolation and passes live app_stats & conversation history.
 */
function aiRunRagChat(int $userId, string $query, array $conversationHistory = [], ?array $filterDocIds = null, array $appStats = []): ?array {
    $payload = [
        "user_id" => $userId,
        "query" => $query,
        "conversation_history" => $conversationHistory,
        "app_stats" => $appStats
    ];
    if (!empty($filterDocIds)) {
        $payload["filter_doc_ids"] = $filterDocIds;
    }
    return callPythonAiService("hybrid_chat", $payload, 12);
}

/**
 * Fetches actual live IntelliFile repository and storage statistics for the authenticated user.
 * Used by Hybrid Assistant to answer questions about user's files without inventing numbers.
 */
function getUserAppStatistics(mysqli $conn, int $userId): array {
    // 1. Total files count
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM documents WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $totalFiles = (int) ($stmt->get_result()->fetch_assoc()["total"] ?? 0);
    $stmt->close();

    // 2. Format breakdown
    $stmt = $conn->prepare("SELECT format, COUNT(*) AS count FROM documents WHERE user_id = ? GROUP BY format ORDER BY count DESC");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $fRes = $stmt->get_result();
    $formatCounts = [];
    $mostUsedFormat = null;
    while ($r = $fRes->fetch_assoc()) {
        $fmt = strtoupper($r["format"]);
        $formatCounts[$fmt] = (int) $r["count"];
        if ($mostUsedFormat === null) {
            $mostUsedFormat = $fmt;
        }
    }
    $stmt->close();

    // 3. Priority breakdown
    $stmt = $conn->prepare("SELECT priority, COUNT(*) AS count FROM documents WHERE user_id = ? GROUP BY priority");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $pRes = $stmt->get_result();
    $priorityCounts = ["IMPORTANT" => 0, "NORMAL" => 0, "LOW" => 0];
    while ($r = $pRes->fetch_assoc()) {
        $p = strtoupper($r["priority"]);
        $priorityCounts[$p] = (int) $r["count"];
    }
    $stmt->close();

    // 4. Bookmarked files count
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM documents WHERE user_id = ? AND is_bookmarked = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $bookmarkedCount = (int) ($stmt->get_result()->fetch_assoc()["count"] ?? 0);
    $stmt->close();

    // 5. Saved files count
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM documents WHERE user_id = ? AND is_saved = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $savedCount = (int) ($stmt->get_result()->fetch_assoc()["count"] ?? 0);
    $stmt->close();

    // 6. Files uploaded today
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM documents WHERE user_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $todayCount = (int) ($stmt->get_result()->fetch_assoc()["count"] ?? 0);
    $stmt->close();

    // 7. Recent files (up to 5)
    $baseUrl = getBaseUrl();
    $stmt = $conn->prepare("SELECT id, title, format, file_size, file_path, category, priority, created_at FROM documents WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $rRes = $stmt->get_result();
    $recentFiles = [];
    while ($r = $rRes->fetch_assoc()) {
        $recentFiles[] = [
            "id" => (int) $r["id"],
            "title" => $r["title"],
            "format" => $r["format"],
            "size" => $r["file_size"],
            "dateText" => date("M j, Y", strtotime($r["created_at"])),
            "priority" => $r["priority"],
            "category" => $r["category"],
            "file_url" => !empty($r["file_path"]) ? ($baseUrl . $r["file_path"]) : null
        ];
    }
    $stmt->close();

    return [
        "total_files" => $totalFiles,
        "format_counts" => $formatCounts,
        "priority_counts" => $priorityCounts,
        "bookmarked_count" => $bookmarkedCount,
        "saved_count" => $savedCount,
        "today_count" => $todayCount,
        "most_used_format" => $mostUsedFormat ?? "None",
        "recent_files" => $recentFiles
    ];
}

/**
 * Indexes a document into the local Vector Database.
 * Deduplicates automatically by content hash.
 */
function aiIndexDocument(int $userId, int $docId, string $title, string $format, string $contentText, int $totalPages = 1): ?array {
    return callPythonAiService("index_document", [
        "user_id" => $userId,
        "doc_id" => $docId,
        "title" => $title,
        "format" => $format,
        "content_text" => $contentText,
        "total_pages" => $totalPages
    ], 10);
}

/**
 * Syncs multiple documents for a user into the vector database.
 */
function aiSyncUserDocuments(int $userId, array $documents): ?array {
    return callPythonAiService("sync_documents", [
        "user_id" => $userId,
        "documents" => $documents
    ], 15);
}

/**
 * Removes a document from the vector database index.
 */
function aiDeleteDocumentIndex(int $userId, int $docId): ?array {
    return callPythonAiService("delete_document_index", [
        "user_id" => $userId,
        "doc_id" => $docId
    ], 5);
}
