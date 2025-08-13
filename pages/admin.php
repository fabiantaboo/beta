<?php
requireAdmin();

// Get user statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $totalUsers = $stmt->fetch()['total_users'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_codes FROM beta_codes WHERE is_active = TRUE AND used_at IS NULL");
    $stmt->execute();
    $activeCodes = $stmt->fetch()['active_codes'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as used_codes FROM beta_codes WHERE used_at IS NOT NULL");
    $stmt->execute();
    $usedCodes = $stmt->fetch()['used_codes'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_aeis FROM aeis WHERE is_active = TRUE");
    $stmt->execute();
    $totalAeis = $stmt->fetch()['total_aeis'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_sessions FROM chat_sessions");
    $stmt->execute();
    $totalSessions = $stmt->fetch()['total_sessions'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_messages FROM chat_messages");
    $stmt->execute();
    $totalMessages = $stmt->fetch()['total_messages'];
} catch (PDOException $e) {
    $totalUsers = $activeCodes = $usedCodes = $totalAeis = $totalSessions = $totalMessages = 0;
}

// Get recent users
try {
    $stmt = $pdo->prepare("SELECT first_name, email, created_at, is_onboarded FROM users ORDER BY created_at DESC LIMIT 5");
    $stmt->execute();
    $recentUsers = $stmt->fetchAll();
} catch (PDOException $e) {
    $recentUsers = [];
}

// Check API configuration
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'anthropic_api_key'");
    $stmt->execute();
    $apiKeyResult = $stmt->fetch();
    $hasApiKey = $apiKeyResult && !empty($apiKeyResult['setting_value']);
} catch (PDOException $e) {
    $hasApiKey = false;
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Admin Overview', 'System statistics and quick links'); ?>

        <!-- Quick Stats -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Users</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $totalUsers ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-robot text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active AEIs</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $totalAeis ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-comments text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Messages</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= number_format($totalMessages) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-ticket-alt text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Beta Codes</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $activeCodes ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-orange-500 to-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Used Beta Codes</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $usedCodes ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r <?= $hasApiKey ? 'from-green-500 to-green-600' : 'from-red-500 to-red-600' ?> rounded-lg flex items-center justify-center">
                        <i class="fas fa-key text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">API Status</p>
                        <p class="text-lg font-bold <?= $hasApiKey ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' ?>">
                            <?= $hasApiKey ? 'Configured' : 'Not Set' ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h3>
                <div class="grid grid-cols-2 gap-4">
                    <a href="/admin/api" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-key text-ayuni-blue mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">API Settings</span>
                    </a>
                    <a href="/admin/prompts" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-code text-ayuni-aqua mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">System Prompts</span>
                    </a>
                    <a href="/admin/beta" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-ticket-alt text-purple-500 mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">Generate Beta Code</span>
                    </a>
                    <a href="/admin/users" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-users text-green-500 mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">Manage Users</span>
                    </a>
                </div>
                <div class="grid grid-cols-2 gap-4 mt-4">
                    <a href="/admin/emotions" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-heart text-red-500 mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">Emotion Monitor</span>
                    </a>
                    <a href="/admin/social" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-network-wired text-orange-500 mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">Social System</span>
                    </a>
                    <a href="/admin/feedback" class="flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors">
                        <i class="fas fa-comment-dots text-blue-500 mr-3"></i>
                        <span class="text-sm font-medium text-gray-900 dark:text-white">User Feedback</span>
                    </a>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Recent Users</h3>
                <div class="space-y-3">
                    <?php if (empty($recentUsers)): ?>
                        <p class="text-gray-500 dark:text-gray-400 text-sm">No users yet</p>
                    <?php else: ?>
                        <?php foreach ($recentUsers as $user): ?>
                            <div class="flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($user['first_name']) ?></p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($user['email']) ?></p>
                                </div>
                                <div class="text-right">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?= $user['is_onboarded'] ? 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400' : 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400' ?>">
                                        <?= $user['is_onboarded'] ? 'Complete' : 'Pending' ?>
                                    </span>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"><?= date('M j', strtotime($user['created_at'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>