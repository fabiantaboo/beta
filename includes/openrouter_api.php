<?php

/**
 * OpenRouter API integration for social system only
 * Uses Google Gemini 2.0 Flash model
 */

function getOpenRouterApiKey() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'openrouter_api_key'");
        $stmt->execute();
        $result = $stmt->fetch();
        return $result ? $result['setting_value'] : null;
    } catch (PDOException $e) {
        error_log("Error getting OpenRouter API key: " . $e->getMessage());
        return null;
    }
}

/**
 * Call OpenRouter API for social system interactions
 * Uses Google Gemini 2.0 Flash model specifically
 */
function callOpenRouterForSocial($messages, $systemPrompt, $maxTokens = 8000) {
    $apiKey = getOpenRouterApiKey();
    
    if (!$apiKey) {
        error_log("OpenRouter API key not configured - falling back to Anthropic");
        // Fallback to Anthropic if OpenRouter not configured
        return callAnthropicAPI($messages, $systemPrompt, $maxTokens);
    }
    
    // Format messages for OpenRouter
    $formattedMessages = [];
    
    // Add system message first
    if (!empty($systemPrompt)) {
        $formattedMessages[] = [
            'role' => 'system',
            'content' => $systemPrompt
        ];
    }
    
    // Add conversation messages
    foreach ($messages as $message) {
        $formattedMessages[] = [
            'role' => $message['role'],
            'content' => $message['content']
        ];
    }
    
    $payload = [
        'model' => 'google/gemini-2.0-flash-001',  // Using Gemini 2.0 Flash
        'messages' => $formattedMessages,
        'max_tokens' => $maxTokens,
        'temperature' => 1.0,
        'top_p' => 1.0,
        'frequency_penalty' => 0,
        'presence_penalty' => 0,
        'stream' => false
    ];
    
    // Retry logic for rate limits
    $maxRetries = 5;
    $retryCount = 0;
    
    while ($retryCount <= $maxRetries) {
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://ayuni.nexinnovations.us',  // Required for OpenRouter
                'X-Title: Ayuni Social System'  // Optional but recommended
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Ayuni-Beta-Social/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("OpenRouter CURL Error: " . $curlError);
            // Fall back to Anthropic on connection errors
            return callAnthropicAPI($messages, $systemPrompt, $maxTokens);
        }
        
        // Handle success
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            if (!$data || !isset($data['choices'][0]['message']['content'])) {
                error_log("Invalid OpenRouter response format, falling back to Anthropic");
                return callAnthropicAPI($messages, $systemPrompt, $maxTokens);
            }
            
            $responseText = $data['choices'][0]['message']['content'];
            
            // Log successful usage for monitoring
            if (isset($data['usage'])) {
                error_log("OpenRouter Social API - Tokens used: " . 
                    ($data['usage']['prompt_tokens'] ?? 0) . " prompt, " . 
                    ($data['usage']['completion_tokens'] ?? 0) . " completion");
            }
            
            return $responseText;
        }
        
        // Handle rate limits (429) with retry
        if ($httpCode === 429) {
            $retryCount++;
            
            if ($retryCount <= $maxRetries) {
                $delay = min($retryCount * 2, 10); // Progressive delay up to 10 seconds
                error_log("OpenRouter rate limited (429), retry $retryCount/$maxRetries in {$delay}s");
                sleep($delay);
                continue;
            } else {
                error_log("OpenRouter max retries exceeded, falling back to Anthropic");
                return callAnthropicAPI($messages, $systemPrompt, $maxTokens);
            }
        }
        
        // Handle other errors
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : "Unknown OpenRouter error (HTTP $httpCode)";
        error_log("OpenRouter API Error: " . $errorMessage);
        
        // Fall back to Anthropic for non-retryable errors
        return callAnthropicAPI($messages, $systemPrompt, $maxTokens);
    }
    
    // Should not reach here, but fallback to Anthropic just in case
    error_log("Unexpected end of OpenRouter retry loop, falling back to Anthropic");
    return callAnthropicAPI($messages, $systemPrompt, $maxTokens);
}

/**
 * Wrapper function specifically for social system calls
 * This ensures only social system uses OpenRouter
 */
function callSocialSystemAPI($messages, $systemPrompt, $maxTokens = 8000) {
    // Always use OpenRouter for social system if configured
    return callOpenRouterForSocial($messages, $systemPrompt, $maxTokens);
}

?>