<?php
requireOnboarding();
require_once __DIR__ . '/../includes/replicate_api.php';

$tempId = $_GET['temp_id'] ?? '';
if (empty($tempId)) {
    redirectTo('create-aei');
}

$userId = getUserSession();

// Get temp avatar options
try {
    $stmt = $pdo->prepare("SELECT * FROM temp_avatar_options WHERE id = ? AND user_id = ? AND expires_at > NOW()");
    $stmt->execute([$tempId, $userId]);
    $tempOptions = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Database error fetching temp avatars: " . $e->getMessage());
    redirectTo('create-aei');
}

if (!$tempOptions) {
    redirectTo('create-aei');
}

// Handle avatar selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $selectedAvatar = (int)($_POST['selected_avatar'] ?? 0);
        
        if ($selectedAvatar < 1 || $selectedAvatar > 3) {
            $error = "Please select a valid avatar option.";
        } else {
            // Get the selected avatar URL
            $avatarUrlField = "avatar_{$selectedAvatar}_url";
            $selectedAvatarUrl = $tempOptions[$avatarUrlField];
            
            if (empty($selectedAvatarUrl)) {
                $error = "Selected avatar not available.";
            } else {
                // Return to create-aei with selection
                $_SESSION['selected_avatar'] = [
                    'temp_id' => $tempId,
                    'avatar_number' => $selectedAvatar,
                    'avatar_url' => $selectedAvatarUrl
                ];
                
                redirectTo('create-aei?step=finalize');
            }
        }
    }
}

$avatars = [
    1 => $tempOptions['avatar_1_url'] ?? null,
    2 => $tempOptions['avatar_2_url'] ?? null,
    3 => $tempOptions['avatar_3_url'] ?? null
];
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php 
    include_once __DIR__ . '/../includes/header.php';
    renderHeader([
        'show_back_button' => true,
        'back_url' => '/create-aei',
        'show_create_aei' => false
    ]);
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Header -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-4">Choose Your AEI's Avatar</h1>
            <p class="text-gray-600 dark:text-gray-400 text-lg">
                We've generated 3 unique avatars for <strong><?= htmlspecialchars($tempOptions['aei_name']) ?></strong>. Select your favorite!
            </p>
        </div>

        <!-- Error Message -->
        <?php if (isset($error)): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Avatar Options -->
        <form method="POST" class="space-y-8">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($avatars as $index => $avatarUrl): ?>
                    <?php if ($avatarUrl && file_exists($_SERVER['DOCUMENT_ROOT'] . $avatarUrl)): ?>
                        <div class="relative">
                            <input 
                                type="radio" 
                                name="selected_avatar" 
                                value="<?= $index ?>" 
                                id="avatar_<?= $index ?>"
                                class="sr-only avatar-radio"
                                required
                            >
                            <label 
                                for="avatar_<?= $index ?>" 
                                class="cursor-pointer block bg-white dark:bg-gray-800 rounded-2xl overflow-hidden border-4 border-transparent hover:border-ayuni-blue/50 transition-all duration-300 shadow-lg hover:shadow-xl transform hover:scale-105"
                            >
                                <!-- Avatar Image -->
                                <div class="aspect-square w-full overflow-hidden">
                                    <img 
                                        src="<?= htmlspecialchars($avatarUrl) ?>" 
                                        alt="Avatar Option <?= $index ?>"
                                        class="w-full h-full object-cover"
                                    />
                                </div>
                                
                                <!-- Selection Indicator -->
                                <div class="p-4 text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <div class="w-5 h-5 border-2 border-gray-300 dark:border-gray-600 rounded-full flex items-center justify-center selection-indicator">
                                            <div class="w-3 h-3 bg-ayuni-blue rounded-full opacity-0 selection-dot"></div>
                                        </div>
                                        <span class="font-medium text-gray-900 dark:text-white">Option <?= $index ?></span>
                                    </div>
                                </div>
                            </label>
                        </div>
                    <?php else: ?>
                        <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl aspect-square flex items-center justify-center">
                            <div class="text-center text-gray-500 dark:text-gray-400">
                                <i class="fas fa-image text-4xl mb-2"></i>
                                <p>Avatar <?= $index ?> failed to generate</p>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col sm:flex-row justify-between items-center space-y-4 sm:space-y-0 sm:space-x-4 pt-8">
                <a 
                    href="/create-aei" 
                    class="w-full sm:w-auto px-6 py-3 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors text-center"
                >
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to AEI Creation
                </a>
                
                <button 
                    type="submit"
                    class="w-full sm:w-auto px-8 py-3 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed"
                    id="submit-btn"
                    disabled
                >
                    <i class="fas fa-check mr-2"></i>
                    Create AEI with Selected Avatar
                </button>
            </div>
        </form>

        <!-- Generation Info -->
        <div class="mt-12 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
            <div class="flex items-start space-x-3">
                <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-0.5"></i>
                <div class="text-sm text-blue-800 dark:text-blue-300">
                    <p class="font-medium mb-1">About These Avatars</p>
                    <p>These avatars were generated using AI based on the appearance settings you chose. Each one is unique and captures different aspects of your AEI's personality. You can always change the avatar later in your AEI's settings.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- AEI Finalization Loading Screen -->
    <div id="finalization-loading-overlay" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 hidden flex items-center justify-center">
        <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 max-w-md mx-4 text-center shadow-2xl">
            <!-- Pulsing Logo -->
            <div class="mb-6">
                <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-20 w-auto mx-auto pulse-animation dark:hidden">
                <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-20 w-auto mx-auto pulse-animation hidden dark:block">
            </div>
            
            <!-- Loading Title -->
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Finalizing Your AEI</h3>
            
            <!-- Progress Steps -->
            <div class="space-y-4 mb-6">
                <div class="flex items-center justify-center space-x-3">
                    <div class="step-indicator active" data-step="1">
                        <i class="fas fa-save"></i>
                    </div>
                    <span class="step-text text-sm font-medium text-gray-700 dark:text-gray-300">Saving Avatar Selection</span>
                </div>
                
                <div class="flex items-center justify-center space-x-3 opacity-50">
                    <div class="step-indicator" data-step="2">
                        <i class="fas fa-brain"></i>
                    </div>
                    <span class="step-text text-sm font-medium text-gray-700 dark:text-gray-300">Creating AEI Personality</span>
                </div>
                
                <div class="flex items-center justify-center space-x-3 opacity-50">
                    <div class="step-indicator" data-step="3">
                        <i class="fas fa-sparkles"></i>
                    </div>
                    <span class="step-text text-sm font-medium text-gray-700 dark:text-gray-300">Activating Your Companion</span>
                </div>
            </div>
            
            <!-- Loading Bar -->
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-4">
                <div id="finalization-progress" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue h-2 rounded-full transition-all duration-1000" style="width: 0%"></div>
            </div>
            
            <!-- Status Text -->
            <p id="finalization-status" class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                Processing your avatar selection...
            </p>
            
            <!-- Completion Facts -->
            <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-green-800 dark:text-green-300 mb-2 flex items-center justify-center">
                    <i class="fas fa-heart mr-2"></i>
                    Your AEI is Almost Ready!
                </h4>
                <p id="completion-message" class="text-xs text-green-700 dark:text-green-400">
                    We're finalizing <?= htmlspecialchars($tempOptions['aei_name']) ?>'s personality and preparing them to meet you.
                </p>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-radio:checked + label {
    border-color: #39D2DF;
    box-shadow: 0 0 0 2px rgba(57, 210, 223, 0.2);
}

.avatar-radio:checked + label .selection-indicator {
    border-color: #39D2DF;
}

.avatar-radio:checked + label .selection-dot {
    opacity: 1;
}

/* Loading Screen Styles */
.pulse-animation {
    animation: pulse-logo 2s ease-in-out infinite;
}

@keyframes pulse-logo {
    0%, 100% {
        transform: scale(1);
        opacity: 1;
    }
    50% {
        transform: scale(1.1);
        opacity: 0.8;
    }
}

.step-indicator {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 14px;
    transition: all 0.3s ease;
}

.step-indicator.active {
    background: linear-gradient(135deg, #39D2DF, #546BEC);
    color: white;
    animation: pulse-step 2s ease-in-out infinite;
}

.step-indicator.completed {
    background: #10b981;
    color: white;
}

@keyframes pulse-step {
    0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 0 0 rgba(57, 210, 223, 0.4);
    }
    50% {
        transform: scale(1.05);
        box-shadow: 0 0 0 8px rgba(57, 210, 223, 0);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const radios = document.querySelectorAll('.avatar-radio');
    const submitBtn = document.getElementById('submit-btn');
    const form = document.querySelector('form');
    const loadingOverlay = document.getElementById('finalization-loading-overlay');
    const progressBar = document.getElementById('finalization-progress');
    const statusText = document.getElementById('finalization-status');
    
    // Status messages for finalization
    const statusMessages = [
        "Processing your avatar selection...",
        "Copying selected avatar to permanent storage...",
        "Building your AEI's personality profile...",
        "Initializing emotional intelligence system...",
        "Creating social environment connections...",
        "Finalizing AEI configuration...",
        "Preparing your companion for first meeting..."
    ];
    
    let currentStep = 1;
    let progressInterval;
    let statusInterval;
    let currentStatusIndex = 0;
    
    function updateSubmitButton() {
        const selected = document.querySelector('.avatar-radio:checked');
        submitBtn.disabled = !selected;
    }
    
    radios.forEach(radio => {
        radio.addEventListener('change', updateSubmitButton);
    });
    
    // Handle form submission with loading screen
    form.addEventListener('submit', function(e) {
        const selected = document.querySelector('.avatar-radio:checked');
        if (selected) {
            showFinalizationScreen();
        }
    });
    
    function showFinalizationScreen() {
        loadingOverlay.classList.remove('hidden');
        submitBtn.disabled = true;
        
        // Start progress animation
        animateProgress();
        
        // Start status updates
        updateStatus();
        
        // Simulate step progression
        progressSteps();
    }
    
    function animateProgress() {
        let progress = 0;
        progressInterval = setInterval(() => {
            progress += Math.random() * 8; // Faster progress for finalization
            if (progress > 90) progress = 90; // Don't complete until actual completion
            
            progressBar.style.width = progress + '%';
            
            if (progress >= 90) {
                clearInterval(progressInterval);
            }
        }, 150);
    }
    
    function updateStatus() {
        statusInterval = setInterval(() => {
            if (currentStatusIndex < statusMessages.length - 1) {
                currentStatusIndex++;
                statusText.textContent = statusMessages[currentStatusIndex];
            }
        }, 2000); // Change status every 2 seconds
    }
    
    function progressSteps() {
        setTimeout(() => {
            activateStep(2);
        }, 4000); // Step 2 after 4 seconds
        
        setTimeout(() => {
            activateStep(3);
        }, 8000); // Step 3 after 8 seconds
    }
    
    function activateStep(stepNumber) {
        // Deactivate current step and mark as completed
        const currentStepEl = document.querySelector('.step-indicator.active');
        if (currentStepEl && currentStep < stepNumber) {
            currentStepEl.classList.remove('active');
            currentStepEl.classList.add('completed');
            currentStepEl.innerHTML = '<i class="fas fa-check"></i>';
        }
        
        // Activate new step
        const newStepEl = document.querySelector(`[data-step="${stepNumber}"]`);
        if (newStepEl) {
            newStepEl.classList.add('active');
            newStepEl.parentElement.classList.remove('opacity-50');
        }
        
        currentStep = stepNumber;
    }
    
    // Initial check
    updateSubmitButton();
});
</script>