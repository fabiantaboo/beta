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
            INDEX idx_session_time (session_id, created_at)
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
            
            -- Relationship with AEI
            relationship_type ENUM('close_friend', 'friend', 'family', 'work_colleague', 'romantic_interest', 'acquaintance') NOT NULL,
            relationship_strength INT DEFAULT 50,
            contact_frequency ENUM('daily', 'weekly', 'monthly', 'rarely') DEFAULT 'weekly',
            
            -- Dynamic life (develops in background)
            current_life_situation TEXT,
            recent_life_events JSON,
            current_concerns TEXT,
            current_goals TEXT,
            
            -- Interaction tracking
            last_contact_initiated TIMESTAMP,
            last_life_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            is_active BOOLEAN DEFAULT TRUE,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            INDEX idx_aei_contacts (aei_id, relationship_type),
            INDEX idx_active_contacts (aei_id, is_active)
        )",
        'aei_contact_interactions' => "CREATE TABLE IF NOT EXISTS aei_contact_interactions (
            id VARCHAR(32) PRIMARY KEY,
            aei_id VARCHAR(32) NOT NULL,
            contact_id VARCHAR(32) NOT NULL,
            
            -- Interaction details
            interaction_type ENUM('shares_news', 'asks_for_advice', 'invites_to_activity', 'shares_problem', 'celebrates_together', 'casual_chat') NOT NULL,
            interaction_context TEXT,
            contact_message TEXT,
            
            -- AEI Response (simulated internal dialogue)
            aei_response TEXT,
            aei_thoughts TEXT,
            
            -- Emotional impact on AEI
            aei_emotional_response JSON,
            relationship_impact INT DEFAULT 0,
            
            -- Processing
            processed_for_emotions BOOLEAN DEFAULT FALSE,
            mentioned_in_chat BOOLEAN DEFAULT FALSE,
            
            occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES aei_social_contacts(id) ON DELETE CASCADE,
            INDEX idx_aei_interactions (aei_id, occurred_at),
            INDEX idx_unprocessed (aei_id, processed_for_emotions)
        )",
        'aei_social_context' => "CREATE TABLE IF NOT EXISTS aei_social_context (
            aei_id VARCHAR(32) PRIMARY KEY,
            
            -- Current social state
            social_satisfaction INT DEFAULT 70,
            social_energy_level INT DEFAULT 50,
            
            -- Chat integration
            recent_social_summary TEXT,
            current_social_concerns TEXT,
            topics_to_mention JSON,
            
            -- Unprocessed interactions
            unprocessed_interactions_count INT DEFAULT 0,
            
            last_social_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            
            FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE
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
                    'system_prompt' => "ALTER TABLE aeis ADD COLUMN system_prompt TEXT NULL AFTER relationship_context"
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
                    'social_personality_seed' => "ALTER TABLE aeis ADD COLUMN social_personality_seed VARCHAR(32) NULL AFTER social_initialized"
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
                'aei_thoughts' => "ALTER TABLE aei_contact_interactions ADD COLUMN aei_thoughts TEXT NULL AFTER aei_response"
            ];
            
            foreach ($dialogColumns as $columnName => $alterSQL) {
                if (!in_array($columnName, $interactionColumns)) {
                    try {
                        $pdo->exec($alterSQL);
                    } catch (PDOException $e) {
                        // Column might already exist, ignore error
                    }
                }
            }
        }
    } catch (PDOException $e) {
        // Error reading columns, ignore
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