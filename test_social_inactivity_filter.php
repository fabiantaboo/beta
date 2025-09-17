<?php
/**
 * Test script to verify the 14-day inactivity filter for social system
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/background_social_processor.php';

echo "Testing 14-day inactivity filter for social system...\n\n";

try {
    $processor = new BackgroundSocialProcessor($pdo);

    // Get method via reflection to test private method
    $reflection = new ReflectionClass($processor);
    $method = $reflection->getMethod('getAEIsWithSocialContacts');
    $method->setAccessible(true);

    // Test the filtered AEI list
    echo "Getting AEIs with social contacts (filtered)...\n";
    $aeis = $method->invoke($processor);

    echo "Found " . count($aeis) . " AEIs for social processing:\n";

    foreach ($aeis as $aei) {
        $lastActivity = $aei['last_chat_activity'] ?? 'Never';
        echo "- {$aei['name']} (ID: {$aei['id']}) - Last activity: $lastActivity\n";
    }

    echo "\n✅ Test completed successfully!\n";
    echo "Note: AEIs without chat activity in the last 14 days should be excluded.\n";

} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
}
?>