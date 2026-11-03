<?php

require_once 'c:/xampp/htdocs/intellifile_backend/config/db.php';
$res = $conn->query("SELECT id, email, fcm_token, fcm_updated_at FROM users WHERE id = 5");
$row = $res->fetch_assoc();
echo "users table: " . json_encode($row) . PHP_EOL;

$res2 = $conn->query("SELECT * FROM user_fcm_tokens WHERE user_id = 5");
while ($r = $res2->fetch_assoc()) {
    echo "user_fcm_tokens table: " . json_encode($r) . PHP_EOL;
}
