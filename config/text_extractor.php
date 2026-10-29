<?php

/**
 * IntelliFile Document Text Extractor
 * Reliable server-side text & page count extraction for:
 * PDF, DOCX, PPTX, XLSX, TXT, CSV, JSON, MD
 */

require_once __DIR__ . "/ai_helper.php";

define("MAX_DOCUMENT_FILE_SIZE", 25 * 1024 * 1024); // 25 MB
define("MAX_EXTRACTED_TEXT_CHARS", 100000); // 100k chars for DB

/**
 * Extracts text and metadata from DOCX (Word Document)
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

    // Try reading page count from app.xml
    $pageCount = 1;
    $appXml = $zip->getFromName('docProps/app.xml');
    if ($appXml !== false) {
        if (preg_match('/<Pages>([0-9]+)<\/Pages>/i', $appXml, $matches)) {
            $pageCount = max(1, intval($matches[1]));
        }
    }

    $zip->close();

    // Parse XML paragraphs and text runs
    $textParts = [];
    $dom = new DOMDocument();
    // Suppress XML warnings on malformed markup
    @$dom->loadXML($documentXml, LIBXML_NOERROR | LIBXML_NOWARNING);
    $paragraphs = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p');

    if ($paragraphs->length === 0) {
        // Fallback to strip_tags if namespace differs
        $clean = strip_tags(str_replace('</w:p>', "\n", $documentXml));
        $fullText = preg_replace('/[ \t]+/', ' ', $clean);
    } else {
        foreach ($paragraphs as $p) {
            $pText = '';
            $textNodes = $p->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
            foreach ($textNodes as $t) {
                $pText .= $t->nodeValue;
            }
            if (trim($pText) !== '') {
                $textParts[] = trim($pText);
            }
        }
        $fullText = implode("\n", $textParts);
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
 * Extracts text and slide count from PPTX (PowerPoint Presentation)
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

    $allText = [];
    foreach ($slides as $slideNum => $slidePath) {
        $xmlContent = $zip->getFromName($slidePath);
        if ($xmlContent !== false) {
            $dom = new DOMDocument();
            @$dom->loadXML($xmlContent, LIBXML_NOERROR | LIBXML_NOWARNING);
            $textNodes = $dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/drawingml/2006/main', 't');
            $slideWords = [];
            foreach ($textNodes as $t) {
                if (trim($t->nodeValue) !== '') {
                    $slideWords[] = trim($t->nodeValue);
                }
            }
            if (!empty($slideWords)) {
                $allText[] = "Slide {$slideNum}: " . implode(" ", $slideWords);
            }
        }
    }

    $zip->close();
    $fullText = implode("\n\n", $allText);
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
 * Extracts text and sheet count from XLSX (Excel Spreadsheet)
 */
function extractTextFromXlsx(string $filePath): array {
    if (!file_exists($filePath) || filesize($filePath) === 0) {
        return ['success' => false, 'error' => 'File is empty or does not exist.', 'text' => '', 'page_count' => 0];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return ['success' => false, 'error' => 'Corrupted or invalid XLSX workbook.', 'text' => '', 'page_count' => 0];
    }

    // Count worksheets
    $sheetCount = 1;
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml !== false) {
        if (preg_match_all('/<sheet /i', $workbookXml, $matches)) {
            $sheetCount = max(1, count($matches[0]));
        }
    }

    // Read shared strings
    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $strings = [];
    if ($sharedStringsXml !== false) {
        $dom = new DOMDocument();
        @$dom->loadXML($sharedStringsXml, LIBXML_NOERROR | LIBXML_NOWARNING);
        $tElements = $dom->getElementsByTagName('t');
        foreach ($tElements as $t) {
            $val = trim($t->nodeValue);
            if ($val !== '') {
                $strings[] = $val;
            }
        }
    }

    $zip->close();
    $fullText = implode(", ", array_unique($strings));
    $safeText = mb_substr(trim($fullText), 0, MAX_EXTRACTED_TEXT_CHARS);

    return [
        'success' => true,
        'text' => $safeText,
        'page_count' => $sheetCount,
        'char_count' => mb_strlen($safeText),
        'format' => 'XLSX'
    ];
}

/**
 * Extracts text from plain text formats: TXT, CSV, JSON, MD
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
    $text = "";

    if ($upperExt === "CSV") {
        $handle = fopen($filePath, "r");
        $rows = [];
        $count = 0;
        if ($handle !== false) {
            while (($data = fgetcsv($handle, 2000, ",")) !== false && $count < 1000) {
                $cleanRow = array_filter(array_map('trim', $data), fn($v) => $v !== '');
                if (!empty($cleanRow)) {
                    $rows[] = implode(" | ", $cleanRow);
                    $count++;
                }
            }
            fclose($handle);
        }
        $text = implode("\n", $rows);

    } elseif ($upperExt === "JSON") {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Flatten JSON into readable key-value sentences
            $flattened = [];
            array_walk_recursive($decoded, function($val, $key) use (&$flattened) {
                if (is_scalar($val) && trim((string)$val) !== '') {
                    $flattened[] = "{$key}: {$val}";
                }
            });
            $text = implode("\n", array_slice($flattened, 0, 1500));
        } else {
            $text = $raw; // Raw json string fallback
        }

    } else {
        // TXT, MD
        $text = $raw;
    }

    // Strip unprintable control characters except \r, \n, \t
    $cleanText = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
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
 * Fallback PHP pure-stream PDF text extractor (runs if Python microservice is offline)
 */
function extractTextFromPdfFallback(string $filePath): array {
    $content = @file_get_contents($filePath);
    if ($content === false || strlen($content) === 0) {
        return ['success' => false, 'error' => 'Empty PDF file.', 'text' => '', 'page_count' => 0];
    }

    // Count pages by counting /Type /Page (excluding /Pages)
    $pageCount = preg_match_all("/\/Type\s*\/Page[^s]/", $content, $matches);
    if ($pageCount === 0) {
        $pageCount = 1;
    }

    // Check encryption
    if (strpos($content, '/Encrypt') !== false) {
        return [
            'success' => false,
            'error' => 'Encrypted PDF (Password Protected).',
            'is_encrypted' => true,
            'text' => '',
            'page_count' => $pageCount
        ];
    }

    // Extract text objects inside BT (Begin Text) ... ET (End Text)
    $text = "";
    if (preg_match_all('/BT[\s\S]*?ET/', $content, $matches)) {
        foreach ($matches[0] as $btBlock) {
            // Extract Tj and TJ operators
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
        'warning' => $isScanned ? 'Scanned document without text layer. OCR required.' : null,
        'format' => 'PDF',
        'source' => 'php_pdf_fallback'
    ];
}

/**
 * Extracts text and page count from PDF using Python microservice (pypdf) with PHP stream fallback
 */
function extractTextFromPdf(string $filePath): array {
    // 1. Try Python AI Microservice with pypdf (100% compliant)
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
            // Encrypted or corrupted PDF reported by pypdf
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

    // 2. Pure PHP stream fallback if microservice is offline
    return extractTextFromPdfFallback($filePath);
}

/**
 * Master extraction router.
 * Validates file size, checks magic bytes / signatures, extracts text,
 * and returns clean metadata.
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

    // Magic Bytes & MIME type validation
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? finfo_file($finfo, $filePath) : "";
    if ($finfo) finfo_close($finfo);

    // Read first 8 bytes for signature check
    $handle = fopen($filePath, "rb");
    $magic = $handle ? fread($handle, 8) : "";
    if ($handle) fclose($handle);

    // Route based on format
    switch ($cleanExt) {
        case 'pdf':
            // Verify %PDF- signature
            if (strncmp($magic, "%PDF-", 5) !== 0) {
                return ['success' => false, 'error' => 'Invalid or spoofed PDF: missing %PDF- header.', 'text' => '', 'page_count' => 0];
            }
            return extractTextFromPdf($filePath);

        case 'docx':
            // Verify PK\x03\x04 zip signature
            if (strncmp($magic, "PK\x03\x04", 4) !== 0) {
                return ['success' => false, 'error' => 'Invalid or corrupted DOCX file: missing ZIP container header.', 'text' => '', 'page_count' => 0];
            }
            return extractTextFromDocx($filePath);

        case 'pptx':
            if (strncmp($magic, "PK\x03\x04", 4) !== 0) {
                return ['success' => false, 'error' => 'Invalid or corrupted PPTX file: missing ZIP container header.', 'text' => '', 'page_count' => 0];
            }
            return extractTextFromPptx($filePath);

        case 'xlsx':
            if (strncmp($magic, "PK\x03\x04", 4) !== 0) {
                return ['success' => false, 'error' => 'Invalid or corrupted XLSX file: missing ZIP container header.', 'text' => '', 'page_count' => 0];
            }
            return extractTextFromXlsx($filePath);

        case 'txt':
        case 'csv':
        case 'json':
        case 'md':
            return extractTextFromPlainText($filePath, $cleanExt);

        case 'png':
        case 'jpg':
        case 'jpeg':
        case 'webp':
            // Explicitly handle images without pretending OCR is present
            return [
                'success' => true,
                'text' => '',
                'page_count' => 1,
                'char_count' => 0,
                'is_scanned' => true,
                'warning' => 'Image document uploaded without OCR layer. Server-side OCR is not yet configured.',
                'format' => strtoupper($cleanExt)
            ];

        default:
            return [
                'success' => false,
                'error' => "Unsupported document format: .{$cleanExt}. Supported formats: PDF, DOCX, PPTX, XLSX, TXT, CSV, JSON, MD.",
                'text' => '',
                'page_count' => 0
            ];
    }
}

