<?php
require_once 'mysqlconnect.php'; // This file should create a MySQLi connection in $mydb

echo "Importing data from testdb.sql...\n";

// Path to your SQL dump file
$sqlFile = __DIR__ . "/testdb.sql";

// Check if file exists
if (!file_exists($sqlFile)) {
    die("Error: testdb.sql file not found.\n");
}

// Read the SQL file contents
$sqlContents = file_get_contents($sqlFile);
if ($sqlContents === false) {
    die("Error reading testdb.sql.\n");
}

// Execute the SQL statements
if ($mydb->multi_query($sqlContents)) {
    // Keep looping while there are still more results
    do {
        if ($result = $mydb->store_result()) {
            // Free each result set
            $result->free();
        }
    } while ($mydb->more_results() && $mydb->next_result());
    
    echo "Database imported successfully from testdb.sql.\n";
} else {
    die("Error importing database: " . $mydb->error . "\n");
}
?>
