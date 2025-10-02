#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use WpOps\Agent\Database\Connection;
use WpOps\Agent\Validation\StateValidator;
use WpOps\Agent\Logger\Logger;
use Dotenv\Dotenv;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Initialize components
$logger = new Logger('weekly-validator');
$db = Connection::getInstance();
$validator = new StateValidator($db, $logger);

$logger->info('Starting weekly validation');

try {
    // Get all active WordPress sites
    $stmt = $db->prepare("
        SELECT ws.*, s.hostname, s.ssh_user, s.ssh_port
        FROM wordpress_sites ws
        LEFT JOIN servers s ON ws.server_id = s.id
        WHERE ws.is_active = 1
    ");
    $stmt->execute();
    $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $logger->info("Found {count($sites)} active sites to validate");
    
    $totalSites = count($sites);
    $validatedSites = 0;
    $totalIssues = 0;
    $siteResults = [];
    
    foreach ($sites as $site) {
        try {
            $logger->info("Validating site: {$site['name']}", [
                'site_id' => $site['id'],
                'path' => $site['path']
            ]);
            
            // Validate site state
            $result = $validator->validateSite($site);
            
            $siteResults[] = [
                'site_id' => $site['id'],
                'site_name' => $site['name'],
                'path' => $site['path'],
                'status' => $result['status'],
                'issues' => $result['issues'] ?? [],
                'checks' => $result['checks'] ?? []
            ];
            
            $validatedSites++;
            $totalIssues += count($result['issues'] ?? []);
            
            if (!empty($result['issues'])) {
                $logger->warning("Site validation issues found", [
                    'site_id' => $site['id'],
                    'issues' => $result['issues']
                ]);
            }
            
        } catch (Exception $e) {
            $logger->error("Failed to validate site {$site['id']}", [
                'error' => $e->getMessage(),
                'site_path' => $site['path']
            ]);
            
            $siteResults[] = [
                'site_id' => $site['id'],
                'site_name' => $site['name'],
                'path' => $site['path'],
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Validate global configuration
    try {
        $globalResult = $validator->validateGlobalConfiguration();
        $totalIssues += count($globalResult['issues'] ?? []);
        
        if (!empty($globalResult['issues'])) {
            $logger->warning('Global configuration issues found', [
                'issues' => $globalResult['issues']
            ]);
        }
    } catch (Exception $e) {
        $logger->error('Failed to validate global configuration', [
            'error' => $e->getMessage()
        ]);
    }
    
    // Log validation summary
    $logger->info('Weekly validation completed', [
        'total_sites' => $totalSites,
        'validated_sites' => $validatedSites,
        'total_issues' => $totalIssues
    ]);
    
    // Send notification if issues found
    if ($totalIssues > 0) {
        $validator->sendNotification([
            'type' => 'weekly_validation',
            'total_sites' => $totalSites,
            'validated_sites' => $validatedSites,
            'total_issues' => $totalIssues,
            'site_results' => $siteResults
        ]);
    }
    
} catch (Exception $e) {
    $logger->error('Weekly validation failed', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    exit(1);
}
