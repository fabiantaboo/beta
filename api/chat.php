<?php
// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output
ini_set('log_errors', 1);     // Log errors instead

session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';
include_once '../includes/anthropic_api.php';
include_once '../includes/emotions.php';

// Clear any unwanted output
ob_clean();
header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

// Validate CSRF token
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// Validate required fields
$message = sanitizeInput($input['message'] ?? '');
$aeiId = sanitizeInput($input['aei_id'] ?? '');

if (empty($message) || empty($aeiId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message and AEI ID are required']);
    exit;
}

// Validate message length
if (strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too long (max 2000 characters)']);
    exit;
}

try {
    // Verify AEI belongs to current user
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt->execute([$aeiId, getUserSession()]);
    $aei = $stmt->fetch();
    
    if (!$aei) {
        http_response_code(404);
        echo json_encode(['error' => 'AEI not found']);
        exit;
    }
    
    // Get or create chat session
    $stmt = $pdo->prepare("SELECT id FROM chat_sessions WHERE user_id = ? AND aei_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([getUserSession(), $aeiId]);
    $session = $stmt->fetch();
    
    if (!$session) {
        $sessionId = generateId();
        $stmt = $pdo->prepare("INSERT INTO chat_sessions (id, user_id, aei_id) VALUES (?, ?, ?)");
        $stmt->execute([$sessionId, getUserSession(), $aeiId]);
        
        // Initialize emotional state for new session
        $emotions = new Emotions($pdo);
        $emotions->initializeSessionEmotions($sessionId);
    } else {
        $sessionId = $session['id'];
    }
    
    // Start database transaction
    $pdo->beginTransaction();
    
    // Get user data for AI context
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([getUserSession()]);
    $user = $stmt->fetch();
    
    // Save user message
    $messageId = generateId();
    $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'user', ?)");
    $stmt->execute([$messageId, $sessionId, $message]);
    
    $userMessageTime = getCurrentTimestamp();
    
    // Generate AI response with complete context (include debug data for admins)
    $isAdmin = isAdmin();
    $aeiResponseData = generateAIResponse($message, $aei, $user, $sessionId, $isAdmin);
    
    // Handle debug response format
    if ($isAdmin && is_array($aeiResponseData)) {
        $aeiResponse = $aeiResponseData['response'];
        $debugData = $aeiResponseData['debug_data'];
    } else {
        $aeiResponse = $aeiResponseData;
        $debugData = null;
    }
    
    // Save AI response
    $aeiResponseId = generateId();
    $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'aei', ?)");
    $stmt->execute([$aeiResponseId, $sessionId, $aeiResponse]);
    
    // Store current emotional state with the AEI message
    $emotions = new Emotions($pdo);
    $currentEmotions = $emotions->getEmotionalState($sessionId);
    $emotions->storeMessageEmotions($aeiResponseId, $currentEmotions);
    
    $aeiMessageTime = getCurrentTimestamp();
    
    // Update session timestamp
    $stmt = $pdo->prepare("UPDATE chat_sessions SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);
    
    // Commit transaction
    $pdo->commit();
    
    // Clear output buffer and return successful response
    ob_clean();
    
    // Return successful response with both messages
    $response = [
        'success' => true,
        'messages' => [
            [
                'id' => $messageId,
                'sender_type' => 'user',
                'message_text' => $message,
                'created_at' => $userMessageTime,
                'sender_name' => 'You'
            ],
            [
                'id' => $aeiResponseId,
                'sender_type' => 'aei',
                'message_text' => $aeiResponse,
                'created_at' => $aeiMessageTime,
                'sender_name' => htmlspecialchars($aei['name'])
            ]
        ]
    ];
    
    // Add debug data for admins
    if ($isAdmin && $debugData) {
        $response['debug_data'] = $debugData;
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Rollback transaction on any error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Chat API error: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    
    // Clear any output buffer to ensure clean JSON
    ob_clean();
    
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message. Please try again.']);
}

// Ensure we end output buffering and send response
ob_end_flush();
?>