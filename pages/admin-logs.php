<?php
requireAdmin();

// Ensure error logging is enabled
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');

$error = null;
$success = null;

// Handle POST actions for log management
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'clear_logs':
            try {
                $logPath = __DIR__ . '/../error.log';
                if (file_exists($logPath)) {
                    file_put_contents($logPath, '');
                    $success = "Error log cleared successfully!";
                    error_log("Error log manually cleared by admin");
                } else {
                    $error = "Error log file does not exist.";
                }
            } catch (Exception $e) {
                $error = "Failed to clear error log: " . $e->getMessage();
            }
            break;
        
        case 'download_logs':
            $logPath = __DIR__ . '/../error.log';
            if (file_exists($logPath) && filesize($logPath) > 0) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="ayuni-error-log-' . date('Y-m-d-H-i-s') . '.log"');
                header('Content-Length: ' . filesize($logPath));
                readfile($logPath);
                exit;
            } else {
                $error = "No error log file found or file is empty.";
            }
            break;
    }
}

// Read error log content
$logPath = __DIR__ . '/../error.log';
$logContent = '';
$logFileSize = 0;
$logLastModified = null;
$logExists = false;

if (file_exists($logPath)) {
    $logExists = true;
    $logFileSize = filesize($logPath);
    $logLastModified = filemtime($logPath);
    
    if ($logFileSize > 0) {
        // Read last 1000 lines for performance
        $lines = [];
        $handle = fopen($logPath, 'r');
        if ($handle) {
            // Read file backwards to get recent logs first
            $lineCount = 0;
            $pos = -1;
            $char = '';
            $line = '';
            
            // Go to end of file
            fseek($handle, $pos, SEEK_END);
            
            while (ftell($handle) > 0 && $lineCount < 1000) {
                $char = fgetc($handle);
                if ($char === "\n") {
                    if (!empty(trim($line))) {
                        $lines[] = strrev($line);
                        $lineCount++;
                    }
                    $line = '';
                } else {
                    $line .= $char;
                }
                fseek($handle, --$pos, SEEK_END);
            }
            
            // Add last line if exists
            if (!empty(trim($line))) {
                $lines[] = strrev($line);
            }
            
            fclose($handle);
            $logContent = implode("\n", array_reverse($lines));
        }
    }
}

// Parse log entries for better display
$logEntries = [];
if (!empty($logContent)) {
    $lines = explode("\n", $logContent);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        
        // Parse PHP error log format: [DD-MMM-YYYY HH:MM:SS UTC] message
        if (preg_match('/^\[(.+?)\]\s+(.+)$/', $line, $matches)) {
            $timestamp = $matches[1];
            $message = $matches[2];
            
            // Determine log level
            $level = 'info';
            if (strpos($message, 'ERROR') !== false || strpos($message, 'error') !== false) {
                $level = 'error';
            } elseif (strpos($message, 'WARNING') !== false || strpos($message, 'warning') !== false) {
                $level = 'warning';
            } elseif (strpos($message, '===') !== false) {
                $level = 'section';
            }
            
            $logEntries[] = [
                'timestamp' => $timestamp,
                'message' => $message,
                'level' => $level,
                'raw' => $line
            ];
        } else {
            // Line without timestamp (probably continuation)
            if (!empty($logEntries)) {
                $logEntries[count($logEntries) - 1]['message'] .= "\n" . $line;
                $logEntries[count($logEntries) - 1]['raw'] .= "\n" . $line;
            } else {
                $logEntries[] = [
                    'timestamp' => 'Unknown',
                    'message' => $line,
                    'level' => 'info',
                    'raw' => $line
                ];
            }
        }
    }
}

include_once __DIR__ . '/../includes/admin_layout.php';
?>

<div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <?php renderAdminNavigation('admin-logs'); ?>
    
    <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <?php renderAdminPageHeader('Error Logs', 'Monitor system errors and debugging information'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>
        
        <!-- Log File Info -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg mb-6">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        <i class="fas fa-file-alt mr-2"></i>
                        Log File Status
                    </h3>
                    <div class="flex space-x-2">
                        <?php if ($logExists && $logFileSize > 0): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="download_logs">
                                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors text-sm">
                                    <i class="fas fa-download mr-2"></i>Download
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <?php if ($logExists): ?>
                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to clear all error logs? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="clear_logs">
                                <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors text-sm">
                                    <i class="fas fa-trash mr-2"></i>Clear Logs
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="px-6 py-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                    <div>
                        <span class="font-medium text-gray-500 dark:text-gray-400">Status:</span>
                        <span class="ml-2 px-2 py-1 rounded-full text-xs <?= $logExists ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400' : 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400' ?>">
                            <?= $logExists ? 'File exists' : 'File not found' ?>
                        </span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-500 dark:text-gray-400">Size:</span>
                        <span class="ml-2 text-gray-900 dark:text-white">
                            <?= $logExists ? number_format($logFileSize / 1024, 2) . ' KB' : 'N/A' ?>
                        </span>
                    </div>
                    <div>
                        <span class="font-medium text-gray-500 dark:text-gray-400">Last Modified:</span>
                        <span class="ml-2 text-gray-900 dark:text-white">
                            <?= $logLastModified ? date('Y-m-d H:i:s', $logLastModified) : 'N/A' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Log Content -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">
                        <i class="fas fa-list mr-2"></i>
                        Recent Log Entries
                        <?php if (count($logEntries) > 0): ?>
                            <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                (Showing last <?= count($logEntries) ?> entries)
                            </span>
                        <?php endif; ?>
                    </h3>
                    
                    <!-- Auto-refresh toggle -->
                    <div class="flex items-center space-x-4">
                        <label class="flex items-center space-x-2 text-sm">
                            <input type="checkbox" id="auto-refresh" class="rounded border-gray-300 dark:border-gray-600">
                            <span class="text-gray-700 dark:text-gray-300">Auto-refresh (10s)</span>
                        </label>
                        <button onclick="location.reload()" class="px-3 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-sm">
                            <i class="fas fa-sync-alt mr-2"></i>Refresh
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="p-6">
                <?php if (empty($logEntries)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-file-alt text-gray-400 text-4xl mb-4"></i>
                        <p class="text-gray-500 dark:text-gray-400">
                            <?= $logExists ? 'No log entries found.' : 'Error log file does not exist yet.' ?>
                        </p>
                        <?php if (!$logExists): ?>
                            <p class="text-sm text-gray-400 dark:text-gray-500 mt-2">
                                The log file will be created automatically when the first error is logged.
                            </p>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        <?php foreach (array_reverse($logEntries) as $entry): ?>
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 <?php
                                switch ($entry['level']) {
                                    case 'error':
                                        echo 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800';
                                        break;
                                    case 'warning':
                                        echo 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800';
                                        break;
                                    case 'section':
                                        echo 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800';
                                        break;
                                    default:
                                        echo 'bg-gray-50 dark:bg-gray-700';
                                }
                            ?>">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start space-x-3 flex-1">
                                        <div class="flex-shrink-0">
                                            <?php
                                            switch ($entry['level']) {
                                                case 'error':
                                                    echo '<i class="fas fa-exclamation-circle text-red-500"></i>';
                                                    break;
                                                case 'warning':
                                                    echo '<i class="fas fa-exclamation-triangle text-yellow-500"></i>';
                                                    break;
                                                case 'section':
                                                    echo '<i class="fas fa-flag text-blue-500"></i>';
                                                    break;
                                                default:
                                                    echo '<i class="fas fa-info-circle text-gray-500"></i>';
                                            }
                                            ?>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                                    <?= htmlspecialchars($entry['timestamp']) ?>
                                                </span>
                                                <span class="px-2 py-1 rounded-full text-xs font-medium <?php
                                                    switch ($entry['level']) {
                                                        case 'error':
                                                            echo 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400';
                                                            break;
                                                        case 'warning':
                                                            echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400';
                                                            break;
                                                        case 'section':
                                                            echo 'bg-blue-100 text-blue-800 dark:bg-blue-900/20 dark:text-blue-400';
                                                            break;
                                                        default:
                                                            echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
                                                    }
                                                ?>">
                                                    <?= strtoupper($entry['level']) ?>
                                                </span>
                                            </div>
                                            <pre class="text-sm text-gray-900 dark:text-white font-mono whitespace-pre-wrap break-all"><?= htmlspecialchars($entry['message']) ?></pre>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Usage Instructions -->
        <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
            <h4 class="text-lg font-medium text-blue-900 dark:text-blue-400 mb-3">
                <i class="fas fa-info-circle mr-2"></i>
                How to Use Error Logs
            </h4>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-blue-800 dark:text-blue-300">
                <div>
                    <h5 class="font-medium mb-2">Debugging Social System:</h5>
                    <ul class="space-y-1 text-blue-700 dark:text-blue-400">
                        <li>• Click "Process All" or "Process Single" in Social System</li>
                        <li>• Check logs for detailed processing steps</li>
                        <li>• Look for error entries with red indicators</li>
                        <li>• Section headers show major processing phases</li>
                    </ul>
                </div>
                <div>
                    <h5 class="font-medium mb-2">Log Management:</h5>
                    <ul class="space-y-1 text-blue-700 dark:text-blue-400">
                        <li>• Auto-refresh monitors logs in real-time</li>
                        <li>• Download logs for external analysis</li>
                        <li>• Clear logs to start fresh debugging</li>
                        <li>• Logs show most recent 1000 entries</li>
                    </ul>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
// Auto-refresh functionality
let autoRefreshInterval;
const autoRefreshCheckbox = document.getElementById('auto-refresh');

autoRefreshCheckbox.addEventListener('change', function() {
    if (this.checked) {
        autoRefreshInterval = setInterval(function() {
            location.reload();
        }, 10000); // Refresh every 10 seconds
    } else {
        if (autoRefreshInterval) {
            clearInterval(autoRefreshInterval);
        }
    }
});

// Cleanup interval when leaving page
window.addEventListener('beforeunload', function() {
    if (autoRefreshInterval) {
        clearInterval(autoRefreshInterval);
    }
});
</script>