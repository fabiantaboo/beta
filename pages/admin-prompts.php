<?php
requireAdmin();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = "Invalid request. Please try again.";
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'update_system_prompt_template') {
            $template = $_POST['system_prompt_template'] ?? '';
            
            try {
                // Update or insert the system prompt template
                $stmt = $pdo->prepare("INSERT INTO api_settings (setting_key, setting_value) VALUES ('global_system_prompt_template', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$template]);
                $success = "System prompt template updated successfully.";
            } catch (PDOException $e) {
                error_log("Database error updating system prompt template: " . $e->getMessage());
                $error = "Failed to update system prompt template.";
            }
        }
    }
}

// Get current system prompt template
try {
    $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'global_system_prompt_template'");
    $stmt->execute();
    $templateResult = $stmt->fetch();
    $currentTemplate = $templateResult ? $templateResult['setting_value'] : '';
} catch (PDOException $e) {
    $currentTemplate = '';
}
?>

<div class="min-h-screen bg-gray-50 dark:bg-ayuni-dark">
    <?php renderAdminNavigation('admin-prompts'); ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php renderAdminPageHeader('System Prompt Templates', 'Configure global templates for AEI system prompts'); ?>
        
        <?php renderAdminAlerts($error, $success); ?>

        <!-- System Prompt Template Builder -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm mb-8">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Global System Prompt Template</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Define the global template for all AEI system prompts using merge tags</p>
            </div>
            <div class="p-6">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                    <input type="hidden" name="action" value="update_system_prompt_template">
                    
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Template Editor -->
                        <div>
                            <label for="system_prompt_template" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-code mr-2 text-ayuni-blue"></i>
                                System Prompt Template
                            </label>
                            <textarea 
                                id="system_prompt_template" 
                                name="system_prompt_template" 
                                rows="20"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-ayuni-blue focus:border-transparent font-mono text-sm"
                                placeholder="Enter your system prompt template..."
                            ><?= htmlspecialchars($currentTemplate) ?></textarea>
                            <div class="flex justify-end mt-3">
                                <button 
                                    type="submit" 
                                    class="px-6 py-2 bg-gradient-to-r from-ayuni-aqua to-ayuni-blue text-white rounded-lg font-medium hover:from-ayuni-aqua/90 hover:to-ayuni-blue/90 transition-colors"
                                >
                                    <i class="fas fa-save mr-2"></i>
                                    Save Template
                                </button>
                            </div>
                        </div>
                        
                        <!-- Merge Tags Reference -->
                        <div>
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-tags mr-2 text-ayuni-aqua"></i>
                                Available Merge Tags
                            </h4>
                            <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4 space-y-4 max-h-96 overflow-y-auto">
                                
                                <!-- AEI Character Data -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">AEI Character Data</h5>
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{aei_name}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">AEI name</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{age}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Age</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{gender}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Gender identity</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{personality}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Core personality</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{communication_style}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Communication style</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{appearance_description}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Physical appearance</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{background}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Personal background</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{interests}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Interests & hobbies</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{quirks}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Unique quirks</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{occupation}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Occupation/role</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{goals}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Goals & aspirations</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{relationship_context}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Relationship to user</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Individual Appearance Fields -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">Individual Appearance Fields</h5>
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{hair_color}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Hair color</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{eye_color}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Eye color</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{height}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Height</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{build}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Build/physique</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{style}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Clothing style</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{appearance_custom}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Custom appearance details</span>
                                        </div>
                                    </div>
                                    <div class="mt-3 p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                                        <h6 class="text-xs font-semibold text-purple-700 dark:text-purple-300 mb-2">🎨 Appearance Options</h6>
                                        <p class="text-xs text-purple-600 dark:text-purple-400">
                                            Use individual fields for specific mentions, or {{appearance_description}} for a complete natural description.
                                        </p>
                                    </div>
                                    </div>
                                    <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                        <h6 class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-2">📋 Note about Character Data</h6>
                                        <p class="text-xs text-blue-600 dark:text-blue-400">
                                            Personality, interests, and communication style are automatically parsed from the interactive character creator. 
                                            Appearance data is intelligently combined into natural descriptions.
                                        </p>
                                    </div>
                                </div>
                                
                                <!-- Relationship Context -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">Relationship Context</h5>
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{relationship_context}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Complete relationship context</span>
                                        </div>
                                    </div>
                                    <div class="mt-3 p-3 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                        <h6 class="text-xs font-semibold text-green-700 dark:text-green-300 mb-2">✨ Gender-Aware Relationship Types</h6>
                                        <div class="text-xs text-green-600 dark:text-green-400 space-y-1">
                                            <p><strong>Family:</strong> Sister, Brother, Daughter, Son</p>
                                            <p><strong>Romantic:</strong> Girlfriend, Boyfriend, Wife, Husband</p>
                                            <p><strong>Friendship:</strong> Best Friend, Close Friend</p>
                                            <p><strong>Professional:</strong> Mentor, Student, Colleague</p>
                                            <p><strong>Other:</strong> Companion</p>
                                            <p class="mt-2 italic">Relationship options automatically filter based on AEI gender</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- User Data -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">User Data</h5>
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_first_name}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's first name</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_profession}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's profession</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_hobbies}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's hobbies</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_goals}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's life goals</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_beliefs}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's beliefs</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_gender}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's gender</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_sexual_orientation}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's orientation</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_daily_rituals}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's daily rituals</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_partner_qualities}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Partner qualities</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_additional_info}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Additional info</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_birth_date}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's birth date</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_age}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's calculated age</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_timezone}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's timezone</span>
                                        </div>
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{user_preferred_language}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">User's preferred language</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Response Preferences -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">Response Preferences</h5>
                                    <div class="space-y-1">
                                        <div class="flex items-center justify-between text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs">{{response_length}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs">Response length preference</span>
                                        </div>
                                    </div>
                                    <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                        <h6 class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-2">💬 Response Length Options</h6>
                                        <div class="text-xs text-blue-600 dark:text-blue-400 space-y-1">
                                            <p><strong>Short:</strong> 2-3 sentences</p>
                                            <p><strong>Medium:</strong> 4-5 sentences</p>
                                            <p><strong>Long:</strong> Detailed responses</p>
                                            <p class="mt-2 italic">User can adjust via chat profile modal</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Conditional Logic -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">Conditional Logic</h5>
                                    <div class="space-y-2">
                                        <div class="text-sm">
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs block mb-1">{{#if variable_name}}</code>
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs block mb-1">Content if exists</code>
                                            <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded text-xs block">{{/if}}</code>
                                            <span class="text-gray-500 dark:text-gray-400 text-xs mt-1 block">Show content only if variable has value</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Quick Insert Buttons -->
                                <div>
                                    <h5 class="text-xs font-semibold text-gray-600 dark:text-gray-400 uppercase tracking-wider mb-2">Quick Insert</h5>
                                    <div class="grid grid-cols-2 gap-2">
                                        <!-- AEI Data -->
                                        <button type="button" onclick="insertTag('{{aei_name}}')" class="text-xs bg-ayuni-blue text-white px-2 py-1 rounded hover:bg-ayuni-blue/90">AEI Name</button>
                                        <button type="button" onclick="insertTag('{{age}}')" class="text-xs bg-ayuni-blue text-white px-2 py-1 rounded hover:bg-ayuni-blue/90">Age</button>
                                        <button type="button" onclick="insertTag('{{gender}}')" class="text-xs bg-ayuni-blue text-white px-2 py-1 rounded hover:bg-ayuni-blue/90">Gender</button>
                                        <button type="button" onclick="insertTag('{{personality}}')" class="text-xs bg-ayuni-blue text-white px-2 py-1 rounded hover:bg-ayuni-blue/90">Personality</button>
                                        
                                        <!-- Appearance -->
                                        <button type="button" onclick="insertTag('{{hair_color}}')" class="text-xs bg-pink-500 text-white px-2 py-1 rounded hover:bg-pink-600">Hair Color</button>
                                        <button type="button" onclick="insertTag('{{eye_color}}')" class="text-xs bg-pink-500 text-white px-2 py-1 rounded hover:bg-pink-600">Eye Color</button>
                                        <button type="button" onclick="insertTag('{{height}}')" class="text-xs bg-pink-500 text-white px-2 py-1 rounded hover:bg-pink-600">Height</button>
                                        <button type="button" onclick="insertTag('{{build}}')" class="text-xs bg-pink-500 text-white px-2 py-1 rounded hover:bg-pink-600">Build</button>
                                        
                                        <!-- User Data -->
                                        <button type="button" onclick="insertTag('{{user_first_name}}')" class="text-xs bg-ayuni-aqua text-white px-2 py-1 rounded hover:bg-ayuni-aqua/90">User Name</button>
                                        <button type="button" onclick="insertTag('{{user_age}}')" class="text-xs bg-ayuni-aqua text-white px-2 py-1 rounded hover:bg-ayuni-aqua/90">User Age</button>
                                        <button type="button" onclick="insertTag('{{user_profession}}')" class="text-xs bg-ayuni-aqua text-white px-2 py-1 rounded hover:bg-ayuni-aqua/90">User Prof</button>
                                        <button type="button" onclick="insertTag('{{user_timezone}}')" class="text-xs bg-ayuni-aqua text-white px-2 py-1 rounded hover:bg-ayuni-aqua/90">User Timezone</button>
                                        <button type="button" onclick="insertTag('{{user_preferred_language}}')" class="text-xs bg-ayuni-aqua text-white px-2 py-1 rounded hover:bg-ayuni-aqua/90">User Language</button>
                                        
                                        <!-- Relationships -->
                                        <button type="button" onclick="insertTag('{{relationship_context}}')" class="text-xs bg-green-500 text-white px-2 py-1 rounded hover:bg-green-600">Relationship</button>
                                        <button type="button" onclick="insertConditional('relationship_context')" class="text-xs bg-green-500 text-white px-2 py-1 rounded hover:bg-green-600">If Relationship</button>
                                        
                                        <!-- Conditionals -->
                                        <button type="button" onclick="insertConditional('personality')" class="text-xs bg-gray-500 text-white px-2 py-1 rounded hover:bg-gray-600">If Personality</button>
                                        <button type="button" onclick="insertConditional('appearance_description')" class="text-xs bg-gray-500 text-white px-2 py-1 rounded hover:bg-gray-600">If Appearance</button>
                                        <button type="button" onclick="insertConditional('interests')" class="text-xs bg-gray-500 text-white px-2 py-1 rounded hover:bg-gray-600">If Interests</button>
                                        <button type="button" onclick="insertConditional('user_profession')" class="text-xs bg-gray-500 text-white px-2 py-1 rounded hover:bg-gray-600">If User Prof</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Template Preview -->
        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm">
            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">How It Works</h3>
            </div>
            <div class="p-6">
                <div class="space-y-4">
                    <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-blue-800 dark:text-blue-300 mb-2">Template Priority</h4>
                        <ol class="text-sm text-blue-700 dark:text-blue-400 space-y-1 list-decimal list-inside">
                            <li>If an AEI has a custom system prompt → Use that directly</li>
                            <li>Otherwise → Use this global template with user data</li>
                            <li>If no template is configured → Use default fallback</li>
                        </ol>
                    </div>
                    
                    <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                        <h4 class="text-sm font-semibold text-yellow-800 dark:text-yellow-300 mb-2">Template Example</h4>
                        <pre class="text-xs text-yellow-700 dark:text-yellow-400 whitespace-pre-wrap font-mono">You are {{aei_name}}, an Artificial Emotional Intelligence.

{{#if age}}Age: {{age}} years old{{/if}}
{{#if gender}} | Gender: {{gender}}{{/if}}

{{#if personality}}
**Personality:** {{personality}}
{{/if}}

{{#if communication_style}}
**Communication:** {{communication_style}}
{{/if}}

{{#if appearance_description}}
**Appearance:** {{appearance_description}}
{{/if}}

{{#if interests}}
**Interests:** {{interests}}
{{/if}}

{{#if relationship_context}}
**Relationship:** {{relationship_context}}
{{/if}}

{{#if user_first_name}}
You're chatting with {{user_first_name}}.{{#if user_profession}} They work as {{user_profession}}.{{/if}}
{{/if}}

Stay true to your character and personality!</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function insertTag(tag) {
    const textarea = document.getElementById('system_prompt_template');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);
    
    textarea.value = before + tag + after;
    textarea.setSelectionRange(start + tag.length, start + tag.length);
    textarea.focus();
}

function insertConditional(variable) {
    const textarea = document.getElementById('system_prompt_template');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    const before = text.substring(0, start);
    const after = text.substring(end, text.length);
    
    const conditional = `{{#if ${variable}}}\n\n{{/if}}`;
    
    textarea.value = before + conditional + after;
    // Position cursor between the if blocks
    const newPosition = start + conditional.indexOf('\n\n') + 1;
    textarea.setSelectionRange(newPosition, newPosition);
    textarea.focus();
}
</script>