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

renderAdminLayout('Replicate API Settings', function() use ($currentSettings, $success, $error) {
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
        <div class="flex items-center space-x-3 mb-4">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900/20 rounded-lg flex items-center justify-center">
                <i class="fas fa-robot text-purple-600 dark:text-purple-400"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Replicate AI Avatar Generation</h1>
                <p class="text-gray-600 dark:text-gray-400">Configure Replicate API for automatic AEI avatar generation</p>
            </div>
        </div>
        
        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
            <div class="flex items-start space-x-3">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-0.5"></i>
                <div class="text-sm text-blue-800 dark:text-blue-300">
                    <p class="font-medium mb-1">About Replicate Avatar Generation</p>
                    <p>This feature uses Replicate's black-forest-labs/flux-dev model to automatically generate unique avatars for AEIs based on their appearance settings. Get your API token from <a href="https://replicate.com/account/api-tokens" target="_blank" class="underline">replicate.com/account/api-tokens</a>.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($success) ?>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($error) ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- API Configuration -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">API Configuration</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Configure your Replicate API token</p>
        </div>
        
        <form method="POST" class="p-6 space-y-6">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="save_settings">
            
            <div>
                <label for="replicate_token" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    Replicate API Token *
                </label>
                <div class="relative">
                    <input 
                        type="password" 
                        id="replicate_token" 
                        name="replicate_token"
                        value="<?= htmlspecialchars($currentSettings['replicate_token'] ?? '') ?>"
                        class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent pr-12"
                        placeholder="r8_xxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                        required
                    >
                    <button 
                        type="button" 
                        onclick="togglePasswordVisibility('replicate_token')"
                        class="absolute inset-y-0 right-0 flex items-center pr-3 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                    >
                        <i class="fas fa-eye" id="replicate_token_eye"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    Get your API token from <a href="https://replicate.com/account/api-tokens" target="_blank" class="text-ayuni-blue hover:underline">replicate.com/account/api-tokens</a>
                </p>
            </div>
            
            <div class="flex space-x-4">
                <button 
                    type="submit"
                    class="bg-ayuni-blue text-white font-semibold py-2 px-6 rounded-lg hover:bg-ayuni-blue/90 transition-colors"
                >
                    <i class="fas fa-save mr-2"></i>
                    Save Settings
                </button>
            </div>
        </form>
    </div>

    <!-- API Testing -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">API Testing</h2>
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
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Avatar Generation Process</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">How automatic avatar generation works</p>
        </div>
        
        <div class="p-6 space-y-4">
            <div class="space-y-3">
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
            
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
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

<?php
});
?>