<?php
// Debug API for social dialog system
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Check authentication and admin access
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $action = $_GET['action'] ?? 'check_columns';
    
    switch ($action) {
        case 'check_columns':
            echo json_encode(checkDatabaseColumns());
            break;
            
        case 'check_recent_interactions':
            echo json_encode(checkRecentInteractions());
            break;
            
        case 'test_dialog_creation':
            echo json_encode(testDialogCreation());
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
    
} catch (Exception $e) {
    error_log("Debug social dialogs error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function checkDatabaseColumns() {
    global $pdo;
    
    try {
        // Check aei_contact_interactions columns
        $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $columnNames = array_column($columns, 'Field');
        
        return [
            'success' => true,
            'table' => 'aei_contact_interactions',
            'has_dialog_history' => in_array('dialog_history', $columnNames),
            'has_initiated_by' => in_array('initiated_by', $columnNames),
            'has_aei_response' => in_array('aei_response', $columnNames),
            'total_columns' => count($columns),
            'all_columns' => $columnNames
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function checkRecentInteractions() {
    global $pdo;
    
    try {
        // Get recent interactions with all relevant fields
        $stmt = $pdo->query("
            SELECT 
                i.*,
                c.name as contact_name,
                a.name as aei_name
            FROM aei_contact_interactions i
            LEFT JOIN aei_social_contacts c ON i.contact_id = c.id
            LEFT JOIN aeis a ON i.aei_id = a.id
            ORDER BY i.occurred_at DESC
            LIMIT 5
        ");
        $interactions = $stmt->fetchAll();
        
        $debugInfo = [];
        foreach ($interactions as $interaction) {
            $dialogHistory = null;
            if (!empty($interaction['dialog_history'])) {
                $dialogHistory = json_decode($interaction['dialog_history'], true);
            }
            
            $debugInfo[] = [
                'id' => $interaction['id'],
                'aei_name' => $interaction['aei_name'],
                'contact_name' => $interaction['contact_name'],
                'interaction_type' => $interaction['interaction_type'],
                'initiated_by' => $interaction['initiated_by'] ?? 'unknown',
                'has_dialog_history' => !empty($interaction['dialog_history']),
                'dialog_turns' => is_array($dialogHistory) ? count($dialogHistory) : 0,
                'occurred_at' => $interaction['occurred_at'],
                'has_contact_message' => !empty($interaction['contact_message']),
                'has_aei_response' => !empty($interaction['aei_response'])
            ];
        }
        
        return [
            'success' => true,
            'total_interactions' => count($interactions),
            'interactions' => $debugInfo
        ];
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testDialogCreation() {
    global $pdo;
    
    try {
        // Get first AEI with contacts
        $stmt = $pdo->query("
            SELECT a.id as aei_id, a.name as aei_name, c.id as contact_id, c.name as contact_name
            FROM aeis a 
            JOIN aei_social_contacts c ON a.id = c.aei_id 
            WHERE a.is_active = TRUE AND c.is_active = TRUE 
            LIMIT 1
        ");
        $data = $stmt->fetch();
        
        if (!$data) {
            return ['success' => false, 'error' => 'No AEI with contacts found'];
        }
        
        // Create a test dialog entry
        require_once __DIR__ . '/../includes/social_contact_manager.php';
        $socialManager = new SocialContactManager($pdo);
        
        // Test AEI-to-Contact interaction
        $result = $socialManager->generateAEIToContactInteraction(
            $data['aei_id'], 
            $data['contact_id']
        );
        
        return [
            'success' => true,
            'test_data' => $data,
            'interaction_created' => !empty($result),
            'result' => $result
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>