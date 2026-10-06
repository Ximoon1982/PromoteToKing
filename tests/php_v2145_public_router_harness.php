<?php
declare(strict_types=1);

define('P2K_PUBLIC_ROUTER_HELPER_ONLY', true);
require dirname(__DIR__) . '/PublicRouter.php';

$uriCases = [
    [['REQUEST_URI'=>'/PublicRouter.php','THE_REQUEST'=>'GET / HTTP/1.1'], '/'],
    [['REQUEST_URI'=>'/PublicRouter.php','THE_REQUEST'=>'GET /assets/app.js?v=abc HTTP/1.1'], '/assets/app.js?v=abc'],
    [['REQUEST_URI'=>'/PublicRouter.php','P2K_PUBLIC_ORIGINAL_PATH'=>'api/diagnostics/','QUERY_STRING'=>'x=1'], '/api/diagnostics/?x=1'],
];
foreach ($uriCases as [$server,$expected]) {
    $actual = p2k_public_original_uri($server);
    if ($actual !== $expected) throw new RuntimeException('public original URI mismatch: ' . $actual);
}

$pathCases = [
    ['/','index.html'],
    ['/api/diagnostics/','api/diagnostics/index.php'],
    ['/api/challenge-club-list','api/challenge-club-list/index.php'],
    ['/trophies/','trophies/index.html'],
    ['/server/team-points/public/intelligence.php','server/team-points/public/intelligence.php'],
];
foreach ($pathCases as [$input,$expected]) {
    $actual = p2k_public_relative_path($input);
    if ($actual !== $expected) throw new RuntimeException('public route target mismatch for ' . $input . ': ' . $actual);
}

foreach ([
    'MaxRatingBackfill.php',
    'OAuthTest.php',
    'auth/callback.php',
    'api/diagnostics/index.php',
    'server/team-points/public/cron.php',
    'server/team-points-green/public/cron.php',
    'server/tournaments/public/cron.php',
] as $path) {
    if (!p2k_public_php_allowed($path)) throw new RuntimeException('expected public PHP path rejected: ' . $path);
}
foreach ([
    'server/team-points/src/bootstrap.php',
    'server/release-control/public/preview-oauth-session.php',
    'queue-history-revalidation-v2.9.0.php',
] as $path) {
    if (p2k_public_php_allowed($path)) throw new RuntimeException('unsafe/non-public PHP path admitted: ' . $path);
}

echo "v2.14.5 public router harness passed\n";
