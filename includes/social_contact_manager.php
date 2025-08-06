<?php

require_once __DIR__ . '/anthropic_api.php';
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
            - Use diverse ethnic backgrounds and cultures for variety
            - Make the personality traits SPECIFIC and interesting
            - Create believable life situations that could lead to interesting conversations
            - Match relationship strength to relationship type (family=70-90, friends=60-85, colleagues=40-70)
            - Consider the AEI's interests and background for natural connections
            
            Examples of good diverse names: Marcus Thompson, Priya Sharma, Sofia Rodriguez, Kenji Nakamura, Fatima Al-Zahra, Emma O'Connor, etc.
            
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
            
            $response = callAnthropicAPI($messages, $systemPrompt, 1000);
            
            // Enhanced JSON parsing with error details
            $contactData = json_decode($response, true);
            $jsonError = json_last_error();
            
            if ($jsonError !== JSON_ERROR_NONE || !$contactData) {
                $errorDetails = [
                    'json_error' => json_last_error_msg(),
                    'raw_response' => $response,
                    'aei_id' => $aeiId,
                    'relationship_type' => $relationshipType
                ];
                
                error_log("LLM JSON Parsing Error in generateContact: " . json_encode($errorDetails));
                
                // Attempt to extract JSON from response if wrapped in text
                $cleanedResponse = $this->extractJSONFromResponse($response);
                if ($cleanedResponse) {
                    $contactData = json_decode($cleanedResponse, true);
                    if ($contactData) {
                        error_log("Successfully recovered JSON after cleaning for AEI $aeiId");
                    }
                }
                
                if (!$contactData) {
                    throw new Exception("LLM Response JSON Parsing Failed: " . json_last_error_msg() . " | Response: " . substr($response, 0, 200));
                }
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
     * Attempt to extract JSON from potentially wrapped response
     */
    private function extractJSONFromResponse($response) {
        // Try to find JSON within the response
        if (preg_match('/\{.*\}/s', $response, $matches)) {
            return $matches[0];
        }
        
        // Try to find JSON between ```json blocks
        if (preg_match('/```json\s*(\{.*\})\s*```/s', $response, $matches)) {
            return $matches[1];
        }
        
        // Try to find JSON between ``` blocks
        if (preg_match('/```\s*(\{.*\})\s*```/s', $response, $matches)) {
            return $matches[1];
        }
        
        return null;
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
     * Evolves a contact's "life" in background using existing callAnthropicAPI()
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
            $response = callAnthropicAPI($messages, $systemPrompt, 1000);
            
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
            $response = callAnthropicAPI($messages, $systemPrompt, 800);
            
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
                
                error_log("LLM JSON Parsing Error in generateContactToAEIInteraction: " . json_encode($errorDetails));
                
                // Try to recover JSON
                $cleanedResponse = $this->extractJSONFromResponse($response);
                if ($cleanedResponse) {
                    $interaction = json_decode($cleanedResponse, true);
                    if ($interaction) {
                        error_log("Successfully recovered interaction JSON for Contact {$contact['name']} -> AEI {$aei['name']}");
                    }
                }
                
                if (!$interaction) {
                    throw new Exception("LLM Response JSON Parsing Failed for interaction: " . json_last_error_msg() . " | Response: " . substr($response, 0, 200));
                }
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
            
            // Generate AEI's internal response to this interaction
            try {
                $this->generateAEIResponse($interactionId, $aeiId, $contactId, $interaction);
            } catch (Exception $e) {
                error_log("Warning: Failed to generate AEI response for interaction $interactionId: " . $e->getMessage());
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
     */
    private function generateAEIResponse($interactionId, $aeiId, $contactId, $interaction) {
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
            $response = callAnthropicAPI($messages, $systemPrompt, 800);
            
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
                    interaction_context, contact_message
                ) VALUES (?, ?, ?, ?, ?, ?)
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
            $stmt = $this->pdo->prepare("SELECT name, age, gender FROM aeis WHERE id = ?");
            $stmt->execute([$aeiId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting AEI: " . $e->getMessage());
            return false;
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
            $response = callAnthropicAPI($messages, $systemPrompt, 600);
            
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