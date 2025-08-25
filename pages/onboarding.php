<?php
requireAuth();

$userId = getUserSession();
if (!$userId) {
    redirectTo('home');
}

// Check if user is already onboarded
try {
    $stmt = $pdo->prepare("SELECT is_onboarded FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if ($user && $user['is_onboarded']) {
        redirectTo('dashboard');
    }
} catch (PDOException $e) {
    error_log("Database error checking onboarding status: " . $e->getMessage());
    redirectTo('dashboard');
}

$step = $_GET['step'] ?? '1';
$maxSteps = 5;

// Validate step
if (!is_numeric($step) || $step < 1 || $step > $maxSteps) {
    $step = 1;
}
$step = (int)$step;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        try {
            $updates = [];
            $params = [$userId];
            
            // Process form data based on step
            if ($step === 1) {
                // Basic Demographics
                $gender = sanitizeInput($_POST['gender'] ?? '');
                $birthDate = $_POST['birth_date'] ?? '';
                $timezone = sanitizeInput($_POST['timezone'] ?? '');
                
                if (empty($gender) || empty($birthDate) || empty($timezone)) {
                    $error = "Please fill in all required fields.";
                } else {
                    // Safety fallback: ensure timezone is valid, default to UTC if somehow empty
                    if (empty($timezone)) {
                        $timezone = 'UTC';
                    }
                    $updates[] = "gender = ?";
                    $updates[] = "birth_date = ?";
                    $updates[] = "timezone = ?";
                    // Add parameters in correct order (SQL field order)
                    // $params currently contains [$userId], we need [$gender, $birthDate, $timezone, $userId]
                    $params = [$gender, $birthDate, $timezone, $userId];
                }
            } elseif ($step === 2) {
                // Professional & Personal
                $profession = sanitizeInput($_POST['profession'] ?? '');
                $hobbies = sanitizeInput($_POST['hobbies'] ?? '');
                
                $updates[] = "profession = ?";
                $updates[] = "hobbies = ?";
                // Add parameters in correct order (SQL field order)
                // $params currently contains [$userId], we need [$profession, $hobbies, $userId]
                $params = [$profession, $hobbies, $userId];
            } elseif ($step === 3) {
                // Lifestyle & Values
                $sexualOrientation = $_POST['sexual_orientation'] ?? '';
                $dailyRituals = sanitizeInput($_POST['daily_rituals'] ?? '');
                $lifeGoals = sanitizeInput($_POST['life_goals'] ?? '');
                $beliefs = sanitizeInput($_POST['beliefs'] ?? '');
                
                // Don't sanitize sexual_orientation as it's a select dropdown with predefined values
                $sexualOrientation = sanitizeInput($sexualOrientation);
                $updates[] = "sexual_orientation = ?";
                $updates[] = "daily_rituals = ?";
                $updates[] = "life_goals = ?";
                $updates[] = "beliefs = ?";
                // Add parameters in correct order (SQL field order) 
                // $params currently contains [$userId], we need [$sexualOrientation, $dailyRituals, $lifeGoals, $beliefs, $userId]
                $params = [$sexualOrientation, $dailyRituals, $lifeGoals, $beliefs, $userId];
            } elseif ($step === 4) {
                // Relationship, Additional & Feedback Contact
                $partnerQualities = sanitizeInput($_POST['partner_qualities'] ?? '');
                $additionalInfo = sanitizeInput($_POST['additional_info'] ?? '');
                $feedbackChannel = sanitizeInput($_POST['feedback_channel'] ?? '');
                $feedbackContact = sanitizeInput($_POST['feedback_contact'] ?? '');
                
                // Validate feedback channel and contact if provided
                if (!empty($feedbackChannel) && empty($feedbackContact)) {
                    $error = "Please provide your contact information for the selected feedback channel.";
                } elseif (!empty($feedbackChannel) && !in_array($feedbackChannel, ['email', 'whatsapp', 'discord', 'x'])) {
                    $error = "Please select a valid feedback channel.";
                } else {
                    $updates[] = "partner_qualities = ?";
                    $updates[] = "additional_info = ?";
                    $updates[] = "feedback_channel = ?";
                    $updates[] = "feedback_contact = ?";
                    // Add parameters in correct order (SQL field order)
                    // $params currently contains [$userId], we need [$partnerQualities, $additionalInfo, $feedbackChannel, $feedbackContact, $userId]
                    $params = [$partnerQualities, $additionalInfo, $feedbackChannel, $feedbackContact, $userId];
                }
            } elseif ($step === 5) {
                // Optional User Appearance
                $userHairColor = sanitizeInput($_POST['user_hair_color'] ?? '');
                $userEyeColor = sanitizeInput($_POST['user_eye_color'] ?? '');
                $userHeight = sanitizeInput($_POST['user_height'] ?? '');
                $userBuild = sanitizeInput($_POST['user_build'] ?? '');
                $userStyle = sanitizeInput($_POST['user_style'] ?? '');
                $userAppearanceCustom = sanitizeInput($_POST['user_appearance_custom'] ?? '');
                
                // All appearance fields are optional, so no validation needed
                $updates[] = "user_hair_color = ?";
                $updates[] = "user_eye_color = ?";
                $updates[] = "user_height = ?";
                $updates[] = "user_build = ?";
                $updates[] = "user_style = ?";
                $updates[] = "user_appearance_custom = ?";
                $updates[] = "is_onboarded = TRUE";
                // Add parameters in correct order (SQL field order)
                // $params currently contains [$userId], we need [$userHairColor, $userEyeColor, $userHeight, $userBuild, $userStyle, $userAppearanceCustom, $userId]
                $params = [$userHairColor, $userEyeColor, $userHeight, $userBuild, $userStyle, $userAppearanceCustom, $userId];
            }
            
            if (!isset($error) && !empty($updates)) {
                $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                // Move to next step or finish
                if ($step < $maxSteps) {
                    header("Location: /onboarding?step=" . ($step + 1));
                    exit;
                } else {
                    // Onboarding complete
                    redirectTo('dashboard');
                }
            }
        } catch (PDOException $e) {
            error_log("Database error during onboarding: " . $e->getMessage());
            $error = "An error occurred. Please try again.";
        }
    }
}

// Get current user data for pre-filling
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userData = $stmt->fetch();
} catch (PDOException $e) {
    $userData = [];
}
?>

<div class="min-h-screen bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 dark:from-ayuni-dark dark:via-blue-900 dark:to-indigo-900 relative overflow-hidden">
    <!-- Subtle Background Pattern -->
    <div class="absolute inset-0 opacity-10">
        <div class="absolute top-20 left-1/4 w-64 h-64 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full filter blur-3xl animate-pulse"></div>
        <div class="absolute bottom-20 right-1/4 w-80 h-80 bg-gradient-to-br from-purple-400 to-pink-400 rounded-full filter blur-3xl animate-pulse animation-delay-2000"></div>
    </div>

    <!-- Unified Header -->
    <div class="relative z-10">
        <div class="max-w-3xl mx-auto px-4 py-12">
            <!-- Logo Section -->
            <div class="text-center mb-10">
                <div class="relative inline-block mb-6">
                    <div class="relative">
                        <img src="/assets/ayuni.png" alt="Ayuni Logo" class="h-20 w-auto dark:hidden">
                        <img src="/assets/ayuni-white.png" alt="Ayuni Logo" class="h-20 w-auto hidden dark:block">
                    </div>
                </div>
                <h1 class="text-3xl font-bold bg-gradient-to-r from-ayuni-aqua to-ayuni-blue bg-clip-text text-transparent mb-4">
                    Tell Us About Yourself
                </h1>
                
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 mb-8">
                    <div class="flex items-start space-x-3">
                        <i class="fas fa-lightbulb text-ayuni-blue text-xl mt-1"></i>
                        <div>
                            <h3 class="font-semibold text-gray-900 dark:text-white mb-2">Why This Information Matters</h3>
                            <p class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed">
                                The more information you provide, the better your AEI can understand you and create authentic, meaningful conversations. 
                                Every detail helps craft a unique personality that truly fits who you are.
                            </p>
                        </div>
                    </div>
                </div>
                
                <!-- Compact Progress -->
                <div class="flex items-center justify-center space-x-4 mb-6">
                    <div class="flex space-x-3">
                        <?php for ($i = 1; $i <= $maxSteps; $i++): ?>
                            <div class="w-10 h-10 sm:w-8 sm:h-8 rounded-full flex items-center justify-center text-sm font-bold transition-all duration-300 <?= $i <= $step ? 'bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white shadow-lg' : 'bg-gray-200 dark:bg-gray-600 text-gray-500 dark:text-gray-400' ?>">
                                <?= $i ?>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="w-full max-w-md mx-auto bg-gray-200 dark:bg-gray-600 rounded-full h-2 mb-4">
                    <div class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue h-2 rounded-full transition-all duration-500" 
                         style="width: <?= ($step / $maxSteps) * 100 ?>%"></div>
                </div>
                
                <p class="text-gray-600 dark:text-gray-300 text-sm">
                    <?php
                    $stepDescriptions = [
                        1 => "Essential details for personalized interactions",
                        2 => "Your interests shape meaningful conversations", 
                        3 => "Values create deeper understanding",
                        4 => "Final touches for perfect compatibility",
                        5 => "Optional appearance details for enhanced interactions"
                    ];
                    echo $stepDescriptions[$step];
                    ?>
                </p>
            </div>

    <div class="max-w-2xl mx-auto px-4 py-8">
        <?php if (isset($error)): ?>
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-3 rounded-lg mb-6">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
            </div>
        <?php endif; ?>

            <!-- Main Form Card -->
            <div class="bg-white/70 dark:bg-gray-800/70 backdrop-blur-sm rounded-2xl border border-white/30 dark:border-gray-600/30 shadow-xl p-8">
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    
                    <?php if ($step === 1): ?>

                    <div>
                        <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Gender *
                        </label>
                        <select 
                            id="gender" 
                            name="gender" 
                            required
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                        >
                            <option value="">Select your gender</option>
                            <option value="male" <?= ($userData['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= ($userData['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
                            <option value="non-binary" <?= ($userData['gender'] ?? '') === 'non-binary' ? 'selected' : '' ?>>Non-binary</option>
                            <option value="prefer-not-to-say" <?= ($userData['gender'] ?? '') === 'prefer-not-to-say' ? 'selected' : '' ?>>Prefer not to say</option>
                            <option value="other" <?= ($userData['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="birth_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Birth Date *
                        </label>
                        <input 
                            type="date" 
                            id="birth_date" 
                            name="birth_date" 
                            required
                            max="<?= date('Y-m-d', strtotime('-13 years')) ?>"
                            min="<?= date('Y-m-d', strtotime('-120 years')) ?>"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                            value="<?= htmlspecialchars($userData['birth_date'] ?? '') ?>"
                        />
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Must be at least 13 years old</p>
                    </div>

                    <div>
                        <label for="timezone_select" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Timezone *
                        </label>
                        <?php renderTimezoneSelect('timezone', $userData['timezone'] ?? '', true, true); ?>
                    </div>

                <?php elseif ($step === 2): ?>

                    <div>
                        <label for="profession" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Profession <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <input 
                            type="text" 
                            id="profession" 
                            name="profession" 
                            maxlength="255"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                            placeholder="e.g., Software Engineer, Teacher, Artist..."
                            value="<?= htmlspecialchars($userData['profession'] ?? '') ?>"
                        />
                    </div>

                    <div>
                        <label for="hobbies" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Hobbies & Interests <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="hobbies" 
                            name="hobbies" 
                            rows="4"
                            maxlength="1000"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="Tell us about your hobbies, interests, favorite activities, sports, music, books, movies..."
                        ><?= htmlspecialchars($userData['hobbies'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">What do you enjoy doing in your free time?</p>
                    </div>

                <?php elseif ($step === 3): ?>

                    <div>
                        <label for="sexual_orientation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Sexual Orientation <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <select 
                            id="sexual_orientation" 
                            name="sexual_orientation" 
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                        >
                            <option value="">Prefer not to specify</option>
                            <option value="straight" <?= ($userData['sexual_orientation'] ?? '') === 'straight' ? 'selected' : '' ?>>Straight</option>
                            <option value="gay" <?= ($userData['sexual_orientation'] ?? '') === 'gay' ? 'selected' : '' ?>>Gay</option>
                            <option value="lesbian" <?= ($userData['sexual_orientation'] ?? '') === 'lesbian' ? 'selected' : '' ?>>Lesbian</option>
                            <option value="bisexual" <?= ($userData['sexual_orientation'] ?? '') === 'bisexual' ? 'selected' : '' ?>>Bisexual</option>
                            <option value="pansexual" <?= ($userData['sexual_orientation'] ?? '') === 'pansexual' ? 'selected' : '' ?>>Pansexual</option>
                            <option value="asexual" <?= ($userData['sexual_orientation'] ?? '') === 'asexual' ? 'selected' : '' ?>>Asexual</option>
                            <option value="questioning" <?= ($userData['sexual_orientation'] ?? '') === 'questioning' ? 'selected' : '' ?>>Questioning</option>
                            <option value="other" <?= ($userData['sexual_orientation'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>
                    </div>

                    <div>
                        <label for="daily_rituals" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Daily Rituals & Routines <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="daily_rituals" 
                            name="daily_rituals" 
                            rows="3"
                            maxlength="1000"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="e.g., Morning coffee routine, evening meditation, weekend rituals..."
                        ><?= htmlspecialchars($userData['daily_rituals'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label for="life_goals" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Life Goals & Aspirations <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="life_goals" 
                            name="life_goals" 
                            rows="3"
                            maxlength="1000"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="What are your dreams, goals, and aspirations for the future?"
                        ><?= htmlspecialchars($userData['life_goals'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label for="beliefs" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Beliefs & Values <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="beliefs" 
                            name="beliefs" 
                            rows="3"
                            maxlength="1000"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="Religious beliefs, spiritual practices, core values, life philosophy..."
                        ><?= htmlspecialchars($userData['beliefs'] ?? '') ?></textarea>
                    </div>

                <?php elseif ($step === 4): ?>

                    <div>
                        <label for="partner_qualities" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Partner Qualities <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="partner_qualities" 
                            name="partner_qualities" 
                            rows="4"
                            maxlength="1000"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="What qualities do you value in a partner? What kind of relationship dynamics do you prefer?"
                        ><?= htmlspecialchars($userData['partner_qualities'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">This helps create more meaningful AEI relationships</p>
                    </div>

                    <div>
                        <label for="additional_info" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Additional Information <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="additional_info" 
                            name="additional_info" 
                            rows="4"
                            maxlength="2000"
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="Anything else you'd like your AEI companions to know about you? Personality traits, communication style, preferences..."
                        ><?= htmlspecialchars($userData['additional_info'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">Share anything that makes you unique!</p>
                    </div>

                    <!-- Feedback Contact Preference -->
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                            <i class="fas fa-comment-dots text-ayuni-blue mr-2"></i>
                            Feedback & Communication
                        </h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="feedback_channel" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    How would you prefer us to contact you for feedback? <span class="text-gray-500 text-xs">(Optional)</span>
                                </label>
                                <select 
                                    id="feedback_channel" 
                                    name="feedback_channel"
                                    class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                    onchange="toggleContactField(this.value)"
                                >
                                    <option value="">No preference / Don't contact me</option>
                                    <option value="email" <?= ($userData['feedback_channel'] ?? '') === 'email' ? 'selected' : '' ?>>📧 Email</option>
                                    <option value="whatsapp" <?= ($userData['feedback_channel'] ?? '') === 'whatsapp' ? 'selected' : '' ?>>📱 WhatsApp</option>
                                    <option value="discord" <?= ($userData['feedback_channel'] ?? '') === 'discord' ? 'selected' : '' ?>>🎮 Discord</option>
                                    <option value="x" <?= ($userData['feedback_channel'] ?? '') === 'x' ? 'selected' : '' ?>>🐦 X (Twitter)</option>
                                </select>
                            </div>

                            <div id="contact_field" style="display: <?= !empty($userData['feedback_channel'] ?? '') ? 'block' : 'none' ?>;">
                                <label for="feedback_contact" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                    <span id="contact_label">Contact Information</span> <span class="text-red-500">*</span>
                                </label>
                                <input 
                                    type="text" 
                                    id="feedback_contact" 
                                    name="feedback_contact" 
                                    maxlength="255"
                                    value="<?= htmlspecialchars($userData['feedback_contact'] ?? '') ?>"
                                    class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                                    placeholder="Enter your contact information"
                                >
                                <p id="contact_help" class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                    We'll only use this to reach out for important feedback or product updates.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3"></i>
                            <div>
                                <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-1">Ready to Meet Your AEI!</h4>
                                <p class="text-sm text-blue-700 dark:text-blue-400">
                                    Your profile information will help create more personalized and meaningful interactions with your AI companions.
                                    You can always update this information later in your profile settings.
                                </p>
                            </div>
                        </div>
                    </div>

                <?php else: // Step 5 - Optional User Appearance ?>

                    <div class="text-center mb-6">
                        <div class="w-16 h-16 bg-gradient-to-r from-purple-500 to-pink-500 rounded-full mx-auto mb-4 flex items-center justify-center">
                            <i class="fas fa-user text-2xl text-white"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Your Appearance</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm">
                            All fields are optional. This helps your AEI understand and relate to you better during conversations.
                        </p>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Hair Color <span class="text-gray-500 text-xs">(Optional)</span></label>
                            <select name="user_hair_color" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select hair color...</option>
                                <option value="Black" <?= ($userData['user_hair_color'] ?? '') === 'Black' ? 'selected' : '' ?>>Black</option>
                                <option value="Brown" <?= ($userData['user_hair_color'] ?? '') === 'Brown' ? 'selected' : '' ?>>Brown</option>
                                <option value="Blonde" <?= ($userData['user_hair_color'] ?? '') === 'Blonde' ? 'selected' : '' ?>>Blonde</option>
                                <option value="Red" <?= ($userData['user_hair_color'] ?? '') === 'Red' ? 'selected' : '' ?>>Red</option>
                                <option value="Auburn" <?= ($userData['user_hair_color'] ?? '') === 'Auburn' ? 'selected' : '' ?>>Auburn</option>
                                <option value="Silver" <?= ($userData['user_hair_color'] ?? '') === 'Silver' ? 'selected' : '' ?>>Silver</option>
                                <option value="White" <?= ($userData['user_hair_color'] ?? '') === 'White' ? 'selected' : '' ?>>White</option>
                                <option value="Colorful" <?= ($userData['user_hair_color'] ?? '') === 'Colorful' ? 'selected' : '' ?>>Colorful/Dyed</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Eye Color <span class="text-gray-500 text-xs">(Optional)</span></label>
                            <select name="user_eye_color" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select eye color...</option>
                                <option value="Brown" <?= ($userData['user_eye_color'] ?? '') === 'Brown' ? 'selected' : '' ?>>Brown</option>
                                <option value="Blue" <?= ($userData['user_eye_color'] ?? '') === 'Blue' ? 'selected' : '' ?>>Blue</option>
                                <option value="Green" <?= ($userData['user_eye_color'] ?? '') === 'Green' ? 'selected' : '' ?>>Green</option>
                                <option value="Hazel" <?= ($userData['user_eye_color'] ?? '') === 'Hazel' ? 'selected' : '' ?>>Hazel</option>
                                <option value="Gray" <?= ($userData['user_eye_color'] ?? '') === 'Gray' ? 'selected' : '' ?>>Gray</option>
                                <option value="Amber" <?= ($userData['user_eye_color'] ?? '') === 'Amber' ? 'selected' : '' ?>>Amber</option>
                                <option value="Violet" <?= ($userData['user_eye_color'] ?? '') === 'Violet' ? 'selected' : '' ?>>Violet</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Height <span class="text-gray-500 text-xs">(Optional)</span></label>
                            <select name="user_height" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select height...</option>
                                <option value="Short" <?= ($userData['user_height'] ?? '') === 'Short' ? 'selected' : '' ?>>Short (under 5'4")</option>
                                <option value="Average" <?= ($userData['user_height'] ?? '') === 'Average' ? 'selected' : '' ?>>Average (5'4" - 5'9")</option>
                                <option value="Tall" <?= ($userData['user_height'] ?? '') === 'Tall' ? 'selected' : '' ?>>Tall (over 5'9")</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Build <span class="text-gray-500 text-xs">(Optional)</span></label>
                            <select name="user_build" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select build...</option>
                                <option value="Slim" <?= ($userData['user_build'] ?? '') === 'Slim' ? 'selected' : '' ?>>Slim</option>
                                <option value="Average" <?= ($userData['user_build'] ?? '') === 'Average' ? 'selected' : '' ?>>Average</option>
                                <option value="Athletic" <?= ($userData['user_build'] ?? '') === 'Athletic' ? 'selected' : '' ?>>Athletic</option>
                                <option value="Curvy" <?= ($userData['user_build'] ?? '') === 'Curvy' ? 'selected' : '' ?>>Curvy</option>
                                <option value="Muscular" <?= ($userData['user_build'] ?? '') === 'Muscular' ? 'selected' : '' ?>>Muscular</option>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Style <span class="text-gray-500 text-xs">(Optional)</span></label>
                            <select name="user_style" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue">
                                <option value="">Select style...</option>
                                <option value="Casual" <?= ($userData['user_style'] ?? '') === 'Casual' ? 'selected' : '' ?>>Casual</option>
                                <option value="Elegant" <?= ($userData['user_style'] ?? '') === 'Elegant' ? 'selected' : '' ?>>Elegant</option>
                                <option value="Sporty" <?= ($userData['user_style'] ?? '') === 'Sporty' ? 'selected' : '' ?>>Sporty</option>
                                <option value="Gothic" <?= ($userData['user_style'] ?? '') === 'Gothic' ? 'selected' : '' ?>>Gothic</option>
                                <option value="Vintage" <?= ($userData['user_style'] ?? '') === 'Vintage' ? 'selected' : '' ?>>Vintage</option>
                                <option value="Modern" <?= ($userData['user_style'] ?? '') === 'Modern' ? 'selected' : '' ?>>Modern</option>
                                <option value="Bohemian" <?= ($userData['user_style'] ?? '') === 'Bohemian' ? 'selected' : '' ?>>Bohemian</option>
                                <option value="Professional" <?= ($userData['user_style'] ?? '') === 'Professional' ? 'selected' : '' ?>>Professional</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="mt-6">
                        <label for="user_appearance_custom" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Additional Details <span class="text-gray-500 text-xs">(Optional)</span>
                        </label>
                        <textarea 
                            id="user_appearance_custom" 
                            name="user_appearance_custom" 
                            rows="3"
                            maxlength="500"
                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all resize-none"
                            placeholder="Any other distinctive features, accessories, or appearance details you'd like to share..."
                        ><?= htmlspecialchars($userData['user_appearance_custom'] ?? '') ?></textarea>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">e.g., glasses, tattoos, beard, unique accessories, etc.</p>
                    </div>

                    <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg p-4 mt-6">
                        <div class="flex items-start">
                            <i class="fas fa-magic text-purple-600 dark:text-purple-400 mt-1 mr-3"></i>
                            <div>
                                <h4 class="text-sm font-semibold text-purple-800 dark:text-purple-300 mb-1">Profile Complete!</h4>
                                <p class="text-sm text-purple-700 dark:text-purple-400">
                                    Your appearance details will help your AEI companions understand and relate to you better. 
                                    You can skip this step or update these details anytime in your profile settings.
                                </p>
                            </div>
                        </div>
                    </div>

                <?php endif; ?>

                    <!-- Navigation Buttons -->
                    <div class="flex justify-between pt-8">
                        <?php if ($step > 1): ?>
                            <a href="/onboarding?step=<?= $step - 1 ?>" class="flex items-center px-6 py-3 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-medium rounded-lg hover:bg-gray-200 dark:hover:bg-gray-500 transition-all duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <button 
                            type="submit" 
                            class="flex items-center px-8 py-3 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-lg hover:shadow-xl"
                        >
                            <?php if ($step < $maxSteps): ?>
                                Continue
                                <i class="fas fa-arrow-right ml-2"></i>
                            <?php else: ?>
                                <i class="fas fa-check mr-2"></i>
                                Complete Profile
                            <?php endif; ?>
                        </button>
                    </div>
            </form>
        </div>

        </div>
    </div>
</div>

<script>
// Dynamic feedback contact field
function toggleContactField(channel) {
    const contactField = document.getElementById('contact_field');
    const contactLabel = document.getElementById('contact_label');
    const contactInput = document.getElementById('feedback_contact');
    const contactHelp = document.getElementById('contact_help');
    
    if (channel) {
        contactField.style.display = 'block';
        
        // Update label and placeholder based on channel
        switch(channel) {
            case 'email':
                contactLabel.textContent = 'Email Address';
                contactInput.placeholder = 'your.email@example.com';
                contactInput.type = 'email';
                contactHelp.textContent = 'We\'ll send you occasional feedback requests and product updates.';
                break;
            case 'whatsapp':
                contactLabel.textContent = 'WhatsApp Phone Number';
                contactInput.placeholder = '+1234567890';
                contactInput.type = 'tel';
                contactHelp.textContent = 'Please include country code (e.g., +1 for US, +49 for Germany).';
                break;
            case 'discord':
                contactLabel.textContent = 'Discord Username';
                contactInput.placeholder = 'username#1234 or @username';
                contactInput.type = 'text';
                contactHelp.textContent = 'Your Discord username (with or without discriminator).';
                break;
            case 'x':
                contactLabel.textContent = 'X (Twitter) Handle';
                contactInput.placeholder = '@yourusername';
                contactInput.type = 'text';
                contactHelp.textContent = 'Your X/Twitter handle (with or without @).';
                break;
        }
    } else {
        contactField.style.display = 'none';
        contactInput.value = '';
    }
}

// Initialize field on page load if channel is already selected
document.addEventListener('DOMContentLoaded', function() {
    const channelSelect = document.getElementById('feedback_channel');
    if (channelSelect && channelSelect.value) {
        toggleContactField(channelSelect.value);
    }
});
</script>