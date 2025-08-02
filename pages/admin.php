<?php
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'generate_codes') {
            $firstName = sanitizeInput($_POST['first_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            
            try {
                $code = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
                
                // Insert with optional name and email
                $stmt = $pdo->prepare("INSERT INTO beta_codes (code, first_name, email) VALUES (?, ?, ?)");
                $stmt->execute([$code, $firstName ?: null, $email ?: null]);
                
                if ($firstName && $email) {
                    $success = "Generated beta code '$code' for $firstName ($email).";
                } else {
                    $success = "Generated beta code '$code' (no pre-filled user data).";
                }
            } catch (PDOException $e) {
                error_log("Database error generating beta code: " . $e->getMessage());
                $error = "Failed to generate beta code: " . $e->getMessage();
            }
        }
        
        if ($action === 'deactivate_code') {
            $code = $_POST['code'] ?? '';
            if (!empty($code)) {
                try {
                    $stmt = $pdo->prepare("UPDATE beta_codes SET is_active = FALSE WHERE code = ?");
                    $stmt->execute([$code]);
                    $success = "Beta code deactivated successfully.";
                } catch (PDOException $e) {
                    error_log("Database error deactivating beta code: " . $e->getMessage());
                    $error = "Failed to deactivate beta code.";
                }
            }
        }
        
        if ($action === 'update_user') {
            $userId = $_POST['user_id'] ?? '';
            $firstName = sanitizeInput($_POST['first_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            
            if (!empty($userId) && !empty($firstName) && !empty($email)) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, email = ?, is_admin = ? WHERE id = ?");
                    $stmt->execute([$firstName, $email, $isAdmin, $userId]);
                    $success = "User updated successfully.";
                } catch (PDOException $e) {
                    error_log("Database error updating user: " . $e->getMessage());
                    $error = "Failed to update user.";
                }
            }
        }
        
        if ($action === 'delete_user') {
            $userId = $_POST['user_id'] ?? '';
            if (!empty($userId)) {
                try {
                    $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND is_admin = FALSE");
                    $stmt->execute([$userId]);
                    $success = "User deleted successfully.";
                } catch (PDOException $e) {
                    error_log("Database error deleting user: " . $e->getMessage());
                    $error = "Failed to delete user.";
                }
            }
        }
        
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

// Get beta codes
try {
    $stmt = $pdo->prepare("SELECT * FROM beta_codes ORDER BY created_at DESC LIMIT 50");
    $stmt->execute();
    $betaCodes = $stmt->fetchAll();
} catch (PDOException $e) {
    $betaCodes = [];
}

// Get users with their beta codes
try {
    $stmt = $pdo->prepare("
        SELECT u.*, bc.code as beta_code, bc.used_at as code_used_at 
        FROM users u 
        LEFT JOIN beta_codes bc ON u.beta_code = bc.code 
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    $users = [];
}

// Get user statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_users FROM users");
    $stmt->execute();
    $totalUsers = $stmt->fetch()['total_users'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_codes FROM beta_codes WHERE is_active = TRUE AND used_at IS NULL");
    $stmt->execute();
    $activeCodes = $stmt->fetch()['active_codes'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as used_codes FROM beta_codes WHERE used_at IS NOT NULL");
    $stmt->execute();
    $usedCodes = $stmt->fetch()['used_codes'];
} catch (PDOException $e) {
    $totalUsers = $activeCodes = $usedCodes = 0;
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
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <img src="assets/ayuni.png" alt="Ayuni Logo" class="h-10 w-auto">
                    <span class="text-sm font-medium text-gray-600 dark:text-gray-400">Admin Panel</span>
                </div>
                <div class="flex items-center space-x-4">
                    <button 
                        id="theme-toggle" 
                        onclick="toggleTheme()" 
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
                        title="Toggle theme"
                    >
                        <i class="fas fa-sun sun-icon text-lg"></i>
                        <i class="fas fa-moon moon-icon text-lg"></i>
                    </button>
                    <a href="/dashboard" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>
                        Back to Dashboard
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-8">
            <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Admin Panel</h2>
            <p class="text-gray-600 dark:text-gray-400">Manage beta codes and monitor system usage</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($success)): ?>
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="grid md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Users</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $totalUsers ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-key text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Codes</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $activeCodes ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-gray-500 to-gray-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Used Codes</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $usedCodes ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- API Configuration -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">API Configuration</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Configure Anthropic API for AI chat functionality</p>
            </div>
            <div class="p-6">
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="update_api_key">
                    
                    <div>
                        <label for="api_key" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <i class="fas fa-key mr-2 text-ayuni-blue"></i>
                            Anthropic API Key
                        </label>
                        <div class="flex space-x-3">
                            <input 
                                type="password" 
                                id="api_key" 
                                name="api_key" 
                                value="<?= htmlspecialchars($currentApiKey) ?>"
                                class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                                placeholder="sk-ant-api03-..."
                            />
                            <button 
                                type="button" 
                                onclick="toggleApiKeyVisibility()"
                                class="px-3 py-2 bg-gray-100 dark:bg-gray-600 text-gray-600 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors"
                                title="Toggle visibility"
                            >
                                <i class="fas fa-eye" id="eye-icon"></i>
                            </button>
                            <button 
                                type="submit" 
                                class="px-6 py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white rounded-lg font-medium hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-colors"
                            >
                                <i class="fas fa-save mr-2"></i>
                                Save
                            </button>
                        </div>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>
                            Required for AI chat functionality. Get your API key from <a href="https://console.anthropic.com/" target="_blank" class="text-ayuni-blue hover:underline">console.anthropic.com</a>
                        </p>
                        <?php if ($currentApiKey): ?>
                            <p class="text-xs text-green-600 dark:text-green-400 mt-1">
                                <i class="fas fa-check-circle mr-1"></i>
                                API key is configured (<?= strlen($currentApiKey) > 0 ? 'Key length: ' . strlen($currentApiKey) . ' characters' : 'No key set' ?>)
                            </p>
                        <?php else: ?>
                            <p class="text-xs text-red-600 dark:text-red-400 mt-1">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                No API key configured - AI chat will not work
                            </p>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Generate Beta Code -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Generate Beta Code</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Create a beta code with optional pre-filled user information</p>
            </div>
            <div class="p-6">
                <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4 items-end">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="generate_codes">
                    
                    <div>
                        <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            First Name <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <input 
                            type="text" 
                            id="first_name" 
                            name="first_name" 
                            maxlength="100"
                            class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                            placeholder="First name (optional)"
                        />
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Email Address <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                            placeholder="email@example.com (optional)"
                        />
                    </div>
                    
                    <div>
                        <button 
                            type="submit" 
                            class="w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white px-6 py-2 rounded-lg font-medium hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-colors"
                        >
                            <i class="fas fa-plus mr-2"></i>
                            Generate Code
                        </button>
                    </div>
                </form>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                    <i class="fas fa-info-circle mr-1"></i>
                    If name and email are provided, they will be pre-filled during account creation
                </p>
            </div>
        </div>

        <!-- Beta Codes List -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Beta Codes</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User Info</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Created</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Used</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($betaCodes as $code): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4 text-sm font-mono text-gray-900 dark:text-white">
                                    <?= htmlspecialchars($code['code']) ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="text-gray-900 dark:text-white font-medium">
                                        <?= htmlspecialchars($code['first_name']) ?>
                                    </div>
                                    <div class="text-gray-500 dark:text-gray-400 text-xs">
                                        <?= htmlspecialchars($code['email']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <?php if (!$code['is_active']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400">
                                            <i class="fas fa-times-circle mr-1"></i>
                                            Inactive
                                        </span>
                                    <?php elseif ($code['used_at']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-800 text-gray-800 dark:text-gray-400">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Used
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400">
                                            <i class="fas fa-circle mr-1"></i>
                                            Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <?= date('M j, Y H:i', strtotime($code['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <?= $code['used_at'] ? date('M j, Y H:i', strtotime($code['used_at'])) : '-' ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <?php if ($code['is_active'] && !$code['used_at']): ?>
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                            <input type="hidden" name="action" value="deactivate_code">
                                            <input type="hidden" name="code" value="<?= htmlspecialchars($code['code']) ?>">
                                            <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300" onclick="return confirm('Deactivate this beta code?')">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- User Management Section -->
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">User Management</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Manage registered users and their beta code usage</p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Beta Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Registered</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Last Active</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-3">
                                            <span class="text-white font-bold text-sm">
                                                <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                                            </span>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?= htmlspecialchars($user['first_name']) ?>
                                                <?php if ($user['is_admin']): ?>
                                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-100 dark:bg-purple-900/20 text-purple-800 dark:text-purple-300">
                                                        Admin
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                <?= htmlspecialchars($user['email']) ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <?php if ($user['beta_code']): ?>
                                        <span class="font-mono text-sm bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">
                                            <?= htmlspecialchars($user['beta_code']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-gray-400 dark:text-gray-500">No code</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($user['is_onboarded']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400">
                                            <i class="fas fa-check-circle mr-1"></i>
                                            Complete
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400">
                                            <i class="fas fa-clock mr-1"></i>
                                            Pending
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <?= date('M j, Y', strtotime($user['last_active'])) ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="flex space-x-2">
                                        <button onclick="editUser('<?= $user['id'] ?>', '<?= htmlspecialchars($user['first_name']) ?>', '<?= htmlspecialchars($user['email']) ?>', <?= $user['is_admin'] ? 'true' : 'false' ?>)" class="text-ayuni-blue hover:text-ayuni-aqua" title="Edit user">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if (!$user['is_admin']): ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300" title="Delete user" onclick="return confirm('Delete this user? This action cannot be undone.')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-md mx-4">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Edit User</h3>
        <form method="POST" id="editUserForm">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="update_user">
            <input type="hidden" name="user_id" id="editUserId">
            
            <div class="space-y-4">
                <div>
                    <label for="editFirstName" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">First Name</label>
                    <input type="text" id="editFirstName" name="first_name" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                </div>
                
                <div>
                    <label for="editEmail" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email</label>
                    <input type="email" id="editEmail" name="email" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                </div>
                
                <div>
                    <label class="flex items-center">
                        <input type="checkbox" id="editIsAdmin" name="is_admin" class="rounded border-gray-300 dark:border-gray-600 text-ayuni-blue focus:ring-ayuni-blue focus:ring-offset-0">
                        <span class="ml-2 text-sm text-gray-700 dark:text-gray-300">Admin privileges</span>
                    </label>
                </div>
            </div>
            
            <div class="flex space-x-3 mt-6">
                <button type="button" onclick="closeEditModal()" class="flex-1 px-4 py-2 bg-gray-300 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-400 dark:hover:bg-gray-500 transition-colors">
                    Cancel
                </button>
                <button type="submit" class="flex-1 px-4 py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all">
                    Update User
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function editUser(id, firstName, email, isAdmin) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editFirstName').value = firstName;
    document.getElementById('editEmail').value = email;
    document.getElementById('editIsAdmin').checked = isAdmin;
    document.getElementById('editUserModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editUserModal').classList.add('hidden');
}

function toggleApiKeyVisibility() {
    const input = document.getElementById('api_key');
    const icon = document.getElementById('eye-icon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

// Close modal when clicking outside
document.getElementById('editUserModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>