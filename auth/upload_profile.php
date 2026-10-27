
<?php

header("Content-Type: application/json");

require_once __DIR__ . "/../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);

    exit;
}

// Get the registered user's email.
$email = trim($_POST["email"] ?? "");

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please provide a valid email."
    ]);

    exit;
}

// Check whether an image was uploaded.
if (!isset($_FILES["profile_image"]) ||
    $_FILES["profile_image"]["error"] !== UPLOAD_ERR_OK) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please select a profile image."
    ]);

    exit;
}

$file = $_FILES["profile_image"];

// Limit file size to 2 MB.
if ($file["size"] > 5 * 1024 * 1024) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Image size must be below 5 MB."
    ]);

    exit;
}

// Validate the actual image file.
$imageInfo = getimagesize($file["tmp_name"]);

if ($imageInfo === false) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Uploaded file is not a valid image."
    ]);

    exit;
}

// Allow only JPEG, PNG and WebP.
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

// Find the user.
$stmt = $conn->prepare(
    "SELECT id FROM users WHERE email = ? LIMIT 1"
);

$stmt->bind_param("s", $email);
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

    exit;
}

// Create a unique filename.
$extension = $allowedTypes[$imageType];

$fileName = bin2hex(random_bytes(16)) . "." . $extension;

$uploadDirectory = __DIR__ . "/../uploads/";

if (!is_dir($uploadDirectory) &&
    !mkdir($uploadDirectory, 0755, true)) {

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to create upload directory."
    ]);

    exit;
}

$destination = $uploadDirectory . $fileName;

// Move the uploaded image.
if (!move_uploaded_file($file["tmp_name"], $destination)) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Image upload failed."
    ]);

    exit;
}

$userId = (int) $user["id"];
$imagePath = "uploads/" . $fileName;

// Update the user's profile image path.
$update = $conn->prepare(
    "UPDATE users SET profile_image = ? WHERE id = ?"
);

$update->bind_param("si", $imagePath, $userId);

if ($update->execute()) {

    echo json_encode([
        "success" => true,
        "message" => "Profile image uploaded successfully.",
        "profile_image" =>
            "http://localhost/intellifile_backend/" . $imagePath
    ]);

} else {

    // Remove the new file if its database update failed.
    if (is_file($destination)) {
        unlink($destination);
    }

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Unable to save profile image."
    ]);
}

$update->close();
$conn->close();

?>