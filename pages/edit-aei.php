<?php
requireOnboarding();

$userId = getUserSession();
$aeiId = $_GET['id'] ?? '';

if (empty($aeiId)) {
    redirectTo('dashboard');
}

// Check if AEI exists and belongs to user
try {
    $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ? AND is_active = TRUE");
    $stmt->execute([$aeiId, $userId]);
    $aei = $stmt->fetch();
    
    if (!$aei) {
        redirectTo('dashboard');
    }
    
    // Check if AEI is still within 24-hour edit window
    $createdAt = new DateTime($aei['created_at']);
    $now = new DateTime();
    $hoursSinceCreation = $now->diff($createdAt)->days * 24 + $now->diff($createdAt)->h;
    
    $canEdit = $hoursSinceCreation < 24;
    
} catch (PDOException $e) {
    error_log("Database error fetching AEI for edit: " . $e->getMessage());
    redirectTo('dashboard');
}

// Parse existing data for form population
$personalityData = json_decode($aei['personality'] ?? '[]', true) ?: [];
$appearanceData = json_decode($aei['appearance_description'] ?? '{}', true) ?: [];
$interestsData = json_decode($aei['interests'] ?? '[]', true) ?: [];
$communicationData = json_decode($aei['communication_style'] ?? '{}', true) ?: [];
$relationshipData = json_decode($aei['relationship_context'] ?? '{}', true) ?: [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $age = intval($_POST['age'] ?? 25);
        $gender = sanitizeInput($_POST['gender'] ?? '');
        
        // Process personality traits
        $personalityTraits = $_POST['personality_traits'] ?? [];
        $personalityCustom = sanitizeInput($_POST['personality_custom'] ?? '');
        $customTraits = $personalityCustom ? json_decode($personalityCustom, true) ?: [] : [];
        $personality = json_encode(array_merge($personalityTraits, $customTraits));
        
        // Process communication style
        $communicationStyle = sanitizeInput($_POST['communication_style'] ?? '');
        $speakingTraits = $_POST['speaking_traits'] ?? [];
        $communication = json_encode([
            'style' => $communicationStyle,
            'traits' => $speakingTraits
        ]);
        
        // Process appearance options
        $hairColor = sanitizeInput($_POST['hair_color'] ?? '');
        $eyeColor = sanitizeInput($_POST['eye_color'] ?? '');
        $height = sanitizeInput($_POST['height'] ?? '');
        $build = sanitizeInput($_POST['build'] ?? '');
        $style = sanitizeInput($_POST['style'] ?? '');
        $appearanceCustom = sanitizeInput($_POST['appearance_custom'] ?? '');
        $appearance = json_encode([
            'hair_color' => $hairColor,
            'eye_color' => $eyeColor,
            'height' => $height,
            'build' => $build,
            'style' => $style,
            'custom' => $appearanceCustom
        ]);
        
        $background = sanitizeInput($_POST['background'] ?? '');
        
        // Process interests as tags
        $interestTags = $_POST['interest_tags'] ?? [];
        $interestCustom = sanitizeInput($_POST['interest_custom'] ?? '');
        $customInterests = $interestCustom ? json_decode($interestCustom, true) ?: [] : [];
        $interests = json_encode(array_merge($interestTags, $customInterests));
        
        $quirks = sanitizeInput($_POST['quirks'] ?? '');
        $occupation = sanitizeInput($_POST['occupation'] ?? '');
        $goals = sanitizeInput($_POST['goals'] ?? '');
        $responseLength = (int)($_POST['response_length'] ?? 2);
        
        // Process relationship data
        $relationshipType = sanitizeInput($_POST['relationship_type'] ?? '');
        $relationshipHistory = sanitizeInput($_POST['relationship_history'] ?? '');
        $relationshipDynamics = $_POST['relationship_dynamics'] ?? [];
        $relationship = json_encode([
            'type' => $relationshipType,
            'history' => $relationshipHistory,
            'dynamics' => $relationshipDynamics
        ]);
        
        // Validation for required fields
        $errors = [];
        if (empty($name)) {
            $errors[] = "AEI name is required";
        }
        if (empty($gender)) {
            $errors[] = "Gender is required";
        }
        if (empty($personalityTraits) && empty($customTraits)) {
            $errors[] = "At least 1 personality trait is required";
        }
        if (empty($communicationStyle)) {
            $errors[] = "Communication style is required";
        }
        if (empty($occupation)) {
            $errors[] = "Occupation or role is required";
        }
        if (empty($interestTags) && empty($customInterests)) {
            $errors[] = "At least 1 interest is required";
        }
        if (empty($relationshipType)) {
            $errors[] = "Relationship type is required";
        }
        
        if (!empty($errors)) {
            $error = implode('. ', $errors);
        } else {
            try {
                // Update AEI in database
                $stmt = $pdo->prepare("UPDATE aeis SET name = ?, age = ?, gender = ?, personality = ?, appearance_description = ?, background = ?, interests = ?, communication_style = ?, quirks = ?, occupation = ?, goals = ?, relationship_context = ?, response_length = ? WHERE id = ? AND user_id = ?");
                $stmt->execute([
                    $name, $age, $gender, $personality, $appearance, $background, 
                    $interests, $communication, $quirks, $occupation, $goals, 
                    $relationship, $responseLength, $aeiId, $userId
                ]);
                
                // Refresh AEI data
                $stmt = $pdo->prepare("SELECT * FROM aeis WHERE id = ? AND user_id = ?");
                $stmt->execute([$aeiId, $userId]);
                $aei = $stmt->fetch();
                
                // Re-parse data
                $personalityData = json_decode($aei['personality'] ?? '[]', true) ?: [];
                $appearanceData = json_decode($aei['appearance_description'] ?? '{}', true) ?: [];
                $interestsData = json_decode($aei['interests'] ?? '[]', true) ?: [];
                $communicationData = json_decode($aei['communication_style'] ?? '{}', true) ?: [];
                $relationshipData = json_decode($aei['relationship_context'] ?? '{}', true) ?: [];
                
                $success = "AEI updated successfully!";
                
            } catch (PDOException $e) {
                error_log("Database error updating AEI: " . $e->getMessage());
                $error = "An error occurred while updating your AEI. Please try again.";
            }
        }
    }
}

// Helper functions for form data
function getPersonalityTraits($data) {
    if (is_array($data)) {
        return array_filter($data, function($trait) {
            return is_string($trait) && !empty(trim($trait));
        });
    }
    return [];
}

function getValueOrDefault($data, $key, $default = '') {
    return isset($data[$key]) ? $data[$key] : $default;
}

function isValueSelected($data, $key, $value) {
    if (!isset($data[$key])) return false;
    if (is_array($data[$key])) {
        return in_array($value, $data[$key]);
    }
    return $data[$key] === $value;
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php 
    include_once __DIR__ . '/../includes/header.php';
    renderHeader([
        'show_back_button' => true,
        'back_url' => '/dashboard'
    ]);
    ?>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if (!$canEdit): ?>
            <div class="text-center mb-8">
                <div class="w-24 h-24 bg-gradient-to-br from-gray-400 to-gray-600 rounded-full mx-auto mb-6 flex items-center justify-center">
                    <i class="fas fa-lock text-3xl text-white"></i>
                </div>
                <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">Edit Window Closed</h1>
                <p class="text-gray-600 dark:text-gray-400 text-lg mb-6">
                    AEI companions can only be edited within 24 hours of creation.<br>
                    Your AEI "<?= htmlspecialchars($aei['name']) ?>" was created <?= $hoursSinceCreation ?> hours ago.
                </p>
            </div>

            <!-- View-only AEI details -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-sm">
                <div class="flex items-center mb-6">
                    <?php if (!empty($aei['avatar_url']) && file_exists($_SERVER['DOCUMENT_ROOT'] . $aei['avatar_url'])): ?>
                        <div class="w-16 h-16 rounded-full overflow-hidden mr-6 border-2 border-gradient-to-br from-ayuni-aqua to-ayuni-blue">
                            <img 
                                src="<?= htmlspecialchars($aei['avatar_url']) ?>" 
                                alt="<?= htmlspecialchars($aei['name']) ?>" 
                                class="w-full h-full object-cover"
                            />
                        </div>
                    <?php else: ?>
                        <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-6">
                            <span class="text-2xl text-white font-bold">
                                <?= strtoupper(substr($aei['name'], 0, 1)) ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h2 class="text-3xl font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($aei['name']) ?></h2>
                        <p class="text-gray-600 dark:text-gray-400">Created <?= date('M j, Y \a\t g:i A', strtotime($aei['created_at'])) ?></p>
                    </div>
                </div>

                <!-- View AEI details in read-only format -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Basic Info</h3>
                        <div class="space-y-2 text-sm">
                            <p><span class="font-medium">Age:</span> <?= htmlspecialchars($aei['age']) ?></p>
                            <p><span class="font-medium">Gender:</span> <?= htmlspecialchars($aei['gender']) ?></p>
                            <p><span class="font-medium">Occupation:</span> <?= htmlspecialchars($aei['occupation']) ?></p>
                        </div>
                    </div>
                    
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Personality</h3>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            <?php 
                            $traits = getPersonalityTraits($personalityData);
                            if (!empty($traits)): 
                            ?>
                                <?= htmlspecialchars(implode(', ', $traits)) ?>
                            <?php else: ?>
                                Not specified
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="mt-8 text-center">
                    <a href="/dashboard" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200">
                        Back to Dashboard
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Edit form -->
            <div class="text-center mb-8">
                <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">Edit Your AEI Companion</h1>
                <p class="text-gray-600 dark:text-gray-400 text-lg">
                    You have <?= 24 - $hoursSinceCreation ?> hours remaining to edit "<?= htmlspecialchars($aei['name']) ?>"
                </p>
            </div>

            <?php if (isset($success)): ?>
                <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-400 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?= htmlspecialchars($success) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Edit Form -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-8">
                <form method="POST" class="space-y-10" id="aei-edit-form">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <!-- Basic Information -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-id-card mr-2 text-ayuni-blue"></i>
                            Basic Information
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    AEI Name *
                                </label>
                                <input 
                                    type="text" 
                                    id="name" 
                                    name="name" 
                                    required
                                    maxlength="100"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                    placeholder="Give your AEI a name..."
                                    value="<?= htmlspecialchars($aei['name'] ?? '') ?>"
                                />
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    Gender Identity *
                                </label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <input type="radio" name="gender" value="Female" class="sr-only" required <?= ($aei['gender'] ?? '') === 'Female' ? 'checked' : '' ?>>
                                        <i class="fas fa-venus text-pink-500 mr-2"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Female</span>
                                    </label>
                                    <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <input type="radio" name="gender" value="Male" class="sr-only" required <?= ($aei['gender'] ?? '') === 'Male' ? 'checked' : '' ?>>
                                        <i class="fas fa-mars text-blue-500 mr-2"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Male</span>
                                    </label>
                                    <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <input type="radio" name="gender" value="Non-binary" class="sr-only" required <?= ($aei['gender'] ?? '') === 'Non-binary' ? 'checked' : '' ?>>
                                        <i class="fas fa-genderless text-purple-500 mr-2"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Non-binary</span>
                                    </label>
                                    <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                        <input type="radio" name="gender" value="Other" class="sr-only" required <?= ($aei['gender'] ?? '') === 'Other' ? 'checked' : '' ?>>
                                        <i class="fas fa-question text-gray-500 mr-2"></i>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Other</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-8">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                Age: <span id="age-display" class="font-bold text-ayuni-blue"><?= htmlspecialchars($aei['age'] ?? 25) ?></span> years old
                            </label>
                            <div class="flex items-center space-x-4">
                                <span class="text-sm text-gray-500">18</span>
                                <input 
                                    type="range" 
                                    id="age" 
                                    name="age" 
                                    min="18" 
                                    max="80" 
                                    value="<?= intval($aei['age'] ?? 25) ?>"
                                    class="flex-1 h-3 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer slider"
                                    oninput="updateAgeDisplay(this.value)"
                                />
                                <span class="text-sm text-gray-500">80</span>
                            </div>
                        </div>
                    </div>

                    <!-- Personality Traits -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-heart mr-2 text-ayuni-aqua"></i>
                            Personality Traits
                        </h3>
                        
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">
                                    Core Personality Traits *
                                    <span class="text-xs text-gray-500 ml-2">(Select at least 1 trait that best describes them)</span>
                                </label>
                                <div id="personality-traits" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                    <?php 
                                    $personalityTraits = [
                                        'Cheerful', 'Serious', 'Playful', 'Calm', 'Energetic', 'Thoughtful',
                                        'Confident', 'Shy', 'Optimistic', 'Realistic', 'Creative', 'Logical',
                                        'Spontaneous', 'Organized', 'Empathetic', 'Independent', 'Loyal', 'Ambitious',
                                        'Gentle', 'Bold', 'Curious', 'Patient', 'Witty', 'Mysterious',
                                        'Adventurous', 'Cautious', 'Romantic', 'Practical', 'Artistic', 'Analytical'
                                    ];
                                    $selectedTraits = getPersonalityTraits($personalityData);
                                    foreach ($personalityTraits as $trait): ?>
                                        <label class="trait-button flex items-center justify-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                                            <input type="checkbox" name="personality_traits[]" value="<?= $trait ?>" class="sr-only" <?= in_array($trait, $selectedTraits) ? 'checked' : '' ?>>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= $trait ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4">
                                    <div class="custom-tags-container" data-field="personality_custom">
                                        <div class="tags-display flex flex-wrap gap-2 mb-3" id="personality-tags"></div>
                                        <input 
                                            type="text" 
                                            id="personality-input"
                                            placeholder="Add custom trait and press Enter..."
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                                        />
                                        <input type="hidden" name="personality_custom" id="personality-custom-hidden" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Communication Style -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-comments mr-2 text-green-500"></i>
                            Communication Style
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">How do they communicate? *</h4>
                                <div class="space-y-3">
                                    <?php 
                                    $commStyles = [
                                        ['value' => 'Formal and polite', 'icon' => 'fas fa-user-tie', 'color' => 'blue'],
                                        ['value' => 'Casual and friendly', 'icon' => 'fas fa-smile', 'color' => 'yellow'],
                                        ['value' => 'Playful and teasing', 'icon' => 'fas fa-laugh', 'color' => 'pink'],
                                        ['value' => 'Direct and straightforward', 'icon' => 'fas fa-arrow-right', 'color' => 'red']
                                    ];
                                    $currentStyle = getValueOrDefault($communicationData, 'style', '');
                                    foreach ($commStyles as $style):
                                    ?>
                                        <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                            <input type="radio" name="communication_style" value="<?= $style['value'] ?>" class="sr-only" <?= $currentStyle === $style['value'] ? 'checked' : '' ?> required>
                                            <i class="<?= $style['icon'] ?> text-<?= $style['color'] ?>-500 mr-3"></i>
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><?= $style['value'] ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div>
                                <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Speaking habits</h4>
                                <div class="space-y-3">
                                    <?php 
                                    $speakingTraits = [
                                        'Uses emojis',
                                        'Loves wordplay',
                                        'Asks thoughtful questions',
                                        'Tells stories',
                                        'Uses metaphors'
                                    ];
                                    $selectedSpeaking = getValueOrDefault($communicationData, 'traits', []);
                                    foreach ($speakingTraits as $trait):
                                    ?>
                                        <label class="flex items-center">
                                            <input type="checkbox" name="speaking_traits[]" value="<?= $trait ?>" class="mr-3 rounded border-gray-300 text-ayuni-blue focus:ring-ayuni-blue" <?= is_array($selectedSpeaking) && in_array($trait, $selectedSpeaking) ? 'checked' : '' ?>>
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><?= $trait === 'Uses emojis' ? 'Uses emojis 😊' : $trait ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Appearance -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-palette mr-2 text-purple-500"></i>
                            Appearance
                        </h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Hair Color *</label>
                                <select name="hair_color" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                    <option value="">Select hair color...</option>
                                    <?php 
                                    $hairColors = ['Black', 'Brown', 'Blonde', 'Red', 'Auburn', 'Silver', 'White', 'Colorful'];
                                    $currentHair = getValueOrDefault($appearanceData, 'hair_color', '');
                                    foreach ($hairColors as $color): ?>
                                        <option value="<?= $color ?>" <?= $currentHair === $color ? 'selected' : '' ?>><?= $color === 'Colorful' ? 'Colorful/Dyed' : $color ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Eye Color *</label>
                                <select name="eye_color" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                    <option value="">Select eye color...</option>
                                    <?php 
                                    $eyeColors = ['Brown', 'Blue', 'Green', 'Hazel', 'Gray', 'Amber', 'Violet', 'Heterochromia'];
                                    $currentEye = getValueOrDefault($appearanceData, 'eye_color', '');
                                    foreach ($eyeColors as $color): ?>
                                        <option value="<?= $color ?>" <?= $currentEye === $color ? 'selected' : '' ?>><?= $color === 'Heterochromia' ? 'Two different colors' : $color ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Height *</label>
                                <select name="height" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                    <option value="">Select height...</option>
                                    <?php 
                                    $heights = [['value' => 'Petite', 'label' => 'Petite (under 5\'4")'], ['value' => 'Average', 'label' => 'Average (5\'4" - 5\'7")'], ['value' => 'Tall', 'label' => 'Tall (over 5\'7")']];
                                    $currentHeight = getValueOrDefault($appearanceData, 'height', '');
                                    foreach ($heights as $height): ?>
                                        <option value="<?= $height['value'] ?>" <?= $currentHeight === $height['value'] ? 'selected' : '' ?>><?= $height['label'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Build *</label>
                                <select name="build" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                    <option value="">Select build...</option>
                                    <?php 
                                    $builds = ['Slim', 'Average', 'Athletic', 'Curvy', 'Muscular'];
                                    $currentBuild = getValueOrDefault($appearanceData, 'build', '');
                                    foreach ($builds as $build): ?>
                                        <option value="<?= $build ?>" <?= $currentBuild === $build ? 'selected' : '' ?>><?= $build ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Style *</label>
                                <select name="style" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                    <option value="">Select style...</option>
                                    <?php 
                                    $styles = ['Casual', 'Elegant', 'Sporty', 'Gothic', 'Vintage', 'Modern', 'Bohemian', 'Professional'];
                                    $currentStyle = getValueOrDefault($appearanceData, 'style', '');
                                    foreach ($styles as $style): ?>
                                        <option value="<?= $style ?>" <?= $currentStyle === $style ? 'selected' : '' ?>><?= $style ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-6">
                            <label for="appearance_custom" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                Additional appearance details
                                <span class="text-xs text-gray-500 ml-2">(Optional - distinctive features, accessories, etc.)</span>
                            </label>
                            <textarea 
                                id="appearance_custom" 
                                name="appearance_custom" 
                                rows="2"
                                maxlength="200"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                placeholder="e.g., Wears glasses, has a scar on forehead, always carries a book..."
                            ><?= htmlspecialchars(getValueOrDefault($appearanceData, 'custom', '')) ?></textarea>
                        </div>
                    </div>

                    <!-- Background & Interests -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-book mr-2 text-indigo-500"></i>
                            Background & Interests
                        </h3>
                        
                        <div class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="occupation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Occupation or Role *
                                    </label>
                                    <input 
                                        type="text" 
                                        id="occupation" 
                                        name="occupation" 
                                        required
                                        maxlength="100"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                                        placeholder="Student, Artist, Scientist, Chef..."
                                        value="<?= htmlspecialchars($aei['occupation'] ?? '') ?>"
                                    />
                                </div>
                                
                                <div>
                                    <label for="goals" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                        Goals & Dreams
                                    </label>
                                    <input 
                                        type="text" 
                                        id="goals" 
                                        name="goals" 
                                        maxlength="100"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                                        placeholder="Travel the world, write a novel..."
                                        value="<?= htmlspecialchars($aei['goals'] ?? '') ?>"
                                    />
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">
                                    Interests & Hobbies *
                                    <span class="text-xs text-gray-500 ml-2">(Select at least 1 thing they're passionate about)</span>
                                </label>
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                    <?php 
                                    $interestOptions = [
                                        'Art', 'Music', 'Books', 'Movies', 'Gaming', 'Sports', 'Cooking', 'Travel',
                                        'Photography', 'Dancing', 'Singing', 'Writing', 'Science', 'Technology',
                                        'Fashion', 'Fitness', 'Yoga', 'Meditation', 'Nature', 'Animals',
                                        'History', 'Philosophy', 'Psychology', 'Astronomy', 'Languages', 'Theater'
                                    ];
                                    $selectedInterests = is_array($interestsData) ? $interestsData : [];
                                    foreach ($interestOptions as $interest): ?>
                                        <label class="interest-button flex items-center justify-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                                            <input type="checkbox" name="interest_tags[]" value="<?= $interest ?>" class="sr-only" <?= in_array($interest, $selectedInterests) ? 'checked' : '' ?>>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= $interest ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="mt-4">
                                    <div class="custom-tags-container" data-field="interest_custom">
                                        <div class="tags-display flex flex-wrap gap-2 mb-3" id="interest-tags"></div>
                                        <input 
                                            type="text" 
                                            id="interest-input"
                                            placeholder="Add custom interest and press Enter..."
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                                        />
                                        <input type="hidden" name="interest_custom" id="interest-custom-hidden" />
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <label for="background" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    Personal Background
                                    <span class="text-xs text-gray-500 ml-2">(Optional - their story, upbringing, key experiences)</span>
                                </label>
                                <textarea 
                                    id="background" 
                                    name="background" 
                                    rows="3"
                                    maxlength="500"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                    placeholder="A brief story about their background, where they came from, key life experiences..."
                                ><?= htmlspecialchars($aei['background'] ?? '') ?></textarea>
                            </div>
                            
                            <div>
                                <label for="quirks" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    Unique Quirks & Habits
                                    <span class="text-xs text-gray-500 ml-2">(Optional - what makes them memorable?)</span>
                                </label>
                                <textarea 
                                    id="quirks" 
                                    name="quirks" 
                                    rows="2"
                                    maxlength="200"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                    placeholder="e.g., Always hums when thinking, collects vintage postcards, speaks in multiple languages..."
                                ><?= htmlspecialchars($aei['quirks'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Relationship Context -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-heart mr-2 text-red-500"></i>
                            Your Relationship
                        </h3>
                        
                        <div class="space-y-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">
                                    What is your AEI for you? *
                                </label>
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" id="relationship-options">
                                    <?php 
                                    $relationshipTypes = [
                                        ['value' => 'Best Friend', 'icon' => 'fas fa-user-friends', 'color' => 'blue-500', 'desc' => 'Close friendship and trust'],
                                        ['value' => 'Close Friend', 'icon' => 'fas fa-users', 'color' => 'blue-400', 'desc' => 'Trusted friendship'],
                                        ['value' => 'Sister', 'icon' => 'fas fa-female', 'color' => 'pink-500', 'desc' => 'She is your sister', 'gender' => 'Female'],
                                        ['value' => 'Brother', 'icon' => 'fas fa-male', 'color' => 'blue-600', 'desc' => 'He is your brother', 'gender' => 'Male'],
                                        ['value' => 'Girlfriend', 'icon' => 'fas fa-heart', 'color' => 'red-500', 'desc' => 'She is your girlfriend', 'gender' => 'Female'],
                                        ['value' => 'Boyfriend', 'icon' => 'fas fa-heart', 'color' => 'red-600', 'desc' => 'He is your boyfriend', 'gender' => 'Male'],
                                        ['value' => 'Wife', 'icon' => 'fas fa-ring', 'color' => 'gold-500', 'desc' => 'She is your wife', 'gender' => 'Female'],
                                        ['value' => 'Husband', 'icon' => 'fas fa-ring', 'color' => 'gold-600', 'desc' => 'He is your husband', 'gender' => 'Male'],
                                        ['value' => 'Mentor', 'icon' => 'fas fa-graduation-cap', 'color' => 'purple-500', 'desc' => 'Teaching relationship'],
                                        ['value' => 'Colleague', 'icon' => 'fas fa-briefcase', 'color' => 'gray-500', 'desc' => 'Professional relationship'],
                                        ['value' => 'Companion', 'icon' => 'fas fa-smile', 'color' => 'yellow-500', 'desc' => 'General support']
                                    ];
                                    $currentRelType = getValueOrDefault($relationshipData, 'type', '');
                                    foreach ($relationshipTypes as $relType): 
                                        if (isset($relType['gender']) && $relType['gender'] !== $aei['gender']) continue;
                                    ?>
                                        <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                            <input type="radio" name="relationship_type" value="<?= $relType['value'] ?>" class="sr-only" <?= $currentRelType === $relType['value'] ? 'checked' : '' ?> required>
                                            <i class="<?= $relType['icon'] ?> text-<?= $relType['color'] ?> mr-3"></i>
                                            <div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= $relType['value'] ?></span>
                                                <p class="text-xs text-gray-500 dark:text-gray-400"><?= $relType['desc'] ?></p>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">
                                    Relationship dynamics
                                    <span class="text-xs text-gray-500 ml-2">(Select what characterizes your relationship)</span>
                                </label>
                                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                                    <?php 
                                    $relationshipDynamics = [
                                        'Supportive', 'Playful', 'Deep conversations', 'Shared interests', 
                                        'Emotional support', 'Adventure together', 'Intellectual discussions', 'Fun and laughter',
                                        'Honest communication', 'Protective', 'Inspiring', 'Relaxing presence'
                                    ];
                                    $selectedDynamics = getValueOrDefault($relationshipData, 'dynamics', []);
                                    foreach ($relationshipDynamics as $dynamic): ?>
                                        <label class="dynamics-button flex items-center justify-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                                            <input type="checkbox" name="relationship_dynamics[]" value="<?= $dynamic ?>" class="sr-only" <?= is_array($selectedDynamics) && in_array($dynamic, $selectedDynamics) ? 'checked' : '' ?>>
                                            <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= $dynamic ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div>
                                <label for="relationship_history" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    How did you meet? 
                                    <span class="text-xs text-gray-500 ml-2">(Optional - your shared history)</span>
                                </label>
                                <textarea 
                                    id="relationship_history" 
                                    name="relationship_history" 
                                    rows="3"
                                    maxlength="300"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                    placeholder="Tell the story of how you met, key moments in your relationship, shared experiences..."
                                ><?= htmlspecialchars(getValueOrDefault($relationshipData, 'history', '')) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Response Length Preference -->
                    <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 flex items-center">
                            <i class="fas fa-comment mr-2 text-purple-500"></i>
                            Response Style
                        </h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">
                                    How verbose should your AEI be?
                                </label>
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                    <?php 
                                    $responseLengths = [
                                        ['value' => '1', 'icon' => 'fas fa-compress', 'color' => 'blue', 'label' => 'Short & Sweet', 'desc' => '2-3 sentences, concise'],
                                        ['value' => '2', 'icon' => 'fas fa-balance-scale', 'color' => 'green', 'label' => 'Balanced', 'desc' => '4-5 sentences, just right'],
                                        ['value' => '3', 'icon' => 'fas fa-expand', 'color' => 'purple', 'label' => 'Detailed', 'desc' => 'Comprehensive, elaborate']
                                    ];
                                    $currentLength = $aei['response_length'] ?? 2;
                                    foreach ($responseLengths as $length): ?>
                                        <label class="response-length flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                            <input type="radio" name="response_length" value="<?= $length['value'] ?>" class="sr-only" <?= $currentLength == $length['value'] ? 'checked' : '' ?>>
                                            <i class="<?= $length['icon'] ?> text-<?= $length['color'] ?>-500 mr-3"></i>
                                            <div>
                                                <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= $length['label'] ?></span>
                                                <p class="text-xs text-gray-500 dark:text-gray-400"><?= $length['desc'] ?></p>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                <div class="flex items-start space-x-3">
                                    <i class="fas fa-lightbulb text-blue-500 mt-0.5"></i>
                                    <div>
                                        <p class="text-sm text-blue-800 dark:text-blue-200 font-medium mb-1">💡 Pro Tip</p>
                                        <p class="text-xs text-blue-700 dark:text-blue-300">
                                            You can change this setting anytime in the chat profile. Your AEI will adapt their response length to match your preference, regardless of how much you write.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="flex space-x-4 pt-6">
                        <a href="/dashboard" class="flex-1 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-4 px-6 rounded-lg text-center hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                            Cancel
                        </a>
                        <button type="submit" class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md">
                            <i class="fas fa-save mr-2"></i>
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* Slider styling */
.slider {
    background: linear-gradient(to right, #39D2DF 0%, #546BEC 100%);
}

.slider::-webkit-slider-thumb {
    appearance: none;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #39D2DF;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.slider::-moz-range-thumb {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #fff;
    border: 2px solid #39D2DF;
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Interactive button styling */
.trait-button input:checked + span,
.interest-button input:checked + span,
.dynamics-button input:checked + span {
    color: #39D2DF;
    font-weight: 600;
}

.trait-button:has(input:checked),
.interest-button:has(input:checked), 
.dynamics-button:has(input:checked) {
    background-color: rgba(57, 210, 223, 0.1);
    border-color: #39D2DF;
}

.gender-option:has(input:checked),
.comm-style:has(input:checked),
.relationship-type:has(input:checked),
.response-length:has(input:checked) {
    background-color: rgba(57, 210, 223, 0.1);
    border-color: #39D2DF;
}

/* Tag styling */
.tag {
    background: linear-gradient(135deg, #39D2DF, #546BEC);
    color: white;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.tag .remove-tag {
    cursor: pointer;
    font-weight: bold;
}
</style>

<script>
// Age display update
function updateAgeDisplay(value) {
    document.getElementById('age-display').textContent = value;
}

// Initialize age display on page load
document.addEventListener('DOMContentLoaded', function() {
    const ageInput = document.getElementById('age');
    if (ageInput) {
        updateAgeDisplay(ageInput.value);
    }
});

// Custom tags system
document.addEventListener('DOMContentLoaded', function() {
    const containers = document.querySelectorAll('.custom-tags-container');
    
    containers.forEach(container => {
        const field = container.dataset.field;
        const tagsDisplay = container.querySelector('.tags-display');
        const input = container.querySelector('input[type="text"]');
        const hiddenInput = container.querySelector('input[type="hidden"]');
        
        let tags = [];
        
        function updateTags() {
            tagsDisplay.innerHTML = tags.map(tag => 
                `<span class="tag">${tag}<span class="remove-tag" onclick="removeTag('${field}', '${tag}')">&times;</span></span>`
            ).join('');
            hiddenInput.value = JSON.stringify(tags);
        }
        
        input.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const value = this.value.trim();
                if (value && !tags.includes(value)) {
                    tags.push(value);
                    updateTags();
                    this.value = '';
                }
            }
        });
        
        // Make removeTag globally available
        window.removeTag = function(field, tag) {
            const container = document.querySelector(`[data-field="${field}"]`);
            const tagsArray = JSON.parse(container.querySelector('input[type="hidden"]').value || '[]');
            const index = tagsArray.indexOf(tag);
            if (index > -1) {
                tagsArray.splice(index, 1);
                container.querySelector('input[type="hidden"]').value = JSON.stringify(tagsArray);
                container.querySelector('.tags-display').innerHTML = tagsArray.map(t => 
                    `<span class="tag">${t}<span class="remove-tag" onclick="removeTag('${field}', '${t}')">&times;</span></span>`
                ).join('');
            }
        };
    });
});
</script>