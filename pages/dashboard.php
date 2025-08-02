<?php
requireOnboarding();

$userId = getUserSession();
if (!$userId) {
    redirectTo('home');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $aeis = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Database error fetching AEIs: " . $e->getMessage());
    $aeis = [];
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-10 w-auto">
                    <span class="text-xl font-semibold text-gray-900 dark:text-white">Dashboard</span>
                </div>
                <div class="flex items-center space-x-4">
                    <button 
                        id="theme-toggle" 
                        onclick="toggleTheme()" 
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
                        title="Toggle theme"
                    >
                        <i class="fas fa-sun sun-icon text-lg"></i>
                        <i class="fas fa-moon moon-icon text-lg"></i>
                    </button>
                    <a href="/create-aei" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-2 px-4 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200">
                        <i class="fas fa-plus mr-2"></i>
                        Create AEI
                    </a>
                    <?php if (isAdmin()): ?>
                        <a href="/admin" class="text-orange-600 dark:text-orange-400 hover:text-orange-700 dark:hover:text-orange-300 font-medium transition-colors border border-orange-300 dark:border-orange-600 px-3 py-2 rounded-lg hover:bg-orange-50 dark:hover:bg-orange-900/20">
                            <i class="fas fa-cog mr-2"></i>
                            Admin Panel
                        </a>
                    <?php endif; ?>
                    <a href="/profile" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors">
                        <i class="fas fa-user mr-2"></i>
                        Profile
                    </a>
                    <a href="/logout" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors">
                        <i class="fas fa-sign-out-alt mr-2"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if (empty($aeis)): ?>
            <div class="text-center py-16">
                <div class="mb-8">
                    <div class="w-24 h-24 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-6 flex items-center justify-center">
                        <i class="fas fa-robot text-3xl text-white"></i>
                    </div>
                    <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-4">Create Your First AEI</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-lg max-w-md mx-auto">
                        Start your journey by creating your first Artificial Emotional Intelligence companion
                    </p>
                </div>
                <a href="/create-aei" class="inline-flex items-center bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl">
                    <i class="fas fa-plus mr-2"></i>
                    Create Your First AEI
                </a>
            </div>
        <?php else: ?>
            <div class="mb-8">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Your AEIs</h2>
                <p class="text-gray-600 dark:text-gray-400">Your digital companions are ready to chat</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($aeis as $aei): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md hover:border-ayuni-blue/50 dark:hover:border-ayuni-aqua/50 transition-all duration-300">
                        <div class="flex items-center mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-4">
                                <span class="text-xl text-white font-bold">
                                    <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                                </span>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Created <?= date('M j, Y', strtotime($aei['created_at'])) ?></p>
                            </div>
                        </div>
                        
                        <?php if ($aei['personality']): ?>
                            <p class="text-gray-600 dark:text-gray-300 mb-4 line-clamp-3"><?= htmlspecialchars(substr($aei['personality'], 0, 120)) ?>...</p>
                        <?php endif; ?>
                        
                        <a href="/chat/<?= urlencode($aei['id']) ?>" class="block w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-2 px-4 rounded-lg text-center hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md">
                            <i class="fas fa-comments mr-2"></i>
                            Start Conversation
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>