<?php
/**
 * Emotional Decay Cron Worker
 * 
 * This script should be run every hour via cron to process emotional decay
 * for all active AEI sessions.
 * 
 * Cron example (run every hour):
 * 0 * * * * /usr/bin/php /path/to/ayuni-beta/cron/emotional_decay_worker.php
 */

// Change to the project directory
chdir(__DIR__ . '/..');

// Include required files
require_once 'config/database.php';
require_once 'includes/emotional_decay.php';
require_once 'includes/background_jobs.php';

// Set up error logging
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/decay_worker.log');

function logMessage($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] EmotionalDecayWorker: $message");
    echo "[$timestamp] $message\n";
}

try {
    logMessage("Starting emotional decay processing");
    
    // Initialize decay processor
    $emotionalDecay = new EmotionalDecay($pdo);
    
    // Process emotional decay for all AEIs
    $processedCount = $emotionalDecay->processEmotionalDecayForAllAEIs();
    
    logMessage("Processed emotional decay for $processedCount sessions");
    
    // Schedule proactive messaging jobs for high-priority triggers
    if ($processedCount > 0) {
        $jobWorker = new BackgroundJobWorker($pdo);
        $scheduledJobs = $jobWorker->schedulePeriodicAnalysis(5); // 5 minute delay for immediate processing
        
        logMessage("Scheduled $scheduledJobs proactive analysis jobs");
    }
    
    // Clean up old decay logs (keep only last 30 days)
    $stmt = $pdo->prepare("
        DELETE FROM aei_emotional_decay_log 
        WHERE processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $cleanedLogs = $stmt->rowCount();
    
    if ($cleanedLogs > 0) {
        logMessage("Cleaned up $cleanedLogs old decay log entries");
    }
    
    logMessage("Emotional decay processing completed successfully");
    
} catch (Exception $e) {
    logMessage("ERROR: " . $e->getMessage());
    logMessage("Stack trace: " . $e->getTraceAsString());
    exit(1);
}

logMessage("Decay worker finished");
?>