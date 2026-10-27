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

$rating = intval($input["rating"] ?? 5);
if ($rating < 1 || $rating > 5) $rating = 5;

$category = strtoupper(trim($input["category"] ?? "EXPERIENCE"));
if (!in_array($category, ["BUG", "IDEA", "EXPERIENCE"])) {
    $category = "EXPERIENCE";
}

$feedbackText = trim($input["feedback_text"] ?? $input["feedback"] ?? $input["message"] ?? "");
$email = trim($input["email"] ?? "");

if ($feedbackText === "") {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Please enter your feedback text."
    ]);
}

if (strlen($feedbackText) > 1000) {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Feedback must not exceed 1000 characters."
    ]);
}

try {
    // Ensure table exists
    $conn->query("
        CREATE TABLE IF NOT EXISTS `feedbacks` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `user_id` int(11) DEFAULT NULL,
          `rating` int(11) NOT NULL DEFAULT 5,
          `category` enum('BUG','IDEA','EXPERIENCE') NOT NULL DEFAULT 'EXPERIENCE',
          `feedback_text` text NOT NULL,
          `email` varchar(150) DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_feedback_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
    ");

    $stmt = $conn->prepare("INSERT INTO feedbacks (user_id, rating, category, feedback_text, email) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iisss", $userId, $rating, $category, $feedbackText, $email);
    $stmt->execute();
    $feedbackId = $conn->insert_id;
    $stmt->close();

    sendJsonResponse(201, [
        "success" => true,
        "message" => "Thank you! Your feedback has been received.",
        "feedback" => [
            "id" => $feedbackId,
            "rating" => $rating,
            "category" => $category,
            "email" => $email,
            "created_at" => date("Y-m-d H:i:s")
        ]
    ]);

} catch (mysqli_sql_exception $e) {
    error_log($e->getMessage());
    sendJsonResponse(500, [
        "success" => false,
        "message" => "Failed to submit feedback. Please try again."
    ]);
}

