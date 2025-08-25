<?php
// Redirect if already logged in
if (isLoggedIn()) {
    redirectTo('dashboard');
}

$error = null;
$success = null;
$token = $_GET['token'] ?? '';
$validToken = false;
$user = null;

// Validate token
if (!empty($token)) {
    try {
        // Clean up expired tokens first
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE expires_at < NOW()");
        $stmt->execute();
        
        // Check if token exists and is valid
        $stmt = $pdo->prepare("
            SELECT pr.*, u.first_name, u.email 
            FROM password_resets pr
            JOIN users u ON pr.user_id = u.id
            WHERE pr.token = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $resetData = $stmt->fetch();
        
        if ($resetData) {
            $validToken = true;
            $user = $resetData;
        }
    } catch (PDOException $e) {
        error_log("Token validation error: " . $e->getMessage());
    }
}

// Handle password reset submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $newPassword = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($newPassword)) {
            $error = "Password is required.";
        } elseif (strlen($newPassword) < 6) {
            $error = "Password must be at least 6 characters long.";
        } elseif ($newPassword !== $confirmPassword) {
            $error = "Passwords do not match.";
        } else {
            try {
                // Update user password
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $user['user_id']]);
                
                // Mark reset token as used
                $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?");
                $stmt->execute([$token]);
                
                // Clean up all other reset tokens for this user
                $stmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND token != ?");
                $stmt->execute([$user['user_id'], $token]);
                
                $success = true;
                
                // Log password reset
                error_log("Password reset successful for user ID: " . $user['user_id']);
                
            } catch (PDOException $e) {
                error_log("Password reset update error: " . $e->getMessage());
                $error = "Failed to update password. Please try again.";
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
            
            <?php if (!$validToken): ?>
                <!-- Invalid Token -->
                <div class="text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-red-500 to-red-600 rounded-2xl mx-auto mb-6 flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-2xl text-white"></i>
                    </div>
                    
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Invalid Reset Link</h1>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">
                        This password reset link is invalid or has expired. Reset links are only valid for 1 hour.
                    </p>
                    
                    <div class="space-y-4">
                        <a 
                            href="/forgot-password" 
                            class="block w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-6 rounded-2xl hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-300 text-center shadow-lg hover:shadow-xl"
                        >
                            <i class="fas fa-paper-plane mr-2"></i>
                            Request New Reset Link
                        </a>
                        
                        <a 
                            href="/login" 
                            class="block w-full bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium py-4 px-6 rounded-2xl hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-center"
                        >
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Back to Login
                        </a>
                    </div>
                </div>

            <?php elseif ($success): ?>
                <!-- Success -->
                <div class="text-center">
                    <div class="w-16 h-16 bg-gradient-to-br from-green-500 to-green-600 rounded-2xl mx-auto mb-6 flex items-center justify-center">
                        <i class="fas fa-check text-2xl text-white"></i>
                    </div>
                    
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Password Reset Successful!</h1>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">
                        Your password has been successfully updated. You can now sign in with your new password.
                    </p>
                    
                    <a 
                        href="/login" 
                        class="block w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-6 rounded-2xl hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-300 text-center shadow-lg hover:shadow-xl"
                    >
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        Sign In Now
                    </a>
                </div>

            <?php else: ?>
                <!-- Reset Form -->
                <div class="text-center mb-8">
                    <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-2xl mx-auto mb-4 flex items-center justify-center">
                        <i class="fas fa-lock text-2xl text-white"></i>
                    </div>
                    
                    <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Reset Your Password</h1>
                    <p class="text-gray-600 dark:text-gray-400">
                        Hi <?= htmlspecialchars($user['first_name']) ?>! Enter your new password below.
                    </p>
                </div>

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
                    
                    <!-- New Password -->
                    <div class="relative group">
                        <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 rounded-3xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                        <div class="relative">
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                required
                                minlength="6"
                                class="block w-full px-6 py-5 pr-14 border border-white/20 dark:border-white/10 rounded-3xl bg-white/80 dark:bg-black/40 backdrop-blur-xl text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:border-ayuni-blue/50 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/10 focus:shadow-ayuni-blue/20"
                                placeholder="New Password (min. 6 characters)"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('password')">
                                <i id="password_icon" class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Confirm Password -->
                    <div class="relative group">
                        <div class="absolute -inset-1 bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 rounded-3xl blur opacity-0 group-hover:opacity-100 transition-opacity duration-500"></div>
                        <div class="relative">
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                required
                                minlength="6"
                                class="block w-full px-6 py-5 pr-14 border border-white/20 dark:border-white/10 rounded-3xl bg-white/80 dark:bg-black/40 backdrop-blur-xl text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue/50 focus:border-ayuni-blue/50 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/10 focus:shadow-ayuni-blue/20"
                                placeholder="Confirm New Password"
                            />
                            <button type="button" class="absolute inset-y-0 right-0 pr-4 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('confirm_password')">
                                <i id="confirm_password_icon" class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button 
                        type="submit" 
                        class="relative w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-5 px-6 rounded-3xl hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-500 text-lg shadow-2xl hover:shadow-ayuni-blue/20 hover:scale-105 focus:scale-105 transform"
                    >
                        <i class="fas fa-key mr-3"></i>
                        Reset Password
                    </button>
                </form>

                <div class="text-center mt-8 pt-6 border-t border-gray-200 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Changed your mind? 
                        <a href="/login" class="text-ayuni-blue hover:text-ayuni-aqua font-semibold transition-colors">
                            Back to Login
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
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