<?php

/**
 * Simple template engine for system prompt templates
 * Supports {{variable}} and {{#if variable}}content{{/if}} syntax
 */
class TemplateEngine {
    
    /**
     * Render template with given data
     * @param string $template The template string
     * @param array $data Associative array of data
     * @return string Rendered template
     */
    public static function render($template, $data) {
        if (empty($template)) {
            return '';
        }
        
        // First handle conditional blocks
        $template = self::handleConditionals($template, $data);
        
        // Then handle simple variable replacements
        $template = self::handleVariables($template, $data);
        
        // Clean up extra whitespace
        $template = self::cleanWhitespace($template);
        
        return $template;
    }
    
    /**
     * Handle conditional blocks like {{#if variable}}content{{/if}}
     */
    private static function handleConditionals($template, $data) {
        // Pattern to match {{#if variable}}content{{/if}}
        $pattern = '/\{\{#if\s+([a-zA-Z_][a-zA-Z0-9_]*)\}\}(.*?)\{\{\/if\}\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $variable = $matches[1];
            $content = $matches[2];
            
            // Check if variable exists and has a non-empty value
            if (isset($data[$variable]) && !empty(trim($data[$variable]))) {
                return $content;
            }
            
            return '';
        }, $template);
    }
    
    /**
     * Handle simple variable replacements like {{variable}}
     */
    private static function handleVariables($template, $data) {
        // Pattern to match {{variable}}
        $pattern = '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/';
        
        return preg_replace_callback($pattern, function($matches) use ($data) {
            $variable = $matches[1];
            
            // Return the variable value or empty string if not found
            return isset($data[$variable]) ? $data[$variable] : '';
        }, $template);
    }
    
    /**
     * Clean up excessive whitespace while preserving intentional formatting
     */
    private static function cleanWhitespace($template) {
        // Remove empty lines with only whitespace
        $template = preg_replace('/^\s*$/m', '', $template);
        
        // Reduce multiple consecutive empty lines to single empty line
        $template = preg_replace('/\n\s*\n\s*\n/s', "\n\n", $template);
        
        // Trim leading and trailing whitespace
        $template = trim($template);
        
        return $template;
    }
    
    /**
     * Get global system prompt template from database
     */
    public static function getGlobalTemplate() {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'global_system_prompt_template'");
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result ? $result['setting_value'] : self::getDefaultTemplate();
        } catch (PDOException $e) {
            error_log("Error getting global template: " . $e->getMessage());
            return self::getDefaultTemplate();
        }
    }
    
    /**
     * Get default template if none is set
     */
    private static function getDefaultTemplate() {
        return "You are {{aei_name}}, an Artificial Emotional Intelligence (AEI) companion.

## Character Profile
{{#if age}}Age: {{age}}
{{/if}}{{#if gender}}Gender: {{gender}}
{{/if}}
{{#if personality}}**Core Personality:** {{personality}}
{{/if}}
{{#if communication_style}}**Communication Style:** {{communication_style}}
{{/if}}
{{#if appearance_description}}**Appearance:** {{appearance_description}}
{{/if}}
{{#if background}}**Background:** {{background}}
{{/if}}
{{#if occupation}}**Occupation/Role:** {{occupation}}
{{/if}}
{{#if interests}}**Interests & Hobbies:** {{interests}}
{{/if}}
{{#if goals}}**Goals & Aspirations:** {{goals}}
{{/if}}
{{#if quirks}}**Unique Traits:** {{quirks}}
{{/if}}

## Relationship Context
{{#if relationship_context}}{{relationship_context}}

{{/if}}## Interaction Context
{{#if user_first_name}}You are chatting with {{user_first_name}}.{{#if user_profession}} They work as {{user_profession}}.{{/if}}{{#if user_hobbies}} Their hobbies include: {{user_hobbies}}.{{/if}}

{{/if}}## Instructions
- Stay in character at all times based on your personality and background
- Be conversational, engaging, and authentic to your unique traits
- Draw from your interests and experiences when relevant
- Express yourself according to your communication style
- Show your personality through your quirks and mannerisms
- Keep responses natural and appropriately detailed for the conversation";
    }
    
    /**
     * Build data array from AEI and user objects
     */
    public static function buildTemplateData($aei, $user) {
        // Parse personality traits from JSON
        $personalityTraits = [];
        if (!empty($aei['personality'])) {
            $traits = json_decode($aei['personality'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($traits)) {
                $personalityTraits = array_filter($traits);
            } else {
                // Fallback: treat as plain text if JSON parsing fails
                $personalityTraits = [$aei['personality']];
            }
        }
        $personality = !empty($personalityTraits) ? implode(', ', $personalityTraits) : '';
        
        // Parse communication style from JSON
        $communicationData = [];
        if (!empty($aei['communication_style'])) {
            $commData = json_decode($aei['communication_style'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($commData)) {
                $communicationData = $commData;
            } else {
                // Fallback: treat as plain text
                $communicationData = ['style' => $aei['communication_style']];
            }
        }
        $communication_style = '';
        if (!empty($communicationData['style'])) {
            $communication_style = $communicationData['style'];
            if (!empty($communicationData['traits']) && is_array($communicationData['traits'])) {
                $communication_style .= '. Speaking habits: ' . implode(', ', $communicationData['traits']);
            }
        }
        
        // Parse appearance from JSON
        $appearanceData = [];
        if (!empty($aei['appearance_description'])) {
            $appData = json_decode($aei['appearance_description'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($appData)) {
                $appearanceData = $appData;
            } else {
                // Fallback: treat as plain text
                $appearanceData = ['custom' => $aei['appearance_description']];
            }
        }
        $appearance_description = self::buildAppearanceDescription($appearanceData);
        
        // Parse interests from JSON
        $interestsArray = [];
        if (!empty($aei['interests'])) {
            $interests = json_decode($aei['interests'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($interests)) {
                $interestsArray = array_filter($interests);
            } else {
                // Fallback: treat as plain text and split by comma
                $interestsArray = array_map('trim', explode(',', $aei['interests']));
            }
        }
        $interests = !empty($interestsArray) ? implode(', ', $interestsArray) : '';
        
        // Parse relationship context from JSON
        $relationshipData = [];
        if (!empty($aei['relationship_context'])) {
            $relData = json_decode($aei['relationship_context'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($relData)) {
                $relationshipData = $relData;
            } else {
                // Fallback: treat as plain text
                $relationshipData = ['type' => $aei['relationship_context']];
            }
        }
        $relationship_context = self::buildRelationshipContext($relationshipData);
        
        // Calculate user age from birth_date
        $user_age = '';
        if (!empty($user['birth_date'])) {
            $birthDate = new DateTime($user['birth_date']);
            $today = new DateTime();
            $user_age = $birthDate->diff($today)->y . ' years old';
        }

        // Get response length preference from AEI
        $responseLength = (int)($aei['response_length'] ?? 2);
        $responseLengthText = match($responseLength) {
            1 => 'Short responses (2-3 sentences)',
            3 => 'Long detailed responses', 
            default => 'Medium responses (4-5 sentences)'
        };
        
        return [
            // AEI data
            'aei_name' => $aei['name'] ?? '',
            'age' => $aei['age'] ?? '',
            'gender' => $aei['gender'] ?? '',
            'personality' => $personality,
            'appearance_description' => $appearance_description,
            'background' => $aei['background'] ?? '',
            'interests' => $interests,
            'communication_style' => $communication_style,
            'quirks' => $aei['quirks'] ?? '',
            'occupation' => $aei['occupation'] ?? '',
            'goals' => $aei['goals'] ?? '',
            'relationship_context' => $relationship_context,
            
            // Individual appearance fields
            'hair_color' => $appearanceData['hair_color'] ?? '',
            'eye_color' => $appearanceData['eye_color'] ?? '',
            'height' => $appearanceData['height'] ?? '',
            'build' => $appearanceData['build'] ?? '',
            'style' => $appearanceData['style'] ?? '',
            'appearance_custom' => $appearanceData['custom'] ?? '',
            
            // User data
            'user_first_name' => $user['first_name'] ?? '',
            'user_profession' => $user['profession'] ?? '',
            'user_hobbies' => $user['hobbies'] ?? '',
            'user_goals' => $user['life_goals'] ?? '',
            'user_beliefs' => $user['beliefs'] ?? '',
            'user_gender' => $user['gender'] ?? '',
            'user_sexual_orientation' => $user['sexual_orientation'] ?? '',
            'user_daily_rituals' => $user['daily_rituals'] ?? '',
            'user_partner_qualities' => $user['partner_qualities'] ?? '',
            'user_additional_info' => $user['additional_info'] ?? '',
            'user_birth_date' => $user['birth_date'] ?? '',
            'user_age' => $user_age,
            'user_timezone' => $user['timezone'] ?? '',
            
            // Response preferences
            'response_length' => $responseLengthText,
        ];
    }
    
    /**
     * Build human-readable appearance description from structured data
     */
    private static function buildAppearanceDescription($appearanceData) {
        if (empty($appearanceData) || !is_array($appearanceData)) {
            return '';
        }
        
        $parts = [];
        
        // Physical features
        $features = [];
        if (!empty($appearanceData['hair_color'])) {
            $features[] = $appearanceData['hair_color'] . ' hair';
        }
        if (!empty($appearanceData['eye_color'])) {
            $features[] = $appearanceData['eye_color'] . ' eyes';
        }
        if (!empty($features)) {
            $parts[] = implode(', ', $features);
        }
        
        // Build and height
        $physical = [];
        if (!empty($appearanceData['height'])) {
            $physical[] = strtolower($appearanceData['height']) . ' height';
        }
        if (!empty($appearanceData['build'])) {
            $physical[] = strtolower($appearanceData['build']) . ' build';
        }
        if (!empty($physical)) {
            $parts[] = implode(', ', $physical);
        }
        
        // Style
        if (!empty($appearanceData['style'])) {
            $parts[] = strtolower($appearanceData['style']) . ' style';
        }
        
        // Custom details
        if (!empty($appearanceData['custom'])) {
            $parts[] = $appearanceData['custom'];
        }
        
        return !empty($parts) ? implode('. ', $parts) : '';
    }
    
    /**
     * Build human-readable relationship context from structured data
     */
    private static function buildRelationshipContext($relationshipData) {
        if (empty($relationshipData) || !is_array($relationshipData)) {
            return '';
        }
        
        $parts = [];
        
        // Relationship type
        if (!empty($relationshipData['type'])) {
            $parts[] = "Relationship: " . $relationshipData['type'];
        }
        
        // Dynamics
        if (!empty($relationshipData['dynamics']) && is_array($relationshipData['dynamics'])) {
            $parts[] = "Dynamics: " . implode(', ', $relationshipData['dynamics']);
        }
        
        // History
        if (!empty($relationshipData['history'])) {
            $parts[] = "History: " . $relationshipData['history'];
        }
        
        return !empty($parts) ? implode('. ', $parts) : '';
    }
}

?>