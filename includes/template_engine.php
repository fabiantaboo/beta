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

{{#if personality}}
Your personality: {{personality}}
{{/if}}

{{#if appearance_description}}
Your appearance: {{appearance_description}}
{{/if}}

{{#if user_first_name}}
You are chatting with {{user_first_name}}.
{{/if}}

Be conversational, helpful, and maintain your unique personality. Keep responses engaging but concise.";
    }
    
    /**
     * Build data array from AEI and user objects
     */
    public static function buildTemplateData($aei, $user) {
        return [
            // AEI data
            'aei_name' => $aei['name'] ?? '',
            'personality' => $aei['personality'] ?? '',
            'appearance_description' => $aei['appearance_description'] ?? '',
            
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
        ];
    }
}

?>