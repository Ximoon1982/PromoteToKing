<?php
declare(strict_types=1);
require_once __DIR__.'/MatchRecruitment/auth.php';
header('Cache-Control: no-store, private');
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$access=tgp_authorize();
if(!$access['ok']){
    http_response_code((int)$access['status']);header('Content-Type: text/html; charset=utf-8');
    $title=$access['status']===401?'Chess.com login required':($access['status']===403?'The Golden Phoenix administrator access required':'TGP administrator verification unavailable');
    $message=$access['status']===401?'Log in using the existing P2K Chess.com OAuth session.':($access['status']===403?'Your current Chess.com account is not listed as a TGP administrator.':'Administrator authorization could not be verified. Please try again.');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>TGP access</title><link rel="icon" href="/assets/images/p2k-logo.jpg"></head><body style="background:#171513;color:#f3eee5;font:16px system-ui;padding:2rem"><h1>'.htmlspecialchars($title,ENT_QUOTES,'UTF-8').'</h1><p>'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</p>';
    if((int)$access['status']===401)echo '<a style="color:#f5bc4d" href="/server/team-points/public/oauth.php?action=login&amp;return=%2FTGP%2F">Sign in with Chess.com</a>';
    echo '</body></html>';exit;
}
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/home.html');
