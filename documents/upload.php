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

// Support multiple auth avenues (Session token, X-User-Id, POST/GET user_id)
$userId = intval($_POST["user_id"] ?? $_GET["user_id"] ?? 0);
if ($userId <= 0) {
    $userId = getAuthenticatedUserId($conn) ?? 0;
}
if ($userId <= 0) {
    $fallbackStmt = $conn->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
    if ($fallbackStmt && $row = $fallbackStmt->fetch_assoc()) {
        $userId = (int) $row["id"];
    } else {
        $userId = requireAuth($conn);
    }
}

// Auto-create documents table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS `documents` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `title` varchar(255) NOT NULL,
      `format` varchar(20) NOT NULL DEFAULT 'PDF',
      `file_size` varchar(50) NOT NULL DEFAULT '1.0 MB',
      `file_path` varchar(500) DEFAULT NULL,
      `category` varchar(100) NOT NULL DEFAULT 'General',
      `location` varchar(100) NOT NULL DEFAULT 'Internal Storage',
      `priority` enum('IMPORTANT','NORMAL','LOW') DEFAULT NULL,
      `is_bookmarked` tinyint(1) NOT NULL DEFAULT 0,
      `is_saved` tinyint(1) NOT NULL DEFAULT 0,
      `total_pages` int(11) NOT NULL DEFAULT 1,
      `content_text` longtext DEFAULT NULL,
      `insight_status` varchar(32) NOT NULL DEFAULT 'PENDING',
      `insight_overview` longtext DEFAULT NULL,
      `insight_topics` longtext DEFAULT NULL,
      `insight_key_points` longtext DEFAULT NULL,
      `insight_details` longtext DEFAULT NULL,
      `insight_terms` longtext DEFAULT NULL,
      `insight_conclusion` longtext DEFAULT NULL,
      `insight_error` text DEFAULT NULL,
      `insight_updated_at` datetime DEFAULT NULL,
      `last_accessed` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_documents_user` (`user_id`),
      KEY `idx_documents_priority` (`priority`),
      KEY `idx_documents_format` (`format`),
      KEY `idx_documents_saved` (`is_saved`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

$input = getJsonInput();

$title = trim($input["title"] ?? $_POST["title"] ?? "");

// Priority is strictly manual or via AI Chat prompt - never auto-assigned on upload
$rawPriority = $input["priority"] ?? $_POST["priority"] ?? null;
$priority = null;
if ($rawPriority !== null && trim($rawPriority) !== "") {
    $cleanPriority = strtoupper(trim($rawPriority));
    if (in_array($cleanPriority, ["IMPORTANT", "NORMAL", "LOW"])) {
        $priority = $cleanPriority;
    }
}

$category = trim($input["category"] ?? $_POST["category"] ?? "General");
$location = trim($input["location"] ?? $_POST["location"] ?? "Internal Storage");
$contentText = trim($input["content_text"] ?? $_POST["content_text"] ?? "");
$format = strtoupper(trim($input["format"] ?? $_POST["format"] ?? "PDF"));
$fileSize = trim($input["file_size"] ?? $_POST["file_size"] ?? "1.0 MB");
$totalPages = intval($input["total_pages"] ?? $_POST["total_pages"] ?? 1);
if ($totalPages <= 0) $totalPages = 1;

$savedRelativePath = null;
$extractionWarning = null;

// Locate uploaded file from any accepted multipart field name
$file = null;
foreach (["document", "document_file", "file", "doc"] as $fKey) {
    if (isset($_FILES[$fKey]) && is_array($_FILES[$fKey]) && !empty($_FILES[$fKey]["name"])) {
        $file = $_FILES[$fKey];
        break;
    }
}
if (!$file && !empty($_FILES)) {
    $firstFile = reset($_FILES);
    if (is_array($firstFile) && !empty($firstFile["name"])) {
        $file = $firstFile;
    }
}

// Handle physical file upload if provided
if ($file !== null) {
    if ($file["error"] === UPLOAD_ERR_INI_SIZE || $file["error"] === UPLOAD_ERR_FORM_SIZE || $file["size"] > MAX_DOCUMENT_FILE_SIZE) {
        sendJsonResponse(413, [
            "success" => false,
            "message" => "File size exceeds the permitted limit of 25 MB."
        ]);
    }

    if ($file["error"] !== UPLOAD_ERR_OK) {
        sendJsonResponse(400, [
            "success" => false,
            "message" => "File upload failed with error code: " . $file["error"]
        ]);
    }

    if ($file["size"] === 0) {
        sendJsonResponse(400, [
            "success" => false,
            "message" => "Uploaded file is completely empty (0 bytes)."
        ]);
    }

    $originalName = basename($file["name"]);
    if ($title === "") {
        $title = $originalName;
    }

    $ext = strtoupper(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== "") {
        $format = $ext;
    }

    // Format human-readable file size
    $bytes = $file["size"];
    if ($bytes >= 1048576) {
        $fileSize = number_format($bytes / 1048576, 1) . " MB";
    } elseif ($bytes >= 1024) {
        $fileSize = number_format($bytes / 1024, 1) . " KB";
    } else {
        $fileSize = $bytes . " B";
    }

    $targetDir = __DIR__ . "/../uploads/documents/";
    if (!is_dir($targetDir)) {
        mkdir($targetDir, 0755, true);
    }

    $uniqueName = bin2hex(random_bytes(12)) . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "", $originalName);
    $targetPath = $targetDir . $uniqueName;

    if (move_uploaded_file($file["tmp_name"], $targetPath)) {
        $savedRelativePath = "uploads/documents/" . $uniqueName;

        // Perform automatic server-side text extraction
        $extResult = extractDocumentText($targetPath, $ext);
        
        if (!$extResult["success"]) {
            if (!empty($extResult["is_encrypted"])) {
                @unlink($targetPath);
                sendJsonResponse(422, [
                    "success" => false,
                    "message" => "Upload rejected: The document is password-protected or encrypted. Please remove password protection before uploading."
                ]);
            }

            // For non-fatal formats, still preserve file in the vault
            $contentText = "";
            $extractionWarning = $extResult["error"] ?? "Text extraction not available for this file.";
        } else {
            // Successfully extracted
            if (!empty($extResult["text"])) {
                $contentText = $extResult["text"];
            }
            if (!empty($extResult["page_count"])) {
                $totalPages = $extResult["page_count"];
            }
            if (!empty($extResult["warning"])) {
                $extractionWarning = $extResult["warning"];
            }
        }
    } else {
        sendJsonResponse(500, [
            "success" => false,
            "message" => "Failed to store uploaded file on server."
        ]);
    }
}

if ($title === "" && !$savedRelativePath) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Document title or file is required."
    ]);
}

// AI Smart Auto-Categorization (Category only - Priority is NOT auto-assigned on upload)
$hasExplicitCategory = !empty($input["category"]) || !empty($_POST["category"]);

if (!$hasExplicitCategory) {
    $aiPrediction = aiPredictCategoryAndPriority($title, $contentText);
    if (!empty($aiPrediction["category"])) {
        $category = $aiPrediction["category"];
    }
}

// Check for existing document in IntelliSpace to prevent duplicates and enable instant sync
// Handles files with identical names but different storage locations correctly
$checkExisting = $conn->prepare("SELECT id, user_id, title, format, file_size, file_path, category, location, priority, total_pages, content_text, created_at FROM documents WHERE user_id = ? AND title = ? AND (location = ? OR location = 'Internal Storage' OR ? = 'Internal Storage') LIMIT 1");
if ($checkExisting) {
    $checkExisting->bind_param("isss", $userId, $title, $location, $location);
    $checkExisting->execute();
    $existRes = $checkExisting->get_result();
    if ($existingRow = $existRes->fetch_assoc()) {
        $checkExisting->close();
        $existingId = (int)$existingRow["id"];

        // If newly uploaded physical file is provided, update path; otherwise keep existing
        $finalPath = $savedRelativePath ?: $existingRow["file_path"];
        $updateStmt = $conn->prepare("UPDATE documents SET file_path = ?, file_size = ?, category = ?, location = ?, priority = COALESCE(?, priority), last_accessed = CURRENT_TIMESTAMP WHERE id = ?");
        if ($updateStmt) {
            $updateStmt->bind_param("sssssi", $finalPath, $fileSize, $category, $location, $priority, $existingId);
            $updateStmt->execute();
            $updateStmt->close();
        }

        // Auto-generate or retrieve Insight for existing document
        $existingInsight = getDocumentInsight($conn, $existingId);
        if (!$existingInsight || empty($existingInsight["overview"]) || $existingInsight["status"] !== "READY") {
            $effectiveContent = $contentText ?: ($existingRow["content_text"] ?? "");
            $generatedInsight = aiGenerateDocumentInsight($title, $format, $effectiveContent);
            saveDocumentInsight($conn, $existingId, $generatedInsight);
            $existingInsight = getDocumentInsight($conn, $existingId) ?: $generatedInsight;
        }

        $fileUrl = $finalPath ? (getBaseUrl() . $finalPath) : null;
        sendJsonResponse(200, [
            "success" => true,
            "message" => "Document synced to IntelliSpace successfully.",
            "insight_status" => $existingInsight["status"] ?? "READY",
            "insight" => $existingInsight,
            "document" => [
                "id" => $existingId,
                "user_id" => $userId,
                "title" => $title,
                "format" => $format,
                "file_size" => $fileSize,
                "file_url" => $fileUrl,
                "file_path" => $finalPath,
                "category" => $category,
                "location" => $location,
                "priority" => $priority ?: $existingRow["priority"],
                "total_pages" => $totalPages,
                "has_text" => !empty($contentText) || !empty($existingRow["content_text"]),
                "content_text" => $contentText ?: ($existingRow["content_text"] ?? ""),
                "extracted_chars" => mb_strlen($contentText ?: ($existingRow["content_text"] ?? "")),
                "insight_status" => $existingInsight["status"] ?? "READY",
                "document_overview" => $existingInsight["document_overview"] ?? $existingInsight["overview"] ?? "",
                "insight_overview" => $existingInsight["overview"] ?? "",
                "insight_topics" => $existingInsight["main_topics"] ?? [],
                "insight_key_points" => $existingInsight["key_points"] ?? [],
                "insight_terms" => $existingInsight["important_terms"] ?? [],
                "insight_insights" => $existingInsight["important_insights"] ?? $existingInsight["important_details"] ?? [],
                "insight_details" => $existingInsight["important_details"] ?? [],
                "insight_conclusion" => $existingInsight["conclusion"] ?? "",
                "created_at" => $existingRow["created_at"]
            ]
        ]);
    }
    $checkExisting->close();
}

try {
    $stmt = $conn->prepare("
        INSERT INTO documents (user_id, title, format, file_size, file_path, category, location, priority, total_pages, content_text)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param("isssssssis", $userId, $title, $format, $fileSize, $savedRelativePath, $category, $location, $priority, $totalPages, $contentText);
    $stmt->execute();
    $newDocId = $conn->insert_id;
    $stmt->close();

    // Trigger local vector indexing for RAG immediately (deduplicated by content hash)
    if (!empty($contentText)) {
        aiIndexDocument($userId, $newDocId, $title, $format, $contentText, $totalPages);
    }

    // MANDATORY REQUIREMENT: Automatically generate and persist Universal AI Insight upon upload
    updateDocumentInsightStatus($conn, $newDocId, "GENERATING");
    $insight = aiGenerateDocumentInsight($title, $format, $contentText);
    saveDocumentInsight($conn, $newDocId, $insight);
    $persistedInsight = getDocumentInsight($conn, $newDocId) ?: $insight;

    $fileUrl = $savedRelativePath ? (getBaseUrl() . $savedRelativePath) : null;

    sendJsonResponse(201, [
        "success" => true,
        "message" => "Document uploaded and AI Insight generated successfully.",
        "insight_status" => "READY",
        "insight" => $persistedInsight,
        "document" => [
            "id" => $newDocId,
            "user_id" => $userId,
            "title" => $title,
            "format" => $format,
            "file_size" => $fileSize,
            "file_url" => $fileUrl,
            "file_path" => $savedRelativePath,
            "category" => $category,
            "location" => $location,
            "priority" => $priority,
            "total_pages" => $totalPages,
            "has_text" => !empty($contentText),
            "content_text" => $contentText,
            "extracted_chars" => mb_strlen($contentText),
            "extraction_warning" => $extractionWarning,
            "insight_status" => "READY",
            "document_overview" => $persistedInsight["document_overview"] ?? $persistedInsight["overview"] ?? "",
            "insight_overview" => $persistedInsight["overview"] ?? "",
            "insight_topics" => $persistedInsight["main_topics"] ?? [],
            "insight_key_points" => $persistedInsight["key_points"] ?? [],
            "insight_terms" => $persistedInsight["important_terms"] ?? [],
            "insight_insights" => $persistedInsight["important_insights"] ?? $persistedInsight["important_details"] ?? [],
            "insight_details" => $persistedInsight["important_details"] ?? [],
            "insight_conclusion" => $persistedInsight["conclusion"] ?? "",
            "created_at" => date("Y-m-d H:i:s")
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to save document. Please try again: " . $e->getMessage()
    ]);
}
