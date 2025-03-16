<?php
ini_set('max_execution_time', 300); // Increase PHP time limit

require_once 'mysqlconnect.php';

$sqlFile = __DIR__ . '/testdb.sql';
$sqlContents = file_get_contents($sqlFile);
if ($sqlContents === false) {
    die("Error reading testdb.sql.\n");
}

// Possibly split the file into smaller queries if it's huge
// e.g., explode(";", $sqlContents) and run them in a loop

if ($mydb->multi_query($sqlContents)) {
    do {
        if ($result = $mydb->store_result()) {
            $result->free();
        }
    } while ($mydb->more_results() && $mydb->next_result());
    echo "Database imported successfully.\n";
} else {
    die("Error importing database: " . $mydb->error . "\n");
}
?>
