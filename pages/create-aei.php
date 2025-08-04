<?php
requireOnboarding();

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
        $customTraits = $personalityCustom ? array_map('trim', explode(',', $personalityCustom)) : [];
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
        $customInterests = $interestCustom ? array_map('trim', explode(',', $interestCustom)) : [];
        $interests = json_encode(array_merge($interestTags, $customInterests));
        
        $quirks = sanitizeInput($_POST['quirks'] ?? '');
        $occupation = sanitizeInput($_POST['occupation'] ?? '');
        $goals = sanitizeInput($_POST['goals'] ?? '');
        
        // Process relationship data
        $relationshipType = sanitizeInput($_POST['relationship_type'] ?? '');
        $relationshipHistory = sanitizeInput($_POST['relationship_history'] ?? '');
        $relationshipDynamics = $_POST['relationship_dynamics'] ?? [];
        $relationship = json_encode([
            'type' => $relationshipType,
            'history' => $relationshipHistory,
            'dynamics' => $relationshipDynamics
        ]);
        
        if (empty($name)) {
            $error = "AEI name is required";
        } else {
            try {
                $aeiId = generateId();
                $stmt = $pdo->prepare("INSERT INTO aeis (id, user_id, name, age, gender, personality, appearance_description, background, interests, communication_style, quirks, occupation, goals, relationship_context) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$aeiId, getUserSession(), $name, $age, $gender, $personality, $appearance, $background, $interests, $communication, $quirks, $occupation, $goals, $relationship]);
                redirectTo('dashboard');
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
            <form method="POST" class="space-y-10">
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
                                Gender Identity
                            </label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Female" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Female' ? 'checked' : '' ?>>
                                    <i class="fas fa-venus text-pink-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Female</span>
                                </label>
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Male" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Male' ? 'checked' : '' ?>>
                                    <i class="fas fa-mars text-blue-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Male</span>
                                </label>
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Non-binary" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Non-binary' ? 'checked' : '' ?>>
                                    <i class="fas fa-genderless text-purple-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Non-binary</span>
                                </label>
                                <label class="gender-option flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Other" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Other' ? 'checked' : '' ?>>
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
                                Core Personality Traits
                                <span class="text-xs text-gray-500 ml-2">(Select 3-6 traits that best describe them)</span>
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
                                <input 
                                    type="text" 
                                    name="personality_custom" 
                                    placeholder="Add custom traits (comma-separated)..."
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                                />
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
                            <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-4">How do they communicate?</h4>
                            <div class="space-y-3">
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Formal and polite" class="sr-only">
                                    <i class="fas fa-user-tie text-blue-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Formal and polite</span>
                                </label>
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Casual and friendly" class="sr-only">
                                    <i class="fas fa-smile text-yellow-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Casual and friendly</span>
                                </label>
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Playful and teasing" class="sr-only">
                                    <i class="fas fa-laugh text-pink-500 mr-3"></i>
                                    <span class="text-sm text-gray-700 dark:text-gray-300">Playful and teasing</span>
                                </label>
                                <label class="comm-style flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="communication_style" value="Direct and straightforward" class="sr-only">
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
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Hair Color</label>
                            <select name="hair_color" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
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
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Eye Color</label>
                            <select name="eye_color" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
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
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Height</label>
                            <select name="height" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select height...</option>
                                <option value="Petite">Petite (under 5'4")</option>
                                <option value="Average">Average (5'4" - 5'7")</option>
                                <option value="Tall">Tall (over 5'7")</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Build</label>
                            <select name="build" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select build...</option>
                                <option value="Slim">Slim</option>
                                <option value="Average">Average</option>
                                <option value="Athletic">Athletic</option>
                                <option value="Curvy">Curvy</option>
                                <option value="Muscular">Muscular</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Style</label>
                            <select name="style" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
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
                                    Occupation or Role
                                </label>
                                <input 
                                    type="text" 
                                    id="occupation" 
                                    name="occupation" 
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
                                Interests & Hobbies
                                <span class="text-xs text-gray-500 ml-2">(Select what they're passionate about)</span>
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
                                <input 
                                    type="text" 
                                    name="interest_custom" 
                                    placeholder="Add custom interests (comma-separated)..."
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue"
                                />
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
                                What is your AEI for you?
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3" id="relationship-options">
                                <!-- Friendship -->
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Best Friend" class="sr-only">
                                    <i class="fas fa-user-friends text-blue-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Best Friend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Close friendship and trust</p>
                                    </div>
                                </label>
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Close Friend" class="sr-only">
                                    <i class="fas fa-users text-blue-400 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Close Friend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Trusted friendship</p>
                                    </div>
                                </label>
                                
                                <!-- Family (Gender-aware) -->
                                <label class="relationship-type family-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="female">
                                    <input type="radio" name="relationship_type" value="Sister" class="sr-only">
                                    <i class="fas fa-female text-pink-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Sister</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">She is your sister</p>
                                    </div>
                                </label>
                                <label class="relationship-type family-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="male">
                                    <input type="radio" name="relationship_type" value="Brother" class="sr-only">
                                    <i class="fas fa-male text-blue-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Brother</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">He is your brother</p>
                                    </div>
                                </label>
                                
                                <!-- Romantic (Gender-aware) -->
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="female">
                                    <input type="radio" name="relationship_type" value="Girlfriend" class="sr-only">
                                    <i class="fas fa-heart text-red-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Girlfriend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">She is your girlfriend</p>
                                    </div>
                                </label>
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="male">
                                    <input type="radio" name="relationship_type" value="Boyfriend" class="sr-only">
                                    <i class="fas fa-heart text-red-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Boyfriend</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">He is your boyfriend</p>
                                    </div>
                                </label>
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="female">
                                    <input type="radio" name="relationship_type" value="Wife" class="sr-only">
                                    <i class="fas fa-ring text-gold-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Wife</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">She is your wife</p>
                                    </div>
                                </label>
                                <label class="relationship-type romantic-relation flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors" data-aei-gender="male">
                                    <input type="radio" name="relationship_type" value="Husband" class="sr-only">
                                    <i class="fas fa-ring text-gold-600 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Husband</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">He is your husband</p>
                                    </div>
                                </label>
                                
                                <!-- Professional/Other -->
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Mentor" class="sr-only">
                                    <i class="fas fa-graduation-cap text-purple-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Mentor</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Teaching relationship</p>
                                    </div>
                                </label>
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Colleague" class="sr-only">
                                    <i class="fas fa-briefcase text-gray-500 mr-3"></i>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Colleague</span>
                                        <p class="text-xs text-gray-500 dark:text-gray-400">Professional relationship</p>
                                    </div>
                                </label>
                                <label class="relationship-type flex items-center p-4 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="relationship_type" value="Companion" class="sr-only">
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

                <!-- Submit -->
                <div class="flex space-x-4 pt-6">
                    <a href="/dashboard" class="flex-1 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-4 px-6 rounded-lg text-center hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                        Cancel
                    </a>
                    <button type="submit" class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i class="fas fa-heart mr-2"></i>
                        Begin Birth
                    </button>
                </div>
            </form>
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
</style>

<script>
function updateAgeDisplay(value) {
    document.getElementById('age-display').textContent = value;
}

// Handle trait button selection
document.addEventListener('DOMContentLoaded', function() {
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
</script>