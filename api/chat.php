<?php
session_start();

include_once '../config/database.php';
include_once '../includes/functions.php';
include_once '../includes/anthropic_api.php';

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
    
    // Generate AI response with complete context
    $aeiResponse = generateAIResponse($message, $aei, $user, $sessionId);
    
    // Save AI response
    $aeiResponseId = generateId();
    $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'aei', ?)");
    $stmt->execute([$aeiResponseId, $sessionId, $aeiResponse]);
    
    $aeiMessageTime = getCurrentTimestamp();
    
    // Update session timestamp
    $stmt = $pdo->prepare("UPDATE chat_sessions SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$sessionId]);
    
    // Commit transaction
    $pdo->commit();
    
    // Return successful response with both messages
    echo json_encode([
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
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on any error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Chat API error: " . $e->getMessage());
    error_log("AEI ID: " . $aeiId . ", User ID: " . getUserSession());
    
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send message. Please try again.']);
}
?>