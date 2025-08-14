<?php
requireAdmin();

// Include required classes
if (file_exists(__DIR__ . '/../config/memory_config.php')) {
    require_once __DIR__ . '/../config/memory_config.php';
}
require_once __DIR__ . '/../includes/qdrant_inference_client.php';
require_once __DIR__ . '/../includes/memory_manager_inference.php';

$setupResults = [];
$memoryStatus = [];
$configExists = file_exists(__DIR__ . '/../config/memory_config.php');

// Handle setup execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $setupResults['error'] = 'Invalid CSRF token';
    } else {
        switch ($_POST['action']) {
            case 'test_connection':
                $setupResults = testMemoryConnection();
                break;
            case 'run_setup':
                $setupResults = runMemorySetup();
                break;
            case 'cleanup_memories':
                $setupResults = cleanupMemories($_POST['aei_id'] ?? '');
                break;
        }
    }
}

// Get memory system status
$memoryStatus = getMemorySystemStatus();

function testMemoryConnection() {
    try {
        if (!file_exists(__DIR__ . '/../config/memory_config.php')) {
            return ['error' => 'Memory config not found. Please copy config/memory_config.example.php to config/memory_config.php'];
        }
        
        // Config already included at top
        
        if (!defined('QDRANT_URL') || !defined('QDRANT_API_KEY')) {
            return ['error' => 'Missing QDRANT_URL or QDRANT_API_KEY in config'];
        }
        
        // Debug info
        $debugInfo = [
            'qdrant_url' => defined('QDRANT_URL') ? 'Set' : 'Missing',
            'qdrant_api_key' => defined('QDRANT_API_KEY') ? 'Set' : 'Missing',
            'config_file' => file_exists(__DIR__ . '/../config/memory_config.php') ? 'Exists' : 'Missing'
        ];
        
        global $pdo;
        $memoryOptions = [
            'default_model' => defined('MEMORY_DEFAULT_MODEL') ? MEMORY_DEFAULT_MODEL : 'sentence-transformers/all-MiniLM-L6-v2',
            'quality_model' => defined('MEMORY_QUALITY_MODEL') ? MEMORY_QUALITY_MODEL : 'mixedbread-ai/mxbai-embed-large-v1',
            'collection_prefix' => defined('MEMORY_COLLECTION_PREFIX') ? MEMORY_COLLECTION_PREFIX : 'aei_memories_'
        ];
        
        $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
        $health = (new QdrantInferenceClient(QDRANT_URL, QDRANT_API_KEY))->healthCheck();
        
        if ($health['status'] === 'healthy') {
            return [
                'success' => 'Connection successful!',
                'details' => [
                    'cluster_health' => 'Healthy',
                    'collections' => $health['collections'],
                    'default_model' => $memoryOptions['default_model'],
                    'quality_model' => $memoryOptions['quality_model']
                ]
            ];
        } else {
            return ['error' => 'Cluster unhealthy: ' . ($health['error'] ?? 'Unknown error')];
        }
        
    } catch (Exception $e) {
        return ['error' => 'Connection failed: ' . $e->getMessage()];
    }
}

function runMemorySetup() {
    try {
        if (!file_exists(__DIR__ . '/../config/memory_config.php')) {
            return ['error' => 'Memory config not found'];
        }
        
        // Config already included at top
        
        global $pdo;
        $memoryOptions = [
            'default_model' => defined('MEMORY_DEFAULT_MODEL') ? MEMORY_DEFAULT_MODEL : 'sentence-transformers/all-MiniLM-L6-v2',
            'quality_model' => defined('MEMORY_QUALITY_MODEL') ? MEMORY_QUALITY_MODEL : 'mixedbread-ai/mxbai-embed-large-v1',
            'collection_prefix' => defined('MEMORY_COLLECTION_PREFIX') ? MEMORY_COLLECTION_PREFIX : 'aei_memories_'
        ];
        
        $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
        
        // Test with first available AEI
        $stmt = $pdo->query("SELECT id, name FROM aeis WHERE is_active = TRUE LIMIT 1");
        $testAei = $stmt->fetch();
        
        if (!$testAei) {
            return ['warning' => 'Setup completed but no AEIs found for testing. Create an AEI first.'];
        }
        
        // Test memory storage
        try {
            $testMemoryId = $memoryManager->storeMemory(
                $testAei['id'],
                'Test memory for setup validation - system working correctly',
                'fact',
                0.8
            );
            
            if (!$testMemoryId) {
                // Get recent error logs for debugging
                $errorLogs = getRecentErrorLogs();
                return [
                    'error' => 'Memory storage test failed - storeMemory returned false',
                    'error_logs' => $errorLogs,
                    'debug_info' => [
                        'aei_id' => $testAei['id'],
                        'memory_options' => $memoryOptions,
                        'last_php_error' => error_get_last()
                    ]
                ];
            }
        } catch (Exception $e) {
            // Get recent error logs for debugging
            $errorLogs = getRecentErrorLogs();
            return [
                'error' => 'Memory storage test failed: ' . $e->getMessage(),
                'error_logs' => $errorLogs,
                'debug_info' => [
                    'exception_trace' => $e->getTraceAsString(),
                    'aei_id' => $testAei['id'],
                    'memory_options' => $memoryOptions
                ]
            ];
        }
        
        // Test memory retrieval  
        try {
            $memories = $memoryManager->retrieveMemories($testAei['id'], 'test memory setup validation', 1);
            
            if (empty($memories)) {
                return ['error' => 'Memory retrieval test failed - no memories found'];
            }
            
            if ($memories[0]['memory_id'] !== $testMemoryId) {
                return ['error' => 'Memory retrieval test failed - wrong memory returned (expected: ' . $testMemoryId . ', got: ' . $memories[0]['memory_id'] . ')'];
            }
        } catch (Exception $e) {
            return ['error' => 'Memory retrieval test failed: ' . $e->getMessage()];
        }
        
        // Cleanup test memory
        try {
            $stmt = $pdo->prepare("DELETE FROM aei_memories WHERE memory_id = ?");
            $stmt->execute([$testMemoryId]);
            
            $qdrantClient = new QdrantInferenceClient(QDRANT_URL, QDRANT_API_KEY);
            $qdrantClient->deletePoints($memoryOptions['collection_prefix'] . $testAei['id'], [$testMemoryId]);
        } catch (Exception $e) {
            // Ignore cleanup errors - test was successful, cleanup is optional
            error_log("Test memory cleanup failed: " . $e->getMessage());
        }
        
        return [
            'success' => 'Memory system setup completed successfully!',
            'details' => [
                'test_aei' => $testAei['name'],
                'memory_stored' => 'Yes',
                'memory_retrieved' => 'Yes',
                'similarity_score' => number_format($memories[0]['similarity_score'], 3),
                'model_used' => $memories[0]['model_used']
            ]
        ];
        
    } catch (Exception $e) {
        return ['error' => 'Setup failed: ' . $e->getMessage()];
    }
}

function cleanupMemories($aeiId = '') {
    try {
        if (!file_exists(__DIR__ . '/../config/memory_config.php')) {
            return ['error' => 'Memory config not found'];
        }
        
        // Config already included at top
        
        global $pdo;
        $memoryOptions = [
            'default_model' => defined('MEMORY_DEFAULT_MODEL') ? MEMORY_DEFAULT_MODEL : 'sentence-transformers/all-MiniLM-L6-v2',
            'quality_model' => defined('MEMORY_QUALITY_MODEL') ? MEMORY_QUALITY_MODEL : 'mixedbread-ai/mxbai-embed-large-v1',
            'collection_prefix' => defined('MEMORY_COLLECTION_PREFIX') ? MEMORY_COLLECTION_PREFIX : 'aei_memories_'
        ];
        
        $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
        
        if (empty($aeiId)) {
            // Cleanup all AEIs
            $stmt = $pdo->query("SELECT DISTINCT aei_id FROM aei_memories");
            $aeiIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $totalCleaned = 0;
            
            foreach ($aeiIds as $id) {
                $cleaned = $memoryManager->cleanupMemories($id);
                $totalCleaned += $cleaned;
            }
            
            return [
                'success' => "Cleaned up $totalCleaned old memories across " . count($aeiIds) . " AEIs"
            ];
        } else {
            $cleaned = $memoryManager->cleanupMemories($aeiId);
            return [
                'success' => "Cleaned up $cleaned old memories for selected AEI"
            ];
        }
        
    } catch (Exception $e) {
        return ['error' => 'Cleanup failed: ' . $e->getMessage()];
    }
}

function getRecentErrorLogs($lines = 50) {
    $logs = [];
    
    // Try multiple common error log locations
    $logPaths = [
        ini_get('error_log'),
        '/var/log/apache2/error.log',
        '/var/log/nginx/error.log',
        '/var/www/vhosts/' . $_SERVER['HTTP_HOST'] . '/logs/error.log',
        '/home/' . get_current_user() . '/logs/error.log',
        '/tmp/php_errors.log',
        __DIR__ . '/../error.log'
    ];
    
    foreach ($logPaths as $logPath) {
        if ($logPath && file_exists($logPath) && is_readable($logPath)) {
            try {
                $command = "tail -n $lines " . escapeshellarg($logPath) . " 2>/dev/null";
                $output = shell_exec($command);
                if ($output) {
                    $logs[] = [
                        'source' => $logPath,
                        'content' => $output
                    ];
                }
            } catch (Exception $e) {
                // Skip this log file
            }
        }
    }
    
    // Also try to get PHP errors from error_get_last()
    $lastError = error_get_last();
    if ($lastError) {
        $logs[] = [
            'source' => 'PHP error_get_last()',
            'content' => json_encode($lastError, JSON_PRETTY_PRINT)
        ];
    }
    
    return $logs;
}

function getMemorySystemStatus() {
    $status = [
        'config_exists' => file_exists(__DIR__ . '/../config/memory_config.php'),
        'table_exists' => false,
        'total_memories' => 0,
        'active_aeis_with_memories' => 0,
        'average_importance' => 0,
        'models_in_use' => []
    ];
    
    try {
        global $pdo;
        
        // Check table exists
        $result = $pdo->query("SHOW TABLES LIKE 'aei_memories'")->fetch();
        $status['table_exists'] = (bool)$result;
        
        if ($status['table_exists']) {
            // Get total memories
            $stmt = $pdo->query("SELECT COUNT(*) FROM aei_memories");
            $status['total_memories'] = $stmt->fetchColumn();
            
            // Get active AEIs with memories
            $stmt = $pdo->query("SELECT COUNT(DISTINCT aei_id) FROM aei_memories");
            $status['active_aeis_with_memories'] = $stmt->fetchColumn();
            
            // Get average importance
            $stmt = $pdo->query("SELECT AVG(importance_score) FROM aei_memories");
            $status['average_importance'] = round($stmt->fetchColumn(), 2);
            
            // Get models in use
            $stmt = $pdo->query("SELECT embedding_model, COUNT(*) as count FROM aei_memories GROUP BY embedding_model");
            $status['models_in_use'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Test connection if config exists
        if ($status['config_exists']) {
            // Config already included at top
            if (defined('QDRANT_URL') && defined('QDRANT_API_KEY')) {
                try {
                    $qdrantClient = new QdrantInferenceClient(QDRANT_URL, QDRANT_API_KEY);
                    $health = $qdrantClient->healthCheck();
                    $status['qdrant_status'] = $health['status'];
                    $status['qdrant_collections'] = $health['collections'] ?? 0;
                } catch (Exception $e) {
                    $status['qdrant_status'] = 'error';
                    $status['qdrant_error'] = $e->getMessage();
                }
            }
        }
        
    } catch (Exception $e) {
        $status['error'] = $e->getMessage();
    }
    
    return $status;
}

// Get AEIs for cleanup dropdown
try {
    $stmt = $pdo->query("
        SELECT a.id, a.name, COUNT(m.memory_id) as memory_count 
        FROM aeis a 
        LEFT JOIN aei_memories m ON a.id = m.aei_id 
        WHERE a.is_active = TRUE 
        GROUP BY a.id, a.name 
        ORDER BY a.name
    ");
    $aeiList = $stmt->fetchAll();
} catch (Exception $e) {
    $aeiList = [];
}
?>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center mb-6">
        <i class="fas fa-brain text-2xl text-ayuni-blue mr-3"></i>
        <h1 class="text-3xl font-bold text-white">Memory System Setup</h1>
    </div>

    <!-- Status Overview -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-ayuni-dark/50 rounded-lg p-4">
            <div class="flex items-center">
                <i class="fas fa-cog text-lg text-ayuni-aqua mr-2"></i>
                <div>
                    <p class="text-gray-300 text-sm">Configuration</p>
                    <p class="text-white font-semibold">
                        <?= $memoryStatus['config_exists'] ? 'Ready' : 'Missing' ?>
                    </p>
                </div>
            </div>
        </div>
        
        <div class="bg-ayuni-dark/50 rounded-lg p-4">
            <div class="flex items-center">
                <i class="fas fa-database text-lg text-ayuni-blue mr-2"></i>
                <div>
                    <p class="text-gray-300 text-sm">Total Memories</p>
                    <p class="text-white font-semibold"><?= number_format($memoryStatus['total_memories']) ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-ayuni-dark/50 rounded-lg p-4">
            <div class="flex items-center">
                <i class="fas fa-robot text-lg text-ayuni-aqua mr-2"></i>
                <div>
                    <p class="text-gray-300 text-sm">AEIs with Memories</p>
                    <p class="text-white font-semibold"><?= $memoryStatus['active_aeis_with_memories'] ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-ayuni-dark/50 rounded-lg p-4">
            <div class="flex items-center">
                <i class="fas fa-server text-lg <?= isset($memoryStatus['qdrant_status']) && $memoryStatus['qdrant_status'] === 'healthy' ? 'text-green-400' : 'text-red-400' ?> mr-2"></i>
                <div>
                    <p class="text-gray-300 text-sm">Qdrant Status</p>
                    <p class="text-white font-semibold">
                        <?= isset($memoryStatus['qdrant_status']) ? ucfirst($memoryStatus['qdrant_status']) : 'Unknown' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Setup Results -->
    <?php if (!empty($setupResults)): ?>
        <div class="mb-6">
            <?php if (isset($setupResults['success'])): ?>
                <div class="bg-green-600/20 border border-green-600/50 rounded-lg p-4">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-check-circle text-green-400 mr-2"></i>
                        <span class="text-green-400 font-semibold">Success</span>
                    </div>
                    <p class="text-white"><?= htmlspecialchars($setupResults['success']) ?></p>
                    
                    <?php if (isset($setupResults['details'])): ?>
                        <div class="mt-3 space-y-1">
                            <?php foreach ($setupResults['details'] as $key => $value): ?>
                                <div class="text-sm">
                                    <span class="text-gray-300"><?= ucfirst(str_replace('_', ' ', $key)) ?>:</span>
                                    <span class="text-white ml-2"><?= htmlspecialchars($value) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($setupResults['debug_info'])): ?>
                        <div class="mt-4">
                            <details class="text-sm">
                                <summary class="text-red-300 cursor-pointer hover:text-red-200">Debug Information</summary>
                                <div class="mt-2 bg-red-900/20 p-3 rounded border border-red-600/30">
                                    <?php foreach ($setupResults['debug_info'] as $key => $value): ?>
                                        <div class="mb-2">
                                            <strong class="text-red-300"><?= ucfirst(str_replace('_', ' ', $key)) ?>:</strong>
                                            <pre class="text-red-100 text-xs mt-1 overflow-x-auto"><?= htmlspecialchars(is_array($value) ? json_encode($value, JSON_PRETTY_PRINT) : $value) ?></pre>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($setupResults['error_logs']) && !empty($setupResults['error_logs'])): ?>
                        <div class="mt-4">
                            <details class="text-sm">
                                <summary class="text-red-300 cursor-pointer hover:text-red-200">Recent Error Logs</summary>
                                <div class="mt-2 space-y-2">
                                    <?php foreach ($setupResults['error_logs'] as $log): ?>
                                        <div class="bg-red-900/20 p-3 rounded border border-red-600/30">
                                            <div class="text-red-300 text-xs mb-1">Source: <?= htmlspecialchars($log['source']) ?></div>
                                            <pre class="text-red-100 text-xs overflow-x-auto max-h-48 overflow-y-auto"><?= htmlspecialchars($log['content']) ?></pre>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            <?php elseif (isset($setupResults['warning'])): ?>
                <div class="bg-yellow-600/20 border border-yellow-600/50 rounded-lg p-4">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-exclamation-triangle text-yellow-400 mr-2"></i>
                        <span class="text-yellow-400 font-semibold">Warning</span>
                    </div>
                    <p class="text-white"><?= htmlspecialchars($setupResults['warning']) ?></p>
                </div>
            <?php elseif (isset($setupResults['error'])): ?>
                <div class="bg-red-600/20 border border-red-600/50 rounded-lg p-4">
                    <div class="flex items-center mb-2">
                        <i class="fas fa-exclamation-circle text-red-400 mr-2"></i>
                        <span class="text-red-400 font-semibold">Error</span>
                    </div>
                    <p class="text-white"><?= htmlspecialchars($setupResults['error']) ?></p>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Setup Actions -->
        <div class="bg-ayuni-dark/30 rounded-lg p-6">
            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                <i class="fas fa-play-circle text-ayuni-aqua mr-2"></i>
                Setup Actions
            </h2>
            
            <div class="space-y-4">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <button type="submit" name="action" value="test_connection" 
                            class="w-full bg-ayuni-blue hover:bg-blue-600 text-white font-semibold py-2 px-4 rounded transition-colors flex items-center justify-center">
                        <i class="fas fa-plug mr-2"></i>
                        Test Connection
                    </button>
                    
                    <button type="submit" name="action" value="run_setup"
                            class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold py-2 px-4 rounded transition-colors flex items-center justify-center">
                        <i class="fas fa-rocket mr-2"></i>
                        Run Full Setup
                    </button>
                </form>
                
                <?php if (!$configExists): ?>
                    <div class="bg-yellow-600/20 border border-yellow-600/50 rounded p-3">
                        <p class="text-yellow-100 text-sm">
                            <i class="fas fa-info-circle mr-1"></i>
                            Copy <code>config/memory_config.example.php</code> to <code>config/memory_config.php</code> and add your Qdrant credentials first.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Memory Statistics -->
        <div class="bg-ayuni-dark/30 rounded-lg p-6">
            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                <i class="fas fa-chart-bar text-ayuni-aqua mr-2"></i>
                Memory Statistics
            </h2>
            
            <div class="space-y-4">
                <div>
                    <p class="text-gray-300 text-sm">Average Importance Score</p>
                    <p class="text-white text-lg font-semibold"><?= $memoryStatus['average_importance'] ?: 'N/A' ?></p>
                </div>
                
                <?php if (!empty($memoryStatus['models_in_use'])): ?>
                    <div>
                        <p class="text-gray-300 text-sm mb-2">Models in Use</p>
                        <div class="space-y-2">
                            <?php foreach ($memoryStatus['models_in_use'] as $model): ?>
                                <div class="flex justify-between">
                                    <span class="text-white text-sm"><?= htmlspecialchars($model['embedding_model']) ?></span>
                                    <span class="text-ayuni-aqua text-sm"><?= number_format($model['count']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($memoryStatus['qdrant_collections'])): ?>
                    <div>
                        <p class="text-gray-300 text-sm">Qdrant Collections</p>
                        <p class="text-white text-lg font-semibold"><?= $memoryStatus['qdrant_collections'] ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Memory Cleanup -->
    <?php if (!empty($aeiList)): ?>
        <div class="mt-8 bg-ayuni-dark/30 rounded-lg p-6">
            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                <i class="fas fa-broom text-ayuni-aqua mr-2"></i>
                Memory Cleanup
            </h2>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div>
                    <label class="block text-gray-300 text-sm mb-2">Select AEI (optional)</label>
                    <select name="aei_id" class="w-full bg-ayuni-dark border border-gray-600 rounded px-3 py-2 text-white">
                        <option value="">All AEIs</option>
                        <?php foreach ($aeiList as $aei): ?>
                            <option value="<?= htmlspecialchars($aei['id']) ?>">
                                <?= htmlspecialchars($aei['name']) ?> (<?= $aei['memory_count'] ?> memories)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit" name="action" value="cleanup_memories"
                        class="bg-orange-600 hover:bg-orange-700 text-white font-semibold py-2 px-4 rounded transition-colors flex items-center"
                        onclick="return confirm('This will remove old and low-importance memories. Continue?')">
                    <i class="fas fa-trash-alt mr-2"></i>
                    Cleanup Old Memories
                </button>
            </form>
            
            <p class="text-gray-400 text-sm mt-2">
                Removes memories older than <?= defined('MEMORY_CLEANUP_DAYS') ? MEMORY_CLEANUP_DAYS : 90 ?> days 
                or with importance below <?= defined('MEMORY_IMPORTANCE_THRESHOLD') ? MEMORY_IMPORTANCE_THRESHOLD : 0.1 ?> 
                that have been accessed less than 3 times.
            </p>
        </div>
    <?php endif; ?>
</div>