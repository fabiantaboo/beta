<?php
// Debug-Seite für Avatar-Generierung
// Use centralized session configuration
include_once 'includes/session_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Not logged in");
}

// Test Replicate API
require_once __DIR__ . '/includes/replicate_api.php';
require_once __DIR__ . '/includes/functions.php';
include_once __DIR__ . '/config/database.php';

$testResult = null;
$error = null;

if (isset($_GET['test'])) {
    try {
        $replicateAPI = new ReplicateAPI();
        
        // Test API connection with a simple prompt
        $testPrompt = "Portrait of a friendly person, high quality portrait, professional lighting";
        
        if ($_GET['test'] === 'single') {
            $prediction = $replicateAPI->generateAvatar($testPrompt, '1:1', 3, 1);
            $testResult = "Single avatar test successful. Prediction ID: " . ($prediction['id'] ?? 'No ID');
        } elseif ($_GET['test'] === 'multiple') {
            $imageUrls = $replicateAPI->generateMultipleAvatars($testPrompt, 3);
            $testResult = "Multiple avatar test successful. Generated " . count($imageUrls) . " URLs: " . json_encode($imageUrls);
        }
        
    } catch (Exception $e) {
        $error = "Test failed: " . $e->getMessage();
    }
}

// Check current settings
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'replicate_api_token'");
    $stmt->execute();
    $result = $stmt->fetch();
    $hasToken = $result && !empty($result['setting_value']);
} catch (Exception $e) {
    $hasToken = false;
}

// Check temp table
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM temp_avatar_options");
    $stmt->execute();
    $tempCount = $stmt->fetch()['count'] ?? 0;
} catch (Exception $e) {
    $tempCount = 0;
}

// Check directories
$avatarDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/avatars/';
$tempDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/avatars/temp/';
$avatarDirExists = is_dir($avatarDir);
$tempDirExists = is_dir($tempDir);
$avatarDirWritable = is_writable($avatarDir);
$tempDirWritable = is_writable($tempDir);

?>
<!DOCTYPE html>
<html>
<head>
    <title>Avatar Debug - Ayuni</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .status-ok { color: green; font-weight: bold; }
        .status-warning { color: orange; font-weight: bold; }
        .status-error { color: red; font-weight: bold; }
        .section { margin: 30px 0; padding: 20px; border: 1px solid #ddd; border-radius: 8px; }
        .test-btn { padding: 10px 20px; margin: 5px; background: #007cba; color: white; text-decoration: none; border-radius: 5px; }
        .test-btn:hover { background: #005a87; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Avatar Generation Debug</h1>
    
    <div class="section">
        <h2>System Status</h2>
        <p><strong>Replicate API Token:</strong> 
            <?= $hasToken ? '<span class="status-ok">Configured ✓</span>' : '<span class="status-error">Not configured ✗</span>' ?>
        </p>
        <p><strong>Avatar Directory:</strong> 
            <?= $avatarDirExists ? '<span class="status-ok">Exists ✓</span>' : '<span class="status-error">Missing ✗</span>' ?>
            <?= $avatarDirWritable ? '<span class="status-ok">Writable ✓</span>' : '<span class="status-error">Not writable ✗</span>' ?>
            <br><code><?= $avatarDir ?></code>
        </p>
        <p><strong>Temp Directory:</strong> 
            <?= $tempDirExists ? '<span class="status-ok">Exists ✓</span>' : '<span class="status-error">Missing ✗</span>' ?>
            <?= $tempDirWritable ? '<span class="status-ok">Writable ✓</span>' : '<span class="status-error">Not writable ✗</span>' ?>
            <br><code><?= $tempDir ?></code>
        </p>
        <p><strong>Temp Avatar Records:</strong> <?= $tempCount ?> records in database</p>
    </div>

    <div class="section">
        <h2>API Tests</h2>
        <p>Test the Replicate API connection and avatar generation:</p>
        <a href="?test=single" class="test-btn">Test Single Avatar</a>
        <a href="?test=multiple" class="test-btn">Test Multiple Avatars</a>
        
        <?php if ($testResult): ?>
            <div style="margin-top: 15px; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 5px;">
                <strong>Test Result:</strong> <?= htmlspecialchars($testResult) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div style="margin-top: 15px; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 5px;">
                <strong>Test Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Debug URLs</h2>
        <p><strong>Create AEI:</strong> <a href="/create-aei">/create-aei</a></p>
        <p><strong>Choose Avatar:</strong> <a href="/choose-avatar?temp_id=test">/choose-avatar?temp_id=test</a> (will show error)</p>
        <p><strong>Session Debug:</strong> <a href="/debug_session.php">/debug_session.php</a></p>
        <p><strong>Admin Replicate:</strong> <a href="/admin/replicate">/admin/replicate</a></p>
    </div>

    <div class="section">
        <h2>Recent Error Logs</h2>
        <p>Check server error logs for recent avatar generation attempts:</p>
        <pre><?php
        // Try to show recent error logs
        $errorLogPath = ini_get('error_log');
        if ($errorLogPath && file_exists($errorLogPath)) {
            $logs = file_get_contents($errorLogPath);
            $recentLogs = [];
            foreach (explode("\n", $logs) as $line) {
                if (stripos($line, 'replicate') !== false || stripos($line, 'avatar') !== false) {
                    $recentLogs[] = $line;
                }
            }
            echo htmlspecialchars(implode("\n", array_slice($recentLogs, -20))); // Last 20 avatar-related lines
        } else {
            echo "Error log not accessible: $errorLogPath";
        }
        ?></pre>
    </div>

    <div class="section">
        <h2>Current Session Data</h2>
        <pre><?php print_r($_SESSION); ?></pre>
    </div>

    <p><a href="/">← Back to Ayuni</a></p>
</body>
</html>