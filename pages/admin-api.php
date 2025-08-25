<?php
requireAdmin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_api_key') {
            $apiKey = sanitizeInput($_POST['api_key'] ?? '');
            
            try {
                // Update or insert the API key
                $stmt = $pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES ('anthropic_api_key', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$apiKey]);
                $success = "Anthropic API key updated successfully.";
            } catch (PDOException $e) {
                error_log("Database error updating API key: " . $e->getMessage());
                $error = "Failed to update API key.";
            }
        }
    }
}

// Get current API key
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'anthropic_api_key'");
    $stmt->execute();
    $apiKeyResult = $stmt->fetch();
    $currentApiKey = $apiKeyResult ? $apiKeyResult['setting_value'] : '';
} catch (PDOException $e) {
    $currentApiKey = '';
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-api'); ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('API Configuration', 'Configure external API keys and settings'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- API Configuration -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Anthropic API Configuration</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Configure the API key for Claude AI integration</p>
            </div>
            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="update_api_key">
                    
                    <div>
                        <label for="api_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-key mr-2 text-ayuni-blue"></i>
                            Anthropic API Key
                        </label>
                        <div class="flex space-x-3">
                            <div class="relative flex-1">
                                <input 
                                    type="password" 
                                    id="api_key" 
                                    name="api_key" 
                                    value="<?= htmlspecialchars($currentApiKey) ?>"
                                    class="w-full px-3 py-2 pr-10 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                                    placeholder="sk-ant-api03-..."
                                />
                                <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" onclick="togglePassword('api_key')">
                                    <i id="api_key_icon" class="fas fa-eye"></i>
                                </button>
                            </div>
                            <button 
                                type="submit" 
                                class="px-6 py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white rounded-lg font-medium hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-colors"
                            >
                                <i class="fas fa-save mr-2"></i>
                                Save
                            </button>
                        </div>
                        
                        <div class="mt-3 space-y-2">
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <i class="fas fa-info-circle mr-1"></i>
                                Required for AI chat functionality. Get your API key from <a href="https://console.anthropic.com/" target="_blank" class="text-ayuni-blue hover:underline">console.anthropic.com</a>
                            </p>
                            <?php if ($currentApiKey): ?>
                                <p class="text-xs text-green-600 dark:text-green-400">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    API key is configured (<?= strlen($currentApiKey) > 0 ? 'Key length: ' . strlen($currentApiKey) . ' characters' : 'No key set' ?>)
                                </p>
                            <?php else: ?>
                                <p class="text-xs text-red-600 dark:text-red-400">
                                    <i class="fas fa-exclamation-triangle mr-1"></i>
                                    No API key configured - AI chat will not work
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- API Usage Information -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">API Usage Information</h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-2">Model Configuration</h4>
                        <ul class="text-sm text-blue-700 dark:text-blue-400 space-y-1">
                            <li><strong>Model:</strong> claude-3-5-sonnet-20241022</li>
                            <li><strong>Max Tokens:</strong> 1000 per response</li>
                            <li><strong>API Version:</strong> 2023-06-01</li>
                            <li><strong>Timeout:</strong> 30 seconds</li>
                        </ul>
                    </div>
                    
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-yellow-800 dark:text-yellow-300 mb-2">Security Notes</h4>
                        <ul class="text-sm text-yellow-700 dark:text-yellow-400 space-y-1">
                            <li>• API keys are stored encrypted in the database</li>
                            <li>• Keys are never logged or exposed in error messages</li>
                            <li>• Only admin users can view or modify API settings</li>
                            <li>• All API requests use HTTPS encryption</li>
                        </ul>
                    </div>
                    
                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-gray-800 dark:text-gray-300 mb-2">Troubleshooting</h4>
                        <ul class="text-sm text-gray-700 dark:text-gray-400 space-y-1">
                            <li>• If chats aren't working, verify the API key is correct</li>
                            <li>• Check browser console for any JavaScript errors</li>
                            <li>• Ensure your Anthropic account has sufficient credits</li>
                            <li>• API errors are logged to the server error log</li>
                        </ul>
                    </div>
                </div>
            </div>
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