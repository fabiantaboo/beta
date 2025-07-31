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
$maxSteps = 4;

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
                $timezone = sanitizeInput($_POST['timezone'] ?? 'UTC');
                
                if (empty($gender) || empty($birthDate) || empty($timezone)) {
                    $error = "Please fill in all required fields.";
                } else {
                    $updates[] = "gender = ?";
                    $updates[] = "birth_date = ?";
                    $updates[] = "timezone = ?";
                    array_unshift($params, $timezone, $birthDate, $gender);
                }
            } elseif ($step === 2) {
                // Professional & Personal
                $profession = sanitizeInput($_POST['profession'] ?? '');
                $hobbies = sanitizeInput($_POST['hobbies'] ?? '');
                
                $updates[] = "profession = ?";
                $updates[] = "hobbies = ?";
                array_unshift($params, $hobbies, $profession);
            } elseif ($step === 3) {
                // Lifestyle & Values
                $sexualOrientation = sanitizeInput($_POST['sexual_orientation'] ?? '');
                $dailyRituals = sanitizeInput($_POST['daily_rituals'] ?? '');
                $lifeGoals = sanitizeInput($_POST['life_goals'] ?? '');
                $beliefs = sanitizeInput($_POST['beliefs'] ?? '');
                
                $updates[] = "sexual_orientation = ?";
                $updates[] = "daily_rituals = ?";
                $updates[] = "life_goals = ?";
                $updates[] = "beliefs = ?";
                array_unshift($params, $beliefs, $lifeGoals, $dailyRituals, $sexualOrientation);
            } elseif ($step === 4) {
                // Relationship & Additional
                $partnerQualities = sanitizeInput($_POST['partner_qualities'] ?? '');
                $additionalInfo = sanitizeInput($_POST['additional_info'] ?? '');
                
                $updates[] = "partner_qualities = ?";
                $updates[] = "additional_info = ?";
                $updates[] = "is_onboarded = TRUE";
                array_unshift($params, $additionalInfo, $partnerQualities);
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

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <!-- Progress Bar -->
    <div class="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <div class="max-w-2xl mx-auto px-4 py-6">
            <div class="flex items-center justify-between mb-4">
                <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Complete Your Profile</h1>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    Step <?= $step ?> of <?= $maxSteps ?>
                </div>
            </div>
            
            <!-- Progress bar -->
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                <div class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue h-2 rounded-full transition-all duration-300" 
                     style="width: <?= ($step / $maxSteps) * 100 ?>%"></div>
            </div>
            
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">
                <i class="fas fa-lightbulb mr-2"></i>
                The more information you provide, the better your AEI companions will understand and connect with you
            </p>
        </div>
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

        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-lg p-8">
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                
                <?php if ($step === 1): ?>
                    <!-- Step 1: Basic Demographics -->
                    <div class="text-center mb-8">
                        <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-4 flex items-center justify-center">
                            <i class="fas fa-user text-2xl text-white"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Tell Us About Yourself</h2>
                        <p class="text-gray-600 dark:text-gray-400">Basic information to help us understand you better</p>
                    </div>

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
                        <label for="timezone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                            Timezone *
                        </label>
                        <select 
                            id="timezone" 
                            name="timezone" 
                            required
                            class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent transition-all"
                        >
                            <option value="">Select your timezone</option>
                            <option value="UTC" <?= ($userData['timezone'] ?? 'UTC') === 'UTC' ? 'selected' : '' ?>>UTC (Coordinated Universal Time)</option>
                            <option value="America/New_York" <?= ($userData['timezone'] ?? '') === 'America/New_York' ? 'selected' : '' ?>>Eastern Time (US)</option>
                            <option value="America/Chicago" <?= ($userData['timezone'] ?? '') === 'America/Chicago' ? 'selected' : '' ?>>Central Time (US)</option>
                            <option value="America/Denver" <?= ($userData['timezone'] ?? '') === 'America/Denver' ? 'selected' : '' ?>>Mountain Time (US)</option>
                            <option value="America/Los_Angeles" <?= ($userData['timezone'] ?? '') === 'America/Los_Angeles' ? 'selected' : '' ?>>Pacific Time (US)</option>
                            <option value="Europe/London" <?= ($userData['timezone'] ?? '') === 'Europe/London' ? 'selected' : '' ?>>GMT (London)</option>
                            <option value="Europe/Paris" <?= ($userData['timezone'] ?? '') === 'Europe/Paris' ? 'selected' : '' ?>>CET (Paris, Berlin)</option>
                            <option value="Europe/Moscow" <?= ($userData['timezone'] ?? '') === 'Europe/Moscow' ? 'selected' : '' ?>>MSK (Moscow)</option>
                            <option value="Asia/Tokyo" <?= ($userData['timezone'] ?? '') === 'Asia/Tokyo' ? 'selected' : '' ?>>JST (Tokyo)</option>
                            <option value="Asia/Shanghai" <?= ($userData['timezone'] ?? '') === 'Asia/Shanghai' ? 'selected' : '' ?>>CST (Beijing)</option>
                            <option value="Australia/Sydney" <?= ($userData['timezone'] ?? '') === 'Australia/Sydney' ? 'selected' : '' ?>>AEDT (Sydney)</option>
                        </select>
                    </div>

                <?php elseif ($step === 2): ?>
                    <!-- Step 2: Professional & Personal -->
                    <div class="text-center mb-8">
                        <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-4 flex items-center justify-center">
                            <i class="fas fa-briefcase text-2xl text-white"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Professional & Personal Life</h2>
                        <p class="text-gray-600 dark:text-gray-400">Help us understand your work and interests</p>
                    </div>

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
                    <!-- Step 3: Lifestyle & Values -->
                    <div class="text-center mb-8">
                        <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-4 flex items-center justify-center">
                            <i class="fas fa-heart text-2xl text-white"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Lifestyle & Values</h2>
                        <p class="text-gray-600 dark:text-gray-400">Share your values and daily life with us</p>
                    </div>

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

                <?php else: ?>
                    <!-- Step 4: Relationship & Additional -->
                    <div class="text-center mb-8">
                        <div class="w-16 h-16 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full mx-auto mb-4 flex items-center justify-center">
                            <i class="fas fa-users text-2xl text-white"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Relationships & More</h2>
                        <p class="text-gray-600 dark:text-gray-400">Final details to complete your profile</p>
                    </div>

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
                <?php endif; ?>

                <div class="flex justify-between pt-6">
                    <?php if ($step > 1): ?>
                        <a href="/onboarding?step=<?= $step - 1 ?>" class="flex items-center px-6 py-3 bg-gray-100 dark:bg-gray-600 text-gray-700 dark:text-gray-300 font-semibold rounded-lg hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors">
                            <i class="fas fa-arrow-left mr-2"></i>
                            Previous
                        </a>
                    <?php else: ?>
                        <div></div>
                    <?php endif; ?>

                    <button 
                        type="submit" 
                        class="flex items-center px-6 py-3 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold rounded-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-all duration-200 shadow-sm hover:shadow-md"
                    >
                        <?php if ($step < $maxSteps): ?>
                            Next
                            <i class="fas fa-arrow-right ml-2"></i>
                        <?php else: ?>
                            <i class="fas fa-check mr-2"></i>
                            Complete Setup
                        <?php endif; ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($step < $maxSteps): ?>
            <div class="text-center mt-6">
                <button type="button" onclick="document.querySelector('form').submit()" class="text-ayuni-blue hover:text-ayuni-aqua font-medium transition-colors">
                    Skip this step
                </button>
            </div>
        <?php endif; ?>
    </div>
</div>