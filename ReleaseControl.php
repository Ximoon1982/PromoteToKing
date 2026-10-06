<?php
declare(strict_types=1);

require_once __DIR__ . '/server/release-control/src/bootstrap.php';

use P2K\ReleaseControl\ReleaseControlAuth;
use P2K\ReleaseControl\ReleaseControlState;
use P2K\ReleaseControl\ReleaseDeploymentManager;
use P2K\ReleaseControl\ReleasePreviewSession;
use P2K\ReleaseControl\ReleasePreviewTree;
use P2K\ReleaseControl\ReleaseVersionManager;

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
$releaseManager = new ReleaseVersionManager(__DIR__);
$actionError = '';
$action = strtolower(trim((string)($_POST['action'] ?? '')));

if ($method === 'POST' && !$authorized && $username === '' && $action === 'enable-preview') {
    $providedCsrf = trim((string)($_POST['csrf'] ?? ''));
    $usernameHint = strtolower(trim((string)($_POST['preview_user'] ?? '')));
    if ($providedCsrf !== '' && $auth->validateControlCsrfToken($usernameHint, $providedCsrf)
        && preg_match('/^[a-z0-9_-]{1,80}$/', $usernameHint) && $auth->isSuperAdmin($usernameHint)) {
        try {
            $previewSession->beginPendingEnable($usernameHint);
            header('Location: ' . $auth->loginUrl('/ReleaseControl.php?resume_preview=1'), true, 303);
            exit;
        } catch (Throwable $e) {
            http_response_code(409);
            $actionError = $e->getMessage();
        }
    }
}

if ($username === '') http_response_code(401);
elseif (!$authorized) http_response_code(403);

if ($method === 'GET' && $authorized && (string)($_GET['resume_preview'] ?? '') === '1'
    && $previewSession->consumePendingEnable($username)) {
    try {
        $previewSession->enable($username);
        header('Location: /ReleaseControl.php?preview_result=enabled', true, 303);
        exit;
    } catch (Throwable $e) {
        http_response_code(409);
        $actionError = $e->getMessage();
    }
}

if ($method === 'POST' && $authorized) {
    $providedCsrf = trim((string)($_POST['csrf'] ?? ''));
    if ($providedCsrf === '' || !$auth->validateControlCsrfToken($username, $providedCsrf)) {
        http_response_code(403);
        $actionError = 'Release Control request validation failed. Reload this page and try again.';
    } else {
        try {
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
            if ($action === 'promote-candidate') {
                if ((string)($_POST['confirm'] ?? '') !== 'yes') {
                    throw new RuntimeException('Promotion confirmation is required.');
                }
                (new ReleaseDeploymentManager(__DIR__))->promote($username);
                $previewSession->disable();
                header('Location: /ReleaseControl.php?deployment_result=promoted', true, 303);
                exit;
            }
            if ($action === 'rollback') {
                if ((string)($_POST['confirm'] ?? '') !== 'yes') {
                    throw new RuntimeException('Rollback confirmation is required.');
                }
                (new ReleaseDeploymentManager(__DIR__))->rollback($username);
                $previewSession->disable();
                header('Location: /ReleaseControl.php?deployment_result=rolled-back', true, 303);
                exit;
            }
            if ($action === 'delete-release') {
                if ((string)($_POST['confirm'] ?? '') !== 'yes') {
                    throw new RuntimeException('Release deletion confirmation is required.');
                }
                $releaseId = trim((string)($_POST['release_id'] ?? ''));
                $releaseManager->deleteRelease($releaseId, $username);
                header('Location: /ReleaseControl.php?cleanup_result=deleted&release_id=' . rawurlencode($releaseId) . '#release-management', true, 303);
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
$csrfToken = $authorized ? $auth->controlCsrfToken($username) : '';
$oauthResult = strtolower(trim((string)($_GET['oauth_result'] ?? '')));
$previewResult = strtolower(trim((string)($_GET['preview_result'] ?? '')));
$deploymentResult = strtolower(trim((string)($_GET['deployment_result'] ?? '')));
$cleanupResult = strtolower(trim((string)($_GET['cleanup_result'] ?? '')));
$cleanupResultRelease = trim((string)($_GET['release_id'] ?? ''));
$releaseInventory = $authorized ? $releaseManager->inventory() : null;
$cleanupPreviewId = trim((string)($_GET['cleanup_preview'] ?? ''));
$cleanupPreview = null;
if ($authorized && $cleanupPreviewId !== '') {
    try { $cleanupPreview = $releaseManager->describeRelease($cleanupPreviewId); }
    catch (Throwable) { $cleanupPreview = null; }
}
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
    <div class="badge">v2.14.6 · Operational proof and hardening · atomic promotion and rollback</div>
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
  <?php if (($snapshot['mode'] ?? '') === 'slots'): ?>
    <section class="notice"><strong>Public release-slot routing is active.</strong> Public HTTP traffic and the existing HTTP/curl CRON endpoints resolve through the same atomic release pointer. The physical root remains the recovery baseline and is not overwritten during promotion or rollback.</section>
  <?php else: ?>
    <section class="notice"><strong>Public serving remains on the direct-root baseline.</strong> PublicRouter currently serves the existing root files. Candidate preview remains isolated to this authenticated Super Admin browser until you explicitly promote it.</section>
  <?php endif; ?>
  <?php if ($actionError !== ''): ?><section class="notice"><strong>Release action failed.</strong> <?= rc_h($actionError) ?></section><?php endif; ?>
  <?php if ($previewResult === 'enabled'): ?><section class="notice"><strong>Candidate preview enabled for this browser session.</strong> Open the site from the control below to browse the candidate.</section><?php elseif ($previewResult === 'disabled'): ?><section class="notice"><strong>Candidate preview disabled.</strong> This browser is back on the public release.</section><?php endif; ?>
  <?php if ($deploymentResult === 'promoted'): ?><section class="notice"><strong>Candidate promoted atomically.</strong> Public traffic now resolves from the promoted release; the former public release is retained as the rollback target.</section><?php elseif ($deploymentResult === 'rolled-back'): ?><section class="notice"><strong>Rollback completed atomically.</strong> The previous public release is serving again, and the rolled-back release is registered as the candidate for verification or re-promotion.</section><?php endif; ?>
  <?php if ($cleanupResult === 'deleted'): ?><section class="notice"><strong>Obsolete release removed.</strong> <?= rc_h($cleanupResultRelease) ?> and its release-control-owned preview/runtime artifacts were deleted.</section><?php endif; ?>

  <div class="grid">
    <section class="card">
      <h2>Current public installation</h2>
      <dl class="meta">
        <dt>Public VERSION</dt><dd><?= rc_h($snapshot['public_version'] ?: 'unavailable') ?></dd>
        <dt>Serving mode</dt><dd><?= rc_h($snapshot['mode']) ?></dd>
        <dt>Public release</dt><dd><?= rc_h($snapshot['public_release'] ?? 'not recorded') ?></dd>
        <dt>Build cache key</dt><dd><?= rc_h($snapshot['build_identity']['cache_key'] ?? 'not stamped / unavailable') ?></dd>
        <dt>Source HEAD</dt><dd><?= rc_h($snapshot['build_identity']['source_head_short'] ?? 'unavailable') ?></dd>
        <dt>Physical root VERSION</dt><dd><?= rc_h($snapshot['installed_version'] ?: 'unavailable') ?></dd>
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
        <dt>Transition #</dt><dd><?= rc_h($snapshot['transition_sequence'] ?? 0) ?></dd>
        <dt>Last transition</dt><dd><?php $lt = $snapshot['last_transition'] ?? null; ?><?= is_array($lt) ? rc_h(($lt['action'] ?? 'unknown') . ' · ' . ($lt['from'] ?? '?') . ' → ' . ($lt['to'] ?? '?')) : 'none' ?></dd>
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

    <section class="card full" id="release-management">
      <h2>Release/version management</h2>
      <p class="small">Only release-control-owned immutable slots, candidate previews and routed runtime trees are managed here. Shared <code>data/</code>, <code>logs/</code>, <code>storage/</code>, the physical recovery root and unrelated projects are outside cleanup scope.</p>
      <?php $rmTotals = is_array($releaseInventory) ? ($releaseInventory['totals'] ?? []) : []; ?>
      <dl class="meta">
        <dt>Managed releases</dt><dd><?= rc_h($rmTotals['managed_release_count'] ?? 0) ?></dd>
        <dt>Protected</dt><dd><?= rc_h($rmTotals['protected_count'] ?? 0) ?></dd>
        <dt>Removable</dt><dd><?= rc_h($rmTotals['removable_count'] ?? 0) ?></dd>
        <dt>Removable entries</dt><dd><?= rc_h($rmTotals['removable_inode_entries'] ?? 0) ?> files/directories</dd>
        <dt>Apparent size</dt><dd><?= rc_h(rc_bytes((int)($rmTotals['removable_apparent_bytes'] ?? 0))) ?></dd>
        <dt>Estimated reclaimable disk</dt><dd><?= rc_h(rc_bytes((int)($rmTotals['estimated_reclaimable_bytes'] ?? 0))) ?> <span class="small">(hard-link aware estimate)</span></dd>
      </dl>
      <?php $managedReleases = is_array($releaseInventory) ? ($releaseInventory['releases'] ?? []) : []; ?>
      <?php if ($managedReleases === []): ?>
        <p class="small">No release-control-managed release artifacts were found.</p>
      <?php else: ?>
        <div class="checks">
          <?php foreach ($managedReleases as $release): $stats = $release['stats'] ?? []; $roles = $release['roles'] ?? []; ?>
            <div class="check <?= !empty($release['protected']) ? 'ok' : (!empty($release['cleanup_ready']) ? 'warning' : 'error') ?>">
              <i class="dot" aria-hidden="true"></i>
              <strong><?= rc_h($release['release_id'] ?? 'unknown') ?></strong>
              <span>
                <?= $roles !== [] ? 'PROTECTED · ' . rc_h(implode(' + ', $roles)) : 'obsolete / unreferenced' ?> ·
                <?= rc_h(implode(' + ', $release['artifacts'] ?? [])) ?> ·
                <?= rc_h($stats['inode_entries'] ?? 0) ?> entries ·
                <?= rc_h(rc_bytes((int)($stats['apparent_bytes'] ?? 0))) ?> apparent ·
                <?= rc_h(rc_bytes((int)($stats['estimated_reclaimable_bytes'] ?? 0))) ?> estimated reclaimable
                <?php if (!empty($stats['hardlink_preserved_bytes'])): ?> · <?= rc_h(rc_bytes((int)$stats['hardlink_preserved_bytes'])) ?> still shared by hard links<?php endif; ?>
                <?php if (empty($release['protected']) && !empty($release['cleanup_ready'])): ?>
                  · <a class="button" href="/ReleaseControl.php?cleanup_preview=<?= rawurlencode((string)$release['release_id']) ?>#release-management">Preview deletion</a>
                <?php elseif (!empty($stats['scan_errors'])): ?>
                  · scan blocked: <?= rc_h(implode('; ', $stats['scan_errors'])) ?>
                <?php endif; ?>
              </span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (is_array($cleanupPreview)): $cpStats = $cleanupPreview['stats'] ?? []; ?>
        <div class="notice" style="margin-top:14px">
          <strong>Deletion preview — nothing has been deleted yet.</strong>
          <p><?= rc_h($cleanupPreview['release_id'] ?? '') ?> · <?= rc_h(implode(' + ', $cleanupPreview['artifacts'] ?? [])) ?> · <?= rc_h($cpStats['inode_entries'] ?? 0) ?> entries · <?= rc_h(rc_bytes((int)($cpStats['estimated_reclaimable_bytes'] ?? 0))) ?> estimated reclaimable.</p>
          <?php if (!empty($cleanupPreview['protected'])): ?>
            <p>Deletion is blocked because this release is protected as <?= rc_h(implode(', ', $cleanupPreview['roles'] ?? [])) ?>.</p>
          <?php elseif (empty($cleanupPreview['cleanup_ready'])): ?>
            <p>Deletion is blocked because the managed artifact scan did not complete safely.</p>
          <?php else: ?>
            <p class="small">This removes only the immutable slot and any derived preview/runtime tree for this release ID. Shared mutable data and the physical P2K root are not touched.</p>
            <form method="post" action="/ReleaseControl.php">
              <input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>">
              <input type="hidden" name="action" value="delete-release">
              <input type="hidden" name="release_id" value="<?= rc_h($cleanupPreview['release_id'] ?? '') ?>">
              <label class="small"><input type="checkbox" name="confirm" value="yes" required> Confirm permanent deletion of this obsolete managed release</label>
              <button class="button" type="submit">Delete obsolete release</button>
            </form>
          <?php endif; ?>
        </div>
      <?php elseif ($cleanupPreviewId !== ''): ?>
        <p class="small">Requested cleanup preview is unavailable or invalid.</p>
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
      <p class="small">Preview remains personal and side-effect-isolated. Promotion and rollback verify immutable slots and runtime trees first, then change the public release with one atomic state-file replacement. No application tree is copied over the live root during the switch.</p>
      <div class="actions">
        <?php if (is_array($candidate) && $csrfToken !== '' && $previewTreeReady): ?>
          <?php if (!empty($previewStatus['enabled'])): ?>
            <form method="post" action="/ReleaseControl.php"><input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>"><input type="hidden" name="action" value="disable-preview"><button class="button" type="submit">Stop preview</button></form>
            <a class="button primary" href="/index.html">Open candidate site</a>
          <?php else: ?>
            <form method="post" action="/ReleaseControl.php"><input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>"><input type="hidden" name="preview_user" value="<?= rc_h($username) ?>"><input type="hidden" name="action" value="enable-preview"><button class="button" type="submit">Preview candidate for me</button></form>
          <?php endif; ?>
        <?php else: ?>
          <span class="button disabled">Preview candidate</span>
          <?php if (is_array($candidate) && !$previewTreeReady): ?><span class="small">Preview tree is not prepared. Re-run the current candidate-preview bootstrap.</span><?php endif; ?>
        <?php endif; ?>

        <?php if (!empty($snapshot['capabilities']['promotion']) && is_array($candidate) && $csrfToken !== ''): ?>
          <form method="post" action="/ReleaseControl.php">
            <input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>"><input type="hidden" name="action" value="promote-candidate">
            <label class="small"><input type="checkbox" name="confirm" value="yes" required> Confirm public switch to <?= rc_h($candidate['release_id'] ?? 'candidate') ?></label>
            <button class="button primary" type="submit">Promote candidate</button>
          </form>
        <?php else: ?><span class="button disabled">Promote candidate</span><?php endif; ?>

        <?php if (!empty($snapshot['capabilities']['rollback']) && $csrfToken !== ''): ?>
          <form method="post" action="/ReleaseControl.php">
            <input type="hidden" name="csrf" value="<?= rc_h($csrfToken) ?>"><input type="hidden" name="action" value="rollback">
            <label class="small"><input type="checkbox" name="confirm" value="yes" required> Confirm rollback to <?= rc_h($snapshot['previous_public_release'] ?? 'previous release') ?></label>
            <button class="button" type="submit">Rollback</button>
          </form>
        <?php else: ?><span class="button disabled">Rollback</span><?php endif; ?>
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
        <dt>Preview side effects</dt><dd>Isolated: read-only DB sessions + protected preview runtime/session sandbox</dd>
        <dt>Public slot routing</dt><dd><?= ($snapshot['mode'] ?? '') === 'slots' ? 'active' : 'available; direct-root baseline currently active' ?></dd>
        <dt>Public CRON routing</dt><dd>follows the same public release pointer</dd>
        <dt>Promotion</dt><dd><?= !empty($snapshot['capabilities']['promotion']) ? 'available' : 'not currently available' ?></dd>
        <dt>Rollback</dt><dd><?= !empty($snapshot['capabilities']['rollback']) ? 'available' : 'not currently available' ?></dd>
      </dl>
      <div class="actions"><a class="button" href="/">Open public site</a></div>
    </section>
  </div>
<?php endif; ?>
</main>
</body>
</html>
