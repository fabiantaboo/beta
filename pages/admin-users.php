<?php
requireAdmin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_user') {
            $userId = $_POST['user_id'] ?? '';
            $firstName = sanitizeInput($_POST['first_name'] ?? '');
            $email = sanitizeInput($_POST['email'] ?? '');
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;
            $privacyLevel = $_POST['privacy_level'] ?? 'normal';
            
            if (!empty($userId) && !empty($firstName) && !empty($email)) {
                try {
                    $stmt = $pdo->prepare("UPDATE users SET first_name = ?, email = ?, is_admin = ?, privacy_level = ? WHERE id = ?");
                    $stmt->execute([$firstName, $email, $isAdmin, $privacyLevel, $userId]);
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
    }
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
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-users'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('User Management', 'Manage registered users and their accounts'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- User Management Section -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Registered Users</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Manage user accounts and their beta code usage</p>
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
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-users text-3xl mb-3"></i>
                                    <p>No users registered yet</p>
                                </td>
                            </tr>
                        <?php else: ?>
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
                                                    <?php if (($user['privacy_level'] ?? 'normal') === 'private'): ?>
                                                        <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300">
                                                            <i class="fas fa-eye-slash mr-1"></i>
                                                            Private
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
                                            <button onclick="editUser('<?= $user['id'] ?>', '<?= htmlspecialchars($user['first_name']) ?>', '<?= htmlspecialchars($user['email']) ?>', <?= $user['is_admin'] ? 'true' : 'false' ?>, '<?= $user['privacy_level'] ?? 'normal' ?>')" class="text-ayuni-blue hover:text-ayuni-aqua" title="Edit user">
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
                        <?php endif; ?>
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
                
                <div>
                    <label for="editPrivacyLevel" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Privacy Level</label>
                    <select id="editPrivacyLevel" name="privacy_level" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                        <option value="normal">Normal</option>
                        <option value="private">Private (Chat Analytics excluded)</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Private users' chats won't appear in analytics</p>
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
function editUser(id, firstName, email, isAdmin, privacyLevel) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editFirstName').value = firstName;
    document.getElementById('editEmail').value = email;
    document.getElementById('editIsAdmin').checked = isAdmin;
    document.getElementById('editPrivacyLevel').value = privacyLevel || 'normal';
    document.getElementById('editUserModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editUserModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('editUserModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditModal();
    }
});
</script>