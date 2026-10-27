
<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// Allow POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed"
    ]);
    exit;
}

// Connect to MySQL database
require_once __DIR__ . "/../config/db.php";

// Read JSON request body
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid JSON request"
    ]);

    exit;
}

// Get signup fields
$full_name = trim($data["full_name"] ?? "");
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate required fields
if ($full_name === "" || $email === "" || $password === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Full name, email and password are required"
    ]);

    exit;
}
// Validate full name
if (strlen($full_name) > 100) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Full name must not exceed 100 characters"
    ]);

    exit;
}

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($email) > 150) {

    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);

    exit;
}

// Validate password
if (strlen($password) < 8) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Password must contain at least 8 characters"
    ]);

    exit;
}
// if(strlen($full_name) < 3){
//     http_response_code(400);
//     echo json_encode([
//         "success" => false,
//         "message" => "full name must be more than 3 char"
//     ]
//     );
//     exit;
// }


// Hash password before storing
$password_hash = password_hash(
    $password,
    PASSWORD_DEFAULT
);

try {

    // Insert user into database
    $stmt = $conn->prepare(
        "INSERT INTO users (full_name, email, password)
         VALUES (?, ?, ?)"
    );

    $stmt->bind_param(
        "sss",
        $full_name,
        $email,
        $password_hash
    );

    $stmt->execute();

    // Signup successful
    http_response_code(201);

    echo json_encode([
        "success" => true,
        "message" => "Account created successfully"
    ]);

    $stmt->close();

} catch (mysqli_sql_exception $e) {

    // Duplicate email
    if ($e->getCode() === 1062) {
        http_response_code(409);

        echo json_encode([
            "success" => false,
            "message" => "This email is already registered"
        ]);

    } else {
        error_log($e->getMessage());

        http_response_code(500);

        echo json_encode([
            "success" => false,
            "message" => "Signup failed. Please try again."
        ]);
    }
}

$conn->close();
?>