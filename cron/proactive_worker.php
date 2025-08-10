<?php
/**
 * Proactive Message Worker - Background Job Processor
 * 
 * This script should be run via cron every 5-15 minutes to:
 * - Analyze AEI emotional/social states for proactive triggers
 * - Generate and schedule proactive messages
 * - Process pending background jobs
 * 
 * Example cron entry (runs every 10 minutes):
 * 10 * * * * /usr/bin/php /path/to/ayuni-beta/cron/proactive_worker.php >> /var/log/ayuni_proactive.log 2>&1
 */

// Prevent web access
if (isset($_SERVER['HTTP_HOST'])) {
    die('This script can only be run from command line');
}

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// Change to script directory
chdir(dirname(__FILE__));

try {
    // Include dependencies
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    require_once '../includes/background_jobs.php';
    
    // Parse command line arguments
    $options = [];
    if (isset($argv)) {
        foreach ($argv as $arg) {
            if (strpos($arg, '--') === 0) {
                $parts = explode('=', substr($arg, 2), 2);
                $key = $parts[0];
                $value = isset($parts[1]) ? $parts[1] : true;
                
                // Convert numeric values
                if (is_string($value) && is_numeric($value)) {
                    $value = is_float($value + 0) ? (float)$value : (int)$value;
                }
                
                $options[$key] = $value;
            }
        }
    }
    
    // Default options
    $options = array_merge([
        'max_jobs' => 25,
        'max_run_time' => 300, // 5 minutes
        'schedule_new' => true,
        'verbose' => false
    ], $options);
    
    if ($options['verbose']) {
        echo "Proactive Worker starting with options: " . json_encode($options) . "\n";
    }
    
    // Create scheduler and run
    $scheduler = new ProactiveJobScheduler($pdo);
    $result = $scheduler->run($options);
    
    if ($options['verbose']) {
        echo "Results: " . json_encode($result) . "\n";
        
        // Show current status
        $status = $scheduler->getStatus();
        echo "System Status:\n";
        echo "- Pending jobs: " . $status['pending']['total_pending'] . "\n";
        echo "- Ready to run: " . $status['pending']['ready_to_run'] . "\n";
        echo "- Currently running: " . $status['pending']['currently_running'] . "\n";
        
        if ($status['pending']['next_job_time']) {
            echo "- Next job scheduled: " . $status['pending']['next_job_time'] . "\n";
        }
    }
    
    // Log summary
    $summary = sprintf(
        "ProactiveWorker completed - Recovered: %d, Scheduled: %d, Processed: %d",
        $result['recovered'],
        $result['scheduled'],
        $result['processed']
    );
    
    error_log($summary);
    
    if ($options['verbose']) {
        echo $summary . "\n";
    }
    
    exit(0);
    
} catch (Exception $e) {
    $error = "ProactiveWorker error: " . $e->getMessage();
    error_log($error);
    
    if (isset($options['verbose']) && $options['verbose']) {
        echo $error . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
    
    exit(1);
}
?>