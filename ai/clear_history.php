<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST" && $_SERVER["REQUEST_METHOD"] !== "DELETE") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST or DELETE methods are allowed."
    ]);
}

$userId = requireAuth($conn);
$input = getJsonInput();
$convId = intval($input["conversation_id"] ?? $input["id"] ?? $_GET["conversation_id"] ?? $_GET["id"] ?? 0);

try {
    if ($convId > 0) {
        // Delete a specific conversation belonging to this user
        // 1. Verify ownership
        $checkStmt = $conn->prepare("SELECT id FROM ai_conversations WHERE id = ? AND user_id = ?");
        $checkStmt->bind_param("ii", $convId, $userId);
        $checkStmt->execute();
        $exists = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();

        if (!$exists) {
            sendJsonResponse(404, [
                "success" => false,
                "message" => "Conversation not found or unauthorized."
            ]);
        }

        // 2. Delete messages
        $delMsg = $conn->prepare("DELETE FROM ai_chat_messages WHERE conversation_id = ?");
        $delMsg->bind_param("i", $convId);
        $delMsg->execute();
        $delMsg->close();

        // 3. Delete conversation
        $delConv = $conn->prepare("DELETE FROM ai_conversations WHERE id = ? AND user_id = ?");
        $delConv->bind_param("ii", $convId, $userId);
        $delConv->execute();
        $affected = $delConv->affected_rows;
        $delConv->close();

        sendJsonResponse(200, [
            "success" => true,
            "message" => "Conversation deleted successfully.",
            "deleted_count" => $affected
        ]);
    } else {
        // Delete all conversations & associated messages for this user
        $delMsgStmt = $conn->prepare("
            DELETE m FROM ai_chat_messages m 
            INNER JOIN ai_conversations c ON m.conversation_id = c.id 
            WHERE c.user_id = ?
        ");
        $delMsgStmt->bind_param("i", $userId);
        $delMsgStmt->execute();
        $delMsgStmt->close();

        $stmt = $conn->prepare("DELETE FROM ai_conversations WHERE user_id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        sendJsonResponse(200, [
            "success" => true,
            "message" => "AI chat history cleared successfully.",
            "deleted_count" => $affected
        ]);
    }

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to clear AI history."
    ]);
}
