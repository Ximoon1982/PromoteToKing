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
            $joined = is_array($item) ? ($item['joined'] ?? null) : null;
            $joined = is_numeric($joined) && (int)$joined > 0 ? (int)$joined : null;
            $lookup = strtolower($username);
            // Preserve join date across all Chess.com membership activity buckets.
            $previous = $names[$lookup]['joined'] ?? null;
            $names[$lookup] = ['username'=>$username, 'joined'=>$joined ?? $previous];
        }
    }
    if (!$names) throw new RuntimeException('Chess.com returned an empty membership list; refusing to replace the saved roster.');
    ksort($names, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($names);
}
function recruitment_valid_snapshot(string $file): ?array {
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    if (!is_array($data) || !in_array(($data['schemaVersion'] ?? null), [1,2], true) ||
        ($data['clubSlug'] ?? '')!=='team-france' ||
        empty($data['fetchedAt']) || !is_string($data['fetchedAt']) ||
        strtotime($data['fetchedAt'])===false || !is_array($data['members'] ?? null)) return null;
    try { $members = recruitment_normalize_members(['weekly'=>$data['members'],'monthly'=>[],'all_time'=>[]]); }
    catch (Throwable) { return null; }
    if (count($members)!==(int)($data['memberCount']??-1)) return null;
    $data['members'] = $members; // Normalize legacy string-only snapshots safely.
    return $data;
}
function recruitment_response_members(array $snapshot): array {
    // Wire protocol remains backward compatible with already-open recruitment tabs.
    // Persisted snapshots may contain records with joining dates, but the "members"
    // response field ALWAYS contains usernames as strings. Joining dates travel in
    // a separate map for modern clients.
    $members = [];
    $joined = [];
    foreach ($snapshot['members'] ?? [] as $member) {
        $username = is_array($member) ? ($member['username'] ?? null) : $member;
        if (!is_string($username) || !preg_match('/^[a-z0-9_-]{1,64}$/iD', $username)) {
            throw new RuntimeException('Invalid cached recruitment roster username.');
        }
        $members[] = $username;
        $epoch = is_array($member) ? ($member['joined'] ?? null) : null;
        if (is_numeric($epoch) && (int)$epoch > 0) $joined[strtolower($username)] = (int)$epoch;
    }
    return ['members' => $members, 'joinedByUsername' => $joined];
}

function recruitment_save_snapshot(string $dir, array $data): void {
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir))
        throw new RuntimeException('Impossible de créer le dossier privé des membres.');
    $tmp = tempnam($dir, '.members-');
    if ($tmp === false) throw new RuntimeException('Impossible d’enregistrer la liste des membres.');
    try {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json, LOCK_EX)===false || !@rename($tmp, $dir.'/members-latest.json'))
            throw new RuntimeException('Impossible de valider l’enregistrement des membres.');
        @chmod($dir.'/members-latest.json', 0660);
    } finally { if (is_file($tmp)) @unlink($tmp); }
}


function recruitment_bearer(array $requests, int $concurrency=6): array {
    $r=\P2K\TeamPoints\OAuthSession::batchForAuthorizedRequest($requests,$concurrency,0.0,'foreground');
    if(!is_array($r)||($r['mode']??'')!=='oauth-bearer')throw new RuntimeException('Une session OAuth valide est nécessaire.');
    return $r;
}
function recruitment_live_bearer_roster():array {
    $r=recruitment_bearer([['id'=>'0','url'=>'https://api.chess.com/pub/club/team-france/members']],1);
    $item=$r['results'][0]??[];$status=(int)($item['status']??0);
    if($status!==200||!is_array($item['json']??null))throw new RuntimeException('Échec de chargement de la liste des membres Chess.com (HTTP '.$status.').');
    $members=recruitment_normalize_members($item['json']);
    return ['schemaVersion'=>2,'clubSlug'=>'team-france','fetchedAt'=>gmdate(DATE_ATOM),'memberCount'=>count($members),'members'=>$members];
}
function recruitment_batch():never {
    $access=team_france_authorize();
    if(!$access['ok'])recruitment_reply((int)$access['status'],['ok'=>false,'error'=>['message'=>'Team France  : accès administrateur requis.']]);
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??''))!=='POST')recruitment_reply(405,['ok'=>false,'error'=>['message'=>'POST required.']]);
    \P2K\TeamPoints\Auth::enforceSameOrigin();
    $csrf=trim((string)($_SERVER['HTTP_X_P2K_OAUTH_CSRF']??''));
    if($csrf===''||!hash_equals((string)$access['csrf'],$csrf))recruitment_reply(403,['ok'=>false,'error'=>['message'=>'Échec de vérification CSRF. Rechargez la page.']]);
    $raw=file_get_contents('php://input');
    if(!is_string($raw)||strlen($raw)>16384)recruitment_reply(413,['ok'=>false,'error'=>['message'=>'Invalid request length.']]);
    $payload=json_decode($raw,true);$items=is_array($payload['requests']??null)?$payload['requests']:[];
    if(count($items)<1||count($items)>12)recruitment_reply(400,['ok'=>false,'error'=>['message'=>'Expected 1 to 12 requests.']]);
    $requests=[];
    foreach($items as $i=>$item){
        $url=is_array($item)?trim((string)($item['url']??'')):'';
        $p=parse_url($url);
        if(!is_array($p)||($p['scheme']??'')!=='https'||($p['host']??'')!=='api.chess.com'||isset($p['user'])||isset($p['pass'])||isset($p['port'])||isset($p['query'])||isset($p['fragment'])||
        !preg_match('~^/pub/(?:club/team-france|match/[0-9]{1,18}|player/[a-zA-Z0-9_-]{1,64}(?:/(?:stats|clubs))?)$~D',(string)($p['path']??'')))
            recruitment_reply(400,['ok'=>false,'error'=>['message'=>'Seuls les points d’accès Chess.com autorisés sont acceptés.']]);
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
        error_log('Team France recruitment bearer: '.get_class($e).': '.$e->getMessage());
        recruitment_reply(503,['ok'=>false,'error'=>['message'=>'Transport OAuth Chess.com indisponible.']]);
    }
}

$access = team_france_authorize();
if (!$access['ok']) recruitment_reply((int)$access['status'], ['ok'=>false,'error'=>['code'=>$access['code'], 'message'=>'Team France administrator authorization is required.']]);
if (($_GET['action']??'roster')==='batch') recruitment_batch();
if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') recruitment_reply(405, ['ok'=>false,'error'=>['message'=>'Method not allowed.']]);
if (($_GET['action']??'roster')!=='roster') recruitment_reply(404, ['ok'=>false,'error'=>['message'=>'Unknown action.']]);
if (!function_exists('curl_init')) recruitment_reply(503, ['ok'=>false,'error'=>['message'=>'PHP cURL is required.']]);
$dir = dirname(__DIR__).'/data';
$file = $dir.'/members-latest.json';
if (!is_dir($dir) && !@mkdir($dir,0770,true) && !is_dir($dir)) recruitment_reply(503,['ok'=>false,'error'=>['message'=>'Répertoire des membres indisponible.']]);
$lock = @fopen($dir.'/members.lock','c+');
if (!$lock || !flock($lock,LOCK_EX)) recruitment_reply(503,['ok'=>false,'error'=>['message'=>'Cache des membres temporairement indisponible.']]);
try {
    // A successful request ALWAYS replaces the saved roster. A failed one never does.
    try {
        $data = recruitment_live_bearer_roster();
        recruitment_save_snapshot($dir,$data);
        recruitment_reply(200,['ok'=>true,'source'=>'live','snapshotAt'=>$data['fetchedAt'],...recruitment_response_members($data),'count'=>$data['memberCount']]);
    } catch (Throwable $e) {
        error_log('Team France recruitment membership API: '.$e->getMessage());
        $cached = recruitment_valid_snapshot($file);
        $warning = 'liste des membres Team France could not be retrieved from Chess.com. To obtain an updated member list, configure the club membership list as Public on Chess.com while the recruitment analysis is in progress.';
        if ($cached !== null) recruitment_reply(200,[
            'ok'=>true,'source'=>'fallback','snapshotAt'=>$cached['fetchedAt'],
            ...recruitment_response_members($cached),'count'=>$cached['memberCount'],'warning'=>$warning.' Utilisation de la dernière liste enregistrée le '.$cached['fetchedAt'].' (UTC). L’appartenance actuelle à Team France sera revérifiée pour chaque candidat.'
        ]);
        recruitment_reply(503,['ok'=>false,'error'=>['code'=>'ROSTER_UNAVAILABLE','message'=>$warning.' Aucune liste sauvegardée : le recrutement ne peut pas démarrer.']]);
    }
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
