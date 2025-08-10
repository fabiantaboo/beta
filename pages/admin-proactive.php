<?php
requireAdmin();

include_once __DIR__ . '/../includes/proactive_messaging.php';

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
                
            } elseif ($action === 'test_triggers') {
                $aeiId = $_POST['test_aei_id'] ?? '';
                
                if (empty($aeiId)) {
                    $error = "Please select an AEI to test";
                } else {
                    // Get session for this AEI
                    $stmt = $pdo->prepare("
                        SELECT cs.id as session_id, a.user_id 
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
                        $testMessages = $proactiveMessaging->analyzeAndGenerateProactiveMessages($aeiId, $session['session_id'], $session['user_id']);
                        
                        $success = "Generated " . count($testMessages) . " test proactive messages for AEI";
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
?>

<div class="space-y-6">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Proactive Messaging Administration</h1>
        <p class="text-gray-600 dark:text-gray-400">Configure and monitor the proactive messaging system for all AEIs.</p>
    </div>

    <?php if (isset($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h2>
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="cleanup_expired">
                <button type="submit" class="w-full bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg transition-colors">
                    <i class="fas fa-broom mr-2"></i>
                    Cleanup Expired Messages
                </button>
            </form>
            
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="test_triggers">
                <select name="test_aei_id" class="mb-2 w-full px-3 py-1 border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                    <option value="">Select AEI to test</option>
                    <?php foreach ($aeis as $aei): ?>
                        <option value="<?= htmlspecialchars($aei['id']) ?>">
                            <?= htmlspecialchars($aei['user_name'] . ' - ' . $aei['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors">
                    <i class="fas fa-vial mr-2"></i>
                    Test Triggers
                </button>
            </form>
            
            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 text-center">
                <div class="text-2xl font-bold text-ayuni-blue"><?= count($messageStats) ?></div>
                <div class="text-sm text-gray-600 dark:text-gray-400">Active AEIs with Proactive Messages</div>
            </div>
        </div>
    </div>

    <!-- Settings Configuration -->
    <?php if ($selectedAei && $settings): ?>
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Proactive Settings Configuration</h2>
            <select onchange="window.location.href='/admin-proactive?aei=' + this.value" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                <option value="">Select AEI</option>
                <?php foreach ($aeis as $aei): ?>
                    <option value="<?= htmlspecialchars($aei['id']) ?>" <?= $aei['id'] === $selectedAei ? 'selected' : '' ?>>
                        <?= htmlspecialchars($aei['user_name'] . ' - ' . $aei['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Proactive Message Statistics</h2>
        
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
</div>