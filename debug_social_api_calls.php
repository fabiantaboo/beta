<?php
/**
 * Debug script to trace ALL API calls in social system
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/social_contact_manager.php';

echo "🔍 DEBUGGING SOCIAL SYSTEM API CALLS...\n\n";

// Override callAnthropicAPI to detect any calls
function callAnthropicAPI($messages, $systemPrompt, $maxTokens = 8000, $imageData = null, $userTimezone = 'UTC', $retryCallback = null, $logData = null) {
    echo "🚨 ANTHROPIC API CALLED! 🚨\n";
    echo "Messages: " . json_encode($messages) . "\n";
    echo "System Prompt: " . substr($systemPrompt, 0, 100) . "...\n";
    echo "Stack trace:\n";
    debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
    echo "\n=================\n";

    // Return fake response to continue execution
    return '{"test": "fake_response"}';
}

// Override callSocialSystemAPI to trace
function callSocialSystemAPI($messages, $systemPrompt, $maxTokens = 8000) {
    echo "✅ SOCIAL SYSTEM API CALLED\n";
    echo "Messages: " . json_encode($messages) . "\n";
    echo "System Prompt: " . substr($systemPrompt, 0, 100) . "...\n";
    echo "\n";

    // Return fake JSON response
    return '{"name": "Test Contact", "personality_traits": ["friendly", "outgoing"], "relationship_strength": 75, "contact_frequency": "weekly"}';
}

try {
    echo "Testing Social Contact Manager...\n";

    $socialManager = new SocialContactManager($pdo);

    // Test 1: Try to generate a contact (this should trigger API calls)
    echo "\n1. Testing generateContact...\n";
    try {
        // Find an AEI to test with
        $stmt = $pdo->prepare("SELECT id FROM aeis WHERE is_active = TRUE LIMIT 1");
        $stmt->execute();
        $aei = $stmt->fetch();

        if ($aei) {
            echo "Using AEI ID: " . $aei['id'] . "\n";

            // This will trigger API calls
            $reflection = new ReflectionClass($socialManager);
            $method = $reflection->getMethod('generateContact');
            $method->setAccessible(true);

            $aeiData = ['name' => 'Test AEI', 'age' => 25, 'gender' => 'female', 'personality' => 'friendly', 'background' => 'student', 'occupation' => 'teacher', 'interests' => 'reading'];

            $result = $method->invoke($socialManager, $aei['id'], 'friend', $aeiData);
            echo "Result: " . json_encode($result) . "\n";
        } else {
            echo "No AEI found to test with\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }

    echo "\n🎯 TEST COMPLETED\n";
    echo "If you see '🚨 ANTHROPIC API CALLED! 🚨' above, then Anthropic is still being used!\n";
    echo "If you only see '✅ SOCIAL SYSTEM API CALLED', then we're using OpenRouter correctly.\n";

} catch (Exception $e) {
    echo "❌ Test failed: " . $e->getMessage() . "\n";
}
?>