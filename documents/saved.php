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
$priorityFilter = strtoupper(trim($_GET["priority"] ?? "ALL"));

$sql = "SELECT id, title, format, file_size, file_path, category, location, priority, is_bookmarked, is_saved, last_accessed, created_at 
        FROM documents 
        WHERE user_id = ? AND (is_saved = 1 OR is_bookmarked = 1)";

$params = [$userId];
$types = "i";

if ($search !== "") {
    $sql .= " AND title LIKE ?";
    $like = "%" . $search . "%";
    $params[] = $like;
    $types .= "s";
}

if (in_array($priorityFilter, ["IMPORTANT", "NORMAL", "LOW"])) {
    $sql .= " AND priority = ?";
    $params[] = $priorityFilter;
    $types .= "s";
}

$sql .= " ORDER BY last_accessed DESC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $savedFiles = [];
    $baseUrl = getBaseUrl();

    while ($row = $result->fetch_assoc()) {
        $fileUrl = !empty($row["file_path"]) ? ($baseUrl . $row["file_path"]) : null;

        $savedFiles[] = [
            "id" => (int) $row["id"],
            "title" => $row["title"],
            "size" => $row["file_size"],
            "format" => $row["format"],
            "dateText" => date("M j, Y", strtotime($row["last_accessed"])),
            "priority" => $row["priority"],
            "is_bookmarked" => (bool) $row["is_bookmarked"],
            "is_saved" => (bool) $row["is_saved"],
            "category" => $row["category"],
            "location" => $row["location"],
            "file_url" => $fileUrl
        ];
    }
    $stmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "count" => count($savedFiles),
        "saved_documents" => $savedFiles
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to fetch saved documents."
    ]);
}

