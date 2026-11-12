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
$settings=(new WidgetConfigStore(root_dir()))->get($widget);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: private, no-cache, max-age=30');
?>
<!doctype html>
<html lang="en" class="p2kw-theme-<?=htmlspecialchars($theme,ENT_QUOTES)?>"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="dark light">
<title><?=htmlspecialchars((string)$settings['title'],ENT_QUOTES)?> — P2K Widget</title>
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-embed-v2153.css?v=1">
<link rel="stylesheet" href="../../../assets/css/widgets/p2k-spotlight-v2159.css?v=1">
</head><body data-widget="<?=htmlspecialchars($widget,ENT_QUOTES)?>" data-preview="<?=$preview?'1':'0'?>">
<main class="p2kw-root" id="p2kwRoot" aria-live="polite">
<?php if(!$settings['enabled']&&!$preview): ?>
<div class="p2kw-unavailable">Widget not published</div>
<?php else: ?>
<section class="p2kw-panel"><header class="p2kw-head"><h1 id="p2kwTitle"><?=htmlspecialchars((string)$settings['title'],ENT_QUOTES)?></h1><span class="p2kw-status" id="p2kwStatus">Loading…</span></header><div class="p2kw-content" id="p2kwContent"><p class="p2kw-empty">Loading current club data…</p></div><footer class="p2kw-foot" id="p2kwFoot"></footer></section>
<?php endif; ?>
</main>
<?php if($settings['enabled']||$preview): ?>
<?php if(in_array($widget,['player-spotlight','tournament-winners'],true)): ?>
<script src="../../../assets/js/widgets/p2k-spotlight-v2159.js?v=1" defer></script>
<?php else: ?>
<script src="../../../assets/js/widgets/p2k-embed-v2153.js?v=1" defer></script>
<?php endif; ?>
<?php endif; ?>
</body></html>
