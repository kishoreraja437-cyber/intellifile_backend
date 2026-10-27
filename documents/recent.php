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

$limit = intval($_GET["limit"] ?? 10);
if ($limit <= 0 || $limit > 100) $limit = 10;

$search = trim($_GET["search"] ?? $_GET["q"] ?? "");
$formatFilter = strtoupper(trim($_GET["format"] ?? "ALL"));

$sql = "SELECT id, title, format, file_size, file_path, category, location, priority, is_bookmarked, is_saved, total_pages, last_accessed 
        FROM documents 
        WHERE user_id = ?";

$params = [$userId];
$types = "i";

if ($search !== "") {
    $sql .= " AND title LIKE ?";
    $like = "%" . $search . "%";
    $params[] = $like;
    $types .= "s";
}

if ($formatFilter === "PDF") {
    $sql .= " AND format = 'PDF'";
} elseif ($formatFilter === "DOC") {
    $sql .= " AND (format = 'DOC' OR format = 'DOCX')";
} elseif ($formatFilter === "PPT") {
    $sql .= " AND (format = 'PPT' OR format = 'PPTX')";
} elseif ($formatFilter === "OTHER") {
    $sql .= " AND format NOT IN ('PDF', 'DOC', 'DOCX', 'PPT', 'PPTX')";
}

$sql .= " ORDER BY last_accessed DESC LIMIT ?";
$params[] = $limit;
$types .= "i";

try {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $recentFiles = [];
    $baseUrl = getBaseUrl();

    while ($row = $result->fetch_assoc()) {
        $fileUrl = !empty($row["file_path"]) ? ($baseUrl . $row["file_path"]) : null;

        // Relative date text (e.g. Today, 1 day ago, Oct 5, 2024)
        $diff = time() - strtotime($row["last_accessed"]);
        if ($diff < 86400 && date("Ymd") === date("Ymd", strtotime($row["last_accessed"]))) {
            $dateText = "Today";
        } elseif ($diff < 172800) {
            $dateText = "1 day ago";
        } elseif ($diff < 604800) {
            $days = intval($diff / 86400);
            $dateText = $days . " days ago";
        } else {
            $dateText = date("M j, Y", strtotime($row["last_accessed"]));
        }

        $recentFiles[] = [
            "id" => (int) $row["id"],
            "title" => $row["title"],
            "format" => $row["format"],
            "size" => $row["file_size"],
            "dateText" => $dateText,
            "priority" => $row["priority"],
            "file_url" => $fileUrl,
            "is_saved" => (bool) $row["is_saved"],
            "is_bookmarked" => (bool) $row["is_bookmarked"],
            "total_pages" => (int) $row["total_pages"]
        ];
    }
    $stmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "count" => count($recentFiles),
        "recent_files" => $recentFiles
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to fetch recent files."
    ]);
}

