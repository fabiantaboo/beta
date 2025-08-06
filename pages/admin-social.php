<?php
requireAdmin();

require_once __DIR__ . '/../includes/background_social_processor.php';
require_once __DIR__ . '/../includes/social_contact_manager.php';

$processor = new BackgroundSocialProcessor($pdo);
$socialManager = new SocialContactManager($pdo);

$error = null;
$success = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'process_all':
            try {
                $count = $processor->processAllAEISocial();
                $success = "Processed social environments for $count AEIs successfully.";
            } catch (Exception $e) {
                $error = "Error processing AEIs: " . $e->getMessage();
            }
            break;
            
        case 'initialize_aei':
            $aeiId = $_POST['aei_id'] ?? '';
            if ($aeiId) {
                try {
                    $result = $processor->initializeAEISocialEnvironment($aeiId);
                    if ($result) {
                        $success = "Successfully initialized social environment for AEI.";
                    } else {
                        $error = "Failed to initialize social environment.";
                    }
                } catch (Exception $e) {
                    $error = "Error initializing AEI: " . $e->getMessage();
                }
            }
            break;
            
        case 'process_single':
            $aeiId = $_POST['aei_id'] ?? '';
            if ($aeiId) {
                try {
                    $result = $processor->processSingleAEI($aeiId);
                    if ($result['success']) {
                        $success = "Generated {$result['interactions_generated']} new interactions for AEI.";
                    } else {
                        $error = "Error: " . $result['error'];
                    }
                } catch (Exception $e) {
                    $error = "Error processing AEI: " . $e->getMessage();
                }
            }
            break;
            
        case 'cleanup':
            try {
                $count = $processor->cleanupOldInteractions();
                $success = "Cleaned up $count old interactions successfully.";
            } catch (Exception $e) {
                $error = "Error during cleanup: " . $e->getMessage();
            }
            break;
    }
}

// Get statistics
try {
    $stmt = $pdo->query("
        SELECT 
            COUNT(DISTINCT a.id) as total_aeis,
            COUNT(DISTINCT CASE WHEN a.social_initialized = TRUE THEN a.id END) as social_aeis,
            COUNT(DISTINCT c.id) as total_contacts,
            COUNT(DISTINCT i.id) as total_interactions,
            COUNT(DISTINCT CASE WHEN i.processed_for_emotions = FALSE THEN i.id END) as unprocessed_interactions
        FROM aeis a
        LEFT JOIN aei_social_contacts c ON a.id = c.aei_id AND c.is_active = TRUE
        LEFT JOIN aei_contact_interactions i ON a.id = i.aei_id
        WHERE a.is_active = TRUE
    ");
    $stats = $stmt->fetch();
} catch (PDOException $e) {
    $stats = null;
    error_log("Error getting social statistics: " . $e->getMessage());
}

// Get AEIs for management
try {
    $stmt = $pdo->query("
        SELECT 
            a.id, 
            a.name, 
            a.social_initialized,
            COUNT(DISTINCT c.id) as contact_count,
            COUNT(DISTINCT i.id) as interaction_count
        FROM aeis a
        LEFT JOIN aei_social_contacts c ON a.id = c.aei_id AND c.is_active = TRUE
        LEFT JOIN aei_contact_interactions i ON a.id = i.aei_id
        WHERE a.is_active = TRUE
        GROUP BY a.id, a.name, a.social_initialized
        ORDER BY a.created_at DESC
    ");
    $aeis = $stmt->fetchAll();
} catch (PDOException $e) {
    $aeis = [];
    error_log("Error getting AEIs: " . $e->getMessage());
}

// Get recent cron job runs
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'social_cron_last_run'");
    $stmt->execute();
    $lastRun = $stmt->fetch();
    $lastRunData = $lastRun ? json_decode($lastRun['setting_value'], true) : null;
} catch (PDOException $e) {
    $lastRunData = null;
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-social'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Social System Management', 'Manage AEI social environments and background processing'); ?>

        <?php renderAdminAlerts($error, $success); ?>

        <!-- Statistics -->
        <?php if ($stats): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-ayuni-blue to-ayuni-aqua rounded-lg flex items-center justify-center">
                        <i class="fas fa-robot text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total AEIs</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $stats['total_aeis'] ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users-cog text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Social AEIs</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $stats['social_aeis'] ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-address-book text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Contacts</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $stats['total_contacts'] ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-comments text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Interactions</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total_interactions']) ?></p>
                    </div>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-orange-500 to-orange-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Unprocessed</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= $stats['unprocessed_interactions'] ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Global Actions -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Global Actions</h3>
                
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <button type="submit" name="action" value="process_all" 
                            class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-ayuni-blue hover:bg-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ayuni-blue transition-colors">
                        <i class="fas fa-play mr-2"></i>
                        Process All AEI Social Environments
                    </button>
                    
                    <button type="submit" name="action" value="cleanup"
                            class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                        <i class="fas fa-trash-alt mr-2"></i>
                        Cleanup Old Interactions
                    </button>
                </form>
            </div>

            <!-- Last Cron Run -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Last Background Process</h3>
                
                <?php if ($lastRunData): ?>
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Time:</span>
                        <span class="text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($lastRunData['timestamp']) ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Processed AEIs:</span>
                        <span class="text-sm text-gray-900 dark:text-white"><?= $lastRunData['processed_aeis'] ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Cleaned Interactions:</span>
                        <span class="text-sm text-gray-900 dark:text-white"><?= $lastRunData['cleaned_interactions'] ?></span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Execution Time:</span>
                        <span class="text-sm text-gray-900 dark:text-white"><?= $lastRunData['execution_time'] ?>s</span>
                    </div>
                </div>
                <?php else: ?>
                <p class="text-gray-500 dark:text-gray-400">No background processing data available</p>
                <?php endif; ?>
                
                <div class="mt-4 p-3 bg-gray-100 dark:bg-gray-700 rounded-lg">
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cron Job Setup:</p>
                    <code class="text-xs text-gray-600 dark:text-gray-400 font-mono">0 STAR/6 * * * php <?= dirname(__DIR__) ?>/social_background_cron.php</code>
                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">(Replace STAR with asterisk symbol)</p>
                </div>
            </div>
        </div>

        <!-- AEI Management -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">AEI Social Management</h3>
            </div>
            
            <div class="overflow-x-auto">
                <?php if (!empty($aeis)): ?>
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">AEI Name</th>
                            <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Social Status</th>
                            <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contacts</th>
                            <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Interactions</th>
                            <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($aeis as $aei): ?>
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <td class="py-4 px-6">
                                <div class="flex items-center">
                                    <div class="w-8 h-8 bg-gradient-to-r from-ayuni-blue to-ayuni-aqua rounded-full flex items-center justify-center mr-3">
                                        <i class="fas fa-robot text-white text-sm"></i>
                                    </div>
                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></div>
                                </div>
                            </td>
                            <td class="py-4 px-6">
                                <?php if ($aei['social_initialized']): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400">
                                        <i class="fas fa-check-circle mr-1"></i>
                                        Initialized
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                        <i class="fas fa-clock mr-1"></i>
                                        Not Initialized
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-4 px-6 text-sm text-gray-900 dark:text-white"><?= $aei['contact_count'] ?></td>
                            <td class="py-4 px-6 text-sm text-gray-900 dark:text-white"><?= number_format($aei['interaction_count']) ?></td>
                            <td class="py-4 px-6">
                                <form method="post" class="inline-flex">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                    <input type="hidden" name="aei_id" value="<?= $aei['id'] ?>">
                                    
                                    <?php if (!$aei['social_initialized']): ?>
                                    <button type="submit" name="action" value="initialize_aei"
                                            class="inline-flex items-center px-3 py-1 border border-transparent text-xs leading-4 font-medium rounded-md text-white bg-ayuni-aqua hover:bg-ayuni-aqua/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ayuni-aqua transition-colors">
                                        <i class="fas fa-plus mr-1"></i>
                                        Initialize
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" name="action" value="process_single"
                                            class="inline-flex items-center px-3 py-1 border border-transparent text-xs leading-4 font-medium rounded-md text-white bg-ayuni-blue hover:bg-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ayuni-blue transition-colors">
                                        <i class="fas fa-sync mr-1"></i>
                                        Process
                                    </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="px-6 py-12 text-center">
                    <div class="w-12 h-12 mx-auto bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                        <i class="fas fa-robot text-gray-400 text-xl"></i>
                    </div>
                    <p class="text-gray-500 dark:text-gray-400">No AEIs found</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>