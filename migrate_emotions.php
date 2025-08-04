<?php
/**
 * Migration script to initialize emotions for existing chat sessions
 * Run this once after implementing the emotion system
 */

include_once 'config/database.php';
include_once 'includes/emotions.php';

echo "Starting emotion migration...\n";

try {
    $emotions = new Emotions($pdo);
    
    // Find all chat sessions without emotional data
    $stmt = $pdo->prepare("SELECT id FROM chat_sessions WHERE aei_joy IS NULL");
    $stmt->execute();
    $sessionsToMigrate = $stmt->fetchAll();
    
    echo "Found " . count($sessionsToMigrate) . " sessions to migrate.\n";
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($sessionsToMigrate as $session) {
        $sessionId = $session['id'];
        
        echo "Migrating session: $sessionId...";
        
        if ($emotions->initializeSessionEmotions($sessionId)) {
            echo " ✓ Success\n";
            $successCount++;
        } else {
            echo " ✗ Failed\n";
            $errorCount++;
        }
    }
    
    echo "\nMigration completed!\n";
    echo "Success: $successCount sessions\n";
    echo "Errors: $errorCount sessions\n";
    
    // Optional: Backfill emotion data for existing AEI messages
    echo "\nChecking for AEI messages without emotion data...\n";
    
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.session_id 
        FROM chat_messages cm
        JOIN chat_sessions cs ON cm.session_id = cs.id
        WHERE cm.sender_type = 'aei' 
        AND cm.aei_joy IS NULL 
        AND cs.aei_joy IS NOT NULL
        LIMIT 100
    ");
    $stmt->execute();
    $messagesToMigrate = $stmt->fetchAll();
    
    echo "Found " . count($messagesToMigrate) . " AEI messages to migrate (limited to 100).\n";
    
    $messageSuccessCount = 0;
    $messageErrorCount = 0;
    
    foreach ($messagesToMigrate as $message) {
        $messageId = $message['id'];
        $sessionId = $message['session_id'];
        
        echo "Migrating message: $messageId...";
        
        // Get current session emotions and apply to message
        $currentEmotions = $emotions->getEmotionalState($sessionId);
        
        if ($emotions->storeMessageEmotions($messageId, $currentEmotions)) {
            echo " ✓ Success\n";
            $messageSuccessCount++;
        } else {
            echo " ✗ Failed\n";
            $messageErrorCount++;
        }
    }
    
    echo "\nMessage migration completed!\n";
    echo "Success: $messageSuccessCount messages\n";
    echo "Errors: $messageErrorCount messages\n";
    
} catch (Exception $e) {
    echo "Migration failed with error: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\nAll done! The emotion system is now ready.\n";
?>