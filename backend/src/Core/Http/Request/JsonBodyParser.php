<?php
declare(strict_types=1);

namespace App\Core\Http\Request;

use App\Core\Error\ApiError;
use Psr\Http\Message\ServerRequestInterface as Request;

final class JsonBodyParser
{
    /**
     * @return array<string,mixed>
     */
    public static function parse(Request $request, bool $allowEmpty = false): array
    {
        $body = $request->getParsedBody();
        if ($body === null) {
            $raw = (string) $request->getBody();
            if ($raw === '') {
                if ($allowEmpty) {
                    return [];
                }
                throw new ApiError('VALIDATION_FAILED', 'A JSON request body is required.', 422);
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 422);
            }
            return $decoded;
        }
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object.', 422);
        }
        return $body;
    }
}