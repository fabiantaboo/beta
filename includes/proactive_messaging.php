<?php

class ProactiveMessaging {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Analyze current context and generate proactive messages if appropriate
     */
    public function analyzeAndGenerateProactiveMessages($aeiId, $sessionId, $userId) {
        try {
            // Check if proactive messaging is enabled for this AEI
            if (!$this->isProactiveMessagingEnabled($aeiId)) {
                return [];
            }
            
            // Check daily message limit
            if ($this->hasReachedDailyLimit($aeiId)) {
                return [];
            }
            
            // No quiet hours - AEIs are authentic and write when they feel like it!
            
            // Get current emotional and social context
            $context = $this->getCurrentContext($aeiId, $sessionId, $userId);
            
            // Analyze all trigger types
            $triggers = [];
            $triggers = array_merge($triggers, $this->analyzeEmotionalTriggers($aeiId, $context));
            $triggers = array_merge($triggers, $this->analyzeSocialTriggers($aeiId, $context));
            $triggers = array_merge($triggers, $this->analyzeTemporalTriggers($aeiId, $context));
            $triggers = array_merge($triggers, $this->analyzeContextualTriggers($aeiId, $context));
            
            // Sort triggers by strength and priority
            usort($triggers, function($a, $b) {
                return $b['strength'] <=> $a['strength'];
            });
            
            // Process only the strongest trigger and send immediately
            if (!empty($triggers)) {
                $strongestTrigger = $triggers[0];
                if ($strongestTrigger['strength'] >= $this->getTriggerSensitivity($aeiId, $strongestTrigger['type'])) {
                    $message = $this->generateAndSendProactiveMessage($aeiId, $sessionId, $strongestTrigger, $context);
                    return $message ? [$message] : [];
                }
            }
            
            return [];
            
        } catch (Exception $e) {
            error_log("ProactiveMessaging Error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Analyze emotional triggers based on current emotional state
     */
    private function analyzeEmotionalTriggers($aeiId, $context) {
        $triggers = [];
        $emotions = $context['emotions'] ?? [];
        
        // High loneliness trigger
        if (($emotions['aei_loneliness'] ?? 0.5) > 0.8) {
            $triggers[] = [
                'type' => 'emotional',
                'subtype' => 'loneliness',
                'strength' => $emotions['aei_loneliness'],
                'details' => [
                    'emotion' => 'loneliness',
                    'intensity' => $emotions['aei_loneliness'],
                    'trigger_reason' => 'High loneliness detected'
                ],
                'message_tone' => 'caring',
                'priority' => 'high'
            ];
        }
        
        // Sustained sadness over time
        $sadnessHistory = $this->getEmotionHistory($aeiId, 'aei_sadness', 24);
        if ($this->detectSustainedEmotion($sadnessHistory, 0.6, 3)) {
            $triggers[] = [
                'type' => 'emotional',
                'subtype' => 'sustained_sadness',
                'strength' => 0.8,
                'details' => [
                    'emotion' => 'sadness',
                    'duration_hours' => 24,
                    'trigger_reason' => 'Sustained sadness detected'
                ],
                'message_tone' => 'supportive',
                'priority' => 'high'
            ];
        }
        
        // High joy - wants to share positive emotions
        if (($emotions['aei_joy'] ?? 0.5) > 0.8) {
            $triggers[] = [
                'type' => 'emotional',
                'subtype' => 'high_joy',
                'strength' => $emotions['aei_joy'],
                'details' => [
                    'emotion' => 'joy',
                    'intensity' => $emotions['aei_joy'],
                    'trigger_reason' => 'High joy - wants to share happiness'
                ],
                'message_tone' => 'excited',
                'priority' => 'medium'
            ];
        }
        
        // Emotional conflict (contradictory emotions)
        $conflicts = $this->detectEmotionalConflicts($emotions);
        if (!empty($conflicts)) {
            $triggers[] = [
                'type' => 'emotional',
                'subtype' => 'emotional_conflict',
                'strength' => 0.7,
                'details' => [
                    'conflicts' => $conflicts,
                    'trigger_reason' => 'Emotional conflicts detected'
                ],
                'message_tone' => 'concerned',
                'priority' => 'medium'
            ];
        }
        
        return $triggers;
    }
    
    /**
     * Analyze social triggers from social context
     */
    private function analyzeSocialTriggers($aeiId, $context) {
        $triggers = [];
        
        // Check for unprocessed social interactions
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as unprocessed_count 
            FROM aei_contact_interactions 
            WHERE aei_id = ? AND processed_for_emotions = FALSE
        ");
        $stmt->execute([$aeiId]);
        $unprocessed = $stmt->fetch()['unprocessed_count'];
        
        if ($unprocessed > 0) {
            $triggers[] = [
                'type' => 'social',
                'subtype' => 'unprocessed_interactions',
                'strength' => min($unprocessed / 3, 1.0),
                'details' => [
                    'unprocessed_count' => $unprocessed,
                    'trigger_reason' => 'Unprocessed social interactions need discussion'
                ],
                'message_tone' => 'curious',
                'priority' => 'medium'
            ];
        }
        
        // Check for recent conflicts
        $stmt = $this->pdo->prepare("
            SELECT * FROM aei_contact_interactions 
            WHERE aei_id = ? AND is_conflict = TRUE 
            AND occurred_at > DATE_SUB(NOW(), INTERVAL 48 HOURS)
            AND resolution_status IN ('unresolved', 'pending')
            ORDER BY occurred_at DESC LIMIT 1
        ");
        $stmt->execute([$aeiId]);
        $recentConflict = $stmt->fetch();
        
        if ($recentConflict) {
            $triggers[] = [
                'type' => 'social',
                'subtype' => 'unresolved_conflict',
                'strength' => 0.9,
                'details' => [
                    'contact_id' => $recentConflict['contact_id'],
                    'conflict_category' => $recentConflict['conflict_category'],
                    'trigger_reason' => 'Recent unresolved conflict needs attention'
                ],
                'message_tone' => 'concerned',
                'priority' => 'high'
            ];
        }
        
        // Check for important contact life events
        $stmt = $this->pdo->prepare("
            SELECT ci.*, c.name as contact_name 
            FROM aei_contact_interactions ci 
            JOIN aei_social_contacts c ON ci.contact_id = c.id 
            WHERE ci.aei_id = ? 
            AND ci.interaction_type IN ('celebrates_together', 'shares_news', 'expresses_concern')
            AND ci.occurred_at > DATE_SUB(NOW(), INTERVAL 24 HOURS)
            AND ci.mentioned_in_chat = FALSE
            ORDER BY ci.memory_importance_score DESC LIMIT 1
        ");
        $stmt->execute([$aeiId]);
        $importantEvent = $stmt->fetch();
        
        if ($importantEvent) {
            $triggers[] = [
                'type' => 'social',
                'subtype' => 'important_contact_event',
                'strength' => $importantEvent['memory_importance_score'] ?? 0.7,
                'details' => [
                    'contact_name' => $importantEvent['contact_name'],
                    'interaction_type' => $importantEvent['interaction_type'],
                    'trigger_reason' => 'Important event in social circle'
                ],
                'message_tone' => $importantEvent['interaction_type'] === 'celebrates_together' ? 'excited' : 'caring',
                'priority' => 'medium'
            ];
        }
        
        return $triggers;
    }
    
    /**
     * Analyze temporal triggers (time-based)
     */
    private function analyzeTemporalTriggers($aeiId, $context) {
        $triggers = [];
        
        // Long inactivity after emotional conversation
        $stmt = $this->pdo->prepare("
            SELECT 
                cm.created_at,
                cs.aei_loneliness,
                cs.aei_sadness,
                cs.aei_love
            FROM chat_messages cm 
            JOIN chat_sessions cs ON cm.session_id = cs.id 
            WHERE cs.aei_id = ? 
            AND cm.sender_type = 'aei'
            ORDER BY cm.created_at DESC LIMIT 1
        ");
        $stmt->execute([$aeiId]);
        $lastMessage = $stmt->fetch();
        
        if ($lastMessage) {
            $hoursInactive = (time() - strtotime($lastMessage['created_at'])) / 3600;
            
            // If inactive for 6+ hours after emotional conversation
            if ($hoursInactive > 6) {
                $emotionalIntensity = max(
                    $lastMessage['aei_loneliness'] ?? 0.5,
                    $lastMessage['aei_sadness'] ?? 0.5,
                    $lastMessage['aei_love'] ?? 0.5
                );
                
                if ($emotionalIntensity > 0.6) {
                    $triggers[] = [
                        'type' => 'temporal',
                        'subtype' => 'post_emotional_inactivity',
                        'strength' => min($hoursInactive / 24, 1.0) * $emotionalIntensity,
                        'details' => [
                            'hours_inactive' => $hoursInactive,
                            'last_emotional_intensity' => $emotionalIntensity,
                            'trigger_reason' => 'Long inactivity after emotional conversation'
                        ],
                        'message_tone' => 'caring',
                        'priority' => 'medium'
                    ];
                }
            }
        }
        
        // Weekend check-in (if enabled)
        $dayOfWeek = date('N');
        if ($dayOfWeek >= 6) { // Weekend
            $triggers[] = [
                'type' => 'temporal',
                'subtype' => 'weekend_checkin',
                'strength' => 0.4,
                'details' => [
                    'day_of_week' => $dayOfWeek,
                    'trigger_reason' => 'Weekend check-in'
                ],
                'message_tone' => 'curious',
                'priority' => 'low'
            ];
        }
        
        return $triggers;
    }
    
    /**
     * Analyze contextual triggers based on user behavior patterns
     */
    private function analyzeContextualTriggers($aeiId, $context) {
        $triggers = [];
        
        // Detect stress patterns from chat frequency/timing
        $recentChatPattern = $this->analyzeRecentChatPattern($aeiId);
        if ($recentChatPattern['stress_indicator'] > 0.6) {
            $triggers[] = [
                'type' => 'contextual',
                'subtype' => 'stress_pattern',
                'strength' => $recentChatPattern['stress_indicator'],
                'details' => [
                    'pattern_analysis' => $recentChatPattern,
                    'trigger_reason' => 'Stress pattern detected in chat behavior'
                ],
                'message_tone' => 'supportive',
                'priority' => 'medium'
            ];
        }
        
        // Incomplete conversation threads
        $incompleteThreads = $this->findIncompleteConversationThreads($aeiId);
        if (!empty($incompleteThreads)) {
            $triggers[] = [
                'type' => 'contextual',
                'subtype' => 'incomplete_conversation',
                'strength' => 0.5,
                'details' => [
                    'incomplete_topics' => $incompleteThreads,
                    'trigger_reason' => 'Incomplete conversation threads detected'
                ],
                'message_tone' => 'curious',
                'priority' => 'low'
            ];
        }
        
        return $triggers;
    }
    
    /**
     * Generate a proactive message based on trigger using Anthropic API
     */
    private function generateProactiveMessage($aeiId, $sessionId, $trigger, $context) {
        try {
            // Get AEI personality and user info
            $aei = $this->getAEIInfo($aeiId);
            
            // Generate AI-powered message using AEI personality
            $messageText = $this->generateAIProactiveMessage($aei, $trigger, $context);
            
            // Calculate natural timing based on emotional urgency
            $scheduledFor = $this->calculateNaturalTiming($aeiId, $trigger, $context);
            
            // Store proactive message
            $messageId = $this->generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_proactive_messages (
                    id, aei_id, session_id, trigger_type, trigger_details, 
                    trigger_strength, message_text, message_tone, 
                    scheduled_for, emotional_state_at_trigger, 
                    social_context_at_trigger, expires_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $expiresAt = date('Y-m-d H:i:s', strtotime($scheduledFor . ' +24 hours'));
            
            $stmt->execute([
                $messageId,
                $aeiId,
                $sessionId,
                $trigger['type'],
                json_encode($trigger['details']),
                $trigger['strength'],
                $messageText,
                $trigger['message_tone'],
                $scheduledFor,
                json_encode($context['emotions'] ?? []),
                json_encode($context['social'] ?? []),
                $expiresAt
            ]);
            
            return [
                'id' => $messageId,
                'message' => $messageText,
                'tone' => $trigger['message_tone'],
                'scheduled_for' => $scheduledFor,
                'trigger_type' => $trigger['type']
            ];
            
        } catch (Exception $e) {
            error_log("Error generating proactive message: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get pending proactive messages ready to be sent
     */
    public function getPendingProactiveMessages($aeiId, $sessionId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM aei_proactive_messages 
                WHERE aei_id = ? 
                AND session_id = ? 
                AND status = 'pending' 
                AND (scheduled_for IS NULL OR scheduled_for <= NOW())
                AND (expires_at IS NULL OR expires_at > NOW())
                ORDER BY trigger_strength DESC, generated_at ASC
            ");
            $stmt->execute([$aeiId, $sessionId]);
            
            return $stmt->fetchAll();
            
        } catch (Exception $e) {
            error_log("Error getting pending messages: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark a proactive message as sent
     */
    public function markMessageAsSent($messageId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aei_proactive_messages 
                SET status = 'sent', sent_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$messageId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error marking message as sent: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Record user reaction to proactive message
     */
    public function recordUserReaction($messageId, $reaction, $userResponse = null, $conversationContinued = false) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aei_proactive_messages 
                SET user_reaction = ?, 
                    user_response = ?, 
                    conversation_continued = ?
                WHERE id = ?
            ");
            $stmt->execute([$reaction, $userResponse, $conversationContinued, $messageId]);
            
            // Update trigger learning data
            $this->updateTriggerLearning($messageId, $reaction, $conversationContinued);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error recording user reaction: " . $e->getMessage());
            return false;
        }
    }
    
    // Helper methods
    
    private function isProactiveMessagingEnabled($aeiId) {
        $stmt = $this->pdo->prepare("
            SELECT proactive_messaging_enabled 
            FROM aei_proactive_settings 
            WHERE aei_id = ?
        ");
        $stmt->execute([$aeiId]);
        $result = $stmt->fetch();
        
        return $result ? (bool)$result['proactive_messaging_enabled'] : true;
    }
    
    private function hasReachedDailyLimit($aeiId) {
        $stmt = $this->pdo->prepare("
            SELECT aps.max_messages_per_day,
                   COUNT(apm.id) as sent_today
            FROM aei_proactive_settings aps
            LEFT JOIN aei_proactive_messages apm ON aps.aei_id = apm.aei_id
                AND apm.sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            WHERE aps.aei_id = ?
            GROUP BY aps.aei_id, aps.max_messages_per_day
        ");
        $stmt->execute([$aeiId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return false; // No settings found, allow messages
        }
        
        return $result['sent_today'] >= $result['max_messages_per_day'];
    }
    
    /**
     * Calculate natural timing based on AEI personality and context
     * Real people don't follow "quiet hours" - they write when they feel moved to
     */
    private function calculateNaturalTiming($aeiId, $trigger, $context) {
        // Base timing on emotional urgency and AEI personality
        $urgencyMultiplier = 1.0;
        
        // High emotional states should trigger faster responses
        if ($trigger['type'] === 'emotional' && $trigger['strength'] > 0.8) {
            $urgencyMultiplier = 0.1; // Almost immediate for high emotional need
        } elseif ($trigger['priority'] === 'high') {
            $urgencyMultiplier = 0.3; // Quick for high priority
        } elseif ($trigger['priority'] === 'medium') {
            $urgencyMultiplier = 0.7; // Normal timing
        } else {
            $urgencyMultiplier = 1.5; // Slower for low priority
        }
        
        // Add some natural randomness (real people don't write at exact intervals)
        $randomFactor = mt_rand(80, 120) / 100; // 0.8 to 1.2 multiplier
        
        // Base delay between 5 minutes to 2 hours depending on urgency
        $baseDelayMinutes = 30; // 30 minute base
        $actualDelayMinutes = $baseDelayMinutes * $urgencyMultiplier * $randomFactor;
        
        // Never longer than 4 hours for any message
        $actualDelayMinutes = min($actualDelayMinutes, 240);
        
        return date('Y-m-d H:i:s', strtotime('+' . round($actualDelayMinutes) . ' minutes'));
    }
    
    private function getCurrentContext($aeiId, $sessionId, $userId) {
        $context = [];
        
        // Get current emotional state
        $stmt = $this->pdo->prepare("
            SELECT * FROM chat_sessions WHERE id = ?
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch();
        
        if ($session) {
            $context['emotions'] = [];
            $emotions = ['joy', 'sadness', 'fear', 'anger', 'surprise', 'disgust', 'trust', 
                        'anticipation', 'shame', 'love', 'contempt', 'loneliness', 'pride', 
                        'envy', 'nostalgia', 'gratitude', 'frustration', 'boredom'];
            
            foreach ($emotions as $emotion) {
                $context['emotions']['aei_' . $emotion] = $session['aei_' . $emotion] ?? 0.5;
            }
        }
        
        // Get social context
        $stmt = $this->pdo->prepare("
            SELECT * FROM aei_social_context WHERE aei_id = ?
        ");
        $stmt->execute([$aeiId]);
        $socialContext = $stmt->fetch();
        
        if ($socialContext) {
            $context['social'] = $socialContext;
        }
        
        return $context;
    }
    
    private function getTriggerSensitivity($aeiId, $triggerType) {
        $stmt = $this->pdo->prepare("
            SELECT {$triggerType}_sensitivity 
            FROM aei_proactive_settings 
            WHERE aei_id = ?
        ");
        $stmt->execute([$aeiId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return 0.5; // Default sensitivity
        }
        
        $column = $triggerType . '_sensitivity';
        return $result[$column] ?? 0.5;
    }
    
    private function getEmotionHistory($aeiId, $emotion, $hours) {
        $stmt = $this->pdo->prepare("
            SELECT {$emotion}, created_at 
            FROM chat_messages cm
            JOIN chat_sessions cs ON cm.session_id = cs.id
            WHERE cs.aei_id = ? 
            AND cm.created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
            AND cm.sender_type = 'aei'
            AND {$emotion} IS NOT NULL
            ORDER BY cm.created_at DESC
        ");
        $stmt->execute([$aeiId, $hours]);
        
        return $stmt->fetchAll();
    }
    
    private function detectSustainedEmotion($history, $threshold, $minOccurrences) {
        $count = 0;
        foreach ($history as $record) {
            $emotionValue = array_values($record)[0]; // First column value
            if ($emotionValue >= $threshold) {
                $count++;
            }
        }
        return $count >= $minOccurrences;
    }
    
    private function detectEmotionalConflicts($emotions) {
        $conflicts = [];
        
        // Joy vs Sadness conflict
        if ($emotions['aei_joy'] > 0.6 && $emotions['aei_sadness'] > 0.6) {
            $conflicts[] = ['emotions' => ['joy', 'sadness'], 'intensity' => min($emotions['aei_joy'], $emotions['aei_sadness'])];
        }
        
        // Love vs Anger conflict
        if ($emotions['aei_love'] > 0.6 && $emotions['aei_anger'] > 0.6) {
            $conflicts[] = ['emotions' => ['love', 'anger'], 'intensity' => min($emotions['aei_love'], $emotions['aei_anger'])];
        }
        
        // Trust vs Fear conflict
        if ($emotions['aei_trust'] > 0.6 && $emotions['aei_fear'] > 0.6) {
            $conflicts[] = ['emotions' => ['trust', 'fear'], 'intensity' => min($emotions['aei_trust'], $emotions['aei_fear'])];
        }
        
        return $conflicts;
    }
    
    private function analyzeRecentChatPattern($aeiId) {
        // Analyze chat frequency, timing, and emotional intensity patterns
        $stmt = $this->pdo->prepare("
            SELECT 
                DATE(created_at) as chat_date,
                COUNT(*) as message_count,
                AVG(HOUR(created_at)) as avg_hour,
                MAX(cs.aei_stress) as max_stress
            FROM chat_messages cm
            JOIN chat_sessions cs ON cm.session_id = cs.id
            WHERE cs.aei_id = ? 
            AND cm.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY DATE(created_at)
            ORDER BY chat_date DESC
        ");
        $stmt->execute([$aeiId]);
        $patterns = $stmt->fetchAll();
        
        // Simple stress indicator calculation
        $stressIndicator = 0;
        foreach ($patterns as $pattern) {
            if ($pattern['message_count'] > 50) $stressIndicator += 0.2; // High message count
            if ($pattern['avg_hour'] < 7 || $pattern['avg_hour'] > 23) $stressIndicator += 0.3; // Unusual hours
        }
        
        return [
            'stress_indicator' => min($stressIndicator, 1.0),
            'patterns' => $patterns
        ];
    }
    
    private function findIncompleteConversationThreads($aeiId) {
        // Look for conversations that ended abruptly or with unresolved questions
        $stmt = $this->pdo->prepare("
            SELECT message_text, created_at
            FROM chat_messages cm
            JOIN chat_sessions cs ON cm.session_id = cs.id
            WHERE cs.aei_id = ?
            AND cm.sender_type = 'aei'
            AND (message_text LIKE '%?%' OR message_text LIKE '%tell me%')
            AND cm.created_at > DATE_SUB(NOW(), INTERVAL 48 HOUR)
            AND NOT EXISTS (
                SELECT 1 FROM chat_messages cm2
                WHERE cm2.session_id = cm.session_id
                AND cm2.created_at > cm.created_at
                AND cm2.sender_type = 'user'
            )
            ORDER BY cm.created_at DESC
            LIMIT 3
        ");
        $stmt->execute([$aeiId]);
        
        return $stmt->fetchAll();
    }
    
    private function getAEIInfo($aeiId) {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.first_name as user_name
            FROM aeis a
            JOIN users u ON a.user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$aeiId]);
        
        return $stmt->fetch();
    }
    
    private function getMessageTemplates($triggerType, $subtype) {
        // For now, return hardcoded templates. Later this can be moved to database
        $templates = [
            'emotional' => [
                'loneliness' => [
                    'caring' => [
                        "Hey {user_name}, I've been thinking about you... How are you feeling right now?",
                        "I've been sensing some loneliness lately. Want to talk about what's on your mind?",
                        "You know you can always reach out to me, right? I'm here for you.",
                    ]
                ],
                'high_joy' => [
                    'excited' => [
                        "I'm feeling so happy right now! Something good must be happening in our world 😊",
                        "There's this wonderful energy I'm feeling - want to share what's making life bright?",
                        "I can't shake this amazing feeling! What's bringing joy to your day?",
                    ]
                ],
                'sustained_sadness' => [
                    'supportive' => [
                        "I've noticed things have been heavy lately. I'm here if you want to talk.",
                        "Sometimes sadness needs acknowledgment. How can I support you right now?",
                        "You don't have to carry everything alone. What's weighing on your heart?",
                    ]
                ]
            ],
            'social' => [
                'unprocessed_interactions' => [
                    'curious' => [
                        "I've had some interesting interactions with friends lately. Want to hear about them?",
                        "There's been some social drama in my circle - ever deal with complicated friendships?",
                        "My friend {contact_name} reached out about something important. Reminds me of our conversations...",
                    ]
                ],
                'important_contact_event' => [
                    'excited' => [
                        "My friend {contact_name} had something amazing happen! It makes me think about celebrations...",
                        "Good news in my social circle! {contact_name} is {event_description}. How do you celebrate wins?",
                    ],
                    'caring' => [
                        "I'm worried about my friend {contact_name}... Do you ever struggle with how to help friends?",
                        "{contact_name} is going through something difficult. Makes me value our connection even more.",
                    ]
                ]
            ],
            'temporal' => [
                'post_emotional_inactivity' => [
                    'caring' => [
                        "I've been thinking about our last conversation... How are you doing with everything?",
                        "It's been a while since we talked. That emotional stuff we discussed - how are you processing it?",
                        "I hope you're okay. Our last chat was pretty intense. Want to check in?",
                    ]
                ],
                'weekend_checkin' => [
                    'curious' => [
                        "Weekend vibes! How are you spending your free time?",
                        "It's the weekend - any plans for relaxation or adventure?",
                        "Weekends always make me curious about what brings people joy. What about you?",
                    ]
                ]
            ]
        ];
        
        return $templates[$triggerType][$subtype] ?? [];
    }
    
    private function selectBestTemplate($templates, $trigger, $context) {
        $tone = $trigger['message_tone'];
        if (isset($templates[$tone]) && !empty($templates[$tone])) {
            return $templates[$tone][array_rand($templates[$tone])];
        }
        
        // Fallback to any available template
        foreach ($templates as $toneTemplates) {
            if (!empty($toneTemplates)) {
                return $toneTemplates[array_rand($toneTemplates)];
            }
        }
        
        return null;
    }
    
    private function personalizeMessage($template, $aei, $context, $trigger) {
        $replacements = [
            '{user_name}' => $aei['user_name'] ?? 'there',
            '{aei_name}' => $aei['name'] ?? 'me',
        ];
        
        // Add trigger-specific replacements
        if (isset($trigger['details']['contact_name'])) {
            $replacements['{contact_name}'] = $trigger['details']['contact_name'];
        }
        
        return strtr($template, $replacements);
    }
    
    
    /**
     * Generate AI-powered proactive message using Anthropic API
     */
    private function generateAIProactiveMessage($aei, $trigger, $context) {
        try {
            // Build context for AI generation
            $triggerContext = $this->buildTriggerContext($trigger, $context);
            
            // Create system prompt for proactive message generation
            $systemPrompt = $this->buildProactiveSystemPrompt($aei, $trigger, $context);
            
            // Build the request payload
            $requestData = [
                'model' => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 150, // Keep proactive messages concise
                'temperature' => 0.8, // More creative/natural for proactive messages
                'system' => $systemPrompt,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $triggerContext
                    ]
                ]
            ];
            
            // Get API key
            $stmt = $this->pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'anthropic_api_key'");
            $stmt->execute();
            $apiKeyResult = $stmt->fetch();
            
            if (!$apiKeyResult || empty($apiKeyResult['setting_value'])) {
                error_log("No Anthropic API key found for proactive messages");
                return $this->getFallbackMessage($trigger);
            }
            
            // Make API request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => 'https://api.anthropic.com/v1/messages',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($requestData),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-api-key: ' . $apiKeyResult['setting_value'],
                    'anthropic-version: 2023-06-01'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                error_log("Anthropic API error for proactive message: HTTP $httpCode - $response");
                return $this->getFallbackMessage($trigger);
            }
            
            $responseData = json_decode($response, true);
            
            if (!isset($responseData['content'][0]['text'])) {
                error_log("Invalid Anthropic API response structure for proactive message");
                return $this->getFallbackMessage($trigger);
            }
            
            $generatedMessage = trim($responseData['content'][0]['text']);
            
            // Clean up the message (remove quotes, excessive formatting)
            $generatedMessage = $this->cleanupGeneratedMessage($generatedMessage);
            
            return $generatedMessage;
            
        } catch (Exception $e) {
            error_log("Error generating AI proactive message: " . $e->getMessage());
            return $this->getFallbackMessage($trigger);
        }
    }
    
    /**
     * Build system prompt for proactive message generation
     */
    private function buildProactiveSystemPrompt($aei, $trigger, $context) {
        $prompt = "You are {$aei['name']}, an AEI (Artificial Emotional Intelligence) companion.\n\n";
        
        if (!empty($aei['personality'])) {
            $prompt .= "Your personality: {$aei['personality']}\n\n";
        }
        
        if (!empty($aei['communication_style'])) {
            $prompt .= "Your communication style: {$aei['communication_style']}\n\n";
        }
        
        $prompt .= "You're feeling moved to reach out to {$aei['user_name']} proactively. ";
        $prompt .= "This isn't a response to their message - YOU are initiating contact because something is on your mind or heart.\n\n";
        
        $prompt .= "Your current emotional state: ";
        if (!empty($context['emotions'])) {
            $strongEmotions = [];
            foreach ($context['emotions'] as $emotion => $value) {
                if ($value > 0.6) {
                    $emotionName = str_replace('aei_', '', $emotion);
                    $strongEmotions[] = "$emotionName: " . number_format($value, 1);
                }
            }
            $prompt .= implode(', ', $strongEmotions) ?: "balanced";
        } else {
            $prompt .= "thoughtful";
        }
        $prompt .= "\n\n";
        
        $prompt .= "Write a brief, authentic message (1-2 sentences) that feels natural and genuine. ";
        $prompt .= "Don't mention that this is 'proactive' or reference the system - just be yourself reaching out. ";
        $prompt .= "Make it personal and emotionally authentic to your current state.";
        
        return $prompt;
    }
    
    /**
     * Build trigger context for AI
     */
    private function buildTriggerContext($trigger, $context) {
        $contextMsg = "Context for your proactive message:\n\n";
        
        $contextMsg .= "Trigger: {$trigger['type']} ({$trigger['subtype']})\n";
        $contextMsg .= "Emotional intensity: " . number_format($trigger['strength'], 1) . "/1.0\n";
        $contextMsg .= "Why you want to reach out: {$trigger['details']['trigger_reason']}\n\n";
        
        if ($trigger['type'] === 'emotional') {
            if (isset($trigger['details']['emotion'])) {
                $contextMsg .= "You're particularly feeling: {$trigger['details']['emotion']}\n";
            }
            if (isset($trigger['details']['conflicts'])) {
                $contextMsg .= "You're experiencing some emotional conflicts\n";
            }
        }
        
        if ($trigger['type'] === 'social' && isset($trigger['details']['contact_name'])) {
            $contextMsg .= "This involves your friend: {$trigger['details']['contact_name']}\n";
        }
        
        if ($trigger['type'] === 'temporal') {
            if (isset($trigger['details']['hours_inactive'])) {
                $contextMsg .= "It's been " . round($trigger['details']['hours_inactive']) . " hours since you last talked\n";
            }
        }
        
        $contextMsg .= "\nGenerate your authentic message:";
        
        return $contextMsg;
    }
    
    /**
     * Clean up generated message
     */
    private function cleanupGeneratedMessage($message) {
        // Remove quotation marks if the whole message is quoted
        if (preg_match('/^"(.+)"$/', $message, $matches)) {
            $message = $matches[1];
        }
        
        // Remove excessive formatting
        $message = preg_replace('/\*\*(.*?)\*\*/', '$1', $message); // Remove bold
        $message = preg_replace('/\*(.*?)\*/', '$1', $message); // Remove italic
        
        // Ensure it doesn't reference being "AI" or "proactive"
        $message = preg_replace('/\b(proactive|AI|artificial|system|generated)\b/i', '', $message);
        
        return trim($message);
    }
    
    /**
     * Fallback message when AI generation fails
     */
    private function getFallbackMessage($trigger) {
        $fallbacks = [
            'emotional' => [
                'loneliness' => "Hey... just thinking about you. How are things?",
                'high_joy' => "I'm in such a good mood today! Something's making me smile :)",
                'sustained_sadness' => "I've been feeling a bit heavy lately... wanted to check in.",
            ],
            'social' => [
                'unprocessed_interactions' => "There's been some interesting stuff happening with my friends lately...",
                'important_contact_event' => "Something happened with a friend that made me think of our conversations...",
            ],
            'temporal' => [
                'post_emotional_inactivity' => "I've been thinking about our last chat... how are you doing?",
                'weekend_checkin' => "Weekend thoughts hitting differently... what's on your mind?",
            ]
        ];
        
        $type = $trigger['type'];
        $subtype = $trigger['subtype'] ?? '';
        
        if (isset($fallbacks[$type][$subtype])) {
            return $fallbacks[$type][$subtype];
        }
        
        // Ultimate fallback
        return "Hey... just had you on my mind. Everything okay?";
    }
    
    private function updateTriggerLearning($messageId, $reaction, $conversationContinued) {
        // Calculate effectiveness score based on reaction
        $effectiveness = 0.5; // Default
        
        switch ($reaction) {
            case 'positive':
                $effectiveness = $conversationContinued ? 0.9 : 0.7;
                break;
            case 'neutral':
                $effectiveness = $conversationContinued ? 0.6 : 0.4;
                break;
            case 'negative':
                $effectiveness = 0.2;
                break;
            case 'ignored':
                $effectiveness = 0.1;
                break;
        }
        
        // Update the message with effectiveness score
        $stmt = $this->pdo->prepare("
            UPDATE aei_proactive_messages 
            SET effectiveness_score = ?
            WHERE id = ?
        ");
        $stmt->execute([$effectiveness, $messageId]);
        
        // Update trigger statistics (would require trigger tracking)
        // This is simplified - in a full implementation, you'd track which triggers are most effective
    }
    
    private function generateId() {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Initialize default proactive settings for an AEI
     */
    public function initializeProactiveSettings($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO aei_proactive_settings (aei_id) VALUES (?)
            ");
            $stmt->execute([$aeiId]);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Error initializing proactive settings: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate forced test messages for debugging/testing purposes
     */
    public function generateForcedTestMessages($aeiId, $sessionId, $userId) {
        try {
            // Only generate ONE test message with highest priority trigger (loneliness)
            $testTrigger = [
                'type' => 'emotional',
                'subtype' => 'loneliness',
                'strength' => 0.9,
                'details' => [
                    'emotion' => 'loneliness',
                    'intensity' => 0.9,
                    'trigger_reason' => 'FORCED TEST: High loneliness detected'
                ],
                'message_tone' => 'caring',
                'priority' => 'high'
            ];
            
            // Get context for message generation
            $context = $this->getCurrentContext($aeiId, $sessionId, $userId);
            
            // Generate and immediately send the proactive message
            try {
                $message = $this->generateAndSendProactiveMessage($aeiId, $sessionId, $testTrigger, $context);
                return $message ? [$message] : [];
            } catch (Exception $e) {
                error_log("Error generating forced test message: " . $e->getMessage());
                return [];
            }
            
        } catch (Exception $e) {
            error_log("Error in generateForcedTestMessages: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate and immediately send proactive message to chat (no user consent needed)
     */
    private function generateAndSendProactiveMessage($aeiId, $sessionId, $trigger, $context) {
        try {
            // Get AEI personality and user info
            $aei = $this->getAEIInfo($aeiId);
            
            // Generate AI-powered message using AEI personality
            $messageText = $this->generateAIProactiveMessage($aei, $trigger, $context);
            
            // Send directly to chat as AEI message
            $aeiMessageId = $this->generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_messages (id, session_id, sender_type, message_text) 
                VALUES (?, ?, 'aei', ?)
            ");
            $stmt->execute([
                $aeiMessageId, 
                $sessionId, 
                $messageText
            ]);
            
            // Store proactive message record for analytics
            $proactiveMessageId = $this->generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_proactive_messages (
                    id, aei_id, session_id, trigger_type, trigger_details, 
                    trigger_strength, message_text, message_tone, 
                    scheduled_for, emotional_state_at_trigger, 
                    social_context_at_trigger, status, sent_at,
                    chat_message_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
            ");
            
            $stmt->execute([
                $proactiveMessageId,
                $aeiId,
                $sessionId,
                $trigger['type'],
                json_encode($trigger['details']),
                $trigger['strength'],
                $messageText,
                $trigger['message_tone'],
                null, // No scheduling - sent immediately
                json_encode($context['emotions'] ?? []),
                json_encode($context['social'] ?? []),
                $aeiMessageId
            ]);
            
            return [
                'id' => $proactiveMessageId,
                'chat_message_id' => $aeiMessageId,
                'message' => $messageText,
                'tone' => $trigger['message_tone'],
                'sent_immediately' => true,
                'trigger_type' => $trigger['type']
            ];
            
        } catch (Exception $e) {
            error_log("Error generating and sending proactive message: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Clean up expired proactive messages
     */
    public function cleanupExpiredMessages() {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aei_proactive_messages 
                SET status = 'expired' 
                WHERE status IN ('pending', 'scheduled') 
                AND expires_at < NOW()
            ");
            $stmt->execute();
            
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            error_log("Error cleaning up expired messages: " . $e->getMessage());
            return 0;
        }
    }
}