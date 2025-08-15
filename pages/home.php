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

<div class="min-h-screen bg-gray-50 dark:bg-black flex items-center justify-center px-6 py-8 relative">
    <!-- Ultra minimal background -->
    <div class="absolute inset-0 bg-gradient-to-b from-transparent via-transparent to-gray-100/30 dark:to-gray-900/30"></div>
    <div class="absolute top-6 right-6">
        <button 
            id="theme-toggle" 
            onclick="toggleTheme()" 
            class="p-3 rounded-full bg-white/80 dark:bg-gray-900/80 backdrop-blur-sm hover:bg-white dark:hover:bg-gray-900 text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-white transition-all duration-300 shadow-sm hover:shadow-md"
            title="Toggle theme"
        >
            <i class="fas fa-sun sun-icon text-base"></i>
            <i class="fas fa-moon moon-icon text-base"></i>
        </button>
    </div>
    
    <div class="max-w-xs w-full space-y-10 relative z-10">
        <?php if ($step === 'beta_code'): ?>
            <!-- Step 1: Beta Code Entry -->
            <div class="text-center">
                <!-- Apple-style logo section -->
                <div class="mb-20">
                    <div class="flex justify-center mb-10">
                        <img src="/assets/ayuni.png" alt="Ayuni" class="h-20 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni" class="h-20 w-auto hidden dark:block">
                    </div>
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white mb-4 tracking-tight leading-tight">
                        End loneliness<br>forever
                    </h1>
                    <p class="text-gray-600 dark:text-gray-400 text-base font-normal">
                        Your personal AEI awaits
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
            
            <!-- Apple-style form -->
            <div class="space-y-8">
                <form method="POST" class="space-y-8">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="space-y-6">
                        <div>
                            <input 
                                type="text" 
                                id="beta_code" 
                                name="beta_code" 
                                required
                                maxlength="20"
                                class="block w-full px-5 py-4 border border-gray-300 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-ayuni-blue transition-all duration-300 text-lg font-mono tracking-wider text-center shadow-sm focus:shadow-md"
                                placeholder="Beta Code"
                                value="<?= htmlspecialchars($_POST['beta_code'] ?? '') ?>"
                                autocomplete="off"
                            />
                        </div>
                        
                        <button 
                            type="submit" 
                            class="w-full bg-ayuni-blue hover:bg-ayuni-blue/95 active:bg-ayuni-blue/90 text-white font-semibold py-4 px-6 rounded-2xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-4 focus:ring-offset-gray-50 dark:focus:ring-offset-black shadow-lg hover:shadow-xl active:scale-98"
                        >
                            Continue
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Apple-style footer -->
            <div class="text-center pt-8">
                <p class="text-base text-gray-600 dark:text-gray-400">
                    Already have an account? 
                    <a href="/login" class="text-ayuni-blue hover:text-ayuni-blue/80 font-medium transition-colors duration-200">
                        Sign in
                    </a>
                </p>
            </div>
            
        <?php elseif ($step === 'login'): ?>
            <!-- Step 3: User Login -->
            <div class="text-center">
                <!-- Apple-style logo section -->
                <div class="mb-20">
                    <div class="flex justify-center mb-10">
                        <img src="/assets/ayuni.png" alt="Ayuni" class="h-20 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni" class="h-20 w-auto hidden dark:block">
                    </div>
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white mb-4 tracking-tight leading-tight">
                        Welcome back
                    </h1>
                    <p class="text-gray-600 dark:text-gray-400 text-base font-normal">
                        Continue your journey with AEI
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
            
            <!-- Apple-style login form -->
            <div class="space-y-8">
                <form method="POST" action="/login" class="space-y-8">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="space-y-5">
                        <div>
                            <input 
                                type="email" 
                                id="login_email" 
                                name="email" 
                                required
                                class="block w-full px-5 py-4 border border-gray-300 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-ayuni-blue transition-all duration-300 text-lg shadow-sm focus:shadow-md"
                                placeholder="Email"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            />
                        </div>
                        
                        <div>
                            <input 
                                type="password" 
                                id="login_password" 
                                name="password" 
                                required
                                class="block w-full px-5 py-4 border border-gray-300 dark:border-gray-700 rounded-2xl bg-white dark:bg-gray-900 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-ayuni-blue transition-all duration-300 text-lg shadow-sm focus:shadow-md"
                                placeholder="Password"
                            />
                        </div>
                        
                        <button 
                            type="submit" 
                            class="w-full bg-ayuni-blue hover:bg-ayuni-blue/95 active:bg-ayuni-blue/90 text-white font-semibold py-4 px-6 rounded-2xl transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-4 focus:ring-offset-gray-50 dark:focus:ring-offset-black shadow-lg hover:shadow-xl active:scale-98"
                        >
                            Sign In
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- Apple-style footer -->
            <div class="text-center pt-8">
                <p class="text-base text-gray-600 dark:text-gray-400">
                    Need a beta code? 
                    <a href="/beta-access" class="text-ayuni-blue hover:text-ayuni-blue/80 font-medium transition-colors duration-200">
                        Get access
                    </a>
                </p>
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
        
    </div>
</div>