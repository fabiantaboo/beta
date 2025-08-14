<?php

/**
 * AEI Memory System Configuration 2025 - Qdrant Inference Only
 * NO OpenAI dependencies - everything runs on Qdrant Cloud!
 * 
 * Copy this file to memory_config.php and update with your Qdrant API keys
 */

// Qdrant Cloud Configuration
define('QDRANT_URL', 'https://your-cluster-id.us-east.aws.cloud.qdrant.io:6333');
define('QDRANT_API_KEY', 'your-qdrant-api-key-here');

// Embedding Model Configuration (2025 Qdrant Inference Models)
define('MEMORY_DEFAULT_MODEL', 'sentence-transformers/all-MiniLM-L6-v2');    // 384d - Fast, efficient
define('MEMORY_QUALITY_MODEL', 'mixedbread-ai/mxbai-embed-large-v1');       // 1024d - Best quality
define('MEMORY_SPARSE_MODEL', 'bm25');                                       // Sparse - Unlimited free

// Memory System Settings
define('MEMORY_COLLECTION_PREFIX', 'aei_memories_');
define('MEMORY_EXTRACTION_ENABLED', true);
define('MEMORY_CONTEXT_LIMIT', 5);           // Number of memories to include in context
define('MEMORY_IMPORTANCE_THRESHOLD', 0.3);  // Minimum importance for context inclusion
define('MEMORY_CLEANUP_DAYS', 90);           // Days to keep low-importance memories
define('MEMORY_DEBUG', false);               // Enable detailed logging

// Model Selection Thresholds
define('MEMORY_QUALITY_THRESHOLD', 0.7);     // Use quality model above this importance
define('MEMORY_BATCH_SIZE', 50);             // Batch size for memory operations

// Performance Settings
define('MEMORY_SEARCH_TIMEOUT', 10);         // Search timeout in seconds
define('MEMORY_MAX_RETRIES', 3);             // Max retries for failed operations

/**
 * Available Qdrant Inference Models 2025:
 * 
 * Dense Models (Semantic Search):
 * - sentence-transformers/all-MiniLM-L6-v2 (384 dimensions) - Fast, good quality
 * - mixedbread-ai/mxbai-embed-large-v1 (1024 dimensions) - Best quality, slower
 * - BAAI/bge-small-en-v1.5 (384 dimensions) - English-optimized
 * - jinaai/jina-embeddings-v2-small-en (512 dimensions) - Small text optimized
 * 
 * Sparse Models (Keyword-like Search):
 * - bm25 - Traditional keyword search (UNLIMITED FREE)
 * - splade-pp-en-v1 - Learned sparse embeddings
 * 
 * Multimodal:
 * - qdrant/clip-vit-b-32-vision - Text + Image embeddings
 * 
 * Free Allowances (per paid cluster):
 * - 5 Million tokens/month for text models
 * - 1 Million tokens/month for image models  
 * - Unlimited BM25 usage
 */

// Memory Type Definitions
define('MEMORY_TYPES', [
    'fact' => 'Factual information about the user',
    'event' => 'Important events or experiences',
    'emotion' => 'Emotional states and feelings',
    'preference' => 'User preferences and likes/dislikes', 
    'relationship' => 'Information about people in user\'s life',
    'goal' => 'User aspirations, plans, and dreams',
    'concern' => 'Worries, fears, and problems'
]);

// Importance Score Guidelines
define('MEMORY_IMPORTANCE_LEVELS', [
    'critical' => 0.9,    // Life-changing events, core identity
    'high' => 0.7,        // Important personal info, significant events
    'medium' => 0.5,      // Preferences, minor events, casual mentions
    'low' => 0.3,         // Small talk, temporary states
    'minimal' => 0.1      // Background context, rarely accessed
]);