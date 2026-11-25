<?php
require_once __DIR__ . '/test_insight_engine.php';

$pptxPath = 'c:/xampp/htdocs/intellifile_backend/uploads/documents/6c5fda478d39669e4d77bfe5_StockSenseProductReview.pptx';
$pptxExtracted = extractDocumentText($pptxPath, 'pptx');
$pptxInsight = generateStructuredInsightPhpFallback("StockSenseProductReview.pptx", "PPTX", $pptxExtracted['text']);

echo "=== INSIGHT FOR PPTX ===\n";
echo "OVERVIEW: " . $pptxInsight['overview'] . "\n\n";
echo "MAIN TOPICS: " . implode(" | ", $pptxInsight['main_topics']) . "\n\n";
echo "KEY POINTS:\n- " . implode("\n- ", $pptxInsight['key_points']) . "\n\n";
echo "CONCLUSION: " . $pptxInsight['conclusion'] . "\n\n";

$csvPath = 'c:/xampp/htdocs/intellifile_backend/uploads/documents/d8f17681ffafb4fed1c7e111_salary_slip.csv';
$csvExtracted = extractDocumentText($csvPath, 'csv');
$csvInsight = generateStructuredInsightPhpFallback("salary_slip.csv", "CSV", $csvExtracted['text']);

echo "=== INSIGHT FOR CSV ===\n";
echo "OVERVIEW: " . $csvInsight['overview'] . "\n\n";
echo "MAIN TOPICS: " . implode(" | ", $csvInsight['main_topics']) . "\n\n";
echo "KEY POINTS:\n- " . implode("\n- ", $csvInsight['key_points']) . "\n\n";
echo "IMPORTANT DETAILS:\n- " . implode("\n- ", $csvInsight['important_details']) . "\n\n";
