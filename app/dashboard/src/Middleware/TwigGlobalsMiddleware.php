<?php

namespace WpOps\Dashboard\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Views\Twig;

/**
 * Twig Globals Middleware
 * Adds session variables as global variables to Twig templates
 */
class TwigGlobalsMiddleware implements MiddlewareInterface
{
    private Twig $twig;

    public function __construct(Twig $twig)
    {
        $this->twig = $twig;
    }

    public function process(Request $request, RequestHandler $handler): ResponseInterface
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Get Twig environment
        $twigEnv = $this->twig->getEnvironment();

        // Add session variables as globals
        $sessionData = [
            'user' => null,
            'flash_messages' => $_SESSION['flash_messages'] ?? []
        ];

        // If user is authenticated, create user object
        if (isset($_SESSION['user_id']) && isset($_SESSION['authenticated']) && $_SESSION['authenticated']) {
            $sessionData['user'] = [
                'id' => $_SESSION['user_id'],
                'username' => $_SESSION['username'] ?? '',
                'role' => $_SESSION['role'] ?? 'user',
                'authenticated' => true,
                'login_time' => $_SESSION['login_time'] ?? null
            ];
        }

        // Add session data as global variable
        $twigEnv->addGlobal('session', $sessionData);

        // Clear flash messages after adding them to globals
        if (isset($_SESSION['flash_messages'])) {
            unset($_SESSION['flash_messages']);
        }

        return $handler->handle($request);
    }
}