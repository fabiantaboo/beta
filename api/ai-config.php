<?php
header('Content-Type: application/json');

// Set CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';
include_once '../includes/anthropic_api.php';

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Get and validate input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['description']) || !isset($input['csrf_token'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields']);
    exit;
}

// Verify CSRF token
if (!verifyCSRFToken($input['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$description = trim($input['description']);

if (empty($description)) {
    http_response_code(400);
    echo json_encode(['error' => 'Description cannot be empty']);
    exit;
}

if (strlen($description) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Description too long (max 2000 characters)']);
    exit;
}

try {
    $config = generateAEIConfiguration($description);
    
    // Validate and sanitize the response
    $sanitizedConfig = [
        'name' => sanitizeInput($config['name'] ?? ''),
        'age' => max(18, min(100, intval($config['age'] ?? 25))),
        'gender' => in_array($config['gender'] ?? '', ['male', 'female', 'non-binary', 'other']) ? $config['gender'] : '',
        'personality_traits' => array_slice($config['personality_traits'] ?? [], 0, 8),
        'communication_style' => $config['communication_style'] ?? 'casual',
        'speaking_traits' => array_slice($config['speaking_traits'] ?? [], 0, 6),
        'interests' => array_slice($config['interests'] ?? [], 0, 10),
        'hair_color' => sanitizeInput($config['hair_color'] ?? ''),
        'eye_color' => sanitizeInput($config['eye_color'] ?? ''),
        'height' => sanitizeInput($config['height'] ?? ''),
        'build' => sanitizeInput($config['build'] ?? ''),
        'style' => sanitizeInput($config['style'] ?? ''),
        'background' => sanitizeInput($config['background'] ?? ''),
        'quirks' => sanitizeInput($config['quirks'] ?? ''),
        'occupation' => sanitizeInput($config['occupation'] ?? ''),
        'goals' => sanitizeInput($config['goals'] ?? ''),
        'relationship_type' => in_array($config['relationship_type'] ?? '', ['friend', 'romantic_partner', 'family_member', 'mentor', 'companion']) ? $config['relationship_type'] : 'friend',
        'relationship_history' => sanitizeInput($config['relationship_history'] ?? ''),
        'relationship_dynamics' => array_slice($config['relationship_dynamics'] ?? [], 0, 4)
    ];
    
    echo json_encode([
        'success' => true,
        'config' => $sanitizedConfig
    ]);
    
} catch (Exception $e) {
    error_log("AI Config Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate configuration. Please try again.']);
}
?>