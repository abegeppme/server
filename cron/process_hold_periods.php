<?php
/**
 * Cron Job: Process Hold Periods
 * 
 * This script should be run:
 * - Every hour: To process 48-hour hold period releases
 * - Daily: To process 7-day auto-releases
 * 
 * Setup cron:
 * # Process 48-hour holds every hour
 * 0 * * * * /usr/bin/php /path/to/server/cron/process_hold_periods.php 48hour
 * 
 * # Process 7-day auto-releases daily at midnight
 * 0 0 * * * /usr/bin/php /path/to/server/cron/process_hold_periods.php 7day
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/services/HoldPeriodService.php';

$action = $argv[1] ?? '48hour';

try {
    $service = new HoldPeriodService();
    
    if ($action === '48hour') {
        echo "Processing 48-hour hold period releases...\n";
        $result = $service->process48HourHoldReleases();
        echo "Processed: {$result['processed']}\n";
        echo "Failed: {$result['failed']}\n";
        echo "Total found: {$result['total']}\n";
    } elseif ($action === '7day') {
        echo "Processing 7-day auto-releases...\n";
        $result = $service->process7DayAutoRelease();
        echo "Processed: {$result['processed']}\n";
        echo "Total found: {$result['total']}\n";
    } else {
        echo "Invalid action. Use '48hour' or '7day'\n";
        exit(1);
    }
    
    echo "Done.\n";
    exit(0);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
