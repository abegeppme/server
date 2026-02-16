<?php
/**
 * Run 48-Hour Hold Schema Updates
 * 
 * This script runs the schema_48hour_hold.sql file
 * Usage: php run_schema_48hour_hold.php
 */

require_once __DIR__ . '/../config/database.php';

$db = Database::getInstance()->getConnection();

// Read SQL file
$sqlFile = __DIR__ . '/schema_48hour_hold.sql';
if (!file_exists($sqlFile)) {
    die("Error: SQL file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);

// Split by semicolons and execute each statement
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    function($stmt) {
        return !empty($stmt) && !preg_match('/^--/', $stmt);
    }
);

echo "Running 48-hour hold schema updates...\n\n";

$success = 0;
$failed = 0;

foreach ($statements as $statement) {
    // Skip comments
    $statement = preg_replace('/--.*$/m', '', $statement);
    $statement = trim($statement);
    
    if (empty($statement)) {
        continue;
    }
    
    try {
        // Handle IF NOT EXISTS for MySQL (MySQL doesn't support it natively for ALTER TABLE)
        if (preg_match('/ALTER TABLE.*ADD COLUMN IF NOT EXISTS/i', $statement)) {
            // Extract table and column info
            preg_match('/ALTER TABLE\s+`?(\w+)`?\s+ADD COLUMN IF NOT EXISTS\s+`?(\w+)`?/i', $statement, $matches);
            if (count($matches) >= 3) {
                $table = $matches[1];
                $column = $matches[2];
                
                // Check if column exists
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
                    echo "✓ Column `$table`.`$column` already exists, skipping...\n";
                    $success++;
                    continue;
                }
                
                // Remove IF NOT EXISTS and execute
                $statement = preg_replace('/IF NOT EXISTS\s+/i', '', $statement);
            }
        }
        
        // Handle CREATE INDEX IF NOT EXISTS
        if (preg_match('/CREATE INDEX IF NOT EXISTS/i', $statement)) {
            preg_match('/CREATE INDEX IF NOT EXISTS\s+`?(\w+)`?\s+ON\s+`?(\w+)`?/i', $statement, $matches);
            if (count($matches) >= 3) {
                $indexName = $matches[1];
                $table = $matches[2];
                
                // Check if index exists
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
                    echo "✓ Index `$indexName` on `$table` already exists, skipping...\n";
                    $success++;
                    continue;
                }
                
                // Remove IF NOT EXISTS and execute
                $statement = preg_replace('/IF NOT EXISTS\s+/i', '', $statement);
            }
        }
        
        $db->exec($statement);
        echo "✓ Executed: " . substr($statement, 0, 60) . "...\n";
        $success++;
    } catch (PDOException $e) {
        // Check if error is because column/index already exists
        if (strpos($e->getMessage(), 'Duplicate column') !== false || 
            strpos($e->getMessage(), 'Duplicate key') !== false) {
            echo "⚠ Column/Index already exists, skipping...\n";
            $success++;
        } else {
            echo "✗ Error: " . $e->getMessage() . "\n";
            echo "  Statement: " . substr($statement, 0, 100) . "...\n";
            $failed++;
        }
    }
}

echo "\n";
echo "========================================\n";
echo "Results:\n";
echo "  Success: $success\n";
echo "  Failed: $failed\n";
echo "========================================\n";

if ($failed === 0) {
    echo "\n✓ Schema update completed successfully!\n";
    exit(0);
} else {
    echo "\n✗ Some statements failed. Please review the errors above.\n";
    exit(1);
}
