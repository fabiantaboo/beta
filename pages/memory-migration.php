<?php
/**
 * Admin Memory Migration Page
 * Convert existing Q&A pairs to structured facts
 */

// Require admin access
requireAdmin();

$pageTitle = "Memory Migration - Q&A to Facts";
$migrationStatus = null;
$migrationResults = [];
$availableAeis = [];

// Load memory configuration status
$memoryConfigured = file_exists(__DIR__ . '/../config/memory_config.php');
if ($memoryConfigured) {
    require_once __DIR__ . '/../config/memory_config.php';
    require_once __DIR__ . '/../includes/memory_manager_inference.php';
}

// Get available AEIs for migration
if ($memoryConfigured && defined('QDRANT_URL') && defined('QDRANT_API_KEY')) {
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.name, a.user_id, u.first_name as user_name,
                   COUNT(DISTINCT cs.id) as session_count,
                   COUNT(cm.id) as message_count,
                   MIN(cm.created_at) as first_message,
                   MAX(cm.created_at) as last_message
            FROM aeis a
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN chat_sessions cs ON a.id = cs.aei_id  
            LEFT JOIN chat_messages cm ON cs.id = cm.session_id
            WHERE a.is_active = TRUE
            GROUP BY a.id, a.name, a.user_id, u.first_name
            HAVING message_count >= 5
            ORDER BY message_count DESC
        ");
        $stmt->execute();
        $availableAeis = $stmt->fetchAll();
    } catch (Exception $e) {
        $migrationStatus = 'error';
        $migrationResults[] = 'Database error: ' . $e->getMessage();
    }
}

// Handle migration request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'migrate') {
    if (!verifyCSRFToken($_POST['csrf_token'])) {
        $migrationStatus = 'error';
        $migrationResults[] = 'Invalid CSRF token';
    } elseif (!$memoryConfigured) {
        $migrationStatus = 'error';
        $migrationResults[] = 'Memory system not configured';
    } else {
        try {
            require_once __DIR__ . '/../includes/anthropic_api.php';
            
            // Initialize memory manager
            $memoryOptions = [
                'default_model' => MEMORY_DEFAULT_MODEL,
                'quality_model' => MEMORY_QUALITY_MODEL,
                'collection_prefix' => 'aei_memories_',
                'facts_prefix' => 'aei_facts_'
            ];
            
            $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
            
            $selectedAeis = $_POST['selected_aeis'] ?? [];
            $batchSize = max(10, min(50, (int)($_POST['batch_size'] ?? 25))); // Larger batches to reduce duplicates
            
            if (empty($selectedAeis)) {
                $migrationStatus = 'error';
                $migrationResults[] = 'No AEIs selected for migration';
            } else {
                $migrationStatus = 'success';
                $totalExtracted = 0;
                
                foreach ($selectedAeis as $aeiId) {
                    try {
                        // Get AEI info
                        $stmt = $pdo->prepare("SELECT name, user_id FROM aeis WHERE id = ? AND is_active = TRUE");
                        $stmt->execute([$aeiId]);
                        $aei = $stmt->fetch();
                        
                        if (!$aei) {
                            $migrationResults[] = "⚠️ AEI $aeiId not found or inactive";
                            continue;
                        }
                        
                        $migrationResults[] = "🤖 Processing: {$aei['name']} (ID: $aeiId)";
                        
                        // Get chat sessions for this AEI
                        $stmt = $pdo->prepare("
                            SELECT DISTINCT cs.id as session_id, COUNT(cm.id) as session_messages
                            FROM chat_sessions cs
                            INNER JOIN chat_messages cm ON cs.id = cm.session_id  
                            WHERE cs.aei_id = ?
                            GROUP BY cs.id
                            HAVING session_messages >= 3
                            ORDER BY cs.created_at DESC
                        ");
                        $stmt->execute([$aeiId]);
                        $sessions = $stmt->fetchAll();
                        
                        $aeiExtractedCount = 0;
                        
                        foreach ($sessions as $session) {
                            // Get chat history for this session
                            $stmt = $pdo->prepare("
                                SELECT sender_type, message_text, created_at
                                FROM chat_messages 
                                WHERE session_id = ? 
                                ORDER BY created_at ASC
                            ");
                            $stmt->execute([$session['session_id']]);
                            $chatMessages = $stmt->fetchAll();
                            
                            // Process in batches
                            $batches = array_chunk($chatMessages, $batchSize);
                            
                            foreach ($batches as $batch) {
                                if (count($batch) < 3) continue;
                                
                                try {
                                    // Convert to proper format
                                    $formattedMessages = [];
                                    foreach ($batch as $msg) {
                                        $formattedMessages[] = [
                                            'role' => $msg['sender_type'] === 'user' ? 'user' : 'assistant',
                                            'content' => $msg['message_text']
                                        ];
                                    }
                                    
                                    // Extract memories from this batch
                                    $extractedMemories = $memoryManager->extractMemoriesFromConversation(
                                        $aeiId,
                                        $formattedMessages,
                                        $aei['user_id'],
                                        $session['session_id']
                                    );
                                    
                                    $aeiExtractedCount += count($extractedMemories);
                                    
                                } catch (Exception $batchError) {
                                    $migrationResults[] = "⚠️ Batch error: " . $batchError->getMessage();
                                }
                            }
                        }
                        
                        $totalExtracted += $aeiExtractedCount;
                        
                        // Warn about potential duplicates if too many facts extracted
                        $sessionCount = count($sessions);
                        if ($aeiExtractedCount > ($sessionCount * 10)) {
                            $migrationResults[] = "⚠️ {$aei['name']}: $aeiExtractedCount facts extracted (possibly contains duplicates due to overlapping batches)";
                        } else {
                            $migrationResults[] = "✅ {$aei['name']}: $aeiExtractedCount facts extracted";
                        }
                        
                    } catch (Exception $aeiError) {
                        $migrationResults[] = "❌ Error processing AEI $aeiId: " . $aeiError->getMessage();
                    }
                }
                
                $migrationResults[] = "";
                $migrationResults[] = "🎉 Migration Complete!";
                $migrationResults[] = "Total facts extracted: $totalExtracted";
                $migrationResults[] = "Old Q&A memories preserved as backup";
            }
            
        } catch (Exception $e) {
            $migrationStatus = 'error';
            $migrationResults[] = 'Migration failed: ' . $e->getMessage();
        }
    }
} elseif ($_POST['action'] === 'parallel_migrate') {
        // Handle parallel migration request
        if (!verifyCSRFToken($_POST['csrf_token'])) {
            $migrationStatus = 'error';
            $migrationResults[] = 'Invalid CSRF token';
        } elseif (!$memoryConfigured) {
            $migrationStatus = 'error';
            $migrationResults[] = 'Memory system not configured';
        } else {
            try {
                $selectedAeis = $_POST['selected_aeis'] ?? [];
                $batchSize = max(10, min(50, (int)($_POST['batch_size'] ?? 25)));
                $parallelJobs = min(20, max(1, (int)($_POST['parallel_jobs'] ?? 10))); // 1-20 parallel jobs
                
                if (empty($selectedAeis)) {
                    $migrationStatus = 'error';
                    $migrationResults[] = 'No AEIs selected for migration';
                } else {
                    // Create parallel migration jobs
                    $jobId = bin2hex(random_bytes(16));
                    
                    // Prepare AEI data for parallel processing
                    $aeiJobData = [];
                    foreach ($selectedAeis as $aeiId) {
                        $stmt = $pdo->prepare("SELECT name, user_id FROM aeis WHERE id = ? AND is_active = TRUE");
                        $stmt->execute([$aeiId]);
                        $aei = $stmt->fetch();
                        
                        if ($aei) {
                            // Get sessions for this AEI
                            $stmt = $pdo->prepare("
                                SELECT DISTINCT cs.id as session_id, COUNT(cm.id) as session_messages
                                FROM chat_sessions cs
                                INNER JOIN chat_messages cm ON cs.id = cm.session_id  
                                WHERE cs.aei_id = ?
                                GROUP BY cs.id
                                HAVING session_messages >= 3
                                ORDER BY cs.created_at DESC
                            ");
                            $stmt->execute([$aeiId]);
                            $sessions = $stmt->fetchAll();
                            
                            $aeiJobData[] = [
                                'aei_id' => $aeiId,
                                'aei_name' => $aei['name'],
                                'user_id' => $aei['user_id'],
                                'sessions' => $sessions,
                                'batch_size' => $batchSize
                            ];
                        }
                    }
                    
                    // Create job in database
                    $stmt = $pdo->prepare("
                        INSERT INTO migration_jobs (job_id, user_id, job_type, status, job_data, progress_total) 
                        VALUES (?, ?, 'memory_migration', 'pending', ?, ?)
                    ");
                    $stmt->execute([
                        $jobId, 
                        getUserSession(), 
                        json_encode(['aeis' => $aeiJobData, 'parallel_jobs' => $parallelJobs]),
                        count($aeiJobData)
                    ]);
                    
                    // Create worker batches
                    $workerBatches = array_chunk($aeiJobData, max(1, ceil(count($aeiJobData) / $parallelJobs)));
                    
                    // Start parallel workers immediately using cURL
                    $migrationResults[] = "🚀 Starting $parallelJobs parallel workers...";
                    $workerCount = 0;
                    
                    foreach ($workerBatches as $batchIndex => $batch) {
                        $workerJobId = $jobId . '_worker_' . $batchIndex;
                        $workerCount++;
                        
                        // Prepare worker data
                        $workerData = [
                            'job_id' => $workerJobId,
                            'batch_data' => $batch,
                            'csrf_token' => generateCSRFToken()
                        ];
                        
                        // Start worker using background cURL request
                        $ch = curl_init();
                        curl_setopt($ch, CURLOPT_URL, (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api/memory-migration-worker.php');
                        curl_setopt($ch, CURLOPT_POST, true);
                        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($workerData));
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Content-Type: application/json',
                            'Content-Length: ' . strlen(json_encode($workerData))
                        ]);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // Quick timeout since we don't wait for response
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        
                        // Execute in background (fire and forget)
                        $response = curl_exec($ch);
                        curl_close($ch);
                        
                        $migrationResults[] = "✅ Worker $workerCount started (processing " . count($batch) . " AEIs)";
                    }
                    
                    $migrationStatus = 'parallel_started';
                    $migrationResults[] = "";
                    $migrationResults[] = "🎉 All $workerCount workers started successfully!";
                    $migrationResults[] = "Job ID: $jobId";
                    $migrationResults[] = "Total AEIs being processed: " . count($aeiJobData);
                    $migrationResults[] = "Check progress below - page will auto-refresh when complete";
                    
                    // Store job ID for progress tracking
                    $_SESSION['current_migration_job'] = $jobId;
                }
            } catch (Exception $e) {
                $migrationStatus = 'error';
                $migrationResults[] = 'Parallel migration setup failed: ' . $e->getMessage();
            }
        }
    }
}
?>

<div class="max-w-6xl mx-auto">
    <div class="flex items-center gap-3 mb-8">
        <div class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-xl p-3">
            <i class="fas fa-brain text-white text-xl"></i>
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">Memory Migration</h1>
            <p class="text-gray-600 dark:text-gray-400">Convert Q&A pairs to structured facts</p>
        </div>
    </div>

    <?php if (!$memoryConfigured): ?>
        <!-- Memory System Not Configured -->
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-6 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400"></i>
                <h2 class="text-lg font-semibold text-red-800 dark:text-red-300">Memory System Not Configured</h2>
            </div>
            <p class="text-red-700 dark:text-red-400 mb-4">
                The memory system must be configured before running migrations.
            </p>
            <div class="bg-red-100 dark:bg-red-800/30 p-4 rounded-lg">
                <p class="text-sm font-mono text-red-800 dark:text-red-300">
                    Please copy <code>config/memory_config.example.php</code> to <code>config/memory_config.php</code> and configure your Qdrant settings.
                </p>
            </div>
        </div>

    <?php elseif (!defined('QDRANT_URL') || !defined('QDRANT_API_KEY')): ?>
        <!-- Missing Configuration -->
        <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-xl p-6 mb-6">
            <h2 class="text-lg font-semibold text-orange-800 dark:text-orange-300 mb-3">
                <i class="fas fa-cog mr-2"></i>Configuration Incomplete
            </h2>
            <p class="text-orange-700 dark:text-orange-400">
                QDRANT_URL or QDRANT_API_KEY is not properly configured in memory_config.php
            </p>
        </div>

    <?php elseif (empty($availableAeis)): ?>
        <!-- No AEIs Available -->
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 mb-6">
            <h2 class="text-lg font-semibold text-blue-800 dark:text-blue-300 mb-3">
                <i class="fas fa-info-circle mr-2"></i>No AEIs Available for Migration
            </h2>
            <p class="text-blue-700 dark:text-blue-400">
                No active AEIs found with sufficient chat history (minimum 5 messages required).
            </p>
        </div>

    <?php else: ?>
        <!-- Migration Form -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Migration Configuration -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                    <i class="fas fa-cogs text-ayuni-blue mr-2"></i>Migration Settings
                </h2>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="migrate">

                    <!-- Migration Mode -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Migration Mode
                        </label>
                        <div class="space-y-3">
                            <label class="flex items-center">
                                <input type="radio" name="migration_mode" value="sequential" class="mr-2" checked>
                                <span class="text-sm text-gray-700 dark:text-gray-300">Sequential (slower, safer)</span>
                            </label>
                            <label class="flex items-center">
                                <input type="radio" name="migration_mode" value="parallel" class="mr-2">
                                <span class="text-sm text-gray-700 dark:text-gray-300">🚀 Parallel (much faster, up to 20x speed)</span>
                            </label>
                        </div>
                    </div>

                    <!-- Batch Size -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Batch Size (messages per extraction)
                        </label>
                        <input type="number" name="batch_size" value="25" min="10" max="50"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Larger batches = less duplicates, better context, but more expensive API calls
                        </p>
                    </div>

                    <!-- Parallel Workers (only shown when parallel mode selected) -->
                    <div id="parallel_settings" style="display: none;">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Parallel Workers (1-20)
                        </label>
                        <input type="number" name="parallel_jobs" value="10" min="1" max="20"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            More workers = faster processing, but higher server load
                        </p>
                    </div>

                    <!-- AEI Selection -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Select AEIs to Migrate
                        </label>
                        <div class="space-y-2 max-h-64 overflow-y-auto border border-gray-300 dark:border-gray-600 rounded-lg p-3">
                            <div class="flex items-center mb-3">
                                <input type="checkbox" id="select_all" class="mr-2">
                                <label for="select_all" class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Select All
                                </label>
                            </div>
                            
                            <?php foreach ($availableAeis as $aei): ?>
                                <div class="flex items-center p-2 bg-gray-50 dark:bg-gray-700 rounded">
                                    <input type="checkbox" name="selected_aeis[]" value="<?= htmlspecialchars($aei['id']) ?>" 
                                           id="aei_<?= htmlspecialchars($aei['id']) ?>" class="aei-checkbox mr-3">
                                    <div class="flex-1">
                                        <label for="aei_<?= htmlspecialchars($aei['id']) ?>" 
                                               class="font-medium text-gray-800 dark:text-white cursor-pointer">
                                            <?= htmlspecialchars($aei['name']) ?>
                                        </label>
                                        <div class="text-sm text-gray-600 dark:text-gray-400">
                                            User: <?= htmlspecialchars($aei['user_name']) ?> | 
                                            <?= number_format($aei['message_count']) ?> messages | 
                                            <?= $aei['session_count'] ?> sessions
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">
                                            <?= date('M j, Y', strtotime($aei['first_message'])) ?> - 
                                            <?= date('M j, Y', strtotime($aei['last_message'])) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Migration Button -->
                    <div class="pt-4 border-t border-gray-200 dark:border-gray-600">
                        <button type="submit" 
                                class="w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white px-6 py-3 rounded-xl hover:opacity-90 transition-opacity font-medium">
                            <i class="fas fa-play mr-2"></i>Start Migration
                        </button>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">
                            ⚠️ This process may take several minutes for large AEIs
                        </p>
                    </div>
                </form>
            </div>

            <!-- System Info -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">
                    <i class="fas fa-info-circle text-ayuni-aqua mr-2"></i>Migration Info
                </h2>

                <div class="space-y-4">
                    <div class="bg-blue-50 dark:bg-blue-900/30 p-4 rounded-lg">
                        <h3 class="font-medium text-blue-800 dark:text-blue-300 mb-2">What happens during migration:</h3>
                        <ul class="text-sm text-blue-700 dark:text-blue-400 space-y-1">
                            <li>• Existing chat history is analyzed in batches</li>
                            <li>• AI extracts structured facts from conversations</li>
                            <li>• Facts are stored in new <code>aei_facts_*</code> collections</li>
                            <li>• Old Q&A pairs remain as backup in <code>aei_memories_*</code></li>
                            <li>• Future memory retrieval uses only the new structured facts</li>
                        </ul>
                    </div>

                    <div class="bg-green-50 dark:bg-green-900/30 p-4 rounded-lg">
                        <h3 class="font-medium text-green-800 dark:text-green-300 mb-2">Benefits:</h3>
                        <ul class="text-sm text-green-700 dark:text-green-400 space-y-1">
                            <li>• Much cleaner memory retrieval</li>
                            <li>• Better semantic understanding</li>
                            <li>• Reduced noise in AI responses</li>
                            <li>• Preserved original data as backup</li>
                        </ul>
                    </div>

                    <div class="bg-yellow-50 dark:bg-yellow-900/30 p-4 rounded-lg">
                        <h3 class="font-medium text-yellow-800 dark:text-yellow-300 mb-2">⚠️ Important:</h3>
                        <ul class="text-sm text-yellow-700 dark:text-yellow-400 space-y-1">
                            <li>• Migration uses Anthropic API calls (has cost)</li>
                            <li>• Process can take several minutes per AEI</li>
                            <li>• Old memories remain accessible if needed</li>
                            <li>• Can be run multiple times safely</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Migration Results -->
    <?php if ($migrationStatus === 'success'): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-6 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <i class="fas fa-check-circle text-green-600 dark:text-green-400"></i>
                <h2 class="text-lg font-semibold text-green-800 dark:text-green-300">Migration Completed Successfully!</h2>
            </div>
            <div class="bg-green-100 dark:bg-green-800/30 p-4 rounded-lg">
                <pre class="text-sm text-green-800 dark:text-green-300 font-mono whitespace-pre-wrap"><?= htmlspecialchars(implode("\n", $migrationResults)) ?></pre>
            </div>
        </div>

    <?php elseif ($migrationStatus === 'parallel_started'): ?>
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <i class="fas fa-rocket text-blue-600 dark:text-blue-400"></i>
                <h2 class="text-lg font-semibold text-blue-800 dark:text-blue-300">Parallel Migration Started!</h2>
            </div>
            <div class="bg-blue-100 dark:bg-blue-800/30 p-4 rounded-lg mb-4">
                <pre class="text-sm text-blue-800 dark:text-blue-300 font-mono whitespace-pre-wrap"><?= htmlspecialchars(implode("\n", $migrationResults)) ?></pre>
            </div>
            <!-- Progress Tracking -->
            <div id="migration_progress" class="mt-4">
                <div class="flex justify-between items-center mb-2">
                    <span class="text-sm font-medium text-blue-700 dark:text-blue-300">Progress</span>
                    <span id="progress_text" class="text-sm text-blue-600 dark:text-blue-400">Starting...</span>
                </div>
                <div class="w-full bg-blue-200 dark:bg-blue-800 rounded-full h-2">
                    <div id="progress_bar" class="bg-blue-600 dark:bg-blue-400 h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                </div>
            </div>
        </div>

    <?php elseif ($migrationStatus === 'error'): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl p-6 mb-6">
            <div class="flex items-center gap-3 mb-4">
                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400"></i>
                <h2 class="text-lg font-semibold text-red-800 dark:text-red-300">Migration Failed</h2>
            </div>
            <div class="bg-red-100 dark:bg-red-800/30 p-4 rounded-lg">
                <pre class="text-sm text-red-800 dark:text-red-300 font-mono whitespace-pre-wrap"><?= htmlspecialchars(implode("\n", $migrationResults)) ?></pre>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Migration mode handling
document.querySelectorAll('input[name="migration_mode"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const parallelSettings = document.getElementById('parallel_settings');
        const actionInput = document.querySelector('input[name="action"]');
        
        if (this.value === 'parallel') {
            parallelSettings.style.display = 'block';
            actionInput.value = 'parallel_migrate';
        } else {
            parallelSettings.style.display = 'none';
            actionInput.value = 'migrate';
        }
    });
});

// Select all functionality
document.getElementById('select_all')?.addEventListener('change', function() {
    const checkboxes = document.querySelectorAll('.aei-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = this.checked;
    });
});

// Update select all when individual checkboxes change
document.querySelectorAll('.aei-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const allCheckboxes = document.querySelectorAll('.aei-checkbox');
        const checkedCheckboxes = document.querySelectorAll('.aei-checkbox:checked');
        const selectAllCheckbox = document.getElementById('select_all');
        
        if (selectAllCheckbox) {
            selectAllCheckbox.checked = allCheckboxes.length === checkedCheckboxes.length;
        }
    });
});

// Parallel migration progress tracking
<?php if (isset($_SESSION['current_migration_job'])): ?>
let jobId = '<?= $_SESSION['current_migration_job'] ?>';
let progressInterval;

function updateProgress() {
    fetch('/api/migration-progress.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            job_id: jobId,
            csrf_token: '<?= generateCSRFToken() ?>'
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const progressBar = document.getElementById('progress_bar');
            const progressText = document.getElementById('progress_text');
            
            if (progressBar && progressText) {
                const percent = data.progress_total > 0 ? 
                    Math.round((data.progress_current / data.progress_total) * 100) : 0;
                
                progressBar.style.width = percent + '%';
                progressText.textContent = `${data.progress_current}/${data.progress_total} (${percent}%) - ${data.status}`;
                
                // Stop polling if completed
                if (data.status === 'completed' || data.status === 'failed' || data.status === 'completed_with_errors') {
                    clearInterval(progressInterval);
                    
                    // Show completion message
                    setTimeout(() => {
                        location.reload(); // Refresh to show final results
                    }, 2000);
                }
            }
        }
    })
    .catch(error => {
        console.error('Progress tracking error:', error);
        clearInterval(progressInterval);
    });
}

// Start progress tracking if migration is running
if (document.getElementById('migration_progress')) {
    progressInterval = setInterval(updateProgress, 2000); // Check every 2 seconds
    updateProgress(); // Initial check
}
<?php endif; ?>

// Start parallel workers when migration starts
function startParallelWorkers(jobId, parallelJobs, aeiData) {
    const workerPromises = [];
    const chunkedData = chunkArray(aeiData, Math.ceil(aeiData.length / parallelJobs));
    
    chunkedData.forEach((chunk, index) => {
        const workerPromise = fetch('/api/memory-migration-worker.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                job_id: jobId + '_worker_' + index,
                batch_data: chunk,
                csrf_token: '<?= generateCSRFToken() ?>'
            })
        });
        
        workerPromises.push(workerPromise);
    });
    
    return Promise.all(workerPromises);
}

function chunkArray(array, chunkSize) {
    const chunks = [];
    for (let i = 0; i < array.length; i += chunkSize) {
        chunks.push(array.slice(i, i + chunkSize));
    }
    return chunks;
}
</script>