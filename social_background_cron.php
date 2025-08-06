<?php
/**
 * Social Background Processing Cron Job
 * Run this script every 6 hours to process AEI social environments
 * 
 * Example crontab entry:
 * 0 */6 * * * /usr/bin/php /path/to/ayuni-beta/social_background_cron.php
 */

// Prevent execution via web browser
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

// Set execution time limit (30 minutes max)
set_time_limit(1800);
ini_set('memory_limit', '512M');

// Include required files
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/background_social_processor.php';

try {
    $startTime = microtime(true);
    echo "Starting social background processing at " . date('Y-m-d H:i:s') . "\n";
    
    $processor = new BackgroundSocialProcessor($pdo);
    
    // 1. Process all AEI social environments
    echo "Processing AEI social environments...\n";
    $processedCount = $processor->processAllAEISocial();
    echo "Processed social environments for $processedCount AEIs\n";
    
    // 2. Clean up old interactions
    echo "Cleaning up old interactions...\n";
    $deletedCount = $processor->cleanupOldInteractions();
    echo "Cleaned up $deletedCount old interactions\n";
    
    // 3. Log completion
    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);
    echo "Social background processing completed in {$executionTime} seconds\n";
    
    // Log to database for monitoring
    try {
        $stmt = $pdo->prepare("
            INSERT INTO api_settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP
        ");
        
        $logData = [
            'timestamp' => date('Y-m-d H:i:s'),
            'processed_aeis' => $processedCount,
            'cleaned_interactions' => $deletedCount,
            'execution_time' => $executionTime
        ];
        
        $stmt->execute(['social_cron_last_run', json_encode($logData)]);
        echo "Logged completion to database\n";
    } catch (PDOException $e) {
        echo "Warning: Could not log to database: " . $e->getMessage() . "\n";
    }
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Social background processing error: " . $e->getMessage());
    exit(1);
}

echo "Social background processing finished successfully!\n";
?>