<?php
// Debug-Seite für Session-Informationen
session_start();

// Aktuelle PHP Session Konfiguration
$sessionConfig = [
    'session.gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
    'session.cookie_lifetime' => ini_get('session.cookie_lifetime'),
    'session.gc_probability' => ini_get('session.gc_probability'),
    'session.gc_divisor' => ini_get('session.gc_divisor'),
    'session.save_handler' => ini_get('session.save_handler'),
    'session.save_path' => ini_get('session.save_path'),
    'session.use_strict_mode' => ini_get('session.use_strict_mode'),
    'session.use_only_cookies' => ini_get('session.use_only_cookies'),
    'session.cookie_httponly' => ini_get('session.cookie_httponly'),
    'session.cookie_secure' => ini_get('session.cookie_secure'),
    'session.cookie_samesite' => ini_get('session.cookie_samesite'),
];

// Session Cookie Info
$sessionCookie = null;
if (isset($_COOKIE[session_name()])) {
    $sessionCookie = [
        'name' => session_name(),
        'value' => $_COOKIE[session_name()],
        'expires' => 'Unknown (check browser)',
    ];
}

// Session Data
$sessionData = $_SESSION ?? [];

// Device Fingerprint Info
function generateDeviceFingerprint() {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    return hash('sha256', $userAgent . $ip . $acceptLanguage);
}

$currentFingerprint = generateDeviceFingerprint();
$sessionFingerprint = $_SESSION['device_fingerprint'] ?? null;

// Current Time Info
$currentTime = time();
$loginTime = $_SESSION['login_time'] ?? null;
$lastActivity = $_SESSION['last_activity'] ?? null;

?>
<!DOCTYPE html>
<html>
<head>
    <title>Session Debug - Ayuni</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { border-collapse: collapse; width: 100%; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .section { margin: 30px 0; }
        .status-ok { color: green; font-weight: bold; }
        .status-warning { color: orange; font-weight: bold; }
        .status-error { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Ayuni Session Debug</h1>
    
    <div class="section">
        <h2>Session Status</h2>
        <p><strong>Session ID:</strong> <?= session_id() ?></p>
        <p><strong>Session Status:</strong> 
            <?php 
            switch(session_status()) {
                case PHP_SESSION_DISABLED:
                    echo '<span class="status-error">DISABLED</span>';
                    break;
                case PHP_SESSION_NONE:
                    echo '<span class="status-warning">NONE</span>';
                    break;
                case PHP_SESSION_ACTIVE:
                    echo '<span class="status-ok">ACTIVE</span>';
                    break;
                default:
                    echo '<span class="status-error">UNKNOWN</span>';
            }
            ?>
        </p>
        <p><strong>Current Time:</strong> <?= date('Y-m-d H:i:s', $currentTime) ?> (<?= $currentTime ?>)</p>
        <?php if ($loginTime): ?>
            <p><strong>Login Time:</strong> <?= date('Y-m-d H:i:s', $loginTime) ?> (<?= $currentTime - $loginTime ?> seconds ago)</p>
        <?php endif; ?>
        <?php if ($lastActivity): ?>
            <p><strong>Last Activity:</strong> <?= date('Y-m-d H:i:s', $lastActivity) ?> (<?= $currentTime - $lastActivity ?> seconds ago)</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>PHP Session Configuration</h2>
        <table>
            <tr><th>Setting</th><th>Value</th><th>Notes</th></tr>
            <?php foreach ($sessionConfig as $key => $value): ?>
                <tr>
                    <td><?= htmlspecialchars($key) ?></td>
                    <td><?= htmlspecialchars($value) ?></td>
                    <td>
                        <?php 
                        switch($key) {
                            case 'session.gc_maxlifetime':
                                echo $value == 2592000 ? '<span class="status-ok">30 days ✓</span>' : '<span class="status-warning">Not 30 days</span>';
                                break;
                            case 'session.cookie_lifetime':
                                echo $value == 2592000 ? '<span class="status-ok">30 days ✓</span>' : '<span class="status-warning">Not 30 days</span>';
                                break;
                            case 'session.save_handler':
                                echo $value == 'files' ? '<span class="status-ok">Files ✓</span>' : '<span class="status-warning">Not files</span>';
                                break;
                        }
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>Session Cookie Info</h2>
        <?php if ($sessionCookie): ?>
            <table>
                <tr><th>Property</th><th>Value</th></tr>
                <tr><td>Name</td><td><?= htmlspecialchars($sessionCookie['name']) ?></td></tr>
                <tr><td>Value</td><td><?= htmlspecialchars($sessionCookie['value']) ?></td></tr>
                <tr><td>Expires</td><td><?= htmlspecialchars($sessionCookie['expires']) ?></td></tr>
            </table>
        <?php else: ?>
            <p class="status-error">No session cookie found!</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Session Data</h2>
        <?php if (!empty($sessionData)): ?>
            <table>
                <tr><th>Key</th><th>Value</th></tr>
                <?php foreach ($sessionData as $key => $value): ?>
                    <tr>
                        <td><?= htmlspecialchars($key) ?></td>
                        <td><?= htmlspecialchars(is_array($value) ? json_encode($value) : $value) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p class="status-warning">No session data found.</p>
        <?php endif; ?>
    </div>

    <div class="section">
        <h2>Device Fingerprint</h2>
        <table>
            <tr><th>Type</th><th>Value</th><th>Status</th></tr>
            <tr>
                <td>Current Fingerprint</td>
                <td><?= htmlspecialchars($currentFingerprint) ?></td>
                <td><span class="status-ok">Current</span></td>
            </tr>
            <tr>
                <td>Session Fingerprint</td>
                <td><?= $sessionFingerprint ? htmlspecialchars($sessionFingerprint) : 'Not set' ?></td>
                <td>
                    <?php 
                    if (!$sessionFingerprint) {
                        echo '<span class="status-warning">Not set</span>';
                    } elseif ($sessionFingerprint === $currentFingerprint) {
                        echo '<span class="status-ok">Match ✓</span>';
                    } else {
                        echo '<span class="status-error">Mismatch!</span>';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td>User Agent</td>
                <td><?= htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown') ?></td>
                <td>-</td>
            </tr>
            <tr>
                <td>IP Address</td>
                <td><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'Unknown') ?></td>
                <td>-</td>
            </tr>
            <tr>
                <td>Accept Language</td>
                <td><?= htmlspecialchars($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'Unknown') ?></td>
                <td>-</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Server Environment</h2>
        <table>
            <tr><th>Variable</th><th>Value</th></tr>
            <tr><td>SERVER_SOFTWARE</td><td><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></td></tr>
            <tr><td>PHP_VERSION</td><td><?= PHP_VERSION ?></td></tr>
            <tr><td>HTTPS</td><td><?= isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'Not set' ?></td></tr>
            <tr><td>HTTP_HOST</td><td><?= $_SERVER['HTTP_HOST'] ?? 'Unknown' ?></td></tr>
            <tr><td>SERVER_NAME</td><td><?= $_SERVER['SERVER_NAME'] ?? 'Unknown' ?></td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Actions</h2>
        <p><a href="/">← Back to Ayuni</a></p>
        <p><strong>Note:</strong> This debug page should be removed in production!</p>
    </div>

    <script>
        // Auto refresh every 30 seconds to monitor session
        setTimeout(() => {
            window.location.reload();
        }, 30000);
    </script>
</body>
</html>