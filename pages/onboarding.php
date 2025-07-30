<?php
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

<div class="min-h-screen flex items-center justify-center px-4">
    <div class="max-w-2xl w-full">
        <div class="text-center mb-12">
            <h1 class="text-6xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent mb-6">
                Welcome to Ayuni
            </h1>
            <p class="text-xl text-gray-300 mb-8">
                Enter a world where you can create and interact with Advanced Electronic Intelligences - 
                unique digital beings with their own personalities and characteristics.
            </p>
        </div>

        <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-8 border border-gray-700 mb-8">
            <h2 class="text-2xl font-semibold text-ayuni-aqua mb-6">What are AEIs?</h2>
            <div class="space-y-4 text-gray-300">
                <p>🧠 <strong>Advanced Electronic Intelligences</strong> are not just AI tools - they're digital companions with unique personalities</p>
                <p>💬 Each AEI has its own way of thinking, communicating, and relating to the world</p>
                <p>🎭 You can create AEIs with different personalities, interests, and characteristics</p>
                <p>🤝 Build meaningful relationships through natural conversations</p>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-500/20 border border-red-500/50 text-red-200 px-4 py-3 rounded-lg mb-6 text-center">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <div class="text-center">
            <form method="POST" class="inline-block">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <button type="submit" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-ayuni-dark font-bold py-4 px-8 rounded-xl text-lg hover:scale-105 transform transition-all duration-200 shadow-lg hover:shadow-xl">
                    Start Your Journey
                </button>
            </form>
        </div>

        <div class="text-center mt-8">
            <div class="inline-flex items-center space-x-2 text-gray-400">
                <div class="w-2 h-2 bg-ayuni-aqua rounded-full animate-pulse"></div>
                <span class="text-sm">Beta Version</span>
                <div class="w-2 h-2 bg-ayuni-blue rounded-full animate-pulse"></div>
            </div>
        </div>
    </div>
</div>