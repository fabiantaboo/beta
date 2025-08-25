<?php
// Test user appearance columns migration
require_once __DIR__ . '/config/database.php';

echo "Testing user appearance columns migration...\n";

try {
    // Check current columns
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Current columns:\n";
    foreach ($columns as $col) {
        echo "- $col\n";
    }
    
    // Check if appearance columns exist
    $appearanceColumns = [
        'user_hair_color', 'user_eye_color', 'user_height', 
        'user_build', 'user_style', 'user_appearance_custom'
    ];
    
    $missingColumns = array_diff($appearanceColumns, $columns);
    
    if (empty($missingColumns)) {
        echo "\nAll appearance columns exist!\n";
        
        // Test a simple update
        $stmt = $pdo->prepare("
            UPDATE users SET 
                user_hair_color = ?, 
                user_eye_color = ? 
            WHERE id = ? 
            LIMIT 1
        ");
        
        $stmt->execute(['Brown', 'Blue', 'test_id_that_does_not_exist']);
        echo "Test update successful!\n";
        
    } else {
        echo "\nMissing columns:\n";
        foreach ($missingColumns as $col) {
            echo "- $col\n";
            
            // Add the missing column
            $sql = "ALTER TABLE users ADD COLUMN $col " . 
                   ($col === 'user_appearance_custom' ? 'TEXT NULL' : 'VARCHAR(50) NULL');
            
            echo "Adding column with SQL: $sql\n";
            $pdo->exec($sql);
        }
        
        echo "\nColumns added successfully!\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?>