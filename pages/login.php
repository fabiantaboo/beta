<?php
// Admin login for special cases
if (isLoggedIn()) {
    redirectTo('dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = "Please fill in all fields.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ? AND password_hash IS NOT NULL");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user && password_verify($password, $user['password_hash'])) {
                    setUserSession($user['id']);
                    
                    $stmt = $pdo->prepare("UPDATE users SET last_active = CURRENT_TIMESTAMP WHERE id = ?");
                    $stmt->execute([$user['id']]);
                    
                    redirectTo('dashboard');
                } else {
                    $error = "Invalid email or password.";
                }
            } catch (PDOException $e) {
                error_log("Database error during login: " . $e->getMessage());
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
                    Admin Access
                </p>
            </div>
            
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Admin Sign In</h2>
            <p class="text-gray-600 dark:text-gray-400 mb-8">
                For administrative access only
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
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Email Address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required
                            class="block w-full pl-12 pr-4 py-4 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all text-lg"
                            placeholder="Enter your email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        />
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            class="block w-full pl-12 pr-4 py-4 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all text-lg"
                            placeholder="Enter your password"
                        />
                    </div>
                </div>
                
                <button 
                    type="submit" 
                    class="w-full flex justify-center items-center px-6 py-4 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition-all duration-200 transform hover:scale-[1.02] shadow-lg"
                >
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Sign In
                </button>
            </form>
        </div>
        
        <div class="text-center">
            <a href="?page=home" class="text-sm text-gray-500 dark:text-gray-400 hover:text-ayuni-blue transition-colors">
                <i class="fas fa-arrow-left mr-1"></i>
                Back to Beta Access
            </a>
        </div>
    </div>
</div>