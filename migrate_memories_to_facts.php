<?php
/**
 * Memory Migration Tool - Convert existing chat history to structured facts
 * 
 * This tool analyzes existing chat conversations and extracts structured facts
 * into the new aei_facts_* collections, while preserving old Q&A pairs as backup.
 * 
 * Usage: php migrate_memories_to_facts.php [aei_id]
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'includes/memory_manager_inference.php';
require_once 'includes/anthropic_api.php';

// Load memory configuration
if (!file_exists('config/memory_config.php')) {
    die("❌ Error: memory_config.php not found. Please configure memory system first.\n");
}

require_once 'config/memory_config.php';

// Verify required constants
if (!defined('QDRANT_URL') || !defined('QDRANT_API_KEY')) {
    die("❌ Error: QDRANT_URL or QDRANT_API_KEY not configured in memory_config.php\n");
}

echo "🧠 AEI Memory Migration Tool - Convert Chat History to Structured Facts\n";
echo "====================================================================\n\n";

// Get command line arguments
$targetAeiId = $argv[1] ?? null;

try {
    // Initialize memory manager with facts collection prefix
    $memoryOptions = [
        'default_model' => MEMORY_DEFAULT_MODEL,
        'quality_model' => MEMORY_QUALITY_MODEL,
        'collection_prefix' => 'aei_memories_', // Old Q&A collection
        'facts_prefix' => 'aei_facts_'          // New facts collection
    ];
    
    $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
    
    echo "✅ Memory Manager initialized successfully\n\n";
    
    // Get AEIs to migrate
    if ($targetAeiId) {
        $stmt = $pdo->prepare("SELECT id, name, user_id FROM aeis WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$targetAeiId]);
        $aeis = $stmt->fetchAll();
        
        if (empty($aeis)) {
            die("❌ Error: AEI with ID '$targetAeiId' not found or inactive\n");
        }
        
        echo "🎯 Migrating specific AEI: {$aeis[0]['name']} (ID: $targetAeiId)\n\n";
    } else {
        $stmt = $pdo->prepare("
            SELECT a.id, a.name, a.user_id, COUNT(cm.id) as message_count 
            FROM aeis a
            INNER JOIN chat_sessions cs ON a.id = cs.aei_id  
            INNER JOIN chat_messages cm ON cs.id = cm.session_id
            WHERE a.is_active = TRUE
            GROUP BY a.id, a.name, a.user_id
            HAVING message_count >= 10
            ORDER BY message_count DESC
        ");
        $stmt->execute();
        $aeis = $stmt->fetchAll();
        
        echo "📊 Found " . count($aeis) . " AEIs with sufficient chat history (>=10 messages)\n\n";
    }
    
    if (empty($aeis)) {
        die("ℹ️  No AEIs found with sufficient chat history to migrate\n");
    }
    
    $totalMigrated = 0;
    
    foreach ($aeis as $aei) {
        echo "🤖 Processing AEI: {$aei['name']} (ID: {$aei['id']})\n";
        echo "   Messages in history: {$aei['message_count']}\n";
        
        try {
            // Get all chat sessions for this AEI
            $stmt = $pdo->prepare("
                SELECT DISTINCT cs.id as session_id, COUNT(cm.id) as session_messages
                FROM chat_sessions cs
                INNER JOIN chat_messages cm ON cs.id = cm.session_id  
                WHERE cs.aei_id = ?
                GROUP BY cs.id
                HAVING session_messages >= 5
                ORDER BY cs.created_at DESC
            ");
            $stmt->execute([$aei['id']]);
            $sessions = $stmt->fetchAll();
            
            echo "   Sessions to process: " . count($sessions) . "\n";
            
            $aeiExtractedCount = 0;
            
            foreach ($sessions as $session) {
                echo "   📝 Processing session {$session['session_id']} ({$session['session_messages']} messages)...\n";
                
                // Get chat history for this session  
                $stmt = $pdo->prepare("
                    SELECT sender_type, message_text, created_at
                    FROM chat_messages 
                    WHERE session_id = ? 
                    ORDER BY created_at ASC
                ");
                $stmt->execute([$session['session_id']]);
                $chatMessages = $stmt->fetchAll();
                
                // Process in batches of 10 messages
                $batches = array_chunk($chatMessages, 10);
                
                foreach ($batches as $batchIndex => $batch) {
                    if (count($batch) < 3) continue; // Skip small batches
                    
                    echo "      Batch " . ($batchIndex + 1) . " (" . count($batch) . " messages)... ";
                    
                    try {
                        // Convert to getChatHistory format
                        $formattedMessages = [];
                        foreach ($batch as $msg) {
                            $formattedMessages[] = [
                                'role' => $msg['sender_type'] === 'user' ? 'user' : 'assistant',
                                'content' => $msg['message_text'],
                                'timestamp' => $msg['created_at']
                            ];
                        }
                        
                        // Extract memories from this batch
                        $extractedMemories = $memoryManager->extractMemoriesFromConversation(
                            $aei['id'],
                            $formattedMessages,
                            $aei['user_id'],
                            $session['session_id']
                        );
                        
                        $batchCount = count($extractedMemories);
                        $aeiExtractedCount += $batchCount;
                        
                        echo "✅ $batchCount facts extracted\n";
                        
                        // Small delay to avoid overwhelming APIs
                        sleep(1);
                        
                    } catch (Exception $batchError) {
                        echo "❌ Failed: " . $batchError->getMessage() . "\n";
                        error_log("Batch extraction failed for AEI {$aei['id']}, session {$session['session_id']}: " . $batchError->getMessage());
                    }
                }
                
                echo "   ✅ Session completed: $aeiExtractedCount total facts extracted\n";
            }
            
            echo "🎉 AEI Migration Complete: {$aei['name']} - $aeiExtractedCount total facts extracted\n\n";
            $totalMigrated += $aeiExtractedCount;
            
        } catch (Exception $aeiError) {
            echo "❌ AEI Migration Failed: {$aei['name']} - " . $aeiError->getMessage() . "\n\n";
            error_log("AEI migration failed for {$aei['id']}: " . $aeiError->getMessage());
        }
    }
    
    echo "🏁 MIGRATION COMPLETE\n";
    echo "====================\n";
    echo "Total AEIs processed: " . count($aeis) . "\n";
    echo "Total facts extracted: $totalMigrated\n";
    echo "Old Q&A memories preserved as backup in aei_memories_* collections\n";
    echo "New structured facts available in aei_facts_* collections\n\n";
    
    echo "🔍 Next Steps:\n";
    echo "1. Test new memory retrieval with existing AEIs\n";
    echo "2. Monitor system performance and memory quality\n";  
    echo "3. Optional: Archive old aei_memories_* collections after validation\n\n";
    
} catch (Exception $e) {
    echo "💥 CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    error_log("Memory migration critical error: " . $e->getMessage());
    exit(1);
}

echo "✅ Migration tool completed successfully!\n";
?>