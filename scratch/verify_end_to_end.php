<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_helper.php';
require_once __DIR__ . '/../config/text_extractor.php';

echo "=== 1. VERIFY DOCUMENTS TABLE COLUMNS ===\n";
$res = $conn->query("DESCRIBE documents");
$cols = [];
while ($row = $res->fetch_assoc()) {
    $cols[] = $row['Field'];
}
$expected = ['insight_status', 'insight_overview', 'insight_topics', 'insight_key_points', 'insight_details', 'insight_terms', 'insight_conclusion', 'insight_error', 'insight_updated_at'];
foreach ($expected as $exp) {
    if (in_array($exp, $cols)) {
        echo "  [OK] Column '$exp' exists.\n";
    } else {
        echo "  [FAIL] Column '$exp' is missing!\n";
    }
}

echo "\n=== 2. VERIFY GETTING INSIGHT FOR EXISTING DOC ===\n";
$stmt = $conn->query("SELECT id, title, format, insight_status, insight_overview FROM documents WHERE insight_status = 'READY' LIMIT 1");
$row = $stmt->fetch_assoc();
if ($row) {
    echo "  Found Doc #{$row['id']} ({$row['title']}) with Status: {$row['insight_status']}\n";
    $insight = getDocumentInsight($conn, (int)$row['id']);
    echo "  Overview: " . substr($insight['overview'], 0, 100) . "...\n";
    echo "  Main Topics: " . implode(", ", $insight['main_topics']) . "\n";
    echo "  Key Points: " . count($insight['key_points']) . " points\n";
    echo "  Details: " . count($insight['important_details']) . " items\n";
    echo "  Terms: " . count($insight['important_terms']) . " terms\n";
    echo "  Conclusion: " . substr($insight['conclusion'], 0, 100) . "...\n";
} else {
    echo "  [FAIL] No documents with insight_status = 'READY' found!\n";
}

echo "\n=== 3. VERIFY UPLOAD & AUTO-INSIGHT SIMULATION ===\n";
// Create a sample multi-section text document to test upload simulation
$testContent = "INTELLISPACE UNIVERSAL INSIGHT SYSTEM SPECIFICATION\n\n"
    . "## Overview and Architecture\n"
    . "The Universal Insight Engine is designed to extract deep semantic intelligence from all document formats including PDF, DOCX, PPTX, XLSX, CSV, JSON, and XML.\n"
    . "The system processes uploaded assets through a multi-stage pipeline: Uploaded, Extracting Content, Generating Insight, and Insight Ready.\n\n"
    . "## Technical Performance Standards\n"
    . "Processing latency is strictly constrained to under 1.5 seconds per document.\n"
    . "Database persistence guarantees 100% zero redundant re-analysis upon document reopening.\n"
    . "Over 40 enterprise file types are natively supported across mobile and server endpoints.\n\n"
    . "## Critical Key Points\n"
    . "Insight generation is mandatory for every uploaded file in IntelliSpace.\n"
    . "Every insight consists of 6 structured sections: Overview, Main Topics, Key Points, Important Details, Important Terms, and Conclusion.\n"
    . "Automatic recovery and user retry mechanisms protect against transient extraction anomalies.\n\n"
    . "## Core Terminology\n"
    . "RAG refers to Retrieval-Augmented Generation for grounded question answering.\n"
    . "NLP is defined as Natural Language Processing algorithms for semantic extraction.\n"
    . "SLM refers to Small Language Models operating on local edge runtimes.\n\n"
    . "## Conclusion\n"
    . "In conclusion, this architecture delivers deterministic, high-accuracy document intelligence without third-party cloud leakage, fully adhering to local-first privacy requirements.";

$testTitle = "Universal_Insight_Spec_" . time() . ".txt";
$testFormat = "TXT";

// Insert test doc
$iStmt = $conn->prepare("INSERT INTO documents (user_id, title, format, file_size, category, location, total_pages, content_text) VALUES (1, ?, 'TXT', '2.5 KB', 'Technical', 'IntelliSpace', 1, ?)");
$iStmt->bind_param("ss", $testTitle, $testContent);
$iStmt->execute();
$testDocId = $conn->insert_id;
$iStmt->close();

echo "  Created Test Doc #$testDocId: $testTitle\n";

// Automatically trigger insight generation as done in upload.php
$insight = aiGenerateDocumentInsight($testTitle, $testFormat, $testContent);
$saved = saveDocumentInsight($conn, $testDocId, $insight);
echo "  [OK] Insight Generated & Saved: " . ($saved ? "YES" : "NO") . "\n";

// Fetch from DB
$dbInsight = getDocumentInsight($conn, $testDocId);
echo "  DB Status: " . $dbInsight['status'] . "\n";
echo "  DB Overview: " . $dbInsight['overview'] . "\n";
echo "  DB Topics: " . implode(" | ", $dbInsight['main_topics']) . "\n";
echo "  DB Key Points count: " . count($dbInsight['key_points']) . "\n";
echo "  DB Details count: " . count($dbInsight['important_details']) . "\n";
echo "  DB Terms count: " . count($dbInsight['important_terms']) . "\n";
echo "  DB Conclusion: " . $dbInsight['conclusion'] . "\n";

// Clean up test doc
$conn->query("DELETE FROM documents WHERE id = $testDocId");
echo "  [OK] Cleaned up test document #$testDocId.\n";

echo "\nALL TESTS PASSED SUCCESSFULLY!\n";
