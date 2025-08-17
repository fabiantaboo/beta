<?php
requireOnboarding();

// Function to safely display message text with emojis
function safeDisplayMessage($text) {
    // First, ensure proper UTF-8 encoding
    if (!mb_check_encoding($text, 'UTF-8')) {
        $text = mb_convert_encoding($text, 'UTF-8', 'auto');
    }
    
    // Use htmlspecialchars with UTF-8 and preserve emojis
    return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
}

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

// Get initial messages (latest 20) and check which ones already have feedback
$stmt = $pdo->prepare("
    SELECT 
        cm.*, 
        mf.id as feedback_id,
        mf.rating as feedback_rating
    FROM chat_messages cm
    LEFT JOIN message_feedback mf ON cm.id = mf.message_id AND mf.user_id = ?
    WHERE cm.session_id = ? 
    ORDER BY cm.created_at DESC
    LIMIT 20
");
$stmt->execute([getUserSession(), $sessionId]);
$messages = array_reverse($stmt->fetchAll()); // Reverse to show chronologically

// Get total message count for pagination
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM chat_messages WHERE session_id = ?");
$stmt->execute([$sessionId]);
$totalMessages = $stmt->fetch()['total'];

// Get current emotional state for display (only for admins)
$isCurrentUserAdmin = isAdmin();
if ($isCurrentUserAdmin) {
    include_once __DIR__ . '/../includes/emotions.php';
    $emotions = new Emotions($pdo);
    $currentEmotions = $emotions->getEmotionalState($sessionId);
    $formattedEmotions = $emotions->formatEmotionsForDisplay($currentEmotions);
}
?>

<?php 
include_once __DIR__ . '/../includes/header.php';
renderChatHeader($aei, $isCurrentUserAdmin, $formattedEmotions ?? []);
?>

<div class="bg-gray-50 dark:bg-ayuni-dark flex flex-col chat-container-ios" style="height: 100vh; height: 100dvh; padding-top: 64px;">

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

    <!-- AEI Info Modal -->
    <div id="aei-info-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-2xl max-w-md w-full max-h-[90vh] overflow-y-auto">
            <!-- Modal Header -->
            <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center">
                        <span class="text-xl text-white font-bold"><?= strtoupper(substr($aei['name'], 0, 1)) ?></span>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></h3>
                        <div class="flex items-center space-x-1">
                            <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                            <span class="text-sm text-gray-500 dark:text-gray-400">Online</span>
                        </div>
                    </div>
                </div>
                <button onclick="closeAEIInfoModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <!-- Modal Content -->
            <div class="p-6 space-y-6">
                <!-- AEI Details -->
                <div class="space-y-4">
                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Basic Info</h4>
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Age:</span>
                                <span class="ml-2 text-gray-900 dark:text-white"><?= htmlspecialchars($aei['age'] ?? 'Unknown') ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500 dark:text-gray-400">Gender:</span>
                                <span class="ml-2 text-gray-900 dark:text-white"><?= htmlspecialchars($aei['gender'] ?? 'Unknown') ?></span>
                            </div>
                        </div>
                    </div>

                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Personality</h4>
                        <div class="flex flex-wrap gap-2">
                            <?php 
                            $traits = $aei['personality_traits'] ? json_decode($aei['personality_traits'], true) : [];
                            foreach ($traits as $trait): ?>
                                <span class="px-2 py-1 bg-ayuni-blue/10 text-ayuni-blue rounded-full text-xs font-medium">
                                    <?= htmlspecialchars($trait) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <?php if (!empty($aei['interests'])): 
                        $interests = json_decode($aei['interests'], true); ?>
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Interests</h4>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($interests as $interest): ?>
                                    <span class="px-2 py-1 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-full text-xs">
                                        <?= htmlspecialchars($interest) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($aei['occupation'])): ?>
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Occupation</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($aei['occupation']) ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Font Size Settings -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Chat Settings</h4>
                    
                    <!-- Font Size Slider -->
                    <div class="space-y-3">
                        <div class="flex items-center justify-between">
                            <label class="text-sm text-gray-600 dark:text-gray-400">Message Font Size</label>
                            <span id="font-size-display" class="text-sm font-medium text-ayuni-blue">Medium</span>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-font text-xs text-gray-400"></i>
                            <input 
                                type="range" 
                                id="font-size-slider" 
                                min="12" 
                                max="20" 
                                value="14" 
                                class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer slider"
                                oninput="updateFontSize(this.value)"
                            >
                            <i class="fas fa-font text-lg text-gray-400"></i>
                        </div>
                        
                        <!-- Preview -->
                        <div class="mt-4 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <div class="text-xs text-gray-500 dark:text-gray-400 mb-2">Preview:</div>
                            <div id="font-preview" class="text-sm text-gray-900 dark:text-white">
                                This is how your messages will look in the chat.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 flex flex-col max-w-4xl mx-auto w-full min-h-0">
        <!-- Messages Area -->
        <div class="flex-1 overflow-y-auto p-4 space-y-4 min-h-0" id="messages-container">
            <!-- Load More Button (shown when there are older messages) -->
            <?php if ($totalMessages > 20): ?>
                <div class="text-center py-4" id="load-more-container">
                    <button 
                        id="load-more-btn" 
                        class="bg-ayuni-blue hover:bg-blue-600 text-white px-6 py-2 rounded-lg font-medium transition-colors"
                        onclick="loadOlderMessages()"
                    >
                        <span id="load-more-text">📜 Load older messages (<?= $totalMessages - 20 ?> more)</span>
                        <span id="load-more-loading" class="hidden">
                            <i class="fas fa-spinner fa-spin"></i> Loading...
                        </span>
                    </button>
                </div>
            <?php endif; ?>
            
            <!-- Messages will be inserted here -->
            <div id="messages-list" class="space-y-4">
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
                        <div class="max-w-xs sm:max-w-sm">
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
                                    <p class="text-sm"><?= nl2br(safeDisplayMessage($message['message_text'])) ?></p>
                                <?php endif; ?>
                                <div class="flex items-center justify-between mt-2">
                                    <p class="text-xs opacity-70">
                                        <?= date('H:i', strtotime($message['created_at'])) ?>
                                    </p>
                                    <?php if ($message['sender_type'] === 'aei'): ?>
                                        <div class="flex items-center space-x-2 ml-2">
                                            <?php if ($message['feedback_id']): ?>
                                                <!-- Already has feedback - show current rating -->
                                                <?php if ($message['feedback_rating'] === 'thumbs_up'): ?>
                                                    <button 
                                                        class="feedback-btn p-1 rounded bg-green-100 dark:bg-green-900/20 cursor-default" 
                                                        title="You already rated this response positively"
                                                        disabled
                                                    >
                                                        <i class="fas fa-thumbs-up text-xs text-green-600 dark:text-green-400"></i>
                                                    </button>
                                                    <button 
                                                        class="feedback-btn p-1 rounded opacity-30 cursor-not-allowed" 
                                                        title="You already provided feedback for this message"
                                                        disabled
                                                    >
                                                        <i class="fas fa-thumbs-down text-xs text-gray-400"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button 
                                                        class="feedback-btn p-1 rounded opacity-30 cursor-not-allowed" 
                                                        title="You already provided feedback for this message"
                                                        disabled
                                                    >
                                                        <i class="fas fa-thumbs-up text-xs text-gray-400"></i>
                                                    </button>
                                                    <button 
                                                        class="feedback-btn p-1 rounded bg-red-100 dark:bg-red-900/20 cursor-default" 
                                                        title="You already rated this response negatively"
                                                        disabled
                                                    >
                                                        <i class="fas fa-thumbs-down text-xs text-red-600 dark:text-red-400"></i>
                                                    </button>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <!-- No feedback yet - show interactive buttons -->
                                                <button 
                                                    class="feedback-btn p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" 
                                                    data-message-id="<?= htmlspecialchars($message['id']) ?>"
                                                    data-rating="thumbs_up"
                                                    onclick="showFeedbackModal('<?= htmlspecialchars($message['id']) ?>', 'thumbs_up')"
                                                    title="This response was helpful"
                                                >
                                                    <i class="fas fa-thumbs-up text-xs text-gray-400 hover:text-green-500 transition-colors"></i>
                                                </button>
                                                <button 
                                                    class="feedback-btn p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" 
                                                    data-message-id="<?= htmlspecialchars($message['id']) ?>"
                                                    data-rating="thumbs_down"
                                                    onclick="showFeedbackModal('<?= htmlspecialchars($message['id']) ?>', 'thumbs_down')"
                                                    title="This response needs improvement"
                                                >
                                                    <i class="fas fa-thumbs-down text-xs text-gray-400 hover:text-red-500 transition-colors"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            </div> <!-- End messages-list -->
        </div>

        <!-- Message Input -->
        <div class="flex-shrink-0 border-t border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-3 sm:p-4 rounded-t-2xl">
            <!-- Error/Success Messages -->
            <div id="chat-alerts" class="hidden mb-4"></div>
            
            <!-- Typing indicator -->
            <div id="typing-indicator" class="hidden mb-4">
                <div class="flex justify-start">
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

<!-- Feedback Modal -->
<div id="feedback-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                    <i id="feedback-modal-icon" class="fas fa-thumbs-up text-green-500 mr-2"></i>
                    <span id="feedback-modal-title">Feedback</span>
                </h3>
                <button 
                    onclick="closeFeedbackModal()"
                    class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                >
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <!-- Alert container inside modal -->
            <div id="feedback-modal-alerts" class="hidden mb-4"></div>
            
            <form id="feedback-form">
                <input type="hidden" id="feedback-message-id" value="">
                <input type="hidden" id="feedback-rating" value="">
                
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        What category best describes your feedback? (Optional)
                    </label>
                    <select 
                        id="feedback-category" 
                        name="category"
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                    >
                        <option value="">Select category...</option>
                        <option value="helpful">Helpful & accurate</option>
                        <option value="accurate">Factually correct</option>
                        <option value="engaging">Engaging conversation</option>
                        <option value="inappropriate">Inappropriate content</option>
                        <option value="inaccurate">Factually incorrect</option>
                        <option value="boring">Boring or repetitive</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                
                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Additional details (Optional)
                    </label>
                    <textarea 
                        id="feedback-text" 
                        name="feedback_text"
                        rows="3"
                        maxlength="500"
                        placeholder="Tell us more about your experience with this response..."
                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue resize-none"
                    ></textarea>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                        <span id="feedback-char-count">0</span>/500 characters
                    </p>
                </div>
                
                <div class="flex space-x-3">
                    <button 
                        type="button"
                        onclick="closeFeedbackModal()"
                        class="flex-1 px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                    >
                        Cancel
                    </button>
                    <button 
                        type="submit"
                        id="submit-feedback-btn"
                        class="flex-1 px-4 py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                        Submit Feedback
                    </button>
                </div>
            </form>
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

::-webkit-scrollbar-button {
    width: 8px;
    height: 16px;
    background: rgba(209, 213, 219, 0.6); /* gray-300 with opacity */
    border-radius: 2px;
    transition: all 0.2s ease;
}

::-webkit-scrollbar-button:hover {
    background: rgba(156, 163, 175, 0.8); /* gray-400 with opacity */
}

::-webkit-scrollbar-button:vertical:start:decrement {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23374151' viewBox='0 0 8 8'%3E%3Cpath d='M4 1L1 4h6z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 8px 8px;
}

::-webkit-scrollbar-button:vertical:end:increment {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23374151' viewBox='0 0 8 8'%3E%3Cpath d='M1 1h6l-3 3z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 8px 8px;
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

.dark ::-webkit-scrollbar-button {
    background: rgba(55, 65, 81, 0.6); /* gray-700 with opacity */
}

.dark ::-webkit-scrollbar-button:hover {
    background: rgba(75, 85, 99, 0.8); /* gray-600 with opacity */
}

.dark ::-webkit-scrollbar-button:vertical:start:decrement {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23D1D5DB' viewBox='0 0 8 8'%3E%3Cpath d='M4 1L1 4h6z'/%3E%3C/svg%3E");
}

.dark ::-webkit-scrollbar-button:vertical:end:increment {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='%23D1D5DB' viewBox='0 0 8 8'%3E%3Cpath d='M1 1h6l-3 3z'/%3E%3C/svg%3E");
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
    const sessionId = '<?= htmlspecialchars($sessionId) ?>';
    const aeiName = '<?= htmlspecialchars($aei['name']) ?>';
    const imageInput = document.getElementById('image-input');
    const imagePreview = document.getElementById('image-preview');
    const previewImage = document.getElementById('preview-image');
    const previewFilename = document.getElementById('preview-filename');
    const previewFilesize = document.getElementById('preview-filesize');
    let selectedImage = null;
    let currentFeedbackMessageId = null;
    
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
    
    // Proactive messages are now sent directly to chat - no polling needed
    
    // Show alert message - make it globally available
    window.showAlert = function(message, type = 'error') {
        console.log('showAlert called with:', { message, type });
        
        const alertsContainer = document.getElementById('chat-alerts');
        if (!alertsContainer) {
            console.error('Chat alerts container not found!');
            // Fallback to browser alert
            alert(message);
            return;
        }
        
        let alertClass, icon;
        
        if (type === 'error') {
            alertClass = 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400';
            icon = 'fas fa-exclamation-circle';
        } else if (type === 'info') {
            alertClass = 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-400';
            icon = 'fas fa-info-circle';
        } else if (type === 'success') {
            alertClass = 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-700 dark:text-green-400';
            icon = 'fas fa-check-circle';
        } else {
            alertClass = 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-700 dark:text-green-400';
            icon = 'fas fa-check-circle';
        }
        
        alertsContainer.innerHTML = `
            <div class="${alertClass} border px-4 py-2 rounded-lg text-sm">
                <div class="flex items-center">
                    <i class="${icon} mr-2"></i>
                    ${message}
                </div>
            </div>
        `;
        alertsContainer.classList.remove('hidden');
        console.log('Alert shown successfully');
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            alertsContainer.classList.add('hidden');
            console.log('Alert hidden after timeout');
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
            <div class="max-w-xs sm:max-w-sm">
                <div class="${message.sender_type === 'user' ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600'} rounded-2xl px-4 py-2 shadow-sm">
                    ${imageHtml}
                    ${textHtml}
                    <div class="flex items-center justify-between mt-2">
                        <p class="text-xs opacity-70">${time}</p>
                        ${message.sender_type === 'aei' ? `
                            <div class="flex items-center space-x-2 ml-2">
                                <button 
                                    class="feedback-btn p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" 
                                    data-message-id="${message.id}"
                                    data-rating="thumbs_up"
                                    onclick="showFeedbackModal('${message.id}', 'thumbs_up')"
                                    title="This response was helpful"
                                >
                                    <i class="fas fa-thumbs-up text-xs text-gray-400 hover:text-green-500 transition-colors"></i>
                                </button>
                                <button 
                                    class="feedback-btn p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" 
                                    data-message-id="${message.id}"
                                    data-rating="thumbs_down"
                                    onclick="showFeedbackModal('${message.id}', 'thumbs_down')"
                                    title="This response needs improvement"
                                >
                                    <i class="fas fa-thumbs-down text-xs text-gray-400 hover:text-red-500 transition-colors"></i>
                                </button>
                            </div>
                        ` : ''}
                    </div>
                </div>
            </div>
        `;
        
        container.appendChild(messageDiv);
        
        // Apply current font size to new message
        applyFontSizeToNewMessage(messageDiv);
        
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
            // Check if device is mobile - prevent Enter submission on mobile
            const isMobile = window.innerWidth <= 768 || /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            
            if (!isMobile) {
                e.preventDefault();
                form.dispatchEvent(new Event('submit'));
            }
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
    
    const textToCopy = JSON.stringify(currentDebugData, null, 2);
    
    // Try modern clipboard API first
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            showCopySuccess(event.target.closest('button'));
        }).catch(() => {
            fallbackCopyTextToClipboard(textToCopy, event.target.closest('button'));
        });
    } else {
        // Fallback for older browsers or non-HTTPS
        fallbackCopyTextToClipboard(textToCopy, event.target.closest('button'));
    }
}

function copyApiRequest() {
    if (!currentDebugData || !currentDebugData.api_request_payload) {
        alert('No API request data available to copy');
        return;
    }
    
    const textToCopy = JSON.stringify(currentDebugData.api_request_payload, null, 2);
    
    // Try modern clipboard API first
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            showCopySuccess(event.target.closest('button'));
        }).catch(() => {
            fallbackCopyTextToClipboard(textToCopy, event.target.closest('button'));
        });
    } else {
        // Fallback for older browsers or non-HTTPS
        fallbackCopyTextToClipboard(textToCopy, event.target.closest('button'));
    }
}

// Fallback copy function for older browsers or HTTP
function fallbackCopyTextToClipboard(text, button) {
    try {
        const textArea = document.createElement('textarea');
        textArea.value = text;
        
        // Make the textarea invisible
        textArea.style.position = 'fixed';
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.width = '2em';
        textArea.style.height = '2em';
        textArea.style.padding = '0';
        textArea.style.border = 'none';
        textArea.style.outline = 'none';
        textArea.style.boxShadow = 'none';
        textArea.style.background = 'transparent';
        textArea.style.opacity = '0';
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        const successful = document.execCommand('copy');
        document.body.removeChild(textArea);
        
        if (successful) {
            showCopySuccess(button);
        } else {
            showCopyError(button);
        }
    } catch (err) {
        console.error('Fallback copy failed:', err);
        showCopyError(button);
    }
}

function showCopySuccess(button) {
    if (!button) return;
    
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-check mr-1"></i>Copied!';
    button.classList.add('bg-green-700');
    button.disabled = true;
    
    setTimeout(() => {
        button.innerHTML = originalText;
        button.classList.remove('bg-green-700');
        button.disabled = false;
    }, 2000);
}

function showCopyError(button) {
    if (!button) return;
    
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-times mr-1"></i>Failed';
    button.classList.add('bg-red-700');
    button.disabled = true;
    
    setTimeout(() => {
        button.innerHTML = originalText;
        button.classList.remove('bg-red-700');
        button.disabled = false;
    }, 2000);
    
    // Also show alert as additional feedback
    alert('Failed to copy to clipboard. Please copy manually from the debug panel.');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
<?php endif; ?>

// Proactive messages are now sent directly to chat - no notification system needed

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

// Feedback modal functions
function showFeedbackModal(messageId, rating) {
    console.log('showFeedbackModal called with:', { messageId, rating });
    
    currentFeedbackMessageId = messageId;
    
    const modal = document.getElementById('feedback-modal');
    const modalIcon = document.getElementById('feedback-modal-icon');
    const modalTitle = document.getElementById('feedback-modal-title');
    const feedbackMessageIdInput = document.getElementById('feedback-message-id');
    const feedbackRatingInput = document.getElementById('feedback-rating');
    const feedbackCategory = document.getElementById('feedback-category');
    const feedbackText = document.getElementById('feedback-text');
    
    // Check if all elements exist
    const elementsCheck = {
        modal: !!modal,
        modalIcon: !!modalIcon,
        modalTitle: !!modalTitle,
        feedbackMessageIdInput: !!feedbackMessageIdInput,
        feedbackRatingInput: !!feedbackRatingInput,
        feedbackCategory: !!feedbackCategory,
        feedbackText: !!feedbackText
    };
    console.log('Modal elements check:', elementsCheck);
    
    if (!modal) {
        console.error('Feedback modal not found!');
        alert('Error: Feedback modal not found. Please refresh the page.');
        return;
    }
    
    // Set values
    feedbackMessageIdInput.value = messageId;
    feedbackRatingInput.value = rating;
    
    // Update modal appearance based on rating
    if (rating === 'thumbs_up') {
        modalIcon.className = 'fas fa-thumbs-up text-green-500 mr-2';
        modalTitle.textContent = 'Positive Feedback';
        feedbackCategory.innerHTML = `
            <option value="">Select category...</option>
            <option value="helpful">Helpful & accurate</option>
            <option value="accurate">Factually correct</option>
            <option value="engaging">Engaging conversation</option>
            <option value="other">Other</option>
        `;
    } else {
        modalIcon.className = 'fas fa-thumbs-down text-red-500 mr-2';
        modalTitle.textContent = 'Feedback for Improvement';
        feedbackCategory.innerHTML = `
            <option value="">Select category...</option>
            <option value="inappropriate">Inappropriate content</option>
            <option value="inaccurate">Factually incorrect</option>
            <option value="boring">Boring or repetitive</option>
            <option value="other">Other</option>
        `;
    }
    
    // Reset form
    feedbackCategory.value = '';
    feedbackText.value = '';
    document.getElementById('feedback-char-count').textContent = '0';
    
    modal.classList.remove('hidden');
    
    // Close modal on click outside
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeFeedbackModal();
        }
    });
    
    // Close modal on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeFeedbackModal();
        }
    });
}

function closeFeedbackModal() {
    const modal = document.getElementById('feedback-modal');
    modal.classList.add('hidden');
    currentFeedbackMessageId = null;
    
    // Clear any pending auto-close timeout
    if (window.feedbackAutoCloseTimeout) {
        clearTimeout(window.feedbackAutoCloseTimeout);
        window.feedbackAutoCloseTimeout = null;
    }
    
    // Hide modal alerts and show form again
    const modalAlerts = document.getElementById('feedback-modal-alerts');
    if (modalAlerts) {
        modalAlerts.classList.add('hidden');
    }
    
    // Show form again and reset it
    const feedbackForm = document.getElementById('feedback-form');
    if (feedbackForm) {
        feedbackForm.style.display = 'block';
        feedbackForm.reset();
    }
}

// Function to show alerts inside the feedback modal
function showFeedbackModalAlert(message, type = 'success') {
    console.log('showFeedbackModalAlert called with:', { message, type });
    
    const alertsContainer = document.getElementById('feedback-modal-alerts');
    if (!alertsContainer) {
        console.error('Modal alerts container not found!');
        // Fallback to regular alert
        window.showAlert(message, type);
        return;
    }
    
    let alertClass, icon;
    
    if (type === 'error') {
        alertClass = 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-700 dark:text-red-400';
        icon = 'fas fa-exclamation-circle';
    } else if (type === 'success') {
        alertClass = 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-700 dark:text-green-400';
        icon = 'fas fa-check-circle';
    } else {
        alertClass = 'bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800 text-blue-700 dark:text-blue-400';
        icon = 'fas fa-info-circle';
    }
    
    alertsContainer.innerHTML = `
        <div class="${alertClass} border px-4 py-3 rounded-lg text-sm">
            <div class="flex items-center">
                <i class="${icon} mr-2"></i>
                <span>${message}</span>
            </div>
        </div>
    `;
    alertsContainer.classList.remove('hidden');
    console.log('Modal alert shown successfully');
}

// Setup feedback form handling
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded - setting up feedback form');
    
    // Character count for feedback text
    const feedbackText = document.getElementById('feedback-text');
    const charCount = document.getElementById('feedback-char-count');
    
    if (feedbackText && charCount) {
        feedbackText.addEventListener('input', function() {
            charCount.textContent = this.value.length;
        });
        console.log('Feedback text character counter set up successfully');
    } else {
        console.error('Feedback form elements not found:', {
            feedbackText: !!feedbackText,
            charCount: !!charCount
        });
    }
    
    // Feedback form submission
    const feedbackForm = document.getElementById('feedback-form');
    if (!feedbackForm) {
        console.error('Feedback form not found!');
        return;
    }
    
    console.log('Setting up feedback form submit handler');
    feedbackForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        console.log('Feedback form submitted');
        
        const submitBtn = document.getElementById('submit-feedback-btn');
        const originalText = submitBtn.textContent;
        let formData; // Define outside try block for access in success handler
        
        // Disable submit button
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';
        
        try {
            // Get CSRF token
            const csrfTokenElement = document.getElementById('csrf-token');
            if (!csrfTokenElement) {
                throw new Error('CSRF token not found - page may not be loaded correctly');
            }
            
            formData = {
                message_id: document.getElementById('feedback-message-id').value,
                rating: document.getElementById('feedback-rating').value,
                category: document.getElementById('feedback-category').value,
                feedback_text: document.getElementById('feedback-text').value,
                csrf_token: csrfTokenElement.value
            };
            
            // Validate required fields
            if (!formData.message_id) {
                throw new Error('Message ID is missing - feedback cannot be submitted');
            }
            if (!formData.rating) {
                throw new Error('Rating is missing - please select thumbs up or down');
            }
            if (!formData.csrf_token) {
                throw new Error('Security token is missing - please refresh the page');
            }
            
            console.log('Submitting feedback with data:', formData);
            
            const response = await fetch('/api/feedback.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(formData)
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Failed to submit feedback');
            }
            
            console.log('Feedback submitted successfully:', data);
            
            // Hide the form and show success message in modal
            const feedbackForm = document.getElementById('feedback-form');
            feedbackForm.style.display = 'none';
            
            // Show success message in modal with countdown
            showFeedbackModalAlert('✅ Thank you for your feedback! It helps us improve your AEI.', 'success');
            
            // Add countdown to the alert
            let countdown = 10;
            const alertsContainer = document.getElementById('feedback-modal-alerts');
            
            function updateCountdown() {
                if (countdown > 0 && alertsContainer && !alertsContainer.classList.contains('hidden')) {
                    const countdownElement = alertsContainer.querySelector('.countdown-text');
                    if (countdownElement) {
                        countdownElement.textContent = `Closing in ${countdown}s...`;
                    } else {
                        // Add countdown text if it doesn't exist
                        const alertDiv = alertsContainer.querySelector('div');
                        if (alertDiv) {
                            alertDiv.innerHTML += '<div class="text-xs opacity-75 mt-2 countdown-text">Closing in ' + countdown + 's...</div>';
                        }
                    }
                    countdown--;
                    setTimeout(updateCountdown, 1000);
                }
            }
            
            // Start countdown after a short delay
            setTimeout(updateCountdown, 500);
            
            // Auto-close modal after 10 seconds
            window.feedbackAutoCloseTimeout = setTimeout(() => {
                closeFeedbackModal();
                // Show form again for next time
                feedbackForm.style.display = 'block';
            }, 10000);
            
            // Update the feedback buttons for this message to show they were clicked
            const messageButtons = document.querySelectorAll(`[data-message-id="${formData.message_id}"]`);
            messageButtons.forEach(btn => {
                if (btn.dataset.rating === formData.rating) {
                    const icon = btn.querySelector('i');
                    if (formData.rating === 'thumbs_up') {
                        icon.classList.remove('text-gray-400', 'hover:text-green-500');
                        icon.classList.add('text-green-500');
                    } else {
                        icon.classList.remove('text-gray-400', 'hover:text-red-500');
                        icon.classList.add('text-red-500');
                    }
                    btn.disabled = true;
                    btn.classList.add('cursor-not-allowed');
                }
            });
            
        } catch (error) {
            console.error('Feedback error:', error);
            console.error('Feedback error details:', {
                message: error.message,
                stack: error.stack,
                formData: formData || 'formData not yet defined',
                response: typeof response !== 'undefined' && response ? {
                    status: response.status,
                    statusText: response.statusText,
                    url: response.url
                } : 'No response'
            });
            showFeedbackModalAlert(error.message || 'Failed to submit feedback. Please try again.', 'error');
        } finally {
            // Re-enable submit button
            try {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            } catch (finalError) {
                console.error('Error in finally block:', finalError);
            }
        }
    });
});

// Chat Pagination / Load More Messages
let currentOffset = 20; // We already loaded first 20 messages
let isLoading = false;
const sessionId = '<?= $sessionId ?>';
const aeiName = '<?= htmlspecialchars($aei['name']) ?>';

function createMessageElement(message) {
    const isUser = message.sender_type === 'user';
    
    return `
        <div class="flex ${isUser ? 'justify-end' : 'justify-start'}">
            <div class="max-w-xs sm:max-w-sm">
                <div class="${isUser ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-600'} rounded-2xl px-4 py-2 shadow-sm">
                    ${message.has_image && message.image_filename ? `
                        <div class="mb-2">
                            <img 
                                src="/uploads/chat_images/${message.image_filename}" 
                                alt="${message.image_original_name || 'Shared image'}"
                                class="max-w-full h-auto rounded-lg shadow-sm cursor-pointer hover:opacity-90 transition-opacity"
                                onclick="openImageModal('${message.image_filename}', '${message.image_original_name || 'Shared image'}')"
                                loading="lazy"
                            >
                        </div>
                    ` : ''}
                    ${message.message_text ? `<p class="text-sm">${message.message_text.replace(/\n/g, '<br>')}</p>` : ''}
                    <div class="flex items-center justify-between mt-2">
                        <p class="text-xs opacity-70">
                            ${new Date(message.created_at).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'})}
                        </p>
                        ${!isUser ? getFeedbackButtons(message) : ''}
                    </div>
                </div>
            </div>
        </div>
    `;
}

function getFeedbackButtons(message) {
    if (message.feedback_id) {
        // Already has feedback
        if (message.feedback_rating === 'thumbs_up') {
            return `
                <div class="flex items-center space-x-2 ml-2">
                    <button class="feedback-btn p-1 rounded bg-green-100 dark:bg-green-900/20 cursor-default" disabled>
                        <i class="fas fa-thumbs-up text-xs text-green-600 dark:text-green-400"></i>
                    </button>
                    <button class="feedback-btn p-1 rounded opacity-30 cursor-not-allowed" disabled>
                        <i class="fas fa-thumbs-down text-xs text-gray-400"></i>
                    </button>
                </div>
            `;
        } else {
            return `
                <div class="flex items-center space-x-2 ml-2">
                    <button class="feedback-btn p-1 rounded opacity-30 cursor-not-allowed" disabled>
                        <i class="fas fa-thumbs-up text-xs text-gray-400"></i>
                    </button>
                    <button class="feedback-btn p-1 rounded bg-red-100 dark:bg-red-900/20 cursor-default" disabled>
                        <i class="fas fa-thumbs-down text-xs text-red-600 dark:text-red-400"></i>
                    </button>
                </div>
            `;
        }
    } else {
        // No feedback yet
        return `
            <div class="flex items-center space-x-2 ml-2">
                <button 
                    class="feedback-btn p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" 
                    data-message-id="${message.id}"
                    data-rating="thumbs_up"
                    onclick="showFeedbackModal('${message.id}', 'thumbs_up')"
                    title="This response was helpful"
                >
                    <i class="fas fa-thumbs-up text-xs text-gray-400 hover:text-green-500 transition-colors"></i>
                </button>
                <button 
                    class="feedback-btn p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors" 
                    data-message-id="${message.id}"
                    data-rating="thumbs_down"
                    onclick="showFeedbackModal('${message.id}', 'thumbs_down')"
                    title="This response could be improved"
                >
                    <i class="fas fa-thumbs-down text-xs text-gray-400 hover:text-red-500 transition-colors"></i>
                </button>
            </div>
        `;
    }
}

async function loadOlderMessages() {
    console.log('loadOlderMessages called');
    
    if (isLoading) {
        console.log('Already loading, returning');
        return;
    }
    
    isLoading = true;
    const loadBtn = document.getElementById('load-more-btn');
    const loadText = document.getElementById('load-more-text');
    const loadSpinner = document.getElementById('load-more-loading');
    
    console.log('Elements found:', { loadBtn, loadText, loadSpinner });
    
    // Show loading state
    loadText.classList.add('hidden');
    loadSpinner.classList.remove('hidden');
    loadBtn.disabled = true;
    
    try {
        const requestBody = {
            session_id: sessionId,
            offset: currentOffset,
            limit: 20
        };
        
        console.log('Making API request:', requestBody);
        
        const response = await fetch('/api/load-messages.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(requestBody)
        });
        
        console.log('Response received:', response.status, response.statusText);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('API Error Response:', errorText);
            throw new Error(`HTTP ${response.status}: ${response.statusText}`);
        }
        
        const data = await response.json();
        console.log('API Response Data:', data);
        
        if (!data.success) {
            throw new Error(data.error || 'Failed to load messages');
        }
        
        // Add messages to the beginning of the messages list
        const messagesList = document.getElementById('messages-list');
        const oldScrollHeight = messagesList.scrollHeight;
        
        // Insert older messages at the top in correct chronological order
        const fragment = document.createDocumentFragment();
        
        data.messages.forEach((message) => {
            const messageElement = document.createElement('div');
            messageElement.innerHTML = createMessageElement(message);
            messageElement.style.marginBottom = '1rem'; // Explicit spacing to match space-y-4
            
            // Apply current font size to new messages
            applyFontSizeToNewMessage(messageElement);
            
            fragment.appendChild(messageElement);
        });

        // Insert the entire fragment at the beginning to preserve order
        if (messagesList.firstChild) {
            messagesList.insertBefore(fragment, messagesList.firstChild);
        } else {
            messagesList.appendChild(fragment);
        }
        
        // Maintain scroll position - user stays at the same visual position
        const messagesContainer = document.getElementById('messages-container');
        messagesContainer.scrollTop = messagesList.scrollHeight - oldScrollHeight;
        
        // Update offset for next load
        currentOffset += data.loaded;
        
        // Update or hide the load more button
        if (data.has_more) {
            const remaining = data.total - currentOffset;
            loadText.textContent = `📜 Load older messages (${remaining} more)`;
        } else {
            document.getElementById('load-more-container').style.display = 'none';
        }
        
    } catch (error) {
        console.error('Error loading older messages:', error);
        alert('Failed to load older messages. Please try again.');
    } finally {
        isLoading = false;
        loadBtn.disabled = false;
        loadText.classList.remove('hidden');
        loadSpinner.classList.add('hidden');
    }
}

// AEI Info Modal & Font Size Management
let currentFontSize = 14; // Default font size

// Load saved font size from localStorage
function loadFontSize() {
    const savedSize = localStorage.getItem('ayuni-chat-font-size');
    if (savedSize) {
        currentFontSize = parseInt(savedSize);
        document.getElementById('font-size-slider').value = currentFontSize;
        updateFontSize(currentFontSize);
    }
}

// Save font size to localStorage
function saveFontSize(size) {
    localStorage.setItem('ayuni-chat-font-size', size.toString());
}

// Update font size throughout the chat
function updateFontSize(size) {
    currentFontSize = parseInt(size);
    
    // Update slider display
    const sizeLabels = {
        12: 'Small',
        13: 'Small+',
        14: 'Medium',
        15: 'Medium+', 
        16: 'Large',
        17: 'Large+',
        18: 'XL',
        19: 'XL+',
        20: 'XXL'
    };
    
    document.getElementById('font-size-display').textContent = sizeLabels[size] || 'Medium';
    
    // Update preview
    const preview = document.getElementById('font-preview');
    preview.style.fontSize = size + 'px';
    
    // Update all message text in chat
    const messageTexts = document.querySelectorAll('#messages-list .text-sm');
    messageTexts.forEach(element => {
        // Only update message content text, not timestamps
        if (!element.classList.contains('opacity-70')) {
            element.style.fontSize = size + 'px';
        }
    });
    
    // Save to localStorage
    saveFontSize(size);
    
    console.log('Font size updated to:', size + 'px');
}

// Modal functions
function openAEIInfoModal() {
    console.log('Opening AEI Info Modal');
    document.getElementById('aei-info-modal').classList.remove('hidden');
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

function closeAEIInfoModal() {
    console.log('Closing AEI Info Modal');
    document.getElementById('aei-info-modal').classList.add('hidden');
    document.body.style.overflow = ''; // Restore scrolling
}

// Close modal when clicking outside
document.getElementById('aei-info-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeAEIInfoModal();
    }
});

// Initialize font size on page load
document.addEventListener('DOMContentLoaded', function() {
    loadFontSize();
});

// Apply font size to new messages when they're added
function applyFontSizeToNewMessage(messageElement) {
    const textElements = messageElement.querySelectorAll('.text-sm');
    textElements.forEach(element => {
        // Only update message content text, not timestamps
        if (!element.classList.contains('opacity-70')) {
            element.style.fontSize = currentFontSize + 'px';
        }
    });
}

</script>