
<?php

header("Content-Type: application/json");

require_once __DIR__ . "/../config/db.php";

// Allow only GET requests
if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only GET method is allowed."
    ]);

    exit;
}

// Get registered user's email
$email = trim($_GET["email"] ?? "");

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please provide a valid email address."
    ]);

    exit;
}

// Fetch profile details from database
$stmt = $conn->prepare(
    "SELECT id, full_name, email, profile_image
     FROM users
     WHERE email = ?
     LIMIT 1"
);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Database query failed."
    ]);

    exit;
}

$stmt->bind_param("s", $email);
$stmt->execute();

$result = $stmt->get_result();
$user = $result->fetch_assoc();

if ($user) {

    $imageUrl = null;

    if (!empty($user["profile_image"])) {

        if (filter_var(
            $user["profile_image"],
            FILTER_VALIDATE_URL
        )) {
            $imageUrl = $user["profile_image"];
        } else {
            $imageUrl =
                "http://localhost/intellifile_backend/" .
                ltrim($user["profile_image"], "/");
        }
    }

    echo json_encode([
        "success" => true,
        "message" => "Profile fetched successfully.",
        "profile" => [
            "id" => (int) $user["id"],
            "full_name" => $user["full_name"],
            "email" => $user["email"],
            "profile_image" => $imageUrl
        ]
    ]);

} else {

    http_response_code(404);

    echo json_encode([
        "success" => false,
        "message" => "User not found."
    ]);
}

$stmt->close();
$conn->close();

?>
