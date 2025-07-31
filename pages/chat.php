<?php
requireAuth();

$aeiId = $_GET['aei'] ?? '';
if (empty($aeiId)) {
    redirectTo('dashboard');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt->execute([$aeiId, getUserSession()]);
    $aei = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Database error fetching AEI: " . $e->getMessage());
    redirectTo('dashboard');
}

if (!$aei) {
    redirectTo('dashboard');
}

$stmt = $pdo->prepare("SELECT id FROM chat_sessions WHERE user_id = ? AND aei_id = ? ORDER BY created_at DESC LIMIT 1");
$stmt->execute([getUserSession(), $aeiId]);
$session = $stmt->fetch();

if (!$session) {
    $sessionId = generateId();
    $stmt = $pdo->prepare("INSERT INTO chat_sessions (id, user_id, aei_id) VALUES (?, ?, ?)");
    $stmt->execute([$sessionId, getUserSession(), $aeiId]);
} else {
    $sessionId = $session['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['message'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $chatError = "Invalid request. Please try again.";
    } else {
        try {
            $message = sanitizeInput($_POST['message']);
            $messageId = generateId();
            
            $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'user', ?)");
            $stmt->execute([$messageId, $sessionId, $message]);
            
            $aeiResponseId = generateId();
            $aeiResponse = "Hello! I'm " . htmlspecialchars($aei['name']) . ". Thanks for your message: \"" . htmlspecialchars($message) . "\". This is a simulated response for the MVP version.";
            
            $stmt = $pdo->prepare("INSERT INTO chat_messages (id, session_id, sender_type, message_text) VALUES (?, ?, 'aei', ?)");
            $stmt->execute([$aeiResponseId, $sessionId, $aeiResponse]);
            
            $stmt = $pdo->prepare("UPDATE chat_sessions SET last_message_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$sessionId]);
        } catch (PDOException $e) {
            error_log("Database error sending message: " . $e->getMessage());
            $chatError = "Failed to send message. Please try again.";
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC");
$stmt->execute([$sessionId]);
$messages = $stmt->fetchAll();
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark flex flex-col">
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <a href="/dashboard" class="flex items-center space-x-2 text-gray-700 dark:text-gray-300 hover:text-ayuni-blue transition-colors">
                        <i class="fas fa-arrow-left"></i>
                        <span class="font-medium">Back to Dashboard</span>
                    </a>
                    <img src="assets/ayuni.png" alt="Ayuni Logo" class="h-8 w-auto">
                </div>
                <div class="flex items-center space-x-4">
                    <button 
                        id="theme-toggle" 
                        onclick="toggleTheme()" 
                        class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
                        title="Toggle theme"
                    >
                        <i class="fas fa-sun sun-icon text-lg"></i>
                        <i class="fas fa-moon moon-icon text-lg"></i>
                    </button>
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center">
                            <span class="text-lg text-white font-bold">
                                <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                            </span>
                        </div>
                        <div>
                            <h1 class="text-lg font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></h1>
                            <div class="flex items-center space-x-1">
                                <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                                <span class="text-xs text-gray-500 dark:text-gray-400">Online</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </nav>

    <div class="flex-1 flex flex-col max-w-4xl mx-auto w-full">
        <!-- Messages Area -->
        <div class="flex-1 overflow-y-auto p-4 space-y-4" id="messages-container">
            <?php if (empty($messages)): ?>
                <div class="text-center py-8">
                    <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-4 flex items-center justify-center">
                        <span class="text-2xl text-white font-bold">
                            <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                        </span>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Start a conversation with <?= htmlspecialchars($aei['name']) ?></h3>
                    <p class="text-gray-600 dark:text-gray-400">Say hello or ask them anything!</p>
                </div>
            <?php else: ?>
                <?php foreach ($messages as $message): ?>
                    <div class="flex <?= $message['sender_type'] === 'user' ? 'justify-end' : 'justify-start' ?>">
                        <div class="flex <?= $message['sender_type'] === 'user' ? 'flex-row-reverse' : 'flex-row' ?> items-end space-x-2 max-w-xs lg:max-w-md">
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center <?= $message['sender_type'] === 'user' ? 'bg-gray-500 dark:bg-gray-600 ml-2' : 'bg-gradient-to-br from-ayuni-aqua to-ayuni-blue mr-2' ?>">
                                <span class="text-xs font-bold <?= $message['sender_type'] === 'user' ? 'text-white' : 'text-white' ?>">
                                    <?= $message['sender_type'] === 'user' ? 'U' : strtoupper(substr($aei['name'], 0, 1)) ?>
                                </span>
                            </div>
                            <div class="<?= $message['sender_type'] === 'user' ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600' ?> rounded-2xl px-4 py-2 shadow-sm">
                                <p class="text-sm"><?= nl2br(htmlspecialchars($message['message_text'])) ?></p>
                                <p class="text-xs opacity-70 mt-1">
                                    <?= date('H:i', strtotime($message['created_at'])) ?>
                                </p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Message Input -->
        <div class="flex-shrink-0 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-4">
            <?php if (isset($chatError)): ?>
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-2 rounded-lg mb-4 text-sm">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($chatError) ?>
                    </div>
                </div>
            <?php endif; ?>
            <form method="POST" class="flex space-x-4">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                <div class="flex-1">
                    <textarea 
                        name="message" 
                        rows="1"
                        required
                        maxlength="1000"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                        placeholder="Type your message..."
                        onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();this.form.submit();}"
                    ></textarea>
                </div>
                <button type="submit" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md flex-shrink-0">
                    <i class="fas fa-paper-plane mr-2"></i>
                    Send
                </button>
            </form>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">
                Press Enter to send, Shift+Enter for new line
            </p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('messages-container');
    container.scrollTop = container.scrollHeight;
});
</script>