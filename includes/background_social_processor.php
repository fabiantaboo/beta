<?php

require_once __DIR__ . '/social_contact_manager.php';
require_once __DIR__ . '/aei_social_context.php';
require_once __DIR__ . '/functions.php';

class BackgroundSocialProcessor {
    private $socialContactManager;
    private $aeiSocialContext;
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->socialContactManager = new SocialContactManager($pdo);
        $this->aeiSocialContext = new AEISocialContext($pdo);
    }
    
    /**
     * Main processing for all AEIs with social environments
     */
    public function processAllAEISocial() {
        try {
            $socialAEIs = $this->getAEIsWithSocialContacts();
            $processedCount = 0;
            
            foreach ($socialAEIs as $aei) {
                try {
                    $this->processAEISocialLife($aei['id']);
                    $processedCount++;
                } catch (Exception $e) {
                    error_log("Error processing social life for AEI {$aei['id']}: " . $e->getMessage());
                    continue;
                }
            }
            
            error_log("Background social processor: Processed $processedCount AEIs");
            return $processedCount;
        } catch (Exception $e) {
            error_log("Error in background social processor: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get all AEIs with social contacts
     */
    private function getAEIsWithSocialContacts() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT a.id, a.name 
                FROM aeis a
                JOIN aei_social_contacts c ON a.id = c.aei_id
                WHERE a.is_active = TRUE 
                AND a.social_initialized = TRUE
                AND c.is_active = TRUE
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error getting AEIs with social contacts: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Processes the social "life" of an AEI
     */
    public function processAEISocialLife($aeiId) {
        try {
            $contacts = $this->socialContactManager->getAEIContacts($aeiId);
            $interactions = [];
            
            foreach ($contacts as $contact) {
                // 1. Evolve contact life (25% chance per processing cycle)
                if (mt_rand(1, 100) <= 25) {
                    $this->socialContactManager->evolveContactLife($contact['id']);
                }
                
                // 2. Check if contact wants to reach out to AEI
                $contactProbability = $this->calculateContactProbability($contact);
                
                if (mt_rand(1, 100) <= ($contactProbability * 100)) {
                    $interaction = $this->socialContactManager->generateContactToAEIInteraction(
                        $contact['id'], 
                        $aeiId
                    );
                    
                    if ($interaction) {
                        $interactions[] = $interaction;
                    }
                }
            }
            
            // 3. Update AEI social context if there were new interactions
            if (!empty($interactions)) {
                $this->updateAEISocialContext($aeiId, $interactions);
            }
            
            return count($interactions);
        } catch (Exception $e) {
            error_log("Error processing AEI social life: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Calculates probability that a contact reaches out
     */
    private function calculateContactProbability($contact) {
        // Base frequency probabilities per day
        $baseFrequency = [
            'daily' => 0.8,
            'weekly' => 0.3,
            'monthly' => 0.1,
            'rarely' => 0.05
        ];
        
        $baseProbability = $baseFrequency[$contact['contact_frequency']] ?? 0.2;
        
        // Adjust based on relationship strength
        $relationshipMultiplier = $contact['relationship_strength'] / 100;
        
        // Time since last contact - increase probability if it's been a while
        $daysSinceLastContact = $this->getDaysSinceLastContact($contact['id']);
        $timeMultiplier = min(2.0, ($daysSinceLastContact + 1) / 7); // Max 2x after a week
        
        // Reduce if contacted recently
        if ($daysSinceLastContact < 1) {
            $timeMultiplier = 0.1;
        }
        
        $finalProbability = min(1.0, $baseProbability * $relationshipMultiplier * $timeMultiplier);
        
        return $finalProbability;
    }
    
    /**
     * Get days since last contact
     */
    private function getDaysSinceLastContact($contactId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COALESCE(DATEDIFF(NOW(), last_contact_initiated), 30) as days
                FROM aei_social_contacts 
                WHERE id = ?
            ");
            $stmt->execute([$contactId]);
            $result = $stmt->fetch();
            
            return $result ? (int)$result['days'] : 30;
        } catch (PDOException $e) {
            error_log("Error getting days since last contact: " . $e->getMessage());
            return 30;
        }
    }
    
    /**
     * Update AEI social context with new interactions
     */
    private function updateAEISocialContext($aeiId, $interactions) {
        try {
            // Update interaction count
            $stmt = $this->pdo->prepare("
                UPDATE aei_social_context 
                SET unprocessed_interactions_count = unprocessed_interactions_count + ?
                WHERE aei_id = ?
            ");
            $stmt->execute([count($interactions), $aeiId]);
            
            // Initialize social context if it doesn't exist
            $this->aeiSocialContext->initializeSocialContext($aeiId);
            
            return true;
        } catch (PDOException $e) {
            error_log("Error updating AEI social context: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Initialize social environment for an AEI
     */
    public function initializeAEISocialEnvironment($aeiId) {
        try {
            // Check if already initialized
            if ($this->socialContactManager->isAEISocialInitialized($aeiId)) {
                return true;
            }
            
            // Generate initial contacts
            $contacts = $this->socialContactManager->generateInitialContactsForAEI($aeiId);
            
            // Initialize social context
            $this->aeiSocialContext->initializeSocialContext($aeiId);
            
            error_log("Initialized social environment for AEI $aeiId with " . count($contacts) . " contacts");
            return count($contacts) > 0;
        } catch (Exception $e) {
            error_log("Error initializing AEI social environment: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get social statistics for an AEI
     */
    public function getAEISocialStatistics($aeiId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(DISTINCT c.id) as total_contacts,
                    COUNT(DISTINCT CASE WHEN i.occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN i.id END) as recent_interactions,
                    AVG(c.relationship_strength) as avg_relationship_strength,
                    COUNT(DISTINCT CASE WHEN i.processed_for_emotions = FALSE THEN i.id END) as unprocessed_interactions
                FROM aei_social_contacts c
                LEFT JOIN aei_contact_interactions i ON c.id = i.contact_id
                WHERE c.aei_id = ? AND c.is_active = TRUE
            ");
            $stmt->execute([$aeiId]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error getting social statistics: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Clean up old interactions (keep only last 30 days)
     */
    public function cleanupOldInteractions() {
        try {
            $stmt = $this->pdo->prepare("
                DELETE FROM aei_contact_interactions 
                WHERE occurred_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND processed_for_emotions = TRUE
                AND mentioned_in_chat = TRUE
            ");
            $deletedRows = $stmt->execute() ? $stmt->rowCount() : 0;
            
            if ($deletedRows > 0) {
                error_log("Cleaned up $deletedRows old social interactions");
            }
            
            return $deletedRows;
        } catch (PDOException $e) {
            error_log("Error cleaning up old interactions: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Process social updates for a specific AEI (useful for manual triggers)
     */
    public function processSingleAEI($aeiId) {
        try {
            // First ensure social environment is initialized
            if (!$this->socialContactManager->isAEISocialInitialized($aeiId)) {
                $this->initializeAEISocialEnvironment($aeiId);
            }
            
            // Process social life
            $interactionCount = $this->processAEISocialLife($aeiId);
            
            return [
                'success' => true,
                'interactions_generated' => $interactionCount
            ];
        } catch (Exception $e) {
            error_log("Error processing single AEI social updates: " . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get comprehensive social analytics for an AEI
     */
    public function getComprehensiveSocialAnalytics($aeiId) {
        try {
            $basicStats = $this->getAEISocialStatistics($aeiId);
            $advancedStats = $this->socialContactManager->getAdvancedSocialStatistics($aeiId);
            
            // Get social context
            $stmt = $this->pdo->prepare("SELECT * FROM aei_social_context WHERE aei_id = ?");
            $stmt->execute([$aeiId]);
            $socialContext = $stmt->fetch();
            
            return [
                'basic_stats' => $basicStats,
                'advanced_stats' => $advancedStats,
                'social_context' => $socialContext
            ];
            
        } catch (Exception $e) {
            error_log("Error getting comprehensive social analytics: " . $e->getMessage());
            return null;
        }
    }
}
?>