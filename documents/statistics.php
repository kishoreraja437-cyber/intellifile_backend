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

try {
    // Total Files count
    $stmt = $conn->prepare("SELECT COUNT(*) AS total FROM documents WHERE user_id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $totalRow = $stmt->get_result()->fetch_assoc();
    $totalFiles = (int) ($totalRow["total"] ?? 0);
    $stmt->close();

    // Priority counts
    $stmt = $conn->prepare("
        SELECT priority, COUNT(*) AS count 
        FROM documents 
        WHERE user_id = ? 
        GROUP BY priority
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $pResult = $stmt->get_result();

    $importantCount = 0;
    $normalCount = 0;
    $lowCount = 0;

    while ($row = $pResult->fetch_assoc()) {
        $p = strtoupper($row["priority"]);
        $c = (int) $row["count"];
        if ($p === "IMPORTANT") $importantCount = $c;
        elseif ($p === "NORMAL") $normalCount = $c;
        elseif ($p === "LOW") $lowCount = $c;
    }
    $stmt->close();

    // Calculate percentages
    $importantPct = ($totalFiles > 0) ? round(($importantCount / $totalFiles) * 100, 1) : 0;
    $normalPct = ($totalFiles > 0) ? round(($normalCount / $totalFiles) * 100, 1) : 0;
    $lowPct = ($totalFiles > 0) ? round(($lowCount / $totalFiles) * 100, 1) : 0;

    // Format counts
    $stmt = $conn->prepare("
        SELECT format, COUNT(*) AS count 
        FROM documents 
        WHERE user_id = ? 
        GROUP BY format
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $fResult = $stmt->get_result();
    $formats = [];
    while ($row = $fResult->fetch_assoc()) {
        $formats[$row["format"]] = (int) $row["count"];
    }
    $stmt->close();

    // Saved count
    $stmt = $conn->prepare("SELECT COUNT(*) AS saved FROM documents WHERE user_id = ? AND is_saved = 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $sRow = $stmt->get_result()->fetch_assoc();
    $savedCount = (int) ($sRow["saved"] ?? 0);
    $stmt->close();

    sendJsonResponse(200, [
        "success" => true,
        "statistics" => [
            "total_files" => $totalFiles,
            "saved_files" => $savedCount,
            "priority_summary" => [
                "important" => [
                    "title" => "Important",
                    "count" => $importantCount,
                    "percentage" => $importantPct,
                    "count_text" => "{$importantCount} ({$importantPct}%)"
                ],
                "normal" => [
                    "title" => "Normal",
                    "count" => $normalCount,
                    "percentage" => $normalPct,
                    "count_text" => "{$normalCount} ({$normalPct}%)"
                ],
                "low" => [
                    "title" => "Low",
                    "count" => $lowCount,
                    "percentage" => $lowPct,
                    "count_text" => "{$lowCount} ({$lowPct}%)"
                ]
            ],
            "formats" => $formats
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to calculate document statistics."
    ]);
}

