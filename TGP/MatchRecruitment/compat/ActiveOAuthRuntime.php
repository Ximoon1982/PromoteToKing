<?php
declare(strict_types=1);

/** Standalone recruitment is physically served; choose the same immutable
 * public core and shared host config as PublicRouter, never the preview tree. */
function recruitment_active_oauth_runtime(string $root): void {
    if (!empty($_COOKIE['P2KRC_PREVIEW']) || (string)(getenv('P2K_PREVIEW_ACTIVE') ?: ($_SERVER['P2K_PREVIEW_ACTIVE'] ?? '')) === '1') {
        throw new RuntimeException('Standalone recruitment is disabled in Release Control preview.');
    }
    require_once $root.'/server/release-control/src/bootstrap.php';
    $state=(new \P2K\ReleaseControl\ReleaseStateStore($root))->read();
    $appRoot=$root;
    $mode=(string)($state['mode']??'direct-root');
    if($mode==='slots'){
        $id=trim((string)($state['public_release']??''));
        $manifest=trim((string)($state['public_manifest_sha256']??''));
        if($id===''||$manifest==='')throw new RuntimeException('Public release selection is incomplete.');
        $runtime=(new \P2K\ReleaseControl\ReleaseRuntimeTree($root))->describeExisting($id,$manifest);
        if(!is_array($runtime)||empty($runtime['valid']))throw new RuntimeException('Public release runtime is unavailable.');
        $appRoot=(string)$runtime['app_root'];
    }elseif($mode!=='direct-root')throw new RuntimeException('Unsupported public release mode.');
    $config=$root.'/server/team-points/config/config.local.php';
    $bootstrap=$appRoot.'/server/team-points/src/bootstrap.php';
    if(!is_file($config)||!is_file($bootstrap))throw new RuntimeException('Active OAuth core configuration is unavailable.');
    // Refuse to mix classes loaded from different trees within this request.
    if(defined('P2K_TP_ROOT') && realpath((string)P2K_TP_ROOT)!==realpath(dirname($bootstrap,2)))throw new RuntimeException('Mixed OAuth runtime.');
    putenv('P2K_TP_CONFIG='.$config);
    putenv('P2K_PUBLIC_ROOT='.$root);
    require_once $bootstrap;
    if(!is_callable([\P2K\TeamPoints\OAuthRateCoordinator::class,'policy']))throw new RuntimeException('Active OAuth core is too old for this recruitment integration.');
}
