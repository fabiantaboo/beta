<?php
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $personality = sanitizeInput($_POST['personality'] ?? '');
        $appearance = sanitizeInput($_POST['appearance'] ?? '');
        $systemPrompt = sanitizeInput($_POST['system_prompt'] ?? '');
        
        if (empty($name)) {
            $error = "AEI name is required";
        } else {
            try {
                $aeiId = generateId();
                $stmt = $pdo->prepare("INSERT INTO aeis (id, user_id, name, personality, appearance_description, system_prompt) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$aeiId, getUserSession(), $name, $personality, $appearance, $systemPrompt]);
                redirectTo('dashboard');
            } catch (PDOException $e) {
                error_log("Database error creating AEI: " . $e->getMessage());
                $error = "An error occurred while creating your AEI. Please try again.";
            }
        }
    }
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <a href="/dashboard" class="flex items-center space-x-2 text-gray-700 dark:text-gray-300 hover:text-ayuni-blue transition-colors">
                        <i class="fas fa-arrow-left"></i>
                        <span class="font-medium">Back to Dashboard</span>
                    </a>
                    <img src="assets/ayuni.png" alt="Ayuni Logo" class="h-10 w-auto">
                    <span class="text-xl font-semibold text-gray-900 dark:text-white">Create AEI</span>
                </div>
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
    </nav>

    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">Create Your AEI</h1>
            <p class="text-gray-600 dark:text-gray-400 text-lg">Design a unique Artificial Emotional Intelligence companion</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-8">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        <i class="fas fa-robot mr-2 text-ayuni-blue"></i>
                        AEI Name *
                    </label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        required
                        maxlength="100"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                        placeholder="Give your AEI a name..."
                        value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                    />
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">What would you like to call your AEI?</p>
                </div>

                <div>
                    <label for="personality" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        <i class="fas fa-heart mr-2 text-ayuni-aqua"></i>
                        Personality & Traits
                    </label>
                    <textarea 
                        id="personality" 
                        name="personality" 
                        rows="4"
                        maxlength="1000"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                        placeholder="Describe your AEI's personality, interests, way of speaking, and unique characteristics..."
                    ><?= htmlspecialchars($_POST['personality'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">What makes your AEI unique? How do they think and communicate?</p>
                </div>

                <div>
                    <label for="appearance" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        <i class="fas fa-palette mr-2 text-ayuni-blue"></i>
                        Appearance Description
                    </label>
                    <textarea 
                        id="appearance" 
                        name="appearance" 
                        rows="3"
                        maxlength="500"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                        placeholder="How does your AEI present themselves? Describe their visual characteristics, style, or digital form..."
                    ><?= htmlspecialchars($_POST['appearance'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Optional: How would you visualize this AEI?</p>
                </div>

                <div>
                    <label for="system_prompt" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        <i class="fas fa-code mr-2 text-ayuni-aqua"></i>
                        Custom System Prompt
                        <span class="text-xs text-gray-500 dark:text-gray-400 font-normal">(Advanced)</span>
                    </label>
                    <textarea 
                        id="system_prompt" 
                        name="system_prompt" 
                        rows="4"
                        maxlength="2000"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                        placeholder="Optional: Define a custom system prompt for advanced AI behavior control..."
                    ><?= htmlspecialchars($_POST['system_prompt'] ?? '') ?></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Optional: Custom instructions for AI behavior. Leave empty to use automatic prompt generation based on personality and appearance.</p>
                </div>

                <div class="flex space-x-4 pt-6">
                    <a href="/dashboard" class="flex-1 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-3 px-6 rounded-lg text-center hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                        Cancel
                    </a>
                    <button type="submit" class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i class="fas fa-plus mr-2"></i>
                        Create AEI
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-8 text-center">
            <div class="inline-flex items-center space-x-2 text-gray-500 dark:text-gray-400 text-sm bg-blue-50 dark:bg-blue-900/20 px-4 py-2 rounded-lg">
                <i class="fas fa-lightbulb text-blue-500"></i>
                <span>Tip: The more detailed your description, the more unique your AEI will be</span>
            </div>
        </div>
    </div>
</div>