<?php

namespace WpOps\Dashboard\Services;

use WpOps\Dashboard\Database\Connection;
use WpOps\Dashboard\Services\SSHService;
use Psr\Log\LoggerInterface;
use Exception;

class ChecksumService
{
    private Connection $db;
    private LoggerInterface $logger;
    private SSHService $sshService;
    
    // WordPress core files to verify
    private const CORE_FILES = [
        'wp-config.php',
        'wp-load.php',
        'wp-blog-header.php',
        'index.php',
        'wp-admin/index.php',
        'wp-includes/version.php',
        'wp-includes/wp-db.php',
        'wp-includes/functions.php'
    ];
    
    // Suspicious file extensions
    private const SUSPICIOUS_EXTENSIONS = [
        '.suspected',
        '.bak.php',
        '.backup.php',
        '.old.php',
        '.orig.php',
        '.save.php',
        '.swp',
        '.swo'
    ];
    
    // Malicious patterns to look for in files
    private const MALICIOUS_PATTERNS = [
        'eval\s*\(',
        'base64_decode\s*\(',
        'gzinflate\s*\(',
        'str_rot13\s*\(',
        'system\s*\(',
        'exec\s*\(',
        'shell_exec\s*\(',
        'passthru\s*\(',
        'file_get_contents\s*\(\s*["\']http',
        'curl_exec\s*\(',
        'fwrite\s*\(.*\$_',
        'fopen\s*\(.*\$_',
        'move_uploaded_file\s*\(',
        'mail\s*\(.*\$_'
    ];

    public function __construct(Connection $db, LoggerInterface $logger, SSHService $sshService)
    {
        $this->db = $db;
        $this->logger = $logger;
        $this->sshService = $sshService;
    }

    /**
     * Iniciar verificación de checksums para un sitio
     */
    public function startVerification(int $siteId, string $verificationType = 'full'): int
    {
        try {
            // Crear registro de verificación
            $stmt = $this->db->getPdo()->prepare("
                INSERT INTO wordpress_checksum_verifications 
                (site_id, verification_type, status, started_at) 
                VALUES (?, ?, 'running', NOW())
            ");
            
            $stmt->execute([$siteId, $verificationType]);
            $verificationId = $this->db->getPdo()->lastInsertId();
            
            $this->logger->info("Verificación de checksums iniciada", [
                'site_id' => $siteId,
                'verification_id' => $verificationId,
                'type' => $verificationType
            ]);
            
            return $verificationId;
            
        } catch (Exception $e) {
            $this->logger->error("Error iniciando verificación de checksums", [
                'site_id' => $siteId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Ejecutar verificación de checksums
     */
    public function executeVerification(int $verificationId): array
    {
        try {
            // Obtener información de la verificación
            $verification = $this->getVerificationInfo($verificationId);
            if (!$verification) {
                throw new Exception("Verificación no encontrada: {$verificationId}");
            }

            $site = $this->getSiteInfo($verification['site_id']);
            if (!$site) {
                throw new Exception("Sitio no encontrado: {$verification['site_id']}");
            }

            // Conectar al servidor
            $server = $this->getServerInfo($site['server_id']);
            $this->sshService->connect($server);

            $results = [
                'total_files' => 0,
                'verified_files' => 0,
                'modified_files' => 0,
                'unauthorized_files' => 0,
                'missing_files' => 0,
                'suspicious_files' => [],
                'modified_core_files' => [],
                'unauthorized_file_list' => []
            ];

            // Ejecutar verificación según el tipo
            switch ($verification['verification_type']) {
                case 'core':
                    $results = $this->verifyCoreFiles($site, $verificationId, $results);
                    break;
                case 'plugins':
                    $results = $this->verifyPlugins($site, $verificationId, $results);
                    break;
                case 'themes':
                    $results = $this->verifyThemes($site, $verificationId, $results);
                    break;
                case 'full':
                default:
                    $results = $this->verifyAllFiles($site, $verificationId, $results);
                    break;
            }

            // Actualizar registro de verificación
            $this->updateVerificationResults($verificationId, $results);

            $this->logger->info("Verificación de checksums completada", [
                'verification_id' => $verificationId,
                'results' => $results
            ]);

            return $results;

        } catch (Exception $e) {
            $this->updateVerificationStatus($verificationId, 'failed');
            $this->logger->error("Error ejecutando verificación de checksums", [
                'verification_id' => $verificationId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Verificar archivos del core de WordPress
     */
    private function verifyCoreFiles(array $site, int $verificationId, array $results): array
    {
        $sitePath = $site['path'];
        
        // Verificar archivos core esenciales
        foreach (self::CORE_FILES as $coreFile) {
            $fullPath = $sitePath . '/' . $coreFile;
            $results['total_files']++;
            
            if (!$this->sshService->fileExists($fullPath)) {
                $results['missing_files']++;
                $this->logger->warning("Archivo core faltante", [
                    'site_id' => $site['id'],
                    'file' => $coreFile
                ]);
                continue;
            }
            
            // Verificar si el archivo ha sido modificado
            if ($this->isFileModified($site['id'], $coreFile, $fullPath)) {
                $results['modified_files']++;
                $results['modified_core_files'][] = $coreFile;
            } else {
                $results['verified_files']++;
            }
        }

        // Buscar archivos sospechosos en directorios core
        $suspiciousFiles = $this->findSuspiciousFiles($sitePath, ['wp-admin', 'wp-includes']);
        $results['unauthorized_files'] += count($suspiciousFiles);
        $results['unauthorized_file_list'] = array_merge($results['unauthorized_file_list'], $suspiciousFiles);

        return $results;
    }

    /**
     * Verificar todos los archivos del sitio
     */
    private function verifyAllFiles(array $site, int $verificationId, array $results): array
    {
        $sitePath = $site['path'];
        
        // Primero verificar archivos core
        $results = $this->verifyCoreFiles($site, $verificationId, $results);
        
        // Buscar archivos sospechosos en todo el sitio
        $suspiciousFiles = $this->findSuspiciousFiles($sitePath);
        
        foreach ($suspiciousFiles as $suspiciousFile) {
            $this->recordUnauthorizedFile($site['id'], $verificationId, $suspiciousFile);
        }
        
        $results['unauthorized_files'] += count($suspiciousFiles);
        $results['unauthorized_file_list'] = array_merge($results['unauthorized_file_list'], $suspiciousFiles);

        return $results;
    }

    /**
     * Buscar archivos sospechosos
     */
    private function findSuspiciousFiles(string $sitePath, array $directories = []): array
    {
        $suspiciousFiles = [];
        
        try {
            // Si no se especifican directorios, buscar en todo el sitio
            if (empty($directories)) {
                $searchPath = $sitePath;
            } else {
                $searchPath = implode(' ', array_map(function($dir) use ($sitePath) {
                    return escapeshellarg($sitePath . '/' . $dir);
                }, $directories));
            }

            // Buscar archivos con extensiones sospechosas
            foreach (self::SUSPICIOUS_EXTENSIONS as $ext) {
                $command = "find {$searchPath} -name '*{$ext}' -type f 2>/dev/null";
                $result = $this->sshService->executeCommand($command);
                
                if ($result['success'] && !empty($result['output'])) {
                    $files = array_filter(explode("\n", trim($result['output'])));
                    foreach ($files as $file) {
                        $suspiciousFiles[] = [
                            'path' => $file,
                            'reason' => 'Extensión sospechosa: ' . $ext,
                            'risk_level' => 'high',
                            'category' => 'suspicious'
                        ];
                    }
                }
            }

            // Buscar archivos PHP con contenido malicioso
            $phpFiles = $this->findPHPFiles($searchPath);
            foreach ($phpFiles as $phpFile) {
                $maliciousContent = $this->scanFileForMaliciousContent($phpFile);
                if (!empty($maliciousContent)) {
                    $suspiciousFiles[] = [
                        'path' => $phpFile,
                        'reason' => 'Contenido malicioso detectado: ' . implode(', ', $maliciousContent),
                        'risk_level' => 'critical',
                        'category' => 'malware'
                    ];
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Error buscando archivos sospechosos", [
                'path' => $sitePath,
                'error' => $e->getMessage()
            ]);
        }

        return $suspiciousFiles;
    }

    /**
     * Encontrar archivos PHP
     */
    private function findPHPFiles(string $searchPath): array
    {
        $command = "find {$searchPath} -name '*.php' -type f 2>/dev/null | head -1000";
        $result = $this->sshService->executeCommand($command);
        
        if ($result['success'] && !empty($result['output'])) {
            return array_filter(explode("\n", trim($result['output'])));
        }
        
        return [];
    }

    /**
     * Escanear archivo en busca de contenido malicioso
     */
    private function scanFileForMaliciousContent(string $filePath): array
    {
        $maliciousPatterns = [];
        
        try {
            // Leer el contenido del archivo
            $command = "head -n 100 " . escapeshellarg($filePath) . " 2>/dev/null";
            $result = $this->sshService->executeCommand($command);
            
            if (!$result['success'] || empty($result['output'])) {
                return $maliciousPatterns;
            }
            
            $content = $result['output'];
            
            // Buscar patrones maliciosos
            foreach (self::MALICIOUS_PATTERNS as $pattern) {
                if (preg_match('/' . $pattern . '/i', $content)) {
                    $maliciousPatterns[] = $pattern;
                }
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Error escaneando archivo", [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }
        
        return $maliciousPatterns;
    }

    /**
     * Registrar archivo no autorizado
     */
    private function recordUnauthorizedFile(int $siteId, int $verificationId, array $fileInfo): void
    {
        try {
            $stmt = $this->db->getPdo()->prepare("
                INSERT INTO wordpress_unauthorized_files 
                (site_id, verification_id, file_path, risk_level, file_category, detection_reason) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $siteId,
                $verificationId,
                $fileInfo['path'],
                $fileInfo['risk_level'],
                $fileInfo['category'],
                $fileInfo['reason']
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error registrando archivo no autorizado", [
                'site_id' => $siteId,
                'file' => $fileInfo['path'],
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Verificar si un archivo ha sido modificado
     */
    private function isFileModified(int $siteId, string $relativePath, string $fullPath): bool
    {
        try {
            // Obtener checksum almacenado
            $stmt = $this->db->getPdo()->prepare("
                SELECT file_hash FROM wordpress_file_checksums 
                WHERE site_id = ? AND file_path = ? AND is_original = 1
            ");
            $stmt->execute([$siteId, $relativePath]);
            $storedHash = $stmt->fetchColumn();
            
            if (!$storedHash) {
                // Si no hay checksum almacenado, calcular y guardar
                $currentHash = $this->calculateFileHash($fullPath);
                if ($currentHash) {
                    $this->storeFileChecksum($siteId, $relativePath, $currentHash);
                }
                return false; // Asumimos que es original si es la primera vez
            }
            
            // Calcular checksum actual
            $currentHash = $this->calculateFileHash($fullPath);
            
            return $currentHash !== $storedHash;
            
        } catch (Exception $e) {
            $this->logger->error("Error verificando modificación de archivo", [
                'site_id' => $siteId,
                'file' => $relativePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Calcular hash de archivo
     */
    private function calculateFileHash(string $filePath): ?string
    {
        try {
            $command = "sha256sum " . escapeshellarg($filePath) . " 2>/dev/null | cut -d' ' -f1";
            $result = $this->sshService->executeCommand($command);
            
            if ($result['success'] && !empty($result['output'])) {
                return trim($result['output']);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error calculando hash de archivo", [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }

    /**
     * Almacenar checksum de archivo
     */
    private function storeFileChecksum(int $siteId, string $relativePath, string $hash): void
    {
        try {
            $stmt = $this->db->getPdo()->prepare("
                INSERT INTO wordpress_file_checksums 
                (site_id, file_path, file_hash, is_original, created_at) 
                VALUES (?, ?, ?, 1, NOW())
                ON DUPLICATE KEY UPDATE 
                file_hash = VALUES(file_hash), 
                updated_at = NOW()
            ");
            
            $stmt->execute([$siteId, $relativePath, $hash]);
            
        } catch (Exception $e) {
            $this->logger->error("Error almacenando checksum", [
                'site_id' => $siteId,
                'file' => $relativePath,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Actualizar resultados de verificación
     */
    private function updateVerificationResults(int $verificationId, array $results): void
    {
        try {
            $stmt = $this->db->getPdo()->prepare("
                UPDATE wordpress_checksum_verifications 
                SET total_files = ?, verified_files = ?, modified_files = ?, 
                    unauthorized_files = ?, missing_files = ?, results_json = ?, 
                    status = 'completed', completed_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $results['total_files'],
                $results['verified_files'],
                $results['modified_files'],
                $results['unauthorized_files'],
                $results['missing_files'],
                json_encode($results),
                $verificationId
            ]);
            
        } catch (Exception $e) {
            $this->logger->error("Error actualizando resultados de verificación", [
                'verification_id' => $verificationId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Actualizar estado de verificación
     */
    private function updateVerificationStatus(int $verificationId, string $status): void
    {
        try {
            $stmt = $this->db->getPdo()->prepare("
                UPDATE wordpress_checksum_verifications 
                SET status = ?, completed_at = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$status, $verificationId]);
            
        } catch (Exception $e) {
            $this->logger->error("Error actualizando estado de verificación", [
                'verification_id' => $verificationId,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Obtener información del sitio
     */
    private function getSiteInfo(int $siteId): ?array
    {
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM wordpress_sites WHERE id = ?");
        $stmt->execute([$siteId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtener información del servidor
     */
    private function getServerInfo(int $serverId): ?array
    {
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM servers WHERE id = ?");
        $stmt->execute([$serverId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtener información de verificación
     */
    private function getVerificationInfo(int $verificationId): ?array
    {
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM wordpress_checksum_verifications WHERE id = ?");
        $stmt->execute([$verificationId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtener historial de verificaciones para un sitio
     */
    public function getVerificationHistory(int $siteId, int $limit = 10): array
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT * FROM wordpress_checksum_verifications 
            WHERE site_id = ? 
            ORDER BY started_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$siteId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Obtener archivos no autorizados para una verificación
     */
    public function getUnauthorizedFiles(int $verificationId): array
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT * FROM wordpress_unauthorized_files 
            WHERE verification_id = ? 
            ORDER BY risk_level DESC, file_path ASC
        ");
        $stmt->execute([$verificationId]);
        return $stmt->fetchAll();
    }
}