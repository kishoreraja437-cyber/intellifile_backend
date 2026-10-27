<?php
header("Content-Type: application/json");

require_once __DIR__ . "/../config/db.php";

// Allow only PUT requests
if ($_SERVER["REQUEST_METHOD"] !== "PUT") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Only PUT method is allowed."
    ]);
    exit;
}

// Read JSON request body
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Please send valid JSON data."
    ]);
    exit;
}

// Get the current email and updated details
$currentEmail = trim($data["current_email"] ?? "");
$full_name = trim($data["full_name"] ?? $data["name"] ?? "");
$newEmail = trim($data["email"] ?? "");

// Validate required fields
if (
    !filter_var($currentEmail, FILTER_VALIDATE_EMAIL) ||
    $full_name === "" ||
    !filter_var($newEmail, FILTER_VALIDATE_EMAIL)
) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "message" => "Provide current_email, full_name, and a valid email."
    ]);
    exit;
}

// Check whether the new email belongs to another user
$check = $conn->prepare(
    "SELECT id FROM users WHERE email = ? AND email <> ? LIMIT 1"
);

$check->bind_param("ss", $newEmail, $currentEmail);
$check->execute();

if ($check->get_result()->num_rows > 0) {
    http_response_code(409);
    echo json_encode([
        "success" => false,
        "message" => "This email address is already registered."
    ]);

    $check->close();
    $conn->close();
    exit;
}

$check->close();

// Update the user's name and email
$stmt = $conn->prepare(
    "UPDATE users SET full_name = ?, email = ? WHERE email = ?"
);

$stmt->bind_param("sss", $full_name, $newEmail, $currentEmail);
$stmt->execute();

if ($stmt->affected_rows > 0) {
    echo json_encode([
        "success" => true,
        "message" => "Profile updated successfully.",
        "profile" => [
            "full_name" => $full_name,
            "email" => $newEmail
        ]
    ]);
} else {
    // A row may exist even if the submitted values are unchanged.
    $verify = $conn->prepare(
        "SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1"
    );
    $verify->bind_param("s", $newEmail);
    $verify->execute();

    $result = $verify->get_result();

    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();

        echo json_encode([
            "success" => true,
            "message" => "Profile is already up to date.",
            "profile" => [
                "full_name" => $user["full_name"],
                "email" => $user["email"]
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "User not found. Check the current email."
        ]);
    }

    $verify->close();
}

$stmt->close();
$conn->close();
?>
