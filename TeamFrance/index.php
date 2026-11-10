<?php
declare(strict_types=1);
require_once __DIR__.'/MatchAnalyzer/auth.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$access=team_france_authorize();
if(!$access['ok']){
    http_response_code((int)$access['status']);header('Content-Type: text/html; charset=utf-8');
    $title=$access['status']===401?'Connexion Chess.com nécessaire':($access['status']===403?'Team France accès administrateur requis':'Team France Vérification administrateur indisponible');
    $message=$access['status']===401?'Connectez-vous avec votre session OAuth Chess.com existante.':($access['status']===403?'Votre compte Chess.com ne figure pas parmi les administrateurs de Team France.':'Impossible de vérifier vos droits d’administration. Réessayez.');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Team France access</title><link rel="icon" href="/assets/images/p2k-logo.jpg"></head><body style="background:#171513;color:#f3eee5;font:16px system-ui;padding:2rem"><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    if((int)$access['status']===401)echo '<a style="color:#f5bc4d" href="/server/team-points/public/oauth.php?action=login&amp;return=%2FTeam France%2F">Se connecter avec Chess.com</a>';
    echo '</body></html>';exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/home.html');
