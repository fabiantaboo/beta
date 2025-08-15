<?php

require_once __DIR__ . '/template_engine.php';
require_once __DIR__ . '/emotions.php';
require_once __DIR__ . '/aei_social_context.php';

function getRelativeTimeDescription($timestamp) {
    $now = time();
    $messageTime = strtotime($timestamp);
    $diff = $now - $messageTime;
    
    if ($diff < 60) {
        return "just now";
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes == 1 ? "1 minute ago" : "$minutes minutes ago";
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours == 1 ? "1 hour ago" : "$hours hours ago";
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days == 1 ? "1 day ago" : "$days days ago";
    } else {
        $weeks = floor($diff / 604800);
        return $weeks == 1 ? "1 week ago" : "$weeks weeks ago";
    }
}

function removeTimestampsFromResponse($response) {
    // Remove timestamp patterns like [2024-01-15 14:23:15] or [2024-01-15 14:23:15 - 2 minutes ago]
    $timestampPattern = '/^\s*\[[\d\-\s:]+(?:\s*-\s*[^\]]+)?\]\s*/';
    
    // Split response into lines and process each
    $lines = explode("\n", $response);
    $cleanedLines = [];
    
    foreach ($lines as $line) {
        // Remove timestamp from beginning of line
        $cleanedLine = preg_replace($timestampPattern, '', $line);
        $cleanedLines[] = $cleanedLine;
    }
    
    // Rejoin and trim any leading/trailing whitespace
    $cleanedResponse = trim(implode("\n", $cleanedLines));
    
    return $cleanedResponse;
}

function getAnthropicApiKey() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'anthropic_api_key'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : null;
    } catch (PDOException $e) {
        error_log("Error getting API key: " . $e->getMessage());
        return null;
    }
}

function generateSystemPrompt($aei, $user, $sessionId = null) {
    global $pdo;
    
    try {
        // If AEI has a custom system prompt, use it directly
        if (!empty($aei['system_prompt'])) {
            $basePrompt = $aei['system_prompt'];
        } else {
            // Otherwise, use the global template system
            $template = TemplateEngine::getGlobalTemplate();
            $data = TemplateEngine::buildTemplateData($aei, $user);
            
            // NEW: Add social context variables
            if ($sessionId && isset($aei['social_initialized']) && $aei['social_initialized']) {
                $aeiSocialContext = new AEISocialContext($pdo);
                
                $socialData = [
                    'social_summary' => $aeiSocialContext->getSocialSummary($aei['id']),
                    'recent_contact_news' => $aeiSocialContext->getRecentContactNews($aei['id']),
                    'social_concerns' => $aeiSocialContext->getCurrentConcerns($aei['id']),
                    'friend_names' => $aeiSocialContext->getImportantContactNames($aei['id']),
                    'social_energy' => $aeiSocialContext->getSocialEnergyDescription($aei['id']),
                    'topics_to_mention' => $aeiSocialContext->getTopicsToMention($aei['id'])
                ];
                
                $data = array_merge($data, $socialData);
            }
            
            $basePrompt = TemplateEngine::render($template, $data);
        }
        
        // Add social context for chat if available
        if ($sessionId && isset($aei['social_initialized']) && $aei['social_initialized']) {
            $aeiSocialContext = new AEISocialContext($pdo);
            $socialChatContext = $aeiSocialContext->generateSocialChatContext($aei['id']);
            
            if (!empty($socialChatContext)) {
                $basePrompt .= "\n" . $socialChatContext;
            }
        }
        
        return $basePrompt;
    } catch (Exception $e) {
        error_log("System prompt generation error: " . $e->getMessage());
        error_log("AEI data: " . print_r($aei, true));
        
        // Return a safe fallback
        return "You are " . ($aei['name'] ?? 'an AEI') . ", an Artificial Emotional Intelligence. Be helpful and conversational.";
    }
}

function callAnthropicAPI($messages, $systemPrompt, $maxTokens = 8000, $imageData = null) {
    $apiKey = getAnthropicApiKey();
    
    if (!$apiKey) {
        throw new Exception("Anthropic API key not configured");
    }
    
    // Add timestamp awareness instruction to system prompt
    if (!empty($messages) && isset($messages[0]['timestamp'])) {
        $systemPrompt .= "\n\nIMPORTANT: Each message in the conversation includes a timestamp prefix showing when it was sent. Pay attention to these timestamps and respond naturally to the time context (e.g., if there was a long pause between messages, or if messages were sent in quick succession).\n\nCRITICAL: DO NOT include any timestamp prefixes in your responses. Never start your responses with timestamps like [2024-01-15 14:23:15] or similar. Only respond with your natural message content - the timestamp information is for your awareness only, not to be repeated in your responses.";
    }
    
    // If image data is provided, modify the last user message to include the image
    if ($imageData && !empty($messages)) {
        $lastMessageIndex = count($messages) - 1;
        if ($messages[$lastMessageIndex]['role'] === 'user') {
            // Convert text content to array format and add image
            $textContent = $messages[$lastMessageIndex]['content'];
            $messages[$lastMessageIndex]['content'] = [
                [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $imageData['mime_type'],
                        'data' => $imageData['data']
                    ]
                ]
            ];
            
            // Add text content if it exists
            if (!empty($textContent)) {
                array_unshift($messages[$lastMessageIndex]['content'], [
                    'type' => 'text',
                    'text' => $textContent
                ]);
            } else {
                // If no text, add a default prompt
                array_unshift($messages[$lastMessageIndex]['content'], [
                    'type' => 'text',
                    'text' => 'What do you see in this image?'
                ]);
            }
        }
    }

    // Format messages with timestamps in content
    $cleanMessages = [];
    foreach ($messages as $message) {
        $content = $message['content'];
        
        // Add timestamp prefix to content if available
        if (isset($message['timestamp']) && isset($message['sent_at'])) {
            $timestamp = $message['timestamp'];
            $relativeTime = $message['sent_at'];
            
            // Handle array content (multimodal messages with images)
            if (is_array($content)) {
                // Find the first text content and prepend timestamp
                foreach ($content as $index => $contentItem) {
                    if ($contentItem['type'] === 'text') {
                        $content[$index]['text'] = "[$timestamp - $relativeTime] " . $contentItem['text'];
                        break;
                    }
                }
            } else {
                // Handle string content (regular text messages)
                $content = "[$timestamp - $relativeTime] $content";
            }
        }
        
        $cleanMessages[] = [
            'role' => $message['role'],
            'content' => $content
        ];
    }
    
    $payload = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => $maxTokens,
        'system' => $systemPrompt,
        'messages' => $cleanMessages
    ];
    
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Ayuni-Beta/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("CURL Error: " . $curlError);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'Unknown API error';
        throw new Exception("API Error (HTTP $httpCode): " . $errorMessage);
    }
    
    $data = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE);
    
    if (!$data || !isset($data['content'][0]['text'])) {
        throw new Exception("Invalid API response format");
    }
    
    $responseText = $data['content'][0]['text'];
    
    // Remove any accidental timestamps from AI response
    $responseText = removeTimestampsFromResponse($responseText);
    
    return $responseText;
}

function getChatHistory($sessionId, $limit = 40) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT sender_type, message_text, created_at, has_image, image_filename, image_original_name 
            FROM chat_messages 
            WHERE session_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$sessionId, $limit]);
        $messages = array_reverse($stmt->fetchAll());
        
        $formattedMessages = [];
        foreach ($messages as $message) {
            $role = $message['sender_type'] === 'user' ? 'user' : 'assistant';
            
            // Format timestamp for AEI understanding
            $timestamp = date('Y-m-d H:i:s', strtotime($message['created_at']));
            $relativeTime = getRelativeTimeDescription($message['created_at']);
            
            // Handle messages with images - keep as string for context, image already processed
            $content = $message['message_text'];
            if ($message['has_image'] && !empty($message['image_filename'])) {
                // For user messages with images, add context but keep as text
                // The actual image was already processed when first sent
                if ($role === 'user') {
                    $imageContext = "shared an image";
                    if (!empty($message['image_original_name'])) {
                        $imageContext .= " (" . $message['image_original_name'] . ")";
                    }
                    
                    if (!empty($content)) {
                        $content = "[User shared an image: " . $imageContext . "] " . $content;
                    } else {
                        $content = "[User shared an image: " . $imageContext . "]";
                    }
                }
            }
            
            $formattedMessages[] = [
                'role' => $role,
                'content' => $content,
                'timestamp' => $timestamp,
                'sent_at' => $relativeTime
            ];
        }
        
        return $formattedMessages;
    } catch (PDOException $e) {
        error_log("Error getting chat history: " . $e->getMessage());
        return [];
    }
}

function analyzeEmotionalState($conversationHistory, $aeiName, $topic = null) {
    $apiKey = getAnthropicApiKey();
    
    if (!$apiKey) {
        throw new Exception("Anthropic API key not configured");
    }
    
    // Build conversation context with timestamps
    $conversationContext = "";
    foreach ($conversationHistory as $message) {
        $sender = $message['sender_type'] === 'user' ? 'Human' : $aeiName;
        $timestamp = isset($message['created_at']) ? date('Y-m-d H:i:s', strtotime($message['created_at'])) : '';
        $relativeTime = isset($message['created_at']) ? getRelativeTimeDescription($message['created_at']) : '';
        
        if ($timestamp) {
            $conversationContext .= "[$timestamp - $relativeTime] $sender: " . $message['message_text'] . "\n";
        } else {
            $conversationContext .= "$sender: " . $message['message_text'] . "\n";
        }
    }
    
    $systemPrompt = "You are an emotion analysis expert. Analyze the emotional state of the AEI character '$aeiName' based on the conversation history.

IMPORTANT: Return ONLY a valid JSON object with emotion values between 0.0 and 1.0 (in 0.1 increments).
Use EXACTLY these 18 emotions: joy, sadness, fear, anger, surprise, disgust, trust, anticipation, shame, love, contempt, loneliness, pride, envy, nostalgia, gratitude, frustration, boredom

Required format: {\"joy\": 0.3, \"sadness\": 0.7, \"fear\": 0.2, \"anger\": 0.1, \"surprise\": 0.4, \"disgust\": 0.0, \"trust\": 0.8, \"anticipation\": 0.6, \"shame\": 0.0, \"love\": 0.5, \"contempt\": 0.0, \"loneliness\": 0.2, \"pride\": 0.3, \"envy\": 0.0, \"nostalgia\": 0.1, \"gratitude\": 0.4, \"frustration\": 0.2, \"boredom\": 0.0}

Do not include any explanation or additional text - ONLY the JSON object.";

    $messages = [
        [
            'role' => 'user',
            'content' => "Conversation history:\n$conversationContext\n\nAnalyze the emotional state of $aeiName."
        ]
    ];
    
    $payload = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 500,
        'system' => $systemPrompt,
        'messages' => $messages
    ];
    
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01'
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Ayuni-Beta/1.0'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception("CURL Error: " . $curlError);
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'Unknown API error';
        throw new Exception("API Error (HTTP $httpCode): " . $errorMessage);
    }
    
    $data = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE);
    
    if (!$data || !isset($data['content'][0]['text'])) {
        throw new Exception("Invalid API response format");
    }
    
    $emotionText = trim($data['content'][0]['text']);
    $emotionData = json_decode($emotionText, true);
    
    if (!$emotionData) {
        throw new Exception("Invalid emotion data format");
    }
    
    return $emotionData;
}

function generateAIResponse($userMessage, $aei, $user, $sessionId, $includeDebugData = false, $uploadedImage = null) {
    global $pdo;
    
    $debugData = [];
    
    try {
        // Initialize emotions instance
        $emotions = new Emotions($pdo);
        
        // Initialize Memory Manager with Qdrant Inference (2025)
        $memoryManager = null;
        $memoryContext = "";
        
        // DEBUG: Log memory system initialization attempt
        error_log("MEMORY_DEBUG: === MEMORY SYSTEM INIT START ===");
        error_log("MEMORY_DEBUG: Checking memory config file...");
        
        if (file_exists(__DIR__ . '/../config/memory_config.php')) {
            error_log("MEMORY_DEBUG: memory_config.php found, loading...");
            require_once __DIR__ . '/../config/memory_config.php';
            error_log("MEMORY_DEBUG: Config loaded, checking constants...");
            require_once __DIR__ . '/memory_manager_inference.php';
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
                    $memoryContext = $memoryManager->getSmartMemoryContext(
                        $aei['id'], 
                        $userMessage, 
                        defined('MEMORY_CONTEXT_LIMIT') ? MEMORY_CONTEXT_LIMIT : 20
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
                }
            } else {
                error_log("MEMORY_DEBUG: Missing QDRANT_URL or QDRANT_API_KEY - URL defined: " . (defined('QDRANT_URL') ? 'YES' : 'NO') . ", KEY defined: " . (defined('QDRANT_API_KEY') ? 'YES' : 'NO'));
                if ($includeDebugData) {
                    $debugData['memory_enabled'] = false;
                    $debugData['memory_error'] = 'Missing QDRANT_URL or QDRANT_API_KEY in config';
                }
            }
        } else {
            error_log("MEMORY_DEBUG: memory_config.php NOT FOUND at: " . __DIR__ . '/../config/memory_config.php');
            error_log("MEMORY_DEBUG: === MEMORY SYSTEM DISABLED ===");
            if ($includeDebugData) {
                $debugData['memory_enabled'] = false;
                $debugData['memory_note'] = 'Memory config not found - copy memory_config.example.php to memory_config.php';
            }
        }
        
        error_log("MEMORY_DEBUG: === MEMORY SYSTEM FINAL STATUS ===");
        error_log("MEMORY_DEBUG: Memory Manager: " . ($memoryManager ? 'INITIALIZED' : 'NULL'));
        error_log("MEMORY_DEBUG: Memory Context Length: " . strlen($memoryContext) . " chars");
        
        // Get recent chat history (including the current message that was just saved)
        $chatHistory = getChatHistory($sessionId, 40);
        if ($includeDebugData) {
            $debugData['chat_history'] = $chatHistory;
        }
        
        // Get current emotional state
        $currentEmotions = $emotions->getEmotionalState($sessionId);
        if ($includeDebugData) {
            $debugData['current_emotions'] = $currentEmotions;
        }
        
        // Process unprocessed social interactions BEFORE generating response
        if (isset($aei['social_initialized']) && $aei['social_initialized']) {
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
        
        // Generate system prompt with emotional, social, and memory context
        $baseSystemPrompt = generateSystemPrompt($aei, $user, $sessionId);
        $emotionContext = $emotions->generateEmotionContext($currentEmotions);
        $systemPrompt = $baseSystemPrompt . "\n\n" . $emotionContext . $memoryContext;
        
        if ($includeDebugData) {
            $debugData['base_system_prompt'] = $baseSystemPrompt;
            $debugData['emotion_context'] = $emotionContext;
            $debugData['full_system_prompt'] = $systemPrompt;
            $debugData['api_model'] = 'claude-3-5-sonnet-20241022';
            $debugData['max_tokens'] = 8000;
            $debugData['timestamp'] = date('Y-m-d H:i:s');
            
            // Capture the complete API request payload
            $debugData['api_request_payload'] = [
                'model' => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 8000,
                'system' => $systemPrompt,
                'messages' => $chatHistory
            ];
        }
        
        // Prepare image data if available
        $imageData = null;
        if ($uploadedImage) {
            include_once 'image_upload.php';
            $imageHandler = new ImageUploadHandler();
            $imagePath = $imageHandler->getImagePath($uploadedImage['filename']);
            $imageData = imageToBase64($imagePath);
            
            if ($includeDebugData) {
                $debugData['image_info'] = [
                    'filename' => $uploadedImage['filename'],
                    'original_name' => $uploadedImage['original_name'],
                    'mime_type' => $uploadedImage['mime_type'],
                    'size' => $uploadedImage['size']
                ];
            }
        }

        // Call Anthropic API with optional image data
        $response = callAnthropicAPI($chatHistory, $systemPrompt, 8000, $imageData);
        
        if ($includeDebugData) {
            $debugData['api_response'] = $response;
            $debugData['response_length'] = strlen($response);
        }
        
        // Analyze emotional state after the conversation
        try {
            $conversationHistory = $emotions->getConversationHistory($sessionId, 10);
            $newEmotions = analyzeEmotionalState($conversationHistory, $aei['name']);
            
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
        
        // Store Q&A pair as single memory
        if ($memoryManager && defined('MEMORY_EXTRACTION_ENABLED') && MEMORY_EXTRACTION_ENABLED) {
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
                
                if (defined('MEMORY_DEBUG') && MEMORY_DEBUG) {
                    error_log("MEMORY_DEBUG: Stored Q&A pair: $qaMemoryId (" . strlen($qaMemoryText) . " chars)");
                }
                
            } catch (Exception $memoryError) {
                error_log("MEMORY_DEBUG: Memory storage error: " . $memoryError->getMessage());
                if ($includeDebugData) {
                    $debugData['memory_storage_error'] = $memoryError->getMessage();
                }
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
                'response' => "I'm temporarily unavailable. Please try again in a moment.",
                'debug_data' => $debugData
            ];
        }
        
        // Don't expose internal errors to users - return generic fallback
        return "I'm temporarily unavailable. Please try again in a moment.";
    }
}

function generateAEIConfiguration($description) {
    $apiKey = getAnthropicApiKey();
    
    if (!$apiKey) {
        throw new Exception("Anthropic API key not configured");
    }
    
    // Get all available options from the AEI creator
    $personalityTraits = [
        'Adventurous', 'Affectionate', 'Ambitious', 'Analytical', 'Artistic', 'Assertive',
        'Calm', 'Caring', 'Charismatic', 'Cheerful', 'Confident', 'Creative', 'Curious',
        'Determined', 'Diplomatic', 'Dramatic', 'Empathetic', 'Energetic', 'Enthusiastic',
        'Flirty', 'Funny', 'Gentle', 'Honest', 'Humble', 'Independent', 'Intelligent',
        'Introverted', 'Intuitive', 'Kind', 'Loyal', 'Mysterious', 'Optimistic',
        'Passionate', 'Patient', 'Playful', 'Protective', 'Rebellious', 'Reserved',
        'Romantic', 'Sarcastic', 'Sensitive', 'Spontaneous', 'Supportive', 'Thoughtful', 'Witty'
    ];
    
    $communicationStyles = [
        'casual' => 'Casual and laid-back',
        'formal' => 'Formal and professional', 
        'playful' => 'Playful and fun',
        'romantic' => 'Romantic and affectionate',
        'intellectual' => 'Intellectual and deep',
        'supportive' => 'Supportive and caring'
    ];
    
    $speakingTraits = [
        'uses_emojis', 'uses_slang', 'asks_questions', 'gives_compliments',
        'tells_jokes', 'shares_stories', 'gives_advice', 'uses_pet_names'
    ];
    
    $interests = [
        'Art', 'Music', 'Reading', 'Movies', 'Gaming', 'Sports', 'Travel', 'Cooking',
        'Technology', 'Science', 'History', 'Philosophy', 'Photography', 'Dancing',
        'Writing', 'Nature', 'Fashion', 'Fitness', 'Meditation', 'Learning'
    ];
    
    $systemPrompt = "You are an AI assistant that analyzes user descriptions and generates AEI (Artificial Emotional Intelligence) configurations. Based on the user's description, you need to select appropriate values from the available options.

Return a JSON response with the following structure:
{
    \"name\": \"suggested name\",
    \"age\": number (18-100),
    \"gender\": \"male\"|\"female\"|\"non-binary\"|\"other\",
    \"personality_traits\": [\"trait1\", \"trait2\", ...] (max 8 from available list),
    \"communication_style\": \"style_key\",
    \"speaking_traits\": [\"trait1\", \"trait2\", ...] (max 6 from available list),
    \"interests\": [\"interest1\", \"interest2\", ...] (max 10 from available list),
    \"hair_color\": \"color\",
    \"eye_color\": \"color\",
    \"height\": \"height description\",
    \"build\": \"build description\",
    \"style\": \"style description\",
    \"background\": \"background story (2-3 sentences)\",
    \"quirks\": \"unique quirks (1-2 sentences)\",
    \"occupation\": \"occupation\",
    \"goals\": \"life goals (1-2 sentences)\",
    \"relationship_type\": \"friend\"|\"romantic_partner\"|\"family_member\"|\"mentor\"|\"companion\",
    \"relationship_history\": \"relationship history (1-2 sentences)\",
    \"relationship_dynamics\": [\"dynamic1\", \"dynamic2\", ...] (max 4)
}

Available personality traits: " . implode(', ', $personalityTraits) . "
Available communication styles: " . implode(', ', array_keys($communicationStyles)) . "
Available speaking traits: " . implode(', ', $speakingTraits) . "
Available interests: " . implode(', ', $interests) . "
Available relationship dynamics: playful_banter, deep_conversations, shared_activities, emotional_support, intellectual_debates, romantic_tension, protective_instincts, mentoring_guidance

Be creative but realistic. Make sure all selected traits and interests are from the provided lists.";
    
    $messages = [
        [
            'role' => 'user',
            'content' => "Please analyze this description and generate an AEI configuration: " . $description
        ]
    ];
    
    $payload = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => 4000,
        'system' => $systemPrompt,
        'messages' => $messages
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        throw new Exception("API request failed with code: " . $httpCode);
    }
    
    $responseData = json_decode($response, true);
    if (!$responseData || !isset($responseData['content'][0]['text'])) {
        throw new Exception("Invalid API response format");
    }
    
    $configText = $responseData['content'][0]['text'];
    
    // Extract JSON from the response
    if (preg_match('/\{.*\}/s', $configText, $matches)) {
        $config = json_decode($matches[0], true);
        if ($config) {
            return $config;
        }
    }
    
    throw new Exception("Could not parse AI configuration response");
}

?>