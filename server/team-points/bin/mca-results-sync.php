<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';

use P2K\TeamPoints\McaResultsCronService;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Repository;

try {
    $seconds = max(20, min(300, (int)($argv[1] ?? 120)));
    $started = microtime(true);
    $repo = new Repository(PublicReadDatabase::core(), PublicReadDatabase::analytics());
    if (!$repo->schemaInstalled()) $repo->upgradeExistingSchema();
    if (!$repo->schemaInstalled()) throw new RuntimeException('Team Points schema is not ready.');

    $service = new McaResultsCronService(PublicReadDatabase::analytics(), $repo);
    // Discovery owns the twice-daily schedule. It may be a no-op when the next
    // scan is not due; hydration still gets the remaining slice so interrupted
    // downloads from an earlier cycle continue to converge.
    $discoveryBudget = max(8, min($seconds - 8, (int)floor($seconds * 0.60)));
    $discovery = $service->runDiscovery($discoveryBudget, false);
    $elapsed = (int)ceil(microtime(true) - $started);
    $remaining = max(8, $seconds - $elapsed);
    $hydration = $service->runHydration($remaining);

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'version' => '2.10.6.25',
        'discovery' => $discovery,
        'hydration' => $hydration,
        'sync' => $service->status(),
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'MCA Results synchronization failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
