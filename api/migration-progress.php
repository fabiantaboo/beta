<?php
/**
 * Migration Progress API
 * Returns progress status for parallel migration jobs
 */

// Use centralized session configuration
include_once '../includes/session_config.php';
include_once '../config/database.php';
include_once '../includes/functions.php';

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

$jobId = $input['job_id'] ?? null;
if (!$jobId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing job_id']);
    exit;
}

try {
    // Get job status from database (main job + workers)
    $stmt = $pdo->prepare("
        SELECT status, message, progress_current, progress_total, created_at, updated_at, completed_at
        FROM migration_jobs 
        WHERE job_id = ? AND user_id = ?
    ");
    $stmt->execute([$jobId, getUserSession()]);
    $job = $stmt->fetch();
    
    if (!$job) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }
    
    // Also check worker jobs if this is a parallel migration
    $workerJobs = [];
    $stmt = $pdo->prepare("
        SELECT job_id, status, message, progress_current, progress_total, completed_at
        FROM migration_jobs 
        WHERE job_id LIKE ? AND user_id = ?
    ");
    $stmt->execute([$jobId . '_worker_%', getUserSession()]);
    $workerJobs = $stmt->fetchAll();
    
    // If we have worker jobs, aggregate their progress
    if (!empty($workerJobs)) {
        $completedWorkers = 0;
        $totalWorkers = count($workerJobs);
        $overallMessage = "Running $totalWorkers parallel workers...";
        
        foreach ($workerJobs as $worker) {
            if ($worker['status'] === 'completed' || $worker['status'] === 'completed_with_errors') {
                $completedWorkers++;
            }
        }
        
        // Update main job progress based on worker completion
        $job['progress_current'] = $completedWorkers;
        $job['progress_total'] = $totalWorkers;
        $job['message'] = "$completedWorkers of $totalWorkers workers completed";
        
        // If all workers are done, mark main job as completed
        if ($completedWorkers === $totalWorkers) {
            $job['status'] = 'completed';
            $job['message'] = "All $totalWorkers parallel workers completed successfully";
        }
    }
    
    // Calculate elapsed time
    $startTime = new DateTime($job['created_at']);
    $currentTime = new DateTime();
    $elapsed = $currentTime->diff($startTime);
    
    $elapsedSeconds = $elapsed->s + ($elapsed->i * 60) + ($elapsed->h * 3600);
    
    // Estimate completion time
    $eta = null;
    if ($job['progress_current'] > 0 && $job['progress_total'] > 0) {
        $progressRatio = $job['progress_current'] / $job['progress_total'];
        $estimatedTotalTime = $elapsedSeconds / $progressRatio;
        $etaSeconds = $estimatedTotalTime - $elapsedSeconds;
        
        if ($etaSeconds > 0) {
            $etaMinutes = floor($etaSeconds / 60);
            $etaSecondsRemainder = $etaSeconds % 60;
            $eta = sprintf('%dm %ds', $etaMinutes, $etaSecondsRemainder);
        }
    }
    
    // Return progress data
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'status' => $job['status'],
        'message' => $job['message'],
        'progress_current' => (int)$job['progress_current'],
        'progress_total' => (int)$job['progress_total'],
        'created_at' => $job['created_at'],
        'updated_at' => $job['updated_at'],
        'completed_at' => $job['completed_at'],
        'elapsed_time' => sprintf('%02d:%02d:%02d', $elapsed->h, $elapsed->i, $elapsed->s),
        'eta' => $eta
    ]);
    
} catch (Exception $e) {
    error_log("Migration progress API error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to get job progress'
    ]);
}
?>