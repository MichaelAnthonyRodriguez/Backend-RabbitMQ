<?php
require_once('mysqlconnect.php'); // Includes the database config

echo "🔄 Initializing database using schema.sql...\n";

// Path to SQL schema file
$schemaFile = __DIR__ . "/schema.sql";

// Check if file exists
if (!file_exists($schemaFile)) {
    die("error: Schema file not found.\n");
}

// Read schema file
$schemaSQL = file_get_contents($schemaFile);

// Execute schema queries
if ($mydb->multi_query($schemaSQL)) {
    do {
        if ($result = $mydb->store_result()) {
            $result->free();
        }
    } while ($mydb->more_results() && $mydb->next_result());
    
    echo "database initialized successfully from schema.sql.\n";
} else {
    die("error initializing database: " . $mydb->error . "\n");
}
// Close connection
$mydb->close();
?>