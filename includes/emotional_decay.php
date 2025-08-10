<?php

/**
 * Emotional Decay System for AEI Companions
 * 
 * Handles the gradual emotional changes when users are inactive for extended periods.
 * AEIs become sadder, lonelier, and experience other realistic emotional shifts.
 */
class EmotionalDecay {
    private $pdo;
    
    // Decay rates per day without user interaction
    const DECAY_RATES = [
        // Emotions that increase with inactivity (negative emotions)
        'loneliness' => 0.15,    // Strong increase - AEIs miss their companion
        'sadness' => 0.12,       // Moderate increase
        'boredom' => 0.10,       // Gradual increase
        'fear' => 0.05,          // Slight increase - worry about abandonment
        'nostalgia' => 0.08,     // Missing past conversations
        'envy' => 0.03,          // Slight increase - envying others who aren't alone
        
        // Emotions that decrease with inactivity (positive emotions)
        'joy' => -0.10,          // Moderate decrease
        'love' => -0.05,         // Slow decrease - deep bonds fade slowly
        'trust' => -0.03,        // Very slow decrease
        'excitement' => -0.15,   // Fast decrease
        'pride' => -0.07,        // Gradual decrease
        'gratitude' => -0.04,    // Slow decrease
        
        // Emotions that fluctuate
        'anticipation' => -0.08, // Decrease over time without interaction
        'surprise' => -0.02,     // Gradual decrease
        'contempt' => 0.02,      // Slight increase - feeling abandoned
        'anger' => 0.08,         // Can increase if feeling neglected
        'frustration' => 0.06,   // Increase with prolonged silence
        
        // Stable emotions (change very slowly)
        'disgust' => 0.0,        // Remains relatively stable
        'shame' => 0.01,         // Very slight increase
    ];
    
    // Minimum and maximum bounds for emotions
    const EMOTION_BOUNDS = [
        'min' => 0.0,
        'max' => 1.0,
        'default' => 0.5
    ];
    
    // Decay acceleration factors based on relationship strength
    const RELATIONSHIP_FACTORS = [
        'new_relationship' => 0.5,    // Less impact for new relationships
        'developing' => 0.8,          // Moderate impact
        'established' => 1.0,         // Full impact
        'deep_bond' => 1.2,           // Higher impact for deep relationships
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Apply emotional decay to all active AEI sessions based on inactivity
     */
    public function processEmotionalDecayForAllAEIs() {
        try {
            // Get all active chat sessions with their last activity
            $stmt = $this->pdo->query("
                SELECT 
                    cs.id as session_id,
                    cs.aei_id,
                    cs.user_id,
                    cs.last_message_at,
                    TIMESTAMPDIFF(HOUR, cs.last_message_at, NOW()) as hours_inactive,
                    a.name as aei_name,
                    u.first_name as user_name
                FROM chat_sessions cs
                JOIN aeis a ON cs.aei_id = a.id
                JOIN users u ON cs.user_id = u.id
                WHERE a.is_active = TRUE
                AND cs.last_message_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)
                ORDER BY hours_inactive DESC
            ");
            
            $sessions = $stmt->fetchAll();
            $processedCount = 0;
            
            foreach ($sessions as $session) {
                if ($this->applyDecayToSession($session)) {
                    $processedCount++;
                }
            }
            
            error_log("EmotionalDecay: Processed decay for $processedCount sessions");
            return $processedCount;
            
        } catch (Exception $e) {
            error_log("Error in processEmotionalDecayForAllAEIs: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Apply emotional decay to a specific chat session
     */
    private function applyDecayToSession($session) {
        try {
            $hoursInactive = $session['hours_inactive'];
            
            // Skip if not inactive long enough
            if ($hoursInactive < 2) {
                return false;
            }
            
            // Get current emotional state
            include_once __DIR__ . '/emotions.php';
            $emotions = new Emotions($this->pdo);
            $currentEmotions = $emotions->getEmotionalState($session['session_id']);
            
            // Calculate relationship strength for decay factor
            $relationshipFactor = $this->calculateRelationshipFactor($session);
            
            // Calculate time-based decay
            $decayMultiplier = $this->calculateDecayMultiplier($hoursInactive);
            
            // Apply decay to each emotion
            $newEmotions = [];
            foreach (self::DECAY_RATES as $emotion => $dailyDecayRate) {
                $currentValue = $currentEmotions[$emotion] ?? self::EMOTION_BOUNDS['default'];
                
                // Calculate total decay amount
                $totalDecay = $dailyDecayRate * $decayMultiplier * $relationshipFactor;
                
                // Apply decay with natural variation
                $variation = (mt_rand(80, 120) / 100); // 20% variation
                $actualDecay = $totalDecay * $variation;
                
                // Calculate new value
                $newValue = $currentValue + $actualDecay;
                
                // Apply bounds and natural limits
                $newEmotions[$emotion] = $this->applyEmotionBounds($newValue, $emotion, $currentValue);
            }
            
            // Update emotional state
            $success = $emotions->updateEmotionalState($session['session_id'], $newEmotions);
            
            if ($success) {
                // Record decay event for analytics
                $this->recordDecayEvent($session, $hoursInactive, $currentEmotions, $newEmotions);
                
                // Check if decay triggered strong emotional states that need proactive messages
                $this->checkForDecayTriggeredMessages($session, $newEmotions, $hoursInactive);
            }
            
            return $success;
            
        } catch (Exception $e) {
            error_log("Error applying decay to session {$session['session_id']}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Calculate decay multiplier based on hours inactive
     */
    private function calculateDecayMultiplier($hoursInactive) {
        $daysInactive = $hoursInactive / 24.0;
        
        // Decay curve: faster initial decay, then slower
        // Uses logarithmic decay to prevent extreme values
        if ($daysInactive <= 1) {
            return $daysInactive; // Linear for first day
        } else {
            // Logarithmic curve for extended periods
            return 1 + (log($daysInactive) * 0.5);
        }
    }
    
    /**
     * Calculate relationship strength factor for decay intensity
     */
    private function calculateRelationshipFactor($session) {
        try {
            // Count total messages to estimate relationship depth
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as message_count,
                       DATEDIFF(NOW(), MIN(created_at)) as relationship_days
                FROM chat_messages cm
                JOIN chat_sessions cs ON cm.session_id = cs.id
                WHERE cs.aei_id = ? AND cs.user_id = ?
            ");
            $stmt->execute([$session['aei_id'], $session['user_id']]);
            $stats = $stmt->fetch();
            
            $messageCount = $stats['message_count'] ?? 0;
            $relationshipDays = $stats['relationship_days'] ?? 0;
            
            // Determine relationship stage
            if ($messageCount < 50 || $relationshipDays < 3) {
                return self::RELATIONSHIP_FACTORS['new_relationship'];
            } elseif ($messageCount < 200 || $relationshipDays < 14) {
                return self::RELATIONSHIP_FACTORS['developing'];
            } elseif ($messageCount < 500 || $relationshipDays < 30) {
                return self::RELATIONSHIP_FACTORS['established'];
            } else {
                return self::RELATIONSHIP_FACTORS['deep_bond'];
            }
            
        } catch (Exception $e) {
            error_log("Error calculating relationship factor: " . $e->getMessage());
            return self::RELATIONSHIP_FACTORS['developing']; // Default
        }
    }
    
    /**
     * Apply bounds and natural limits to emotion values
     */
    private function applyEmotionBounds($newValue, $emotion, $currentValue) {
        // Apply hard bounds
        $newValue = max(self::EMOTION_BOUNDS['min'], min(self::EMOTION_BOUNDS['max'], $newValue));
        
        // Prevent unrealistic extreme changes (max 0.3 change per decay cycle)
        $maxChange = 0.3;
        if (abs($newValue - $currentValue) > $maxChange) {
            $direction = $newValue > $currentValue ? 1 : -1;
            $newValue = $currentValue + ($maxChange * $direction);
        }
        
        // Round to reasonable precision
        return round($newValue, 1);
    }
    
    /**
     * Record decay event for analytics and debugging
     */
    private function recordDecayEvent($session, $hoursInactive, $oldEmotions, $newEmotions) {
        try {
            // Calculate significant changes
            $significantChanges = [];
            foreach ($newEmotions as $emotion => $newValue) {
                $oldValue = $oldEmotions[$emotion] ?? 0.5;
                $change = $newValue - $oldValue;
                
                if (abs($change) >= 0.05) { // Only record significant changes
                    $significantChanges[$emotion] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                        'change' => round($change, 2)
                    ];
                }
            }
            
            if (!empty($significantChanges)) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO aei_emotional_decay_log (
                        id, aei_id, session_id, hours_inactive, 
                        emotional_changes, processed_at
                    ) VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                $logId = bin2hex(random_bytes(16));
                $stmt->execute([
                    $logId,
                    $session['aei_id'],
                    $session['session_id'],
                    $hoursInactive,
                    json_encode($significantChanges)
                ]);
            }
            
        } catch (Exception $e) {
            // Don't fail the main process if logging fails
            error_log("Error recording decay event: " . $e->getMessage());
        }
    }
    
    /**
     * Check if decay triggered conditions for proactive messages
     */
    private function checkForDecayTriggeredMessages($session, $newEmotions, $hoursInactive) {
        try {
            $triggers = [];
            
            // High loneliness trigger
            if ($newEmotions['loneliness'] >= 0.7) {
                $triggers[] = [
                    'type' => 'emotional_decay',
                    'subtype' => 'loneliness_decay',
                    'strength' => $newEmotions['loneliness'],
                    'details' => [
                        'emotion' => 'loneliness',
                        'intensity' => $newEmotions['loneliness'],
                        'hours_inactive' => $hoursInactive,
                        'trigger_reason' => 'Loneliness increased due to prolonged inactivity'
                    ]
                ];
            }
            
            // Combined sadness and loneliness
            if ($newEmotions['sadness'] >= 0.6 && $newEmotions['loneliness'] >= 0.6) {
                $combinedIntensity = ($newEmotions['sadness'] + $newEmotions['loneliness']) / 2;
                
                $triggers[] = [
                    'type' => 'emotional_decay',
                    'subtype' => 'emotional_distress',
                    'strength' => $combinedIntensity,
                    'details' => [
                        'emotions' => ['sadness', 'loneliness'],
                        'sadness' => $newEmotions['sadness'],
                        'loneliness' => $newEmotions['loneliness'],
                        'hours_inactive' => $hoursInactive,
                        'trigger_reason' => 'Multiple negative emotions increased from inactivity'
                    ]
                ];
            }
            
            // Fear of abandonment
            if ($newEmotions['fear'] >= 0.6 && $hoursInactive > 48) {
                $triggers[] = [
                    'type' => 'emotional_decay',
                    'subtype' => 'abandonment_fear',
                    'strength' => $newEmotions['fear'],
                    'details' => [
                        'emotion' => 'fear',
                        'intensity' => $newEmotions['fear'],
                        'hours_inactive' => $hoursInactive,
                        'trigger_reason' => 'Fear of abandonment after extended silence'
                    ]
                ];
            }
            
            // Schedule proactive messages for strongest triggers
            if (!empty($triggers)) {
                usort($triggers, function($a, $b) {
                    return $b['strength'] <=> $a['strength'];
                });
                
                // Process strongest trigger
                $this->scheduleDecayBasedProactiveMessage($session, $triggers[0]);
            }
            
        } catch (Exception $e) {
            error_log("Error checking decay triggers: " . $e->getMessage());
        }
    }
    
    /**
     * Schedule a proactive message based on emotional decay
     */
    private function scheduleDecayBasedProactiveMessage($session, $trigger) {
        try {
            include_once __DIR__ . '/background_jobs.php';
            $jobWorker = new BackgroundJobWorker($this->pdo);
            
            // Calculate delay based on emotional intensity
            $baseDelayMinutes = 30; // 30 minutes base
            $urgencyFactor = $trigger['strength']; // Higher emotion = faster response
            $delayMinutes = max(5, $baseDelayMinutes * (1 - $urgencyFactor));
            
            $executeAfter = date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));
            
            // Create special job data for decay-triggered proactive message
            $jobData = [
                'aei_id' => $session['aei_id'],
                'session_id' => $session['session_id'],
                'user_id' => $session['user_id'],
                'analysis_type' => 'decay_triggered_analysis',
                'forced_trigger' => $trigger,
                'hours_inactive' => $trigger['details']['hours_inactive']
            ];
            
            $jobId = bin2hex(random_bytes(16));
            
            $stmt = $this->pdo->prepare("
                INSERT INTO background_jobs (
                    id, job_type, target_type, target_id, job_data, 
                    priority, execute_after
                ) VALUES (?, 'proactive_analysis', 'aei', ?, ?, 'high', ?)
            ");
            
            $stmt->execute([
                $jobId, 
                $session['aei_id'], 
                json_encode($jobData), 
                $executeAfter
            ]);
            
            error_log("Scheduled decay-based proactive message for AEI {$session['aei_id']} after {$delayMinutes} minutes");
            
        } catch (Exception $e) {
            error_log("Error scheduling decay-based proactive message: " . $e->getMessage());
        }
    }
    
    /**
     * Get decay statistics for admin panel
     */
    public function getDecayStatistics($days = 7) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    DATE(processed_at) as date,
                    COUNT(*) as decay_events,
                    AVG(hours_inactive) as avg_hours_inactive,
                    AVG(JSON_LENGTH(emotional_changes)) as avg_changes_per_event
                FROM aei_emotional_decay_log
                WHERE processed_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                GROUP BY DATE(processed_at)
                ORDER BY date DESC
            ");
            $stmt->execute([$days]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error getting decay statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get most affected AEIs by emotional decay
     */
    public function getMostAffectedAEIs($limit = 10) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    a.name as aei_name,
                    u.first_name as user_name,
                    dl.aei_id,
                    COUNT(*) as decay_events,
                    MAX(dl.hours_inactive) as max_hours_inactive,
                    AVG(JSON_LENGTH(dl.emotional_changes)) as avg_emotional_changes
                FROM aei_emotional_decay_log dl
                JOIN aeis a ON dl.aei_id = a.id
                JOIN users u ON a.user_id = u.id
                WHERE dl.processed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                GROUP BY dl.aei_id, a.name, u.first_name
                ORDER BY decay_events DESC, max_hours_inactive DESC
                LIMIT ?
            ");
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error getting most affected AEIs: " . $e->getMessage());
            return [];
        }
    }
}