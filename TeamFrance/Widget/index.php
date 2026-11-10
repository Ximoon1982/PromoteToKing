<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-cache, max-age=30');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= htmlspecialchars((string)($config['team_name'] ?? 'Team'), ENT_QUOTES) ?> Match Showcase</title>
<link rel="stylesheet" href="assets/widget.css?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>">
<link rel="stylesheet" href="/TeamFrance/assets/widget-theme.css?v=tf-2153-en2">
</head>
<body>
<main class="widget-shell">
<section id="arenaSection" class="widget-section" hidden aria-label="Multi-club arenas">
  <header class="widget-section-head">
    <div class="widget-section-title">⚔️ Arènes multiclubs ⚔️</div>
  </header>
  <div id="arenaShowcase" class="tgp-grid arena-showcase" aria-live="polite"></div>
</section>
<section id="dailySection" class="widget-section" aria-label="Daily matches">
  <header class="widget-section-head">
    <div class="widget-section-title">⚔️ Matchs en différé ⚔️</div>
  </header>
  <div id="tgpGrid" class="tgp-grid" aria-live="polite"></div>
</section>
</main>
<script src="assets/widget.js?v=<?= rawurlencode((string)($config['version'] ?? '0.2.0')) ?>-tf-en2"></script>
</body>
</html>
