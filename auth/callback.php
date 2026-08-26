<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/team-points/src/bootstrap.php';
use P2K\TeamPoints\OAuthSession;
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){http_response_code(405);header('Allow: GET');exit('Method not allowed');}
OAuthSession::handleCallback();
