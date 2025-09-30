<?php

namespace WpOps\Dashboard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use WpOps\Dashboard\Database\Connection;

/**
 * Jobs Controller
 * Handles job management and monitoring
 */
class JobsController
{
    private Connection $db;
    private Twig $view;

    public function __construct(Connection $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
    }

    /**
     * List all jobs
     */
    public function index(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        // Build filters
        $where = [];
        $params = [];

        if (!empty($queryParams['status'])) {
            $where[] = "j.status = :status";
            $params['status'] = $queryParams['status'];
        }

        // Temporarily disabled job_type filter since column doesn't exist
        /*
        if (!empty($queryParams['job_type'])) {
            $where[] = "j.job_type = :job_type";
            $params['job_type'] = $queryParams['job_type'];
        }
        */

        if (!empty($queryParams['site_id'])) {
            $where[] = "j.site_id = :site_id";
            $params['site_id'] = $queryParams['site_id'];
        }

        if (!empty($queryParams['date_from'])) {
            $where[] = "j.created_at >= :date_from";
            $params['date_from'] = $queryParams['date_from'] . ' 00:00:00';
        }

        if (!empty($queryParams['date_to'])) {
            $where[] = "j.created_at <= :date_to";
            $params['date_to'] = $queryParams['date_to'] . ' 23:59:59';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        // Get jobs with pagination
        $sql = "SELECT j.*, 
                       s.path as site_name, 
                       s.url as site_url,
                       u.username as started_by_username
                FROM jobs j
                LEFT JOIN sites s ON j.site_id = s.id
                LEFT JOIN users u ON j.started_by = u.id
                {$whereClause}
                ORDER BY j.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}";

        $jobs = $this->db->query($sql, $params);

        // Get total count for pagination
        $countSql = "SELECT COUNT(*) as total FROM jobs j {$whereClause}";
        $totalCount = $this->db->queryOne($countSql, $params)['total'] ?? 0;
        $totalPages = ceil($totalCount / $perPage);

        // Get filter options
        $statuses = $this->db->query("SELECT DISTINCT status FROM jobs ORDER BY status");
        // $jobTypes = $this->db->query("SELECT DISTINCT job_type FROM jobs ORDER BY job_type");
        $jobTypes = []; // Temporarily disabled since job_type column doesn't exist
        $sites = $this->db->query("SELECT id, path as name FROM sites ORDER BY path");

        // Get job statistics
        $stats = $this->getJobStatistics();

        return $this->view->render($response, 'jobs/index.twig', [
            'jobs' => $jobs,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'per_page' => $perPage,
                'total_count' => $totalCount
            ],
            'filters' => [
                'status' => $queryParams['status'] ?? '',
                // 'job_type' => $queryParams['job_type'] ?? '', // Disabled since column doesn't exist
                'site_id' => $queryParams['site_id'] ?? '',
                'date_from' => $queryParams['date_from'] ?? '',
                'date_to' => $queryParams['date_to'] ?? ''
            ],
            'statuses' => $statuses,
            'job_types' => $jobTypes,
            'sites' => $sites,
            'stats' => $stats,
            'page_title' => 'Jobs'
        ]);
    }

    /**
     * Show single job details
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $jobId = (int) $args['id'];
        
        // Get job details
        $job = $this->db->queryOne(
            "SELECT j.*, 
                    s.path as site_name, 
                    s.url as site_url, 
                    s.path as site_path,
                    u.username as started_by_username
             FROM jobs j
             LEFT JOIN sites s ON j.site_id = s.id
             LEFT JOIN users u ON j.started_by = u.id
             WHERE j.id = :id",
            ['id' => $jobId]
        );
        
        if (!$job) {
            return $response->withStatus(404);
        }

        // Parse parameters and result
        $job['parameters_parsed'] = json_decode($job['params_json'], true) ?? [];
        $job['result_parsed'] = json_decode($job['output'], true) ?? [];

        // Get related jobs (same site, similar time)
        $relatedJobs = $this->db->query(
            "SELECT * FROM jobs 
             WHERE site_id = :site_id 
             AND id != :job_id 
             AND created_at BETWEEN DATE_SUB(:created_at, INTERVAL 1 HOUR) 
                                AND DATE_ADD(:created_at, INTERVAL 1 HOUR)
             ORDER BY created_at DESC
             LIMIT 10",
            [
                'site_id' => $job['site_id'],
                'job_id' => $jobId,
                'created_at' => $job['created_at']
            ]
        );

        return $this->view->render($response, 'jobs/show.twig', [
            'job' => $job,
            'related_jobs' => $relatedJobs,
            'page_title' => "Job #{$jobId}"
        ]);
    }

    /**
     * Retry a failed job
     */
    public function retry(Request $request, Response $response, array $args): Response
    {
        $jobId = (int) $args['id'];
        
        $job = $this->db->selectOne('jobs', ['id' => $jobId]);
        
        if (!$job) {
            return $response->withStatus(404);
        }

        if ($job['status'] !== 'failed') {
            $this->addFlashMessage('error', 'Only failed jobs can be retried.');
            return $response->withHeader('Location', "/jobs/{$jobId}")->withStatus(302);
        }

        // Create new job with same parameters (session already started by middleware)
        $newJobId = $this->db->insert('jobs', [
            'site_id' => $job['site_id'],
            // 'job_type' => $job['job_type'], // Temporarily disabled since column doesn't exist
            'params_json' => $job['params_json'],
            'status' => 'pending',
            'started_by' => $_SESSION['user_id'] ?? null,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $this->addFlashMessage('success', "Job has been retried. New job ID: #{$newJobId}");
        
        return $response->withHeader('Location', "/jobs/{$newJobId}")->withStatus(302);
    }

    /**
     * Delete a job
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $jobId = (int) $args['id'];
        
        $job = $this->db->selectOne('jobs', ['id' => $jobId]);
        
        if (!$job) {
            return $response->withStatus(404);
        }

        if ($job['status'] === 'running') {
            $this->addFlashMessage('error', 'Cannot delete a running job.');
            return $response->withHeader('Location', "/jobs/{$jobId}")->withStatus(302);
        }

        // Delete the job
        $this->db->delete('jobs', ['id' => $jobId]);

        // Log the deletion (session already started by middleware)
        $this->db->insert('audit_logs', [
            'site_id' => $job['site_id'],
            'user_id' => $_SESSION['user_id'] ?? null,
            'action' => 'job_deleted',
            'details' => json_encode(['job_id' => $jobId]), // Removed job_type since column doesn't exist
            'created_at' => date('Y-m-d H:i:s')
        ]);

        $this->addFlashMessage('success', 'Job has been deleted.');
        
        return $response->withHeader('Location', '/jobs')->withStatus(302);
    }

    /**
     * Get job statistics
     */
    private function getJobStatistics(): array
    {
        // Jobs by status (last 24 hours)
        $statusStats = $this->db->query(
            "SELECT status, COUNT(*) as count 
             FROM jobs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY status"
        );

        $statusCounts = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($statusStats as $stat) {
            $statusCounts[$stat['status']] = $stat['count'];
        }

        // Jobs by type (last 7 days) - Temporarily disabled since job_type column doesn't exist
        /*
        $typeStats = $this->db->query(
            "SELECT job_type, COUNT(*) as count 
             FROM jobs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY job_type 
             ORDER BY count DESC"
        );
        */
        $typeStats = []; // Empty array since job_type column doesn't exist

        // Average execution time (completed jobs, last 7 days)
        $avgExecutionTime = $this->db->queryOne(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, created_at, finished_at)) as avg_seconds
             FROM jobs 
             WHERE status = 'completed' 
             AND finished_at IS NOT NULL
             AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        )['avg_seconds'] ?? 0;

        // Success rate (last 7 days)
        $successRate = $this->db->queryOne(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
             FROM jobs 
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             AND status IN ('completed', 'failed')"
        );

        $successRatePercent = $successRate['total'] > 0 
            ? round(($successRate['completed'] / $successRate['total']) * 100, 1)
            : 0;

        // Queue length
        $queueLength = $this->db->count('jobs', ['status' => 'pending']);

        return [
            'status_counts' => $statusCounts,
            'type_stats' => $typeStats,
            'avg_execution_time' => round($avgExecutionTime, 1),
            'success_rate' => $successRatePercent,
            'queue_length' => $queueLength
        ];
    }

    /**
     * Add flash message to session
     */
    private function addFlashMessage(string $type, string $message): void
    {
        // Session already started by middleware
        if (!isset($_SESSION['flash_messages'])) {
            $_SESSION['flash_messages'] = [];
        }
        
        $_SESSION['flash_messages'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
}