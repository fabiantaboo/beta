<?php
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_settings') {
            $replicateToken = sanitizeInput($_POST['replicate_token'] ?? '');
            
            try {
                // Save or update Replicate API token
                $stmt = $pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES ('replicate_api_token', ?) 
                                     ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$replicateToken, $replicateToken]);
                
                $success = "Replicate API settings saved successfully.";
            } catch (PDOException $e) {
                error_log("Error saving Replicate settings: " . $e->getMessage());
                $error = "Failed to save settings. Please try again.";
            }
        } elseif ($action === 'test_api') {
            try {
                require_once __DIR__ . '/../includes/replicate_api.php';
                $replicateAPI = new ReplicateAPI();
                
                // Test with a simple prompt
                $testPrompt = "Portrait of a friendly person, high quality, professional lighting";
                $prediction = $replicateAPI->generateAvatar($testPrompt, '1:1', 3, 1);
                
                if (isset($prediction['id'])) {
                    $success = "API test successful! Prediction ID: " . $prediction['id'];
                } else {
                    $error = "API test failed: Invalid response";
                }
            } catch (Exception $e) {
                $error = "API test failed: " . $e->getMessage();
            }
        }
    }
}

// Load current settings
$currentSettings = [];
try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM api_settings WHERE setting_key = 'replicate_api_token'");
    $stmt->execute();
    $result = $stmt->fetch();
    if ($result) {
        $currentSettings['replicate_token'] = $result['setting_value'];
    }
} catch (PDOException $e) {
    error_log("Error loading Replicate settings: " . $e->getMessage());
}

?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-replicate'); ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Replicate AI Avatar Generation', 'Configure Replicate API for automatic AEI avatar generation'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- Info Section -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                    <i class="fas fa-robot text-purple-600 dark:text-purple-400 mr-2"></i>
                    About Avatar Generation
                </h3>
            </div>
            <div class="p-6">
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <div class="flex items-start space-x-3">
                        <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-0.5"></i>
                        <div class="text-sm text-blue-800 dark:text-blue-300">
                            <p class="font-medium mb-1">Replicate Avatar Generation</p>
                            <p>This feature uses Replicate's black-forest-labs/flux-dev model to automatically generate unique avatars for AEIs based on their appearance settings. Get your API token from <a href="https://replicate.com/account/api-tokens" target="_blank" class="underline hover:text-blue-600">replicate.com/account/api-tokens</a>.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- API Configuration -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">API Configuration</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Configure your Replicate API token</p>
            </div>
            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div>
                        <label for="replicate_token" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-key mr-2 text-ayuni-blue"></i>
                            Replicate API Token
                        </label>
                        <div class="flex space-x-3">
                            <input 
                                type="password" 
                                id="replicate_token" 
                                name="replicate_token"
                                value="<?= htmlspecialchars($currentSettings['replicate_token'] ?? '') ?>"
                                class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                                placeholder="r8_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                            />
                            <button 
                                type="button" 
                                onclick="togglePasswordVisibility('replicate_token')"
                                class="px-3 py-2 bg-gray-100 dark:bg-gray-600 text-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors"
                                title="Toggle visibility"
                            >
                                <i class="fas fa-eye" id="replicate_token_eye"></i>
                            </button>
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
                                Get your API token from <a href="https://replicate.com/account/api-tokens" target="_blank" class="text-ayuni-blue hover:underline">replicate.com/account/api-tokens</a>
                            </p>
                            <?php if (!empty($currentSettings['replicate_token'])): ?>
                                <p class="text-xs text-green-600 dark:text-green-400">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    API token is configured (<?= strlen($currentSettings['replicate_token']) ?> characters)
                                </p>
                            <?php else: ?>
                                <p class="text-xs text-red-600 dark:text-red-400">
                                    <i class="fas fa-exclamation-circle mr-1"></i>
                                    No API token configured
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- API Testing -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">API Testing</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Test your Replicate API connection</p>
            </div>
            <div class="p-6">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="test_api">
                    
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        This will send a test request to Replicate to verify your API token is working correctly.
                        <strong>Note:</strong> This will consume credits from your Replicate account.
                    </p>
                    
                    <button 
                        type="submit"
                        class="bg-purple-600 text-white font-semibold py-2 px-6 rounded-lg hover:bg-purple-700 transition-colors"
                    >
                        <i class="fas fa-flask mr-2"></i>
                        Test API Connection
                    </button>
                </form>
            </div>
        </div>

        <!-- Avatar Generation Info -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Avatar Generation Process</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">How automatic avatar generation works</p>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="flex items-start space-x-3">
                        <div class="w-6 h-6 bg-ayuni-blue text-white rounded-full flex items-center justify-center text-xs font-bold mt-0.5">1</div>
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Appearance Analysis</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">When a user creates an AEI, the system analyzes their appearance choices (hair color, eye color, build, style, etc.)</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-3">
                        <div class="w-6 h-6 bg-ayuni-blue text-white rounded-full flex items-center justify-center text-xs font-bold mt-0.5">2</div>
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Prompt Generation</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">A detailed prompt is automatically generated based on the appearance settings and AEI name</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-3">
                        <div class="w-6 h-6 bg-ayuni-blue text-white rounded-full flex items-center justify-center text-xs font-bold mt-0.5">3</div>
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Image Generation</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">The prompt is sent to Replicate's flux-dev model to generate a high-quality portrait</p>
                        </div>
                    </div>
                    
                    <div class="flex items-start space-x-3">
                        <div class="w-6 h-6 bg-ayuni-blue text-white rounded-full flex items-center justify-center text-xs font-bold mt-0.5">4</div>
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Avatar Storage</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">The generated image is downloaded and stored locally, then linked to the AEI profile</p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mt-6">
                    <div class="flex items-start space-x-3">
                        <i class="fas fa-exclamation-triangle text-yellow-600 dark:text-yellow-400 mt-0.5"></i>
                        <div class="text-sm text-yellow-800 dark:text-yellow-300">
                            <p class="font-medium mb-1">Cost Consideration</p>
                            <p>Each avatar generation consumes credits from your Replicate account. The flux-dev model typically costs around $0.003-0.01 per image depending on settings.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function togglePasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    const eye = document.getElementById(inputId + '_eye');
    
    if (input.type === 'password') {
        input.type = 'text';
        eye.className = 'fas fa-eye-slash';
    } else {
        input.type = 'password';
        eye.className = 'fas fa-eye';
    }
}
</script>