-- Ayuni Beta Database Schema

CREATE DATABASE IF NOT EXISTS ayuni_beta CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ayuni_beta;

-- Users table for basic user management
CREATE TABLE users (
    id VARCHAR(32) PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_onboarded BOOLEAN DEFAULT FALSE
);

-- AEIs table - the AI companions
CREATE TABLE aeis (
    id VARCHAR(32) PRIMARY KEY,
    user_id VARCHAR(32) NOT NULL,
    name VARCHAR(100) NOT NULL,
    personality TEXT,
    appearance_description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id)
);

-- Chat sessions between users and AEIs
CREATE TABLE chat_sessions (
    id VARCHAR(32) PRIMARY KEY,
    user_id VARCHAR(32) NOT NULL,
    aei_id VARCHAR(32) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_message_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
    INDEX idx_user_aei (user_id, aei_id)
);

-- Individual chat messages
CREATE TABLE chat_messages (
    id VARCHAR(32) PRIMARY KEY,
    session_id VARCHAR(32) NOT NULL,
    sender_type ENUM('user', 'aei') NOT NULL,
    message_text TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES chat_sessions(id) ON DELETE CASCADE,
    INDEX idx_session_time (session_id, created_at)
);