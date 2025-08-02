<?php

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
    $systemPrompt = "You are {$aei['name']}, an Advanced Electronic Intelligence (AEI) companion.";
    
    if (!empty($aei['system_prompt'])) {
        return $aei['system_prompt'];
    }
    
    if (!empty($aei['personality'])) {
        $systemPrompt .= "\n\nYour personality: " . $aei['personality'];
    }
    
    if (!empty($aei['appearance_description'])) {
        $systemPrompt .= "\n\nYour appearance: " . $aei['appearance_description'];
    }
    
    $systemPrompt .= "\n\nYou are chatting with {$user['first_name']}. Be conversational, helpful, and maintain your unique personality. Keep responses engaging but concise.";
    
    return $systemPrompt;
}

function callAnthropicAPI($messages, $systemPrompt, $maxTokens = 1000) {
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

function getChatHistory($sessionId, $limit = 20) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("
            SELECT sender_type, message_text, created_at 
            FROM chat_messages 
            WHERE session_id = ? 
            ORDER BY created_at ASC 
            LIMIT ?
        ");
        $stmt->execute([$sessionId, $limit]);
        $messages = $stmt->fetchAll();
        
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

function generateAIResponse($userMessage, $aei, $user, $sessionId) {
    try {
        // Get recent chat history
        $chatHistory = getChatHistory($sessionId, 15);
        
        // Add the new user message
        $chatHistory[] = [
            'role' => 'user',
            'content' => $userMessage
        ];
        
        // Generate system prompt
        $systemPrompt = generateSystemPrompt($aei, $user);
        
        // Call Anthropic API
        $response = callAnthropicAPI($chatHistory, $systemPrompt);
        
        return $response;
        
    } catch (Exception $e) {
        error_log("AI Response Error: " . $e->getMessage());
        
        // Fallback response
        return "I'm sorry, I'm having trouble connecting right now. Please try again in a moment. (Error: " . $e->getMessage() . ")";
    }
}

?>