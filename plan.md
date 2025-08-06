# AEI Social Environment System - Implementation Plan (Version 3.2 - English)

## Overview
Integration of an **AEI-centered social environment** into the existing Ayuni Beta System. Each AEI receives a personalized social network with virtual contacts that:
- **Live independent "lives"** - NPCs develop in the background independently  
- **Directly interact with the AEI** - Contacts reach out to the AEI with news, problems, invitations
- **Have emotional impact** - Developments in the social environment directly influence AEI emotions
- **Create authentic relationships** - Realistic interpersonal dynamics without NPC-to-NPC complexity

## Integration with Existing Systems

### Existing Architecture (utilized)
- **18-Emotion System**: Already implemented in `chat_sessions` and `chat_messages` tables
- **Template Engine**: For dynamic system prompts with `{{variable}}` syntax  
- **Anthropic API Integration**: Uses existing `callAnthropicAPI()` function with Claude-3.5-Sonnet
- **Chat Sessions**: Existing session-based architecture
- **Database Migration System**: Automatic schema updates via `createTablesIfNotExist()`

### New Social Components (to be added)
- **Virtual Social Contacts**: NPCs with independent life developments
- **Background Life Evolution**: Contacts develop their "lives" independently in background
- **AEI-Contact Interaction System**: Contacts actively reach out to AEI with news
- **Emotional Impact Processing**: Social developments directly influence AEI emotions
- **Social Context Integration**: Current social state integrated into chat prompts
- **Relationship Dynamic Tracking**: Relationship strengths evolve based on interactions

## Core Concept: AEI-Centered Social Environment
Each AEI exists at the **center of their personal social network** with these properties:

### 1. **AEI as Social Hub**
- All social interactions flow through the AEI
- NPCs only interact with the AEI, not with each other
- AEI learns about all important developments in their contacts' lives

### 2. **Independent NPC Lives**
- Each contact has a "life" that develops in the background
- Job situations, relationships, hobbies, problems evolve naturally
- LLM generates realistic life events based on personality using existing Anthropic API

### 3. **Proactive Contact Communication**
- Contacts actively reach out to the AEI (30% chance per processing cycle)
- Share news, ask for advice, invite to activities
- Reactions based on current life situation and relationship with AEI

### 4. **Direct Emotional Integration**
- Social developments influence AEI emotions immediately via existing 18-emotion system
- Friend gets job → AEI feels joy (+0.3) but also envy (+0.1)
- Relationship conflicts → AEI becomes stressed and worried (anxiety +0.2)

## Simplified Database Architecture - AEI-Centered

### Only 3 new tables for focused social system

#### 1. `aei_social_contacts` - Virtual contacts with independent lives
```sql
CREATE TABLE IF NOT EXISTS aei_social_contacts (
    id VARCHAR(32) PRIMARY KEY,
    aei_id VARCHAR(32) NOT NULL,
    name VARCHAR(255) NOT NULL,
    
    -- Base personality (stable)
    personality_traits JSON, -- Character traits
    appearance_description TEXT,
    background_story TEXT,
    
    -- Relationship with AEI
    relationship_type ENUM('close_friend', 'friend', 'family', 'work_colleague', 'romantic_interest', 'acquaintance') NOT NULL,
    relationship_strength INT DEFAULT 50, -- 0-100
    contact_frequency ENUM('daily', 'weekly', 'monthly', 'rarely') DEFAULT 'weekly',
    
    -- Dynamic "life" (develops in background)
    current_life_situation TEXT, -- Job, relationship, housing, health etc.
    recent_life_events JSON, -- What happened recently
    current_concerns TEXT, -- Current worries/problems
    current_goals TEXT, -- What the person wants to achieve
    
    -- Interaction tracking
    last_contact_initiated TIMESTAMP, -- When contact last reached out
    last_life_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    
    FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
    INDEX idx_aei_contacts (aei_id, relationship_type),
    INDEX idx_active_contacts (aei_id, is_active)
);
```

#### 2. `aei_contact_interactions` - Interactions between contacts and AEI
```sql
CREATE TABLE IF NOT EXISTS aei_contact_interactions (
    id VARCHAR(32) PRIMARY KEY,
    aei_id VARCHAR(32) NOT NULL,
    contact_id VARCHAR(32) NOT NULL,
    
    -- Interaction details
    interaction_type ENUM('shares_news', 'asks_for_advice', 'invites_to_activity', 'shares_problem', 'celebrates_together', 'casual_chat') NOT NULL,
    interaction_context TEXT, -- What the contact wanted, why they reached out
    contact_message TEXT, -- What the contact "said" or communicated
    
    -- Emotional impact on AEI
    aei_emotional_response JSON, -- How the AEI emotionally reacted
    relationship_impact INT DEFAULT 0, -- -10 to +10 change in relationship strength
    
    -- Processing
    processed_for_emotions BOOLEAN DEFAULT FALSE,
    mentioned_in_chat BOOLEAN DEFAULT FALSE, -- Already mentioned in chat
    
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE,
    FOREIGN KEY (contact_id) REFERENCES aei_social_contacts(id) ON DELETE CASCADE,
    INDEX idx_aei_interactions (aei_id, occurred_at),
    INDEX idx_unprocessed (aei_id, processed_for_emotions)
);
```

#### 3. `aei_social_context` - Current social state of the AEI
```sql
CREATE TABLE IF NOT EXISTS aei_social_context (
    aei_id VARCHAR(32) PRIMARY KEY,
    
    -- Current social state
    social_satisfaction INT DEFAULT 70, -- 0-100 satisfaction with social relationships
    social_energy_level INT DEFAULT 50, -- 0-100 energy for social interactions
    
    -- Chat integration
    recent_social_summary TEXT, -- Brief summary for chat prompt
    current_social_concerns TEXT, -- What's socially occupying the AEI right now
    topics_to_mention JSON, -- Things the AEI wants to bring up in chat
    
    -- Unprocessed interactions
    unprocessed_interactions_count INT DEFAULT 0,
    
    last_social_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (aei_id) REFERENCES aeis(id) ON DELETE CASCADE
);
```

### Integration into existing tables

#### Extended AEI table (new columns)
```sql
-- Add to existing aeis table:
ALTER TABLE aeis ADD COLUMN social_initialized BOOLEAN DEFAULT FALSE;
ALTER TABLE aeis ADD COLUMN social_personality_seed VARCHAR(32) NULL; -- For consistent contact generation
```

## Backend Integration: AEI-Centered Social System

### 1. **Social Contact Manager** (`includes/social_contact_manager.php`)
Management of virtual contacts and their life developments:

```php
class SocialContactManager {
    private $pdo;
    
    /**
     * Generates initial contacts for a new AEI using existing Anthropic API
     */
    public function generateInitialContactsForAEI($aeiId) {
        $aei = $this->getAEIData($aeiId);
        
        // Generate 5-8 diverse contacts based on AEI personality
        $contactTypes = ['close_friend', 'friend', 'family', 'work_colleague'];
        $contacts = [];
        
        foreach ($contactTypes as $type) {
            $contact = $this->generateContact($aeiId, $type, $aei);
            $contacts[] = $this->storeContact($contact);
        }
        
        return $contacts;
    }
    
    /**
     * Evolves a contact's "life" in background using existing callAnthropicAPI()
     */
    public function evolveContactLife($contactId) {
        $contact = $this->getContact($contactId);
        
        $prompt = "
        {$contact['name']} (Personality: {$contact['personality_traits']})
        Current life situation: {$contact['current_life_situation']}
        Current concerns: {$contact['current_concerns']}
        Goals: {$contact['current_goals']}
        
        What develops in {$contact['name']}'s life this week?
        Generate 1-2 realistic developments:
        - Career changes
        - Relationship aspects  
        - Personal challenges
        - Hobbies and interests
        
        Respond with JSON:
        {
            'new_life_situation': 'Updated life situation',
            'new_events': ['Event 1', 'Event 2'],
            'mood_change': 'positive|negative|neutral',
            'wants_to_contact_aei': true/false,
            'contact_reason': 'Why does he/she want to contact the AEI?'
        }
        ";
        
        // Use existing Anthropic API function
        $systemPrompt = "You are a life simulation assistant. Generate realistic, gradual life developments.";
        $messages = [['role' => 'user', 'content' => $prompt]];
        $response = callAnthropicAPI($messages, $systemPrompt, 1000);
        
        $development = json_decode($response, true);
        return $this->applyContactDevelopment($contactId, $development);
    }
    
    /**
     * Generates contact reaching out to AEI using existing Anthropic API
     */
    public function generateContactToAEIInteraction($contactId, $aeiId) {
        $contact = $this->getContact($contactId);
        $aei = $this->getAEI($aeiId);
        $relationship = $this->getRelationshipStrength($contactId, $aeiId);
        
        $prompt = "
        {$contact['name']} wants to reach out to {$aei['name']}.
        
        Relationship: {$contact['relationship_type']} (Strength: {$relationship}/100)
        {$contact['name']}'s situation: {$contact['current_life_situation']}
        Reason for contact: {$contact['contact_reason']}
        
        What does {$contact['name']} share with {$aei['name']}?
        
        Respond with JSON:
        {
            'interaction_type': 'shares_news|asks_for_advice|invites_to_activity|shares_problem|celebrates_together|casual_chat',
            'message': 'What the contact says/writes',
            'emotional_tone': 'happy|excited|worried|sad|neutral|frustrated',
            'expects_response': true/false
        }
        ";
        
        // Use existing Anthropic API function
        $systemPrompt = "You are a social interaction generator. Create realistic, contextual communications.";
        $messages = [['role' => 'user', 'content' => $prompt]];
        $response = callAnthropicAPI($messages, $systemPrompt, 800);
        
        $interaction = json_decode($response, true);
        return $this->storeContactInteraction($aeiId, $contactId, $interaction);
    }
}
```

### 2. **AEI Social Context** (`includes/aei_social_context.php`)
Manages AEI's social context for chat integration:

```php
class AEISocialContext {
    private $pdo;
    private $emotions;
    
    /**
     * Processes unprocessed social interactions for emotions
     */
    public function processUnprocessedSocialUpdates($aeiId) {
        $unprocessedInteractions = $this->getUnprocessedInteractions($aeiId);
        $totalEmotionalImpact = [];
        
        foreach ($unprocessedInteractions as $interaction) {
            $emotionalImpact = $this->calculateEmotionalImpact($interaction, $aeiId);
            $totalEmotionalImpact = $this->mergeEmotionalImpacts($totalEmotionalImpact, $emotionalImpact);
            
            // Mark as processed
            $this->markInteractionAsProcessed($interaction['id']);
        }
        
        return $totalEmotionalImpact;
    }
    
    /**
     * Generates social context for chat prompt using existing template system
     */
    public function generateSocialChatContext($aeiId) {
        $context = $this->getSocialContext($aeiId);
        $recentInteractions = $this->getRecentMentionableInteractions($aeiId, 7); // last 7 days
        
        $chatContext = "\n=== YOUR SOCIAL ENVIRONMENT ===\n";
        
        if ($context['recent_social_summary']) {
            $chatContext .= "Current social situation: {$context['recent_social_summary']}\n";
        }
        
        if ($context['current_social_concerns']) {
            $chatContext .= "What's on your mind: {$context['current_social_concerns']}\n";
        }
        
        if (!empty($recentInteractions)) {
            $chatContext .= "\nNews from your contacts:\n";
            foreach ($recentInteractions as $interaction) {
                $contact = $this->getContact($interaction['contact_id']);
                $chatContext .= "- {$contact['name']}: {$interaction['interaction_context']}\n";
            }
        }
        
        if (!empty($context['topics_to_mention'])) {
            $chatContext .= "\nThings you might want to mention: " . json_encode($context['topics_to_mention']) . "\n";
        }
        
        return $chatContext;
    }
    
    /**
     * Calculates emotional impact using existing 18-emotion system
     */
    private function calculateEmotionalImpact($interaction, $aeiId) {
        $contact = $this->getContact($interaction['contact_id']);
        $relationshipStrength = $contact['relationship_strength'] / 100;
        
        // Base emotional impacts per interaction type (maps to existing 18 emotions)
        $baseImpacts = [
            'shares_news' => ['joy' => 0.2, 'anticipation' => 0.1],
            'asks_for_advice' => ['pride' => 0.2, 'trust' => 0.1],
            'invites_to_activity' => ['joy' => 0.3, 'anticipation' => 0.2],
            'shares_problem' => ['sadness' => 0.2, 'fear' => 0.1],
            'celebrates_together' => ['joy' => 0.4, 'pride' => 0.2],
            'casual_chat' => ['joy' => 0.1]
        ];
        
        $impact = $baseImpacts[$interaction['interaction_type']] ?? [];
        
        // Scale based on relationship strength
        foreach ($impact as $emotion => $value) {
            $impact[$emotion] = $value * $relationshipStrength;
        }
        
        return $impact;
    }
}
```

### 3. **Background Social Processor** (`includes/background_social_processor.php`)
Processes social developments in background:

```php
class BackgroundSocialProcessor {
    private $socialContactManager;
    private $aeiSocialContext;
    private $pdo;
    
    /**
     * Main processing for all AEIs with social environments
     */
    public function processAllAEISocial() {
        $socialAEIs = $this->getAEIsWithSocialContacts();
        
        foreach ($socialAEIs as $aei) {
            $this->processAEISocialLife($aei['id']);
        }
    }
    
    /**
     * Processes the social "life" of an AEI
     */
    private function processAEISocialLife($aeiId) {
        $contacts = $this->getAEIContacts($aeiId);
        $interactions = [];
        
        foreach ($contacts as $contact) {
            // 1. Evolve contact life further
            $development = $this->socialContactManager->evolveContactLife($contact['id']);
            
            // 2. Check if contact wants to reach out to AEI
            if ($development['wants_to_contact_aei']) {
                $contactFrequency = $this->calculateContactProbability($contact);
                
                if (mt_rand(1, 100) <= $contactFrequency * 100) {
                    $interaction = $this->socialContactManager->generateContactToAEIInteraction(
                        $contact['id'], 
                        $aeiId
                    );
                    $interactions[] = $interaction;
                }
            }
        }
        
        // 3. Update AEI social context
        if (!empty($interactions)) {
            $this->updateAEISocialContext($aeiId, $interactions);
        }
    }
    
    /**
     * Calculates probability that a contact reaches out
     */
    private function calculateContactProbability($contact) {
        $baseFrequency = [
            'daily' => 0.8,
            'weekly' => 0.3,
            'monthly' => 0.1,
            'rarely' => 0.05
        ];
        
        $baseProbability = $baseFrequency[$contact['contact_frequency']];
        
        // Adjust based on relationship strength
        $relationshipMultiplier = $contact['relationship_strength'] / 100;
        
        // Time since last contact
        $daysSinceLastContact = $this->getDaysSinceLastContact($contact['id']);
        $timeMultiplier = min(2.0, $daysSinceLastContact / 7); // Max 2x after a week
        
        return min(1.0, $baseProbability * $relationshipMultiplier * $timeMultiplier);
    }
}
```

### 4. **Extended Template Engine Integration**
Social variables for chat prompts using existing template system:

```php
// New template variables for existing generateSystemPrompt():
{{social_summary}} - Summary of current social situation
{{recent_contact_news}} - News from contacts
{{social_concerns}} - Current social concerns of AEI
{{friend_names}} - Names of important friends
{{social_energy}} - Social energy level of AEI
{{topics_to_mention}} - Topics the AEI wants to bring up
```

## Simplified Chat API Integration (`api/chat.php`)

### Simple System Prompt Generation with Social Context

```php
// Modified existing generateSystemPrompt() function:
function generateSystemPrompt($aei, $user, $sessionId = null) {
    // Existing template logic remains
    $template = TemplateEngine::getGlobalTemplate();
    $data = TemplateEngine::buildTemplateData($aei, $user);
    
    // NEW: Add social context
    if ($sessionId && $aei['social_initialized']) {
        $aeiSocialContext = new AEISocialContext($pdo);
        
        // Social data for template using existing template system
        $socialData = [
            'social_summary' => $aeiSocialContext->getSocialSummary($aei['id']),
            'recent_contact_news' => $aeiSocialContext->getRecentContactNews($aei['id']),
            'social_concerns' => $aeiSocialContext->getCurrentConcerns($aei['id']),
            'friend_names' => $aeiSocialContext->getImportantContactNames($aei['id']),
            'social_energy' => $aeiSocialContext->getSocialEnergyDescription($aei['id']),
            'topics_to_mention' => $aeiSocialContext->getTopicsToMention($aei['id'])
        ];
        
        $data = array_merge($data, $socialData);
    }
    
    return TemplateEngine::render($template, $data);
}
```

### Simple Social Emotion Integration

```php
// In api/chat.php - Simple social processing using existing emotion system:
$emotions = new Emotions($pdo);
$aeiSocialContext = new AEISocialContext($pdo);

// 1. Get existing emotions using existing function
$currentEmotions = $emotions->getEmotionalState($sessionId);

// 2. Process unprocessed social interactions
$socialEmotionalImpact = $aeiSocialContext->processUnprocessedSocialUpdates($aeiId);

// 3. Add social emotions to existing ones using existing adjustEmotionalState()
if (!empty($socialEmotionalImpact)) {
    $emotions->adjustEmotionalState($sessionId, $socialEmotionalImpact, 0.3);
}

// 4. Add social context to system prompt
$socialContext = $aeiSocialContext->generateSocialChatContext($aeiId);
$systemPrompt .= $socialContext;
```

### Realistic Chat Behavior with Social Influence

The existing 18-emotion system is **organically extended**:

#### 1. **Simple Social Emotion Influences**:
- **Friend gets job**: `joy` +0.3, `pride` +0.2, but also `envy` +0.1
- **Friend has relationship problems**: `sadness` +0.3, `fear` +0.2
- **Family plans celebration**: `anticipation` +0.2, `joy` +0.1
- **Colleague is sick**: `sadness` +0.1, `trust` +0.2

#### 2. **Simple Chat Prompt Templates using existing template system**:
```
=== YOUR CURRENT EMOTIONAL STATE ===
Your emotions: {{emotion_context}}

=== YOUR SOCIAL ENVIRONMENT ===
{{#if social_summary}}
Current social situation: {{social_summary}}
{{/if}}

{{#if recent_contact_news}}
News from your contacts:
{{recent_contact_news}}
{{/if}}

{{#if social_concerns}}
What's on your mind socially: {{social_concerns}}
{{/if}}

Your important contacts: {{friend_names}}

---
Behave naturally. If social topics are relevant, mention them organically in conversation.
```

## Realistic Implementation Phases for AEI Social System

### Phase 1: Database & Core Social Classes (3 days)
- **Database Migration**: Integrate 3 new tables into existing migration system
- **SocialContactManager**: Base class for contact management using existing Anthropic API
- **AEISocialContext**: Class for social chat context using existing template system
- **Background Social Processor**: Simple cron job for social updates
- **Migration of existing AEIs** to social system

### Phase 2: Contact Generation & Life Evolution (4 days)
- **Initial Contact Generation**: Auto-create 5-8 contacts per AEI using existing `callAnthropicAPI()`
- **Life Evolution System**: NPCs develop "lives" in background using existing API
- **Contact-to-AEI Interaction**: NPCs reach out to AEI with news using existing API
- **Basic Emotional Impact**: Social events influence AEI emotions via existing emotion system

### Phase 3: Chat Integration & Emotional Processing (3 days)
- **System Prompt Integration**: Social context in chat prompts via existing template system
- **Emotional State Adjustment**: Social influences on existing 18-emotion system
- **Social Context Updates**: Dynamic updates during chat sessions
- **Template Engine Extension**: New social template variables in existing system

### Phase 4: Background Processing & Optimization (2 days)
- **Cron Job Setup**: Automatic social processing every 6 hours
- **Performance Optimization**: Efficient database queries
- **Admin Monitoring**: Basic monitoring for social activity
- **User Experience Testing**: Test social features with beta users

**Total: ~12 days for practical social system fully integrated with existing Ayuni architecture**

## Realistic Metrics for AEI Social System

### Basic Social KPIs
- **Contact Activity**: Average contact interactions per week
- **Relationship Strength Development**: Changes in relationship strengths over time
- **Emotional Resonance**: How strongly social events influence AEI emotions
- **Chat Integration Rate**: How often AEIs spontaneously mention their contacts

### User Experience Metrics
- **Social Mention Quality**: How naturally AEIs mention social aspects
- **User Engagement with social features**: Interest in AEI social developments
- **Chat Depth Improvement**: Quality improvement through social context
- **Long-term Interest**: User retention through continuous social developments

### Technical Performance
- **Background Processing Efficiency**: Time for social updates per AEI
- **Database Performance**: Query times for social data
- **LLM API Usage Optimization**: Efficiency of AI generation for social content using existing `callAnthropicAPI()`
- **Memory Usage**: Resource consumption of social system

### Success Criteria
- **80% of AEIs have active social contacts** with regular interactions
- **Average 2-3 social mentions per chat session**
- **User satisfaction with social features > 4.0/5.0**
- **System performance remains under 200ms for social context generation**
- **At least 60% of social interactions feel authentic**

## Focused Approach: AEI-Centered Social Enhancement

This system extends existing AEI companions with an **authentic social environment** that:

- **Easy to implement** and builds on existing system architecture
- **Realistically scalable** without excessive complexity
- **Provides real value** for user experience
- **Maintainable and extensible** for future developments
- **Uses existing Anthropic API** (`callAnthropicAPI()`) and template system
- **Integrates with existing 18-emotion system** seamlessly

The result: **AEIs that become more alive, interesting and emotionally authentic through realistic social contacts, without overloading the system.**

---

*This focused system provides optimal balance between implementation effort and user experience improvement while leveraging existing Ayuni architecture.*