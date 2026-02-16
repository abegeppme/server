<?php
/**
 * Run SQL File
 * Executes SQL statements from a file with error handling
 * 
 * Usage: php run_sql.php schema_48hour_hold_final.sql
 */

require_once __DIR__ . '/../config/database.php';

$sqlFile = $argv[1] ?? 'schema_48hour_hold_final.sql';

if (!file_exists(__DIR__ . '/' . $sqlFile)) {
    die("Error: SQL file not found: $sqlFile\n");
}

$db = Database::getInstance()->getConnection();
$sql = file_get_contents(__DIR__ . '/' . $sqlFile);

// Remove comments and split by semicolons
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) {
        $stmt = trim($stmt);
        return !empty($stmt) && !preg_match('/^--/', $stmt);
    }
);

echo "========================================\n";
echo "Running SQL: $sqlFile\n";
echo "========================================\n\n";

$success = 0;
$failed = 0;
$skipped = 0;

foreach ($statements as $index => $statement) {
    // Remove inline comments
    $statement = preg_replace('/--.*$/m', '', $statement);
    $statement = trim($statement);
    
    if (empty($statement)) {
        continue;
    }
    
    // Check if it's an ALTER TABLE ADD COLUMN
    if (preg_match('/ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN\s+`?(\w+)`?/i', $statement, $matches)) {
        $table = $matches[1];
        $column = $matches[2];
        
        // Check if column already exists
        $checkStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?
        ");
        $checkStmt->execute([$table, $column]);
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            echo "⚠ Column `$table`.`$column` already exists, skipping...\n";
            $skipped++;
            continue;
        }
    }
    
    // Check if it's a CREATE INDEX
    if (preg_match('/CREATE INDEX\s+`?(\w+)`?\s+ON\s+`?(\w+)`?/i', $statement, $matches)) {
        $indexName = $matches[1];
        $table = $matches[2];
        
        // Check if index already exists
        $checkStmt = $db->prepare("
            SELECT COUNT(*) as count 
            FROM INFORMATION_SCHEMA.STATISTICS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND INDEX_NAME = ?
        ");
        $checkStmt->execute([$table, $indexName]);
        $result = $checkStmt->fetch();
        
        if ($result['count'] > 0) {
            echo "⚠ Index `$indexName` on `$table` already exists, skipping...\n";
            $skipped++;
            continue;
        }
    }
    
    try {
        $db->exec($statement);
        $preview = substr($statement, 0, 80);
        echo "✓ Executed: $preview...\n";
        $success++;
    } catch (PDOException $e) {
        // Check for duplicate errors
        if (strpos($e->getMessage(), 'Duplicate column') !== false || 
            strpos($e->getMessage(), 'Duplicate key') !== false ||
            strpos($e->getMessage(), 'already exists') !== false) {
            echo "⚠ Already exists, skipping...\n";
            $skipped++;
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
            echo "  Statement: " . substr($statement, 0, 100) . "...\n\n";
            $failed++;
        }
    }
}

echo "\n";
echo "========================================\n";
echo "Results:\n";
echo "  ✓ Success: $success\n";
echo "  ⚠ Skipped: $skipped\n";
echo "  ✗ Failed: $failed\n";
echo "========================================\n";

if ($failed === 0) {
    echo "\n✓ Schema update completed successfully!\n";
    exit(0);
} else {
    echo "\n✗ Some statements failed. Please review the errors above.\n";
    exit(1);
}
