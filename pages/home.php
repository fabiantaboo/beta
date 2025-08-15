<?php
if (isLoggedIn()) {
    redirectTo('dashboard');
}

$step = $_GET['step'] ?? 'beta_code';
$betaCodeData = null;

// Allowed steps
$allowedSteps = ['beta_code', 'register', 'login'];
if (!in_array($step, $allowedSteps)) {
    $step = 'beta_code';
}

// If we have temp beta code data and step is register, load it
if ($step === 'register' && isset($_SESSION['temp_beta_code'])) {
    $betaCodeData = $_SESSION['temp_beta_code'];
    // Debug: Show what data we have
    error_log("Pre-fill data: " . print_r($betaCodeData, true));
} elseif ($step === 'register' && !isset($_SESSION['temp_beta_code'])) {
    // No beta code data, redirect back to step 1
    $step = 'beta_code';
    $error = "Please enter your beta code first.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        if ($step === 'beta_code') {
            // Step 1: Validate beta code
            $betaCode = sanitizeInput($_POST['beta_code'] ?? '');
            
            if (empty($betaCode)) {
                $error = "Please enter your beta code.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT * FROM beta_codes WHERE code = ? AND is_active = TRUE AND used_at IS NULL");
                    $stmt->execute([$betaCode]);
                    $validCode = $stmt->fetch();
                    
                    if (!$validCode) {
                        $error = "Invalid or already used beta code.";
                    } else {
                        // Store beta code data in session temporarily
                        $_SESSION['temp_beta_code'] = $validCode;
                        header("Location: /register");
                        exit;
                    }
                } catch (PDOException $e) {
                    error_log("Database error validating beta code: " . $e->getMessage());
                    $error = "An error occurred. Please try again.";
                }
            }
        } elseif ($step === 'register') {
            // Step 2: Create account
            $email = sanitizeInput($_POST['email'] ?? '');
            $firstName = sanitizeInput($_POST['first_name'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($email) || empty($firstName) || empty($password)) {
                $error = "Please fill in all required fields.";
            } elseif ($password !== $confirmPassword) {
                $error = "Passwords do not match.";
            } elseif (strlen($password) < 6) {
                $error = "Password must be at least 6 characters long.";
            } else {
                try {
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = "An account with this email already exists.";
                    } else {
                        // Get beta code from session
                        $betaCodeInfo = $_SESSION['temp_beta_code'] ?? null;
                        if (!$betaCodeInfo) {
                            $error = "Session expired. Please start over.";
                        } else {
                            $userId = generateId();
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            
                            $pdo->beginTransaction();
                            
                            $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, first_name, beta_code, is_onboarded) VALUES (?, ?, ?, ?, ?, FALSE)");
                            $stmt->execute([$userId, $email, $passwordHash, $firstName, $betaCodeInfo['code']]);
                            
                            // Mark beta code as used
                            $stmt = $pdo->prepare("UPDATE beta_codes SET used_at = CURRENT_TIMESTAMP, used_by = ? WHERE code = ?");
                            $stmt->execute([$userId, $betaCodeInfo['code']]);
                            
                            $pdo->commit();
                            
                            // Clear temp session data
                            unset($_SESSION['temp_beta_code']);
                            
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
                    error_log("Database error during account creation: " . $e->getMessage());
                    $error = "An error occurred. Please try again.";
                }
            }
        } elseif ($step === 'login') {
            // Step 3: User Login
            $email = sanitizeInput($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            
            if (empty($email) || empty($password)) {
                $error = "Please fill in all fields.";
            } else {
                try {
                    $stmt = $pdo->prepare("SELECT id, password_hash, is_admin, is_onboarded FROM users WHERE email = ? AND password_hash IS NOT NULL");
                    $stmt->execute([$email]);
                    $user = $stmt->fetch();
                    
                    if ($user && password_verify($password, $user['password_hash'])) {
                        setUserSession($user['id']);
                        
                        $stmt = $pdo->prepare("UPDATE users SET last_active = CURRENT_TIMESTAMP WHERE id = ?");
                        $stmt->execute([$user['id']]);
                        
                        // Redirect based on user type and onboarding status
                        if ($user['is_admin']) {
                            redirectTo('admin');
                        } elseif (!$user['is_onboarded']) {
                            redirectTo('onboarding');
                        } else {
                            redirectTo('dashboard');
                        }
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
}
?>

<div class="min-h-screen bg-gradient-to-br from-indigo-50 via-purple-50 to-pink-50 dark:from-ayuni-dark dark:via-gray-900 dark:to-indigo-900 flex items-center justify-center px-4 py-8 relative overflow-hidden">
    <!-- Animated background elements -->
    <div class="absolute inset-0 overflow-hidden">
        <div class="absolute -top-40 -right-40 w-80 h-80 bg-ayuni-aqua/20 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute -bottom-40 -left-40 w-80 h-80 bg-ayuni-blue/20 rounded-full blur-3xl animate-pulse" style="animation-delay: 2s;"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-gradient-to-r from-ayuni-aqua/10 to-ayuni-blue/10 rounded-full blur-3xl animate-spin" style="animation-duration: 20s;"></div>
    </div>
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
    
    <div class="max-w-md w-full space-y-6 relative z-10">
        <?php if ($step === 'beta_code'): ?>
            <!-- Step 1: Beta Code Entry -->
            <div class="text-center">
                <!-- App-style header -->
                <div class="mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="relative">
                            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-4 mb-4 transform rotate-3 hover:rotate-0 transition-transform duration-300">
                                <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-20 w-auto dark:hidden">
                                <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-20 w-auto hidden dark:block">
                            </div>
                        </div>
                    </div>
                    <p class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">
                        End loneliness forever
                    </p>
                </div>
                
                <div class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-sm rounded-3xl border border-white/20 dark:border-gray-700/30 shadow-xl p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Welcome to Beta</h2>
                    <p class="text-gray-600 dark:text-gray-400">
                        Create and chat with <span class="font-semibold text-ayuni-blue">AEI companions</span> that understand your emotions
                    </p>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-lg rounded-3xl border border-white/30 dark:border-gray-700/30 shadow-2xl p-8">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label for="beta_code" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                            <i class="fas fa-key text-ayuni-blue mr-2"></i>Beta Access Code
                        </label>
                        <div class="relative">
                            <input 
                                type="text" 
                                id="beta_code" 
                                name="beta_code" 
                                required
                                maxlength="20"
                                class="block w-full px-6 py-5 border-2 border-gray-200 dark:border-gray-600 rounded-2xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-0 focus:border-ayuni-blue dark:focus:border-ayuni-aqua transition-all text-lg font-mono tracking-wider text-center"
                                placeholder="XXXX-XXXX-XXXX"
                                value="<?= htmlspecialchars($_POST['beta_code'] ?? '') ?>"
                                autocomplete="off"
                            />
                        </div>
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400 text-center">
                            🔑 Provided by the Ayuni team
                        </p>
                    </div>
                    
                    <button 
                        type="submit" 
                        class="w-full flex justify-center items-center px-6 py-5 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-bold rounded-2xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-4 focus:ring-ayuni-blue/30 transition-all duration-300 transform hover:scale-[1.02] hover:shadow-2xl shadow-lg active:scale-[0.98]"
                    >
                        <i class="fas fa-rocket mr-3"></i>
                        Start Your Journey
                    </button>
                </form>
            </div>
            
            <div class="text-center">
                <div class="bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm rounded-2xl border border-white/20 dark:border-gray-700/30 p-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                        Already have an account?
                    </p>
                    <a href="/login" class="inline-flex items-center text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Sign in to your account
                    </a>
                </div>
            </div>
            
        <?php elseif ($step === 'login'): ?>
            <!-- Step 3: User Login -->
            <div class="text-center">
                <!-- App-style header -->
                <div class="mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="relative">
                            <div class="bg-white dark:bg-gray-800 rounded-3xl shadow-2xl p-4 mb-4 transform rotate-3 hover:rotate-0 transition-transform duration-300">
                                <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-20 w-auto dark:hidden">
                                <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-20 w-auto hidden dark:block">
                            </div>
                        </div>
                    </div>
                    <p class="text-lg font-medium text-gray-700 dark:text-gray-300 mb-2">
                        End loneliness forever
                    </p>
                </div>
                
                <div class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-sm rounded-3xl border border-white/20 dark:border-gray-700/30 shadow-xl p-6 mb-6">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Welcome Back</h2>
                    <p class="text-gray-600 dark:text-gray-400">
                        Continue your journey with your <span class="font-semibold text-ayuni-blue">AEI companions</span>
                    </p>
                </div>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-lg rounded-3xl border border-white/30 dark:border-gray-700/30 shadow-2xl p-8">
                <form method="POST" action="/login" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label for="login_email" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                            <i class="fas fa-envelope text-ayuni-blue mr-2"></i>Email Address
                        </label>
                        <input 
                            type="email" 
                            id="login_email" 
                            name="email" 
                            required
                            class="block w-full px-6 py-5 border-2 border-gray-200 dark:border-gray-600 rounded-2xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-0 focus:border-ayuni-blue dark:focus:border-ayuni-aqua transition-all text-lg"
                            placeholder="your@email.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        />
                    </div>
                    
                    <div>
                        <label for="login_password" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">
                            <i class="fas fa-lock text-ayuni-blue mr-2"></i>Password
                        </label>
                        <input 
                            type="password" 
                            id="login_password" 
                            name="password" 
                            required
                            class="block w-full px-6 py-5 border-2 border-gray-200 dark:border-gray-600 rounded-2xl bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-0 focus:border-ayuni-blue dark:focus:border-ayuni-aqua transition-all text-lg"
                            placeholder="Your password"
                        />
                    </div>
                    
                    <button 
                        type="submit" 
                        class="w-full flex justify-center items-center px-6 py-5 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-bold rounded-2xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 focus:outline-none focus:ring-4 focus:ring-ayuni-blue/30 transition-all duration-300 transform hover:scale-[1.02] hover:shadow-2xl shadow-lg active:scale-[0.98]"
                    >
                        <i class="fas fa-sign-in-alt mr-3"></i>
                        Welcome Back
                    </button>
                </form>
            </div>
            
            <div class="text-center">
                <div class="bg-white/60 dark:bg-gray-800/60 backdrop-blur-sm rounded-2xl border border-white/20 dark:border-gray-700/30 p-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
                        Need a beta code?
                    </p>
                    <a href="/beta-access" class="inline-flex items-center text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                        <i class="fas fa-key mr-2"></i>
                        Get beta access
                    </a>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Step 2: Account Registration -->
            <div class="text-center">
                <div class="mb-8">
                    <div class="flex justify-center mb-6">
                        <div class="relative">
                            <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-28 w-auto dark:hidden">
                            <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-28 w-auto hidden dark:block">
                        </div>
                    </div>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Create Your Account</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-8">
                    Set up your Ayuni account to start creating AI companions
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
                <form method="POST" action="/register" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            First Name *
                        </label>
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            required
                            maxlength="100"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                            placeholder="Your first name"
                            value="<?= htmlspecialchars($_POST['first_name'] ?? ($betaCodeData['first_name'] ?? '')) ?>"
                        />
                        <?php if ($betaCodeData): ?>
                            <p class="text-xs text-blue-600 mt-1">Debug: Prefilling name with "<?= htmlspecialchars($betaCodeData['first_name'] ?? 'null') ?>"</p>
                        <?php endif; ?>
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Email Address *
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                            placeholder="your@email.com"
                            value="<?= htmlspecialchars($_POST['email'] ?? ($betaCodeData['email'] ?? '')) ?>"
                        />
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Password *
                        </label>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            minlength="6"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                            placeholder="Create a secure password"
                        />
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            At least 6 characters
                        </p>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Confirm Password *
                        </label>
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            required
                            minlength="6"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                            placeholder="Confirm your password"
                        />
                    </div>
                    
                    <div class="flex space-x-4 pt-4">
                        <a href="/beta-access" class="flex-1 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-3 px-6 rounded-lg text-center hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                            Back
                        </a>
                        <button 
                            type="submit" 
                            class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200"
                        >
                            <i class="fas fa-user-plus mr-2"></i>
                            Create Account
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="text-center">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Already have an account?
                </p>
                <a href="/login" class="text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                    Sign in instead
                </a>
            </div>
        <?php endif; ?>
        
        <!-- Feature highlights -->
        <div class="text-center">
            <div class="bg-white/50 dark:bg-gray-800/50 backdrop-blur-sm rounded-3xl border border-white/20 dark:border-gray-700/30 p-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">What makes AEI special?</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div class="flex flex-col items-center space-y-2 p-3 bg-gradient-to-br from-ayuni-aqua/10 to-ayuni-blue/10 rounded-2xl">
                        <div class="w-10 h-10 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center">
                            <i class="fas fa-heart text-white text-sm"></i>
                        </div>
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Emotional Understanding</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Companions that truly get you</span>
                    </div>
                    <div class="flex flex-col items-center space-y-2 p-3 bg-gradient-to-br from-ayuni-blue/10 to-purple-500/10 rounded-2xl">
                        <div class="w-10 h-10 bg-gradient-to-br from-ayuni-blue to-purple-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-user-friends text-white text-sm"></i>
                        </div>
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Personal AEI</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Create your unique companion</span>
                    </div>
                    <div class="flex flex-col items-center space-y-2 p-3 bg-gradient-to-br from-purple-500/10 to-pink-500/10 rounded-2xl">
                        <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full flex items-center justify-center">
                            <i class="fas fa-shield-alt text-white text-sm"></i>
                        </div>
                        <span class="font-semibold text-gray-700 dark:text-gray-300">Private & Secure</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">Your data stays yours</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>