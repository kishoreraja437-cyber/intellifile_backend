<?php
require_once __DIR__ . '/../config/db.php';

$res = $conn->query('SELECT id, full_name, email, auth_token FROM users LIMIT 5');
while ($row = $res->fetch_assoc()) {
    echo "User {$row['id']}: {$row['full_name']} ({$row['email']}) Token: {$row['auth_token']}\n";
}

$dres = $conn->query('SELECT id, user_id, title, format, file_size FROM documents');
echo "\nTotal documents in vault: " . $dres->num_rows . "\n";
while ($d = $dres->fetch_assoc()) {
    echo "  Doc {$d['id']} (User {$d['user_id']}): {$d['title']} [{$d['format']}] {$d['file_size']}\n";
}
