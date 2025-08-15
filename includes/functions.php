<?php
function sanitizeInput($input) {
    // Check if input is valid UTF-8, convert if needed
    if (!mb_check_encoding($input, 'UTF-8')) {
        $input = mb_convert_encoding($input, 'UTF-8', 'auto');
    }
    return htmlspecialchars(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

function generateId() {
    return bin2hex(random_bytes(16));
}

function getUserSession() {
    return $_SESSION['user_id'] ?? null;
}

function setUserSession($userId) {
    // Don't regenerate session ID on every login to allow multi-device sessions
    // Only regenerate if this is a new session or security risk
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] !== $userId) {
        session_regenerate_id(true);
    }
    
    $_SESSION['user_id'] = $userId;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['device_fingerprint'] = generateDeviceFingerprint();
    
    // Extend session lifetime when user logs in
    setcookie(session_name(), session_id(), time() + 2592000, '/'); // 30 days
}

function generateDeviceFingerprint() {
    // Simple device fingerprint to detect suspicious activity
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    
    return hash('sha256', $userAgent . $ip . $acceptLanguage);
}

function clearUserSession() {
    session_destroy();
}

function isLoggedIn() {
    $userId = getUserSession();
    if (!$userId) return false;
    
    // Basic device fingerprint validation for security
    $currentFingerprint = generateDeviceFingerprint();
    $sessionFingerprint = $_SESSION['device_fingerprint'] ?? null;
    
    // If fingerprint changed significantly, require re-login (optional security)
    // For now, we'll just log it but allow the session to continue
    if ($sessionFingerprint && $sessionFingerprint !== $currentFingerprint) {
        error_log("Device fingerprint changed for user $userId. Session: $sessionFingerprint, Current: $currentFingerprint");
        // Could invalidate session here for extra security: return false;
    }
    
    // Update last activity
    $_SESSION['last_activity'] = time();
    
    // Refresh cookie on each request to maintain 30-day sliding window
    setcookie(session_name(), session_id(), time() + 2592000, '/');
    
    return true;
}

function isAdmin() {
    global $pdo;
    if (!isLoggedIn()) return false;
    
    try {
        $stmt = $pdo->prepare("SELECT is_admin FROM users WHERE id = ?");
        $stmt->execute([getUserSession()]);
        $user = $stmt->fetch();
        return $user && $user['is_admin'];
    } catch (PDOException $e) {
        return false;
    }
}

function requireAuth() {
    if (!isLoggedIn()) {
        redirectTo('home');
    }
}

function requireOnboarding() {
    requireAuth();
    global $pdo;
    
    $userId = getUserSession();
    if (!$userId) {
        redirectTo('home');
        return;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT is_onboarded FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if ($user && !$user['is_onboarded']) {
            redirectTo('onboarding');
        }
    } catch (PDOException $e) {
        error_log("Error checking onboarding status: " . $e->getMessage());
        // Continue anyway to avoid blocking legitimate users
    }
}

function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        redirectTo('dashboard');
    }
}

function getCurrentTimestamp() {
    return date('Y-m-d H:i:s');
}

function redirectTo($route, $params = []) {
    global $router;
    
    // Handle old-style page redirects for backward compatibility
    $routeMap = [
        'home' => '',
        'login' => 'admin/login',
        'dashboard' => 'dashboard',
        'admin' => 'admin',
        'create-aei' => 'create-aei',
        'chat' => 'chat',
        'profile' => 'profile',
        'onboarding' => 'onboarding'
    ];
    
    if (isset($routeMap[$route])) {
        $route = $routeMap[$route];
    }
    
    $url = $router->url($route, $params);
    header("Location: " . $url);
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