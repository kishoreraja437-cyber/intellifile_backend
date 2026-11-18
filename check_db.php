<?php
require_once __DIR__ . '/config/db.php';
$res = $conn->query('SELECT * FROM users LIMIT 3');
while ($r = $res->fetch_assoc()) {
    print_r(array_keys($r));
    echo "ID: " . $r['id'] . ", Token: " . ($r['auth_token'] ?? 'NULL') . "\n";
}
