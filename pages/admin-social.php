<?php
requireAdmin();

require_once __DIR__ . '/../includes/background_social_processor.php';
require_once __DIR__ . '/../includes/social_contact_manager.php';

$processor = new BackgroundSocialProcessor($pdo);
$socialManager = new SocialContactManager($pdo);

$message = '';
$messageType = 'info';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCSRFToken($_POST['csrf_token'] ?? '')) {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'process_all':
            try {
                $count = $processor->processAllAEISocial();
                $message = "Processed social environments for $count AEIs successfully.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error processing AEIs: " . $e->getMessage();
                $messageType = 'error';
            }
            break;
            
        case 'initialize_aei':
            $aeiId = $_POST['aei_id'] ?? '';
            if ($aeiId) {
                try {
                    $result = $processor->initializeAEISocialEnvironment($aeiId);
                    if ($result) {
                        $message = "Successfully initialized social environment for AEI.";
                        $messageType = 'success';
                    } else {
                        $message = "Failed to initialize social environment.";
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = "Error initializing AEI: " . $e->getMessage();
                    $messageType = 'error';
                }
            }
            break;
            
        case 'process_single':
            $aeiId = $_POST['aei_id'] ?? '';
            if ($aeiId) {
                try {
                    $result = $processor->processSingleAEI($aeiId);
                    if ($result['success']) {
                        $message = "Generated {$result['interactions_generated']} new interactions for AEI.";
                        $messageType = 'success';
                    } else {
                        $message = "Error: " . $result['error'];
                        $messageType = 'error';
                    }
                } catch (Exception $e) {
                    $message = "Error processing AEI: " . $e->getMessage();
                    $messageType = 'error';
                }
            }
            break;
            
        case 'cleanup':
            try {
                $count = $processor->cleanupOldInteractions();
                $message = "Cleaned up $count old interactions successfully.";
                $messageType = 'success';
            } catch (Exception $e) {
                $message = "Error during cleanup: " . $e->getMessage();
                $messageType = 'error';
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

<div class="min-h-screen bg-ayuni-dark text-white">
    <div class="container mx-auto px-6 py-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">Social System Management</h1>
            <p class="text-gray-300">Manage AEI social environments and background processing</p>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
        <div class="mb-6 p-4 rounded-lg <?= $messageType === 'success' ? 'bg-green-800 text-green-100' : ($messageType === 'error' ? 'bg-red-800 text-red-100' : 'bg-blue-800 text-blue-100') ?>">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <!-- Statistics -->
        <?php if ($stats): ?>
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-ayuni-blue/20 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-2">Total AEIs</h3>
                <p class="text-3xl font-bold text-ayuni-aqua"><?= $stats['total_aeis'] ?></p>
            </div>
            <div class="bg-ayuni-aqua/20 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-2">Social AEIs</h3>
                <p class="text-3xl font-bold text-ayuni-aqua"><?= $stats['social_aeis'] ?></p>
            </div>
            <div class="bg-green-900/20 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-2">Total Contacts</h3>
                <p class="text-3xl font-bold text-green-400"><?= $stats['total_contacts'] ?></p>
            </div>
            <div class="bg-purple-900/20 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-2">Interactions</h3>
                <p class="text-3xl font-bold text-purple-400"><?= $stats['total_interactions'] ?></p>
            </div>
            <div class="bg-orange-900/20 rounded-lg p-6">
                <h3 class="text-lg font-semibold mb-2">Unprocessed</h3>
                <p class="text-3xl font-bold text-orange-400"><?= $stats['unprocessed_interactions'] ?></p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Global Actions -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Global Actions</h2>
                
                <form method="post" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <button type="submit" name="action" value="process_all" 
                            class="w-full bg-ayuni-blue hover:bg-ayuni-blue/80 text-white font-bold py-2 px-4 rounded">
                        Process All AEI Social Environments
                    </button>
                    
                    <button type="submit" name="action" value="cleanup"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                        Cleanup Old Interactions
                    </button>
                </form>
            </div>

            <!-- Last Cron Run -->
            <div class="bg-gray-800 rounded-lg p-6">
                <h2 class="text-xl font-semibold mb-4">Last Background Process</h2>
                
                <?php if ($lastRunData): ?>
                <div class="space-y-2 text-sm">
                    <p><strong>Time:</strong> <?= htmlspecialchars($lastRunData['timestamp']) ?></p>
                    <p><strong>Processed AEIs:</strong> <?= $lastRunData['processed_aeis'] ?></p>
                    <p><strong>Cleaned Interactions:</strong> <?= $lastRunData['cleaned_interactions'] ?></p>
                    <p><strong>Execution Time:</strong> <?= $lastRunData['execution_time'] ?>s</p>
                </div>
                <?php else: ?>
                <p class="text-gray-400">No background processing data available</p>
                <?php endif; ?>
                
                <div class="mt-4 p-3 bg-gray-700 rounded text-xs">
                    <strong>Cron Job Setup:</strong><br>
                    <code class="text-ayuni-aqua">0 */6 * * * php <?= __DIR__ ?>/../social_background_cron.php</code>
                </div>
            </div>
        </div>

        <!-- AEI Management -->
        <div class="bg-gray-800 rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">AEI Social Management</h2>
            
            <?php if (!empty($aeis)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead class="border-b border-gray-600">
                        <tr>
                            <th class="py-3 px-4">AEI Name</th>
                            <th class="py-3 px-4">Social Status</th>
                            <th class="py-3 px-4">Contacts</th>
                            <th class="py-3 px-4">Interactions</th>
                            <th class="py-3 px-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($aeis as $aei): ?>
                        <tr class="border-b border-gray-700 hover:bg-gray-700/50">
                            <td class="py-3 px-4 font-medium"><?= htmlspecialchars($aei['name']) ?></td>
                            <td class="py-3 px-4">
                                <?php if ($aei['social_initialized']): ?>
                                    <span class="bg-green-600 text-white px-2 py-1 rounded text-xs">Initialized</span>
                                <?php else: ?>
                                    <span class="bg-gray-600 text-white px-2 py-1 rounded text-xs">Not Initialized</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4"><?= $aei['contact_count'] ?></td>
                            <td class="py-3 px-4"><?= $aei['interaction_count'] ?></td>
                            <td class="py-3 px-4">
                                <form method="post" class="inline-flex space-x-2">
                                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                    <input type="hidden" name="aei_id" value="<?= $aei['id'] ?>">
                                    
                                    <?php if (!$aei['social_initialized']): ?>
                                    <button type="submit" name="action" value="initialize_aei"
                                            class="bg-ayuni-aqua hover:bg-ayuni-aqua/80 text-white px-3 py-1 rounded text-xs">
                                        Initialize
                                    </button>
                                    <?php else: ?>
                                    <button type="submit" name="action" value="process_single"
                                            class="bg-ayuni-blue hover:bg-ayuni-blue/80 text-white px-3 py-1 rounded text-xs">
                                        Process
                                    </button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="text-gray-400">No AEIs found.</p>
            <?php endif; ?>
        </div>
    </div>
</div>