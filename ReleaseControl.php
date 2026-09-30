<?php
declare(strict_types=1);

require_once __DIR__ . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseControlAuth;
use P2K\ReleaseControl\ReleaseControlState;
use P2K\ReleaseControl\ReleasePreviewSession;
use P2K\ReleaseControl\ReleasePreviewTree;

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, ['GET','POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Method not allowed');
}

header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

function rc_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rc_status_class(string $status): string
{
    return in_array($status, ['ok', 'info', 'warning', 'error'], true) ? $status : 'info';
}

function rc_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    if ($bytes < 1024) return $bytes . ' B';
    $units = ['KiB', 'MiB', 'GiB', 'TiB'];
    $value = $bytes / 1024;
    foreach ($units as $unit) {
        if ($value < 1024 || $unit === 'TiB') return number_format($value, $value >= 100 ? 0 : ($value >= 10 ? 1 : 2)) . ' ' . $unit;
        $value /= 1024;
    }
    return $bytes . ' B';
}

$auth = new ReleaseControlAuth(__DIR__);
$username = $auth->currentUsername();
$authorized = $username !== '' && $auth->isSuperAdmin($username);
$previewSession = new ReleasePreviewSession(__DIR__);
$actionError = '';

if ($username === '') http_response_code(401);
elseif (!$authorized) http_response_code(403);

if ($method === 'POST' && $authorized) {
    $providedCsrf = trim((string)($_POST['csrf'] ?? ''));
    $expectedCsrf = $auth->currentCsrfToken();
    if ($providedCsrf === '' || $expectedCsrf === '' || !hash_equals($expectedCsrf, $providedCsrf)) {
        http_response_code(403);
        $actionError = 'Release Control request validation failed. Reload this page and try again.';
    } else {
        try {
            $action = strtolower(trim((string)($_POST['action'] ?? '')));
            if ($action === 'enable-preview') {
                $previewSession->enable($username);
                header('Location: /ReleaseControl.php?preview_result=enabled', true, 303);
                exit;
            }
            if ($action === 'disable-preview') {
                $previewSession->disable();
                header('Location: /ReleaseControl.php?preview_result=disabled', true, 303);
                exit;
            }
            http_response_code(400);
            $actionError = 'Unknown Release Control action.';
        } catch (Throwable $e) {
            http_response_code(409);
            $actionError = $e->getMessage();
        }
    }
}

$snapshot = $authorized ? (new ReleaseControlState(__DIR__))->snapshot() : null;
$previewStatus = $authorized ? $previewSession->status($username) : ['enabled'=>false,'reason'=>'unauthorized'];
$previewTree = null;
if ($authorized && is_array($snapshot) && !empty($snapshot['candidate_release'])) {
    $previewTree = (new ReleasePreviewTree(__DIR__))->describeExisting((string)$snapshot['candidate_release']);
}
$previewTreeReady = is_array($previewTree);
$csrfToken = $authorized ? $auth->currentCsrfToken() : '';
$oauthResult = strtolower(trim((string)($_GET['oauth_result'] ?? '')));
$previewResult = strtolower(trim((string)($_GET['preview_result'] ?? '')));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Promote to King · Release Control</title>
<style>
:root{color-scheme:dark;--bg:#0e0d0c;--panel:#1a1815;--panel2:#211e19;--text:#f5ead9;--muted:#a99f92;--gold:#f3bd55;--line:#ffffff18;--ok:#8fd18a;--info:#8ab6ee;--warning:#e7bd68;--error:#ef8c82}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#211a12 0,#0e0d0c 44%);color:var(--text);font:15px/1.5 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh}.wrap{max-width:980px;margin:0 auto;padding:28px 18px 56px}.head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:18px}.eyebrow{text-transform:uppercase;letter-spacing:.11em;font-size:11px;color:var(--gold);font-weight:800}.head h1{margin:4px 0 3px;font-size:29px}.head p{margin:0;color:var(--muted)}.badge{border:1px solid #f3bd5544;background:#f3bd5510;color:#ffd88c;border-radius:999px;padding:7px 11px;font-size:12px;white-space:nowrap}.notice,.card{background:linear-gradient(145deg,var(--panel2),var(--panel));border:1px solid var(--line);border-radius:15px}.notice{padding:14px 16px;margin-bottom:14px}.notice strong{color:#ffd88c}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.card{padding:18px}.card.full{grid-column:1/-1}.card h2{font-size:16px;margin:0 0 12px;color:#ffe1a4}.meta{display:grid;grid-template-columns:180px 1fr;gap:7px 14px;margin:0}.meta dt{color:var(--muted)}.meta dd{margin:0;overflow-wrap:anywhere}.checks{display:grid;gap:8px}.check{display:grid;grid-template-columns:10px 180px 1fr;gap:10px;align-items:start;padding:9px 0;border-top:1px solid var(--line)}.check:first-child{border-top:0}.dot{width:9px;height:9px;border-radius:50%;margin-top:6px;background:var(--info)}.check.ok .dot{background:var(--ok)}.check.warning .dot{background:var(--warning)}.check.error .dot{background:var(--error)}.check strong{font-size:14px}.check span{color:var(--muted)}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:15px}.button{display:inline-block;text-decoration:none;border:1px solid #ffffff24;border-radius:9px;padding:9px 13px;background:#2a251e;color:var(--text);font:700 15px/1.2 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;cursor:pointer}.button.primary{background:var(--gold);border-color:var(--gold);color:#1a140b}.disabled{opacity:.45}.small{font-size:12px;color:var(--muted)}code{color:#ffd88c}@media(max-width:700px){.grid{grid-template-columns:1fr}.head{display:block}.badge{display:inline-block;margin-top:10px}.meta{grid-template-columns:1fr}.meta dd{margin-bottom:7px}.check{grid-template-columns:10px 1fr}.check span{grid-column:2}}
</style>
</head>
<body>
<main class="wrap">
  <header class="head">
    <div><div class="eyebrow">Recovery plane</div><h1>Release Control</h1><p>Standalone release diagnostics · fixed URL <code>/ReleaseControl.php</code></p></div>
    <div class="badge">v2.14.3 · Super Admin candidate preview</div>
  </header>

<?php if ($username === ''): ?>
  <section class="card full">
    <h2>Super Admin authentication required</h2>
    <p>This recovery page does not load either P2K UI shell. Sign in with the existing Chess.com OAuth flow, then return directly here.</p>
    <?php if ($oauthResult === 'fail'): ?><p class="small">The previous OAuth attempt did not complete successfully.</p><?php endif; ?>
    <div class="actions"><a class="button primary" href="<?= rc_h($auth->loginUrl()) ?>">Log in with Chess.com</a><a class="button" href="/">Return to site</a></div>
  </section>
<?php elseif (!$authorized): ?>
  <section class="card full">
    <h2>Recovery access denied</h2>
    <p>The authenticated identity <strong>@<?= rc_h($username) ?></strong> is not in the Release Control Super Admin allowlist.</p>
    <p class="small">Allowlist source: <?= rc_h($auth->allowlistSource()) ?></p>
  </section>
<?php else: ?>
  <section class="notice"><strong>Public serving is still direct-root.</strong> v2.14.3 can route only this authenticated Super Admin browser session to the registered candidate. Everyone else, OAuth callback traffic and all CRON/background execution remain on the public release. Candidate preview is read-only in this increment: non-GET/HEAD requests are blocked.</section>
  <?php if ($actionError !== ''): ?><section class="notice"><strong>Preview action failed.</strong> <?= rc_h($actionError) ?></section><?php endif; ?>
  <?php if ($previewResult === 'enabled'): ?><section class="notice"><strong>Candidate preview enabled for this browser session.</strong> Open the public site from the control below to browse the candidate.</section><?php elseif ($previewResult === 'disabled'): ?><section class="notice"><strong>Candidate preview disabled.</strong> This browser is back on the public release.</section><?php endif; ?>

  <div class="grid">
    <section class="card">
      <h2>Current public installation</h2>
      <dl class="meta">
        <dt>VERSION</dt><dd><?= rc_h($snapshot['installed_version'] ?: 'unavailable') ?></dd>
        <dt>Serving mode</dt><dd><?= rc_h($snapshot['mode']) ?></dd>
        <dt>Public release</dt><dd><?= rc_h($snapshot['public_release'] ?? 'not recorded') ?></dd>
        <dt>Build cache key</dt><dd><?= rc_h($snapshot['build_identity']['cache_key'] ?? 'not stamped / unavailable') ?></dd>
        <dt>Source HEAD</dt><dd><?= rc_h($snapshot['build_identity']['source_head_short'] ?? 'unavailable') ?></dd>
      </dl>
    </section>

    <section class="card">
      <h2>Recovery state</h2>
      <dl class="meta">
        <dt>State schema</dt><dd><?= rc_h($snapshot['schema_version']) ?></dd>
        <dt>State file</dt><dd><?= rc_h($snapshot['state_status']) ?></dd>
        <dt>Previous public</dt><dd><?= rc_h($snapshot['previous_public_release'] ?? 'not recorded') ?></dd>
        <dt>Candidate</dt><dd><?= rc_h($snapshot['candidate_release'] ?? 'none') ?></dd>
        <dt>Candidate registered</dt><dd><?= rc_h($snapshot['candidate_registered_at'] ?? 'not registered') ?></dd>
        <dt>Candidate by</dt><dd><?= rc_h($snapshot['candidate_registered_by'] ?? '—') ?></dd>
        <dt>Preview tree</dt><dd><?= $previewTreeReady ? 'prepared' : 'not prepared' ?></dd>
        <dt>My preview</dt><dd><?= !empty($previewStatus['enabled']) ? 'enabled · ' . rc_h($previewStatus['release_id'] ?? '') : 'disabled' ?></dd>
        <dt>Slot routing</dt><dd><?= !empty($snapshot['release_slots_enabled']) ? 'enabled' : 'disabled (direct-root)' ?></dd>
        <dt>Stored slots</dt><dd><?= rc_h($snapshot['slot_storage']['slot_count'] ?? 0) ?></dd>
      </dl>
    </section>

    <section class="card full">
      <h2>Recovery health</h2>
      <div class="checks">
        <?php foreach ($snapshot['health'] as $check): $class = rc_status_class((string)$check['status']); ?>
          <div class="check <?= rc_h($class) ?>"><i class="dot" aria-hidden="true"></i><strong><?= rc_h($check['label']) ?></strong><span><?= rc_h($check['detail']) ?></span></div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="card">
      <h2>Protected runtime</h2>
      <dl class="meta">
        <dt>Path</dt><dd><?= rc_h($snapshot['runtime_status']['display_path']) ?></dd>
        <dt>Exists</dt><dd><?= !empty($snapshot['runtime_status']['exists']) ? 'yes' : 'no' ?></dd>
        <dt>Readable</dt><dd><?= !empty($snapshot['runtime_status']['readable']) ? 'yes' : 'no' ?></dd>
        <dt>Writable</dt><dd><?= !empty($snapshot['runtime_status']['writable']) ? 'yes' : 'no' ?></dd>
      </dl>
    </section>

    <section class="card">
      <h2>Release-slot filesystem</h2>
      <?php $fs = $snapshot['slot_storage']['filesystem_capabilities'] ?? null; ?>
      <dl class="meta">
        <dt>Slot storage</dt><dd><?= rc_h($snapshot['slot_storage']['display_path']) ?></dd>
        <dt>Selected strategy</dt><dd><?= rc_h(is_array($fs) ? ($fs['selected_strategy'] ?? 'unknown') : 'not probed') ?></dd>
        <dt>Hard links</dt><dd><?= is_array($fs) ? (!empty($fs['hardlink_supported']) ? 'supported' : 'not available') : 'not probed' ?></dd>
        <dt>Symlinks</dt><dd><?= is_array($fs) ? (!empty($fs['symlink_supported']) ? 'supported; not used for snapshots' : 'not available') : 'not probed' ?></dd>
        <dt>Atomic rename</dt><dd><?= is_array($fs) ? (!empty($fs['atomic_rename_supported']) ? 'supported' : 'not available') : 'not probed' ?></dd>
      </dl>
    </section>

    <section class="card full">
      <h2>Installed release slots</h2>
      <?php $slots = $snapshot['slot_storage']['slots'] ?? []; ?>
      <?php if ($slots === []): ?>
        <p class="small">No release slot has been materialized yet.</p>
      <?php else: ?>
        <div class="checks">
          <?php foreach ($slots as $slot): $valid = ($slot['integrity_status'] ?? '') === 'valid'; ?>
            <div class="check <?= $valid ? 'ok' : 'error' ?>">
              <i class="dot" aria-hidden="true"></i>
              <strong><?= rc_h($slot['release_id']) ?></strong>
              <span>
                <?= $valid ? 'valid' : 'INVALID' ?> ·
                <?= rc_h($slot['strategy'] ?: 'unknown') ?> ·
                <?= rc_h($slot['file_count']) ?> files ·
                <?= rc_h(rc_bytes((int)$slot['logical_bytes'])) ?> logical ·
                <?= rc_h(rc_bytes((int)$slot['additional_bytes_at_creation'])) ?> additional at creation ·
                <?= rc_h($slot['hardlinked_files']) ?> hard-linked / <?= rc_h($slot['copied_files']) ?> copied
                <?php if (!$valid && !empty($slot['errors'])): ?> · <?= rc_h(implode('; ', $slot['errors'])) ?><?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Shared mutable paths</h2>
      <p class="small">Kept outside immutable slots by contract:</p>
      <ul class="small">
        <?php foreach (($snapshot['slot_storage']['shared_paths_external'] ?? []) as $shared): ?><li><?= rc_h($shared) ?></li><?php endforeach; ?>
      </ul>
    </section>

    <section class="card">
      <h2>Candidate installation</h2>
      <?php $candidate = $snapshot['candidate_slot'] ?? null; ?>
      <?php if (is_array($candidate)): ?>
        <dl class="meta">
          <dt>Release</dt><dd><?= rc_h($candidate['release_id'] ?? 'unknown') ?></dd>
          <dt>Integrity</dt><dd><?= rc_h($candidate['integrity_status'] ?? 'unknown') ?></dd>
          <dt>Build ID</dt><dd><?= rc_h($candidate['build_id'] ?? 'unknown') ?></dd>
          <dt>Base release</dt><dd><?= rc_h($candidate['base_release_id'] ?? 'unknown') ?></dd>
          <dt>Routing</dt><dd><?= !empty($candidate['routing_enabled']) ? 'UNEXPECTEDLY ENABLED' : 'disabled' ?></dd>
        </dl>
      <?php else: ?>
        <p class="small">No candidate is registered. Install and register a qualified candidate package before enabling personal preview.</p>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Deployment controls</h2>
      <p class="small">Personal candidate preview is available only to this authenticated Super Admin session. Writes remain blocked; public promotion and rollback are still disabled.</p>
      <div class="actions">
        <?php if (is_array($candidate) && $csrfToken !== '' && $previewTreeReady): ?>
          <?php if (!empty($previewStatus['enabled'])): ?>
            <form method="post" action="/ReleaseControl.php"><input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>"><input type="hidden" name="action" value="disable-preview"><button class="button" type="submit">Stop preview</button></form>
            <a class="button primary" href="/index.html">Open candidate site</a>
          <?php else: ?>
            <form method="post" action="/ReleaseControl.php"><input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>"><input type="hidden" name="action" value="enable-preview"><button class="button primary" type="submit">Preview candidate for me</button></form>
          <?php endif; ?>
        <?php else: ?>
          <span class="button disabled">Preview candidate</span>
          <?php if (is_array($candidate) && !$previewTreeReady): ?><span class="small">Preview tree is not prepared. Re-run the v2.14.3 candidate-preview bootstrap.</span><?php endif; ?>
        <?php endif; ?>
        <span class="button disabled">Promote candidate</span><span class="button disabled">Rollback</span>
      </div>
    </section>

    <section class="card full">
      <h2>Recovery identity</h2>
      <dl class="meta">
        <dt>Authenticated as</dt><dd>@<?= rc_h($username) ?></dd>
        <dt>Allowlist source</dt><dd><?= rc_h($auth->allowlistSource()) ?></dd>
        <dt>Application dependency</dt><dd>None on UI v1/UI v2 shell assets or JavaScript</dd>
        <dt>Candidate install</dt><dd>Enabled through verified CLI package installation</dd>
        <dt>Personal preview</dt><dd>Enabled for authenticated Super Admin session only</dd>
        <dt>Preview writes</dt><dd>Blocked until v2.14.4</dd>
        <dt>Public slot routing</dt><dd>Disabled in v2.14.3</dd>
        <dt>Promotion / rollback</dt><dd>Disabled in v2.14.3</dd>
      </dl>
      <div class="actions"><a class="button" href="/">Open public site</a></div>
    </section>
  </div>
<?php endif; ?>
</main>
</body>
</html>
