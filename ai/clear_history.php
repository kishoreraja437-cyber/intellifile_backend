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

try {
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

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to clear AI history."
    ]);
}

