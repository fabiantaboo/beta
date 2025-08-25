<?php
requireOnboarding();
require_once __DIR__ . '/../includes/background_social_processor.php';
require_once __DIR__ . '/../includes/replicate_api.php';

$step = $_GET['step'] ?? 'create';
$userId = getUserSession();

// Handle finalization with selected avatar
if ($step === 'finalize' && isset($_SESSION['selected_avatar'])) {
    $selectedAvatar = $_SESSION['selected_avatar'];
    
    // Get temp data to complete AEI creation
    try {
        $stmt = $pdo->prepare("SELECT * FROM temp_avatar_options WHERE id = ? AND user_id = ?");
        $stmt->execute([$selectedAvatar['temp_id'], $userId]);
        $tempData = $stmt->fetch();
        
        if ($tempData && isset($_SESSION['aei_creation_data'])) {
            $aeiData = $_SESSION['aei_creation_data'];
            
            // Complete AEI creation with selected avatar
            $aeiId = generateId();
            
            // Copy selected avatar to final location
            $sourceUrl = $selectedAvatar['avatar_url'];
            $targetFilename = $aeiId . '.png';
            $targetUrl = '/assets/avatars/' . $targetFilename;
            $sourcePath = $_SERVER['DOCUMENT_ROOT'] . $sourceUrl;
            $targetPath = $_SERVER['DOCUMENT_ROOT'] . $targetUrl;
            
            if (file_exists($sourcePath) && copy($sourcePath, $targetPath)) {
                $finalAvatarUrl = $targetUrl;
            } else {
                $finalAvatarUrl = null;
            }
            
            // Create AEI in database
            $stmt = $pdo->prepare("INSERT INTO aeis (id, user_id, name, age, gender, personality, appearance_description, background, interests, communication_style, quirks, occupation, goals, relationship_context, avatar_url, response_length) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $aeiId, $userId, $aeiData['name'], $aeiData['age'], $aeiData['gender'],
                $aeiData['personality'], $aeiData['appearance'], $aeiData['background'],
                $aeiData['interests'], $aeiData['communication'], $aeiData['quirks'],
                $aeiData['occupation'], $aeiData['goals'], $aeiData['relationship'],
                $finalAvatarUrl, $aeiData['response_length'] ?? 2
            ]);
            
            // Initialize social environment
            try {
                $socialProcessor = new BackgroundSocialProcessor($pdo);
                $socialProcessor->initializeAEISocialEnvironment($aeiId);
            } catch (Exception $e) {
                error_log("Failed to initialize social environment for AEI $aeiId: " . $e->getMessage());
            }
            
            // Clean up temp data
            unset($_SESSION['selected_avatar']);
            unset($_SESSION['aei_creation_data']);
            
            // Delete temp avatars
            $stmt = $pdo->prepare("DELETE FROM temp_avatar_options WHERE id = ?");
            $stmt->execute([$selectedAvatar['temp_id']]);
            
            redirectTo('dashboard');
        }
    } catch (Exception $e) {
        error_log("Error finalizing AEI creation: " . $e->getMessage());
        unset($_SESSION['selected_avatar']);
        unset($_SESSION['aei_creation_data']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $name = sanitizeInput($_POST['name'] ?? '');
        $age = intval($_POST['age'] ?? 25);
        $gender = sanitizeInput($_POST['gender'] ?? '');
        
        // Process personality traits (selected traits as JSON)
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
            $errors[] = "At least 3 personality traits are required";
        } elseif (count(array_merge($personalityTraits, $customTraits)) < 3) {
            $errors[] = "Please select at least 3 personality traits";
        }
        if (empty($communicationStyle)) {
            $errors[] = "Communication style is required";
        }
        if (empty($occupation)) {
            $errors[] = "Occupation or role is required";
        }
        if (empty($interestTags) && empty($customInterests)) {
            $errors[] = "At least 3 interests are required";
        } elseif (count(array_merge($interestTags, $customInterests)) < 3) {
            $errors[] = "Please select at least 3 interests";
        }
        if (empty($relationshipType)) {
            $errors[] = "Relationship type is required";
        }
        if (empty($hairColor)) {
            $errors[] = "Hair color is required for avatar generation";
        }
        if (empty($eyeColor)) {
            $errors[] = "Eye color is required for avatar generation";
        }
        if (empty($height)) {
            $errors[] = "Height is required for avatar generation";
        }
        if (empty($build)) {
            $errors[] = "Build is required for avatar generation";
        }
        if (empty($style)) {
            $errors[] = "Style is required for avatar generation";
        }
        
        if (!empty($errors)) {
            $error = implode('. ', $errors);
        } else {
            try {
                // Store AEI data in session for avatar selection
                $_SESSION['aei_creation_data'] = [
                    'name' => $name,
                    'age' => $age,
                    'gender' => $gender,
                    'personality' => $personality,
                    'appearance' => $appearance,
                    'background' => $background,
                    'interests' => $interests,
                    'communication' => $communication,
                    'quirks' => $quirks,
                    'occupation' => $occupation,
                    'goals' => $goals,
                    'relationship' => $relationship,
                    'response_length' => $responseLength
                ];
                
                // Generate 3 avatar options if Replicate API is configured
                try {
                    $replicateAPI = new ReplicateAPI();
                    
                    // Build prompt from appearance
                    $prompt = $replicateAPI->buildPromptFromAppearance($appearance, $name, $gender);
                    error_log("DEBUG: Avatar generation prompt: " . $prompt);
                    error_log("DEBUG: Starting 3-avatar generation for AEI: $name");
                    
                    // Generate 3 avatars
                    $imageUrls = $replicateAPI->generateMultipleAvatars($prompt, 3);
                    error_log("DEBUG: Generated image URLs: " . json_encode($imageUrls));
                    
                    if (!empty($imageUrls)) {
                        // Create temp record
                        $tempId = generateId();
                        $avatarDir = $_SERVER['DOCUMENT_ROOT'] . '/assets/avatars/temp/';
                        error_log("DEBUG: Temp ID: $tempId, Avatar Dir: $avatarDir");
                        
                        // Ensure temp directory exists
                        if (!file_exists($avatarDir)) {
                            mkdir($avatarDir, 0755, true);
                            error_log("DEBUG: Created temp directory: $avatarDir");
                        }
                        
                        // Download and save all avatars
                        $savedAvatars = $replicateAPI->downloadAndSaveAvatars($imageUrls, $avatarDir, $tempId);
                        error_log("DEBUG: Saved avatars: " . json_encode($savedAvatars));
                        
                        // Store in database
                        $stmt = $pdo->prepare("INSERT INTO temp_avatar_options (id, user_id, aei_name, prompt_used, avatar_1_url, avatar_2_url, avatar_3_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $tempId,
                            $userId,
                            $name,
                            $prompt,
                            $savedAvatars[0]['url'] ?? null,
                            $savedAvatars[1]['url'] ?? null,
                            $savedAvatars[2]['url'] ?? null
                        ]);
                        error_log("DEBUG: Stored temp avatar options in database");
                        
                        // Redirect to avatar selection
                        error_log("DEBUG: Redirecting to choose-avatar with temp_id: $tempId");
                        redirectTo('choose-avatar?temp_id=' . $tempId);
                    } else {
                        error_log("DEBUG: No image URLs returned from generation");
                        throw new Exception("No avatars generated");
                    }
                    
                } catch (Exception $e) {
                    error_log("ERROR: Avatar generation failed for AEI $name: " . $e->getMessage());
                    error_log("ERROR: Exception trace: " . $e->getTraceAsString());
                    
                    // Create AEI without avatar as fallback
                    $aeiId = generateId();
                    $stmt = $pdo->prepare("INSERT INTO aeis (id, user_id, name, age, gender, personality, appearance_description, background, interests, communication_style, quirks, occupation, goals, relationship_context, avatar_url, response_length) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$aeiId, $userId, $name, $age, $gender, $personality, $appearance, $background, $interests, $communication, $quirks, $occupation, $goals, $relationship, null, $responseLength]);
                    
                    // Initialize social environment
                    try {
                        $socialProcessor = new BackgroundSocialProcessor($pdo);
                        $socialProcessor->initializeAEISocialEnvironment($aeiId);
                    } catch (Exception $e) {
                        error_log("Failed to initialize social environment for AEI $aeiId: " . $e->getMessage());
                    }
                    
                    // Clean up session data
                    unset($_SESSION['aei_creation_data']);
                    
                    redirectTo('dashboard');
                }
                
            } catch (PDOException $e) {
                error_log("Database error creating AEI: " . $e->getMessage());
                $error = "An error occurred while creating your AEI. Please try again.";
            }
        }
    }
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
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">Birth Your AEI Companion</h1>
            <p class="text-gray-600 dark:text-gray-400 text-lg">Guide the birth of a unique Artificial Emotional Intelligence being</p>
        </div>

        <?php if (isset($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>


        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-8">
            <form method="POST" class="space-y-10" id="aei-form">
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
                                value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                            />
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                Gender Identity *
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Female" class="sr-only" required <?= ($_POST['gender'] ?? '') === 'Female' ? 'checked' : '' ?>>
                                    <i class="fas fa-venus text-pink-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Female</span>
                                </label>
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Male" class="sr-only" required <?= ($_POST['gender'] ?? '') === 'Male' ? 'checked' : '' ?>>
                                    <i class="fas fa-mars text-blue-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Male</span>
                                </label>
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Non-binary" class="sr-only" required <?= ($_POST['gender'] ?? '') === 'Non-binary' ? 'checked' : '' ?>>
                                    <i class="fas fa-genderless text-purple-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Non-binary</span>
                                </label>
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Other" class="sr-only" required <?= ($_POST['gender'] ?? '') === 'Other' ? 'checked' : '' ?>>
                                    <i class="fas fa-question text-gray-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Other</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-8">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Age: <span id="age-display" class="font-bold text-ayuni-blue">25</span> years old
                        </label>
                        <div class="flex items-center space-x-4">
                            <span class="text-sm text-gray-500">18</span>
                            <input 
                                type="range" 
                                id="age" 
                                name="age" 
                                min="18" 
                                max="80" 
                                value="<?= intval($_POST['age'] ?? 25) ?>"
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
                                <span class="text-xs text-gray-500 ml-2">(Select at least 3 traits that best describe them)</span>
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
                                foreach ($personalityTraits as $trait): ?>
                                    <label class="trait-button flex items-center justify-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                                        <input type="checkbox" name="personality_traits[]" value="<?= $trait ?>" class="sr-only">
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
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Formal and polite" class="sr-only" required>
                                    <i class="fas fa-user-tie text-blue-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Formal and polite</span>
                                </label>
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Casual and friendly" class="sr-only" required>
                                    <i class="fas fa-smile text-yellow-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Casual and friendly</span>
                                </label>
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Playful and teasing" class="sr-only" required>
                                    <i class="fas fa-laugh text-pink-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Playful and teasing</span>
                                </label>
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Direct and straightforward" class="sr-only" required>
                                    <i class="fas fa-arrow-right text-red-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Direct and straightforward</span>
                                </label>
                            </div>
                        </div>
                        
                        <div>
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">Speaking habits</h4>
                            <div class="space-y-3">
                                <label class="flex items-center">
                                    <input type="checkbox" name="speaking_traits[]" value="Uses emojis" class="mr-3 rounded border-gray-300 text-ayuni-blue focus:ring-ayuni-blue">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Uses emojis 😊</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="speaking_traits[]" value="Loves wordplay" class="mr-3 rounded border-gray-300 text-ayuni-blue focus:ring-ayuni-blue">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Loves wordplay and puns</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="speaking_traits[]" value="Asks thoughtful questions" class="mr-3 rounded border-gray-300 text-ayuni-blue focus:ring-ayuni-blue">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Asks thoughtful questions</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="speaking_traits[]" value="Tells stories" class="mr-3 rounded border-gray-300 text-ayuni-blue focus:ring-ayuni-blue">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Tells stories and anecdotes</span>
                                </label>
                                <label class="flex items-center">
                                    <input type="checkbox" name="speaking_traits[]" value="Uses metaphors" class="mr-3 rounded border-gray-300 text-ayuni-blue focus:ring-ayuni-blue">
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Uses metaphors and analogies</span>
                                </label>
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
                                <option value="Black">Black</option>
                                <option value="Brown">Brown</option>
                                <option value="Blonde">Blonde</option>
                                <option value="Red">Red</option>
                                <option value="Auburn">Auburn</option>
                                <option value="Silver">Silver</option>
                                <option value="White">White</option>
                                <option value="Colorful">Colorful/Dyed</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Eye Color *</label>
                            <select name="eye_color" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select eye color...</option>
                                <option value="Brown">Brown</option>
                                <option value="Blue">Blue</option>
                                <option value="Green">Green</option>
                                <option value="Hazel">Hazel</option>
                                <option value="Gray">Gray</option>
                                <option value="Amber">Amber</option>
                                <option value="Violet">Violet</option>
                                <option value="Heterochromia">Two different colors</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Height *</label>
                            <select name="height" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select height...</option>
                                <option value="Petite">Petite (under 5'4")</option>
                                <option value="Average">Average (5'4" - 5'7")</option>
                                <option value="Tall">Tall (over 5'7")</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Build *</label>
                            <select name="build" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select build...</option>
                                <option value="Slim">Slim</option>
                                <option value="Average">Average</option>
                                <option value="Athletic">Athletic</option>
                                <option value="Curvy">Curvy</option>
                                <option value="Muscular">Muscular</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Style *</label>
                            <select name="style" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select style...</option>
                                <option value="Casual">Casual</option>
                                <option value="Elegant">Elegant</option>
                                <option value="Sporty">Sporty</option>
                                <option value="Gothic">Gothic</option>
                                <option value="Vintage">Vintage</option>
                                <option value="Modern">Modern</option>
                                <option value="Bohemian">Bohemian</option>
                                <option value="Professional">Professional</option>
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
                        ></textarea>
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
                                />
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">
                                Interests & Hobbies *
                                <span class="text-xs text-gray-500 ml-2">(Select at least 3 things they're passionate about)</span>
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                                <?php 
                                $interestOptions = [
                                    'Art', 'Music', 'Books', 'Movies', 'Gaming', 'Sports', 'Cooking', 'Travel',
                                    'Photography', 'Dancing', 'Singing', 'Writing', 'Science', 'Technology',
                                    'Fashion', 'Fitness', 'Yoga', 'Meditation', 'Nature', 'Animals',
                                    'History', 'Philosophy', 'Psychology', 'Astronomy', 'Languages', 'Theater'
                                ];
                                foreach ($interestOptions as $interest): ?>
                                    <label class="interest-button flex items-center justify-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                                        <input type="checkbox" name="interest_tags[]" value="<?= $interest ?>" class="sr-only">
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
                            ></textarea>
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
                            ></textarea>
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
                                <!-- Friendship -->
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Best Friend" class="sr-only" required>
                                    <i class="fas fa-user-friends text-blue-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Best Friend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Close friendship and trust</p>
                                    </div>
                                </label>
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Close Friend" class="sr-only" required>
                                    <i class="fas fa-users text-blue-400 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Close Friend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Trusted friendship</p>
                                    </div>
                                </label>
                                
                                <!-- Family (Gender-aware) -->
                                <label class="relationship-type family-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="female">
                                    <input type="radio" name="relationship_type" value="Sister" class="sr-only" required>
                                    <i class="fas fa-female text-pink-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Sister</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">She is your sister</p>
                                    </div>
                                </label>
                                <label class="relationship-type family-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="male">
                                    <input type="radio" name="relationship_type" value="Brother" class="sr-only" required>
                                    <i class="fas fa-male text-blue-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Brother</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">He is your brother</p>
                                    </div>
                                </label>
                                
                                <!-- Romantic (Gender-aware) -->
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="female">
                                    <input type="radio" name="relationship_type" value="Girlfriend" class="sr-only" required>
                                    <i class="fas fa-heart text-red-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Girlfriend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">She is your girlfriend</p>
                                    </div>
                                </label>
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="male">
                                    <input type="radio" name="relationship_type" value="Boyfriend" class="sr-only" required>
                                    <i class="fas fa-heart text-red-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Boyfriend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">He is your boyfriend</p>
                                    </div>
                                </label>
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="female">
                                    <input type="radio" name="relationship_type" value="Wife" class="sr-only" required>
                                    <i class="fas fa-ring text-gold-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Wife</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">She is your wife</p>
                                    </div>
                                </label>
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="male">
                                    <input type="radio" name="relationship_type" value="Husband" class="sr-only" required>
                                    <i class="fas fa-ring text-gold-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Husband</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">He is your husband</p>
                                    </div>
                                </label>
                                
                                <!-- Professional/Other -->
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Mentor" class="sr-only" required>
                                    <i class="fas fa-graduation-cap text-purple-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Mentor</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Teaching relationship</p>
                                    </div>
                                </label>
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Colleague" class="sr-only" required>
                                    <i class="fas fa-briefcase text-gray-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Colleague</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Professional relationship</p>
                                    </div>
                                </label>
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Companion" class="sr-only" required>
                                    <i class="fas fa-smile text-yellow-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Companion</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">General support</p>
                                    </div>
                                </label>
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
                                foreach ($relationshipDynamics as $dynamic): ?>
                                    <label class="dynamics-button flex items-center justify-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-all">
                                        <input type="checkbox" name="relationship_dynamics[]" value="<?= $dynamic ?>" class="sr-only">
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
                            ></textarea>
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
                                <label class="response-length flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="response_length" value="1" class="sr-only">
                                    <i class="fas fa-compress text-blue-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Short & Sweet</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">2-3 sentences, concise</p>
                                    </div>
                                </label>
                                <label class="response-length flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="response_length" value="2" class="sr-only" checked>
                                    <i class="fas fa-balance-scale text-green-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Balanced</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">4-5 sentences, just right</p>
                                    </div>
                                </label>
                                <label class="response-length flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="response_length" value="3" class="sr-only">
                                    <i class="fas fa-expand text-purple-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Detailed</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Comprehensive, elaborate</p>
                                    </div>
                                </label>
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
                    <button type="submit" id="submit-btn" class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed disabled:from-gray-400 disabled:to-gray-500" disabled>
                        <i class="fas fa-heart mr-2"></i>
                        <span id="submit-btn-text">Complete Required Fields</span>
                    </button>
                </div>
                
                <!-- Validation Status -->
                <div id="validation-status" class="mt-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-amber-600 dark:text-amber-400 mr-3 mt-0.5"></i>
                        <div>
                            <h4 class="text-sm font-medium text-amber-800 dark:text-amber-200 mb-2">Required Fields Missing</h4>
                            <ul id="missing-fields-list" class="text-sm text-amber-700 dark:text-amber-300 space-y-1">
                                <!-- Missing fields will be populated here -->
                            </ul>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Avatar Generation Loading Screen -->
        <div id="avatar-loading-overlay" class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm z-50 hidden flex items-center justify-center">
            <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 max-w-md mx-4 text-center shadow-2xl">
                <!-- Pulsing Logo -->
                <div class="mb-6">
                    <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-20 w-auto mx-auto pulse-animation dark:hidden">
                    <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-20 w-auto mx-auto pulse-animation hidden dark:block">
                </div>
                
                <!-- Loading Title -->
                <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-4">Creating Your AEI</h3>
                
                <!-- Progress Steps -->
                <div class="space-y-4 mb-6">
                    <div class="flex items-center justify-center space-x-3">
                        <div class="step-indicator active" data-step="1">
                            <i class="fas fa-magic"></i>
                        </div>
                        <span class="step-text text-sm font-medium text-gray-700 dark:text-gray-300">Generating Avatar Options</span>
                    </div>
                    
                    <div class="flex items-center justify-center space-x-3 opacity-50">
                        <div class="step-indicator" data-step="2">
                            <i class="fas fa-palette"></i>
                        </div>
                        <span class="step-text text-sm font-medium text-gray-700 dark:text-gray-300">Creating Photorealistic Portraits</span>
                    </div>
                    
                    <div class="flex items-center justify-center space-x-3 opacity-50">
                        <div class="step-indicator" data-step="3">
                            <i class="fas fa-heart"></i>
                        </div>
                        <span class="step-text text-sm font-medium text-gray-700 dark:text-gray-300">Preparing Avatar Selection</span>
                    </div>
                </div>
                
                <!-- Loading Bar -->
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2 mb-4">
                    <div id="loading-progress" class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue h-2 rounded-full transition-all duration-1000" style="width: 0%"></div>
                </div>
                
                <!-- Status Text -->
                <p id="loading-status" class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Initializing AI avatar generation...
                </p>
                
                <!-- Fun Facts -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-2 flex items-center justify-center">
                        <i class="fas fa-lightbulb mr-2"></i>
                        Did you know?
                    </h4>
                    <p id="fun-fact" class="text-xs text-blue-700 dark:text-blue-400">
                        We generate 3 unique avatar options so you can choose the perfect look for your AEI companion.
                    </p>
                </div>
                
                <!-- Cancel Button -->
                <button type="button" id="cancel-generation" class="mt-6 px-4 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 transition-colors">
                    Cancel Generation
                </button>
            </div>
        </div>

        <!-- Tips Section -->
        <div class="mt-8 space-y-4">
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-3 flex items-center">
                    <i class="fas fa-lightbulb mr-2"></i>
                    Character Creation Tips
                </h4>
                <ul class="text-sm text-blue-700 dark:text-blue-400 space-y-2">
                    <li>• <strong>Mix traits:</strong> Combine different personality traits for depth (e.g., shy but passionate about art)</li>
                    <li>• <strong>Be specific:</strong> The more details you provide, the more unique your AEI will be</li>
                    <li>• <strong>Think realistic:</strong> Create someone who feels like a real person with flaws and strengths</li>
                    <li>• <strong>Consider relationships:</strong> How would they interact with different types of people?</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<style>
/* Custom slider styling */
.slider::-webkit-slider-thumb {
    appearance: none;
    height: 20px;
    width: 20px;
    border-radius: 50%;
    background: linear-gradient(135deg, #00D4AA, #2196F3);
    cursor: pointer;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.slider::-moz-range-thumb {
    height: 20px;
    width: 20px;
    border-radius: 50%;
    background: linear-gradient(135deg, #00D4AA, #2196F3);
    cursor: pointer;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

/* Trait button styling */
.trait-button input:checked + span,
.interest-button input:checked + span {
    background: linear-gradient(135deg, #00D4AA, #2196F3);
    color: white;
    border-radius: 6px;
    padding: 8px 12px;
    margin: -8px -12px;
}

.trait-button:has(input:checked),
.interest-button:has(input:checked) {
    border-color: #2196F3;
    background: rgba(33, 150, 243, 0.1);
}

/* Gender option styling */
.gender-option:has(input:checked) {
    border-color: #2196F3;
    background: rgba(33, 150, 243, 0.1);
}

/* Communication style styling */
.comm-style:has(input:checked) {
    border-color: #2196F3;
    background: rgba(33, 150, 243, 0.1);
}

/* Relationship type styling */
.relationship-type:has(input:checked) {
    border-color: #2196F3;
    background: rgba(33, 150, 243, 0.1);
}
/* Response length styling */
.response-length:has(input:checked) {
    border-color: #2196F3;
    background: rgba(33, 150, 243, 0.1);
}

/* Dynamics button styling */
.dynamics-button input:checked + span {
    background: linear-gradient(135deg, #00D4AA, #2196F3);
    color: white;
    border-radius: 6px;
    padding: 8px 12px;
    margin: -8px -12px;
}

.dynamics-button:has(input:checked) {
    border-color: #2196F3;
    background: rgba(33, 150, 243, 0.1);
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
function updateAgeDisplay(value) {
    document.getElementById('age-display').textContent = value;
}

// Handle trait button selection
document.addEventListener('DOMContentLoaded', function() {
    // Initialize age display with current slider value
    const ageSlider = document.getElementById('age');
    if (ageSlider) {
        updateAgeDisplay(ageSlider.value);
    }
    
    // Trait buttons
    document.querySelectorAll('.trait-button input, .interest-button input, .dynamics-button input').forEach(input => {
        input.addEventListener('change', function() {
            this.closest('label').classList.toggle('selected', this.checked);
        });
    });
    
    // Radio button selections
    document.querySelectorAll('.gender-option input, .comm-style input, .relationship-type input').forEach(input => {
        input.addEventListener('change', function() {
            // Remove selected class from siblings
            this.closest('.grid, .space-y-3').querySelectorAll('label').forEach(label => {
                label.classList.remove('selected');
            });
            // Add selected class to current
            if (this.checked) {
                this.closest('label').classList.add('selected');
            }
            
            // If this is a gender selection, update relationship options
            if (this.name === 'gender') {
                updateRelationshipOptions(this.value);
            }
        });
    });
    
    // Initialize selections on page load
    document.querySelectorAll('input:checked').forEach(input => {
        input.closest('label').classList.add('selected');
    });
    
    // Initialize relationship options based on pre-selected gender
    const selectedGender = document.querySelector('input[name="gender"]:checked');
    if (selectedGender) {
        updateRelationshipOptions(selectedGender.value);
    }
});

// Function to update relationship options based on AEI gender
function updateRelationshipOptions(aeiGender) {
    console.log('updateRelationshipOptions called with aeiGender:', aeiGender);
    
    const relationshipOptions = document.getElementById('relationship-options');
    const familyOptions = relationshipOptions.querySelectorAll('.family-relation');
    const romanticOptions = relationshipOptions.querySelectorAll('.romantic-relation');
    
    console.log('Found family options:', familyOptions.length);
    console.log('Found romantic options:', romanticOptions.length);
    
    // Show all options if no gender is selected
    if (!aeiGender) {
        console.log('No gender selected, showing all options');
        familyOptions.forEach(option => option.style.display = 'flex');
        romanticOptions.forEach(option => option.style.display = 'flex');
        return;
    }
    
    // Convert aeiGender to lowercase for comparison
    const aeiGenderLower = aeiGender.toLowerCase();
    
    // Show/hide family options based on gender
    familyOptions.forEach(option => {
        const optionGender = option.getAttribute('data-aei-gender');
        console.log('Family option:', option.querySelector('span').textContent, 'data-aei-gender:', optionGender, 'aeiGenderLower:', aeiGenderLower, 'matches:', optionGender === aeiGenderLower);
        if (optionGender === aeiGenderLower) {
            option.style.display = 'flex';
        } else {
            option.style.display = 'none';
            // Uncheck if hidden
            const input = option.querySelector('input');
            if (input && input.checked) {
                input.checked = false;
                option.classList.remove('selected');
            }
        }
    });
    
    // Show/hide romantic options based on gender
    romanticOptions.forEach(option => {
        const optionGender = option.getAttribute('data-aei-gender');
        console.log('Romantic option:', option.querySelector('span').textContent, 'data-aei-gender:', optionGender, 'aeiGenderLower:', aeiGenderLower, 'matches:', optionGender === aeiGenderLower);
        if (optionGender === aeiGenderLower) {
            option.style.display = 'flex';
        } else {
            option.style.display = 'none';
            // Uncheck if hidden
            const input = option.querySelector('input');
            if (input && input.checked) {
                input.checked = false;
                option.classList.remove('selected');
            }
        }
    });
}

// AI Configuration System
document.addEventListener('DOMContentLoaded', function() {
    const generateBtn = document.getElementById('generate-config-btn');
    const descriptionInput = document.getElementById('ai-description-input');
    const loadingDiv = document.getElementById('ai-loading');
    const successDiv = document.getElementById('ai-success');
    const errorDiv = document.getElementById('ai-error');
    const errorText = document.getElementById('ai-error-text');
    const btnText = document.getElementById('generate-btn-text');
    
    generateBtn.addEventListener('click', async function() {
        const description = descriptionInput.value.trim();
        
        if (!description) {
            showError('Please describe your ideal AEI companion first.');
            return;
        }
        
        // Hide previous messages
        hideMessages();
        
        // Show loading state
        generateBtn.disabled = true;
        loadingDiv.classList.remove('hidden');
        btnText.textContent = 'Generating...';
        
        try {
            const response = await fetch('/api/ai-config.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    description: description,
                    csrf_token: document.querySelector('[name="csrf_token"]').value
                })
            });
            
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.error || 'Failed to generate configuration');
            }
            
            // Apply the configuration to form with animations
            await applyConfigurationWithAnimation(data.config);
            
            // Show success message
            successDiv.classList.remove('hidden');
            
            // Scroll to form
            document.getElementById('aei-form').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
            
        } catch (error) {
            console.error('AI Config Error:', error);
            showError(error.message);
        } finally {
            // Reset button state
            generateBtn.disabled = false;
            loadingDiv.classList.add('hidden');
            btnText.textContent = 'Generate AEI Configuration';
        }
    });
    
    function hideMessages() {
        successDiv.classList.add('hidden');
        errorDiv.classList.add('hidden');
    }
    
    function showError(message) {
        errorText.textContent = message;
        errorDiv.classList.remove('hidden');
    }
    
    async function applyConfigurationWithAnimation(config) {
        // Name and Age
        animateFieldUpdate('name', config.name);
        animateFieldUpdate('age', config.age);
        
        // Gender
        if (config.gender) {
            animateSelectUpdate('gender', config.gender);
        }
        
        // Personality Traits with staggered animation
        if (config.personality_traits && config.personality_traits.length > 0) {
            for (let i = 0; i < config.personality_traits.length; i++) {
                setTimeout(() => {
                    animateCheckboxUpdate('personality_traits[]', config.personality_traits[i]);
                }, i * 200);
            }
        }
        
        // Communication Style
        if (config.communication_style) {
            setTimeout(() => {
                animateSelectUpdate('communication_style', config.communication_style);
            }, config.personality_traits ? config.personality_traits.length * 200 : 0);
        }
        
        // Speaking Traits
        if (config.speaking_traits && config.speaking_traits.length > 0) {
            const baseDelay = (config.personality_traits ? config.personality_traits.length * 200 : 0) + 500;
            for (let i = 0; i < config.speaking_traits.length; i++) {
                setTimeout(() => {
                    animateCheckboxUpdate('speaking_traits[]', config.speaking_traits[i]);
                }, baseDelay + (i * 200));
            }
        }
        
        // Interests
        if (config.interests && config.interests.length > 0) {
            const baseDelay = (config.personality_traits ? config.personality_traits.length * 200 : 0) + 
                            (config.speaking_traits ? config.speaking_traits.length * 200 : 0) + 1000;
            for (let i = 0; i < config.interests.length; i++) {
                setTimeout(() => {
                    animateCheckboxUpdate('interest_tags[]', config.interests[i]);
                }, baseDelay + (i * 200));
            }
        }
        
        // Appearance fields
        setTimeout(() => {
            if (config.hair_color) animateFieldUpdate('hair_color', config.hair_color);
            if (config.eye_color) animateFieldUpdate('eye_color', config.eye_color);
            if (config.height) animateFieldUpdate('height', config.height);
            if (config.build) animateFieldUpdate('build', config.build);
            if (config.style) animateFieldUpdate('style', config.style);
        }, 2000);
        
        // Text areas
        setTimeout(() => {
            if (config.background) animateFieldUpdate('background', config.background);
            if (config.quirks) animateFieldUpdate('quirks', config.quirks);
            if (config.occupation) animateFieldUpdate('occupation', config.occupation);
            if (config.goals) animateFieldUpdate('goals', config.goals);
        }, 2500);
        
        // Relationship fields
        setTimeout(() => {
            if (config.relationship_type) animateSelectUpdate('relationship_type', config.relationship_type);
            if (config.relationship_history) animateFieldUpdate('relationship_history', config.relationship_history);
            
            if (config.relationship_dynamics && config.relationship_dynamics.length > 0) {
                config.relationship_dynamics.forEach((dynamic, i) => {
                    setTimeout(() => {
                        animateCheckboxUpdate('relationship_dynamics[]', dynamic);
                    }, i * 200);
                });
            }
        }, 3000);
    }
    
    function animateFieldUpdate(fieldName, value) {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.style.transform = 'scale(1.05)';
            field.style.transition = 'all 0.3s ease';
            field.value = value;
            
            setTimeout(() => {
                field.style.transform = 'scale(1)';
            }, 300);
        }
    }
    
    function animateSelectUpdate(fieldName, value) {
        const field = document.querySelector(`[name="${fieldName}"]`);
        if (field) {
            field.style.transform = 'scale(1.05)';
            field.style.transition = 'all 0.3s ease';
            field.value = value;
            
            // Trigger change event for any dependent functionality
            field.dispatchEvent(new Event('change'));
            
            setTimeout(() => {
                field.style.transform = 'scale(1)';
            }, 300);
        }
    }
    
    function animateCheckboxUpdate(fieldName, value) {
        const checkboxes = document.querySelectorAll(`input[name="${fieldName}"]`);
        checkboxes.forEach(checkbox => {
            if (checkbox.value === value) {
                const label = checkbox.closest('label');
                if (label) {
                    label.style.transform = 'scale(1.05)';
                    label.style.transition = 'all 0.3s ease';
                    
                    checkbox.checked = true;
                    label.classList.add('selected');
                    
                    setTimeout(() => {
                        label.style.transform = 'scale(1)';
                    }, 300);
                }
            }
        });
    }
});

// Custom Tags System
document.addEventListener('DOMContentLoaded', function() {
    const tagSystems = {
        'personality': {
            input: document.getElementById('personality-input'),
            tagsContainer: document.getElementById('personality-tags'),
            hiddenInput: document.getElementById('personality-custom-hidden'),
            tags: []
        },
        'interest': {
            input: document.getElementById('interest-input'),
            tagsContainer: document.getElementById('interest-tags'),
            hiddenInput: document.getElementById('interest-custom-hidden'),
            tags: []
        }
    };

    // Initialize tag system for each type
    Object.keys(tagSystems).forEach(type => {
        const system = tagSystems[type];
        if (!system.input) return;

        if (system.input) {
            system.input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const value = this.value.trim();
                    if (value && !system.tags.includes(value)) {
                        addTag(type, value);
                        this.value = '';
                    }
                }
            });

            system.input.addEventListener('blur', function() {
                const value = this.value.trim();
                if (value && !system.tags.includes(value)) {
                    addTag(type, value);
                    this.value = '';
                }
            });
        }
    });

    function addTag(type, tagText) {
        const system = tagSystems[type];
        system.tags.push(tagText);
        
        // Create tag element
        const tagElement = document.createElement('div');
        tagElement.className = 'inline-flex items-center bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white text-sm px-3 py-1 rounded-full';
        tagElement.innerHTML = `
            <span>${tagText}</span>
            <button type="button" class="ml-2 text-white hover:text-gray-200 focus:outline-none" onclick="removeTag('${type}', '${tagText}', this)">
                <i class="fas fa-times text-xs"></i>
            </button>
        `;
        
        system.tagsContainer.appendChild(tagElement);
        updateHiddenInput(type);
    }

    function updateHiddenInput(type) {
        const system = tagSystems[type];
        system.hiddenInput.value = JSON.stringify(system.tags);
    }

    // Global function for removing tags
    window.removeTag = function(type, tagText, buttonElement) {
        const system = tagSystems[type];
        const index = system.tags.indexOf(tagText);
        if (index > -1) {
            system.tags.splice(index, 1);
            buttonElement.parentElement.remove();
            updateHiddenInput(type);
        }
    };
});

// Avatar Generation Loading Screen
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('aei-form');
    const submitBtn = document.getElementById('submit-btn');
    const loadingOverlay = document.getElementById('avatar-loading-overlay');
    const loadingProgress = document.getElementById('loading-progress');
    const loadingStatus = document.getElementById('loading-status');
    const funFact = document.getElementById('fun-fact');
    const cancelBtn = document.getElementById('cancel-generation');
    
    // Fun facts about avatar generation
    const funFacts = [
        "We generate 3 unique avatar options so you can choose the perfect look for your AEI companion.",
        "Our AI uses advanced photorealistic prompting to create human-like portraits that aren't cartoonish.",
        "Each avatar generation takes about 30-60 seconds depending on complexity and server load.",
        "The Flux-Dev model we use is specifically trained for high-quality portrait photography.",
        "Your AEI's appearance is generated based on the detailed characteristics you provided.",
        "We use professional camera settings simulation (85mm lens, f/1.4) for realistic depth of field.",
        "The AI considers lighting, skin texture, and natural expressions for maximum realism."
    ];
    
    // Status messages for different steps
    const statusMessages = [
        "Initializing AI avatar generation...",
        "Analyzing your AEI's appearance description...",
        "Building photorealistic prompts...",
        "Sending generation request to Flux-Dev AI...",
        "Generating first avatar option...",
        "Generating second avatar option...",
        "Generating third avatar option...",
        "Processing and optimizing images...",
        "Preparing avatar selection interface...",
        "Almost ready! Finalizing your options..."
    ];
    
    let currentStep = 1;
    let progressInterval;
    let statusInterval;
    let factInterval;
    let currentFactIndex = 0;
    let currentStatusIndex = 0;
    
    form.addEventListener('submit', function(e) {
        // Check if the form has a name (required field) to avoid showing loading for invalid submissions
        const nameField = document.getElementById('name');
        if (!nameField.value.trim()) {
            return; // Let the form handle validation normally
        }
        
        // Show loading screen
        showLoadingScreen();
    });
    
    cancelBtn.addEventListener('click', function() {
        hideLoadingScreen();
    });
    
    function showLoadingScreen() {
        loadingOverlay.classList.remove('hidden');
        submitBtn.disabled = true;
        
        // Start progress animation
        animateProgress();
        
        // Start status updates
        updateStatus();
        
        // Rotate fun facts
        rotateFunFacts();
        
        // Simulate step progression
        progressSteps();
    }
    
    function hideLoadingScreen() {
        loadingOverlay.classList.add('hidden');
        submitBtn.disabled = false;
        clearIntervals();
        resetProgress();
    }
    
    function animateProgress() {
        let progress = 0;
        progressInterval = setInterval(() => {
            progress += Math.random() * 3; // Variable progress speed
            if (progress > 85) progress = 85; // Don't complete until actual completion
            
            loadingProgress.style.width = progress + '%';
            
            if (progress >= 85) {
                clearInterval(progressInterval);
            }
        }, 200);
    }
    
    function updateStatus() {
        statusInterval = setInterval(() => {
            if (currentStatusIndex < statusMessages.length - 1) {
                currentStatusIndex++;
                loadingStatus.textContent = statusMessages[currentStatusIndex];
            }
        }, 3000); // Change status every 3 seconds
    }
    
    function rotateFunFacts() {
        factInterval = setInterval(() => {
            currentFactIndex = (currentFactIndex + 1) % funFacts.length;
            funFact.style.opacity = '0';
            
            setTimeout(() => {
                funFact.textContent = funFacts[currentFactIndex];
                funFact.style.opacity = '1';
            }, 300);
        }, 8000); // Change fact every 8 seconds
    }
    
    function progressSteps() {
        setTimeout(() => {
            activateStep(2);
        }, 10000); // Step 2 after 10 seconds
        
        setTimeout(() => {
            activateStep(3);
        }, 20000); // Step 3 after 20 seconds
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
    
    function clearIntervals() {
        if (progressInterval) clearInterval(progressInterval);
        if (statusInterval) clearInterval(statusInterval);
        if (factInterval) clearInterval(factInterval);
    }
    
    function resetProgress() {
        loadingProgress.style.width = '0%';
        currentStep = 1;
        currentStatusIndex = 0;
        currentFactIndex = 0;
        
        // Reset steps
        document.querySelectorAll('.step-indicator').forEach((el, index) => {
            el.classList.remove('active', 'completed');
            el.parentElement.classList.toggle('opacity-50', index > 0);
            
            if (index === 0) {
                el.classList.add('active');
                el.innerHTML = '<i class="fas fa-magic"></i>';
            } else if (index === 1) {
                el.innerHTML = '<i class="fas fa-palette"></i>';
            } else if (index === 2) {
                el.innerHTML = '<i class="fas fa-heart"></i>';
            }
        });
        
        // Reset status and fact
        loadingStatus.textContent = statusMessages[0];
        funFact.textContent = funFacts[0];
        funFact.style.opacity = '1';
    }
    
    // Add smooth opacity transition for fun facts
    funFact.style.transition = 'opacity 0.3s ease';
    
    // Required fields validation
    const requiredFields = [
        { id: 'name', name: 'Name', type: 'text' },
        { id: 'age', name: 'Age', type: 'number' },
        { id: 'hair_color', name: 'Hair Color', type: 'select' },
        { id: 'eye_color', name: 'Eye Color', type: 'select' },
        { id: 'height', name: 'Height', type: 'select' },
        { id: 'build', name: 'Build', type: 'select' },
        { id: 'style', name: 'Style', type: 'select' },
        { id: 'occupation', name: 'Occupation', type: 'text' }
    ];
    
    const submitBtnElement = document.getElementById('submit-btn');
    const submitBtnText = document.getElementById('submit-btn-text');
    const validationStatus = document.getElementById('validation-status');
    const missingFieldsList = document.getElementById('missing-fields-list');
    
    // Special validation for complex fields
    const requiredComplexFields = [
        { name: 'gender', selector: 'input[name="gender"]:checked', errorMsg: 'Gender is required' },
        { name: 'personality_traits', selector: 'input[name="personality_traits[]"]:checked', errorMsg: 'At least 3 personality traits are required', minCount: 3 },
        { name: 'communication_style', selector: 'input[name="communication_style"]:checked', errorMsg: 'Communication style is required' },
        { name: 'interests', selector: 'input[name="interests[]"]:checked', errorMsg: 'At least 3 interests are required', minCount: 3 },
        { name: 'relationship', selector: 'input[name="relationship"]:checked', errorMsg: 'Relationship type is required' }
    ];
    
    function showError(field, message) {
        // Remove existing error
        const parent = field.closest('.space-y-6, .grid, .border-b, div') || field.parentNode;
        const existingError = parent.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }
        
        // Add error styling
        field.classList.add('border-red-500', 'dark:border-red-400');
        field.classList.remove('border-gray-300', 'dark:border-gray-600');
        
        // Create error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'validation-error text-red-600 dark:text-red-400 text-sm mt-2 flex items-center';
        errorDiv.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i>${message}`;
        parent.appendChild(errorDiv);
    }
    
    function showComplexError(container, message) {
        if (!container) return; // Skip if container is null
        
        // Remove existing error
        const existingError = container.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }
        
        // Create error message
        const errorDiv = document.createElement('div');
        errorDiv.className = 'validation-error text-red-600 dark:text-red-400 text-sm mt-2 flex items-center';
        errorDiv.innerHTML = `<i class="fas fa-exclamation-circle mr-2"></i>${message}`;
        container.appendChild(errorDiv);
    }
    
    function clearError(field) {
        // Remove error styling
        field.classList.remove('border-red-500', 'dark:border-red-400');
        field.classList.add('border-gray-300', 'dark:border-gray-600');
        
        // Remove error message
        const parent = field.closest('.space-y-6, .grid, .border-b, div') || field.parentNode;
        const existingError = parent.querySelector('.validation-error');
        if (existingError) {
            existingError.remove();
        }
    }
    
    function validateSimpleField(fieldConfig) {
        const field = document.getElementById(fieldConfig.id);
        if (!field) return true;
        
        const value = field.value.trim();
        
        if (!value) {
            showError(field, `${fieldConfig.name} is required`);
            return false;
        }
        
        // Special validation for age
        if (fieldConfig.id === 'age') {
            const age = parseInt(value);
            if (age < 18 || age > 100) {
                showError(field, 'Age must be between 18 and 100');
                return false;
            }
        }
        
        clearError(field);
        return true;
    }
    
    function validateComplexField(fieldConfig) {
        const elements = document.querySelectorAll(fieldConfig.selector);
        const count = elements.length;
        const minCount = fieldConfig.minCount || 1;
        
        let container = null;
        
        if (fieldConfig.name === 'gender') {
            const genderElement = document.querySelector('[name="gender"]');
            container = genderElement ? genderElement.closest('.space-y-3') : null;
        } else if (fieldConfig.name === 'personality_traits') {
            const traitsElement = document.getElementById('personality-traits');
            container = traitsElement ? traitsElement.parentNode : null;
        } else if (fieldConfig.name === 'communication_style') {
            const styleElement = document.querySelector('[name="communication_style"]');
            container = styleElement ? styleElement.closest('.space-y-3') : null;
        } else if (fieldConfig.name === 'interests') {
            const interestsElement = document.getElementById('interests-grid');
            container = interestsElement ? interestsElement.parentNode : null;
        } else {
            const relationshipElement = document.querySelector('[name="relationship"]');
            container = relationshipElement ? relationshipElement.closest('.space-y-3') : null;
        }
        
        if (count < minCount) {
            showComplexError(container, fieldConfig.errorMsg);
            return false;
        }
        
        // Clear error
        if (container) {
            const existingError = container.querySelector('.validation-error');
            if (existingError) {
                existingError.remove();
            }
        }
        
        return true;
    }
    
    function validateForm() {
        let isValid = true;
        
        // Clear all previous errors
        document.querySelectorAll('.validation-error').forEach(error => error.remove());
        document.querySelectorAll('.border-red-500, .border-red-400').forEach(field => {
            field.classList.remove('border-red-500', 'dark:border-red-400');
            field.classList.add('border-gray-300', 'dark:border-gray-600');
        });
        
        // Validate simple fields
        requiredFields.forEach(fieldConfig => {
            if (!validateSimpleField(fieldConfig)) {
                isValid = false;
            }
        });
        
        // Validate complex fields
        requiredComplexFields.forEach(fieldConfig => {
            if (!validateComplexField(fieldConfig)) {
                isValid = false;
            }
        });
        
        return isValid;
    }
    
    function updateSubmitButtonState() {
        const missingFields = [];
        
        // Check simple fields
        requiredFields.forEach(fieldConfig => {
            const field = document.getElementById(fieldConfig.id);
            if (field) {
                const value = field.value.trim();
                if (!value || (fieldConfig.id === 'age' && (parseInt(value) < 18 || parseInt(value) > 100))) {
                    missingFields.push(fieldConfig.name);
                }
            }
        });
        
        // Check complex fields
        requiredComplexFields.forEach(fieldConfig => {
            const elements = document.querySelectorAll(fieldConfig.selector);
            const count = elements.length;
            const minCount = fieldConfig.minCount || 1;
            
            if (count < minCount) {
                if (fieldConfig.name === 'gender') {
                    missingFields.push('Gender');
                } else if (fieldConfig.name === 'personality_traits') {
                    missingFields.push(`Personality Traits (${count}/3 selected)`);
                } else if (fieldConfig.name === 'communication_style') {
                    missingFields.push('Communication Style');
                } else if (fieldConfig.name === 'interests') {
                    missingFields.push(`Interests (${count}/3 selected)`);
                } else if (fieldConfig.name === 'relationship') {
                    missingFields.push('Relationship Type');
                }
            }
        });
        
        // Update UI based on validation state
        if (missingFields.length === 0) {
            // All fields valid - enable button
            submitBtnElement.disabled = false;
            submitBtnText.textContent = 'Begin Birth Process';
            validationStatus.style.display = 'none';
        } else {
            // Missing fields - disable button and show status
            submitBtnElement.disabled = true;
            submitBtnText.textContent = `Complete Required Fields (${missingFields.length} missing)`;
            validationStatus.style.display = 'block';
            
            // Update missing fields list
            missingFieldsList.innerHTML = missingFields.map(field => 
                `<li class="flex items-center"><i class="fas fa-exclamation-triangle text-xs mr-2"></i>${field}</li>`
            ).join('');
        }
    }
    
    // Real-time validation and button state update
    requiredFields.forEach(fieldConfig => {
        const field = document.getElementById(fieldConfig.id);
        if (field) {
            const eventType = fieldConfig.type === 'select' ? 'change' : 'input';
            field.addEventListener(eventType, () => {
                validateSimpleField(fieldConfig);
                updateSubmitButtonState();
            });
        }
    });
    
    // Real-time validation for complex fields
    document.addEventListener('change', function(e) {
        if (e.target.name === 'gender' || 
            e.target.name === 'personality_traits[]' || 
            e.target.name === 'communication_style' || 
            e.target.name === 'interests[]' || 
            e.target.name === 'relationship') {
            
            const fieldConfig = requiredComplexFields.find(f => f.name === e.target.name.replace('[]', ''));
            if (fieldConfig) {
                validateComplexField(fieldConfig);
            }
            updateSubmitButtonState();
        }
    });
    
    // Initial button state update
    updateSubmitButtonState();
    
    // Override form submission
    const originalSubmitHandler = form.onsubmit;
    form.addEventListener('submit', function(e) {
        if (!validateForm()) {
            e.preventDefault();
            
            // Scroll to first error
            const firstError = document.querySelector('.validation-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            // Show notification
            const notification = document.createElement('div');
            notification.className = 'fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 transform translate-x-full transition-transform duration-300';
            notification.innerHTML = '<i class="fas fa-exclamation-circle mr-2"></i>Please fill in all required fields correctly';
            document.body.appendChild(notification);
            
            // Animate in
            setTimeout(() => {
                notification.classList.remove('translate-x-full');
            }, 100);
            
            // Remove after 5 seconds
            setTimeout(() => {
                notification.classList.add('translate-x-full');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 5000);
            
            return false;
        }
    }, true); // Use capture phase to run before existing handlers
});
</script>