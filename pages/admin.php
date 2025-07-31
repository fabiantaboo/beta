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
                    <a href="?page=dashboard" class="text-gray-700 dark:text-gray-300 hover:text-ayuni-blue font-medium transition-colors">
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
    </div>
</div>