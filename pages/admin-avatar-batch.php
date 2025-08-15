<?php
requireAdmin();
require_once __DIR__ . '/../includes/replicate_api.php';

$error = null;
$success = null;
$inProgress = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'generate_batch') {
            try {
                $replicateAPI = new ReplicateAPI();
                
                // Get all AEIs without avatars
                $stmt = $pdo->prepare("SELECT id, name, gender, appearance_description FROM aeis WHERE (avatar_url IS NULL OR avatar_url = '') AND is_active = TRUE");
                $stmt->execute();
                $aeisWithoutAvatars = $stmt->fetchAll();
                
                if (empty($aeisWithoutAvatars)) {
                    $success = "No AEIs found that need avatars.";
                } else {
                    $inProgress = true;
                    $generated = 0;
                    $failed = 0;
                    
                    foreach ($aeisWithoutAvatars as $aei) {
                        try {
                            // Parse appearance if it's JSON
                            $appearance = $aei['appearance_description'];
                            if (is_string($appearance) && substr($appearance, 0, 1) === '{') {
                                $appearance = json_decode($appearance, true);
                            }
                            
                            // Build prompt
                            $prompt = $replicateAPI->buildPromptFromAppearance($appearance, $aei['name'], $aei['gender']);
                            
                            // Generate single avatar
                            $avatarDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/avatars/';
                            $avatarFilename = $aei['id'] . '.png';
                            $avatarPath = $avatarDir . $avatarFilename;
                            
                            $savedPath = $replicateAPI->generateAndDownloadAvatar($prompt, $avatarPath);
                            $avatarUrl = '/assets/avatars/' . $avatarFilename;
                            
                            // Update AEI in database
                            $updateStmt = $pdo->prepare("UPDATE aeis SET avatar_url = ? WHERE id = ?");
                            $updateStmt->execute([$avatarUrl, $aei['id']]);
                            
                            $generated++;
                            error_log("Generated avatar for AEI {$aei['name']} ({$aei['id']})");
                            
                        } catch (Exception $e) {
                            $failed++;
                            error_log("Failed to generate avatar for AEI {$aei['name']} ({$aei['id']}): " . $e->getMessage());
                        }
                    }
                    
                    $success = "Batch generation completed! Generated: $generated, Failed: $failed";
                }
                
            } catch (Exception $e) {
                error_log("Batch avatar generation error: " . $e->getMessage());
                $error = "Batch generation failed: " . $e->getMessage();
            }
        }
    }
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

// Get sample AEIs without avatars
try {
    $stmt = $pdo->prepare("SELECT id, name, gender, created_at FROM aeis WHERE (avatar_url IS NULL OR avatar_url = '') AND is_active = TRUE ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $sampleAeisWithoutAvatars = $stmt->fetchAll();
} catch (PDOException $e) {
    $sampleAeisWithoutAvatars = [];
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-avatar-batch'); ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Batch Avatar Generation', 'Generate avatars for existing AEIs that don\'t have them'); ?>
        
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
                        <div class="text-sm text-gray-600 dark:text-gray-400">Need Avatars</div>
                    </div>
                </div>
                
                <?php if ($stats['aeis_without_avatars'] > 0): ?>
                    <div class="mt-6 bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-triangle text-orange-600 dark:text-orange-400 mr-2"></i>
                            <span class="text-orange-800 dark:text-orange-300">
                                <?= $stats['aeis_without_avatars'] ?> AEIs are missing avatars and can be processed
                            </span>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Batch Generation -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Batch Generation</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Generate avatars for all AEIs that don't have them</p>
            </div>
            <div class="p-6">
                <?php if ($stats['aeis_without_avatars'] > 0): ?>
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="generate_batch">
                        
                        <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                            <div class="flex items-start space-x-3">
                                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-0.5"></i>
                                <div class="text-sm text-blue-800 dark:text-blue-300">
                                    <p class="font-medium mb-1">About Batch Generation</p>
                                    <ul class="space-y-1">
                                        <li>• Will process <?= $stats['aeis_without_avatars'] ?> AEIs without avatars</li>
                                        <li>• Each avatar costs ~$0.003-0.01 in Replicate credits</li>
                                        <li>• Total estimated cost: $<?= number_format($stats['aeis_without_avatars'] * 0.005, 2) ?> - $<?= number_format($stats['aeis_without_avatars'] * 0.01, 2) ?></li>
                                        <li>• Process may take several minutes depending on queue</li>
                                        <li>• Failed generations will be logged and skipped</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                This will generate avatars for all AEIs missing them. The process cannot be stopped once started.
                            </div>
                            <button 
                                type="submit"
                                class="bg-purple-600 text-white font-semibold py-3 px-6 rounded-lg hover:bg-purple-700 transition-colors flex items-center"
                                onclick="return confirm('Are you sure you want to start batch generation for <?= $stats['aeis_without_avatars'] ?> AEIs? This will consume Replicate credits.')"
                            >
                                <i class="fas fa-magic mr-2"></i>
                                Start Batch Generation
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-check-circle text-green-500 text-4xl mb-4"></i>
                        <p class="text-lg font-medium text-gray-900 dark:text-white mb-2">All AEIs have avatars!</p>
                        <p class="text-gray-600 dark:text-gray-400">No batch generation needed at this time.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sample AEIs without avatars -->
        <?php if (!empty($sampleAeisWithoutAvatars)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">AEIs Needing Avatars</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Sample of AEIs that will be processed (showing 10 most recent)</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($sampleAeisWithoutAvatars as $aei): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
                                <div class="flex items-center space-x-4">
                                    <div class="w-10 h-10 bg-gradient-to-br from-gray-400 to-gray-500 rounded-full flex items-center justify-center">
                                        <span class="text-white font-bold"><?= strtoupper(substr($aei['name'], 0, 1)) ?></span>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?= ucfirst($aei['gender']) ?> • Created <?= date('M j, Y', strtotime($aei['created_at'])) ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="text-orange-600 dark:text-orange-400">
                                    <i class="fas fa-user-times"></i>
                                    <span class="text-xs ml-1">No Avatar</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if ($stats['aeis_without_avatars'] > 10): ?>
                            <div class="text-center text-sm text-gray-600 dark:text-gray-400 mt-4">
                                ... and <?= $stats['aeis_without_avatars'] - 10 ?> more AEIs
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>