<?php

class Emotions {
    
    const EMOTIONS = [
        'joy', 'sadness', 'fear', 'anger', 'surprise', 'disgust',
        'trust', 'anticipation', 'shame', 'love', 'contempt', 
        'loneliness', 'pride', 'envy', 'nostalgia', 'gratitude',
        'frustration', 'boredom'
    ];
    
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Get the current emotional state for a chat session
     */
    public function getEmotionalState($sessionId) {
        try {
            $emotionColumns = array_map(function($emotion) {
                return "aei_$emotion";
            }, self::EMOTIONS);
            
            $sql = "SELECT " . implode(', ', $emotionColumns) . " FROM chat_sessions WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$sessionId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                return $this->getDefaultEmotions();
            }
            
            $emotions = [];
            foreach (self::EMOTIONS as $emotion) {
                $emotions[$emotion] = (float) $result["aei_$emotion"] ?? 0.5;
            }
            
            return $emotions;
        } catch (PDOException $e) {
            error_log("Error getting emotional state: " . $e->getMessage());
            return $this->getDefaultEmotions();
        }
    }
    
    /**
     * Update the emotional state for a chat session
     */
    public function updateEmotionalState($sessionId, $emotions) {
        try {
            $setColumns = [];
            $params = [];
            
            foreach (self::EMOTIONS as $emotion) {
                if (isset($emotions[$emotion])) {
                    $setColumns[] = "aei_$emotion = ?";
                    $params[] = $this->normalizeEmotionValue($emotions[$emotion]);
                }
            }
            
            if (empty($setColumns)) {
                return false;
            }
            
            $params[] = $sessionId;
            $sql = "UPDATE chat_sessions SET " . implode(', ', $setColumns) . " WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error updating emotional state: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Adjust emotional state by a percentage factor
     */
    public function adjustEmotionalState($sessionId, $emotionChanges, $adjustmentFactor = 0.3) {
        $currentEmotions = $this->getEmotionalState($sessionId);
        $adjustedEmotions = [];
        
        foreach (self::EMOTIONS as $emotion) {
            $currentValue = $currentEmotions[$emotion];
            $changeValue = $emotionChanges[$emotion] ?? 0;
            
            // Apply adjustment factor
            $adjustment = ($changeValue - $currentValue) * $adjustmentFactor;
            $newValue = $currentValue + $adjustment;
            
            $adjustedEmotions[$emotion] = $this->normalizeEmotionValue($newValue);
        }
        
        return $this->updateEmotionalState($sessionId, $adjustedEmotions);
    }
    
    /**
     * Store emotion data for a specific message
     */
    public function storeMessageEmotions($messageId, $emotions) {
        try {
            $setColumns = [];
            $params = [];
            
            foreach (self::EMOTIONS as $emotion) {
                if (isset($emotions[$emotion])) {
                    $setColumns[] = "aei_$emotion = ?";
                    $params[] = $this->normalizeEmotionValue($emotions[$emotion]);
                }
            }
            
            if (empty($setColumns)) {
                return false;
            }
            
            $params[] = $messageId;
            $sql = "UPDATE chat_messages SET " . implode(', ', $setColumns) . " WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (PDOException $e) {
            error_log("Error storing message emotions: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get emotions for a specific message
     */
    public function getMessageEmotions($messageId) {
        try {
            $emotionColumns = array_map(function($emotion) {
                return "aei_$emotion";
            }, self::EMOTIONS);
            
            $sql = "SELECT " . implode(', ', $emotionColumns) . " FROM chat_messages WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$messageId]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$result) {
                return null;
            }
            
            $emotions = [];
            foreach (self::EMOTIONS as $emotion) {
                $value = $result["aei_$emotion"];
                $emotions[$emotion] = $value !== null ? (float) $value : null;
            }
            
            return $emotions;
        } catch (PDOException $e) {
            error_log("Error getting message emotions: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Initialize emotional state for a new chat session
     */
    public function initializeSessionEmotions($sessionId) {
        $randomEmotions = [];
        
        foreach (self::EMOTIONS as $emotion) {
            // Generate random initial values between 0.3 and 0.7 (avoiding extremes)
            $randomEmotions[$emotion] = round(mt_rand(30, 70) / 100, 1);
        }
        
        return $this->updateEmotionalState($sessionId, $randomEmotions);
    }
    
    /**
     * Get conversation history for emotion analysis
     */
    public function getConversationHistory($sessionId, $limit = 10) {
        try {
            $sql = "SELECT sender_type, message_text, created_at 
                    FROM chat_messages 
                    WHERE session_id = ? 
                    ORDER BY created_at DESC 
                    LIMIT ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$sessionId, $limit]);
            
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_reverse($messages); // Return in chronological order
        } catch (PDOException $e) {
            error_log("Error getting conversation history: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Format emotions for display purposes
     */
    public function formatEmotionsForDisplay($emotions) {
        $formatted = [
            'strong' => [], // 0.7+
            'moderate' => [], // 0.4-0.6
            'mild' => [] // 0.1-0.3
        ];
        
        foreach ($emotions as $emotion => $value) {
            if ($value >= 0.7) {
                $formatted['strong'][] = "$emotion: $value";
            } elseif ($value >= 0.4) {
                $formatted['moderate'][] = "$emotion: $value";
            } elseif ($value >= 0.1) {
                $formatted['mild'][] = "$emotion: $value";
            }
        }
        
        return $formatted;
    }
    
    /**
     * Generate emotion context for AI prompts
     */
    public function generateEmotionContext($emotions) {
        $formatted = $this->formatEmotionsForDisplay($emotions);
        $context = "Your current emotional state:\n";
        
        if (!empty($formatted['strong'])) {
            $context .= "Strong emotions: " . implode(', ', $formatted['strong']) . "\n";
        }
        
        if (!empty($formatted['moderate'])) {
            $context .= "Moderate emotions: " . implode(', ', $formatted['moderate']) . "\n";
        }
        
        if (!empty($formatted['mild'])) {
            $context .= "Mild emotions: " . implode(', ', $formatted['mild']) . "\n";
        }
        
        $context .= "\nRespond in a way that reflects your current emotional state naturally.";
        
        return $context;
    }
    
    /**
     * Validate emotion data from API response
     */
    public function validateEmotionData($emotionData) {
        if (!is_array($emotionData)) {
            return false;
        }
        
        foreach (self::EMOTIONS as $emotion) {
            if (!isset($emotionData[$emotion])) {
                return false;
            }
            
            $value = $emotionData[$emotion];
            if (!is_numeric($value) || $value < 0 || $value > 1) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get default neutral emotions
     */
    private function getDefaultEmotions() {
        $emotions = [];
        foreach (self::EMOTIONS as $emotion) {
            $emotions[$emotion] = 0.5;
        }
        return $emotions;
    }
    
    /**
     * Normalize emotion value to valid range and precision
     */
    private function normalizeEmotionValue($value) {
        $value = (float) $value;
        $value = max(0.0, min(1.0, $value)); // Clamp to 0-1 range
        return round($value, 1); // Round to 0.1 precision
    }
    
    /**
     * Get emotion statistics for a session
     */
    public function getEmotionStatistics($sessionId) {
        try {
            $sql = "SELECT COUNT(*) as message_count,
                           AVG(aei_joy) as avg_joy,
                           AVG(aei_sadness) as avg_sadness,
                           AVG(aei_anger) as avg_anger,
                           AVG(aei_fear) as avg_fear
                    FROM chat_messages 
                    WHERE session_id = ? AND sender_type = 'aei' AND aei_joy IS NOT NULL";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$sessionId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting emotion statistics: " . $e->getMessage());
            return null;
        }
    }
}
?>