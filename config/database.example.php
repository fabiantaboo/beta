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
            personality TEXT,
            appearance_description TEXT,
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
            FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
            INDEX idx_session_time (session_id, created_at)
        )",
        'api_settings' => "CREATE TABLE IF NOT EXISTS api_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
            // Add system_prompt column if it doesn't exist
            try {
                $stmt = $pdo->query("DESCRIBE aeis");
                $aeiColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('system_prompt', $aeiColumns)) {
                    $pdo->exec("ALTER TABLE aeis ADD COLUMN system_prompt TEXT NULL AFTER appearance_description");
                }
            } catch (PDOException $e) {
                // Column might already exist, ignore error
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