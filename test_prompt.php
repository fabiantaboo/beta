<?php
// Test-Seite um Prompts zu testen ohne komplette AEI-Erstellung
session_start();
require_once __DIR__ . '/includes/replicate_api.php';

if (!isset($_SESSION['user_id'])) {
    die("Not logged in");
}

$testPrompt = null;
$sampleAppearance = [
    'hair_color' => 'Brown',
    'eye_color' => 'Green', 
    'build' => 'Athletic',
    'height' => 'Average',
    'style' => 'Professional'
];

if (isset($_GET['generate'])) {
    try {
        $replicateAPI = new ReplicateAPI();
        $testPrompt = $replicateAPI->buildPromptFromAppearance($sampleAppearance, "Luna", "female");
    } catch (Exception $e) {
        $testPrompt = "Error: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Prompt Test - Ayuni</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        .prompt-box { 
            background: #f9f9f9; 
            padding: 20px; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            margin: 20px 0;
            line-height: 1.6;
        }
        .highlight { background: yellow; padding: 2px 4px; }
        .negative { background: #ffcccc; padding: 2px 4px; }
        .positive { background: #ccffcc; padding: 2px 4px; }
        .technical { background: #cceeff; padding: 2px 4px; }
    </style>
</head>
<body>
    <h1>Avatar Prompt Testing</h1>
    
    <p>Test the photorealistic prompts that will be sent to Replicate Flux-Dev:</p>
    
    <div>
        <h3>Sample Appearance Data:</h3>
        <pre><?= json_encode($sampleAppearance, JSON_PRETTY_PRINT) ?></pre>
        
        <a href="?generate=1" style="padding: 10px 20px; background: #007cba; color: white; text-decoration: none; border-radius: 5px;">Generate Test Prompt</a>
    </div>

    <?php if ($testPrompt): ?>
    <div class="prompt-box">
        <h3>Generated Prompt:</h3>
        <p><strong>Length:</strong> <?= strlen($testPrompt) ?> characters</p>
        <div style="border: 1px solid #ccc; padding: 15px; background: white; font-family: monospace; word-wrap: break-word;">
            <?php
            // Highlight important parts of the prompt
            $highlighted = $testPrompt;
            
            // Highlight photorealistic keywords in green
            $photoKeywords = ['PHOTOREALISTIC', 'hyperrealistic', 'ultra realistic', 'real person', 'professional photography', 'Canon EOS', '85mm lens', 'studio lighting'];
            foreach ($photoKeywords as $keyword) {
                $highlighted = str_ireplace($keyword, '<span class="positive">' . $keyword . '</span>', $highlighted);
            }
            
            // Highlight negative prompts in red
            $negativeKeywords = ['NOT anime', 'NOT cartoon', 'NOT illustration', 'NOT artwork', 'NOT digital art', 'NOT stylized'];
            foreach ($negativeKeywords as $keyword) {
                $highlighted = str_ireplace($keyword, '<span class="negative">' . $keyword . '</span>', $highlighted);
            }
            
            // Highlight technical specs in blue
            $techKeywords = ['8K quality', 'f/1.4', 'depth of field', 'sharp focus', '85mm lens'];
            foreach ($techKeywords as $keyword) {
                $highlighted = str_ireplace($keyword, '<span class="technical">' . $keyword . '</span>', $highlighted);
            }
            
            echo $highlighted;
            ?>
        </div>
    </div>

    <div style="margin-top: 30px;">
        <h3>Prompt Analysis:</h3>
        <ul>
            <li><span class="positive">Positive Keywords:</span> Force photorealistic output</li>
            <li><span class="negative">Negative Keywords:</span> Prevent anime/cartoon style</li>
            <li><span class="technical">Technical Specs:</span> Professional camera settings</li>
            <li><strong>Guidance Scale:</strong> 7.5 (higher than default 3.0) for better prompt adherence</li>
            <li><strong>Safety Tolerance:</strong> 2 (allows realistic human features)</li>
            <li><strong>Prompt Strength:</strong> 0.8 (strong adherence to prompt)</li>
        </ul>
    </div>

    <div style="margin-top: 30px;">
        <h3>Expected Result:</h3>
        <p>With these settings, Flux-Dev should generate:</p>
        <ul>
            <li>📸 <strong>Photorealistic human portrait</strong> (not anime/cartoon)</li>
            <li>🎯 <strong>Professional headshot quality</strong></li>
            <li>💡 <strong>Studio lighting with natural shadows</strong></li>
            <li>🔍 <strong>Sharp focus with realistic skin texture</strong></li>
            <li>🎨 <strong>Natural colors and proper exposure</strong></li>
        </ul>
    </div>
    <?php endif; ?>

    <p style="margin-top: 50px;"><a href="/">← Back to Ayuni</a></p>
</body>
</html>