<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

define('DB_HOST', 'localhost');
define('DB_NAME', 'ayuni_beta');
define('DB_USER', 'root');
define('DB_PASS', '');

function createDatabaseIfNotExists() {
    try {
        $pdo_temp = new PDO(
            "mysql:host=" . DB_HOST . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $pdo_temp->exec("CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (PDOException $e) {
        error_log("Failed to create database: " . $e->getMessage());
    }
}

function createTablesIfNotExist($pdo) {
    $tables = [
        'beta_codes' => "CREATE TABLE IF NOT EXISTS beta_codes (
            code VARCHAR(20) PRIMARY KEY,
            first_name VARCHAR(100) NULL,
            email VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL,
            used_by VARCHAR(32) NULL,
            is_active BOOLEAN DEFAULT TRUE
        )",
        'users' => "CREATE TABLE IF NOT EXISTS users (
            id VARCHAR(32) PRIMARY KEY,
            email VARCHAR(255) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NULL,
            first_name VARCHAR(100) NOT NULL,
            beta_code VARCHAR(20) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_onboarded BOOLEAN DEFAULT FALSE,
            is_admin BOOLEAN DEFAULT FALSE,
            
            -- Onboarding profile data
            gender VARCHAR(50) NULL,
            birth_date DATE NULL,
            profession VARCHAR(255) NULL,
            hobbies TEXT NULL,
            sexual_orientation VARCHAR(100) NULL,
            daily_rituals TEXT NULL,
            life_goals TEXT NULL,
            beliefs TEXT NULL,
            partner_qualities TEXT NULL,
            additional_info TEXT NULL,
            timezone VARCHAR(100) DEFAULT 'UTC',
            
            FOREIGN KEY (beta_code) REFERENCES beta_codes(code)
        )",
        'aeis' => "CREATE TABLE IF NOT EXISTS aeis (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            name VARCHAR(100) NOT NULL,
            age VARCHAR(50) NULL,
            gender VARCHAR(50) NULL,
            personality TEXT,
            appearance_description TEXT,
            background TEXT NULL,
            interests TEXT NULL,
            communication_style TEXT NULL,
            quirks TEXT NULL,
            occupation VARCHAR(200) NULL,
            goals VARCHAR(200) NULL,
            relationship_context TEXT NULL,
            system_prompt TEXT NULL,
            response_length TINYINT DEFAULT 2,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_id (user_id)
        )",
        'chat_sessions' => "CREATE TABLE IF NOT EXISTS chat_sessions (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            aei_id VARCHAR(32) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            -- Current AEI Emotional State
            aei_joy DECIMAL(3,2) DEFAULT 0.5,
            aei_sadness DECIMAL(3,2) DEFAULT 0.5,
            aei_fear DECIMAL(3,2) DEFAULT 0.5,
            aei_anger DECIMAL(3,2) DEFAULT 0.5,
            aei_surprise DECIMAL(3,2) DEFAULT 0.5,
            aei_disgust DECIMAL(3,2) DEFAULT 0.5,
            aei_trust DECIMAL(3,2) DEFAULT 0.5,
            aei_anticipation DECIMAL(3,2) DEFAULT 0.5,
            aei_shame DECIMAL(3,2) DEFAULT 0.5,
            aei_love DECIMAL(3,2) DEFAULT 0.5,
            aei_contempt DECIMAL(3,2) DEFAULT 0.5,
            aei_loneliness DECIMAL(3,2) DEFAULT 0.5,
            aei_pride DECIMAL(3,2) DEFAULT 0.5,
            aei_envy DECIMAL(3,2) DEFAULT 0.5,
            aei_nostalgia DECIMAL(3,2) DEFAULT 0.5,
            aei_gratitude DECIMAL(3,2) DEFAULT 0.5,
            aei_frustration DECIMAL(3,2) DEFAULT 0.5,
            aei_boredom DECIMAL(3,2) DEFAULT 0.5,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            INDEX idx_user_aei (user_id, aei_id)
        )",
        'chat_messages' => "CREATE TABLE IF NOT EXISTS chat_messages (
            id VARCHAR(32) PRIMARY KEY,
            session_id VARCHAR(32) NOT NULL,
            sender_type ENUM('user', 'aei') NOT NULL,
            message_text TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            -- Image Upload Support
            has_image BOOLEAN DEFAULT FALSE,
            image_filename VARCHAR(255) NULL,
            image_original_name VARCHAR(255) NULL,
            image_mime_type VARCHAR(100) NULL,
            image_size INT NULL,
            
            -- AEI Emotional State (NULL for user messages)
            aei_joy DECIMAL(3,2) NULL,
            aei_sadness DECIMAL(3,2) NULL,
            aei_fear DECIMAL(3,2) NULL,
            aei_anger DECIMAL(3,2) NULL,
            aei_surprise DECIMAL(3,2) NULL,
            aei_disgust DECIMAL(3,2) NULL,
            aei_trust DECIMAL(3,2) NULL,
            aei_anticipation DECIMAL(3,2) NULL,
            aei_shame DECIMAL(3,2) NULL,
            aei_love DECIMAL(3,2) NULL,
            aei_contempt DECIMAL(3,2) NULL,
            aei_loneliness DECIMAL(3,2) NULL,
            aei_pride DECIMAL(3,2) NULL,
            aei_envy DECIMAL(3,2) NULL,
            aei_nostalgia DECIMAL(3,2) NULL,
            aei_gratitude DECIMAL(3,2) NULL,
            aei_frustration DECIMAL(3,2) NULL,
            aei_boredom DECIMAL(3,2) NULL,
            
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            INDEX idx_session_time (session_id, created_at),
            INDEX idx_image_messages (session_id, has_image)
        )",
        'api_settings' => "CREATE TABLE IF NOT EXISTS api_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        'aei_social_contacts' => "CREATE TABLE IF NOT EXISTS aei_social_contacts (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            name VARCHAR(255) NOT NULL,
            
            -- Base personality (stable)
            personality_traits JSON,
            appearance_description TEXT,
            background_story TEXT,
            
            -- NEW: Advanced Psychological Profile
            psychological_profile JSON,
            attachment_style ENUM('secure', 'anxious', 'avoidant', 'disorganized') DEFAULT 'secure',
            communication_patterns JSON,
            life_phase ENUM('exploration', 'establishment', 'maintenance', 'legacy') DEFAULT 'establishment',
            core_wounds JSON,
            growth_areas JSON,
            
            -- Relationship with AEI (ENHANCED)
            relationship_type ENUM('close_friend', 'friend', 'family', 'work_colleague', 'romantic_interest', 'acquaintance') NOT NULL,
            relationship_strength INT DEFAULT 50,
            contact_frequency ENUM('daily', 'weekly', 'monthly', 'rarely') DEFAULT 'weekly',
            
            -- NEW: Relationship Evolution Tracking
            relationship_evolution JSON,
            trust_level DECIMAL(3,2) DEFAULT 0.5,
            intimacy_level DECIMAL(3,2) DEFAULT 0.5,
            conflict_history JSON,
            shared_experiences JSON,
            communication_frequency_trend ENUM('increasing', 'stable', 'decreasing') DEFAULT 'stable',
            last_interaction_sentiment DECIMAL(3,2) DEFAULT 0.5,
            
            -- Dynamic life (develops in background)
            current_life_situation TEXT,
            recent_life_events JSON,
            current_concerns TEXT,
            current_goals TEXT,
            
            -- NEW: Advanced Life Context
            life_event_history JSON,
            current_life_phase_challenges TEXT,
            seasonal_mood_patterns JSON,
            cultural_background JSON,
            
            -- NEW: Memory System
            episodic_memories JSON,
            semantic_knowledge JSON,
            emotional_associations JSON,
            procedural_patterns JSON,
            working_memory_topics JSON,
            
            -- Interaction tracking
            last_contact_initiated TIMESTAMP,
            last_life_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_conflict_date TIMESTAMP NULL,
            last_positive_interaction TIMESTAMP NULL,
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            INDEX idx_aei_contacts (aei_id, relationship_type),
            INDEX idx_active_contacts (aei_id, is_active),
            INDEX idx_trust_intimacy (aei_id, trust_level, intimacy_level)
        )",
        'aei_contact_interactions' => "CREATE TABLE IF NOT EXISTS aei_contact_interactions (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            contact_id VARCHAR(32) NOT NULL,
            
            -- Interaction details (EXPANDED)
            interaction_type ENUM('shares_news', 'asks_for_advice', 'invites_to_activity', 'shares_problem', 'celebrates_together', 'casual_chat', 'asks_for_favor', 'shares_gossip', 'expresses_concern', 'apologizes', 'expresses_jealousy', 'seeks_validation', 'shares_secret', 'offers_help', 'cancels_plans', 'expresses_conflict', 'seeks_reconciliation', 'social_media_interaction', 'group_event_mention') NOT NULL,
            interaction_context TEXT,
            contact_message TEXT,
            
            -- NEW: Advanced Interaction Details
            interaction_subtype VARCHAR(100),
            emotional_tone ENUM('very_positive', 'positive', 'neutral', 'negative', 'very_negative') DEFAULT 'neutral',
            urgency_level ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            privacy_level ENUM('public', 'semi_private', 'private', 'secret') DEFAULT 'private',
            
            -- NEW: Cross-Contact Context
            mentions_other_contacts JSON,
            group_interaction_id VARCHAR(32) NULL,
            initiated_by ENUM('contact', 'aei', 'system') DEFAULT 'contact',
            
            -- AEI Response (simulated internal dialogue)
            aei_response TEXT,
            aei_thoughts TEXT,
            aei_internal_conflict TEXT,
            
            -- Dialog History for multi-turn conversations
            dialog_history JSON,
            
            -- NEW: Advanced Response Analysis
            aei_response_strategy ENUM('supportive', 'advisory', 'celebratory', 'concerned', 'boundary_setting', 'conflict_avoidant', 'direct_confrontation') NULL,
            conversation_satisfaction_score DECIMAL(3,2) DEFAULT 0.5,
            
            -- Emotional impact on AEI (ENHANCED)
            aei_emotional_response JSON,
            relationship_impact DECIMAL(4,2) DEFAULT 0.0,
            trust_impact DECIMAL(3,2) DEFAULT 0.0,
            intimacy_impact DECIMAL(3,2) DEFAULT 0.0,
            
            -- NEW: Conflict & Resolution Tracking
            is_conflict BOOLEAN DEFAULT FALSE,
            conflict_category ENUM('values', 'expectations', 'jealousy', 'betrayal', 'misunderstanding', 'boundary_violation') NULL,
            resolution_status ENUM('unresolved', 'pending', 'partially_resolved', 'fully_resolved') NULL,
            resolution_method ENUM('apology', 'compromise', 'boundary_setting', 'time', 'third_party', 'avoidance') NULL,
            
            -- Processing
            processed_for_emotions BOOLEAN DEFAULT FALSE,
            mentioned_in_chat BOOLEAN DEFAULT FALSE,
            
            -- NEW: Memory Integration
            memory_type ENUM('episodic', 'semantic', 'emotional', 'procedural') DEFAULT 'episodic',
            memory_importance_score DECIMAL(3,2) DEFAULT 0.5,
            
            occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES aei_social_contacts(id) ON DELETE CASCADE,
            INDEX idx_aei_interactions (aei_id, occurred_at),
            INDEX idx_unprocessed (aei_id, processed_for_emotions),
            INDEX idx_conflict_tracking (aei_id, is_conflict, resolution_status),
            INDEX idx_memory_importance (aei_id, memory_importance_score),
            INDEX idx_group_interactions (group_interaction_id)
        )",
        'aei_social_context' => "CREATE TABLE IF NOT EXISTS aei_social_context (
            aei_id VARCHAR(32) PRIMARY KEY,
            
            -- Current social state (ENHANCED)
            social_satisfaction INT DEFAULT 70,
            social_energy_level INT DEFAULT 50,
            social_anxiety_level INT DEFAULT 30,
            social_confidence_level INT DEFAULT 60,
            
            -- NEW: Advanced Social Metrics
            relationship_portfolio_balance DECIMAL(3,2) DEFAULT 0.5,
            social_stimulation_preference ENUM('low', 'medium', 'high') DEFAULT 'medium',
            current_social_phase ENUM('expanding', 'maintaining', 'consolidating', 'withdrawing') DEFAULT 'maintaining',
            
            -- Chat integration (ENHANCED)
            recent_social_summary TEXT,
            current_social_concerns TEXT,
            topics_to_mention JSON,
            conversation_starters JSON,
            
            -- NEW: Advanced Context Tracking
            seasonal_social_patterns JSON,
            cultural_event_awareness JSON,
            local_community_involvement JSON,
            
            -- NEW: Cross-Contact Analytics
            social_network_density DECIMAL(3,2) DEFAULT 0.3,
            friend_group_dynamics JSON,
            social_influence_map JSON,
            
            -- NEW: Emotional Contagion Tracking
            absorbed_emotions JSON,
            emotional_support_burden_score INT DEFAULT 0,
            emotional_resilience_level INT DEFAULT 70,
            
            -- Unprocessed interactions
            unprocessed_interactions_count INT DEFAULT 0,
            pending_conflict_count INT DEFAULT 0,
            
            -- NEW: Predictive Social AI Data
            predicted_next_contacts JSON,
            social_pattern_analysis JSON,
            relationship_health_scores JSON,
            
            last_social_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_social_analysis TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE
        )",
        'aei_contact_relationships' => "CREATE TABLE IF NOT EXISTS aei_contact_relationships (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            contact_a_id VARCHAR(32) NOT NULL,
            contact_b_id VARCHAR(32) NOT NULL,
            
            -- Cross-Contact Relationship Details
            relationship_type ENUM('friends', 'colleagues', 'family', 'romantic', 'acquaintances', 'rivals', 'strangers', 'complicated') DEFAULT 'strangers',
            relationship_strength INT DEFAULT 30,
            interaction_frequency ENUM('never', 'rarely', 'sometimes', 'often', 'daily') DEFAULT 'never',
            
            -- Dynamic Relationship Data
            shared_history JSON,
            mutual_interests JSON,
            conflict_areas JSON,
            gossip_topics JSON,
            
            -- Tracking
            last_mentioned_together TIMESTAMP NULL,
            creates_drama_potential BOOLEAN DEFAULT FALSE,
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_a_id) REFERENCES aei_social_contacts(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_b_id) REFERENCES aei_social_contacts(id) ON DELETE CASCADE,
            INDEX idx_aei_contact_pairs (aei_id, contact_a_id, contact_b_id),
            INDEX idx_drama_potential (aei_id, creates_drama_potential)
        )",
        'aei_group_events' => "CREATE TABLE IF NOT EXISTS aei_group_events (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            
            -- Event Details
            event_name VARCHAR(255) NOT NULL,
            event_type ENUM('birthday', 'celebration', 'gathering', 'crisis_support', 'work_event', 'family_event', 'social_outing', 'conflict_mediation') NOT NULL,
            event_description TEXT,
            
            -- Participants
            organizer_contact_id VARCHAR(32),
            invited_contacts JSON,
            attending_contacts JSON,
            aei_participation ENUM('organizing', 'attending', 'invited', 'declined', 'conflicted') DEFAULT 'invited',
            
            -- Event Dynamics
            social_dynamics JSON,
            drama_level ENUM('peaceful', 'minor_tension', 'moderate_conflict', 'major_drama') DEFAULT 'peaceful',
            emotional_impact_on_aei JSON,
            
            -- Timing
            planned_date DATETIME NULL,
            actual_date DATETIME NULL,
            event_status ENUM('planned', 'happening', 'completed', 'cancelled', 'postponed') DEFAULT 'planned',
            
            -- Outcomes
            relationship_changes JSON,
            memorable_moments JSON,
            follow_up_interactions JSON,
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (organizer_contact_id) REFERENCES aei_social_contacts(id) ON DELETE SET NULL,
            INDEX idx_aei_events (aei_id, event_status),
            INDEX idx_event_timeline (aei_id, planned_date)
        )",
        'aei_social_media_simulation' => "CREATE TABLE IF NOT EXISTS aei_social_media_simulation (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            contact_id VARCHAR(32) NOT NULL,
            
            -- Social Media Post Details
            post_type ENUM('status_update', 'photo', 'achievement', 'life_event', 'opinion', 'question', 'share') NOT NULL,
            post_content TEXT NOT NULL,
            post_mood ENUM('excited', 'happy', 'contemplative', 'worried', 'proud', 'frustrated', 'nostalgic') NOT NULL,
            
            -- Interaction Data
            aei_reaction ENUM('like', 'love', 'care', 'wow', 'sad', 'angry', 'ignore') NULL,
            aei_comment TEXT NULL,
            other_reactions_simulation JSON,
            
            -- Context
            triggers_conversation BOOLEAN DEFAULT FALSE,
            privacy_level ENUM('public', 'friends', 'close_friends', 'private') DEFAULT 'friends',
            
            -- Analytics
            emotional_impact_score DECIMAL(3,2) DEFAULT 0.0,
            generates_gossip BOOLEAN DEFAULT FALSE,
            
            posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            aei_seen_at TIMESTAMP NULL,
            aei_reacted_at TIMESTAMP NULL,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES aei_social_contacts(id) ON DELETE CASCADE,
            INDEX idx_aei_timeline (aei_id, posted_at),
            INDEX idx_pending_reactions (aei_id, aei_seen_at, aei_reaction)
        )",
        'aei_seasonal_cultural_context' => "CREATE TABLE IF NOT EXISTS aei_seasonal_cultural_context (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            
            -- Seasonal Patterns
            current_season ENUM('spring', 'summer', 'autumn', 'winter') NOT NULL,
            seasonal_mood_modifier DECIMAL(3,2) DEFAULT 0.0,
            seasonal_activity_preferences JSON,
            seasonal_social_patterns JSON,
            
            -- Cultural Events & Holidays
            active_cultural_events JSON,
            upcoming_holidays JSON,
            cultural_traditions JSON,
            family_holiday_dynamics JSON,
            
            -- Local Community Context  
            local_events JSON,
            economic_climate_impact ENUM('positive', 'neutral', 'negative') DEFAULT 'neutral',
            social_trends JSON,
            community_involvement_level ENUM('minimal', 'moderate', 'active', 'highly_engaged') DEFAULT 'moderate',
            
            -- Time-Aware Factors
            current_life_stage_events JSON,
            age_appropriate_social_pressures JSON,
            generational_influences JSON,
            
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            INDEX idx_seasonal_updates (aei_id, current_season)
        )",
        'aei_predictive_social_ai' => "CREATE TABLE IF NOT EXISTS aei_predictive_social_ai (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            
            -- Pattern Recognition Data
            interaction_patterns JSON,
            communication_rhythms JSON,
            emotional_cycles JSON,
            relationship_progression_models JSON,
            
            -- Predictive Models
            next_contact_predictions JSON,
            conflict_probability_scores JSON,
            relationship_trajectory_analysis JSON,
            social_need_predictions JSON,
            
            -- Learning Metrics
            prediction_accuracy_scores JSON,
            behavioral_pattern_confidence JSON,
            social_intelligence_growth_metrics JSON,
            
            -- Coaching Recommendations
            relationship_coaching_suggestions JSON,
            communication_improvement_areas JSON,
            social_skill_development_focus JSON,
            
            last_analysis TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            model_version VARCHAR(10) DEFAULT '1.0',
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            INDEX idx_predictions_timeline (aei_id, last_analysis)
        )",
        'global_proactive_settings' => "CREATE TABLE IF NOT EXISTS global_proactive_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            description TEXT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        'aei_proactive_messages' => "CREATE TABLE IF NOT EXISTS aei_proactive_messages (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            session_id VARCHAR(32) NOT NULL,
            
            -- Trigger Information
            trigger_type ENUM('emotional', 'social', 'temporal', 'contextual', 'mixed') NOT NULL,
            trigger_details JSON,
            trigger_strength DECIMAL(3,2) DEFAULT 0.0,
            
            -- Message Content
            message_text TEXT NOT NULL,
            message_tone ENUM('caring', 'excited', 'concerned', 'nostalgic', 'supportive', 'curious') DEFAULT 'caring',
            
            -- Timing and Status
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            scheduled_for TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            status ENUM('pending', 'scheduled', 'sent', 'dismissed', 'expired') DEFAULT 'pending',
            
            -- Chat Integration
            chat_message_id VARCHAR(32) NULL,
            
            -- User Interaction
            user_response TEXT NULL,
            user_reaction ENUM('positive', 'neutral', 'negative', 'ignored') NULL,
            conversation_continued BOOLEAN DEFAULT FALSE,
            
            -- Learning Data
            effectiveness_score DECIMAL(3,2) NULL,
            timing_appropriateness DECIMAL(3,2) NULL,
            content_relevance DECIMAL(3,2) NULL,
            
            -- Context
            emotional_state_at_trigger JSON,
            social_context_at_trigger JSON,
            user_last_active TIMESTAMP NULL,
            
            expires_at TIMESTAMP NULL,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            INDEX idx_aei_status (aei_id, status),
            INDEX idx_trigger_type (aei_id, trigger_type),
            INDEX idx_scheduled_messages (aei_id, scheduled_for, status),
            INDEX idx_effectiveness (aei_id, effectiveness_score)
        )",
        'aei_proactive_triggers' => "CREATE TABLE IF NOT EXISTS aei_proactive_triggers (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            
            -- Trigger Configuration
            trigger_name VARCHAR(100) NOT NULL,
            trigger_type ENUM('emotional', 'social', 'temporal', 'contextual') NOT NULL,
            trigger_conditions JSON NOT NULL,
            
            -- Trigger Settings
            is_active BOOLEAN DEFAULT TRUE,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            cooldown_hours INT DEFAULT 24,
            max_triggers_per_day INT DEFAULT 3,
            
            -- Message Templates
            message_templates JSON,
            tone_preferences JSON,
            
            -- Learning & Adaptation
            success_rate DECIMAL(3,2) DEFAULT 0.5,
            total_triggers INT DEFAULT 0,
            successful_triggers INT DEFAULT 0,
            last_triggered TIMESTAMP NULL,
            
            -- User Preferences
            user_feedback_score DECIMAL(3,2) DEFAULT 0.5,
            user_disabled BOOLEAN DEFAULT FALSE,
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            INDEX idx_active_triggers (aei_id, is_active, trigger_type),
            INDEX idx_trigger_performance (aei_id, success_rate),
            INDEX idx_last_triggered (aei_id, last_triggered)
        )",
        'aei_proactive_settings' => "CREATE TABLE IF NOT EXISTS aei_proactive_settings (
            aei_id VARCHAR(32) PRIMARY KEY,
            
            -- Global Settings  
            proactive_messaging_enabled BOOLEAN DEFAULT TRUE,
            max_messages_per_day INT DEFAULT 5,
            
            -- Trigger Sensitivity
            emotional_sensitivity DECIMAL(3,2) DEFAULT 0.6,
            social_sensitivity DECIMAL(3,2) DEFAULT 0.5,
            temporal_sensitivity DECIMAL(3,2) DEFAULT 0.4,
            contextual_sensitivity DECIMAL(3,2) DEFAULT 0.5,
            
            -- Behavioral Adaptation
            learns_from_user_responses BOOLEAN DEFAULT TRUE,
            adapts_timing BOOLEAN DEFAULT TRUE,
            adapts_content BOOLEAN DEFAULT TRUE,
            
            -- User Preferences
            preferred_message_types JSON,
            blocked_trigger_types JSON,
            custom_trigger_rules JSON,
            
            -- AI Learning
            personality_adaptation_rate DECIMAL(3,2) DEFAULT 0.1,
            context_memory_depth INT DEFAULT 10,
            
            last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE
        )",
        'background_jobs' => "CREATE TABLE IF NOT EXISTS background_jobs (
            id VARCHAR(32) PRIMARY KEY,
            job_type ENUM('proactive_analysis', 'social_update', 'emotional_analysis', 'cleanup') NOT NULL,
            target_type ENUM('aei', 'user', 'system') NOT NULL,
            target_id VARCHAR(32) NULL,
            
            -- Job Payload and Configuration
            job_data JSON,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            
            -- Scheduling
            scheduled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            execute_after TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            max_attempts INT DEFAULT 3,
            current_attempt INT DEFAULT 0,
            
            -- Status and Results
            status ENUM('pending', 'running', 'completed', 'failed', 'cancelled') DEFAULT 'pending',
            started_at TIMESTAMP NULL,
            completed_at TIMESTAMP NULL,
            error_message TEXT NULL,
            result_data JSON NULL,
            
            -- Metadata
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_heartbeat TIMESTAMP NULL,
            worker_id VARCHAR(32) NULL,
            
            INDEX idx_job_execution (status, execute_after, priority),
            INDEX idx_job_type_target (job_type, target_type, target_id),
            INDEX idx_scheduled_jobs (scheduled_at, status),
            INDEX idx_failed_jobs (status, current_attempt, max_attempts)
        )",
        'user_notification_preferences' => "CREATE TABLE IF NOT EXISTS user_notification_preferences (
            user_id VARCHAR(32) PRIMARY KEY,
            
            -- Notification Channels
            email_notifications BOOLEAN DEFAULT TRUE,
            push_notifications BOOLEAN DEFAULT TRUE,
            in_app_notifications BOOLEAN DEFAULT TRUE,
            
            -- Proactive Message Preferences
            proactive_messages_enabled BOOLEAN DEFAULT TRUE,
            max_proactive_per_day INT DEFAULT 5,
            
            -- Notification Types
            emotional_checkins BOOLEAN DEFAULT TRUE,
            social_updates BOOLEAN DEFAULT TRUE,
            milestone_celebrations BOOLEAN DEFAULT TRUE,
            concern_followups BOOLEAN DEFAULT TRUE,
            
            -- Delivery Preferences
            immediate_for_high_priority BOOLEAN DEFAULT TRUE,
            batch_low_priority BOOLEAN DEFAULT FALSE,
            respect_do_not_disturb BOOLEAN DEFAULT TRUE,
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        'aei_emotional_decay_log' => "CREATE TABLE IF NOT EXISTS aei_emotional_decay_log (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            session_id VARCHAR(32) NOT NULL,
            hours_inactive DECIMAL(5,2) NOT NULL,
            
            -- Record of emotional changes applied
            emotional_changes JSON NOT NULL,
            
            -- Metadata
            processed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            decay_strength DECIMAL(3,2) DEFAULT 1.0,
            relationship_factor DECIMAL(3,2) DEFAULT 1.0,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            INDEX idx_aei_processed (aei_id, processed_at),
            INDEX idx_session_decay (session_id, hours_inactive)
        )",
        'message_feedback' => "CREATE TABLE IF NOT EXISTS message_feedback (
            id VARCHAR(32) PRIMARY KEY,
            message_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(32) NOT NULL,
            session_id VARCHAR(32) NOT NULL,
            aei_id VARCHAR(32) NOT NULL,
            
            -- Feedback Data
            rating ENUM('thumbs_up', 'thumbs_down') NOT NULL,
            feedback_text TEXT NULL,
            feedback_category ENUM('helpful', 'accurate', 'engaging', 'inappropriate', 'inaccurate', 'boring', 'other') NULL,
            
            -- Context Data (last 20 messages for better understanding)
            message_context JSON NULL,
            
            -- Metadata
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            
            FOREIGN KEY (message_id) REFERENCES chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            
            -- Indexes
            INDEX idx_message_feedback (message_id),
            INDEX idx_user_feedback (user_id, created_at),
            INDEX idx_aei_feedback (aei_id, rating, created_at),
            INDEX idx_session_feedback (session_id, created_at),
            
            -- Unique constraint to prevent duplicate feedback
            UNIQUE KEY unique_user_message_feedback (user_id, message_id)
        )",

        // AEI Memory System - Long-term memory storage (2025 Qdrant Inference)
        'aei_memories' => "CREATE TABLE IF NOT EXISTS aei_memories (
            memory_id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            user_id VARCHAR(32) NULL,
            session_id VARCHAR(32) NULL,
            memory_type ENUM('fact', 'event', 'emotion', 'preference', 'relationship', 'goal', 'concern') NOT NULL,
            content TEXT NOT NULL,
            importance_score DECIMAL(3,2) DEFAULT 0.5,
            embedding_model VARCHAR(100) DEFAULT 'sentence-transformers/all-MiniLM-L6-v2',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_accessed TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            access_count INT DEFAULT 0,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE SET NULL,
            
            -- Indexes for efficient querying
            INDEX idx_aei_memories (aei_id, importance_score),
            INDEX idx_memory_type (memory_type, created_at),
            INDEX idx_memory_access (last_accessed, access_count),
            INDEX idx_session_memories (session_id, created_at),
            INDEX idx_embedding_model (embedding_model, created_at)
        )",

        // Temporary avatar options for AEI creation
        'temp_avatar_options' => "CREATE TABLE IF NOT EXISTS temp_avatar_options (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            aei_name VARCHAR(100) NOT NULL,
            prompt_used TEXT NOT NULL,
            avatar_1_url VARCHAR(500) NULL,
            avatar_2_url VARCHAR(500) NULL,
            avatar_3_url VARCHAR(500) NULL,
            selected_avatar INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP DEFAULT (CURRENT_TIMESTAMP + INTERVAL 24 HOUR),
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_expires (user_id, expires_at),
            INDEX idx_expires (expires_at)
        )",
        'migration_jobs' => "CREATE TABLE IF NOT EXISTS migration_jobs (
            job_id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            job_type ENUM('memory_migration') NOT NULL DEFAULT 'memory_migration',
            status ENUM('pending', 'processing', 'completed', 'failed', 'completed_with_errors') NOT NULL DEFAULT 'pending',
            message TEXT NULL,
            job_data JSON NULL,
            progress_current INT DEFAULT 0,
            progress_total INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            completed_at TIMESTAMP NULL,
            
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_jobs (user_id, created_at),
            INDEX idx_status (status, created_at)
        )"
    ];

    foreach ($tables as $tableName => $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            error_log("Failed to create table $tableName: " . $e->getMessage());
        }
    }
}

createDatabaseIfNotExists();

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    createTablesIfNotExist($pdo);
    
    // Comprehensive migration - ensure ALL tables and columns exist
    try {
        // 1. Check and create beta_codes table first (no dependencies)
        $stmt = $pdo->query("SHOW TABLES LIKE 'beta_codes'");
        if (!$stmt->fetch()) {
            $pdo->exec("CREATE TABLE beta_codes (
                code VARCHAR(20) PRIMARY KEY,
                first_name VARCHAR(100) NULL,
                email VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                used_at TIMESTAMP NULL,
                used_by VARCHAR(32) NULL,
                is_active BOOLEAN DEFAULT TRUE
            )");
        } else {
            // Fix existing beta_codes table columns to allow NULL
            try {
                $pdo->exec("ALTER TABLE beta_codes MODIFY COLUMN first_name VARCHAR(100) NULL");
                $pdo->exec("ALTER TABLE beta_codes MODIFY COLUMN email VARCHAR(255) NULL");
            } catch (PDOException $e) {
                // Columns might already be nullable, ignore error
            }
        }
        
        // 2. Check and migrate users table
        $stmt = $pdo->query("DESCRIBE users");
        $userColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $requiredUserColumns = [
            'email', 'password_hash', 'first_name', 'beta_code', 'is_admin',
            'gender', 'birth_date', 'profession', 'hobbies', 'sexual_orientation',
            'daily_rituals', 'life_goals', 'beliefs', 'partner_qualities', 
            'additional_info', 'timezone'
        ];
        $missingUserColumns = array_diff($requiredUserColumns, $userColumns);
        
        foreach ($missingUserColumns as $column) {
            switch ($column) {
                case 'email':
                    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(255) UNIQUE");
                    break;
                case 'password_hash':
                    $pdo->exec("ALTER TABLE users ADD COLUMN password_hash VARCHAR(255) NULL");
                    break;
                case 'first_name':
                    $pdo->exec("ALTER TABLE users ADD COLUMN first_name VARCHAR(100) DEFAULT ''");
                    break;
                case 'beta_code':
                    $pdo->exec("ALTER TABLE users ADD COLUMN beta_code VARCHAR(20) NULL");
                    break;
                case 'is_admin':
                    $pdo->exec("ALTER TABLE users ADD COLUMN is_admin BOOLEAN DEFAULT FALSE");
                    break;
                case 'gender':
                    $pdo->exec("ALTER TABLE users ADD COLUMN gender VARCHAR(50) NULL");
                    break;
                case 'birth_date':
                    $pdo->exec("ALTER TABLE users ADD COLUMN birth_date DATE NULL");
                    break;
                case 'profession':
                    $pdo->exec("ALTER TABLE users ADD COLUMN profession VARCHAR(255) NULL");
                    break;
                case 'hobbies':
                    $pdo->exec("ALTER TABLE users ADD COLUMN hobbies TEXT NULL");
                    break;
                case 'sexual_orientation':
                    $pdo->exec("ALTER TABLE users ADD COLUMN sexual_orientation VARCHAR(100) NULL");
                    break;
                case 'daily_rituals':
                    $pdo->exec("ALTER TABLE users ADD COLUMN daily_rituals TEXT NULL");
                    break;
                case 'life_goals':
                    $pdo->exec("ALTER TABLE users ADD COLUMN life_goals TEXT NULL");
                    break;
                case 'beliefs':
                    $pdo->exec("ALTER TABLE users ADD COLUMN beliefs TEXT NULL");
                    break;
                case 'partner_qualities':
                    $pdo->exec("ALTER TABLE users ADD COLUMN partner_qualities TEXT NULL");
                    break;
                case 'additional_info':
                    $pdo->exec("ALTER TABLE users ADD COLUMN additional_info TEXT NULL");
                    break;
                case 'timezone':
                    $pdo->exec("ALTER TABLE users ADD COLUMN timezone VARCHAR(100) DEFAULT 'UTC'");
                    break;
                case 'feedback_channel':
                    $pdo->exec("ALTER TABLE users ADD COLUMN feedback_channel ENUM('email', 'whatsapp', 'discord', 'x') NULL");
                    break;
                case 'feedback_contact':
                    $pdo->exec("ALTER TABLE users ADD COLUMN feedback_contact VARCHAR(255) NULL");
                    break;
            }
        }
        
        // 3. Add foreign key constraint if it doesn't exist (safely)
        try {
            $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_beta_code FOREIGN KEY (beta_code) REFERENCES beta_codes(code)");
        } catch (PDOException $e) {
            // Constraint might already exist, ignore
        }
        
        // 4. Check and migrate aeis table
        $stmt = $pdo->query("SHOW TABLES LIKE 'aeis'");
        if (!$stmt->fetch()) {
            $pdo->exec("CREATE TABLE aeis (
                id VARCHAR(32) PRIMARY KEY,
                user_id VARCHAR(32) NOT NULL,
                name VARCHAR(100) NOT NULL,
                personality TEXT,
                appearance_description TEXT,
                system_prompt TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_active BOOLEAN DEFAULT TRUE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user_id (user_id)
            )");
        } else {
            // Add new character columns if they don't exist
            try {
                $stmt = $pdo->query("DESCRIBE aeis");
                $aeiColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                $newColumns = [
                    'age' => "ALTER TABLE aeis ADD COLUMN age VARCHAR(50) NULL AFTER name",
                    'gender' => "ALTER TABLE aeis ADD COLUMN gender VARCHAR(50) NULL AFTER age", 
                    'background' => "ALTER TABLE aeis ADD COLUMN background TEXT NULL AFTER appearance_description",
                    'interests' => "ALTER TABLE aeis ADD COLUMN interests TEXT NULL AFTER background",
                    'communication_style' => "ALTER TABLE aeis ADD COLUMN communication_style TEXT NULL AFTER interests",
                    'quirks' => "ALTER TABLE aeis ADD COLUMN quirks TEXT NULL AFTER communication_style",
                    'occupation' => "ALTER TABLE aeis ADD COLUMN occupation VARCHAR(200) NULL AFTER quirks",
                    'goals' => "ALTER TABLE aeis ADD COLUMN goals VARCHAR(200) NULL AFTER occupation",
                    'relationship_context' => "ALTER TABLE aeis ADD COLUMN relationship_context TEXT NULL AFTER goals",
                    'avatar_url' => "ALTER TABLE aeis ADD COLUMN avatar_url VARCHAR(500) NULL AFTER relationship_context",
                    'system_prompt' => "ALTER TABLE aeis ADD COLUMN system_prompt TEXT NULL AFTER avatar_url",
                    'response_length' => "ALTER TABLE aeis ADD COLUMN response_length TINYINT DEFAULT 2 AFTER system_prompt"
                ];
                
                foreach ($newColumns as $columnName => $alterSQL) {
                    if (!in_array($columnName, $aeiColumns)) {
                        try {
                            $pdo->exec($alterSQL);
                        } catch (PDOException $e) {
                            // Column might already exist, ignore error
                        }
                    }
                }
                
                // Add social columns if they don't exist
                $socialColumns = [
                    'social_initialized' => "ALTER TABLE aeis ADD COLUMN social_initialized BOOLEAN DEFAULT FALSE AFTER is_active",
                    'social_personality_seed' => "ALTER TABLE aeis ADD COLUMN social_personality_seed VARCHAR(32) NULL AFTER social_initialized",
                    'personality_traits' => "ALTER TABLE aeis ADD COLUMN personality_traits JSON NULL AFTER social_personality_seed"
                ];
                
                foreach ($socialColumns as $columnName => $alterSQL) {
                    if (!in_array($columnName, $aeiColumns)) {
                        try {
                            $pdo->exec($alterSQL);
                        } catch (PDOException $e) {
                            // Column might already exist, ignore error
                        }
                    }
                }
            } catch (PDOException $e) {
                // Error reading columns, ignore
            }
        }
        
        // 5. Check and migrate chat_sessions table
        $stmt = $pdo->query("SHOW TABLES LIKE 'chat_sessions'");
        if (!$stmt->fetch()) {
            $pdo->exec("CREATE TABLE chat_sessions (
                id VARCHAR(32) PRIMARY KEY,
                user_id VARCHAR(32) NOT NULL,
                aei_id VARCHAR(32) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
                INDEX idx_user_aei (user_id, aei_id)
            )");
        }
        
        // 6. Check and migrate chat_messages table
        $stmt = $pdo->query("SHOW TABLES LIKE 'chat_messages'");
        if (!$stmt->fetch()) {
            $pdo->exec("CREATE TABLE chat_messages (
                id VARCHAR(32) PRIMARY KEY,
                session_id VARCHAR(32) NOT NULL,
                sender_type ENUM('user', 'aei') NOT NULL,
                message_text TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
                INDEX idx_session_time (session_id, created_at)
            )");
        }
        
        // Add emotion columns to chat_messages if they don't exist
        try {
            $stmt = $pdo->query("DESCRIBE chat_messages");
            $messageColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $emotionColumns = [
                'aei_joy', 'aei_sadness', 'aei_fear', 'aei_anger', 'aei_surprise', 
                'aei_disgust', 'aei_trust', 'aei_anticipation', 'aei_shame', 'aei_love',
                'aei_contempt', 'aei_loneliness', 'aei_pride', 'aei_envy', 'aei_nostalgia',
                'aei_gratitude', 'aei_frustration', 'aei_boredom'
            ];
            
            foreach ($emotionColumns as $column) {
                if (!in_array($column, $messageColumns)) {
                    try {
                        $pdo->exec("ALTER TABLE chat_messages ADD COLUMN $column DECIMAL(3,2) NULL");
                    } catch (PDOException $e) {
                        // Column might already exist, ignore error
                    }
                }
            }
        } catch (PDOException $e) {
            // Error reading columns, ignore
        }
        
        // Add emotion columns to chat_sessions if they don't exist
        try {
            $stmt = $pdo->query("DESCRIBE chat_sessions");
            $sessionColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $emotionColumns = [
                'aei_joy', 'aei_sadness', 'aei_fear', 'aei_anger', 'aei_surprise', 
                'aei_disgust', 'aei_trust', 'aei_anticipation', 'aei_shame', 'aei_love',
                'aei_contempt', 'aei_loneliness', 'aei_pride', 'aei_envy', 'aei_nostalgia',
                'aei_gratitude', 'aei_frustration', 'aei_boredom'
            ];
            
            foreach ($emotionColumns as $column) {
                if (!in_array($column, $sessionColumns)) {
                    try {
                        $pdo->exec("ALTER TABLE chat_sessions ADD COLUMN $column DECIMAL(3,2) DEFAULT 0.5");
                    } catch (PDOException $e) {
                        // Column might already exist, ignore error
                    }
                }
            }
        } catch (PDOException $e) {
            // Error reading columns, ignore
        }
        
        // 7. Check and migrate api_settings table
        $stmt = $pdo->query("SHOW TABLES LIKE 'api_settings'");
        if (!$stmt->fetch()) {
            $pdo->exec("CREATE TABLE api_settings (
                id INT PRIMARY KEY AUTO_INCREMENT,
                setting_key VARCHAR(100) UNIQUE NOT NULL,
                setting_value TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");
        }
        
        // 8. Initialize default system prompt template if not exists
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'global_system_prompt_template'");
            $stmt->execute();
            if (!$stmt->fetch()) {
                $defaultTemplate = "You are {{aei_name}}, an Artificial Emotional Intelligence (AEI) companion.

{{#if personality}}
Your personality: {{personality}}
{{/if}}

{{#if appearance_description}}
Your appearance: {{appearance_description}}
{{/if}}

{{#if user_first_name}}
You are chatting with {{user_first_name}}.
{{/if}}

{{#if user_profession}}
{{user_first_name}} works as {{user_profession}}.
{{/if}}

{{#if user_hobbies}}
{{user_first_name}}'s hobbies include: {{user_hobbies}}
{{/if}}

{{#if user_goals}}
{{user_first_name}}'s life goals: {{user_goals}}
{{/if}}

Be conversational, helpful, and maintain your unique personality. Keep responses engaging but concise.";
                
                $stmt = $pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES ('global_system_prompt_template', ?)");
                $stmt->execute([$defaultTemplate]);
            }
        } catch (PDOException $e) {
            error_log("Error setting default system prompt template: " . $e->getMessage());
        }
        
    } catch (PDOException $e) {
        error_log("Comprehensive migration error: " . $e->getMessage());
    }
    
    // Add social dialog columns to aei_contact_interactions if they don't exist
    try {
        $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
        if ($stmt) {
            $interactionColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $dialogColumns = [
                'aei_response' => "ALTER TABLE aei_contact_interactions ADD COLUMN aei_response TEXT NULL AFTER contact_message",
                'aei_thoughts' => "ALTER TABLE aei_contact_interactions ADD COLUMN aei_thoughts TEXT NULL AFTER aei_response",
                'dialog_history' => "ALTER TABLE aei_contact_interactions ADD COLUMN dialog_history JSON NULL AFTER aei_thoughts",
                'initiated_by' => "ALTER TABLE aei_contact_interactions ADD COLUMN initiated_by ENUM('contact', 'aei', 'system') DEFAULT 'contact' AFTER group_interaction_id",
                'processed_for_emotions' => "ALTER TABLE aei_contact_interactions ADD COLUMN processed_for_emotions BOOLEAN DEFAULT FALSE AFTER mentioned_in_chat"
            ];
            
            foreach ($dialogColumns as $columnName => $alterSQL) {
                if (!in_array($columnName, $interactionColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                        error_log("MIGRATION: Added column '{$columnName}' to aei_contact_interactions table");
                    } catch (PDOException $e) {
                        error_log("MIGRATION: Failed to add column '{$columnName}': " . $e->getMessage());
                        // Column might already exist, ignore error
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Error reading columns, ignore
    }
    
    // COMPREHENSIVE MIGRATION FOR ALL NEW ADVANCED SOCIAL FEATURES
    try {
        // 1. Migrate aei_social_contacts with all new psychological and relationship columns
        $stmt = $pdo->query("DESCRIBE aei_social_contacts");
        if ($stmt) {
            $contactColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $advancedContactColumns = [
                'psychological_profile' => "ALTER TABLE aei_social_contacts ADD COLUMN psychological_profile JSON AFTER background_story",
                'attachment_style' => "ALTER TABLE aei_social_contacts ADD COLUMN attachment_style ENUM('secure', 'anxious', 'avoidant', 'disorganized') DEFAULT 'secure' AFTER psychological_profile",
                'communication_patterns' => "ALTER TABLE aei_social_contacts ADD COLUMN communication_patterns JSON AFTER attachment_style",
                'life_phase' => "ALTER TABLE aei_social_contacts ADD COLUMN life_phase ENUM('exploration', 'establishment', 'maintenance', 'legacy') DEFAULT 'establishment' AFTER communication_patterns",
                'core_wounds' => "ALTER TABLE aei_social_contacts ADD COLUMN core_wounds JSON AFTER life_phase",
                'growth_areas' => "ALTER TABLE aei_social_contacts ADD COLUMN growth_areas JSON AFTER core_wounds",
                'relationship_evolution' => "ALTER TABLE aei_social_contacts ADD COLUMN relationship_evolution JSON AFTER contact_frequency",
                'trust_level' => "ALTER TABLE aei_social_contacts ADD COLUMN trust_level DECIMAL(3,2) DEFAULT 0.5 AFTER relationship_evolution",
                'intimacy_level' => "ALTER TABLE aei_social_contacts ADD COLUMN intimacy_level DECIMAL(3,2) DEFAULT 0.5 AFTER trust_level",
                'conflict_history' => "ALTER TABLE aei_social_contacts ADD COLUMN conflict_history JSON AFTER intimacy_level",
                'shared_experiences' => "ALTER TABLE aei_social_contacts ADD COLUMN shared_experiences JSON AFTER conflict_history",
                'communication_frequency_trend' => "ALTER TABLE aei_social_contacts ADD COLUMN communication_frequency_trend ENUM('increasing', 'stable', 'decreasing') DEFAULT 'stable' AFTER shared_experiences",
                'last_interaction_sentiment' => "ALTER TABLE aei_social_contacts ADD COLUMN last_interaction_sentiment DECIMAL(3,2) DEFAULT 0.5 AFTER communication_frequency_trend",
                'life_event_history' => "ALTER TABLE aei_social_contacts ADD COLUMN life_event_history JSON AFTER current_goals",
                'current_life_phase_challenges' => "ALTER TABLE aei_social_contacts ADD COLUMN current_life_phase_challenges TEXT AFTER life_event_history",
                'seasonal_mood_patterns' => "ALTER TABLE aei_social_contacts ADD COLUMN seasonal_mood_patterns JSON AFTER current_life_phase_challenges",
                'cultural_background' => "ALTER TABLE aei_social_contacts ADD COLUMN cultural_background JSON AFTER seasonal_mood_patterns",
                'episodic_memories' => "ALTER TABLE aei_social_contacts ADD COLUMN episodic_memories JSON AFTER cultural_background",
                'semantic_knowledge' => "ALTER TABLE aei_social_contacts ADD COLUMN semantic_knowledge JSON AFTER episodic_memories",
                'emotional_associations' => "ALTER TABLE aei_social_contacts ADD COLUMN emotional_associations JSON AFTER semantic_knowledge",
                'procedural_patterns' => "ALTER TABLE aei_social_contacts ADD COLUMN procedural_patterns JSON AFTER emotional_associations",
                'working_memory_topics' => "ALTER TABLE aei_social_contacts ADD COLUMN working_memory_topics JSON AFTER procedural_patterns",
                'last_conflict_date' => "ALTER TABLE aei_social_contacts ADD COLUMN last_conflict_date TIMESTAMP NULL AFTER last_life_update",
                'last_positive_interaction' => "ALTER TABLE aei_social_contacts ADD COLUMN last_positive_interaction TIMESTAMP NULL AFTER last_conflict_date"
            ];
            
            foreach ($advancedContactColumns as $columnName => $alterSQL) {
                if (!in_array($columnName, $contactColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                    } catch (PDOException $e) {
                        error_log("Error adding contact column $columnName: " . $e->getMessage());
                    }
                }
            }
        }
        
        // 2. Migrate aei_contact_interactions with all new interaction types and features
        $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
        if ($stmt) {
            $interactionColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // First, modify the ENUM for interaction_type to include all new types
            try {
                $pdo->exec("ALTER TABLE aei_contact_interactions MODIFY COLUMN interaction_type ENUM('shares_news', 'asks_for_advice', 'invites_to_activity', 'shares_problem', 'celebrates_together', 'casual_chat', 'asks_for_favor', 'shares_gossip', 'expresses_concern', 'apologizes', 'expresses_jealousy', 'seeks_validation', 'shares_secret', 'offers_help', 'cancels_plans', 'expresses_conflict', 'seeks_reconciliation', 'social_media_interaction', 'group_event_mention') NOT NULL");
            } catch (PDOException $e) {
                error_log("Error modifying interaction_type enum: " . $e->getMessage());
            }
            
            $advancedInteractionColumns = [
                'interaction_subtype' => "ALTER TABLE aei_contact_interactions ADD COLUMN interaction_subtype VARCHAR(100) AFTER interaction_type",
                'emotional_tone' => "ALTER TABLE aei_contact_interactions ADD COLUMN emotional_tone ENUM('very_positive', 'positive', 'neutral', 'negative', 'very_negative') DEFAULT 'neutral' AFTER interaction_context",
                'urgency_level' => "ALTER TABLE aei_contact_interactions ADD COLUMN urgency_level ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' AFTER emotional_tone",
                'privacy_level' => "ALTER TABLE aei_contact_interactions ADD COLUMN privacy_level ENUM('public', 'semi_private', 'private', 'secret') DEFAULT 'private' AFTER urgency_level",
                'mentions_other_contacts' => "ALTER TABLE aei_contact_interactions ADD COLUMN mentions_other_contacts JSON AFTER privacy_level",
                'group_interaction_id' => "ALTER TABLE aei_contact_interactions ADD COLUMN group_interaction_id VARCHAR(32) NULL AFTER mentions_other_contacts",
                'initiated_by' => "ALTER TABLE aei_contact_interactions ADD COLUMN initiated_by ENUM('contact', 'aei', 'system') DEFAULT 'contact' AFTER group_interaction_id",
                'aei_internal_conflict' => "ALTER TABLE aei_contact_interactions ADD COLUMN aei_internal_conflict TEXT AFTER aei_thoughts",
                'aei_response_strategy' => "ALTER TABLE aei_contact_interactions ADD COLUMN aei_response_strategy ENUM('supportive', 'advisory', 'celebratory', 'concerned', 'boundary_setting', 'conflict_avoidant', 'direct_confrontation') NULL AFTER aei_internal_conflict",
                'conversation_satisfaction_score' => "ALTER TABLE aei_contact_interactions ADD COLUMN conversation_satisfaction_score DECIMAL(3,2) DEFAULT 0.5 AFTER aei_response_strategy",
                'trust_impact' => "ALTER TABLE aei_contact_interactions ADD COLUMN trust_impact DECIMAL(3,2) DEFAULT 0.0 AFTER relationship_impact",
                'intimacy_impact' => "ALTER TABLE aei_contact_interactions ADD COLUMN intimacy_impact DECIMAL(3,2) DEFAULT 0.0 AFTER trust_impact",
                'is_conflict' => "ALTER TABLE aei_contact_interactions ADD COLUMN is_conflict BOOLEAN DEFAULT FALSE AFTER intimacy_impact",
                'conflict_category' => "ALTER TABLE aei_contact_interactions ADD COLUMN conflict_category ENUM('values', 'expectations', 'jealousy', 'betrayal', 'misunderstanding', 'boundary_violation') NULL AFTER is_conflict",
                'resolution_status' => "ALTER TABLE aei_contact_interactions ADD COLUMN resolution_status ENUM('unresolved', 'pending', 'partially_resolved', 'fully_resolved') NULL AFTER conflict_category",
                'resolution_method' => "ALTER TABLE aei_contact_interactions ADD COLUMN resolution_method ENUM('apology', 'compromise', 'boundary_setting', 'time', 'third_party', 'avoidance') NULL AFTER resolution_status",
                'memory_type' => "ALTER TABLE aei_contact_interactions ADD COLUMN memory_type ENUM('episodic', 'semantic', 'emotional', 'procedural') DEFAULT 'episodic' AFTER mentioned_in_chat",
                'memory_importance_score' => "ALTER TABLE aei_contact_interactions ADD COLUMN memory_importance_score DECIMAL(3,2) DEFAULT 0.5 AFTER memory_type"
            ];
            
            foreach ($advancedInteractionColumns as $columnName => $alterSQL) {
                if (!in_array($columnName, $interactionColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                    } catch (PDOException $e) {
                        error_log("Error adding interaction column $columnName: " . $e->getMessage());
                    }
                }
            }
            
            // Also change relationship_impact to DECIMAL for more precision
            try {
                $pdo->exec("ALTER TABLE aei_contact_interactions MODIFY COLUMN relationship_impact DECIMAL(4,2) DEFAULT 0.0");
            } catch (PDOException $e) {
                error_log("Error modifying relationship_impact column: " . $e->getMessage());
            }
        }
        
        // 4. Add message_context column to message_feedback table if it doesn't exist
        try {
            $stmt = $pdo->query("DESCRIBE message_feedback");
            if ($stmt) {
                $feedbackColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (!in_array('message_context', $feedbackColumns)) {
                    try {
                        $pdo->exec("ALTER TABLE message_feedback ADD COLUMN message_context JSON NULL AFTER feedback_category");
                        error_log("MIGRATION: Added column 'message_context' to message_feedback table");
                    } catch (PDOException $e) {
                        error_log("MIGRATION: Failed to add column 'message_context': " . $e->getMessage());
                    }
                }
            }
        } catch (PDOException $e) {
            // Table might not exist yet, ignore
        }
        
        // 5. Add image columns to chat_messages table for image upload support
        try {
            $stmt = $pdo->query("DESCRIBE chat_messages");
            $messageColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $imageColumns = [
                'has_image' => "ALTER TABLE chat_messages ADD COLUMN has_image BOOLEAN DEFAULT FALSE AFTER created_at",
                'image_filename' => "ALTER TABLE chat_messages ADD COLUMN image_filename VARCHAR(255) NULL AFTER has_image",
                'image_original_name' => "ALTER TABLE chat_messages ADD COLUMN image_original_name VARCHAR(255) NULL AFTER image_filename",
                'image_mime_type' => "ALTER TABLE chat_messages ADD COLUMN image_mime_type VARCHAR(100) NULL AFTER image_original_name",
                'image_size' => "ALTER TABLE chat_messages ADD COLUMN image_size INT NULL AFTER image_mime_type"
            ];
            
            foreach ($imageColumns as $columnName => $alterSQL) {
                if (!in_array($columnName, $messageColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                    } catch (PDOException $e) {
                        error_log("Error adding image column $columnName: " . $e->getMessage());
                    }
                }
            }
            
            // Add index for image messages if it doesn't exist
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_image_messages ON chat_messages (session_id, has_image)");
            } catch (PDOException $e) {
                error_log("Error adding image messages index: " . $e->getMessage());
            }
        } catch (PDOException $e) {
            error_log("Error migrating chat_messages for image support: " . $e->getMessage());
        }
        
        // 3. Migrate aei_social_context with all new advanced metrics
        $stmt = $pdo->query("DESCRIBE aei_social_context");
        if ($stmt) {
            $contextColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $advancedContextColumns = [
                'social_anxiety_level' => "ALTER TABLE aei_social_context ADD COLUMN social_anxiety_level INT DEFAULT 30 AFTER social_energy_level",
                'social_confidence_level' => "ALTER TABLE aei_social_context ADD COLUMN social_confidence_level INT DEFAULT 60 AFTER social_anxiety_level",
                'relationship_portfolio_balance' => "ALTER TABLE aei_social_context ADD COLUMN relationship_portfolio_balance DECIMAL(3,2) DEFAULT 0.5 AFTER social_confidence_level",
                'social_stimulation_preference' => "ALTER TABLE aei_social_context ADD COLUMN social_stimulation_preference ENUM('low', 'medium', 'high') DEFAULT 'medium' AFTER relationship_portfolio_balance",
                'current_social_phase' => "ALTER TABLE aei_social_context ADD COLUMN current_social_phase ENUM('expanding', 'maintaining', 'consolidating', 'withdrawing') DEFAULT 'maintaining' AFTER social_stimulation_preference",
                'conversation_starters' => "ALTER TABLE aei_social_context ADD COLUMN conversation_starters JSON AFTER topics_to_mention",
                'seasonal_social_patterns' => "ALTER TABLE aei_social_context ADD COLUMN seasonal_social_patterns JSON AFTER conversation_starters",
                'cultural_event_awareness' => "ALTER TABLE aei_social_context ADD COLUMN cultural_event_awareness JSON AFTER seasonal_social_patterns",
                'local_community_involvement' => "ALTER TABLE aei_social_context ADD COLUMN local_community_involvement JSON AFTER cultural_event_awareness",
                'social_network_density' => "ALTER TABLE aei_social_context ADD COLUMN social_network_density DECIMAL(3,2) DEFAULT 0.3 AFTER local_community_involvement",
                'friend_group_dynamics' => "ALTER TABLE aei_social_context ADD COLUMN friend_group_dynamics JSON AFTER social_network_density",
                'social_influence_map' => "ALTER TABLE aei_social_context ADD COLUMN social_influence_map JSON AFTER friend_group_dynamics",
                'absorbed_emotions' => "ALTER TABLE aei_social_context ADD COLUMN absorbed_emotions JSON AFTER social_influence_map",
                'emotional_support_burden_score' => "ALTER TABLE aei_social_context ADD COLUMN emotional_support_burden_score INT DEFAULT 0 AFTER absorbed_emotions",
                'emotional_resilience_level' => "ALTER TABLE aei_social_context ADD COLUMN emotional_resilience_level INT DEFAULT 70 AFTER emotional_support_burden_score",
                'pending_conflict_count' => "ALTER TABLE aei_social_context ADD COLUMN pending_conflict_count INT DEFAULT 0 AFTER unprocessed_interactions_count",
                'predicted_next_contacts' => "ALTER TABLE aei_social_context ADD COLUMN predicted_next_contacts JSON AFTER pending_conflict_count",
                'social_pattern_analysis' => "ALTER TABLE aei_social_context ADD COLUMN social_pattern_analysis JSON AFTER predicted_next_contacts",
                'relationship_health_scores' => "ALTER TABLE aei_social_context ADD COLUMN relationship_health_scores JSON AFTER social_pattern_analysis",
                'last_social_analysis' => "ALTER TABLE aei_social_context ADD COLUMN last_social_analysis TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER last_social_update"
            ];
            
            foreach ($advancedContextColumns as $columnName => $alterSQL) {
                if (!in_array($columnName, $contextColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                    } catch (PDOException $e) {
                        error_log("Error adding context column $columnName: " . $e->getMessage());
                    }
                }
            }
        }
        
    } catch (PDOException $e) {
        error_log("Advanced social features migration error: " . $e->getMessage());
    }
    
    // 14. Migrate aei_proactive_messages table for direct chat integration
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM aei_proactive_messages");
        $proactiveColumns = array_column($stmt->fetchAll(), 'Field');
        
        $proactiveMessageColumns = [
            'chat_message_id' => "ALTER TABLE aei_proactive_messages ADD COLUMN chat_message_id VARCHAR(32) NULL AFTER status"
        ];
        
        foreach ($proactiveMessageColumns as $columnName => $alterSQL) {
            if (!in_array($columnName, $proactiveColumns)) {
                try {
                    $pdo->exec($alterSQL);
                } catch (PDOException $e) {
                    error_log("Could not add proactive messages column $columnName: " . $e->getMessage());
                }
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet, ignore
    }
    
    // 15. Migrate aei_memories table for Qdrant Inference (2025)
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM aei_memories");
        if ($stmt) {
            $memoryColumns = array_column($stmt->fetchAll(), 'Field');
            
            $memoryMigrations = [
                'embedding_model' => "ALTER TABLE aei_memories ADD COLUMN embedding_model VARCHAR(100) DEFAULT 'sentence-transformers/all-MiniLM-L6-v2' AFTER importance_score"
            ];
            
            foreach ($memoryMigrations as $columnName => $alterSQL) {
                if (!in_array($columnName, $memoryColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                        error_log("Added aei_memories column: $columnName");
                    } catch (PDOException $e) {
                        error_log("Could not add aei_memories column $columnName: " . $e->getMessage());
                    }
                }
            }
            
            // Add index for embedding_model if it doesn't exist
            try {
                $stmt = $pdo->query("SHOW INDEX FROM aei_memories WHERE Key_name = 'idx_embedding_model'");
                if (!$stmt->fetch()) {
                    $pdo->exec("ALTER TABLE aei_memories ADD INDEX idx_embedding_model (embedding_model, created_at)");
                    error_log("Added aei_memories index: idx_embedding_model");
                }
            } catch (PDOException $e) {
                error_log("Could not add aei_memories index: " . $e->getMessage());
            }
        }
    } catch (PDOException $e) {
        // Table might not exist yet, ignore
    }
    
    // 16. Create API request logging table for training data
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'api_request_logs'");
        if (!$stmt->fetch()) {
            $pdo->exec("CREATE TABLE api_request_logs (
                id VARCHAR(32) PRIMARY KEY,
                user_id VARCHAR(32) NULL,
                aei_id VARCHAR(32) NULL,
                session_id VARCHAR(32) NULL,
                message_id VARCHAR(32) NULL,
                request_payload LONGTEXT NOT NULL,
                response_payload LONGTEXT,
                system_prompt LONGTEXT,
                user_message TEXT,
                ai_response TEXT,
                model VARCHAR(100),
                tokens_used INT,
                processing_time_ms INT,
                status VARCHAR(50) DEFAULT 'success',
                error_message TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user_aei (user_id, aei_id),
                INDEX idx_session (session_id),
                INDEX idx_created (created_at),
                INDEX idx_status (status),
                INDEX idx_model (model)
            )");
            error_log("Created api_request_logs table");
        }
    } catch (PDOException $e) {
        error_log("Could not create api_request_logs table: " . $e->getMessage());
    }
    
    // Create admin account if it doesn't exist
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute(['fabian.budde@nexinnovations.us']);
        if (!$stmt->fetch()) {
            $adminId = bin2hex(random_bytes(16));
            $passwordHash = password_hash('Fabian,123', PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, first_name, is_admin, is_onboarded) VALUES (?, ?, ?, ?, TRUE, TRUE)");
            $stmt->execute([$adminId, 'fabian.budde@nexinnovations.us', $passwordHash, 'Fabian']);
        }
    } catch (PDOException $e) {
        error_log("Failed to create admin account: " . $e->getMessage());
    }
    
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>