<?php
require 'C:/xampp/htdocs/intellifile_backend/config/db.php';

echo "=== COLUMNS IN documents ===\n";
$colRes = $conn->query("DESCRIBE documents");
while($row = $colRes->fetch_assoc()) {
    echo " - " . $row['Field'] . " (" . $row['Type'] . ")\n";
}

echo "\n=== SAMPLE DOCUMENTS (ID, user_id, title, priority, status, file_path) ===\n";
$docRes = $conn->query("SELECT id, user_id, title, priority, created_at, file_path FROM documents ORDER BY id DESC LIMIT 20");
while($row = $docRes->fetch_assoc()) {
    echo json_encode($row) . "\n";
}

echo "\n=== PRIORITY COUNTS IN documents ===\n";
$prioRes = $conn->query("SELECT priority, COUNT(*) as cnt FROM documents GROUP BY priority");
while($row = $prioRes->fetch_assoc()) {
    echo "Priority: " . var_export($row['priority'], true) . " => " . $row['cnt'] . "\n";
}

echo "\n=== USERS ===\n";
$userRes = $conn->query("SELECT id, name, email FROM users");
while($row = $userRes->fetch_assoc()) {
    echo json_encode($row) . "\n";
}
?>