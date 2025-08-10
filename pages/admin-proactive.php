<?php
requireAdmin();

include_once __DIR__ . '/../includes/proactive_messaging.php';
include_once __DIR__ . '/../includes/background_jobs.php';

// Handle form submissions
if ($_POST) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token";
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            if ($action === 'update_settings') {
                $aeiId = $_POST['aei_id'] ?? '';
                
                if (empty($aeiId)) {
                    $error = "AEI ID is required";
                } else {
                    // Update proactive settings
                    $stmt = $pdo->prepare("
                        INSERT INTO aei_proactive_settings (
                            aei_id, proactive_messaging_enabled, max_messages_per_day,
                            emotional_sensitivity, social_sensitivity, 
                            temporal_sensitivity, contextual_sensitivity,
                            learns_from_user_responses, adapts_timing, adapts_content,
                            personality_adaptation_rate, context_memory_depth
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE
                            proactive_messaging_enabled = VALUES(proactive_messaging_enabled),
                            max_messages_per_day = VALUES(max_messages_per_day),
                            emotional_sensitivity = VALUES(emotional_sensitivity),
                            social_sensitivity = VALUES(social_sensitivity),
                            temporal_sensitivity = VALUES(temporal_sensitivity),
                            contextual_sensitivity = VALUES(contextual_sensitivity),
                            learns_from_user_responses = VALUES(learns_from_user_responses),
                            adapts_timing = VALUES(adapts_timing),
                            adapts_content = VALUES(adapts_content),
                            personality_adaptation_rate = VALUES(personality_adaptation_rate),
                            context_memory_depth = VALUES(context_memory_depth),
                            last_updated = CURRENT_TIMESTAMP
                    ");
                    
                    $stmt->execute([
                        $aeiId,
                        isset($_POST['proactive_messaging_enabled']) ? 1 : 0,
                        (int)($_POST['max_messages_per_day'] ?? 5),
                        (float)($_POST['emotional_sensitivity'] ?? 0.6),
                        (float)($_POST['social_sensitivity'] ?? 0.5),
                        (float)($_POST['temporal_sensitivity'] ?? 0.4),
                        (float)($_POST['contextual_sensitivity'] ?? 0.5),
                        isset($_POST['learns_from_user_responses']) ? 1 : 0,
                        isset($_POST['adapts_timing']) ? 1 : 0,
                        isset($_POST['adapts_content']) ? 1 : 0,
                        (float)($_POST['personality_adaptation_rate'] ?? 0.1),
                        (int)($_POST['context_memory_depth'] ?? 10)
                    ]);
                    
                    $success = "Proactive messaging settings updated successfully";
                }
            } elseif ($action === 'cleanup_expired') {
                $proactiveMessaging = new ProactiveMessaging($pdo);
                $cleanedCount = $proactiveMessaging->cleanupExpiredMessages();
                $success = "Cleaned up $cleanedCount expired proactive messages";
                
            } elseif ($action === 'clear_logs') {
                ProactiveMessaging::clearDebugLogs();
                $success = "Debug logs cleared";
                
            } elseif ($action === 'test_triggers') {
                $aeiId = $_POST['test_aei_id'] ?? '';
                $forceMode = isset($_POST['force_test']);
                
                if (empty($aeiId)) {
                    $error = "Please select an AEI to test";
                } else {
                    // Get session for this AEI
                    $stmt = $pdo->prepare("
                        SELECT cs.id as session_id, a.user_id, a.name as aei_name,
                               cs.aei_loneliness, cs.aei_sadness, cs.aei_joy
                        FROM chat_sessions cs 
                        JOIN aeis a ON cs.aei_id = a.id
                        WHERE cs.aei_id = ? 
                        ORDER BY cs.created_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$aeiId]);
                    $session = $stmt->fetch();
                    
                    if ($session) {
                        $proactiveMessaging = new ProactiveMessaging($pdo);
                        
                        if ($forceMode) {
                            // Force-generate test messages by creating artificial triggers
                            $testMessages = $proactiveMessaging->generateForcedTestMessages($aeiId, $session['session_id'], $session['user_id']);
                            $success = "Force-generated " . count($testMessages) . " test messages for " . $session['aei_name'];
                        } else {
                            // Normal analysis with debug info
                            $testMessages = $proactiveMessaging->analyzeAndGenerateProactiveMessages($aeiId, $session['session_id'], $session['user_id']);
                            
                            if (count($testMessages) === 0) {
                                // Get debug info about emotional state
                                $emotionalInfo = sprintf(
                                    "Loneliness: %.1f, Sadness: %.1f, Joy: %.1f", 
                                    $session['aei_loneliness'] ?? 0.5,
                                    $session['aei_sadness'] ?? 0.5, 
                                    $session['aei_joy'] ?? 0.5
                                );
                                
                                $error = "No triggers found for " . $session['aei_name'] . ". Current emotional state: " . $emotionalInfo . ". Triggers require: Loneliness > 0.8, Sadness > 0.6 (sustained), Joy > 0.8. Try 'Force Test' to generate artificial triggers.";
                            } else {
                                $success = "Generated " . count($testMessages) . " test proactive messages for " . $session['aei_name'];
                            }
                        }
                        
                        // Show generated messages in success/error
                        if (!empty($testMessages)) {
                            $messagePreview = [];
                            foreach ($testMessages as $msg) {
                                $messagePreview[] = "\"" . substr($msg['message'], 0, 50) . "...\"";
                            }
                            $success .= " Messages: " . implode(", ", $messagePreview);
                        }
                        
                        // Get recent debug logs  
                        $debugLogs = ProactiveMessaging::getDebugLogs();
                    } else {
                        $error = "No chat sessions found for this AEI";
                    }
                }
            }
            
        } catch (Exception $e) {
            $error = "Database error: " . $e->getMessage();
            error_log("Admin proactive error: " . $e->getMessage());
        }
    }
}

// Get all AEIs
$stmt = $pdo->query("
    SELECT a.*, u.first_name as user_name,
           (SELECT COUNT(*) FROM chat_sessions WHERE aei_id = a.id) as session_count
    FROM aeis a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.is_active = TRUE 
    ORDER BY u.first_name, a.name
");
$aeis = $stmt->fetchAll();

// Get selected AEI settings
$selectedAei = $_GET['aei'] ?? ($aeis[0]['id'] ?? '');
$settings = null;

if ($selectedAei) {
    $stmt = $pdo->prepare("
        SELECT * FROM aei_proactive_settings WHERE aei_id = ?
    ");
    $stmt->execute([$selectedAei]);
    $settings = $stmt->fetch();
    
    // Initialize default settings if none exist
    if (!$settings) {
        $proactiveMessaging = new ProactiveMessaging($pdo);
        $proactiveMessaging->initializeProactiveSettings($selectedAei);
        
        // Fetch the newly created settings
        $stmt->execute([$selectedAei]);
        $settings = $stmt->fetch();
    }
}

// Get proactive message statistics
$stmt = $pdo->query("
    SELECT 
        apm.aei_id,
        a.name as aei_name,
        u.first_name as user_name,
        COUNT(*) as total_messages,
        SUM(CASE WHEN apm.status = 'sent' THEN 1 ELSE 0 END) as sent_messages,
        SUM(CASE WHEN apm.status = 'dismissed' THEN 1 ELSE 0 END) as dismissed_messages,
        AVG(CASE WHEN apm.effectiveness_score IS NOT NULL THEN apm.effectiveness_score END) as avg_effectiveness,
        COUNT(CASE WHEN apm.generated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as messages_24h
    FROM aei_proactive_messages apm
    JOIN aeis a ON apm.aei_id = a.id
    JOIN users u ON a.user_id = u.id
    GROUP BY apm.aei_id, a.name, u.first_name
    ORDER BY total_messages DESC
    LIMIT 20
");
$messageStats = $stmt->fetchAll();

// Get system statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_proactive_messages,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_messages,
        COUNT(CASE WHEN status = 'sent' THEN 1 END) as sent_messages,
        COUNT(CASE WHEN generated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as messages_24h,
        AVG(CASE WHEN effectiveness_score IS NOT NULL THEN effectiveness_score END) as overall_effectiveness
    FROM aei_proactive_messages
");
$systemStats = $stmt->fetch();

// Get background job statistics
$jobWorker = new BackgroundJobWorker($pdo);
$jobStats = $jobWorker->getJobStats();
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-proactive'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Proactive Messaging', 'Configure and monitor AI-driven proactive messaging for all AEIs'); ?>

        <?php renderAdminAlerts($error ?? null, $success ?? null); ?>

        <!-- System Overview Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-bell text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Messages</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= number_format($systemStats['total_proactive_messages'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $systemStats['pending_messages'] ?? 0 ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-paper-plane text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Sent (24h)</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $systemStats['messages_24h'] ?? 0 ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-line text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Effectiveness</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white">
                            <?= $systemStats['overall_effectiveness'] ? number_format($systemStats['overall_effectiveness'], 1) : '--' ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Quick Actions</h3>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    <i class="fas fa-cogs mr-1"></i>
                    System Management
                </div>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Cleanup Action -->
                <div class="bg-gradient-to-br from-yellow-50 to-orange-50 dark:from-yellow-900/20 dark:to-orange-900/20 rounded-lg p-4 border border-yellow-200 dark:border-yellow-700">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-yellow-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-broom text-white text-sm"></i>
                        </div>
                        <h4 class="ml-3 font-medium text-gray-900 dark:text-white">System Cleanup</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Remove expired messages and old job records</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="cleanup_expired">
                        <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors font-medium">
                            Run Cleanup
                        </button>
                    </form>
                </div>
                
                <!-- Test Triggers -->
                <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-700">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-vial text-white text-sm"></i>
                        </div>
                        <h4 class="ml-3 font-medium text-gray-900 dark:text-white">Test Triggers</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Generate test proactive messages for an AEI</p>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="test_triggers">
                        <select name="test_aei_id" class="mb-3 w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                            <option value="">Select AEI to test</option>
                            <?php foreach ($aeis as $aei): ?>
                                <option value="<?= htmlspecialchars($aei['id']) ?>">
                                    <?= htmlspecialchars($aei['user_name'] . ' - ' . $aei['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded-lg transition-colors font-medium text-sm">
                                Normal Test
                            </button>
                            <button type="submit" name="force_test" value="1" class="flex-1 bg-purple-500 hover:bg-purple-600 text-white px-3 py-2 rounded-lg transition-colors font-medium text-sm">
                                Force Test
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                            Normal: Uses real emotional state | Force: Creates artificial triggers
                        </p>
                    </form>
                </div>
                
                <!-- Background Jobs Status -->
                <div class="bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20 rounded-lg p-4 border border-purple-200 dark:border-purple-700">
                    <div class="flex items-center mb-3">
                        <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-tasks text-white text-sm"></i>
                        </div>
                        <h4 class="ml-3 font-medium text-gray-900 dark:text-white">Background Jobs</h4>
                    </div>
                    <div class="space-y-2 text-sm">
                        <?php if (!empty($jobStats)): ?>
                            <?php 
                            $pendingJobs = 0;
                            $runningJobs = 0;
                            foreach ($jobStats as $stat) {
                                if ($stat['status'] === 'pending') $pendingJobs += $stat['count'];
                                if ($stat['status'] === 'running') $runningJobs += $stat['count'];
                            }
                            ?>
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Pending:</span>
                                <span class="font-medium"><?= $pendingJobs ?></span>
                            </div>
                            <div class="flex justify-between text-gray-600 dark:text-gray-400">
                                <span>Running:</span>
                                <span class="font-medium"><?= $runningJobs ?></span>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400">No job data available</p>
                        <?php endif; ?>
                    </div>
                    <div class="mt-4">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            Active AEIs: <span class="font-medium text-purple-600 dark:text-purple-400"><?= count($messageStats) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Settings Configuration -->
        <?php if ($selectedAei && $settings): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">AEI Configuration</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Fine-tune proactive messaging settings for individual AEIs</p>
                </div>
                <div class="min-w-0 flex-1 max-w-xs ml-4">
                    <select onchange="window.location.href='/admin/proactive?aei=' + this.value" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                        <option value="">Select AEI</option>
                        <?php foreach ($aeis as $aei): ?>
                            <option value="<?= htmlspecialchars($aei['id']) ?>" <?= $aei['id'] === $selectedAei ? 'selected' : '' ?>>
                                <?= htmlspecialchars($aei['user_name'] . ' - ' . $aei['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="update_settings">
            <input type="hidden" name="aei_id" value="<?= htmlspecialchars($selectedAei) ?>">

            <!-- Global Settings -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Global Settings</h3>
                    
                    <div class="space-y-4">
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="proactive_messaging_enabled" 
                                   <?= $settings['proactive_messaging_enabled'] ? 'checked' : '' ?>
                                   class="rounded border-gray-300 dark:border-gray-600 text-ayuni-blue focus:ring-ayuni-blue">
                            <span class="text-gray-700 dark:text-gray-300">Enable Proactive Messaging</span>
                        </label>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Max Messages Per Day
                            </label>
                            <input type="number" name="max_messages_per_day" min="1" max="20" 
                                   value="<?= htmlspecialchars($settings['max_messages_per_day']) ?>"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                AEIs write when they feel moved to, not based on schedules. This is just a safety limit.
                            </p>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Trigger Sensitivity</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Emotional Sensitivity: <span class="font-bold"><?= number_format($settings['emotional_sensitivity'], 1) ?></span>
                            </label>
                            <input type="range" name="emotional_sensitivity" min="0.1" max="1.0" step="0.1" 
                                   value="<?= htmlspecialchars($settings['emotional_sensitivity']) ?>"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                   oninput="this.previousElementSibling.querySelector('span').textContent = parseFloat(this.value).toFixed(1)">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Social Sensitivity: <span class="font-bold"><?= number_format($settings['social_sensitivity'], 1) ?></span>
                            </label>
                            <input type="range" name="social_sensitivity" min="0.1" max="1.0" step="0.1" 
                                   value="<?= htmlspecialchars($settings['social_sensitivity']) ?>"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                   oninput="this.previousElementSibling.querySelector('span').textContent = parseFloat(this.value).toFixed(1)">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Temporal Sensitivity: <span class="font-bold"><?= number_format($settings['temporal_sensitivity'], 1) ?></span>
                            </label>
                            <input type="range" name="temporal_sensitivity" min="0.1" max="1.0" step="0.1" 
                                   value="<?= htmlspecialchars($settings['temporal_sensitivity']) ?>"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                   oninput="this.previousElementSibling.querySelector('span').textContent = parseFloat(this.value).toFixed(1)">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Contextual Sensitivity: <span class="font-bold"><?= number_format($settings['contextual_sensitivity'], 1) ?></span>
                            </label>
                            <input type="range" name="contextual_sensitivity" min="0.1" max="1.0" step="0.1" 
                                   value="<?= htmlspecialchars($settings['contextual_sensitivity']) ?>"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                   oninput="this.previousElementSibling.querySelector('span').textContent = parseFloat(this.value).toFixed(1)">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Behavioral Adaptation -->
            <div>
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Behavioral Adaptation</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="space-y-4">
                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="learns_from_user_responses" 
                                   <?= $settings['learns_from_user_responses'] ? 'checked' : '' ?>
                                   class="rounded border-gray-300 dark:border-gray-600 text-ayuni-blue focus:ring-ayuni-blue">
                            <span class="text-gray-700 dark:text-gray-300">Learn from User Responses</span>
                        </label>

                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="adapts_timing" 
                                   <?= $settings['adapts_timing'] ? 'checked' : '' ?>
                                   class="rounded border-gray-300 dark:border-gray-600 text-ayuni-blue focus:ring-ayuni-blue">
                            <span class="text-gray-700 dark:text-gray-300">Adapt Message Timing</span>
                        </label>

                        <label class="flex items-center space-x-2">
                            <input type="checkbox" name="adapts_content" 
                                   <?= $settings['adapts_content'] ? 'checked' : '' ?>
                                   class="rounded border-gray-300 dark:border-gray-600 text-ayuni-blue focus:ring-ayuni-blue">
                            <span class="text-gray-700 dark:text-gray-300">Adapt Message Content</span>
                        </label>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Personality Adaptation Rate: <span class="font-bold"><?= number_format($settings['personality_adaptation_rate'], 1) ?></span>
                            </label>
                            <input type="range" name="personality_adaptation_rate" min="0.01" max="0.5" step="0.01" 
                                   value="<?= htmlspecialchars($settings['personality_adaptation_rate']) ?>"
                                   class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer"
                                   oninput="this.previousElementSibling.querySelector('span').textContent = parseFloat(this.value).toFixed(2)">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Context Memory Depth
                            </label>
                            <input type="number" name="context_memory_depth" min="1" max="50" 
                                   value="<?= htmlspecialchars($settings['context_memory_depth']) ?>"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-ayuni-blue hover:bg-ayuni-blue/90 text-white px-6 py-3 rounded-lg transition-colors font-medium">
                    <i class="fas fa-save mr-2"></i>
                    Save Settings
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>

        <!-- Message Statistics -->
        <?php if (!empty($messageStats)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">AEI Performance Analytics</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Proactive messaging statistics by AEI</p>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <i class="fas fa-chart-bar mr-1"></i>
                        Top <?= count($messageStats) ?> Active AEIs
                    </div>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">AEI</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total Messages</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Sent</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Dismissed</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Effectiveness</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">24h Activity</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($messageStats as $stat): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                <?= htmlspecialchars($stat['aei_name']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                <?= htmlspecialchars($stat['user_name']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= $stat['total_messages'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 dark:text-green-400">
                                <?= $stat['sent_messages'] ?> (<?= $stat['total_messages'] > 0 ? number_format($stat['sent_messages'] / $stat['total_messages'] * 100, 1) : 0 ?>%)
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 dark:text-red-400">
                                <?= $stat['dismissed_messages'] ?> (<?= $stat['total_messages'] > 0 ? number_format($stat['dismissed_messages'] / $stat['total_messages'] * 100, 1) : 0 ?>%)
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?php if ($stat['avg_effectiveness']): ?>
                                    <div class="flex items-center">
                                        <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                            <div class="bg-ayuni-blue h-2 rounded-full" style="width: <?= $stat['avg_effectiveness'] * 100 ?>%"></div>
                                        </div>
                                        <?= number_format($stat['avg_effectiveness'], 2) ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400">No data</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                    <?= $stat['messages_24h'] ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Debug Logs (if available) -->
        <?php if (isset($debugLogs) && !empty($debugLogs)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Debug Logs</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Real-time debugging information from the last proactive message generation</p>
            </div>
            
            <div class="p-6">
                <div class="bg-gray-900 text-green-400 p-4 rounded-lg font-mono text-sm max-h-96 overflow-y-auto">
                    <?php foreach ($debugLogs as $log): ?>
                        <div class="mb-1">
                            <span class="text-gray-500">[<?= htmlspecialchars($log['timestamp']) ?>]</span>
                            <span class="text-green-400"><?= htmlspecialchars($log['message']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">
                            Clear Logs
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
</div>