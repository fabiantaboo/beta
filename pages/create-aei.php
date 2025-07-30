<?php
requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $personality = sanitizeInput($_POST['personality'] ?? '');
        $appearance = sanitizeInput($_POST['appearance'] ?? '');
        
        if (empty($name)) {
            $error = "AEI name is required";
        } else {
            try {
                $aeiId = generateId();
                $stmt = $pdo->prepare("INSERT INTO aeis (id, user_id, name, personality, appearance_description) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$aeiId, getUserSession(), $name, $personality, $appearance]);
                redirectTo('dashboard');
            } catch (PDOException $e) {
                error_log("Database error creating AEI: " . $e->getMessage());
                $error = "An error occurred while creating your AEI. Please try again.";
            }
        }
    }
}
?>

<div class="min-h-screen bg-gradient-to-br from-ayuni-dark via-gray-900 to-ayuni-dark">
    <nav class="bg-ayuni-dark/80 backdrop-blur-sm border-b border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <a href="?page=dashboard" class="flex items-center space-x-3 hover:opacity-80 transition-opacity">
                    <i class="fas fa-arrow-left text-ayuni-aqua"></i>
                    <img src="assets/ayuni.png" alt="Ayuni Logo" class="h-10 w-auto">
                </a>
                <button 
                    id="theme-toggle" 
                    onclick="toggleTheme()" 
                    class="p-2 rounded-lg bg-gray-700/50 hover:bg-gray-600/50 text-gray-300 hover:text-white transition-all duration-200"
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
            <h1 class="text-4xl font-bold text-ayuni-white mb-4">Create Your AEI</h1>
            <p class="text-gray-400 text-lg">Design a unique Advanced Electronic Intelligence companion</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-6 border border-gray-700">
                <label for="name" class="block text-sm font-medium text-ayuni-aqua mb-2">
                    AEI Name *
                </label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    required
                    maxlength="100"
                    class="w-full bg-gray-700/50 border border-gray-600 rounded-lg px-4 py-3 text-ayuni-white placeholder-gray-400 focus:border-ayuni-aqua focus:ring-1 focus:ring-ayuni-aqua focus:outline-none transition-colors"
                    placeholder="Give your AEI a name..."
                    value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                />
                <p class="text-xs text-gray-500 mt-1">What would you like to call your AEI?</p>
            </div>

            <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-6 border border-gray-700">
                <label for="personality" class="block text-sm font-medium text-ayuni-aqua mb-2">
                    Personality & Traits
                </label>
                <textarea 
                    id="personality" 
                    name="personality" 
                    rows="4"
                    maxlength="1000"
                    class="w-full bg-gray-700/50 border border-gray-600 rounded-lg px-4 py-3 text-ayuni-white placeholder-gray-400 focus:border-ayuni-aqua focus:ring-1 focus:ring-ayuni-aqua focus:outline-none transition-colors resize-none"
                    placeholder="Describe your AEI's personality, interests, way of speaking, and unique characteristics..."
                ><?= htmlspecialchars($_POST['personality'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-1">What makes your AEI unique? How do they think and communicate?</p>
            </div>

            <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-6 border border-gray-700">
                <label for="appearance" class="block text-sm font-medium text-ayuni-aqua mb-2">
                    Appearance Description
                </label>
                <textarea 
                    id="appearance" 
                    name="appearance" 
                    rows="3"
                    maxlength="500"
                    class="w-full bg-gray-700/50 border border-gray-600 rounded-lg px-4 py-3 text-ayuni-white placeholder-gray-400 focus:border-ayuni-aqua focus:ring-1 focus:ring-ayuni-aqua focus:outline-none transition-colors resize-none"
                    placeholder="How does your AEI present themselves? Describe their visual characteristics, style, or digital form..."
                ><?= htmlspecialchars($_POST['appearance'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-1">Optional: How would you visualize this AEI?</p>
            </div>

            <div class="flex space-x-4 pt-4">
                <a href="?page=dashboard" class="flex-1 bg-gray-700 text-gray-300 font-semibold py-3 px-6 rounded-lg text-center hover:bg-gray-600 transition-colors">
                    Cancel
                </a>
                <button type="submit" class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-ayuni-dark font-bold py-3 px-6 rounded-lg hover:scale-105 transform transition-all duration-200 shadow-lg hover:shadow-xl">
                    Create AEI
                </button>
            </div>
        </form>

        <div class="mt-8 text-center">
            <div class="inline-flex items-center space-x-2 text-gray-500 text-sm">
                <span>💡</span>
                <span>Tip: The more detailed your description, the more unique your AEI will be</span>
            </div>
        </div>
    </div>
</div>