<?php

namespace WpOps\Dashboard\Services;

use WpOps\Dashboard\Database\Connection;
use WpOps\Dashboard\Services\SSHService;
use WpOps\Dashboard\Services\JobService;
use PDO;

class AsyncScanService
{
    private PDO $pdo;
    private JobService $jobService;

    public function __construct()
    {
        $this->pdo = Connection::getInstance()->getPdo();
        $this->jobService = new JobService();
    }

    /**
     * Start an async WordPress scan
     */
    public function startWordPressScan(int $serverId): int
    {
        // Create job
        $jobId = $this->jobService->createJob($serverId, 'scanner', 'wordpress_scan', [
            'server_id' => $serverId,
            'scan_type' => 'wordpress'
        ]);

        $this->jobService->addJobLog($jobId, "Iniciando escaneo de WordPress para servidor ID: $serverId");

        // Start the scan in background (simulate async processing)
        $this->processWordPressScan($jobId, $serverId);

        return $jobId;
    }

    /**
     * Process WordPress scan (this would normally run in background)
     */
    private function processWordPressScan(int $jobId, int $serverId): void
    {
        try {
            $this->jobService->updateJobStatus($jobId, 'running');
            $this->jobService->addJobLog($jobId, "Conectando al servidor...");

            // Get server details
            $stmt = $this->pdo->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$server) {
                throw new \Exception("Servidor no encontrado");
            }

            $this->jobService->addJobLog($jobId, "Servidor encontrado: {$server['name']}");

            // Check if this is a local server (localhost or 127.0.0.1)
            $isLocal = in_array($server['hostname'], ['localhost', '127.0.0.1', 'host.docker.internal']);
            
            if ($isLocal) {
                $this->jobService->addJobLog($jobId, "Servidor local detectado - usando escaneo local");
                $this->processLocalWordPressScan($jobId, $serverId, $server);
            } else {
                $this->jobService->addJobLog($jobId, "Servidor remoto detectado - usando escaneo SSH");
                $this->processRemoteWordPressScan($jobId, $serverId, $server);
            }

        } catch (\Exception $e) {
            $this->jobService->addJobLog($jobId, "ERROR: " . $e->getMessage());
            $this->jobService->updateJobStatus($jobId, 'failed', null, $e->getMessage());
        }
    }

    /**
     * Process local WordPress scan (without SSH)
     */
    private function processLocalWordPressScan(int $jobId, int $serverId, array $server): void
    {
        // Parse scan paths
        $scanPaths = [];
        if (!empty($server['scan_paths'])) {
            $paths = json_decode($server['scan_paths'], true);
            if (is_array($paths)) {
                $scanPaths = $paths;
            }
        }

        if (empty($scanPaths)) {
            throw new \Exception("No hay rutas de escaneo configuradas para este servidor");
        }

        $this->jobService->addJobLog($jobId, "Rutas de escaneo: " . implode(', ', $scanPaths));
        $this->jobService->updateJobProgress($jobId, 10, 100);

        // Expand wildcard paths locally
        $expandedPaths = [];
        foreach ($scanPaths as $path) {
            $this->jobService->addJobLog($jobId, "Expandiendo ruta: $path");
            $expanded = $this->expandWildcardPathLocal($path);
            $expandedPaths = array_merge($expandedPaths, $expanded);
        }

        $this->jobService->addJobLog($jobId, "Rutas expandidas: " . count($expandedPaths) . " encontradas");
        $this->jobService->updateJobProgress($jobId, 40, 100);

        // Scan each path locally
        $foundSites = [];
        $totalPaths = count($expandedPaths);
        $processedPaths = 0;

        foreach ($expandedPaths as $basePath) {
            $this->jobService->addJobLog($jobId, "Escaneando: $basePath");
            
            if (!is_dir($basePath)) {
                $this->jobService->addJobLog($jobId, "Ruta no existe: $basePath");
                $processedPaths++;
                continue;
            }
            
            $sites = $this->scanPathForWordPressLocal($basePath, $jobId);
            $foundSites = array_merge($foundSites, $sites);
            
            $processedPaths++;
            $progress = 40 + (($processedPaths / $totalPaths) * 50);
            $this->jobService->updateJobProgress($jobId, (int)$progress, 100);
            
            $this->jobService->addJobLog($jobId, "Sitios encontrados en $basePath: " . count($sites));
        }

        // Store found sites
        if (!empty($foundSites)) {
            $this->storeWordPressSites($serverId, $foundSites, $jobId);
        }

        // Update server's last scan time
        $stmt = $this->pdo->prepare("UPDATE servers SET last_scan_at = NOW() WHERE id = ?");
        $stmt->execute([$serverId]);

        $this->jobService->updateJobProgress($jobId, 100, 100);
        $this->jobService->addJobLog($jobId, "Escaneo completado. Total de sitios encontrados: " . count($foundSites));

        $this->jobService->updateJobStatus($jobId, 'completed', [
            'sites_found' => count($foundSites),
            'sites' => $foundSites,
            'scanned_paths' => $expandedPaths
        ]);
    }

    /**
     * Process remote WordPress scan (with SSH)
     */
    private function processRemoteWordPressScan(int $jobId, int $serverId, array $server): void
    {
        try {
            // Parse scan paths
            $scanPaths = [];
            if (!empty($server['scan_paths'])) {
                $paths = json_decode($server['scan_paths'], true);
                if (is_array($paths)) {
                    $scanPaths = $paths;
                } else {
                    // Fallback: split by comma
                    $paths = explode(',', $server['scan_paths']);
                }
            } else {
                // Fallback: split by comma
                $paths = explode(',', $server['scan_paths']);
            }

            // Clean up paths
            if (is_array($paths)) {
                foreach ($paths as $path) {
                    $path = trim($path);
                    if (!empty($path)) {
                        $scanPaths[] = $path;
                    }
                }
            }

            if (empty($scanPaths)) {
                throw new \Exception("No hay rutas de escaneo configuradas para este servidor");
            }

            $this->jobService->addJobLog($jobId, "Rutas de escaneo: " . implode(', ', $scanPaths));
            $this->jobService->updateJobProgress($jobId, 10, 100);

            // Connect via SSH
            $sshService = new SSHService($server);
            if (!$sshService->connect()) {
                throw new \Exception("No se pudo conectar por SSH al servidor");
            }

            $this->jobService->addJobLog($jobId, "Conexión SSH establecida");
            $this->jobService->updateJobProgress($jobId, 20, 100);

            // Expand wildcard paths
            $expandedPaths = [];
            foreach ($scanPaths as $path) {
                $this->jobService->addJobLog($jobId, "Expandiendo ruta: $path");
                $expanded = $this->expandWildcardPathRemote($sshService, $path);
                $expandedPaths = array_merge($expandedPaths, $expanded);
            }

            $this->jobService->addJobLog($jobId, "Rutas expandidas: " . count($expandedPaths) . " encontradas");
            $this->jobService->updateJobProgress($jobId, 40, 100);

            // Scan each path
            $foundSites = [];
            $totalPaths = count($expandedPaths);
            $processedPaths = 0;

            foreach ($expandedPaths as $basePath) {
                $this->jobService->addJobLog($jobId, "Escaneando: $basePath");
                
                if (!$sshService->directoryExists($basePath)) {
                    $this->jobService->addJobLog($jobId, "Ruta no existe: $basePath");
                    $processedPaths++;
                    continue;
                }
                
                $sites = $this->scanPathForWordPressRemote($sshService, $basePath, $jobId);
                $foundSites = array_merge($foundSites, $sites);
                
                $processedPaths++;
                $progress = 40 + (($processedPaths / $totalPaths) * 50);
                $this->jobService->updateJobProgress($jobId, (int)$progress, 100);
                
                $this->jobService->addJobLog($jobId, "Sitios encontrados en $basePath: " . count($sites));
            }

            // Disconnect SSH
            $sshService->disconnect();
            $this->jobService->addJobLog($jobId, "Conexión SSH cerrada");

            // Store found sites
            if (!empty($foundSites)) {
                $this->storeWordPressSites($serverId, $foundSites, $jobId);
            }

            // Update server's last scan time
            $stmt = $this->pdo->prepare("UPDATE servers SET last_scan_at = NOW() WHERE id = ?");
            $stmt->execute([$serverId]);

            $this->jobService->updateJobProgress($jobId, 100, 100);
            $this->jobService->addJobLog($jobId, "Escaneo completado. Total de sitios encontrados: " . count($foundSites));

            $this->jobService->updateJobStatus($jobId, 'completed', [
                'sites_found' => count($foundSites),
                'sites' => $foundSites,
                'scanned_paths' => $expandedPaths
            ]);

        } catch (\Exception $e) {
            $this->jobService->addJobLog($jobId, "ERROR: " . $e->getMessage());
            $this->jobService->updateJobStatus($jobId, 'failed', null, $e->getMessage());
        }
    }

    /**
     * Expand wildcard paths locally
     */
    private function expandWildcardPathLocal(string $path): array
    {
        $expandedPaths = [];
        
        if (strpos($path, '*') !== false) {
            // Use glob to expand wildcards
            $matches = glob($path, GLOB_ONLYDIR);
            if ($matches) {
                $expandedPaths = array_merge($expandedPaths, $matches);
            }
        } else {
            // No wildcards, just add the path if it exists
            if (is_dir($path)) {
                $expandedPaths[] = $path;
            }
        }
        
        return $expandedPaths;
    }

    /**
     * Scan path for WordPress installations locally
     */
    private function scanPathForWordPressLocal(string $basePath, int $jobId): array
    {
        $sites = [];
        
        // Look for wp-config.php files
        $configFiles = glob($basePath . '/wp-config.php');
        
        foreach ($configFiles as $configFile) {
            $siteDir = dirname($configFile);
            
            // Check if wp-includes and wp-admin exist
            if (is_dir($siteDir . '/wp-includes') && is_dir($siteDir . '/wp-admin')) {
                
                $this->jobService->addJobLog($jobId, "WordPress encontrado en: $siteDir");
                
                // Get WordPress version
                $version = $this->getWordPressVersionLocal($siteDir);
                
                // Get site URL from wp-config.php or wp-options
                $siteUrl = $this->getSiteUrlLocal($siteDir);
                
                $sites[] = [
                    'path' => $siteDir,
                    'version' => $version,
                    'site_url' => $siteUrl,
                    'config_file' => $configFile
                ];
            }
        }
        
        return $sites;
    }

    /**
     * Get WordPress version locally
     */
    private function getWordPressVersionLocal(string $sitePath): string
    {
        $versionFile = $sitePath . '/wp-includes/version.php';
        
        if (file_exists($versionFile)) {
            $content = file_get_contents($versionFile);
            if (preg_match("/wp_version\s*=\s*['\"]([^'\"]+)['\"];/", $content, $matches)) {
                return $matches[1];
            }
        }
        
        return 'Unknown';
    }

    /**
     * Get site URL locally
     */
    private function getSiteUrlLocal(string $sitePath): string
    {
        // Try to get from wp-config.php first
        $configFile = $sitePath . '/wp-config.php';
        
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            if (preg_match("/define\s*\(\s*['\"]WP_(HOME|SITEURL)['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $content, $matches)) {
                return $matches[2];
            }
        }
        
        // Fallback: try to guess from path
        $pathParts = explode('/', str_replace('\\', '/', trim($sitePath, '/\\')));
        $domain = end($pathParts);
        
        return "http://$domain";
    }

    /**
     * Expand wildcard paths on remote server
     */
    private function expandWildcardPathRemote(SSHService $sshService, string $path): array
    {
        if (strpos($path, '*') === false) {
            return [$path];
        }

        $command = "find " . dirname($path) . " -maxdepth 1 -type d -name '" . basename($path) . "' 2>/dev/null";
        $result = $sshService->executeCommand($command);
        
        if (empty($result) || !$result['success'] || empty($result['output'])) {
            return [];
        }
        
        return array_filter(explode("\n", trim($result['output'])));
    }

    /**
     * Scan a path for WordPress installations on remote server
     */
    private function scanPathForWordPressRemote(SSHService $sshService, string $basePath, int $jobId): array
    {
        $sites = [];
        
        // Look for wp-config.php files
        $command = "find '$basePath' -name 'wp-config.php' -type f 2>/dev/null";
        $result = $sshService->executeCommand($command);
        
        if (empty($result) || !$result['success'] || empty($result['output'])) {
            return $sites;
        }
        
        $configFiles = array_filter(explode("\n", trim($result['output'])));
        
        foreach ($configFiles as $configFile) {
            $siteDir = dirname($configFile);
            
            // Check if wp-includes and wp-admin exist
            if ($sshService->directoryExists($siteDir . '/wp-includes') && 
                $sshService->directoryExists($siteDir . '/wp-admin')) {
                
                $this->jobService->addJobLog($jobId, "WordPress encontrado en: $siteDir");
                
                // Get WordPress version
                $version = $this->getWordPressVersionRemote($sshService, $siteDir);
                
                // Get site URL from wp-config.php or wp-options
                $siteUrl = $this->getSiteUrlRemote($sshService, $siteDir);
                
                $sites[] = [
                    'path' => $siteDir,
                    'version' => $version,
                    'site_url' => $siteUrl,
                    'config_file' => $configFile
                ];
            }
        }
        
        return $sites;
    }

    /**
     * Get WordPress version from remote server
     */
    private function getWordPressVersionRemote(SSHService $sshService, string $sitePath): string
    {
        $versionFile = $sitePath . '/wp-includes/version.php';
        $command = "grep '\$wp_version' '$versionFile' 2>/dev/null | head -1";
        $result = $sshService->executeCommand($command);
        
        if ($result['success'] && !empty($result['output']) && preg_match("/wp_version\s*=\s*['\"]([^'\"]+)['\"];/", $result['output'], $matches)) {
            return $matches[1];
        }
        
        return 'Unknown';
    }

    /**
     * Get site URL from remote server
     */
    private function getSiteUrlRemote(SSHService $sshService, string $sitePath): string
    {
        // Try to get from wp-config.php first
        $configFile = $sitePath . '/wp-config.php';
        $command = "grep -E '(WP_HOME|WP_SITEURL)' '$configFile' 2>/dev/null | head -1";
        $result = $sshService->executeCommand($command);
        
        if ($result['success'] && !empty($result['output']) && preg_match("/define\s*\(\s*['\"]WP_(HOME|SITEURL)['\"]\s*,\s*['\"]([^'\"]+)['\"]/", $result['output'], $matches)) {
            return $matches[2];
        }
        
        // Fallback: try to guess from path
        $pathParts = explode('/', trim($sitePath, '/'));
        $domain = end($pathParts);
        
        return "http://$domain";
    }

    /**
     * Store WordPress sites in database
     */
    private function storeWordPressSites(int $serverId, array $sites, int $jobId): void
    {
        $this->jobService->addJobLog($jobId, "Guardando sitios en base de datos...");
        
        foreach ($sites as $site) {
            // Check if site already exists
            $stmt = $this->pdo->prepare("SELECT id FROM wordpress_sites WHERE server_id = ? AND path = ?");
            $stmt->execute([$serverId, $site['path']]);
            
            if ($stmt->fetch()) {
                // Update existing site
                $stmt = $this->pdo->prepare("
                    UPDATE wordpress_sites 
                    SET wp_version = ?, domain = ?, last_scanned_at = NOW() 
                    WHERE server_id = ? AND path = ?
                ");
                $stmt->execute([
                    $site['version'],
                    $site['site_url'],
                    $serverId,
                    $site['path']
                ]);
                
                $this->jobService->addJobLog($jobId, "Sitio actualizado: {$site['path']}");
            } else {
                // Insert new site
                $stmt = $this->pdo->prepare("
                    INSERT INTO wordpress_sites (server_id, path, wp_version, domain, last_scanned_at, created_at) 
                    VALUES (?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $serverId,
                    $site['path'],
                    $site['version'],
                    $site['site_url']
                ]);
                
                $this->jobService->addJobLog($jobId, "Nuevo sitio agregado: {$site['path']}");
            }
        }
    }
}