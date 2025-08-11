<?php
// Fix missing dialog_history column in aei_contact_interactions
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
    $results = [];
    
    // 1. Check current table structure
    $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $results['current_columns'] = $columns;
    $results['fixes_applied'] = [];
    
    // 2. Add missing columns if they don't exist
    $requiredColumns = [
        'dialog_history' => "ALTER TABLE aei_contact_interactions ADD COLUMN dialog_history JSON NULL AFTER aei_thoughts",
        'initiated_by' => "ALTER TABLE aei_contact_interactions ADD COLUMN initiated_by ENUM('contact', 'aei', 'system') DEFAULT 'contact' AFTER mentions_other_contacts"
    ];
    
    foreach ($requiredColumns as $columnName => $alterSQL) {
        if (!in_array($columnName, $columns)) {
            try {
                $pdo->exec($alterSQL);
                $results['fixes_applied'][] = "Added column: {$columnName}";
                error_log("Added missing column {$columnName} to aei_contact_interactions");
            } catch (PDOException $e) {
                $results['errors'][] = "Failed to add {$columnName}: " . $e->getMessage();
                error_log("Failed to add column {$columnName}: " . $e->getMessage());
            }
        } else {
            $results['fixes_applied'][] = "Column {$columnName} already exists";
        }
    }
    
    // 3. Verify final table structure
    $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
    $finalColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $results['final_columns'] = $finalColumns;
    $results['has_dialog_history'] = in_array('dialog_history', $finalColumns);
    $results['has_initiated_by'] = in_array('initiated_by', $finalColumns);
    
    // 4. Count interactions and check for existing dialog histories
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM aei_contact_interactions");
    $totalInteractions = $stmt->fetch()['total'];
    
    if (in_array('dialog_history', $finalColumns)) {
        $stmt = $pdo->query("SELECT COUNT(*) as with_dialogs FROM aei_contact_interactions WHERE dialog_history IS NOT NULL");
        $withDialogs = $stmt->fetch()['with_dialogs'];
        
        $results['interaction_stats'] = [
            'total_interactions' => $totalInteractions,
            'with_dialog_history' => $withDialogs,
            'without_dialog_history' => $totalInteractions - $withDialogs
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Database structure check and fix completed',
        'results' => $results
    ]);
    
} catch (Exception $e) {
    error_log("Fix dialog columns error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>