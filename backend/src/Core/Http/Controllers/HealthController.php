<?php
declare(strict_types=1);

namespace App\Core\Http\Controllers;

use App\Core\Http\Response\Envelope;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class HealthController
{
    public function __invoke(Request $request, Response $response): Response
    {
        return Envelope::success($response, [
            'status' => 'pass',
            'request_id' => $request->getAttribute('request_id')
        ]);
    }
}
