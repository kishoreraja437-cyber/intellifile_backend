<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);
}

// 1. Locate LibreOffice soffice executable
function getLibreOfficeExecutable(): ?string {
    $candidates = [
        "C:\\Users\\SCAD\\LibreOffice\\program\\soffice.exe",
        "C:\\xampp\\htdocs\\intellifile_backend\\bin\\libreoffice\\program\\soffice.exe",
        "C:\\Program Files\\LibreOffice\\program\\soffice.exe",
        "C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe"
    ];

    foreach ($candidates as $path) {
        if (file_exists($path) && is_executable($path)) {
            return $path;
        }
    }

    // Check system PATH
    $which = shell_exec("where soffice.exe 2>NUL");
    if (!empty($which)) {
        $firstLine = trim(explode("\n", trim($which))[0]);
        if (file_exists($firstLine)) {
            return $firstLine;
        }
    }

    return null;
}

// 2. Count pages in PDF file safely
function getPdfPageCount(string $pdfPath): int {
    if (!file_exists($pdfPath)) return 1;
    $content = @file_get_contents($pdfPath);
    if ($content === false) return 1;

    // Search for /Count <number> in /Pages dictionary
    if (preg_match_all("/\/Type\s*\/Pages[^>]*\/Count\s+(\d+)/s", $content, $matches)) {
        $max = 0;
        foreach ($matches[1] as $c) {
            $num = intval($c);
            if ($num > $max) $max = $num;
        }
        if ($max > 0) return $max;
    }

    // Fallback: count /Type /Page occurrences
    $count = preg_match_all("/\/Type\s*\/Page[^s]/", $content);
    return ($count && $count > 0) ? $count : 1;
}

$libreOfficeExe = getLibreOfficeExecutable();
if (!$libreOfficeExe) {
    sendJsonResponse(500, [
        "success" => false,
        "message" => "LibreOffice executable not found on server. Please verify installation."
    ]);
}

$cacheDir = __DIR__ . "/../uploads/converted_pdf/";
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$tempDir = __DIR__ . "/../uploads/temp_convert/";
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0755, true);
}

$profileDir = __DIR__ . "/../uploads/temp_profile/";
if (!is_dir($profileDir)) {
    mkdir($profileDir, 0755, true);
}

$sourceFilePath = null;
$originalFileName = "document.docx";
$isTemporaryUpload = false;

// Case A: document_id provided
$docId = intval($_POST["document_id"] ?? $_GET["document_id"] ?? 0);
if ($docId > 0) {
    $stmt = $conn->prepare("SELECT id, title, file_path, format FROM documents WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $docId);
        $stmt->execute();
        $res = $stmt->get_result();
        $docRow = $res->fetch_assoc();
        $stmt->close();

        if ($docRow && !empty($docRow["file_path"])) {
            $cleanPath = ltrim($docRow["file_path"], "/\\");
            $absPath = __DIR__ . "/../" . $cleanPath;
            if (file_exists($absPath)) {
                $sourceFilePath = $absPath;
                $originalFileName = $docRow["title"] ?: basename($absPath);
            }
        }
    }
}

// Case A.2: Direct server relative file_path or file_url provided
$rawFilePath = trim($_POST["file_path"] ?? $_POST["file_url"] ?? $_GET["file_path"] ?? $_GET["file_url"] ?? "");
if (!empty($rawFilePath) && !$sourceFilePath) {
    $clean = preg_replace('#^https?://[^/]+/#', '', $rawFilePath);
    $clean = preg_replace('#^intellifile_backend/#', '', $clean);
    $clean = ltrim($clean, "/\\");
    $abs = __DIR__ . "/../" . $clean;
    if (file_exists($abs)) {
        $sourceFilePath = $abs;
        $originalFileName = basename($abs);
    }
}

// Case B: Multipart file upload (for local files from device)
if (!$sourceFilePath && !empty($_FILES)) {
    $file = null;
    foreach (["file", "document", "doc_file", "word_file", "doc"] as $fKey) {
        if (isset($_FILES[$fKey]) && is_array($_FILES[$fKey]) && !empty($_FILES[$fKey]["tmp_name"])) {
            $file = $_FILES[$fKey];
            break;
        }
    }
    if (!$file) {
        $first = reset($_FILES);
        if (is_array($first) && !empty($first["tmp_name"])) {
            $file = $first;
        }
    }

    if ($file && $file["error"] === UPLOAD_ERR_OK && file_exists($file["tmp_name"])) {
        $originalFileName = basename($file["name"] ?: "document.docx");
        $tempUploadName = "upload_word_" . bin2hex(random_bytes(8)) . "_" . preg_replace("/[^a-zA-Z0-9._-]/", "", $originalFileName);
        $targetUploadPath = $tempDir . $tempUploadName;

        if (move_uploaded_file($file["tmp_name"], $targetUploadPath)) {
            $sourceFilePath = $targetUploadPath;
            $isTemporaryUpload = true;
        }
    }
}

if (!$sourceFilePath || !file_exists($sourceFilePath)) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "No valid Word document found. Provide a valid document_id or upload a DOC/DOCX file."
    ]);
}

// Verify extension
$ext = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
if (!in_array($ext, ["doc", "docx", "odt", "rtf", "dot", "dotx"])) {
    if ($isTemporaryUpload) {
        @unlink($sourceFilePath);
    }
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Invalid file format '$ext'. Only DOC, DOCX, ODT, and RTF documents are supported."
    ]);
}

// Compute hash of the document for deterministic caching
$fileHash = md5_file($sourceFilePath);
$cachedPdfFileName = "word_" . $fileHash . ".pdf";
$cachedPdfFullPath = $cacheDir . $cachedPdfFileName;
$relativePdfPath = "uploads/converted_pdf/" . $cachedPdfFileName;
$pdfUrl = getBaseUrl() . $relativePdfPath;

// Check if cached PDF already exists and is non-empty
if (file_exists($cachedPdfFullPath) && filesize($cachedPdfFullPath) > 1024) {
    if ($isTemporaryUpload) {
        @unlink($sourceFilePath);
    }
    $pageCount = getPdfPageCount($cachedPdfFullPath);
    sendJsonResponse(200, [
        "success" => true,
        "message" => "Word document retrieved from cache.",
        "pdfUrl" => $pdfUrl,
        "pdfPath" => $relativePdfPath,
        "fileName" => pathinfo($originalFileName, PATHINFO_FILENAME) . ".pdf",
        "totalPages" => $pageCount,
        "cached" => true
    ]);
}

// Not in cache: Convert via headless LibreOffice Writer
$jobId = bin2hex(random_bytes(8));
$jobOutDir = $tempDir . "job_word_" . $jobId . "/";
mkdir($jobOutDir, 0755, true);

$userProfileUrl = "file:///" . str_replace("\\", "/", $profileDir);
$escapedExe = escapeshellarg($libreOfficeExe);
$escapedProfile = escapeshellarg("-env:UserInstallation=" . $userProfileUrl);
$escapedOutDir = escapeshellarg($jobOutDir);
$escapedInput = escapeshellarg($sourceFilePath);

// Command: soffice -env:UserInstallation=... --headless --convert-to pdf:writer_pdf_Export --outdir <outdir> <input>
$cmd = "{$escapedExe} {$escapedProfile} --headless --convert-to pdf:writer_pdf_Export --outdir {$escapedOutDir} {$escapedInput} 2>&1";
$output = [];
$returnCode = 0;

exec($cmd, $output, $returnCode);

// Identify converted PDF in jobOutDir
$convertedFiles = glob($jobOutDir . "*.pdf");
$convertedPdfPath = !empty($convertedFiles) ? $convertedFiles[0] : null;

if ($convertedPdfPath && file_exists($convertedPdfPath) && filesize($convertedPdfPath) > 512) {
    // Move to persistent cache location
    rename($convertedPdfPath, $cachedPdfFullPath);

    // Clean up job directory
    @rmdir($jobOutDir);
    if ($isTemporaryUpload) {
        @unlink($sourceFilePath);
    }

    $pageCount = getPdfPageCount($cachedPdfFullPath);

    sendJsonResponse(200, [
        "success" => true,
        "message" => "Word document converted to PDF successfully via LibreOffice.",
        "pdfUrl" => $pdfUrl,
        "pdfPath" => $relativePdfPath,
        "fileName" => pathinfo($originalFileName, PATHINFO_FILENAME) . ".pdf",
        "totalPages" => $pageCount,
        "cached" => false
    ]);
} else {
    // Cleanup
    if ($convertedPdfPath && file_exists($convertedPdfPath)) {
        @unlink($convertedPdfPath);
    }
    @rmdir($jobOutDir);
    if ($isTemporaryUpload) {
        @unlink($sourceFilePath);
    }

    $errLog = implode("\n", $output);
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to convert Word document to PDF. Return code: $returnCode",
        "error" => $errLog
    ]);
}
