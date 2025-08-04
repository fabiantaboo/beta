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
                        4 => "Final touches for perfect compatibility"
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

                <?php else: ?>

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