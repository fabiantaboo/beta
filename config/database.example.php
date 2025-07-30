<?php
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
            first_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
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
            FOREIGN KEY (beta_code) REFERENCES beta_codes(code)
        )",
        'aeis' => "CREATE TABLE IF NOT EXISTS aeis (
            id VARCHAR(32) PRIMARY KEY,
            user_id VARCHAR(32) NOT NULL,
            name VARCHAR(100) NOT NULL,
            personality TEXT,
            appearance_description TEXT,
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