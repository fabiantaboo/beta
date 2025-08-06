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
            Generate a realistic social contact for the AEI character '{$aei['name']}'. 
            
            AEI Profile:
            - Name: {$aei['name']}
            - Age: {$aei['age']}
            - Gender: {$aei['gender']}
            - Personality: {$aei['personality']}
            - Background: {$aei['background']}
            - Occupation: {$aei['occupation']}
            
            Relationship Type: $relationshipType
            
            Create a contact that would realistically know this AEI based on their background and personality.
            
            Respond with JSON only:
            {
                \"name\": \"Contact's full name\",
                \"personality_traits\": [\"trait1\", \"trait2\", \"trait3\"],
                \"appearance_description\": \"Brief physical description\",
                \"background_story\": \"How they know the AEI (2-3 sentences)\",
                \"current_life_situation\": \"Job, living situation, relationship status\",
                \"current_concerns\": \"What worries them currently\",
                \"current_goals\": \"What they want to achieve\",
                \"relationship_strength\": 50-90,
                \"contact_frequency\": \"daily|weekly|monthly|rarely\"
            }
            ";
            
            $systemPrompt = "You are a character generator. Create realistic, diverse social contacts. Respond only with valid JSON.";
            $messages = [['role' => 'user', 'content' => $prompt]];
            $response = callAnthropicAPI($messages, $systemPrompt, 1000);
            
            $contactData = json_decode($response, true);
            if (!$contactData) {
                error_log("Failed to parse contact JSON: " . $response);
                return null;
            }
            
            // Add additional fields
            $contactData['aei_id'] = $aeiId;
            $contactData['relationship_type'] = $relationshipType;
            $contactData['id'] = generateId();
            $contactData['recent_life_events'] = [];
            
            return $contactData;
        } catch (Exception $e) {
            error_log("Error generating contact: " . $e->getMessage());
            return null;
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
    public function generateContactToAEIInteraction($contactId, $aeiId) {
        try {
            $contact = $this->getContact($contactId);
            $aei = $this->getAEI($aeiId);
            
            if (!$contact || !$aei) {
                return false;
            }
            
            $personalityTraits = json_decode($contact['personality_traits'], true);
            $recentEvents = json_decode($contact['recent_life_events'], true);
            
            $prompt = "
            {$contact['name']} wants to reach out to {$aei['name']}.
            
            Relationship: {$contact['relationship_type']} (Strength: {$contact['relationship_strength']}/100)
            {$contact['name']}'s personality: " . implode(', ', $personalityTraits) . "
            {$contact['name']}'s situation: {$contact['current_life_situation']}
            Recent events: " . implode(', ', $recentEvents ?: []) . "
            Concerns: {$contact['current_concerns']}
            
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
            
            $interaction = json_decode($response, true);
            if (!$interaction) {
                error_log("Failed to parse interaction JSON: " . $response);
                return false;
            }
            
            return $this->storeContactInteraction($aeiId, $contactId, $interaction);
        } catch (Exception $e) {
            error_log("Error generating contact interaction: " . $e->getMessage());
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
}
?>