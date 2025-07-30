<?php
if (isLoggedIn()) {
    redirectTo('dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $betaCode = sanitizeInput($_POST['beta_code'] ?? '');
        
        if (empty($email) || empty($password) || empty($firstName) || empty($lastName) || empty($betaCode)) {
            $error = "Please fill in all fields.";
        } elseif ($password !== $confirmPassword) {
            $error = "Passwords do not match.";
        } elseif (strlen($password) < 8) {
            $error = "Password must be at least 8 characters long.";
        } else {
            try {
                // Check if beta code is valid
                $stmt = $pdo->prepare("SELECT code FROM beta_codes WHERE code = ? AND is_active = TRUE AND used_at IS NULL");
                $stmt->execute([$betaCode]);
                $validCode = $stmt->fetch();
                
                if (!$validCode) {
                    $error = "Invalid or already used beta code.";
                } else {
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = "An account with this email already exists.";
                    } else {
                        // Create user
                        $userId = generateId();
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        
                        $pdo->beginTransaction();
                        
                        $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, first_name, last_name, beta_code) VALUES (?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$userId, $email, $passwordHash, $firstName, $lastName, $betaCode]);
                        
                        // Mark beta code as used
                        $stmt = $pdo->prepare("UPDATE beta_codes SET used_at = CURRENT_TIMESTAMP, used_by = ? WHERE code = ?");
                        $stmt->execute([$userId, $betaCode]);
                        
                        $pdo->commit();
                        
                        setUserSession($userId);
                        redirectTo('onboarding');
                    }
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log("Database error during registration: " . $e->getMessage());
                $error = "An error occurred. Please try again.";
            }
        }
    }
}
?>

<div class="min-h-screen flex">
    <!-- Left Side - Branding -->
    <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-ayuni-blue to-ayuni-aqua items-center justify-center p-12">
        <div class="max-w-md text-center">
            <h1 class="text-4xl font-bold text-white mb-6">Join Ayuni Beta</h1>
            <p class="text-xl text-white/90 mb-8">Be among the first to experience the future of Artificial Emotional Intelligence.</p>
            <div class="space-y-4 text-white/80">
                <div class="flex items-center justify-center space-x-3">
                    <i class="fas fa-flask text-2xl"></i>
                    <span>Early access features</span>
                </div>
                <div class="flex items-center justify-center space-x-3">
                    <i class="fas fa-users text-2xl"></i>
                    <span>Exclusive beta community</span>
                </div>
                <div class="flex items-center justify-center space-x-3">
                    <i class="fas fa-rocket text-2xl"></i>
                    <span>Shape the future</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Side - Register Form -->
    <div class="flex-1 flex items-center justify-center p-8 bg-gray-50 dark:bg-gray-900">
        <div class="max-w-md w-full space-y-8">
            <div class="text-center">
                <div class="lg:hidden mb-8">
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent">Ayuni</h1>
                </div>
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white">Join the beta</h2>
                <p class="mt-2 text-gray-600 dark:text-gray-400">
                    Already have an account? 
                    <a href="?page=login" class="text-ayuni-blue hover:text-ayuni-aqua font-medium transition-colors">Sign in</a>
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
            
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            First name
                        </label>
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            required
                            maxlength="100"
                            class="block w-full px-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="First name"
                            value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>"
                        />
                    </div>
                    <div>
                        <label for="last_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Last name
                        </label>
                        <input 
                            type="text" 
                            id="last_name" 
                            name="last_name" 
                            required
                            maxlength="100"
                            class="block w-full px-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="Last name"
                            value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>"
                        />
                    </div>
                </div>
                
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Email address
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-gray-400"></i>
                        </div>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="Enter your email"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        />
                    </div>
                </div>
                
                <div>
                    <label for="beta_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Beta code
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-key text-gray-400"></i>
                        </div>
                        <input 
                            type="text" 
                            id="beta_code" 
                            name="beta_code" 
                            required
                            maxlength="20"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="Enter your beta code"
                            value="<?= htmlspecialchars($_POST['beta_code'] ?? '') ?>"
                        />
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            minlength="8"
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="Create a password (min. 8 characters)"
                        />
                    </div>
                </div>
                
                <div>
                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Confirm password
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-gray-400"></i>
                        </div>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            required
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="Confirm your password"
                        />
                    </div>
                </div>
                
                <button 
                    type="submit" 
                    class="w-full flex justify-center items-center px-4 py-3 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition-all duration-200 transform hover:scale-[1.02]"
                >
                    <i class="fas fa-user-plus mr-2"></i>
                    Create Account
                </button>
            </form>
        </div>
    </div>
</div>