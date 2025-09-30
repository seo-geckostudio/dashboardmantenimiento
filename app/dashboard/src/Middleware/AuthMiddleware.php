<?php

namespace WpOps\Dashboard\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response as SlimResponse;
use WpOps\Dashboard\Database\Connection;

/**
 * Authentication Middleware
 * Handles user authentication and session management
 */
class AuthMiddleware implements MiddlewareInterface
{
    private Connection $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function process(Request $request, RequestHandler $handler): Response
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $uri = $request->getUri();
        $path = $uri->getPath();

        // Public routes that don't require authentication
        $publicRoutes = [
            '/',
            '/login',
            '/logout'
        ];

        // Check if current path is public
        if (in_array($path, $publicRoutes) || $request->getMethod() === 'POST' && $path === '/login') {
            return $handler->handle($request);
        }

        // Check if user is authenticated
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['authenticated'])) {
            return $this->handleUnauthenticated($request);
        }

        // Validate session
        if (!$this->validateSession($_SESSION['user_id'])) {
            // Clear invalid session
            session_destroy();
            
            return $this->handleUnauthenticated($request);
        }

        // User is authenticated, continue with request
        return $handler->handle($request);
    }

    /**
     * Handle unauthenticated requests
     * Returns JSON for AJAX requests, redirects for regular requests
     */
    private function handleUnauthenticated(Request $request): Response
    {
        $response = new SlimResponse();
        
        // Check if this is an AJAX request or API call
        $contentType = $request->getHeaderLine('Content-Type');
        $accept = $request->getHeaderLine('Accept');
        $xRequestedWith = $request->getHeaderLine('X-Requested-With');
        $uri = $request->getUri()->getPath();
        
        // Detect AJAX/API requests
        $isAjax = $xRequestedWith === 'XMLHttpRequest' ||
                  strpos($contentType, 'application/json') !== false ||
                  strpos($accept, 'application/json') !== false ||
                  strpos($uri, '/async-scan') !== false ||
                  strpos($uri, '/api/') !== false;
        
        if ($isAjax) {
            // Return JSON error for AJAX requests
            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Authentication required',
                'message' => 'Please log in to continue'
            ]));
            
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(401);
        }
        
        // Redirect to login page for regular requests
        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    /**
     * Validate user session
     */
    private function validateSession(int $userId): bool
    {
        try {
            $stmt = $this->db->getPdo()->prepare(
                "SELECT id, username, is_active FROM users WHERE id = ? AND is_active = 1"
            );
            $stmt->execute([$userId]);
            
            return $stmt->fetch() !== false;
        } catch (\Exception $e) {
            // If there's a database error, assume session is invalid
            return false;
        }
    }

    /**
     * Check if user is authenticated
     */
    public static function isAuthenticated(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return isset($_SESSION['user_id']) && isset($_SESSION['authenticated']);
    }

    /**
     * Get current user ID
     */
    public static function getCurrentUserId(): ?int
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        return $_SESSION['user_id'] ?? null;
    }

    /**
     * Set user as authenticated
     */
    public static function setAuthenticated(int $userId, string $username): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['authenticated'] = true;
        $_SESSION['login_time'] = time();
    }

    /**
     * Clear authentication
     */
    public static function clearAuthentication(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        session_destroy();
    }
}