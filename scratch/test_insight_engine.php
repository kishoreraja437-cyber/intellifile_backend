<?php

function extractSentences(string $text): array {
    $clean = preg_replace('/[ \t]+/', ' ', $text);
    $raw = preg_split('/(?<=[.!?\n])\s+/', $clean, -1, PREG_SPLIT_NO_EMPTY);
    $sentences = [];
    foreach ($raw as $s) {
        $trimmed = trim($s, " \t\n\r\0\x0B-•*|#");
        if (mb_strlen($trimmed) >= 15 && mb_strlen($trimmed) <= 350) {
            $sentences[] = $trimmed;
        }
    }
    return $sentences;
}

function generateStructuredInsightPhpFallback(string $title, string $format, string $contentText): array {
    $cleanTitle = trim($title);
    $upperFormat = strtoupper(trim($format));
    $sentences = extractSentences($contentText);
    $totalChars = mb_strlen($contentText);

    // 1. Overview Synthesis
    $overview = "";
    if (!empty($sentences)) {
        // Take top 2-3 most representative sentences from beginning or executive summary
        $introCandidates = [];
        foreach ($sentences as $s) {
            $lower = strtolower($s);
            if (str_contains($lower, 'overview') || str_contains($lower, 'summary') || str_contains($lower, 'purpose') || str_contains($lower, 'introduction') || str_contains($lower, 'is an') || str_contains($lower, 'designed to')) {
                $introCandidates[] = $s;
            }
        }
        if (count($introCandidates) >= 2) {
            $overview = implode(" ", array_slice($introCandidates, 0, 3));
        } else {
            $overview = implode(" ", array_slice($sentences, 0, min(3, count($sentences))));
        }
    }
    if (empty($overview)) {
        $overview = "{$cleanTitle} is a {$upperFormat} document securely processed in IntelliSpace containing {$totalChars} characters.";
    }

    // 2. Main Topics Extraction
    $topics = [];
    // Check for markdown headings, slide labels, or capitalized section markers
    if (preg_match_all('/(?:##|#|===|Worksheet:|Slide\s+\d+:?)\s*([^\n\r]+)/i', $contentText, $hMatches)) {
        foreach ($hMatches[1] as $h) {
            $hClean = trim(strip_tags($h), " \t\n\r-•*:=#|");
            if (mb_strlen($hClean) >= 3 && mb_strlen($hClean) <= 65 && !in_array($hClean, $topics)) {
                $topics[] = $hClean;
            }
            if (count($topics) >= 6) break;
        }
    }
    // If no headings found, extract high frequency topical phrases
    if (count($topics) < 3) {
        $stopWords = ['the','and','for','that','this','with','from','have','were','which','their','about','these','document','file'];
        preg_match_all('/\b[A-Z][a-zA-Z0-9-]{3,}\b/', $contentText, $wMatches);
        $counts = array_count_values(array_map('strtolower', $wMatches[0]));
        arsort($counts);
        foreach ($counts as $word => $cnt) {
            if (!in_array($word, $stopWords) && $cnt >= 2) {
                $cap = ucwords($word);
                if (!in_array($cap, $topics)) {
                    $topics[] = $cap;
                }
            }
            if (count($topics) >= 5) break;
        }
    }
    if (empty($topics)) {
        $topics = [ucwords(pathinfo($cleanTitle, PATHINFO_FILENAME)), "{$upperFormat} Content Analysis", "Document Structure"];
    }

    // 3. Key Points Extraction
    $keyPoints = [];
    $importanceKeywords = ['important', 'key', 'critical', 'feature', 'system', 'result', 'finding', 'provides', 'includes', 'requirement', 'objective', 'architecture', 'model', 'benefit'];
    $scoredSentences = [];
    foreach ($sentences as $idx => $s) {
        $score = 0;
        $lower = strtolower($s);
        foreach ($importanceKeywords as $kw) {
            if (str_contains($lower, $kw)) $score += 2;
        }
        if (preg_match('/[0-9]+/', $s)) $score += 1;
        // Moderate length bias
        if (mb_strlen($s) > 40 && mb_strlen($s) < 200) $score += 1;
        $scoredSentences[] = ['text' => $s, 'score' => $score, 'index' => $idx];
    }
    usort($scoredSentences, fn($a, $b) => $b['score'] <=> $a['score']);
    $topSentences = array_slice($scoredSentences, 0, 7);
    usort($topSentences, fn($a, $b) => $a['index'] <=> $b['index']);
    foreach ($topSentences as $item) {
        $keyPoints[] = $item['text'];
    }
    if (empty($keyPoints)) {
        $keyPoints = array_slice($sentences, 0, min(5, count($sentences)));
    }

    // 4. Important Details (Numbers, statistics, dates, metrics, facts)
    $details = [];
    foreach ($sentences as $s) {
        // Search for numbers, percentages, currency, dates
        if (preg_match('/(?:\$|€|₹|Rs\.?|USD|\b\d+(?:\.\d+)?%|\b\d{4}\b|\b\d+,\d+|\b\d+\s*(?:MB|KB|GB|users|records|days|months|hours|parameters|accuracy)\b)/i', $s)) {
            if (!in_array($s, $details) && !in_array($s, $keyPoints)) {
                $details[] = $s;
            }
        }
        if (count($details) >= 6) break;
    }
    // Tabular row details fallback
    if (count($details) < 3 && preg_match_all('/(?:Row\s+\d+|Headers?):[^\n]+/i', $contentText, $rowMatches)) {
        foreach (array_slice($rowMatches[0], 0, 5) as $rm) {
            $details[] = trim($rm);
        }
    }
    if (empty($details)) {
        $details = ["Document format: {$upperFormat}", "Extracted text volume: {$totalChars} characters", "Indexed sections: " . count($topics)];
    }

    // 5. Important Terms & Definitions
    $terms = [];
    // Acronyms (e.g. AI, RAG, NLP, API, CPU, HTTP, SQL, ROI)
    preg_match_all('/\b([A-Z]{2,6})\b/', $contentText, $acronyms);
    $uniqueAcronyms = array_unique($acronyms[1]);
    $commonFilters = ['THE', 'AND', 'FOR', 'NOT', 'ALL', 'CAN', 'NEW', 'DOC', 'PDF', 'CSV', 'XLS', 'PPT', 'XML', 'TXT', 'ZIP'];
    foreach ($uniqueAcronyms as $acr) {
        if (!in_array($acr, $commonFilters)) {
            // Find context sentence for definition
            $defSentence = "";
            foreach ($sentences as $s) {
                if (preg_match("/\b{$acr}\b/", $s)) {
                    $defSentence = $s;
                    break;
                }
            }
            if ($defSentence) {
                $terms[] = "{$acr} — " . mb_substr($defSentence, 0, 140);
            } else {
                $terms[] = "{$acr} — Core terminology identified in {$cleanTitle}";
            }
        }
        if (count($terms) >= 6) break;
    }
    // Key-value definitions pattern: "Term: Definition" or "X is defined as Y"
    if (count($terms) < 4) {
        if (preg_match_all('/([A-Za-z0-9\s_-]{3,25})\s*(?::|is defined as|refers to)\s*([^\n.]{15,120})/i', $contentText, $defMatches)) {
            foreach ($defMatches[1] as $dIdx => $dTerm) {
                $dT = trim($dTerm);
                $dD = trim($defMatches[2][$dIdx]);
                if (mb_strlen($dT) <= 30 && !in_array($dT, $commonFilters)) {
                    $terms[] = "{$dT} — {$dD}";
                }
                if (count($terms) >= 6) break;
            }
        }
    }
    if (empty($terms)) {
        $terms = [
            "Document Domain — " . ($topics[0] ?? "General Analysis"),
            "Processing Tier — IntelliSpace Universal Extraction",
            "File Architecture — Structured {$upperFormat} Specification"
        ];
    }

    // 6. Conclusion
    $conclusion = "";
    if (count($sentences) > 3) {
        // Look for ending sentences or conclusion keywords
        $conclusionCandidates = [];
        foreach (array_slice($sentences, -5) as $s) {
            $lower = strtolower($s);
            if (str_contains($lower, 'conclu') || str_contains($lower, 'summary') || str_contains($lower, 'overall') || str_contains($lower, 'future') || str_contains($lower, 'recommend') || str_contains($lower, 'result') || str_contains($lower, 'thus') || str_contains($lower, 'therefore')) {
                $conclusionCandidates[] = $s;
            }
        }
        if (!empty($conclusionCandidates)) {
            $conclusion = implode(" ", $conclusionCandidates);
        } else {
            $conclusion = end($sentences);
        }
    }
    if (empty($conclusion) || mb_strlen($conclusion) < 20) {
        $conclusion = "In conclusion, {$cleanTitle} provides a comprehensive overview of its subject matter across {$upperFormat} format, organized into " . count($topics) . " core functional areas.";
    }

    return [
        'success' => true,
        'status' => 'READY',
        'title' => $cleanTitle,
        'format' => $upperFormat,
        'overview' => $overview,
        'main_topics' => array_values(array_unique($topics)),
        'key_points' => array_values(array_unique($keyPoints)),
        'important_details' => array_values(array_unique($details)),
        'important_terms' => array_values(array_unique($terms)),
        'conclusion' => $conclusion,
        'sentence_count' => count($sentences),
        'char_count' => $totalChars
    ];
}

require_once __DIR__ . '/../config/text_extractor.php';

$docxPath = 'c:/xampp/htdocs/intellifile_backend/uploads/documents/cc00dd74bd47b23e12eba375_IntelliFile_Project_Document.docx';
$docExtracted = extractDocumentText($docxPath, 'docx');
$insight = generateStructuredInsightPhpFallback("IntelliFile_Project_Document.docx", "DOCX", $docExtracted['text']);

echo "=== INSIGHT FOR DOCX ===\n";
echo "OVERVIEW: " . $insight['overview'] . "\n\n";
echo "MAIN TOPICS: " . implode(" | ", $insight['main_topics']) . "\n\n";
echo "KEY POINTS:\n- " . implode("\n- ", $insight['key_points']) . "\n\n";
echo "IMPORTANT DETAILS:\n- " . implode("\n- ", $insight['important_details']) . "\n\n";
echo "IMPORTANT TERMS:\n- " . implode("\n- ", $insight['important_terms']) . "\n\n";
echo "CONCLUSION: " . $insight['conclusion'] . "\n";
