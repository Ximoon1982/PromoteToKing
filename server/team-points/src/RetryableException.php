<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use RuntimeException;

/**
 * Signals a transient upstream or network failure that should be retried.
 *
 * This class intentionally lives in its own PSR-style file so bootstrap.php's
 * namespace autoloader can resolve it directly.
 */
final class RetryableException extends RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfterSeconds = 30)
    {
        parent::__construct($message);
    }
}
