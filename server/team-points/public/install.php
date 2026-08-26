<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$status = 'Configuration not detected.';
$ready = false;
try {
    $config = p2k_tp_config();
    $database = $config['database'] ?? [];
    $ready = !empty($database['host']) && !empty($database['name']) && !empty($database['user']) && !empty($database['password']);
    $status = $ready
        ? 'Server-side database configuration detected. The schema is installed or upgraded automatically in a bounded operation when a verified club administrator opens Team Points. Historical board-state data is backfilled incrementally by the worker.'
        : 'Complete the protected server-side database configuration before opening Team Points. Existing valid configuration files do not need changes for v2.8.0.';
} catch (Throwable $exception) {
    $status = 'Protected server-side configuration is not available yet.';
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>P2K Team Points setup</title>
<style>body{margin:0;background:#100f0e;color:#eee;font:15px Arial;padding:24px}.box{max-width:700px;margin:auto;padding:22px;border:1px solid #8b6528;border-radius:14px;background:linear-gradient(135deg,#27231f,#171513)}h1{color:#f6b73c}.ok{color:#91e09a}.note{color:#c9c2b9;line-height:1.6}a{display:inline-flex;margin-top:12px;padding:10px 14px;border-radius:7px;background:#d98d18;color:#fff;font-weight:700;text-decoration:none}code{color:#ffd078}</style></head><body><main class="box"><h1>Promote to King Team Points</h1>
<p class="<?= $ready ? 'ok' : 'note' ?>"><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></p>
<p class="note">Database credentials and administrator secrets are never requested in the browser. They remain in <code>server/team-points/config/config.local.php</code>, which is denied by the packaged web-server rules.</p>
<a href="../../../TeamPointsAdmin.html">Open Team Points</a>
</main></body></html>
