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
    
    public function generateAvatar($prompt, $aspectRatio = '1:1', $guidanceScale = 3, $numOutputs = 1) {
        if (!$this->apiToken) {
            throw new Exception("Replicate API token not configured");
        }
        
        $data = [
            'version' => 'black-forest-labs/flux-dev',
            'input' => [
                'prompt' => $prompt,
                'aspect_ratio' => $aspectRatio,
                'guidance' => $guidanceScale,
                'num_outputs' => $numOutputs,
                'output_format' => 'png',
                'megapixels' => '1'
            ]
        ];
        
        return $this->makeRequest('POST', '/predictions', $data);
    }
    
    public function generateMultipleAvatars($prompt, $count = 3, $aspectRatio = '1:1') {
        try {
            error_log("Replicate: Starting generation of $count avatars with prompt: " . $prompt);
            
            // Start the prediction for multiple outputs
            $prediction = $this->generateAvatar($prompt, $aspectRatio, 3, $count);
            
            if (!isset($prediction['id'])) {
                throw new Exception("Failed to start image generation: " . json_encode($prediction));
            }
            
            error_log("Replicate: Prediction started with ID: " . $prediction['id']);
            
            // Wait for completion
            $completedPrediction = $this->waitForCompletion($prediction['id']);
            
            if (!isset($completedPrediction['output']) || empty($completedPrediction['output'])) {
                throw new Exception("No output images generated");
            }
            
            // Return all generated image URLs
            $imageUrls = $completedPrediction['output'];
            if (!is_array($imageUrls)) {
                $imageUrls = [$imageUrls];
            }
            
            error_log("Replicate: Generated " . count($imageUrls) . " images successfully");
            return $imageUrls;
            
        } catch (Exception $e) {
            error_log("Replicate: Multiple avatar generation failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function downloadAndSaveAvatars($imageUrls, $baseDir, $baseFilename) {
        $savedPaths = [];
        
        foreach ($imageUrls as $index => $imageUrl) {
            try {
                // Create filename with index
                $filename = $baseFilename . '_' . ($index + 1) . '.png';
                $targetPath = $baseDir . $filename;
                
                // Download the image
                $imageData = $this->downloadImage($imageUrl);
                
                // Ensure directory exists
                if (!file_exists($baseDir)) {
                    mkdir($baseDir, 0755, true);
                }
                
                // Save the image
                if (file_put_contents($targetPath, $imageData) !== false) {
                    $savedPaths[] = [
                        'path' => $targetPath,
                        'url' => '/assets/avatars/' . $filename,
                        'index' => $index + 1
                    ];
                    error_log("Replicate: Avatar $filename saved successfully");
                } else {
                    error_log("Replicate: Failed to save avatar $filename");
                }
                
            } catch (Exception $e) {
                error_log("Replicate: Failed to download avatar " . ($index + 1) . ": " . $e->getMessage());
            }
        }
        
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
        $prompt = "Portrait of ";
        
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
            // Fallback basic prompt
            return $prompt . "friendly and approachable, high quality portrait, professional lighting, detailed face";
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
        
        // Add quality and style modifiers
        $prompt .= "friendly and approachable expression, high quality portrait, professional lighting, detailed face, photorealistic, sharp focus, 4k quality";
        
        return $prompt;
    }
}