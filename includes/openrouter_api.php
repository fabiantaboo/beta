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
 * No Anthropic fallbacks - fails gracefully if OpenRouter unavailable
 */
function callOpenRouterForSocial($messages, $systemPrompt, $maxTokens = 8000) {
    $apiKey = getOpenRouterApiKey();

    if (!$apiKey) {
        error_log("CRITICAL: OpenRouter API key not configured for social system");
        throw new Exception("OpenRouter API key not configured. Social system cannot function without it.");
    }

    // Enhance system prompt with strict JSON instructions
    $enhancedSystemPrompt = $systemPrompt . "\n\nCRITICAL: Your response MUST be valid JSON only. No text before or after the JSON object. No markdown formatting. Start with { and end with }.";

    // Format messages for OpenRouter with enhanced JSON instructions
    $formattedMessages = [];

    // Add enhanced system message first
    if (!empty($enhancedSystemPrompt)) {
        $formattedMessages[] = [
            'role' => 'system',
            'content' => $enhancedSystemPrompt
        ];
    }

    // Add conversation messages with JSON reminder
    foreach ($messages as $message) {
        $content = $message['content'];

        // Add JSON reminder to user messages if they expect JSON response
        if ($message['role'] === 'user' && (strpos($content, 'JSON') !== false || strpos($content, 'json') !== false)) {
            $content .= "\n\nReminder: Respond with ONLY valid JSON. No explanations, no markdown, just the JSON object.";
        }

        $formattedMessages[] = [
            'role' => $message['role'],
            'content' => $content
        ];
    }

    $payload = [
        'model' => 'google/gemini-2.0-flash-001',  // Using Gemini 2.0 Flash
        'messages' => $formattedMessages,
        'max_tokens' => $maxTokens,
        'temperature' => 0.7,  // Lower temperature for more consistent JSON
        'top_p' => 0.9,        // Slightly lower for better consistency
        'frequency_penalty' => 0,
        'presence_penalty' => 0,
        'stream' => false
    ];

    // Enhanced retry logic with response validation
    $maxNetworkRetries = 5;
    $maxResponseRetries = 3;
    $networkRetryCount = 0;

    while ($networkRetryCount <= $maxNetworkRetries) {
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
            CURLOPT_TIMEOUT => 60,  // Increased timeout
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'Ayuni-Beta-Social/1.0'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Handle CURL errors with retry
        if ($curlError) {
            $networkRetryCount++;
            if ($networkRetryCount <= $maxNetworkRetries) {
                $delay = min($networkRetryCount * 3, 15); // Progressive delay up to 15 seconds
                error_log("OpenRouter CURL Error (retry $networkRetryCount/$maxNetworkRetries): $curlError - retrying in {$delay}s");
                sleep($delay);
                continue;
            } else {
                error_log("OpenRouter CURL failed after $maxNetworkRetries retries: $curlError");
                throw new Exception("OpenRouter connection failed after multiple retries: $curlError");
            }
        }

        // Handle rate limits (429) with retry
        if ($httpCode === 429) {
            $networkRetryCount++;
            if ($networkRetryCount <= $maxNetworkRetries) {
                $delay = min($networkRetryCount * 5, 30); // Progressive delay up to 30 seconds for rate limits
                error_log("OpenRouter rate limited (429), retry $networkRetryCount/$maxNetworkRetries in {$delay}s");
                sleep($delay);
                continue;
            } else {
                error_log("OpenRouter rate limit exceeded after $maxNetworkRetries retries");
                throw new Exception("OpenRouter rate limit exceeded after multiple retries");
            }
        }

        // Handle success
        if ($httpCode === 200) {
            $data = json_decode($response, true);

            if (!$data || !isset($data['choices'][0]['message']['content'])) {
                error_log("Invalid OpenRouter response structure: " . substr($response, 0, 500));
                throw new Exception("Invalid response structure from OpenRouter");
            }

            $responseText = trim($data['choices'][0]['message']['content']);

            // Enhanced JSON validation with retry mechanism
            $cleanedResponse = cleanAndValidateJSONResponse($responseText);

            if ($cleanedResponse !== null) {
                // Log successful usage for monitoring
                if (isset($data['usage'])) {
                    error_log("OpenRouter Social API Success - Tokens used: " .
                        ($data['usage']['prompt_tokens'] ?? 0) . " prompt, " .
                        ($data['usage']['completion_tokens'] ?? 0) . " completion");
                }

                return $cleanedResponse;
            } else {
                // Response validation failed - retry with more explicit instructions
                if ($networkRetryCount < $maxResponseRetries) {
                    error_log("Invalid JSON response from OpenRouter (attempt " . ($networkRetryCount + 1) . "/$maxResponseRetries), retrying with enhanced instructions");

                    // Add even more explicit JSON instructions for retry
                    $payload['messages'][count($payload['messages']) - 1]['content'] .= "\n\nIMPORTANT: Your previous response was not valid JSON. Please respond with ONLY a valid JSON object that starts with { and ends with }. No other text whatsoever.";

                    $networkRetryCount++;
                    continue;
                } else {
                    error_log("Failed to get valid JSON after $maxResponseRetries attempts. Last response: " . substr($responseText, 0, 500));
                    throw new Exception("OpenRouter returned invalid JSON after multiple attempts");
                }
            }
        }

        // Handle other HTTP errors
        $errorData = json_decode($response, true);
        $errorMessage = isset($errorData['error']['message']) ? $errorData['error']['message'] : "HTTP $httpCode";

        // Some errors are retryable, others are not
        if (in_array($httpCode, [500, 502, 503, 504])) {
            // Server errors - retry
            $networkRetryCount++;
            if ($networkRetryCount <= $maxNetworkRetries) {
                $delay = min($networkRetryCount * 2, 10);
                error_log("OpenRouter server error ($httpCode), retry $networkRetryCount/$maxNetworkRetries in {$delay}s: $errorMessage");
                sleep($delay);
                continue;
            }
        }

        // Non-retryable error or max retries exceeded
        error_log("OpenRouter API Error: $errorMessage (HTTP $httpCode)");
        throw new Exception("OpenRouter API error: $errorMessage");
    }

    // Should not reach here
    throw new Exception("OpenRouter request failed unexpectedly");
}

/**
 * Clean and validate JSON response from OpenRouter
 * Attempts to extract valid JSON from potentially malformed responses
 */
function cleanAndValidateJSONResponse($response) {
    // Remove common problematic characters and formatting
    $cleaned = trim($response);

    // Remove markdown JSON blocks if present
    $cleaned = preg_replace('/^```(?:json)?\s*/', '', $cleaned);
    $cleaned = preg_replace('/\s*```$/', '', $cleaned);

    // Remove any text before the first { and after the last }
    if (preg_match('/\{.*\}/s', $cleaned, $matches)) {
        $cleaned = $matches[0];
    }

    // Attempt to decode
    $decoded = json_decode($cleaned, true);

    if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
        return $cleaned; // Return the cleaned JSON string
    }

    // Try common fixes for malformed JSON
    $fixes = [
        // Fix unescaped quotes in strings
        function($str) {
            return preg_replace('/(?<!\\\\)"(?=(?:[^"\\\\]|\\\\.)*"[^"]*$)/', '\\"', $str);
        },
        // Fix trailing commas
        function($str) {
            return preg_replace('/,(\s*[}\]])/', '$1', $str);
        },
        // Fix missing quotes around keys
        function($str) {
            return preg_replace('/(\w+)(\s*:\s*)/', '"$1"$2', $str);
        }
    ];

    foreach ($fixes as $fix) {
        $fixed = $fix($cleaned);
        $decoded = json_decode($fixed, true);
        if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
            error_log("JSON fixed with automatic correction");
            return $fixed;
        }
    }

    error_log("JSON validation failed: " . json_last_error_msg() . " | Response: " . substr($cleaned, 0, 200));
    return null;
}

/**
 * Wrapper function specifically for social system calls
 * This ensures only social system uses OpenRouter with proper error handling
 */
function callSocialSystemAPI($messages, $systemPrompt, $maxTokens = 8000) {
    try {
        return callOpenRouterForSocial($messages, $systemPrompt, $maxTokens);
    } catch (Exception $e) {
        error_log("Social System API Call Failed: " . $e->getMessage());

        // Return null instead of falling back to Anthropic
        // Calling code should handle null responses gracefully
        return null;
    }
}

?>