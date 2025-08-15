<?php
// Test script to check emoji handling
require_once 'config/database.php';

echo "Emoji Database Test\n";
echo "==================\n\n";

// Test emoji string
$testEmoji = "Test with emoji: 😘❤️🎉 Hello!";
echo "Original string: " . $testEmoji . "\n";
echo "Hex representation: " . bin2hex($testEmoji) . "\n\n";

// Test database storage and retrieval
try {
    // Insert test
    $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, ?, ?)");
    $testId = bin2hex(random_bytes(16));
    $testSessionId = bin2hex(random_bytes(16));
    $stmt->execute([$testId, $testSessionId, 'test', $testEmoji]);
    
    echo "✓ Successfully inserted test message\n";
    
    // Retrieve test
    $stmt = $pdo->prepare("SELECT message_text FROM chat_messages WHERE id = ?");
    $stmt->execute([$testId]);
    $result = $stmt->fetch();
    
    if ($result) {
        $retrieved = $result['message_text'];
        echo "Retrieved string: " . $retrieved . "\n";
        echo "Retrieved hex: " . bin2hex($retrieved) . "\n";
        
        if ($testEmoji === $retrieved) {
            echo "✓ Emojis match perfectly!\n";
        } else {
            echo "✗ Emojis don't match\n";
            echo "Difference: " . strcmp($testEmoji, $retrieved) . "\n";
        }
    } else {
        echo "✗ Failed to retrieve test message\n";
    }
    
    // Cleanup
    $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE id = ?");
    $stmt->execute([$testId]);
    echo "✓ Cleaned up test data\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\nTest with htmlspecialchars:\n";
echo "Original: " . $testEmoji . "\n";
echo "htmlspecialchars default: " . htmlspecialchars($testEmoji) . "\n";
echo "htmlspecialchars UTF-8: " . htmlspecialchars($testEmoji, ENT_QUOTES, 'UTF-8') . "\n";
echo "htmlspecialchars UTF-8 false: " . htmlspecialchars($testEmoji, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) . "\n";
?>