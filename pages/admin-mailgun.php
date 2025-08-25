<?php
requireAdmin();
require_once __DIR__ . '/../includes/mailgun_api.php';

$error = null;
$success = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_settings') {
            // Sanitize and validate input
            $apiKey = sanitizeInput($_POST['mailgun_api_key'] ?? '');
            $domain = sanitizeInput($_POST['mailgun_domain'] ?? '');
            $fromEmail = sanitizeInput($_POST['mailgun_from_email'] ?? '');
            $fromName = sanitizeInput($_POST['mailgun_from_name'] ?? '');
            
            // Validate required fields
            if (empty($apiKey)) {
                $error = "Mailgun API Key is required.";
            } elseif (empty($domain)) {
                $error = "Mailgun Domain is required.";
            } elseif (!empty($fromEmail) && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid from email address.";
            } else {
                try {
                    $userId = getUserSession();
                    
                    // Save settings to database
                    $settings = [
                        'mailgun_api_key' => $apiKey,
                        'mailgun_domain' => $domain,
                        'mailgun_from_email' => $fromEmail,
                        'mailgun_from_name' => $fromName
                    ];
                    
                    foreach ($settings as $key => $value) {
                        $stmt = $pdo->prepare("
                            INSERT INTO admin_settings (setting_key, setting_value, setting_category, updated_by) 
                            VALUES (?, ?, 'mailgun', ?)
                            ON DUPLICATE KEY UPDATE 
                            setting_value = VALUES(setting_value), 
                            updated_by = VALUES(updated_by),
                            updated_at = CURRENT_TIMESTAMP
                        ");
                        $stmt->execute([$key, $value, $userId]);
                    }
                    
                    $success = "Mailgun settings saved successfully!";
                    
                } catch (PDOException $e) {
                    error_log("Error saving Mailgun settings: " . $e->getMessage());
                    $error = "Failed to save settings. Please try again.";
                }
            }
        } elseif ($action === 'test_connection') {
            $mailgun = new MailgunAPI();
            $testResult = $mailgun->testConnection();
            
            if ($testResult['success']) {
                $success = "✅ " . $testResult['message'];
            } else {
                $error = "❌ " . $testResult['error'];
            }
        } elseif ($action === 'send_test_email') {
            $testEmail = sanitizeInput($_POST['test_email'] ?? '');
            
            if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                $error = "Please enter a valid test email address.";
            } else {
                $mailgun = new MailgunAPI();
                $testUrl = "https://" . $_SERVER['HTTP_HOST'] . "/reset-password?token=TEST_TOKEN_123456";
                
                if ($mailgun->sendPasswordResetEmail($testEmail, $testUrl, 'Test User')) {
                    $success = "✅ Test email sent successfully to $testEmail";
                } else {
                    $error = "❌ Failed to send test email. Check your settings and try again.";
                }
            }
        }
    }
}

// Load current settings
$currentSettings = [];
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM admin_settings WHERE setting_category = 'mailgun'");
    $stmt->execute();
    $currentSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    error_log("Error loading Mailgun settings: " . $e->getMessage());
}

$mailgun = new MailgunAPI();
$isConfigured = $mailgun->isConfigured();
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php 
    include_once __DIR__ . '/../includes/header.php';
    renderHeader([
        'show_back_button' => true,
        'back_url' => '/admin'
    ]);
    ?>

    <div class="max-w-4xl mx-auto px-4 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full mx-auto mb-4 flex items-center justify-center">
                <i class="fas fa-envelope text-2xl text-white"></i>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white mb-2">Mailgun Configuration</h1>
            <p class="text-gray-600 dark:text-gray-400">Configure email settings for password resets and notifications</p>
        </div>

        <!-- Status Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Configuration Status -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-semibold text-gray-900 dark:text-white">Configuration Status</h3>
                    <?php if ($isConfigured): ?>
                        <span class="bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-300 text-xs font-medium px-2.5 py-0.5 rounded-full">
                            ✅ Configured
                        </span>
                    <?php else: ?>
                        <span class="bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-300 text-xs font-medium px-2.5 py-0.5 rounded-full">
                            ❌ Not Configured
                        </span>
                    <?php endif; ?>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    <?php if ($isConfigured): ?>
                        Mailgun is properly configured and ready to send emails.
                    <?php else: ?>
                        Please configure your Mailgun API credentials below to enable email functionality.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                <h3 class="font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h3>
                <div class="space-y-3">
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="test_connection">
                        <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white text-sm font-medium py-2 px-4 rounded-lg transition-colors" <?= !$isConfigured ? 'disabled' : '' ?>>
                            <i class="fas fa-plug mr-2"></i>Test Connection
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($error): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
            </div>
        <?php endif; ?>

        <!-- Mailgun Settings Form -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-8">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">
                <i class="fas fa-cog mr-2 text-purple-500"></i>
                Mailgun API Settings
            </h2>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="save_settings">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- API Key -->
                    <div>
                        <label for="mailgun_api_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-key mr-2 text-purple-500"></i>
                            Mailgun API Key *
                        </label>
                        <div class="relative">
                            <input 
                                type="password" 
                                id="mailgun_api_key" 
                                name="mailgun_api_key" 
                                value="<?= htmlspecialchars($currentSettings['mailgun_api_key'] ?? '') ?>"
                                class="w-full px-3 py-2 pr-10 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                                placeholder="key-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                required
                            />
                            <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('mailgun_api_key')">
                                <i id="mailgun_api_key_icon" class="fas fa-eye"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Your Mailgun private API key from your Mailgun dashboard
                        </p>
                    </div>

                    <!-- Domain -->
                    <div>
                        <label for="mailgun_domain" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-globe mr-2 text-purple-500"></i>
                            Mailgun Domain *
                        </label>
                        <input 
                            type="text" 
                            id="mailgun_domain" 
                            name="mailgun_domain" 
                            value="<?= htmlspecialchars($currentSettings['mailgun_domain'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                            placeholder="mg.yourdomain.com"
                            required
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Your verified Mailgun sending domain
                        </p>
                    </div>

                    <!-- From Email -->
                    <div>
                        <label for="mailgun_from_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-at mr-2 text-purple-500"></i>
                            From Email Address
                        </label>
                        <input 
                            type="email" 
                            id="mailgun_from_email" 
                            name="mailgun_from_email" 
                            value="<?= htmlspecialchars($currentSettings['mailgun_from_email'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                            placeholder="noreply@yourdomain.com"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Email address that appears in the "From" field
                        </p>
                    </div>

                    <!-- From Name -->
                    <div>
                        <label for="mailgun_from_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-user mr-2 text-purple-500"></i>
                            From Name
                        </label>
                        <input 
                            type="text" 
                            id="mailgun_from_name" 
                            name="mailgun_from_name" 
                            value="<?= htmlspecialchars($currentSettings['mailgun_from_name'] ?? '') ?>"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                            placeholder="Ayuni Beta"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            Name that appears in the "From" field
                        </p>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button 
                        type="submit" 
                        class="bg-gradient-to-r from-purple-500 to-pink-500 text-white font-semibold py-3 px-6 rounded-lg hover:from-purple-600 hover:to-pink-600 transition-colors"
                    >
                        <i class="fas fa-save mr-2"></i>
                        Save Settings
                    </button>
                </div>
            </form>
        </div>

        <!-- Test Email Section -->
        <?php if ($isConfigured): ?>
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">
                <i class="fas fa-paper-plane mr-2 text-blue-500"></i>
                Send Test Email
            </h2>

            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="send_test_email">

                <div class="flex space-x-4">
                    <div class="flex-1">
                        <input 
                            type="email" 
                            id="test_email" 
                            name="test_email" 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                            placeholder="test@example.com"
                            required
                        />
                    </div>
                    <button 
                        type="submit" 
                        class="bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-6 rounded-lg transition-colors"
                    >
                        <i class="fas fa-paper-plane mr-2"></i>
                        Send Test
                    </button>
                </div>
                
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    This will send a sample password reset email to the specified address
                </p>
            </form>
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