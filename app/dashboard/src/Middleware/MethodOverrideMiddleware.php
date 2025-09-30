<?php

namespace WpOps\Dashboard\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

/**
 * Method Override Middleware
 * Handles HTTP method override via _method field in POST requests
 */
class MethodOverrideMiddleware implements MiddlewareInterface
{
    /**
     * Process the request and override HTTP method if _method field is present
     */
    public function process(Request $request, RequestHandler $handler): Response
    {
        // Only process POST requests
        if ($request->getMethod() !== 'POST') {
            return $handler->handle($request);
        }

        // Get parsed body
        $parsedBody = $request->getParsedBody();
        
        // Check if _method field exists
        if (is_array($parsedBody) && isset($parsedBody['_method'])) {
            $method = strtoupper($parsedBody['_method']);
            
            // Only allow specific methods
            if (in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
                // Create new request with overridden method
                $request = $request->withMethod($method);
                
                // Remove _method from parsed body
                unset($parsedBody['_method']);
                $request = $request->withParsedBody($parsedBody);
            }
        }

        return $handler->handle($request);
    }
}