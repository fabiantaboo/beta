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
            <form method="POST" class="space-y-8">
                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">

                <!-- Step 1: Basic Information -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="flex items-center mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-4">
                            <span class="text-white font-bold text-lg">1</span>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Basic Information</h2>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">AEI Name *</label>
                            <input 
                                type="text" 
                                id="name" 
                                name="name" 
                                value="<?= htmlspecialchars($aei['name'] ?? '') ?>"
                                required
                                class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-ayuni-blue transition-all"
                                placeholder="Enter AEI's name"
                            />
                        </div>

                        <div>
                            <label for="age" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Age</label>
                            <input 
                                type="number" 
                                id="age" 
                                name="age" 
                                value="<?= htmlspecialchars($aei['age'] ?? 25) ?>"
                                min="18" 
                                max="99"
                                class="block w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-ayuni-blue transition-all"
                            />
                        </div>
                    </div>

                    <div class="mt-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Gender *</label>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                            <?php 
                            $genderOptions = ['Female', 'Male', 'Non-binary', 'Other'];
                            foreach ($genderOptions as $genderOption): 
                            ?>
                                <label class="relative flex items-center p-4 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer transition-all">
                                    <input 
                                        type="radio" 
                                        name="gender" 
                                        value="<?= htmlspecialchars($genderOption) ?>"
                                        <?= ($aei['gender'] === $genderOption) ? 'checked' : '' ?>
                                        class="sr-only"
                                    />
                                    <div class="flex items-center">
                                        <div class="w-5 h-5 border-2 border-gray-300 dark:border-gray-500 rounded-full mr-3 flex items-center justify-center transition-all">
                                            <div class="w-3 h-3 bg-ayuni-blue rounded-full opacity-0 transition-opacity"></div>
                                        </div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= htmlspecialchars($genderOption) ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Personality -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl p-8 border border-gray-200 dark:border-gray-700 shadow-sm">
                    <div class="flex items-center mb-6">
                        <div class="w-10 h-10 bg-gradient-to-br from-ayuni-aqua to-ayuni-blue rounded-full flex items-center justify-center mr-4">
                            <span class="text-white font-bold text-lg">2</span>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Personality Traits</h2>
                    </div>

                    <div class="mb-4">
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Select personality traits that define your AEI's character. You can select multiple traits.</p>
                        
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3">
                            <?php 
                            $personalityOptions = [
                                'Caring', 'Adventurous', 'Intellectual', 'Playful', 'Mysterious', 'Confident',
                                'Gentle', 'Passionate', 'Witty', 'Romantic', 'Ambitious', 'Creative',
                                'Loyal', 'Independent', 'Empathetic', 'Curious', 'Optimistic', 'Sophisticated'
                            ];
                            
                            $selectedTraits = getPersonalityTraits($personalityData);
                            
                            foreach ($personalityOptions as $trait): 
                            ?>
                                <label class="relative flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-600 cursor-pointer transition-all">
                                    <input 
                                        type="checkbox" 
                                        name="personality_traits[]" 
                                        value="<?= htmlspecialchars($trait) ?>"
                                        <?= in_array($trait, $selectedTraits) ? 'checked' : '' ?>
                                        class="sr-only"
                                    />
                                    <div class="flex items-center">
                                        <div class="w-5 h-5 border-2 border-gray-300 dark:border-gray-500 rounded mr-2 flex items-center justify-center transition-all">
                                            <i class="fas fa-check text-ayuni-blue text-xs opacity-0 transition-opacity"></i>
                                        </div>
                                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300"><?= htmlspecialchars($trait) ?></span>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Save Button -->
                <div class="text-center">
                    <button 
                        type="submit"
                        class="bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white font-semibold py-4 px-8 rounded-xl text-lg hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transform hover:scale-105 transition-all duration-200 shadow-lg hover:shadow-xl"
                    >
                        <i class="fas fa-save mr-2"></i>
                        Save Changes
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<style>
/* Radio button styling */
input[type="radio"]:checked + .flex .w-5.h-5 .w-3.h-3 {
    opacity: 1;
}

input[type="radio"]:checked + .flex .w-5.h-5 {
    border-color: rgb(84, 107, 236);
    background-color: rgba(84, 107, 236, 0.1);
}

/* Checkbox styling */
input[type="checkbox"]:checked + .flex .w-5.h-5 i {
    opacity: 1;
}

input[type="checkbox"]:checked + .flex .w-5.h-5 {
    border-color: rgb(84, 107, 236);
    background-color: rgba(84, 107, 236, 0.1);
}
</style>