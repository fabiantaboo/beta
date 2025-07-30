<?php
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_onboarded = TRUE WHERE id = ?");
            $stmt->execute([getUserSession()]);
            redirectTo('dashboard');
        } catch (PDOException $e) {
            error_log("Database error during onboarding: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}
?>

<div class="min-h-screen bg-gradient-to-br from-gray-50 to-white dark:from-ayuni-dark dark:to-gray-900 flex items-center justify-center px-4 relative">
    <div class="absolute top-4 right-4">
        <button 
            id="theme-toggle" 
            onclick="toggleTheme()" 
            class="p-2 rounded-lg bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
            title="Toggle theme"
        >
            <i class="fas fa-sun sun-icon text-lg"></i>
            <i class="fas fa-moon moon-icon text-lg"></i>
        </button>
    </div>
    
    <div class="max-w-4xl w-full">
        <div class="text-center mb-12">
            
            <div class="flex justify-center mb-8">
                <img src="assets/ayuni.png" alt="Ayuni Logo" class="h-32 w-auto">
            </div>
            <h1 class="text-5xl md:text-6xl font-bold text-gray-900 dark:text-white mb-6">
                Welcome to the Future
            </h1>
            <p class="text-xl text-gray-600 dark:text-gray-400 mb-8 max-w-3xl mx-auto">
                You're now part of the future of Artificial Emotional Intelligence. 
                Create and connect with AI companions that truly understand you.
            </p>
        </div>

        <div class="grid md:grid-cols-2 gap-8 mb-12">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                <div class="w-12 h-12 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-xl flex items-center justify-center mb-6">
                    <i class="fas fa-brain text-xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Emotional Intelligence</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Our AEIs understand and respond to emotional nuances, creating deeper, more meaningful interactions.
                </p>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                <div class="w-12 h-12 bg-gradient-to-r from-ayuni-blue to-ayuni-aqua rounded-xl flex items-center justify-center mb-6">
                    <i class="fas fa-palette text-xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Unique Personalities</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Design companions with distinct personalities, interests, and communication styles tailored to your preferences.
                </p>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                <div class="w-12 h-12 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-xl flex items-center justify-center mb-6">
                    <i class="fas fa-comments text-xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Natural Conversations</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Engage in flowing, natural dialogues that feel authentic and emotionally resonant.
                </p>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-lg">
                <div class="w-12 h-12 bg-gradient-to-r from-ayuni-blue to-ayuni-aqua rounded-xl flex items-center justify-center mb-6">
                    <i class="fas fa-shield-alt text-xl text-white"></i>
                </div>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Privacy & Security</h3>
                <p class="text-gray-600 dark:text-gray-400">
                    Your conversations are private and secure, with enterprise-grade protection for your data.
                </p>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6 text-center">
                <div class="flex items-center justify-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="text-center">
            <form method="POST" class="inline-block">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <button type="submit" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-8 rounded-xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition-all duration-200 transform hover:scale-105 shadow-lg">
                    <i class="fas fa-rocket mr-2"></i>
                    Start Your Journey
                </button>
            </form>
        </div>

        <div class="text-center mt-8">
            <div class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-ayuni-aqua/10 to-ayuni-blue/10 border border-ayuni-aqua/20 dark:border-ayuni-blue/20 rounded-full">
                <i class="fas fa-flask text-ayuni-blue mr-2"></i>
                <span class="text-ayuni-blue font-medium text-sm">Beta Version</span>
            </div>
        </div>
    </div>
</div>