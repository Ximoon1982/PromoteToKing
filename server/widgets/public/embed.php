<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/api/_common.php';
require_once dirname(__DIR__).'/src/WidgetConfigStore.php';
use P2K\Widgets\WidgetConfigStore;
use P2K\TeamPoints\Auth;
$widget=trim((string)($_GET['widget']??''));
if(!in_array($widget,WidgetConfigStore::IDS,true)){http_response_code(404);exit('Unknown widget');}
$theme=trim((string)($_GET['theme']??'auto'));
if(!in_array($theme,['dark','light','auto'],true))$theme='auto';
$preview=(string)($_GET['preview']??'')==='1';
if($preview){try{Auth::requireAdmin();}catch(Throwable $e){http_response_code(403);exit('Administrator preview requires authentication');}}
$store=new WidgetConfigStore(root_dir());
$settings=$store->get($widget);
// Publishing Competition also makes its two composed child views available,
// even when their independent standalone widgets are unpublished.
// No arbitrary widget ID or remote embedding is accepted.
$competitionChild=in_array($widget,['recruitment-radar','rivalry-spotlight'],true)
    && (string)($_GET['via']??'')==='competition';
$viaCompetition=$competitionChild
    && (bool)($store->get('competition')['enabled']??false);
$published=(bool)$settings['enabled']||$viaCompetition;
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-cache, max-age=30');
?>
<!doctype html>
<html lang="en" class="p2kw-theme-<?=htmlspecialchars($theme,ENT_QUOTES)?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="dark light">
<title><?=htmlspecialchars((string)$settings['title'],ENT_QUOTES)?> — P2K Widget</title>
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-embed-v2153.css?v=p2k-2159-progress-square-2">
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-spotlight-v2159.css?v=p2k-2159-widgets-8-rivalry">
<?php if($widget==='recruitment-radar'): ?><link rel="stylesheet" href="../../../assets/css/widgets/p2k-recruitment-radar-v2159.css?v=p2k-2159-radar-ui-3"><?php endif; ?>
<?php if($widget==='rivalry-spotlight'): ?><link rel="stylesheet" href="../../../assets/css/widgets/p2k-rivalry-v2159.css?v=p2k-2159-rival-linked-2"><?php endif; ?>
<?php if($widget==='competition'): ?><link rel="stylesheet" href="../../../assets/css/widgets/p2k-competition-v2159.css?v=p2k-2159-competition-3"><?php endif; ?>
<?php if($competitionChild): ?><style>
/* The parent Competition header already displays the title and LIVE state. */
.p2kw-head{display:none!important}
</style><?php endif; ?>
</head><body data-widget="<?=htmlspecialchars($widget,ENT_QUOTES)?>" data-preview="<?=($preview||$viaCompetition)?'1':'0'?>">
<main class="p2kw-root" id="p2kwRoot" aria-live="polite">
<?php if(!$published&&!$preview): ?>
<div class="p2kw-unavailable">Widget not published</div>
<?php else: ?>
<section class="p2kw-panel"><header class="p2kw-head"><h1 id="p2kwTitle"><?=htmlspecialchars((string)$settings['title'],ENT_QUOTES)?></h1><span class="p2kw-status" id="p2kwStatus">Loading…</span></header><div class="p2kw-content" id="p2kwContent"><p class="p2kw-empty">Loading current club data…</p></div><footer class="p2kw-foot" id="p2kwFoot"></footer></section>
<?php endif; ?>
</main>
<?php if($published||$preview): ?>
<?php if($widget==='competition'): ?>
<script src="../../../assets/js/widgets/p2k-competition-v2159.js?v=p2k-2159-competition-3" defer></script>
<?php elseif($widget==='recruitment-radar'): ?>
<script src="../../../assets/js/shared/recruitment-lineup-core.js?v=p2k-2159-rivalry-green-1" defer></script>
<script src="../../../assets/js/widgets/p2k-recruitment-radar-v2159.js?v=p2k-2159-radar-ui-3" defer></script>
<?php elseif($widget==='rivalry-spotlight'): ?>
<script src="../../../assets/js/widgets/p2k-rivalry-v2159.js?v=p2k-2159-rival-linked-2" defer></script>
<?php elseif(in_array($widget,['player-spotlight','tournament-winners','hall-of-fame'],true)): ?>
<script src="../../../assets/js/widgets/p2k-spotlight-v2159.js?v=p2k-2159-hof-profile-labels-1" defer></script>
<?php else: ?>
<?php if($widget==='club-progress'):
// Chart scripts are cached as immutable for one year by PublicRouter.php.
// Tie the URL revision to the actual served chart bytes, not a release label.
$progressChartFile=dirname(__DIR__,3).'/assets/js/widgets/p2k-progress-chart-v2159.js';
$progressChartSha=is_file($progressChartFile)?hash_file('sha256',$progressChartFile):false;
$progressChartVersion=is_string($progressChartSha)&&preg_match('/^[a-f0-9]{64}$/D',$progressChartSha)
    ?substr($progressChartSha,0,16):'p2k-21510-progress-axis';
?>
<script src="../../../assets/js/widgets/p2k-progress-chart-v2159.js?v=<?=rawurlencode($progressChartVersion)?>" defer></script>
<?php endif; ?>
<script src="../../../assets/js/widgets/p2k-embed-v2153.js?v=p2k-2159-progress-square-2" defer></script>
<?php endif; ?>
<?php endif; ?>
</body></html>
