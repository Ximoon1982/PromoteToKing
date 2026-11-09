<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/auth.php';
require_once dirname(__DIR__).'/MatchPolicy.php';
require_once dirname(__DIR__, 3).'/api/_common.php';
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\PreviewIsolation;
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
$access=tgp_authorize();
if (!$access['ok']) api_error((int)$access['status'],(string)$access['code'],'The Golden Phoenix administrator authorization failed.');
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
if (!in_array($method,['GET','POST'],true)) { header('Allow: GET, POST'); api_error(405,'METHOD_NOT_ALLOWED','Use GET or POST.'); }
if($method==='POST') {
    Auth::enforceSameOrigin();
    require_write_header('tgp-track-match');
    $body=body_json(8192);
    $csrf=trim((string)($_SERVER['HTTP_X_P2K_OAUTH_CSRF']??''));
    if ($csrf==='' || !hash_equals((string)$access['csrf'],$csrf)) api_error(403,'OAUTH_CSRF','OAuth request validation failed.');
    if (PreviewIsolation::active()) api_error(403,'PREVIEW_READ_ONLY','Tracking is disabled in preview.');
    $id=match_id($body['match']??'');
} else $id=match_id($_GET['match']??'');
if (!preg_match('/^\d{1,12}$/D',$id)) api_error(400,'INVALID_MATCH_ID','Invalid match ID.');

try {
    $match=chess_json(chess_match_url($id));
    if(!TgpMatchPolicy::containsClub($match))api_error(422,'NOT_TGP_MATCH','The Golden Phoenix does not participate in this match.');
    $registry=read_follow_registry();
    $entry=$registry['matches'][$id]??null;
    $tracked=is_array($entry)&&!empty($entry['followed']);
    if($method==='GET') json_response(200,['ok'=>true,'matchId'=>$id,'tracked'=>$tracked,'hasHistory'=>latest_snapshot_record($id)!==null]);
    if($tracked)json_response(200,['ok'=>true,'matchId'=>$id,'tracked'=>true,'alreadyTracked'=>true]);
    $stored=save_snapshot($match,true,'tgp-match-analyzer');
    json_response(201,['ok'=>true,'matchId'=>$id,'tracked'=>true,'captured'=>true,'trackedAt'=>$stored['trackedAt']??null]);
} catch(InvalidArgumentException $error) {
    api_error(400,'INVALID_MATCH',$error->getMessage());
} catch(Throwable $error) {
    error_log('TGP match tracking: '.$error->getMessage());
    api_error(503,'TRACKING_UNAVAILABLE','The match-monitoring service is temporarily unavailable.');
}
