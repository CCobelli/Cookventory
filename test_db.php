<?php
require_once __DIR__ . '/private/db-connect.php';

echo "Database connection successful!\n";

// Show tables to verify schema
$result = $conn->query("SHOW TABLES");
if ($result) {
    echo "Tables in database:\n";
    while ($row = $result->fetch_row()) {
        echo "  - " . $row[0] . "\n";
    }
} else {
    echo "No tables found. Run db/schema.sql to set up the database.\n";
}

$conn->close();
?>
