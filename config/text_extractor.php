<?php

/**
 * IntelliFile Universal Document Text Extractor
 * Reliable server-side text & structure extraction for all supported formats:
 * PDF, PPT/PPTX, DOC/DOCX, XLS/XLSX, CSV, TSV, TXT, JSON, XML, HTML/HTM, RTF, MD, LOG, and Generic.
 */

require_once __DIR__ . "/ai_helper.php";

if (!defined("MAX_DOCUMENT_FILE_SIZE")) {
    define("MAX_DOCUMENT_FILE_SIZE", 25 * 1024 * 1024); // 25 MB
}
if (!defined("MAX_EXTRACTED_TEXT_CHARS")) {
    define("MAX_EXTRACTED_TEXT_CHARS", 250000); // 250k chars for comprehensive DB indexing
}

/**
 * Extracts embedded text from images using native Windows OCR engine.
 */
function extractTextFromImage(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'Image file is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $ocrScript = __DIR__ . '/../ai_service/ocr_helper.ps1';
    $ext = strtoupper(pathinfo($filePath, PATHINFO_EXTENSION));
    $text = "";

    if (file_exists($ocrScript)) {
        $realPath = realpath($filePath);
        $cmd = 'powershell.exe -ExecutionPolicy Bypass -NoProfile -File ' . escapeshellarg($ocrScript) . ' -ImagePath ' . escapeshellarg($realPath);
        $output = @shell_exec($cmd);
        if ($output !== null) {
            $text = trim($output);
        }
    }

    $safeText = mb_substr($text, 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => 1,
        'char_count' => mb_strlen($safeText),
        'is_scanned' => empty($safeText),
        'warning' => empty($safeText) ? 'No text detected in image via OCR.' : null,
        'format' => $ext
    ];
}

/**
 * Extracts text and metadata from DOCX (Word Document) including headings, paragraphs, and tables.
 */
function extractTextFromDocx(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $zip = new ZipArchive();
    $openRes = $zip->open($filePath);
    if ($openRes !== true) {
        return ['success' => false, 'error' => 'Corrupted or invalid DOCX archive (Error ' . $openRes . ').', 'text' => '', 'page_count' => 0];
    }

    $documentXml = $zip->getFromName('word/document.xml');
    if ($documentXml === false) {
        $zip->close();
        return ['success' => false, 'error' => 'Invalid DOCX: missing word/document.xml.', 'text' => '', 'page_count' => 0];
    }

    $pageCount = 1;
    $appXml = $zip->getFromName('docProps/app.xml');
    if ($appXml !== false) {
        if (preg_match('/<Pages>([0-9]+)<\/Pages>/i', $appXml, $matches)) {
            $pageCount = max(1, intval($matches[1]));
        }
    }

    $zip->close();

    $dom = new DOMDocument();
    @$dom->loadXML($documentXml, LIBXML_NOERROR | LIBXML_NOWARNING);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $lines = [];
    $bodyNodes = $xpath->query('//w:body/*');

    if ($bodyNodes && $bodyNodes->length > 0) {
        foreach ($bodyNodes as $node) {
            if ($node->nodeName === 'w:p') {
                $pText = '';
                $textNodes = $xpath->query('.//w:t', $node);
                foreach ($textNodes as $t) {
                    $pText .= $t->nodeValue;
                }
                $cleanP = trim($pText);
                if ($cleanP !== '') {
                    // Check if heading
                    $styleNodes = $xpath->query('.//w:pStyle/@w:val', $node);
                    $style = $styleNodes->length > 0 ? strtolower($styleNodes->item(0)->nodeValue) : '';
                    if (str_contains($style, 'heading') || str_contains($style, 'title')) {
                        $lines[] = "## " . $cleanP;
                    } else {
                        $lines[] = $cleanP;
                    }
                }
            } elseif ($node->nodeName === 'w:tbl') {
                // Table extraction
                $tableRows = [];
                $rows = $xpath->query('.//w:tr', $node);
                foreach ($rows as $row) {
                    $cells = [];
                    $cellNodes = $xpath->query('.//w:tc', $row);
                    foreach ($cellNodes as $cell) {
                        $cText = '';
                        $tNodes = $xpath->query('.//w:t', $cell);
                        foreach ($tNodes as $t) {
                            $cText .= $t->nodeValue;
                        }
                        $cells[] = trim($cText);
                    }
                    if (!empty($cells) && implode('', $cells) !== '') {
                        $tableRows[] = "| " . implode(" | ", $cells) . " |";
                    }
                }
                if (!empty($tableRows)) {
                    $lines[] = implode("\n", $tableRows);
                }
            }
        }
    }

    if (empty($lines)) {
        $clean = strip_tags(str_replace(['</w:p>', '</w:tr>'], ["\n", "\n"], $documentXml));
        $fullText = preg_replace('/[ \t]+/', ' ', $clean);
    } else {
        $fullText = implode("\n\n", $lines);
    }

    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'DOCX'
    ];
}

/**
 * Extracts printable text from legacy binary DOC (Word 97-2003 OLE) files.
 */
function extractTextFromDoc(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false || strlen($raw) === 0) {
        return ['success' => false, 'error' => 'Unable to read DOC file.', 'text' => '', 'page_count' => 0];
    }

    // Scan for printable UTF-16LE and ASCII text chunks
    $extractedChunks = [];
    
    // 1. Try UTF-16LE scanner (Word binary stores lots of text in UTF-16LE)
    if (preg_match_all('/(?:[\x20-\x7E]\x00){4,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $utf8 = @mb_convert_encoding($match, 'UTF-8', 'UTF-16LE');
            $clean = trim($utf8);
            if (mb_strlen($clean) >= 6 && !preg_match('/^[0-9a-fA-F_.\s-]+$/', $clean)) {
                $extractedChunks[] = $clean;
            }
        }
    }

    // 2. Scan ASCII runs
    if (preg_match_all('/[a-zA-Z0-9\s.,!?:;\'"()_\-\/]{8,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $clean = trim($match);
            if (mb_strlen($clean) >= 12 && !in_array($clean, $extractedChunks)) {
                $extractedChunks[] = $clean;
            }
        }
    }

    $fullText = implode("\n", array_unique($extractedChunks));
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 3000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'DOC'
    ];
}

/**
 * Extracts text and slide count from PPTX (PowerPoint Presentation) with slide-by-slide structure.
 */
function extractTextFromPptx(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['success' => false, 'error' => 'Corrupted or invalid PPTX presentation.', 'text' => '', 'page_count' => 0];
    }

    $slides = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('/^ppt\/slides\/slide([0-9]+)\.xml$/i', $name, $matches)) {
            $slides[intval($matches[1])] = $name;
        }
    }

    ksort($slides);
    $slideCount = count($slides);
    if ($slideCount === 0) {
        $slideCount = 1;
    }

    $allSlides = [];
    foreach ($slides as $slideNum => $slidePath) {
        $xmlContent = $zip->getFromName($slidePath);
        if ($xmlContent !== false) {
            $dom = new DOMDocument();
            @$dom->loadXML($xmlContent, LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('p', 'http://schemas.openxmlformats.org/presentationml/2006/main');
            $xpath->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');

            $titleText = "";
            $bodyParagraphs = [];

            // Check shape paragraphs
            $shapes = $xpath->query('//p:sp');
            if ($shapes && $shapes->length > 0) {
                foreach ($shapes as $shape) {
                    $phType = '';
                    $phNodes = $xpath->query('.//p:ph/@type', $shape);
                    if ($phNodes->length > 0) {
                        $phType = strtolower($phNodes->item(0)->nodeValue);
                    }

                    $pNodes = $xpath->query('.//a:p', $shape);
                    foreach ($pNodes as $p) {
                        $pStr = '';
                        $tNodes = $xpath->query('.//a:t', $p);
                        foreach ($tNodes as $t) {
                            $pStr .= $t->nodeValue;
                        }
                        $clean = trim($pStr);
                        if ($clean !== '') {
                            if (($phType === 'title' || $phType === 'ctrtitle') && $titleText === '') {
                                $titleText = $clean;
                            } else {
                                $bodyParagraphs[] = "• " . $clean;
                            }
                        }
                    }
                }
            } else {
                // Fallback drawing text elements
                $textNodes = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't');
                foreach ($textNodes as $t) {
                    $val = trim($t->nodeValue);
                    if ($val !== '') {
                        $bodyParagraphs[] = "• " . $val;
                    }
                }
            }

            $slideOutput = "Slide " . $slideNum . ($titleText !== "" ? ": " . $titleText : "");
            if (!empty($bodyParagraphs)) {
                $slideOutput .= "\n" . implode("\n", $bodyParagraphs);
            }
            $allSlides[] = $slideOutput;
        }
    }

    $zip->close();
    $fullText = implode("\n\n", $allSlides);
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $slideCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'PPTX'
    ];
}

/**
 * Extracts printable text from legacy binary PPT (PowerPoint 97-2003) files.
 */
function extractTextFromPpt(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read PPT file.', 'text' => '', 'page_count' => 0];
    }

    $extractedChunks = [];
    if (preg_match_all('/(?:[\x20-\x7E]\x00){4,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $utf8 = @mb_convert_encoding($match, 'UTF-8', 'UTF-16LE');
            $clean = trim($utf8);
            if (mb_strlen($clean) >= 4 && !preg_match('/^[0-9a-fA-F_.\s-]+$/', $clean)) {
                $extractedChunks[] = $clean;
            }
        }
    }

    if (preg_match_all('/[a-zA-Z0-9\s.,!?:;\'"()_\-\/]{6,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $clean = trim($match);
            if (mb_strlen($clean) >= 8 && !in_array($clean, $extractedChunks)) {
                $extractedChunks[] = $clean;
            }
        }
    }

    $fullText = implode("\n", array_unique($extractedChunks));
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 2000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'PPT'
    ];
}

/**
 * Extracts text, sheet names, headers, and tabular row cells from XLSX (Excel Spreadsheet).
 */
function extractTextFromXlsx(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['success' => false, 'error' => 'Corrupted or invalid XLSX workbook.', 'text' => '', 'page_count' => 0];
    }

    // 1. Read Shared Strings lookup table
    $sharedStrings = [];
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsXml !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($sharedStringsXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $siElements = $dom->getElementsByTagName('si');
        foreach ($siElements as $si) {
            $siText = '';
            $tElements = $si->getElementsByTagName('t');
            foreach ($tElements as $t) {
                $siText .= $t->nodeValue;
            }
            $sharedStrings[] = trim($siText);
        }
    }

    // 2. Read Sheet names from workbook.xml
    $sheets = [];
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($workbookXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $sheetNodes = $dom->getElementsByTagName('sheet');
        $index = 1;
        foreach ($sheetNodes as $sNode) {
            $sName = $sNode->getAttribute('name') ?: ("Sheet" . $index);
            $sheets[] = [
                'name' => $sName,
                'file' => "xl/worksheets/sheet{$index}.xml"
            ];
            $index++;
        }
    }

    if (empty($sheets)) {
        // Fallback default sheet1
        $sheets[] = ['name' => 'Sheet1', 'file' => 'xl/worksheets/sheet1.xml'];
    }

    $sheetOutputs = [];
    $totalSheetCount = count($sheets);

    // 3. Parse cell contents from each sheet
    foreach ($sheets as $sInfo) {
        $sheetXml = $zip->getFromName($sInfo['file']);
        if ($sheetXml === false) {
            continue;
        }

        $dom = new DOMDocument();
        @$dom->loadXML($sheetXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = $xpath->query('//x:sheetData/x:row');
        $sheetRows = [];
        $rowCount = 0;

        foreach ($rows as $row) {
            $rowNum = $row->getAttribute('r') ?: ($rowCount + 1);
            $cells = $xpath->query('.//x:c', $row);
            $rowValues = [];

            foreach ($cells as $c) {
                $type = $c->getAttribute('t'); // 's' = shared string, 'inlineStr', 'b', etc.
                $val = '';

                if ($type === 's') {
                    $vNodes = $c->getElementsByTagName('v');
                    if ($vNodes->length > 0) {
                        $sIdx = intval($vNodes->item(0)->nodeValue);
                        $val = $sharedStrings[$sIdx] ?? '';
                    }
                } elseif ($type === 'inlineStr') {
                    $tNodes = $c->getElementsByTagName('t');
                    if ($tNodes->length > 0) {
                        $val = $tNodes->item(0)->nodeValue;
                    }
                } else {
                    $vNodes = $c->getElementsByTagName('v');
                    if ($vNodes->length > 0) {
                        $val = $vNodes->item(0)->nodeValue;
                    }
                }

                $cleanVal = trim($val);
                if ($cleanVal !== '') {
                    $rowValues[] = $cleanVal;
                }
            }

            if (!empty($rowValues)) {
                $sheetRows[] = "Row {$rowNum}: " . implode(" | ", $rowValues);
                $rowCount++;
            }

            if ($rowCount >= 300) {
                $sheetRows[] = "... [Additional rows truncated for analysis]";
                break;
            }
        }

        if (!empty($sheetRows)) {
            $sheetOutputs[] = "=== Worksheet: {$sInfo['name']} (Rows: {$rowCount}) ===\n" . implode("\n", $sheetRows);
        }
    }

    $zip->close();

    if (empty($sheetOutputs) && !empty($sharedStrings)) {
        $fullText = "Excel Shared Content:\n" . implode("\n", array_unique($sharedStrings));
    } else {
        $fullText = implode("\n\n", $sheetOutputs);
    }

    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => max(1, $totalSheetCount),
        'char_count' => mb_strlen($safeText),
        'format' => 'XLSX'
    ];
}

/**
 * Extracts printable text and numbers from legacy binary XLS (Excel 97-2003 BIFF) files.
 */
function extractTextFromXls(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read XLS file.', 'text' => '', 'page_count' => 0];
    }

    $strings = [];
    // Scan for UTF-16LE strings
    if (preg_match_all('/(?:[\x20-\x7E]\x00){3,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $utf8 = @mb_convert_encoding($match, 'UTF-8', 'UTF-16LE');
            $clean = trim($utf8);
            if (mb_strlen($clean) >= 3 && !preg_match('/^[0-9a-fA-F_.\s-]+$/', $clean)) {
                $strings[] = $clean;
            }
        }
    }

    // Scan for ASCII strings
    if (preg_match_all('/[a-zA-Z0-9\s.,!?:;\'"()_\-\/%]{4,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $clean = trim($match);
            if (mb_strlen($clean) >= 4 && !in_array($clean, $strings)) {
                $strings[] = $clean;
            }
        }
    }

    $fullText = "Excel Data:\n" . implode(" | ", array_unique($strings));
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => 1,
        'char_count' => mb_strlen($safeText),
        'format' => 'XLS'
    ];
}

/**
 * Extracts structured tabular data from CSV or TSV files.
 */
function extractTextFromCsvOrTsv(string $filePath, string $ext): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty (0 bytes).', 'text' => '', 'page_count' => 0];
    }

    $upperExt = strtoupper($ext);
    $delimiter = ($upperExt === 'TSV') ? "\t" : ",";

    $handle = fopen($filePath, "r");
    if ($handle === false) {
        return ['success' => false, 'error' => 'Unable to read file.', 'text' => '', 'page_count' => 0];
    }

    // Delimiter auto-detection on first line if CSV
    if ($upperExt === 'CSV') {
        $firstLine = fgets($handle);
        if ($firstLine !== false) {
            $commaCount = substr_count($firstLine, ",");
            $semicolonCount = substr_count($firstLine, ";");
            $tabCount = substr_count($firstLine, "\t");
            if ($semicolonCount > $commaCount && $semicolonCount > $tabCount) {
                $delimiter = ";";
            } elseif ($tabCount > $commaCount) {
                $delimiter = "\t";
            }
            rewind($handle);
        }
    }

    $rows = [];
    $headers = [];
    $rowCount = 0;

    while (($data = fgetcsv($handle, 4000, $delimiter)) !== false && $rowCount < 800) {
        $cleanRow = array_map(function($v) {
            return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v));
        }, $data);

        $hasContent = false;
        foreach ($cleanRow as $v) {
            if ($v !== '') { $hasContent = true; break; }
        }

        if ($hasContent) {
            if (empty($headers)) {
                $headers = $cleanRow;
                $rows[] = "Headers: " . implode(" | ", $headers);
            } else {
                $rows[] = "Row {$rowCount}: " . implode(" | ", $cleanRow);
            }
            $rowCount++;
        }
    }
    fclose($handle);

    $summaryHeader = "Tabular Data ({$upperExt}) - Total Columns: " . count($headers) . ", Processed Rows: " . count($rows);
    $fullText = $summaryHeader . "\n\n" . implode("\n", $rows);
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(count($rows) / 50));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => $upperExt
    ];
}

/**
 * Extracts and structures JSON content into entities, keys, and values.
 */
function extractTextFromJson(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read JSON file.', 'text' => '', 'page_count' => 0];
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        // Fallback to raw text
        return [
            'success' => true,
            'text' => mb_substr(trim($raw), 0, MAX_EXTRACTED_TEXT_CHARS),
            'page_count' => 1,
            'char_count' => mb_strlen($raw),
            'format' => 'JSON'
        ];
    }

    $lines = [];
    $lines[] = "Structured JSON Document (Keys & Entities):";

    // If array of objects, summarize record structure
    if (isset($decoded[0]) && is_array($decoded[0])) {
        $keys = array_keys($decoded[0]);
        $lines[] = "Record Count: " . count($decoded);
        $lines[] = "Fields: " . implode(", ", $keys);
        $lines[] = "\nSample Records:";
        foreach (array_slice($decoded, 0, 50) as $idx => $record) {
            $recordPairs = [];
            foreach ($record as $k => $v) {
                if (is_scalar($v)) {
                    $recordPairs[] = "{$k}: {$v}";
                }
            }
            $lines[] = "Record #" . ($idx + 1) . ": " . implode("; ", $recordPairs);
        }
    } else {
        // Hierarchical key-value flattening
        $flatten = function($arr, $prefix = '') use (&$flatten, &$lines) {
            foreach ($arr as $k => $v) {
                $fullKey = $prefix ? "{$prefix}.{$k}" : $k;
                if (is_array($v)) {
                    $flatten($v, $fullKey);
                } elseif (is_scalar($v) && trim((string)$v) !== '') {
                    $lines[] = "{$fullKey}: {$v}";
                }
            }
        };
        $flatten($decoded);
    }

    $fullText = implode("\n", array_slice($lines, 0, 1500));
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 3000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'JSON'
    ];
}

/**
 * Extracts tags, elements, and text from XML documents.
 */
function extractTextFromXml(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read XML file.', 'text' => '', 'page_count' => 0];
    }

    $dom = new DOMDocument();
    if (@$dom->loadXML($raw, LIBXML_NOERROR | LIBXML_NOWARNING)) {
        $lines = [];
        $rootName = $dom->documentElement ? $dom->documentElement->nodeName : 'Root';
        $lines[] = "XML Document (Root: {$rootName}):";

        $xpath = new DOMXPath($dom);
        $elements = $xpath->query('//*[not(*) and normalize-space(text()) != ""]');
        if ($elements && $elements->length > 0) {
            foreach ($elements as $el) {
                $tag = $el->nodeName;
                $val = trim($el->nodeValue);
                if ($val !== '') {
                    $lines[] = "{$tag}: {$val}";
                }
                if (count($lines) >= 800) break;
            }
        }
        $fullText = implode("\n", $lines);
    } else {
        // Strip XML tags fallback
        $clean = strip_tags(str_replace('>', ">\n", $raw));
        $fullText = preg_replace('/\n\s*\n/', "\n", $clean);
    }

    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 3000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'XML'
    ];
}

/**
 * Extracts semantic titles, headings, paragraphs, lists, and tables from HTML/HTM documents.
 */
function extractTextFromHtml(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read HTML file.', 'text' => '', 'page_count' => 0];
    }

    $dom = new DOMDocument();
    // Load HTML safely
    @$dom->loadHTML(mb_convert_encoding($raw, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);

    // Remove scripts, styles, svg
    $xpath = new DOMXPath($dom);
    $scripts = $xpath->query('//script | //style | //svg | //canvas | //noscript');
    foreach ($scripts as $s) {
        $s->parentNode->removeChild($s);
    }

    $lines = [];
    $titleNode = $dom->getElementsByTagName('title');
    if ($titleNode->length > 0) {
        $lines[] = "# " . trim($titleNode->item(0)->nodeValue);
    }

    $headingsAndParas = $xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6 | //p | //li | //tr');
    if ($headingsAndParas && $headingsAndParas->length > 0) {
        foreach ($headingsAndParas as $node) {
            $text = trim(preg_replace('/\s+/', ' ', $node->nodeValue));
            if ($text === '') continue;

            $name = strtolower($node->nodeName);
            if (str_starts_with($name, 'h')) {
                $level = intval(substr($name, 1));
                $lines[] = str_repeat('#', $level) . " " . $text;
            } elseif ($name === 'li') {
                $lines[] = "• " . $text;
            } elseif ($name === 'tr') {
                $cells = [];
                foreach ($node->childNodes as $child) {
                    if (in_array(strtolower($child->nodeName), ['td', 'th'])) {
                        $cells[] = trim($child->nodeValue);
                    }
                }
                if (!empty($cells)) {
                    $lines[] = "| " . implode(" | ", $cells) . " |";
                }
            } else {
                $lines[] = $text;
            }
        }
    }

    if (empty($lines)) {
        $clean = strip_tags(str_replace(['<p>', '<div>', '<br>', '<tr>'], "\n", $raw));
        $fullText = preg_replace('/\n\s*\n/', "\n\n", $clean);
    } else {
        $fullText = implode("\n\n", $lines);
    }

    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 3000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'HTML'
    ];
}

/**
 * Extracts plain text from RTF (Rich Text Format) documents.
 */
function extractTextFromRtf(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read RTF file.', 'text' => '', 'page_count' => 0];
    }

    // Replace RTF paragraphs and line breaks
    $text = str_replace(['\par', '\line', '\tab'], ["\n", "\n", "\t"], $raw);

    // Decode hex-encoded characters \'xx
    $text = preg_replace_callback("/\\\'([0-9a-fA-F]{2})/", function($m) {
        return chr(hexdec($m[1]));
    }, $text);

    // Remove RTF control words
    $text = preg_replace('/\\\([a-zA-Z]+)(-?[0-9]+)?[ ]?/', '', $text);
    // Remove braces
    $text = str_replace(['{', '}'], '', $text);

    // Clean multiple whitespaces
    $clean = preg_replace('/[ \t]+/', ' ', $text);
    $clean = preg_replace('/\n\s*\n/', "\n\n", $clean);
    $safeText = mb_substr(trim($clean), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 3000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'RTF'
    ];
}

/**
 * Extracts text from plain text formats: TXT, MD, LOG.
 */
function extractTextFromPlainText(string $filePath, string $ext): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty (0 bytes).', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read file content.', 'text' => '', 'page_count' => 0];
    }

    // Normalize encoding to UTF-8
    $encoding = mb_detect_encoding($raw, ['UTF-8', 'ASCII', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding !== 'UTF-8') {
        $raw = mb_convert_encoding($raw, 'UTF-8', $encoding);
    }

    $upperExt = strtoupper($ext);
    $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
    $safeText = mb_substr(trim($cleanText), 0, MAX_EXTRACTED_TEXT_CHARS);
    $pageCount = max(1, (int) ceil(mb_strlen($safeText) / 3000));

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $pageCount,
        'char_count' => mb_strlen($safeText),
        'format' => $upperExt
    ];
}

/**
 * Universal fallback extractor for any other or unknown format.
 * Scans for printable ASCII and UTF-8 string chunks so no file is left behind.
 */
function extractTextGenericFallback(string $filePath, string $ext): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $raw = @file_get_contents($filePath);
    if ($raw === false) {
        return ['success' => false, 'error' => 'Unable to read file.', 'text' => '', 'page_count' => 0];
    }

    $upperExt = strtoupper($ext);
    $extracted = [];

    // Scan printable ASCII runs of 4+ characters
    if (preg_match_all('/[a-zA-Z0-9\s.,!?:;\'"()_\-\/@#$%&*+=]{4,}/', $raw, $matches)) {
        foreach ($matches[0] as $match) {
            $clean = trim($match);
            if (mb_strlen($clean) >= 4) {
                $extracted[] = $clean;
            }
        }
    }

    $fullText = implode("\n", array_unique($extracted));
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => max(1, (int) ceil(mb_strlen($safeText) / 3000)),
        'char_count' => mb_strlen($safeText),
        'format' => $upperExt,
        'is_fallback' => true
    ];
}

/**
 * Fallback PHP pure-stream PDF text extractor (runs if Python microservice is offline).
 */
function extractTextFromPdfFallback(string $filePath): array {
    $content = @file_get_contents($filePath);
    if ($content === false || strlen($content) === 0) {
        return ['success' => false, 'error' => 'Empty PDF file.', 'text' => '', 'page_count' => 0];
    }

    $pageCount = preg_match_all("/\/Type\s*\/Page[^s]/", $content, $matches);
    if ($pageCount === 0) {
        $pageCount = 1;
    }

    if (strpos($content, '/Encrypt') !== false) {
        return [
            'success' => false,
            'error' => 'Encrypted PDF (Password Protected).',
            'is_encrypted' => true,
            'text' => '',
            'page_count' => $pageCount
        ];
    }

    $text = "";
    // Uncompress FlateDecode streams if present
    if (preg_match_all('/<<[^>]*\/FlateDecode[^>]*>>\s*stream([\s\S]*?)endstream/', $content, $streamMatches)) {
        foreach ($streamMatches[1] as $stream) {
            $trimmedStream = ltrim($stream, "\r\n");
            $uncompressed = @gzuncompress($trimmedStream);
            if ($uncompressed !== false) {
                if (preg_match_all('/\((.*?)\)\s*Tj/', $uncompressed, $tjMatches)) {
                    $text .= implode(" ", $tjMatches[1]) . " ";
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/', $uncompressed, $tJMatches)) {
                    foreach ($tJMatches[1] as $tjArray) {
                        if (preg_match_all('/\((.*?)\)/', $tjArray, $inner)) {
                            $text .= implode("", $inner[1]) . " ";
                        }
                    }
                }
            }
        }
    }

    // Extract standard text objects inside BT (Begin Text) ... ET (End Text)
    if (preg_match_all('/BT[\s\S]*?ET/', $content, $matches)) {
        foreach ($matches[0] as $btBlock) {
            if (preg_match_all('/\((.*?)\)\s*Tj/', $btBlock, $tjMatches)) {
                $text .= implode(" ", $tjMatches[1]) . " ";
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/', $btBlock, $tJMatches)) {
                foreach ($tJMatches[1] as $tjArray) {
                    if (preg_match_all('/\((.*?)\)/', $tjArray, $inner)) {
                        $text .= implode("", $inner[1]) . " ";
                    }
                }
            }
        }
    }

    $clean = trim(preg_replace('/\s+/', ' ', $text));
    $isScanned = ($pageCount > 0 && strlen($clean) === 0);

    return [
        'success' => true,
        'text' => mb_substr($clean, 0, MAX_EXTRACTED_TEXT_CHARS),
        'page_count' => $pageCount,
        'char_count' => strlen($clean),
        'is_scanned' => $isScanned,
        'warning' => $isScanned ? 'Scanned document without text layer.' : null,
        'format' => 'PDF',
        'source' => 'php_pdf_fallback'
    ];
}

/**
 * Extracts text and page count from PDF using Python microservice (pypdf) with PHP stream fallback.
 */
function extractTextFromPdf(string $filePath): array {
    $aiRes = aiExtractPdfText($filePath);
    if ($aiRes && isset($aiRes['success'])) {
        if ($aiRes['success']) {
            return [
                'success' => true,
                'text' => $aiRes['text'] ?? '',
                'page_count' => intval($aiRes['page_count'] ?? 1),
                'char_count' => intval($aiRes['char_count'] ?? 0),
                'is_scanned' => !empty($aiRes['is_scanned']),
                'warning' => $aiRes['warning'] ?? null,
                'format' => 'PDF',
                'source' => 'python_pypdf'
            ];
        } else {
            return [
                'success' => false,
                'error' => $aiRes['error'] ?? 'PDF extraction failed.',
                'is_encrypted' => !empty($aiRes['is_encrypted']),
                'is_corrupted' => !empty($aiRes['is_corrupted']),
                'text' => '',
                'page_count' => intval($aiRes['page_count'] ?? 0)
            ];
        }
    }

    return extractTextFromPdfFallback($filePath);
}

/**
 * Master Universal Extraction Router.
 * Extracts content from PDF, PPT, PPTX, DOC, DOCX, XLS, XLSX, CSV, TSV, TXT, JSON, XML, HTML, RTF, MD, LOG, and Generic formats.
 */
function extractDocumentText(string $filePath, string $ext): array {
    if (!file_exists($filePath)) {
        return ['success' => false, 'error' => 'File does not exist on disk.', 'text' => '', 'page_count' => 0];
    }

    $size = filesize($filePath);
    if ($size === 0) {
        return ['success' => false, 'error' => 'Uploaded file is completely empty (0 bytes).', 'text' => '', 'page_count' => 0];
    }

    if ($size > MAX_DOCUMENT_FILE_SIZE) {
        return ['success' => false, 'error' => 'File size exceeds maximum permitted limit of 25 MB.', 'text' => '', 'page_count' => 0];
    }

    $cleanExt = strtolower(trim($ext, ". \t\n\r"));

    // Route by file extension
    switch ($cleanExt) {
        case 'pdf':
            return extractTextFromPdf($filePath);

        case 'docx':
            return extractTextFromDocx($filePath);

        case 'doc':
            return extractTextFromDoc($filePath);

        case 'pptx':
            return extractTextFromPptx($filePath);

        case 'ppt':
            return extractTextFromPpt($filePath);

        case 'xlsx':
            return extractTextFromXlsx($filePath);

        case 'xls':
            return extractTextFromXls($filePath);

        case 'csv':
        case 'tsv':
            return extractTextFromCsvOrTsv($filePath, $cleanExt);

        case 'json':
            return extractTextFromJson($filePath);

        case 'xml':
            return extractTextFromXml($filePath);

        case 'html':
        case 'htm':
            return extractTextFromHtml($filePath);

        case 'rtf':
            return extractTextFromRtf($filePath);

        case 'txt':
        case 'log':
        case 'md':
            return extractTextFromPlainText($filePath, $cleanExt);

        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'webp':
            return extractTextFromImage($filePath);

        default:
            return extractTextGenericFallback($filePath, $cleanExt);
    }
}
