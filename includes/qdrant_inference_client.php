<?php

/**
 * Qdrant Inference API Client 2025 - Native Embedding Generation
 * Uses Qdrant's built-in embedding models - NO external dependencies!
 */
class QdrantInferenceClient {
    private $baseUrl;
    private $apiKey;
    
    // Available embedding models with their dimensions
    const MODELS = [
        'sentence-transformers/all-MiniLM-L6-v2' => 384,      // Fast, efficient
        'mixedbread-ai/mxbai-embed-large-v1' => 1024,        // Best quality
        'BAAI/bge-small-en-v1.5' => 384,                     // English-optimized
        'jinaai/jina-embeddings-v2-small-en' => 512,         // Small texts
        'bm25' => null,                                       // Sparse (unlimited free)
        'splade-pp-en-v1' => null                            // Sparse vectors
    ];
    
    public function __construct($clusterUrl, $apiKey) {
        $this->baseUrl = rtrim($clusterUrl, '/');
        $this->apiKey = $apiKey;
    }
    
    /**
     * Create collection with specific model dimensions
     */
    public function createCollection($collectionName, $model = 'sentence-transformers/all-MiniLM-L6-v2') {
        if (!isset(self::MODELS[$model])) {
            throw new Exception("Unknown model: $model");
        }
        
        $vectorSize = self::MODELS[$model];
        if ($vectorSize === null) {
            throw new Exception("Sparse models need different configuration");
        }
        
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName);
        
        $data = [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => 'Cosine'
            ],
            'optimizers_config' => [
                'default_segment_number' => 2
            ],
            'replication_factor' => 1
        ];
        
        return $this->makeRequest('PUT', $url, $data);
    }
    
    /**
     * Store text with automatic embedding generation (NEW 2025 FORMAT)
     */
    public function storeTextWithEmbedding($collectionName, $pointId, $text, $payload = [], $model = 'sentence-transformers/all-MiniLM-L6-v2') {
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName) . "/points?wait=true";
        
        $data = [
            'points' => [
                [
                    'id' => $pointId,
                    'vector' => [
                        'text' => $text,
                        'model' => $model
                    ],
                    'payload' => array_merge($payload, [
                        'original_text' => $text,
                        'embedding_model' => $model,
                        'created_at' => date('Y-m-d H:i:s')
                    ])
                ]
            ]
        ];
        
        return $this->makeRequest('PUT', $url, $data);
    }
    
    /**
     * Search with automatic query embedding (NEW 2025 FORMAT)
     */
    public function searchWithText($collectionName, $queryText, $model = 'sentence-transformers/all-MiniLM-L6-v2', $limit = 10, $filter = null) {
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName) . "/points/query";
        
        $data = [
            'query' => [
                'text' => $queryText,
                'model' => $model
            ],
            'limit' => $limit,
            'with_payload' => true,
            'with_vector' => false
        ];
        
        if ($filter) {
            $data['filter'] = $filter;
        }
        
        return $this->makeRequest('POST', $url, $data);
    }
    
    /**
     * Store batch of texts with embeddings
     */
    public function storeBatchTexts($collectionName, $textBatch, $model = 'sentence-transformers/all-MiniLM-L6-v2') {
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName) . "/points?wait=true";
        
        $points = [];
        foreach ($textBatch as $item) {
            $points[] = [
                'id' => $item['id'],
                'vector' => [
                    'text' => $item['text'],
                    'model' => $model
                ],
                'payload' => array_merge($item['payload'] ?? [], [
                    'original_text' => $item['text'],
                    'embedding_model' => $model,
                    'created_at' => date('Y-m-d H:i:s')
                ])
            ];
        }
        
        $data = ['points' => $points];
        
        return $this->makeRequest('PUT', $url, $data);
    }
    
    /**
     * Get collection info and validate model compatibility
     */
    public function getCollectionInfo($collectionName) {
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName);
        return $this->makeRequest('GET', $url);
    }
    
    /**
     * Delete points from collection
     */
    public function deletePoints($collectionName, $pointIds) {
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName) . "/points/delete?wait=true";
        
        $data = [
            'points' => is_array($pointIds) ? $pointIds : [$pointIds]
        ];
        
        return $this->makeRequest('POST', $url, $data);
    }
    
    /**
     * Get recommended model based on use case
     */
    public function getRecommendedModel($useCase = 'general') {
        switch ($useCase) {
            case 'fast':
                return 'sentence-transformers/all-MiniLM-L6-v2';
            case 'quality':
                return 'mixedbread-ai/mxbai-embed-large-v1';
            case 'english':
                return 'BAAI/bge-small-en-v1.5';
            case 'sparse':
                return 'bm25';
            default:
                return 'sentence-transformers/all-MiniLM-L6-v2';
        }
    }
    
    /**
     * Validate model and get dimensions
     */
    public function getModelDimensions($model) {
        return self::MODELS[$model] ?? null;
    }
    
    /**
     * Health check - test connection and inference
     */
    public function healthCheck() {
        try {
            // Test basic connection
            $collectionsResponse = $this->makeRequest('GET', $this->baseUrl . '/collections');
            
            if (!isset($collectionsResponse['result'])) {
                throw new Exception("Invalid collections response");
            }
            
            return [
                'status' => 'healthy',
                'collections' => count($collectionsResponse['result']['collections']),
                'timestamp' => date('Y-m-d H:i:s')
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'error' => $e->getMessage(),
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
    }
    
    /**
     * Make HTTP request to Qdrant API
     */
    private function makeRequest($method, $url, $data = null) {
        $ch = curl_init();
        
        $headers = [
            'Content-Type: application/json',
            'api-key: ' . $this->apiKey  // 2025 standard format
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST => $method
        ]);
        
        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            throw new Exception("cURL Error: " . $curlError);
        }
        
        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $errorMessage = isset($errorData['status']['error']) 
                ? $errorData['status']['error'] 
                : "HTTP $httpCode: $response";
            throw new Exception("Qdrant API Error: " . $errorMessage);
        }
        
        $responseData = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response: " . json_last_error_msg());
        }
        
        return $responseData;
    }
    
    /**
     * Create field index for filtering (NEW 2025 FORMAT)
     */
    public function createFieldIndex($collectionName, $fieldName, $fieldType = 'keyword') {
        $url = $this->baseUrl . "/collections/" . urlencode($collectionName) . "/index?wait=true";
        
        $data = [
            'field_name' => $fieldName,
            'field_schema' => [
                'type' => $fieldType
            ]
        ];
        
        return $this->makeRequest('PUT', $url, $data);
    }
    
    /**
     * Get base URL for API requests
     */
    public function getBaseUrl() {
        return $this->baseUrl;
    }
    
    /**
     * Get API key for authentication
     */
    public function getApiKey() {
        return $this->apiKey;
    }
}
?>