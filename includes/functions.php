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

function getTimezoneList() {
    return [
        // Major US timezones
        'America/New_York' => 'Eastern Time (US & Canada)',
        'America/Chicago' => 'Central Time (US & Canada)', 
        'America/Denver' => 'Mountain Time (US & Canada)',
        'America/Los_Angeles' => 'Pacific Time (US & Canada)',
        'America/Anchorage' => 'Alaska Time',
        'Pacific/Honolulu' => 'Hawaii Time',
        
        // Major European timezones
        'Europe/London' => 'London (GMT/BST)',
        'Europe/Dublin' => 'Dublin (GMT/IST)',
        'Europe/Berlin' => 'Berlin (CET/CEST)',
        'Europe/Paris' => 'Paris (CET/CEST)', 
        'Europe/Madrid' => 'Madrid (CET/CEST)',
        'Europe/Rome' => 'Rome (CET/CEST)',
        'Europe/Amsterdam' => 'Amsterdam (CET/CEST)',
        'Europe/Brussels' => 'Brussels (CET/CEST)',
        'Europe/Vienna' => 'Vienna (CET/CEST)',
        'Europe/Zurich' => 'Zurich (CET/CEST)',
        'Europe/Stockholm' => 'Stockholm (CET/CEST)',
        'Europe/Copenhagen' => 'Copenhagen (CET/CEST)',
        'Europe/Oslo' => 'Oslo (CET/CEST)',
        'Europe/Helsinki' => 'Helsinki (EET/EEST)',
        'Europe/Warsaw' => 'Warsaw (CET/CEST)',
        'Europe/Prague' => 'Prague (CET/CEST)',
        'Europe/Budapest' => 'Budapest (CET/CEST)',
        'Europe/Athens' => 'Athens (EET/EEST)',
        'Europe/Istanbul' => 'Istanbul (TRT)',
        'Europe/Moscow' => 'Moscow (MSK)',
        'Europe/Kiev' => 'Kiev (EET/EEST)',
        
        // Asian timezones
        'Asia/Tokyo' => 'Tokyo (JST)',
        'Asia/Shanghai' => 'Beijing/Shanghai (CST)',
        'Asia/Hong_Kong' => 'Hong Kong (HKT)',
        'Asia/Singapore' => 'Singapore (SGT)',
        'Asia/Seoul' => 'Seoul (KST)',
        'Asia/Manila' => 'Manila (PHT)',
        'Asia/Bangkok' => 'Bangkok (ICT)',
        'Asia/Jakarta' => 'Jakarta (WIB)',
        'Asia/Kuala_Lumpur' => 'Kuala Lumpur (MYT)',
        'Asia/Kolkata' => 'Mumbai/Delhi (IST)',
        'Asia/Dubai' => 'Dubai (GST)',
        'Asia/Tehran' => 'Tehran (IRST)',
        'Asia/Kabul' => 'Kabul (AFT)',
        'Asia/Karachi' => 'Karachi (PKT)',
        'Asia/Dhaka' => 'Dhaka (BST)',
        'Asia/Kathmandu' => 'Kathmandu (NPT)',
        'Asia/Colombo' => 'Colombo (IST)',
        
        // Middle East & Africa
        'Africa/Cairo' => 'Cairo (EET)',
        'Africa/Lagos' => 'Lagos (WAT)',
        'Africa/Nairobi' => 'Nairobi (EAT)',
        'Africa/Johannesburg' => 'Johannesburg (SAST)',
        'Africa/Casablanca' => 'Casablanca (WET)',
        'Africa/Algiers' => 'Algiers (CET)',
        'Asia/Jerusalem' => 'Jerusalem (IST)',
        'Asia/Riyadh' => 'Riyadh (AST)',
        
        // Australia & Pacific
        'Australia/Sydney' => 'Sydney (AEDT/AEST)',
        'Australia/Melbourne' => 'Melbourne (AEDT/AEST)',
        'Australia/Brisbane' => 'Brisbane (AEST)',
        'Australia/Perth' => 'Perth (AWST)',
        'Australia/Adelaide' => 'Adelaide (ACDT/ACST)',
        'Australia/Darwin' => 'Darwin (ACST)',
        'Pacific/Auckland' => 'Auckland (NZDT/NZST)',
        'Pacific/Fiji' => 'Fiji (FJT)',
        'Pacific/Tahiti' => 'Tahiti (TAHT)',
        'Pacific/Guam' => 'Guam (ChST)',
        
        // Americas (South & Central)
        'America/Mexico_City' => 'Mexico City (CST)',
        'America/Bogota' => 'Bogota (COT)',
        'America/Lima' => 'Lima (PET)',
        'America/Santiago' => 'Santiago (CLT)',
        'America/Buenos_Aires' => 'Buenos Aires (ART)',
        'America/Sao_Paulo' => 'São Paulo (BRT)',
        'America/Caracas' => 'Caracas (VET)',
        'America/Toronto' => 'Toronto (EST/EDT)',
        'America/Vancouver' => 'Vancouver (PST/PDT)',
        'America/Montreal' => 'Montreal (EST/EDT)',
        
        // UTC and special
        'UTC' => 'UTC (Coordinated Universal Time)',
    ];
}

function renderTimezoneSelect($name, $selectedValue = 'UTC', $required = false, $searchable = true) {
    $timezones = getTimezoneList();
    $requiredAttr = $required ? 'required' : '';
    $selectId = $name . '_select';
    
    echo '<div class="timezone-select-container">';
    
    if ($searchable) {
        // Create searchable select with JavaScript
        echo '<div class="relative">';
        echo '<input type="text" id="' . $selectId . '_search" placeholder="Search timezones..." class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm min-h-[44px]">';
        echo '<div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">';
        echo '<i class="fas fa-search text-gray-400"></i>';
        echo '</div>';
        echo '</div>';
        
        echo '<select name="' . $name . '" id="' . $selectId . '" ' . $requiredAttr . ' class="hidden">';
        echo '<option value="">Select timezone</option>';
        foreach ($timezones as $tz => $label) {
            $selected = ($selectedValue === $tz) ? 'selected' : '';
            echo '<option value="' . htmlspecialchars($tz) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        echo '</select>';
        
        echo '<div id="' . $selectId . '_dropdown" class="hidden absolute z-50 w-full bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md shadow-lg max-h-60 overflow-y-auto mt-1">';
        foreach ($timezones as $tz => $label) {
            echo '<div class="timezone-option px-3 py-2 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer" data-value="' . htmlspecialchars($tz) . '">' . htmlspecialchars($label) . '</div>';
        }
        echo '</div>';
        
    } else {
        // Regular select
        echo '<select name="' . $name . '" id="' . $selectId . '" ' . $requiredAttr . ' class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm min-h-[44px]">';
        echo '<option value="">Select timezone</option>';
        foreach ($timezones as $tz => $label) {
            $selected = ($selectedValue === $tz) ? 'selected' : '';
            echo '<option value="' . htmlspecialchars($tz) . '" ' . $selected . '>' . htmlspecialchars($label) . '</option>';
        }
        echo '</select>';
    }
    
    echo '</div>';
    
    if ($searchable) {
        // Add JavaScript for searchable functionality
        echo '<script>';
        echo 'document.addEventListener("DOMContentLoaded", function() {';
        echo '    setupTimezoneSearch("' . $selectId . '");';
        echo '});';
        echo '</script>';
    }
}
?>