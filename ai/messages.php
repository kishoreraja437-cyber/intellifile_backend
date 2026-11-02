<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);
}

$userId = requireAuth($conn);

$convId = intval($_GET["conversation_id"] ?? $_GET["id"] ?? 0);

if ($convId <= 0) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Invalid or missing conversation_id parameter."
    ]);
}

try {
    // 1. Verify that conversation belongs to the authenticated user
    $convStmt = $conn->prepare("
        SELECT id, user_id, title, snippet, category, created_at, updated_at 
        FROM ai_conversations 
        WHERE id = ? AND user_id = ?
    ");
    $convStmt->bind_param("ii", $convId, $userId);
    $convStmt->execute();
    $conv = $convStmt->get_result()->fetch_assoc();
    $convStmt->close();

    if (!$conv) {
        sendJsonResponse(404, [
            "success" => false,
            "message" => "Conversation not found or access denied."
        ]);
    }

    // 2. Fetch all messages in chronological order
    $msgStmt = $conn->prepare("
        SELECT id, sender, message_text, related_files_json, created_at 
        FROM ai_chat_messages 
        WHERE conversation_id = ? 
        ORDER BY id ASC
    ");
    $msgStmt->bind_param("i", $convId);
    $msgStmt->execute();
    $result = $msgStmt->get_result();

    $messages = [];
    while ($row = $result->fetch_assoc()) {
        $files = [];
        if (!empty($row["related_files_json"])) {
            $decoded = json_decode($row["related_files_json"], true);
            if (is_array($decoded)) {
                $files = $decoded;
            }
        }

        $messages[] = [
            "id" => (int) $row["id"],
            "sender" => $row["sender"],
            "text" => $row["message_text"],
            "time" => date("h:i A", strtotime($row["created_at"])),
            "created_at" => $row["created_at"],
            "files" => $files
        ];
    }
    $msgStmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "conversation" => [
            "id" => (int) $conv["id"],
            "title" => $conv["title"],
            "snippet" => $conv["snippet"] ?? "",
            "category" => $conv["category"],
            "dateText" => date("M j, Y", strtotime($conv["updated_at"])),
            "timeText" => date("h:i A", strtotime($conv["updated_at"])),
            "created_at" => $conv["created_at"]
        ],
        "count" => count($messages),
        "messages" => $messages
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to load conversation messages."
    ]);
}

