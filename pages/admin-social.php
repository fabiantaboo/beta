<?php
requireAdmin();

// Ensure error logging is enabled
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../error.log');
error_log("Admin social page loaded");

require_once __DIR__ . '/../includes/background_social_processor.php';
require_once __DIR__ . '/../includes/social_contact_manager.php';

$processor = new BackgroundSocialProcessor($pdo);
$socialManager = new SocialContactManager($pdo);

$error = null;
$success = null;

// Handle POST actions
error_log("POST request received: " . json_encode($_POST));
error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
error_log("POST keys: " . implode(', ', array_keys($_POST)));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("CSRF token provided: " . ($_POST['csrf_token'] ?? 'NONE'));
    error_log("CSRF token valid: " . (verifyCSRFToken($_POST['csrf_token'] ?? '') ? 'YES' : 'NO'));
    
    if (verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $action = $_POST['action'] ?? '';
        error_log("=== ADMIN SOCIAL ACTION: '$action' ===");
        error_log("Action value type: " . gettype($action));
        error_log("Action empty check: " . (empty($action) ? 'EMPTY' : 'NOT EMPTY'));
        
        switch ($action) {
        case 'process_all':
            error_log("Process All button clicked - starting processing");
            $startTime = microtime(true);
            try {
                // Enhanced pre-check with detailed debugging info
                $stmt = $pdo->query("
                    SELECT 
                        COUNT(DISTINCT a.id) as total_aeis,
                        COUNT(DISTINCT CASE WHEN COALESCE(a.social_initialized, FALSE) = TRUE THEN a.id END) as social_aeis,
                        COUNT(DISTINCT CASE WHEN COALESCE(a.social_initialized, FALSE) = FALSE THEN a.id END) as uninitialized_aeis,
                        COUNT(DISTINCT c.id) as total_contacts
                    FROM aeis a
                    LEFT JOIN aei_social_contacts c ON a.id = c.aei_id AND c.is_active = TRUE
                    WHERE a.is_active = TRUE
                ");
                $preCheck = $stmt->fetch();
                
                // Log detailed pre-processing state for debugging
                error_log("ADMIN SOCIAL PROCESSING PRE-CHECK:");
                error_log("- Total AEIs: {$preCheck['total_aeis']}");
                error_log("- Initialized AEIs: {$preCheck['social_aeis']}");
                error_log("- Uninitialized AEIs: {$preCheck['uninitialized_aeis']}");
                error_log("- Total Contacts: {$preCheck['total_contacts']}");
                
                $result = $processor->processAllAEISocial();
                $executionTime = round(microtime(true) - $startTime, 2);
                
                if ($result['success']) {
                    $details = $result['details'] ?? [];
                    $message = "✅ ALL AEI PROCESSING COMPLETED!\n\n";
                    $message .= "⏱️ Execution time: {$executionTime}s\n\n";
                    $message .= "📊 System Status:\n";
                    $message .= "• {$preCheck['total_aeis']} total AEIs in system\n";
                    $message .= "• {$preCheck['social_aeis']} AEIs with social environment\n";
                    $message .= "• {$preCheck['uninitialized_aeis']} AEIs require initialization\n";
                    $message .= "• {$preCheck['total_contacts']} active contacts total\n\n";
                    
                    if ($details['total_aeis'] > 0) {
                        $message .= "📈 Processing Results:\n";
                        $message .= "• {$details['processed_successfully']}/{$details['total_aeis']} AEIs processed successfully\n";
                        $message .= "• {$details['total_interactions']} new interactions generated\n";
                        
                        if (!empty($details['processing_details'])) {
                            $message .= "\n🔍 AEI Details:\n";
                            $count = 0;
                            foreach ($details['processing_details'] as $aeiId => $aeiDetail) {
                                if ($count < 3) { // Show first 3
                                    $status = $aeiDetail['status'] === 'success' ? '✓' : '✗';
                                    $message .= "• $status {$aeiDetail['name']}: ";
                                    if ($aeiDetail['status'] === 'success') {
                                        $interactions = $aeiDetail['details']['interactions_generated'] ?? 0;
                                        $message .= "$interactions interactions\n";
                                    } else {
                                        $message .= "Error\n";
                                    }
                                }
                                $count++;
                            }
                        }
                    } else {
                        $message .= "ℹ️ No AEIs with social contacts found\n";
                        $message .= "\n💡 To use the Social System:\n";
                        $message .= "1. Initialize AEIs individually (Initialize Button)\n";
                        $message .= "2. Then run 'Process All' again\n";
                    }
                    
                    if (!empty($details['errors'])) {
                        $message .= "\n⚠️ Warnings:\n";
                        foreach (array_slice($details['errors'], 0, 3) as $err) {
                            $message .= "• " . $err . "\n";
                        }
                    }
                    $success = $message;
                } else {
                    $details = $result['details'] ?? [];
                    $errorMsg = "❌ PROCESSING ERROR\n\n";
                    $errorMsg .= "⏱️ Execution time: {$executionTime}s\n";
                    $errorMsg .= "Main error: " . ($result['error'] ?? 'Unknown error') . "\n";
                    if (isset($result['error_code'])) {
                        $errorMsg .= "Error Code: " . $result['error_code'] . "\n";
                    }
                    if (!empty($details['errors'])) {
                        $errorMsg .= "\nDetailed errors:\n";
                        foreach (array_slice($details['errors'], 0, 3) as $err) {
                            $errorMsg .= "• " . $err . "\n";
                        }
                    }
                    $errorMsg .= "\n📊 System Status:\n";
                    $errorMsg .= "• {$preCheck['total_aeis']} total AEIs in system\n";
                    $errorMsg .= "• {$preCheck['social_aeis']} AEIs with social environment\n";
                    $error = $errorMsg;
                }
            } catch (Exception $e) {
                $executionTime = round(microtime(true) - $startTime, 2);
                $error = "💥 CRITICAL ERROR\n\n⏱️ Error after: {$executionTime}s\nException: " . $e->getMessage() . "\n\nPlease check logs for details.";
            }
            break;
            
        case 'initialize_aei':
            $aeiId = $_POST['aei_id'] ?? '';
            if ($aeiId) {
                try {
                    $result = $processor->initializeAEISocialEnvironment($aeiId);
                    if ($result) {
                        $success = "✅ AEI SOCIAL ENVIRONMENT INITIALIZED\n\nSocial environment successfully set up for the AEI!";
                    } else {
                        $error = "❌ INITIALIZATION FAILED\n\nCould not set up social environment.\nPossible causes:\n• AEI already initialized\n• Database error\n• API problem";
                    }
                } catch (Exception $e) {
                    $error = "💥 INITIALIZATION ERROR\n\nException: " . $e->getMessage() . "\n\nPlease check logs.";
                }
            }
            break;
            
        case 'process_single':
            $aeiId = $_POST['aei_id'] ?? '';
            error_log("Process Single button clicked for AEI ID: $aeiId");
            if ($aeiId) {
                $startTime = microtime(true);
                try {
                    // Get AEI info first
                    $stmt = $pdo->prepare("SELECT name, social_initialized FROM aeis WHERE id = ?");
                    $stmt->execute([$aeiId]);
                    $aeiInfo = $stmt->fetch();
                    
                    $result = $processor->processSingleAEI($aeiId);
                    $executionTime = round(microtime(true) - $startTime, 2);
                    
                    if ($result['success']) {
                        $details = $result['details'] ?? [];
                        $message = "✅ AEI PROCESSED SUCCESSFULLY!\n\n";
                        $message .= "🤖 AEI: " . ($aeiInfo['name'] ?? 'Unbekannt') . "\n";
                        $message .= "⏱️ Execution time: {$executionTime}s\n\n";
                        $message .= "📊 Aktivitäten generiert:\n";
                        $message .= "• {$details['interactions_generated']} neue Interaktionen\n";
                        $message .= "• {$details['social_media_posts']} Social Media Posts\n";
                        $message .= "• {$details['group_events_created']} group events\n";
                        $message .= "• {$details['cross_contact_relationships']} cross-contact relationships\n";
                        $message .= "\n👥 {$details['contacts_processed']} contacts processed\n";
                        
                        if ($details['interactions_generated'] == 0 && $details['contacts_processed'] > 0) {
                            $message .= "\n💭 No new interactions generated - this is normal!\n";
                            $message .= "Interactions are created based on probabilities.\n";
                            $message .= "Contacts were still checked for life evolution.\n";
                        }
                        
                        if (!empty($details['warnings'])) {
                            $message .= "\n⚠️ Hinweise:\n";
                            foreach ($details['warnings'] as $warning) {
                                $message .= "• " . $warning . "\n";
                            }
                        }
                        
                        if (!empty($details['errors'])) {
                            $message .= "\n🔧 Teil-Fehler (nicht kritisch):\n";
                            foreach (array_slice($details['errors'], 0, 2) as $err) {
                                $message .= "• " . $err . "\n";
                            }
                        }
                        
                        $success = $message;
                    } else {
                        $details = $result['details'] ?? [];
                        $errorMsg = "❌ AEI PROCESSING FEHLER\n\n";
                        $errorMsg .= "🤖 AEI: " . ($aeiInfo['name'] ?? 'Unbekannt') . "\n";
                        $errorMsg .= "⏱️ Fehler nach: {$executionTime}s\n";
                        $errorMsg .= "Hauptfehler: " . ($result['error'] ?? 'Unbekannter Fehler') . "\n";
                        
                        if (isset($result['error_code'])) {
                            $errorMsg .= "Error Code: " . $result['error_code'] . "\n";
                        }
                        
                        if (!empty($details['errors'])) {
                            $errorMsg .= "\nDetailfehler:\n";
                            foreach (array_slice($details['errors'], 0, 3) as $err) {
                                $errorMsg .= "• " . $err . "\n";
                            }
                        }
                        
                        if (isset($details['contacts_processed'])) {
                            $errorMsg .= "\n📊 Versucht: {$details['contacts_processed']} Kontakte zu verarbeiten\n";
                        }
                        
                        $errorMsg .= "\n🔍 Debug-Info:\n";
                        $errorMsg .= "• Social Initialized: " . ($aeiInfo['social_initialized'] ? 'Ja' : 'Nein') . "\n";
                        $errorMsg .= "• AEI ID: " . $aeiId . "\n";
                        
                        $error = $errorMsg;
                    }
                } catch (Exception $e) {
                    $executionTime = round(microtime(true) - $startTime, 2);
                    $error = "💥 KRITISCHER PROCESSING FEHLER\n\n🤖 AEI: " . ($aeiInfo['name'] ?? 'Unbekannt') . "\n⏱️ Fehler nach: {$executionTime}s\nException: " . $e->getMessage() . "\n\nBitte Logs und Datenbankverbindung prüfen.";
                }
            } else {
                $error = "❌ FEHLER\n\nKeine AEI ID übermittelt. Bitte Seite neu laden und erneut versuchen.";
            }
            break;
            
        case 'cleanup':
            try {
                $count = $processor->cleanupOldInteractions();
                $success = "✅ CLEANUP ERFOLGREICH\n\n$count alte Interaktionen wurden erfolgreich entfernt.\n\nDatenbank ist jetzt bereinigt!";
            } catch (Exception $e) {
                $error = "❌ CLEANUP FEHLER\n\nException: " . $e->getMessage() . "\n\nKonnte alte Interaktionen nicht entfernen.";
            }
            break;
        }
    } else {
        error_log("CSRF token validation failed");
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

// Get ADVANCED detailed data for selected AEI
$selectedAeiId = $_GET['aei_id'] ?? '';
$selectedAeiDetails = null;
$recentInteractions = [];
$contacts = [];
$emotionalHistory = [];
$advancedStats = null;
$socialContext = null;
$groupEvents = [];
$socialMediaActivity = [];
$crossContactRelationships = [];
$conflictStatus = [];
$seasonalContext = null;
$predictiveInsights = null;

if ($selectedAeiId) {
    try {
        // Get AEI details
        $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ?");
        $stmt->execute([$selectedAeiId]);
        $selectedAeiDetails = $stmt->fetch();
        
        // Get ADVANCED social statistics
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(DISTINCT c.id) as total_contacts,
                AVG(c.relationship_strength) as avg_relationship_strength,
                AVG(c.trust_level) as avg_trust_level,
                AVG(c.intimacy_level) as avg_intimacy_level,
                COUNT(DISTINCT CASE WHEN i.is_conflict = TRUE AND i.resolution_status IS NULL THEN i.id END) as unresolved_conflicts,
                COUNT(DISTINCT CASE WHEN i.occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN i.id END) as recent_interactions,
                COUNT(DISTINCT CASE WHEN c.communication_frequency_trend = 'increasing' THEN c.id END) as improving_relationships,
                COUNT(DISTINCT CASE WHEN c.communication_frequency_trend = 'decreasing' THEN c.id END) as declining_relationships,
                COUNT(DISTINCT cr.id) as cross_contact_relationships,
                COUNT(DISTINCT CASE WHEN cr.creates_drama_potential = TRUE THEN cr.id END) as drama_potential_relationships
            FROM aei_social_contacts c
            LEFT JOIN aei_contact_interactions i ON c.id = i.contact_id
            LEFT JOIN aei_contact_relationships cr ON c.aei_id = cr.aei_id AND (c.id = cr.contact_a_id OR c.id = cr.contact_b_id)
            WHERE c.aei_id = ? AND c.is_active = TRUE
        ");
        $stmt->execute([$selectedAeiId]);
        $advancedStats = $stmt->fetch();
        
        // Get comprehensive social context
        $stmt = $pdo->prepare("SELECT * FROM aei_social_context WHERE aei_id = ?");
        $stmt->execute([$selectedAeiId]);
        $socialContext = $stmt->fetch();
        
        // Get recent interactions with FULL details
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                c.name as contact_name,
                c.relationship_type,
                c.relationship_strength,
                c.trust_level,
                c.intimacy_level,
                c.attachment_style,
                c.life_phase,
                c.psychological_profile,
                c.communication_frequency_trend,
                a.name as aei_name
            FROM aei_contact_interactions i
            JOIN aei_social_contacts c ON i.contact_id = c.id
            JOIN aeis a ON i.aei_id = a.id
            WHERE i.aei_id = ?
            ORDER BY i.occurred_at DESC
            LIMIT 25
        ");
        $stmt->execute([$selectedAeiId]);
        $recentInteractions = $stmt->fetchAll();
        
        // Get all contacts with ADVANCED details
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                COUNT(i.id) as total_interactions,
                MAX(i.occurred_at) as last_interaction,
                AVG(CASE WHEN i.relationship_impact IS NOT NULL THEN i.relationship_impact ELSE 0 END) as avg_impact,
                COUNT(CASE WHEN i.is_conflict = TRUE AND i.resolution_status IS NULL THEN 1 END) as active_conflicts,
                COUNT(CASE WHEN i.memory_importance_score > 0.7 THEN 1 END) as significant_memories
            FROM aei_social_contacts c
            LEFT JOIN aei_contact_interactions i ON c.id = i.contact_id
            WHERE c.aei_id = ? AND c.is_active = TRUE
            GROUP BY c.id
            ORDER BY c.trust_level DESC, c.intimacy_level DESC, total_interactions DESC
        ");
        $stmt->execute([$selectedAeiId]);
        $contacts = $stmt->fetchAll();
        
        // Get group events
        $stmt = $pdo->prepare("
            SELECT * FROM aei_group_events 
            WHERE aei_id = ? 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $stmt->execute([$selectedAeiId]);
        $groupEvents = $stmt->fetchAll();
        
        // Get social media activity
        $stmt = $pdo->prepare("
            SELECT sms.*, c.name as contact_name
            FROM aei_social_media_simulation sms
            JOIN aei_social_contacts c ON sms.contact_id = c.id
            WHERE sms.aei_id = ?
            ORDER BY sms.posted_at DESC
            LIMIT 15
        ");
        $stmt->execute([$selectedAeiId]);
        $socialMediaActivity = $stmt->fetchAll();
        
        // Get cross-contact relationships
        $stmt = $pdo->prepare("
            SELECT 
                cr.*,
                ca.name as contact_a_name,
                cb.name as contact_b_name
            FROM aei_contact_relationships cr
            JOIN aei_social_contacts ca ON cr.contact_a_id = ca.id
            JOIN aei_social_contacts cb ON cr.contact_b_id = cb.id
            WHERE cr.aei_id = ?
            ORDER BY cr.creates_drama_potential DESC, cr.relationship_strength DESC
        ");
        $stmt->execute([$selectedAeiId]);
        $crossContactRelationships = $stmt->fetchAll();
        
        // Get conflict status
        $stmt = $pdo->prepare("
            SELECT 
                i.conflict_category, 
                i.resolution_status,
                i.resolution_method,
                i.occurred_at,
                c.name as contact_name
            FROM aei_contact_interactions i
            JOIN aei_social_contacts c ON i.contact_id = c.id
            WHERE i.aei_id = ? AND i.is_conflict = TRUE
            ORDER BY i.occurred_at DESC
            LIMIT 10
        ");
        $stmt->execute([$selectedAeiId]);
        $conflictStatus = $stmt->fetchAll();
        
        // Get seasonal/cultural context
        $stmt = $pdo->prepare("SELECT * FROM aei_seasonal_cultural_context WHERE aei_id = ?");
        $stmt->execute([$selectedAeiId]);
        $seasonalContext = $stmt->fetch();
        
        // Get predictive insights
        $stmt = $pdo->prepare("SELECT * FROM aei_predictive_social_ai WHERE aei_id = ?");
        $stmt->execute([$selectedAeiId]);
        $predictiveInsights = $stmt->fetch();
        
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
        error_log("Error getting ADVANCED AEI data: " . $e->getMessage());
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
                
                <div class="space-y-4">
                    <!-- Process All Form -->
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="process_all">
                        <button type="submit" 
                                class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-ayuni-blue hover:bg-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ayuni-blue transition-colors">
                            <i class="fas fa-play mr-2"></i>
                            Process All AEI Social Environments
                        </button>
                    </form>
                    
                    <!-- Cleanup Form -->
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="cleanup">
                        <button type="submit"
                                class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors">
                            <i class="fas fa-trash-alt mr-2"></i>
                            Cleanup Old Interactions
                        </button>
                    </form>
                </div>
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
                                <div class="flex space-x-2">
                                    <?php if (!$aei['social_initialized']): ?>
                                    <form method="post" class="inline-flex">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="aei_id" value="<?= $aei['id'] ?>">
                                        <input type="hidden" name="action" value="initialize_aei">
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-xs leading-4 font-medium rounded-md text-white bg-ayuni-aqua hover:bg-ayuni-aqua/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ayuni-aqua transition-colors">
                                            <i class="fas fa-plus mr-1"></i>
                                            Initialize
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" class="inline-flex">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="aei_id" value="<?= $aei['id'] ?>">
                                        <input type="hidden" name="action" value="process_single">
                                        <button type="submit"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-xs leading-4 font-medium rounded-md text-white bg-ayuni-blue hover:bg-ayuni-blue/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-ayuni-blue transition-colors">
                                            <i class="fas fa-sync mr-1"></i>
                                            Process
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                
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
                                <th class="text-left py-3 px-6 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Initiated By</th>
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
                                        $initiatorColors = [
                                            'aei' => 'bg-indigo-100 dark:bg-indigo-900/20 text-indigo-800 dark:text-indigo-400',
                                            'contact' => 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400',
                                            'system' => 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300'
                                        ];
                                        echo $initiatorColors[$interaction['initiated_by'] ?? 'contact'] ?? $initiatorColors['contact'];
                                        ?>">
                                        <?php
                                        $initiatorLabels = [
                                            'aei' => '🤖 AEI',
                                            'contact' => '👤 Contact', 
                                            'system' => '⚙️ System'
                                        ];
                                        echo $initiatorLabels[$interaction['initiated_by'] ?? 'contact'] ?? '👤 Contact';
                                        ?>
                                    </span>
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
                                    <?php if (!empty($interaction['dialog_history'])): ?>
                                        <?php 
                                        $dialogHistory = json_decode($interaction['dialog_history'], true);
                                        $turnCount = is_array($dialogHistory) ? count($dialogHistory) : 0;
                                        ?>
                                        <div class="text-xs text-blue-600 dark:text-blue-400 mt-1">
                                            💬 Multi-turn dialog (<?= $turnCount ?> turns)
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($interaction['contact_message']): ?>
                                    <div class="text-xs text-gray-500 dark:text-gray-500 mt-1 max-w-xs truncate">
                                        👤 "<?= htmlspecialchars($interaction['contact_message']) ?>"
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($interaction['aei_response'] && $interaction['initiated_by'] === 'aei'): ?>
                                    <div class="text-xs text-indigo-600 dark:text-indigo-400 mt-1 max-w-xs truncate">
                                        🤖 "<?= htmlspecialchars($interaction['aei_response']) ?>"
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

            <!-- Advanced Social Metrics Dashboard -->
            <?php if ($advancedStats): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Advanced Social Metrics</h3>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Relationship Quality Metrics -->
                        <div class="text-center p-4 bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                <?= number_format($advancedStats['avg_trust_level'] ?? 0, 1) ?>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Average Trust Level</div>
                            <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                Range: 0.0 - 1.0
                            </div>
                        </div>
                        
                        <div class="text-center p-4 bg-gradient-to-br from-pink-50 to-red-50 dark:from-pink-900/20 dark:to-red-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-pink-600 dark:text-pink-400">
                                <?= number_format($advancedStats['avg_intimacy_level'] ?? 0, 1) ?>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Average Intimacy Level</div>
                            <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                Emotional closeness
                            </div>
                        </div>
                        
                        <!-- Relationship Dynamics -->
                        <div class="text-center p-4 bg-gradient-to-br from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                                <?= $advancedStats['improving_relationships'] ?? 0 ?>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Improving Relationships</div>
                            <div class="text-xs text-red-500 dark:text-red-400 mt-1">
                                <?= $advancedStats['declining_relationships'] ?? 0 ?> declining
                            </div>
                        </div>
                        
                        <!-- Conflict Status -->
                        <div class="text-center p-4 bg-gradient-to-br from-orange-50 to-red-50 dark:from-orange-900/20 dark:to-red-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                                <?= $advancedStats['unresolved_conflicts'] ?? 0 ?>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Active Conflicts</div>
                            <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                Need attention
                            </div>
                        </div>
                        
                        <!-- Cross-Contact Network -->
                        <div class="text-center p-4 bg-gradient-to-br from-purple-50 to-violet-50 dark:from-purple-900/20 dark:to-violet-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                                <?= $advancedStats['cross_contact_relationships'] ?? 0 ?>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Cross-Contact Links</div>
                            <div class="text-xs text-yellow-500 dark:text-yellow-400 mt-1">
                                <?= $advancedStats['drama_potential_relationships'] ?? 0 ?> drama potential
                            </div>
                        </div>
                        
                        <!-- Social Activity -->
                        <div class="text-center p-4 bg-gradient-to-br from-cyan-50 to-blue-50 dark:from-cyan-900/20 dark:to-blue-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-cyan-600 dark:text-cyan-400">
                                <?= $advancedStats['recent_interactions'] ?? 0 ?>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Weekly Interactions</div>
                            <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                Last 7 days
                            </div>
                        </div>
                        
                        <!-- Overall Health Score -->
                        <?php 
                        $healthScore = 0;
                        if ($advancedStats['total_contacts'] > 0) {
                            $trustScore = ($advancedStats['avg_trust_level'] ?? 0) * 25;
                            $intimacyScore = ($advancedStats['avg_intimacy_level'] ?? 0) * 25;
                            $activityScore = min(($advancedStats['recent_interactions'] ?? 0) * 2, 25);
                            $conflictPenalty = ($advancedStats['unresolved_conflicts'] ?? 0) * -5;
                            $healthScore = max(0, min(100, $trustScore + $intimacyScore + $activityScore + $conflictPenalty));
                        }
                        $healthColor = $healthScore >= 70 ? 'green' : ($healthScore >= 40 ? 'yellow' : 'red');
                        ?>
                        <div class="text-center p-4 bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20 rounded-lg">
                            <div class="text-2xl font-bold text-<?= $healthColor ?>-600 dark:text-<?= $healthColor ?>-400">
                                <?= number_format($healthScore) ?>%
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">Social Health Score</div>
                            <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                Overall well-being
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Cross-Contact Relationships Network -->
            <?php if (!empty($crossContactRelationships)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Cross-Contact Relationship Network</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">How AEI's contacts relate to each other</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($crossContactRelationships as $relationship): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex items-center justify-between mb-3">
                                <div class="flex items-center space-x-3">
                                    <div class="flex items-center space-x-2">
                                        <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-xs font-medium">
                                            <?= strtoupper(substr($relationship['contact_a_name'], 0, 1)) ?>
                                        </div>
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($relationship['contact_a_name']) ?>
                                        </span>
                                    </div>
                                    <div class="text-gray-400">
                                        <i class="fas fa-arrow-right"></i>
                                    </div>
                                    <div class="flex items-center space-x-2">
                                        <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center text-white text-xs font-medium">
                                            <?= strtoupper(substr($relationship['contact_b_name'], 0, 1)) ?>
                                        </div>
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($relationship['contact_b_name']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <?= $relationship['relationship_strength'] ?>%
                                    </span>
                                    <?php if ($relationship['creates_drama_potential']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400">
                                        <i class="fas fa-exclamation-triangle mr-1"></i>
                                        Drama Risk
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                <strong>Relationship Type:</strong> <?= str_replace('_', ' ', ucwords($relationship['relationship_type'])) ?>
                            </div>
                            
                            <?php if ($relationship['relationship_history']): ?>
                            <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">
                                <strong>History:</strong> <?= htmlspecialchars($relationship['relationship_history']) ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($relationship['affects_aei_interactions']): ?>
                            <div class="mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs text-blue-700 dark:text-blue-300">
                                <i class="fas fa-info-circle mr-1"></i>
                                This relationship influences how the AEI interacts with both contacts
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-3 text-xs text-gray-500 dark:text-gray-500">
                                Mutual awareness: <?= $relationship['mutual_awareness_level'] ? 'High' : 'Low' ?> • 
                                Created: <?= date('M j, Y', strtotime($relationship['created_at'])) ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Social Media Activity Simulation -->
            <?php if (!empty($socialMediaActivity)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Social Media Activity Simulation</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Virtual social media posts from AEI's contacts</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($socialMediaActivity as $activity): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex items-start space-x-3">
                                <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-pink-500 rounded-full flex items-center justify-center text-white text-sm font-medium">
                                    <?= strtoupper(substr($activity['contact_name'], 0, 1)) ?>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <span class="font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($activity['contact_name']) ?>
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                            <?php 
                                            $platformColors = [
                                                'instagram' => 'bg-pink-100 dark:bg-pink-900/20 text-pink-800 dark:text-pink-400',
                                                'facebook' => 'bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400',
                                                'twitter' => 'bg-cyan-100 dark:bg-cyan-900/20 text-cyan-800 dark:text-cyan-400',
                                                'linkedin' => 'bg-indigo-100 dark:bg-indigo-900/20 text-indigo-800 dark:text-indigo-400'
                                            ];
                                            echo $platformColors[$activity['platform']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300';
                                            ?>">
                                            <?= ucfirst($activity['platform']) ?>
                                        </span>
                                        <span class="text-xs text-gray-500 dark:text-gray-500">
                                            <?= date('M j, H:i', strtotime($activity['posted_at'])) ?>
                                        </span>
                                    </div>
                                    
                                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-3 mb-3">
                                        <p class="text-sm text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($activity['post_content']) ?>
                                        </p>
                                    </div>
                                    
                                    <?php if ($activity['aei_reaction']): ?>
                                    <div class="flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-500">
                                        <div class="flex items-center space-x-1">
                                            <i class="fas fa-heart text-red-500"></i>
                                            <span><?= $activity['likes_count'] ?? 0 ?></span>
                                        </div>
                                        <div class="flex items-center space-x-1">
                                            <i class="fas fa-comment text-blue-500"></i>
                                            <span><?= $activity['comments_count'] ?? 0 ?></span>
                                        </div>
                                        <div class="flex items-center space-x-1">
                                            <i class="fas fa-share text-green-500"></i>
                                            <span><?= $activity['shares_count'] ?? 0 ?></span>
                                        </div>
                                    </div>
                                    
                                    <?php if ($activity['aei_comment']): ?>
                                    <div class="mt-3 border-l-2 border-purple-200 dark:border-purple-700 pl-3">
                                        <div class="text-xs text-purple-600 dark:text-purple-400 font-medium mb-1">
                                            <?= htmlspecialchars($selectedAeiDetails['name']) ?> commented:
                                        </div>
                                        <p class="text-sm text-gray-700 dark:text-gray-300">
                                            <?= htmlspecialchars($activity['aei_comment']) ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Group Events & Social Gatherings -->
            <?php if (!empty($groupEvents)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Group Events & Social Gatherings</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Multi-contact social interactions</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($groupEvents as $event): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <h4 class="font-semibold text-gray-900 dark:text-white">
                                        <?= htmlspecialchars($event['event_type']) ?>
                                    </h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <?= htmlspecialchars($event['event_description']) ?>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <div class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <?= $event['participants_count'] ?> participants
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">
                                        <?= date('M j, Y', strtotime($event['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($event['participant_contacts']): 
                            $participants = json_decode($event['participant_contacts'], true);
                            if ($participants): ?>
                            <div class="mb-3">
                                <div class="text-xs font-medium text-gray-500 dark:text-gray-500 mb-2">Participants:</div>
                                <div class="flex flex-wrap gap-2">
                                    <?php foreach ($participants as $participant): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400">
                                        <?= htmlspecialchars($participant) ?>
                                    </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; endif; ?>
                            
                            <?php if ($event['social_dynamics_created']): ?>
                            <div class="p-2 bg-green-50 dark:bg-green-900/20 rounded text-xs text-green-700 dark:text-green-300">
                                <i class="fas fa-users mr-1"></i>
                                This event created new social dynamics between participants
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Conflict Resolution Tracking -->
            <?php if (!empty($conflictStatus)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Conflict Resolution Status</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Tracking relationship conflicts and resolutions</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php foreach ($conflictStatus as $conflict): ?>
                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div>
                                    <div class="flex items-center space-x-2 mb-2">
                                        <span class="font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($conflict['contact_name']) ?>
                                        </span>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium 
                                            <?php 
                                            $categoryColors = [
                                                'miscommunication' => 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400',
                                                'value_difference' => 'bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400',
                                                'boundary_issue' => 'bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400',
                                                'jealousy' => 'bg-purple-100 dark:bg-purple-900/20 text-purple-800 dark:text-purple-400',
                                                'trust_issue' => 'bg-orange-100 dark:bg-orange-900/20 text-orange-800 dark:text-orange-400'
                                            ];
                                            echo $categoryColors[$conflict['conflict_category']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300';
                                            ?>">
                                            <?= str_replace('_', ' ', ucwords($conflict['conflict_category'])) ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500 dark:text-gray-500">
                                        <?= date('M j, Y H:i', strtotime($conflict['occurred_at'])) ?>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <?php if ($conflict['resolution_status']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium 
                                        <?php 
                                        $statusColors = [
                                            'resolved' => 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400',
                                            'partially_resolved' => 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400',
                                            'escalated' => 'bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400'
                                        ];
                                        echo $statusColors[$conflict['resolution_status']] ?? 'bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300';
                                        ?>">
                                        <i class="fas <?= $conflict['resolution_status'] === 'resolved' ? 'fa-check-circle' : ($conflict['resolution_status'] === 'escalated' ? 'fa-exclamation-triangle' : 'fa-clock') ?> mr-1"></i>
                                        <?= str_replace('_', ' ', ucwords($conflict['resolution_status'])) ?>
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400">
                                        <i class="fas fa-exclamation-circle mr-1"></i>
                                        Unresolved
                                    </span>
                                    <?php endif; ?>
                                    
                                    <?php if ($conflict['resolution_method']): ?>
                                    <div class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                        Method: <?= str_replace('_', ' ', ucwords($conflict['resolution_method'])) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Seasonal & Cultural Context -->
            <?php if ($seasonalContext): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Seasonal & Cultural Context</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Environmental factors influencing social behavior</p>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-3">Current Context</h4>
                            <div class="space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Season:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?= ucfirst($seasonalContext['current_season'] ?? 'Unknown') ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Cultural Period:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?= htmlspecialchars($seasonalContext['cultural_period'] ?? 'Normal') ?>
                                    </span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-600 dark:text-gray-400">Social Energy Modifier:</span>
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        <?= number_format(($seasonalContext['social_energy_modifier'] ?? 1.0) * 100 - 100) ?>%
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-3">Active Influences</h4>
                            <?php 
                            $influences = json_decode($seasonalContext['active_influences'] ?? '[]', true);
                            if (!empty($influences)): ?>
                            <div class="space-y-2">
                                <?php foreach ($influences as $influence): ?>
                                <span class="inline-block px-2 py-1 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400">
                                    <?= htmlspecialchars($influence) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-sm text-gray-500 dark:text-gray-500">No special influences active</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <?php if ($seasonalContext['context_description']): ?>
                    <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <p class="text-sm text-gray-700 dark:text-gray-300">
                            <?= htmlspecialchars($seasonalContext['context_description']) ?>
                        </p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Predictive Social AI Insights -->
            <?php if ($predictiveInsights): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Predictive Social AI Insights</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">AI-generated predictions and coaching recommendations</p>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-3">Relationship Predictions</h4>
                            <?php 
                            $predictions = json_decode($predictiveInsights['relationship_predictions'] ?? '[]', true);
                            if (!empty($predictions)): ?>
                            <div class="space-y-3">
                                <?php foreach ($predictions as $prediction): ?>
                                <div class="p-3 border border-gray-200 dark:border-gray-700 rounded-lg">
                                    <div class="flex items-start justify-between mb-2">
                                        <span class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($prediction['contact'] ?? 'Unknown') ?>
                                        </span>
                                        <span class="text-xs px-2 py-1 rounded 
                                            <?php 
                                            $confidence = $prediction['confidence'] ?? 0;
                                            if ($confidence >= 0.8) echo 'bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400';
                                            else if ($confidence >= 0.6) echo 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-800 dark:text-yellow-400';
                                            else echo 'bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400';
                                            ?>">
                                            <?= number_format($confidence * 100) ?>% confidence
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <?= htmlspecialchars($prediction['prediction'] ?? '') ?>
                                    </p>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-sm text-gray-500 dark:text-gray-500">No predictions available</p>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-3">Coaching Recommendations</h4>
                            <?php 
                            $coaching = json_decode($predictiveInsights['coaching_recommendations'] ?? '[]', true);
                            if (!empty($coaching)): ?>
                            <div class="space-y-3">
                                <?php foreach ($coaching as $recommendation): ?>
                                <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                    <div class="flex items-start space-x-2">
                                        <i class="fas fa-lightbulb text-blue-500 mt-0.5"></i>
                                        <div>
                                            <div class="text-sm font-medium text-blue-900 dark:text-blue-100">
                                                <?= htmlspecialchars($recommendation['category'] ?? 'General') ?>
                                            </div>
                                            <p class="text-sm text-blue-700 dark:text-blue-300 mt-1">
                                                <?= htmlspecialchars($recommendation['suggestion'] ?? '') ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-sm text-gray-500 dark:text-gray-500">No coaching recommendations available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                            <div>
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= number_format($predictiveInsights['social_stability_score'] ?? 0, 2) ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Stability Score</div>
                            </div>
                            <div>
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= number_format($predictiveInsights['growth_potential'] ?? 0, 2) ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Growth Potential</div>
                            </div>
                            <div>
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= number_format($predictiveInsights['conflict_risk_assessment'] ?? 0, 2) ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Conflict Risk</div>
                            </div>
                            <div>
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= date('M j', strtotime($predictiveInsights['last_analysis_date'] ?? 'now')) ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Last Analysis</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Social Context Summary for Chat -->
            <?php if ($socialContext): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Current Social Context for Chat</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">What the AEI knows about their social environment</p>
                </div>
                <div class="p-6">
                    <div class="space-y-4">
                        <?php if ($socialContext['recent_social_summary']): ?>
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-2">Recent Social Summary</h4>
                            <p class="text-sm text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700 p-3 rounded-lg">
                                <?= htmlspecialchars($socialContext['recent_social_summary']) ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($socialContext['current_social_concerns']): ?>
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-2">Current Concerns</h4>
                            <p class="text-sm text-gray-700 dark:text-gray-300 bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg">
                                <?= htmlspecialchars($socialContext['current_social_concerns']) ?>
                            </p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($socialContext['topics_to_mention']): 
                        $topics = json_decode($socialContext['topics_to_mention'], true);
                        if (!empty($topics)): ?>
                        <div>
                            <h4 class="font-medium text-gray-900 dark:text-white mb-2">Topics to Mention</h4>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($topics as $topic): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400">
                                    <?= htmlspecialchars($topic) ?>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; endif; ?>
                        
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                            <div class="text-center">
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= $socialContext['social_energy_level'] ?? 50 ?>%
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Social Energy</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= $socialContext['emotional_support_burden_score'] ?? 0 ?>%
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Support Burden</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= $socialContext['unprocessed_interactions_count'] ?? 0 ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Unprocessed</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <?= date('M j', strtotime($socialContext['last_social_update'] ?? 'now')) ?>
                                </div>
                                <div class="text-xs text-gray-500 dark:text-gray-500">Last Update</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

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
    
    // Check if this is a multi-turn dialog from the updated API
    if (interaction.dialog_history && Array.isArray(interaction.dialog_history) && interaction.dialog_history.length > 0) {
        html += `<div class="mb-4">
            <h5 class="text-sm font-semibold text-gray-900 dark:text-white mb-3">💬 Multi-turn Dialog (${interaction.dialog_history.length} turns)</h5>
        </div>`;
        
        // Display each turn in the dialog
        interaction.dialog_history.forEach((turn, index) => {
            const isAEI = turn.sender === 'aei';
            const senderName = isAEI ? interaction.aei_name : interaction.contact_name;
            
            html += `
                <div class="flex items-start space-x-3 mb-4">
                    <div class="w-8 h-8 ${isAEI ? 'bg-gradient-to-r from-purple-500 to-pink-500' : 'bg-blue-500'} rounded-full flex items-center justify-center text-white text-sm font-medium">
                        ${isAEI ? '<i class="fas fa-robot"></i>' : senderName.charAt(0).toUpperCase()}
                    </div>
                    <div class="flex-1">
                        <div class="${isAEI ? 'bg-gradient-to-r from-purple-100 to-pink-100 dark:from-purple-900/30 dark:to-pink-900/30' : 'bg-blue-100 dark:bg-blue-900/30'} rounded-lg p-3">
                            <p class="text-sm text-gray-900 dark:text-white">${escapeHtml(turn.message)}</p>
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                            ${isAEI ? '🤖' : '👤'} ${escapeHtml(senderName)} • Turn ${turn.turn || (index + 1)}
                        </div>
                    </div>
                </div>
            `;
        });
    } else {
        // Fallback to old format if no dialog_history
        
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
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">👤 ${escapeHtml(interaction.contact_name)} • ${formatTime(interaction.occurred_at)}</div>
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
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">🤖 ${escapeHtml(interaction.aei_name)} • ${formatTime(interaction.occurred_at)}</div>
                    </div>
                </div>
            `;
        }
        
        // Show notice if no dialog content
        if (!interaction.contact_message && !interaction.aei_response) {
            html += `
                <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                    <i class="fas fa-comment-slash text-2xl mb-2"></i>
                    <p class="text-sm">No dialog content available</p>
                    <p class="text-xs mt-1">This interaction may be from the old format</p>
                </div>
            `;
        }
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

// Enhanced form submission with loading state
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', function(e) {
        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            const originalText = submitButton.innerHTML;
            
            // Show loading state
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Verarbeitung...';
            submitButton.disabled = true;
            
            // Reset after form submission (for page reload case)
            setTimeout(() => {
                submitButton.innerHTML = originalText;
                submitButton.disabled = false;
            }, 1000);
        }
    });
});
</script>