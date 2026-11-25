<?php
require_once __DIR__ . '/../config/db.php';

try {
    echo "Connected to DB successfully.\n";

    $result = $conn->query("DESCRIBE documents");
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    echo "Current columns in documents:\n" . implode(", ", $columns) . "\n\n";

    $newColumns = [
        "insight_status" => "VARCHAR(32) NOT NULL DEFAULT 'PENDING'",
        "insight_overview" => "LONGTEXT DEFAULT NULL",
        "insight_topics" => "LONGTEXT DEFAULT NULL",
        "insight_key_points" => "LONGTEXT DEFAULT NULL",
        "insight_details" => "LONGTEXT DEFAULT NULL",
        "insight_terms" => "LONGTEXT DEFAULT NULL",
        "insight_conclusion" => "LONGTEXT DEFAULT NULL",
        "insight_error" => "TEXT DEFAULT NULL",
        "insight_updated_at" => "DATETIME DEFAULT NULL"
    ];

    foreach ($newColumns as $col => $definition) {
        if (!in_array($col, $columns)) {
            $sql = "ALTER TABLE documents ADD COLUMN $col $definition";
            if ($conn->query($sql)) {
                echo "Added column: $col\n";
            } else {
                echo "Failed to add column: $col - " . $conn->error . "\n";
            }
        } else {
            echo "Column already exists: $col\n";
        }
    }

    echo "\nMigration completed successfully!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
