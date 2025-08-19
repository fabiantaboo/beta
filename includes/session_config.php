<?php
// Centralized session configuration for all API endpoints and pages
// This ensures consistent session settings across the entire application

// Configure session for VERY long lifetime (30 days) - MUST be before session_start()
ini_set('session.gc_maxlifetime', 2592000); // 30 days
ini_set('session.cookie_lifetime', 2592000); // 30 days

// CRITICAL: Reduce garbage collection frequency to prevent premature cleanup
// Only run GC 1 in 1000 requests instead of 1 in 100
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 1000); // Changed from 100 to 1000

ini_set('session.use_strict_mode', 1);
ini_set('session.use_only_cookies', 1);

// Force session storage method and ensure we have a persistent path
ini_set('session.save_handler', 'files');

// Try to set a custom session save path if possible (may fail on some hosts)
$customSessionPath = __DIR__ . '/../temp/sessions';
if (!is_dir($customSessionPath)) {
    @mkdir($customSessionPath, 0755, true);
}
if (is_dir($customSessionPath) && is_writable($customSessionPath)) {
    ini_set('session.save_path', $customSessionPath);
} else {
    // Fallback: try system temp directory
    $systemTemp = sys_get_temp_dir() . '/ayuni_sessions';
    if (!is_dir($systemTemp)) {
        @mkdir($systemTemp, 0755, true);
    }
    if (is_dir($systemTemp) && is_writable($systemTemp)) {
        ini_set('session.save_path', $systemTemp);
    }
}

// Set session cookie parameters BEFORE starting session
session_set_cookie_params([
    'lifetime' => 2592000, // 30 days
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
    'httponly' => true,
    'samesite' => 'Lax'  // Lax is better for login flows
]);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}