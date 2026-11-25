<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data: https://images.chesscomfiles.com; object-src 'none'; base-uri 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Team France · Classements</title><link rel="stylesheet" href="assets/style.css?v=010-player-info"></head>
<body class="widget"><main id="widget"><form id="search" class="search"><input id="username" aria-label="Rechercher un joueur" placeholder="Rechercher un joueur" autocomplete="off" maxlength="80"><button aria-label="Rechercher" type="submit">⌕</button></form><div id="status" role="status"></div><div id="profile" hidden></div><section id="daily" class="panel"><h2>Matchs en différé</h2><select aria-label="Saison des matchs en différé"></select><div class="ranking"></div><div class="pager"></div></section><section id="arena" class="panel"><h2>Arènes multiclubs</h2><select aria-label="Saison des arènes"></select><div class="ranking"></div><div class="pager"></div></section></main><script src="assets/public.js?v=010-player-info" defer></script></body></html>
