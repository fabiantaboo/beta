<?php
requireAdmin();

$error = null;
$success = null;
$selectedUserId = $_GET['user_id'] ?? '';
$selectedSessionId = $_GET['session_id'] ?? '';

// Get non-admin users with their AEIs and chat sessions (anonymized)
try {
    $stmt = $pdo->prepare("
        SELECT 
            u.id as user_id,
            CONCAT('User ', RIGHT(u.id, 6)) as anonymous_name,
            COUNT(DISTINCT a.id) as aei_count,
            COUNT(DISTINCT cs.id) as session_count,
            MAX(cs.last_message_at) as last_activity,
            DATE(u.created_at) as join_date
        FROM users u
        LEFT JOIN aeis a ON u.id = a.user_id AND a.is_active = TRUE
        LEFT JOIN chat_sessions cs ON a.id = cs.aei_id
        WHERE u.is_admin = FALSE
        GROUP BY u.id
        ORDER BY last_activity DESC, u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Database error fetching users: " . $e->getMessage());
    $users = [];
}

// Get chat sessions for selected user
$chatSessions = [];
if ($selectedUserId) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                cs.*,
                a.name as aei_name,
                COUNT(cm.id) as message_count,
                MAX(cm.timestamp) as last_message
            FROM chat_sessions cs
            JOIN aeis a ON cs.aei_id = a.id
            LEFT JOIN chat_messages cm ON cs.id = cm.session_id
            WHERE cs.user_id = ?
            GROUP BY cs.id
            ORDER BY cs.last_message_at DESC
        ");
        $stmt->execute([$selectedUserId]);
        $chatSessions = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Database error fetching chat sessions: " . $e->getMessage());
        $chatSessions = [];
    }
}

// Get messages for selected session
$messages = [];
$sessionInfo = null;
if ($selectedSessionId) {
    try {
        // Get session info (anonymized)
        $stmt = $pdo->prepare("
            SELECT 
                cs.*,
                a.name as aei_name,
                CONCAT('User ', RIGHT(u.id, 6)) as anonymous_name
            FROM chat_sessions cs
            JOIN aeis a ON cs.aei_id = a.id
            JOIN users u ON cs.user_id = u.id
            WHERE cs.id = ? AND u.is_admin = FALSE
        ");
        $stmt->execute([$selectedSessionId]);
        $sessionInfo = $stmt->fetch();
        
        if ($sessionInfo) {
            // Get messages
            $stmt = $pdo->prepare("
                SELECT *
                FROM chat_messages
                WHERE session_id = ?
                ORDER BY timestamp ASC
            ");
            $stmt->execute([$selectedSessionId]);
            $messages = $stmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log("Database error fetching messages: " . $e->getMessage());
        $messages = [];
    }
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-chats'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('Chat Analytics', 'Analyze anonymized chat patterns and conversation flows'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Users List -->
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Anonymous Users</h3>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Beta testers and their chat activity</p>
                </div>
                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                    <?php foreach ($users as $user): ?>
                        <div class="p-4">
                            <a href="?user_id=<?= urlencode($user['user_id']) ?>" 
                               class="block hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg p-3 transition-colors <?= $selectedUserId === $user['user_id'] ? 'bg-ayuni-blue/10 border border-ayuni-blue/20' : '' ?>">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <h4 class="font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($user['anonymous_name']) ?>
                                        </h4>
                                        <p class="text-sm text-gray-500 dark:text-gray-400">
                                            Joined <?= date('M j, Y', strtotime($user['join_date'])) ?>
                                        </p>
                                    </div>
                                    <div class="text-right text-xs text-gray-500">
                                        <div><?= $user['aei_count'] ?> AEIs</div>
                                        <div><?= $user['session_count'] ?> chats</div>
                                    </div>
                                </div>
                                <?php if ($user['last_activity']): ?>
                                    <div class="mt-2 text-xs text-gray-400">
                                        Last: <?= date('M j, Y g:i A', strtotime($user['last_activity'])) ?>
                                    </div>
                                <?php endif; ?>
                            </a>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($users)): ?>
                        <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                            <i class="fas fa-users text-4xl mb-4 opacity-50"></i>
                            <p>No users found</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Chat Sessions -->
            <?php if ($selectedUserId): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Chat Sessions</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Select a chat to view messages</p>
                    </div>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($chatSessions as $session): ?>
                            <div class="p-4">
                                <a href="?user_id=<?= urlencode($selectedUserId) ?>&session_id=<?= urlencode($session['id']) ?>"
                                   class="block hover:bg-gray-50 dark:hover:bg-gray-700 rounded-lg p-3 transition-colors <?= $selectedSessionId === $session['id'] ? 'bg-ayuni-blue/10 border border-ayuni-blue/20' : '' ?>">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <h4 class="font-medium text-gray-900 dark:text-white">
                                                <?= htmlspecialchars($session['aei_name']) ?>
                                            </h4>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                <?= $session['message_count'] ?> messages
                                            </p>
                                        </div>
                                        <div class="text-xs text-gray-500">
                                            <?php if ($session['last_message']): ?>
                                                <?= date('M j, g:i A', strtotime($session['last_message'])) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        
                        <?php if (empty($chatSessions)): ?>
                            <div class="p-8 text-center text-gray-500 dark:text-gray-400">
                                <i class="fas fa-comments text-4xl mb-4 opacity-50"></i>
                                <p>No chat sessions found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Messages -->
            <?php if ($selectedSessionId && $sessionInfo): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            Chat: <?= htmlspecialchars($sessionInfo['anonymous_name']) ?> & <?= htmlspecialchars($sessionInfo['aei_name']) ?>
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                            <?= count($messages) ?> messages • Started <?= date('M j, Y g:i A', strtotime($sessionInfo['created_at'])) ?>
                        </p>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <div class="p-4 space-y-4">
                            <?php foreach ($messages as $message): ?>
                                <div class="<?= $message['sender_type'] === 'user' ? 'flex justify-end' : 'flex justify-start' ?>">
                                    <div class="max-w-xs lg:max-w-sm">
                                        <div class="<?= $message['sender_type'] === 'user' 
                                            ? 'bg-ayuni-blue text-white' 
                                            : 'bg-gray-100 dark:bg-gray-700 text-gray-900 dark:text-white' 
                                        ?> rounded-2xl px-4 py-3 shadow-sm">
                                            
                                            <?php if ($message['image_filename']): ?>
                                                <div class="mb-2">
                                                    <img src="/uploads/chat_images/<?= htmlspecialchars($message['image_filename']) ?>" 
                                                         alt="Shared image" 
                                                         class="max-w-full h-auto rounded-lg">
                                                    <?php if ($message['image_original_name']): ?>
                                                        <p class="text-xs opacity-70 mt-1"><?= htmlspecialchars($message['image_original_name']) ?></p>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($message['content']): ?>
                                                <div class="text-sm">
                                                    <?= nl2br(htmlspecialchars($message['content'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="flex items-center mt-1 text-xs text-gray-500 <?= $message['sender_type'] === 'user' ? 'justify-end' : 'justify-start' ?>">
                                            <span><?= $message['sender_type'] === 'user' ? $sessionInfo['anonymous_name'] : $sessionInfo['aei_name'] ?></span>
                                            <span class="mx-1">•</span>
                                            <span><?= date('M j, g:i A', strtotime($message['timestamp'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <?php if (empty($messages)): ?>
                                <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                                    <i class="fas fa-message text-4xl mb-4 opacity-50"></i>
                                    <p>No messages in this chat</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Instructions when nothing is selected -->
        <?php if (!$selectedUserId): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-8 text-center">
                <i class="fas fa-search text-6xl text-gray-300 dark:text-gray-600 mb-6"></i>
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Chat Analytics</h3>
                <p class="text-gray-600 dark:text-gray-400 max-w-md mx-auto">
                    Analyze anonymized beta user conversations to understand chat patterns and improve AEI responses. 
                    All data is anonymized to protect user privacy.
                </p>
            </div>
        <?php endif; ?>
    </div>
</div>