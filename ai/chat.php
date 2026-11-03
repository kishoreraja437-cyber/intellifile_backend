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
    // Priority Command Handling (Strictly two ways to set priority: 1. Manual, 2. AI Chat Prompt)
    $priorityCmd = detectPriorityCommand($prompt);
    $isPriorityExplQuery = !$priorityCmd && (
        preg_match('/^(?:how\s+(?:can|do)\s+i\s+set|how\s+to\s+set)\s+(?:the\s+)?priority/i', $prompt) ||
        preg_match('/^(?:how\s+(?:can|do)\s+i\s+change|how\s+to\s+change)\s+(?:the\s+)?priority/i', $prompt) ||
        preg_match('/^can\s+you\s+set\s+(?:the\s+)?priority/i', $prompt)
    );

    if ($priorityCmd !== null || $isPriorityExplQuery) {
        $baseUrl = getBaseUrl();
        $filesResult = [];

        if ($isPriorityExplQuery) {
            $aiResponseText = "In IntelliFile, there are strictly two ways to set a file's priority:\n\n" .
                "1. **Manual**: Open the document in AI Vault or File Details, tap **Priority**, and choose **Important**, **Normal**, or **Low**.\n" .
                "2. **AI Chat Prompt**: You can ask me anytime right here! For example, try:\n" .
                "   • *\"Set priority of AML Assignment.pdf to important\"*\n" .
                "   • *\"Mark notes.docx as normal priority\"*\n" .
                "   • *\"Change priority of report.pdf to low\"*\n\n" .
                "Files uploaded to AI Vault are never automatically assigned a priority. They remain unassigned until you choose to set one.";
            $category = "GENERAL";
            $queryType = "APP_DATA_QUERY";
        } elseif ($priorityCmd['action'] === 'SET') {
            $targetPriority = $priorityCmd['priority'];
            $targetFile = $priorityCmd['target_file'];
            $matchedDoc = findUserDocumentByTitle($conn, $userId, $targetFile);

            if ($matchedDoc) {
                $upStmt = $conn->prepare("UPDATE documents SET priority = ? WHERE id = ? AND user_id = ?");
                $upStmt->bind_param("sii", $targetPriority, $matchedDoc['id'], $userId);
                $upStmt->execute();
                $upStmt->close();

                $aiResponseText = "I have updated the priority of '**{$matchedDoc['title']}**' to **{$targetPriority}** as requested.\n\nYou can view and filter this file under the {$targetPriority} priority section in your AI Vault and Saved screen.";
                $category = "ANALYSIS";
                $queryType = "APP_DATA_QUERY";
                $filesResult = [
                    [
                        "id" => (int) $matchedDoc["id"],
                        "title" => $matchedDoc["title"],
                        "format" => $matchedDoc["format"],
                        "size" => $matchedDoc["file_size"],
                        "dateText" => "Priority: " . $targetPriority,
                        "priority" => $targetPriority,
                        "category" => $matchedDoc["category"],
                        "file_path" => $matchedDoc["file_path"],
                        "source" => "AI Vault",
                        "file_url" => !empty($matchedDoc["file_path"]) ? ($baseUrl . $matchedDoc["file_path"]) : null
                    ]
                ];
            } else {
                $aiResponseText = "I couldn't find a document matching '{$targetFile}' in your AI Vault. Please check the document name and try again.";
                $category = "SEARCH";
                $queryType = "APP_DATA_QUERY";
            }
        } elseif ($priorityCmd['action'] === 'CLEAR') {
            $targetFile = $priorityCmd['target_file'];
            $matchedDoc = findUserDocumentByTitle($conn, $userId, $targetFile);

            if ($matchedDoc) {
                $upStmt = $conn->prepare("UPDATE documents SET priority = NULL WHERE id = ? AND user_id = ?");
                $upStmt->bind_param("ii", $matchedDoc['id'], $userId);
                $upStmt->execute();
                $upStmt->close();

                $aiResponseText = "I have cleared the priority for '**{$matchedDoc['title']}**'. It is now unassigned.";
                $category = "ANALYSIS";
                $queryType = "APP_DATA_QUERY";
                $filesResult = [
                    [
                        "id" => (int) $matchedDoc["id"],
                        "title" => $matchedDoc["title"],
                        "format" => $matchedDoc["format"],
                        "size" => $matchedDoc["file_size"],
                        "dateText" => "Priority: Unassigned",
                        "priority" => null,
                        "category" => $matchedDoc["category"],
                        "file_path" => $matchedDoc["file_path"],
                        "source" => "AI Vault",
                        "file_url" => !empty($matchedDoc["file_path"]) ? ($baseUrl . $matchedDoc["file_path"]) : null
                    ]
                ];
            } else {
                $aiResponseText = "I couldn't find a document matching '{$targetFile}' in your AI Vault.";
                $category = "SEARCH";
                $queryType = "APP_DATA_QUERY";
            }
        } elseif ($priorityCmd['action'] === 'CHECK') {
            $targetFile = $priorityCmd['target_file'];
            $matchedDoc = findUserDocumentByTitle($conn, $userId, $targetFile);

            if ($matchedDoc) {
                $currentPrio = $matchedDoc['priority'];
                if (!empty($currentPrio)) {
                    $aiResponseText = "The current priority of '**{$matchedDoc['title']}**' is **{$currentPrio}**.";
                } else {
                    $aiResponseText = "The priority of '**{$matchedDoc['title']}**' is currently **Not Set** (unassigned). You can set it manually or ask me: *\"Set priority of {$matchedDoc['title']} to Important\"*.";
                }
                $category = "ANALYSIS";
                $queryType = "APP_DATA_QUERY";
                $filesResult = [
                    [
                        "id" => (int) $matchedDoc["id"],
                        "title" => $matchedDoc["title"],
                        "format" => $matchedDoc["format"],
                        "size" => $matchedDoc["file_size"],
                        "dateText" => !empty($currentPrio) ? "Priority: $currentPrio" : "Priority: Not Set",
                        "priority" => $currentPrio,
                        "category" => $matchedDoc["category"],
                        "file_path" => $matchedDoc["file_path"],
                        "source" => "AI Vault",
                        "file_url" => !empty($matchedDoc["file_path"]) ? ($baseUrl . $matchedDoc["file_path"]) : null
                    ]
                ];
            } else {
                $aiResponseText = "I couldn't find a document matching '{$targetFile}' in your AI Vault.";
                $category = "SEARCH";
                $queryType = "APP_DATA_QUERY";
            }
        }

        // Conversation & message persistence for priority action
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

        $userMsgStmt = $conn->prepare("INSERT INTO ai_chat_messages (conversation_id, sender, message_text) VALUES (?, 'USER', ?)");
        $userMsgStmt->bind_param("is", $conversationId, $prompt);
        $userMsgStmt->execute();
        $userMsgStmt->close();

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
            "query_type" => $queryType,
            "message" => [
                "id" => $aiMsgId,
                "sender" => "AI",
                "text" => $aiResponseText,
                "files" => $filesResult,
                "created_at" => date("Y-m-d H:i:s")
            ]
        ]);
        exit;
    }

    // File Search / Filter / Open Command Handling
    $searchIntent = detectFileSearchIntent($prompt);
    if ($searchIntent !== null) {
        $searchAction = $searchIntent["action"];
        $searchKeyword = $searchIntent["query"];
        $formatFilter = $searchIntent["format"];

        $matchedFiles = searchUserVaultDocuments($conn, $userId, $searchKeyword, $formatFilter);

        // If SQL text match didn't find files, use RAG semantic retrieval on document content
        if (empty($matchedFiles) && $searchAction !== "FILTER") {
            $docStmt = $conn->prepare("SELECT id, title, format, file_size, file_path, category, priority, total_pages, content_text FROM documents WHERE user_id = ? AND content_text IS NOT NULL AND content_text != ''");
            $docStmt->bind_param("i", $userId);
            $docStmt->execute();
            $dRes = $docStmt->get_result();
            $userDocs = [];
            while ($row = $dRes->fetch_assoc()) {
                $userDocs[] = $row;
            }
            $docStmt->close();

            if (!empty($userDocs)) {
                aiSyncUserDocuments($userId, $userDocs);
                $ragRes = aiRunRagChat($userId, $prompt, [], null);
                if (!empty($ragRes["sources"])) {
                    $baseUrl = getBaseUrl();
                    $seenDocIds = [];
                    foreach ($ragRes["sources"] as $s) {
                        $sDocId = (int) ($s["id"] ?? 0);
                        if (in_array($sDocId, $seenDocIds)) continue;
                        $seenDocIds[] = $sDocId;

                        foreach ($userDocs as $ud) {
                            if ((int)$ud["id"] === $sDocId) {
                                if (!empty($formatFilter) && !str_contains(strtoupper($ud["format"]), strtoupper($formatFilter))) {
                                    continue;
                                }
                                $matchedFiles[] = [
                                    "id" => $sDocId,
                                    "title" => $ud["title"],
                                    "format" => $ud["format"],
                                    "size" => $ud["file_size"],
                                    "dateText" => date("M j, Y", strtotime($ud["last_accessed"] ?? $ud["created_at"])),
                                    "priority" => $ud["priority"],
                                    "category" => $ud["category"],
                                    "location" => $ud["location"] ?? "Internal Storage",
                                    "file_url" => !empty($ud["file_path"]) ? ($baseUrl . $ud["file_path"]) : null,
                                    "file_path" => $ud["file_path"],
                                    "source" => "AI Vault",
                                    "similarity" => $s["similarity"] ?? 0.0,
                                    "snippet" => $s["snippet"] ?? (mb_substr($ud["content_text"], 0, 150) . "...")
                                ];
                                break;
                            }
                        }
                    }
                }
            }
        }

        $count = count($matchedFiles);
        $filesResult = $matchedFiles;
        $category = "SEARCH";
        $queryType = "FILE_SEARCH";

        if ($searchAction === "OPEN") {
            if ($count === 1) {
                $docName = $matchedFiles[0]["title"];
                $aiResponseText = "Opening '**{$docName}**'. You can view and read the full document below.";
            } elseif ($count > 1) {
                $aiResponseText = "I found **{$count} files** matching '**{$searchKeyword}**'. Tap a file card below to open it:";
            } else {
                $aiResponseText = "No matching files found for '**{$searchKeyword}**' in your AI Vault. Please verify the filename or upload the document.";
            }
        } elseif ($searchAction === "FILTER") {
            $fmtLabel = $formatFilter ?: "matching";
            if ($count > 0) {
                $aiResponseText = "Here are your **{$count} {$fmtLabel} file" . ($count > 1 ? "s" : "") . "** stored in your AI Vault:";
            } else {
                $aiResponseText = "No {$fmtLabel} files found in your AI Vault.";
            }
        } else {
            // SEARCH
            if ($count > 0) {
                $aiResponseText = "I found **{$count} matching file" . ($count > 1 ? "s" : "") . "** for '**{$searchKeyword}**' in your AI Vault:";
            } else {
                $aiResponseText = "No matching files found for '**{$searchKeyword}**'. Try another filename or keyword.";
            }
        }

        // Conversation & message persistence for search
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

        $userMsgStmt = $conn->prepare("INSERT INTO ai_chat_messages (conversation_id, sender, message_text) VALUES (?, 'USER', ?)");
        $userMsgStmt->bind_param("is", $conversationId, $prompt);
        $userMsgStmt->execute();
        $userMsgStmt->close();

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
            "query_type" => $queryType,
            "message" => [
                "id" => $aiMsgId,
                "sender" => "AI",
                "text" => $aiResponseText,
                "files" => $filesResult,
                "created_at" => date("Y-m-d H:i:s")
            ]
        ]);
        exit;
    }

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

    // 1. Fetch conversation history for follow-up questions
    $conversationHistory = [];
    if ($conversationId > 0) {
        $histStmt = $conn->prepare("
            SELECT sender, message_text 
            FROM ai_chat_messages 
            WHERE conversation_id = ? 
            ORDER BY id ASC 
            LIMIT 6
        ");
        $histStmt->bind_param("i", $conversationId);
        $histStmt->execute();
        $hRes = $histStmt->get_result();
        while ($hr = $hRes->fetch_assoc()) {
            $conversationHistory[] = [
                "sender" => $hr["sender"],
                "text" => $hr["message_text"]
            ];
        }
        $histStmt->close();
    }

    // 2. Fetch live IntelliFile statistics for this authenticated user
    $appStats = getUserAppStatistics($conn, $userId);

    // 3. Synchronize user's existing vault documents to vector store
    // (Only indexes unindexed documents; deduplicated by content hash)
    $userDocs = [];
    $docStmt = $conn->prepare("
        SELECT id, title, format, file_size, file_path, category, priority, total_pages, content_text 
        FROM documents 
        WHERE user_id = ? AND content_text IS NOT NULL AND content_text != ''
    ");
    $docStmt->bind_param("i", $userId);
    $docStmt->execute();
    $dRes = $docStmt->get_result();
    while ($row = $dRes->fetch_assoc()) {
        $userDocs[] = $row;
    }
    $docStmt->close();

    if (!empty($userDocs)) {
        aiSyncUserDocuments($userId, $userDocs);
    }

    // 4. Execute Hybrid Assistant Pipeline (Classification -> SLM / App Stats / RAG / Hybrid)
    $hybridResponse = aiRunRagChat($userId, $prompt, $conversationHistory, null, $appStats);

    $aiResponseText = "";
    $filesResult = [];
    $baseUrl = getBaseUrl();
    $queryType = $hybridResponse["query_type"] ?? "DOCUMENT_QUERY";

    if ($hybridResponse && !empty($hybridResponse["success"])) {
        $aiResponseText = $hybridResponse["answer"];
        $category = $hybridResponse["category"] ?? $category;

        // Map files/sources depending on query type
        if ($queryType === "GENERAL_QUERY") {
            // General questions must NOT attach files or trigger retrieval
            $filesResult = [];
        } elseif ($queryType === "APP_DATA_QUERY") {
            // If recent files or file list requested, attach formatted file list
            $attached = $hybridResponse["files"] ?? [];
            foreach ($attached as $af) {
                $filesResult[] = [
                    "id" => (int) ($af["id"] ?? 0),
                    "title" => $af["title"] ?? "Document",
                    "format" => $af["format"] ?? "PDF",
                    "size" => $af["size"] ?? "1.0 MB",
                    "dateText" => $af["dateText"] ?? "Recent",
                    "priority" => $af["priority"] ?? null,
                    "category" => $af["category"] ?? "General",
                    "file_url" => $af["file_url"] ?? null,
                    "source" => "AI Vault"
                ];
            }
        } else {
            // DOCUMENT_QUERY or HYBRID_QUERY: attach sources with page citations
            $sources = $hybridResponse["sources"] ?? $hybridResponse["files"] ?? [];
            foreach ($sources as $s) {
                $docId = (int) ($s["id"] ?? 0);
                $matchedMeta = null;
                foreach ($userDocs as $ud) {
                    if ((int)$ud["id"] === $docId) {
                        $matchedMeta = $ud;
                        break;
                    }
                }

                $pageStr = (!empty($s["page"]) && $s["page"] > 0) ? "Page " . $s["page"] : null;
                $dateOrPage = implode(" · ", array_filter([$s["format"] ?? "PDF", $pageStr, $matchedMeta["file_size"] ?? null]));

                $filesResult[] = [
                    "id" => $docId,
                    "title" => $s["title"] ?? ($matchedMeta["title"] ?? "Document"),
                    "format" => $s["format"] ?? ($matchedMeta["format"] ?? "PDF"),
                    "size" => $matchedMeta["file_size"] ?? "1.0 MB",
                    "page" => $s["page"] ?? 1,
                    "dateText" => !empty($dateOrPage) ? $dateOrPage : "Recent",
                    "similarity" => $s["similarity"] ?? 0.0,
                    "snippet" => $s["snippet"] ?? "",
                    "priority" => $matchedMeta["priority"] ?? null,
                    "category" => $matchedMeta["category"] ?? "General",
                    "file_url" => !empty($matchedMeta["file_path"]) ? ($baseUrl . $matchedMeta["file_path"]) : null,
                    "file_path" => $matchedMeta["file_path"] ?? null,
                    "source" => "AI Vault"
                ];
            }
        }
    } else {
        // Fallback if AI service is offline
        if ($category === "ANALYSIS") {
            if (!empty($userDocs)) {
                $primaryDoc = $userDocs[0];
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
            $count = count($userDocs);
            if ($count > 0) {
                $aiResponseText = "I found {$count} file" . ($count > 1 ? "s" : "") . " in your documents matching your request.";
            } else {
                $aiResponseText = "No documents found matching '{$prompt}'. Try checking spelling or searching by category.";
            }
        } else {
            $aiResponseText = "I searched your documents, but could not find information regarding '{$prompt}' in your uploaded files.";
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
        "query_type" => $queryType,
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

