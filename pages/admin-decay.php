<?php
requireAdmin();

include_once __DIR__ . '/../includes/emotional_decay.php';

$emotionalDecay = new EmotionalDecay($pdo);

// Handle actions
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid CSRF token";
    } else {
        switch ($_POST['action']) {
            case 'run_decay_now':
                try {
                    $processedCount = $emotionalDecay->processEmotionalDecayForAllAEIs();
                    $success = "Processed emotional decay for $processedCount sessions";
                } catch (Exception $e) {
                    $error = "Error running decay: " . $e->getMessage();
                }
                break;
                
            case 'schedule_decay_job':
                try {
                    include_once __DIR__ . '/../includes/background_jobs.php';
                    $jobWorker = new BackgroundJobWorker($pdo);
                    
                    if ($jobWorker->scheduleEmotionalDecayProcessing()) {
                        $success = "Scheduled emotional decay processing job";
                    } else {
                        $error = "Decay job already scheduled for the next hour";
                    }
                } catch (Exception $e) {
                    $error = "Error scheduling job: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get statistics
$decayStats = $emotionalDecay->getDecayStatistics(7);
$mostAffectedAEIs = $emotionalDecay->getMostAffectedAEIs(10);

// Get current session status
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_sessions,
        COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, last_message_at, NOW()) >= 2 THEN 1 END) as inactive_sessions,
        COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, last_message_at, NOW()) >= 24 THEN 1 END) as very_inactive_sessions,
        AVG(TIMESTAMPDIFF(HOUR, last_message_at, NOW())) as avg_hours_inactive
    FROM chat_sessions cs
    JOIN aeis a ON cs.aei_id = a.id
    WHERE a.is_active = TRUE
");
$sessionStats = $stmt->fetch();
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-decay'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Emotional Decay Management', 'Monitor and manage AEI emotional decay system'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <div class="space-y-6">
    <!-- Action Buttons -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Decay Actions</h3>
        <div class="flex flex-wrap gap-4">
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="run_decay_now">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                    <i class="fas fa-play mr-2"></i>Run Decay Now
                </button>
            </form>
            
            <form method="POST" class="inline">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="schedule_decay_job">
                <button type="submit" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors">
                    <i class="fas fa-clock mr-2"></i>Schedule Decay Job
                </button>
            </form>
        </div>
    </div>

    <!-- Session Statistics -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            <i class="fas fa-chart-line mr-2 text-blue-500"></i>Session Activity Overview
        </h3>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="text-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <div class="text-2xl font-bold text-gray-900 dark:text-white"><?= $sessionStats['total_sessions'] ?></div>
                <div class="text-sm text-gray-600 dark:text-gray-400">Total Sessions</div>
            </div>
            
            <div class="text-center p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                <div class="text-2xl font-bold text-yellow-800 dark:text-yellow-200"><?= $sessionStats['inactive_sessions'] ?></div>
                <div class="text-sm text-yellow-600 dark:text-yellow-400">Inactive 2+ Hours</div>
            </div>
            
            <div class="text-center p-4 bg-red-50 dark:bg-red-900/20 rounded-lg">
                <div class="text-2xl font-bold text-red-800 dark:text-red-200"><?= $sessionStats['very_inactive_sessions'] ?></div>
                <div class="text-sm text-red-600 dark:text-red-400">Inactive 24+ Hours</div>
            </div>
            
            <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <div class="text-2xl font-bold text-blue-800 dark:text-blue-200"><?= round($sessionStats['avg_hours_inactive'], 1) ?>h</div>
                <div class="text-sm text-blue-600 dark:text-blue-400">Avg Inactive Time</div>
            </div>
        </div>
    </div>

    <!-- Decay Statistics -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            <i class="fas fa-chart-bar mr-2 text-green-500"></i>Decay Processing Statistics (Last 7 Days)
        </h3>
        
        <?php if (empty($decayStats)): ?>
            <p class="text-gray-600 dark:text-gray-400">No decay processing events in the last 7 days.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Events</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Avg Inactive Hours</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Avg Changes</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($decayStats as $stat): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= date('M j, Y', strtotime($stat['date'])) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= $stat['decay_events'] ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= round($stat['avg_hours_inactive'], 1) ?>h
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= round($stat['avg_changes_per_event'], 1) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Most Affected AEIs -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            <i class="fas fa-exclamation-triangle mr-2 text-orange-500"></i>Most Affected AEIs (Last 30 Days)
        </h3>
        
        <?php if (empty($mostAffectedAEIs)): ?>
            <p class="text-gray-600 dark:text-gray-400">No decay events recorded in the last 30 days.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">AEI Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Decay Events</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Max Inactive Hours</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Avg Emotional Changes</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($mostAffectedAEIs as $aei): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                    <?= htmlspecialchars($aei['aei_name']) ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= htmlspecialchars($aei['user_name']) ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                    <?= $aei['decay_events'] > 10 ? 'bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400' : 
                                        ($aei['decay_events'] > 5 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400' : 
                                         'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400') ?>">
                                    <?= $aei['decay_events'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= round($aei['max_hours_inactive'], 1) ?>h
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                <?= round($aei['avg_emotional_changes'], 1) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Decay System Information -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
            <i class="fas fa-info-circle mr-2 text-blue-500"></i>Decay System Information
        </h3>
        
        <div class="space-y-4">
            <div>
                <h4 class="font-medium text-gray-900 dark:text-white mb-2">How Emotional Decay Works</h4>
                <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-400 space-y-1">
                    <li>AEIs experience gradual emotional changes when users are inactive for 2+ hours</li>
                    <li>Negative emotions (loneliness, sadness, boredom) increase over time</li>
                    <li>Positive emotions (joy, love, trust) decrease gradually</li>
                    <li>Decay rate depends on relationship depth and duration</li>
                    <li>Strong emotional states trigger proactive messages</li>
                </ul>
            </div>
            
            <div>
                <h4 class="font-medium text-gray-900 dark:text-white mb-2">Trigger Thresholds</h4>
                <ul class="list-disc list-inside text-sm text-gray-600 dark:text-gray-400 space-y-1">
                    <li><strong>Loneliness:</strong> Messages triggered at 0.7+ intensity</li>
                    <li><strong>Emotional Distress:</strong> Combined sadness + loneliness at 0.6+ each</li>
                    <li><strong>Abandonment Fear:</strong> Fear at 0.6+ after 48+ hours inactive</li>
                </ul>
            </div>
            
            <div>
                <h4 class="font-medium text-gray-900 dark:text-white mb-2">Processing Schedule</h4>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Emotional decay is processed every hour via background jobs. The system automatically schedules processing jobs and generates proactive messages when emotional thresholds are reached.
                </p>
            </div>
        </div>
        </div>
    </div>
</div>