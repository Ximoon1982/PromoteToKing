<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 500,
        public readonly string $errorCode = 'SERVER_ERROR',
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}
