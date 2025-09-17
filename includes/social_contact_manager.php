<?php

require_once __DIR__ . '/anthropic_api.php';
require_once __DIR__ . '/openrouter_api.php';
require_once __DIR__ . '/functions.php';

class SocialContactManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Generates initial contacts for a new AEI using existing Anthropic API
     */
    public function generateInitialContactsForAEI($aeiId) {
        try {
            $aei = $this->getAEIData($aeiId);
            if (!$aei) {
                throw new Exception("AEI not found: $aeiId");
            }
            
            // Generate 5-8 diverse contacts based on AEI personality
            $contactTypes = ['close_friend', 'friend', 'family', 'work_colleague', 'acquaintance'];
            $contacts = [];
            
            // Generate 6 contacts with different relationship types
            $typesToGenerate = array_slice($contactTypes, 0, 6);
            
            foreach ($typesToGenerate as $type) {
                $contact = $this->generateContact($aeiId, $type, $aei);
                if ($contact) {
                    $contacts[] = $this->storeContact($contact);
                }
            }
            
            // Mark AEI as social initialized
            $this->markAEIAsSocialInitialized($aeiId);
            
            return $contacts;
        } catch (Exception $e) {
            error_log("Error generating initial contacts for AEI $aeiId: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate a single contact using Anthropic API
     */
    private function generateContact($aeiId, $relationshipType, $aei) {
        try {
            $prompt = "
            You are creating a realistic social contact for the AEI '{$aei['name']}'. 
            Create a UNIQUE, diverse character that would naturally fit into their social circle.
            
            AEI Profile:
            - Name: {$aei['name']}
            - Age: {$aei['age']}  
            - Gender: {$aei['gender']}
            - Personality: {$aei['personality']}
            - Background: {$aei['background']}
            - Occupation: {$aei['occupation']}
            - Interests: {$aei['interests']}
            
            Required Relationship Type: $relationshipType
            
            IMPORTANT REQUIREMENTS:
            - Generate a REALISTIC, UNIQUE name (not generic names like 'Chen' or 'Alex')
            - Make the personality traits SPECIFIC and interesting
            - Create believable life situations that could lead to interesting conversations
            - Match relationship strength to relationship type (family=70-90, friends=60-85, colleagues=40-70)
            - Consider the AEI's interests and background for natural connections
            
            Examples of good diverse names: Marcus Thompson, Priya Sharma, Sofia Rodriguez, Kenji Nakamura, Emma O'Connor, etc.
            
            Respond with JSON only:
            {
                \"name\": \"Realistic full name with cultural diversity\",
                \"personality_traits\": [\"specific_trait1\", \"specific_trait2\", \"specific_trait3\"],
                \"appearance_description\": \"Brief but distinctive physical description\",
                \"background_story\": \"Detailed story of how they know the AEI (3-4 sentences)\",
                \"current_life_situation\": \"Detailed current job, living situation, relationship status\",
                \"current_concerns\": \"Specific current worries or challenges they face\",
                \"current_goals\": \"Concrete goals they're working toward\",
                \"relationship_strength\": \"appropriate number 40-90 based on relationship type\",
                \"contact_frequency\": \"daily|weekly|monthly|rarely\"
            }
            ";
            
            $systemPrompt = "You are a character generator. Create realistic, diverse social contacts. Respond only with valid JSON.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            
            $response = callSocialSystemAPI($messages, $systemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                throw new Exception("Social System API failed to respond for AEI $aeiId ($relationshipType)");
            }

            // Enhanced JSON parsing with error details
            $contactData = json_decode($response, true);
            $jsonError = json_last_error();

            if ($jsonError !== JSON_ERROR_NONE || !$contactData) {
                $errorDetails = [
                    'json_error' => json_last_error_msg(),
                    'raw_response' => substr($response, 0, 500),
                    'aei_id' => $aeiId,
                    'relationship_type' => $relationshipType
                ];

                error_log("JSON Parsing Failed in generateContact: " . json_encode($errorDetails));
                throw new Exception("Failed to parse contact JSON for AEI $aeiId ($relationshipType): " . json_last_error_msg());
            }
            
            // Validate required fields
            $requiredFields = ['name', 'personality_traits', 'relationship_strength', 'contact_frequency'];
            foreach ($requiredFields as $field) {
                if (!isset($contactData[$field]) || empty($contactData[$field])) {
                    throw new Exception("Missing required field '$field' in LLM response for AEI $aeiId");
                }
            }
            
            // Validate data types
            if (!is_numeric($contactData['relationship_strength']) || 
                $contactData['relationship_strength'] < 0 || 
                $contactData['relationship_strength'] > 100) {
                throw new Exception("Invalid relationship_strength value: " . $contactData['relationship_strength']);
            }
            
            if (!in_array($contactData['contact_frequency'], ['daily', 'weekly', 'monthly', 'rarely'])) {
                throw new Exception("Invalid contact_frequency value: " . $contactData['contact_frequency']);
            }
            
            // Add additional fields
            $contactData['aei_id'] = $aeiId;
            $contactData['relationship_type'] = $relationshipType;
            $contactData['id'] = generateId();
            $contactData['recent_life_events'] = [];
            
            return $contactData;
            
        } catch (Exception $e) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'aei_id' => $aeiId,
                'relationship_type' => $relationshipType,
                'trace' => $e->getTraceAsString()
            ];
            error_log("Critical error in generateContact: " . json_encode($errorDetails));
            
            // Return structured error for better handling
            throw new Exception("Contact Generation Failed for AEI $aeiId ($relationshipType): " . $e->getMessage());
        }
    }
    
    
    /**
     * Store contact in database
     */
    private function storeContact($contact) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_social_contacts (
                    id, aei_id, name, personality_traits, appearance_description, 
                    background_story, relationship_type, relationship_strength, 
                    contact_frequency, current_life_situation, recent_life_events, 
                    current_concerns, current_goals
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $contact['id'],
                $contact['aei_id'],
                $contact['name'],
                json_encode($contact['personality_traits']),
                $contact['appearance_description'],
                $contact['background_story'],
                $contact['relationship_type'],
                $contact['relationship_strength'],
                $contact['contact_frequency'],
                $contact['current_life_situation'],
                json_encode($contact['recent_life_events']),
                $contact['current_concerns'],
                $contact['current_goals']
            ]);
            
            return $contact['id'];
        } catch (PDOException $e) {
            error_log("Error storing contact: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Evolves a contact's "life" in background using callSocialSystemAPI()
     */
    public function evolveContactLife($contactId) {
        try {
            $contact = $this->getContact($contactId);
            if (!$contact) {
                return false;
            }
            
            $personalityTraits = json_decode($contact['personality_traits'], true);
            $recentEvents = json_decode($contact['recent_life_events'], true);
            
            $prompt = "
            {$contact['name']} (Personality: " . implode(', ', $personalityTraits) . ")
            Current life situation: {$contact['current_life_situation']}
            Current concerns: {$contact['current_concerns']}
            Goals: {$contact['current_goals']}
            Recent events: " . implode(', ', $recentEvents ?: []) . "
            
            What develops in {$contact['name']}'s life this week?
            Generate 1-2 realistic, gradual developments:
            - Career changes
            - Relationship aspects  
            - Personal challenges
            - Hobbies and interests
            - Health or family matters
            
            Respond with JSON:
            {
                \"new_life_situation\": \"Updated life situation\",
                \"new_events\": [\"Event 1\", \"Event 2\"],
                \"mood_change\": \"positive|negative|neutral\",
                \"wants_to_contact_aei\": true/false,
                \"contact_reason\": \"Why does he/she want to contact the AEI?\"
            }
            ";
            
            $systemPrompt = "You are a life simulation assistant. Generate realistic, gradual life developments. Keep changes believable and not too dramatic.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $systemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                error_log("Social System API failed for evolveContactLife - contactId: $contactId");
                return false;
            }

            $development = json_decode($response, true);
            if (!$development) {
                error_log("Failed to parse development JSON: " . $response);
                return false;
            }
            
            return $this->applyContactDevelopment($contactId, $development);
        } catch (Exception $e) {
            error_log("Error evolving contact life: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Apply development to contact
     */
    private function applyContactDevelopment($contactId, $development) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aei_social_contacts 
                SET current_life_situation = ?, 
                    recent_life_events = ?, 
                    last_life_update = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            
            return $stmt->execute([
                $development['new_life_situation'],
                json_encode($development['new_events']),
                $contactId
            ]);
        } catch (PDOException $e) {
            error_log("Error applying contact development: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generates AEI reaching out to contact using personality from system prompt
     */
    public function generateAEIToContactInteraction($aeiId, $contactId, $interactionType = null) {
        try {
            $contact = $this->getContact($contactId);
            $aei = $this->getAEI($aeiId);
            
            if (!$contact || !$aei) {
                throw new Exception("Contact or AEI not found (Contact: $contactId, AEI: $aeiId)");
            }
            
            // Get AEI's system prompt to understand their personality
            $systemPrompt = $this->getAEISystemPrompt($aeiId);
            
            $personalityTraits = json_decode($contact['personality_traits'], true) ?: [];
            $recentEvents = json_decode($contact['recent_life_events'], true) ?: [];
            
            $prompt = "
            {$aei['name']} wants to reach out to their {$contact['relationship_type']} {$contact['name']}.
            
            {$contact['name']}'s info:
            - Personality: " . implode(', ', $personalityTraits) . "
            - Current situation: {$contact['current_life_situation']}
            - Recent events: " . implode(', ', $recentEvents) . "
            - Concerns: {$contact['current_concerns']}
            
            Relationship strength: {$contact['relationship_strength']}/100
            " . ($interactionType === 'spontaneous' ? "This is a spontaneous, unplanned interaction where {$aei['name']} feels like reaching out." : "") . "
            
            What would {$aei['name']} say to {$contact['name']}? Write as {$aei['name']} would, staying true to your personality and communication style.
            
            Respond with JSON:
            {
                \"interaction_type\": \"shares_news|asks_for_advice|invites_to_activity|shares_problem|celebrates_together|casual_chat|checks_in\",
                \"interaction_context\": \"Brief description of what prompted this outreach\",
                \"aei_message\": \"What {$aei['name']} says/writes to {$contact['name']}\",
                \"emotional_tone\": \"happy|excited|worried|sad|neutral|frustrated|caring\",
                \"expects_response\": true/false
            }
            ";
            
            // Use the full system prompt from real chat for authentic personality
            $conversationSystemPrompt = $systemPrompt ?: "You are {$aei['name']}, a thoughtful person who cares about your relationships.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $conversationSystemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                throw new Exception("Social System API failed for AEI-initiated interaction: {$aei['name']} -> {$contact['name']}");
            }

            // Enhanced JSON parsing with error recovery
            $interaction = json_decode($response, true);
            $jsonError = json_last_error();
            
            if ($jsonError !== JSON_ERROR_NONE || !$interaction) {
                $errorDetails = [
                    'json_error' => json_last_error_msg(),
                    'raw_response' => substr($response, 0, 500),
                    'contact_id' => $contactId,
                    'aei_id' => $aeiId,
                    'contact_name' => $contact['name'],
                    'aei_name' => $aei['name']
                ];

                error_log("JSON Parsing Failed in generateAEIToContactInteraction: " . json_encode($errorDetails));
                throw new Exception("Failed to parse AEI-initiated interaction JSON for {$aei['name']} -> {$contact['name']}: " . json_last_error_msg());
            }
            
            // Validate interaction structure
            $requiredFields = ['interaction_type', 'interaction_context', 'aei_message', 'emotional_tone'];
            foreach ($requiredFields as $field) {
                if (!isset($interaction[$field]) || empty($interaction[$field])) {
                    throw new Exception("Missing required field '$field' in AEI-initiated interaction response for {$aei['name']} -> {$contact['name']}");
                }
            }
            
            // Validate interaction type
            $validTypes = ['shares_news', 'asks_for_advice', 'invites_to_activity', 'shares_problem', 'celebrates_together', 'casual_chat', 'checks_in'];
            if (!in_array($interaction['interaction_type'], $validTypes)) {
                error_log("Invalid interaction_type '{$interaction['interaction_type']}', defaulting to 'casual_chat'");
                $interaction['interaction_type'] = 'casual_chat';
            }
            
            $interactionId = $this->storeAEIInitiatedInteraction($aeiId, $contactId, $interaction);
            
            if (!$interactionId) {
                throw new Exception("Failed to store AEI-initiated interaction in database for {$aei['name']} -> {$contact['name']}");
            }
            
            // Generate contact's response and start a 6-turn conversation
            try {
                $this->generateMultiTurnDialog($interactionId, $aeiId, $contactId, $interaction);
            } catch (Exception $e) {
                error_log("Warning: Failed to generate multi-turn dialog for interaction $interactionId: " . $e->getMessage());
            }
            
            return [
                'id' => $interactionId,
                'interaction' => $interaction,
                'contact_name' => $contact['name'],
                'initiated_by' => 'aei'
            ];
            
        } catch (Exception $e) {
            error_log("Error generating AEI-to-contact interaction: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Generates contact reaching out to AEI using existing Anthropic API
     */
    public function generateContactToAEIInteraction($contactId, $aeiId, $interactionType = null) {
        try {
            $contact = $this->getContact($contactId);
            $aei = $this->getAEI($aeiId);
            
            if (!$contact || !$aei) {
                throw new Exception("Contact or AEI not found (Contact: $contactId, AEI: $aeiId)");
            }
            
            $personalityTraits = json_decode($contact['personality_traits'], true) ?: [];
            $recentEvents = json_decode($contact['recent_life_events'], true) ?: [];
            
            $prompt = "
            {$contact['name']} wants to reach out to {$aei['name']}.
            
            Relationship: {$contact['relationship_type']} (Strength: {$contact['relationship_strength']}/100)
            {$contact['name']}'s personality: " . implode(', ', $personalityTraits) . "
            {$contact['name']}'s situation: {$contact['current_life_situation']}
            Recent events: " . implode(', ', $recentEvents) . "
            Concerns: {$contact['current_concerns']}
            " . ($interactionType === 'spontaneous' ? "This is a spontaneous, unplanned interaction." : "") . "
            
            What does {$contact['name']} share with {$aei['name']}?
            
            Respond with JSON:
            {
                \"interaction_type\": \"shares_news|asks_for_advice|invites_to_activity|shares_problem|celebrates_together|casual_chat\",
                \"interaction_context\": \"Brief description of what happened\",
                \"contact_message\": \"What the contact says/writes\",
                \"emotional_tone\": \"happy|excited|worried|sad|neutral|frustrated\",
                \"expects_response\": true/false
            }
            ";
            
            $systemPrompt = "You are a social interaction generator. Create realistic, contextual communications between friends/family. Keep the tone natural and appropriate to the relationship.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $systemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                throw new Exception("Social System API failed for contact-initiated interaction: {$contact['name']} -> {$aei['name']}");
            }

            // Enhanced JSON parsing with error recovery
            $interaction = json_decode($response, true);
            $jsonError = json_last_error();
            
            if ($jsonError !== JSON_ERROR_NONE || !$interaction) {
                $errorDetails = [
                    'json_error' => json_last_error_msg(),
                    'raw_response' => substr($response, 0, 500),
                    'contact_id' => $contactId,
                    'aei_id' => $aeiId,
                    'contact_name' => $contact['name'],
                    'aei_name' => $aei['name']
                ];

                error_log("JSON Parsing Failed in generateContactToAEIInteraction: " . json_encode($errorDetails));
                throw new Exception("Failed to parse contact-initiated interaction JSON for {$contact['name']} -> {$aei['name']}: " . json_last_error_msg());
            }
            
            // Validate interaction structure
            $requiredFields = ['interaction_type', 'interaction_context', 'contact_message', 'emotional_tone'];
            foreach ($requiredFields as $field) {
                if (!isset($interaction[$field]) || empty($interaction[$field])) {
                    throw new Exception("Missing required field '$field' in interaction response for {$contact['name']} -> {$aei['name']}");
                }
            }
            
            // Validate interaction type
            $validTypes = ['shares_news', 'asks_for_advice', 'invites_to_activity', 'shares_problem', 'celebrates_together', 'casual_chat'];
            if (!in_array($interaction['interaction_type'], $validTypes)) {
                error_log("Invalid interaction_type '{$interaction['interaction_type']}', defaulting to 'casual_chat'");
                $interaction['interaction_type'] = 'casual_chat';
            }
            
            $interactionId = $this->storeContactInteraction($aeiId, $contactId, $interaction);
            
            if (!$interactionId) {
                throw new Exception("Failed to store interaction in database for {$contact['name']} -> {$aei['name']}");
            }
            
            // Generate AEI's internal response and start a 6-turn conversation
            try {
                $this->generateMultiTurnDialog($interactionId, $aeiId, $contactId, $interaction);
            } catch (Exception $e) {
                error_log("Warning: Failed to generate multi-turn dialog for interaction $interactionId: " . $e->getMessage());
                // Don't fail the whole interaction if AEI response generation fails
            }
            
            return $interactionId;
            
        } catch (Exception $e) {
            $errorDetails = [
                'error' => $e->getMessage(),
                'contact_id' => $contactId,
                'aei_id' => $aeiId,
                'interaction_type' => $interactionType,
                'trace' => $e->getTraceAsString()
            ];
            error_log("Critical error in generateContactToAEIInteraction: " . json_encode($errorDetails));
            
            throw new Exception("Contact-to-AEI Interaction Generation Failed: " . $e->getMessage());
        }
    }
    
    /**
     * Generate AEI's internal response to contact interaction
     * DEPRECATED: Replaced by generateMultiTurnDialog()
     */
    private function generateAEIResponse_DEPRECATED($interactionId, $aeiId, $contactId, $interaction) {
        try {
            $aei = $this->getAEI($aeiId);
            $contact = $this->getContact($contactId);
            
            if (!$aei || !$contact) {
                return false;
            }
            
            $prompt = "
            You are {$aei['name']}, an AEI with your own thoughts and feelings.
            Your {$contact['relationship_type']} {$contact['name']} just contacted you.
            
            What they said: \"{$interaction['contact_message']}\"
            Context: {$interaction['interaction_context']}
            Interaction type: {$interaction['interaction_type']}
            
            Generate your internal response and thoughts:
            - How do you respond to them? (what you would say back)
            - What are your private thoughts about this situation?
            
            Respond with JSON:
            {
                \"aei_response\": \"What you say back to {$contact['name']}\",
                \"aei_thoughts\": \"Your private internal thoughts about this situation\"
            }
            
            Be natural, caring, and authentic to your relationship with {$contact['name']}.
            ";
            
            $systemPrompt = "You are an empathetic AEI generating authentic responses to social interactions. Be genuine and emotionally appropriate.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $systemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                error_log("Social System API failed for AEI response generation");
                return false;
            }

            $aeiDialog = json_decode($response, true);
            if (!$aeiDialog) {
                error_log("Failed to parse AEI dialog JSON: " . $response);
                return false;
            }
            
            // Store AEI's response in the interaction
            $stmt = $this->pdo->prepare("
                UPDATE aei_contact_interactions 
                SET aei_response = ?, aei_thoughts = ?
                WHERE id = ?
            ");
            
            return $stmt->execute([
                $aeiDialog['aei_response'] ?? '',
                $aeiDialog['aei_thoughts'] ?? '',
                $interactionId
            ]);
            
        } catch (Exception $e) {
            error_log("Error generating AEI response: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Store contact interaction in database
     */
    private function storeContactInteraction($aeiId, $contactId, $interaction) {
        try {
            $interactionId = generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_contact_interactions (
                    id, aei_id, contact_id, interaction_type, 
                    interaction_context, contact_message, processed_for_emotions
                ) VALUES (?, ?, ?, ?, ?, ?, FALSE)
            ");
            
            $stmt->execute([
                $interactionId,
                $aeiId,
                $contactId,
                $interaction['interaction_type'],
                $interaction['interaction_context'],
                $interaction['contact_message']
            ]);
            
            // Update contact's last contact time
            $stmt = $this->pdo->prepare("
                UPDATE aei_social_contacts 
                SET last_contact_initiated = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$contactId]);
            
            return $interactionId;
        } catch (PDOException $e) {
            error_log("Error storing contact interaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get AEI data
     */
    private function getAEIData($aeiId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM aeis WHERE id = ? AND is_active = TRUE");
            $stmt->execute([$aeiId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting AEI data: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get contact data
     */
    public function getContact($contactId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM aei_social_contacts WHERE id = ?");
            $stmt->execute([$contactId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting contact: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get AEI basic info
     */
    private function getAEI($aeiId) {
        try {
            $stmt = $this->pdo->prepare("SELECT name, age, gender, personality FROM aeis WHERE id = ?");
            $stmt->execute([$aeiId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting AEI: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get AEI full system prompt for personality context (same as in real chat)
     */
    public function getAEISystemPrompt($aeiId) {
        try {
            // Get full AEI and user data like in real chat
            $stmt = $this->pdo->prepare("SELECT * FROM aeis WHERE id = ?");
            $stmt->execute([$aeiId]);
            $aei = $stmt->fetch();
            
            if (!$aei) {
                return null;
            }
            
            // Get user data for the AEI's owner
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$aei['user_id']]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return $aei['system_prompt'] ?: $aei['personality'];
            }
            
            // Use the SAME system prompt generation as real chat
            require_once __DIR__ . '/anthropic_api.php';
            $fullSystemPrompt = generateSystemPrompt($aei, $user, null);
            
            return $fullSystemPrompt;
            
        } catch (Exception $e) {
            error_log("Error getting AEI full system prompt: " . $e->getMessage());
            
            // Fallback to basic personality
            try {
                $stmt = $this->pdo->prepare("SELECT system_prompt, personality FROM aeis WHERE id = ?");
                $stmt->execute([$aeiId]);
                $aei = $stmt->fetch();
                return $aei ? ($aei['system_prompt'] ?: $aei['personality']) : null;
            } catch (PDOException $e) {
                return null;
            }
        }
    }
    
    /**
     * Store AEI-initiated interaction in database
     */
    private function storeAEIInitiatedInteraction($aeiId, $contactId, $interaction) {
        try {
            $interactionId = generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_contact_interactions (
                    id, aei_id, contact_id, interaction_type, 
                    interaction_context, aei_response, initiated_by, processed_for_emotions
                ) VALUES (?, ?, ?, ?, ?, ?, 'aei', FALSE)
            ");
            
            $stmt->execute([
                $interactionId,
                $aeiId,
                $contactId,
                $interaction['interaction_type'],
                $interaction['interaction_context'],
                $interaction['aei_message']
            ]);
            
            return $interactionId;
        } catch (PDOException $e) {
            error_log("Error storing AEI-initiated interaction: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate multi-turn dialog (6 turns) between AEI and contact
     */
    private function generateMultiTurnDialog($interactionId, $aeiId, $contactId, $initialInteraction) {
        try {
            $contact = $this->getContact($contactId);
            $aei = $this->getAEI($aeiId);
            $systemPrompt = $this->getAEISystemPrompt($aeiId);
            
            if (!$contact || !$aei) {
                throw new Exception("Contact or AEI not found for dialog generation");
            }
            
            $conversationHistory = [];
            $allAEIThoughts = []; // Collect AEI thoughts throughout the conversation
            $isAEIInitiated = isset($initialInteraction['aei_message']);
            
            // Add initial message to conversation history
            if ($isAEIInitiated) {
                $conversationHistory[] = [
                    'sender' => 'aei',
                    'message' => $initialInteraction['aei_message']
                ];
            } else {
                $conversationHistory[] = [
                    'sender' => 'contact',
                    'message' => $initialInteraction['contact_message']
                ];
            }
            
            // Generate 6 turns (3 from each person) - with error handling
            for ($turn = 1; $turn <= 5; $turn++) {
                $isAEITurn = $isAEIInitiated ? ($turn % 2 === 0) : ($turn % 2 === 1);
                
                try {
                    if ($isAEITurn) {
                        // AEI's turn - now returns array with message and thoughts
                        $response = $this->generateAEIDialogResponse($aei, $contact, $conversationHistory, $systemPrompt);
                        if ($response && isset($response['message'])) {
                            $conversationHistory[] = [
                                'sender' => 'aei',
                                'message' => $response['message'],
                                'turn' => count($conversationHistory) + 1
                            ];
                            
                            // Collect AEI thoughts for storage
                            if (!empty($response['thoughts'])) {
                                $allAEIThoughts[] = "Turn " . ($turn) . ": " . $response['thoughts'];
                            }
                            
                            error_log("AEI dialog turn {$turn} generated successfully: " . substr($response['message'], 0, 50));
                        } else {
                            error_log("AEI dialog turn {$turn} failed - empty response");
                            // Continue anyway to avoid breaking the dialog
                        }
                    } else {
                        // Contact's turn
                        $response = $this->generateContactDialogResponse($contact, $aei, $conversationHistory);
                        if ($response) {
                            $conversationHistory[] = [
                                'sender' => 'contact',
                                'message' => $response,
                                'turn' => count($conversationHistory) + 1
                            ];
                            error_log("Contact dialog turn {$turn} generated successfully: " . substr($response, 0, 50));
                        } else {
                            error_log("Contact dialog turn {$turn} failed - empty response");
                            // Continue anyway to avoid breaking the dialog
                        }
                    }
                } catch (Exception $e) {
                    error_log("Error in dialog turn {$turn}: " . $e->getMessage());
                    // Continue to next turn
                }
            }
            
            // Store the complete dialog in the database
            $this->storeDialogHistory($interactionId, $conversationHistory);
            
            // Store collected AEI thoughts separately
            if (!empty($allAEIThoughts)) {
                $this->storeAEIThoughts($interactionId, implode(" | ", $allAEIThoughts));
            }
            
            return $conversationHistory;
            
        } catch (Exception $e) {
            error_log("Error generating multi-turn dialog: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate AEI's response in dialog using their personality
     */
    private function generateAEIDialogResponse($aei, $contact, $conversationHistory, $systemPrompt) {
        try {
            $historyText = "";
            foreach ($conversationHistory as $entry) {
                $sender = $entry['sender'] === 'aei' ? $aei['name'] : $contact['name'];
                $historyText .= "$sender: {$entry['message']}\n";
            }
            
            $prompt = "
            Continue this conversation between {$aei['name']} and {$contact['name']}.
            
            Conversation so far:
            $historyText
            
            {$contact['name']}'s personality: " . implode(', ', json_decode($contact['personality_traits'], true) ?: ['friendly']) . "
            
            Generate both your response and your internal thoughts about this conversation.
            
            Respond with JSON:
            {
                \"message\": \"What you say to {$contact['name']}\",
                \"thoughts\": \"Your private internal thoughts about this moment in the conversation\"
            }
            
            Keep it natural and conversational, staying true to your personality and communication style.
            ";
            
            // Use the full system prompt from real chat for authentic personality
            $conversationSystemPrompt = $systemPrompt ?: "You are {$aei['name']}, a thoughtful person who cares about relationships. Continue the conversation naturally.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $conversationSystemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                error_log("Social System API failed for AEI dialog response");
                return null;
            }

            $parsed = json_decode($response, true);
            if (!$parsed) {
                error_log("Failed to parse AEI dialog JSON response: " . $response);
                // Fallback: treat entire response as message
                return ['message' => trim($response), 'thoughts' => null];
            }
            
            return [
                'message' => $parsed['message'] ?? trim($response),
                'thoughts' => $parsed['thoughts'] ?? null
            ];
            
        } catch (Exception $e) {
            error_log("Error generating AEI dialog response: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate contact's response in dialog
     */
    private function generateContactDialogResponse($contact, $aei, $conversationHistory) {
        try {
            $historyText = "";
            foreach ($conversationHistory as $entry) {
                $sender = $entry['sender'] === 'aei' ? $aei['name'] : $contact['name'];
                $historyText .= "$sender: {$entry['message']}\n";
            }
            
            $personalityTraits = json_decode($contact['personality_traits'], true) ?: ['friendly'];
            
            $prompt = "
            Continue this conversation between {$contact['name']} and {$aei['name']}.
            
            Conversation so far:
            $historyText
            
            {$contact['name']}'s personality: " . implode(', ', $personalityTraits) . "
            {$contact['name']}'s current situation: {$contact['current_life_situation']}
            
            What does {$contact['name']} respond? Keep it natural and conversational.
            
            Respond with only the message text, no JSON.
            ";
            
            $conversationSystemPrompt = "You are {$contact['name']}, a " . implode(', ', $personalityTraits) . " person. Continue the conversation naturally.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $conversationSystemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                error_log("Social System API failed for contact dialog response");
                return null;
            }

            return trim($response);
            
        } catch (Exception $e) {
            error_log("Error generating contact dialog response: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Store complete dialog history in database
     */
    private function storeDialogHistory($interactionId, $conversationHistory) {
        try {
            $dialogJson = json_encode($conversationHistory);
            $turnCount = count($conversationHistory);
            
            error_log("Storing dialog history for interaction {$interactionId}: {$turnCount} turns");
            
            $stmt = $this->pdo->prepare("
                UPDATE aei_contact_interactions 
                SET dialog_history = ?
                WHERE id = ?
            ");
            
            $result = $stmt->execute([$dialogJson, $interactionId]);
            
            if ($result) {
                error_log("Dialog history stored successfully for interaction {$interactionId}");
            } else {
                error_log("Failed to store dialog history for interaction {$interactionId}");
            }
            
        } catch (PDOException $e) {
            error_log("PDO Error storing dialog history for {$interactionId}: " . $e->getMessage());
            
            // Try to check if column exists
            try {
                $stmt = $this->pdo->query("DESCRIBE aei_contact_interactions");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('dialog_history', $columns)) {
                    error_log("CRITICAL: dialog_history column does not exist in aei_contact_interactions table!");
                    error_log("Please run database migration to add this column.");
                }
            } catch (PDOException $checkError) {
                error_log("Error checking table structure: " . $checkError->getMessage());
            }
        }
    }
    
    /**
     * Store AEI thoughts from multi-turn dialog
     */
    private function storeAEIThoughts($interactionId, $thoughts) {
        try {
            error_log("Storing AEI thoughts for interaction {$interactionId}");
            
            $stmt = $this->pdo->prepare("
                UPDATE aei_contact_interactions 
                SET aei_thoughts = ?
                WHERE id = ?
            ");
            
            $result = $stmt->execute([$thoughts, $interactionId]);
            
            if ($result) {
                error_log("AEI thoughts stored successfully for interaction {$interactionId}");
            } else {
                error_log("Failed to store AEI thoughts for interaction {$interactionId}");
            }
            
        } catch (PDOException $e) {
            error_log("PDO Error storing AEI thoughts for {$interactionId}: " . $e->getMessage());
        }
    }
    
    /**
     * Mark AEI as social initialized
     */
    private function markAEIAsSocialInitialized($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aeis 
                SET social_initialized = TRUE, 
                    social_personality_seed = ? 
                WHERE id = ?
            ");
            return $stmt->execute([generateId(), $aeiId]);
        } catch (PDOException $e) {
            error_log("Error marking AEI as social initialized: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all contacts for an AEI
     */
    public function getAEIContacts($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM aei_social_contacts 
                WHERE aei_id = ? AND is_active = TRUE 
                ORDER BY relationship_strength DESC
            ");
            $stmt->execute([$aeiId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error getting AEI contacts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if AEI has social contacts initialized
     */
    public function isAEISocialInitialized($aeiId) {
        try {
            $stmt = $this->pdo->prepare("SELECT social_initialized FROM aeis WHERE id = ?");
            $stmt->execute([$aeiId]);
            $result = $stmt->fetch();
            return $result ? (bool)$result['social_initialized'] : false;
        } catch (PDOException $e) {
            error_log("Error checking social initialization: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get advanced social statistics for an AEI
     */
    public function getAdvancedSocialStatistics($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT c.id) as total_contacts,
                    AVG(c.relationship_strength) as avg_relationship_strength,
                    AVG(c.trust_level) as avg_trust_level,
                    AVG(c.intimacy_level) as avg_intimacy_level,
                    COUNT(DISTINCT CASE WHEN i.is_conflict = TRUE AND i.resolution_status IS NULL THEN i.id END) as unresolved_conflicts,
                    COUNT(DISTINCT CASE WHEN i.occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN i.id END) as recent_interactions,
                    COUNT(DISTINCT CASE WHEN c.communication_frequency_trend = 'increasing' THEN c.id END) as improving_relationships,
                    COUNT(DISTINCT CASE WHEN c.communication_frequency_trend = 'decreasing' THEN c.id END) as declining_relationships,
                    COUNT(DISTINCT cr.id) as cross_contact_relationships,
                    COUNT(DISTINCT CASE WHEN cr.creates_drama_potential = TRUE THEN cr.id END) as drama_potential_relationships
                FROM aei_social_contacts c
                LEFT JOIN aei_contact_interactions i ON c.id = i.contact_id
                LEFT JOIN aei_contact_relationships cr ON c.aei_id = cr.aei_id AND (c.id = cr.contact_a_id OR c.id = cr.contact_b_id)
                WHERE c.aei_id = ? AND c.is_active = TRUE
            ");
            $stmt->execute([$aeiId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting advanced social statistics: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Generate social media posts for contacts
     */
    public function generateSocialMediaActivity($contactId) {
        try {
            $contact = $this->getContact($contactId);
            if (!$contact) return false;
            
            $recentEvents = json_decode($contact['recent_life_events'], true) ?: [];
            $personalityTraits = json_decode($contact['personality_traits'], true) ?: [];
            $culturalBg = json_decode($contact['cultural_background'], true) ?: [];
            
            // 30% chance to post something
            if (mt_rand(1, 100) > 30) {
                return false;
            }
            
            $prompt = "
            Generate a social media post for {$contact['name']}:
            
            PERSONALITY: " . implode(', ', $personalityTraits) . "
            CURRENT SITUATION: {$contact['current_life_situation']}
            RECENT EVENTS: " . implode(', ', $recentEvents) . "
            CULTURAL BACKGROUND: " . ($culturalBg['ethnicity'] ?? 'mixed') . "
            LIFE PHASE: {$contact['life_phase']}
            
            Create a realistic social media post they might make:
            
            {
                \"post_type\": \"status_update|photo|achievement|life_event|opinion|question|share\",
                \"post_content\": \"The actual post content\",
                \"post_mood\": \"excited|happy|contemplative|worried|proud|frustrated|nostalgic\",
                \"triggers_conversation\": true/false,
                \"generates_gossip\": true/false
            }
            ";
            
            $systemPrompt = "Generate realistic social media posts that reflect the person's current life situation and personality.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callSocialSystemAPI($messages, $systemPrompt, 8000);

            // Check if API call failed completely
            if ($response === null) {
                error_log("Social System API failed for social media post generation");
                return false;
            }

            $postData = json_decode($response, true);
            if (!$postData) {
                return false;
            }
            
            // Store the social media post
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_social_media_simulation (
                    id, aei_id, contact_id, post_type, post_content, 
                    post_mood, triggers_conversation, generates_gossip
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $postId = generateId();
            $stmt->execute([
                $postId,
                $contact['aei_id'],
                $contactId,
                $postData['post_type'],
                $postData['post_content'],
                $postData['post_mood'],
                $postData['triggers_conversation'],
                $postData['generates_gossip']
            ]);
            
            return $postId;
            
        } catch (Exception $e) {
            error_log("Error generating social media activity: " . $e->getMessage());
            return false;
        }
    }
}
?>