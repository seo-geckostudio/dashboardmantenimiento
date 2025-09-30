<?php

namespace WpOps\Dashboard\Controllers;

use WpOps\Dashboard\Services\ChecksumService;
use WpOps\Dashboard\Database\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Exception;

class ChecksumController
{
    private ChecksumService $checksumService;
    private Database $db;
    private LoggerInterface $logger;

    public function __construct(ChecksumService $checksumService, Database $db, LoggerInterface $logger)
    {
        $this->checksumService = $checksumService;
        $this->db = $db;
        $this->logger = $logger;
    }

    /**
     * Iniciar verificación de checksums
     */
    public function startVerification(Request $request, Response $response): Response
    {
        try {
            $data = json_decode($request->getBody()->getContents(), true);
            
            if (!isset($data['site_id'])) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'ID del sitio es requerido'
                ], 400);
            }

            $siteId = (int)$data['site_id'];
            $verificationType = $data['verification_type'] ?? 'full';

            // Validar tipo de verificación
            $validTypes = ['core', 'plugins', 'themes', 'full'];
            if (!in_array($verificationType, $validTypes)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Tipo de verificación inválido'
                ], 400);
            }

            // Verificar que el sitio existe
            $site = $this->getSiteInfo($siteId);
            if (!$site) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Sitio no encontrado'
                ], 404);
            }

            // Verificar si ya hay una verificación en progreso
            if ($this->hasRunningVerification($siteId)) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Ya hay una verificación en progreso para este sitio'
                ], 409);
            }

            $verificationId = $this->checksumService->startVerification($siteId, $verificationType);

            // Ejecutar verificación en segundo plano
            $this->executeVerificationAsync($verificationId);

            return $this->jsonResponse($response, [
                'success' => true,
                'message' => 'Verificación iniciada correctamente',
                'data' => [
                    'verification_id' => $verificationId,
                    'site_id' => $siteId,
                    'verification_type' => $verificationType
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error iniciando verificación de checksums', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener estado de verificación
     */
    public function getVerificationStatus(Request $request, Response $response): Response
    {
        try {
            $verificationId = (int)$request->getAttribute('verification_id');

            $verification = $this->getVerificationInfo($verificationId);
            if (!$verification) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Verificación no encontrada'
                ], 404);
            }

            $data = [
                'verification_id' => $verification['id'],
                'site_id' => $verification['site_id'],
                'verification_type' => $verification['verification_type'],
                'status' => $verification['status'],
                'started_at' => $verification['started_at'],
                'completed_at' => $verification['completed_at'],
                'total_files' => $verification['total_files'],
                'verified_files' => $verification['verified_files'],
                'modified_files' => $verification['modified_files'],
                'unauthorized_files' => $verification['unauthorized_files'],
                'missing_files' => $verification['missing_files']
            ];

            // Si está completada, incluir resultados detallados
            if ($verification['status'] === 'completed' && $verification['results_json']) {
                $results = json_decode($verification['results_json'], true);
                $data['results'] = $results;
                
                // Obtener archivos no autorizados
                $unauthorizedFiles = $this->checksumService->getUnauthorizedFiles($verificationId);
                $data['unauthorized_files_list'] = $unauthorizedFiles;
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => $data
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error obteniendo estado de verificación', [
                'verification_id' => $verificationId ?? null,
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener historial de verificaciones para un sitio
     */
    public function getVerificationHistory(Request $request, Response $response): Response
    {
        try {
            $siteId = (int)$request->getAttribute('site_id');
            $limit = (int)($request->getQueryParams()['limit'] ?? 10);

            $site = $this->getSiteInfo($siteId);
            if (!$site) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Sitio no encontrado'
                ], 404);
            }

            $history = $this->checksumService->getVerificationHistory($siteId, $limit);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'site_id' => $siteId,
                    'site_domain' => $site['domain'],
                    'verifications' => $history
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error obteniendo historial de verificaciones', [
                'site_id' => $siteId ?? null,
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener archivos no autorizados de una verificación
     */
    public function getUnauthorizedFiles(Request $request, Response $response): Response
    {
        try {
            $verificationId = (int)$request->getAttribute('verification_id');

            $verification = $this->getVerificationInfo($verificationId);
            if (!$verification) {
                return $this->jsonResponse($response, [
                    'success' => false,
                    'message' => 'Verificación no encontrada'
                ], 404);
            }

            $unauthorizedFiles = $this->checksumService->getUnauthorizedFiles($verificationId);

            // Agrupar por categoría de riesgo
            $groupedFiles = [
                'critical' => [],
                'high' => [],
                'medium' => [],
                'low' => []
            ];

            foreach ($unauthorizedFiles as $file) {
                $riskLevel = $file['risk_level'] ?? 'medium';
                if (!isset($groupedFiles[$riskLevel])) {
                    $groupedFiles[$riskLevel] = [];
                }
                $groupedFiles[$riskLevel][] = $file;
            }

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'verification_id' => $verificationId,
                    'total_unauthorized_files' => count($unauthorizedFiles),
                    'files_by_risk' => $groupedFiles,
                    'all_files' => $unauthorizedFiles
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error obteniendo archivos no autorizados', [
                'verification_id' => $verificationId ?? null,
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener resumen de verificaciones para el dashboard
     */
    public function getDashboardSummary(Request $request, Response $response): Response
    {
        try {
            // Obtener estadísticas generales
            $stats = $this->getVerificationStats();

            // Obtener verificaciones recientes
            $recentVerifications = $this->getRecentVerifications(5);

            // Obtener sitios con más archivos no autorizados
            $sitesWithIssues = $this->getSitesWithMostIssues(5);

            return $this->jsonResponse($response, [
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'recent_verifications' => $recentVerifications,
                    'sites_with_issues' => $sitesWithIssues
                ]
            ]);

        } catch (Exception $e) {
            $this->logger->error('Error obteniendo resumen del dashboard', [
                'error' => $e->getMessage()
            ]);

            return $this->jsonResponse($response, [
                'success' => false,
                'message' => 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Ejecutar verificación de forma asíncrona
     */
    private function executeVerificationAsync(int $verificationId): void
    {
        // En un entorno de producción, esto debería ejecutarse en una cola de trabajos
        // Por ahora, lo ejecutamos en segundo plano usando exec
        $command = "php " . __DIR__ . "/../../scripts/run_verification.php {$verificationId} > /dev/null 2>&1 &";
        exec($command);
    }

    /**
     * Verificar si hay una verificación en progreso
     */
    private function hasRunningVerification(int $siteId): bool
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT COUNT(*) FROM wordpress_checksum_verifications 
            WHERE site_id = ? AND status = 'running'
        ");
        $stmt->execute([$siteId]);
        return $stmt->fetchColumn() > 0;
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
     * Obtener información de verificación
     */
    private function getVerificationInfo(int $verificationId): ?array
    {
        $stmt = $this->db->getPdo()->prepare("SELECT * FROM wordpress_checksum_verifications WHERE id = ?");
        $stmt->execute([$verificationId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Obtener estadísticas de verificaciones
     */
    private function getVerificationStats(): array
    {
        $stats = [];

        // Total de verificaciones
        $stmt = $this->db->getPdo()->query("SELECT COUNT(*) FROM wordpress_checksum_verifications");
        $stats['total_verifications'] = $stmt->fetchColumn();

        // Verificaciones completadas
        $stmt = $this->db->getPdo()->query("
            SELECT COUNT(*) FROM wordpress_checksum_verifications WHERE status = 'completed'
        ");
        $stats['completed_verifications'] = $stmt->fetchColumn();

        // Verificaciones en progreso
        $stmt = $this->db->getPdo()->query("
            SELECT COUNT(*) FROM wordpress_checksum_verifications WHERE status = 'running'
        ");
        $stats['running_verifications'] = $stmt->fetchColumn();

        // Total de archivos no autorizados encontrados
        $stmt = $this->db->getPdo()->query("SELECT COUNT(*) FROM wordpress_unauthorized_files");
        $stats['total_unauthorized_files'] = $stmt->fetchColumn();

        // Archivos críticos encontrados
        $stmt = $this->db->getPdo()->query("
            SELECT COUNT(*) FROM wordpress_unauthorized_files WHERE risk_level = 'critical'
        ");
        $stats['critical_files'] = $stmt->fetchColumn();

        return $stats;
    }

    /**
     * Obtener verificaciones recientes
     */
    private function getRecentVerifications(int $limit): array
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT v.*, w.domain, w.path 
            FROM wordpress_checksum_verifications v
            JOIN wordpress_sites w ON v.site_id = w.id
            ORDER BY v.started_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Obtener sitios con más problemas
     */
    private function getSitesWithMostIssues(int $limit): array
    {
        $stmt = $this->db->getPdo()->prepare("
            SELECT w.id, w.domain, w.path, COUNT(uf.id) as unauthorized_files_count
            FROM wordpress_sites w
            LEFT JOIN wordpress_checksum_verifications v ON w.id = v.site_id
            LEFT JOIN wordpress_unauthorized_files uf ON v.id = uf.verification_id
            GROUP BY w.id, w.domain, w.path
            ORDER BY unauthorized_files_count DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * Crear respuesta JSON
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}