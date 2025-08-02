<?php
requireAuth();

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
        $personality = json_encode(array_merge($personalityTraits, $personalityCustom ? [explode(',', $personalityCustom)] : []));
        
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
        $interests = json_encode(array_merge($interestTags, $interestCustom ? explode(',', $interestCustom) : []));
        
        $communicationStyle = sanitizeInput($_POST['communication_style'] ?? '');
        $quirks = sanitizeInput($_POST['quirks'] ?? '');
        $occupation = sanitizeInput($_POST['occupation'] ?? '');
        $goals = sanitizeInput($_POST['goals'] ?? '');
        
        if (empty($name)) {
            $error = "AEI name is required";
        } else {
            try {
                $aeiId = generateId();
                $stmt = $pdo->prepare("INSERT INTO aeis (id, user_id, name, age, gender, personality, appearance_description, background, interests, communication_style, quirks, occupation, goals) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$aeiId, getUserSession(), $name, $age, $gender, $personality, $appearance, $background, $interests, $communicationStyle, $quirks, $occupation, $goals]);
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
    <!-- Navigation -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center space-x-4">
                    <a href="/dashboard" class="flex items-center space-x-2 text-gray-700 dark:text-gray-300 hover:text-ayuni-blue transition-colors">
                        <i class="fas fa-arrow-left"></i>
                        <span class="font-medium">Back to Dashboard</span>
                    </a>
                    <img src="assets/ayuni.png" alt="Ayuni Logo" class="h-10 w-auto">
                    <span class="text-xl font-semibold text-gray-900 dark:text-white">Create AEI</span>
                </div>
                <button 
                    id="theme-toggle" 
                    onclick="toggleTheme()" 
                    class="p-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-600 dark:text-gray-300 hover:text-gray-800 dark:hover:text-white transition-all duration-200"
                    title="Toggle theme"
                >
                    <i class="fas fa-sun sun-icon text-lg"></i>
                    <i class="fas fa-moon moon-icon text-lg"></i>
                </button>
            </div>
        </div>
    </nav>

    <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold text-gray-900 dark:text-white mb-4">Create Your AEI</h1>
            <p class="text-gray-600 dark:text-gray-400 text-lg">Design a unique Artificial Emotional Intelligence companion</p>
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
            <form method="POST" class="space-y-8">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <!-- Basic Information -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-8">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">
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
                            <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                Gender Identity
                            </label>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Female" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Female' ? 'checked' : '' ?>>
                                    <i class="fas fa-venus text-pink-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Female</span>
                                </label>
                                <label class="flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Male" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Male' ? 'checked' : '' ?>>
                                    <i class="fas fa-mars text-blue-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Male</span>
                                </label>
                                <label class="flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                    <input type="radio" name="gender" value="Non-binary" class="sr-only" <?= ($_POST['gender'] ?? '') === 'Non-binary' ? 'checked' : '' ?>>
                                    <i class="fas fa-genderless text-purple-500 mr-2"></i>
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Non-binary</span>
                                </label>
                                <label class="flex items-center p-3 border border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
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
                                class="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-lg appearance-none cursor-pointer slider"
                                oninput="updateAgeDisplay(this.value)"
                            />
                            <span class="text-sm text-gray-500">80</span>
                        </div>
                    </div>
                </div>

                <!-- Personality & Character -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        <i class="fas fa-heart mr-2 text-ayuni-aqua"></i>
                        Personality & Character
                    </h3>
                    
                    <div class="space-y-6">
                        <div>
                            <label for="personality" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Core Personality Traits
                            </label>
                            <textarea 
                                id="personality" 
                                name="personality" 
                                rows="4"
                                maxlength="1000"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                placeholder="Describe their fundamental personality traits: Are they introverted or extroverted? Optimistic or realistic? Playful or serious? Logical or emotional?"
                            ><?= htmlspecialchars($_POST['personality'] ?? '') ?></textarea>
                        </div>
                        
                        <div>
                            <label for="communication_style" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Communication Style
                            </label>
                            <textarea 
                                id="communication_style" 
                                name="communication_style" 
                                rows="3"
                                maxlength="500"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                placeholder="How do they speak and express themselves? Formal or casual? Use slang or proper grammar? Talkative or concise?"
                            ><?= htmlspecialchars($_POST['communication_style'] ?? '') ?></textarea>
                        </div>
                        
                        <div>
                            <label for="quirks" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Unique Quirks & Mannerisms
                            </label>
                            <textarea 
                                id="quirks" 
                                name="quirks" 
                                rows="3"
                                maxlength="500"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                placeholder="Special habits, catchphrases, reactions, or unique behaviors that make them memorable..."
                            ><?= htmlspecialchars($_POST['quirks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Appearance & Style -->
                <div class="border-b border-gray-200 dark:border-gray-700 pb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        <i class="fas fa-palette mr-2 text-ayuni-blue"></i>
                        Appearance & Style
                    </h3>
                    
                    <div>
                        <label for="appearance" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            Physical Appearance
                        </label>
                        <textarea 
                            id="appearance" 
                            name="appearance" 
                            rows="4"
                            maxlength="800"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="Describe their physical features: hair color and style, eye color, height, build, facial features, skin tone, style of dress, any distinctive markings or accessories..."
                        ><?= htmlspecialchars($_POST['appearance'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- Background & Life -->
                <div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                        <i class="fas fa-book mr-2 text-purple-500"></i>
                        Background & Life
                    </h3>
                    
                    <div class="space-y-6">
                        <div>
                            <label for="background" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Personal Background & History
                            </label>
                            <textarea 
                                id="background" 
                                name="background" 
                                rows="4"
                                maxlength="1000"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                placeholder="Their life story, where they came from, important experiences that shaped them, family background, education, or origin story..."
                            ><?= htmlspecialchars($_POST['background'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="occupation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Occupation or Role
                                </label>
                                <input 
                                    type="text" 
                                    id="occupation" 
                                    name="occupation" 
                                    maxlength="200"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                    placeholder="Student, Artist, Scientist, Adventurer..."
                                    value="<?= htmlspecialchars($_POST['occupation'] ?? '') ?>"
                                />
                            </div>
                            
                            <div>
                                <label for="goals" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Goals & Aspirations
                                </label>
                                <input 
                                    type="text" 
                                    id="goals" 
                                    name="goals" 
                                    maxlength="200"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                    placeholder="What drives them? What do they want to achieve?"
                                    value="<?= htmlspecialchars($_POST['goals'] ?? '') ?>"
                                />
                            </div>
                        </div>
                        
                        <div>
                            <label for="interests" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Interests & Hobbies
                            </label>
                            <textarea 
                                id="interests" 
                                name="interests" 
                                rows="3"
                                maxlength="500"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                                placeholder="What they love to do, topics they're passionate about, hobbies, favorite things..."
                            ><?= htmlspecialchars($_POST['interests'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="flex space-x-4 pt-6">
                    <a href="/dashboard" class="flex-1 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold py-3 px-6 rounded-lg text-center hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                        Cancel
                    </a>
                    <button type="submit" class="flex-1 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-3 px-6 rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i class="fas fa-plus mr-2"></i>
                        Create AEI
                    </button>
                </div>
            </form>
        </div>

        <div class="mt-8 space-y-4">
            <div class="text-center">
                <div class="inline-flex items-center space-x-2 text-gray-500 dark:text-gray-400 text-sm bg-blue-50 dark:bg-blue-900/20 px-4 py-2 rounded-lg">
                    <i class="fas fa-lightbulb text-blue-500"></i>
                    <span>The more detailed your character, the more unique and realistic your AEI will be!</span>
                </div>
            </div>
            
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-yellow-800 dark:text-yellow-300 mb-2">
                    <i class="fas fa-magic mr-2"></i>
                    Character Creation Tips
                </h4>
                <ul class="text-sm text-yellow-700 dark:text-yellow-400 space-y-1">
                    <li>• Think of them as a real person with depth, flaws, and unique qualities</li>
                    <li>• Consider how their background influences their personality and worldview</li>
                    <li>• Mix different traits to create complexity (e.g., shy but passionate about their interests)</li>
                    <li>• Give them specific preferences, opinions, and ways of expressing themselves</li>
                    <li>• The system will automatically generate an appropriate prompt based on all these details</li>
                </ul>
            </div>
        </div>
    </div>
</div>