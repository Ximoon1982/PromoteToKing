<?php
declare(strict_types=1);
require __DIR__.'/_common.php';

try {
    $endpoint = defined('CLUB_TOOLS_ENDPOINT') ? CLUB_TOOLS_ENDPOINT : (defined('P2K_ENDPOINT') ? P2K_ENDPOINT : '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    switch ($endpoint) {
        case 'traffic':
            if ($method === 'POST') {
                require_write_header('traffic-analytics');
                $body=body_json(8192);
                json_response(202, \P2K\Shared\TrafficAnalytics::record(root_dir(),$body,$_SERVER));
            }
            break;
        case 'challenge':
            $path = root_dir().'/data/challenge-club-list.json';
            if ($method === 'GET') {
                $record = read_json_file($path, ['schemaVersion' => 1, 'revision' => 0, 'updatedAt' => null, 'clubs' => []]);
                json_response(200, array_merge(['ok' => true, 'exists' => (int)($record['revision'] ?? 0) > 0], $record));
            }
            if ($method === 'PUT') {
                require_admin_write('challenge-club-list');
                $body = body_json();
                $clubs = []; $seen = [];
                foreach (($body['clubs'] ?? []) as $raw) {
                    $slug = strtolower(trim((string)$raw));
                    if ($slug === '' || strlen($slug) > 128 || !preg_match('/^[a-z0-9-]+$/', $slug)) throw new InvalidArgumentException('invalid club slug');
                    if (!isset($seen[$slug])) { $seen[$slug] = true; $clubs[] = $slug; }
                }
                if (!$clubs) throw new InvalidArgumentException('clubs must contain at least one club');
                $current = read_json_file($path, ['revision' => 0]);
                $expected = (int)($body['revision'] ?? 0);
                if ($expected !== (int)($current['revision'] ?? 0)) api_error(409, 'REVISION_CONFLICT', 'The server default changed after it was loaded.', ['current' => $current]);
                $record = ['schemaVersion' => 1, 'revision' => $expected + 1, 'updatedAt' => utc_stamp(), 'clubs' => $clubs];
                atomic_json($path, $record, true);
                json_response(200, array_merge(['ok' => true, 'exists' => true], $record));
            }
            break;
        case 'match-log':
            if ($method === 'POST') {
                require_write_header('match-assistant-log');
                $body = body_json(16384);
                $username = strtolower(trim((string)($body['username'] ?? '')));
                $count = filter_var($body['matchesFound'] ?? null, FILTER_VALIDATE_INT);
                if ($username === '' || strlen($username) > 128 || $count === false || $count < 0) throw new InvalidArgumentException('invalid log entry');
                $entry = ['schemaVersion' => 1, 'event' => 'match-assistant-analysis', 'timestamp' => utc_stamp(), 'username' => $username, 'matchesFound' => $count];
                append_jsonl(root_dir().'/logs/match-assistant', $entry);
                json_response(201, ['ok' => true, 'entry' => $entry]);
            }
            break;
        case 'match-logs': if ($method === 'GET') json_response(200, match_logs_response()); break;
        case 'task-log':
            if ($method === 'POST') {
                require_admin_write('scheduled-task-log');
                $body = body_json(16384);
                if (($body['source'] ?? '') !== 'manual' || !in_array($body['status'] ?? '', ['success', 'partial', 'failed'], true)) throw new InvalidArgumentException('invalid scheduled task entry');
                $entry = task_entry('manual', $body['status'], $body);
                append_jsonl(root_dir().'/logs/scheduled-tasks', $entry);
                json_response(201, ['ok' => true, 'entry' => $entry]);
            }
            break;
        case 'task-logs': if ($method === 'GET') json_response(200, task_logs_response()); break;
        case 'refs': if ($method === 'GET') json_response(200, array_merge(['ok' => true], tracking_references())); break;
        case 'record':
            if ($method === 'POST') {
                require_admin_write('record-league-match');
                $body = body_json(16384);
                $id = match_id($body['match'] ?? '');
                json_response(201, ['ok' => true, 'stored' => save_snapshot(chess_json(chess_match_url($id)), true, 'manual-recording')]);
            }
            break;
        case 'track':
            if ($method === 'GET') {
                $startedAt = utc_stamp();
                $started = microtime(true);
                $taskId = 'track-upcoming-league-matches';
                $runId = task_run_id($taskId);
                $config = read_json_file(root_dir().'/data/server-config.json', []);
                $configured = trim((string)($config['cronToken'] ?? ''));
                $supplied = trim((string)($_SERVER['HTTP_X_P2K_CRON_TOKEN'] ?? ($_GET['token'] ?? '')));
                if ($configured === '' || $supplied === '' || !hash_equals($configured, $supplied)) {
                    $code = $configured === '' ? 'CRON_TOKEN_NOT_CONFIGURED' : 'INVALID_CRON_TOKEN';
                    $message = $configured === ''
                        ? 'Configure data/server-config.json before enabling the tracking cron.'
                        : 'The cron token is missing or invalid.';
                    append_jsonl(root_dir().'/logs/scheduled-tasks', task_entry('cron', 'failed', [
                        'taskType' => 'match-update', 'taskId' => $taskId, 'runId' => $runId,
                        'startedAt' => $startedAt, 'endedAt' => utc_stamp(), 'processedReferences' => 0,
                        'failedMatches' => 1, 'durationMs' => (int)round((microtime(true) - $started) * 1000),
                        'message' => $message, 'details' => ['authentication' => $code]
                    ]));
                    api_error($configured === '' ? 503 : 403, $code, $message);
                }
                $registry = new \P2K\Shared\TaskRegistry(\P2K\TeamPoints\Database::connection());
                if ($registry->isPaused('match-tracking')) {
                    $registry->log('match-tracking', 'warning', 'cron', 'Match monitoring CRON skipped because the task is paused.', [], $runId);
                    json_response(200, ['ok'=>true,'status'=>'paused','message'=>'Match monitoring is paused. Resume it from the unified task control page.']);
                }
                if (!$registry->beginRun('match-tracking', 'cron', $runId)) {
                    $reason = $registry->lastBeginReason();
                    json_response(200, [
                        'ok'=>true,
                        'status'=>$reason === 'paused' ? 'paused' : 'scheduler_busy',
                        'message'=>$reason === 'paused' ? 'Match monitoring is paused.' : 'Another match monitoring execution is already active.',
                    ]);
                }
                try {
                    @set_time_limit(50);
                    $result = track_all('cron', 40);
                    $registryStatus = in_array($result['status'], ['success','partial','failed'], true) ? $result['status'] : 'partial';
                    $registry->finishRun('match-tracking', $runId, $registryStatus, [
                        'processed'=>(int)($result['processedReferences']??0),
                        'updated'=>(int)($result['storedMatches']??0),
                        'failed'=>(int)($result['failedMatches']??0),
                    ], 'Match monitoring completed.', [
                        'expectedIntervalSeconds'=>3600,
                        'sharedGateway'=>true,
                        'legacyTaskId'=>$taskId,
                    ]);
                } catch (Throwable $error) {
                    $registry->finishRun('match-tracking', $runId, 'failed', ['failed'=>1], $error->getMessage(), ['exception'=>get_class($error)]);
                    $entry = task_entry('cron', 'failed', [
                        'taskType' => 'match-update', 'taskId' => $taskId, 'runId' => $runId,
                        'startedAt' => $startedAt, 'endedAt' => utc_stamp(), 'processedReferences' => 0,
                        'failedMatches' => 1, 'durationMs' => (int)round((microtime(true) - $started) * 1000),
                        'message' => $error->getMessage(), 'details' => ['exception' => get_class($error)]
                    ]);
                    append_jsonl(root_dir().'/logs/scheduled-tasks', $entry);
                    throw $error;
                }
                json_response($result['status'] === 'success' ? 200 : ($result['status'] === 'partial' ? 206 : 502), $result + ['shared_gateway'=>true]);
            }
            if ($method === 'POST') {
                require_admin_write('track-upcoming-league-matches');
                $registry = new \P2K\Shared\TaskRegistry(\P2K\TeamPoints\Database::connection());
                $runId = task_run_id('track-upcoming-league-matches-manual');
                if ($registry->isPaused('match-tracking')) {
                    json_response(409, ['ok'=>false,'status'=>'paused','error'=>['code'=>'TASK_PAUSED','message'=>'Match monitoring is paused. Resume it from the unified task control page.']]);
                }
                if (!$registry->beginRun('match-tracking', 'manual', $runId)) {
                    $paused = $registry->lastBeginReason() === 'paused';
                    json_response(409, [
                        'ok'=>false,
                        'status'=>$paused ? 'paused' : 'scheduler_busy',
                        'error'=>[
                            'code'=>$paused ? 'TASK_PAUSED' : 'TASK_BUSY',
                            'message'=>$paused ? 'Match monitoring is paused.' : 'Another match monitoring execution is already active.',
                        ],
                    ]);
                }
                try {
                    $result = track_all('manual');
                    $registry->finishRun('match-tracking', $runId, $result['status'], [
                        'processed'=>(int)($result['processedReferences']??0),
                        'updated'=>(int)($result['storedMatches']??0),
                        'failed'=>(int)($result['failedMatches']??0),
                    ], 'Manual league match tracking completed.', ['sharedGateway'=>true]);
                } catch (Throwable $error) {
                    $registry->finishRun('match-tracking', $runId, 'failed', ['failed'=>1], $error->getMessage(), ['exception'=>get_class($error)]);
                    throw $error;
                }
                json_response($result['status'] === 'success' ? 200 : ($result['status'] === 'partial' ? 206 : 502), $result + ['shared_gateway'=>true]);
            }
            break;
        case 'history':
            if ($method === 'GET') {
                $id = match_id($_GET['match'] ?? '');
                $migration = migrate_legacy_tracking();
                $files = glob(history_root().'/'.$id.'/*.json') ?: [];
                sort($files);
                $snapshots = []; $invalid = 0;
                foreach ($files as $file) {
                    $record = read_json_file($file, []);
                    if (!isset($record['trackedAt'], $record['match']) || !is_array($record['match'])) { $invalid++; continue; }
                    $snapshots[] = ['trackedAt' => (string)$record['trackedAt'], 'match' => $record['match']];
                }
                $truncated = count($snapshots) > MAX_SNAPSHOTS;
                if ($truncated) $snapshots = array_slice($snapshots, -MAX_SNAPSHOTS);
                json_response(200, ['ok' => true, 'matchId' => $id, 'snapshots' => $snapshots, 'fileCount' => count($files), 'invalidFiles' => $invalid, 'truncated' => $truncated, 'migration' => $migration]);
            }
            break;
        case 'tracked':
            if ($method === 'GET') {
                $migration = migrate_legacy_tracking();
                $matches = tracked_records();
                json_response(200, [
                    'ok' => true,
                    'migration' => $migration,
                    'matches' => $matches,
                    'summary' => [
                        'matches' => count($matches),
                        'files' => array_sum(array_column($matches, 'fileCount')),
                        'followed' => count(array_filter($matches, fn($match) => $match['followed'] ?? false)),
                        'registration' => count(array_filter($matches, fn($match) => ($match['status'] ?? '') === 'registration')),
                        'ongoing' => count(array_filter($matches, fn($match) => ($match['status'] ?? '') === 'ongoing')),
                        'finished' => count(array_filter($matches, fn($match) => ($match['status'] ?? '') === 'finished'))
                    ]
                ]);
            }
            if ($method === 'POST') {
                require_admin_write('tracked-match-data');
                $body = body_json(16384);
                $action = (string)($body['action'] ?? 'follow');
                if ($action !== 'follow') throw new InvalidArgumentException('Unsupported tracked match action.');
                $result = follow_and_capture($body['match'] ?? '');
                json_response(201, array_merge(['ok' => true], $result));
            }
            if ($method === 'DELETE') {
                require_admin_write('tracked-match-data');
                $mode = (string)($_GET['mode'] ?? 'data');
                if ($mode === 'finished-data') json_response(200, array_merge(['ok' => true], remove_finished_data()));
                $id = match_id($_GET['match'] ?? '');
                if ($mode === 'unfollow') json_response(200, ['ok' => true, 'match' => set_follow_state($id, false, 'manual')]);
                if ($mode === 'data') json_response(200, array_merge(['ok' => true], remove_match_data($id)));
                throw new InvalidArgumentException('Unsupported tracked match deletion mode.');
            }
            break;
        case 'diagnostics':
            if ($method === 'GET') {
                $test = root_dir().'/data/.write-test';
                $writable = @file_put_contents($test, 'ok') !== false;
                if ($writable) @unlink($test);
                $config = read_json_file(root_dir().'/data/server-config.json', []);
                $migration = migrate_legacy_tracking();
                $registry = read_follow_registry();
                $packageVersion=trim((string)@file_get_contents(root_dir().'/VERSION'));
                $manifest=read_json_file(root_dir().'/site-manifest.json',[]);
                $siteConfigText=(string)@file_get_contents(root_dir().'/assets/js/site-config.js');
                $siteConfigVersion=null;$siteBuiltAt=null;$siteSchema=null;
                if(preg_match('/\bversion:\s*"([^"]+)"/',$siteConfigText,$m))$siteConfigVersion=$m[1];
                if(preg_match('/\bbuiltAt:\s*"([^"]+)"/',$siteConfigText,$m))$siteBuiltAt=$m[1];
                if(preg_match('/\bschemaVersion:\s*(\d+)/',$siteConfigText,$m))$siteSchema=(int)$m[1];
                $runtimeDir=null;$db=['available'=>false];$cron=[];
                try{
                    $tpConfig=\p2k_tp_config();$runtimeDir=rtrim((string)($tpConfig['storage']['runtime_dir']??root_dir().'/data/runtime-v280'),'/\\');
                    $repo=new \P2K\TeamPoints\Repository(\P2K\TeamPoints\Database::core(),\P2K\TeamPoints\Database::analytics());
                    $db=['available'=>true,'core_schema'=>(int)$repo->schemaVersion(),'analytics_schema'=>(int)$repo->analyticsSchemaVersion(),'installed'=>$repo->schemaInstalled()];
                    foreach(['team-points-club','tournaments','team-points-player','match-tracking'] as $key){$path=$runtimeDir.'/cron-shell/last-'.$key.'.json';$cron[$key]=is_file($path)?read_json_file($path,[]):['status'=>'not_run'];}
                }catch(Throwable $e){$db=['available'=>false,'error'=>$e->getMessage()];}
                $warnings=[];
                $versions=array_filter(['package'=>$packageVersion,'manifest'=>(string)($manifest['version']??''),'site_config'=>(string)($siteConfigVersion??'')],static fn($v)=>$v!=='');
                if(count(array_unique(array_values($versions)))>1)$warnings[]='Release version markers disagree: '.json_encode($versions,JSON_UNESCAPED_SLASHES);
                json_response(200, [
                    'ok' => true,
                    'backend' => 'PHP '.PHP_VERSION,
                    'php'=>['version'=>PHP_VERSION,'sapi'=>PHP_SAPI],
                    'writable' => $writable,
                    'release'=>[
                        'package_version'=>$packageVersion?:null,'manifest_version'=>$manifest['version']??null,'manifest_schema'=>$manifest['schemaVersion']??null,
                        'manifest_built_at'=>$manifest['builtAt']??null,'site_config_version'=>$siteConfigVersion,'site_config_built_at'=>$siteBuiltAt,'site_config_schema'=>$siteSchema,
                    ],
                    'database'=>$db,
                    'runtime_dir'=>$runtimeDir,
                    'cron'=>$cron,
                    'warnings'=>$warnings,
                    'cronConfigured' => !empty($config['cronToken']),
                    'trafficAnalyticsConfigured'=>!empty($config['trafficAnalyticsSecret'])||!empty($config['cronToken']),
                    'followRegistryEntries' => count($registry['matches']),
                    'trackingMigration' => $migration,
                    'serverTime' => utc_stamp()
                ]);
            }
            break;
    }
    api_error(405, 'METHOD_NOT_ALLOWED', 'Method not allowed.');
} catch (\P2K\TeamPoints\ApiException $error) {
    api_error($error->httpStatus, $error->errorCode, $error->getMessage(), $error->details);
} catch (InvalidArgumentException $error) {
    api_error(400, 'INVALID_REQUEST', $error->getMessage());
} catch (RuntimeException $error) {
    api_error(502, 'UPSTREAM_FAILED', $error->getMessage());
} catch (Throwable $error) {
    api_error(500, 'SERVER_ERROR', $error->getMessage());
}
