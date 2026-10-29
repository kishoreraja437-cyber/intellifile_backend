<?php

require_once __DIR__ . "/../config/auth_helper.php";
require_once __DIR__ . "/../config/ai_helper.php";

handleCorsPreflight();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    sendJsonResponse(405, [
        "success" => false,
        "message" => "Only POST method is allowed."
    ]);
}

$userId = requireAuth($conn);
$input = getJsonInput();

$title = trim($input["title"] ?? "");
$contentText = trim($input["content_text"] ?? "");

if ($title === "" && $contentText === "") {
    sendJsonResponse(400, [
        "success" => false,
        "message" => "Please provide document title or content text to predict category and priority."
    ]);
}

$prediction = aiPredictCategoryAndPriority($title, $contentText);

sendJsonResponse(200, [
    "success" => true,
    "title" => $title,
    "category" => $prediction["category"],
    "priority" => $prediction["priority"],
    "confidence" => $prediction["confidence"] ?? 0.85,
    "source" => $prediction["source"] ?? "python_ml"
]);

