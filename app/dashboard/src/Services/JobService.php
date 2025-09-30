<?php

namespace WpOps\Dashboard\Services;

use WpOps\Dashboard\Database\Connection;
use PDO;

class JobService
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance()->getPdo();
    }

    /**
     * Create a new job
     */
    public function createJob($serverId, $module, $action, $params = [], $siteId = null): string
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO jobs (site_id, server_id, module, action, params_json, status, created_at) 
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())
        ");
        
        $stmt->execute([
            $siteId,
            $serverId,
            $module,
            $action,
            json_encode($params)
        ]);
        
        return $this->pdo->lastInsertId();
    }

    /**
     * Get job by ID
     */
    public function getJobById($jobId) {
        $stmt = $this->pdo->prepare("
            SELECT j.*, s.name as server_name, si.path as site_path
            FROM jobs j 
            LEFT JOIN servers s ON j.server_id = s.id 
            LEFT JOIN sites si ON j.site_id = si.id
            WHERE j.id = ?
        ");
        
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($job) {
            $job['params_json'] = json_decode($job['params_json'], true);
        }
        
        return $job;
    }

    /**
     * Update job status
     */
    public function updateJobStatus($jobId, $status, $result = null, $errorMessage = null) {
        $sql = "UPDATE jobs SET status = ?, finished_at = NOW()";
        $params = [$status];
        
        if ($result !== null) {
            $sql .= ", output = ?";
            $params[] = is_array($result) ? json_encode($result) : $result;
        }
        
        if ($errorMessage !== null) {
            $sql .= ", error_message = ?";
            $params[] = $errorMessage;
        }
        
        if ($status === 'running') {
            $sql = str_replace('finished_at = NOW()', 'started_at = NOW()', $sql);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $jobId;
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Update job progress
     */
    public function updateJobProgress($jobId, $current, $total = null, $message = null)
    {
        $sql = "UPDATE jobs SET progress = ?, updated_at = NOW()";
        $params = [$current];
        
        if ($total !== null) {
            $sql .= ", total = ?";
            $params[] = $total;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $jobId;
        
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    /**
     * Add log entry to job
     */
    public function addJobLog($jobId, $message) {
        $stmt = $this->pdo->prepare("
            UPDATE jobs 
            SET output = CONCAT(COALESCE(output, ''), ?, '\n')
            WHERE id = ?
        ");
        
        $logEntry = '[' . date('Y-m-d H:i:s') . '] ' . $message;
        return $stmt->execute([$logEntry, $jobId]);
    }

    /**
     * Get jobs by server ID
     */
    public function getJobsByServerId($serverId, $limit = 10) {
        $stmt = $this->pdo->prepare("
            SELECT j.*, s.name as server_name 
            FROM jobs j 
            LEFT JOIN servers s ON j.site_id = s.id 
            WHERE j.site_id = ? 
            ORDER BY j.created_at DESC 
            LIMIT ?
        ");
        
        $stmt->execute([$serverId, $limit]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as &$job) {
            $job['params_json'] = json_decode($job['params_json'], true);
        }
        
        return $jobs;
    }

    /**
     * Get recent jobs
     */
    public function getRecentJobs($limit = 20) {
        $stmt = $this->pdo->prepare("
            SELECT j.*, s.name as server_name 
            FROM jobs j 
            LEFT JOIN servers s ON j.site_id = s.id 
            ORDER BY j.created_at DESC 
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as &$job) {
            $job['params_json'] = json_decode($job['params_json'], true);
        }
        
        return $jobs;
    }

    /**
     * Get running jobs
     */
    public function getRunningJobs(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT j.*, s.name as server_name 
            FROM jobs j 
            LEFT JOIN servers s ON j.site_id = s.id 
            WHERE j.status = 'running' 
            ORDER BY j.started_at ASC
        ");
        
        $stmt->execute();
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($jobs as &$job) {
            $job['params_json'] = json_decode($job['params_json'], true);
        }
        
        return $jobs;
    }

    /**
     * Clean old completed jobs
     */
    public function cleanOldJobs(int $daysOld = 30): int
    {
        $stmt = $this->pdo->prepare("
            DELETE FROM jobs 
            WHERE status IN ('completed', 'failed') 
            AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        
        $stmt->execute([$daysOld]);
        return $stmt->rowCount();
    }
    
    public function getPendingJobs() {
        $stmt = $this->pdo->prepare("
            SELECT * FROM jobs 
            WHERE status = 'pending' 
            ORDER BY created_at ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}