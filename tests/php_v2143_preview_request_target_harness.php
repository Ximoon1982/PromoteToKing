<?php
declare(strict_types=1);

define('P2K_PREVIEW_ROUTER_HELPER_ONLY', true);
require dirname(__DIR__) . '/PreviewRouter.php';

$cases = [
    [
        'server'=>[
            'REQUEST_URI'=>'/PreviewRouter.php',
            'THE_REQUEST'=>'GET / HTTP/1.1',
        ],
        'expected'=>'/',
        'label'=>'root internal rewrite',
    ],
    [
        'server'=>[
            'REQUEST_URI'=>'/PreviewRouter.php?x=1',
            'THE_REQUEST'=>'GET /assets/js/app.js?x=1 HTTP/1.1',
        ],
        'expected'=>'/assets/js/app.js?x=1',
        'label'=>'asset internal rewrite with query',
    ],
    [
        'server'=>[
            'REQUEST_URI'=>'/PreviewRouter.php',
            'P2K_PREVIEW_ORIGINAL_PATH'=>'ClubIntelligence.html',
            'QUERY_STRING'=>'team=abc',
        ],
        'expected'=>'/ClubIntelligence.html?team=abc',
        'label'=>'rewrite environment fallback',
    ],
    [
        'server'=>[
            'REQUEST_URI'=>'/PreviewRouter.php',
            'THE_REQUEST'=>'GET /PreviewRouter.php HTTP/1.1',
        ],
        'expected'=>'/PreviewRouter.php',
        'label'=>'direct router request stays direct',
    ],
];

foreach ($cases as $case) {
    $actual = p2k_preview_original_uri($case['server']);
    if ($actual !== $case['expected']) {
        throw new RuntimeException($case['label'] . ': expected ' . $case['expected'] . ', got ' . $actual);
    }
}

echo "v2.14.3 preview original-request harness passed\n";
