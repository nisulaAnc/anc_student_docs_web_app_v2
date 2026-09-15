<?php
require_once __DIR__ . '/config.php';

try {
    $pdo = getDBConnection();
    echo "SUCCESS: Connected to the database successfully!\n";
    
    // Check if tables exist
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Tables in database: " . (empty($tables) ? 'None yet' : implode(', ', $tables)) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
