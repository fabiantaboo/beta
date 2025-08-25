<?php
// API für das Laden älterer Chat-Nachrichten (Pagination)
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use centralized session configuration
include_once '../includes/session_config.php';

// Debug logging - removed for production

include_once '../config/database.php';
include_once '../includes/functions.php';

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
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    error_log("API Error: Failed to parse JSON input");
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

// Validate required parameters
$sessionId = $input['session_id'] ?? '';
$offset = (int)($input['offset'] ?? 0);
$limit = min((int)($input['limit'] ?? 20), 50); // Max 50 messages at once

if (empty($sessionId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing session_id']);
    exit;
}

try {
    // Verify user owns this session
    $stmt = $pdo->prepare("SELECT user_id FROM chat_sessions WHERE id = ?");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch();
    
    if (!$session || $session['user_id'] !== getUserSession()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        exit;
    }
    
    // Load older messages with pagination
    $stmt = $pdo->prepare("
        SELECT 
            cm.*, 
            mf.id as feedback_id,
            mf.rating as feedback_rating
        FROM chat_messages cm
        LEFT JOIN message_feedback mf ON cm.id = mf.message_id AND mf.user_id = ?
        WHERE cm.session_id = ? 
        ORDER BY cm.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([getUserSession(), $sessionId, $limit, $offset]);
    $messages = array_reverse($stmt->fetchAll()); // Reverse to show chronologically
    
    // Get total count
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chat_messages WHERE session_id = ?");
    $stmt->execute([$sessionId]);
    $total = $stmt->fetch()['total'];
    
    // Format messages for frontend
    $formattedMessages = [];
    foreach ($messages as $message) {
        $formattedMessages[] = [
            'id' => $message['id'],
            'sender_type' => $message['sender_type'],
            'message_text' => $message['message_text'],
            'created_at' => $message['created_at'],
            'has_image' => $message['has_image'],
            'image_filename' => $message['image_filename'],
            'image_original_name' => $message['image_original_name'],
            'feedback_id' => $message['feedback_id'],
            'feedback_rating' => $message['feedback_rating']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $formattedMessages,
        'total' => $total,
        'loaded' => count($formattedMessages),
        'has_more' => ($offset + $limit) < $total
    ]);
    
} catch (PDOException $e) {
    error_log("Error loading messages: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>