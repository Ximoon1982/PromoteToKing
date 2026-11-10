<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$access=team_france_authorize();
if (!$access['ok']) {
    http_response_code((int)$access['status']);
    header('Content-Type: text/html; charset=utf-8');
    $title=$access['status']===401?'Connexion OAuth Chess.com nécessaire':($access['status']===403?'Accès réservé aux administrateurs de Team France':'Vérification des droits indisponible');
    $message=$access['status']===401?'Connectez-vous avec votre session OAuth Chess.com actuelle.':($access['status']===403?'Votre compte Chess.com n’est pas administrateur de Team France.':'Les données des administrateurs Chess.com sont momentanément indisponibles.');
    $return='/TeamFrance/MatchAnalyzer/';
    if (isset($_GET['match'])&&preg_match('/^\d{1,12}$/D',(string)$_GET['match'])) $return.='?match='.rawurlencode((string)$_GET['match']);
    $login='/server/team-points/public/oauth.php?action=login&amp;return='.rawurlencode($return);
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accès à l’analyseur Team France</title></head><body style="background:#171513;color:#eee;font:16px system-ui;padding:2rem"><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    if($access['status']===401) echo '<a style="color:#9fd8ff" href="'.$login.'">Se connecter avec Chess.com</a>';
    echo '</body></html>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
$template=file_get_contents(__DIR__.'/template.html');
if ($template === false) { http_response_code(503); exit('Le modèle de l’analyseur Team France est indisponible.'); }
// Reuse the OAuth CSRF token from the already authorized page request.
$token=htmlspecialchars((string)$access['csrf'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
echo str_replace('<!-- TEAM_FRANCE_OAUTH_CSRF -->', '<meta name="team-france-oauth-csrf" content="'.$token.'">', $template);
