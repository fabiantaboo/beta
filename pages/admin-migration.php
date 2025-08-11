<?php
requireAdmin();

// Handle AJAX requests for migration actions
if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? 'check';
    
    try {
        switch ($action) {
            case 'check':
                echo json_encode(checkMigrationStatus());
                break;
            case 'debug':
                echo json_encode(debugInteractions());
                break;
            case 'test':
                echo json_encode(testDialogCreation());
                break;
            default:
                echo json_encode(['success' => false, 'error' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function checkMigrationStatus() {
    global $pdo;
    
    $stmt = $pdo->query("DESCRIBE aei_contact_interactions");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $requiredColumns = ['dialog_history', 'initiated_by', 'processed_for_emotions'];
    $missingColumns = [];
    $existingColumns = [];
    
    foreach ($requiredColumns as $col) {
        if (in_array($col, $columns)) {
            $existingColumns[] = $col;
        } else {
            $missingColumns[] = $col;
        }
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM aei_contact_interactions");
    $totalInteractions = $stmt->fetch()['total'];
    
    $withDialogs = 0;
    if (in_array('dialog_history', $columns)) {
        $stmt = $pdo->query("SELECT COUNT(*) as with_dialogs FROM aei_contact_interactions WHERE dialog_history IS NOT NULL AND dialog_history != ''");
        $withDialogs = $stmt->fetch()['with_dialogs'];
    }
    
    return [
        'success' => true,
        'required_columns' => $requiredColumns,
        'existing_columns' => $existingColumns,
        'missing_columns' => $missingColumns,
        'migration_complete' => empty($missingColumns),
        'total_interactions' => $totalInteractions,
        'with_dialog_history' => $withDialogs,
        'percentage_with_dialogs' => $totalInteractions > 0 ? round(($withDialogs / $totalInteractions) * 100, 1) : 0
    ];
}

function debugInteractions() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT 
            i.*,
            c.name as contact_name,
            a.name as aei_name
        FROM aei_contact_interactions i
        LEFT JOIN aei_social_contacts c ON i.contact_id = c.id
        LEFT JOIN aeis a ON i.aei_id = a.id
        ORDER BY i.occurred_at DESC
        LIMIT 5
    ");
    $interactions = $stmt->fetchAll();
    
    $debugInfo = [];
    foreach ($interactions as $interaction) {
        $dialogHistory = null;
        if (!empty($interaction['dialog_history'])) {
            $dialogHistory = json_decode($interaction['dialog_history'], true);
        }
        
        $debugInfo[] = [
            'id' => $interaction['id'],
            'aei_name' => $interaction['aei_name'],
            'contact_name' => $interaction['contact_name'],
            'interaction_type' => $interaction['interaction_type'],
            'initiated_by' => $interaction['initiated_by'] ?? 'unknown',
            'has_dialog_history' => !empty($interaction['dialog_history']),
            'dialog_turns' => is_array($dialogHistory) ? count($dialogHistory) : 0,
            'occurred_at' => $interaction['occurred_at']
        ];
    }
    
    return [
        'success' => true,
        'interactions' => $debugInfo
    ];
}

function testDialogCreation() {
    global $pdo;
    
    $stmt = $pdo->query("
        SELECT a.id as aei_id, a.name as aei_name, c.id as contact_id, c.name as contact_name
        FROM aeis a 
        JOIN aei_social_contacts c ON a.id = c.aei_id 
        WHERE a.is_active = TRUE AND c.is_active = TRUE 
        LIMIT 1
    ");
    $data = $stmt->fetch();
    
    if (!$data) {
        return ['success' => false, 'error' => 'No AEI with contacts found'];
    }
    
    require_once __DIR__ . '/../includes/social_contact_manager.php';
    $socialManager = new SocialContactManager($pdo);
    
    // Test AEI-initiated interaction (should generate thoughts + multi-turn dialog)
    $result = $socialManager->generateAEIToContactInteraction(
        $data['aei_id'], 
        $data['contact_id']
    );
    
    if ($result && isset($result['id'])) {
        // Check if the interaction has dialog_history and aei_thoughts
        $stmt = $pdo->prepare("
            SELECT 
                dialog_history, 
                aei_thoughts, 
                mentioned_in_chat,
                processed_for_emotions
            FROM aei_contact_interactions 
            WHERE id = ?
        ");
        $stmt->execute([$result['id']]);
        $interactionDetails = $stmt->fetch();
        
        $dialogHistory = json_decode($interactionDetails['dialog_history'], true);
        
        return [
            'success' => true,
            'test_data' => $data,
            'interaction_created' => true,
            'interaction_id' => $result['id'],
            'has_dialog_history' => !empty($interactionDetails['dialog_history']),
            'dialog_turns' => is_array($dialogHistory) ? count($dialogHistory) : 0,
            'has_aei_thoughts' => !empty($interactionDetails['aei_thoughts']),
            'mentioned_in_chat' => (bool)$interactionDetails['mentioned_in_chat'],
            'processed_for_emotions' => (bool)$interactionDetails['processed_for_emotions'],
            'aei_thoughts_preview' => $interactionDetails['aei_thoughts'] ? substr($interactionDetails['aei_thoughts'], 0, 100) . '...' : null
        ];
    }
    
    return [
        'success' => false,
        'error' => 'Failed to create interaction',
        'result' => $result
    ];
}
?>

<div class="max-w-6xl mx-auto p-6">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">Social Dialog Migration</h1>
        <p class="text-gray-600 dark:text-gray-300">Check and test the enhanced social dialog system migration.</p>
    </div>

    <!-- Migration Status -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Migration Status</h2>
        </div>
        <div class="p-6">
            <button onclick="checkMigration()" class="mb-4 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                Check Migration Status
            </button>
            <div id="migration-status" class="space-y-4"></div>
        </div>
    </div>

    <!-- Debug Tools -->
    <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-6">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Debug Tools</h2>
        </div>
        <div class="p-6 space-y-4">
            <button onclick="debugInteractions()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors mr-4">
                Debug Recent Interactions
            </button>
            <button onclick="testDialog()" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors">
                Create Test Dialog
            </button>
            <div id="debug-results" class="mt-4"></div>
        </div>
    </div>
</div>

<script>
async function checkMigration() {
    const response = await fetch('?ajax=1&action=check');
    const data = await response.json();
    
    const container = document.getElementById('migration-status');
    
    if (data.success) {
        container.innerHTML = `
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-4 border rounded-lg ${data.migration_complete ? 'border-green-200 bg-green-50 dark:bg-green-900/20' : 'border-yellow-200 bg-yellow-50 dark:bg-yellow-900/20'}">
                    <h3 class="font-semibold ${data.migration_complete ? 'text-green-800 dark:text-green-400' : 'text-yellow-800 dark:text-yellow-400'}">
                        Migration Status: ${data.migration_complete ? '✅ Complete' : '⚠️ Incomplete'}
                    </h3>
                    <p class="text-sm mt-2">
                        ${data.existing_columns.length}/${data.required_columns.length} required columns exist
                    </p>
                    ${data.missing_columns.length > 0 ? `
                        <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                            Missing: ${data.missing_columns.join(', ')}
                        </p>
                    ` : ''}
                </div>
                <div class="p-4 border border-gray-200 rounded-lg">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-200">Interaction Stats</h3>
                    <p class="text-sm mt-2">Total Interactions: ${data.total_interactions}</p>
                    <p class="text-sm">With Dialog History: ${data.with_dialog_history} (${data.percentage_with_dialogs}%)</p>
                </div>
            </div>
        `;
    } else {
        container.innerHTML = `<div class="text-red-600 dark:text-red-400">Error: ${data.error}</div>`;
    }
}

async function debugInteractions() {
    const response = await fetch('?ajax=1&action=debug');
    const data = await response.json();
    
    const container = document.getElementById('debug-results');
    
    if (data.success) {
        const rows = data.interactions.map(interaction => `
            <tr class="border-b border-gray-200 dark:border-gray-700">
                <td class="py-2 px-3 text-sm">${interaction.aei_name}</td>
                <td class="py-2 px-3 text-sm">${interaction.contact_name}</td>
                <td class="py-2 px-3 text-sm">
                    <span class="px-2 py-1 rounded text-xs ${interaction.initiated_by === 'aei' ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/20 dark:text-indigo-400' : 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400'}">
                        ${interaction.initiated_by === 'aei' ? '🤖 AEI' : '👤 Contact'}
                    </span>
                </td>
                <td class="py-2 px-3 text-sm">${interaction.interaction_type}</td>
                <td class="py-2 px-3 text-sm">${interaction.has_dialog_history ? `💬 ${interaction.dialog_turns} turns` : 'No dialog'}</td>
                <td class="py-2 px-3 text-sm">${new Date(interaction.occurred_at).toLocaleString()}</td>
            </tr>
        `).join('');
        
        container.innerHTML = `
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-600 dark:text-gray-400">AEI</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-600 dark:text-gray-400">Contact</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-600 dark:text-gray-400">Initiated By</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-600 dark:text-gray-400">Type</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-600 dark:text-gray-400">Dialog</th>
                            <th class="text-left py-2 px-3 text-sm font-medium text-gray-600 dark:text-gray-400">Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rows}
                    </tbody>
                </table>
            </div>
        `;
    } else {
        container.innerHTML = `<div class="text-red-600 dark:text-red-400">Error: ${data.error}</div>`;
    }
}

async function testDialog() {
    const container = document.getElementById('debug-results');
    container.innerHTML = '<div class="text-blue-600 dark:text-blue-400">Creating test dialog...</div>';
    
    const response = await fetch('?ajax=1&action=test');
    const data = await response.json();
    
    if (data.success) {
        const statusBadges = [
            { key: 'has_dialog_history', label: 'Multi-turn Dialog', icon: '💬' },
            { key: 'has_aei_thoughts', label: 'AEI Thoughts', icon: '🧠' },
            { key: 'mentioned_in_chat', label: 'Mentioned in Chat', icon: '💬' },
            { key: 'processed_for_emotions', label: 'Emotionally Processed', icon: '❤️' }
        ];
        
        const badges = statusBadges.map(badge => {
            const isActive = data[badge.key];
            const colorClass = isActive ? 'bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400';
            const icon = isActive ? '✅' : '❌';
            return `<span class="inline-flex items-center px-2 py-1 rounded-full text-xs ${colorClass}">${icon} ${badge.icon} ${badge.label}</span>`;
        }).join(' ');
        
        container.innerHTML = `
            <div class="p-4 border border-green-200 bg-green-50 dark:bg-green-900/20 rounded-lg">
                <h3 class="font-semibold text-green-800 dark:text-green-400">✅ Test Dialog Created Successfully!</h3>
                <div class="mt-3 space-y-2">
                    <p class="text-sm">
                        <strong>AEI:</strong> "${data.test_data.aei_name}" → <strong>Contact:</strong> "${data.test_data.contact_name}"
                    </p>
                    <p class="text-sm">
                        <strong>Interaction ID:</strong> ${data.interaction_id}
                    </p>
                    <p class="text-sm">
                        <strong>Dialog Turns:</strong> ${data.dialog_turns || 0}
                    </p>
                    ${data.aei_thoughts_preview ? `<p class="text-sm"><strong>AEI Thoughts Preview:</strong> "${data.aei_thoughts_preview}"</p>` : ''}
                    <div class="flex flex-wrap gap-2 mt-3">
                        ${badges}
                    </div>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-3">
                    ✨ Check the admin/social page to see the complete multi-turn dialog with AEI thoughts.
                </p>
            </div>
        `;
    } else {
        container.innerHTML = `<div class="text-red-600 dark:text-red-400">Error: ${data.error}</div>`;
    }
}

// Auto-check migration status on page load
document.addEventListener('DOMContentLoaded', checkMigration);
</script>