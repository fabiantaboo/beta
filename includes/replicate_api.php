<?php

class ReplicateAPI {
    private $apiToken;
    private $baseUrl = 'https://api.replicate.com/v1';
    
    public function __construct() {
        // Get API token from environment or database
        $this->apiToken = $this->getApiToken();
    }
    
    private function getApiToken() {
        global $pdo;
        
        // Try to get from database first
        try {
            $stmt = $pdo->prepare("SELECT setting_value FROM api_settings WHERE setting_key = 'replicate_api_token'");
            $stmt->execute();
            $result = $stmt->fetch();
            
            if ($result && !empty($result['setting_value'])) {
                return $result['setting_value'];
            }
        } catch (PDOException $e) {
            error_log("Failed to get Replicate API token from database: " . $e->getMessage());
        }
        
        // Fallback to environment variable
        return $_ENV['REPLICATE_API_TOKEN'] ?? null;
    }
    
    public function generateAvatar($prompt, $aspectRatio = '1:1', $guidanceScale = 7.5, $numOutputs = 1) {
        if (!$this->apiToken) {
            throw new Exception("Replicate API token not configured");
        }
        
        // Optimized settings for photorealistic portraits
        $data = [
            'version' => 'black-forest-labs/flux-dev',
            'input' => [
                'prompt' => $prompt,
                'aspect_ratio' => $aspectRatio,
                'guidance' => $guidanceScale, // Higher guidance for better prompt adherence
                'num_outputs' => $numOutputs,
                'output_format' => 'png',
                'megapixels' => '1', // Good quality without being too heavy
                'safety_tolerance' => 2, // Allow realistic human features
                'prompt_strength' => 0.8 // Strong prompt adherence
            ]
        ];
        
        return $this->makeRequest('POST', '/predictions', $data);
    }
    
    public function generateMultipleAvatars($prompt, $count = 3, $aspectRatio = '1:1') {
        try {
            error_log("DEBUG Replicate: Starting generation of $count avatars with prompt: " . $prompt);
            
            // Check API token first
            if (!$this->apiToken) {
                error_log("ERROR Replicate: No API token configured");
                throw new Exception("Replicate API token not configured");
            }
            
            // Start the prediction for multiple outputs with optimized settings for realism
            error_log("DEBUG Replicate: Calling generateAvatar with count=$count");
            $prediction = $this->generateAvatar($prompt, $aspectRatio, 7.5, $count); // Higher guidance for photorealism
            error_log("DEBUG Replicate: Raw prediction response: " . json_encode($prediction));
            
            if (!isset($prediction['id'])) {
                error_log("ERROR Replicate: No prediction ID in response");
                throw new Exception("Failed to start image generation: " . json_encode($prediction));
            }
            
            error_log("DEBUG Replicate: Prediction started with ID: " . $prediction['id']);
            
            // Wait for completion
            error_log("DEBUG Replicate: Waiting for completion...");
            $completedPrediction = $this->waitForCompletion($prediction['id']);
            error_log("DEBUG Replicate: Completed prediction: " . json_encode($completedPrediction));
            
            if (!isset($completedPrediction['output']) || empty($completedPrediction['output'])) {
                error_log("ERROR Replicate: No output in completed prediction");
                throw new Exception("No output images generated. Response: " . json_encode($completedPrediction));
            }
            
            // Return all generated image URLs
            $imageUrls = $completedPrediction['output'];
            if (!is_array($imageUrls)) {
                $imageUrls = [$imageUrls];
            }
            
            error_log("DEBUG Replicate: Generated " . count($imageUrls) . " images successfully: " . json_encode($imageUrls));
            return $imageUrls;
            
        } catch (Exception $e) {
            error_log("ERROR Replicate: Multiple avatar generation failed: " . $e->getMessage());
            error_log("ERROR Replicate: Exception trace: " . $e->getTraceAsString());
            throw $e;
        }
    }
    
    public function downloadAndSaveAvatars($imageUrls, $baseDir, $baseFilename) {
        $savedPaths = [];
        error_log("DEBUG Replicate: downloadAndSaveAvatars called with " . count($imageUrls) . " URLs, baseDir: $baseDir, baseFilename: $baseFilename");
        
        foreach ($imageUrls as $index => $imageUrl) {
            try {
                // Create filename with index
                $filename = $baseFilename . '_' . ($index + 1) . '.png';
                $targetPath = $baseDir . $filename;
                error_log("DEBUG Replicate: Processing image $index: $imageUrl -> $targetPath");
                
                // Download the image
                $imageData = $this->downloadImage($imageUrl);
                error_log("DEBUG Replicate: Downloaded " . strlen($imageData) . " bytes for image $index");
                
                // Ensure directory exists
                if (!file_exists($baseDir)) {
                    mkdir($baseDir, 0755, true);
                    error_log("DEBUG Replicate: Created directory: $baseDir");
                }
                
                // Save the image
                $bytesWritten = file_put_contents($targetPath, $imageData);
                if ($bytesWritten !== false) {
                    $savedPaths[] = [
                        'path' => $targetPath,
                        'url' => '/assets/avatars/temp/' . $filename,  // Fixed URL path
                        'index' => $index + 1
                    ];
                    error_log("DEBUG Replicate: Avatar $filename saved successfully ($bytesWritten bytes)");
                } else {
                    error_log("ERROR Replicate: Failed to save avatar $filename");
                }
                
            } catch (Exception $e) {
                error_log("ERROR Replicate: Failed to download avatar " . ($index + 1) . ": " . $e->getMessage());
            }
        }
        
        error_log("DEBUG Replicate: downloadAndSaveAvatars returning " . count($savedPaths) . " saved paths");
        return $savedPaths;
    }
    
    public function getPrediction($predictionId) {
        return $this->makeRequest('GET', "/predictions/{$predictionId}");
    }
    
    public function waitForCompletion($predictionId, $maxWaitTime = 300) {
        $startTime = time();
        
        while (time() - $startTime < $maxWaitTime) {
            $prediction = $this->getPrediction($predictionId);
            
            if ($prediction['status'] === 'succeeded') {
                return $prediction;
            } elseif ($prediction['status'] === 'failed') {
                throw new Exception("Image generation failed: " . ($prediction['error'] ?? 'Unknown error'));
            } elseif ($prediction['status'] === 'canceled') {
                throw new Exception("Image generation was canceled");
            }
            
            // Wait 2 seconds before checking again
            sleep(2);
        }
        
        throw new Exception("Image generation timed out after {$maxWaitTime} seconds");
    }
    
    public function generateAndDownloadAvatar($prompt, $targetPath, $aspectRatio = '1:1') {
        try {
            error_log("Replicate: Starting avatar generation with prompt: " . $prompt);
            
            // Start the prediction
            $prediction = $this->generateAvatar($prompt, $aspectRatio);
            
            if (!isset($prediction['id'])) {
                throw new Exception("Failed to start image generation: " . json_encode($prediction));
            }
            
            error_log("Replicate: Prediction started with ID: " . $prediction['id']);
            
            // Wait for completion
            $completedPrediction = $this->waitForCompletion($prediction['id']);
            
            if (!isset($completedPrediction['output']) || empty($completedPrediction['output'])) {
                throw new Exception("No output images generated");
            }
            
            // Get the first generated image URL
            $imageUrls = $completedPrediction['output'];
            $imageUrl = is_array($imageUrls) ? $imageUrls[0] : $imageUrls;
            
            error_log("Replicate: Image generated successfully, downloading from: " . $imageUrl);
            
            // Download the image
            $imageData = $this->downloadImage($imageUrl);
            
            // Ensure directory exists
            $directory = dirname($targetPath);
            if (!file_exists($directory)) {
                mkdir($directory, 0755, true);
            }
            
            // Save the image
            if (file_put_contents($targetPath, $imageData) !== false) {
                error_log("Replicate: Avatar saved successfully to: " . $targetPath);
                return $targetPath;
            } else {
                throw new Exception("Failed to save image to: " . $targetPath);
            }
            
        } catch (Exception $e) {
            error_log("Replicate: Avatar generation failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function downloadImage($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Ayuni Beta Avatar Generator');
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || $data === false) {
            throw new Exception("Failed to download image: HTTP {$httpCode}");
        }
        
        return $data;
    }
    
    private function makeRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Token ' . $this->apiToken,
            'Content-Type: application/json'
        ]);
        
        if ($method === 'POST' && $data) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception("Curl error: " . curl_error($ch));
        }
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $error = isset($decodedResponse['detail']) ? $decodedResponse['detail'] : "HTTP {$httpCode}";
            throw new Exception("Replicate API error: " . $error);
        }
        
        return $decodedResponse;
    }
    
    public function buildPromptFromAppearance($appearanceData, $name, $gender = null) {
        // Start with STRONG photorealistic base
        $prompt = "Professional headshot photograph of a ";
        
        // Add gender and name context
        if ($gender) {
            $prompt .= strtolower($gender) . " ";
        }
        $prompt .= "person named {$name}, ";
        
        // Parse appearance JSON if it's a string
        if (is_string($appearanceData)) {
            $appearance = json_decode($appearanceData, true);
        } else {
            $appearance = $appearanceData;
        }
        
        if (!$appearance) {
            // Fallback with STRONG photorealistic prompt
            return $prompt . "friendly and approachable expression, " .
                   "PHOTOREALISTIC, hyperrealistic, professional photography, studio lighting, " .
                   "shot with Canon EOS R5, 85mm lens, natural skin texture, high resolution, " .
                   "NOT anime, NOT cartoon, NOT illustration, real human person";
        }
        
        $features = [];
        
        // Hair
        if (!empty($appearance['hair_color'])) {
            $features[] = $appearance['hair_color'] . " hair";
        }
        
        // Eyes
        if (!empty($appearance['eye_color'])) {
            $features[] = $appearance['eye_color'] . " eyes";
        }
        
        // Build
        if (!empty($appearance['build'])) {
            $buildMap = [
                'Slim' => 'slim build',
                'Average' => 'average build', 
                'Athletic' => 'athletic build',
                'Curvy' => 'curvy figure',
                'Muscular' => 'muscular build'
            ];
            if (isset($buildMap[$appearance['build']])) {
                $features[] = $buildMap[$appearance['build']];
            }
        }
        
        // Height context
        if (!empty($appearance['height'])) {
            $heightMap = [
                'Petite' => 'petite',
                'Average' => 'average height',
                'Tall' => 'tall'
            ];
            if (isset($heightMap[$appearance['height']])) {
                $features[] = $heightMap[$appearance['height']];
            }
        }
        
        // Style
        if (!empty($appearance['style'])) {
            $styleMap = [
                'Casual' => 'casual clothing style',
                'Elegant' => 'elegant and refined style',
                'Sporty' => 'sporty athletic wear',
                'Gothic' => 'gothic style clothing',
                'Vintage' => 'vintage retro style',
                'Modern' => 'modern contemporary style',
                'Bohemian' => 'bohemian free-spirited style',
                'Professional' => 'professional business attire'
            ];
            if (isset($styleMap[$appearance['style']])) {
                $features[] = $styleMap[$appearance['style']];
            }
        }
        
        // Add custom appearance details
        if (!empty($appearance['custom'])) {
            $features[] = $appearance['custom'];
        }
        
        // Combine features
        if (!empty($features)) {
            $prompt .= implode(', ', $features) . ", ";
        }
        
        // Add STRONG photorealistic quality modifiers - CRITICAL for realistic output
        $prompt .= "friendly and approachable expression, ";
        $prompt .= "PHOTOREALISTIC, hyperrealistic, ultra realistic, real person, actual human being, ";
        $prompt .= "professional headshot photography, studio lighting, commercial portrait, ";
        $prompt .= "sharp focus, highly detailed skin texture, natural skin, realistic skin pores, ";
        $prompt .= "detailed facial features, natural lighting, depth of field, ";
        $prompt .= "shot with Canon EOS R5, 85mm lens, f/1.4, professional photographer, ";
        $prompt .= "high resolution, 8K quality, cinematic lighting, perfect exposure, ";
        $prompt .= "NOT anime, NOT cartoon, NOT illustration, NOT artwork, NOT digital art, NOT stylized, ";
        $prompt .= "real photography, genuine human portrait, authentic person";
        
        return $prompt;
    }
}