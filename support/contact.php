<?php

require_once __DIR__ . "/../config/auth_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);
}

$userId = getAuthenticatedUserId($conn);
$input = getJsonInput();

$name = trim($input["name"] ?? "");
$email = trim($input["email"] ?? "");
$subject = trim($input["subject"] ?? "");
$message = trim($input["message"] ?? "");

if ($name === "" || $email === "" || $subject === "" || $message === "") {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Name, email, subject, and message are all required."
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Please provide a valid email address."
    ]);
}

try {
    // Ensure table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS `support_messages` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) DEFAULT NULL,
          `name` varchar(100) NOT NULL,
          `email` varchar(150) NOT NULL,
          `subject` varchar(150) NOT NULL,
          `message` text NOT NULL,
          `status` enum('PENDING','IN_PROGRESS','RESOLVED') NOT NULL DEFAULT 'PENDING',
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_support_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $stmt = $conn->prepare("INSERT INTO support_messages (user_id, name, email, subject, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $name, $email, $subject, $message);
    $stmt->execute();
    $ticketId = $conn->insert_id;
    $stmt->close();

    sendJsonResponse(201, [
        "success" => true,
        "message" => "Your message has been submitted to support successfully.",
        "ticket" => [
            "id" => $ticketId,
            "name" => $name,
            "email" => $email,
            "subject" => $subject,
            "status" => "PENDING",
            "created_at" => date("Y-m-d H:i:s")
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to submit support message."
    ]);
}

