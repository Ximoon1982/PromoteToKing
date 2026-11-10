<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

function recruitment_reply(int $code, array $data): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function recruitment_normalize_members(array $payload): array {
    $names = [];
    foreach (['weekly', 'monthly', 'all_time'] as $bucket) {
        if (!isset($payload[$bucket]) || !is_array($payload[$bucket])) {
            throw new RuntimeException('Chess.com returned an incomplete membership list.');
        }
        foreach ($payload[$bucket] as $item) {
            $username = is_array($item) ? ($item['username'] ?? null) : $item;
            if (!is_string($username) || !preg_match('/^[a-z0-9_-]{1,64}$/iD', $username)) {
                throw new RuntimeException('Chess.com returned a malformed membership entry.');
            }
            $names[strtolower($username)] = $username;
        }
    }
    if (!$names) throw new RuntimeException('Chess.com returned an empty membership list; refusing to replace the saved roster.');
    natcasesort($names);
    return array_values($names);
}
function recruitment_valid_snapshot(string $file): ?array {
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || ($data['schemaVersion'] ?? null)!==1 ||
        ($data['clubSlug'] ?? '')!=='the-golden-phoenix' ||
        empty($data['fetchedAt']) || !is_string($data['fetchedAt']) ||
        strtotime($data['fetchedAt'])===false || !is_array($data['members'] ?? null)) return null;
    try { $members = recruitment_normalize_members(['weekly'=>$data['members'],'monthly'=>[],'all_time'=>[]]); }
    catch (Throwable) { return null; }
    if (count($members)!==(int)($data['memberCount']??-1)) return null;
    return $data;
}
function recruitment_save_snapshot(string $dir, array $data): void {
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir))
        throw new RuntimeException('Unable to create the private membership cache directory.');
    $tmp = tempnam($dir, '.members-');
    if ($tmp === false) throw new RuntimeException('Unable to save membership snapshot.');
    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX)===false || !@rename($tmp, $dir.'/members-latest.json'))
            throw new RuntimeException('Unable to commit membership snapshot.');
        @chmod($dir.'/members-latest.json', 0660);
    } finally { if (is_file($tmp)) @unlink($tmp); }
}


function recruitment_bearer(array $requests, int $concurrency=6): array {
    $r=\P2K\TeamPoints\OAuthSession::batchForAuthorizedRequest($requests,$concurrency,0.0,'foreground');
    if(!is_array($r)||($r['mode']??'')!=='oauth-bearer')throw new RuntimeException('A valid OAuth bearer session is required.');
    return $r;
}
function recruitment_live_bearer_roster():array {
    $r=recruitment_bearer([['id'=>'0','url'=>'https://api.chess.com/pub/club/the-golden-phoenix/members']],1);
    $item=$r['results'][0]??[];$status=(int)($item['status']??0);
    if($status!==200||!is_array($item['json']??null))throw new RuntimeException('Chess.com member roster request failed (HTTP '.$status.').');
    $members=recruitment_normalize_members($item['json']);
    return ['schemaVersion'=>1,'clubSlug'=>'the-golden-phoenix','fetchedAt'=>gmdate(DATE_ATOM),'memberCount'=>count($members),'members'=>$members];
}
function recruitment_batch():never {
    $access=tgp_authorize();
    if(!$access['ok'])recruitment_reply((int)$access['status'],['ok'=>false,'error'=>['message'=>'TGP administrator authorization required.']]);
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST')recruitment_reply(405,['ok'=>false,'error'=>['message'=>'POST required.']]);
    \P2K\TeamPoints\Auth::enforceSameOrigin();
    $csrf=trim((string)($_SERVER['HTTP_X_P2K_OAUTH_CSRF']??''));
    if($csrf===''||!hash_equals((string)$access['csrf'],$csrf))recruitment_reply(403,['ok'=>false,'error'=>['message'=>'OAuth CSRF check failed. Reload the page.']]);
    $raw=file_get_contents('php://input');
    if(!is_string($raw)||strlen($raw)>16384)recruitment_reply(413,['ok'=>false,'error'=>['message'=>'Invalid request length.']]);
    $payload=json_decode($raw,true);$items=is_array($payload['requests']??null)?$payload['requests']:[];
    if(count($items)<1||count($items)>12)recruitment_reply(400,['ok'=>false,'error'=>['message'=>'Expected 1 to 12 requests.']]);
    $requests=[];
    foreach($items as $i=>$item){
        $url=is_array($item)?trim((string)($item['url']??'')):'';
        $p=parse_url($url);
        if(!is_array($p)||($p['scheme']??'')!=='https'||($p['host']??'')!=='api.chess.com'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['query'])||isset($p['fragment'])||
        !preg_match('~^/pub/(?:club/the-golden-phoenix|match/[0-9]{1,18}|player/[a-zA-Z0-9_-]{1,64}(?:/(?:stats|clubs))?)$~D',(string)($p['path']??'')))
            recruitment_reply(400,['ok'=>false,'error'=>['message'=>'Only approved Chess.com recruitment API endpoints are allowed.']]);
        $requests[]=['id'=>(string)$i,'url'=>$url];
    }
    try{
        $r=recruitment_bearer($requests,6);
        $rows=[];
        foreach($r['results']??[] as $item)$rows[]=[
            'id'=>(string)($item['id']??''),'status'=>(int)($item['status']??0),
            'json'=>is_array($item['json']??null)?$item['json']:null,
            'elapsed_ms'=>(int)($item['elapsed_ms']??0),
            'retry_after'=>(string)($item['headers']['retry-after']??'')];
        recruitment_reply(200,['ok'=>true,'mode'=>'oauth-bearer','results'=>$rows]);
    }catch(Throwable $e){
        error_log('TGP recruitment bearer: '.get_class($e).': '.$e->getMessage());
        recruitment_reply(503,['ok'=>false,'error'=>['message'=>'Authenticated Chess.com API transport unavailable.']]);
    }
}

$access = tgp_authorize();
if (!$access['ok']) recruitment_reply((int)$access['status'], ['ok'=>false,'error'=>['code'=>$access['code'], 'message'=>'The Golden Phoenix administrator authorization is required.']]);
if (($_GET['action']??'roster')==='batch') recruitment_batch();
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') recruitment_reply(405, ['ok'=>false,'error'=>['message'=>'Method not allowed.']]);
if (($_GET['action']??'roster')!=='roster') recruitment_reply(404, ['ok'=>false,'error'=>['message'=>'Unknown action.']]);
if (!function_exists('curl_init')) recruitment_reply(503, ['ok'=>false,'error'=>['message'=>'PHP cURL is required.']]);
$dir = dirname(__DIR__).'/data';
$file = $dir.'/members-latest.json';
if (!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) recruitment_reply(503,['ok'=>false,'error'=>['message'=>'Membership data directory is unavailable.']]);
$lock = @fopen($dir.'/members.lock','c+');
if (!$lock || !flock($lock,LOCK_EX)) recruitment_reply(503,['ok'=>false,'error'=>['message'=>'Membership cache is temporarily unavailable.']]);
try {
    // A successful request ALWAYS replaces the saved roster. A failed one never does.
    try {
        $data = recruitment_live_bearer_roster();
        recruitment_save_snapshot($dir,$data);
        recruitment_reply(200,['ok'=>true,'source'=>'live','snapshotAt'=>$data['fetchedAt'],'members'=>$data['members'],'count'=>$data['memberCount']]);
    } catch (Throwable $e) {
        error_log('TGP recruitment membership API: '.$e->getMessage());
        $cached = recruitment_valid_snapshot($file);
        $warning = 'The Golden Phoenix membership list could not be retrieved from Chess.com. To obtain an updated member list, configure the club membership list as Public on Chess.com while the recruitment analysis is in progress.';
        if ($cached !== null) recruitment_reply(200,[
            'ok'=>true,'source'=>'fallback','snapshotAt'=>$cached['fetchedAt'],
            'members'=>$cached['members'],'count'=>$cached['memberCount'],'warning'=>$warning.' Using the last saved roster from '.$cached['fetchedAt'].' (UTC). All potentially eligible players will have their current TGP membership rechecked.'
        ]);
        recruitment_reply(503,['ok'=>false,'error'=>['code'=>'ROSTER_UNAVAILABLE','message'=>$warning.' No previously saved roster exists, so recruitment cannot proceed.']]);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
