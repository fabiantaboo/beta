<?php
// Redirect if already logged in
if (isLoggedIn()) {
    redirectTo('dashboard');
}

require_once __DIR__ . '/../includes/mailgun_api.php';

$error = null;
$success = null;
$step = $_GET['step'] ?? 'request';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 'request') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = "Please enter a valid email address.";
        } else {
            try {
                // Check if user exists
                $stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email = ? AND is_admin = FALSE");
                $stmt->execute([$email]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Check rate limiting (max 3 requests per hour per email)
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) as request_count 
                        FROM password_resets 
                        WHERE user_id = ? 
                        AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    ");
                    $stmt->execute([$user['id']]);
                    $recentRequests = $stmt->fetchColumn();
                    
                    if ($recentRequests >= 3) {
                        $error = "Too many password reset requests. Please try again in an hour.";
                    } else {
                        // Generate secure token
                        $token = bin2hex(random_bytes(32));
                        $resetId = generateId();
                        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour from now
                        
                        // Store reset token
                        $stmt = $pdo->prepare("
                            INSERT INTO password_resets (id, user_id, token, expires_at, ip_address, user_agent) 
                            VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $resetId,
                            $user['id'],
                            $token,
                            $expiresAt,
                            $_SERVER['REMOTE_ADDR'] ?? null,
                            $_SERVER['HTTP_USER_AGENT'] ?? null
                        ]);
                        
                        // Send reset email
                        $mailgun = new MailgunAPI();
                        $resetUrl = "https://" . $_SERVER['HTTP_HOST'] . "/reset-password?token=" . urlencode($token);
                        
                        if ($mailgun->sendPasswordResetEmail($email, $resetUrl, $user['first_name'])) {
                            // Clean up old reset tokens for this user
                            $stmt = $pdo->prepare("
                                DELETE FROM password_resets 
                                WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
                            ");
                            $stmt->execute([$user['id']]);
                            
                            $step = 'sent';
                        } else {
                            $error = "Failed to send reset email. Please try again later.";
                        }
                    }
                } else {
                    // Don't reveal if email exists - show success anyway for security
                    $step = 'sent';
                }
                
            } catch (PDOException $e) {
                error_log("Password reset error: " . $e->getMessage());
                $error = "An error occurred. Please try again later.";
            }
        }
    }
}
?>

<div class="min-h-screen bg-gradient-to-br from-ayuni-aqua/10 via-white to-ayuni-blue/10 dark:from-ayuni-dark dark:via-gray-900 dark:to-ayuni-dark flex items-center justify-center px-4 py-8">
    <div class="w-full max-w-md">
        <!-- Back to Login Link -->
        <div class="text-center mb-6">
            <a href="/login" class="inline-flex items-center text-ayuni-blue hover:text-ayuni-aqua transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                Back to Login
            </a>
        </div>

        <div class="bg-white/80 dark:bg-gray-800/80 backdrop-blur-xl rounded-3xl shadow-2xl border border-white/20 dark:border-white/10 p-8">
            <!-- Header -->
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-2xl mx-auto mb-4 flex items-center justify-center">
                    <i class="fas fa-key text-2xl text-white"></i>
                </div>
                
                <?php if ($step === 'request'): ?>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Forgot Your Password?</h1>
                    <p class="text-gray-600 dark:text-gray-400">No worries! Enter your email and we'll send you a reset link.</p>
                <?php else: ?>
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Check Your Email</h1>
                    <p class="text-gray-600 dark:text-gray-400">We've sent password reset instructions to your email address.</p>
                <?php endif; ?>
            </div>

            <?php if ($step === 'request'): ?>
                <!-- Request Form -->
                <?php if ($error): ?>
                    <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <span><?= htmlspecialchars($error) ?></span>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <div class="relative group">
                        <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 rounded-3xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required
                            class="relative block w-full px-6 py-5 border border-white/20 dark:border-white/10 rounded-3xl bg-white/80 dark:bg-black/40 backdrop-blur-xl text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:border-ayuni-blue/50 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/10 focus:shadow-ayuni-blue/20"
                            placeholder="Enter your email address"
                            value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                        />
                    </div>

                    <button 
                        type="submit" 
                        class="relative w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-5 px-6 rounded-3xl hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/20 hover:scale-105 focus:scale-105 transform"
                    >
                        <i class="fas fa-paper-plane mr-3"></i>
                        Send Reset Link
                    </button>
                </form>

                <div class="text-center mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Remember your password? 
                        <a href="/login" class="text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                            Sign in instead
                        </a>
                    </p>
                </div>

            <?php else: ?>
                <!-- Success Message -->
                <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-6 py-4 rounded-2xl mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle text-xl mr-3"></i>
                        <div>
                            <p class="font-semibold">Email sent successfully!</p>
                            <p class="text-sm mt-1">Check your inbox for password reset instructions.</p>
                        </div>
                    </div>
                </div>

                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 text-amber-700 dark:text-amber-400 px-6 py-4 rounded-2xl mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-clock text-xl mr-3"></i>
                        <div>
                            <p class="font-semibold">Reset link expires in 1 hour</p>
                            <p class="text-sm mt-1">For security, the link will only work for 60 minutes.</p>
                        </div>
                    </div>
                </div>

                <div class="space-y-4">
                    <a 
                        href="/login" 
                        class="block w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-6 rounded-2xl hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-300 text-center shadow-lg hover:shadow-xl"
                    >
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Login
                    </a>
                    
                    <button 
                        onclick="window.location.reload()" 
                        class="block w-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium py-4 px-6 rounded-2xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-center"
                    >
                        <i class="fas fa-redo mr-2"></i>
                        Send Another Email
                    </button>
                </div>

                <div class="text-center mt-6 text-sm text-gray-500 dark:text-gray-400">
                    <p>Didn't receive the email? Check your spam folder or try again.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>