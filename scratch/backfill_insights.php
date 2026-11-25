<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_helper.php';
require_once __DIR__ . '/../config/text_extractor.php';

$res = $conn->query("SELECT id, title, format, file_path, content_text, insight_status FROM documents");
$count = 0;
while ($row = $res->fetch_assoc()) {
    $id = (int)$row['id'];
    $title = $row['title'];
    $format = $row['format'];
    $content = $row['content_text'] ?? '';

    if (empty($content) && !empty($row['file_path'])) {
        $diskPath = __DIR__ . '/../' . ltrim($row['file_path'], "/\\");
        if (file_exists($diskPath)) {
            $ext = strtolower(pathinfo($diskPath, PATHINFO_EXTENSION));
            $extRes = extractDocumentText($diskPath, $ext);
            if (!empty($extRes['text'])) {
                $content = $extRes['text'];
                $uStmt = $conn->prepare("UPDATE documents SET content_text = ? WHERE id = ?");
                $uStmt->bind_param("si", $content, $id);
                $uStmt->execute();
                $uStmt->close();
            }
        }
    }

    $insight = aiGenerateDocumentInsight($title, $format, $content);
    saveDocumentInsight($conn, $id, $insight);
    $count++;
    echo "[$count] Document #$id ($title) => Insight generated and saved (Status: READY)\n";
}

echo "Backfill finished. Total documents updated: $count\n";
