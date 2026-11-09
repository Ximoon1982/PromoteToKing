<?php
declare(strict_types=1);
define('P2K_PREVIEW_ROUTER_HELPER_ONLY', true);
require dirname(__DIR__).'/PreviewRouter.php';
define('P2K_PUBLIC_ROUTER_HELPER_ONLY', true);
require dirname(__DIR__).'/PublicRouter.php';
$cases=[
 ['/TGP/MatchAnalyzer/','TGP/MatchAnalyzer/index.php'],
 ['/TGP/MatchAnalyzer/api/','TGP/MatchAnalyzer/api/index.html'],
 ['/TGP/MatchAnalyzer/api/index.php','TGP/MatchAnalyzer/api/index.php'],
 ['/TGP/MatchAnalyzer/assets/tgp-tracking.js','TGP/MatchAnalyzer/assets/tgp-tracking.js'],
];
foreach($cases as [$uri,$expected]){
    $path=(string)(parse_url($uri,PHP_URL_PATH)??'');
    $preview=p2k_preview_relative_path($path);
    $public=p2k_public_relative_path($path);
    if($preview!==$expected||$public!==$expected)throw new RuntimeException("Routing mismatch for $uri: $preview / $public");
}
foreach(['TGP/MatchAnalyzer/index.php','TGP/MatchAnalyzer/api/index.php'] as $path){
    if(!p2k_public_php_allowed($path))throw new RuntimeException("Public PHP access denied: $path");
}
foreach(['TGP/Other/index.php','TGP/MatchAnalyzer/auth.php','TGP/MatchAnalyzer/MatchPolicy.php'] as $path){
    if(p2k_public_php_allowed($path))throw new RuntimeException("Unrelated/internal PHP unexpectedly exposed: $path");
}
echo "TGP release router tests passed.\n";
