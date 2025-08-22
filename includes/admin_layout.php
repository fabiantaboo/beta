<?php
function renderAdminNavigation($currentPage = '') {
    $navCategories = [
        'dashboard' => [
            'name' => 'Dashboard',
            'icon' => 'fas fa-chart-line',
            'items' => [
                'admin' => ['name' => 'Overview', 'icon' => 'fas fa-chart-line', 'url' => '/admin']
            ]
        ],
        'system' => [
            'name' => 'System',
            'icon' => 'fas fa-cogs',
            'items' => [
                'admin-api' => ['name' => 'API Settings', 'icon' => 'fas fa-key', 'url' => '/admin/api'],
                'admin-prompts' => ['name' => 'System Prompts', 'icon' => 'fas fa-code', 'url' => '/admin/prompts'],
                'admin-logs' => ['name' => 'Error Logs', 'icon' => 'fas fa-file-alt', 'url' => '/admin/logs']
            ]
        ],
        'users' => [
            'name' => 'Users & Analytics',
            'icon' => 'fas fa-users',
            'items' => [
                'admin-users' => ['name' => 'User Management', 'icon' => 'fas fa-users', 'url' => '/admin/users'],
                'admin-chats' => ['name' => 'Chat Analytics', 'icon' => 'fas fa-chart-bar', 'url' => '/admin/chats'],
                'admin-beta' => ['name' => 'Beta Codes', 'icon' => 'fas fa-ticket-alt', 'url' => '/admin/beta'],
                'admin-feedback' => ['name' => 'User Feedback', 'icon' => 'fas fa-comment-dots', 'url' => '/admin/feedback']
            ]
        ],
        'ai' => [
            'name' => 'AI Features',
            'icon' => 'fas fa-brain',
            'items' => [
                'admin-emotions' => ['name' => 'Emotions', 'icon' => 'fas fa-heart', 'url' => '/admin/emotions'],
                'admin-social' => ['name' => 'Social System', 'icon' => 'fas fa-users-cog', 'url' => '/admin/social'],
                'admin-proactive' => ['name' => 'Proactive Messaging', 'icon' => 'fas fa-bell', 'url' => '/admin/proactive'],
                'admin-decay' => ['name' => 'Emotional Decay', 'icon' => 'fas fa-heart-broken', 'url' => '/admin/decay'],
                'memory-setup' => ['name' => 'Memory Setup', 'icon' => 'fas fa-database', 'url' => '/admin/memory-setup']
            ]
        ],
        'external' => [
            'name' => 'External Services',
            'icon' => 'fas fa-cloud',
            'items' => [
                'admin-replicate' => ['name' => 'Replicate AI', 'icon' => 'fas fa-robot', 'url' => '/admin/replicate'],
                'admin-avatar-regenerate' => ['name' => 'Avatar Regenerate', 'icon' => 'fas fa-redo-alt', 'url' => '/admin/avatar-regenerate'],
                'admin-avatar-batch' => ['name' => 'Avatar Batch', 'icon' => 'fas fa-images', 'url' => '/admin/avatar-batch']
            ]
        ],
        'monitoring' => [
            'name' => 'Logs & Monitoring',
            'icon' => 'fas fa-chart-area',
            'items' => [
                'admin-api-logs' => ['name' => 'API Request Logs', 'icon' => 'fas fa-list-alt', 'url' => '/admin/api-logs']
            ]
        ]
    ];
    
    ?>
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-12 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-12 w-auto hidden dark:block">
                    </div>
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Admin Panel</span>
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
                    <a href="/dashboard" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Admin Sub-Navigation -->
    <div class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-1 overflow-x-auto py-2">
                <?php 
                // Determine which category the current page belongs to
                $currentCategory = '';
                foreach ($navCategories as $categoryKey => $category) {
                    foreach ($category['items'] as $pageKey => $item) {
                        if ($currentPage === $pageKey) {
                            $currentCategory = $categoryKey;
                            break 2;
                        }
                    }
                }
                
                foreach ($navCategories as $categoryKey => $category): 
                    $isActiveCategory = $currentCategory === $categoryKey;
                ?>
                    <div class="relative group">
                        <button class="flex items-center space-x-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors whitespace-nowrap <?= $isActiveCategory ? 'bg-ayuni-blue text-white' : 'text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white hover:bg-gray-200 dark:hover:bg-gray-700' ?>">
                            <i class="<?= htmlspecialchars($category['icon']) ?>"></i>
                            <span><?= htmlspecialchars($category['name']) ?></span>
                            <i class="fas fa-chevron-down text-xs ml-1 transition-transform group-hover:rotate-180"></i>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div class="absolute left-0 top-full mt-1 w-64 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                            <div class="py-2">
                                <?php foreach ($category['items'] as $pageKey => $item): ?>
                                    <a href="<?= htmlspecialchars($item['url']) ?>" 
                                       class="flex items-center space-x-3 px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 hover:text-ayuni-blue transition-colors <?= $currentPage === $pageKey ? 'bg-ayuni-blue/10 text-ayuni-blue border-r-2 border-ayuni-blue' : '' ?>">
                                        <i class="<?= htmlspecialchars($item['icon']) ?> w-4"></i>
                                        <span><?= htmlspecialchars($item['name']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php
}

function renderAdminPageHeader($title, $subtitle = '') {
    ?>
    <div class="mb-8">
        <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-2"><?= htmlspecialchars($title) ?></h2>
        <?php if ($subtitle): ?>
            <p class="text-gray-600 dark:text-gray-400"><?= htmlspecialchars($subtitle) ?></p>
        <?php endif; ?>
    </div>
    <?php
}

function renderAdminAlerts($error = null, $success = null) {
    if ($error): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        </div>
    <?php endif;
    
    if ($success): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        </div>
    <?php endif;
}
?>