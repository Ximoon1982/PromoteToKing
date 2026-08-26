<?php
declare(strict_types=1);

$siteRoot = dirname(__DIR__, 3);
$blueBootstrap = $siteRoot . '/server/team-points/src/bootstrap.php';
if (!is_file($blueBootstrap)) {
    throw new RuntimeException('Promote to King Blue Team Points bootstrap is missing. v2.10.4 requires the v2.9.22.10 Blue engine baseline.');
}
require_once $blueBootstrap;

require_once __DIR__ . '/GreenConfig.php';
require_once __DIR__ . '/GreenRepository.php';
require_once __DIR__ . '/GreenWorker.php';
require_once __DIR__.'/GreenComparison.php';
require_once __DIR__.'/GreenIdentityMigration.php';
require_once __DIR__.'/GreenLegacyFacts.php';

require_once __DIR__.'/GreenCompatibility.php';
require_once __DIR__.'/GreenAnalyticsBootstrap.php';
