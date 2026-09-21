<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\OAuthSession;

function pd_auth(bool $requireAuthenticated = true, bool $write = false): array
{
    $info = OAuthSession::sessionInfo();
    $profile = is_array($info['profile'] ?? null) ? $info['profile'] : [];
    $username = P2KPlayerDiscoveryStore::normalizeUsername((string)($profile['username'] ?? ''));
    $playerId = (int)($profile['playerId'] ?? 0);
    $csrf = (string)($info['csrf'] ?? '');
    if ($write) {
        $provided = trim((string)($_SERVER['HTTP_X_P2K_OAUTH_CSRF'] ?? ''));
        if ($csrf === '' || $provided === '' || !hash_equals($csrf,$provided)) {
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            throw new ApiException('The OAuth session could not validate this request.',403,'CSRF_VALIDATION_FAILED');
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if ($requireAuthenticated && ($username === '' || empty($info['authenticated']))) {
        throw new ApiException('Log in with Chess.com through P2K to use this tool.',401,'OAUTH_REQUIRED');
    }
    return [
        'authenticated'=>!empty($info['authenticated']) && $username !== '',
        'username'=>$username,
        'player_id'=>$playerId,
        'csrf'=>$csrf,
        'profile'=>[
            'username'=>$username,
            'playerId'=>$playerId ?: null,
            'avatar'=>$profile['avatar'] ?? null,
            'title'=>$profile['title'] ?? null,
            'name'=>$profile['name'] ?? null,
        ],
        'enabled'=>$info['enabled'] ?? true,
    ];
}

function pd_body(): array { return Http::body(); }
function pd_job_id(array $source): string { return trim((string)($source['job_id'] ?? '')); }

try {
    $action = strtolower(trim((string)($_GET['action'] ?? 'session')));
    if ($action === 'session') {
        Http::method('GET');
        $auth = pd_auth(false,false);
        Http::json(['ok'=>true,'authenticated'=>$auth['authenticated'],'enabled'=>$auth['enabled'],'profile'=>$auth['authenticated']?$auth['profile']:null,'csrf'=>$auth['csrf']]);
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $write = $method !== 'GET';
    $auth = pd_auth(true,$write);
    $owner = ['username'=>$auth['username'],'player_id'=>$auth['player_id']];
    $store = new P2KPlayerDiscoveryStore();

    if ($action === 'jobs') {
        Http::method('GET');
        Http::json(['ok'=>true,'jobs'=>$store->listJobs($owner)]);
    }
    if ($action === 'job') {
        Http::method('GET');
        Http::json(['ok'=>true,'job'=>$store->job(pd_job_id($_GET),$owner)]);
    }
    if ($action === 'create') {
        Http::method('POST'); $body=pd_body();
        $job=$store->createJob(
            $owner,
            (int)($body['months']??2),
            (string)($body['daily_mode']??'team'),
            !empty($body['include_chess960']),
            (int)($body['max_concurrency']??24)
        );
        Http::json(['ok'=>true,'job'=>$job],201);
    }
    if ($action === 'add-seeds') {
        Http::method('POST'); $body=pd_body();
        $names=is_array($body['usernames']??null)?$body['usernames']:[];
        if (count($names)>2000) throw new ApiException('Upload seed players in batches of at most 2,000.',400,'SEED_BATCH_TOO_LARGE');
        Http::json(['ok'=>true,'result'=>$store->addSeeds(pd_job_id($body),$owner,$names)]);
    }
    if ($action === 'finalize-seeds') {
        Http::method('POST'); $body=pd_body();
        Http::json(['ok'=>true,'job'=>$store->finalizeSeeds(pd_job_id($body),$owner)]);
    }
    if ($action === 'pause') {
        Http::method('POST'); $body=pd_body(); Http::json(['ok'=>true,'job'=>$store->pause(pd_job_id($body),$owner)]);
    }
    if ($action === 'resume') {
        Http::method('POST'); $body=pd_body(); Http::json(['ok'=>true,'job'=>$store->resume(pd_job_id($body),$owner,(string)($body['client_id']??''))]);
    }
    if ($action === 'claim') {
        Http::method('POST'); $body=pd_body();
        Http::json(['ok'=>true]+$store->claim(pd_job_id($body),$owner,(int)($body['limit']??50),(string)($body['client_id']??'')));
    }
    if ($action === 'complete-discovery') {
        Http::method('POST'); $body=pd_body();
        Http::json(['ok'=>true,'job'=>$store->completeDiscovery(
            pd_job_id($body),$owner,(string)($body['username']??''),(string)($body['lease_token']??''),
            is_array($body['opponents']??null)?$body['opponents']:[],
            is_array($body['metrics']??null)?$body['metrics']:[]
        )]);
    }
    if ($action === 'complete-enrichment') {
        Http::method('POST'); $body=pd_body();
        Http::json(['ok'=>true,'job'=>$store->completeEnrichment(
            pd_job_id($body),$owner,(string)($body['username']??''),(string)($body['lease_token']??''),
            is_array($body['payload']??null)?$body['payload']:[],
            is_array($body['metrics']??null)?$body['metrics']:[]
        )]);
    }
    if ($action === 'fail') {
        Http::method('POST'); $body=pd_body();
        Http::json(['ok'=>true,'job'=>$store->failWork(
            pd_job_id($body),$owner,(string)($body['phase']??''),(string)($body['username']??''),(string)($body['lease_token']??''),(string)($body['message']??'Request failed')
        )]);
    }
    if ($action === 'results') {
        Http::method('GET');
        Http::json(['ok'=>true]+$store->results(pd_job_id($_GET),$owner,$_GET));
    }
    if ($action === 'delete') {
        Http::method('POST'); $body=pd_body(); $store->deleteJob(pd_job_id($body),$owner); Http::json(['ok'=>true]);
    }
    if ($action === 'export') {
        Http::method('GET');
        $jobId=pd_job_id($_GET);
        $rows=$store->csvRows($jobId,$owner);
        http_response_code(200);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="p2k-player-discovery-'.preg_replace('/[^a-f0-9-]/','',$jobId).'.csv"');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $out=fopen('php://output','wb');
        if ($out===false) throw new RuntimeException('Unable to open CSV output.');
        $headerWritten=false;
        foreach ($rows as $row) {
            if (!$headerWritten) { fputcsv($out,array_keys($row)); $headerWritten=true; }
            fputcsv($out,array_values($row));
        }
        if (!$headerWritten) fputcsv($out,['username']);
        fclose($out);
        exit;
    }
    throw new ApiException('Unknown player-discovery action.',404,'ACTION_NOT_FOUND');
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K player discovery: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'PLAYER_DISCOVERY_SERVER_ERROR','message'=>'The player-discovery service encountered an unexpected error.']],500);
}
