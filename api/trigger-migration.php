<?php
// Manually trigger database migration for social dialog features
// Use centralized session configuration
include_once '../includes/session_config.php';
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
    // The migration already ran when we included database.php
    // Let's check what columns now exist
    $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $columnNames = array_column($columns, 'Field');
    
    $requiredColumns = ['dialog_history', 'initiated_by', 'processed_for_emotions'];
    $missingColumns = [];
    $existingColumns = [];
    
    foreach ($requiredColumns as $col) {
        if (in_array($col, $columnNames)) {
            $existingColumns[] = $col;
        } else {
            $missingColumns[] = $col;
        }
    }
    
    // Get recent interaction stats
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM aei_contact_interactions");
    $totalInteractions = $stmt->fetch()['total'];
    
    $withDialogs = 0;
    if (in_array('dialog_history', $columnNames)) {
        $stmt = $pdo->query("SELECT COUNT(*) as with_dialogs FROM aei_contact_interactions WHERE dialog_history IS NOT NULL AND dialog_history != ''");
        $withDialogs = $stmt->fetch()['with_dialogs'];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Migration check completed',
        'database_status' => [
            'required_columns' => $requiredColumns,
            'existing_columns' => $existingColumns,
            'missing_columns' => $missingColumns,
            'migration_complete' => empty($missingColumns),
            'total_columns' => count($columnNames)
        ],
        'interaction_stats' => [
            'total_interactions' => $totalInteractions,
            'with_dialog_history' => $withDialogs,
            'percentage_with_dialogs' => $totalInteractions > 0 ? round(($withDialogs / $totalInteractions) * 100, 1) : 0
        ],
        'next_steps' => [
            'migration_complete' => empty($missingColumns),
            'ready_for_enhanced_dialogs' => in_array('dialog_history', $columnNames) && in_array('initiated_by', $columnNames),
            'recommendation' => empty($missingColumns) ? 
                'Database is ready! New social interactions will have enhanced multi-turn dialogs.' :
                'Some columns are missing. Please check error logs for migration issues.'
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Trigger migration error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>