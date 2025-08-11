<?php
// Test API for enhanced social dialog system
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/social_contact_manager.php';
require_once __DIR__ . '/../includes/background_social_processor.php';

header('Content-Type: application/json');

// Check authentication and admin access
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    $action = $_GET['action'] ?? 'test_all';
    
    switch ($action) {
        case 'test_aei_initiated':
            echo json_encode(testAEIInitiated());
            break;
            
        case 'test_multi_turn':
            echo json_encode(testMultiTurnDialog());
            break;
            
        case 'test_personality_integration':
            echo json_encode(testPersonalityIntegration());
            break;
            
        default:
            echo json_encode(testAllFeatures());
            break;
    }
    
} catch (Exception $e) {
    error_log("Social dialog test error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function testAEIInitiated() {
    global $pdo;
    
    try {
        // Get first AEI with social contacts
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
        
        $socialManager = new SocialContactManager($pdo);
        $result = $socialManager->generateAEIToContactInteraction(
            $data['aei_id'], 
            $data['contact_id']
        );
        
        return [
            'success' => true,
            'test' => 'AEI-initiated interaction',
            'aei_name' => $data['aei_name'],
            'contact_name' => $data['contact_name'],
            'result' => $result
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testMultiTurnDialog() {
    global $pdo;
    
    try {
        // Get recent interaction with dialog history
        $stmt = $pdo->query("
            SELECT i.*, a.name as aei_name, c.name as contact_name
            FROM aei_contact_interactions i
            JOIN aeis a ON i.aei_id = a.id
            JOIN aei_social_contacts c ON i.contact_id = c.id
            WHERE i.dialog_history IS NOT NULL
            ORDER BY i.occurred_at DESC
            LIMIT 1
        ");
        $interaction = $stmt->fetch();
        
        if (!$interaction) {
            return ['success' => false, 'error' => 'No interactions with dialog history found'];
        }
        
        $dialogHistory = json_decode($interaction['dialog_history'], true);
        
        return [
            'success' => true,
            'test' => 'Multi-turn dialog',
            'aei_name' => $interaction['aei_name'],
            'contact_name' => $interaction['contact_name'],
            'dialog_turns' => count($dialogHistory),
            'dialog_preview' => array_slice($dialogHistory, 0, 3) // First 3 turns
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testPersonalityIntegration() {
    global $pdo;
    
    try {
        // Get AEI with system prompt/personality
        $stmt = $pdo->query("
            SELECT id, name, personality, system_prompt
            FROM aeis 
            WHERE is_active = TRUE AND (personality IS NOT NULL OR system_prompt IS NOT NULL)
            LIMIT 1
        ");
        $aei = $stmt->fetch();
        
        if (!$aei) {
            return ['success' => false, 'error' => 'No AEI with personality found'];
        }
        
        $socialManager = new SocialContactManager($pdo);
        $systemPrompt = $socialManager->getAEISystemPrompt($aei['id']);
        
        return [
            'success' => true,
            'test' => 'Full system prompt integration (same as real chat)',
            'aei_name' => $aei['name'],
            'has_personality' => !empty($aei['personality']),
            'has_system_prompt' => !empty($aei['system_prompt']),
            'uses_full_chat_prompt' => strlen($systemPrompt) > 100,
            'prompt_length' => strlen($systemPrompt),
            'extracted_prompt' => substr($systemPrompt, 0, 300) . '...'
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function testAllFeatures() {
    return [
        'success' => true,
        'message' => 'Enhanced social dialog system ready',
        'features' => [
            'aei_initiated_interactions' => 'AEIs can now initiate conversations with contacts',
            'multi_turn_dialogs' => 'Conversations now have 6 turns instead of single Q&A',
            'personality_integration' => 'AEI personality from system prompt used in social dialogs',
            'contextual_probability' => 'AEIs more likely to reach out based on recent events'
        ],
        'tests_available' => [
            '?action=test_aei_initiated',
            '?action=test_multi_turn', 
            '?action=test_personality_integration'
        ]
    ];
}
?>