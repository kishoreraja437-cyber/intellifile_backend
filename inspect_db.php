<?php
require 'C:/xampp/htdocs/intellifile_backend/config/db.php';

echo "=== DATABASES ===\n";
$dbRes = $conn->query("SHOW DATABASES");
while($row = $dbRes->fetch_row()) {
    echo "DB: " . $row[0] . "\n";
}

echo "\n=== TABLES IN intellifile ===\n";
$res = $conn->query("SHOW TABLES");
while($row = $res->fetch_row()) {
    $t = $row[0];
    $countRes = $conn->query("SELECT COUNT(*) FROM `$t`");
    $count = $countRes ? $countRes->fetch_row()[0] : 'err';
    echo " - " . $t . " (" . $count . " rows)\n";
}
?>