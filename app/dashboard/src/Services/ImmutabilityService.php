<?php

namespace WpOps\Dashboard\Services;

use WpOps\Dashboard\Database\Connection;
use Exception;

/**
 * Immutability Service
 * Handles site immutabilization with specific exceptions
 */
class ImmutabilityService
{
    private Connection $db;
    
    // Directories to exclude from immutabilization
    private const EXCLUDED_DIRS = [
        'wp-content/uploads',
        'wp-content/cache',
        'wp-content/wp-rocket-config',
        'wp-content/languages',
        'wp-content/plugins/sitepress-multilingual-cms/res',
        'wp-content/plugins/wpml-*',
        'wp-content/uploads/wpml'
    ];
    
    // File patterns to exclude
    private const EXCLUDED_PATTERNS = [
        '*.log',
        '*.tmp',
        'wp-content/cache/*',
        'wp-content/uploads/*',
        'wp-content/wp-rocket-config/*'
    ];

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    /**
     * Immutabilize a site
     */
    public function immutabilize(int $siteId): array
    {
        try {
            $site = $this->getSiteInfo($siteId);
            if (!$site) {
                throw new Exception("Site not found");
            }

            $sitePath = $site['path'];
            $serverInfo = $this->getServerInfo($site['server_id']);
            
            if (!$serverInfo) {
                throw new Exception("Server information not found");
            }

            // Build the immutabilization command
            $command = $this->buildImmutabilizeCommand($sitePath, $serverInfo);
            
            // Execute the command via SSH or local execution
            $result = $this->executeCommand($command, $serverInfo);
            
            if ($result['success']) {
                // Update site status in database
                $this->updateSiteImmutabilityStatus($siteId, true);
                
                return [
                    'success' => true,
                    'message' => 'Site immutabilized successfully',
                    'excluded_dirs' => self::EXCLUDED_DIRS,
                    'command_output' => $result['output']
                ];
            } else {
                throw new Exception("Failed to immutabilize site: " . $result['error']);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error immutabilizing site: ' . $e->getMessage()
            ];
        }
    }

    /**
     * De-immutabilize a site
     */
    public function deimmutabilize(int $siteId): array
    {
        try {
            $site = $this->getSiteInfo($siteId);
            if (!$site) {
                throw new Exception("Site not found");
            }

            $sitePath = $site['path'];
            $serverInfo = $this->getServerInfo($site['server_id']);
            
            if (!$serverInfo) {
                throw new Exception("Server information not found");
            }

            // Build the de-immutabilization command
            $command = $this->buildDeimmutabilizeCommand($sitePath, $serverInfo);
            
            // Execute the command
            $result = $this->executeCommand($command, $serverInfo);
            
            if ($result['success']) {
                // Update site status in database
                $this->updateSiteImmutabilityStatus($siteId, false);
                
                return [
                    'success' => true,
                    'message' => 'Site de-immutabilized successfully',
                    'command_output' => $result['output']
                ];
            } else {
                throw new Exception("Failed to de-immutabilize site: " . $result['error']);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error de-immutabilizing site: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get site information
     */
    private function getSiteInfo(int $siteId): ?array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM wordpress_sites WHERE id = ?");
        $stmt->execute([$siteId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }



    /**
     * Build immutabilization command
     */
    private function buildImmutabilizeCommand(string $sitePath, array $serverInfo): string
    {
        $excludeArgs = '';
        
        // Add exclusions for each directory
        foreach (self::EXCLUDED_DIRS as $dir) {
            $fullPath = rtrim($sitePath, '/') . '/' . $dir;
            $excludeArgs .= " -not -path '{$fullPath}' -not -path '{$fullPath}/*'";
        }
        
        // Command to make files immutable using chattr +i
        // Only apply to regular files, exclude directories and our exception paths
        $command = "find '{$sitePath}' -type f {$excludeArgs} -exec chattr +i {} \\;";
        
        return $command;
    }

    /**
     * Build de-immutabilization command
     */
    private function buildDeimmutabilizeCommand(string $sitePath, array $serverInfo): string
    {
        // Command to remove immutable attribute using chattr -i
        // Apply to all files in the site path
        $command = "find '{$sitePath}' -type f -exec chattr -i {} \\; 2>/dev/null || true";
        
        return $command;
    }

    /**
     * Execute command on server
     */
    private function executeCommand(string $command, array $serverInfo): array
    {
        try {
            // For local execution (when hostname is localhost or 127.0.0.1)
            if (in_array($serverInfo['hostname'], ['localhost', '127.0.0.1', 'host.docker.internal'])) {
                return $this->executeLocalCommand($command);
            } else {
                return $this->executeRemoteCommand($command, $serverInfo);
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'output' => ''
            ];
        }
    }

    /**
     * Execute command locally
     */
    private function executeLocalCommand(string $command): array
    {
        $output = [];
        $returnCode = 0;
        
        exec($command . ' 2>&1', $output, $returnCode);
        
        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'error' => $returnCode !== 0 ? implode("\n", $output) : ''
        ];
    }

    /**
     * Execute command remotely via SSH
     */
    private function executeRemoteCommand(string $command, array $serverInfo): array
    {
        $sshCommand = sprintf(
            'ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2 %s@%s "%s"',
            $serverInfo['ssh_user'],
            $serverInfo['hostname'],
            escapeshellarg($command)
        );
        
        $output = [];
        $returnCode = 0;
        
        // Set a timeout for the exec command using timeout command (Windows)
        $timeoutCommand = "timeout /t 25 " . $sshCommand . ' 2>&1';
        
        exec($timeoutCommand, $output, $returnCode);
        
        // Check if command timed out (timeout command returns 1 on Windows when timeout occurs)
        if ($returnCode === 1 && empty($output)) {
            return [
                'success' => false,
                'output' => '',
                'error' => 'SSH command timed out after 25 seconds'
            ];
        }
        
        return [
            'success' => $returnCode === 0,
            'output' => implode("\n", $output),
            'error' => $returnCode !== 0 ? implode("\n", $output) : ''
        ];
    }

    /**
     * Update site immutability status in database
     */
    private function updateSiteImmutabilityStatus(int $siteId, bool $isImmutable): void
    {
        $pdo = $this->db->getPdo();
        
        // Check if immutability column exists, if not add it
        $this->ensureImmutabilityColumn();
        
        $stmt = $pdo->prepare("UPDATE wordpress_sites SET is_immutable = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$isImmutable ? 1 : 0, $siteId]);
    }

    /**
     * Ensure immutability column exists in wordpress_sites table
     */
    private function ensureImmutabilityColumn(): void
    {
        $pdo = $this->db->getPdo();
        
        // Check if column exists
        $stmt = $pdo->prepare("SHOW COLUMNS FROM wordpress_sites LIKE 'is_immutable'");
        $stmt->execute();
        
        if ($stmt->rowCount() === 0) {
            // Add the column
            $pdo->exec("ALTER TABLE wordpress_sites ADD COLUMN is_immutable TINYINT(1) DEFAULT 0 AFTER status");
        }
    }

    /**
     * Check if chattr is available on the system
     */
    public function isImmutabilitySupported(array $serverInfo): bool
    {
        $command = 'which chattr';
        $result = $this->executeCommand($command, $serverInfo);
        
        return $result['success'] && !empty(trim($result['output']));
    }

    /**
     * Get immutability status for multiple sites
     */
    public function getImmutabilityStatus(array $siteIds): array
    {
        if (empty($siteIds)) {
            return [];
        }
        
        $pdo = $this->db->getPdo();
        $placeholders = str_repeat('?,', count($siteIds) - 1) . '?';
        
        $stmt = $pdo->prepare("SELECT id, is_immutable FROM wordpress_sites WHERE id IN ({$placeholders})");
        $stmt->execute($siteIds);
        
        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[$row['id']] = (bool) $row['is_immutable'];
        }
        
        return $results;
    }

    /**
     * Check folder-specific immutability status for a site
     */
    public function getFolderImmutabilityStatus(string $sitePath, array $serverInfo): array
    {
        $folders = [
            'root' => $sitePath,
            'language' => $sitePath . '/wp-content/languages',
            'upload' => $sitePath . '/wp-content/uploads',
            'wp_rocket' => $sitePath . '/wp-content/wp-rocket-config'
        ];
        
        $status = [];
        
        foreach ($folders as $folderName => $folderPath) {
            $status[$folderName] = $this->checkFolderImmutability($folderPath, $serverInfo);
        }
        
        return $status;
    }

    /**
     * Check if a specific folder has immutable files
     */
    private function checkFolderImmutability(string $folderPath, array $serverInfo): bool
    {
        // Check if folder exists
        if (!$this->folderExists($folderPath, $serverInfo)) {
            return false;
        }
        
        // Use lsattr to check for immutable attribute on files in the folder
        $command = "find '{$folderPath}' -maxdepth 1 -type f -exec lsattr {} \\; 2>/dev/null | grep '^....i' | head -1";
        $result = $this->executeCommand($command, $serverInfo);
        
        // If we find any file with immutable attribute, consider the folder as immutable
        return $result['success'] && !empty(trim($result['output']));
    }

    /**
     * Check if folder exists on the server
     */
    private function folderExists(string $folderPath, array $serverInfo): bool
    {
        $command = "test -d '{$folderPath}' && echo 'exists'";
        $result = $this->executeCommand($command, $serverInfo);
        
        return $result['success'] && trim($result['output']) === 'exists';
    }

    /**
     * Get detailed immutability status for sites with folder breakdown
     */
    public function getDetailedImmutabilityStatus(array $sites): array
    {
        $results = [];
        
        foreach ($sites as $site) {
            $siteId = $site['id'];
            $sitePath = $site['path'];
            $serverInfo = $this->getServerInfo($site['server_id']);
            
            $folderStatus = $this->getFolderImmutabilityStatus($sitePath, $serverInfo);
            
            $results[$siteId] = [
                'overall_immutable' => (bool) ($site['is_immutable'] ?? false),
                'folders' => $folderStatus,
                'last_updated' => $site['updated_at'] ?? null
            ];
        }
        
        return $results;
    }

    /**
     * Get server information for immutability operations
     */
    private function getServerInfo(int $serverId): array
    {
        $pdo = $this->db->getPdo();
        $stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        $server = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$server) {
            throw new Exception("Server not found with ID: {$serverId}");
        }
        
        return $server;
    }
}