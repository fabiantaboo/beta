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

// Get detailed interaction data for selected AEI
$selectedAeiId = $_GET['aei_id'] ?? '';
$selectedAeiDetails = null;
$recentInteractions = [];
$contacts = [];
$emotionalHistory = [];

if ($selectedAeiId) {
    try {
        // Get AEI details
        $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ?");
        $stmt->execute([$selectedAeiId]);
        $selectedAeiDetails = $stmt->fetch();
        
        // Get recent interactions with details
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                c.name as contact_name,
                c.relationship_type,
                c.relationship_strength,
                c.personality_traits,
                c.current_life_situation,
                a.name as aei_name
            FROM aei_contact_interactions i
            JOIN aei_social_contacts c ON i.contact_id = c.id
            JOIN aeis a ON i.aei_id = a.id
            WHERE i.aei_id = ?
            ORDER BY i.occurred_at DESC
            LIMIT 20
        ");
        $stmt->execute([$selectedAeiId]);
        $recentInteractions = $stmt->fetchAll();
        
        // Get all contacts for this AEI
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                COUNT(i.id) as total_interactions,
                MAX(i.occurred_at) as last_interaction,
                AVG(CASE WHEN i.relationship_impact IS NOT NULL THEN i.relationship_impact ELSE 0 END) as avg_impact
            FROM aei_social_contacts c
            LEFT JOIN aei_contact_interactions i ON c.id = i.contact_id
            WHERE c.aei_id = ? AND c.is_active = TRUE
            GROUP BY c.id
            ORDER BY c.relationship_strength DESC, total_interactions DESC
        ");
        $stmt->execute([$selectedAeiId]);
        $contacts = $stmt->fetchAll();
        
        // Get emotional history from chat sessions
        $stmt = $pdo->prepare("
            SELECT 
                cs.*,
                COUNT(cm.id) as message_count,
                AVG(cm.aei_joy) as avg_joy,
                AVG(cm.aei_sadness) as avg_sadness,
                AVG(cm.aei_love) as avg_love,
                AVG(cm.aei_trust) as avg_trust
            FROM chat_sessions cs
            LEFT JOIN chat_messages cm ON cs.id = cm.session_id AND cm.sender_type = 'aei'
            WHERE cs.aei_id = ?
            GROUP BY cs.id
            ORDER BY cs.last_message_at DESC
            LIMIT 10
        ");
        $stmt->execute([$selectedAeiId]);
        $emotionalHistory = $stmt->fetchAll();
        
    } catch (PDOException $e) {
        error_log("Error getting detailed AEI data: " . $e->getMessage());
    }
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
                                    
                                    <div class="flex space-x-2">
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
                                    </div>
                                </form>
                                
                                <?php if ($aei['social_initialized']): ?>
                                <div class="mt-2">
                                    <a href="/admin/social?aei_id=<?= $aei['id'] ?>" 
                                       class="inline-flex items-center px-3 py-1 border border-gray-300 dark:border-gray-600 text-xs leading-4 font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                        <i class="fas fa-chart-line mr-1"></i>
                                        Details
                                    </a>
                                </div>
                                <?php endif; ?>
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

        <?php if ($selectedAeiDetails): ?>
        <!-- Detailed AEI Social Monitoring -->
        <div class="mt-8 space-y-8">
            <!-- AEI Details Header -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-gradient-to-r from-ayuni-blue to-ayuni-aqua rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-robot text-white text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($selectedAeiDetails['name']) ?></h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Detailed Social Monitoring</p>
                        </div>
                    </div>
                    <a href="/admin/social" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                        <i class="fas fa-times text-xl"></i>
                    </a>
                </div>
            </div>

            <!-- Social Contacts Details -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Social Contacts (<?= count($contacts) ?>)</h3>
                </div>
                <div class="p-6">
                    <?php if (!empty($contacts)): ?>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <?php foreach ($contacts as $contact): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h4 class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($contact['name']) ?></h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 capitalize"><?= str_replace('_', ' ', $contact['relationship_type']) ?></p>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= $contact['relationship_strength'] ?>%</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">Relationship</div>
                                </div>
                            </div>
                            
                            <?php 
                            $personality = json_decode($contact['personality_traits'], true);
                            if ($personality): ?>
                            <div class="mb-3">
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-500 mb-1">Personality:</div>
                                <div class="flex flex-wrap gap-1">
                                    <?php foreach (array_slice($personality, 0, 3) as $trait): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300">
                                        <?= htmlspecialchars($trait) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-500 mb-1">Current Situation:</div>
                                <p class="text-xs text-gray-700 dark:text-gray-300 line-clamp-2"><?= htmlspecialchars($contact['current_life_situation']) ?></p>
                            </div>
                            
                            <div class="flex justify-between items-center text-xs text-gray-500 dark:text-gray-500">
                                <div>
                                    <i class="fas fa-comments mr-1"></i>
                                    <?= $contact['total_interactions'] ?> interactions
                                </div>
                                <div>
                                    <?php if ($contact['last_interaction']): ?>
                                    Last: <?= date('M j, H:i', strtotime($contact['last_interaction'])) ?>
                                    <?php else: ?>
                                    No interactions yet
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500 dark:text-gray-400 text-center py-8">No contacts found</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent Social Interactions -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Social Interactions (<?= count($recentInteractions) ?>)</h3>
                </div>
                <div class="overflow-x-auto">
                    <?php if (!empty($recentInteractions)): ?>
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-gray-700">
                                <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Time</th>
                                <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Contact</th>
                                <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Type</th>
                                <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Content</th>
                                <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Memory Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($recentInteractions as $interaction): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                <td class="py-4 px-6 text-sm text-gray-900 dark:text-white">
                                    <div><?= date('M j, Y', strtotime($interaction['occurred_at'])) ?></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500"><?= date('H:i:s', strtotime($interaction['occurred_at'])) ?></div>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($interaction['contact_name']) ?></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500 capitalize"><?= str_replace('_', ' ', $interaction['relationship_type']) ?></div>
                                </td>
                                <td class="py-4 px-6">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                        <?php 
                                        $typeColors = [
                                            'shares_news' => 'bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400',
                                            'asks_for_advice' => 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400',
                                            'invites_to_activity' => 'bg-purple-100 dark:bg-purple-900/20 text-purple-800 dark:text-purple-400',
                                            'shares_problem' => 'bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400',
                                            'celebrates_together' => 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400',
                                            'casual_chat' => 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300'
                                        ];
                                        echo $typeColors[$interaction['interaction_type']] ?? $typeColors['casual_chat'];
                                        ?>">
                                        <?= str_replace('_', ' ', ucfirst($interaction['interaction_type'])) ?>
                                    </span>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="text-sm text-gray-900 dark:text-white max-w-xs truncate">
                                        <?= htmlspecialchars($interaction['interaction_context']) ?>
                                    </div>
                                    <?php if ($interaction['contact_message']): ?>
                                    <div class="text-xs text-gray-500 dark:text-gray-500 mt-1 max-w-xs truncate">
                                        "<?= htmlspecialchars($interaction['contact_message']) ?>"
                                    </div>
                                    <?php endif; ?>
                                    <div class="mt-2">
                                        <button onclick="showDialog('<?= $interaction['id'] ?>')" 
                                                class="inline-flex items-center px-2 py-1 border border-gray-300 dark:border-gray-600 text-xs leading-4 font-medium rounded text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                                            <i class="fas fa-comments mr-1"></i>
                                            Full Dialog
                                        </button>
                                    </div>
                                </td>
                                <td class="py-4 px-6">
                                    <div class="space-y-1">
                                        <div class="flex items-center text-xs">
                                            <?php if ($interaction['processed_for_emotions']): ?>
                                            <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                            <span class="text-green-600 dark:text-green-400">Emotionally Processed</span>
                                            <?php else: ?>
                                            <i class="fas fa-clock text-orange-500 mr-1"></i>
                                            <span class="text-orange-600 dark:text-orange-400">Pending Emotion Processing</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center text-xs">
                                            <?php if ($interaction['mentioned_in_chat']): ?>
                                            <i class="fas fa-comment text-blue-500 mr-1"></i>
                                            <span class="text-blue-600 dark:text-blue-400">Mentioned in Chat</span>
                                            <?php else: ?>
                                            <i class="fas fa-comment-slash text-gray-400 mr-1"></i>
                                            <span class="text-gray-500 dark:text-gray-500">Not Mentioned Yet</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="px-6 py-12 text-center">
                        <div class="w-12 h-12 mx-auto bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mb-4">
                            <i class="fas fa-comments text-gray-400 text-xl"></i>
                        </div>
                        <p class="text-gray-500 dark:text-gray-400">No interactions found</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Emotional Impact History -->
            <?php if (!empty($emotionalHistory)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Emotional State History</h3>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($emotionalHistory as $session): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                        Chat Session - <?= date('M j, Y H:i', strtotime($session['last_message_at'])) ?>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500"><?= $session['message_count'] ?> messages</div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                                        <?= number_format(($session['avg_joy'] ?? 0.5) * 100) ?>%
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">Joy</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                        <?= number_format(($session['avg_sadness'] ?? 0.5) * 100) ?>%
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">Sadness</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-red-600 dark:text-red-400">
                                        <?= number_format(($session['avg_love'] ?? 0.5) * 100) ?>%
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">Love</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                                        <?= number_format(($session['avg_trust'] ?? 0.5) * 100) ?>%
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">Trust</div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Dialog Modal -->
<div id="dialogModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white">Full Dialog</h3>
            <button onclick="closeDialog()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div id="dialogContent" class="space-y-4">
            <!-- Dialog content will be loaded here -->
        </div>
    </div>
</div>

<script>
function showDialog(interactionId) {
    const modal = document.getElementById('dialogModal');
    const content = document.getElementById('dialogContent');
    
    // Show modal
    modal.classList.remove('hidden');
    
    // Load dialog content
    content.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin text-2xl text-gray-400"></i></div>';
    
    // Fetch dialog data
    fetch(`/api/social-dialog.php?interaction_id=${interactionId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayDialog(data.interaction);
            } else {
                content.innerHTML = '<div class="text-red-500">Error loading dialog: ' + data.error + '</div>';
            }
        })
        .catch(error => {
            content.innerHTML = '<div class="text-red-500">Error loading dialog: ' + error.message + '</div>';
        });
}

function displayDialog(interaction) {
    const content = document.getElementById('dialogContent');
    
    let html = `
        <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 mb-4">
            <div class="flex items-center justify-between mb-2">
                <h4 class="font-semibold text-gray-900 dark:text-white">${escapeHtml(interaction.contact_name)}</h4>
                <span class="text-xs text-gray-500 dark:text-gray-400">${formatDate(interaction.occurred_at)}</span>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">Context: ${escapeHtml(interaction.interaction_context)}</p>
            <p class="text-sm text-gray-600 dark:text-gray-400">Type: ${interaction.interaction_type.replace(/_/g, ' ')}</p>
        </div>
    `;
    
    // Contact message
    if (interaction.contact_message) {
        html += `
            <div class="flex items-start space-x-3 mb-4">
                <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-sm font-medium">
                    ${interaction.contact_name.charAt(0).toUpperCase()}
                </div>
                <div class="flex-1">
                    <div class="bg-blue-100 dark:bg-blue-900/30 rounded-lg p-3">
                        <p class="text-sm text-gray-900 dark:text-white">${escapeHtml(interaction.contact_message)}</p>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">${escapeHtml(interaction.contact_name)} • ${formatTime(interaction.occurred_at)}</div>
                </div>
            </div>
        `;
    }
    
    // AEI response
    if (interaction.aei_response) {
        html += `
            <div class="flex items-start space-x-3 mb-4">
                <div class="w-8 h-8 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white text-sm">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="flex-1">
                    <div class="bg-gradient-to-r from-purple-100 to-pink-100 dark:from-purple-900/30 dark:to-pink-900/30 rounded-lg p-3">
                        <p class="text-sm text-gray-900 dark:text-white">${escapeHtml(interaction.aei_response)}</p>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">${escapeHtml(interaction.aei_name)} • ${formatTime(interaction.occurred_at)}</div>
                </div>
            </div>
        `;
    }
    
    // AEI thoughts (internal)
    if (interaction.aei_thoughts) {
        html += `
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border-l-4 border-yellow-400 p-4 mt-4">
                <div class="flex items-start">
                    <div class="flex-shrink-0">
                        <i class="fas fa-brain text-yellow-400"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-yellow-800 dark:text-yellow-200">Internal Thoughts</p>
                        <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">${escapeHtml(interaction.aei_thoughts)}</p>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Status info
    html += `
        <div class="border-t border-gray-200 dark:border-gray-600 pt-4 mt-4">
            <div class="flex flex-wrap gap-2">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${interaction.processed_for_emotions ? 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400' : 'bg-orange-100 dark:bg-orange-900/20 text-orange-800 dark:text-orange-400'}">
                    <i class="fas ${interaction.processed_for_emotions ? 'fa-check-circle' : 'fa-clock'} mr-1"></i>
                    ${interaction.processed_for_emotions ? 'Emotionally Processed' : 'Pending Processing'}
                </span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${interaction.mentioned_in_chat ? 'bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400' : 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300'}">
                    <i class="fas ${interaction.mentioned_in_chat ? 'fa-comment' : 'fa-comment-slash'} mr-1"></i>
                    ${interaction.mentioned_in_chat ? 'Mentioned in Chat' : 'Not Mentioned Yet'}
                </span>
            </div>
        </div>
    `;
    
    content.innerHTML = html;
}

function closeDialog() {
    document.getElementById('dialogModal').classList.add('hidden');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

function formatTime(dateString) {
    const date = new Date(dateString);
    return date.toLocaleTimeString();
}

// Close modal when clicking outside
document.getElementById('dialogModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeDialog();
    }
});
</script>