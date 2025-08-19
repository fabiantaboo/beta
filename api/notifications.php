<?php
// Simple notification system for offline users
// This could be extended to send actual push notifications, emails, etc.

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use centralized session configuration
include_once '../includes/session_config.php';

include_once '../config/database.php';
include_once '../includes/functions.php';

ob_clean();
header('Content-Type: application/json');

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = getUserSession();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get pending proactive messages for offline notification
    try {
        $stmt = $pdo->prepare("
            SELECT 
                pm.id,
                pm.message_text,
                pm.message_tone,
                pm.trigger_type,
                pm.generated_at,
                a.name as aei_name,
                a.id as aei_id,
                cs.id as session_id
            FROM aei_proactive_messages pm
            JOIN aeis a ON pm.aei_id = a.id
            JOIN chat_sessions cs ON pm.session_id = cs.id
            WHERE a.user_id = ?
            AND pm.status = 'pending'
            AND pm.scheduled_for <= NOW()
            AND (pm.expires_at IS NULL OR pm.expires_at > NOW())
            ORDER BY pm.trigger_strength DESC, pm.generated_at ASC
            LIMIT 5
        ");
        $stmt->execute([$userId]);
        
        $notifications = $stmt->fetchAll();
        
        // Format for display
        $formattedNotifications = [];
        foreach ($notifications as $notification) {
            $formattedNotifications[] = [
                'id' => $notification['id'],
                'title' => $notification['aei_name'] . ' wants to talk',
                'message' => truncateText($notification['message_text'], 100),
                'type' => 'proactive_message',
                'aei_name' => $notification['aei_name'],
                'aei_id' => $notification['aei_id'],
                'session_id' => $notification['session_id'],
                'tone' => $notification['message_tone'],
                'trigger_type' => $notification['trigger_type'],
                'generated_at' => $notification['generated_at'],
                'url' => '/chat/' . $notification['aei_id']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'notifications' => $formattedNotifications,
            'count' => count($formattedNotifications)
        ]);
        
    } catch (Exception $e) {
        error_log("Notification API error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to fetch notifications']);
    }
    
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mark notification as seen/dismissed
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }
    
    $notificationId = $input['notification_id'] ?? '';
    $action = $input['action'] ?? 'dismiss'; // dismiss or view
    
    if (empty($notificationId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Notification ID required']);
        exit;
    }
    
    try {
        // Verify the notification belongs to this user
        $stmt = $pdo->prepare("
            SELECT pm.* FROM aei_proactive_messages pm
            JOIN aeis a ON pm.aei_id = a.id
            WHERE pm.id = ? AND a.user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        $notification = $stmt->fetch();
        
        if (!$notification) {
            http_response_code(404);
            echo json_encode(['error' => 'Notification not found']);
            exit;
        }
        
        if ($action === 'dismiss') {
            // Dismiss the notification
            $stmt = $pdo->prepare("
                UPDATE aei_proactive_messages 
                SET status = 'dismissed' 
                WHERE id = ?
            ");
            $stmt->execute([$notificationId]);
            
            include_once '../includes/proactive_messaging.php';
            $proactiveMessaging = new ProactiveMessaging($pdo);
            $proactiveMessaging->recordUserReaction($notificationId, 'ignored');
            
        } elseif ($action === 'view') {
            // Mark as viewed (but don't dismiss - user might engage)
            $stmt = $pdo->prepare("
                UPDATE aei_proactive_messages 
                SET user_reaction = 'neutral'
                WHERE id = ? AND user_reaction IS NULL
            ");
            $stmt->execute([$notificationId]);
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        error_log("Notification action error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to process notification']);
    }
    
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}

function truncateText($text, $maxLength) {
    if (strlen($text) <= $maxLength) return $text;
    return substr($text, 0, $maxLength) . '...';
}

ob_end_flush();
?>