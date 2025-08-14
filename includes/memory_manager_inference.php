<?php

require_once __DIR__ . '/qdrant_inference_client.php';

/**
 * AEI Memory Manager 2025 - Using Qdrant Inference API
 * NO OpenAI dependencies - everything runs on Qdrant Cloud!
 */
class MemoryManagerInference {
    private $qdrantClient;
    private $pdo;
    private $defaultModel;
    private $qualityModel;
    private $collectionPrefix;
    
    // Model selection based on importance
    const MODEL_FAST = 'sentence-transformers/all-MiniLM-L6-v2';      // 384d
    const MODEL_QUALITY = 'mixedbread-ai/mxbai-embed-large-v1';      // 1024d
    const MODEL_SPARSE = 'bm25';                                      // Free unlimited
    
    public function __construct($qdrantUrl, $qdrantApiKey, $pdo, $options = []) {
        $this->qdrantClient = new QdrantInferenceClient($qdrantUrl, $qdrantApiKey);
        $this->pdo = $pdo;
        $this->defaultModel = $options['default_model'] ?? self::MODEL_FAST;
        $this->qualityModel = $options['quality_model'] ?? self::MODEL_QUALITY;
        $this->collectionPrefix = $options['collection_prefix'] ?? 'aei_memories_';
        
        // Test connection
        $health = $this->qdrantClient->healthCheck();
        if ($health['status'] !== 'healthy') {
            throw new Exception("Qdrant connection failed: " . ($health['error'] ?? 'Unknown error'));
        }
    }
    
    /**
     * Initialize memory collection for an AEI
     */
    public function initializeAEICollection($aeiId) {
        $collectionName = $this->collectionPrefix . $aeiId;
        
        try {
            // Check if collection exists
            $info = $this->qdrantClient->getCollectionInfo($collectionName);
            error_log("Collection $collectionName already exists");
            return $collectionName; // Already exists
            
        } catch (Exception $e) {
            // Create new collection with quality model dimensions (can store both models)
            try {
                error_log("Creating new collection: $collectionName with model: " . $this->qualityModel);
                $result = $this->qdrantClient->createCollection($collectionName, $this->qualityModel);
                error_log("Created memory collection: $collectionName, result: " . json_encode($result));
                return $collectionName;
                
            } catch (Exception $createError) {
                error_log("Collection creation failed: " . $createError->getMessage());
                throw new Exception("Failed to create memory collection: " . $createError->getMessage());
            }
        }
    }
    
    /**
     * Store memory with automatic embedding generation
     */
    public function storeMemory($aeiId, $memoryText, $memoryType, $importance = 0.5, $sessionId = null, $userId = null) {
        try {
            error_log("[MEMORY_DEBUG] storeMemory() called with AEI: $aeiId, TextLength: " . strlen($memoryText) . ", Type: $memoryType);
            
            $memoryId = bin2hex(random_bytes(16));
            error_log("[MEMORY_DEBUG] Generated memory ID: $memoryId");
            
            $collectionName = $this->initializeAEICollection($aeiId);
            error_log("[MEMORY_DEBUG] Collection name: $collectionName");
            
            // Select model based on importance
            $model = $importance > 0.7 ? $this->qualityModel : $this->defaultModel;
            error_log("[MEMORY_DEBUG] Selected model: $model (importance: $importance)");
            
            // Prepare metadata
            $payload = [
                'memory_id' => $memoryId,
                'aei_id' => $aeiId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'memory_type' => $memoryType,
                'importance' => $importance,
                'model_used' => $model
            ];
            error_log("[MEMORY_DEBUG] Payload prepared: " . json_encode($payload));
            
            // Store with automatic embedding generation
            error_log("[MEMORY_DEBUG] Attempting to store memory in Qdrant: Collection=$collectionName, ID=$memoryId, Model=$model, TextLength=" . strlen($memoryText));
            
            try {
                $result = $this->qdrantClient->storeTextWithEmbedding(
                    $collectionName,
                    $memoryId,
                    $memoryText,
                    $payload,
                    $model
                );
                error_log("[MEMORY_DEBUG] Qdrant storage result: " . json_encode($result));
                
                // Check if Qdrant storage was successful
                if (!$result || (isset($result['status']) && $result['status'] !== 'ok')) {
                    throw new Exception("Qdrant storage failed: " . json_encode($result));
                }
                
            } catch (Exception $qdrantError) {
                error_log("[MEMORY_DEBUG] Qdrant storage FAILED: " . $qdrantError->getMessage());
                error_log("[MEMORY_DEBUG] Qdrant error trace: " . $qdrantError->getTraceAsString());
                throw $qdrantError;
            }
            
            // Store metadata in MySQL for additional queries
            error_log("[MEMORY_DEBUG] Storing memory metadata in MySQL");
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO aei_memories (
                        memory_id, aei_id, memory_type, content, importance_score,
                        session_id, user_id, embedding_model, created_at, last_accessed, access_count
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 0)
                ");
                
                $executeResult = $stmt->execute([
                    $memoryId, $aeiId, $memoryType, $memoryText, $importance,
                    $sessionId, $userId, $model
                ]);
                
                if (!$executeResult) {
                    $errorInfo = $stmt->errorInfo();
                    throw new Exception("MySQL execute failed: " . json_encode($errorInfo));
                }
                
                $rowCount = $stmt->rowCount();
                error_log("[MEMORY_DEBUG] MySQL storage completed successfully. Rows affected: $rowCount");
                
            } catch (Exception $mysqlError) {
                error_log("[MEMORY_DEBUG] MySQL storage FAILED: " . $mysqlError->getMessage());
                error_log("[MEMORY_DEBUG] MySQL error trace: " . $mysqlError->getTraceAsString());
                throw $mysqlError;
            }
            
            error_log("[MEMORY_DEBUG] Memory storage SUCCESSFUL - returning ID: $memoryId");
            
            if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                error_log("Memory stored: $memoryId (model: $model, importance: $importance)");
            }
            
            return $memoryId;
            
        } catch (Exception $e) {
            error_log("[MEMORY_DEBUG] EXCEPTION in storeMemory(): " . $e->getMessage());
            error_log("[MEMORY_DEBUG] Exception file: " . $e->getFile() . ":" . $e->getLine());
            error_log("[MEMORY_DEBUG] Memory storage error details: AEI ID: $aeiId, Text length: " . strlen($memoryText) . ", Type: $memoryType, Importance: $importance");
            error_log("[MEMORY_DEBUG] Full stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Retrieve relevant memories with automatic query embedding
     */
    public function retrieveMemories($aeiId, $queryText, $limit = 5, $memoryTypes = null, $minImportance = 0.0) {
        try {
            $collectionName = $this->collectionPrefix . $aeiId;
            
            // Use quality model for better retrieval
            $model = $this->qualityModel;
            
            // Build filter
            $filter = [
                'must' => [
                    [
                        'key' => 'aei_id',
                        'match' => ['value' => $aeiId]
                    ]
                ]
            ];
            
            // Add memory type filter
            if ($memoryTypes && is_array($memoryTypes)) {
                $filter['must'][] = [
                    'key' => 'memory_type',
                    'match' => ['any' => $memoryTypes]
                ];
            }
            
            // Add importance filter
            if ($minImportance > 0) {
                $filter['must'][] = [
                    'key' => 'importance',
                    'range' => ['gte' => $minImportance]
                ];
            }
            
            // Search with automatic embedding
            $results = $this->qdrantClient->searchWithText(
                $collectionName,
                $queryText,
                $model,
                $limit,
                $filter
            );
            
            $memories = [];
            if (isset($results['result']) && is_array($results['result'])) {
                foreach ($results['result'] as $result) {
                    $memories[] = [
                        'memory_id' => $result['payload']['memory_id'],
                        'content' => $result['payload']['original_text'],
                        'memory_type' => $result['payload']['memory_type'],
                        'importance' => $result['payload']['importance'],
                        'created_at' => $result['payload']['created_at'],
                        'similarity_score' => $result['score'],
                        'session_id' => $result['payload']['session_id'] ?? null,
                        'model_used' => $result['payload']['model_used'] ?? 'unknown'
                    ];
                    
                    // Update access count
                    $this->updateMemoryAccess($result['payload']['memory_id']);
                }
            }
            
            if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                error_log("Retrieved " . count($memories) . " memories for query: " . substr($queryText, 0, 50));
            }
            
            return $memories;
            
        } catch (Exception $e) {
            error_log("Failed to retrieve memories: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract memories from conversation using AI (via existing Anthropic API)
     */
    public function extractMemoriesFromConversation($aeiId, $messages, $userId = null, $sessionId = null) {
        try {
            // Build conversation context
            $conversationText = "";
            foreach ($messages as $message) {
                $sender = $message['sender_type'] === 'user' ? 'User' : 'AEI';
                $conversationText .= "$sender: " . $message['message_text'] . "\n";
            }
            
            // Create enhanced extraction prompt
            $extractionPrompt = "Analyze this conversation and extract important long-term memories. Focus on:

FACTS: Concrete information about the user (name, job, family, location, preferences)
EVENTS: Important things that happened or were mentioned
EMOTIONS: Emotional states, feelings, reactions
RELATIONSHIPS: Information about people in user's life
GOALS: Aspirations, plans, dreams the user mentioned
CONCERNS: Worries, fears, problems the user discussed

Only extract information that would be valuable to remember weeks or months later for maintaining conversation continuity.

Format as JSON:
{
    \"memories\": [
        {
            \"content\": \"specific memory text\",
            \"type\": \"fact|event|emotion|relationship|goal|concern\",
            \"importance\": 0.1-1.0
        }
    ]
}

Conversation to analyze:
$conversationText";
            
            // Use existing Anthropic API for extraction
            $messages = [['role' => 'user', 'content' => $extractionPrompt]];
            $systemPrompt = "You are a memory extraction specialist. Extract only the most important, long-term relevant information. Be selective - quality over quantity. Focus on facts that would help maintain personality and relationship continuity in future conversations.";
            
            $response = callAnthropicAPI($messages, $systemPrompt, 4000);
            $memoryData = json_decode($response, true);
            
            if (!isset($memoryData['memories']) || !is_array($memoryData['memories'])) {
                if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                    error_log("Memory extraction failed - invalid response format");
                }
                return [];
            }
            
            $storedMemories = [];
            foreach ($memoryData['memories'] as $memory) {
                if (isset($memory['content']) && isset($memory['type']) && isset($memory['importance'])) {
                    $memoryId = $this->storeMemory(
                        $aeiId,
                        $memory['content'],
                        $memory['type'],
                        (float)$memory['importance'],
                        $sessionId,
                        $userId
                    );
                    
                    if ($memoryId) {
                        $storedMemories[] = [
                            'memory_id' => $memoryId,
                            'content' => $memory['content'],
                            'type' => $memory['type'],
                            'importance' => $memory['importance']
                        ];
                    }
                }
            }
            
            if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                error_log("Extracted " . count($storedMemories) . " memories from conversation");
            }
            
            return $storedMemories;
            
        } catch (Exception $e) {
            error_log("Failed to extract memories from conversation: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get memory context for system prompt
     */
    public function getMemoryContext($aeiId, $currentMessage, $limit = 5) {
        $memories = $this->retrieveMemories($aeiId, $currentMessage, $limit, null, 0.3);
        
        if (empty($memories)) {
            return "";
        }
        
        $context = "\n\nRELEVANT MEMORIES (use naturally in conversation):\n";
        
        foreach ($memories as $memory) {
            $timeAgo = $this->getRelativeTime($memory['created_at']);
            $context .= "- " . $memory['content'] . " (relevance: " . 
                       number_format($memory['similarity_score'], 2) . ", $timeAgo)\n";
        }
        
        $context .= "\nUse these memories naturally without explicitly mentioning them as memories.";
        
        return $context;
    }
    
    /**
     * Store batch of memories efficiently
     */
    public function storeBatchMemories($aeiId, $memoryBatch) {
        try {
            $collectionName = $this->initializeAEICollection($aeiId);
            
            // Prepare batch for Qdrant
            $qdrantBatch = [];
            $sqlBatch = [];
            
            foreach ($memoryBatch as $memory) {
                $memoryId = bin2hex(random_bytes(16));
                $model = $memory['importance'] > 0.7 ? $this->qualityModel : $this->defaultModel;
                
                $qdrantBatch[] = [
                    'id' => $memoryId,
                    'text' => $memory['content'],
                    'payload' => [
                        'memory_id' => $memoryId,
                        'aei_id' => $aeiId,
                        'memory_type' => $memory['type'],
                        'importance' => $memory['importance'],
                        'model_used' => $model
                    ]
                ];
                
                $sqlBatch[] = [
                    'memory_id' => $memoryId,
                    'content' => $memory['content'],
                    'type' => $memory['type'],
                    'importance' => $memory['importance'],
                    'model' => $model
                ];
            }
            
            // Store batch in Qdrant
            $this->qdrantClient->storeBatchTexts($collectionName, $qdrantBatch, $this->qualityModel);
            
            // Store batch in MySQL
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_memories (
                    memory_id, aei_id, memory_type, content, importance_score,
                    embedding_model, created_at, last_accessed, access_count
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), 0)
            ");
            
            foreach ($sqlBatch as $sql) {
                $stmt->execute([
                    $sql['memory_id'], $aeiId, $sql['type'], $sql['content'],
                    $sql['importance'], $sql['model']
                ]);
            }
            
            return count($sqlBatch);
            
        } catch (Exception $e) {
            error_log("Failed to store memory batch: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Clean up old/low importance memories
     */
    public function cleanupMemories($aeiId, $keepDays = 90, $minImportance = 0.1) {
        try {
            $collectionName = $this->collectionPrefix . $aeiId;
            
            // Get memories to delete from MySQL
            $stmt = $this->pdo->prepare("
                SELECT memory_id 
                FROM aei_memories 
                WHERE aei_id = ? 
                AND (
                    created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                    OR importance_score < ?
                )
                AND access_count < 3
                ORDER BY importance_score ASC, last_accessed ASC
                LIMIT 50
            ");
            
            $stmt->execute([$aeiId, $keepDays, $minImportance]);
            $memoriesToDelete = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($memoriesToDelete)) {
                // Delete from Qdrant
                $this->qdrantClient->deletePoints($collectionName, $memoriesToDelete);
                
                // Delete from MySQL
                $placeholders = str_repeat('?,', count($memoriesToDelete) - 1) . '?';
                $stmt = $this->pdo->prepare("DELETE FROM aei_memories WHERE memory_id IN ($placeholders)");
                $stmt->execute($memoriesToDelete);
                
                if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                    error_log("Cleaned up " . count($memoriesToDelete) . " old memories for AEI $aeiId");
                }
                
                return count($memoriesToDelete);
            }
            
            return 0;
            
        } catch (Exception $e) {
            error_log("Failed to cleanup memories: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get memory statistics
     */
    public function getMemoryStats($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(*) as total_memories,
                    AVG(importance_score) as avg_importance,
                    COUNT(DISTINCT memory_type) as memory_types,
                    MAX(created_at) as last_memory,
                    SUM(access_count) as total_accesses
                FROM aei_memories 
                WHERE aei_id = ?
            ");
            $stmt->execute([$aeiId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $stats ?: [
                'total_memories' => 0,
                'avg_importance' => 0,
                'memory_types' => 0,
                'last_memory' => null,
                'total_accesses' => 0
            ];
            
        } catch (Exception $e) {
            error_log("Failed to get memory stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update memory access tracking
     */
    private function updateMemoryAccess($memoryId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aei_memories 
                SET access_count = access_count + 1, last_accessed = NOW() 
                WHERE memory_id = ?
            ");
            $stmt->execute([$memoryId]);
        } catch (Exception $e) {
            error_log("Failed to update memory access: " . $e->getMessage());
        }
    }
    
    /**
     * Get relative time description
     */
    private function getRelativeTime($timestamp) {
        $now = time();
        $time = strtotime($timestamp);
        $diff = $now - $time;
        
        if ($diff < 3600) {
            return floor($diff / 60) . " minutes ago";
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . " hours ago";
        } elseif ($diff < 604800) {
            return floor($diff / 86400) . " days ago";
        } else {
            return floor($diff / 604800) . " weeks ago";
        }
    }
}
?>