<?php
requireOnboarding();

$userId = getUserSession();
if (!$userId) {
    redirectTo('home');
}

// Handle AEI deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_aei') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $aeiId = sanitizeInput($_POST['aei_id'] ?? '');
        $confirmDelete = sanitizeInput($_POST['confirm_delete'] ?? '');
        
        if (empty($aeiId) || $confirmDelete !== 'DELETE') {
            $error = "Invalid deletion request.";
        } else {
            try {
                // Verify AEI belongs to user
                $stmt = $pdo->prepare("SELECT name FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
                $stmt->execute([$aeiId, $userId]);
                $aei = $stmt->fetch();
                
                if (!$aei) {
                    $error = "AEI not found or already deleted.";
                } else {
                    $pdo->beginTransaction();
                    
                    // Soft delete the AEI
                    $stmt = $pdo->prepare("UPDATE aeis SET is_active = FALSE WHERE id = ? AND user_id = ?");
                    $stmt->execute([$aeiId, $userId]);
                    
                    $pdo->commit();
                    $success = "AEI '{$aei['name']}' has been permanently deleted.";
                }
            } catch (PDOException $e) {
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } catch (PDOException $rollbackException) {
                    error_log("Rollback failed: " . $rollbackException->getMessage());
                }
                error_log("Database error deleting AEI: " . $e->getMessage());
                $error = "An error occurred while deleting the AEI. Please try again.";
            }
        }
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $aeis = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Database error fetching AEIs: " . $e->getMessage());
    $aeis = [];
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php 
    include_once __DIR__ . '/../includes/header.php';
    renderHeader([
        'show_create_aei' => true
    ]);
    ?>

    <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 py-6 sm:py-8">
        <!-- Success/Error Messages -->
        <?php if (isset($success)): ?>
            <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($success) ?>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (isset($error)): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>
        <?php if (empty($aeis)): ?>
            <div class="text-center py-16">
                <div class="mb-8">
                    <div class="w-24 h-24 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-6 flex items-center justify-center">
                        <i class="fas fa-robot text-3xl text-white"></i>
                    </div>
                    <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-4">Create Your First AEI</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-lg max-w-md mx-auto">
                        Start your journey by creating your first Artificial Emotional Intelligence companion
                    </p>
                </div>
                <a href="/create-aei" class="inline-flex items-center bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl">
                    <i class="fas fa-plus mr-2"></i>
                    Create Your First AEI
                </a>
            </div>
        <?php else: ?>
            <div class="mb-8">
                <h2 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Your AEIs</h2>
                <p class="text-gray-600 dark:text-gray-400">Your digital companions are ready to chat</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($aeis as $aei): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm hover:shadow-md hover:border-ayuni-blue/50 dark:hover:border-ayuni-aqua/50 transition-all duration-300">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-4">
                                    <span class="text-xl text-white font-bold">
                                        <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                                    </span>
                                </div>
                                <div>
                                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">Created <?= date('M j, Y', strtotime($aei['created_at'])) ?></p>
                                </div>
                            </div>
                            
                            <!-- Dropdown Menu -->
                            <div class="relative" data-dropdown>
                                <button 
                                    onclick="toggleDropdown(this)"
                                    class="p-2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700"
                                    aria-label="Options"
                                >
                                    <i class="fas fa-ellipsis-v"></i>
                                </button>
                                
                                <div class="absolute right-0 top-full mt-1 w-48 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-10 hidden">
                                    <div class="py-1">
                                        <a 
                                            href="/chat/<?= urlencode($aei['id']) ?>" 
                                            class="flex items-center px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
                                        >
                                            <i class="fas fa-comments mr-3 text-ayuni-blue"></i>
                                            Start Chat
                                        </a>
                                        <button 
                                            onclick="showDeleteModal('<?= htmlspecialchars($aei['id']) ?>', '<?= htmlspecialchars($aei['name']) ?>')"
                                            class="flex items-center w-full px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                                        >
                                            <i class="fas fa-trash mr-3"></i>
                                            Delete AEI
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($aei['personality']): ?>
                            <p class="text-gray-600 dark:text-gray-300 mb-4 line-clamp-3"><?= htmlspecialchars(substr($aei['personality'], 0, 120)) ?>...</p>
                        <?php endif; ?>
                        
                        <a href="/chat/<?= urlencode($aei['id']) ?>" class="block w-full bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-4 rounded-lg text-center hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md">
                            <i class="fas fa-comments mr-2"></i>
                            Start Conversation
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
        <!-- Modal Header -->
        <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-red-100 dark:bg-red-900/20 rounded-full flex items-center justify-center">
                    <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Delete AEI</h3>
            </div>
            <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>

        <!-- Modal Content -->
        <div class="p-6 space-y-4">
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <h4 class="font-semibold text-red-800 dark:text-red-300 mb-2">⚠️ This action cannot be undone!</h4>
                <p class="text-red-700 dark:text-red-400 text-sm">
                    Deleting "<span id="modalAeiName" class="font-semibold"></span>" will permanently remove:
                </p>
                <ul class="text-red-700 dark:text-red-400 text-sm mt-2 space-y-1 ml-4">
                    <li>• The AEI and all its personality data</li>
                    <li>• All chat history and conversations</li>
                    <li>• All memories and learning progress</li>
                    <li>• Any emotional bonds developed</li>
                </ul>
            </div>
            
            <div class="space-y-3">
                <p class="text-gray-900 dark:text-white font-medium">
                    To confirm deletion, type <span class="bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded font-mono text-red-600 dark:text-red-400">DELETE</span> below:
                </p>
                
                <form id="deleteForm" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="delete_aei">
                    <input type="hidden" name="aei_id" id="deleteAeiId" value="">
                    
                    <input 
                        type="text" 
                        name="confirm_delete" 
                        id="confirmDeleteInput"
                        placeholder="Type DELETE to confirm"
                        class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-red-500 focus:border-red-500 transition-all font-mono"
                        autocomplete="off"
                        required
                    />
                    
                    <div class="flex space-x-3">
                        <button 
                            type="button" 
                            onclick="closeDeleteModal()"
                            class="flex-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold py-3 px-4 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
                        >
                            Cancel
                        </button>
                        <button 
                            type="submit" 
                            id="deleteButton"
                            disabled
                            class="flex-1 bg-red-600 hover:bg-red-700 disabled:bg-red-300 dark:disabled:bg-red-800 text-white font-semibold py-3 px-4 rounded-lg transition-colors disabled:cursor-not-allowed"
                        >
                            Delete Forever
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Dropdown functionality
function toggleDropdown(button) {
    const dropdown = button.nextElementSibling;
    const isHidden = dropdown.classList.contains('hidden');
    
    // Close all other dropdowns
    document.querySelectorAll('[data-dropdown] .absolute').forEach(d => {
        if (d !== dropdown) {
            d.classList.add('hidden');
        }
    });
    
    // Toggle current dropdown
    if (isHidden) {
        dropdown.classList.remove('hidden');
    } else {
        dropdown.classList.add('hidden');
    }
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('[data-dropdown]')) {
        document.querySelectorAll('[data-dropdown] .absolute').forEach(d => {
            d.classList.add('hidden');
        });
    }
});

function showDeleteModal(aeiId, aeiName) {
    // Close any open dropdowns
    document.querySelectorAll('[data-dropdown] .absolute').forEach(d => {
        d.classList.add('hidden');
    });
    
    document.getElementById('modalAeiName').textContent = aeiName;
    document.getElementById('deleteAeiId').value = aeiId;
    document.getElementById('confirmDeleteInput').value = '';
    document.getElementById('deleteButton').disabled = true;
    document.getElementById('deleteModal').classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    
    // Focus on input
    setTimeout(() => {
        document.getElementById('confirmDeleteInput').focus();
    }, 100);
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
    document.body.style.overflow = '';
    document.getElementById('deleteForm').reset();
}

// Enable delete button only when "DELETE" is typed
document.addEventListener('DOMContentLoaded', function() {
    const confirmInput = document.getElementById('confirmDeleteInput');
    if (confirmInput) {
        confirmInput.addEventListener('input', function(e) {
            const deleteButton = document.getElementById('deleteButton');
            if (e.target.value === 'DELETE') {
                deleteButton.disabled = false;
                deleteButton.classList.remove('bg-red-300', 'dark:bg-red-800');
                deleteButton.classList.add('bg-red-600', 'hover:bg-red-700');
            } else {
                deleteButton.disabled = true;
                deleteButton.classList.add('bg-red-300', 'dark:bg-red-800');
                deleteButton.classList.remove('bg-red-600', 'hover:bg-red-700');
            }
        });
    }
});

// Close modal on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDeleteModal();
    }
});

// Close modal when clicking outside
document.getElementById('deleteModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeDeleteModal();
    }
});
</script>