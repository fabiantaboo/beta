<?php

require_once __DIR__ . '/template_engine.php';
require_once __DIR__ . '/emotions.php';
require_once __DIR__ . '/aei_social_context.php';

/**
 * Log API request and response for training data
 */
function logApiRequest($userId, $aeiId, $sessionId, $messageId, $requestPayload, $responsePayload, $systemPrompt, $userMessage, $aiResponse, $model, $tokensUsed, $processingTime, $status = 'success', $errorMessage = null) {
    global $pdo;
    
    try {
        $logId = generateId();
        $stmt = $pdo->prepare("
            INSERT INTO api_request_logs 
            (id, user_id, aei_id, session_id, message_id, request_payload, response_payload, system_prompt, user_message, ai_response, model, tokens_used, processing_time_ms, status, error_message) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $logId, $userId, $aeiId, $sessionId, $messageId,
            json_encode($requestPayload), 
            json_encode($responsePayload),
            $systemPrompt, $userMessage, $aiResponse,
            $model, $tokensUsed, $processingTime,
            $status, $errorMessage
        ]);
        
        error_log("API request logged: $logId");
    } catch (Exception $e) {
        error_log("Failed to log API request: " . $e->getMessage());
    }
}

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
        
        // Add response length instructions
        $responseLength = (int)($aei['response_length'] ?? 2);
        $lengthInstructions = "\n\n🚨 CRITICAL RESPONSE LENGTH REQUIREMENT - MUST FOLLOW EXACTLY:\n";
        
        switch ($responseLength) {
            case 1: // Short
                $lengthInstructions .= "MANDATORY: Your responses MUST be SHORT (2-3 sentences maximum). DO NOT write more than 3 sentences under ANY circumstances. The user has explicitly set this preference. COMPLETELY IGNORE how long the user's messages are - even if they write paragraphs, you MUST respond with only 2-3 sentences. This is NON-NEGOTIABLE. Count your sentences before responding and ensure you never exceed 3 sentences.";
                break;
            case 3: // Long
                $lengthInstructions .= "MANDATORY: Your responses should be DETAILED and COMPREHENSIVE. Write multiple paragraphs when appropriate. Elaborate extensively, provide context, examples, and thorough explanations. DO NOT give short responses. The user wants detailed, in-depth responses. COMPLETELY IGNORE if the user sends short messages - you must still provide detailed, comprehensive responses. This is NON-NEGOTIABLE.";
                break;
            case 2: // Medium
            default:
                $lengthInstructions .= "MANDATORY: Your responses MUST be MEDIUM length (4-5 sentences). DO NOT write more than 6 sentences and DO NOT write less than 4 sentences. COMPLETELY IGNORE the length of the user's messages in the chat history. Even if they send one word, respond with 4-5 sentences. Even if they send paragraphs, limit yourself to 4-5 sentences. Count your sentences and ensure they are between 4-5. This is NON-NEGOTIABLE.";
                break;
        }
        
        $basePrompt .= $lengthInstructions;
        
        return $basePrompt;
    } catch (Exception $e) {
        error_log("System prompt generation error: " . $e->getMessage());
        // AEI data logged for debugging - removed in production
        
        // Return a safe fallback
        return "You are " . ($aei['name'] ?? 'an AEI') . ", an Artificial Emotional Intelligence. Be helpful and conversational.";
    }
}

function callAnthropicAPI($messages, $systemPrompt, $maxTokens = 8000, $imageData = null, $userTimezone = 'UTC', $retryCallback = null, $logData = null) {
    $apiKey = getAnthropicApiKey();
    
    if (!$apiKey) {
        throw new Exception("Anthropic API key not configured");
    }
    
    // Set start time for logging
    if ($logData && !isset($logData['start_time'])) {
        $logData['start_time'] = microtime(true);
    }
    
    // Add current time and timezone context
    $currentTime = new DateTime('now', new DateTimeZone($userTimezone));
    $timeInfo = "\n\nCURRENT TIME CONTEXT:\n";
    $timeInfo .= "Current time: " . $currentTime->format('Y-m-d H:i:s') . " (" . $userTimezone . ")\n";
    $timeInfo .= "Day of week: " . $currentTime->format('l') . "\n";
    $timeInfo .= "Date: " . $currentTime->format('F j, Y') . "\n\n";
    
    // Add timestamp awareness instruction to system prompt
    if (!empty($messages) && isset($messages[0]['timestamp'])) {
        $systemPrompt .= $timeInfo . "IMPORTANT: Each message in the conversation includes a timestamp prefix showing when it was sent in your user's timezone (" . $userTimezone . "). Pay attention to these timestamps and respond naturally to the time context (e.g., if there was a long pause between messages, or if messages were sent in quick succession). Consider the current time when responding appropriately.\n\nCRITICAL: DO NOT include any timestamp prefixes in your responses. Never start your responses with timestamps like [2024-01-15 14:23:15] or similar. Only respond with your natural message content - the timestamp information is for your awareness only, not to be repeated in your responses.";
    } else {
        $systemPrompt .= $timeInfo . "Use this time context to respond appropriately to time-sensitive topics.";
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
        'temperature' => 1.0,
        'system' => $systemPrompt,
        'messages' => $cleanMessages
    ];
    
    // Retry logic for 529 errors (overloaded)
    $maxRetries = 10;
    $retryCount = 0;
    
    while ($retryCount <= $maxRetries) {
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
            // CURL errors should not retry, they're connection issues
            throw new Exception("CURL Error: " . $curlError);
        }
        
        // CRITICAL: Handle ALL errors here, including 529
        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : 'Unknown API error';
            
            // Handle HTTP 529 (overloaded) with retries
            if ($httpCode === 529) {
                $retryCount++;
                
                if ($retryCount <= $maxRetries) {
                    // Call retry callback if provided
                    if ($retryCallback && is_callable($retryCallback)) {
                        $retryCallback($retryCount, $maxRetries);
                    }
                    
                    // Optimized delay strategy for better user experience
                    // Fast retries 1-3: Very short delays for transient issues
                    // Retries 4-7: Progressive delays for persistent issues  
                    // Retries 8-10: Longer delays as last resort
                    if ($retryCount <= 3) {
                        $delay = 1; // Always 1 second for fast retries
                    } elseif ($retryCount <= 7) {
                        $delay = $retryCount - 2; // 2s, 3s, 4s, 5s
                    } else {
                        $delay = 8; // 8 seconds for final retries
                    }
                    
                    error_log("API overloaded (529), retry $retryCount/$maxRetries in {$delay}s");
                    sleep($delay);
                    continue; // Try again
                } else {
                    // Max retries exceeded for 529 errors
                    if ($logData) {
                        $processingTime = (microtime(true) - $logData['start_time']) * 1000;
                        logApiRequest(
                            $logData['user_id'] ?? null, $logData['aei_id'] ?? null, 
                            $logData['session_id'] ?? null, $logData['message_id'] ?? null,
                            $payload, null, $systemPrompt, $logData['user_message'] ?? '', null,
                            'claude-3-5-sonnet-20241022', 0, (int)$processingTime, 'error', 'API_OVERLOAD_MAX_RETRIES'
                        );
                    }
                    throw new Exception("API_OVERLOAD_MAX_RETRIES");
                }
            } else {
                // Non-529 errors should not retry
                if ($logData) {
                    $processingTime = (microtime(true) - $logData['start_time']) * 1000;
                    logApiRequest(
                        $logData['user_id'] ?? null, $logData['aei_id'] ?? null, 
                        $logData['session_id'] ?? null, $logData['message_id'] ?? null,
                        $payload, $errorData ?? null, $systemPrompt, $logData['user_message'] ?? '', null,
                        'claude-3-5-sonnet-20241022', 0, (int)$processingTime, 'error', $errorMessage
                    );
                }
                throw new Exception("API Error (HTTP $httpCode): " . $errorMessage);
            }
        }
        
        // SUCCESS - parse response
        $data = json_decode($response, true, 512, JSON_INVALID_UTF8_IGNORE);
        
        if (!$data || !isset($data['content'][0]['text'])) {
            throw new Exception("Invalid API response format");
        }
        
        $responseText = $data['content'][0]['text'];
        
        // Remove any accidental timestamps from AI response
        $responseText = removeTimestampsFromResponse($responseText);
        
        // Log the API request and response for training data
        if ($logData) {
            $tokensUsed = $data['usage']['input_tokens'] ?? 0 + $data['usage']['output_tokens'] ?? 0;
            $processingTime = (microtime(true) - $logData['start_time']) * 1000; // Convert to milliseconds
            
            logApiRequest(
                $logData['user_id'] ?? null,
                $logData['aei_id'] ?? null, 
                $logData['session_id'] ?? null,
                $logData['message_id'] ?? null,
                $payload,
                $data,
                $systemPrompt,
                $logData['user_message'] ?? '',
                $responseText,
                'claude-3-5-sonnet-20241022',
                $tokensUsed,
                (int)$processingTime
            );
        }
        
        return $responseText;
    }
    
    // This should never be reached, but just in case
    throw new Exception("Unexpected end of retry loop");
}

function getChatHistory($sessionId, $limit = 40, $userTimezone = 'UTC') {
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
            
            // Format timestamp for AEI understanding in user's timezone
            $date = new DateTime($message['created_at'], new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($userTimezone));
            $timestamp = $date->format('Y-m-d H:i:s');
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

function analyzeEmotionalState($conversationHistory, $aeiName, $topic = null, $userTimezone = 'UTC') {
    $apiKey = getAnthropicApiKey();
    
    if (!$apiKey) {
        throw new Exception("Anthropic API key not configured");
    }
    
    // Build conversation context with timestamps
    $conversationContext = "";
    foreach ($conversationHistory as $message) {
        $sender = $message['sender_type'] === 'user' ? 'Human' : $aeiName;
        if (isset($message['created_at'])) {
            $date = new DateTime($message['created_at'], new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($userTimezone));
            $timestamp = $date->format('Y-m-d H:i:s');
            $relativeTime = getRelativeTimeDescription($message['created_at']);
        } else {
            $timestamp = '';
            $relativeTime = '';
        }
        
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
        'temperature' => 0.5,
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
        // Get user timezone
        $userTimezone = $user['timezone'] ?? 'UTC';
        
        // Initialize emotions instance
        $emotions = new Emotions($pdo);
        
        // Initialize Memory Manager with Qdrant Inference (2025)
        $memoryManager = null;
        $memoryContext = "";
        
        // Memory system initialization
        
        if (file_exists(__DIR__ . '/../config/memory_config.php')) {
            // Memory config found
            require_once __DIR__ . '/../config/memory_config.php';
            // Config loaded
            require_once __DIR__ . '/memory_manager_inference.php';
            // MemoryManagerInference class loaded
            
            if (defined('QDRANT_URL') && defined('QDRANT_API_KEY')) {
                // QDRANT credentials found, initializing MemoryManager
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
                    // MemoryManager created successfully
                    
                    // Get relevant memories with smart context retrieval
                    $memoryLimit = defined('MEMORY_CONTEXT_LIMIT') ? MEMORY_CONTEXT_LIMIT : 60; // Increased for maximum memory context
                    
                    // Get last AEI message for better context
                    $lastAEIMessage = '';
                    $stmt = $pdo->prepare("
                        SELECT message_text 
                        FROM chat_messages 
                        WHERE session_id = ? AND sender_type = 'aei' 
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ");
                    $stmt->execute([$sessionId]);
                    $lastMessage = $stmt->fetch();
                    if ($lastMessage) {
                        $lastAEIMessage = $lastMessage['message_text'];
                    }
                    
                    // Combine AEI context + user message for better memory search with clear attribution
                    $contextualQuery = '';
                    if (!empty($lastAEIMessage)) {
                        $contextualQuery .= $aei['name'] . ': ' . $lastAEIMessage . ' ';
                    }
                    $contextualQuery .= 'User: ' . $userMessage;
                    $contextualQuery = trim($contextualQuery);
                    
                    // Memory query processing - debug removed
                    
                    $memoryContext = $memoryManager->getSmartMemoryContext(
                        $aei['id'], 
                        $contextualQuery, 
                        $memoryLimit
                    );
                    // Memory context retrieved successfully
                    
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
                    error_log("Memory system error: " . $memoryError->getMessage());
                    if ($includeDebugData) {
                        $debugData['memory_error'] = $memoryError->getMessage();
                        $debugData['memory_system'] = 'failed_to_initialize';
                    }
                }
            } else {
                error_log("Missing QDRANT configuration - memory system disabled");
                if ($includeDebugData) {
                    $debugData['memory_enabled'] = false;
                    $debugData['memory_error'] = 'Missing QDRANT_URL or QDRANT_API_KEY in config';
                }
            }
        } else {
            // Memory config not found - memory system disabled
            if ($includeDebugData) {
                $debugData['memory_enabled'] = false;
                $debugData['memory_note'] = 'Memory config not found - copy memory_config.example.php to memory_config.php';
            }
        }
        
        // Memory system initialization complete
        
        // Get recent chat history (including the current message that was just saved)
        $chatHistory = getChatHistory($sessionId, 40, $userTimezone);
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

        // Call Anthropic API with optional image data and retry callback
        $retryCallback = function($retryCount, $maxRetries) use ($aei) {
            // This gets called during retries - we can use it to send status updates
            error_log("API Retry $retryCount/$maxRetries for AEI: " . ($aei['name'] ?? 'unknown'));
        };
        
        // Prepare logging data for training (skip admin users)
        $logData = null;
        
        // Check if user is admin - don't log admin conversations
        try {
            $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userInfo = $stmt->fetch();
            $isAdmin = $userInfo && $userInfo['is_admin'];
        } catch (Exception $e) {
            $isAdmin = false; // Default to non-admin if query fails
        }
        
        if (!$isAdmin) {
            $userMessage = '';
            if (!empty($chatHistory)) {
                $lastMessage = end($chatHistory);
                if ($lastMessage['role'] === 'user') {
                    $userMessage = is_array($lastMessage['content']) ? 
                        ($lastMessage['content'][0]['text'] ?? '') : 
                        $lastMessage['content'];
                }
            }
            
            $logData = [
                'user_id' => $userId,
                'aei_id' => $aei['id'],
                'session_id' => $sessionId,
                'message_id' => $messageId ?? generateId(),
                'user_message' => $userMessage,
                'start_time' => microtime(true)
            ];
        }
        
        $response = callAnthropicAPI($chatHistory, $systemPrompt, 8000, $imageData, $userTimezone, $retryCallback, $logData);
        
        if ($includeDebugData) {
            $debugData['api_response'] = $response;
            $debugData['response_length'] = strlen($response);
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
        
        // Store extracted memories in batches for better context  
        if ($memoryManager && defined('MEMORY_EXTRACTION_ENABLED') && MEMORY_EXTRACTION_ENABLED) {
            try {
                // Get message count for this session to determine if we should extract
                $stmt = $pdo->prepare("SELECT COUNT(*) as msg_count FROM chat_messages WHERE session_id = ?");
                $stmt->execute([$sessionId]);
                $messageCount = $stmt->fetch()['msg_count'];
                
                // Extract memories every 5 messages for better context
                if ($messageCount % 5 == 0) {
                    // Batch extracting memories
                    
                    // Get last 10 messages for rich context analysis
                    $recentHistory = getChatHistory($sessionId, 10, $user['timezone'] ?? 'UTC');
                    
                    if (!empty($recentHistory)) {
                        // Extract structured memories from recent conversation batch
                        $extractedMemories = $memoryManager->extractMemoriesFromConversation(
                            $aei['id'],
                            $recentHistory,
                            $user['id'],
                            $sessionId
                        );
                        
                        // Batch extraction completed
                    } else {
                        $extractedMemories = [];
                        // No recent history for batch extraction
                    }
                } else {
                    $extractedMemories = [];
                    // Skipping extraction until batch threshold
                }
                
                if ($includeDebugData) {
                    $debugData['memory_storage'] = [
                        'enabled' => true,
                        'extracted_count' => count($extractedMemories),
                        'storage_method' => 'extracted_facts',
                        'memories' => $extractedMemories
                    ];
                }
                
                // Memory extraction completed
                
            } catch (Exception $memoryError) {
                error_log("Memory extraction error: " . $memoryError->getMessage());
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
        
        // Handle API overload (max retries exceeded) specially
        if ($e->getMessage() === "API_OVERLOAD_MAX_RETRIES") {
            if ($includeDebugData) {
                $debugData['error'] = $e->getMessage();
                $debugData['error_type'] = 'api_overload_max_retries';
                $debugData['trace'] = $e->getTraceAsString();
                
                return [
                    'response' => "API_OVERLOAD_MAX_RETRIES",
                    'debug_data' => $debugData
                ];
            }
            
            // Special response for max retries exceeded
            throw new Exception("API_OVERLOAD_MAX_RETRIES");
        }
        
        // Handle HTTP 529 errors during the first few retries - don't show error to user
        if (strpos($e->getMessage(), "API Error (HTTP 529)") !== false) {
            // This is a 529 error that happened during the initial tries
            // We should retry transparently without showing the user an error
            if ($includeDebugData) {
                $debugData['error'] = 'Transparent retry in progress';
                $debugData['original_error'] = $e->getMessage();
                $debugData['trace'] = $e->getTraceAsString();
                
                return [
                    'response' => "TRANSPARENT_RETRY_IN_PROGRESS",
                    'debug_data' => $debugData
                ];
            }
            
            // For regular users, throw the overload exception to trigger proper handling
            throw new Exception("API_OVERLOAD_MAX_RETRIES");
        }
        
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
        'temperature' => 0.5,
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