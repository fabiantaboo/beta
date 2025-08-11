<?php

class BackgroundJobWorker {
    private $pdo;
    private $workerId;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->workerId = 'worker_' . bin2hex(random_bytes(8));
    }
    
    /**
     * Schedule a proactive analysis job for an AEI
     */
    public function scheduleProactiveAnalysis($aeiId, $sessionId, $userId, $executeAfter = null) {
        $jobId = $this->generateId();
        $executeAfter = $executeAfter ?: date('Y-m-d H:i:s');
        
        $jobData = [
            'aei_id' => $aeiId,
            'session_id' => $sessionId,
            'user_id' => $userId,
            'analysis_type' => 'full_trigger_analysis'
        ];
        
        $stmt = $this->pdo->prepare("
            INSERT INTO background_jobs (
                id, job_type, target_type, target_id, job_data, 
                priority, execute_after
            ) VALUES (?, 'proactive_analysis', 'aei', ?, ?, 'medium', ?)
        ");
        
        return $stmt->execute([
            $jobId, $aeiId, json_encode($jobData), $executeAfter
        ]);
    }
    
    /**
     * Schedule periodic proactive analysis for all active AEIs
     */
    public function schedulePeriodicAnalysis($delayMinutes = 30) {
        $executeAfter = date('Y-m-d H:i:s', strtotime("+$delayMinutes minutes"));
        
        // Get all active AEIs with recent activity
        $stmt = $this->pdo->query("
            SELECT DISTINCT a.id as aei_id, a.user_id, cs.id as session_id
            FROM aeis a
            JOIN chat_sessions cs ON a.id = cs.aei_id
            WHERE a.is_active = TRUE
            AND cs.last_message_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND NOT EXISTS (
                SELECT 1 FROM background_jobs bj 
                WHERE bj.target_id = a.id 
                AND bj.job_type = 'proactive_analysis'
                AND bj.status IN ('pending', 'running')
                AND bj.execute_after > DATE_SUB(NOW(), INTERVAL 2 HOUR)
            )
        ");
        
        $aeis = $stmt->fetchAll();
        $scheduled = 0;
        
        foreach ($aeis as $aei) {
            // Add some randomness to spread the load
            $randomDelay = mt_rand(0, $delayMinutes * 60); // Random seconds within delay window
            $individualExecuteTime = date('Y-m-d H:i:s', strtotime($executeAfter . " +$randomDelay seconds"));
            
            if ($this->scheduleProactiveAnalysis($aei['aei_id'], $aei['session_id'], $aei['user_id'], $individualExecuteTime)) {
                $scheduled++;
            }
        }
        
        return $scheduled;
    }
    
    /**
     * Process pending background jobs
     */
    public function processJobs($maxJobs = 10, $maxRunTimeSeconds = 300) {
        $startTime = time();
        $processedCount = 0;
        
        while ($processedCount < $maxJobs && (time() - $startTime) < $maxRunTimeSeconds) {
            // Get next job to process
            $job = $this->getNextJob();
            
            if (!$job) {
                break; // No more jobs
            }
            
            // Process the job
            $this->processJob($job);
            $processedCount++;
            
            // Small delay to prevent overwhelming the system
            usleep(100000); // 0.1 second
        }
        
        return $processedCount;
    }
    
    /**
     * Get next job to process
     */
    private function getNextJob() {
        // Use a transaction to atomically claim a job
        $this->pdo->beginTransaction();
        
        try {
            // Find the next available job
            $stmt = $this->pdo->prepare("
                SELECT * FROM background_jobs 
                WHERE status = 'pending' 
                AND execute_after <= NOW()
                AND current_attempt < max_attempts
                ORDER BY priority DESC, execute_after ASC, created_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $job = $stmt->fetch();
            
            if (!$job) {
                $this->pdo->rollback();
                return null;
            }
            
            // Claim the job
            $stmt = $this->pdo->prepare("
                UPDATE background_jobs 
                SET status = 'running', 
                    started_at = NOW(), 
                    worker_id = ?,
                    current_attempt = current_attempt + 1,
                    last_heartbeat = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$this->workerId, $job['id']]);
            
            $this->pdo->commit();
            
            // Refresh job data
            $job['status'] = 'running';
            $job['worker_id'] = $this->workerId;
            $job['current_attempt']++;
            
            return $job;
            
        } catch (Exception $e) {
            $this->pdo->rollback();
            error_log("Error claiming job: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process a specific job
     */
    private function processJob($job) {
        try {
            $this->updateHeartbeat($job['id']);
            
            switch ($job['job_type']) {
                case 'proactive_analysis':
                    $result = $this->processProactiveAnalysis($job);
                    break;
                    
                case 'social_update':
                    $result = $this->processSocialUpdate($job);
                    break;
                    
                case 'emotional_analysis':
                    $result = $this->processEmotionalAnalysis($job);
                    break;
                    
                case 'cleanup':
                    $result = $this->processCleanup($job);
                    break;
                    
                case 'emotional_decay':
                    $result = $this->processEmotionalDecay($job);
                    break;
                    
                default:
                    throw new Exception("Unknown job type: {$job['job_type']}");
            }
            
            // Mark job as completed
            $this->markJobCompleted($job['id'], $result);
            
        } catch (Exception $e) {
            error_log("Job processing error ({$job['id']}): " . $e->getMessage());
            $this->markJobFailed($job['id'], $e->getMessage());
        }
    }
    
    /**
     * Process proactive analysis job
     */
    private function processProactiveAnalysis($job) {
        $jobData = json_decode($job['job_data'], true);
        
        include_once __DIR__ . '/proactive_messaging.php';
        $proactiveMessaging = new ProactiveMessaging($this->pdo);
        
        // Check if this is a decay-triggered analysis
        if ($jobData['analysis_type'] === 'decay_triggered_analysis') {
            // Process decay-specific proactive messaging
            $messages = $this->processDecayTriggeredAnalysis($jobData, $proactiveMessaging);
        } else {
            // Run normal analysis
            $messages = $proactiveMessaging->analyzeAndGenerateProactiveMessages(
                $jobData['aei_id'],
                $jobData['session_id'],
                $jobData['user_id']
            );
        }
        
        return [
            'generated_messages' => count($messages),
            'messages' => $messages,
            'analysis_type' => $jobData['analysis_type'] ?? 'normal'
        ];
    }
    
    /**
     * Process decay-triggered proactive analysis
     */
    private function processDecayTriggeredAnalysis($jobData, $proactiveMessaging) {
        // Generate proactive message with forced decay trigger
        $context = [
            'session_id' => $jobData['session_id'],
            'hours_inactive' => $jobData['hours_inactive']
        ];
        
        // Use the forced trigger from emotional decay
        $trigger = $jobData['forced_trigger'];
        
        // Generate and send the message immediately
        $aei = $this->getAEIInfo($jobData['aei_id']);
        
        try {
            // Generate AI message using decay context
            $messageText = $this->generateDecayBasedMessage($aei, $trigger, $context);
            
            // Skip sending message if decay system is disabled (returns null)
            if ($messageText === null) {
                error_log("Decay message system disabled - proactive system will handle loneliness");
                return ['status' => 'disabled', 'message' => 'Decay system disabled'];
            }
            
            // Send directly to chat as AEI message
            $aeiMessageId = $this->generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_messages (id, session_id, sender_type, message_text) 
                VALUES (?, ?, 'aei', ?)
            ");
            $stmt->execute([
                $aeiMessageId, 
                $jobData['session_id'], 
                $messageText
            ]);
            
            // Store proactive message record for analytics
            $proactiveMessageId = $this->generateId();
            
            $stmt = $this->pdo->prepare("
                INSERT INTO aei_proactive_messages (
                    id, aei_id, session_id, trigger_type, trigger_details, 
                    trigger_strength, message_text, message_tone, 
                    status, sent_at, chat_message_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW(), ?)
            ");
            
            $stmt->execute([
                $proactiveMessageId,
                $jobData['aei_id'],
                $jobData['session_id'],
                $trigger['type'],
                json_encode($trigger['details']),
                $trigger['strength'],
                $messageText,
                $this->getMessageToneForTrigger($trigger),
                $aeiMessageId
            ]);
            
            return [[
                'id' => $proactiveMessageId,
                'chat_message_id' => $aeiMessageId,
                'message' => $messageText,
                'trigger_type' => 'emotional_decay',
                'sent_immediately' => true
            ]];
            
        } catch (Exception $e) {
            error_log("Error in decay-triggered analysis: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Generate decay-based message - disabled as proactive system handles this
     */
    private function generateDecayBasedMessage($aei, $trigger, $context) {
        // Disabled: The proactive system should handle loneliness messages
        // with AEI personality-generated content instead of hardcoded messages
        return null;
    }
    
    /**
     * Get message tone for trigger type
     */
    private function getMessageToneForTrigger($trigger) {
        $toneMap = [
            'loneliness_decay' => 'caring',
            'emotional_distress' => 'concerned',
            'abandonment_fear' => 'worried'
        ];
        
        return $toneMap[$trigger['subtype']] ?? 'caring';
    }
    
    /**
     * Get AEI info for message generation
     */
    private function getAEIInfo($aeiId) {
        $stmt = $this->pdo->prepare("
            SELECT a.*, u.first_name as user_name
            FROM aeis a
            JOIN users u ON a.user_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$aeiId]);
        
        return $stmt->fetch();
    }
    
    /**
     * Process social update job
     */
    private function processSocialUpdate($job) {
        // This would update social contexts, run social AI, etc.
        // For now, just return success
        return ['status' => 'completed', 'updates' => 0];
    }
    
    /**
     * Process emotional analysis job
     */
    private function processEmotionalAnalysis($job) {
        // This would run emotional pattern analysis
        // For now, just return success
        return ['status' => 'completed', 'analysis' => []];
    }
    
    /**
     * Process cleanup job
     */
    private function processCleanup($job) {
        include_once __DIR__ . '/proactive_messaging.php';
        $proactiveMessaging = new ProactiveMessaging($this->pdo);
        
        $cleanedCount = $proactiveMessaging->cleanupExpiredMessages();
        
        // Also cleanup old completed jobs
        $stmt = $this->pdo->prepare("
            DELETE FROM background_jobs 
            WHERE status IN ('completed', 'failed') 
            AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        $stmt->execute();
        $cleanedJobs = $stmt->rowCount();
        
        return [
            'expired_messages' => $cleanedCount,
            'old_jobs' => $cleanedJobs
        ];
    }
    
    /**
     * Update job heartbeat
     */
    private function updateHeartbeat($jobId) {
        $stmt = $this->pdo->prepare("
            UPDATE background_jobs 
            SET last_heartbeat = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
    }
    
    /**
     * Mark job as completed
     */
    private function markJobCompleted($jobId, $result) {
        $stmt = $this->pdo->prepare("
            UPDATE background_jobs 
            SET status = 'completed', 
                completed_at = NOW(), 
                result_data = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($result), $jobId]);
    }
    
    /**
     * Mark job as failed
     */
    private function markJobFailed($jobId, $errorMessage) {
        $stmt = $this->pdo->prepare("
            UPDATE background_jobs 
            SET status = 'failed', 
                completed_at = NOW(), 
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$errorMessage, $jobId]);
    }
    
    /**
     * Process emotional decay job
     */
    private function processEmotionalDecay($job) {
        include_once __DIR__ . '/emotional_decay.php';
        $emotionalDecay = new EmotionalDecay($this->pdo);
        
        $processedCount = $emotionalDecay->processEmotionalDecayForAllAEIs();
        
        return [
            'processed_sessions' => $processedCount,
            'status' => 'completed'
        ];
    }
    
    /**
     * Schedule emotional decay processing
     */
    public function scheduleEmotionalDecayProcessing($delayHours = 1) {
        $executeAfter = date('Y-m-d H:i:s', strtotime("+$delayHours hours"));
        
        // Check if decay job already exists for the next few hours
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM background_jobs 
            WHERE job_type = 'emotional_decay' 
            AND status IN ('pending', 'running')
            AND execute_after >= NOW()
            AND execute_after <= DATE_ADD(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$delayHours + 1]);
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return false; // Already scheduled
        }
        
        $jobId = $this->generateId();
        
        $stmt = $this->pdo->prepare("
            INSERT INTO background_jobs (
                id, job_type, target_type, job_data, 
                priority, execute_after
            ) VALUES (?, 'emotional_decay', 'system', '{}', 'medium', ?)
        ");
        
        return $stmt->execute([$jobId, $executeAfter]);
    }
    
    /**
     * Schedule daily cleanup job
     */
    public function scheduleDailyCleanup() {
        // Check if cleanup job already exists for today
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count FROM background_jobs 
            WHERE job_type = 'cleanup' 
            AND status IN ('pending', 'running')
            AND DATE(scheduled_at) = CURDATE()
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        if ($result['count'] > 0) {
            return false; // Already scheduled for today
        }
        
        $jobId = $this->generateId();
        
        // Schedule for next night (2 AM)
        $tomorrow2AM = date('Y-m-d 02:00:00', strtotime('tomorrow'));
        
        $stmt = $this->pdo->prepare("
            INSERT INTO background_jobs (
                id, job_type, target_type, job_data, 
                priority, execute_after
            ) VALUES (?, 'cleanup', 'system', '{}', 'low', ?)
        ");
        
        return $stmt->execute([$jobId, $tomorrow2AM]);
    }
    
    /**
     * Get job statistics
     */
    public function getJobStats() {
        $stmt = $this->pdo->query("
            SELECT 
                job_type,
                status,
                COUNT(*) as count,
                AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_duration_seconds
            FROM background_jobs 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY job_type, status
            ORDER BY job_type, status
        ");
        
        return $stmt->fetchAll();
    }
    
    /**
     * Detect and recover stuck jobs
     */
    public function recoverStuckJobs($timeoutMinutes = 15) {
        $stmt = $this->pdo->prepare("
            UPDATE background_jobs 
            SET status = 'pending', 
                worker_id = NULL,
                error_message = CONCAT(COALESCE(error_message, ''), ' [Recovered from stuck state]')
            WHERE status = 'running' 
            AND last_heartbeat < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            AND current_attempt < max_attempts
        ");
        
        $stmt->execute([$timeoutMinutes]);
        return $stmt->rowCount();
    }
    
    private function generateId() {
        return bin2hex(random_bytes(16));
    }
}

/**
 * Simple job scheduler that can be called from cron
 */
class ProactiveJobScheduler {
    private $pdo;
    private $worker;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->worker = new BackgroundJobWorker($pdo);
    }
    
    /**
     * Run the main scheduling and processing loop
     */
    public function run($options = []) {
        $maxJobs = $options['max_jobs'] ?? 20;
        $maxRunTime = $options['max_run_time'] ?? 300; // 5 minutes
        $scheduleNew = $options['schedule_new'] ?? true;
        
        error_log("ProactiveJobScheduler: Starting run (max_jobs: $maxJobs, max_run_time: $maxRunTime)");
        
        // Recover stuck jobs first
        $recovered = $this->worker->recoverStuckJobs();
        if ($recovered > 0) {
            error_log("ProactiveJobScheduler: Recovered $recovered stuck jobs");
        }
        
        // Schedule new jobs if enabled
        if ($scheduleNew) {
            $scheduled = $this->worker->schedulePeriodicAnalysis();
            error_log("ProactiveJobScheduler: Scheduled $scheduled new proactive analysis jobs");
            
            // Schedule daily cleanup
            if ($this->worker->scheduleDailyCleanup()) {
                error_log("ProactiveJobScheduler: Scheduled daily cleanup job");
            }
            
            // Schedule emotional decay processing
            if ($this->worker->scheduleEmotionalDecayProcessing()) {
                error_log("ProactiveJobScheduler: Scheduled emotional decay processing job");
            }
        }
        
        // Process existing jobs
        $processed = $this->worker->processJobs($maxJobs, $maxRunTime);
        error_log("ProactiveJobScheduler: Processed $processed jobs");
        
        return [
            'recovered' => $recovered,
            'scheduled' => $scheduled ?? 0,
            'processed' => $processed
        ];
    }
    
    /**
     * Get system status
     */
    public function getStatus() {
        $stats = $this->worker->getJobStats();
        
        $stmt = $this->pdo->query("
            SELECT 
                COUNT(*) as total_pending,
                COUNT(CASE WHEN execute_after <= NOW() THEN 1 END) as ready_to_run,
                COUNT(CASE WHEN status = 'running' THEN 1 END) as currently_running,
                MIN(execute_after) as next_job_time
            FROM background_jobs 
            WHERE status = 'pending'
        ");
        $pending = $stmt->fetch();
        
        return [
            'stats' => $stats,
            'pending' => $pending,
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}