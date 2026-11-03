<?php
require_once __DIR__ . '/../config/db.php';
$res = $conn->query('SHOW TABLES');
while($r = $res->fetch_array()) {
    echo $r[0] . PHP_EOL;
}

