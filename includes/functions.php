<?php
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function generateId() {
    return bin2hex(random_bytes(16));
}

function getUserSession() {
    return $_SESSION['user_id'] ?? null;
}

function setUserSession($userId) {
    $_SESSION['user_id'] = $userId;
}

function clearUserSession() {
    session_destroy();
}

function getCurrentTimestamp() {
    return date('Y-m-d H:i:s');
}

function redirectTo($page) {
    header("Location: index.php?page=" . urlencode($page));
    exit;
}

function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireValidSession() {
    if (!getUserSession()) {
        redirectTo('home');
    }
}
?>