<?php
/**
 * Background Memory Migration Worker
 * Processes memory migration jobs in parallel
 */

// Use centralized session configuration
include_once '../includes/session_config.php';
include_once '../config/database.php';
include_once '../includes/functions.php';
include_once '../includes/anthropic_api.php';

// Prevent output buffering
if (ob_get_level()) ob_end_clean();

// Set headers for JSON API
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Check authentication
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON input']);
    exit;
}

// Validate CSRF token
if (!verifyCSRFToken($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// Validate required fields
$jobId = $input['job_id'] ?? null;
$batchData = $input['batch_data'] ?? null;

if (!$jobId || !$batchData) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job_id or batch_data']);
    exit;
}

try {
    // Load memory configuration
    if (!file_exists(__DIR__ . '/../config/memory_config.php')) {
        throw new Exception('Memory system not configured');
    }
    
    require_once __DIR__ . '/../config/memory_config.php';
    require_once __DIR__ . '/../includes/memory_manager_inference.php';
    
    if (!defined('QDRANT_URL') || !defined('QDRANT_API_KEY')) {
        throw new Exception('Qdrant configuration missing');
    }
    
    // Initialize memory manager
    $memoryOptions = [
        'default_model' => MEMORY_DEFAULT_MODEL,
        'quality_model' => MEMORY_QUALITY_MODEL,
        'collection_prefix' => 'aei_memories_',
        'facts_prefix' => 'aei_facts_'
    ];
    
    $memoryManager = new MemoryManagerInference(QDRANT_URL, QDRANT_API_KEY, $pdo, $memoryOptions);
    
    // Create worker job in database
    try {
        $stmt = $pdo->prepare("
            INSERT INTO migration_jobs (job_id, user_id, job_type, status, message, progress_total) 
            VALUES (?, ?, 'memory_migration_worker', 'processing', 'Processing batch...', ?)
        ");
        $stmt->execute([$jobId, getUserSession(), count($batchData)]);
    } catch (Exception $e) {
        // Job might already exist, just update status
        updateJobStatus($pdo, $jobId, 'processing', 'Processing batch...');
    }
    
    $results = [];
    $totalExtracted = 0;
    $errors = [];
    $processedCount = 0;
    
    // Process each AEI in the batch
    foreach ($batchData as $aeiIndex => $aeiData) {
        $aeiId = $aeiData['aei_id'];
        $aeiName = $aeiData['aei_name'];
        $userId = $aeiData['user_id'];
        $sessions = $aeiData['sessions'];
        $batchSize = $aeiData['batch_size'];
        
        try {
            $aeiExtractedCount = 0;
            
            foreach ($sessions as $session) {
                // Get chat messages for this session
                $stmt = $pdo->prepare("
                    SELECT sender_type, message_text, created_at
                    FROM chat_messages 
                    WHERE session_id = ? 
                    ORDER BY created_at ASC
                ");
                $stmt->execute([$session['session_id']]);
                $chatMessages = $stmt->fetchAll();
                
                // Process in batches
                $batches = array_chunk($chatMessages, $batchSize);
                
                foreach ($batches as $batch) {
                    if (count($batch) < 3) continue;
                    
                    try {
                        // Convert to proper format
                        $formattedMessages = [];
                        foreach ($batch as $msg) {
                            $formattedMessages[] = [
                                'role' => $msg['sender_type'] === 'user' ? 'user' : 'assistant',
                                'content' => $msg['message_text']
                            ];
                        }
                        
                        // Extract memories from this batch
                        $extractedMemories = $memoryManager->extractMemoriesFromConversation(
                            $aeiId,
                            $formattedMessages,
                            $userId,
                            $session['session_id']
                        );
                        
                        $aeiExtractedCount += count($extractedMemories);
                        
                        // Small delay to avoid overwhelming APIs
                        usleep(500000); // 0.5 seconds
                        
                    } catch (Exception $batchError) {
                        $errors[] = "Batch error for AEI {$aeiName}: " . $batchError->getMessage();
                        error_log("Migration batch error: " . $batchError->getMessage());
                    }
                }
            }
            
            $totalExtracted += $aeiExtractedCount;
            $processedCount++;
            
            $results[] = [
                'aei_id' => $aeiId,
                'aei_name' => $aeiName,
                'extracted_count' => $aeiExtractedCount,
                'status' => 'completed'
            ];
            
            // Update progress
            updateJobProgress($pdo, $jobId, $processedCount, count($batchData), "Processed {$aeiName} - extracted {$aeiExtractedCount} facts");
            
        } catch (Exception $aeiError) {
            $processedCount++;
            $errors[] = "AEI error for {$aeiName}: " . $aeiError->getMessage();
            $results[] = [
                'aei_id' => $aeiId,
                'aei_name' => $aeiName,
                'extracted_count' => 0,
                'status' => 'failed',
                'error' => $aeiError->getMessage()
            ];
            
            // Update progress even for failed AEI
            updateJobProgress($pdo, $jobId, $processedCount, count($batchData), "Failed processing {$aeiName}: " . $aeiError->getMessage());
        }
    }
    
    // Update job status to completed
    $status = empty($errors) ? 'completed' : 'completed_with_errors';
    $message = "Processed " . count($batchData) . " AEIs, extracted {$totalExtracted} facts";
    if (!empty($errors)) {
        $message .= " (with " . count($errors) . " errors)";
    }
    
    updateJobStatus($pdo, $jobId, $status, $message);
    
    // Return results
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'results' => $results,
        'total_extracted' => $totalExtracted,
        'errors' => $errors
    ]);
    
} catch (Exception $e) {
    // Update job status to failed
    updateJobStatus($pdo, $jobId, 'failed', $e->getMessage());
    
    error_log("Migration worker error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

/**
 * Update job status in database
 */
function updateJobStatus($pdo, $jobId, $status, $message) {
    try {
        $stmt = $pdo->prepare("
            UPDATE migration_jobs 
            SET status = ?, message = ?, updated_at = NOW() 
            WHERE job_id = ?
        ");
        $stmt->execute([$status, $message, $jobId]);
    } catch (Exception $e) {
        error_log("Failed to update job status: " . $e->getMessage());
    }
}

/**
 * Update job progress in database
 */
function updateJobProgress($pdo, $jobId, $current, $total, $message) {
    try {
        $stmt = $pdo->prepare("
            UPDATE migration_jobs 
            SET progress_current = ?, progress_total = ?, message = ?, updated_at = NOW() 
            WHERE job_id = ?
        ");
        $stmt->execute([$current, $total, $message, $jobId]);
    } catch (Exception $e) {
        error_log("Failed to update job progress: " . $e->getMessage());
    }
}
?>