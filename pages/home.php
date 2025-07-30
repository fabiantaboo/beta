<?php
if (isLoggedIn()) {
    redirectTo('dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $betaCode = sanitizeInput($_POST['beta_code'] ?? '');
        
        if (empty($betaCode)) {
            $error = "Please enter your beta code.";
        } else {
            try {
                // Check if beta code is valid and get pre-filled data
                $stmt = $pdo->prepare("SELECT * FROM beta_codes WHERE code = ? AND is_active = TRUE AND used_at IS NULL");
                $stmt->execute([$betaCode]);
                $validCode = $stmt->fetch();
                
                if (!$validCode) {
                    $error = "Invalid or already used beta code.";
                } else {
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$validCode['email']]);
                    if ($stmt->fetch()) {
                        $error = "An account with this email already exists.";
                    } else {
                        // Create user automatically with pre-filled data
                        $userId = generateId();
                        
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("INSERT INTO users (id, email, first_name, beta_code) VALUES (?, ?, ?, ?)");
                        $stmt->execute([$userId, $validCode['email'], $validCode['first_name'], $betaCode]);
                        
                        // Mark beta code as used
                        $stmt = $pdo->prepare("UPDATE beta_codes SET used_at = CURRENT_TIMESTAMP, used_by = ? WHERE code = ?");
                        $stmt->execute([$userId, $betaCode]);
                        
                        $pdo->commit();
                        
                        setUserSession($userId);
                        redirectTo('onboarding');
                    }
                }
            } catch (PDOException $e) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (PDOException $rollbackException) {
                    error_log("Rollback failed: " . $rollbackException->getMessage());
                }
                error_log("Database error during beta code verification: " . $e->getMessage());
                $error = "An error occurred. Please try again.";
            }
        }
    }
}
?>

<div class="min-h-screen bg-gradient-to-br from-gray-50 to-white dark:from-ayuni-dark dark:to-gray-900 flex items-center justify-center px-4">
    <div class="max-w-md w-full space-y-8">
        <div class="text-center">
            <div class="mb-8">
                <h1 class="text-4xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent mb-2">
                    Ayuni Beta
                </h1>
                <p class="text-gray-600 dark:text-gray-400">
                    Artificial Emotional Intelligence
                </p>
            </div>
            
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Welcome to the Future</h2>
            <p class="text-gray-600 dark:text-gray-400 mb-8">
                Enter your beta code to begin your journey with AI companions that truly understand you.
            </p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
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
                    <label for="beta_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Beta Access Code
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-key text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            id="beta_code" 
                            name="beta_code" 
                            required
                            maxlength="20"
                            class="block w-full pl-12 pr-4 py-4 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all text-lg font-mono tracking-wider"
                            placeholder="Enter your beta code"
                            value="<?= htmlspecialchars($_POST['beta_code'] ?? '') ?>"
                            autocomplete="off"
                        />
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        Your beta code was provided by the Ayuni team
                    </p>
                </div>
                
                <button 
                    type="submit" 
                    class="w-full flex justify-center items-center px-6 py-4 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition-all duration-200 transform hover:scale-[1.02] shadow-lg"
                >
                    <i class="fas fa-arrow-right mr-2"></i>
                    Access Ayuni Beta
                </button>
            </form>
        </div>
        
        <div class="text-center">
            <div class="flex items-center justify-center space-x-6 text-sm text-gray-500 dark:text-gray-400">
                <div class="flex items-center space-x-2">
                    <i class="fas fa-brain text-ayuni-blue"></i>
                    <span>Emotional AI</span>
                </div>
                <div class="flex items-center space-x-2">
                    <i class="fas fa-shield-alt text-ayuni-blue"></i>
                    <span>Private & Secure</span>
                </div>
                <div class="flex items-center space-x-2">
                    <i class="fas fa-flask text-ayuni-blue"></i>
                    <span>Beta Access</span>
                </div>
            </div>
        </div>
    </div>
</div>