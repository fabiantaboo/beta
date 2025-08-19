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
include_once '../includes/image_upload.php';
include_once '../includes/proactive_messaging.php';

// Clear any unwanted output
ob_clean();
header('Content-Type: application/json; charset=UTF-8');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Check authentication
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle both JSON and FormData input (for image uploads)
$input = null;
$uploadedImage = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    // FormData request with image
    $input = [
        'message' => $_POST['message'] ?? '',
        'aei_id' => $_POST['aei_id'] ?? '',
        'csrf_token' => $_POST['csrf_token'] ?? ''
    ];
    
    // Handle image upload
    $imageHandler = new ImageUploadHandler();
    $uploadResult = $imageHandler->handleUpload($_FILES['image']);
    
    if (!$uploadResult['success']) {
        http_response_code(400);
        echo json_encode(['error' => 'Image upload failed: ' . $uploadResult['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $uploadedImage = $uploadResult;
    
} else {
    // JSON request (text only)
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid input'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Validate CSRF token
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate required fields
$message = sanitizeInput($input['message'] ?? '');
$aeiId = sanitizeInput($input['aei_id'] ?? '');

if ((empty($message) && !$uploadedImage) || empty($aeiId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Message or image and AEI ID are required'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Validate message length
if (strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too long (max 2000 characters)'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Verify AEI belongs to current user
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt->execute([$aeiId, getUserSession()]);
    $aei = $stmt->fetch();
    
    if (!$aei) {
        http_response_code(404);
        echo json_encode(['error' => 'AEI not found'], JSON_UNESCAPED_UNICODE);
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
    
    if ($uploadedImage) {
        // Save message with image
        $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text, has_image, image_filename, image_original_name, image_mime_type, image_size) VALUES (?, ?, 'user', ?, TRUE, ?, ?, ?, ?)");
        $stmt->execute([
            $messageId, 
            $sessionId, 
            $message,
            $uploadedImage['filename'],
            $uploadedImage['original_name'],
            $uploadedImage['mime_type'],
            $uploadedImage['size']
        ]);
    } else {
        // Save text-only message
        $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'user', ?)");
        $stmt->execute([$messageId, $sessionId, $message]);
    }
    
    $userMessageTime = getCurrentTimestamp();
    
    // Generate AI response with complete context (include debug data for admins)
    $isAdmin = isAdmin();
    $aeiResponseData = generateAIResponse($message, $aei, $user, $sessionId, $isAdmin, $uploadedImage);
    
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
    
    // Process social context integration after chat
    if (isset($aei['social_initialized']) && $aei['social_initialized']) {
        require_once '../includes/aei_social_context.php';
        $socialContext = new AEISocialContext($pdo);
        
        // Mark social interactions that were used in this chat as mentioned
        $markedCount = $socialContext->markRecentInteractionsAsMentioned($aeiId);
        
        // Process any unprocessed social emotional impacts
        $emotionalImpact = $socialContext->processUnprocessedSocialUpdates($aeiId);
        
        if (!empty($emotionalImpact)) {
            // Apply social emotional impact to current session
            $emotions = new Emotions($pdo);
            $emotions->updateEmotions($sessionId, $emotionalImpact, 'social_interaction');
            error_log("Applied social emotional impact to session {$sessionId}: " . json_encode($emotionalImpact));
        }
        
        if ($markedCount > 0) {
            error_log("Social integration complete for AEI {$aeiId}: {$markedCount} interactions marked as mentioned");
        }
    }
    
    // Analyze for proactive messaging triggers (after the conversation)
    $proactiveMessaging = new ProactiveMessaging($pdo);
    $proactiveMessages = $proactiveMessaging->analyzeAndGenerateProactiveMessages($aeiId, $sessionId, getUserSession());
    
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
                'sender_name' => 'You',
                'has_image' => $uploadedImage ? true : false,
                'image_filename' => $uploadedImage['filename'] ?? null,
                'image_original_name' => $uploadedImage['original_name'] ?? null,
                'image_mime_type' => $uploadedImage['mime_type'] ?? null,
                'image_size' => $uploadedImage['size'] ?? null
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
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    // Rollback transaction on any error
    if ($pdo->inTransaction()) {
        $pdo->rollback();
    }
    
    error_log("Chat API error: " . $e->getMessage());
    error_log("Error trace: " . $e->getTraceAsString());
    
    // Clear any output buffer to ensure clean JSON
    ob_clean();
    
    // Handle API overload (max retries exceeded) specially
    if ($e->getMessage() === "API_OVERLOAD_MAX_RETRIES") {
        http_response_code(503); // Service Unavailable
        echo json_encode([
            'error' => 'API_OVERLOAD_MAX_RETRIES',
            'error_type' => 'api_overload',
            'aei_name' => htmlspecialchars($aei['name'] ?? 'your AEI'),
            'message' => htmlspecialchars($aei['name'] ?? 'Your AEI') . ' is experiencing high demand right now. Please try again in a few minutes.'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to send message. Please try again.'], JSON_UNESCAPED_UNICODE);
    }
}

// Ensure we end output buffering and send response
ob_end_flush();
?>