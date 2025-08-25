<?php
requireAdmin();
require_once __DIR__ . '/../includes/mailgun_api.php';

$error = null;
$success = null;

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
                    // Send beta invite email automatically
                    $mailgun = new MailgunAPI();
                    
                    // Build registration URL with pre-filled data
                    $registrationUrl = "https://" . $_SERVER['HTTP_HOST'] . "/register?" . http_build_query([
                        'beta_code' => $code,
                        'first_name' => $firstName,
                        'email' => $email
                    ]);
                    
                    if ($mailgun->sendBetaInviteEmail($email, $code, $firstName, $registrationUrl)) {
                        $success = "Generated beta code '$code' for $firstName ($email) and sent invitation email! 📧";
                    } else {
                        $success = "Generated beta code '$code' for $firstName ($email), but failed to send email. Please check Mailgun configuration.";
                    }
                } else {
                    $success = "Generated beta code '$code' (no pre-filled user data).";
                }
            } catch (PDOException $e) {
                error_log("Database error generating beta code: " . $e->getMessage());
                $error = "Failed to generate beta code: " . $e->getMessage();
            }
        }
        
        if ($action === 'parse_emails') {
            $emailList = $_POST['email_list'] ?? '';
            $parsedEmails = [];
            $invalidEmails = [];
            
            if (!empty($emailList)) {
                $lines = explode("\n", trim($emailList));
                
                foreach ($lines as $lineIndex => $line) {
                    $line = trim($line);
                    if (empty($line)) continue;
                    
                    // Parse line format: "Name email@domain.com" or "Name name2 email@domain.com"
                    $parts = explode(' ', $line);
                    $email = '';
                    $firstName = '';
                    
                    // Find email (contains @)
                    foreach ($parts as $part) {
                        if (strpos($part, '@') !== false) {
                            $email = trim($part);
                            break;
                        }
                    }
                    
                    // Everything before email is name
                    $nameParts = [];
                    foreach ($parts as $part) {
                        if (strpos($part, '@') !== false) break;
                        $nameParts[] = trim($part);
                    }
                    $firstName = implode(' ', $nameParts);
                    
                    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $invalidEmails[] = [
                            'line' => $lineIndex + 1,
                            'original' => $line,
                            'error' => 'Invalid email format'
                        ];
                        continue;
                    }
                    
                    // Check if email already exists
                    $stmt = $pdo->prepare("SELECT code FROM beta_codes WHERE email = ?");
                    $stmt->execute([$email]);
                    $existingCode = $stmt->fetchColumn();
                    
                    $parsedEmails[] = [
                        'firstName' => $firstName,
                        'email' => $email,
                        'original' => $line,
                        'exists' => $existingCode ? $existingCode : false
                    ];
                }
            } else {
                $error = "Please enter email list.";
            }
        }
        
        if ($action === 'confirm_send') {
            $emailsData = $_POST['emails_data'] ?? '';
            if (!empty($emailsData)) {
                $emailsToSend = json_decode($emailsData, true);
                $sentCount = 0;
                $failedCount = 0;
                $failedEmails = [];
                $mailgun = new MailgunAPI();
                
                foreach ($emailsToSend as $emailData) {
                    try {
                        // Generate beta code
                        $code = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
                        
                        // Insert beta code
                        $stmt = $pdo->prepare("INSERT INTO beta_codes (code, first_name, email) VALUES (?, ?, ?)");
                        $stmt->execute([$code, $emailData['firstName'] ?: null, $emailData['email']]);
                        
                        // Build registration URL
                        $registrationUrl = "https://" . $_SERVER['HTTP_HOST'] . "/register?" . http_build_query([
                            'beta_code' => $code,
                            'first_name' => $emailData['firstName'],
                            'email' => $emailData['email']
                        ]);
                        
                        // Send email
                        if ($mailgun->sendBetaInviteEmail($emailData['email'], $code, $emailData['firstName'], $registrationUrl)) {
                            $sentCount++;
                        } else {
                            $failedCount++;
                            $failedEmails[] = "{$emailData['firstName']} ({$emailData['email']}) - Email send failed";
                        }
                        
                        // Small delay to avoid overwhelming the API
                        usleep(100000); // 0.1 seconds
                        
                    } catch (Exception $e) {
                        $failedCount++;
                        $failedEmails[] = "{$emailData['firstName']} ({$emailData['email']}) - " . $e->getMessage();
                    }
                }
                
                $resultMessage = "✅ Sent: $sentCount emails";
                if ($failedCount > 0) {
                    $resultMessage .= " | ❌ Failed: $failedCount";
                    if (!empty($failedEmails)) {
                        $resultMessage .= "\n\nFailed entries:\n" . implode("\n", array_slice($failedEmails, 0, 10));
                        if (count($failedEmails) > 10) {
                            $resultMessage .= "\n... and " . (count($failedEmails) - 10) . " more";
                        }
                    }
                }
                
                if ($sentCount > 0) {
                    $success = $resultMessage;
                } else {
                    $error = $resultMessage;
                }
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

// Get statistics
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as active_codes FROM beta_codes WHERE is_active = TRUE AND used_at IS NULL");
    $stmt->execute();
    $activeCodes = $stmt->fetch()['active_codes'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as used_codes FROM beta_codes WHERE used_at IS NOT NULL");
    $stmt->execute();
    $usedCodes = $stmt->fetch()['used_codes'];
    
    $stmt = $pdo->prepare("SELECT COUNT(*) as total_codes FROM beta_codes");
    $stmt->execute();
    $totalCodes = $stmt->fetch()['total_codes'];
} catch (PDOException $e) {
    $activeCodes = $usedCodes = $totalCodes = 0;
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-beta'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Beta Code Management', 'Generate and manage beta access codes'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- Statistics -->
        <div class="grid md:grid-cols-3 gap-6 mb-8">
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
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue rounded-lg flex items-center justify-center">
                        <i class="fas fa-ticket-alt text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Codes</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $totalCodes ?></p>
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

        <!-- Batch Email Sender -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Batch Email Sender</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Send beta invites to multiple people at once</p>
            </div>
            <div class="p-6">
                <?php if (!isset($parsedEmails) && !isset($invalidEmails)): ?>
                    <!-- Step 1: Input email list -->
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="parse_emails">
                        
                        <div class="mb-4">
                            <label for="email_list" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Email List
                            </label>
                            <textarea 
                                id="email_list" 
                                name="email_list" 
                                rows="15"
                                class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent font-mono text-sm"
                                placeholder="Format: Name email@domain.com (one per line)&#10;Example:&#10;John Doe john@example.com&#10;Jane Smith jane@example.com"
                                required
                            ></textarea>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                Format: <code>Name email@domain.com</code> (one per line). Names can contain spaces.
                            </p>
                        </div>
                        
                        <button 
                            type="submit" 
                            class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white px-6 py-2 rounded-lg font-medium hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-colors"
                        >
                            <i class="fas fa-search mr-2"></i>
                            Parse Email List
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Step 2: Review parsed emails -->
                    <div class="mb-6">
                        <h4 class="text-lg font-medium text-gray-900 dark:text-white mb-4">
                            📋 Parsed Email List - Review Before Sending
                        </h4>
                        
                        <?php if (!empty($parsedEmails)): ?>
                            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4 mb-4">
                                <h5 class="text-green-800 dark:text-green-200 font-medium mb-2">
                                    ✅ Valid Emails (<?= count($parsedEmails) ?>)
                                </h5>
                                <div class="max-h-64 overflow-y-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-green-200 dark:border-green-800">
                                                <th class="text-left py-2">Name</th>
                                                <th class="text-left py-2">Email</th>
                                                <th class="text-left py-2">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($parsedEmails as $email): ?>
                                                <tr class="border-b border-green-100 dark:border-green-800/50">
                                                    <td class="py-1 font-medium"><?= htmlspecialchars($email['firstName']) ?></td>
                                                    <td class="py-1"><?= htmlspecialchars($email['email']) ?></td>
                                                    <td class="py-1">
                                                        <?php if ($email['exists']): ?>
                                                            <span class="text-yellow-600 dark:text-yellow-400">
                                                                ⚠️ Exists (<?= htmlspecialchars($email['exists']) ?>)
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-green-600 dark:text-green-400">✓ New</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($invalidEmails)): ?>
                            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4 mb-4">
                                <h5 class="text-red-800 dark:text-red-200 font-medium mb-2">
                                    ❌ Invalid Emails (<?= count($invalidEmails) ?>)
                                </h5>
                                <div class="max-h-32 overflow-y-auto">
                                    <?php foreach ($invalidEmails as $invalid): ?>
                                        <div class="text-sm text-red-700 dark:text-red-300">
                                            Line <?= $invalid['line'] ?>: <code><?= htmlspecialchars($invalid['original']) ?></code>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div class="flex gap-4">
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="confirm_send">
                                <input type="hidden" name="emails_data" value="<?= htmlspecialchars(json_encode(array_filter($parsedEmails, function($email) { return !$email['exists']; }))) ?>">
                                <button 
                                    type="submit" 
                                    class="bg-gradient-to-r from-green-500 to-green-600 text-white px-6 py-2 rounded-lg font-medium hover:from-green-600 hover:to-green-700 transition-colors"
                                    <?= empty($parsedEmails) || count(array_filter($parsedEmails, function($email) { return !$email['exists']; })) == 0 ? 'disabled' : '' ?>
                                >
                                    <i class="fas fa-paper-plane mr-2"></i>
                                    Send Invites (<?= count(array_filter($parsedEmails, function($email) { return !$email['exists']; })) ?> new)
                                </button>
                            </form>
                            
                            <button 
                                onclick="window.location.reload()" 
                                class="bg-gray-500 text-white px-6 py-2 rounded-lg font-medium hover:bg-gray-600 transition-colors"
                            >
                                <i class="fas fa-arrow-left mr-2"></i>
                                Start Over
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Beta Codes List -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Beta Codes</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Showing last 50 generated codes</p>
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
                        <?php if (empty($betaCodes)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-ticket-alt text-3xl mb-3"></i>
                                    <p>No beta codes generated yet</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($betaCodes as $code): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 text-sm font-mono text-gray-900 dark:text-white">
                                        <?= htmlspecialchars($code['code']) ?>
                                    </td>
                                    <td class="px-6 py-4 text-sm">
                                        <div class="text-gray-900 dark:text-white font-medium">
                                            <?= htmlspecialchars($code['first_name']) ?: 'No name' ?>
                                        </div>
                                        <div class="text-gray-500 dark:text-gray-400 text-xs">
                                            <?= htmlspecialchars($code['email']) ?: 'No email' ?>
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
                                                <button type="submit" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300" onclick="return confirm('Deactivate this beta code?')" title="Deactivate code">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>