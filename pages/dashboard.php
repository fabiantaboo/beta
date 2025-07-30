<?php
requireValidSession();

$userId = getUserSession();
if (!$userId) {
    redirectTo('home');
}

try {
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE user_id = ? AND is_active = TRUE ORDER BY created_at DESC");
    $stmt->execute([$userId]);
    $aeis = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Database error fetching AEIs: " . $e->getMessage());
    $aeis = [];
}
?>

<div class="min-h-screen bg-gradient-to-br from-ayuni-dark via-gray-900 to-ayuni-dark">
    <nav class="bg-ayuni-dark/80 backdrop-blur-sm border-b border-gray-700 sticky top-0 z-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <h1 class="text-2xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent">
                    Ayuni Beta
                </h1>
                <a href="?page=create-aei" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-ayuni-dark font-semibold py-2 px-4 rounded-lg hover:scale-105 transform transition-all duration-200">
                    + Create AEI
                </a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if (empty($aeis)): ?>
            <div class="text-center py-16">
                <div class="mb-8">
                    <div class="w-24 h-24 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-6 flex items-center justify-center">
                        <span class="text-3xl text-ayuni-dark">🤖</span>
                    </div>
                    <h2 class="text-3xl font-bold text-ayuni-white mb-4">Create Your First AEI</h2>
                    <p class="text-gray-400 text-lg max-w-md mx-auto">
                        Start your journey by creating your first Advanced Electronic Intelligence companion
                    </p>
                </div>
                <a href="?page=create-aei" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-ayuni-dark font-bold py-3 px-6 rounded-xl text-lg hover:scale-105 transform transition-all duration-200 shadow-lg hover:shadow-xl">
                    Create Your First AEI
                </a>
            </div>
        <?php else: ?>
            <div class="mb-8">
                <h2 class="text-3xl font-bold text-ayuni-white mb-2">Your AEIs</h2>
                <p class="text-gray-400">Your digital companions are ready to chat</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($aeis as $aei): ?>
                    <div class="bg-gray-800/50 backdrop-blur-sm rounded-2xl p-6 border border-gray-700 hover:border-ayuni-aqua/50 transition-all duration-300 hover:scale-105">
                        <div class="flex items-center mb-4">
                            <div class="w-12 h-12 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-4">
                                <span class="text-xl text-ayuni-dark font-bold">
                                    <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                                </span>
                            </div>
                            <div>
                                <h3 class="text-xl font-semibold text-ayuni-white"><?= htmlspecialchars($aei['name']) ?></h3>
                                <p class="text-sm text-gray-400">Created <?= date('M j, Y', strtotime($aei['created_at'])) ?></p>
                            </div>
                        </div>
                        
                        <?php if ($aei['personality']): ?>
                            <p class="text-gray-300 mb-4 line-clamp-3"><?= htmlspecialchars(substr($aei['personality'], 0, 120)) ?>...</p>
                        <?php endif; ?>
                        
                        <a href="?page=chat&aei=<?= urlencode($aei['id']) ?>" class="block w-full bg-gradient-to-r from-ayuni-aqua/20 to-ayuni-blue/20 border border-ayuni-aqua/30 text-ayuni-aqua font-semibold py-2 px-4 rounded-lg text-center hover:bg-gradient-to-r hover:from-ayuni-aqua/30 hover:to-ayuni-blue/30 transition-all duration-200">
                            Start Conversation
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>