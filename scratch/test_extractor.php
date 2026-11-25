<?php
require_once __DIR__ . '/../config/text_extractor.php';

$testFiles = [
    'uploads/documents/b3b8151d5c05d79d0a3096c0_rental_agreement.json' => 'json',
    'uploads/documents/d8f17681ffafb4fed1c7e111_salary_slip.csv' => 'csv',
    'uploads/documents/cc00dd74bd47b23e12eba375_IntelliFile_Project_Document.docx' => 'docx',
    'uploads/documents/6c5fda478d39669e4d77bfe5_StockSenseProductReview.pptx' => 'pptx',
    'uploads/documents/cebc243334e701533a150f55_security_keys.txt' => 'txt',
];

foreach ($testFiles as $rel => $ext) {
    $full = 'c:/xampp/htdocs/intellifile_backend/' . $rel;
    if (file_exists($full)) {
        $res = extractDocumentText($full, $ext);
        echo "[$ext] " . basename($rel) . " => success: " . ($res['success'] ? 'YES' : 'NO') . ", chars: " . strlen($res['text']) . ", pages: " . ($res['page_count'] ?? 0) . "\n";
        echo "   Snippet: " . substr(str_replace("\n", " ", $res['text']), 0, 100) . "...\n";
    } else {
        echo "[$ext] Not found: $full\n";
    }
}
