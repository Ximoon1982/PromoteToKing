<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use RuntimeException;

/**
 * Retained only so stale callers fail explicitly after the 2.11.0 Green promotion.
 * MCA now reads/writes the canonical Green Analytics database through PublicReadDatabase.
 */
final class McaBlueGreenSync
{
    public static function run(): array
    {
        throw new RuntimeException('MCA Blue to Green synchronization was retired in Promote to King 2.11.0. Green is now the primary MCA store.');
    }
}
