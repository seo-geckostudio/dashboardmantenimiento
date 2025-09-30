<?php

namespace WpOps\Dashboard\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use WpOps\Dashboard\Database\Connection;

/**
 * Servers Controller
 * Handles server management operations
 */
class ServersController
{
    private Connection $db;
    private Twig $view;

    public function __construct(Connection $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
    }

    /**
     * Expand wildcard paths (local)
     */
    private function expandWildcardPath(string $path): array
    {
        $expandedPaths = [];
        
        if (strpos($path, '*') !== false) {
            $globPaths = glob($path, GLOB_ONLYDIR);
            if ($globPaths) {
                $expandedPaths = array_merge($expandedPaths, $globPaths);
            }
        } else {
            $expandedPaths[] = $path;
        }
        
        return $expandedPaths;
    }

    /**
     * Expand wildcard paths (remote via SSH)
     */
    private function expandWildcardPathRemote($sshService, string $path): array
    {
        $expandedPaths = [];
        
        if (strpos($path, '*') !== false) {
            // Use SSH to expand wildcards
            $command = "ls -d {$path} 2>/dev/null";
            $result = $sshService->executeCommand($command);
            
            if ($result['success'] && !empty($result['output'])) {
                $paths = explode("\n", trim($result['output']));
                foreach ($paths as $expandedPath) {
                    $expandedPath = trim($expandedPath);
                    if (!empty($expandedPath) && $sshService->directoryExists($expandedPath)) {
                        $expandedPaths[] = $expandedPath;
                    }
                }
            }
        } else {
            if ($sshService->directoryExists($path)) {
                $expandedPaths[] = $path;
            }
        }
        
        return $expandedPaths;
    }

    /**
     * Scan a specific path for WordPress installations (remote)
     */
    private function scanPathForWordPressRemote($sshService, string $basePath): array
    {
        $sites = [];
        
        try {
            // Find directories that might contain WordPress installations
            // Look for wp-config.php files as indicators
            $command = "find {$basePath} -maxdepth 4 -name 'wp-config.php' -type f 2>/dev/null";
            $result = $sshService->executeCommand($command);
            
            if ($result['success'] && !empty($result['output'])) {
                $wpConfigFiles = explode("\n", trim($result['output']));
                
                foreach ($wpConfigFiles as $wpConfigFile) {
                    $wpConfigFile = trim($wpConfigFile);
                    if (empty($wpConfigFile)) continue;
                    
                    $wpPath = dirname($wpConfigFile);
                    
                    // Verify it's a valid WordPress installation
                    if ($this->isWordPressInstallationRemote($sshService, $wpPath)) {
                        $sites[] = [
                            'path' => $wpPath,
                            'domain' => $this->extractDomainFromPath($wpPath),
                            'user_account' => $this->extractUserFromPath($wpPath),
                            'wp_config_exists' => true,
                            'version' => $this->getWordPressVersionRemote($sshService, $wpPath),
                            'found_at' => date('Y-m-d H:i:s')
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            error_log("Error scanning remote path {$basePath}: " . $e->getMessage());
        }
        
        return $sites;
    }

    /**
     * Check if a remote directory contains a WordPress installation
     */
    private function isWordPressInstallationRemote($sshService, string $path): bool
    {
        // Check for key WordPress files
        $wpFiles = [
            'wp-includes/version.php',
            'wp-admin/index.php'
        ];
        
        $foundFiles = 0;
        foreach ($wpFiles as $file) {
            if ($sshService->fileExists($path . '/' . $file)) {
                $foundFiles++;
            }
        }
        
        // Consider it WordPress if at least 2 of the key files are present (plus wp-config.php)
        return $foundFiles >= 2;
    }

    /**
     * Get WordPress version from remote installation
     */
    private function getWordPressVersionRemote($sshService, string $path): ?string
    {
        $versionFile = $path . '/wp-includes/version.php';
        
        if (!$sshService->fileExists($versionFile)) {
            return null;
        }
        
        try {
            $command = "grep '\$wp_version' {$versionFile} | head -1";
            $result = $sshService->executeCommand($command);
            
            if ($result['success'] && !empty($result['output'])) {
                if (preg_match('/\$wp_version\s*=\s*[\'"]([\'"]+)[\'"]/', $result['output'], $matches)) {
                    return $matches[1];
                }
            }
        } catch (\Exception $e) {
            error_log("Error reading remote WordPress version: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Create JSON response
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * List all servers
     */
    public function index(Request $request, Response $response): Response
    {
        try {
            $servers = $this->db->query("SELECT * FROM servers ORDER BY name ASC");
            
            return $this->view->render($response, 'servers/index.twig', [
                'servers' => $servers,
                'title' => 'Gestión de Servidores'
            ]);
            
        } catch (\Exception $e) {
            error_log("Error loading servers: " . $e->getMessage());
            return $this->view->render($response, 'servers/index.twig', [
                'servers' => [],
                'error' => 'Error al cargar los servidores',
                'title' => 'Gestión de Servidores'
            ]);
        }
    }

    /**
     * Show create server form
     */
    public function create(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'servers/create.twig', [
            'title' => 'Añadir Servidor'
        ]);
    }

    /**
     * Store new server
     */
    public function store(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        try {
            // Validate required fields
            if (empty($data['name']) || empty($data['hostname'])) {
                throw new \Exception('Nombre y hostname son requeridos');
            }
            
            // Prepare scan paths
            $scanPaths = [];
            if (!empty($data['scan_paths'])) {
                $paths = explode("\n", $data['scan_paths']);
                $scanPaths = array_map('trim', array_filter($paths));
            }
            
            // Use the Connection class insert method instead of prepare/execute
            $serverId = $this->db->insert('servers', [
                'name' => $data['name'],
                'hostname' => $data['hostname'],
                'ip_address' => $data['ip_address'] ?? null,
                'ssh_user' => $data['ssh_user'] ?? null,
                'ssh_port' => $data['ssh_port'] ?? 22,
                'scan_paths' => implode("\n", $scanPaths),
                'is_active' => isset($data['is_active']) ? 1 : 0
            ]);
            
            return $response->withHeader('Location', '/servers')->withStatus(302);
            
        } catch (\Exception $e) {
            error_log("Error creating server: " . $e->getMessage());
            return $this->view->render($response, 'servers/create.twig', [
                'error' => $e->getMessage(),
                'data' => $data,
                'title' => 'Añadir Servidor'
            ]);
        }
    }

    /**
     * Show server details
     */
    public function show(Request $request, Response $response, array $args): Response
    {
        $serverId = (int)$args['id'];
        
        try {
            $stmt = $this->db->getPdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            if (!$server) {
                return $response->withStatus(404);
            }
            
            return $this->view->render($response, 'servers/show.twig', [
                'server' => $server,
                'title' => 'Detalles del Servidor: ' . $server['name']
            ]);
            
        } catch (\Exception $e) {
            error_log("Error loading server: " . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    /**
     * Show edit server form
     */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $serverId = (int)$args['id'];
        
        try {
            $stmt = $this->db->getPdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            if (!$server) {
                return $response->withStatus(404);
            }
            
            return $this->view->render($response, 'servers/edit.twig', [
                'server' => $server,
                'title' => 'Editar Servidor: ' . $server['name']
            ]);
            
        } catch (\Exception $e) {
            error_log("Error loading server for edit: " . $e->getMessage());
            return $response->withStatus(500);
        }
    }

    /**
     * Update server
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $serverId = (int)$args['id'];
        $data = $request->getParsedBody();
        
        try {
            // Validate required fields
            if (empty($data['name']) || empty($data['hostname'])) {
                throw new \Exception('Nombre y hostname son requeridos');
            }
            
            // Prepare scan paths
            $scanPaths = [];
            if (!empty($data['scan_paths'])) {
                $paths = explode("\n", $data['scan_paths']);
                $scanPaths = array_map('trim', array_filter($paths));
            }
            
            $updateData = [
                'name' => $data['name'],
                'hostname' => $data['hostname'],
                'ip_address' => $data['ip_address'] ?? null,
                'ssh_user' => $data['ssh_user'] ?? null,
                'ssh_port' => $data['ssh_port'] ?? 22,
                'scan_paths' => json_encode($scanPaths),
                'is_active' => isset($data['is_active']) ? 1 : 0,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            $this->db->update('servers', $updateData, ['id' => $serverId]);
            
            return $response->withHeader('Location', '/servers/' . $serverId)->withStatus(302);
            
        } catch (\Exception $e) {
            error_log("Error updating server: " . $e->getMessage());
            
            // Get server data for form
            $stmt = $this->db->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            return $this->view->render($response, 'servers/edit.twig', [
                'server' => $server,
                'error' => $e->getMessage(),
                'title' => 'Editar Servidor: ' . ($server['name'] ?? 'Desconocido')
            ]);
        }
    }

    /**
     * Delete server
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $serverId = (int)$args['id'];
        
        try {
            $affectedRows = $this->db->delete('servers', ['id' => $serverId]);
            
            if ($affectedRows > 0) {
                return $this->jsonResponse($response, ['success' => true, 'message' => 'Servidor eliminado correctamente']);
            } else {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Servidor no encontrado'], 404);
            }
            
        } catch (\Exception $e) {
            error_log("Error deleting server: " . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Error al eliminar el servidor'], 500);
        }
    }

    /**
     * Test server connectivity
     */
    public function testConnection(Request $request, Response $response, array $args): Response
    {
        $serverId = $args['id'];
        
        try {
            $stmt = $this->db->getPdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            if (!$server) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Servidor no encontrado'], 404);
            }
            
            $result = $this->testServerConnectivity($server);
            
            return $this->jsonResponse($response, $result);
            
        } catch (\Exception $e) {
            error_log("Error testing server connectivity: " . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Error interno del servidor'], 500);
        }
    }

    /**
     * Test server connectivity from form data
     */
    public function testConnectionForm(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        try {
            // Validate required fields
            if (empty($data['hostname'])) {
                return $this->jsonResponse($response, ['success' => false, 'message' => 'Hostname es requerido'], 400);
            }
            
            // Create a temporary server array from form data
            $server = [
                'hostname' => $data['hostname'],
                'ip_address' => $data['ip_address'] ?? null,
                'ssh_user' => $data['ssh_user'] ?? null,
                'ssh_port' => $data['ssh_port'] ?? 22,
                'scan_paths' => $data['scan_paths'] ?? null
            ];
            
            $result = $this->testServerConnectivity($server);
            
            return $this->jsonResponse($response, $result);
            
        } catch (\Exception $e) {
            error_log("Error testing server connectivity from form: " . $e->getMessage());
            return $this->jsonResponse($response, ['success' => false, 'message' => 'Error al probar la conexión'], 500);
        }
    }

    /**
     * Test server connectivity implementation
     */
    private function testServerConnectivity(array $server): array
    {
        $results = [];
        
        // Test basic connectivity (ping)
        $pingResult = $this->testPing($server['hostname'] ?: $server['ip_address']);
        $results['ping'] = $pingResult;
        
        // Test SSH connectivity if credentials are provided
        if (!empty($server['ssh_user'])) {
            $sshResult = $this->testSSH($server);
            $results['ssh'] = $sshResult;
        }
        
        // Test scan paths accessibility
        if (!empty($server['scan_paths'])) {
            $pathsResult = $this->testScanPaths($server);
            $results['paths'] = $pathsResult;
        }
        
        // Overall success
        $overallSuccess = $results['ping']['success'] && 
                         (!isset($results['ssh']) || $results['ssh']['success']) &&
                         (!isset($results['paths']) || $results['paths']['success']);
        
        return [
            'success' => $overallSuccess,
            'message' => $overallSuccess ? 'Conectividad exitosa' : 'Problemas de conectividad detectados',
            'details' => $results
        ];
    }

    /**
     * Test ping connectivity
     */
    private function testPing(string $host): array
    {
        $command = "ping -n 1 -w 3000 " . escapeshellarg($host);
        $output = [];
        $returnCode = 0;
        
        exec($command, $output, $returnCode);
        
        // Clean output to ensure UTF-8 compatibility
        $cleanOutput = array_map(function($line) {
            return mb_convert_encoding($line, 'UTF-8', 'UTF-8');
        }, $output);
        
        return [
            'success' => $returnCode === 0,
            'message' => $returnCode === 0 ? 'Ping exitoso' : 'Ping falló',
            'details' => implode("\n", $cleanOutput)
        ];
    }

    /**
     * Test SSH connectivity
     */
    private function testSSH(array $server): array
    {
        // For Windows, we'll use a simple approach
        // In production, you might want to use a proper SSH library
        
        $host = $server['hostname'] ?: $server['ip_address'];
        $port = $server['ssh_port'] ?: 22;
        $username = $server['ssh_user'];
        
        // Test if SSH port is open
        $connection = @fsockopen($host, $port, $errno, $errstr, 5);
        
        if ($connection) {
            fclose($connection);
            return [
                'success' => true,
                'message' => 'Puerto SSH accesible',
                'details' => "Conexión al puerto {$port} exitosa"
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Puerto SSH no accesible',
                'details' => "Error: {$errstr} ({$errno})"
            ];
        }
    }

    /**
     * Test scan paths accessibility
     */
    private function testScanPaths(array $server): array
    {
        $paths = json_decode($server['scan_paths'], true) ?: [];
        $results = [];
        $allSuccess = true;
        
        foreach ($paths as $path) {
            // For local paths, test if directory exists and is readable
            if (is_dir($path)) {
                $results[] = [
                    'path' => $path,
                    'success' => true,
                    'message' => 'Directorio accesible'
                ];
            } else {
                $results[] = [
                    'path' => $path,
                    'success' => false,
                    'message' => 'Directorio no encontrado o no accesible'
                ];
                $allSuccess = false;
            }
        }
        
        return [
            'success' => $allSuccess,
            'message' => $allSuccess ? 'Todas las rutas accesibles' : 'Algunas rutas no son accesibles',
            'details' => $results
        ];
    }

    /**
     * Show scan logs interface
     */
    public function showScanLogs(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) $args['id'];
        
        try {
            $servers = $this->db->query("SELECT * FROM servers WHERE id = ?", [$serverId]);
            
            if (empty($servers)) {
                throw new \Exception('Servidor no encontrado');
            }
            
            $server = $servers[0];
            
            return $this->view->render($response, 'servers/scan_logs.twig', [
                'server' => $server,
                'current_route' => 'servers'
            ]);
            
        } catch (\Exception $e) {
            return $this->view->render($response, 'error.twig', [
                'error' => $e->getMessage()
            ])->withStatus(404);
        }
    }

    /**
     * Get job status and logs
     */
    public function getJobStatus(Request $request, Response $response, array $args): Response
    {
        $jobId = (int) $args['id'];
        
        try {
            $jobService = new \WpOps\Dashboard\Services\JobService();
            $job = $jobService->getJobById($jobId);
            
            if (!$job) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Job no encontrado'
                ], 404);
            }
            
            return $this->jsonResponse($response, [
                'success' => true,
                'job' => $job
            ]);
            
        } catch (\Exception $e) {
            error_log("Error getting job status: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error al obtener el estado del job: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get recent jobs for a server
     */
    public function getServerJobs(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) $args['id'];
        
        try {
            $jobService = new \WpOps\Dashboard\Services\JobService();
            $jobs = $jobService->getJobsByServerId($serverId);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'jobs' => $jobs
            ]);
            
        } catch (\Exception $e) {
            error_log("Error getting server jobs: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error al obtener los jobs del servidor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start async WordPress scan
     */
    public function startAsyncScan(Request $request, Response $response, array $args): Response
    {
        $serverId = (int) $args['id'];
        
        try {
            // Validate that the server exists
            $pdo = $this->db->getPdo();
            $stmt = $pdo->prepare("SELECT id, name FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            if (!$server) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => "Servidor con ID $serverId no encontrado"
                ], 404);
            }
            
            $asyncScanService = new \WpOps\Dashboard\Services\AsyncScanService();
            $jobId = $asyncScanService->startWordPressScan($serverId);
            
            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Escaneo iniciado en segundo plano',
                'job_id' => $jobId
            ]);
            
        } catch (\Exception $e) {
            error_log("Error starting async scan: " . $e->getMessage());
            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error al iniciar el escaneo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Scan WordPress installations on a server (legacy sync method)
     */
    public function scanWordPress(Request $request, Response $response, array $args): Response
    {
        $serverId = (int)$args['id'];
        
        try {
            // Get server details
            $stmt = $this->db->getPdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            if (!$server) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Servidor no encontrado'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            // Initialize SSH connection
            $sshService = new \WpOps\Dashboard\Services\SSHService($server);
            if (!$sshService->connect()) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'No se pudo conectar al servidor via SSH. Verifique las credenciales.'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            
            // Parse scan paths and expand wildcards remotely
            $scanPaths = [];
            if (!empty($server['scan_paths'])) {
                $paths = explode("\n", $server['scan_paths']);
                $cleanPaths = array_map('trim', array_filter($paths));
                
                // Expand wildcards in paths using SSH
                foreach ($cleanPaths as $path) {
                    $expandedPaths = $this->expandWildcardPathRemote($sshService, $path);
                    $scanPaths = array_merge($scanPaths, $expandedPaths);
                }
            }
            
            if (empty($scanPaths)) {
                $sshService->disconnect();
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'No hay rutas de escaneo configuradas para este servidor'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $foundSites = [];
            $totalSites = 0;
            $validPaths = [];
            $invalidPaths = [];

            foreach ($scanPaths as $basePath) {
                if (!$sshService->directoryExists($basePath)) {
                    $invalidPaths[] = $basePath;
                    continue;
                }
                
                $validPaths[] = $basePath;
                $sites = $this->scanPathForWordPressRemote($sshService, $basePath);
                $foundSites = array_merge($foundSites, $sites);
                $totalSites += count($sites);
            }

            // Disconnect SSH
            $sshService->disconnect();

            // If no valid paths found, return informative message
            if (empty($validPaths)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Ninguna de las rutas de escaneo configuradas existe en el servidor: ' . implode(', ', $invalidPaths)
                ]);
            }
            
            // Store found sites in database
            if (!empty($foundSites)) {
                $this->storeWordPressSites($serverId, $foundSites);
            }
            
            // Update server's last scan time
            $stmt = $this->db->getPdo()->prepare("UPDATE servers SET last_scan_at = NOW() WHERE id = ?");
            $stmt->execute([$serverId]);
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => "Escaneo completado. Se encontraron {$totalSites} instalaciones de WordPress.",
                'sites_found' => $totalSites,
                'sites' => $foundSites,
                'scanned_paths' => $validPaths,
                'invalid_paths' => $invalidPaths
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Error scanning WordPress installations: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Error durante el escaneo: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Scan a specific path for WordPress installations
     */
    private function scanPathForWordPress(string $basePath): array
    {
        $sites = [];
        
        if (!is_dir($basePath)) {
            error_log("Path does not exist or is not a directory: {$basePath}");
            return $sites;
        }
        
        if (!is_readable($basePath)) {
            error_log("Path is not readable: {$basePath}");
            return $sites;
        }
        
        try {
            // Look for user directories (common patterns: /home/*, /var/www/*, etc.)
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($basePath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            
            $iterator->setMaxDepth(4); // Limit depth to avoid infinite recursion
            
            foreach ($iterator as $file) {
                try {
                    if ($file->isDir()) {
                        $dirPath = $file->getPathname();
                        
                        // Check if this directory contains WordPress
                        if ($this->isWordPressInstallation($dirPath)) {
                            $sites[] = [
                                'path' => $dirPath,
                                'domain' => $this->extractDomainFromPath($dirPath),
                                'user_account' => $this->extractUserFromPath($dirPath),
                                'wp_config_exists' => file_exists($dirPath . '/wp-config.php'),
                                'version' => $this->getWordPressVersion($dirPath),
                                'found_at' => date('Y-m-d H:i:s')
                            ];
                        }
                    }
                } catch (\Exception $e) {
                    // Skip individual files/directories that cause errors
                    error_log("Error processing file/directory: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            error_log("Error scanning path {$basePath}: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
        }
        
        return $sites;
    }

    /**
     * Check if a directory contains a WordPress installation
     */
    private function isWordPressInstallation(string $path): bool
    {
        // Check for key WordPress files
        $wpFiles = [
            'wp-config.php',
            'wp-includes/version.php',
            'wp-admin/index.php',
            'wp-content'
        ];
        
        $foundFiles = 0;
        foreach ($wpFiles as $file) {
            if (file_exists($path . '/' . $file)) {
                $foundFiles++;
            }
        }
        
        // Consider it WordPress if at least 3 of the key files are present
        return $foundFiles >= 3;
    }

    /**
     * Extract domain from path (heuristic)
     */
    private function extractDomainFromPath(string $path): ?string
    {
        // Try to extract domain from common patterns
        if (preg_match('/\/([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $path, $matches)) {
            return $matches[1];
        }
        
        // Fallback: use the last directory name
        return basename($path);
    }

    /**
     * Extract user account from path
     */
    private function extractUserFromPath(string $path): ?string
    {
        // Common patterns: /home/username, /var/www/username, etc.
        if (preg_match('/\/home\/([^\/]+)/', $path, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/\/var\/www\/([^\/]+)/', $path, $matches)) {
            return $matches[1];
        }
        
        return null;
    }

    /**
     * Get WordPress version from installation
     */
    private function getWordPressVersion(string $path): ?string
    {
        $versionFile = $path . '/wp-includes/version.php';
        
        if (!file_exists($versionFile)) {
            return null;
        }
        
        try {
            $content = file_get_contents($versionFile);
            if (preg_match('/\$wp_version\s*=\s*[\'"]([\'"]+)[\'"]/', $content, $matches)) {
                return $matches[1];
            }
        } catch (\Exception $e) {
            error_log("Error reading WordPress version: " . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Store WordPress sites in database
     */
    private function storeWordPressSites(int $serverId, array $sites): void
    {
        try {
            // First, remove existing sites for this server
            $stmt = $this->db->getPdo()->prepare("DELETE FROM wordpress_sites WHERE server_id = ?");
            $stmt->execute([$serverId]);
            
            // Insert new sites
            $stmt = $this->db->getPdo()->prepare("
                INSERT INTO wordpress_sites (server_id, path, domain, user_account, wp_version, status, last_scanned_at, created_at) 
                VALUES (?, ?, ?, ?, ?, 'active', NOW(), NOW())
            ");
            
            foreach ($sites as $site) {
                $stmt->execute([
                    $serverId,
                    $site['path'],
                    $site['domain'],
                    $site['user_account'],
                    $site['version']
                ]);
            }
        } catch (\Exception $e) {
            error_log("Error storing WordPress sites: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            throw $e;
        }
    }

    /**
     * Get WordPress sites for a server
     */
    public function getWordPressSites(Request $request, Response $response, array $args): Response
    {
        $serverId = (int)$args['id'];
        
        try {
            // Get server details
            $stmt = $this->db->getPdo()->prepare("SELECT * FROM servers WHERE id = ?");
            $stmt->execute([$serverId]);
            $server = $stmt->fetch();
            
            if (!$server) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Servidor no encontrado'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
            }
            
            // Get WordPress sites for this server
            $stmt = $this->db->getPdo()->prepare("
                SELECT * FROM wordpress_sites 
                WHERE server_id = ? 
                ORDER BY created_at DESC
            ");
            $stmt->execute([$serverId]);
            $sites = $stmt->fetchAll();
            
            $response->getBody()->write(json_encode([
                'success' => true,
                'server' => $server,
                'sites' => $sites
            ]));
            return $response->withHeader('Content-Type', 'application/json');
            
        } catch (\Exception $e) {
            error_log("Error getting WordPress sites: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'success' => false,
                'message' => 'Error al obtener los sitios WordPress'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}