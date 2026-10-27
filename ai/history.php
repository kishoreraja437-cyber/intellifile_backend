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

$search = trim($_GET["search"] ?? $_GET["q"] ?? "");
$category = strtoupper(trim($_GET["category"] ?? "ALL"));

$sql = "SELECT id, user_id, title, snippet, category, created_at, updated_at 
        FROM ai_conversations 
        WHERE user_id = ?";

$params = [$userId];
$types = "i";

if ($search !== "") {
    $sql .= " AND (title LIKE ? OR snippet LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $types .= "ss";
}

if (in_array($category, ["ANALYSIS", "SEARCH", "GENERAL"])) {
    $sql .= " AND category = ?";
    $params[] = $category;
    $types .= "s";
}

$sql .= " ORDER BY updated_at DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $historyItems = [];
    while ($row = $result->fetch_assoc()) {
        $historyItems[] = [
            "id" => (int) $row["id"],
            "title" => $row["title"],
            "snippet" => $row["snippet"] ?? "",
            "dateText" => date("M j, Y", strtotime($row["updated_at"])),
            "timeText" => date("h:i A", strtotime($row["updated_at"])),
            "category" => $row["category"],
            "created_at" => $row["created_at"]
        ];
    }
    $stmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "count" => count($historyItems),
        "history" => $historyItems
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to fetch AI chat history."
    ]);
}

