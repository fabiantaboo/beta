<?php
requireAdmin();

// Get filter parameters
$rating_filter = $_GET['rating'] ?? '';
$category_filter = $_GET['category'] ?? '';
$aei_filter = $_GET['aei'] ?? '';
$page_num = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page_num - 1) * $per_page;

// Build WHERE conditions
$where_conditions = [];
$params = [];

if ($rating_filter && in_array($rating_filter, ['thumbs_up', 'thumbs_down'])) {
    $where_conditions[] = "mf.rating = ?";
    $params[] = $rating_filter;
}

if ($category_filter && in_array($category_filter, ['helpful', 'accurate', 'engaging', 'inappropriate', 'inaccurate', 'boring', 'other'])) {
    $where_conditions[] = "mf.feedback_category = ?";
    $params[] = $category_filter;
}

if ($aei_filter) {
    $where_conditions[] = "a.id = ?";
    $params[] = $aei_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get feedback statistics
try {
    // Total feedback count
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_feedback,
            SUM(CASE WHEN rating = 'thumbs_up' THEN 1 ELSE 0 END) as positive_feedback,
            SUM(CASE WHEN rating = 'thumbs_down' THEN 1 ELSE 0 END) as negative_feedback
        FROM message_feedback mf
        JOIN aeis a ON mf.aei_id = a.id
        JOIN users u ON mf.user_id = u.id
        $where_clause
    ");
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
    // Feedback by category
    $stmt = $pdo->prepare("
        SELECT 
            feedback_category, 
            COUNT(*) as count,
            SUM(CASE WHEN rating = 'thumbs_up' THEN 1 ELSE 0 END) as positive,
            SUM(CASE WHEN rating = 'thumbs_down' THEN 1 ELSE 0 END) as negative
        FROM message_feedback mf
        JOIN aeis a ON mf.aei_id = a.id
        JOIN users u ON mf.user_id = u.id
        $where_clause
        GROUP BY feedback_category
        ORDER BY count DESC
    ");
    $stmt->execute($params);
    $category_stats = $stmt->fetchAll();
    
    // Get feedback data with pagination
    $stmt = $pdo->prepare("
        SELECT 
            mf.id,
            mf.rating,
            mf.feedback_category,
            mf.feedback_text,
            mf.message_context,
            mf.created_at,
            mf.updated_at,
            u.first_name as user_name,
            u.email as user_email,
            a.name as aei_name,
            a.id as aei_id,
            cm.message_text as original_message,
            SUBSTRING(cm.message_text, 1, 100) as message_preview
        FROM message_feedback mf
        JOIN users u ON mf.user_id = u.id
        JOIN aeis a ON mf.aei_id = a.id
        JOIN chat_messages cm ON mf.message_id = cm.id
        $where_clause
        ORDER BY mf.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params_with_pagination = array_merge($params, [$per_page, $offset]);
    $stmt->execute($params_with_pagination);
    $feedback_data = $stmt->fetchAll();
    
    // Get total count for pagination
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as total
        FROM message_feedback mf
        JOIN aeis a ON mf.aei_id = a.id
        JOIN users u ON mf.user_id = u.id
        $where_clause
    ");
    $stmt->execute($params);
    $total_records = $stmt->fetch()['total'];
    $total_pages = ceil($total_records / $per_page);
    
    // Get AEI list for filter dropdown
    $stmt = $pdo->prepare("SELECT DISTINCT a.id, a.name FROM aeis a JOIN message_feedback mf ON a.id = mf.aei_id ORDER BY a.name");
    $stmt->execute();
    $aei_list = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Feedback admin error: " . $e->getMessage());
    $stats = ['total_feedback' => 0, 'positive_feedback' => 0, 'negative_feedback' => 0];
    $category_stats = [];
    $feedback_data = [];
    $total_records = 0;
    $total_pages = 0;
    $aei_list = [];
}

$positive_percentage = $stats['total_feedback'] > 0 ? round(($stats['positive_feedback'] / $stats['total_feedback']) * 100, 1) : 0;
$negative_percentage = $stats['total_feedback'] > 0 ? round(($stats['negative_feedback'] / $stats['total_feedback']) * 100, 1) : 0;
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-feedback'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('User Feedback', 'Monitor and analyze user feedback on AEI responses'); ?>

        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-blue-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-comments text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Feedback</p>
                        <p class="text-3xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total_feedback']) ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-green-500 to-green-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-thumbs-up text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Positive</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['positive_feedback'] ?></p>
                        <p class="text-xs text-green-600 dark:text-green-400"><?= $positive_percentage ?>%</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-red-500 to-red-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-thumbs-down text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Negative</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $stats['negative_feedback'] ?></p>
                        <p class="text-xs text-red-600 dark:text-red-400"><?= $negative_percentage ?>%</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white dark:bg-gray-800 rounded-xl p-6 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center">
                    <div class="w-12 h-12 bg-gradient-to-r from-purple-500 to-purple-600 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-line text-white text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Satisfaction</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $positive_percentage ?>%</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Approval Rate</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Filters</h3>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rating</label>
                    <select name="rating" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <option value="">All Ratings</option>
                        <option value="thumbs_up" <?= $rating_filter === 'thumbs_up' ? 'selected' : '' ?>>👍 Positive</option>
                        <option value="thumbs_down" <?= $rating_filter === 'thumbs_down' ? 'selected' : '' ?>>👎 Negative</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Category</label>
                    <select name="category" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <option value="">All Categories</option>
                        <option value="helpful" <?= $category_filter === 'helpful' ? 'selected' : '' ?>>Helpful & accurate</option>
                        <option value="accurate" <?= $category_filter === 'accurate' ? 'selected' : '' ?>>Factually correct</option>
                        <option value="engaging" <?= $category_filter === 'engaging' ? 'selected' : '' ?>>Engaging conversation</option>
                        <option value="inappropriate" <?= $category_filter === 'inappropriate' ? 'selected' : '' ?>>Inappropriate content</option>
                        <option value="inaccurate" <?= $category_filter === 'inaccurate' ? 'selected' : '' ?>>Factually incorrect</option>
                        <option value="boring" <?= $category_filter === 'boring' ? 'selected' : '' ?>>Boring or repetitive</option>
                        <option value="other" <?= $category_filter === 'other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">AEI</label>
                    <select name="aei" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white">
                        <option value="">All AEIs</option>
                        <?php foreach ($aei_list as $aei_item): ?>
                            <option value="<?= htmlspecialchars($aei_item['id']) ?>" <?= $aei_filter === $aei_item['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($aei_item['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex items-end space-x-2">
                    <button type="submit" class="px-4 py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all">
                        <i class="fas fa-filter mr-2"></i>Apply Filters
                    </button>
                    <a href="/admin/feedback" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                        <i class="fas fa-times mr-2"></i>Clear
                    </a>
                </div>
            </form>
        </div>

        <!-- Category Statistics -->
        <?php if (!empty($category_stats)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Feedback by Category</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <?php foreach ($category_stats as $cat_stat): ?>
                    <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-medium text-gray-900 dark:text-white capitalize">
                                <?= $cat_stat['feedback_category'] ?: 'No Category' ?>
                            </span>
                            <span class="text-sm text-gray-600 dark:text-gray-400"><?= $cat_stat['count'] ?></span>
                        </div>
                        <div class="flex justify-between text-xs">
                            <span class="text-green-600 dark:text-green-400">👍 <?= $cat_stat['positive'] ?></span>
                            <span class="text-red-600 dark:text-red-400">👎 <?= $cat_stat['negative'] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Feedback Table -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Feedback Details</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Showing <?= count($feedback_data) ?> of <?= number_format($total_records) ?> feedback entries
                </p>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50 dark:bg-gray-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Rating</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">AEI</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Category</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Original Message</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Context</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Feedback</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (empty($feedback_data)): ?>
                            <tr>
                                <td colspan="8" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-comment-slash text-3xl mb-2"></i>
                                    <p>No feedback found matching your criteria</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($feedback_data as $feedback): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($feedback['rating'] === 'thumbs_up'): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-400">
                                                <i class="fas fa-thumbs-up mr-1"></i> Positive
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/20 text-red-800 dark:text-red-400">
                                                <i class="fas fa-thumbs-down mr-1"></i> Negative
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?= htmlspecialchars($feedback['user_name']) ?>
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                <?= htmlspecialchars($feedback['user_email']) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($feedback['aei_name']) ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($feedback['feedback_category']): ?>
                                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400 capitalize">
                                                <?= htmlspecialchars($feedback['feedback_category']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400 dark:text-gray-500">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 max-w-xs">
                                        <div class="text-sm text-gray-900 dark:text-white truncate" title="<?= htmlspecialchars($feedback['original_message']) ?>">
                                            <?= htmlspecialchars($feedback['message_preview']) ?><?= strlen($feedback['original_message']) > 100 ? '...' : '' ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($feedback['message_context']): ?>
                                            <?php
                                            $context = json_decode($feedback['message_context'], true);
                                            $contextCount = $context ? count($context) : 0;
                                            ?>
                                            <button 
                                                onclick="showContextModal('<?= htmlspecialchars($feedback['id']) ?>', <?= htmlspecialchars(json_encode($context)) ?>)"
                                                class="inline-flex items-center px-2 py-1 bg-blue-100 dark:bg-blue-900/20 text-blue-800 dark:text-blue-400 text-xs font-medium rounded-full hover:bg-blue-200 dark:hover:bg-blue-900/40 transition-colors"
                                                title="View conversation context (<?= $contextCount ?> messages)"
                                            >
                                                <i class="fas fa-comments mr-1"></i>
                                                <?= $contextCount ?> messages
                                            </button>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400 dark:text-gray-500">No context</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 max-w-xs">
                                        <?php if ($feedback['feedback_text']): ?>
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?= nl2br(htmlspecialchars($feedback['feedback_text'])) ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-sm text-gray-400 dark:text-gray-500 italic">No additional feedback</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?= date('M j, Y H:i', strtotime($feedback['created_at'])) ?>
                                        <?php if ($feedback['updated_at'] !== $feedback['created_at']): ?>
                                            <div class="text-xs text-gray-400 dark:text-gray-500">
                                                Updated: <?= date('M j, H:i', strtotime($feedback['updated_at'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-gray-700 dark:text-gray-300">
                        Showing page <?= $page_num ?> of <?= $total_pages ?>
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page_num > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page_num - 1])) ?>" 
                               class="px-3 py-2 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300">
                                Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $start_page = max(1, $page_num - 2);
                        $end_page = min($total_pages, $page_num + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                               class="px-3 py-2 text-sm <?= $i === $page_num ? 'bg-ayuni-blue text-white' : 'bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600' ?> rounded-lg">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page_num < $total_pages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page_num + 1])) ?>" 
                               class="px-3 py-2 text-sm bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300">
                                Next
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Context Modal -->
<div id="context-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] mx-4 flex flex-col">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white flex items-center">
                <i class="fas fa-comments text-blue-500 mr-2"></i>
                Conversation Context
            </h3>
            <button 
                onclick="closeContextModal()"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
            >
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6">
            <div id="context-messages" class="space-y-4">
                <!-- Messages will be loaded here -->
            </div>
        </div>
        
        <div class="p-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-700 text-sm text-gray-600 dark:text-gray-400">
            <p><strong>Note:</strong> This shows the last 20 messages leading up to and including the message that received feedback. The highlighted message is the one that was rated.</p>
        </div>
    </div>
</div>

<script>
function showContextModal(feedbackId, context) {
    const modal = document.getElementById('context-modal');
    const messagesContainer = document.getElementById('context-messages');
    
    if (!context || !Array.isArray(context)) {
        messagesContainer.innerHTML = '<p class="text-center text-gray-500 dark:text-gray-400">No context available</p>';
        modal.classList.remove('hidden');
        return;
    }
    
    let messagesHtml = '';
    
    context.forEach((message, index) => {
        const isTarget = message.is_target;
        const isUser = message.sender_type === 'user';
        const timestamp = new Date(message.timestamp).toLocaleString();
        
        const bgColor = isTarget 
            ? 'bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-200 dark:border-yellow-800' 
            : 'bg-gray-50 dark:bg-gray-700';
        
        const senderColor = isUser ? 'text-blue-600 dark:text-blue-400' : 'text-purple-600 dark:text-purple-400';
        const senderIcon = isUser ? 'fa-user' : 'fa-robot';
        
        let messageContent = '';
        if (message.has_image && message.image_name) {
            messageContent += `<div class="text-sm text-gray-600 dark:text-gray-400 italic mb-2"><i class="fas fa-image mr-1"></i>Image: ${escapeHtml(message.image_name)}</div>`;
        }
        if (message.message_text) {
            messageContent += `<p class="text-sm text-gray-900 dark:text-white">${escapeHtml(message.message_text).replace(/\n/g, '<br>')}</p>`;
        }
        
        messagesHtml += `
            <div class="p-4 rounded-lg border ${bgColor} ${isTarget ? 'shadow-md' : ''}">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center space-x-2">
                        <i class="fas ${senderIcon} ${senderColor}"></i>
                        <span class="font-medium ${senderColor} capitalize">${message.sender_type}</span>
                        ${isTarget ? '<span class="ml-2 px-2 py-0.5 bg-yellow-200 dark:bg-yellow-800 text-yellow-800 dark:text-yellow-200 text-xs font-medium rounded-full">Feedback Target</span>' : ''}
                    </div>
                    <span class="text-xs text-gray-500 dark:text-gray-400">${timestamp}</span>
                </div>
                <div class="ml-6">
                    ${messageContent || '<span class="text-sm text-gray-400 italic">Empty message</span>'}
                </div>
            </div>
        `;
    });
    
    messagesContainer.innerHTML = messagesHtml;
    modal.classList.remove('hidden');
    
    // Scroll to target message if it exists
    setTimeout(() => {
        const targetMessage = messagesContainer.querySelector('.border-yellow-200, .border-yellow-800');
        if (targetMessage) {
            targetMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, 100);
}

function closeContextModal() {
    const modal = document.getElementById('context-modal');
    modal.classList.add('hidden');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Close modal on click outside
document.getElementById('context-modal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeContextModal();
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeContextModal();
    }
});
</script>