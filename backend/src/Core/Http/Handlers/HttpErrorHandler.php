<?php
declare(strict_types=1);

namespace App\Core\Http\Handlers;

use App\Core\Http\Response\Envelope;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\Exception\HttpException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;

class HttpErrorHandler extends SlimErrorHandler
{
    protected function respond(): Response
    {
        $exception = $this->exception;
        $statusCode = 500;
        $code = 'INTERNAL_ERROR';
        $message = 'An internal error has occurred while processing your request.';
        $details = [];

        if ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
            $code = 'HTTP_ERROR_' . $statusCode;
            $message = $exception->getMessage();
        } else if ($this->displayErrorDetails) {
            $message = $exception->getMessage();
            $details = [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ];
        }

        $response = $this->responseFactory->createResponse();
        
        $request = $this->request;
        if ($request && $request->getAttribute('request_id')) {
            $details['request_id'] = $request->getAttribute('request_id');
        }

        return Envelope::error($response, $code, $message, $details, $statusCode);
    }
}
