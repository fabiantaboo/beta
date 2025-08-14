<?php
/**
 * Renders the main navigation header for user pages
 */
function renderHeader($config = []) {
    // Default configuration
    $defaults = [
        'title' => '',
        'show_back_button' => false,
        'back_url' => '/dashboard',
        'back_text' => 'Back to Dashboard',
        'show_theme_toggle' => true,
        'show_user_menu' => true,
        'show_create_aei' => false,
        'show_admin_link' => true,
        'extra_content' => null,
        'container_class' => 'max-w-7xl',
        'height' => 'h-16'
    ];
    
    $config = array_merge($defaults, $config);
    ?>
    
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 <?= $config['height'] === 'h-16' ? 'flex-shrink-0' : '' ?>">
        <div class="<?= $config['container_class'] ?> mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center <?= $config['height'] ?>">
                <!-- Left Side -->
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <?php if ($config['show_back_button']): ?>
                        <a href="<?= $config['back_url'] ?>" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue transition-colors p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" title="Back to Dashboard">
                            <i class="fas fa-arrow-left text-lg"></i>
                        </a>
                    <?php endif; ?>
                    
                    <div class="relative">
                        <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-12 sm:h-14 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-12 sm:h-14 w-auto hidden dark:block">
                    </div>
                </div>

                <!-- Right Side -->
                <div class="flex items-center space-x-2 sm:space-x-4">
                    <!-- Extra Content (e.g., AEI info in chat) -->
                    <?php if ($config['extra_content']): ?>
                        <?= $config['extra_content'] ?>
                    <?php endif; ?>
                    
                    <?php if ($config['show_theme_toggle']): ?>
                        <button 
                            id="theme-toggle" 
                            onclick="toggleTheme()" 
                            class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
                            title="Toggle theme"
                        >
                            <i class="fas fa-sun sun-icon text-lg"></i>
                            <i class="fas fa-moon moon-icon text-lg"></i>
                        </button>
                    <?php endif; ?>
                    
                    <?php if ($config['show_create_aei']): ?>
                        <a href="/create-aei" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-2 px-3 sm:px-4 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200">
                            <i class="fas fa-plus sm:mr-2"></i>
                            <span class="hidden sm:inline">Create AEI</span>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($config['show_admin_link'] && isAdmin()): ?>
                        <a href="/admin" class="text-orange-600 dark:text-orange-400 hover:text-orange-700 dark:hover:text-orange-300 font-medium transition-colors border border-orange-300 dark:border-orange-600 px-2 sm:px-3 py-2 rounded-lg hover:bg-orange-50 dark:hover:bg-orange-900/20">
                            <i class="fas fa-cog sm:mr-2"></i>
                            <span class="hidden sm:inline">Admin Panel</span>
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($config['show_user_menu']): ?>
                        <a href="/profile" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors hidden sm:flex items-center">
                            <i class="fas fa-user mr-2"></i>
                            Profile
                        </a>
                        
                        <a href="/logout" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors flex items-center">
                            <i class="fas fa-sign-out-alt sm:mr-2"></i>
                            <span class="hidden sm:inline">Logout</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>
    
    <?php
}

/**
 * Renders a simple header for specific pages like chat
 */
function renderChatHeader($aei, $isCurrentUserAdmin = false, $formattedEmotions = []) {
    ?>
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
        <div class="w-full px-4 sm:px-6 lg:px-8">
            <div class="flex items-center h-16">
                <!-- Left side with chat container alignment -->
                <div class="flex-1 flex justify-center">
                    <div class="max-w-4xl w-full flex items-center px-4">
                        <!-- Back Button + AEI Info -->
                        <div class="flex items-center space-x-4">
                            <a href="/dashboard" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue transition-colors p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" title="Back to Dashboard">
                                <i class="fas fa-arrow-left text-lg"></i>
                            </a>
                            
                            <div class="flex items-center space-x-3 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg p-2 -m-2 transition-colors" onclick="openAEIInfoModal()">
                        <!-- AEI Avatar with emotion indicator -->
                        <div class="relative">
                            <div class="w-10 h-10 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center">
                                <span class="text-lg text-white font-bold"><?= strtoupper(substr($aei['name'], 0, 1)) ?></span>
                            </div>
                            
                            <!-- Emotion indicator for admins -->
                            <?php if ($isCurrentUserAdmin && !empty($formattedEmotions['strong'])): 
                                $primaryEmotion = explode(':', $formattedEmotions['strong'][0])[0];
                                $emotionIcon = match($primaryEmotion) {
                                    'joy' => 'fa-smile',
                                    'love' => 'fa-heart',
                                    'trust' => 'fa-handshake',
                                    'sadness' => 'fa-frown',
                                    'anger' => 'fa-angry',
                                    'fear' => 'fa-exclamation-triangle',
                                    'surprise' => 'fa-surprise',
                                    'disgust' => 'fa-grimace',
                                    'anticipation' => 'fa-clock',
                                    'shame' => 'fa-eye-slash',
                                    'contempt' => 'fa-smirk',
                                    'loneliness' => 'fa-user-times',
                                    'pride' => 'fa-medal',
                                    'envy' => 'fa-eye',
                                    'nostalgia' => 'fa-history',
                                    'gratitude' => 'fa-hands',
                                    'frustration' => 'fa-fist-raised',
                                    'boredom' => 'fa-yawn',
                                    default => 'fa-brain'
                                };
                                $emotionColor = match($primaryEmotion) {
                                    'joy' => 'text-yellow-400',
                                    'love' => 'text-pink-400',
                                    'trust' => 'text-green-400',
                                    'sadness' => 'text-blue-400',
                                    'anger' => 'text-red-400',
                                    'fear' => 'text-yellow-600',
                                    'surprise' => 'text-cyan-400',
                                    'disgust' => 'text-green-600',
                                    'anticipation' => 'text-indigo-400',
                                    'shame' => 'text-gray-600',
                                    'contempt' => 'text-purple-600',
                                    'loneliness' => 'text-gray-400',
                                    'pride' => 'text-purple-400',
                                    'envy' => 'text-emerald-600',
                                    'nostalgia' => 'text-amber-600',
                                    'gratitude' => 'text-orange-400',
                                    'frustration' => 'text-red-600',
                                    'boredom' => 'text-slate-400',
                                    default => 'text-gray-400'
                                };
                            ?>
                                <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-white dark:bg-gray-800 rounded-full flex items-center justify-center border-2 border-white dark:border-gray-800">
                                    <i class="fas <?= $emotionIcon ?> text-xs <?= $emotionColor ?>"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- AEI Name and Status -->
                        <div>
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></h1>
                            <div class="flex items-center space-x-1">
                                <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Online</span>
                                
                                <?php if ($isCurrentUserAdmin): ?>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">•</span>
                                    <button onclick="toggleEmotions()" class="text-xs text-ayuni-blue hover:text-ayuni-blue/80 transition-colors" title="View emotional state (Admin only)">
                                        <i class="fas fa-brain mr-1"></i>Emotions
                                    </button>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">•</span>
                                    <button onclick="toggleDebugPanel()" class="text-xs text-red-500 hover:text-red-400 transition-colors" title="View API debug information (Admin only)">
                                        <i class="fas fa-bug mr-1"></i>Debug
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
                
                <!-- Right: Theme Toggle at far right -->
                <div class="flex-shrink-0 mr-1">
                    <button 
                        id="theme-toggle" 
                        onclick="toggleTheme()" 
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
                        title="Toggle theme"
                    >
                        <i class="fas fa-sun sun-icon text-lg"></i>
                        <i class="fas fa-moon moon-icon text-lg"></i>
                    </button>
                </div>
                </div>
            </div>
        </div>
    </nav>
    <?php
}
?>