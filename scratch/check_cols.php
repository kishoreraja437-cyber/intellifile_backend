<?php
require_once __DIR__ . '/../config/db.php';
function showCols($conn, $table) {
    echo "=== $table ===" . PHP_EOL;
    $res = $conn->query("DESCRIBE $table");
    while($r = $res->fetch_assoc()) {
        echo $r['Field'] . ' | ' . $r['Type'] . ' | ' . $r['Null'] . ' | ' . $r['Default'] . PHP_EOL;
    }
}
showCols($conn, 'users');
showCols($conn, 'user_settings');
