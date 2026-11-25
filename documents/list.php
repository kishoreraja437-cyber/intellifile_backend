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

$sql = "SELECT id, user_id, title, format, file_size, file_path, category, location, priority, is_bookmarked, is_saved, total_pages, content_text, 
               insight_status, insight_overview, insight_topics, insight_key_points, insight_details, insight_terms, insight_conclusion, insight_error,
               last_accessed, created_at 
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
} elseif ($formatFilter === "PPT") {
    $sql .= " AND (format = 'PPT' OR format = 'PPTX')";
} elseif ($formatFilter === "OTHER") {
    $sql .= " AND format NOT IN ('PDF', 'DOC', 'DOCX', 'PPT', 'PPTX')";
}

// Filter by category
if ($categoryFilter !== "" && strtolower($categoryFilter) !== "all") {
    $sql .= " AND category = ?";
    $params[] = $categoryFilter;
    $types .= "s";
}

// Sorting
if ($sort === "title") {
    $sql .= " ORDER BY title ASC, id DESC";
} elseif ($sort === "size") {
    $sql .= " ORDER BY file_size DESC, id DESC";
} else {
    $sql .= " ORDER BY last_accessed DESC, id DESC";
}

try {
    $stmt = $conn->prepare($sql);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $documents = [];
    $seenTitles = [];
    $baseUrl = getBaseUrl();

    while ($row = $result->fetch_assoc()) {
        $cleanTitle = trim($row["title"] ?? "");
        $normKey = strtolower($cleanTitle);
        // Avoid duplicate counting while including all valid unique stored files
        if ($normKey !== "" && isset($seenTitles[$normKey])) {
            continue;
        }
        $seenTitles[$normKey] = true;

        $fileUrl = !empty($row["file_path"]) ? ($baseUrl . $row["file_path"]) : null;
        $contentText = $row["content_text"] ?? "";
        $hasText = !empty($contentText);

        $topics = json_decode($row["insight_topics"] ?? "[]", true);
        if (!is_array($topics)) $topics = [];
        $keyPoints = json_decode($row["insight_key_points"] ?? "[]", true);
        if (!is_array($keyPoints)) $keyPoints = [];
        $details = json_decode($row["insight_details"] ?? "[]", true);
        if (!is_array($details)) $details = [];
        $terms = json_decode($row["insight_terms"] ?? "[]", true);
        if (!is_array($terms)) $terms = [];

        $documents[] = [
            "id" => (int) $row["id"],
            "user_id" => (int) $row["user_id"],
            "title" => $cleanTitle,
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
            "insight_status" => $row["insight_status"] ?: "PENDING",
            "insight_overview" => $row["insight_overview"] ?: "",
            "insight_topics" => $topics,
            "insight_key_points" => $keyPoints,
            "insight_details" => $details,
            "insight_terms" => $terms,
            "insight_conclusion" => $row["insight_conclusion"] ?: "",
            "insight_error" => $row["insight_error"],
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

