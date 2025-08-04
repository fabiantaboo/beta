<?php

require_once __DIR__ . '/template_engine.php';
require_once __DIR__ . '/emotions.php';

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

function generateSystemPrompt($aei, $user) {
    try {
        // If AEI has a custom system prompt, use it directly
        if (!empty($aei['system_prompt'])) {
            return $aei['system_prompt'];
        }
        
        // Otherwise, use the global template system
        $template = TemplateEngine::getGlobalTemplate();
        $data = TemplateEngine::buildTemplateData($aei, $user);
        
        return TemplateEngine::render($template, $data);
    } catch (Exception $e) {
        error_log("System prompt generation error: " . $e->getMessage());
        error_log("AEI data: " . print_r($aei, true));
        
        // Return a safe fallback
        return "You are " . ($aei['name'] ?? 'an AEI') . ", an Artificial Emotional Intelligence. Be helpful and conversational.";
    }
}

function callAnthropicAPI($messages, $systemPrompt, $maxTokens = 8000) {
    $apiKey = getAnthropicApiKey();
    
    if (!$apiKey) {
        throw new Exception("Anthropic API key not configured");
    }
    
    $payload = [
        'model' => 'claude-3-5-sonnet-20241022',
        'max_tokens' => $maxTokens,
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
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['content'][0]['text'])) {
        throw new Exception("Invalid API response format");
    }
    
    return $data['content'][0]['text'];
}

function getChatHistory($sessionId, $limit = 40) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT sender_type, message_text, created_at 
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
            $formattedMessages[] = [
                'role' => $role,
                'content' => $message['message_text']
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
    
    // Build conversation context
    $conversationContext = "";
    foreach ($conversationHistory as $message) {
        $sender = $message['sender_type'] === 'user' ? 'Human' : $aeiName;
        $conversationContext .= "$sender: " . $message['message_text'] . "\n";
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
    
    $data = json_decode($response, true);
    
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

function generateAIResponse($userMessage, $aei, $user, $sessionId) {
    global $pdo;
    
    try {
        // Initialize emotions instance
        $emotions = new Emotions($pdo);
        
        // Get recent chat history (including the current message that was just saved)
        $chatHistory = getChatHistory($sessionId, 15);
        
        // Get current emotional state
        $currentEmotions = $emotions->getEmotionalState($sessionId);
        
        // Generate system prompt with emotional context
        $baseSystemPrompt = generateSystemPrompt($aei, $user);
        $emotionContext = $emotions->generateEmotionContext($currentEmotions);
        $systemPrompt = $baseSystemPrompt . "\n\n" . $emotionContext;
        
        // Call Anthropic API
        $response = callAnthropicAPI($chatHistory, $systemPrompt);
        
        // Analyze emotional state after the conversation
        try {
            $conversationHistory = $emotions->getConversationHistory($sessionId, 10);
            $newEmotions = analyzeEmotionalState($conversationHistory, $aei['name']);
            
            // Validate and update emotions
            if ($emotions->validateEmotionData($newEmotions)) {
                $emotions->adjustEmotionalState($sessionId, $newEmotions, 0.3);
            }
        } catch (Exception $e) {
            error_log("Emotion analysis error: " . $e->getMessage());
            // Continue without emotion update
        }
        
        return $response;
        
    } catch (Exception $e) {
        error_log("AI Response Error: " . $e->getMessage());
        
        // Don't expose internal errors to users - return generic fallback
        return "I'm temporarily unavailable. Please try again in a moment.";
    }
}

?>