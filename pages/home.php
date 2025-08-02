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
    
    <div class="max-w-md w-full space-y-8">
        <?php if ($step === 'beta_code'): ?>
            <!-- Step 1: Beta Code Entry -->
            <div class="text-center">
                <div class="mb-8">
                    <div class="flex justify-center mb-6">
                        <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-24 w-auto">
                    </div>
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
                        Continue
                    </button>
                </form>
            </div>
            
            <div class="text-center">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Already have an account?
                </p>
                <a href="/login" class="text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                    Sign in to your account
                </a>
            </div>
            
        <?php elseif ($step === 'login'): ?>
            <!-- Step 3: User Login -->
            <div class="text-center">
                <div class="mb-8">
                    <div class="flex justify-center mb-6">
                        <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-24 w-auto">
                    </div>
                </div>
                
                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Welcome Back</h2>
                <p class="text-gray-600 dark:text-gray-400 mb-8">
                    Sign in to continue your AI companion journey
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
                <form method="POST" action="/login" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div>
                        <label for="login_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Email Address
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-envelope text-gray-400"></i>
                            </div>
                            <input 
                                type="email" 
                                id="login_email" 
                                name="email" 
                                required
                                class="block w-full pl-12 pr-4 py-4 border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all text-lg"
                                placeholder="Enter your email"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            />
                        </div>
                    </div>
                    
                    <div>
                        <label for="login_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Password
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-gray-400"></i>
                            </div>
                            <input 
                                type="password" 
                                id="login_password" 
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
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Need a beta code?
                </p>
                <a href="/beta-access" class="text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                    Get beta access
                </a>
            </div>
            
        <?php else: ?>
            <!-- Step 2: Account Registration -->
            <div class="text-center">
                <div class="mb-8">
                    <div class="flex justify-center mb-6">
                        <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-24 w-auto">
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