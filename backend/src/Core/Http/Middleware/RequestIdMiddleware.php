<?php
declare(strict_types=1);

namespace App\Core\Http\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class RequestIdMiddleware implements MiddlewareInterface
{
    public function process(Request $request, RequestHandler $handler): Response
    {
        $requestId = $request->getHeaderLine('X-Request-Id');
        if (empty($requestId)) {
            $requestId = uniqid('req_', true);
        }

        $request = $request->withAttribute('request_id', $requestId);
        
        $response = $handler->handle($request);
        
        return $response->withHeader('X-Request-Id', $requestId);
    }
}
