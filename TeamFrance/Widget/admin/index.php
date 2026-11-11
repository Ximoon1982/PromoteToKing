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
<link rel="stylesheet" href="/TeamFrance/assets/widget-theme.css?v=tf-2153-en2">
</head>
<body>
<main class="admin-shell">
<header class="admin-head"><div><h1>Match Showcase</h1><p><?= htmlspecialchars((string)($config['team_name'] ?? 'Team'), ENT_QUOTES) ?> · Daily matches and arenas</p></div><a class="preview-link" href="../" target="_blank" rel="noopener">Open display</a></header>
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
<div class="hint">The first enabled Daily matches fill the configured rows (two cards per row, up to six cards).</div>
<div id="matchList" class="match-list"></div>
<section class="arena-editor" aria-labelledby="arenaTitle">
<div class="arena-editor-head"><h2 id="arenaTitle">Multi-club arenas</h2><button id="addArenaButton" type="button" class="secondary">Add arena</button></div>
<p class="hint">Up to four enabled arenas fill the configured MCA rows. Dates and times use Europe/Luxembourg time. Arena edits save immediately; Daily-match changes use Save configuration.</p>
<div id="arenaList" class="arena-list"></div>
</section>
<dialog id="arenaModal" class="arena-modal" aria-labelledby="arenaModalTitle">
  <form id="arenaForm" class="arena-modal-form" novalidate>
    <div class="arena-modal-head"><h2 id="arenaModalTitle">Add arena</h2><button id="arenaModalCancel" type="button" class="secondary" aria-label="Close arena editor">✕</button></div>
    <div class="arena-modal-fields">
      <label class="wide">Arena name<input name="name" required maxlength="120" autocomplete="off"></label>
      <label class="wide">Chess.com arena URL<input name="link" type="url" required maxlength="600" autocomplete="off" placeholder="https://www.chess.com/tournament/live/arena/..."></label>
      <label>Start date (Luxembourg)<input name="date" type="date" required></label>
      <label>Start time (Luxembourg)<input name="time" type="time" required></label>
      <label>Time control<input name="timeControl" maxlength="40" autocomplete="off" placeholder="3+2"></label>
      <label>Duration<input name="duration" required maxlength="40" autocomplete="off" placeholder="90m, 2h or 1:30"></label>
    </div>
    <div id="arenaModalError" class="arena-modal-error" role="alert" aria-live="polite"></div>
    <div class="arena-modal-actions"><button type="button" class="secondary" onclick="document.getElementById('arenaModalCancel').click()">Cancel</button><button id="arenaModalSave" type="submit">Save arena</button></div>
  </form>
</dialog>
<section class="layout-editor" aria-labelledby="layoutTitle"><h2 id="layoutTitle">Public widget card rows</h2><div class="layout-fields"><label for="arenaRows">MCA rows<select id="arenaRows"><option value="1">1 row · 2 cards</option><option value="2">2 rows · 4 cards</option></select></label><label for="dailyRows">Daily-match rows<select id="dailyRows"><option value="1">1 row · 2 cards</option><option value="2">2 rows · 4 cards</option><option value="3">3 rows · 6 cards</option></select></label></div><p class="hint">Changes save immediately. The iframe HTML above updates its height for the selected rows. Copy the refreshed iframe when changing the layout on an external page.</p></section>
<div class="actions"><button id="saveButton" type="button">Save configuration</button><span id="dirtyState"></span></div>
<div class="registration-head"><div><h2>Matches currently in registration</h2><p>Loaded directly from the Chess.com club match index.</p></div><button id="refreshRegistration" type="button" class="secondary">Refresh</button></div>
<div id="registrationStatus" class="registration-status" aria-live="polite"></div>
<div id="registrationList" class="registration-list"></div>
</section>
<section class="panel preview-panel"><h2>Live showcase preview (arenas + Daily matches)</h2><iframe id="preview" src="../" title="Match Showcase preview" loading="eager"></iframe></section>
</main>
<script>window.TEAM_FRANCE_WIDGET_CONFIG={teamSlug:<?= json_encode((string)($config['team_slug'] ?? 'team-france')) ?>,teamName:<?= json_encode((string)($config['team_name'] ?? 'Team France')) ?>,version:<?= json_encode((string)($config['version'] ?? '0.2.0')) ?>};</script>
<script src="../assets/admin.js?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>-tf-en2"></script>
</body>
</html>
