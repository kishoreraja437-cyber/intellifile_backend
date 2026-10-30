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
$formatFilter = strtoupper(trim($_GET["format"] ?? "ALL"));
$categoryFilter = trim($_GET["category"] ?? "");
$sort = strtolower(trim($_GET["sort"] ?? "recent"));

$sql = "SELECT id, user_id, title, format, file_size, file_path, category, location, priority, is_bookmarked, is_saved, total_pages, content_text, last_accessed, created_at 
        FROM documents 
        WHERE user_id = ?";

$params = [$userId];
$types = "i";

// Filter by search query
if ($search !== "") {
    $sql .= " AND (title LIKE ? OR content_text LIKE ? OR category LIKE ?)";
    $like = "%" . $search . "%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

// Filter by priority
if (in_array($priorityFilter, ["IMPORTANT", "NORMAL", "LOW"])) {
    $sql .= " AND priority = ?";
    $params[] = $priorityFilter;
    $types .= "s";
}

// Filter by format
if ($formatFilter === "PDF") {
    $sql .= " AND format = 'PDF'";
} elseif ($formatFilter === "DOC") {
    $sql .= " AND (format = 'DOC' OR format = 'DOCX')";
} elseif ($formatFilter === "OTHER") {
    $sql .= " AND format NOT IN ('PDF', 'DOC', 'DOCX')";
}

// Filter by category
if ($categoryFilter !== "" && strtolower($categoryFilter) !== "all") {
    $sql .= " AND category = ?";
    $params[] = $categoryFilter;
    $types .= "s";
}

// Sorting
if ($sort === "title") {
    $sql .= " ORDER BY title ASC";
} elseif ($sort === "size") {
    $sql .= " ORDER BY file_size DESC";
} else {
    $sql .= " ORDER BY last_accessed DESC";
}

try {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $documents = [];
    $baseUrl = getBaseUrl();

    while ($row = $result->fetch_assoc()) {
        $fileUrl = !empty($row["file_path"]) ? ($baseUrl . $row["file_path"]) : null;
        $contentText = $row["content_text"] ?? "";
        $hasText = !empty($contentText);
        $documents[] = [
            "id" => (int) $row["id"],
            "user_id" => (int) $row["user_id"],
            "title" => $row["title"],
            "format" => $row["format"],
            "size" => $row["file_size"],
            "file_size" => $row["file_size"],
            "file_path" => $row["file_path"],
            "file_url" => $fileUrl,
            "category" => $row["category"],
            "location" => $row["location"],
            "priority" => $row["priority"],
            "is_bookmarked" => (bool) $row["is_bookmarked"],
            "is_saved" => (bool) $row["is_saved"],
            "total_pages" => (int) $row["total_pages"],
            "content_text" => $contentText,
            "has_text" => $hasText,
            "content_text_length" => mb_strlen($contentText),
            "dateText" => date("M j, Y", strtotime($row["last_accessed"])),
            "created_at" => $row["created_at"],
            "last_accessed" => $row["last_accessed"]
        ];
    }
    $stmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "count" => count($documents),
        "documents" => $documents
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to fetch documents."
    ]);
}

