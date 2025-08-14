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
    private $debugCallback;
    
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
        $this->debugCallback = null;
        
        // Test connection
        $health = $this->qdrantClient->healthCheck();
        if ($health['status'] !== 'healthy') {
            throw new Exception("Qdrant connection failed: " . ($health['error'] ?? 'Unknown error'));
        }
    }
    
    /**
     * Set debug callback for detailed logging
     */
    public function setDebugCallback($callback) {
        $this->debugCallback = $callback;
    }
    
    /**
     * Initialize memory collection for an AEI with proper indexes
     */
    public function initializeAEICollection($aeiId) {
        $collectionName = $this->collectionPrefix . $aeiId;
        
        // Use debug callback if available
        $debugFunc = function($message, $type = 'info') {
            if ($this->debugCallback && is_callable($this->debugCallback)) {
                call_user_func($this->debugCallback, $message, $type);
            } else {
                error_log("[MEMORY_DEBUG] $message");
            }
        };
        
        try {
            // Check if collection exists
            $info = $this->qdrantClient->getCollectionInfo($collectionName);
            $debugFunc("📁 Collection $collectionName already exists");
            
            // Ensure indexes exist for existing collection
            $debugFunc("🔧 Checking/creating indexes for existing collection...");
            try {
                // Try to create indexes (they will fail if they already exist, which is fine)
                $this->qdrantClient->createFieldIndex($collectionName, 'aei_id', 'keyword');
                $debugFunc("✅ Created/verified aei_id index");
            } catch (Exception $indexError) {
                $debugFunc("⚠️ aei_id index creation failed (may already exist): " . $indexError->getMessage());
            }
            
            try {
                $this->qdrantClient->createFieldIndex($collectionName, 'memory_type', 'keyword');
                $debugFunc("✅ Created/verified memory_type index");
            } catch (Exception $indexError) {
                $debugFunc("⚠️ memory_type index creation failed (may already exist): " . $indexError->getMessage());
            }
            
            try {
                $this->qdrantClient->createFieldIndex($collectionName, 'importance', 'integer');
                $debugFunc("✅ Created/verified importance index");
            } catch (Exception $indexError) {
                $debugFunc("⚠️ importance index creation failed (may already exist): " . $indexError->getMessage());
            }
            
            return $collectionName;
            
        } catch (Exception $e) {
            // Create new collection with quality model dimensions (can store both models)
            try {
                $debugFunc("🏗️ Creating new collection: $collectionName with model: " . $this->qualityModel);
                $result = $this->qdrantClient->createCollection($collectionName, $this->qualityModel);
                $debugFunc("✅ Created collection: " . json_encode($result));
                
                // Create indexes for filter fields
                $debugFunc("🔧 Creating indexes for filter fields...");
                
                try {
                    // Create index for aei_id (keyword index for exact matching)
                    $indexResult1 = $this->qdrantClient->createFieldIndex($collectionName, 'aei_id', 'keyword');
                    $debugFunc("✅ Created aei_id index: " . json_encode($indexResult1));
                    
                    // Create index for memory_type (keyword index)  
                    $indexResult2 = $this->qdrantClient->createFieldIndex($collectionName, 'memory_type', 'keyword');
                    $debugFunc("✅ Created memory_type index: " . json_encode($indexResult2));
                    
                    // Create index for importance (integer/float index for range queries)
                    $indexResult3 = $this->qdrantClient->createFieldIndex($collectionName, 'importance', 'integer');
                    $debugFunc("✅ Created importance index: " . json_encode($indexResult3));
                    
                } catch (Exception $indexError) {
                    $debugFunc("⚠️ Index creation failed (collection still usable): " . $indexError->getMessage());
                    error_log("Index creation failed: " . $indexError->getMessage());
                    // Don't fail - collection is still usable, just filtering won't work optimally
                }
                
                $debugFunc("🎉 Collection $collectionName fully initialized with indexes");
                return $collectionName;
                
            } catch (Exception $createError) {
                $debugFunc("❌ Collection creation failed: " . $createError->getMessage());
                error_log("Collection creation failed: " . $createError->getMessage());
                throw new Exception("Failed to create memory collection: " . $createError->getMessage());
            }
        }
    }
    
    /**
     * Store a chat message or Q&A pair as memory with smart metadata
     */
    public function storeChatMessage($aeiId, $message, $sender, $sessionId = null, $userId = null) {
        // Calculate smart importance score
        $importance = $this->calculateMessageImportance($message, $sender);
        
        // Detect if it's a Q&A pair or single message
        $isQaPair = ($sender === 'conversation' && strpos($message, "\n") !== false);
        
        // Smart metadata
        $metadata = [
            'sender' => $sender,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'timestamp' => time(),
            'char_count' => strlen($message),
            'has_question' => (strpos($message, '?') !== false),
            'retrieval_count' => 0,
            'message_type' => $isQaPair ? 'qa_pair' : 'chat_message',
            'is_qa_pair' => $isQaPair
        ];
        
        return $this->storeMemory($aeiId, $message, $isQaPair ? 'qa_pair' : 'chat_message', $importance, $sessionId, $userId, $metadata);
    }
    
    /**
     * Calculate message importance based on simple rules
     */
    private function calculateMessageImportance($message, $sender) {
        $importance = 0.5; // Base importance
        
        // Q&A pairs are more important than single messages
        if ($sender === 'conversation') {
            $importance += 0.3; // Q&A pairs get bonus
        }
        
        // Longer messages are more important
        if (strlen($message) > 100) $importance += 0.2;
        if (strlen($message) > 200) $importance += 0.1;
        if (strlen($message) > 300) $importance += 0.1; // Extra boost for long Q&A pairs
        
        // Questions are important
        if (strpos($message, '?') !== false) $importance += 0.3;
        
        // User messages slightly more important (but Q&A pairs are better)
        if ($sender === 'user') $importance += 0.1;
        
        // Cap at 1.0
        return min(1.0, $importance);
    }

    /**
     * Store memory with automatic embedding generation (Enhanced)
     */
    public function storeMemory($aeiId, $memoryText, $memoryType, $importance = 0.5, $sessionId = null, $userId = null, $customMetadata = []) {
        // Use debug callback if available, otherwise fall back to error_log
        $debugFunc = function($message, $type = 'info') {
            if ($this->debugCallback && is_callable($this->debugCallback)) {
                call_user_func($this->debugCallback, $message, $type);
            } elseif (function_exists('addDebugLog')) {
                addDebugLog($message, $type);
            } else {
                error_log("[MEMORY_DEBUG] $message");
            }
        };
        
        try {
            $debugFunc("🔧 storeMemory() called with AEI: $aeiId, TextLength: " . strlen($memoryText) . ", Type: $memoryType");
            
            $memoryId = bin2hex(random_bytes(16));
            $debugFunc("🆔 Generated memory ID: $memoryId");
            
            $collectionName = $this->initializeAEICollection($aeiId);
            $debugFunc("📁 Collection name: $collectionName");
            
            // Select model based on importance
            $model = $importance > 0.7 ? $this->qualityModel : $this->defaultModel;
            $debugFunc("🤖 Selected model: $model (importance: $importance)");
            
            // Prepare metadata (merge custom metadata)
            $payload = array_merge([
                'memory_id' => $memoryId,
                'aei_id' => $aeiId,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'memory_type' => $memoryType,
                'importance' => $importance,
                'model_used' => $model
            ], $customMetadata);
            $debugFunc("📦 Payload prepared: " . json_encode($payload));
            
            // Store with automatic embedding generation
            $debugFunc("📡 Attempting to store memory in Qdrant: Collection=$collectionName, ID=$memoryId, Model=$model");
            
            try {
                $result = $this->qdrantClient->storeTextWithEmbedding(
                    $collectionName,
                    $memoryId,
                    $memoryText,
                    $payload,
                    $model
                );
                $debugFunc("✅ Qdrant storage result: " . json_encode($result));
                
                // Check if Qdrant storage was successful
                if (!$result || (isset($result['status']) && $result['status'] !== 'ok')) {
                    throw new Exception("Qdrant storage failed: " . json_encode($result));
                }
                
            } catch (Exception $qdrantError) {
                $debugFunc("❌ Qdrant storage FAILED: " . $qdrantError->getMessage());
                $debugFunc("🔍 Qdrant error trace: " . $qdrantError->getTraceAsString());
                throw $qdrantError;
            }
            
            // Store metadata in MySQL for additional queries
            $debugFunc("💾 Storing memory metadata in MySQL");
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
                $debugFunc("✅ MySQL storage completed successfully. Rows affected: $rowCount");
                
            } catch (Exception $mysqlError) {
                $debugFunc("❌ MySQL storage FAILED: " . $mysqlError->getMessage());
                $debugFunc("🔍 MySQL error trace: " . $mysqlError->getTraceAsString());
                throw $mysqlError;
            }
            
            $debugFunc("🎉 Memory storage SUCCESSFUL - returning ID: $memoryId");
            
            if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                error_log("Memory stored: $memoryId (model: $model, importance: $importance)");
            }
            
            return $memoryId;
            
        } catch (Exception $e) {
            $debugFunc("💥 EXCEPTION in storeMemory(): " . $e->getMessage());
            $debugFunc("📍 Exception file: " . $e->getFile() . ":" . $e->getLine());
            $debugFunc("📋 Memory storage error details: AEI ID: $aeiId, Text length: " . strlen($memoryText) . ", Type: $memoryType, Importance: $importance");
            $debugFunc("📜 Full stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Retrieve relevant memories with automatic query embedding
     */
    public function retrieveMemories($aeiId, $queryText, $limit = 5, $memoryTypes = null, $minImportance = 0.0) {
        // Use debug callback if available
        $debugFunc = function($message, $type = 'info') {
            if ($this->debugCallback && is_callable($this->debugCallback)) {
                call_user_func($this->debugCallback, $message, $type);
            } elseif (function_exists('addDebugLog')) {
                addDebugLog($message, $type);
            } else {
                error_log("[MEMORY_DEBUG] $message");
            }
        };
        
        try {
            $collectionName = $this->collectionPrefix . $aeiId;
            $debugFunc("🔍 Starting memory retrieval from collection: $collectionName");
            
            // Use quality model for better retrieval
            $model = $this->qualityModel;
            $debugFunc("🤖 Using retrieval model: $model");
            
            // Build filter
            $filter = [
                'must' => [
                    [
                        'key' => 'aei_id',
                        'match' => ['value' => $aeiId]
                    ]
                ]
            ];
            $debugFunc("🔧 Base filter prepared for AEI: $aeiId");
            
            // Add memory type filter
            if ($memoryTypes && is_array($memoryTypes)) {
                $filter['must'][] = [
                    'key' => 'memory_type',
                    'match' => ['any' => $memoryTypes]
                ];
                $debugFunc("🏷️ Added memory type filter: " . implode(', ', $memoryTypes));
            }
            
            // Add importance filter
            if ($minImportance > 0) {
                $filter['must'][] = [
                    'key' => 'importance',
                    'range' => ['gte' => $minImportance]
                ];
                $debugFunc("⭐ Added importance filter >= $minImportance");
            }
            
            $debugFunc("📡 Executing search with query: '" . substr($queryText, 0, 100) . "'");
            $debugFunc("🔍 Filter: " . json_encode($filter));
            
            // Search with automatic embedding
            $results = $this->qdrantClient->searchWithText(
                $collectionName,
                $queryText,
                $model,
                $limit,
                $filter
            );
            
            $debugFunc("📊 Qdrant search result: " . json_encode($results));
            
            $memories = [];
            if (isset($results['result']['points']) && is_array($results['result']['points'])) {
                $debugFunc("✅ Found " . count($results['result']['points']) . " potential results");
                
                foreach ($results['result']['points'] as $index => $result) {
                    $debugFunc("📝 Processing result #$index: " . json_encode($result));
                    
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
                    $debugFunc("📈 Updated access count for memory: " . $result['payload']['memory_id']);
                }
            } else {
                $debugFunc("❌ No results found or invalid result structure");
                $debugFunc("🔍 Raw results structure: " . json_encode($results));
            }
            
            $debugFunc("🎯 Final memories retrieved: " . count($memories));
            
            if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                error_log("Retrieved " . count($memories) . " memories for query: " . substr($queryText, 0, 50));
            }
            
            return $memories;
            
        } catch (Exception $e) {
            $debugFunc("💥 EXCEPTION in retrieveMemories(): " . $e->getMessage());
            $debugFunc("📍 Exception file: " . $e->getFile() . ":" . $e->getLine());
            $debugFunc("📜 Stack trace: " . $e->getTraceAsString());
            error_log("Failed to retrieve memories: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Time-based memory retrieval with smart scoring
     */
    private function getTimeBasedMemories($aeiId, $query, $maxDays, $minSimilarity, $limit) {
        $collectionName = $this->collectionPrefix . $aeiId;
        $currentTime = time();
        $cutoffTime = $currentTime - ($maxDays * 24 * 60 * 60);
        
        try {
            // Build filter for time range
            $filter = [
                'must' => [
                    ['key' => 'aei_id', 'match' => ['value' => $aeiId]]
                ]
            ];
            
            // Add time filter if not searching all time
            if ($maxDays < 999) {
                $filter['must'][] = [
                    'key' => 'timestamp',
                    'range' => ['gte' => $cutoffTime]
                ];
            }
            
            // Search with enhanced scoring
            $results = $this->qdrantClient->searchWithText(
                $collectionName,
                $query,
                $limit * 2, // Get more results for smart filtering
                $this->qualityModel, // Use quality model for better retrieval
                $filter
            );
            
            $memories = [];
            foreach ($results as $result) {
                $similarity = $result['score'];
                $payload = $result['payload'];
                
                // Calculate time-decay boost
                $timestamp = $payload['timestamp'] ?? $currentTime;
                $daysSince = ($currentTime - $timestamp) / 86400;
                $recencyBoost = exp(-$daysSince / 10); // 10-day half-life
                
                // Calculate final score with recency boost
                $finalScore = $similarity * $recencyBoost;
                
                // Apply minimum threshold
                if ($finalScore >= $minSimilarity) {
                    $memories[] = [
                        'content' => $payload['original_text'] ?? 'Unknown content',
                        'similarity_score' => $similarity,
                        'final_score' => $finalScore,
                        'recency_boost' => $recencyBoost,
                        'timestamp' => $timestamp,
                        'sender' => $payload['sender'] ?? 'unknown',
                        'memory_id' => $payload['memory_id'] ?? 'unknown',
                        'is_qa_pair' => $payload['is_qa_pair'] ?? false,
                        'message_type' => $payload['message_type'] ?? 'unknown'
                    ];
                }
            }
            
            // Sort by final score (similarity * recency)
            usort($memories, function($a, $b) {
                return $b['final_score'] <=> $a['final_score'];
            });
            
            return array_slice($memories, 0, $limit);
            
        } catch (Exception $e) {
            error_log("Error in time-based memory retrieval: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Remove duplicate memories based on content similarity
     */
    private function deduplicateMemories($memories) {
        $unique = [];
        $seen = [];
        
        foreach ($memories as $memory) {
            $content = strtolower(trim($memory['content']));
            $isDuplicate = false;
            
            foreach ($seen as $seenContent) {
                if (similar_text($content, $seenContent, $percent) && $percent > 80) {
                    $isDuplicate = true;
                    break;
                }
            }
            
            if (!$isDuplicate) {
                $unique[] = $memory;
                $seen[] = $content;
            }
        }
        
        return $unique;
    }
    
    /**
     * Get human-readable time ago string
     */
    private function getTimeAgo($timestamp) {
        $diff = time() - $timestamp;
        
        if ($diff < 3600) {
            $minutes = max(1, floor($diff / 60));
            return $minutes . "m ago";
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . "h ago";
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . "d ago";
        } else {
            $weeks = floor($diff / 604800);
            return $weeks . "w ago";
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
                // Handle both formats: getChatHistory format (role/content) and direct DB format (sender_type/message_text)
                if (isset($message['role']) && isset($message['content'])) {
                    // getChatHistory format
                    $sender = $message['role'] === 'user' ? 'User' : 'AEI';
                    $conversationText .= "$sender: " . $message['content'] . "\n";
                } elseif (isset($message['sender_type']) && isset($message['message_text'])) {
                    // Direct DB format
                    $sender = $message['sender_type'] === 'user' ? 'User' : 'AEI';
                    $conversationText .= "$sender: " . $message['message_text'] . "\n";
                } else {
                    error_log("MEMORY_EXTRACTION_WARNING: Unknown message format: " . json_encode(array_keys($message)));
                }
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
     * Get smart memory context with time-decay and multi-window retrieval
     */
    public function getSmartMemoryContext($aeiId, $currentMessage, $limit = 6) {
        // AUTO-CREATE COLLECTION IF NOT EXISTS
        try {
            $collectionName = $this->initializeAEICollection($aeiId);
            if ($this->debugCallback) {
                call_user_func($this->debugCallback, "🏗️ Collection initialized: $collectionName", 'info');
            }
        } catch (Exception $e) {
            if ($this->debugCallback) {
                call_user_func($this->debugCallback, "❌ Failed to initialize collection for AEI $aeiId: " . $e->getMessage(), 'error');
            }
            error_log("Failed to initialize collection for AEI $aeiId: " . $e->getMessage());
            return "";
        }
        
        // Multi-window smart retrieval
        $allMemories = [];
        
        // Window 1: Recent messages (1 day) - lower similarity threshold
        $recent = $this->getTimeBasedMemories($aeiId, $currentMessage, 1, 0.4, 2);
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "📅 Found " . count($recent) . " recent memories (1 day)", 'info');
        }
        
        // Window 2: Medium term (7 days) - medium similarity  
        $medium = $this->getTimeBasedMemories($aeiId, $currentMessage, 7, 0.6, 2);
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "📆 Found " . count($medium) . " medium-term memories (7 days)", 'info');
        }
        
        // Window 3: Long term (any time) - high similarity only
        $longterm = $this->getTimeBasedMemories($aeiId, $currentMessage, 999, 0.8, 2);
        if ($this->debugCallback) {
            call_user_func($this->debugCallback, "🗄️ Found " . count($longterm) . " long-term memories (high similarity)", 'info');
        }
        
        // Combine and deduplicate
        $allMemories = array_merge($recent, $medium, $longterm);
        $allMemories = $this->deduplicateMemories($allMemories);
        
        // Limit to requested amount
        $allMemories = array_slice($allMemories, 0, $limit);
        
        if (empty($allMemories)) {
            return "";
        }
        
        $context = "\n\nRELEVANT CONVERSATION HISTORY (use naturally):\n";
        
        foreach ($allMemories as $memory) {
            $timeAgo = $this->getTimeAgo($memory['timestamp'] ?? time());
            $isQaPair = isset($memory['is_qa_pair']) && $memory['is_qa_pair'];
            
            if ($isQaPair) {
                // Q&A pairs display differently
                $context .= "[$timeAgo]\n" . $memory['content'] . "\n\n";
            } else {
                // Single messages 
                $sender = $memory['sender'] ?? 'unknown';
                $context .= "- [$timeAgo, $sender]: " . $memory['content'] . "\n";
            }
        }
        
        return $context;
    }
    
    /**
     * Legacy method for backward compatibility
     */
    public function getMemoryContext($aeiId, $currentMessage, $limit = 5) {
        // AUTO-CREATE COLLECTION IF NOT EXISTS
        try {
            $collectionName = $this->initializeAEICollection($aeiId);
            if ($this->debugCallback) {
                call_user_func($this->debugCallback, "🏗️ Collection initialized: $collectionName", 'info');
            }
        } catch (Exception $e) {
            if ($this->debugCallback) {
                call_user_func($this->debugCallback, "❌ Failed to initialize collection for AEI $aeiId: " . $e->getMessage(), 'error');
            }
            error_log("Failed to initialize collection for AEI $aeiId: " . $e->getMessage());
            return "";
        }
        
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