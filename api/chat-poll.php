<?php
// Chat completion polling API for PWA background processing
// Use centralized session configuration
include_once '../includes/session_config.php';

include_once '../config/database.php';
include_once '../includes/functions.php';

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

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid input']);
    exit;
}

// Validate CSRF token
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

$requestId = $input['request_id'] ?? '';
if (empty($requestId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Request ID required']);
    exit;
}

try {
    // Check for completed messages from the last 5 minutes that might have been missed
    // Look for recent AEI messages in user's sessions
    $userId = getUserSession();
    
    $stmt = $pdo->prepare("
        SELECT cm.*, aei.name as aei_name
        FROM chat_messages cm
        JOIN chat_sessions cs ON cm.session_id = cs.id
        JOIN aeis aei ON cs.aei_id = aei.id
        WHERE cs.user_id = ? 
        AND cm.sender_type = 'aei'
        AND cm.created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ORDER BY cm.created_at DESC 
        LIMIT 1
    ");
    
    $stmt->execute([$userId]);
    $recentMessage = $stmt->fetch();
    
    if ($recentMessage) {
        // Get additional message metadata that might be needed
        $messageData = [
            'id' => $recentMessage['id'],
            'sender_type' => $recentMessage['sender_type'],
            'message_text' => $recentMessage['message_text'],
            'created_at' => $recentMessage['created_at'],
            'sender_name' => $recentMessage['aei_name'],
            'has_image' => $recentMessage['has_image'] ? true : false,
            'image_filename' => $recentMessage['image_filename'],
            'image_original_name' => $recentMessage['image_original_name']
        ];
        
        // For admin users, include debug data if available
        $debugData = null;
        if (isAdmin()) {
            // Try to get debug data for this message (stored emotions, memory context, etc.)
            // Note: This is a limitation - we can't reconstruct the full debug data
            // that was available during generation, but we can provide what we have
            $debugData = [
                'message_retrieved_via' => 'polling_api',
                'original_generation_time' => $recentMessage['created_at'],
                'note' => 'Full debug data not available for polled messages',
                'memory_system' => 'not_available_in_polling',
                'emotions_system' => 'message_stored_separately'
            ];
        }
        
        $response = [
            'status' => 'completed',
            'message' => $messageData
        ];
        
        if ($debugData) {
            $response['debug_data'] = $debugData;
        }
        
        echo json_encode($response);
    } else {
        // No recent messages found
        echo json_encode([
            'status' => 'pending'
        ]);
    }
    
} catch (Exception $e) {
    error_log("Chat poll error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Poll failed']);
}
?>