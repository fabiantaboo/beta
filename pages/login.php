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
        
        if (empty($email) || empty($password)) {
            $error = "Please fill in all fields.";
        } else {
            try {
                $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = ?");
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

<div class="min-h-screen flex">
    <!-- Left Side - Branding -->
    <div class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue items-center justify-center p-12">
        <div class="max-w-md text-center">
            <h1 class="text-4xl font-bold text-white mb-6">Welcome to Ayuni</h1>
            <p class="text-xl text-white/90 mb-8">Connect with Artificial Emotional Intelligence companions designed to understand and engage with you on a deeper level.</p>
            <div class="space-y-4 text-white/80">
                <div class="flex items-center justify-center space-x-3">
                    <i class="fas fa-brain text-2xl"></i>
                    <span>Advanced emotional understanding</span>
                </div>
                <div class="flex items-center justify-center space-x-3">
                    <i class="fas fa-comments text-2xl"></i>
                    <span>Natural conversations</span>
                </div>
                <div class="flex items-center justify-center space-x-3">
                    <i class="fas fa-user-friends text-2xl"></i>
                    <span>Personalized companions</span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Right Side - Login Form -->
    <div class="flex-1 flex items-center justify-center p-8 bg-gray-50 dark:bg-gray-900">
        <div class="max-w-md w-full space-y-8">
            <div class="text-center">
                <div class="lg:hidden mb-8">
                    <h1 class="text-3xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent">Ayuni</h1>
                </div>
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white">Sign in to your account</h2>
                <p class="mt-2 text-gray-600 dark:text-gray-400">
                    Don't have an account? 
                    <a href="?page=register" class="text-ayuni-blue hover:text-ayuni-aqua font-medium transition-colors">Join the beta</a>
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
                            class="block w-full pl-10 pr-3 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-800 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-colors"
                            placeholder="Enter your password"
                        />
                    </div>
                </div>
                
                <button 
                    type="submit" 
                    class="w-full flex justify-center items-center px-4 py-3 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-2 dark:focus:ring-offset-gray-900 transition-all duration-200 transform hover:scale-[1.02]"
                >
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Sign In
                </button>
            </form>
        </div>
    </div>
</div>