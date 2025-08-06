<?php
// Prevent any output before JSON
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Clear any unwanted output
ob_clean();
header('Content-Type: application/json');

// Check authentication and admin access
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$interactionId = $_GET['interaction_id'] ?? '';

if (empty($interactionId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Interaction ID required']);
    exit;
}

try {
    // Get interaction with full details
    $stmt = $pdo->prepare("
        SELECT 
            i.*,
            c.name as contact_name,
            c.relationship_type,
            c.relationship_strength,
            c.personality_traits,
            c.current_life_situation,
            a.name as aei_name
        FROM aei_contact_interactions i
        JOIN aei_social_contacts c ON i.contact_id = c.id
        JOIN aeis a ON i.aei_id = a.id
        WHERE i.id = ?
    ");
    $stmt->execute([$interactionId]);
    $interaction = $stmt->fetch();
    
    if (!$interaction) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Interaction not found']);
        exit;
    }
    
    // Return the interaction data
    echo json_encode([
        'success' => true,
        'interaction' => $interaction
    ]);
    
} catch (PDOException $e) {
    error_log("Social dialog API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

ob_end_flush();
?>