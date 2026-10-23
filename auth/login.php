```php
<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "message" => "Only POST requests are allowed"
    ]);
    exit;
}

require_once __DIR__ . "/../config/db.php";

// Read JSON request
$data = json_decode(file_get_contents("php://input"), true);

if (!is_array($data)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Invalid request data"
    ]);
    exit;
}

// Login fields
$email = trim($data["email"] ?? "");
$password = $data["password"] ?? "";

// Validate fields
if ($email === "" || $password === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Email and password are required"
    ]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "message" => "Please enter a valid email address"
    ]);
    exit;
}

try {
    // Find user by email
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, password
         FROM users
         WHERE email = ?
         LIMIT 1"
    );

    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Verify password hash
    if ($user && password_verify($password, $user["password"])) {

        http_response_code(200);

        echo json_encode([
            "success" => true,
            "message" => "Login successful",
            "user" => [
                "id" => (int) $user["id"],
                "full_name" => $user["full_name"],
                "email" => $user["email"]
            ]
        ]);

    } else {
        http_response_code(401);

        echo json_encode([
            "success" => false,
            "message" => "Invalid email or password"
        ]);
    }

    $stmt->close();

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "message" => "Login failed. Please try again."
    ]);
}

$conn->close();

?>
```