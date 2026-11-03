<?php

require_once __DIR__ . "/auth_helper.php";

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
        $p = strtoupper($r["priority"] ?? "");
        if (isset($priorityCounts[$p])) {
            $priorityCounts[$p] = (int) $r["count"];
        }
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


/**
 * Normalizes priority strings to 'IMPORTANT', 'NORMAL', or 'LOW'.
 */
function normalizePriority(string $val): string {
    $v = strtoupper(trim($val));
    if (in_array($v, ['HIGH', 'URGENT', 'IMPORTANT'])) return 'IMPORTANT';
    if (in_array($v, ['NORMAL', 'MEDIUM', 'REGULAR'])) return 'NORMAL';
    if ($v === 'LOW') return 'LOW';
    return 'NORMAL';
}

/**
 * Cleans user-provided file names from chat prompts.
 */
function cleanTargetFileName(string $raw): string {
    $clean = trim($raw, ' \'"\t\n\r\0\x0B');
    $clean = preg_replace('/^(the\s+file\s+|the\s+document\s+|file\s+|document\s+)/i', '', $clean);
    $clean = preg_replace('/(\s+file|\s+document)$/i', '', $clean);
    return trim($clean, ' \'"\t\n\r\0\x0B');
}

/**
 * Detects whether a prompt in AI chat is requesting to set, clear, check, or query priority.
 */
function detectPriorityCommand(string $prompt): ?array {
    $clean = trim($prompt);
    $prioRegex = '(?:important|high|urgent|normal|medium|regular|low)';

    // Pattern 1: assign (priority)? (P) to (File)
    if (preg_match('/^assign\s+(?:the\s+)?(?:priority\s+)?(' . $prioRegex . ')\s+(?:priority\s+)?to\s+(?:the\s+)?(.+?)\.?$/i', $clean, $m)) {
        return [
            'action' => 'SET',
            'target_file' => cleanTargetFileName($m[2]),
            'priority' => normalizePriority($m[1])
        ];
    }

    // Pattern 2: set/change/update (the) priority of/for (File) to/as (P)
    if (preg_match('/^(?:set|change|update)\s+(?:the\s+)?priority\s+(?:of|for)\s+(?:the\s+)?(.+?)\s+(?:to|as)\s+(' . $prioRegex . ')(?:\s+priority)?\.?$/i', $clean, $m)) {
        return [
            'action' => 'SET',
            'target_file' => cleanTargetFileName($m[1]),
            'priority' => normalizePriority($m[2])
        ];
    }

    // Pattern 3: set/change/update (File) priority to/as (P)
    if (preg_match('/^(?:set|change|update)\s+(?:the\s+)?(.+?)\s+priority\s+(?:to|as)\s+(' . $prioRegex . ')(?:\s+priority)?\.?$/i', $clean, $m)) {
        return [
            'action' => 'SET',
            'target_file' => cleanTargetFileName($m[1]),
            'priority' => normalizePriority($m[2])
        ];
    }

    // Pattern 4: mark/make/tag (File) as/to (P) (priority)
    if (preg_match('/^(?:mark|make|tag)\s+(?:the\s+)?(.+?)\s+(?:as|to)\s+(' . $prioRegex . ')(?:\s+priority)?\.?$/i', $clean, $m)) {
        return [
            'action' => 'SET',
            'target_file' => cleanTargetFileName($m[1]),
            'priority' => normalizePriority($m[2])
        ];
    }

    // Pattern 5: set/mark (File) to (P) priority
    if (preg_match('/^(?:set|mark)\s+(?:the\s+)?(.+?)\s+to\s+(' . $prioRegex . ')\s+priority\.?$/i', $clean, $m)) {
        return [
            'action' => 'SET',
            'target_file' => cleanTargetFileName($m[1]),
            'priority' => normalizePriority($m[2])
        ];
    }

    // Clear / Remove priority
    if (preg_match('/^(?:remove|clear|unset|reset|delete)\s+(?:the\s+)?priority\s+(?:of|for)\s+(?:the\s+)?(.+?)\.?$/i', $clean, $m)) {
        return [
            'action' => 'CLEAR',
            'target_file' => cleanTargetFileName($m[1]),
            'priority' => null
        ];
    }

    // Check / Query priority
    if (preg_match('/^(?:what\s+is\s+the\s+priority\s+of|check\s+(?:the\s+)?priority\s+of|show\s+(?:the\s+)?priority\s+of|get\s+(?:the\s+)?priority\s+of)\s+["\']?(.+?)["\']?\??$/i', $clean, $m)) {
        return [
            'action' => 'CHECK',
            'target_file' => cleanTargetFileName($m[1]),
            'priority' => null
        ];
    }

    return null;
}

/**
 * Searches the user's documents in the database by title or title match.
 */
function findUserDocumentByTitle($conn, int $userId, string $rawQueryTitle): ?array {
    $cleanTitle = cleanTargetFileName($rawQueryTitle);
    $cleanTitle = preg_replace('/[\.\,\?\!]+$/', '', $cleanTitle);
    if (empty($cleanTitle)) return null;

    // 1. Exact title match
    $stmt = $conn->prepare("SELECT id, user_id, title, format, file_size, file_path, category, location, priority, total_pages FROM documents WHERE user_id = ? AND title = ? LIMIT 1");
    $stmt->bind_param("is", $userId, $cleanTitle);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($doc) return $doc;

    // 2. Case-insensitive exact title match
    $stmt = $conn->prepare("SELECT id, user_id, title, format, file_size, file_path, category, location, priority, total_pages FROM documents WHERE user_id = ? AND LOWER(title) = LOWER(?) LIMIT 1");
    $stmt->bind_param("is", $userId, $cleanTitle);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($doc) return $doc;

    // 3. Match without file extension
    $stmt = $conn->prepare("SELECT id, user_id, title, format, file_size, file_path, category, location, priority, total_pages FROM documents WHERE user_id = ? AND LOWER(SUBSTRING_INDEX(title, '.', 1)) = LOWER(?) LIMIT 1");
    $stmt->bind_param("is", $userId, $cleanTitle);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($doc) return $doc;

    // 4. Substring LIKE match
    $like = "%" . $cleanTitle . "%";
    $stmt = $conn->prepare("SELECT id, user_id, title, format, file_size, file_path, category, location, priority, total_pages FROM documents WHERE user_id = ? AND title LIKE ? ORDER BY last_accessed DESC LIMIT 1");
    $stmt->bind_param("is", $userId, $like);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($doc) return $doc;

    return null;
}

/**
 * Extracts the search topic and optional format from natural language queries.
 * Examples:
 * - "Show the PDF containing machine learning notes" => topic: "machine learning notes", format: "PDF"
 * - "Find the document where I wrote about Python" => topic: "Python", format: null
 * - "I forgot the filename, but it contains information about cloud computing" => topic: "cloud computing", format: null
 * - "Find my college assignment." => topic: "college assignment", format: null
 */
function extractSearchTopic(string $input): array {
    $clean = trim($input);
    $format = null;

    if (preg_match('/\b(pdf|pdfs)\b/i', $clean)) {
        $format = "PDF";
    } elseif (preg_match('/\b(docx?|word)\b/i', $clean)) {
        $format = "DOCX";
    } elseif (preg_match('/\b(pptx?|powerpoint|slides?)\b/i', $clean)) {
        $format = "PPTX";
    } elseif (preg_match('/\b(xlsx?|excel|sheets?)\b/i', $clean)) {
        $format = "XLSX";
    } elseif (preg_match('/\b(txt|text)\b/i', $clean)) {
        $format = "TXT";
    }

    $target = $clean;

    $prefixPatterns = [
        '/^(?:i\s+(?:forgot|don\'?t\s+remember)\s+the\s+filename[,\s]+(?:but\s+)?it\s+contains(?:\s+information\s+about|\s+notes\s+on|\s+info\s+about)?)\s+/i',
        '/^(?:find|search(?:\s+for)?|where\s+is|where\s+are|look\s+for|show\s+me|show|locate|get)\s+(?:the\s+file\s+|the\s+document\s+|the\s+pdf\s+|the\s+word\s+doc\s+|my\s+|the\s+)?/i',
        '/^(?:do\s+i\s+have|is\s+there)\s+(?:any\s+)?(?:files?\s+(?:on|for|about)\s+|my\s+|the\s+)?/i',
        '/^(?:open|view|read|launch)\s+(?:the\s+file\s+|the\s+document\s+|my\s+|the\s+)?/i'
    ];

    foreach ($prefixPatterns as $p) {
        $target = preg_replace($p, '', $target);
    }

    $connectorPatterns = [
        '/\b(?:where\s+i\s+(?:wrote|talked|discussed|mentioned|wrote\s+about|talked\s+about)\s+about)\s+/i',
        '/\b(?:where\s+i\s+(?:wrote|talked|discussed|mentioned))\s+/i',
        '/\b(?:containing\s+(?:information\s+about|info\s+about|notes\s+on|notes\s+about)?)\s*/i',
        '/\b(?:contains\s+(?:information\s+about|info\s+about|notes\s+on|notes\s+about)?)\s*/i',
        '/\b(?:information\s+about|info\s+about|notes\s+on|notes\s+about|related\s+to)\s+/i',
        '/\b(?:document\s+about|file\s+about|pdf\s+about)\s+/i',
        '/^(?:the\s+pdf|the\s+file|the\s+document|my\s+document|my\s+file|my\s+pdf)\s+/i'
    ];

    foreach ($connectorPatterns as $cp) {
        $target = preg_replace($cp, '', $target);
    }

    $target = preg_replace('/(\s+file|\s+document|\s+files|\s+documents)$/i', '', $target);
    $target = trim($target, " \t\n\r\0\x0B'\".,?!");

    return [
        "topic" => $target,
        "format" => $format
    ];
}

/**
 * Detects whether a prompt in AI chat is asking to search, filter, or open a file.
 */
function detectFileSearchIntent(string $prompt): ?array {
    $clean = trim($prompt);

    // Exclude RAG summaries / explanations / analysis
    if (preg_match('/\b(summar|explain\s+chapter|explain\s+section|key\s+point|compare|takeaway)\b/i', $clean)) {
        return null;
    }

    // Exclude App stats count queries
    if (preg_match('/\bhow\s+many\b/i', $clean) || preg_match('/\b(total\s+count|count\s+of)\b/i', $clean)) {
        return null;
    }

    // Exclude General AI questions
    if (preg_match('/^(?:what\s+is|what\s+are|how\s+does|why\s+is|tell\s+me\s+about|write\s+a)\b/i', $clean)) {
        return null;
    }

    // 1. FILE_OPEN: e.g. "open assignment.pdf", "open my project report", "view Java notes", "read resume"
    if (preg_match('/^(?:open|view|read|launch)\s+(?:the\s+file\s+|the\s+document\s+|my\s+|the\s+)?(.+?)[.\?!]?$/i', $clean, $m)) {
        $target = trim($m[1], " \t\n\r\0\x0B'\"");
        if (!empty($target)) {
            return [
                "action" => "OPEN",
                "query" => $target,
                "format" => null
            ];
        }
    }

    // 2. FILE_TYPE_FILTER: e.g. "show all PDF files", "find all docx files", "show my PPT files", "show word documents"
    if (preg_match('/^(?:show|find|list|display|get)\s+(?:all\s+)?(?:my\s+)?(pdf|docx?|word|pptx?|powerpoint|txt|text|csv|xlsx?|excel)\s*(?:files?|documents?|\s*$)/i', $clean, $m)) {
        $rawFmt = strtoupper($m[1]);
        if ($rawFmt === "DOC" || $rawFmt === "WORD") $rawFmt = "DOCX";
        if ($rawFmt === "PPT" || $rawFmt === "POWERPOINT") $rawFmt = "PPT";
        if ($rawFmt === "XLS" || $rawFmt === "EXCEL") $rawFmt = "XLSX";
        if ($rawFmt === "TEXT") $rawFmt = "TXT";
        return [
            "action" => "FILTER",
            "query" => "",
            "format" => $rawFmt
        ];
    }

    // 3. Standalone filename with document extension (e.g. "assignment.pdf", "resume.docx", "notes.txt")
    if (preg_match('/^([a-zA-Z0-9_\-\s\(\)]+\.(pdf|docx?|pptx?|xlsx?|txt|csv))[.\?!]?$/i', $clean, $m)) {
        return [
            "action" => "SEARCH",
            "query" => trim($m[1], " \t\n\r\0\x0B'\""),
            "format" => strtoupper(pathinfo($m[1], PATHINFO_EXTENSION))
        ];
    }

    // 4. Natural Language File Search & Content-Based Search:
    // e.g. "Find my college assignment", "Show the PDF containing machine learning notes",
    // "Find the document where I wrote about Python", "I forgot the filename, but it contains information about cloud computing"
    $isSearchIntent = false;
    if (preg_match('/^(?:find|search(?:\s+for)?|where\s+is|where\s+are|look\s+for|show\s+me|show|locate|get|do\s+i\s+have|is\s+there)\b/i', $clean)) {
        $isSearchIntent = true;
    } elseif (preg_match('/^(?:i\s+(?:forgot|don\'?t\s+remember)|which\s+file|which\s+document)\b/i', $clean)) {
        $isSearchIntent = true;
    } elseif (preg_match('/\b(containing|contains|notes\s+on|wrote\s+about|information\s+about)\b/i', $clean)) {
        $isSearchIntent = true;
    }

    if ($isSearchIntent) {
        $extracted = extractSearchTopic($clean);
        $topic = $extracted["topic"];
        $format = $extracted["format"];

        if (!empty($topic)) {
            return [
                "action" => "SEARCH",
                "query" => $topic,
                "format" => $format
            ];
        }
    }

    // 5. Short keyword, name, or candidate filename query without question markers (1 to 5 words)
    // e.g. "Ramesh Kumar", "assignment", "cloud computing notes", "John Doe", "doctor prescription"
    $words = preg_split('/\s+/', $clean);
    $wordCount = count($words);
    if ($wordCount >= 1 && $wordCount <= 5) {
        $firstWord = strtolower($words[0]);
        $questionWords = [
            'what', 'why', 'how', 'when', 'who', 'where', 'which',
            'can', 'could', 'would', 'should', 'is', 'are', 'was',
            'were', 'do', 'does', 'did', 'summarize', 'explain', 'compare', 'tell'
        ];
        if (!in_array($firstWord, $questionWords) && !str_ends_with($clean, '?')) {
            $extracted = extractSearchTopic($clean);
            $topic = !empty($extracted["topic"]) ? $extracted["topic"] : $clean;
            $format = $extracted["format"];
            return [
                "action" => "SEARCH",
                "query" => $topic,
                "format" => $format
            ];
        }
    }

    return null;
}

/**
 * Searches the authenticated user's documents in the database.
 */
function searchUserVaultDocuments($conn, int $userId, string $query, ?string $formatFilter = null): array {
    $cleanQuery = trim($query);
    $params = [$userId];
    $types = "i";

    $whereClauses = ["user_id = ?"];

    // Format filter
    if (!empty($formatFilter)) {
        $fmt = strtoupper($formatFilter);
        if ($fmt === "WORD" || $fmt === "DOC") {
            $whereClauses[] = "(format = 'DOC' OR format = 'DOCX')";
        } elseif ($fmt === "POWERPOINT" || $fmt === "PPT") {
            $whereClauses[] = "(format = 'PPT' OR format = 'PPTX')";
        } elseif ($fmt === "EXCEL" || $fmt === "XLS") {
            $whereClauses[] = "(format = 'XLS' OR format = 'XLSX')";
        } else {
            $whereClauses[] = "format = ?";
            $params[] = $fmt;
            $types .= "s";
        }
    }

    if ($cleanQuery !== "" && $cleanQuery !== "all" && $cleanQuery !== "files" && $cleanQuery !== "documents") {
        $like = "%" . $cleanQuery . "%";
        $subLike = "(title LIKE ? OR category LIKE ? OR content_text LIKE ?)";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "sss";

        // Split into words if multi-word query
        $words = preg_split('/\s+/', $cleanQuery);
        if (count($words) > 1) {
            $wordClauses = [];
            foreach ($words as $w) {
                $w = trim($w);
                if (strlen($w) >= 2 && !in_array(strtolower($w), ['the', 'my', 'all', 'file', 'files', 'doc', 'docs'])) {
                    $wordClauses[] = "(title LIKE ? OR content_text LIKE ?)";
                    $wLike = "%" . $w . "%";
                    $params[] = $wLike;
                    $params[] = $wLike;
                    $types .= "ss";
                }
            }
            if (!empty($wordClauses)) {
                $subLike = "($subLike OR " . implode(" OR ", $wordClauses) . ")";
            }
        }
        $whereClauses[] = $subLike;
    }

    $whereSql = implode(" AND ", $whereClauses);
    $sql = "SELECT id, user_id, title, format, file_size, file_path, category, location, priority, total_pages, content_text, last_accessed, created_at 
            FROM documents 
            WHERE {$whereSql} 
            ORDER BY 
                priority = 'IMPORTANT' DESC,
                last_accessed DESC 
            LIMIT 25";

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $baseUrl = getBaseUrl();
    $matched = [];
    $seenTitles = [];
    while ($row = $res->fetch_assoc()) {
        $cleanTitle = strtolower(trim($row["title"]));
        if (in_array($cleanTitle, $seenTitles)) {
            continue;
        }
        $seenTitles[] = $cleanTitle;

        $fileUrl = !empty($row["file_path"]) ? ($baseUrl . $row["file_path"]) : null;
        $dateText = date("M j, Y", strtotime($row["last_accessed"] ?? $row["created_at"]));

        $snippet = null;
        if (!empty($row["content_text"])) {
            $pos = false;
            if ($cleanQuery !== "" && $cleanQuery !== "all" && $cleanQuery !== "files") {
                $pos = mb_stripos($row["content_text"], $cleanQuery);
            }
            if ($pos === false && !empty($words)) {
                foreach ($words as $w) {
                    if (strlen($w) >= 3) {
                        $p = mb_stripos($row["content_text"], $w);
                        if ($p !== false) {
                            $pos = $p;
                            break;
                        }
                    }
                }
            }
            if ($pos !== false) {
                $start = max(0, $pos - 35);
                $snippet = ($start > 0 ? "..." : "") . mb_substr($row["content_text"], $start, 140) . "...";
            } else {
                $snippet = mb_substr($row["content_text"], 0, 140) . "...";
            }
        }

        $matched[] = [
            "id" => (int) $row["id"],
            "title" => $row["title"],
            "format" => $row["format"],
            "size" => $row["file_size"],
            "dateText" => $dateText,
            "priority" => $row["priority"],
            "category" => $row["category"],
            "location" => $row["location"],
            "file_url" => $fileUrl,
            "file_path" => $row["file_path"],
            "source" => "AI Vault",
            "snippet" => $snippet
        ];
    }
    $stmt->close();
    return $matched;
}
