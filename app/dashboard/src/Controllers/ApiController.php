<?php

namespace WpOps\Dashboard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use WpOps\Dashboard\Database\Connection;

/**
 * API Controller
 * Handles REST API endpoints for external integrations
 */
class ApiController
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Get all sites
     */
    public function getSites(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        
        // Build filters
        $where = [];
        $params = [];

        if (!empty($queryParams['status'])) {
            $where[] = "status = :status";
            $params['status'] = $queryParams['status'];
        }

        if (!empty($queryParams['search'])) {
            $where[] = "(name LIKE :search OR url LIKE :search)";
            $params['search'] = '%' . $queryParams['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 50)));

        $sql = "SELECT * FROM sites {$whereClause} ORDER BY name ASC LIMIT {$limit}";
        $sites = $this->db->query($sql, $params);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $sites,
            'count' => count($sites)
        ]);
    }

    /**
     * Get single site
     */
    public function getSite(Request $request, Response $response, array $args): Response
    {
        $siteId = (int) $args['id'];
        
        $site = $this->db->selectOne('sites', ['id' => $siteId]);
        
        if (!$site) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Site not found'
            ], 404);
        }

        // Get additional site information
        $desiredState = $this->db->selectOne('desired_state', ['site_id' => $siteId]);
        $openIssues = $this->db->count('issues', ['site_id' => $siteId, 'status' => 'open']);
        $pendingJobs = $this->db->count('jobs', ['site_id' => $siteId, 'status' => 'pending']);

        $site['desired_state'] = $desiredState;
        $site['open_issues'] = $openIssues;
        $site['pending_jobs'] = $pendingJobs;

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $site
        ]);
    }

    /**
     * Create a job for a site
     */
    public function createJob(Request $request, Response $response, array $args): Response
    {
        $siteId = (int) $args['id'];
        $data = $request->getParsedBody();

        // Validate required fields
        if (empty($data['job_type'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'job_type is required'
            ], 400);
        }

        // Validate site exists
        $site = $this->db->selectOne('sites', ['id' => $siteId]);
        if (!$site) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Site not found'
            ], 404);
        }

        // Validate job type
        $validJobTypes = [
            'lock_site', 'unlock_site', 'harden_site', 'unharden_site',
            'fix_permissions', 'scan_code', 'update_plugins', 'update_themes',
            'update_core', 'backup_site', 'restore_site'
        ];

        if (!in_array($data['job_type'], $validJobTypes)) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Invalid job type'
            ], 400);
        }

        // Create job
        $jobId = $this->db->insert('jobs', [
            'site_id' => $siteId,
            'job_type' => $data['job_type'],
            'params_json' => json_encode($data['parameters'] ?? []),
            'status' => 'pending',
            'started_by' => null, // API jobs don't have a user
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Get created job
        $job = $this->db->selectOne('jobs', ['id' => $jobId]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $job
        ], 201);
    }

    /**
     * Get all jobs
     */
    public function getJobs(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        
        // Build filters
        $where = [];
        $params = [];

        if (!empty($queryParams['status'])) {
            $where[] = "j.status = :status";
            $params['status'] = $queryParams['status'];
        }

        if (!empty($queryParams['job_type'])) {
            $where[] = "j.job_type = :job_type";
            $params['job_type'] = $queryParams['job_type'];
        }

        if (!empty($queryParams['site_id'])) {
            $where[] = "j.site_id = :site_id";
            $params['site_id'] = $queryParams['site_id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 50)));

        $sql = "SELECT j.*, s.path as site_name, s.url as site_url
                FROM jobs j
                LEFT JOIN sites s ON j.site_id = s.id
                {$whereClause}
                ORDER BY j.created_at DESC
                LIMIT {$limit}";

        $jobs = $this->db->query($sql, $params);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $jobs,
            'count' => count($jobs)
        ]);
    }

    /**
     * Get single job
     */
    public function getJob(Request $request, Response $response, array $args): Response
    {
        $jobId = (int) $args['id'];
        
        $job = $this->db->queryOne(
            "SELECT j.*, s.path as site_name, s.url as site_url
             FROM jobs j
             LEFT JOIN sites s ON j.site_id = s.id
             WHERE j.id = :id",
            ['id' => $jobId]
        );
        
        if (!$job) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Job not found'
            ], 404);
        }

        // Parse JSON fields
        $job['params_json'] = json_decode($job['params_json'] ?? '{}', true) ?? [];
        $job['output'] = $job['output'] ?? '';

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $job
        ]);
    }

    /**
     * Get dashboard statistics
     */
    public function getDashboardStats(Request $request, Response $response): Response
    {
        // Sites statistics
        $siteStats = [
            'total' => $this->db->query(
                "SELECT COUNT(*) as count FROM sites WHERE status != 'removed'"
            )[0]['count'] ?? 0,
            'active' => $this->db->count('sites', ['status' => 'active']),
            'locked' => $this->db->count('sites', ['status' => 'locked']),
            'hardened' => $this->db->query(
                "SELECT COUNT(*) as count FROM sites WHERE is_hardened = 1 AND status != 'removed'"
            )[0]['count'] ?? 0
        ];

        // Jobs statistics
        $jobStats = [
            'pending' => $this->db->count('jobs', ['status' => 'pending']),
            'running' => $this->db->count('jobs', ['status' => 'running']),
            'completed_24h' => $this->db->query(
                "SELECT COUNT(*) as count FROM jobs 
                 WHERE status = 'completed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )[0]['count'] ?? 0,
            'failed_24h' => $this->db->query(
                "SELECT COUNT(*) as count FROM jobs 
                 WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
            )[0]['count'] ?? 0
        ];

        // Issues statistics
        $issueStats = [
            'open' => $this->db->count('issues', ['status' => 'open']),
            'critical' => $this->db->count('issues', ['status' => 'open', 'severity' => 'high']),
            'medium' => $this->db->count('issues', ['status' => 'open', 'severity' => 'medium']),
            'low' => $this->db->count('issues', ['status' => 'open', 'severity' => 'low'])
        ];

        // Recent activity
        $recentJobs = $this->db->query(
            "SELECT j.id, j.job_type, j.status, j.created_at, s.path as site_name
             FROM jobs j
             LEFT JOIN sites s ON j.site_id = s.id
             ORDER BY j.created_at DESC
             LIMIT 10"
        );

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => [
                'sites' => $siteStats,
                'jobs' => $jobStats,
                'issues' => $issueStats,
                'recent_jobs' => $recentJobs,
                'timestamp' => date('c')
            ]
        ]);
    }

    /**
     * Get issues
     */
    public function getIssues(Request $request, Response $response): Response
    {
        $queryParams = $request->getQueryParams();
        
        // Build filters
        $where = [];
        $params = [];

        if (!empty($queryParams['status'])) {
            $where[] = "i.status = :status";
            $params['status'] = $queryParams['status'];
        }

        if (!empty($queryParams['severity'])) {
            $where[] = "i.severity = :severity";
            $params['severity'] = $queryParams['severity'];
        }

        if (!empty($queryParams['site_id'])) {
            $where[] = "i.site_id = :site_id";
            $params['site_id'] = $queryParams['site_id'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $limit = min(100, max(1, (int) ($queryParams['limit'] ?? 50)));

        $sql = "SELECT i.*, s.path as site_name, s.url as site_url
                FROM issues i
                LEFT JOIN sites s ON i.site_id = s.id
                {$whereClause}
                ORDER BY i.created_at DESC
                LIMIT {$limit}";

        $issues = $this->db->query($sql, $params);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $issues,
            'count' => count($issues)
        ]);
    }

    /**
     * Resolve an issue
     */
    public function resolveIssue(Request $request, Response $response, array $args): Response
    {
        $issueId = (int) $args['id'];
        
        $issue = $this->db->selectOne('issues', ['id' => $issueId]);
        
        if (!$issue) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Issue not found'
            ], 404);
        }

        if ($issue['status'] === 'resolved') {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'Issue is already resolved'
            ], 400);
        }

        // Resolve the issue
        $this->db->update('issues', [
            'status' => 'resolved',
            'resolved_at' => date('Y-m-d H:i:s')
        ], ['id' => $issueId]);

        // Get updated issue
        $updatedIssue = $this->db->selectOne('issues', ['id' => $issueId]);

        return $this->jsonResponse($response, [
            'success' => true,
            'data' => $updatedIssue
        ]);
    }

    /**
     * Get jobs status by IDs
     */
    public function getJobsStatus(Request $request, Response $response): Response
    {
        $body = $request->getBody()->getContents();
        $data = json_decode($body, true);
        
        if (!isset($data['job_ids']) || !is_array($data['job_ids'])) {
            return $this->jsonResponse($response, [
                'success' => false,
                'error' => 'job_ids array is required'
            ], 400);
        }
        
        $jobIds = array_map('intval', $data['job_ids']);
        $placeholders = str_repeat('?,', count($jobIds) - 1) . '?';
        
        $jobs = $this->db->query(
            "SELECT j.id, j.status, j.progress, j.created_at, j.started_at, j.finished_at, j.error_message
             FROM jobs j
             WHERE j.id IN ($placeholders)
             ORDER BY j.created_at DESC",
            $jobIds
        );
        
        return $this->jsonResponse($response, [
            'success' => true,
            'jobs' => $jobs
        ]);
    }

    /**
     * Create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}