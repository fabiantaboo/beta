<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Use centralized session configuration
include_once '../includes/session_config.php';

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Get JSON data
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    error_log("Feedback API: Invalid JSON received - Raw input: " . substr($rawInput, 0, 500));
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid JSON data', 
        'debug' => [
            'received_length' => strlen($rawInput),
            'json_error' => json_last_error_msg()
        ]
    ]);
    exit;
}

// Validate required fields
$required_fields = ['message_id', 'rating', 'csrf_token'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        error_log("Feedback API: Missing field '$field' in request from user: " . (getUserSession() ?? 'not logged in'));
        http_response_code(400);
        echo json_encode([
            'error' => "Missing required field: $field",
            'debug' => [
                'received_fields' => array_keys($input),
                'field_values' => array_map(function($v) { return is_string($v) ? (strlen($v) > 50 ? substr($v, 0, 50) . '...' : $v) : gettype($v); }, $input)
            ]
        ]);
        exit;
    }
}

// Verify CSRF token
if (!verifyCSRFToken($input['csrf_token'])) {
    error_log("CSRF token verification failed. Received: " . ($input['csrf_token'] ?? 'null') . ", Session token: " . ($_SESSION['csrf_token'] ?? 'null'));
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// Check if user is authenticated
$userId = getUserSession();
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

// Validate rating
if (!in_array($input['rating'], ['thumbs_up', 'thumbs_down'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid rating value']);
    exit;
}

// Validate category if provided
$allowed_categories = ['helpful', 'accurate', 'engaging', 'inappropriate', 'inaccurate', 'boring', 'other'];
if (!empty($input['category']) && !in_array($input['category'], $allowed_categories)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid feedback category']);
    exit;
}

// Sanitize feedback text
$feedbackText = !empty($input['feedback_text']) ? trim($input['feedback_text']) : null;
if ($feedbackText && strlen($feedbackText) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'Feedback text too long (max 500 characters)']);
    exit;
}

try {
    // Verify that the message exists and belongs to a session the user has access to
    $stmt = $pdo->prepare("
        SELECT cm.id, cm.sender_type, cs.user_id, cs.aei_id, cs.id as session_id, cm.created_at
        FROM chat_messages cm
        JOIN chat_sessions cs ON cm.session_id = cs.id
        WHERE cm.id = ? AND cs.user_id = ?
    ");
    $stmt->execute([$input['message_id'], $userId]);
    $message = $stmt->fetch();
    
    if (!$message) {
        http_response_code(404);
        echo json_encode(['error' => 'Message not found or access denied']);
        exit;
    }
    
    // Only allow feedback on AEI messages
    if ($message['sender_type'] !== 'aei') {
        http_response_code(400);
        echo json_encode(['error' => 'Feedback can only be submitted for AEI responses']);
        exit;
    }
    
    // Get the last 20 messages from this session as context (including the feedback target message)
    $stmt = $pdo->prepare("
        SELECT 
            id, 
            sender_type, 
            message_text, 
            created_at,
            has_image,
            image_original_name
        FROM chat_messages 
        WHERE session_id = ? AND created_at <= ?
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $stmt->execute([$message['session_id'], $message['created_at']]);
    $contextMessages = $stmt->fetchAll();
    
    // Reverse to get chronological order (oldest first)
    $contextMessages = array_reverse($contextMessages);
    
    // Format context for storage
    $messageContext = array_map(function($msg) use ($input) {
        return [
            'id' => $msg['id'],
            'sender_type' => $msg['sender_type'],
            'message_text' => $msg['message_text'],
            'has_image' => (bool)$msg['has_image'],
            'image_name' => $msg['image_original_name'],
            'timestamp' => $msg['created_at'],
            'is_target' => (string)$msg['id'] === (string)$input['message_id'], // Mark which message the feedback is for
        ];
    }, $contextMessages);
    
    // Check if feedback already exists for this user/message combination
    $stmt = $pdo->prepare("SELECT id FROM message_feedback WHERE user_id = ? AND message_id = ?");
    $stmt->execute([$userId, $input['message_id']]);
    $existingFeedback = $stmt->fetch();
    
    if ($existingFeedback) {
        // Prevent overwriting existing feedback
        error_log("Feedback API: User $userId attempted to submit duplicate feedback for message " . $input['message_id']);
        http_response_code(409); // Conflict
        echo json_encode([
            'error' => 'You have already provided feedback for this message. Each message can only be rated once.',
            'existing_feedback_id' => $existingFeedback['id']
        ]);
        exit;
    } else {
        // Create new feedback
        $feedbackId = generateId();
        $stmt = $pdo->prepare("
            INSERT INTO message_feedback 
            (id, message_id, user_id, session_id, aei_id, rating, feedback_text, feedback_category, message_context, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $feedbackId,
            $input['message_id'],
            $userId,
            $message['session_id'],
            $message['aei_id'],
            $input['rating'],
            $feedbackText,
            $input['category'] ?? null,
            json_encode($messageContext, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Feedback submitted successfully',
        'feedback_id' => $feedbackId,
        'action' => $existingFeedback ? 'updated' : 'created'
    ]);
    
} catch (PDOException $e) {
    error_log("Feedback submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred']);
    exit;
} catch (Exception $e) {
    error_log("Feedback submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred']);
    exit;
}
?>