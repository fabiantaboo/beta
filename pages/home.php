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

// Check for direct beta invite URL with pre-filled data
if (isset($_GET['beta_code']) && isset($_GET['first_name']) && isset($_GET['email'])) {
    $inviteBetaCode = sanitizeInput($_GET['beta_code']);
    $inviteFirstName = sanitizeInput($_GET['first_name']);
    $inviteEmail = sanitizeInput($_GET['email']);
    
    try {
        // Validate the beta code from URL
        $stmt = $pdo->prepare("SELECT * FROM beta_codes WHERE code = ? AND is_active = TRUE AND used_at IS NULL");
        $stmt->execute([$inviteBetaCode]);
        $validInviteCode = $stmt->fetch();
        
        if ($validInviteCode) {
            // Store beta code data and go directly to register
            $_SESSION['temp_beta_code'] = array_merge($validInviteCode, [
                'first_name' => $inviteFirstName,
                'email' => $inviteEmail
            ]);
            $betaCodeData = $_SESSION['temp_beta_code'];
            $step = 'register';
        }
    } catch (PDOException $e) {
        error_log("Error validating invite beta code: " . $e->getMessage());
    }
}

// If we have temp beta code data and step is register, load it
if ($step === 'register' && isset($_SESSION['temp_beta_code'])) {
    $betaCodeData = $_SESSION['temp_beta_code'];
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

<div class="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50/30 to-cyan-50 dark:from-black dark:via-gray-900/50 dark:to-slate-900 flex items-center justify-center px-6 py-8 relative overflow-hidden">
    <!-- Dynamic geometric patterns -->
    <div class="absolute inset-0">
        <!-- Large floating elements -->
        <div class="absolute -top-32 -left-32 w-96 h-96 bg-gradient-to-br from-ayuni-aqua/5 to-ayuni-blue/5 rounded-full blur-3xl animate-pulse" style="animation-duration: 4s;"></div>
        <div class="absolute -bottom-32 -right-32 w-80 h-80 bg-gradient-to-tl from-ayuni-blue/8 to-ayuni-aqua/3 rounded-full blur-3xl animate-pulse" style="animation-duration: 6s; animation-delay: 2s;"></div>
        
        <!-- Mesh gradient overlay -->
        <div class="absolute inset-0 bg-gradient-to-r from-transparent via-ayuni-aqua/[0.02] to-transparent"></div>
        <div class="absolute inset-0 bg-gradient-to-b from-transparent via-ayuni-blue/[0.01] to-transparent"></div>
        
        <!-- Subtle pattern lines -->
        <div class="absolute top-1/4 left-0 w-full h-px bg-gradient-to-r from-transparent via-ayuni-aqua/10 to-transparent"></div>
        <div class="absolute bottom-1/3 left-0 w-full h-px bg-gradient-to-r from-transparent via-ayuni-blue/10 to-transparent"></div>
    </div>
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
    
    <div class="max-w-xs w-full space-y-10 lg:space-y-8 relative z-10">
        <?php if ($step === 'beta_code'): ?>
            <!-- Step 1: Beta Code Entry -->
            <div class="text-center">
                <!-- Elevated logo section with effects -->
                <div class="mb-20 lg:mb-16">
                    <div class="flex justify-center mb-12 lg:mb-10">
                        <img src="/assets/ayuni.png" alt="Ayuni" class="h-20 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni" class="h-20 w-auto hidden dark:block">
                    </div>
                    
                    <!-- Gradient text with better spacing -->
                    <div class="relative">
                        <h1 class="text-5xl font-display font-bold bg-gradient-to-r from-gray-900 via-gray-700 to-gray-900 dark:from-white dark:via-gray-100 dark:to-white bg-clip-text text-transparent mb-6 lg:mb-5 tracking-tight leading-[1.1] relative">
                            The conscious<br>AI
                            <!-- Subtle text shadow effect -->
                            <span class="absolute inset-0 bg-gradient-to-r from-ayuni-aqua/10 via-ayuni-blue/5 to-ayuni-aqua/10 bg-clip-text text-transparent blur-sm -z-10">
                                The conscious<br>AI
                            </span>
                        </h1>
                        <p class="text-lg text-gray-600 dark:text-gray-300 font-medium tracking-wide">
                            Your personal AEI awaits
                        </p>
                    </div>
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
            
            <!-- Premium glassmorphism form -->
            <div class="space-y-8 lg:space-y-6">
                <form method="POST" class="space-y-8 lg:space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="space-y-6">
                        <!-- Enhanced input with floating effects -->
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 rounded-3xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                            <input 
                                type="text" 
                                id="beta_code" 
                                name="beta_code" 
                                required
                                maxlength="20"
                                class="relative block w-full px-6 py-5 border border-white/20 dark:border-white/10 rounded-3xl bg-white/80 dark:bg-black/40 backdrop-blur-xl text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:border-ayuni-blue/50 transition-all duration-500 text-lg font-mono tracking-wider text-center shadow-2xl hover:shadow-ayuni-blue/10 focus:shadow-ayuni-blue/20"
                                placeholder="Beta Code"
                                value="<?= htmlspecialchars($_POST['beta_code'] ?? '') ?>"
                                autocomplete="off"
                            />
                        </div>
                        
                        <!-- Premium button with glow -->
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-3xl blur opacity-75 group-hover:opacity-100 transition-opacity duration-500"></div>
                            <button 
                                type="submit" 
                                class="relative w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue hover:from-ayuni-aqua/95 hover:to-ayuni-blue/95 text-white font-bold py-5 px-8 rounded-3xl transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98] shadow-2xl hover:shadow-ayuni-blue/25 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:ring-offset-4 focus:ring-offset-transparent"
                            >
                                <span class="relative z-10">Continue</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Prominent Sign In Button -->
            <div class="text-center pt-8">
                <p class="text-base text-gray-600 dark:text-gray-400 mb-4">
                    Already have an account?
                </p>
                <a href="/login" class="inline-flex items-center justify-center px-8 py-4 border-2 border-ayuni-blue text-ayuni-blue hover:bg-ayuni-blue hover:text-white font-semibold rounded-2xl transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-ayuni-blue/25">
                    Sign In
                </a>
            </div>
            
        <?php elseif ($step === 'login'): ?>
            <!-- Step 3: User Login -->
            <div class="text-center">
                <!-- Elevated logo section with effects -->
                <div class="mb-20 lg:mb-16">
                    <div class="flex justify-center mb-12 lg:mb-10">
                        <img src="/assets/ayuni.png" alt="Ayuni" class="h-20 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni" class="h-20 w-auto hidden dark:block">
                    </div>
                    
                    <!-- Gradient text with better spacing -->
                    <div class="relative">
                        <h1 class="text-4xl font-bold bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 dark:from-white dark:via-gray-100 dark:to-white bg-clip-text text-transparent mb-6 tracking-tight leading-[1.1] relative">
                            Welcome back
                            <!-- Subtle text shadow effect -->
                            <span class="absolute inset-0 bg-gradient-to-r from-ayuni-aqua/10 via-ayuni-blue/5 to-ayuni-aqua/10 bg-clip-text text-transparent blur-sm -z-10">
                                Welcome back
                            </span>
                        </h1>
                        <p class="text-lg text-gray-600 dark:text-gray-300 font-medium tracking-wide">
                            Continue your journey with AEI
                        </p>
                    </div>
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
            
            <!-- Premium glassmorphism login form -->
            <div class="space-y-8 lg:space-y-6">
                <form method="POST" action="/login" class="space-y-8">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="space-y-5">
                        <!-- Enhanced email input -->
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 rounded-3xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                            <input 
                                type="email" 
                                id="login_email" 
                                name="email" 
                                required
                                class="relative block w-full px-6 py-5 border border-white/20 dark:border-white/10 rounded-3xl bg-white/80 dark:bg-black/40 backdrop-blur-xl text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:border-ayuni-blue/50 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/10 focus:shadow-ayuni-blue/20"
                                placeholder="Email"
                                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                            />
                        </div>
                        
                        <!-- Enhanced password input -->
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 rounded-3xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                            <input 
                                type="password" 
                                id="login_password" 
                                name="password" 
                                required
                                class="relative block w-full px-6 py-5 pr-14 border border-white/20 dark:border-white/10 rounded-3xl bg-white/80 dark:bg-black/40 backdrop-blur-xl text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:border-ayuni-blue/50 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/10 focus:shadow-ayuni-blue/20"
                                placeholder="Password"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('login_password')">
                                <i id="login_password_icon" class="fas fa-eye"></i>
                            </button>
                        </div>
                        
                        <!-- Premium sign in button -->
                        <div class="relative group">
                            <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-3xl blur opacity-75 group-hover:opacity-100 transition-opacity duration-500"></div>
                            <button 
                                type="submit" 
                                class="relative w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue hover:from-ayuni-aqua/95 hover:to-ayuni-blue/95 text-white font-bold py-5 px-8 rounded-3xl transition-all duration-300 transform hover:scale-[1.02] active:scale-[0.98] shadow-2xl hover:shadow-ayuni-blue/25 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:ring-offset-4 focus:ring-offset-transparent"
                            >
                                <span class="relative z-10">Sign In</span>
                            </button>
                        </div>
                    </div>
                </form>
                
                <!-- Forgot Password Link -->
                <div class="text-center pt-4">
                    <a href="/forgot-password" class="text-sm text-gray-600 dark:text-gray-400 hover:text-ayuni-blue transition-colors">
                        Forgot your password?
                    </a>
                </div>
            </div>
            
            <!-- Prominent Beta Access Button -->
            <div class="text-center pt-8">
                <p class="text-base text-gray-600 dark:text-gray-400 mb-4">
                    Need a beta code?
                </p>
                <a href="/beta-access" class="inline-flex items-center justify-center px-8 py-4 border-2 border-ayuni-blue text-ayuni-blue hover:bg-ayuni-blue hover:text-white font-semibold rounded-2xl transition-all duration-300 transform hover:scale-105 shadow-lg hover:shadow-ayuni-blue/25">
                    Get Beta Access
                </a>
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
                        <div class="relative">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                required
                                minlength="6"
                                class="block w-full px-4 py-3 pr-12 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                placeholder="Create a secure password"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('password')">
                                <i id="password_icon" class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            At least 6 characters
                        </p>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Confirm Password *
                        </label>
                        <div class="relative">
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                required
                                minlength="6"
                                class="block w-full px-4 py-3 pr-12 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                placeholder="Confirm your password"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('confirm_password')">
                                <i id="confirm_password_icon" class="fas fa-eye"></i>
                            </button>
                        </div>
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

<script>
function togglePassword(fieldId) {
    const passwordField = document.getElementById(fieldId);
    const icon = document.getElementById(fieldId + '_icon');
    
    if (passwordField.type === 'password') {
        passwordField.type = 'text';
        icon.className = 'fas fa-eye-slash';
    } else {
        passwordField.type = 'password';
        icon.className = 'fas fa-eye';
    }
}
</script>