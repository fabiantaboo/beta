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
        error_log("=== PROCESS ALL AEI SOCIAL STARTED ===");
        $globalResults = [
            'total_aeis' => 0,
            'processed_successfully' => 0,
            'failed_processing' => 0,
            'total_interactions' => 0,
            'errors' => [],
            'processing_details' => []
        ];
        
        try {
            error_log("Getting AEIs with social contacts...");
            $socialAEIs = $this->getAEIsWithSocialContacts();
            error_log("Found " . count($socialAEIs) . " AEIs for processing");
            $globalResults['total_aeis'] = count($socialAEIs);
            
            if (empty($socialAEIs)) {
                error_log("No AEIs found for social processing - returning early");
                return [
                    'success' => true,
                    'message' => 'No AEIs with social contacts found',
                    'details' => $globalResults
                ];
            }
            
            foreach ($socialAEIs as $aei) {
                try {
                    error_log("Processing AEI: {$aei['name']} (ID: {$aei['id']})");
                    
                    // Auto-initialize AEI social environment if needed
                    if (!$aei['social_initialized'] || $aei['contact_count'] == 0) {
                        error_log("Auto-initializing social environment for AEI: {$aei['name']}");
                        $initResult = $this->initializeAEISocialEnvironment($aei['id']);
                        if (!$initResult) {
                            error_log("Failed to initialize AEI {$aei['name']}");
                            $globalResults['failed_processing']++;
                            $globalResults['errors'][] = "Failed to auto-initialize social environment for {$aei['name']}";
                            continue;
                        }
                        error_log("Successfully initialized AEI {$aei['name']}");
                    }
                    
                    error_log("Starting processSingleAEI for {$aei['name']}");
                    $result = $this->processSingleAEI($aei['id']);
                    
                    error_log("processSingleAEI result for {$aei['name']}: " . json_encode($result));
                    
                    if ($result['success']) {
                        error_log("AEI {$aei['name']} processed successfully");
                        $globalResults['processed_successfully']++;
                        if (isset($result['details']['interactions_generated'])) {
                            $globalResults['total_interactions'] += $result['details']['interactions_generated'];
                        }
                        $globalResults['processing_details'][$aei['id']] = [
                            'name' => $aei['name'],
                            'status' => 'success',
                            'details' => $result['details'] ?? []
                        ];
                    } else {
                        error_log("AEI {$aei['name']} processing failed: " . ($result['error'] ?? 'Unknown error'));
                        $globalResults['failed_processing']++;
                        $globalResults['errors'][] = "AEI {$aei['name']}: " . ($result['error'] ?? 'Unknown error');
                        $globalResults['processing_details'][$aei['id']] = [
                            'name' => $aei['name'],
                            'status' => 'error',
                            'error' => $result['error'] ?? 'Unknown error',
                            'details' => $result['details'] ?? []
                        ];
                    }
                } catch (Exception $e) {
                    $globalResults['failed_processing']++;
                    $errorMsg = "Critical error processing AEI {$aei['name']}: " . $e->getMessage();
                    $globalResults['errors'][] = $errorMsg;
                    $globalResults['processing_details'][$aei['id']] = [
                        'name' => $aei['name'],
                        'status' => 'critical_error',
                        'error' => $e->getMessage()
                    ];
                    error_log($errorMsg);
                    error_log("Exception trace: " . $e->getTraceAsString());
                }
            }
            
            // Generate summary message
            $message = "Social processing for all AEIs completed!\n";
            $message .= "• {$globalResults['processed_successfully']}/{$globalResults['total_aeis']} AEIs processed successfully\n";
            $message .= "• {$globalResults['total_interactions']} interactions generated total\n";
            
            if ($globalResults['failed_processing'] > 0) {
                $message .= "• {$globalResults['failed_processing']} AEIs with errors";
            }
            
            $success = $globalResults['failed_processing'] == 0 || $globalResults['processed_successfully'] > 0;
            
            error_log("Background social processor: Processed {$globalResults['processed_successfully']}/{$globalResults['total_aeis']} AEIs successfully");
            error_log("=== PROCESS ALL AEI SOCIAL COMPLETED ===");
            
            return [
                'success' => $success,
                'message' => $message,
                'details' => $globalResults
            ];
            
        } catch (Exception $e) {
            error_log("Critical error in processAllAEISocial: " . $e->getMessage());
            $globalResults['errors'][] = 'Kritischer Fehler: ' . $e->getMessage();
            
            return [
                'success' => false,
                'error' => 'Kritischer Fehler beim Verarbeiten aller AEIs: ' . $e->getMessage(),
                'error_code' => 'GLOBAL_CRITICAL_ERROR',
                'details' => $globalResults
            ];
        }
    }
    
    /**
     * Get all AEIs with social contacts
     */
    private function getAEIsWithSocialContacts() {
        try {
            // First try to get AEIs with existing social contacts
            $stmt = $this->pdo->prepare("
                SELECT DISTINCT a.id, a.name, 
                       COALESCE(a.social_initialized, FALSE) as social_initialized,
                       COUNT(c.id) as contact_count
                FROM aeis a
                LEFT JOIN aei_social_contacts c ON a.id = c.aei_id AND c.is_active = TRUE
                WHERE a.is_active = TRUE 
                GROUP BY a.id, a.name, a.social_initialized
                HAVING contact_count > 0 OR social_initialized = FALSE
                ORDER BY contact_count DESC, a.name
            ");
            $stmt->execute();
            $result = $stmt->fetchAll();
            
            error_log("getAEIsWithSocialContacts found " . count($result) . " AEIs for processing");
            
            return $result;
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
                // 1. Evolve contact life (60% chance per processing cycle - much more frequent)
                if (mt_rand(1, 100) <= 60) {
                    $this->socialContactManager->evolveContactLife($contact['id']);
                }
                
                // 2. Much simpler and more frequent interaction generation
                // Base chance for ANY interaction happening (much higher!)
                $baseInteractionChance = 75; // 75% chance per contact per 6h cycle
                
                if (mt_rand(1, 100) <= $baseInteractionChance) {
                    // Determine who initiates (simplified)
                    $aeiInitiatesChance = 60; // 60% chance AEI initiates (much higher!)
                    
                    if (mt_rand(1, 100) <= $aeiInitiatesChance) {
                        // AEI initiates - MUCH MORE FREQUENT!
                        $interaction = $this->socialContactManager->generateAEIToContactInteraction(
                            $aeiId,
                            $contact['id']
                        );
                        error_log("AEI-initiated interaction generated for contact: {$contact['name']}");
                    } else {
                        // Contact initiates
                        $interaction = $this->socialContactManager->generateContactToAEIInteraction(
                            $contact['id'], 
                            $aeiId
                        );
                        error_log("Contact-initiated interaction generated from: {$contact['name']}");
                    }
                    
                    if ($interaction) {
                        $interactions[] = $interaction;
                    }
                }
            }
            
            // 4. Generate cross-contact interactions and group events
            $this->processAdvancedSocialDynamics($aeiId, $contacts);
            
            // 5. Generate social media activity
            $this->processSocialMediaActivity($aeiId, $contacts);
            
            // 6. Process seasonal/cultural events
            $this->processSeasonalCulturalEvents($aeiId);
            
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
     * Advanced probability calculation with psychological and temporal factors
     */
    private function calculateAdvancedContactProbability($contact) {
        // Much higher base frequencies for 6-hour intervals (4x per day)
        $baseFrequency = [
            'daily' => 0.35,        // ~70% chance per day across 4 intervals
            'weekly' => 0.15,       // ~45% chance per day
            'monthly' => 0.08,      // ~25% chance per day
            'rarely' => 0.03        // ~10% chance per day
        ];
        
        $baseProbability = $baseFrequency[$contact['contact_frequency']] ?? 0.12;
        
        // Relationship strength multiplier (higher impact)
        $relationshipMultiplier = 0.5 + ($contact['relationship_strength'] / 100) * 1.5;
        
        // Attachment style multipliers
        $attachmentMultiplier = $this->getAttachmentStyleContactMultiplier($contact['attachment_style']);
        
        // Time since last contact with more aggressive scaling
        $daysSinceLastContact = $this->getDaysSinceLastContact($contact['id']);
        $timeMultiplier = 1.0;
        
        if ($daysSinceLastContact == 0) {
            $timeMultiplier = 0.2; // Still some chance same day
        } elseif ($daysSinceLastContact == 1) {
            $timeMultiplier = 0.8; // Lower chance next day
        } elseif ($daysSinceLastContact >= 2) {
            $timeMultiplier = min(3.0, 1.0 + ($daysSinceLastContact - 1) * 0.3); // Increases significantly
        }
        
        // Life phase multiplier
        $lifePhaseMultiplier = $this->getLifePhaseContactMultiplier($contact['life_phase'] ?? 'maintenance');
        
        // Trust and intimacy boost
        $trustBoost = 1.0 + (($contact['trust_level'] ?? 0.5) * 0.5);
        $intimacyBoost = 1.0 + (($contact['intimacy_level'] ?? 0.5) * 0.3);
        
        // Seasonal modifier
        $seasonalMultiplier = $this->getSeasonalContactMultiplier();
        
        $finalProbability = min(0.95, $baseProbability * 
                                     $relationshipMultiplier * 
                                     $attachmentMultiplier * 
                                     $timeMultiplier * 
                                     $lifePhaseMultiplier * 
                                     $trustBoost * 
                                     $intimacyBoost * 
                                     $seasonalMultiplier);
        
        return max(0.01, $finalProbability); // Minimum 1% chance
    }
    
    /**
     * Calculate probability of AEI initiating contact with this person
     */
    private function calculateAEIInitiatedProbability($contact, $aeiId) {
        // Base probability significantly increased - AEIs should be more proactive!
        $baseFrequency = [
            'daily' => 0.45,        // 45% chance per 6-hour interval (much higher!)
            'weekly' => 0.25,       // 25% chance
            'monthly' => 0.15,      // 15% chance
            'rarely' => 0.08        // 8% chance
        ];
        
        $baseProbability = $baseFrequency[$contact['contact_frequency']] ?? 0.05;
        
        // Relationship strength matters more for AEI-initiated contact
        $relationshipMultiplier = 0.3 + ($contact['relationship_strength'] / 100) * 2.0;
        
        // AEIs are more likely to reach out to close relationships
        $relationshipBonus = [
            'family' => 1.5,
            'close_friend' => 1.3,
            'friend' => 1.0,
            'work_colleague' => 0.7,
            'acquaintance' => 0.4
        ];
        
        $typeMultiplier = $relationshipBonus[$contact['relationship_type']] ?? 1.0;
        
        // Time since last contact - AEIs more likely to reach out if it's been a while
        $daysSinceLastContact = $this->getDaysSinceLastContact($contact['id']);
        $timeMultiplier = 1.0;
        
        if ($daysSinceLastContact == 0) {
            $timeMultiplier = 0.1; // Very low chance same day
        } elseif ($daysSinceLastContact >= 3) {
            $timeMultiplier = min(2.5, 1.0 + ($daysSinceLastContact - 2) * 0.4); // Increases significantly
        }
        
        // Check for unresolved issues or celebrations that might prompt outreach
        $contextualBonus = 1.0;
        
        // If contact shared problems recently, AEI might follow up
        $recentProblems = $this->hasRecentProblems($contact['id']);
        if ($recentProblems) {
            $contextualBonus += 0.5;
        }
        
        // If contact had good news, AEI might want to celebrate
        $recentGoodNews = $this->hasRecentGoodNews($contact['id']);
        if ($recentGoodNews) {
            $contextualBonus += 0.3;
        }
        
        $finalProbability = min(0.80, $baseProbability * 
                                     $relationshipMultiplier * 
                                     $typeMultiplier * 
                                     $timeMultiplier * 
                                     $contextualBonus);
        
        return max(0.005, $finalProbability); // Minimum 0.5% chance
    }
    
    /**
     * Calculate chance for spontaneous interactions based on attachment style
     */
    private function calculateSpontaneousInteractionChance($contact) {
        $attachmentBonuses = [
            'anxious' => 0.25,      // High need for contact
            'secure' => 0.15,       // Balanced approach
            'avoidant' => 0.05,     // Lower spontaneous contact
            'disorganized' => 0.18  // Unpredictable but frequent
        ];
        
        $baseChance = $attachmentBonuses[$contact['attachment_style']] ?? 0.12;
        
        // Current emotional state modifier
        $emotionalStateMultiplier = 1.0;
        if (!empty($contact['current_emotional_state'])) {
            $emotions = json_decode($contact['current_emotional_state'], true);
            if ($emotions) {
                // Negative emotions increase contact need
                $negativeScore = ($emotions['sadness'] ?? 0) + ($emotions['fear'] ?? 0) + ($emotions['anger'] ?? 0);
                $emotionalStateMultiplier = 1.0 + ($negativeScore * 0.8);
            }
        }
        
        return min(0.35, $baseChance * $emotionalStateMultiplier);
    }
    
    /**
     * Get contact multiplier based on attachment style
     */
    private function getAttachmentStyleContactMultiplier($attachmentStyle) {
        $multipliers = [
            'anxious' => 1.4,       // More frequent contact need
            'secure' => 1.2,        // Healthy contact patterns
            'avoidant' => 0.8,      // Less frequent contact
            'disorganized' => 1.1   // Inconsistent but average
        ];
        
        return $multipliers[$attachmentStyle] ?? 1.0;
    }
    
    /**
     * Get contact multiplier based on life phase
     */
    private function getLifePhaseContactMultiplier($lifePhase) {
        $multipliers = [
            'exploration' => 1.3,   // Young, more social
            'establishment' => 1.1, // Building relationships
            'maintenance' => 1.0,   // Stable social patterns
            'legacy' => 0.9        // More selective socializing
        ];
        
        return $multipliers[$lifePhase] ?? 1.0;
    }
    
    /**
     * Get seasonal contact multiplier
     */
    private function getSeasonalContactMultiplier() {
        $month = date('n');
        
        // Winter (Dec, Jan, Feb) - more contact seeking
        if (in_array($month, [12, 1, 2])) return 1.3;
        
        // Spring (Mar, Apr, May) - moderate increase
        if (in_array($month, [3, 4, 5])) return 1.1;
        
        // Summer (Jun, Jul, Aug) - baseline
        if (in_array($month, [6, 7, 8])) return 1.0;
        
        // Fall (Sep, Oct, Nov) - slight increase
        return 1.15;
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
     * Check if contact has shared problems recently
     */
    private function hasRecentProblems($contactId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as problem_count
                FROM aei_contact_interactions 
                WHERE contact_id = ? 
                AND interaction_type = 'shares_problem'
                AND occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$contactId]);
            $result = $stmt->fetch();
            
            return ($result['problem_count'] ?? 0) > 0;
        } catch (PDOException $e) {
            error_log("Error checking recent problems: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if contact has shared good news recently
     */
    private function hasRecentGoodNews($contactId) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as good_news_count
                FROM aei_contact_interactions 
                WHERE contact_id = ? 
                AND interaction_type IN ('shares_news', 'celebrates_together')
                AND occurred_at >= DATE_SUB(NOW(), INTERVAL 5 DAY)
            ");
            $stmt->execute([$contactId]);
            $result = $stmt->fetch();
            
            return ($result['good_news_count'] ?? 0) > 0;
        } catch (PDOException $e) {
            error_log("Error checking recent good news: " . $e->getMessage());
            return false;
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
        error_log("=== PROCESS SINGLE AEI STARTED: $aeiId ===");
        $processingDetails = [
            'aei_id' => $aeiId,
            'contacts_processed' => 0,
            'interactions_generated' => 0,
            'social_media_posts' => 0,
            'group_events_created' => 0,
            'cross_contact_relationships' => 0,
            'errors' => [],
            'warnings' => []
        ];
        
        try {
            // Validate AEI exists
            error_log("Validating AEI exists: $aeiId");
            $stmt = $this->pdo->prepare("SELECT id, name, social_initialized FROM aeis WHERE id = ? AND is_active = TRUE");
            $stmt->execute([$aeiId]);
            $aei = $stmt->fetch();
            
            if (!$aei) {
                error_log("AEI not found or inactive: $aeiId");
                return [
                    'success' => false,
                    'error' => 'AEI not found or inactive',
                    'error_code' => 'AEI_NOT_FOUND',
                    'details' => $processingDetails
                ];
            }
            
            error_log("Found AEI: {$aei['name']}, Social initialized: " . ($aei['social_initialized'] ? 'YES' : 'NO'));
            
            // First ensure social environment is initialized
            if (!$this->socialContactManager->isAEISocialInitialized($aeiId)) {
                try {
                    $initResult = $this->initializeAEISocialEnvironment($aeiId);
                    if (!$initResult) {
                        return [
                            'success' => false,
                            'error' => 'Konnte soziales Umfeld nicht initialisieren',
                            'error_code' => 'SOCIAL_INIT_FAILED',
                            'details' => $processingDetails
                        ];
                    }
                    $processingDetails['warnings'][] = 'Soziales Umfeld wurde erst initialisiert';
                } catch (Exception $e) {
                    return [
                        'success' => false,
                        'error' => 'Fehler beim Initialisieren des sozialen Umfelds: ' . $e->getMessage(),
                        'error_code' => 'SOCIAL_INIT_ERROR',
                        'details' => $processingDetails
                    ];
                }
            }
            
            // Get contacts for processing
            $contacts = $this->socialContactManager->getAEIContacts($aeiId);
            $processingDetails['contacts_processed'] = count($contacts);
            
            if (empty($contacts)) {
                $processingDetails['warnings'][] = 'Keine aktiven Kontakte gefunden';
                return [
                    'success' => true,
                    'message' => 'Social Processing abgeschlossen, aber keine Kontakte vorhanden',
                    'details' => $processingDetails
                ];
            }
            
            // Process each component with detailed error tracking
            $interactions = [];
            
            // 1. Process contact interactions - NOW WITH AEI INITIATION!
            try {
                foreach ($contacts as $contact) {
                    // Evolve contact life
                    if (mt_rand(1, 100) <= 60) {
                        $this->socialContactManager->evolveContactLife($contact['id']);
                    }
                    
                    // NEW: Much simpler and more frequent interaction generation
                    // Base chance for ANY interaction happening (much higher!)
                    $baseInteractionChance = 75; // 75% chance per contact per 6h cycle
                    
                    if (mt_rand(1, 100) <= $baseInteractionChance) {
                        // Determine who initiates (simplified)
                        $aeiInitiatesChance = 60; // 60% chance AEI initiates (much higher!)
                        
                        if (mt_rand(1, 100) <= $aeiInitiatesChance) {
                            // AEI initiates - MUCH MORE FREQUENT!
                            $interaction = $this->socialContactManager->generateAEIToContactInteraction(
                                $aeiId,
                                $contact['id']
                            );
                            error_log("AEI-initiated interaction generated for contact: {$contact['name']}");
                        } else {
                            // Contact initiates
                            $interaction = $this->socialContactManager->generateContactToAEIInteraction(
                                $contact['id'], 
                                $aeiId
                            );
                            error_log("Contact-initiated interaction generated from: {$contact['name']}");
                        }
                        
                        if ($interaction) {
                            $interactions[] = $interaction;
                            $processingDetails['interactions_generated']++;
                        }
                    }
                }
            } catch (Exception $e) {
                $processingDetails['errors'][] = 'Kontakt-Interaktionen: ' . $e->getMessage();
            }
            
            // 2. Process advanced social dynamics
            try {
                if (count($contacts) >= 2 && mt_rand(1, 100) <= 25) {
                    $this->generateCrossContactRelationship($aeiId, $contacts);
                    $processingDetails['cross_contact_relationships']++;
                }
                
                if (count($contacts) >= 3 && mt_rand(1, 100) <= 15) {
                    $this->generateGroupEvent($aeiId, $contacts);
                    $processingDetails['group_events_created']++;
                }
                
                if (mt_rand(1, 100) <= 20) {
                    $this->evolveRelationshipDynamics($aeiId, $contacts);
                }
            } catch (Exception $e) {
                $processingDetails['errors'][] = 'Erweiterte Dynamiken: ' . $e->getMessage();
            }
            
            // 3. Process social media activity
            try {
                foreach ($contacts as $contact) {
                    if (mt_rand(1, 100) <= 30) {
                        $this->generateSocialMediaPost($contact, $aeiId);
                        $processingDetails['social_media_posts']++;
                    }
                }
            } catch (Exception $e) {
                $processingDetails['errors'][] = 'Social Media: ' . $e->getMessage();
            }
            
            // 4. Process seasonal events
            try {
                $this->processSeasonalCulturalEvents($aeiId);
            } catch (Exception $e) {
                $processingDetails['errors'][] = 'Saisonale Events: ' . $e->getMessage();
            }
            
            // 5. Update social context
            if (!empty($interactions)) {
                try {
                    $this->updateAEISocialContext($aeiId, $interactions);
                } catch (Exception $e) {
                    $processingDetails['errors'][] = 'Social Context Update: ' . $e->getMessage();
                }
            }
            
            // Generate detailed success message
            $message = "Social Processing erfolgreich für {$aei['name']}!\n";
            $message .= "• {$processingDetails['interactions_generated']} neue Interaktionen\n";
            $message .= "• {$processingDetails['social_media_posts']} Social Media Posts\n";
            $message .= "• {$processingDetails['group_events_created']} Gruppen-Events\n";
            $message .= "• {$processingDetails['cross_contact_relationships']} neue Cross-Contact Beziehungen";
            
            return [
                'success' => true,
                'message' => $message,
                'details' => $processingDetails
            ];
            
        } catch (Exception $e) {
            error_log("Critical error in processSingleAEI: " . $e->getMessage());
            $processingDetails['errors'][] = 'Kritischer Fehler: ' . $e->getMessage();
            
            return [
                'success' => false,
                'error' => 'Kritischer Fehler beim Social Processing: ' . $e->getMessage(),
                'error_code' => 'CRITICAL_ERROR',
                'details' => $processingDetails
            ];
        }
    }
    
    /**
     * Process advanced social dynamics (cross-contact relationships, group events)
     */
    private function processAdvancedSocialDynamics($aeiId, $contacts) {
        try {
            // 25% chance to create/update cross-contact relationships
            if (mt_rand(1, 100) <= 25 && count($contacts) >= 2) {
                $this->generateCrossContactRelationship($aeiId, $contacts);
            }
            
            // 15% chance to create group event
            if (mt_rand(1, 100) <= 15 && count($contacts) >= 3) {
                $this->generateGroupEvent($aeiId, $contacts);
            }
            
            // 20% chance to update relationship dynamics
            if (mt_rand(1, 100) <= 20) {
                $this->evolveRelationshipDynamics($aeiId, $contacts);
            }
            
        } catch (Exception $e) {
            error_log("Error processing advanced social dynamics: " . $e->getMessage());
        }
    }
    
    /**
     * Process social media activity simulation
     */
    private function processSocialMediaActivity($aeiId, $contacts) {
        try {
            foreach ($contacts as $contact) {
                // 30% chance for contact to post something
                if (mt_rand(1, 100) <= 30) {
                    $this->generateSocialMediaPost($contact, $aeiId);
                }
            }
        } catch (Exception $e) {
            error_log("Error processing social media activity: " . $e->getMessage());
        }
    }
    
    /**
     * Process seasonal and cultural events
     */
    private function processSeasonalCulturalEvents($aeiId) {
        try {
            // 10% chance for seasonal event to affect social dynamics
            if (mt_rand(1, 100) <= 10) {
                $this->generateSeasonalEvent($aeiId);
            }
            
            // Update seasonal context if needed
            $this->updateSeasonalContext($aeiId);
            
        } catch (Exception $e) {
            error_log("Error processing seasonal/cultural events: " . $e->getMessage());
        }
    }
    
    /**
     * Generate cross-contact relationship
     */
    private function generateCrossContactRelationship($aeiId, $contacts) {
        try {
            // Pick two random contacts
            $contactA = $contacts[array_rand($contacts)];
            $contactB = $contacts[array_rand($contacts)];
            
            if ($contactA['id'] == $contactB['id']) return;
            
            // Check if relationship already exists
            $stmt = $this->pdo->prepare("
                SELECT id FROM aei_contact_relationships 
                WHERE aei_id = ? AND 
                ((contact_a_id = ? AND contact_b_id = ?) OR 
                 (contact_a_id = ? AND contact_b_id = ?))
            ");
            $stmt->execute([$aeiId, $contactA['id'], $contactB['id'], $contactB['id'], $contactA['id']]);
            
            if ($stmt->fetch()) return; // Already exists
            
            // Create new relationship
            $relationshipTypes = ['friends', 'acquaintances', 'colleagues', 'rivals', 'family_friends'];
            $relationshipType = $relationshipTypes[array_rand($relationshipTypes)];
            
            $dramaPotential = $relationshipType === 'rivals' || mt_rand(1, 100) <= 15;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_contact_relationships (
                    aei_id, contact_a_id, contact_b_id, relationship_type,
                    relationship_strength, creates_drama_potential,
                    affects_aei_interactions, mutual_awareness_level
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $aeiId,
                $contactA['id'],
                $contactB['id'], 
                $relationshipType,
                mt_rand(20, 90), // Random strength
                $dramaPotential,
                mt_rand(1, 100) <= 40, // 40% chance to affect AEI interactions
                mt_rand(1, 100) <= 60  // 60% chance for high mutual awareness
            ]);
            
        } catch (Exception $e) {
            error_log("Error generating cross-contact relationship: " . $e->getMessage());
        }
    }
    
    /**
     * Generate group event
     */
    private function generateGroupEvent($aeiId, $contacts) {
        try {
            $eventTypes = [
                'birthday_party', 'dinner_gathering', 'movie_night', 'game_night',
                'wedding', 'graduation', 'holiday_celebration', 'work_event',
                'casual_meetup', 'celebration'
            ];
            
            $eventType = $eventTypes[array_rand($eventTypes)];
            
            // Select 3-5 random participants
            $participantCount = mt_rand(3, min(5, count($contacts)));
            $participants = array_rand($contacts, $participantCount);
            if (!is_array($participants)) $participants = [$participants];
            
            $participantNames = [];
            foreach ($participants as $index) {
                $participantNames[] = $contacts[$index]['name'];
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_group_events (
                    aei_id, event_type, event_description, participants_count,
                    participant_contacts, social_dynamics_created
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $description = $this->generateEventDescription($eventType, $participantNames);
            
            $stmt->execute([
                $aeiId,
                $eventType,
                $description,
                count($participantNames),
                json_encode($participantNames),
                mt_rand(1, 100) <= 60 // 60% chance to create new dynamics
            ]);
            
        } catch (Exception $e) {
            error_log("Error generating group event: " . $e->getMessage());
        }
    }
    
    /**
     * Generate social media post
     */
    private function generateSocialMediaPost($contact, $aeiId) {
        try {
            $platforms = ['instagram', 'facebook', 'twitter', 'linkedin'];
            $platform = $platforms[array_rand($platforms)];
            
            // Simple post content generation
            $postTypes = [
                'life_update', 'photo_share', 'thought_share', 'event_announcement',
                'mood_post', 'achievement', 'question', 'memory_share'
            ];
            $postType = $postTypes[array_rand($postTypes)];
            
            $content = $this->generateSocialMediaContent($contact, $postType);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_social_media_simulation (
                    aei_id, contact_id, platform, post_type, post_content,
                    likes_count, comments_count, shares_count,
                    aei_reaction, aei_comment
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // 70% chance AEI reacts to the post
            $aeiReacts = mt_rand(1, 100) <= 70;
            $aeiComment = null;
            
            if ($aeiReacts && mt_rand(1, 100) <= 30) { // 30% of reactions include comment
                $aeiComment = $this->generateAEIComment($content);
            }
            
            $stmt->execute([
                $aeiId,
                $contact['id'],
                $platform,
                $postType,
                $content,
                mt_rand(5, 50), // Likes
                mt_rand(0, 12), // Comments
                mt_rand(0, 8),  // Shares
                $aeiReacts,
                $aeiComment
            ]);
            
        } catch (Exception $e) {
            error_log("Error generating social media post: " . $e->getMessage());
        }
    }
    
    /**
     * Simple social media content generator
     */
    private function generateSocialMediaContent($contact, $postType) {
        $templates = [
            'life_update' => [
                "Starting a new chapter in my life!",
                "Exciting changes ahead 🌟",
                "Life has been keeping me busy lately",
                "Grateful for all the opportunities coming my way"
            ],
            'photo_share' => [
                "Beautiful day out! ☀️",
                "Captured this amazing moment",
                "Love this view 📸",
                "Perfect lighting today"
            ],
            'thought_share' => [
                "Sometimes you just need to appreciate the small things",
                "Random thought: life is pretty amazing",
                "Reflecting on how much I've grown this year",
                "Feeling philosophical today"
            ],
            'mood_post' => [
                "Feeling blessed today 💕",
                "Having one of those great days!",
                "Mood: optimistic ✨",
                "Feeling grateful for good friends"
            ]
        ];
        
        $options = $templates[$postType] ?? $templates['life_update'];
        return $options[array_rand($options)];
    }
    
    /**
     * Generate AEI comment on social media
     */
    private function generateAEIComment($postContent) {
        $responses = [
            "Love this! 💕",
            "So happy for you!",
            "This made me smile 😊",
            "Beautiful post!",
            "Thanks for sharing this",
            "Hope you're doing well!",
            "Great to see you happy!"
        ];
        
        return $responses[array_rand($responses)];
    }
    
    /**
     * Generate event description
     */
    private function generateEventDescription($eventType, $participants) {
        $participantList = implode(', ', array_slice($participants, 0, 3));
        if (count($participants) > 3) {
            $participantList .= ' and others';
        }
        
        $descriptions = [
            'birthday_party' => "Birthday celebration with $participantList - great fun and lots of laughs!",
            'dinner_gathering' => "Lovely dinner gathering with $participantList - amazing food and conversation.",
            'movie_night' => "Movie night with $participantList - watched a great film together.",
            'game_night' => "Fun game night with $participantList - competitive but friendly!",
            'casual_meetup' => "Casual meetup with $participantList - good to catch up with everyone."
        ];
        
        return $descriptions[$eventType] ?? "Social gathering with $participantList - great time together.";
    }
    
    /**
     * Evolve relationship dynamics
     */
    private function evolveRelationshipDynamics($aeiId, $contacts) {
        try {
            foreach ($contacts as $contact) {
                // Small chance to update trust/intimacy levels
                if (mt_rand(1, 100) <= 15) {
                    $trustChange = mt_rand(-5, 10) / 100; // Slight bias toward positive
                    $intimacyChange = mt_rand(-3, 8) / 100;
                    
                    $newTrust = max(0, min(1, ($contact['trust_level'] ?? 0.5) + $trustChange));
                    $newIntimacy = max(0, min(1, ($contact['intimacy_level'] ?? 0.5) + $intimacyChange));
                    
                    $stmt = $this->pdo->prepare("
                        UPDATE aei_social_contacts 
                        SET trust_level = ?, intimacy_level = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$newTrust, $newIntimacy, $contact['id']]);
                }
            }
        } catch (Exception $e) {
            error_log("Error evolving relationship dynamics: " . $e->getMessage());
        }
    }
    
    /**
     * Generate seasonal event
     */
    private function generateSeasonalEvent($aeiId) {
        $month = date('n');
        $season = $this->getCurrentSeason($month);
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_seasonal_cultural_context (aei_id, current_season, cultural_period, social_energy_modifier, context_description)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    current_season = VALUES(current_season),
                    cultural_period = VALUES(cultural_period),
                    social_energy_modifier = VALUES(social_energy_modifier),
                    context_description = VALUES(context_description)
            ");
            
            $culturalPeriod = $this->getCulturalPeriod($month);
            $energyModifier = $this->getSeasonalContactMultiplier();
            $description = "Seasonal influence: $season brings $culturalPeriod energy to social interactions.";
            
            $stmt->execute([$aeiId, $season, $culturalPeriod, $energyModifier, $description]);
            
        } catch (Exception $e) {
            error_log("Error generating seasonal event: " . $e->getMessage());
        }
    }
    
    /**
     * Update seasonal context
     */
    private function updateSeasonalContext($aeiId) {
        // Implementation for updating seasonal context
        // This would be called regularly to keep context current
    }
    
    /**
     * Get current season
     */
    private function getCurrentSeason($month) {
        if (in_array($month, [12, 1, 2])) return 'winter';
        if (in_array($month, [3, 4, 5])) return 'spring';
        if (in_array($month, [6, 7, 8])) return 'summer';
        return 'fall';
    }
    
    /**
     * Get cultural period
     */
    private function getCulturalPeriod($month) {
        $periods = [
            1 => 'new_year',
            2 => 'winter_reflection', 
            3 => 'spring_renewal',
            4 => 'spring_growth',
            5 => 'spring_celebration',
            6 => 'summer_beginning',
            7 => 'summer_peak',
            8 => 'summer_endings',
            9 => 'autumn_transitions',
            10 => 'autumn_harvest',
            11 => 'autumn_reflection',
            12 => 'winter_holidays'
        ];
        
        return $periods[$month] ?? 'normal';
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