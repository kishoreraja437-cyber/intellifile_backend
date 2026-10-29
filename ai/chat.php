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

$prompt = trim($input["prompt"] ?? $input["message"] ?? $input["query"] ?? "");
$conversationId = intval($input["conversation_id"] ?? 0);
$category = strtoupper(trim($input["category"] ?? ""));

if ($prompt === "") {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Please provide a chat prompt or question."
    ]);
}

// Ensure AI tables exist
$conn->query("
    CREATE TABLE IF NOT EXISTS `ai_conversations` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `user_id` int(11) NOT NULL,
      `title` varchar(255) NOT NULL,
      `snippet` text DEFAULT NULL,
      `category` enum('ALL','ANALYSIS','SEARCH','GENERAL') NOT NULL DEFAULT 'GENERAL',
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_ai_conv_user` (`user_id`),
      KEY `idx_ai_conv_cat` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

$conn->query("
    CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
      `id` int(11) NOT NULL AUTO_INCREMENT,
      `conversation_id` int(11) NOT NULL,
      `sender` enum('USER','AI') NOT NULL,
      `message_text` longtext NOT NULL,
      `related_files_json` longtext DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
      PRIMARY KEY (`id`),
      KEY `idx_ai_msg_conv` (`conversation_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");

try {
    // Determine category automatically if not supplied
    $lowerPrompt = strtolower($prompt);
    if ($category === "" || !in_array($category, ["ANALYSIS", "SEARCH", "GENERAL"])) {
        if (str_contains($lowerPrompt, "summar") || str_contains($lowerPrompt, "explain") || str_contains($lowerPrompt, "analys") || str_contains($lowerPrompt, "key point") || str_contains($lowerPrompt, "compare")) {
            $category = "ANALYSIS";
        } elseif (str_contains($lowerPrompt, "find") || str_contains($lowerPrompt, "search") || str_contains($lowerPrompt, "where is") || str_contains($lowerPrompt, "look for") || str_contains($lowerPrompt, "show me")) {
            $category = "SEARCH";
        } else {
            $category = "GENERAL";
        }
    }

    // 1. RAG Retrieval Step: Fetch real matching documents from database
    $words = preg_split("/\s+/", $lowerPrompt);
    $stopWords = ["the", "is", "at", "which", "on", "a", "an", "and", "or", "in", "for", "to", "my", "this", "that", "file", "files", "document", "documents", "show", "give", "me", "what", "find", "search", "summarize", "tell"];
    $keywords = array_filter($words, fn($w) => strlen($w) >= 3 && !in_array($w, $stopWords));

    $matchedDocs = [];
    $searchClauses = [];
    $searchParams = [$userId];
    $searchTypes = "i";

    foreach ($keywords as $kw) {
        $searchClauses[] = "(title LIKE ? OR content_text LIKE ? OR category LIKE ?)";
        $kwLike = "%" . $kw . "%";
        $searchParams[] = $kwLike;
        $searchParams[] = $kwLike;
        $searchParams[] = $kwLike;
        $searchTypes .= "sss";
    }

    if (!empty($searchClauses)) {
        $ragSql = "SELECT id, title, format, file_size, file_path, category, priority, total_pages, content_text, last_accessed 
                   FROM documents 
                   WHERE user_id = ? AND (" . implode(" OR ", $searchClauses) . ") 
                   ORDER BY priority = 'IMPORTANT' DESC, last_accessed DESC 
                   LIMIT 5";
        $ragStmt = $conn->prepare($ragSql);
        $ragStmt->bind_param($searchTypes, ...$searchParams);
        $ragStmt->execute();
        $ragRes = $ragStmt->get_result();
        while ($d = $ragRes->fetch_assoc()) {
            $matchedDocs[] = $d;
        }
        $ragStmt->close();
    }

    // Fallback if no specific keywords: retrieve recent important documents
    if (empty($matchedDocs)) {
        $ragSql = "SELECT id, title, format, file_size, file_path, category, priority, total_pages, content_text, last_accessed 
                   FROM documents 
                   WHERE user_id = ? 
                   ORDER BY last_accessed DESC 
                   LIMIT 3";
        $ragStmt = $conn->prepare($ragSql);
        $ragStmt->bind_param("i", $userId);
        $ragStmt->execute();
        $ragRes = $ragStmt->get_result();
        while ($d = $ragRes->fetch_assoc()) {
            $matchedDocs[] = $d;
        }
        $ragStmt->close();
    }

    // 2. RAG Generation / Synthesis Step
    $filesResult = [];
    $baseUrl = getBaseUrl();
    foreach ($matchedDocs as $doc) {
        $filesResult[] = [
            "id" => (int) $doc["id"],
            "title" => $doc["title"],
            "format" => $doc["format"],
            "size" => $doc["file_size"],
            "dateText" => date("M j, Y", strtotime($doc["last_accessed"])),
            "priority" => $doc["priority"],
            "category" => $doc["category"],
            "file_url" => !empty($doc["file_path"]) ? ($baseUrl . $doc["file_path"]) : null
        ];
    }

    $aiResponseText = "";

    // 2. Local Python AI Document Q&A & Semantic Extraction
    $qaResponse = aiPerformDocumentQA($prompt, $matchedDocs);
    if (!empty($qaResponse["answer"])) {
        $aiResponseText = $qaResponse["answer"];
        if (!empty($qaResponse["category"]) && $category === "GENERAL") {
            $category = $qaResponse["category"];
        }
    } else {
        // Graceful heuristic fallback if microservice is offline
        if ($category === "ANALYSIS") {
            if (!empty($matchedDocs)) {
                $primaryDoc = $matchedDocs[0];
                $docTitle = $primaryDoc["title"];
                $contentSample = !empty($primaryDoc["content_text"]) ? $primaryDoc["content_text"] : "No text extracted yet.";
                
                $aiResponseText = "Here is the AI analysis for '{$docTitle}':\n" .
                    "• Document Type: {$primaryDoc['format']} ({$primaryDoc['total_pages']} pages, {$primaryDoc['file_size']})\n" .
                    "• Category & Priority: {$primaryDoc['category']} (Priority: {$primaryDoc['priority']})\n" .
                    "• Key Summary: " . (strlen($contentSample) > 200 ? mb_substr($contentSample, 0, 200) . "..." : $contentSample);
            } else {
                $aiResponseText = "I reviewed your repository, but could not find a specific document to analyze. Please upload or specify a file name.";
            }
        } elseif ($category === "SEARCH") {
            $count = count($matchedDocs);
            if ($count > 0) {
                $aiResponseText = "I found {$count} file" . ($count > 1 ? "s" : "") . " matching your request '{$prompt}'.";
            } else {
                $aiResponseText = "No documents found matching '{$prompt}'. Try checking spelling or searching by category.";
            }
        } else {
            // GENERAL
            if (!empty($matchedDocs)) {
                $docNames = implode(", ", array_slice(array_column($matchedDocs, "title"), 0, 3));
                $aiResponseText = "Based on your stored documents ({$docNames}), here is what I found regarding '{$prompt}'. Let me know if you would like me to summarize or prioritize them.";
            } else {
                $aiResponseText = "Hello! I am your IntelliFile AI Assistant. You can ask me to search, summarize, analyze, or organize your documents anytime.";
            }
        }
    }

    // 3. Conversation & Message Persistence
    if ($conversationId <= 0) {
        $convTitle = mb_substr($prompt, 0, 60);
        $convSnippet = mb_substr($aiResponseText, 0, 120);

        $createConv = $conn->prepare("INSERT INTO ai_conversations (user_id, title, snippet, category) VALUES (?, ?, ?, ?)");
        $createConv->bind_param("isss", $userId, $convTitle, $convSnippet, $category);
        $createConv->execute();
        $conversationId = $conn->insert_id;
        $createConv->close();
    } else {
        $updateConv = $conn->prepare("UPDATE ai_conversations SET snippet = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND user_id = ?");
        $snippetSample = mb_substr($aiResponseText, 0, 120);
        $updateConv->bind_param("sii", $snippetSample, $conversationId, $userId);
        $updateConv->execute();
        $updateConv->close();
    }

    // Insert user message
    $userMsgStmt = $conn->prepare("INSERT INTO ai_chat_messages (conversation_id, sender, message_text) VALUES (?, 'USER', ?)");
    $userMsgStmt->bind_param("is", $conversationId, $prompt);
    $userMsgStmt->execute();
    $userMsgStmt->close();

    // Insert AI response message
    $jsonFiles = !empty($filesResult) ? json_encode($filesResult) : null;
    $aiMsgStmt = $conn->prepare("INSERT INTO ai_chat_messages (conversation_id, sender, message_text, related_files_json) VALUES (?, 'AI', ?, ?)");
    $aiMsgStmt->bind_param("iss", $conversationId, $aiResponseText, $jsonFiles);
    $aiMsgStmt->execute();
    $aiMsgId = $conn->insert_id;
    $aiMsgStmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "conversation_id" => $conversationId,
        "category" => $category,
        "message" => [
            "id" => $aiMsgId,
            "sender" => "AI",
            "text" => $aiResponseText,
            "files" => $filesResult,
            "created_at" => date("Y-m-d H:i:s")
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "AI query processing failed."
    ]);
}

