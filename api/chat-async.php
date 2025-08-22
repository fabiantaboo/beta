<?php
// Asynchronous Chat API with Server-Sent Events for background processing
// Use centralized session configuration
include_once '../includes/session_config.php';

include_once '../config/database.php';
include_once '../includes/functions.php';
include_once '../includes/anthropic_api.php';
include_once '../includes/emotions.php';
include_once '../includes/image_upload.php';
include_once '../includes/proactive_messaging.php';

// Prevent output buffering issues
if (ob_get_level()) ob_end_clean();

// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable Nginx buffering

// Function to send SSE message
function sendSSE($type, $data) {
    echo "event: $type\n";
    echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level()) ob_flush();
    flush();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendSSE('error', ['message' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isLoggedIn()) {
    sendSSE('error', ['message' => 'Unauthorized']);
    exit;
}

// Handle both JSON and FormData input
$input = null;
$uploadedImage = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    // FormData request with image
    $input = [
        'message' => $_POST['message'] ?? '',
        'aei_id' => $_POST['aei_id'] ?? '',
        'csrf_token' => $_POST['csrf_token'] ?? ''
    ];
    
    // Handle image upload
    $imageHandler = new ImageUploadHandler();
    $uploadResult = $imageHandler->handleUpload($_FILES['image']);
    
    if (!$uploadResult['success']) {
        sendSSE('error', ['message' => 'Image upload failed: ' . $uploadResult['error']]);
        exit;
    }
    
    $uploadedImage = $uploadResult;
} else {
    // JSON request (text only)
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        sendSSE('error', ['message' => 'Invalid input']);
        exit;
    }
}

// Validate CSRF token
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    sendSSE('error', ['message' => 'Invalid CSRF token']);
    exit;
}

// Validate required fields
$message = sanitizeInput($input['message'] ?? '');
$aeiId = sanitizeInput($input['aei_id'] ?? '');

if ((empty($message) && !$uploadedImage) || empty($aeiId)) {
    sendSSE('error', ['message' => 'Message or image and AEI ID are required']);
    exit;
}

// Validate message length
if (strlen($message) > 2000) {
    sendSSE('error', ['message' => 'Message too long (max 2000 characters)']);
    exit;
}

try {
    // Send initial processing status
    sendSSE('status', ['message' => 'Processing your message...']);
    
    // Verify AEI belongs to current user
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt->execute([$aeiId, getUserSession()]);
    $aei = $stmt->fetch();
    
    if (!$aei) {
        sendSSE('error', ['message' => 'AEI not found']);
        exit;
    }
    
    // Get or create chat session
    $stmt = $pdo->prepare("SELECT id FROM chat_sessions WHERE user_id = ? AND aei_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([getUserSession(), $aeiId]);
    $session = $stmt->fetch();
    
    if (!$session) {
        $sessionId = generateId();
        $stmt = $pdo->prepare("INSERT INTO chat_sessions (id, user_id, aei_id) VALUES (?, ?, ?)");
        $stmt->execute([$sessionId, getUserSession(), $aeiId]);
        
        // Initialize emotional state for new session
        $emotions = new Emotions($pdo);
        $emotions->initializeSessionEmotions($sessionId);
    } else {
        $sessionId = $session['id'];
    }
    
    // Start database transaction
    $pdo->beginTransaction();
    
    // Get user data for AI context
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([getUserSession()]);
    $user = $stmt->fetch();
    
    // Save user message
    $messageId = generateId();
    
    if ($uploadedImage) {
        // Save message with image
        $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text, has_image, image_filename, image_original_name, image_mime_type, image_size) VALUES (?, ?, 'user', ?, TRUE, ?, ?, ?, ?)");
        $stmt->execute([
            $messageId, 
            $sessionId, 
            $message,
            $uploadedImage['filename'],
            $uploadedImage['original_name'],
            $uploadedImage['mime_type'],
            $uploadedImage['size']
        ]);
    } else {
        // Save text-only message
        $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'user', ?)");
        $stmt->execute([$messageId, $sessionId, $message]);
    }
    
    $userMessageTime = getCurrentTimestamp();
    
    // Send user message confirmation
    $userMessageData = [
        'id' => $messageId,
        'sender_type' => 'user',
        'message_text' => $message,
        'created_at' => $userMessageTime,
        'sender_name' => 'You',
        'has_image' => $uploadedImage ? true : false,
        'image_filename' => $uploadedImage['filename'] ?? null,
        'image_original_name' => $uploadedImage['original_name'] ?? null
    ];
    
    sendSSE('user_message', $userMessageData);
    
    // Send initial typing status
    sendSSE('typing', [
        'aei_name' => $aei['name'],
        'message' => $aei['name'] . ' is typing...'
    ]);
    
    // Define progressive retry callback for live status updates
    $retryCallback = function($retryCount, $maxRetries) use ($aei) {
        if ($retryCount <= 3) {
            // Fast retries - keep showing normal typing
            sendSSE('typing', [
                'aei_name' => $aei['name'],
                'message' => $aei['name'] . ' is typing...',
                'retry' => $retryCount,
                'type' => 'normal'
            ]);
        } else {
            // Longer retries - show extended typing
            sendSSE('typing_longer', [
                'aei_name' => $aei['name'],
                'message' => $aei['name'] . ' is typing a bit longer...',
                'retry' => $retryCount,
                'max_retries' => $maxRetries,
                'type' => 'extended'
            ]);
        }
    };
    
    // Generate AI response with progressive retry feedback
    $isAdmin = isAdmin();
    
    // Use modified AI response generation with our SSE callback
    $aeiResponseData = generateAIResponseWithSSECallback($message, $aei, $user, $sessionId, $isAdmin, $uploadedImage, $retryCallback);
    
    // Handle debug response format
    if ($isAdmin && is_array($aeiResponseData)) {
        $aeiResponse = $aeiResponseData['response'];
        $debugData = $aeiResponseData['debug_data'];
    } else {
        $aeiResponse = $aeiResponseData;
        $debugData = null;
    }
    
    // Check for system overload
    if ($aeiResponse === "API_OVERLOAD_MAX_RETRIES") {
        sendSSE('system_overload', [
            'aei_name' => $aei['name'],
            'message' => $aei['name'] . ' is experiencing high demand right now. Please try again in a few minutes.',
            'error_type' => 'api_overload'
        ]);
        $pdo->rollback();
        exit;
    }
    
    // Save AI response
    $aeiResponseId = generateId();
    $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'aei', ?)");
    $stmt->execute([$aeiResponseId, $sessionId, $aeiResponse]);
    
    // Store current emotional state with the AEI message
    $emotions = new Emotions($pdo);
    $currentEmotions = $emotions->getEmotionalState($sessionId);
    $emotions->storeMessageEmotions($aeiResponseId, $currentEmotions);
    
    $aeiMessageTime = getCurrentTimestamp();
    
    // Update session timestamp
    $stmt = $pdo->prepare("UPDATE chat_sessions SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);
    
    // Process social context integration after chat (if enabled)
    if (isset($aei['social_initialized']) && $aei['social_initialized']) {
        require_once '../includes/aei_social_context.php';
        $socialContext = new AEISocialContext($pdo);
        
        // Mark social interactions that were used in this chat as mentioned
        $markedCount = $socialContext->markRecentInteractionsAsMentioned($aeiId);
        
        // Process any unprocessed social emotional impacts
        $emotionalImpact = $socialContext->processUnprocessedSocialUpdates($aeiId);
        
        if (!empty($emotionalImpact)) {
            // Apply social emotional impact to current session
            $emotions->updateEmotions($sessionId, $emotionalImpact, 'social_interaction');
        }
    }
    
    // Analyze for proactive messaging triggers (after the conversation)
    $proactiveMessaging = new ProactiveMessaging($pdo);
    $proactiveMessages = $proactiveMessaging->analyzeAndGenerateProactiveMessages($aeiId, $sessionId, getUserSession());
    
    // Commit transaction
    $pdo->commit();
    
    // Send AI response
    $responseData = [
        'id' => $aeiResponseId,
        'sender_type' => 'aei',
        'message_text' => $aeiResponse,
        'created_at' => $aeiMessageTime,
        'sender_name' => htmlspecialchars($aei['name'])
    ];
    
    sendSSE('aei_message', $responseData);
    
    // Add debug data for admins
    if ($isAdmin && $debugData) {
        error_log("Sending debug data to admin: " . json_encode(array_keys($debugData)));
        sendSSE('debug_data', $debugData);
    } else if ($isAdmin) {
        error_log("Admin detected but no debug data available");
    }
    
    // Send completion
    sendSSE('complete', [
        'success' => true,
        'messages' => [$userMessageData, $responseData]
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on any error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Async Chat API error: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    
    // Handle API overload specially
    if ($e->getMessage() === "API_OVERLOAD_MAX_RETRIES") {
        sendSSE('system_overload', [
            'aei_name' => htmlspecialchars($aei['name'] ?? 'Your AEI'),
            'message' => htmlspecialchars($aei['name'] ?? 'Your AEI') . ' is experiencing high demand right now. Please try again in a few minutes.',
            'error_type' => 'api_overload'
        ]);
    } else {
        sendSSE('error', ['message' => 'Failed to send message. Please try again.']);
    }
}

// SSE-compatible version of generateAIResponse with callback support
function generateAIResponseWithSSECallback($userMessage, $aei, $user, $sessionId, $includeDebugData = false, $uploadedImage = null, $retryCallback = null) {
    global $pdo;
    
    $debugData = [];
    
    try {
        // Get user timezone
        $userTimezone = $user['timezone'] ?? 'UTC';
        
        // Initialize emotions instance
        $emotions = new Emotions($pdo);
        
        // Get recent chat history
        $chatHistory = getChatHistory($sessionId, 40, $userTimezone);
        if ($includeDebugData) {
            $debugData['chat_history'] = $chatHistory;
        }
        
        // Get current emotional state
        $currentEmotions = $emotions->getEmotionalState($sessionId);
        if ($includeDebugData) {
            $debugData['current_emotions'] = $currentEmotions;
        }
        
        // Process social interactions BEFORE generating response
        if (isset($aei['social_initialized']) && $aei['social_initialized']) {
            require_once '../includes/aei_social_context.php';
            $aeiSocialContext = new AEISocialContext($pdo);
            $socialEmotionalImpact = $aeiSocialContext->processUnprocessedSocialUpdates($aei['id']);
            
            if ($includeDebugData) {
                $debugData['social_emotional_impact'] = $socialEmotionalImpact;
            }
            
            // Apply social emotional impact to current state
            if (!empty($socialEmotionalImpact)) {
                $emotions->adjustEmotionalState($sessionId, $socialEmotionalImpact, 0.3);
                // Refresh current emotions after social impact
                $currentEmotions = $emotions->getEmotionalState($sessionId);
                
                if ($includeDebugData) {
                    $debugData['emotions_after_social'] = $currentEmotions;
                }
            }
        }
        
        // Generate system prompt with emotional context
        $baseSystemPrompt = generateSystemPrompt($aei, $user, $sessionId);
        $emotionContext = $emotions->generateEmotionContext($currentEmotions);
        
        // Initialize memory context
        $memoryContext = '';
        
        // Smart Memory System Integration (Qdrant)
        if (file_exists(__DIR__ . '/../config/memory_config.php')) {
            error_log("MEMORY_DEBUG: memory_config.php found, loading...");
            require_once __DIR__ . '/../config/memory_config.php';
            error_log("MEMORY_DEBUG: Config loaded, checking constants...");
            require_once __DIR__ . '/../includes/memory_manager_inference.php';
            error_log("MEMORY_DEBUG: MemoryManagerInference class loaded");
            
            if (defined('QDRANT_URL') && defined('QDRANT_API_KEY')) {
                error_log("MEMORY_DEBUG: QDRANT_URL and QDRANT_API_KEY are defined, initializing MemoryManager...");
                try {
                    $memoryOptions = [
                        'default_model' => defined('MEMORY_DEFAULT_MODEL') ? MEMORY_DEFAULT_MODEL : 'sentence-transformers/all-MiniLM-L6-v2',
                        'quality_model' => defined('MEMORY_QUALITY_MODEL') ? MEMORY_QUALITY_MODEL : 'mixedbread-ai/mxbai-embed-large-v1',
                        'collection_prefix' => defined('MEMORY_COLLECTION_PREFIX') ? MEMORY_COLLECTION_PREFIX : 'aei_memories_'
                    ];
                    
                    $memoryManager = new MemoryManagerInference(
                        QDRANT_URL, 
                        QDRANT_API_KEY, 
                        $pdo,
                        $memoryOptions
                    );
                    error_log("MEMORY_DEBUG: MemoryManager created successfully");
                    
                    // Get relevant memories with smart context retrieval
                    $memoryLimit = defined('MEMORY_CONTEXT_LIMIT') ? MEMORY_CONTEXT_LIMIT : 60;
                    
                    // Use only user message as search query for better memory retrieval
                    $searchQuery = $userMessage;
                    
                    if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                        error_log("MEMORY_DEBUG: User message query: " . substr($searchQuery, 0, 200));
                    }
                    
                    $memoryContext = $memoryManager->getSmartMemoryContext(
                        $aei['id'], 
                        $searchQuery, 
                        $memoryLimit
                    );
                    error_log("MEMORY_DEBUG: Smart memory context retrieved: " . strlen($memoryContext) . " chars");
                    error_log("MEMORY_DEBUG: Memory context content preview: " . substr($memoryContext, 0, 200));
                    
                    if ($includeDebugData) {
                        $debugData['memory_enabled'] = true;
                        $debugData['memory_system'] = 'qdrant_inference_2025';
                        $debugData['memory_models'] = [
                            'default' => $memoryOptions['default_model'],
                            'quality' => $memoryOptions['quality_model']
                        ];
                        $debugData['memory_context'] = $memoryContext;
                    }
                    
                } catch (Exception $memoryError) {
                    error_log("MEMORY_DEBUG: Memory system error: " . $memoryError->getMessage());
                    error_log("MEMORY_DEBUG: Error trace: " . $memoryError->getTraceAsString());
                    if ($includeDebugData) {
                        $debugData['memory_error'] = $memoryError->getMessage();
                        $debugData['memory_system'] = 'failed_to_initialize';
                    }
                    $memoryContext = ''; // Empty on error
                }
            } else {
                error_log("MEMORY_DEBUG: QDRANT_URL or QDRANT_API_KEY not defined");
                if ($includeDebugData) {
                    $debugData['memory_system'] = 'not_configured';
                    $debugData['memory_enabled'] = false;
                }
            }
        } else {
            error_log("MEMORY_DEBUG: memory_config.php not found");
            if ($includeDebugData) {
                $debugData['memory_system'] = 'config_not_found';
                $debugData['memory_enabled'] = false;
            }
        }
        
        // Combine all context parts
        $systemPrompt = $baseSystemPrompt . "\n\n" . $emotionContext;
        if (!empty($memoryContext)) {
            $systemPrompt .= "\n\n" . $memoryContext;
        }
        
        if ($includeDebugData) {
            $debugData['full_system_prompt'] = $systemPrompt;
            $debugData['api_model'] = 'claude-3-5-sonnet-20241022';
            $debugData['max_tokens'] = 8000;
            $debugData['timestamp'] = date('Y-m-d H:i:s');
        }
        
        // Prepare image data if available
        $imageData = null;
        if ($uploadedImage) {
            include_once '../includes/image_upload.php';
            $imageHandler = new ImageUploadHandler();
            $imagePath = $imageHandler->getImagePath($uploadedImage['filename']);
            $imageData = imageToBase64($imagePath);
        }

        // Call Anthropic API with retry callback for live updates
        $response = callAnthropicAPI($chatHistory, $systemPrompt, 8000, $imageData, $userTimezone, $retryCallback);
        
        if ($includeDebugData) {
            $debugData['api_response'] = $response;
            $debugData['response_length'] = strlen($response);
        }
        
        // Store conversation in smart memory system if enabled
        if (isset($memoryManager) && defined('MEMORY_EXTRACTION_ENABLED') && MEMORY_EXTRACTION_ENABLED) {
            try {
                error_log("MEMORY_DEBUG: Storing Q&A pair as memory...");
                
                // Create Q&A pair format
                $qaMemoryText = "User: " . $userMessage . "\n" . $aei['name'] . ": " . $response;
                
                // Store as single conversation memory
                $qaMemoryId = $memoryManager->storeChatMessage(
                    $aei['id'],
                    $qaMemoryText,
                    'conversation',
                    $sessionId,
                    $user['id']
                );
                
                if ($includeDebugData) {
                    $debugData['memory_storage'] = [
                        'enabled' => true,
                        'qa_pair_stored' => $qaMemoryId ? true : false,
                        'qa_memory_id' => $qaMemoryId,
                        'qa_length' => strlen($qaMemoryText),
                        'storage_method' => 'qa_pairs',
                        'qa_preview' => substr($qaMemoryText, 0, 100) . '...'
                    ];
                }
                
                error_log("MEMORY_DEBUG: Q&A memory stored with ID: " . ($qaMemoryId ?: 'failed'));
            } catch (Exception $memoryStorageError) {
                error_log("MEMORY_DEBUG: Memory storage error: " . $memoryStorageError->getMessage());
                if ($includeDebugData) {
                    $debugData['memory_storage'] = [
                        'enabled' => true,
                        'error' => $memoryStorageError->getMessage(),
                        'storage_method' => 'qa_pairs'
                    ];
                }
            }
        } else if ($includeDebugData) {
            $debugData['memory_storage'] = [
                'enabled' => false,
                'reason' => 'MEMORY_EXTRACTION_ENABLED not set or memoryManager not available'
            ];
        }
        
        // Analyze emotional state after the conversation
        try {
            $conversationHistory = $emotions->getConversationHistory($sessionId, 10);
            $newEmotions = analyzeEmotionalState($conversationHistory, $aei['name'], null, $userTimezone);
            
            if ($includeDebugData) {
                $debugData['analyzed_emotions'] = $newEmotions;
            }
            
            // Validate and update emotions
            if ($emotions->validateEmotionData($newEmotions)) {
                $emotions->adjustEmotionalState($sessionId, $newEmotions, 0.3);
                
                if ($includeDebugData) {
                    $debugData['emotions_validated'] = true;
                    $debugData['final_emotions'] = $emotions->getEmotionalState($sessionId);
                }
            } else if ($includeDebugData) {
                $debugData['emotions_validated'] = false;
            }
        } catch (Exception $e) {
            error_log("Emotion analysis error: " . $e->getMessage());
            if ($includeDebugData) {
                $debugData['emotion_analysis_error'] = $e->getMessage();
            }
        }
        
        if ($includeDebugData) {
            return [
                'response' => $response,
                'debug_data' => $debugData
            ];
        }
        
        return $response;
        
    } catch (Exception $e) {
        error_log("AI Response Error: " . $e->getMessage());
        
        if ($includeDebugData) {
            $debugData['error'] = $e->getMessage();
            $debugData['trace'] = $e->getTraceAsString();
            
            return [
                'response' => $e->getMessage() === "API_OVERLOAD_MAX_RETRIES" ? "API_OVERLOAD_MAX_RETRIES" : "I'm temporarily unavailable. Please try again in a moment.",
                'debug_data' => $debugData
            ];
        }
        
        // Handle overload specially
        if ($e->getMessage() === "API_OVERLOAD_MAX_RETRIES") {
            return "API_OVERLOAD_MAX_RETRIES";
        }
        
        return "I'm temporarily unavailable. Please try again in a moment.";
    }
}
?>