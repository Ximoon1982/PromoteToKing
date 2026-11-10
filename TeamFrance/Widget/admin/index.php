<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars((string)($config['team_name'] ?? 'Team'), ENT_QUOTES) ?> Match Showcase · Admin</title>
<link rel="stylesheet" href="../assets/admin.css?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>">
</head>
<body>
<main class="admin-shell">
<header class="admin-head"><div><h1>Match Showcase</h1><p><?= htmlspecialchars((string)($config['team_name'] ?? 'Team'), ENT_QUOTES) ?> · standalone Daily matches</p></div><a class="preview-link" href="../" target="_blank" rel="noopener">Open display</a></header>
<section id="authPanel" class="panel"><div id="authStatus">Checking Chess.com login…</div><button id="loginButton" type="button" hidden>Log in with Chess.com</button><button id="logoutButton" type="button" class="secondary" hidden>End admin session</button></section>
<section id="embedPanel" class="panel embed-panel" hidden>
<h2>Embed</h2>
<div class="copy-field"><label for="displayUrl">Card URL</label><div class="copy-row"><input id="displayUrl" type="text" readonly><button type="button" class="secondary" data-copy-target="displayUrl">Copy URL</button></div></div>
<div class="copy-field"><label for="iframeHtml">Iframe HTML</label><div class="copy-row copy-row-area"><textarea id="iframeHtml" rows="3" readonly></textarea><button type="button" class="secondary" data-copy-target="iframeHtml">Copy HTML</button></div></div>
<div id="copyStatus" class="copy-status" aria-live="polite"></div>
</section>
<section id="editor" class="panel" hidden>
<div class="add-row"><input id="matchReference" type="text" autocomplete="off" placeholder="Chess.com match URL, ID or slug"><button id="addButton" type="button">Add match</button></div>
<div id="message" class="message" aria-live="polite"></div>
<div class="hint">The first four enabled matches below are the four public cards.</div>
<div id="matchList" class="match-list"></div>
<div class="actions"><button id="saveButton" type="button">Save configuration</button><span id="dirtyState"></span></div>
<div class="registration-head"><div><h2>Matches currently in registration</h2><p>Loaded directly from the Chess.com club match index.</p></div><button id="refreshRegistration" type="button" class="secondary">Refresh</button></div>
<div id="registrationStatus" class="registration-status" aria-live="polite"></div>
<div id="registrationList" class="registration-list"></div>
</section>
<section class="panel preview-panel"><h2>Live 2×2 preview</h2><iframe id="preview" src="../" title="Match Showcase preview" loading="eager"></iframe></section>
</main>
<script>window.TEAM_FRANCE_WIDGET_CONFIG={teamSlug:<?= json_encode((string)($config['team_slug'] ?? 'team-france')) ?>,teamName:<?= json_encode((string)($config['team_name'] ?? 'Team France')) ?>,version:<?= json_encode((string)($config['version'] ?? '0.2.0')) ?>};</script>
<script src="../assets/admin.js?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>"></script>
</body>
</html>
