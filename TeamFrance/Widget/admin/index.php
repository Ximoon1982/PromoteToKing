<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/config.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars((string)($config['team_name'] ?? 'Team'), ENT_QUOTES) ?> Présentation des matchs · Administration</title>
<link rel="stylesheet" href="../assets/admin.css?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>">
<link rel="stylesheet" href="/TeamFrance/assets/widget-theme.css?v=tf-2153-1">
</head>
<body>
<main class="admin-shell">
<header class="admin-head"><div><h1>Présentation des matchs</h1><p><?= htmlspecialchars((string)($config['team_name'] ?? 'Team'), ENT_QUOTES) ?> · matchs en différé autonomes</p></div><a class="preview-link" href="../" target="_blank" rel="noopener">Voir la présentation</a></header>
<section id="authPanel" class="panel"><div id="authStatus">Vérification de la connexion Chess.com…</div><button id="loginButton" type="button" hidden>Se connecter avec Chess.com</button><button id="logoutButton" type="button" class="secondary" hidden>Terminer la session</button></section>
<section id="embedPanel" class="panel embed-panel" hidden>
<h2>Intégration</h2>
<div class="copy-field"><label for="displayUrl">URL du widget</label><div class="copy-row"><input id="displayUrl" type="text" readonly><button type="button" class="secondary" data-copy-target="displayUrl">Copier l’URL</button></div></div>
<div class="copy-field"><label for="iframeHtml">Iframe HTML</label><div class="copy-row copy-row-area"><textarea id="iframeHtml" rows="3" readonly></textarea><button type="button" class="secondary" data-copy-target="iframeHtml">Copier le HTML</button></div></div>
<div id="copyStatus" class="copy-status" aria-live="polite"></div>
</section>
<section id="editor" class="panel" hidden>
<div class="add-row"><input id="matchReference" type="text" autocomplete="off" placeholder="URL ou identifiant du match Chess.com"><button id="addButton" type="button">Ajouter un match</button></div>
<div id="message" class="message" aria-live="polite"></div>
<div class="hint">Les quatre premiers matchs activés apparaissent dans le widget public.</div>
<div id="matchList" class="match-list"></div>
<div class="actions"><button id="saveButton" type="button">Enregistrer la configuration</button><span id="dirtyState"></span></div>
<div class="registration-head"><div><h2>Matchs ouverts aux inscriptions</h2><p>Chargés depuis la liste des matchs Chess.com.</p></div><button id="refreshRegistration" type="button" class="secondary">Actualiser</button></div>
<div id="registrationStatus" class="registration-status" aria-live="polite"></div>
<div id="registrationList" class="registration-list"></div>
</section>
<section class="panel preview-panel"><h2>Aperçu en direct 2×2</h2><iframe id="preview" src="../" title="Présentation des matchs preview" loading="eager"></iframe></section>
</main>
<script>window.TEAM_FRANCE_WIDGET_CONFIG={teamSlug:<?= json_encode((string)($config['team_slug'] ?? 'team-france')) ?>,teamName:<?= json_encode((string)($config['team_name'] ?? 'Team France')) ?>,version:<?= json_encode((string)($config['version'] ?? '0.2.0')) ?>};</script>
<script src="../assets/admin.js?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>-tf-fr1"></script>
<script defer src="/TeamFrance/assets/localize.js?v=tf-2153-1"></script></body>
</html>
