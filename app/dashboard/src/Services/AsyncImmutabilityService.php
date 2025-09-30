<?php

namespace WpOps\Dashboard\Services;

use WpOps\Dashboard\Database\Connection;
use PDO;
use Exception;

class AsyncImmutabilityService
{
    private PDO $pdo;
    private JobService $jobService;
    private ImmutabilityService $immutabilityService;

    public function __construct()
    {
        $connection = Connection::getInstance();
        $this->pdo = $connection->getPdo();
        $this->jobService = new JobService();
        $this->immutabilityService = new ImmutabilityService($connection);
    }

    /**
     * Process immutability check job
     */
    public function processImmutabilityCheck($jobId): void
    {
        $job = $this->jobService->getJobById($jobId);
        if (!$job) {
            throw new Exception("Job not found: $jobId");
        }

        $this->jobService->addJobLog($jobId, "Iniciando verificación de inmutabilidad");

        try {
            $params = $job['params_json'];
            $siteId = $params['site_id'] ?? null;
            $checkAll = $params['check_all'] ?? false;

            if ($checkAll) {
                $this->processAllSites($jobId);
            } elseif ($siteId) {
                $this->processSingleSite($jobId, $siteId);
            } else {
                throw new Exception("No se especificó site_id ni check_all");
            }

            $this->jobService->updateJobStatus($jobId, 'completed');
            $this->jobService->addJobLog($jobId, "Verificación de inmutabilidad completada exitosamente");

        } catch (Exception $e) {
            $this->jobService->updateJobStatus($jobId, 'failed', null, $e->getMessage());
            $this->jobService->addJobLog($jobId, "Error: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process immutability check for all sites
     */
    private function processAllSites($jobId): void
    {
        // Get all active sites
        $stmt = $this->pdo->prepare("
            SELECT id, path, server_id 
            FROM wordpress_sites 
            WHERE is_active = 1
            ORDER BY id
        ");
        $stmt->execute();
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = count($sites);
        $this->jobService->updateJobProgress($jobId, 0, $total);
        $this->jobService->addJobLog($jobId, "Procesando $total sitios");

        foreach ($sites as $index => $site) {
            $current = $index + 1;
            $this->jobService->addJobLog($jobId, "Procesando sitio $current/$total: {$site['path']}");
            
            try {
                $this->processSiteImmutability($site['id']);
                $this->jobService->addJobLog($jobId, "✓ Sitio {$site['path']} procesado correctamente");
            } catch (Exception $e) {
                $this->jobService->addJobLog($jobId, "✗ Error en sitio {$site['path']}: " . $e->getMessage());
                // Continue with other sites even if one fails
            }

            $this->jobService->updateJobProgress($jobId, $current, $total);
        }
    }

    /**
     * Process immutability check for a single site
     */
    private function processSingleSite($jobId, $siteId): void
    {
        $this->jobService->updateJobProgress($jobId, 0, 1);
        $this->jobService->addJobLog($jobId, "Procesando sitio ID: $siteId");

        try {
            $this->processSiteImmutability($siteId);
            $this->jobService->addJobLog($jobId, "✓ Sitio procesado correctamente");
            $this->jobService->updateJobProgress($jobId, 1, 1);
        } catch (Exception $e) {
            $this->jobService->addJobLog($jobId, "✗ Error procesando sitio: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process immutability for a specific site
     */
    private function processSiteImmutability($siteId): void
    {
        // Get site info
        $stmt = $this->pdo->prepare("
            SELECT ws.*, s.hostname, s.ssh_user, s.ssh_port, s.ssh_password
            FROM wordpress_sites ws
            JOIN servers s ON ws.server_id = s.id
            WHERE ws.id = ?
        ");
        $stmt->execute([$siteId]);
        $site = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$site) {
            throw new Exception("Site not found: $siteId");
        }

        // Check immutability status using existing service
        $immutabilityStatus = $this->immutabilityService->getImmutabilityStatus($site);

        // Update site with immutability status
        $this->updateSiteImmutabilityStatus($siteId, $immutabilityStatus);
    }

    /**
     * Update site immutability status in database
     */
    private function updateSiteImmutabilityStatus($siteId, $immutabilityStatus): void
    {
        $isImmutable = $immutabilityStatus['is_immutable'] ?? false;
        
        $stmt = $this->pdo->prepare("
            UPDATE wordpress_sites 
            SET 
                is_immutable = ?,
                immutability_last_check = NOW(),
                immutability_status = ?
            WHERE id = ?
        ");
        
        $stmt->execute([
            $isImmutable ? 1 : 0,
            json_encode($immutabilityStatus),
            $siteId
        ]);
    }

    /**
     * Create job for immutability check
     */
    public function createImmutabilityJob($siteId = null, $checkAll = false): string
    {
        $params = [];
        
        if ($checkAll) {
            $params['check_all'] = true;
            $serverId = null; // Will process multiple servers
        } else {
            $params['site_id'] = $siteId;
            
            // Get server ID for the site
            $stmt = $this->pdo->prepare("SELECT server_id FROM wordpress_sites WHERE id = ?");
            $stmt->execute([$siteId]);
            $site = $stmt->fetch(PDO::FETCH_ASSOC);
            $serverId = $site['server_id'] ?? null;
        }

        return $this->jobService->createJob(
            $serverId,
            'immutability',
            'check_immutability',
            $params,
            $siteId
        );
    }

    /**
     * Get immutability jobs for a site
     */
    public function getImmutabilityJobs($siteId = null, $limit = 10): array
    {
        $sql = "
            SELECT j.*, s.name as server_name, si.path as site_path
            FROM jobs j 
            LEFT JOIN servers s ON j.server_id = s.id 
            LEFT JOIN wordpress_sites si ON j.site_id = si.id
            WHERE j.module = 'immutability' AND j.action = 'check_immutability'
        ";
        
        $params = [];
        
        if ($siteId) {
            $sql .= " AND j.site_id = ?";
            $params[] = $siteId;
        }
        
        $sql .= " ORDER BY j.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the latest immutability status for a site from the database
     */
    public function getLatestImmutabilityStatus(int $siteId): ?array
    {
        try {
            $db = Connection::getInstance();
            
            $site = $db->queryOne(
                "SELECT immutability_last_check, immutability_status FROM wordpress_sites WHERE id = ?",
                [$siteId]
            );
            
            if (!$site || !$site['immutability_last_check']) {
                return null;
            }
            
            $status = $site['immutability_status'] ? json_decode($site['immutability_status'], true) : null;
            
            return [
                'last_check' => $site['immutability_last_check'],
                'is_immutable' => $status['is_immutable'] ?? false,
                'folders' => $status['folders'] ?? []
            ];
            
        } catch (\Exception $e) {
            error_log("Error getting latest immutability status for site {$siteId}: " . $e->getMessage());
            return null;
        }
    }
}