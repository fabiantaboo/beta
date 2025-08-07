<?php
requireOnboarding();

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

// Get current emotional state for display (only for admins)
$isCurrentUserAdmin = isAdmin();
if ($isCurrentUserAdmin) {
    include_once __DIR__ . '/../includes/emotions.php';
    $emotions = new Emotions($pdo);
    $currentEmotions = $emotions->getEmotionalState($sessionId);
    $formattedEmotions = $emotions->formatEmotionsForDisplay($currentEmotions);
}
?>

<div class="h-screen bg-gray-50 dark:bg-ayuni-dark flex flex-col">
    <?php 
    include_once __DIR__ . '/../includes/header.php';
    renderChatHeader($aei, $isCurrentUserAdmin, $formattedEmotions ?? []);
    ?>

    <!-- Emotion Panel (Hidden by default, Admin only) -->
    <?php if ($isCurrentUserAdmin): ?>
    <div id="emotion-panel" class="hidden bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 shadow-sm">
        <div class="max-w-4xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium text-gray-900 dark:text-white flex items-center">
                    <i class="fas fa-brain text-ayuni-blue mr-2"></i>
                    <?= htmlspecialchars($aei['name']) ?>'s Current Emotional State
                </h3>
                <button 
                    onclick="toggleEmotions()" 
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6 gap-2 text-xs">
                <?php 
                $emotionIcons = [
                    // Grundemotionen (Plutchik)
                    'joy' => ['icon' => 'fa-smile', 'color' => 'text-yellow-500'],
                    'sadness' => ['icon' => 'fa-frown', 'color' => 'text-blue-500'],
                    'fear' => ['icon' => 'fa-exclamation-triangle', 'color' => 'text-yellow-600'],
                    'anger' => ['icon' => 'fa-angry', 'color' => 'text-red-500'],
                    'surprise' => ['icon' => 'fa-surprise', 'color' => 'text-cyan-500'],
                    'disgust' => ['icon' => 'fa-grimace', 'color' => 'text-green-600'],
                    'trust' => ['icon' => 'fa-handshake', 'color' => 'text-green-500'],
                    'anticipation' => ['icon' => 'fa-clock', 'color' => 'text-indigo-500'],
                    
                    // Erweiterte Emotionen
                    'shame' => ['icon' => 'fa-eye-slash', 'color' => 'text-gray-600'],
                    'love' => ['icon' => 'fa-heart', 'color' => 'text-pink-500'],
                    'contempt' => ['icon' => 'fa-smirk', 'color' => 'text-purple-600'],
                    'loneliness' => ['icon' => 'fa-user-times', 'color' => 'text-gray-500'],
                    'pride' => ['icon' => 'fa-medal', 'color' => 'text-purple-500'],
                    'envy' => ['icon' => 'fa-eye', 'color' => 'text-emerald-600'],
                    'nostalgia' => ['icon' => 'fa-history', 'color' => 'text-amber-600'],
                    'gratitude' => ['icon' => 'fa-hands', 'color' => 'text-orange-500'],
                    'frustration' => ['icon' => 'fa-fist-raised', 'color' => 'text-red-600'],
                    'boredom' => ['icon' => 'fa-yawn', 'color' => 'text-slate-500']
                ];
                
                // Show strongest emotions first
                $allEmotions = array_merge(
                    $formattedEmotions['strong'] ?? [],
                    $formattedEmotions['moderate'] ?? [],
                    $formattedEmotions['mild'] ?? []
                );
                
                // Show all emotions, sorted by intensity
                $displayedCount = 0;
                foreach ($allEmotions as $emotionText) {
                    preg_match('/^(\w+):\s*(.+)$/', $emotionText, $matches);
                    if (count($matches) === 3) {
                        $emotion = $matches[1];
                        $value = (float) $matches[2];
                        
                        if (isset($emotionIcons[$emotion])) {
                            $config = $emotionIcons[$emotion];
                            $intensity = $value >= 0.7 ? 'strong' : ($value >= 0.4 ? 'moderate' : 'mild');
                            $bgColor = $value >= 0.7 ? 'bg-opacity-20' : ($value >= 0.4 ? 'bg-opacity-15' : 'bg-opacity-10');
                            ?>
                            <div class="flex items-center justify-between px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 <?= $bgColor ?>">
                                <div class="flex items-center space-x-2 min-w-0">
                                    <i class="fas <?= $config['icon'] ?> <?= $config['color'] ?> flex-shrink-0"></i>
                                    <span class="text-gray-700 dark:text-gray-300 capitalize text-xs truncate"><?= $emotion ?></span>
                                </div>
                                <span class="font-medium text-gray-900 dark:text-white text-xs ml-1"><?= number_format($value, 1) ?></span>
                            </div>
                            <?php
                            $displayedCount++;
                        }
                    }
                }
                
                // Show any remaining emotions that weren't in the formatted lists
                foreach (Emotions::EMOTIONS as $emotion) {
                    if (!array_key_exists($emotion, array_flip(array_map(function($e) { return explode(':', $e)[0]; }, $allEmotions)))) {
                        $value = $currentEmotions[$emotion] ?? 0.5;
                        if ($value > 0.0 && isset($emotionIcons[$emotion])) {
                            $config = $emotionIcons[$emotion];
                            $bgColor = 'bg-opacity-5';
                            ?>
                            <div class="flex items-center justify-between px-2 py-1 rounded-lg bg-gray-100 dark:bg-gray-700 <?= $bgColor ?>">
                                <div class="flex items-center space-x-2 min-w-0">
                                    <i class="fas <?= $config['icon'] ?> <?= $config['color'] ?> flex-shrink-0 opacity-60"></i>
                                    <span class="text-gray-700 dark:text-gray-300 capitalize text-xs truncate opacity-60"><?= $emotion ?></span>
                                </div>
                                <span class="font-medium text-gray-900 dark:text-white text-xs ml-1 opacity-60"><?= number_format($value, 1) ?></span>
                            </div>
                            <?php
                            $displayedCount++;
                        }
                    }
                }
                
                if ($displayedCount === 0): ?>
                    <div class="col-span-full text-center text-gray-500 dark:text-gray-400 py-2">
                        <i class="fas fa-brain text-2xl mb-2"></i>
                        <p>Emotional state is being analyzed...</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Debug Panel (Admin only) -->
    <?php if ($isCurrentUserAdmin): ?>
    <div id="debug-panel" class="hidden bg-gray-900 text-green-400 border-b border-gray-700 shadow-lg max-h-96 overflow-y-auto">
        <div class="max-w-4xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between mb-3">
                <h3 class="text-sm font-medium text-white flex items-center">
                    <i class="fas fa-bug text-red-400 mr-2"></i>
                    API Debug Information - Last Request
                </h3>
                <div class="flex items-center space-x-2">
                    <button 
                        onclick="copyDebugData()" 
                        class="px-2 py-1 bg-gray-700 text-gray-300 rounded text-xs hover:bg-gray-600 transition-colors"
                        title="Copy debug data to clipboard"
                    >
                        <i class="fas fa-copy mr-1"></i>Copy
                    </button>
                    <button 
                        onclick="toggleDebugPanel()" 
                        class="text-gray-400 hover:text-gray-200"
                        title="Close debug panel"
                    >
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
            <div id="debug-content" class="text-xs font-mono space-y-3">
                <div class="text-gray-400">No debug data available yet. Send a message to see API details.</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
                        <div class="flex <?= $message['sender_type'] === 'user' ? 'flex-row-reverse' : 'flex-row' ?> items-end space-x-2 max-w-sm sm:max-w-md lg:max-w-lg">
                            <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center <?= $message['sender_type'] === 'user' ? 'bg-gray-500 dark:bg-gray-600 ml-2' : 'bg-gradient-to-br from-ayuni-aqua to-ayuni-blue mr-2' ?>">
                                <span class="text-xs font-bold <?= $message['sender_type'] === 'user' ? 'text-white' : 'text-white' ?>">
                                    <?= $message['sender_type'] === 'user' ? 'U' : strtoupper(substr($aei['name'], 0, 1)) ?>
                                </span>
                            </div>
                            <div class="<?= $message['sender_type'] === 'user' ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600' ?> rounded-2xl px-4 py-2 shadow-sm">
                                <?php if ($message['has_image'] && !empty($message['image_filename'])): ?>
                                    <div class="mb-2">
                                        <img 
                                            src="/uploads/chat_images/<?= htmlspecialchars($message['image_filename']) ?>" 
                                            alt="<?= htmlspecialchars($message['image_original_name'] ?? 'Shared image') ?>"
                                            class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition-opacity"
                                            onclick="openImageModal('<?= htmlspecialchars($message['image_filename']) ?>', '<?= htmlspecialchars($message['image_original_name'] ?? 'Shared image') ?>')"
                                            loading="lazy"
                                        >
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($message['message_text'])): ?>
                                    <p class="text-sm"><?= nl2br(htmlspecialchars($message['message_text'])) ?></p>
                                <?php endif; ?>
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
        <div class="flex-shrink-0 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-3 sm:p-4">
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
            
            <form id="chat-form" class="space-y-3">
                <input type="hidden" id="csrf-token" value="<?= generateCSRFToken() ?>">
                
                <!-- Image Upload Preview -->
                <div id="image-preview" class="hidden">
                    <div class="flex items-start space-x-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600">
                        <div class="flex-shrink-0">
                            <img id="preview-image" src="" alt="Preview" class="w-16 h-16 object-cover rounded-lg">
                        </div>
                        <div class="flex-1 min-w-0">
                            <p id="preview-filename" class="text-sm font-medium text-gray-900 dark:text-white truncate"></p>
                            <p id="preview-filesize" class="text-xs text-gray-500 dark:text-gray-400"></p>
                        </div>
                        <button 
                            type="button" 
                            onclick="removeImagePreview()"
                            class="flex-shrink-0 text-gray-400 hover:text-red-500 transition-colors"
                            title="Remove image"
                        >
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <div class="flex space-x-2 sm:space-x-4">
                    <!-- Image Upload Button -->
                    <div class="flex-shrink-0">
                        <input type="file" id="image-input" name="image" accept="image/*" class="hidden">
                        <button 
                            type="button" 
                            onclick="document.getElementById('image-input').click()"
                            class="bg-gray-100 dark:bg-gray-600 text-gray-600 dark:text-gray-300 p-3 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-500 transition-all duration-200 shadow-sm hover:shadow-md"
                            title="Upload image"
                            id="image-upload-btn"
                        >
                            <i class="fas fa-image"></i>
                        </button>
                    </div>
                    
                    <div class="flex-1">
                        <textarea 
                            id="message-input"
                            name="message" 
                            rows="1"
                            maxlength="2000"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="Type your message or upload an image..."
                        ></textarea>
                    </div>
                    <button 
                        type="submit" 
                        id="send-button"
                        class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-4 sm:px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md flex-shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        <i class="fas fa-paper-plane sm:mr-2"></i>
                        <span id="send-text" class="hidden sm:inline">Send</span>
                    </button>
                </div>
            </form>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">
                Press Enter to send, Shift+Enter for new line
            </p>
        </div>
    </div>
</div>

<!-- Image Modal -->
<div id="image-modal" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="max-w-4xl max-h-full p-4">
        <div class="relative">
            <img id="modal-image" src="" alt="" class="max-w-full max-h-full object-contain rounded-lg">
            <button 
                onclick="closeImageModal()"
                class="absolute top-4 right-4 bg-black bg-opacity-50 text-white rounded-full w-8 h-8 flex items-center justify-center hover:bg-opacity-70 transition-colors"
            >
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="text-center mt-4">
            <p id="modal-image-name" class="text-white text-sm"></p>
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

/* Emotion panel animation */
.animate-fade-in {
    animation: fadeIn 0.3s ease-in-out;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
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

/* Custom Scrollbar Styling */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: rgba(229, 231, 235, 0.3); /* gray-200 with low opacity */
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: rgba(156, 163, 175, 0.5); /* gray-400 with opacity */
    border-radius: 4px;
    transition: all 0.2s ease;
}

::-webkit-scrollbar-thumb:hover {
    background: rgba(107, 114, 128, 0.7); /* gray-500 with opacity */
}

/* Dark mode scrollbar */
.dark ::-webkit-scrollbar-track {
    background: rgba(55, 65, 81, 0.3); /* gray-700 with low opacity */
}

.dark ::-webkit-scrollbar-thumb {
    background: rgba(75, 85, 99, 0.6); /* gray-600 with opacity */
}

.dark ::-webkit-scrollbar-thumb:hover {
    background: rgba(55, 65, 81, 0.8); /* gray-700 with opacity */
}

/* Firefox scrollbar */
.messages-container {
    scrollbar-width: thin;
    scrollbar-color: rgba(156, 163, 175, 0.5) rgba(229, 231, 235, 0.3);
}

.dark .messages-container {
    scrollbar-color: rgba(75, 85, 99, 0.6) rgba(55, 65, 81, 0.3);
}

/* Hide scrollbar on mobile for cleaner look */
@media (max-width: 640px) {
    ::-webkit-scrollbar {
        width: 0px;
        height: 0px;
    }
    
    .messages-container {
        scrollbar-width: none;
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
    const imageInput = document.getElementById('image-input');
    const imagePreview = document.getElementById('image-preview');
    const previewImage = document.getElementById('preview-image');
    const previewFilename = document.getElementById('preview-filename');
    const previewFilesize = document.getElementById('preview-filesize');
    let selectedImage = null;
    
    // Image upload handling
    imageInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Validate file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!allowedTypes.includes(file.type)) {
            showAlert('Unsupported file type. Please select a JPEG, PNG, GIF, or WebP image.', 'error');
            return;
        }
        
        // Validate file size (10MB limit)
        const maxSize = 10 * 1024 * 1024;
        if (file.size > maxSize) {
            showAlert('File too large. Maximum size is 10MB.', 'error');
            return;
        }
        
        // Store selected image
        selectedImage = file;
        
        // Show preview
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImage.src = e.target.result;
            previewFilename.textContent = file.name;
            previewFilesize.textContent = formatFileSize(file.size);
            imagePreview.classList.remove('hidden');
        };
        reader.readAsDataURL(file);
    });
    
    // Format file size
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }
    
    // Scroll to bottom with retry for images
    function scrollToBottom() {
        container.scrollTop = container.scrollHeight;
    }
    
    // Enhanced scroll to bottom that waits for images
    function scrollToBottomWithImages() {
        // Immediate scroll
        scrollToBottom();
        
        // Wait for any loading images and scroll again
        const images = container.querySelectorAll('img[loading="lazy"]:not([data-scroll-handled])');
        if (images.length === 0) {
            return;
        }
        
        let loadedCount = 0;
        const totalImages = images.length;
        
        images.forEach(img => {
            img.setAttribute('data-scroll-handled', 'true');
            
            if (img.complete) {
                loadedCount++;
                if (loadedCount === totalImages) {
                    setTimeout(scrollToBottom, 50); // Small delay to ensure DOM update
                }
            } else {
                img.addEventListener('load', function() {
                    loadedCount++;
                    if (loadedCount === totalImages) {
                        setTimeout(scrollToBottom, 50);
                    }
                });
                
                img.addEventListener('error', function() {
                    loadedCount++;
                    if (loadedCount === totalImages) {
                        setTimeout(scrollToBottom, 50);
                    }
                });
            }
        });
        
        // Fallback scroll after a short delay
        setTimeout(scrollToBottom, 300);
    }
    
    // Initial scroll with image loading handling
    setTimeout(scrollToBottomWithImages, 100);
    
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
        
        let imageHtml = '';
        if (message.has_image && message.image_filename) {
            // Handle both temporary blob URLs and server URLs
            const imageSrc = message.image_filename.startsWith('blob:') 
                ? message.image_filename 
                : `/uploads/chat_images/${message.image_filename}`;
            
            const onClickCode = message.image_filename.startsWith('blob:')
                ? '' // No modal for temporary images
                : `onclick="openImageModal('${message.image_filename}', '${message.image_original_name || 'Shared image'}')"`;
            
            imageHtml = `
                <div class="mb-2">
                    <img 
                        src="${imageSrc}" 
                        alt="${message.image_original_name || 'Shared image'}"
                        class="max-w-full h-auto rounded-lg shadow-sm ${message.image_filename.startsWith('blob:') ? '' : 'cursor-pointer hover:opacity-90'} transition-opacity"
                        ${onClickCode}
                        loading="lazy"
                    >
                </div>
            `;
        }
        
        let textHtml = '';
        if (message.message_text && message.message_text.trim()) {
            textHtml = `<p class="text-sm">${message.message_text.replace(/\n/g, '<br>')}</p>`;
        }
        
        messageDiv.innerHTML = `
            <div class="flex ${message.sender_type === 'user' ? 'flex-row-reverse' : 'flex-row'} items-end space-x-2 max-w-xs lg:max-w-md">
                <div class="w-8 h-8 rounded-full flex-shrink-0 flex items-center justify-center ${message.sender_type === 'user' ? 'bg-gray-500 dark:bg-gray-600 ml-2' : 'bg-gradient-to-br from-ayuni-aqua to-ayuni-blue mr-2'}">
                    <span class="text-xs font-bold text-white">
                        ${message.sender_type === 'user' ? 'U' : aeiName.charAt(0).toUpperCase()}
                    </span>
                </div>
                <div class="${message.sender_type === 'user' ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600'} rounded-2xl px-4 py-2 shadow-sm">
                    ${imageHtml}
                    ${textHtml}
                    <p class="text-xs opacity-70 mt-1">${time}</p>
                </div>
            </div>
        `;
        
        container.appendChild(messageDiv);
        scrollToBottomWithImages();
        
        // Return the message element so it can be updated later
        return messageDiv;
    }
    
    // Show typing indicator
    function showTyping() {
        typingIndicator.classList.remove('hidden');
        scrollToBottomWithImages();
    }
    
    // Hide typing indicator
    function hideTyping() {
        typingIndicator.classList.add('hidden');
    }
    
    // Send AI message with optional image
    async function sendAIMessage(message, imageFile = null) {
        try {
            let requestData;
            let headers = {};
            
            if (imageFile) {
                // Use FormData for file upload
                const formData = new FormData();
                formData.append('message', message);
                formData.append('aei_id', aeiId);
                formData.append('csrf_token', csrfToken);
                formData.append('image', imageFile);
                
                requestData = formData;
                // Don't set Content-Type header - let browser set it with boundary
            } else {
                // Use JSON for text-only messages
                headers['Content-Type'] = 'application/json';
                requestData = JSON.stringify({
                    message: message,
                    aei_id: aeiId,
                    csrf_token: csrfToken
                });
            }
            
            const response = await fetch('/api/chat.php', {
                method: 'POST',
                headers: headers,
                body: requestData
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Failed to send message');
            }
            
            // Return the response data so we can update the user message with correct image URL
            return data;
            
        } catch (error) {
            console.error('Chat error:', error);
            showAlert(error.message || 'Failed to send message. Please try again.');
            hideTyping();
            throw error;
        }
    }
    
    // Handle form submission
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const message = messageInput.value.trim();
        if (!message && !selectedImage) return;
        
        // Disable form
        sendButton.disabled = true;
        sendText.textContent = 'Sending...';
        messageInput.disabled = true;
        
        // Clear input and capture image file
        const userMessage = message;
        const imageFile = selectedImage;
        messageInput.value = '';
        
        // IMPORTANT: Reset selectedImage immediately to prevent it being sent again
        selectedImage = null;
        
        // Add user message immediately with temporary preview
        const userMessageData = {
            sender_type: 'user',
            message_text: userMessage
        };
        
        let tempImageUrl = null;
        if (imageFile) {
            tempImageUrl = URL.createObjectURL(imageFile);
            userMessageData.has_image = true;
            userMessageData.image_filename = tempImageUrl;
            userMessageData.image_original_name = imageFile.name;
        }
        
        const userMessageElement = addMessage(userMessageData);
        
        // Clear image preview
        removeImagePreview();
        
        // Show typing indicator
        showTyping();
        
        try {
            // Send message with image and wait for response
            const data = await sendAIMessage(userMessage, imageFile);
            
            // Update user message with correct image URL if there was an image
            if (data.messages && data.messages.length > 0) {
                const serverUserMessage = data.messages.find(msg => msg.sender_type === 'user');
                const serverAiMessage = data.messages.find(msg => msg.sender_type === 'aei');
                
                // Update the user message image URL if needed
                if (serverUserMessage && serverUserMessage.has_image && userMessageElement) {
                    const imageElement = userMessageElement.querySelector('img');
                    if (imageElement && tempImageUrl) {
                        // Clean up temporary URL
                        URL.revokeObjectURL(tempImageUrl);
                        // Update with server URL
                        imageElement.src = '/uploads/chat_images/' + serverUserMessage.image_filename;
                        imageElement.onclick = function() {
                            openImageModal(serverUserMessage.image_filename, serverUserMessage.image_original_name);
                        };
                        // Add cursor pointer and hover effect
                        imageElement.classList.add('cursor-pointer', 'hover:opacity-90');
                        
                        // Scroll after image URL update
                        imageElement.addEventListener('load', function() {
                            scrollToBottom();
                        });
                    }
                }
                
                // Add AI response
                if (serverAiMessage) {
                    addMessage(serverAiMessage);
                }
            }
            
            // Handle debug data for admins
            <?php if ($isCurrentUserAdmin): ?>
            if (data.debug_data) {
                updateDebugPanel(data.debug_data);
            }
            <?php endif; ?>
            
        } catch (error) {
            // Error already handled in sendAIMessage
            // Clean up temporary URL on error
            if (tempImageUrl) {
                URL.revokeObjectURL(tempImageUrl);
            }
        }
        
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

// Emotion panel toggle (admin only)
function toggleEmotions() {
    <?php if ($isCurrentUserAdmin): ?>
    const panel = document.getElementById('emotion-panel');
    if (panel && panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        panel.classList.add('animate-fade-in');
    } else if (panel) {
        panel.classList.add('hidden');
        panel.classList.remove('animate-fade-in');
    }
    <?php else: ?>
    // Not available for regular users
    console.log('Emotion panel is only available for administrators.');
    <?php endif; ?>
}

// Debug panel functions (Admin only)
<?php if ($isCurrentUserAdmin): ?>
let currentDebugData = null;

function toggleDebugPanel() {
    const panel = document.getElementById('debug-panel');
    if (panel && panel.classList.contains('hidden')) {
        panel.classList.remove('hidden');
        panel.classList.add('animate-fade-in');
    } else if (panel) {
        panel.classList.add('hidden');
        panel.classList.remove('animate-fade-in');
    }
}

function updateDebugPanel(debugData) {
    currentDebugData = debugData;
    const content = document.getElementById('debug-content');
    if (!content) return;
    
    let html = '<div class="space-y-4">';
    
    // Timestamp
    html += `<div class="border-b border-gray-700 pb-2">
        <span class="text-yellow-400 font-semibold">Request Timestamp:</span>
        <span class="text-white ml-2">${debugData.timestamp || 'Unknown'}</span>
    </div>`;
    
    // API Configuration
    html += `<div class="border-b border-gray-700 pb-2">
        <span class="text-yellow-400 font-semibold">API Configuration:</span>
        <div class="ml-4 mt-1 text-gray-300">
            Model: <span class="text-cyan-400">${debugData.api_model || 'claude-3-5-sonnet-20241022'}</span><br>
            Max Tokens: <span class="text-cyan-400">${debugData.max_tokens || 8000}</span><br>
            Response Length: <span class="text-cyan-400">${debugData.response_length || 'Unknown'} chars</span>
        </div>
    </div>`;
    
    // Complete API Request JSON
    if (debugData.api_request_payload) {
        html += `<div class="border-b border-gray-700 pb-2">
            <span class="text-orange-400 font-semibold">Complete API Request JSON:</span>
            <div class="ml-2 mt-2 relative">
                <button onclick="copyApiRequest()" class="absolute top-2 right-2 px-2 py-1 bg-gray-700 text-gray-300 rounded text-xs hover:bg-gray-600 transition-colors z-10">
                    <i class="fas fa-copy mr-1"></i>Copy JSON
                </button>
                <div class="p-3 bg-black rounded border border-gray-600 max-h-80 overflow-y-auto">
                    <pre class="whitespace-pre-wrap text-xs text-green-400" id="api-request-json">${JSON.stringify(debugData.api_request_payload, null, 2)}</pre>
                </div>
            </div>
        </div>`;
    }
    
    // Full System Prompt - This is the key part!
    if (debugData.full_system_prompt) {
        html += `<div class="border-b border-gray-700 pb-2">
            <span class="text-red-400 font-semibold">Complete System Prompt:</span>
            <div class="ml-2 mt-2 p-3 bg-gray-800 rounded border border-gray-600 max-h-64 overflow-y-auto">
                <pre class="whitespace-pre-wrap text-xs text-gray-200">${escapeHtml(debugData.full_system_prompt)}</pre>
            </div>
        </div>`;
    }
    
    // Chat History sent to API
    if (debugData.chat_history && debugData.chat_history.length > 0) {
        html += `<div class="border-b border-gray-700 pb-2">
            <span class="text-green-400 font-semibold">Chat History (${debugData.chat_history.length} messages):</span>
            <div class="ml-2 mt-2 space-y-2 max-h-48 overflow-y-auto">`;
        
        debugData.chat_history.forEach(msg => {
            const roleColor = msg.role === 'user' ? 'text-blue-400' : 'text-purple-400';
            html += `<div class="p-2 bg-gray-800 rounded border border-gray-600">
                <div class="${roleColor} font-semibold text-xs mb-1">${msg.role.toUpperCase()}</div>
                <div class="text-gray-300 text-xs whitespace-pre-wrap">${escapeHtml(msg.content)}</div>
            </div>`;
        });
        
        html += `</div></div>`;
    }
    
    // Current Emotions
    if (debugData.current_emotions) {
        html += `<div class="border-b border-gray-700 pb-2">
            <span class="text-purple-400 font-semibold">Current Emotional State:</span>
            <div class="ml-2 mt-1 grid grid-cols-3 gap-1 text-xs">`;
        
        Object.entries(debugData.current_emotions).forEach(([emotion, value]) => {
            const intensity = parseFloat(value);
            const color = intensity > 0.5 ? 'text-red-400' : intensity > 0.3 ? 'text-yellow-400' : 'text-gray-500';
            html += `<div class="${color}">${emotion}: ${intensity.toFixed(1)}</div>`;
        });
        
        html += `</div></div>`;
    }
    
    // API Response
    if (debugData.api_response) {
        html += `<div class="border-b border-gray-700 pb-2">
            <span class="text-cyan-400 font-semibold">API Response:</span>
            <div class="ml-2 mt-2 p-3 bg-gray-800 rounded border border-gray-600 max-h-48 overflow-y-auto">
                <pre class="whitespace-pre-wrap text-xs text-gray-200">${escapeHtml(debugData.api_response)}</pre>
            </div>
        </div>`;
    }
    
    // Social Context (if available)
    if (debugData.social_emotional_impact) {
        html += `<div class="border-b border-gray-700 pb-2">
            <span class="text-orange-400 font-semibold">Social Context:</span>
            <div class="ml-2 mt-1 p-2 bg-gray-800 rounded border border-gray-600">
                <pre class="whitespace-pre-wrap text-xs text-gray-300">${JSON.stringify(debugData.social_emotional_impact, null, 2)}</pre>
            </div>
        </div>`;
    }
    
    // Errors (if any)
    if (debugData.error || debugData.emotion_analysis_error) {
        html += `<div class="border-b border-red-600 pb-2">
            <span class="text-red-400 font-semibold">Errors:</span>
            <div class="ml-2 mt-1 text-red-300 text-xs">`;
        
        if (debugData.error) {
            html += `<div>General Error: ${escapeHtml(debugData.error)}</div>`;
        }
        if (debugData.emotion_analysis_error) {
            html += `<div>Emotion Analysis: ${escapeHtml(debugData.emotion_analysis_error)}</div>`;
        }
        
        html += `</div></div>`;
    }
    
    html += '</div>';
    content.innerHTML = html;
}

function copyDebugData() {
    if (!currentDebugData) {
        alert('No debug data available to copy');
        return;
    }
    
    navigator.clipboard.writeText(JSON.stringify(currentDebugData, null, 2)).then(() => {
        // Show temporary confirmation
        const button = event.target.closest('button');
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
        button.classList.add('bg-green-700');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('bg-green-700');
        }, 2000);
    }).catch(() => {
        alert('Failed to copy debug data to clipboard');
    });
}

function copyApiRequest() {
    if (!currentDebugData || !currentDebugData.api_request_payload) {
        alert('No API request data available to copy');
        return;
    }
    
    navigator.clipboard.writeText(JSON.stringify(currentDebugData.api_request_payload, null, 2)).then(() => {
        // Show temporary confirmation
        const button = event.target.closest('button');
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
        button.classList.add('bg-green-700');
        
        setTimeout(() => {
            button.innerHTML = originalText;
            button.classList.remove('bg-green-700');
        }, 2000);
    }).catch(() => {
        alert('Failed to copy API request to clipboard');
    });
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
<?php endif; ?>

// Image preview and modal functions
function removeImagePreview() {
    const imagePreview = document.getElementById('image-preview');
    const imageInput = document.getElementById('image-input');
    imagePreview.classList.add('hidden');
    imageInput.value = '';
    selectedImage = null;
}

function openImageModal(filename, originalName) {
    const modal = document.getElementById('image-modal');
    const modalImage = document.getElementById('modal-image');
    const modalImageName = document.getElementById('modal-image-name');
    
    modalImage.src = '/uploads/chat_images/' + filename;
    modalImageName.textContent = originalName;
    modal.classList.remove('hidden');
    
    // Close modal on click outside image
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeImageModal();
        }
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeImageModal();
        }
    });
}

function closeImageModal() {
    const modal = document.getElementById('image-modal');
    modal.classList.add('hidden');
}

</script>