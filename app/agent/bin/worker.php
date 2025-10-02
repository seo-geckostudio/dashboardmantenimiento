#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use WpOps\Agent\Database\Connection;
use WpOps\Agent\Jobs\JobProcessor;
use WpOps\Agent\Logger\Logger;
use WpOps\Agent\Utils\CommandExecutor;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize components
$logger = new Logger('worker');
$db = Connection::getInstance();
$executor = new CommandExecutor();
$processor = new JobProcessor($db, $logger, $executor);

$logger->info('Worker started');

// Main worker loop
while (true) {
    try {
        // Get pending jobs
        $stmt = $db->prepare("
            SELECT * FROM jobs 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT 1
        ");
        $stmt->execute();
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job) {
            // No jobs available, sleep for a bit
            sleep(5);
            continue;
        }
        
        $jobId = $job['id'];
        $logger->info("Processing job {$jobId}", [
            'module' => $job['module'],
            'action' => $job['action'],
            'site_id' => $job['site_id']
        ]);
        
        // Mark job as running
        $stmt = $db->prepare("
            UPDATE jobs 
            SET status = 'running', started_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$jobId]);
        
        // Process the job
        $result = $processor->processJob($job);
        
        // Update job status based on result
        if ($result['success'] ?? true) {
            $stmt = $db->prepare("
                UPDATE jobs 
                SET status = 'completed', completed_at = NOW(), result = ? 
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result), $jobId]);
            
            $logger->info("Job {$jobId} completed successfully");
        } else {
            $stmt = $db->prepare("
                UPDATE jobs 
                SET status = 'failed', completed_at = NOW(), result = ? 
                WHERE id = ?
            ");
            $stmt->execute([json_encode($result), $jobId]);
            
            $logger->error("Job {$jobId} failed", ['error' => $result['error'] ?? 'Unknown error']);
        }
        
    } catch (Exception $e) {
        $logger->error('Worker error', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        
        // Mark current job as failed if we have one
        if (isset($jobId)) {
            try {
                $stmt = $db->prepare("
                    UPDATE jobs 
                    SET status = 'failed', completed_at = NOW(), result = ? 
                    WHERE id = ?
                ");
                $stmt->execute([json_encode(['error' => $e->getMessage()]), $jobId]);
            } catch (Exception $updateError) {
                $logger->error('Failed to update job status', ['error' => $updateError->getMessage()]);
            }
        }
        
        // Sleep before retrying
        sleep(10);
    }
}
