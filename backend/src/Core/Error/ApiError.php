<?php
declare(strict_types=1);

namespace App\Core\Error;

use RuntimeException;

/**
 * ApiError is thrown by application services and mapped to a closed-set
 * error envelope by the calling controller (api.md §1.2).
 */
final class ApiError extends RuntimeException
{
    private readonly string $errorCode;

    /**
     * @param array<string,mixed> $details Extra machine-readable context,
     *                                     e.g. details.fields[] for validation failures.
     */
    public function __construct(
        string $code,
        string $message,
        private readonly int $apiStatus = 422,
        private readonly array $details = [],
    ) {
        $this->errorCode = $code;
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getApiStatus(): int
    {
        return $this->apiStatus;
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}