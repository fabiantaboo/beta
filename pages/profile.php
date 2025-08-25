<?php
requireOnboarding();

$error = null;
$success = null;

// Get current user data
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([getUserSession()]);
    $user = $stmt->fetch();
    
    if (!$user) {
        redirectTo('home');
    }
} catch (PDOException $e) {
    error_log("Error fetching user data: " . $e->getMessage());
    redirectTo('dashboard');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        // Sanitize and validate input
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $gender = sanitizeInput($_POST['gender'] ?? '');
        $birthDate = $_POST['birth_date'] ?? '';
        $profession = sanitizeInput($_POST['profession'] ?? '');
        $hobbies = sanitizeInput($_POST['hobbies'] ?? '');
        $sexualOrientation = sanitizeInput($_POST['sexual_orientation'] ?? '');
        $dailyRituals = sanitizeInput($_POST['daily_rituals'] ?? '');
        $lifeGoals = sanitizeInput($_POST['life_goals'] ?? '');
        $beliefs = sanitizeInput($_POST['beliefs'] ?? '');
        $partnerQualities = sanitizeInput($_POST['partner_qualities'] ?? '');
        $additionalInfo = sanitizeInput($_POST['additional_info'] ?? '');
        $timezone = sanitizeInput($_POST['timezone'] ?? 'UTC');
        $preferredLanguage = sanitizeInput($_POST['preferred_language'] ?? 'en');
        
        // User appearance fields
        $userHairColor = sanitizeInput($_POST['user_hair_color'] ?? '');
        $userEyeColor = sanitizeInput($_POST['user_eye_color'] ?? '');
        $userHeight = sanitizeInput($_POST['user_height'] ?? '');
        $userBuild = sanitizeInput($_POST['user_build'] ?? '');
        $userStyle = sanitizeInput($_POST['user_style'] ?? '');
        $userAppearanceCustom = sanitizeInput($_POST['user_appearance_custom'] ?? '');
        
        // Validate required fields
        if (empty($firstName)) {
            $error = "First name is required.";
        } elseif (!empty($birthDate) && !strtotime($birthDate)) {
            $error = "Please enter a valid birth date.";
        } else {
            try {
                // Update user data
                $stmt = $pdo->prepare("
                    UPDATE users SET 
                        first_name = ?, 
                        gender = ?, 
                        birth_date = ?, 
                        profession = ?, 
                        hobbies = ?, 
                        sexual_orientation = ?, 
                        daily_rituals = ?, 
                        life_goals = ?, 
                        beliefs = ?, 
                        partner_qualities = ?, 
                        additional_info = ?, 
                        timezone = ?,
                        preferred_language = ?,
                        user_hair_color = ?,
                        user_eye_color = ?,
                        user_height = ?,
                        user_build = ?,
                        user_style = ?,
                        user_appearance_custom = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([
                    $firstName,
                    $gender,
                    $birthDate ?: null,
                    $profession,
                    $hobbies,
                    $sexualOrientation,
                    $dailyRituals,
                    $lifeGoals,
                    $beliefs,
                    $partnerQualities,
                    $additionalInfo,
                    $timezone,
                    $preferredLanguage,
                    $userHairColor,
                    $userEyeColor,
                    $userHeight,
                    $userBuild,
                    $userStyle,
                    $userAppearanceCustom,
                    getUserSession()
                ]);
                
                $success = "Profile updated successfully!";
                
                // Refresh user data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([getUserSession()]);
                $user = $stmt->fetch();
                
            } catch (PDOException $e) {
                error_log("Error updating profile: " . $e->getMessage());
                $error = "Failed to update profile. Please try again.";
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

    <div class="max-w-4xl mx-auto px-3 sm:px-6 lg:px-8 py-4 sm:py-8">
        <!-- Header -->
        <div class="text-center mb-6 sm:mb-8">
            <div class="w-16 h-16 sm:w-20 sm:h-20 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-3 sm:mb-4 flex items-center justify-center">
                <span class="text-2xl sm:text-3xl text-white font-bold">
                    <?= strtoupper(substr($user['first_name'], 0, 1)) ?>
                </span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 dark:text-white px-4 sm:px-0">Profile Settings</h1>
            <p class="mt-2 text-sm sm:text-base text-gray-600 dark:text-gray-400 px-4 sm:px-0">Manage your personal information and preferences</p>
        </div>

        <!-- Success/Error Messages -->
        <?php if ($success): ?>
            <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-md p-4">
                <div class="flex">
                    <i class="fas fa-check-circle text-green-400 mr-2 mt-0.5"></i>
                    <p class="text-sm text-green-700 dark:text-green-400"><?= htmlspecialchars($success) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-md p-4">
                <div class="flex">
                    <i class="fas fa-exclamation-circle text-red-400 mr-2 mt-0.5"></i>
                    <p class="text-sm text-red-700 dark:text-red-400"><?= htmlspecialchars($error) ?></p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Profile Form -->
        <div class="bg-white dark:bg-gray-800 shadow rounded-lg">
            <form method="POST" class="p-4 sm:p-6 space-y-4 sm:space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <!-- Basic Information -->
                <div>
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-user text-ayuni-blue mr-2"></i>
                        Basic Information
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- First Name -->
                        <div>
                            <label for="first_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                First Name *
                            </label>
                            <input 
                                type="text" 
                                id="first_name" 
                                name="first_name" 
                                required
                                maxlength="100"
                                value="<?= htmlspecialchars($user['first_name']) ?>"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm"
                                placeholder="Enter your first name"
                            >
                        </div>

                        <!-- Email (Read-only) -->
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Email Address
                            </label>
                            <input 
                                type="email" 
                                id="email" 
                                value="<?= htmlspecialchars($user['email']) ?>"
                                readonly
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-gray-50 dark:bg-gray-600 text-gray-500 dark:text-gray-400 cursor-not-allowed text-base sm:text-sm"
                            >
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Email cannot be changed</p>
                        </div>

                        <!-- Gender -->
                        <div>
                            <label for="gender" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Gender
                            </label>
                            <select 
                                id="gender" 
                                name="gender"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Select gender</option>
                                <option value="male" <?= $user['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                                <option value="female" <?= $user['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                                <option value="non-binary" <?= $user['gender'] === 'non-binary' ? 'selected' : '' ?>>Non-binary</option>
                                <option value="other" <?= $user['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                                <option value="prefer-not-to-say" <?= $user['gender'] === 'prefer-not-to-say' ? 'selected' : '' ?>>Prefer not to say</option>
                            </select>
                        </div>

                        <!-- Birth Date -->
                        <div>
                            <label for="birth_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Birth Date
                            </label>
                            <input 
                                type="date" 
                                id="birth_date" 
                                name="birth_date"
                                value="<?= $user['birth_date'] ?>"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                        </div>
                    </div>
                </div>

                <!-- Personal Details -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-id-card text-ayuni-blue mr-2"></i>
                        Personal Details
                    </h2>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Profession -->
                        <div>
                            <label for="profession" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Profession
                            </label>
                            <input 
                                type="text" 
                                id="profession" 
                                name="profession"
                                maxlength="255"
                                value="<?= htmlspecialchars($user['profession'] ?? '') ?>"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm"
                                placeholder="e.g., Software Engineer, Teacher, Artist"
                            >
                        </div>

                        <!-- Sexual Orientation -->
                        <div>
                            <label for="sexual_orientation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Sexual Orientation
                            </label>
                            <select 
                                id="sexual_orientation" 
                                name="sexual_orientation"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Prefer not to specify</option>
                                <option value="straight" <?= $user['sexual_orientation'] === 'straight' ? 'selected' : '' ?>>Straight</option>
                                <option value="gay" <?= $user['sexual_orientation'] === 'gay' ? 'selected' : '' ?>>Gay</option>
                                <option value="lesbian" <?= $user['sexual_orientation'] === 'lesbian' ? 'selected' : '' ?>>Lesbian</option>
                                <option value="bisexual" <?= $user['sexual_orientation'] === 'bisexual' ? 'selected' : '' ?>>Bisexual</option>
                                <option value="pansexual" <?= $user['sexual_orientation'] === 'pansexual' ? 'selected' : '' ?>>Pansexual</option>
                                <option value="asexual" <?= $user['sexual_orientation'] === 'asexual' ? 'selected' : '' ?>>Asexual</option>
                                <option value="questioning" <?= $user['sexual_orientation'] === 'questioning' ? 'selected' : '' ?>>Questioning</option>
                                <option value="other" <?= $user['sexual_orientation'] === 'other' ? 'selected' : '' ?>>Other</option>
                            </select>
                        </div>

                        <!-- Timezone -->
                        <div>
                            <label for="timezone_select" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Timezone
                            </label>
                            <?php renderTimezoneSelect('timezone', $user['timezone'] ?? 'UTC', false, true); ?>
                        </div>
                        
                        <!-- Preferred Language -->
                        <div>
                            <label for="preferred_language" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Preferred Language
                            </label>
                            <select 
                                id="preferred_language" 
                                name="preferred_language" 
                                class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent"
                            >
                                <option value="en" <?= ($user['preferred_language'] ?? 'en') === 'en' ? 'selected' : '' ?>>🇺🇸 English</option>
                                <option value="de" <?= ($user['preferred_language'] ?? '') === 'de' ? 'selected' : '' ?>>🇩🇪 Deutsch</option>
                                <option value="es" <?= ($user['preferred_language'] ?? '') === 'es' ? 'selected' : '' ?>>🇪🇸 Español</option>
                                <option value="fr" <?= ($user['preferred_language'] ?? '') === 'fr' ? 'selected' : '' ?>>🇫🇷 Français</option>
                                <option value="it" <?= ($user['preferred_language'] ?? '') === 'it' ? 'selected' : '' ?>>🇮🇹 Italiano</option>
                                <option value="pt" <?= ($user['preferred_language'] ?? '') === 'pt' ? 'selected' : '' ?>>🇵🇹 Português</option>
                                <option value="ru" <?= ($user['preferred_language'] ?? '') === 'ru' ? 'selected' : '' ?>>🇷🇺 Русский</option>
                                <option value="ja" <?= ($user['preferred_language'] ?? '') === 'ja' ? 'selected' : '' ?>>🇯🇵 日本語</option>
                                <option value="ko" <?= ($user['preferred_language'] ?? '') === 'ko' ? 'selected' : '' ?>>🇰🇷 한국어</option>
                                <option value="zh" <?= ($user['preferred_language'] ?? '') === 'zh' ? 'selected' : '' ?>>🇨🇳 中文</option>
                            </select>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Language your AEI will use in conversations</p>
                        </div>
                    </div>
                </div>

                <!-- Interests & Lifestyle -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-heart text-ayuni-blue mr-2"></i>
                        Interests & Lifestyle
                    </h2>
                    
                    <div class="space-y-6">
                        <!-- Hobbies -->
                        <div>
                            <label for="hobbies" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Hobbies & Interests
                            </label>
                            <textarea 
                                id="hobbies" 
                                name="hobbies" 
                                rows="2"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Tell us about your hobbies and interests..."
                            ><?= htmlspecialchars($user['hobbies'] ?? '') ?></textarea>
                        </div>

                        <!-- Daily Rituals -->
                        <div>
                            <label for="daily_rituals" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Daily Rituals & Routines
                            </label>
                            <textarea 
                                id="daily_rituals" 
                                name="daily_rituals" 
                                rows="2"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Describe your daily routines and rituals..."
                            ><?= htmlspecialchars($user['daily_rituals'] ?? '') ?></textarea>
                        </div>

                        <!-- Life Goals -->
                        <div>
                            <label for="life_goals" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Life Goals & Aspirations
                            </label>
                            <textarea 
                                id="life_goals" 
                                name="life_goals" 
                                rows="2"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Share your life goals and aspirations..."
                            ><?= htmlspecialchars($user['life_goals'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Beliefs & Values -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-lightbulb text-ayuni-blue mr-2"></i>
                        Beliefs & Values
                    </h2>
                    
                    <div class="space-y-6">
                        <!-- Beliefs -->
                        <div>
                            <label for="beliefs" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Personal Beliefs & Values
                            </label>
                            <textarea 
                                id="beliefs" 
                                name="beliefs" 
                                rows="2"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Share your beliefs and values..."
                            ><?= htmlspecialchars($user['beliefs'] ?? '') ?></textarea>
                        </div>

                        <!-- Partner Qualities -->
                        <div>
                            <label for="partner_qualities" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Ideal Partner Qualities
                            </label>
                            <textarea 
                                id="partner_qualities" 
                                name="partner_qualities" 
                                rows="2"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Describe qualities you value in a partner..."
                            ><?= htmlspecialchars($user['partner_qualities'] ?? '') ?></textarea>
                        </div>

                        <!-- Additional Information -->
                        <div>
                            <label for="additional_info" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Additional Information
                            </label>
                            <textarea 
                                id="additional_info" 
                                name="additional_info" 
                                rows="3"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Any additional information you'd like to share..."
                            ><?= htmlspecialchars($user['additional_info'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- User Appearance -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                    <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                        <i class="fas fa-user text-purple-600 mr-2"></i>
                        Your Appearance <span class="text-sm font-normal text-gray-500 dark:text-gray-400 ml-2">(Optional)</span>
                    </h2>
                    
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">
                        Help your AEI companions understand and relate to you better by sharing your appearance details.
                    </p>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Hair Color -->
                        <div>
                            <label for="user_hair_color" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Hair Color
                            </label>
                            <select 
                                id="user_hair_color" 
                                name="user_hair_color"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Select hair color...</option>
                                <option value="Black" <?= ($user['user_hair_color'] ?? '') === 'Black' ? 'selected' : '' ?>>Black</option>
                                <option value="Brown" <?= ($user['user_hair_color'] ?? '') === 'Brown' ? 'selected' : '' ?>>Brown</option>
                                <option value="Blonde" <?= ($user['user_hair_color'] ?? '') === 'Blonde' ? 'selected' : '' ?>>Blonde</option>
                                <option value="Red" <?= ($user['user_hair_color'] ?? '') === 'Red' ? 'selected' : '' ?>>Red</option>
                                <option value="Auburn" <?= ($user['user_hair_color'] ?? '') === 'Auburn' ? 'selected' : '' ?>>Auburn</option>
                                <option value="Silver" <?= ($user['user_hair_color'] ?? '') === 'Silver' ? 'selected' : '' ?>>Silver</option>
                                <option value="White" <?= ($user['user_hair_color'] ?? '') === 'White' ? 'selected' : '' ?>>White</option>
                                <option value="Colorful" <?= ($user['user_hair_color'] ?? '') === 'Colorful' ? 'selected' : '' ?>>Colorful/Dyed</option>
                            </select>
                        </div>

                        <!-- Eye Color -->
                        <div>
                            <label for="user_eye_color" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Eye Color
                            </label>
                            <select 
                                id="user_eye_color" 
                                name="user_eye_color"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Select eye color...</option>
                                <option value="Brown" <?= ($user['user_eye_color'] ?? '') === 'Brown' ? 'selected' : '' ?>>Brown</option>
                                <option value="Blue" <?= ($user['user_eye_color'] ?? '') === 'Blue' ? 'selected' : '' ?>>Blue</option>
                                <option value="Green" <?= ($user['user_eye_color'] ?? '') === 'Green' ? 'selected' : '' ?>>Green</option>
                                <option value="Hazel" <?= ($user['user_eye_color'] ?? '') === 'Hazel' ? 'selected' : '' ?>>Hazel</option>
                                <option value="Gray" <?= ($user['user_eye_color'] ?? '') === 'Gray' ? 'selected' : '' ?>>Gray</option>
                                <option value="Amber" <?= ($user['user_eye_color'] ?? '') === 'Amber' ? 'selected' : '' ?>>Amber</option>
                                <option value="Violet" <?= ($user['user_eye_color'] ?? '') === 'Violet' ? 'selected' : '' ?>>Violet</option>
                            </select>
                        </div>

                        <!-- Height -->
                        <div>
                            <label for="user_height" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Height
                            </label>
                            <select 
                                id="user_height" 
                                name="user_height"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Select height...</option>
                                <option value="Short" <?= ($user['user_height'] ?? '') === 'Short' ? 'selected' : '' ?>>Short (under 5'4")</option>
                                <option value="Average" <?= ($user['user_height'] ?? '') === 'Average' ? 'selected' : '' ?>>Average (5'4" - 5'9")</option>
                                <option value="Tall" <?= ($user['user_height'] ?? '') === 'Tall' ? 'selected' : '' ?>>Tall (over 5'9")</option>
                            </select>
                        </div>

                        <!-- Build -->
                        <div>
                            <label for="user_build" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Build
                            </label>
                            <select 
                                id="user_build" 
                                name="user_build"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Select build...</option>
                                <option value="Slim" <?= ($user['user_build'] ?? '') === 'Slim' ? 'selected' : '' ?>>Slim</option>
                                <option value="Average" <?= ($user['user_build'] ?? '') === 'Average' ? 'selected' : '' ?>>Average</option>
                                <option value="Athletic" <?= ($user['user_build'] ?? '') === 'Athletic' ? 'selected' : '' ?>>Athletic</option>
                                <option value="Curvy" <?= ($user['user_build'] ?? '') === 'Curvy' ? 'selected' : '' ?>>Curvy</option>
                                <option value="Muscular" <?= ($user['user_build'] ?? '') === 'Muscular' ? 'selected' : '' ?>>Muscular</option>
                            </select>
                        </div>

                        <!-- Style -->
                        <div class="md:col-span-2">
                            <label for="user_style" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Style
                            </label>
                            <select 
                                id="user_style" 
                                name="user_style"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent text-base sm:text-sm min-h-[44px]"
                            >
                                <option value="">Select style...</option>
                                <option value="Casual" <?= ($user['user_style'] ?? '') === 'Casual' ? 'selected' : '' ?>>Casual</option>
                                <option value="Elegant" <?= ($user['user_style'] ?? '') === 'Elegant' ? 'selected' : '' ?>>Elegant</option>
                                <option value="Sporty" <?= ($user['user_style'] ?? '') === 'Sporty' ? 'selected' : '' ?>>Sporty</option>
                                <option value="Gothic" <?= ($user['user_style'] ?? '') === 'Gothic' ? 'selected' : '' ?>>Gothic</option>
                                <option value="Vintage" <?= ($user['user_style'] ?? '') === 'Vintage' ? 'selected' : '' ?>>Vintage</option>
                                <option value="Modern" <?= ($user['user_style'] ?? '') === 'Modern' ? 'selected' : '' ?>>Modern</option>
                                <option value="Bohemian" <?= ($user['user_style'] ?? '') === 'Bohemian' ? 'selected' : '' ?>>Bohemian</option>
                                <option value="Professional" <?= ($user['user_style'] ?? '') === 'Professional' ? 'selected' : '' ?>>Professional</option>
                            </select>
                        </div>

                        <!-- Additional Details -->
                        <div class="md:col-span-2">
                            <label for="user_appearance_custom" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Additional Details
                            </label>
                            <textarea 
                                id="user_appearance_custom" 
                                name="user_appearance_custom" 
                                rows="3"
                                maxlength="500"
                                class="w-full px-3 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent resize-none text-base sm:text-sm"
                                placeholder="Any other distinctive features, accessories, or appearance details you'd like to share..."
                            ><?= htmlspecialchars($user['user_appearance_custom'] ?? '') ?></textarea>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">e.g., glasses, tattoos, beard, unique accessories, etc.</p>
                        </div>
                    </div>

                    <div class="bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg p-4 mt-6">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-purple-600 dark:text-purple-400 mt-0.5 mr-3"></i>
                            <div>
                                <h4 class="text-sm font-semibold text-purple-800 dark:text-purple-300 mb-1">Enhanced Interactions</h4>
                                <p class="text-sm text-purple-700 dark:text-purple-400">
                                    Your appearance details help AEI companions create more personalized and relatable conversations. All fields are optional and can be updated anytime.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4 sm:pt-6">
                    <div class="flex flex-col sm:flex-row justify-end space-y-3 sm:space-y-0 sm:space-x-4">
                        <a 
                            href="/dashboard" 
                            class="px-6 py-3 sm:py-2 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent text-center text-base sm:text-sm font-medium min-h-[44px] flex items-center justify-center"
                        >
                            Cancel
                        </a>
                        <button 
                            type="submit"
                            class="px-6 py-3 sm:py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-md hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:ring-offset-2 shadow-sm hover:shadow-md text-base sm:text-sm min-h-[44px] flex items-center justify-center"
                        >
                            <i class="fas fa-save mr-2"></i>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Account Information -->
        <div class="mt-6 sm:mt-8 bg-white dark:bg-gray-800 shadow rounded-lg p-4 sm:p-6">
            <h2 class="text-lg font-medium text-gray-900 dark:text-white mb-4 flex items-center">
                <i class="fas fa-info-circle text-ayuni-blue mr-2"></i>
                Account Information
            </h2>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 sm:gap-6 text-sm">
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Member since:</span>
                    <span class="ml-2 text-gray-900 dark:text-white font-medium">
                        <?= date('F j, Y', strtotime($user['created_at'])) ?>
                    </span>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Last active:</span>
                    <span class="ml-2 text-gray-900 dark:text-white font-medium">
                        <?= date('F j, Y g:i A', strtotime($user['last_active'])) ?>
                    </span>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Beta code:</span>
                    <span class="ml-2 text-gray-900 dark:text-white font-medium">
                        <?= $user['beta_code'] ? htmlspecialchars($user['beta_code']) : 'N/A' ?>
                    </span>
                </div>
                <div>
                    <span class="text-gray-500 dark:text-gray-400">Account status:</span>
                    <span class="ml-2">
                        <?php if ($user['is_admin']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 dark:bg-purple-900/20 text-purple-800 dark:text-purple-300">
                                <i class="fas fa-crown mr-1"></i>Administrator
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/20 text-green-800 dark:text-green-300">
                                <i class="fas fa-check mr-1"></i>Active User
                            </span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Auto-resize textareas with mobile optimization
document.addEventListener('DOMContentLoaded', function() {
    const textareas = document.querySelectorAll('textarea');
    const isMobile = window.innerWidth < 640; // sm breakpoint
    const maxHeight = isMobile ? 120 : 200; // Smaller max height on mobile
    
    textareas.forEach(textarea => {
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, maxHeight) + 'px';
        });
        
        // Initial resize
        textarea.style.height = 'auto';
        textarea.style.height = Math.min(textarea.scrollHeight, maxHeight) + 'px';
    });
    
    // Re-calculate on window resize
    window.addEventListener('resize', function() {
        const newIsMobile = window.innerWidth < 640;
        const newMaxHeight = newIsMobile ? 120 : 200;
        textareas.forEach(textarea => {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, newMaxHeight) + 'px';
        });
    });
});
</script>