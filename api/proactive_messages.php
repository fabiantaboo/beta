<?php
// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in output
ini_set('log_errors', 1);     // Log errors instead

// Use centralized session configuration
include_once '../includes/session_config.php';

include_once '../config/database.php';
include_once '../includes/functions.php';
include_once '../includes/proactive_messaging.php';

// Clear any unwanted output
ob_clean();
header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$proactiveMessaging = new ProactiveMessaging($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get pending proactive messages
    $aeiId = $_GET['aei_id'] ?? '';
    $sessionId = $_GET['session_id'] ?? '';
    
    if (empty($aeiId) || empty($sessionId)) {
        http_response_code(400);
        echo json_encode(['error' => 'AEI ID and Session ID are required']);
        exit;
    }
    
    // Verify AEI belongs to current user
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt->execute([$aeiId, getUserSession()]);
    $aei = $stmt->fetch();
    
    if (!$aei) {
        http_response_code(404);
        echo json_encode(['error' => 'AEI not found']);
        exit;
    }
    
    try {
        $pendingMessages = $proactiveMessaging->getPendingProactiveMessages($aeiId, $sessionId);
        
        echo json_encode([
            'success' => true,
            'messages' => $pendingMessages
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting proactive messages: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to get proactive messages']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle user interaction with proactive message
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
    
    $action = $input['action'] ?? '';
    $messageId = $input['message_id'] ?? '';
    
    if (empty($action) || empty($messageId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Action and message ID are required']);
        exit;
    }
    
    try {
        // Verify the proactive message belongs to user's AEI
        $stmt = $pdo->prepare("
            SELECT pm.*, a.user_id 
            FROM aei_proactive_messages pm
            JOIN aeis a ON pm.aei_id = a.id
            WHERE pm.id = ? AND a.user_id = ?
        ");
        $stmt->execute([$messageId, getUserSession()]);
        $proactiveMessage = $stmt->fetch();
        
        if (!$proactiveMessage) {
            http_response_code(404);
            echo json_encode(['error' => 'Proactive message not found']);
            exit;
        }
        
        if ($action === 'send') {
            // Send the proactive message as AEI message
            $aeiMessageId = generateId();
            
            $stmt = $pdo->prepare("
                INSERT INTO chat_messages (id, session_id, sender_type, message_text) 
                VALUES (?, ?, 'aei', ?)
            ");
            $stmt->execute([
                $aeiMessageId, 
                $proactiveMessage['session_id'], 
                $proactiveMessage['message_text']
            ]);
            
            // Mark proactive message as sent
            $proactiveMessaging->markMessageAsSent($messageId);
            
            // Return the new message data
            echo json_encode([
                'success' => true,
                'message' => [
                    'id' => $aeiMessageId,
                    'sender_type' => 'aei',
                    'message_text' => $proactiveMessage['message_text'],
                    'created_at' => getCurrentTimestamp(),
                    'is_proactive' => true,
                    'proactive_id' => $messageId
                ]
            ]);
            
        } elseif ($action === 'dismiss') {
            // Dismiss the proactive message
            $stmt = $pdo->prepare("
                UPDATE aei_proactive_messages 
                SET status = 'dismissed' 
                WHERE id = ?
            ");
            $stmt->execute([$messageId]);
            
            // Record negative reaction for learning
            $proactiveMessaging->recordUserReaction($messageId, 'ignored');
            
            echo json_encode(['success' => true]);
            
        } elseif ($action === 'react') {
            // Record user reaction to sent proactive message
            $reaction = $input['reaction'] ?? 'neutral';
            $userResponse = $input['user_response'] ?? null;
            $conversationContinued = (bool)($input['conversation_continued'] ?? false);
            
            $proactiveMessaging->recordUserReaction($messageId, $reaction, $userResponse, $conversationContinued);
            
            echo json_encode(['success' => true]);
            
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
        }
        
    } catch (Exception $e) {
        error_log("Error handling proactive message: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to handle proactive message']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

// Ensure we end output buffering and send response
ob_end_flush();
?>