<?php

require_once __DIR__ . '/emotions.php';
require_once __DIR__ . '/functions.php';

class AEISocialContext {
    private $pdo;
    private $emotions;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->emotions = new Emotions($pdo);
    }
    
    /**
     * Processes unprocessed social interactions for emotions
     */
    public function processUnprocessedSocialUpdates($aeiId) {
        try {
            $unprocessedInteractions = $this->getUnprocessedInteractions($aeiId);
            $totalEmotionalImpact = [];
            
            foreach ($unprocessedInteractions as $interaction) {
                $emotionalImpact = $this->calculateEmotionalImpact($interaction, $aeiId);
                $totalEmotionalImpact = $this->mergeEmotionalImpacts($totalEmotionalImpact, $emotionalImpact);
                
                // Mark as processed
                $this->markInteractionAsProcessed($interaction['id']);
            }
            
            // Update social context summary
            if (!empty($unprocessedInteractions)) {
                $this->updateSocialContextSummary($aeiId, $unprocessedInteractions);
            }
            
            return $totalEmotionalImpact;
        } catch (Exception $e) {
            error_log("Error processing unprocessed social updates: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get unprocessed interactions for AEI
     */
    private function getUnprocessedInteractions($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT i.*, c.name as contact_name, c.relationship_type, c.relationship_strength
                FROM aei_contact_interactions i
                JOIN aei_social_contacts c ON i.contact_id = c.id
                WHERE i.aei_id = ? AND i.processed_for_emotions = FALSE
                ORDER BY i.occurred_at ASC
            ");
            $stmt->execute([$aeiId]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error getting unprocessed interactions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Calculate emotional impact using existing 18-emotion system
     */
    private function calculateEmotionalImpact($interaction, $aeiId) {
        try {
            $contact = $this->getContact($interaction['contact_id']);
            $relationshipStrength = $contact['relationship_strength'] / 100; // Convert to 0-1 scale
            
            // Base emotional impacts per interaction type (maps to existing 18 emotions)
            $baseImpacts = [
                'shares_news' => ['joy' => 0.2, 'anticipation' => 0.1, 'trust' => 0.1],
                'asks_for_advice' => ['pride' => 0.3, 'trust' => 0.2, 'joy' => 0.1],
                'invites_to_activity' => ['joy' => 0.3, 'anticipation' => 0.2, 'trust' => 0.1],
                'shares_problem' => ['sadness' => 0.2, 'fear' => 0.1, 'trust' => 0.2],
                'celebrates_together' => ['joy' => 0.4, 'pride' => 0.2, 'love' => 0.1],
                'casual_chat' => ['joy' => 0.1, 'trust' => 0.1]
            ];
            
            $impact = $baseImpacts[$interaction['interaction_type']] ?? ['joy' => 0.1];
            
            // Scale based on relationship strength and type
            $relationshipMultiplier = $this->getRelationshipEmotionMultiplier($contact['relationship_type']);
            
            foreach ($impact as $emotion => $value) {
                $impact[$emotion] = $value * $relationshipStrength * $relationshipMultiplier;
            }
            
            return $impact;
        } catch (Exception $e) {
            error_log("Error calculating emotional impact: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get emotion multiplier based on relationship type
     */
    private function getRelationshipEmotionMultiplier($relationshipType) {
        $multipliers = [
            'close_friend' => 1.2,
            'family' => 1.3,
            'romantic_interest' => 1.5,
            'friend' => 1.0,
            'work_colleague' => 0.8,
            'acquaintance' => 0.6
        ];
        
        return $multipliers[$relationshipType] ?? 1.0;
    }
    
    /**
     * Merge emotional impacts
     */
    private function mergeEmotionalImpacts($total, $new) {
        foreach ($new as $emotion => $value) {
            $total[$emotion] = ($total[$emotion] ?? 0) + $value;
        }
        return $total;
    }
    
    /**
     * Mark interaction as processed
     */
    private function markInteractionAsProcessed($interactionId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE aei_contact_interactions 
                SET processed_for_emotions = TRUE 
                WHERE id = ?
            ");
            return $stmt->execute([$interactionId]);
        } catch (PDOException $e) {
            error_log("Error marking interaction as processed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update social context summary
     */
    private function updateSocialContextSummary($aeiId, $interactions) {
        try {
            // Create summary of recent interactions
            $summaryParts = [];
            $topicsToMention = [];
            
            foreach ($interactions as $interaction) {
                $summaryParts[] = "{$interaction['contact_name']}: {$interaction['interaction_context']}";
                
                // Add topics the AEI might want to mention
                if ($interaction['interaction_type'] === 'asks_for_advice') {
                    $topicsToMention[] = "Help {$interaction['contact_name']} with their situation";
                } elseif ($interaction['interaction_type'] === 'shares_problem') {
                    $topicsToMention[] = "Check on {$interaction['contact_name']}";
                } elseif ($interaction['interaction_type'] === 'celebrates_together') {
                    $topicsToMention[] = "Celebrate with {$interaction['contact_name']}";
                }
            }
            
            $summary = implode('. ', $summaryParts);
            $concerns = $this->generateCurrentConcerns($aeiId, $interactions);
            
            // Update or insert social context
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_social_context (
                    aei_id, recent_social_summary, current_social_concerns, 
                    topics_to_mention, unprocessed_interactions_count
                ) VALUES (?, ?, ?, ?, 0)
                ON DUPLICATE KEY UPDATE
                    recent_social_summary = VALUES(recent_social_summary),
                    current_social_concerns = VALUES(current_social_concerns),
                    topics_to_mention = VALUES(topics_to_mention),
                    unprocessed_interactions_count = 0,
                    last_social_update = CURRENT_TIMESTAMP
            ");
            
            return $stmt->execute([
                $aeiId,
                $summary,
                $concerns,
                json_encode($topicsToMention)
            ]);
        } catch (PDOException $e) {
            error_log("Error updating social context summary: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate current social concerns
     */
    private function generateCurrentConcerns($aeiId, $interactions) {
        $concerns = [];
        
        foreach ($interactions as $interaction) {
            if ($interaction['interaction_type'] === 'shares_problem') {
                $concerns[] = "Worried about {$interaction['contact_name']}";
            }
        }
        
        return implode(', ', $concerns);
    }
    
    /**
     * Generates social context for chat prompt using existing template system
     */
    public function generateSocialChatContext($aeiId) {
        try {
            $context = $this->getSocialContext($aeiId);
            $recentInteractions = $this->getRecentMentionableInteractions($aeiId, 7);
            
            $chatContext = "\n=== YOUR SOCIAL ENVIRONMENT ===\n";
            
            if ($context && $context['recent_social_summary']) {
                $chatContext .= "Current social situation: {$context['recent_social_summary']}\n";
            }
            
            if ($context && $context['current_social_concerns']) {
                $chatContext .= "What's on your mind: {$context['current_social_concerns']}\n";
            }
            
            if (!empty($recentInteractions)) {
                $chatContext .= "\nNews from your contacts:\n";
                foreach ($recentInteractions as $interaction) {
                    $contact = $this->getContact($interaction['contact_id']);
                    if ($contact) {
                        $chatContext .= "- {$contact['name']}: {$interaction['interaction_context']}\n";
                    }
                }
            }
            
            if ($context && !empty($context['topics_to_mention'])) {
                $topics = json_decode($context['topics_to_mention'], true);
                if (!empty($topics)) {
                    $chatContext .= "\nThings you might want to mention: " . implode(', ', $topics) . "\n";
                }
            }
            
            $chatContext .= "\nYou can naturally bring up your social life when relevant to the conversation.\n";
            
            return $chatContext;
        } catch (Exception $e) {
            error_log("Error generating social chat context: " . $e->getMessage());
            return "";
        }
    }
    
    /**
     * Get social context for AEI
     */
    public function getSocialContext($aeiId) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM aei_social_context WHERE aei_id = ?");
            $stmt->execute([$aeiId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting social context: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get recent mentionable interactions
     */
    private function getRecentMentionableInteractions($aeiId, $days) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM aei_contact_interactions 
                WHERE aei_id = ? 
                AND mentioned_in_chat = FALSE 
                AND occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
                ORDER BY occurred_at DESC
                LIMIT 5
            ");
            $stmt->execute([$aeiId, $days]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error getting recent mentionable interactions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get contact data
     */
    private function getContact($contactId) {
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
     * Get social summary for template
     */
    public function getSocialSummary($aeiId) {
        $context = $this->getSocialContext($aeiId);
        return $context ? ($context['recent_social_summary'] ?? '') : '';
    }
    
    /**
     * Get recent contact news for template
     */
    public function getRecentContactNews($aeiId) {
        $interactions = $this->getRecentMentionableInteractions($aeiId, 3);
        if (empty($interactions)) return '';
        
        $news = [];
        foreach ($interactions as $interaction) {
            $contact = $this->getContact($interaction['contact_id']);
            if ($contact) {
                $news[] = "{$contact['name']}: {$interaction['interaction_context']}";
            }
        }
        
        return implode('. ', $news);
    }
    
    /**
     * Get current concerns for template
     */
    public function getCurrentConcerns($aeiId) {
        $context = $this->getSocialContext($aeiId);
        return $context ? ($context['current_social_concerns'] ?? '') : '';
    }
    
    /**
     * Get important contact names for template
     */
    public function getImportantContactNames($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT name FROM aei_social_contacts 
                WHERE aei_id = ? AND is_active = TRUE 
                AND relationship_type IN ('close_friend', 'family', 'romantic_interest')
                ORDER BY relationship_strength DESC
                LIMIT 3
            ");
            $stmt->execute([$aeiId]);
            $contacts = $stmt->fetchAll();
            
            return implode(', ', array_column($contacts, 'name'));
        } catch (PDOException $e) {
            error_log("Error getting important contact names: " . $e->getMessage());
            return '';
        }
    }
    
    /**
     * Get social energy description for template
     */
    public function getSocialEnergyDescription($aeiId) {
        $context = $this->getSocialContext($aeiId);
        if (!$context) return 'moderate';
        
        $energy = $context['social_energy_level'] ?? 50;
        if ($energy >= 70) return 'high social energy';
        if ($energy <= 30) return 'low social energy';
        return 'moderate social energy';
    }
    
    /**
     * Get topics to mention for template
     */
    public function getTopicsToMention($aeiId) {
        $context = $this->getSocialContext($aeiId);
        if (!$context || !$context['topics_to_mention']) return '';
        
        $topics = json_decode($context['topics_to_mention'], true);
        return is_array($topics) ? implode(', ', $topics) : '';
    }
    
    /**
     * Mark recent interactions as mentioned in chat to avoid repetition
     * This should be called after each chat session where social context was used
     */
    public function markRecentInteractionsAsMentioned($aeiId) {
        try {
            // Get interactions that are currently being shown in social context
            // These are the ones that would be visible to the AEI in chat
            $mentionableInteractions = $this->getRecentMentionableInteractions($aeiId, 7);
            
            if (empty($mentionableInteractions)) {
                return 0;
            }
            
            // Mark these specific interactions as mentioned
            $interactionIds = array_column($mentionableInteractions, 'id');
            $placeholders = implode(',', array_fill(0, count($interactionIds), '?'));
            
            $stmt = $this->pdo->prepare("
                UPDATE aei_contact_interactions 
                SET mentioned_in_chat = TRUE 
                WHERE id IN ($placeholders)
            ");
            $affectedRows = $stmt->execute($interactionIds) ? $stmt->rowCount() : 0;
            
            if ($affectedRows > 0) {
                error_log("Marked {$affectedRows} specific social interactions as mentioned for AEI {$aeiId}");
            }
            
            return $affectedRows;
        } catch (PDOException $e) {
            error_log("Error marking social interactions as mentioned: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get social interactions that are currently relevant for chat context
     */
    public function getCurrentChatContextInteractions($aeiId) {
        try {
            return $this->getRecentMentionableInteractions($aeiId, 7);
        } catch (Exception $e) {
            error_log("Error getting current chat context interactions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Initialize social context for a new AEI
     */
    public function initializeSocialContext($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_social_context (aei_id) VALUES (?)
                ON DUPLICATE KEY UPDATE last_social_update = CURRENT_TIMESTAMP
            ");
            return $stmt->execute([$aeiId]);
        } catch (PDOException $e) {
            error_log("Error initializing social context: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Generate relationship coaching suggestions
     */
    public function generateRelationshipCoaching($aeiId) {
        try {
            $context = $this->getSocialContext($aeiId);
            $suggestions = [];
            
            // Social energy coaching
            if ($context && $context['social_energy_level'] < 30) {
                $suggestions[] = "You seem socially drained - consider some alone time to recharge";
            }
            
            // Emotional support coaching
            if ($context && $context['emotional_support_burden_score'] > 60) {
                $suggestions[] = "You've been supporting many friends - remember your own needs too";
            }
            
            return array_slice($suggestions, 0, 3);
            
        } catch (Exception $e) {
            error_log("Error generating relationship coaching: " . $e->getMessage());
            return [];
        }
    }
}
?>