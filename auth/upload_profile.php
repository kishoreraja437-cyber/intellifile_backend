<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/auth_helper.php";

// Get user identifier via email, user_id, or Bearer auth token
$userId = intval($_POST["user_id"] ?? $_POST["id"] ?? getAuthenticatedUserId($conn) ?? 0);
$email = strtolower(trim($_POST["email"] ?? ""));

if ($email === "" && $userId <= 0) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide user email or user_id, or authenticate with session token."
    ]);
    exit;
}

if ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please provide a valid email."
    ]);
    exit;
}

// Check whether an image was uploaded
if (!isset($_FILES["profile_image"]) || $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please select a valid profile image file to upload."
    ]);
    exit;
}

$file = $_FILES["profile_image"];

// Limit file size to 5 MB
if ($file["size"] > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Image size must not exceed 5 MB."
    ]);
    exit;
}

// Validate that it's a real image
$imageInfo = @getimagesize($file["tmp_name"]);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Uploaded file is not a valid image."
    ]);
    exit;
}

// Allow only JPEG, PNG, and WebP
$allowedTypes = [
    IMAGETYPE_JPEG => "jpg",
    IMAGETYPE_PNG  => "png",
    IMAGETYPE_WEBP => "webp"
];

$imageType = $imageInfo[2];

if (!isset($allowedTypes[$imageType])) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Only JPG, PNG and WebP images are allowed."
    ]);
    exit;
}

try {
    // Locate the user and get their current profile image
    if ($userId > 0) {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
    } else {
        $stmt = $conn->prepare("SELECT id, full_name, email, profile_image FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found."
        ]);
        $conn->close();
        exit;
    }

    $oldImagePath = $user["profile_image"];

    // Ensure upload directory exists
    $uploadDirectory = __DIR__ . "/../uploads/";
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Unable to create upload directory."
        ]);
        $conn->close();
        exit;
    }

    // Generate unique random filename
    $extension = $allowedTypes[$imageType];
    $fileName = bin2hex(random_bytes(16)) . "." . $extension;
    $destination = $uploadDirectory . $fileName;

    if (!move_uploaded_file($file["tmp_name"], $destination)) {
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Image upload failed."
        ]);
        $conn->close();
        exit;
    }

    $newRelativePath = "uploads/" . $fileName;
    $targetUserId = (int) $user["id"];

    // Update database record
    $update = $conn->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
    $update->bind_param("si", $newRelativePath, $targetUserId);

    if ($update->execute()) {
        $update->close();

        // Delete old image file if it exists on disk and is inside uploads/
        if (!empty($oldImagePath)) {
            $oldFullPath = __DIR__ . "/../" . ltrim($oldImagePath, "/\\");
            if (is_file($oldFullPath) && realpath($oldFullPath) !== realpath($destination)) {
                @unlink($oldFullPath);
            }
        }

        $fullImageUrl = resolvePublicImageUrl($newRelativePath);

        http_response_code(200);
        echo json_encode([
            "success" => true,
            "message" => "Profile image uploaded successfully.",
            "profile_image" => $fullImageUrl,
            "profile_image_url" => $fullImageUrl,
            "profile_image_path" => $newRelativePath
        ]);

    } else {
        $update->close();
        if (is_file($destination)) {
            @unlink($destination);
        }
        http_response_code(500);
        echo json_encode([
            "success" => false,
            "message" => "Unable to save profile image in database."
        ]);
    }

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "An error occurred during profile image upload."
    ]);
}

$conn->close();
