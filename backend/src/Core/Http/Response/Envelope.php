<?php
declare(strict_types=1);

namespace App\Core\Http\Response;

use Psr\Http\Message\ResponseInterface as Response;

class Envelope
{
    public static function success(Response $response, array|object $data = [], int $status = 200): Response
    {
        $payload = [
            'success' => true,
            'data' => $data
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    public static function error(Response $response, string $code, string $message, array $details = [], int $status = 400): Response
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details
            ]
        ];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
