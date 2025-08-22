<?php
requireAdmin();

$error = null;
$success = null;

// Handle export request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'export') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        try {
            // Get export parameters
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $status = $_POST['status_filter'] ?? '';
            $format = $_POST['export_format'] ?? 'json';
            
            // Build query
            $whereConditions = [];
            $params = [];
            
            if ($startDate) {
                $whereConditions[] = "created_at >= ?";
                $params[] = $startDate . ' 00:00:00';
            }
            
            if ($endDate) {
                $whereConditions[] = "created_at <= ?";
                $params[] = $endDate . ' 23:59:59';
            }
            
            if ($status && $status !== 'all') {
                $whereConditions[] = "status = ?";
                $params[] = $status;
            }
            
            $whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);
            
            $stmt = $pdo->prepare("
                SELECT * FROM api_request_logs 
                $whereClause
                ORDER BY created_at DESC
                LIMIT 1000
            ");
            $stmt->execute($params);
            $logs = $stmt->fetchAll();
            
            if ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="api_logs_' . date('Y-m-d') . '.json"');
                echo json_encode($logs, JSON_PRETTY_PRINT);
                exit;
            } elseif ($format === 'csv') {
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="api_logs_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                if (!empty($logs)) {
                    fputcsv($output, array_keys($logs[0]));
                    foreach ($logs as $log) {
                        fputcsv($output, $log);
                    }
                }
                fclose($output);
                exit;
            }
            
        } catch (Exception $e) {
            error_log("Export error: " . $e->getMessage());
            $error = "Export failed. Please try again.";
        }
    }
}

// Pagination settings
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Filter parameters
$statusFilter = $_GET['status'] ?? '';
$dateFilter = $_GET['date'] ?? '';
$aeiFilter = $_GET['aei_id'] ?? '';

// Build query with filters
$whereConditions = [];
$params = [];

if ($statusFilter && $statusFilter !== 'all') {
    $whereConditions[] = "l.status = ?";
    $params[] = $statusFilter;
}

if ($dateFilter) {
    $whereConditions[] = "DATE(l.created_at) = ?";
    $params[] = $dateFilter;
}

if ($aeiFilter) {
    $whereConditions[] = "l.aei_id = ?";
    $params[] = $aeiFilter;
}

$whereClause = empty($whereConditions) ? '' : 'WHERE ' . implode(' AND ', $whereConditions);

try {
    // Get total count for pagination
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM api_request_logs l
        $whereClause
    ");
    $countStmt->execute($params);
    $totalLogs = $countStmt->fetchColumn();
    $totalPages = ceil($totalLogs / $limit);

    // Get logs with pagination
    $stmt = $pdo->prepare("
        SELECT 
            l.*,
            a.name as aei_name,
            CONCAT('User ', RIGHT(l.user_id, 6)) as anonymous_user
        FROM api_request_logs l
        LEFT JOIN aeis a ON l.aei_id = a.id
        $whereClause
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$params, $limit, $offset]);
    $logs = $stmt->fetchAll();

    // Get stats
    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_requests,
            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_requests,
            SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_requests,
            AVG(processing_time_ms) as avg_processing_time,
            SUM(tokens_used) as total_tokens
        FROM api_request_logs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $statsStmt->execute();
    $stats = $statsStmt->fetch() ?: [];

} catch (PDOException $e) {
    error_log("Database error fetching API logs: " . $e->getMessage());
    $logs = [];
    $totalLogs = 0;
    $totalPages = 0;
    $stats = [];
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-api-logs'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('API Request Logs', 'Training data from Anthropic API calls'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-blue-100 dark:bg-blue-900/50">
                        <i class="fas fa-chart-line text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Total (24h)</h3>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total_requests'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-green-100 dark:bg-green-900/50">
                        <i class="fas fa-check-circle text-green-600 dark:text-green-400"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Success</h3>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['successful_requests'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-red-100 dark:bg-red-900/50">
                        <i class="fas fa-exclamation-circle text-red-600 dark:text-red-400"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Errors</h3>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['failed_requests'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-purple-100 dark:bg-purple-900/50">
                        <i class="fas fa-clock text-purple-600 dark:text-purple-400"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg Time</h3>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['avg_processing_time'] ?? 0) ?>ms</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <div class="flex items-center">
                    <div class="p-3 rounded-full bg-yellow-100 dark:bg-yellow-900/50">
                        <i class="fas fa-coins text-yellow-600 dark:text-yellow-400"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Tokens (24h)</h3>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total_tokens'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters and Export -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <!-- Filters -->
                <div class="flex items-center space-x-4">
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Status:</label>
                        <select id="status-filter" class="ml-2 px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                            <option value="all" <?= $statusFilter === 'all' || !$statusFilter ? 'selected' : '' ?>>All</option>
                            <option value="success" <?= $statusFilter === 'success' ? 'selected' : '' ?>>Success</option>
                            <option value="error" <?= $statusFilter === 'error' ? 'selected' : '' ?>>Error</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Date:</label>
                        <input type="date" id="date-filter" value="<?= htmlspecialchars($dateFilter) ?>" 
                               class="ml-2 px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                    </div>
                </div>
                
                <!-- Export -->
                <div class="ml-auto">
                    <button onclick="openExportModal()" class="px-4 py-2 bg-ayuni-blue text-white rounded-lg hover:bg-ayuni-blue/90 transition-colors text-sm">
                        <i class="fas fa-download mr-2"></i>Export Data
                    </button>
                </div>
            </div>
        </div>

        <!-- API Logs Table -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                    API Request Logs
                    <span class="text-sm font-normal text-gray-500 dark:text-gray-400 ml-2">
                        (<?= number_format($totalLogs) ?> total • Page <?= $page ?> of <?= $totalPages ?>)
                    </span>
                </h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">AEI</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Tokens</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Time (ms)</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?= date('M j, g:i A', strtotime($log['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?= htmlspecialchars($log['anonymous_user'] ?? 'Unknown') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?= htmlspecialchars($log['aei_name'] ?? 'Unknown') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php if ($log['status'] === 'success'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-400">
                                            <i class="fas fa-check-circle mr-1"></i>Success
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-400">
                                            <i class="fas fa-exclamation-circle mr-1"></i>Error
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?= number_format($log['tokens_used'] ?? 0) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                    <?= number_format($log['processing_time_ms'] ?? 0) ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <button onclick="viewLogDetails('<?= htmlspecialchars(json_encode($log)) ?>')" 
                                            class="text-ayuni-blue hover:text-ayuni-blue/80">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="7" class="px-6 py-12 text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-database text-4xl mb-4 opacity-50"></i>
                                    <p>No API logs found</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700 dark:text-gray-300">
                            Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $limit, $totalLogs)) ?> of <?= number_format($totalLogs) ?> results
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&status=<?= urlencode($statusFilter) ?>&date=<?= urlencode($dateFilter) ?>&aei_id=<?= urlencode($aeiFilter) ?>" 
                                   class="px-3 py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600">
                                    Previous
                                </a>
                            <?php endif; ?>
                            
                            <?php 
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++): 
                            ?>
                                <a href="?page=<?= $i ?>&status=<?= urlencode($statusFilter) ?>&date=<?= urlencode($dateFilter) ?>&aei_id=<?= urlencode($aeiFilter) ?>" 
                                   class="px-3 py-2 text-sm <?= $i === $page ? 'bg-ayuni-blue text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600' ?> rounded-md">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?= $page + 1 ?>&status=<?= urlencode($statusFilter) ?>&date=<?= urlencode($dateFilter) ?>&aei_id=<?= urlencode($aeiFilter) ?>" 
                                   class="px-3 py-2 text-sm bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-md hover:bg-gray-200 dark:hover:bg-gray-600">
                                    Next
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div id="export-modal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Export API Logs</h3>
        </div>
        
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="export">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Date Range</label>
                <div class="grid grid-cols-2 gap-2">
                    <input type="date" name="start_date" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                    <input type="date" name="end_date" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status Filter</label>
                <select name="status_filter" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                    <option value="all">All</option>
                    <option value="success">Success Only</option>
                    <option value="error">Errors Only</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Export Format</label>
                <select name="export_format" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                    <option value="json">JSON</option>
                    <option value="csv">CSV</option>
                </select>
            </div>
            
            <div class="flex space-x-3 pt-4">
                <button type="button" onclick="closeExportModal()" 
                        class="flex-1 px-4 py-2 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                    Cancel
                </button>
                <button type="submit" 
                        class="flex-1 px-4 py-2 bg-ayuni-blue text-white rounded-lg hover:bg-ayuni-blue/90 transition-colors">
                    <i class="fas fa-download mr-2"></i>Export
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Log Details Modal -->
<div id="log-details-modal" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">API Request Details</h3>
            <button onclick="closeLogDetailsModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto max-h-[calc(90vh-100px)]">
            <div id="log-details-content">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<script>
// Filter functionality
document.getElementById('status-filter').addEventListener('change', function() {
    updateFilters();
});

document.getElementById('date-filter').addEventListener('change', function() {
    updateFilters();
});

function updateFilters() {
    const status = document.getElementById('status-filter').value;
    const date = document.getElementById('date-filter').value;
    
    const params = new URLSearchParams();
    if (status && status !== 'all') params.set('status', status);
    if (date) params.set('date', date);
    
    window.location.search = params.toString();
}

// Export modal
function openExportModal() {
    document.getElementById('export-modal').classList.remove('hidden');
}

function closeExportModal() {
    document.getElementById('export-modal').classList.add('hidden');
}

// Log details modal
function viewLogDetails(logData) {
    const log = JSON.parse(logData);
    const content = document.getElementById('log-details-content');
    
    content.innerHTML = `
        <div class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                    <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Request Info</h4>
                    <div class="text-sm space-y-1">
                        <div><strong>Time:</strong> ${log.created_at}</div>
                        <div><strong>User:</strong> ${log.anonymous_user || 'Unknown'}</div>
                        <div><strong>AEI:</strong> ${log.aei_name || 'Unknown'}</div>
                        <div><strong>Status:</strong> <span class="px-2 py-1 rounded text-xs ${log.status === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">${log.status}</span></div>
                        <div><strong>Processing Time:</strong> ${log.processing_time_ms}ms</div>
                        <div><strong>Tokens Used:</strong> ${log.tokens_used}</div>
                    </div>
                </div>
                
                ${log.error_message ? `
                <div class="bg-red-50 dark:bg-red-900/20 p-4 rounded-lg">
                    <h4 class="font-semibold text-red-900 dark:text-red-100 mb-2">Error Message</h4>
                    <div class="text-sm text-red-800 dark:text-red-200">${log.error_message}</div>
                </div>
                ` : ''}
            </div>
            
            ${log.user_message ? `
            <div class="bg-blue-50 dark:bg-blue-900/20 p-4 rounded-lg">
                <h4 class="font-semibold text-blue-900 dark:text-blue-100 mb-2">User Message</h4>
                <div class="text-sm text-blue-800 dark:text-blue-200 whitespace-pre-wrap">${log.user_message}</div>
            </div>
            ` : ''}
            
            ${log.ai_response ? `
            <div class="bg-green-50 dark:bg-green-900/20 p-4 rounded-lg">
                <h4 class="font-semibold text-green-900 dark:text-green-100 mb-2">AI Response</h4>
                <div class="text-sm text-green-800 dark:text-green-200 whitespace-pre-wrap">${log.ai_response}</div>
            </div>
            ` : ''}
            
            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Raw Request Payload</h4>
                <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto bg-white dark:bg-gray-800 p-3 rounded border">${log.request_payload ? JSON.stringify(JSON.parse(log.request_payload), null, 2) : 'N/A'}</pre>
            </div>
            
            ${log.response_payload ? `
            <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                <h4 class="font-semibold text-gray-900 dark:text-white mb-2">Raw Response Payload</h4>
                <pre class="text-xs text-gray-600 dark:text-gray-400 overflow-x-auto bg-white dark:bg-gray-800 p-3 rounded border">${JSON.stringify(JSON.parse(log.response_payload), null, 2)}</pre>
            </div>
            ` : ''}
        </div>
    `;
    
    document.getElementById('log-details-modal').classList.remove('hidden');
}

function closeLogDetailsModal() {
    document.getElementById('log-details-modal').classList.add('hidden');
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeExportModal();
        closeLogDetailsModal();
    }
});
</script>
</div>