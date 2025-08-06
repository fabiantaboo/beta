<?php

require_once __DIR__ . '/template_engine.php';
require_once __DIR__ . '/emotions.php';
require_once __DIR__ . '/aei_social_context.php';

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
        
        // Process unprocessed social interactions BEFORE generating response
        if (isset($aei['social_initialized']) && $aei['social_initialized']) {
            $aeiSocialContext = new AEISocialContext($pdo);
            $socialEmotionalImpact = $aeiSocialContext->processUnprocessedSocialUpdates($aei['id']);
            
            // Apply social emotional impact to current state
            if (!empty($socialEmotionalImpact)) {
                $emotions->adjustEmotionalState($sessionId, $socialEmotionalImpact, 0.3);
                // Refresh current emotions after social impact
                $currentEmotions = $emotions->getEmotionalState($sessionId);
            }
        }
        
        // Generate system prompt with emotional and social context
        $baseSystemPrompt = generateSystemPrompt($aei, $user, $sessionId);
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