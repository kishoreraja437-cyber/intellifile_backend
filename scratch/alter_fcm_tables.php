<?php
require_once __DIR__ . '/../config/db.php';

// 1. Add fcm_token column to users table if not exists
$checkCol = $conn->query("SHOW COLUMNS FROM users LIKE 'fcm_token'");
if ($checkCol->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN fcm_token VARCHAR(500) DEFAULT NULL AFTER token_expires_at");
    echo "Added fcm_token column to users table." . PHP_EOL;
} else {
    echo "fcm_token column already exists in users table." . PHP_EOL;
}

// 2. Add fcm_updated_at column to users table if not exists
$checkCol2 = $conn->query("SHOW COLUMNS FROM users LIKE 'fcm_updated_at'");
if ($checkCol2->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN fcm_updated_at TIMESTAMP NULL DEFAULT NULL AFTER fcm_token");
    echo "Added fcm_updated_at column to users table." . PHP_EOL;
} else {
    echo "fcm_updated_at column already exists in users table." . PHP_EOL;
}

// 3. Create user_fcm_tokens table
$conn->query("
    CREATE TABLE IF NOT EXISTS user_fcm_tokens (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        fcm_token VARCHAR(500) NOT NULL,
        device_name VARCHAR(100) DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_fcm (user_id, fcm_token(191)),
        KEY idx_fcm_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
");
echo "user_fcm_tokens table created/verified." . PHP_EOL;

