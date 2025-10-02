#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use WpOps\Agent\Database\Connection;
use WpOps\Agent\Scanner\SiteDetector;
use WpOps\Agent\Logger\Logger;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize components
$logger = new Logger('site-scanner');
$db = Connection::getInstance();
$detector = new SiteDetector($db, $logger);

$logger->info('Starting WordPress site detection');

try {
    // Create a new detection run record
    $stmt = $db->prepare("
        INSERT INTO detection_runs (status, started_at) 
        VALUES ('running', NOW())
    ");
    $stmt->execute();
    $runId = $db->lastInsertId();
    
    $logger->info("Created detection run {$runId}");
    
    // Get scan roots from registered servers
    $stmt = $db->prepare("
        SELECT id, name, hostname, scan_paths 
        FROM servers 
        WHERE is_active = 1
    ");
    $stmt->execute();
    $servers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($servers)) {
        $logger->warning('No active servers found, using environment scan paths');
        
        // Fallback to environment variable
        $scanRoots = explode(',', $_ENV['WP_SITES_ROOT'] ?? '/var/www');
        $servers = [[
            'id' => null,
            'name' => 'Local Environment',
            'hostname' => 'localhost',
            'scan_paths' => json_encode($scanRoots)
        ]];
    }
    
    $totalSites = 0;
    $newSites = 0;
    $removedSites = 0;
    
    foreach ($servers as $server) {
        $serverId = $server['id'];
        $serverName = $server['name'];
        $scanPaths = json_decode($server['scan_paths'], true) ?? [];
        
        $logger->info("Scanning server: {$serverName}", [
            'server_id' => $serverId,
            'scan_paths' => $scanPaths
        ]);
        
        foreach ($scanPaths as $scanRoot) {
            $logger->info("Scanning path: {$scanRoot}");
            
            try {
                $result = $detector->scanPath($scanRoot, $serverId);
                
                $totalSites += $result['total_sites'];
                $newSites += $result['new_sites'];
                $removedSites += $result['removed_sites'];
                
                $logger->info("Scan completed for {$scanRoot}", [
                    'total_sites' => $result['total_sites'],
                    'new_sites' => $result['new_sites'],
                    'removed_sites' => $result['removed_sites']
                ]);
                
            } catch (Exception $e) {
                $logger->error("Failed to scan path {$scanRoot}", [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }
    
    // Update detection run with results
    $stmt = $db->prepare("
        UPDATE detection_runs 
        SET status = 'completed', 
            completed_at = NOW(),
            total_sites = ?,
            new_sites = ?,
            removed_sites = ?
        WHERE id = ?
    ");
    $stmt->execute([$totalSites, $newSites, $removedSites, $runId]);
    
    $logger->info('Site detection completed', [
        'run_id' => $runId,
        'total_sites' => $totalSites,
        'new_sites' => $newSites,
        'removed_sites' => $removedSites
    ]);
    
} catch (Exception $e) {
    $logger->error('Site detection failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    // Update detection run as failed
    if (isset($runId)) {
        $stmt = $db->prepare("
            UPDATE detection_runs 
            SET status = 'failed', 
                completed_at = NOW(),
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$e->getMessage(), $runId]);
    }
    
    exit(1);
}
