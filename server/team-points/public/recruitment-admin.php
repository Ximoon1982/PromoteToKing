<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\{ApiException,Auth,Http,PublicReadDatabase,PreviewIsolation,RecruitmentAnalysisStore};

const P2K_RECRUITMENT_MAX_CANDIDATES=100000;
const P2K_RECRUITMENT_MEMBERSHIP_BATCH_MAX=2000;
const P2K_RECRUITMENT_CHECKPOINT_MAX=500;

function p2k_recruitment_username(string $raw): string
{
    $value = trim($raw);
    $value = preg_replace('~^https?://(?:www\.)?chess\.com/member/~i', '', $value) ?? $value;
    $value = preg_replace('~^https?://api\.chess\.com/pub/player/~i', '', $value) ?? $value;
    $value = preg_replace('~/.*$~', '', $value) ?? $value;
    $value = trim($value);
    if ($value === '' || strlen($value) > 64 || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return '';
    return $value;
}

function p2k_recruitment_normalize_pool(mixed $input): array
{
    $raw = is_array($input) ? $input : preg_split('/[\r\n,;]+/', (string)$input);
    $out = [];
    $seen = [];
    foreach ((array)$raw as $item) {
        $username = p2k_recruitment_username((string)$item);
        $key = strtolower($username);
        if ($username === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        $out[] = $username;
        if (count($out) >= P2K_RECRUITMENT_MAX_CANDIDATES) break;
    }
    return $out;
}

function p2k_recruitment_number(array $body, string $key, ?float $default): ?float
{
    if (!array_key_exists($key, $body)) return $default;
    if ($body[$key] === '' || $body[$key] === null) return null;
    if (!is_numeric($body[$key])) throw new ApiException($key . ' must be numeric or empty.', 400, 'INVALID_CRITERIA');
    return (float)$body[$key];
}

function p2k_recruitment_criteria(array $body): array
{
    $criteria = [
        'minRating' => p2k_recruitment_number($body, 'minRating', 1300),
        'maxRating' => p2k_recruitment_number($body, 'maxRating', null),
        'maxTimeout' => p2k_recruitment_number($body, 'maxTimeout', 8),
        'maxRd' => p2k_recruitment_number($body, 'maxRd', 100),
        'minGames' => p2k_recruitment_number($body, 'minGames', 2),
        'maxGames' => p2k_recruitment_number($body, 'maxGames', 25),
        'minCompleted' => p2k_recruitment_number($body, 'minCompleted', 50),
        'maxOffline' => p2k_recruitment_number($body, 'maxOffline', 14),
        'maxSpm' => p2k_recruitment_number($body, 'maxSpm', null),
        'minAge' => p2k_recruitment_number($body, 'minAge', null),
        'excludeFormer' => !empty($body['excludeFormer']),
    ];
    foreach (['minRating','maxRating','maxTimeout','maxRd','minGames','maxGames','minCompleted','maxOffline','maxSpm','minAge'] as $key) {
        if ($criteria[$key] !== null && $criteria[$key] < 0) {
            throw new ApiException($key . ' cannot be negative.', 400, 'INVALID_CRITERIA');
        }
    }
    if ($criteria['minRating'] !== null && $criteria['maxRating'] !== null && $criteria['minRating'] > $criteria['maxRating']) {
        throw new ApiException('Minimum rating cannot exceed maximum rating.', 400, 'INVALID_CRITERIA');
    }
    if ($criteria['minGames'] !== null && $criteria['maxGames'] !== null && $criteria['minGames'] > $criteria['maxGames']) {
        throw new ApiException('Minimum current games cannot exceed maximum current games.', 400, 'INVALID_CRITERIA');
    }
    return $criteria;
}

function p2k_recruitment_metric(array $data, string $field): ?float
{
    if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') return null;
    return is_numeric($data[$field]) ? (float)$data[$field] : null;
}

/**
 * Resolve Recruitment membership directly from canonical Green identity/player data.
 * Returns lowercase input username => current|former|none.
 */
function p2k_recruitment_membership_states(PDO $pdo, array $usernames): array
{
    $input = [];
    foreach ($usernames as $raw) {
        $username = p2k_recruitment_username((string)$raw);
        if ($username === '') continue;
        $input[strtolower($username)] = $username;
    }
    $states = array_fill_keys(array_keys($input), 'none');
    if ($input === []) return $states;

    foreach (array_chunk(array_keys($input), 500) as $keys) {
        $marks = implode(',', array_fill(0, count($keys), '?'));

        $q = $pdo->prepare("SELECT username_key,current_member FROM p2k_g_players WHERE username_key IN ({$marks})");
        $q->execute($keys);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)($row['username_key'] ?? ''));
            if (isset($states[$key])) $states[$key] = !empty($row['current_member']) ? 'current' : 'former';
        }

        $q = $pdo->prepare("SELECT m.username_key,p.current_member
            FROM p2k_g_identity_map m
            JOIN p2k_g_players p ON p.username_key=m.canonical_username_key
            WHERE m.username_key IN ({$marks})");
        $q->execute($keys);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)($row['username_key'] ?? ''));
            if (isset($states[$key])) $states[$key] = !empty($row['current_member']) ? 'current' : 'former';
        }

        $q = $pdo->prepare("SELECT a.username_key,p.current_member
            FROM p2k_g_player_aliases a
            JOIN p2k_g_players p ON p.chess_player_id=a.chess_player_id
            WHERE a.username_key IN ({$marks})");
        $q->execute($keys);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $key = strtolower((string)($row['username_key'] ?? ''));
            if (isset($states[$key])) $states[$key] = !empty($row['current_member']) ? 'current' : 'former';
        }
    }
    return $states;
}

function p2k_recruitment_membership_state(PDO $pdo, string $username): string
{
    $states = p2k_recruitment_membership_states($pdo, [$username]);
    return $states[strtolower(p2k_recruitment_username($username))] ?? 'none';
}

function p2k_recruitment_evaluate(array $data, array $criteria): array
{
    $fail = [];
    $error = trim((string)($data['error'] ?? ''));
    $closed = !empty($data['closed']);
    $p2k = strtolower(trim((string)($data['p2k_state'] ?? 'none')));

    if ($error !== '') $fail[] = 'profile data unavailable: ' . $error;
    if ($closed) $fail[] = 'account closed / unavailable';
    if ($p2k === 'current') $fail[] = 'already current P2K member';
    if (!empty($criteria['excludeFormer']) && $p2k === 'former') $fail[] = 'former P2K member';

    $membershipRejected = $p2k === 'current' || (!empty($criteria['excludeFormer']) && $p2k === 'former');
    if (!$closed && $error === '' && !$membershipRejected) {
        $checks = [
            ['daily_rating', 'minRating', 'min', 'Daily rating below minimum', 'Daily rating unavailable'],
            ['daily_rating', 'maxRating', 'max', 'Daily rating above maximum', 'Daily rating unavailable'],
            ['timeout_percent', 'maxTimeout', 'max', 'timeout rate too high', 'timeout rate unavailable'],
            ['daily_rd', 'maxRd', 'max', 'Daily rating deviation too high', 'Daily rating deviation unavailable'],
            ['current_daily_games', 'minGames', 'min', 'too few current Daily games', 'current Daily games unavailable'],
            ['current_daily_games', 'maxGames', 'max', 'too many current Daily games', 'current Daily games unavailable'],
            ['completed_daily_games', 'minCompleted', 'min', 'too few completed Daily games', 'completed Daily games unavailable'],
            ['last_online_days', 'maxOffline', 'max', 'last online too old', 'last online unavailable'],
            ['avg_seconds_per_move', 'maxSpm', 'max', 'average seconds per move too high', 'average seconds per move unavailable'],
            ['account_age_days', 'minAge', 'min', 'account too new', 'account age unavailable'],
        ];
        foreach ($checks as [$field, $criterion, $mode, $message, $missing]) {
            $limit = $criteria[$criterion] ?? null;
            if ($limit === null) continue;
            $value = p2k_recruitment_metric($data, $field);
            if ($value === null) { $fail[] = $missing; continue; }
            if (($mode === 'min' && $value < (float)$limit) || ($mode === 'max' && $value > (float)$limit)) $fail[] = $message;
        }
    }

    $fail = array_values(array_unique($fail));
    return [
        'selected' => $fail === [],
        'decision' => $fail === [] ? 'selected' : 'rejected',
        'reason' => $fail === [] ? 'All criteria passed.' : implode('; ', $fail),
    ];
}


try {
    Auth::requireAdmin();
    $actor=PreviewIsolation::active()?PreviewIsolation::username():(string)($_SESSION['p2k_tp_admin_username']??'administrator');
    // Do not hold the PHP session lock across SQLite or membership queries.
    if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
    $action=strtolower(trim((string)($_GET['action']??'state')));
    $read=in_array($action,['state','detail','results','csv'],true);
    Http::method($read?'GET':'POST');
    if (!$read && PreviewIsolation::active()) throw new ApiException('Candidate preview is read-only.',403,'PREVIEW_READ_ONLY');
    $body=$read?[]:Http::body();
    $id=(string)($read?($_GET['id']??''):($body['id']??''));
    $token=(string)($body['lease']??'');
    if ($action==='membership-batch') {
        $raw=is_array($body['usernames']??null)?$body['usernames']:[];
        if (count($raw)>P2K_RECRUITMENT_MEMBERSHIP_BATCH_MAX) throw new ApiException('Membership batch too large.',400,'MEMBERSHIP_BATCH_TOO_LARGE');
        $states=p2k_recruitment_membership_states(PublicReadDatabase::core(),$raw);
        Http::json(['ok'=>true,'states'=>$states,'count'=>count($states),'source'=>'green_native_core']);
    }
    $config=p2k_tp_config();
    $runtime=rtrim(trim((string)($config['storage']['runtime_dir']??'')),'/\\');
    if ($runtime==='') $runtime=dirname(__DIR__,3).'/data/runtime-v280';
    $store=new RecruitmentAnalysisStore($runtime.'/recruitment-admin');
    $store->migrate('p2k_recruitment_normalize_pool','p2k_recruitment_criteria');
    if ($action==='state') Http::json(['ok'=>true,'analyses'=>$store->page((string)($_GET['search']??''),(int)($_GET['page']??1))]);
    if ($action==='detail') Http::json(['ok'=>true,'run'=>$store->detail($id,($_GET['candidates']??'')==='1')]);
    if ($action==='results') Http::json(['ok'=>true,'results'=>$store->results($id,(string)($_GET['filter']??'all'),(string)($_GET['search']??''),(int)($_GET['page']??1),(int)($_GET['size']??25))]);
    if (in_array($action,['create','update-draft'],true)) {
        $criteria=p2k_recruitment_criteria((array)($body['criteria']??[]));
        $candidates=p2k_recruitment_normalize_pool($body['candidates']??'');
        $name=(string)($body['name']??'');
        Http::json(['ok'=>true,'run'=>$action==='create'?$store->create($name,$actor,$criteria,$candidates):$store->updateDraft($id,$name,$criteria,$candidates)]);
    }
    if ($action==='duplicate') Http::json(['ok'=>true,'run'=>$store->duplicate($id,$actor,(string)($body['name']??''))]);
    if ($action==='resume') Http::json(['ok'=>true]+$store->resume($id));
    if ($action==='heartbeat') Http::json(['ok'=>true,'run'=>$store->heartbeat($id,$token)]);
    if ($action==='pause') Http::json(['ok'=>true,'run'=>$store->pause($id,$token)]);
    if ($action==='retry-errors') Http::json(['ok'=>true,'run'=>$store->retryErrors($id)]);
    if ($action==='delete') {
        if (($body['confirm']??false)!==true) throw new ApiException('Explicit deletion confirmation required.',400,'CONFIRM_REQUIRED');
        $store->delete($id);Http::json(['ok'=>true]);
    }
    if ($action==='checkpoint') {
        $incoming=is_array($body['results']??null)?$body['results']:[];
        if ($incoming===[]||count($incoming)>P2K_RECRUITMENT_CHECKPOINT_MAX) throw new ApiException('Invalid checkpoint batch size.',400,'INVALID_CHECKPOINT');
        // Reject stale IDs before querying Green, then validate the lease again transactionally.
        $store->heartbeat($id,$token);
        $names=[];
        foreach ($incoming as $row) if(is_array($row)) $names[]=p2k_recruitment_username((string)($row['username']??''));
        $membership=p2k_recruitment_membership_states(PublicReadDatabase::core(),$names);
        $accepted=[];
        foreach ($incoming as $row) {
            if(!is_array($row)) continue;
            $username=p2k_recruitment_username((string)($row['username']??''));
            if($username==='') continue;
            $data=is_array($row['data']??null)?$row['data']:[];
            $safe=[];
            foreach (['daily_rating','timeout_percent','current_daily_games','daily_rd','completed_daily_games','avg_seconds_per_move','last_online_days','account_age_days'] as $field) $safe[$field]=p2k_recruitment_metric($data,$field);
            $safe+=['p2k_state'=>$membership[strtolower($username)]??'none','closed'=>!empty($data['closed']),'error'=>trim(substr((string)($data['error']??''),0,500))];
            $accepted[]=['username'=>$username,'data'=>$safe];
        }
        Http::json(['ok'=>true,'run'=>$store->checkpoint($id,$token,$accepted,'p2k_recruitment_evaluate')]);
    }
    if ($action==='csv') {
        $run=$store->detail($id);$selected=($_GET['scope']??'selected')!=='all';
        $filename='p2k-recruitment-'.substr($run['id'],0,12).'-'.($selected?'selected':'all').'-'.gmdate('Ymd\THis\Z').'.csv';
        header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Cache-Control: private, no-store');
        $out=fopen('php://output','wb');
        $fields=['daily_rating','timeout_percent','current_daily_games','daily_rd','completed_daily_games','avg_seconds_per_move','last_online_days','account_age_days','p2k_state','closed','error'];
        fputcsv($out,array_merge(['analysis_id','username','decision','checked_at_utc'],$fields,['decision_reason']),',','"','');
        foreach ($store->export($id,$selected) as $row) {
            $data=$row['data'];$values=[$id,$row['username'],$row['decision'],$row['checkedAt']];
            foreach($fields as $field) $values[]=$data[$field]??'';
            $values[]=$row['reason'];fputcsv($out,$values,',','"','');
        }
        fclose($out);exit;
    }
    throw new ApiException('Unknown recruitment action. Reload the current application.',404,'UNKNOWN_ACTION');
} catch(ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch(Throwable $e) {
    error_log('P2K recruitment analyses: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'RECRUITMENT_STORAGE_ERROR','message'=>'Recruitment operation failed safely. Check server storage and logs.']],503);
}
