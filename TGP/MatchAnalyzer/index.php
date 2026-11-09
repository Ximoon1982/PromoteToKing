<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$access=tgp_authorize();
if (!$access['ok']) {
    http_response_code((int)$access['status']);
    header('Content-Type: text/html; charset=utf-8');
    $title=$access['status']===401?'Chess.com OAuth login required':($access['status']===403?'The Golden Phoenix administrator access required':'Administrator verification unavailable');
    $message=$access['status']===401?'Sign in using the existing Promote to King Chess.com OAuth login.':($access['status']===403?'The authenticated Chess.com account is not an administrator of The Golden Phoenix.':'Chess.com administrator data is temporarily unavailable.');
    $return='/TGP/MatchAnalyzer/';
    if (isset($_GET['match'])&&preg_match('/^\d{1,12}$/D',(string)$_GET['match'])) $return.='?match='.rawurlencode((string)$_GET['match']);
    $login='/server/team-points/public/oauth.php?action=login&amp;return='.rawurlencode($return);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TGP analyzer access</title></head><body style="background:#171513;color:#eee;font:16px system-ui;padding:2rem"><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    if($access['status']===401) echo '<a style="color:#9fd8ff" href="'.$login.'">Sign in with Chess.com</a>';
    echo '</body></html>';
    exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/template.html');
