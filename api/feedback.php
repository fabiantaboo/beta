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

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Get JSON data
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$required_fields = ['message_id', 'rating', 'csrf_token'];
foreach ($required_fields as $field) {
    if (!isset($input[$field]) || empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

// Verify CSRF token
if (!verifyCSRFToken($input['csrf_token'])) {
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
    $messageContext = array_map(function($msg) {
        return [
            'id' => $msg['id'],
            'sender_type' => $msg['sender_type'],
            'message_text' => $msg['message_text'],
            'has_image' => (bool)$msg['has_image'],
            'image_name' => $msg['image_original_name'],
            'timestamp' => $msg['created_at'],
            'is_target' => $msg['id'] === $input['message_id'] // Mark which message the feedback is for
        ];
    }, $contextMessages);
    
    // Check if feedback already exists for this user/message combination
    $stmt = $pdo->prepare("SELECT id FROM message_feedback WHERE user_id = ? AND message_id = ?");
    $stmt->execute([$userId, $input['message_id']]);
    $existingFeedback = $stmt->fetch();
    
    if ($existingFeedback) {
        // Update existing feedback
        $stmt = $pdo->prepare("
            UPDATE message_feedback 
            SET rating = ?, feedback_text = ?, feedback_category = ?, message_context = ?, updated_at = NOW(), 
                ip_address = ?, user_agent = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $input['rating'],
            $feedbackText,
            $input['category'] ?? null,
            json_encode($messageContext, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $existingFeedback['id']
        ]);
        
        $feedbackId = $existingFeedback['id'];
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