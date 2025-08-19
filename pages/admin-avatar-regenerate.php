<?php
requireAdmin();
require_once __DIR__ . '/../includes/replicate_api.php';

$error = null;
$success = null;
$selectedAei = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'regenerate_avatar') {
            $aeiId = sanitizeInput($_POST['aei_id'] ?? '');
            
            if (empty($aeiId)) {
                $error = "Please select an AEI to regenerate avatar.";
            } else {
                try {
                    // Get AEI details
                    $stmt = $pdo->prepare("SELECT id, name, gender, appearance_description, avatar_url FROM aeis WHERE id = ? AND is_active = TRUE");
                    $stmt->execute([$aeiId]);
                    $aei = $stmt->fetch();
                    
                    if (!$aei) {
                        $error = "AEI not found or inactive.";
                    } else {
                        $selectedAei = $aei;
                        $replicateAPI = new ReplicateAPI();
                        
                        // Parse appearance if it's JSON
                        $appearance = $aei['appearance_description'];
                        if (is_string($appearance) && substr($appearance, 0, 1) === '{') {
                            $appearance = json_decode($appearance, true);
                        }
                        
                        // Build prompt from appearance data
                        $prompt = $replicateAPI->buildPromptFromAppearance($appearance, $aei['name'], $aei['gender']);
                        
                        // Generate new avatar
                        $avatarDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/avatars/';
                        $avatarFilename = $aei['id'] . '_' . time() . '.png'; // Add timestamp to avoid cache issues
                        $avatarPath = $avatarDir . $avatarFilename;
                        
                        $savedPath = $replicateAPI->generateAndDownloadAvatar($prompt, $avatarPath);
                        $avatarUrl = '/assets/avatars/' . $avatarFilename;
                        
                        // Store old avatar URL for potential cleanup
                        $oldAvatarUrl = $aei['avatar_url'];
                        
                        // Update AEI in database with new avatar
                        $updateStmt = $pdo->prepare("UPDATE aeis SET avatar_url = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $updateStmt->execute([$avatarUrl, $aei['id']]);
                        
                        // Optionally clean up old avatar file (if it exists and is not default)
                        if (!empty($oldAvatarUrl) && file_exists($_SERVER['DOCUMENT_ROOT'] . $oldAvatarUrl)) {
                            $oldFilePath = $_SERVER['DOCUMENT_ROOT'] . $oldAvatarUrl;
                            if (strpos($oldAvatarUrl, '/assets/avatars/') === 0) {
                                @unlink($oldFilePath); // Suppress errors in case file is locked
                            }
                        }
                        
                        $success = "Successfully regenerated avatar for {$aei['name']}!";
                        
                        // Update selectedAei with new avatar URL for display
                        $selectedAei['avatar_url'] = $avatarUrl;
                        
                        error_log("Regenerated avatar for AEI {$aei['name']} ({$aei['id']}) - New avatar: $avatarUrl");
                    }
                } catch (Exception $e) {
                    error_log("Avatar regeneration error for AEI ID $aeiId: " . $e->getMessage());
                    $error = "Avatar regeneration failed: " . $e->getMessage();
                }
            }
        }
    }
}

// Get all active AEIs for selection - EXACT COPY from admin-avatar-batch.php pattern
try {
    $stmt = $pdo->prepare("SELECT id, name, gender, avatar_url, appearance_description, created_at FROM aeis WHERE is_active = TRUE ORDER BY name ASC");
    $stmt->execute();
    $availableAeis = $stmt->fetchAll();
} catch (PDOException $e) {
    $availableAeis = [];
}

// Get statistics
try {
    $stmt = $pdo->prepare("SELECT 
        COUNT(*) as total_aeis,
        COUNT(avatar_url) as aeis_with_avatars,
        COUNT(*) - COUNT(avatar_url) as aeis_without_avatars
        FROM aeis WHERE is_active = TRUE");
    $stmt->execute();
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = ['total_aeis' => 0, 'aeis_with_avatars' => 0, 'aeis_without_avatars' => 0];
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-avatar-regenerate'); ?>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Avatar Regeneration', 'Regenerate profile images for existing AEIs based on their appearance data'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- Statistics -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Avatar Statistics</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-ayuni-blue mb-2"><?= $stats['total_aeis'] ?></div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">Total AEIs</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-green-600 mb-2"><?= $stats['aeis_with_avatars'] ?></div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">With Avatars</div>
                    </div>
                    <div class="text-center">
                        <div class="text-3xl font-bold text-orange-600 mb-2"><?= $stats['aeis_without_avatars'] ?></div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">Without Avatars</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Avatar Regeneration -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Regenerate Avatar</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Select an AEI to regenerate their profile image based on appearance data</p>
            </div>
            <div class="p-6">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="regenerate_avatar">
                    
                    <!-- AEI Selection -->
                    <div>
                        <label for="aei_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            <i class="fas fa-robot mr-2 text-ayuni-blue"></i>
                            Select AEI
                        </label>
                        <select 
                            id="aei_id" 
                            name="aei_id"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                            onchange="showAeiPreview(this.value)"
                            required
                        >
                            <option value="">Choose an AEI...</option>
                            <?php foreach ($availableAeis as $aei): ?>
                                <option value="<?= htmlspecialchars($aei['id']) ?>" 
                                        data-name="<?= htmlspecialchars($aei['name']) ?>"
                                        data-gender="<?= htmlspecialchars($aei['gender']) ?>"
                                        data-avatar="<?= htmlspecialchars($aei['avatar_url'] ?? '') ?>"
                                        data-appearance="<?= htmlspecialchars($aei['appearance_description'] ?? '') ?>"
                                        data-created="<?= htmlspecialchars($aei['created_at']) ?>"
                                        <?= (isset($_POST['aei_id']) && $_POST['aei_id'] === $aei['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($aei['name']) ?> (<?= ucfirst($aei['gender']) ?>) 
                                    <?= !empty($aei['avatar_url']) ? '• Has Avatar' : '• No Avatar' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- AEI Preview (populated by JavaScript) -->
                    <div id="aei-preview" class="hidden bg-gray-50 dark:bg-gray-700 rounded-lg p-6 border border-gray-200 dark:border-gray-600">
                        <h4 class="text-md font-semibold text-gray-900 dark:text-white mb-4">AEI Preview</h4>
                        <div class="flex items-start space-x-6">
                            <!-- Current Avatar -->
                            <div class="flex-shrink-0">
                                <div class="text-center">
                                    <div id="current-avatar-container" class="w-24 h-24 rounded-full overflow-hidden border-2 border-gray-300 dark:border-gray-600 mb-2"></div>
                                    <span class="text-xs text-gray-600 dark:text-gray-400">Current Avatar</span>
                                </div>
                            </div>
                            
                            <!-- AEI Details -->
                            <div class="flex-1">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Name</p>
                                        <p id="preview-name" class="font-medium text-gray-900 dark:text-white"></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Gender</p>
                                        <p id="preview-gender" class="font-medium text-gray-900 dark:text-white"></p>
                                    </div>
                                    <div>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Created</p>
                                        <p id="preview-created" class="font-medium text-gray-900 dark:text-white"></p>
                                    </div>
                                </div>
                                
                                <!-- Appearance Details -->
                                <div class="mt-4">
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Appearance Details</p>
                                    <div id="preview-appearance" class="font-medium text-gray-900 dark:text-white mt-1"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Warning and Cost Info -->
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-exclamation-triangle text-yellow-600 dark:text-yellow-400 mt-0.5"></i>
                            <div class="text-sm text-yellow-800 dark:text-yellow-300">
                                <p class="font-medium mb-1">Avatar Regeneration</p>
                                <ul class="space-y-1">
                                    <li>• Will generate a new avatar based on the AEI's stored appearance data</li>
                                    <li>• Each regeneration costs ~$0.003-0.01 in Replicate credits</li>
                                    <li>• The old avatar will be replaced permanently</li>
                                    <li>• Generation may take 30-60 seconds depending on Replicate queue</li>
                                    <li>• This works for AEIs with OR without existing avatars</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            This will permanently replace the current avatar with a newly generated one.
                        </div>
                        <button 
                            type="submit"
                            id="regenerate-button"
                            class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-colors flex items-center disabled:opacity-50 disabled:cursor-not-allowed"
                            onclick="return confirm('Are you sure you want to regenerate this AEI\'s avatar? This action cannot be undone and will consume Replicate credits.')"
                            disabled
                        >
                            <i class="fas fa-redo-alt mr-2"></i>
                            Regenerate Avatar
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Recently Regenerated (if any) -->
        <?php if ($selectedAei): ?>
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl p-6">
                <div class="flex items-center mb-4">
                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 mr-2"></i>
                    <h3 class="text-lg font-semibold text-green-800 dark:text-green-300">Avatar Successfully Regenerated</h3>
                </div>
                
                <div class="flex items-center space-x-6">
                    <!-- New Avatar -->
                    <div class="flex-shrink-0 text-center">
                        <?php if (!empty($selectedAei['avatar_url']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $selectedAei['avatar_url'])): ?>
                            <div class="w-24 h-24 rounded-full overflow-hidden border-2 border-green-300 dark:border-green-600 mb-2">
                                <img 
                                    src="<?= htmlspecialchars($selectedAei['avatar_url']) ?>?v=<?= time() ?>" 
                                    alt="<?= htmlspecialchars($selectedAei['name']) ?>"
                                    class="w-full h-full object-cover"
                                />
                            </div>
                        <?php else: ?>
                            <div class="w-24 h-24 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mb-2">
                                <span class="text-xl text-white font-bold"><?= strtoupper(substr($selectedAei['name'], 0, 1)) ?></span>
                            </div>
                        <?php endif; ?>
                        <span class="text-xs text-green-600 dark:text-green-400 font-medium">New Avatar</span>
                    </div>
                    
                    <!-- AEI Info -->
                    <div>
                        <p class="text-lg font-semibold text-green-900 dark:text-green-100"><?= htmlspecialchars($selectedAei['name']) ?></p>
                        <p class="text-sm text-green-700 dark:text-green-300"><?= ucfirst($selectedAei['gender']) ?> • Avatar regenerated just now</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function showAeiPreview(aeiId) {
    const preview = document.getElementById('aei-preview');
    const button = document.getElementById('regenerate-button');
    
    if (!aeiId) {
        preview.classList.add('hidden');
        button.disabled = true;
        return;
    }
    
    // Get selected option
    const select = document.getElementById('aei_id');
    const selectedOption = select.querySelector(`option[value="${aeiId}"]`);
    
    if (!selectedOption) {
        preview.classList.add('hidden');
        button.disabled = true;
        return;
    }
    
    // Extract data from option attributes
    const name = selectedOption.getAttribute('data-name');
    const gender = selectedOption.getAttribute('data-gender');
    const avatar = selectedOption.getAttribute('data-avatar');
    const appearance = selectedOption.getAttribute('data-appearance');
    const created = selectedOption.getAttribute('data-created');
    
    // Update preview content
    document.getElementById('preview-name').textContent = name || 'N/A';
    document.getElementById('preview-gender').textContent = (gender ? gender.charAt(0).toUpperCase() + gender.slice(1) : 'N/A');
    document.getElementById('preview-created').textContent = created ? new Date(created).toLocaleDateString() : 'N/A';
    
    // Update avatar display
    const avatarContainer = document.getElementById('current-avatar-container');
    if (avatar && avatar.trim()) {
        avatarContainer.innerHTML = `<img src="${avatar}" alt="${name}" class="w-full h-full object-cover" onerror="this.parentElement.innerHTML='<div class=\\'w-full h-full bg-gradient-to-br from-gray-400 to-gray-500 flex items-center justify-center\\'><span class=\\'text-white font-bold text-lg\\'>${name.charAt(0).toUpperCase()}</span></div>'">`;
    } else {
        avatarContainer.innerHTML = `<div class="w-full h-full bg-gradient-to-br from-gray-400 to-gray-500 flex items-center justify-center"><span class="text-white font-bold text-lg">${name.charAt(0).toUpperCase()}</span></div>`;
    }
    
    // Parse and display appearance
    let appearanceDisplay = 'No appearance data available';
    if (appearance && appearance.trim() && appearance.startsWith('{')) {
        try {
            const appearanceObj = JSON.parse(appearance);
            const features = [];
            
            if (appearanceObj.hair_color) features.push(`${appearanceObj.hair_color} hair`);
            if (appearanceObj.eye_color) features.push(`${appearanceObj.eye_color} eyes`);
            if (appearanceObj.build) features.push(`${appearanceObj.build} build`);
            if (appearanceObj.height) features.push(`${appearanceObj.height} height`);
            if (appearanceObj.style) features.push(`${appearanceObj.style} style`);
            if (appearanceObj.custom) features.push(appearanceObj.custom);
            
            appearanceDisplay = features.length > 0 ? features.join(', ') : 'No specific features defined';
        } catch (e) {
            appearanceDisplay = appearance || 'Invalid appearance data';
        }
    } else if (appearance && appearance.trim()) {
        appearanceDisplay = appearance;
    }
    
    document.getElementById('preview-appearance').textContent = appearanceDisplay;
    
    // Show preview and enable button
    preview.classList.remove('hidden');
    button.disabled = false;
}

// Auto-select and show preview if there's a POST value (after form submission)
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('aei_id');
    if (select.value) {
        showAeiPreview(select.value);
    }
});
</script>