<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query("SHOW COLUMNS FROM documents");
while ($r = $res->fetch_assoc()) {
    echo $r['Field'] . " | " . $r['Type'] . PHP_EOL;
}
