<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only GET or POST methods are allowed."
    ]);
}

$userId = requireAuth($conn);
$input = getJsonInput();

$query = trim($input["query"] ?? $input["q"] ?? $_GET["query"] ?? $_GET["q"] ?? "");

if ($query === "") {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Please enter a search query."
    ]);
}

try {
    // Search user's documents matching title or content
    $searchTerm = "%" . $query . "%";
    $words = explode(" ", $query);
    $extraConditions = [];
    $extraParams = [];
    $extraTypes = "";

    foreach ($words as $w) {
        $cleanWord = trim($w);
        if (strlen($cleanWord) >= 2) {
            $extraConditions[] = "(title LIKE ? OR content_text LIKE ? OR category LIKE ?)";
            $wLike = "%" . $cleanWord . "%";
            $extraParams[] = $wLike;
            $extraParams[] = $wLike;
            $extraParams[] = $wLike;
            $extraTypes .= "sss";
        }
    }

    $whereExtra = !empty($extraConditions) ? (" OR " . implode(" OR ", $extraConditions)) : "";

    $sql = "SELECT id, title, format, file_size, file_path, category, location, priority, total_pages, content_text, last_accessed 
            FROM documents 
            WHERE user_id = ? AND (title LIKE ? OR content_text LIKE ? OR category LIKE ?{$whereExtra})
            ORDER BY 
                CASE 
                    WHEN title = ? THEN 1
                    WHEN title LIKE ? THEN 2
                    WHEN category LIKE ? THEN 3
                    ELSE 4
                END,
                priority = 'IMPORTANT' DESC,
                last_accessed DESC
            LIMIT 20";

    $stmt = $conn->prepare($sql);
    
    $fullExact = $query;
    $fullLike = "%" . $query . "%";
    
    $allParams = array_merge([$userId, $searchTerm, $searchTerm, $searchTerm], $extraParams, [$fullExact, $fullLike, $fullLike]);
    $allTypes = "isss" . $extraTypes . "sss";

    $stmt->bind_param($allTypes, ...$allParams);
    $stmt->execute();
    $result = $stmt->get_result();

    $matchedFiles = [];
    $baseUrl = getBaseUrl();

    while ($row = $result->fetch_assoc()) {
        $fileUrl = !empty($row["file_path"]) ? ($baseUrl . $row["file_path"]) : null;

        $matchedFiles[] = [
            "id" => (int) $row["id"],
            "title" => $row["title"],
            "format" => $row["format"],
            "size" => $row["file_size"],
            "dateText" => date("M j, Y", strtotime($row["last_accessed"])),
            "priority" => $row["priority"],
            "category" => $row["category"],
            "location" => $row["location"],
            "file_url" => $fileUrl,
            "total_pages" => (int) $row["total_pages"],
            "snippet" => !empty($row["content_text"]) ? (mb_substr($row["content_text"], 0, 150) . "...") : "Document content ready for indexing."
        ];
    }
    $stmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "query" => $query,
        "count" => count($matchedFiles),
        "results" => $matchedFiles
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "AI search query failed."
    ]);
}

