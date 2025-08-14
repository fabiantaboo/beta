<?php
/**
 * AEI Memory System Setup - Qdrant Inference 2025
 * NO OpenAI dependencies - everything runs on Qdrant Cloud!
 * 
 * Run this once to initialize the memory system after configuration
 */

require_once 'config/database.php';

echo "🧠 Ayuni Memory System Setup 2025 - Qdrant Inference\n";
echo "=====================================================\n\n";

// Check if memory config exists
if (!file_exists('config/memory_config.php')) {
    echo "❌ Memory configuration not found!\n";
    echo "📋 Please copy config/memory_config.example.php to config/memory_config.php\n\n";
    echo "You need:\n";
    echo "1. Qdrant Cloud PAID account: https://cloud.qdrant.io/\n";
    echo "   (Inference API is only available on paid clusters)\n";
    echo "2. Create a cluster and get your API key\n";
    echo "3. Update the config file with your cluster URL and API key\n\n";
    exit(1);
}

require_once 'config/memory_config.php';
require_once 'includes/memory_manager_inference.php';

// Check required constants
$required = ['QDRANT_URL', 'QDRANT_API_KEY'];
$missing = [];

foreach ($required as $const) {
    if (!defined($const) || empty(constant($const))) {
        $missing[] = $const;
    }
}

if (!empty($missing)) {
    echo "❌ Missing required configuration:\n";
    foreach ($missing as $const) {
        echo "   - $const\n";
    }
    echo "\n📋 Please update config/memory_config.php with your Qdrant credentials\n";
    exit(1);
}

try {
    echo "🔧 Initializing Qdrant Inference Memory Manager...\n";
    
    // Test database connection
    echo "📊 Testing database connection... ";
    $pdo->query("SELECT 1");
    echo "✅ OK\n";
    
    // Initialize Memory Manager
    echo "🚀 Testing Qdrant Inference connection... ";
    $memoryOptions = [
        'default_model' => MEMORY_DEFAULT_MODEL ?? 'sentence-transformers/all-MiniLM-L6-v2',
        'quality_model' => MEMORY_QUALITY_MODEL ?? 'mixedbread-ai/mxbai-embed-large-v1',
        'collection_prefix' => MEMORY_COLLECTION_PREFIX ?? 'aei_memories_'
    ];
    
    $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
    echo "✅ OK\n";
    
    // Test Qdrant health
    echo "🏥 Testing Qdrant cluster health... ";
    $health = (new QdrantInferenceClient(QDRANT_URL, QDRANT_API_KEY))->healthCheck();
    if ($health['status'] === 'healthy') {
        echo "✅ OK (Collections: {$health['collections']})\n";
    } else {
        throw new Exception("Cluster unhealthy: " . ($health['error'] ?? 'Unknown error'));
    }
    
    // Check if aei_memories table exists and has correct structure
    echo "🗃️  Checking database schema... ";
    $result = $pdo->query("SHOW TABLES LIKE 'aei_memories'")->fetch();
    if ($result) {
        // Check for embedding_model column
        $columns = $pdo->query("SHOW COLUMNS FROM aei_memories")->fetchAll(PDO::FETCH_COLUMN);
        if (in_array('embedding_model', $columns)) {
            echo "✅ OK (with embedding_model column)\n";
        } else {
            echo "⚠️  Missing embedding_model column - running migration...\n";
            $pdo->exec("ALTER TABLE aei_memories ADD COLUMN embedding_model VARCHAR(100) DEFAULT 'sentence-transformers/all-MiniLM-L6-v2' AFTER importance_score");
            echo "✅ Migration completed\n";
        }
    } else {
        echo "❌ Missing aei_memories table\n";
        echo "📋 Please run your application once to create database tables automatically\n";
        exit(1);
    }
    
    // Test memory storage and retrieval with Inference API
    echo "💾 Testing memory storage with Qdrant Inference...\n";
    
    // Get a test AEI (first active AEI)
    $stmt = $pdo->query("SELECT id, name FROM aeis WHERE is_active = TRUE LIMIT 1");
    $testAei = $stmt->fetch();
    
    if (!$testAei) {
        echo "⚠️  No active AEIs found - skipping memory test\n";
        echo "✅ Basic setup completed successfully!\n\n";
        echo "🎯 Next Steps:\n";
        echo "1. Create some AEIs in your application\n";
        echo "2. Start chatting - memories will be automatically extracted and stored\n";
        echo "3. Enable MEMORY_DEBUG in config to see memory extraction in logs\n";
        exit(0);
    }
    
    // Test memory storage with automatic embedding
    $testMemoryId = $memoryManager->storeMemory(
        $testAei['id'], 
        "This is a test memory to verify the Qdrant Inference system is working correctly with automatic embedding generation", 
        'fact', 
        0.8
    );
    
    if ($testMemoryId) {
        echo "   ✅ Memory storage: OK (ID: $testMemoryId)\n";
        
        // Test retrieval with semantic search
        $memories = $memoryManager->retrieveMemories($testAei['id'], "verify system working embedding", 1);
        if (!empty($memories) && $memories[0]['memory_id'] === $testMemoryId) {
            echo "   ✅ Memory retrieval: OK (Similarity: " . number_format($memories[0]['similarity_score'], 3) . ")\n";
            echo "   📊 Model used: " . $memories[0]['model_used'] . "\n";
            
            // Test memory extraction
            echo "   🤖 Testing memory extraction...\n";
            $testMessages = [
                ['sender_type' => 'user', 'message_text' => 'Hi, I love hiking in the mountains and I work as a software engineer at Google'],
                ['sender_type' => 'aei', 'message_text' => 'That sounds amazing! I would love to hear more about your hiking adventures.']
            ];
            
            $extractedMemories = $memoryManager->extractMemoriesFromConversation(
                $testAei['id'], 
                $testMessages, 
                'test_user', 
                'test_session'
            );
            
            if (!empty($extractedMemories)) {
                echo "   ✅ Memory extraction: OK (" . count($extractedMemories) . " memories extracted)\n";
                foreach ($extractedMemories as $memory) {
                    echo "      - {$memory['type']}: {$memory['content']} (importance: {$memory['importance']})\n";
                }
            } else {
                echo "   ⚠️  Memory extraction returned no memories (check Anthropic API configuration)\n";
            }
            
            // Clean up test memories
            $allTestMemoryIds = [$testMemoryId];
            foreach ($extractedMemories as $memory) {
                $allTestMemoryIds[] = $memory['memory_id'];
            }
            
            $stmt = $pdo->prepare("DELETE FROM aei_memories WHERE memory_id IN (" . str_repeat('?,', count($allTestMemoryIds) - 1) . "?)");
            $stmt->execute($allTestMemoryIds);
            
            // Clean up from Qdrant
            try {
                $qdrantClient = new QdrantInferenceClient(QDRANT_URL, QDRANT_API_KEY);
                $qdrantClient->deletePoints($memoryOptions['collection_prefix'] . $testAei['id'], $allTestMemoryIds);
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            
            echo "   🧹 Test memories cleaned up\n";
            
        } else {
            throw new Exception("Memory retrieval test failed - no similar results found");
        }
    } else {
        throw new Exception("Memory storage test failed");
    }
    
    echo "\n🎉 Qdrant Inference Memory System Setup Complete!\n\n";
    
    echo "📋 Configuration Summary:\n";
    echo "   • Qdrant URL: " . QDRANT_URL . "\n";
    echo "   • Default Model: " . (MEMORY_DEFAULT_MODEL ?? 'sentence-transformers/all-MiniLM-L6-v2') . " (384d)\n";
    echo "   • Quality Model: " . (MEMORY_QUALITY_MODEL ?? 'mixedbread-ai/mxbai-embed-large-v1') . " (1024d)\n";
    echo "   • Collection Prefix: " . (MEMORY_COLLECTION_PREFIX ?? 'aei_memories_') . "\n";
    echo "   • Context Limit: " . (MEMORY_CONTEXT_LIMIT ?? 5) . " memories\n";
    echo "   • Extraction: " . (MEMORY_EXTRACTION_ENABLED ? 'Enabled' : 'Disabled') . "\n";
    echo "   • Debug Mode: " . (MEMORY_DEBUG ? 'Enabled' : 'Disabled') . "\n\n";
    
    echo "💰 Cost Information:\n";
    echo "   • FREE: 5M tokens/month per text model (covers ~10,000-15,000 chat messages)\n";
    echo "   • FREE: 1M tokens/month for image models\n";
    echo "   • FREE: Unlimited BM25 sparse search\n\n";
    
    echo "🚀 System is ready! Features:\n";
    echo "   ✅ Automatic embedding generation (NO OpenAI needed)\n";
    echo "   ✅ Semantic memory search with Qdrant Inference\n";
    echo "   ✅ Smart model selection based on importance\n";
    echo "   ✅ Memory extraction from conversations\n";
    echo "   ✅ Memory context integration in AI responses\n";
    echo "   ✅ Automatic cleanup of old/unused memories\n\n";
    
    if (!MEMORY_DEBUG) {
        echo "💡 Tip: Enable MEMORY_DEBUG in config/memory_config.php to see detailed memory logs\n";
    }
    
    echo "🎯 Ready to chat! Your AEIs will now have true long-term memory! 🧠\n";

} catch (Exception $e) {
    echo "\n❌ Setup failed: " . $e->getMessage() . "\n";
    echo "🔍 Please check your configuration and try again\n\n";
    
    echo "🛠️  Troubleshooting:\n";
    echo "1. Make sure you have a PAID Qdrant Cloud cluster (not free tier)\n";
    echo "2. Check your cluster URL format: https://[cluster-id].us-east.aws.cloud.qdrant.io:6333\n";
    echo "3. Verify your API key has proper permissions\n";
    echo "4. Test cluster access: curl -H 'api-key: YOUR_KEY' YOUR_CLUSTER_URL/collections\n";
    echo "5. Check that Inference API is enabled on your cluster\n";
    
    exit(1);
}
?>