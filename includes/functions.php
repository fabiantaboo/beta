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
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function clearUserSession() {
    session_destroy();
}

function isLoggedIn() {
    return getUserSession() !== null;
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