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

// No more POST handling here - everything moved to AJAX API

$stmt = $pdo->prepare("SELECT * FROM chat_messages WHERE session_id = ? ORDER BY created_at ASC");
$stmt->execute([$sessionId]);
$messages = $stmt->fetchAll();
?>

<div class="h-screen bg-gray-50 dark:bg-ayuni-dark flex flex-col">
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700 flex-shrink-0">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <a href="/dashboard" class="flex items-center space-x-2 text-gray-700 dark:text-gray-300 hover:text-ayuni-blue transition-colors">
                        <i class="fas fa-arrow-left"></i>
                        <span class="font-medium">Back to Dashboard</span>
                    </a>
                    <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-8 w-auto">
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

    <div class="flex-1 flex flex-col max-w-4xl mx-auto w-full min-h-0">
        <!-- Messages Area -->
        <div class="flex-1 overflow-y-auto p-4 space-y-4 min-h-0" id="messages-container">
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
            <!-- Error/Success Messages -->
            <div id="chat-alerts" class="hidden mb-4"></div>
            
            <!-- Typing indicator -->
            <div id="typing-indicator" class="hidden mb-4">
                <div class="flex items-center space-x-2">
                    <div class="w-8 h-8 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center">
                        <span class="text-xs font-bold text-white">
                            <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                        </span>
                    </div>
                    <div class="bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600 rounded-2xl px-4 py-2 shadow-sm">
                        <div class="flex items-center space-x-1">
                            <span class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($aei['name']) ?> is typing</span>
                            <div class="typing-dots">
                                <span></span>
                                <span></span>
                                <span></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <form id="chat-form" class="flex space-x-4">
                <input type="hidden" id="csrf-token" value="<?= generateCSRFToken() ?>">
                <div class="flex-1">
                    <textarea 
                        id="message-input"
                        name="message" 
                        rows="1"
                        required
                        maxlength="2000"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                        placeholder="Type your message..."
                    ></textarea>
                </div>
                <button 
                    type="submit" 
                    id="send-button"
                    class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <i class="fas fa-paper-plane mr-2"></i>
                    <span id="send-text">Send</span>
                </button>
            </form>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">
                Press Enter to send, Shift+Enter for new line
            </p>
        </div>
    </div>
</div>

<style>
/* Typing animation CSS */
.typing-dots {
    display: inline-flex;
    align-items: center;
    gap: 2px;
}

.typing-dots span {
    width: 4px;
    height: 4px;
    border-radius: 50%;
    background-color: #9CA3AF;
    animation: typing 1.4s infinite ease-in-out;
}

.typing-dots span:nth-child(1) {
    animation-delay: -0.32s;
}

.typing-dots span:nth-child(2) {
    animation-delay: -0.16s;
}

@keyframes typing {
    0%, 80%, 100% {
        transform: scale(0.8);
        opacity: 0.5;
    }
    40% {
        transform: scale(1);
        opacity: 1;
    }
}

/* Message fade-in animation */
.message-fade-in {
    animation: fadeInUp 0.3s ease-out;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('messages-container');
    const form = document.getElementById('chat-form');
    const messageInput = document.getElementById('message-input');
    const sendButton = document.getElementById('send-button');
    const sendText = document.getElementById('send-text');
    const typingIndicator = document.getElementById('typing-indicator');
    const chatAlerts = document.getElementById('chat-alerts');
    const csrfToken = document.getElementById('csrf-token').value;
    const aeiId = '<?= htmlspecialchars($aeiId) ?>';
    const aeiName = '<?= htmlspecialchars($aei['name']) ?>';
    
    // Scroll to bottom
    function scrollToBottom() {
        container.scrollTop = container.scrollHeight;
    }
    
    // Initial scroll
    scrollToBottom();
    
    // Show alert message
    function showAlert(message, type = 'error') {
        const alertClass = type === 'error' 
            ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400' 
            : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-700 dark:text-green-400';
        
        const icon = type === 'error' ? 'fas fa-exclamation-circle' : 'fas fa-check-circle';
        
        chatAlerts.innerHTML = `
            <div class="${alertClass} border px-4 py-2 rounded-lg text-sm">
                <div class="flex items-center">
                    <i class="${icon} mr-2"></i>
                    ${message}
                </div>
            </div>
        `;
        chatAlerts.classList.remove('hidden');
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            chatAlerts.classList.add('hidden');
        }, 5000);
    }
    
    // Add message to chat
    function addMessage(message) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `flex ${message.sender_type === 'user' ? 'justify-end' : 'justify-start'} message-fade-in`;
        
        const time = new Date().toLocaleTimeString('en-US', { 
            hour: '2-digit', 
            minute: '2-digit',
            hour12: false 
        });
        
        messageDiv.innerHTML = `
            <div class="flex ${message.sender_type === 'user' ? 'flex-row-reverse' : 'flex-row'} items-end space-x-2 max-w-xs lg:max-w-md">
                <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center ${message.sender_type === 'user' ? 'bg-gray-500 dark:bg-gray-600 ml-2' : 'bg-gradient-to-br from-ayuni-aqua to-ayuni-blue mr-2'}">
                    <span class="text-xs font-bold text-white">
                        ${message.sender_type === 'user' ? 'U' : aeiName.charAt(0).toUpperCase()}
                    </span>
                </div>
                <div class="${message.sender_type === 'user' ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600'} rounded-2xl px-4 py-2 shadow-sm">
                    <p class="text-sm">${message.message_text.replace(/\n/g, '<br>')}</p>
                    <p class="text-xs opacity-70 mt-1">${time}</p>
                </div>
            </div>
        `;
        
        container.appendChild(messageDiv);
        scrollToBottom();
    }
    
    // Show typing indicator
    function showTyping() {
        typingIndicator.classList.remove('hidden');
        scrollToBottom();
    }
    
    // Hide typing indicator
    function hideTyping() {
        typingIndicator.classList.add('hidden');
    }
    
    // Send AI message (user message already shown)
    async function sendAIMessage(message) {
        try {
            const response = await fetch('/api/chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    message: message,
                    aei_id: aeiId,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Failed to send message');
            }
            
            // Only add AI response (user message was already added)
            const aiMessage = data.messages.find(msg => msg.sender_type === 'aei');
            if (aiMessage) {
                addMessage(aiMessage);
            }
            
        } catch (error) {
            console.error('Chat error:', error);
            showAlert(error.message || 'Failed to send message. Please try again.');
            hideTyping();
        }
    }
    
    // Handle form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const message = messageInput.value.trim();
        if (!message) return;
        
        // Disable form
        sendButton.disabled = true;
        sendText.textContent = 'Sending...';
        messageInput.disabled = true;
        
        // Clear input
        const userMessage = message;
        messageInput.value = '';
        
        // Add user message immediately
        addMessage({
            sender_type: 'user',
            message_text: userMessage
        });
        
        // Show typing indicator
        showTyping();
        
        // Send message (only AI response will be added)
        await sendAIMessage(userMessage);
        
        // Re-enable form
        sendButton.disabled = false;
        sendText.textContent = 'Send';
        messageInput.disabled = false;
        messageInput.focus();
        
        // Hide typing indicator
        hideTyping();
    });
    
    // Handle Enter key
    messageInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            form.dispatchEvent(new Event('submit'));
        }
    });
    
    // Auto-resize textarea
    messageInput.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
});
</script>