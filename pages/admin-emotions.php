<?php
requireAdmin();

include_once __DIR__ . '/../includes/emotions.php';

$emotions = new Emotions($pdo);

// Get emotion statistics
try {
    // Total sessions with emotions
    $stmt = $pdo->prepare("SELECT COUNT(*) as sessions_with_emotions FROM chat_sessions WHERE aei_joy IS NOT NULL");
    $stmt->execute();
    $sessionsWithEmotions = $stmt->fetch()['sessions_with_emotions'];
    
    // Total messages with emotions
    $stmt = $pdo->prepare("SELECT COUNT(*) as messages_with_emotions FROM chat_messages WHERE aei_joy IS NOT NULL");
    $stmt->execute();
    $messagesWithEmotions = $stmt->fetch()['messages_with_emotions'];
    
    // Average emotion levels across all sessions (all 18 emotions)
    $stmt = $pdo->prepare("
        SELECT 
            AVG(aei_joy) as avg_joy,
            AVG(aei_sadness) as avg_sadness,
            AVG(aei_fear) as avg_fear,
            AVG(aei_anger) as avg_anger,
            AVG(aei_surprise) as avg_surprise,
            AVG(aei_disgust) as avg_disgust,
            AVG(aei_trust) as avg_trust,
            AVG(aei_anticipation) as avg_anticipation,
            AVG(aei_shame) as avg_shame,
            AVG(aei_love) as avg_love,
            AVG(aei_contempt) as avg_contempt,
            AVG(aei_loneliness) as avg_loneliness,
            AVG(aei_pride) as avg_pride,
            AVG(aei_envy) as avg_envy,
            AVG(aei_nostalgia) as avg_nostalgia,
            AVG(aei_gratitude) as avg_gratitude,
            AVG(aei_frustration) as avg_frustration,
            AVG(aei_boredom) as avg_boredom
        FROM chat_sessions 
        WHERE aei_joy IS NOT NULL
    ");
    $stmt->execute();
    $avgEmotions = $stmt->fetch();
    
    // Recent active sessions with emotional data (all 18 emotions)
    $stmt = $pdo->prepare("
        SELECT 
            cs.id,
            cs.last_message_at,
            a.name as aei_name,
            u.first_name as user_name,
            cs.aei_joy,
            cs.aei_sadness,
            cs.aei_fear,
            cs.aei_anger,
            cs.aei_surprise,
            cs.aei_disgust,
            cs.aei_trust,
            cs.aei_anticipation,
            cs.aei_shame,
            cs.aei_love,
            cs.aei_contempt,
            cs.aei_loneliness,
            cs.aei_pride,
            cs.aei_envy,
            cs.aei_nostalgia,
            cs.aei_gratitude,
            cs.aei_frustration,
            cs.aei_boredom
        FROM chat_sessions cs
        JOIN aeis a ON cs.aei_id = a.id
        JOIN users u ON cs.user_id = u.id
        WHERE cs.aei_joy IS NOT NULL
        ORDER BY cs.last_message_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $recentSessions = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Error getting emotion statistics: " . $e->getMessage());
    $sessionsWithEmotions = $messagesWithEmotions = 0;
    $avgEmotions = [];
    $recentSessions = [];
}

// Handle emotion reset request
if ($_POST && isset($_POST['action']) && $_POST['action'] === 'reset_session_emotions') {
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $sessionId = $_POST['session_id'] ?? '';
        if (!empty($sessionId)) {
            if ($emotions->initializeSessionEmotions($sessionId)) {
                $success = "Emotional state reset successfully for session.";
            } else {
                $error = "Failed to reset emotional state.";
            }
        }
    } else {
        $error = "Invalid CSRF token.";
    }
}
?>

<?php renderAdminNavigation('admin-emotions'); ?>

<div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
    <div class="px-4 py-6 sm:px-0">
        <div class="border-b border-gray-200 dark:border-gray-700 pb-4 mb-6">
            <h1 class="text-3xl font-bold leading-tight text-gray-900 dark:text-white">Emotion Monitoring</h1>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">Monitor and analyze AEI emotional states</p>
        </div>

        <?php if (isset($success)): ?>
            <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-md p-4">
                <div class="flex">
                    <i class="fas fa-check-circle text-green-400 mr-2 mt-0.5"></i>
                    <p class="text-sm text-green-700 dark:text-green-400"><?= htmlspecialchars($success) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
                <div class="flex">
                    <i class="fas fa-exclamation-circle text-red-400 mr-2 mt-0.5"></i>
                    <p class="text-sm text-red-700 dark:text-red-400"><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics Overview -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-brain text-ayuni-blue text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Sessions with Emotions
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= number_format($sessionsWithEmotions) ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-comments text-ayuni-aqua text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Messages with Emotions
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= number_format($messagesWithEmotions) ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-heart text-red-500 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Average Joy Level
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= isset($avgEmotions['avg_joy']) ? number_format($avgEmotions['avg_joy'], 2) : '0.00' ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-handshake text-green-500 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Average Trust Level
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= isset($avgEmotions['avg_trust']) ? number_format($avgEmotions['avg_trust'], 2) : '0.00' ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Emotion Statistics -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-heart text-pink-500 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Average Love Level
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= isset($avgEmotions['avg_love']) ? number_format($avgEmotions['avg_love'], 2) : '0.00' ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Average Fear Level
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= isset($avgEmotions['avg_fear']) ? number_format($avgEmotions['avg_fear'], 2) : '0.00' ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-fist-raised text-red-600 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Average Frustration Level
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= isset($avgEmotions['avg_frustration']) ? number_format($avgEmotions['avg_frustration'], 2) : '0.00' ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow rounded-lg">
                <div class="p-5">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-medal text-purple-500 text-2xl"></i>
                        </div>
                        <div class="ml-5 w-0 flex-1">
                            <dl>
                                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400 truncate">
                                    Average Pride Level
                                </dt>
                                <dd class="text-lg font-medium text-gray-900 dark:text-white">
                                    <?= isset($avgEmotions['avg_pride']) ? number_format($avgEmotions['avg_pride'], 2) : '0.00' ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Average Emotion Levels Chart -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Average Emotion Levels</h2>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <?php 
                    $displayEmotions = [
                        // Grundemotionen (Plutchik)
                        'joy' => ['name' => 'Joy', 'color' => 'bg-yellow-500', 'icon' => 'fa-smile'],
                        'sadness' => ['name' => 'Sadness', 'color' => 'bg-blue-500', 'icon' => 'fa-frown'],
                        'fear' => ['name' => 'Fear', 'color' => 'bg-yellow-600', 'icon' => 'fa-exclamation-triangle'],
                        'anger' => ['name' => 'Anger', 'color' => 'bg-red-500', 'icon' => 'fa-angry'],
                        'surprise' => ['name' => 'Surprise', 'color' => 'bg-cyan-500', 'icon' => 'fa-surprise'],
                        'disgust' => ['name' => 'Disgust', 'color' => 'bg-green-600', 'icon' => 'fa-grimace'],
                        'trust' => ['name' => 'Trust', 'color' => 'bg-green-500', 'icon' => 'fa-handshake'],
                        'anticipation' => ['name' => 'Anticipation', 'color' => 'bg-indigo-500', 'icon' => 'fa-clock'],
                        
                        // Erweiterte Emotionen
                        'shame' => ['name' => 'Shame', 'color' => 'bg-gray-600', 'icon' => 'fa-eye-slash'],
                        'love' => ['name' => 'Love', 'color' => 'bg-pink-500', 'icon' => 'fa-heart'],
                        'contempt' => ['name' => 'Contempt', 'color' => 'bg-purple-600', 'icon' => 'fa-smirk'],
                        'loneliness' => ['name' => 'Loneliness', 'color' => 'bg-gray-500', 'icon' => 'fa-user-times'],
                        'pride' => ['name' => 'Pride', 'color' => 'bg-purple-500', 'icon' => 'fa-medal'],
                        'envy' => ['name' => 'Envy', 'color' => 'bg-emerald-600', 'icon' => 'fa-eye'],
                        'nostalgia' => ['name' => 'Nostalgia', 'color' => 'bg-amber-600', 'icon' => 'fa-history'],
                        'gratitude' => ['name' => 'Gratitude', 'color' => 'bg-orange-500', 'icon' => 'fa-hands'],
                        'frustration' => ['name' => 'Frustration', 'color' => 'bg-red-600', 'icon' => 'fa-fist-raised'],
                        'boredom' => ['name' => 'Boredom', 'color' => 'bg-slate-500', 'icon' => 'fa-yawn']
                    ];
                    
                    foreach ($displayEmotions as $emotion => $config):
                        $value = $avgEmotions["avg_$emotion"] ?? 0;
                        $percentage = $value * 100;
                    ?>
                        <div class="flex items-center space-x-4">
                            <div class="w-20">
                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 flex items-center">
                                    <i class="fas <?= $config['icon'] ?> mr-2"></i>
                                    <?= $config['name'] ?>
                                </span>
                            </div>
                            <div class="flex-1">
                                <div class="bg-gray-200 dark:bg-gray-700 rounded-full h-3">
                                    <div class="<?= $config['color'] ?> h-3 rounded-full transition-all duration-300" 
                                         style="width: <?= $percentage ?>%"></div>
                                </div>
                            </div>
                            <div class="w-12 text-right">
                                <span class="text-sm font-medium text-gray-900 dark:text-white">
                                    <?= number_format($value, 2) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Recent Sessions -->
        <div class="bg-white dark:bg-gray-800 shadow overflow-hidden sm:rounded-md">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-lg font-medium text-gray-900 dark:text-white">Recent Active Sessions</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Session
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Last Active
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Joy
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Love
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Trust
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Sadness
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Anger
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Fear
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($recentSessions)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">
                                    No emotional data available yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recentSessions as $session): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($session['aei_name']) ?>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            with <?= htmlspecialchars($session['user_name']) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= date('M d, Y H:i', strtotime($session['last_message_at'])) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $session['aei_joy'] >= 0.7 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : ($session['aei_joy'] >= 0.4 ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : 'bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                                            <?= number_format($session['aei_joy'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $session['aei_love'] >= 0.7 ? 'bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-200' : ($session['aei_love'] >= 0.4 ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : 'bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                                            <?= number_format($session['aei_love'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $session['aei_trust'] >= 0.7 ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($session['aei_trust'] >= 0.4 ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : 'bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                                            <?= number_format($session['aei_trust'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $session['aei_sadness'] >= 0.7 ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : ($session['aei_sadness'] >= 0.4 ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : 'bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                                            <?= number_format($session['aei_sadness'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $session['aei_anger'] >= 0.7 ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : ($session['aei_anger'] >= 0.4 ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : 'bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                                            <?= number_format($session['aei_anger'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $session['aei_fear'] >= 0.7 ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : ($session['aei_fear'] >= 0.4 ? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300' : 'bg-gray-50 text-gray-600 dark:bg-gray-800 dark:text-gray-400') ?>">
                                            <?= number_format($session['aei_fear'], 2) ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <form method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to reset the emotional state for this session?')">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="action" value="reset_session_emotions">
                                            <input type="hidden" name="session_id" value="<?= htmlspecialchars($session['id']) ?>">
                                            <button type="submit" class="text-ayuni-blue hover:text-ayuni-blue/80">
                                                <i class="fas fa-redo mr-1"></i>Reset
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>