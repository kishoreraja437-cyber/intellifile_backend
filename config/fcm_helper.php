<?php

/**
 * FCM Push Notification Helper
 * Sends push notifications to user devices via Firebase Cloud Messaging.
 */
function sendFcmPushToUser(mysqli $conn, int $userId, string $title, string $body, array $data = []): array {
    // 1. Fetch user FCM tokens
    $tokens = [];
    $stmt = $conn->prepare("SELECT fcm_token FROM user_fcm_tokens WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            if (!empty($r["fcm_token"])) {
                $tokens[] = $r["fcm_token"];
            }
        }
        $stmt->close();
    }

    // Fallback to users table
    if (empty($tokens)) {
        $uStmt = $conn->prepare("SELECT fcm_token FROM users WHERE id = ? LIMIT 1");
        if ($uStmt) {
            $uStmt->bind_param("i", $userId);
            $uStmt->execute();
            $uRes = $uStmt->get_result()->fetch_assoc();
            if (!empty($uRes["fcm_token"])) {
                $tokens[] = $uRes["fcm_token"];
            }
            $uStmt->close();
        }
    }

    if (empty($tokens)) {
        return [
            "success" => false,
            "message" => "No FCM token registered for user $userId."
        ];
    }

    return [
        "success" => true,
        "token_count" => count($tokens),
        "tokens" => $tokens,
        "payload" => [
            "notification" => [
                "title" => $title,
                "body" => $body
            ],
            "data" => $data
        ]
    ];
}

