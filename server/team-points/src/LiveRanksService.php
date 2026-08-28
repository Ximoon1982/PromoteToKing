<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

final class LiveRanksService
{
    private readonly string $clubSlug;
    private readonly string $storageDir;
    private readonly int $maxUploadFiles;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Repository $repository,
        private readonly ChessApi $api
    ) {
        $config = \p2k_tp_config();
        $this->clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
        $configured = trim((string)($config['app']['live_ranks_upload_dir'] ?? ''));
        $this->storageDir = $configured !== ''
            ? rtrim($configured, '/\\')
            : dirname(__DIR__, 3) . '/data/live-ranks/uploads';
        $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        $this->maxUploadFiles = max(10, (int)($storage['live_ranks_max_upload_files'] ?? 5000));
    }

    public function ensureStorage(): void
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0770, true) && !is_dir($this->storageDir)) {
            throw new \RuntimeException('Unable to create the Live ranks upload directory.');
        }
        $deny = dirname($this->storageDir) . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "Require all denied\nDeny from all\n");
        }
    }

    public function uploadFiles(array $files): array
    {
        $this->ensureStorage();
        $normalized = $this->normalizeUploadArray($files);
        $uploaded = [];
        $replaced = [];
        $errors = [];
        foreach ($normalized as $file) {
            try {
                $stored = $this->storeUpload($file);
                $uploaded[] = $stored;
                if (!empty($stored['replaced'])) {
                    $replaced[] = $stored;
                }
            } catch (ApiException $exception) {
                $errors[] = ['name' => (string)($file['name'] ?? ''), 'message' => $exception->getMessage()];
            } catch (\Throwable $exception) {
                $errors[] = ['name' => (string)($file['name'] ?? ''), 'message' => $exception->getMessage()];
            }
        }
        if ($uploaded !== []) {
            $this->invalidateProcessingAfterUpload();
        }
        $players = $this->adminPlayerRows();
        return [
            'uploaded' => array_values($uploaded),
            'replaced' => array_values($replaced),
            // Kept for compatibility with the first prototype response shape.
            'skipped' => [],
            'errors' => $errors,
            'files' => $this->fileRows(),
            'players' => $players,
            'processing' => $this->processingState(),
            'summary' => $this->summary($players),
        ];
    }

    public function fileRows(): array
    {
        $query = $this->pdo->prepare(
            "SELECT id,original_name,sha256,size_bytes,uploaded_at,replaced_at,arena_id,arena_slug,event_url,csv_url,source_origin,source_fetched_at,actual_event_date,effective_event_date,event_date_precision,event_date_updated_at,status,row_count,p2k_row_count,processed_at,last_error
             FROM p2k_lr_files WHERE club_slug=? ORDER BY uploaded_at DESC,id DESC"
        );
        $query->execute([$this->clubSlug]);
        return array_map(static function (array $row): array {
            $name=(string)$row['original_name'];
            $slug=trim((string)($row['arena_slug']??''));if($slug==='')$slug=preg_replace('/\.csv$/i','',$name)??$name;
            $arenaId=is_numeric($row['arena_id']??null)?(int)$row['arena_id']:null;if($arenaId===null&&preg_match('/-(\d+)$/',$slug,$m))$arenaId=(int)$m[1];
            return [
                'id' => (int)$row['id'],
                'name' => $name,
                'arena_id' => $arenaId,
                'event_url' => trim((string)($row['event_url']??'')) ?: ('https://www.chess.com/tournament/live/arena/' . rawurlencode($slug)),
                'csv_url' => trim((string)($row['csv_url']??'')),
                'source_origin' => (string)($row['source_origin']??'manual'),
                'source_fetched_at' => $row['source_fetched_at']!==null?(string)$row['source_fetched_at']:null,
                'sha256' => (string)$row['sha256'],
                'size' => (int)$row['size_bytes'],
                'uploaded_at' => (string)$row['uploaded_at'],
                'replaced_at' => $row['replaced_at'] !== null ? (string)$row['replaced_at'] : null,
                'actual_event_date' => $row['actual_event_date'] !== null ? (string)$row['actual_event_date'] : null,
                'event_date' => $row['effective_event_date'] !== null ? (string)$row['effective_event_date'] : substr((string)$row['uploaded_at'],0,10),
                'event_date_precision' => (string)($row['event_date_precision'] ?: 'upload-fallback'),
                'event_date_approximate' => (string)($row['event_date_precision'] ?? 'upload-fallback') !== 'known',
                'status' => (string)$row['status'],
                'rows' => (int)$row['row_count'],
                'p2k_rows' => (int)$row['p2k_row_count'],
                'processed_at' => $row['processed_at'] !== null ? (string)$row['processed_at'] : null,
                'error' => $row['last_error'] !== null ? (string)$row['last_error'] : null,
            ];
        }, $query->fetchAll() ?: []);
    }

    /** Store a known MCA event date and immediately recalculate all derived dates. */
    public function setEventDate(int $fileId, ?string $date): array
    {
        if($fileId<=0)throw new ApiException('A valid stored MCA file id is required.',400,'FILE_ID_REQUIRED');
        $date=$date!==null?trim($date):'';
        if($date!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new ApiException('Event date must use YYYY-MM-DD.',400,'INVALID_EVENT_DATE');
        if($date!==''){
            $dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$date,new \DateTimeZone('UTC'));
            if(!$dt||$dt->format('Y-m-d')!==$date)throw new ApiException('Event date is not a valid calendar date.',400,'INVALID_EVENT_DATE');
        }
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_files WHERE club_slug=? AND id=?');$q->execute([$this->clubSlug,$fileId]);
        if((int)$q->fetchColumn()!==1)throw new ApiException('Stored MCA source file was not found.',404,'MCA_FILE_NOT_FOUND');
        $u=$this->pdo->prepare('UPDATE p2k_lr_files SET actual_event_date=?,event_date_updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND id=?');
        $u->execute([$date!==''?$date:null,$this->clubSlug,$fileId]);
        $this->recomputeEventDates();
        foreach($this->fileRows() as $row)if((int)$row['id']===$fileId)return $row;
        throw new ApiException('Stored MCA source file was not found after update.',404,'MCA_FILE_NOT_FOUND');
    }

    /**
     * Arena ids are monotonically increasing on Chess.com. Known dates are hard
     * anchors; gaps between two anchors use id-weighted linear interpolation.
     * Outside an anchored interval we deliberately fall back to upload date.
     */
    public function recomputeEventDates(): void
    {
        $q=$this->pdo->prepare('SELECT id,original_name,uploaded_at,actual_event_date FROM p2k_lr_files WHERE club_slug=? ORDER BY id');$q->execute([$this->clubSlug]);$rows=$q->fetchAll()?:[];
        $byArena=[];$anchors=[];
        foreach($rows as $row){$slug=preg_replace('/\.csv$/i','',(string)$row['original_name'])??(string)$row['original_name'];$arena=null;if(preg_match('/-(\d+)$/',$slug,$m))$arena=(int)$m[1];$row['_arena']=$arena;if($arena!==null){$byArena[$arena]=$row;if(!empty($row['actual_event_date']))$anchors[$arena]=(string)$row['actual_event_date'];}}
        ksort($anchors,SORT_NUMERIC);$anchorIds=array_keys($anchors);
        $update=$this->pdo->prepare('UPDATE p2k_lr_files SET effective_event_date=?,event_date_precision=?,event_date_updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND id=?');
        foreach($rows as $row){$actual=trim((string)($row['actual_event_date']??''));$effective=$actual;$precision='known';$arena=$row['_arena']??null;
            if($effective===''){
                $precision='upload-fallback';$effective=substr((string)$row['uploaded_at'],0,10);
                if($arena!==null&&count($anchorIds)>=2){$prev=null;$next=null;foreach($anchorIds as $aid){if($aid<$arena)$prev=$aid;elseif($aid>$arena){$next=$aid;break;}else{$prev=$next=$aid;break;}}
                    if($prev!==null&&$next!==null&&$prev!==$next){$a=strtotime($anchors[$prev].' 00:00:00 UTC');$b=strtotime($anchors[$next].' 00:00:00 UTC');if($a!==false&&$b!==false&&$next>$prev){$ratio=($arena-$prev)/($next-$prev);$stamp=(int)round($a+($b-$a)*$ratio);$effective=gmdate('Y-m-d',$stamp);$precision='interpolated';}}
                }
            }
            $update->execute([$effective,$precision,$this->clubSlug,(int)$row['id']]);
        }
    }

    public function startProcessing(?float $deadlineAt = null): array
    {
        $files = $this->storedFileRecords();
        if ($files === []) {
            throw new ApiException('Upload at least one CSV file before processing.', 400, 'NO_LIVE_RANK_FILES');
        }
        $checkDeadline=static function(float $reserve=0.25) use($deadlineAt):void {
            if($deadlineAt!==null && microtime(true)+max(0.0,$reserve)>=$deadlineAt) throw new ApiException('MCA identity attribution rebuild reached its CMDI deadline.',503,'MIRA_DEADLINE');
        };
        $checkDeadline(0.5);
        $miac = new MiacService($this->repository->core(), $this->clubSlug);
        $miac->importSeedIfNeeded();
        $identityGeneration = $miac->generation();
        $aggregates = [];
        $fileStats = [];
        $sourceRowsByFile = [];
        $attributions = [];
        // MIRA: resolve each raw username once per rebuild. Large MCA histories repeat
        // the same names across many arenas; the MIAC graph is generation-stable for
        // this rebuild, so repeated Core/map lookups are pure amplification.
        $resolutionCache = [];
        foreach ($files as $file) {
            $checkDeadline(0.5);
            try {
                $parsed = $this->parseCsvFile((string)$file['stored_name']);
                $fileStats[(int)$file['id']] = ['rows' => $parsed['rows'], 'p2k_rows' => $parsed['p2k_rows'], 'error' => null, 'name' => (string)$file['original_name'], 'arena' => $parsed['arena']];
                $sourceRowsByFile[(int)$file['id']] = $parsed['source_rows'] ?? [];
                $eventAggregates = [];
                foreach (($parsed['source_rows'] ?? []) as $sourceIndex=>$sourceRow) {
                    if(($sourceIndex%250)===0)$checkDeadline(0.35);
                    $rawKey = (string)$sourceRow['username_key'];
                    if (!isset($resolutionCache[$rawKey])) $resolutionCache[$rawKey] = $miac->resolve((string)$sourceRow['username']);
                    $resolved = $resolutionCache[$rawKey];
                    $key = (string)$resolved['canonical_username_key'];
                    $canonicalName = (string)$resolved['canonical_username'];
                    if (!isset($eventAggregates[$key])) {
                        $eventAggregates[$key] = [
                            'username_key'=>$key,'username'=>$canonicalName,'score'=>0.0,'games'=>null,'wins'=>null,'draws'=>null,'losses'=>null,
                            'streak'=>null,'max_wins'=>null,'max_games'=>null,'rank'=>null,'reasons'=>[],'conflict'=>false,
                        ];
                    }
                    $e=&$eventAggregates[$key];
                    $e['score'] += (float)$sourceRow['score'];
                    foreach (['games','wins','draws','losses'] as $metric) if($sourceRow[$metric]!==null)$e[$metric]=(int)($e[$metric]??0)+(int)$sourceRow[$metric];
                    foreach (['streak','max_wins','max_games'] as $metric) if($sourceRow[$metric]!==null)$e[$metric]=max((int)($e[$metric]??0),(int)$sourceRow[$metric]);
                    if($sourceRow['rank']!==null&&(int)$sourceRow['rank']>0)$e['rank']=$e['rank']===null?(int)$sourceRow['rank']:min((int)$e['rank'],(int)$sourceRow['rank']);
                    $e['reasons'][(string)$resolved['reason']]=true;$e['conflict']=$e['conflict']||!empty($resolved['conflict']);
                    $attributions[]=[
                        'file_id'=>(int)$file['id'],'source_row_no'=>(int)$sourceRow['source_row_no'],'raw_username_key'=>
                            (string)$sourceRow['username_key'],'raw_username'=>(string)$sourceRow['username'],'canonical_username_key'=>$key,
                        'canonical_username'=>$canonicalName,'resolution_reason'=>(string)$resolved['reason'],'conflict'=>!empty($resolved['conflict'])?1:0,
                    ];
                    unset($e);
                }
                $eventIndex=0;
                foreach ($eventAggregates as $key=>$player) {
                    if(($eventIndex++%250)===0)$checkDeadline(0.35);
                    if (!isset($aggregates[$key])) {
                        $aggregates[$key] = [
                            'username_key' => $key,'username' => $player['username'],'total_points' => 0.0,'arena_count' => 0,
                            'total_games' => null,'total_wins' => null,'total_draws' => null,'total_losses' => null,'best_streak' => null,
                            'max_wins_single_arena' => null,'max_games_single_arena' => null,'best_rank' => null,'first_place_count' => 0,
                            'top3_count' => 0,'top10_count' => 0,'best_score' => null,'files' => [],'identity_reasons'=>[],'identity_conflict'=>false,
                        ];
                    }
                    $aggregates[$key]['total_points'] += (float)$player['score'];
                    $aggregates[$key]['arena_count']++; // distinct source event, even when multiple raw aliases map here
                    $aggregates[$key]['best_score'] = max((float)($aggregates[$key]['best_score'] ?? 0), (float)$player['score']);
                    if ($player['rank'] !== null && (int)$player['rank'] > 0) {
                        $rankValue = (int)$player['rank'];
                        $aggregates[$key]['best_rank'] = $aggregates[$key]['best_rank'] === null ? $rankValue : min((int)$aggregates[$key]['best_rank'], $rankValue);
                        if ($rankValue === 1) $aggregates[$key]['first_place_count']++;
                        if ($rankValue <= 3) $aggregates[$key]['top3_count']++;
                        if ($rankValue <= 10) $aggregates[$key]['top10_count']++;
                    }
                    foreach (['total_games'=>'games','total_wins'=>'wins','total_draws'=>'draws','total_losses'=>'losses'] as $target=>$source) if($player[$source]!==null)$aggregates[$key][$target]=(int)($aggregates[$key][$target]??0)+(int)$player[$source];
                    foreach (['best_streak'=>'streak','max_wins_single_arena'=>'max_wins','max_games_single_arena'=>'max_games'] as $target=>$source) if($player[$source]!==null)$aggregates[$key][$target]=max((int)($aggregates[$key][$target]??0),(int)$player[$source]);
                    foreach(array_keys($player['reasons']) as $reason)$aggregates[$key]['identity_reasons'][$reason]=true;
                    $aggregates[$key]['identity_conflict']=$aggregates[$key]['identity_conflict']||$player['conflict'];
                    $aggregates[$key]['files'][] = (string)$file['original_name'];
                }
            } catch (\Throwable $exception) {
                if($exception instanceof ApiException && $exception->errorCode==='MIRA_DEADLINE')throw $exception;
                $fileStats[(int)$file['id']] = ['rows' => 0, 'p2k_rows' => 0, 'error' => $exception->getMessage(), 'name' => (string)$file['original_name'], 'arena' => null];
            }
        }

        $checkDeadline(1.0);
        $roster = $this->currentMemberMap();
        $checkDeadline(0.75);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM p2k_lr_players WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare('DELETE FROM p2k_lr_arena_stats WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare('DELETE FROM p2k_lr_source_rows WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare('DELETE FROM p2k_lr_attributions WHERE club_slug=?')->execute([$this->clubSlug]);
            foreach ($fileStats as $fileId => $stats) {
                $status = $stats['error'] === null ? 'processed' : 'error';
                $update = $this->pdo->prepare(
                    'UPDATE p2k_lr_files SET status=?,row_count=?,p2k_row_count=?,processed_at=UTC_TIMESTAMP(),last_error=? WHERE id=? AND club_slug=?'
                );
                $update->execute([$status, $stats['rows'], $stats['p2k_rows'], $stats['error'], $fileId, $this->clubSlug]);
            }
            $arenaInsert = $this->pdo->prepare(
                'INSERT INTO p2k_lr_arena_stats(club_slug,file_id,original_name,participant_count,total_points,first_places,second_places,third_places,processed_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())'
            );
            foreach ($fileStats as $fileId => $stats) {
                if ($stats['error'] !== null || !is_array($stats['arena'])) continue;
                $arenaInsert->execute([
                    $this->clubSlug, $fileId, $stats['name'], (int)$stats['arena']['participants'],
                    round((float)$stats['arena']['points'], 2), (int)$stats['arena']['first'],
                    (int)$stats['arena']['second'], (int)$stats['arena']['third'],
                ]);
            }
            $sourceInsert=$this->pdo->prepare("INSERT INTO p2k_lr_source_rows(club_slug,file_id,source_row_no,raw_username_key,raw_username,score,games,wins,draws,losses,streak,max_wins,max_games,rank_value,source_event_key,captured_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
            $sourceCounter=0;foreach($sourceRowsByFile as $fileId=>$rows)foreach($rows as $row){if(($sourceCounter++%500)===0)$checkDeadline(0.4);$sourceInsert->execute([$this->clubSlug,$fileId,(int)$row['source_row_no'],(string)$row['username_key'],(string)$row['username'],(float)$row['score'],$row['games'],$row['wins'],$row['draws'],$row['losses'],$row['streak'],$row['max_wins'],$row['max_games'],$row['rank'],(string)$fileId]);}
            $attrInsert=$this->pdo->prepare("INSERT INTO p2k_lr_attributions(club_slug,file_id,source_row_no,raw_username_key,raw_username,canonical_username_key,canonical_username,resolution_reason,identity_map_generation,conflict,attributed_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
            $attrCounter=0;foreach($attributions as $a){if(($attrCounter++%500)===0)$checkDeadline(0.4);$attrInsert->execute([$this->clubSlug,$a['file_id'],$a['source_row_no'],$a['raw_username_key'],$a['raw_username'],$a['canonical_username_key'],$a['canonical_username'],$a['resolution_reason'],$identityGeneration,$a['conflict']]);}
            $insert = $this->pdo->prepare(
                "INSERT INTO p2k_lr_players(
                    club_slug,username_key,username,total_points,arena_count,total_games,total_wins,total_draws,total_losses,
                    best_streak,max_wins_single_arena,max_games_single_arena,best_rank,first_place_count,top3_count,top10_count,best_score,current_member,account_state,
                    profile_checked_at,last_error,source_files_json,identity_map_generation,identity_resolution,updated_at
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())"
            );
            $aggregateCounter=0;
            foreach ($aggregates as $key => $player) {
                if(($aggregateCounter++%250)===0)$checkDeadline(0.4);
                $current = isset($roster[$key]);
                $insert->execute([
                    $this->clubSlug,
                    $key,
                    $current ? (string)$roster[$key] : (string)$player['username'],
                    round((float)$player['total_points'], 2),
                    (int)$player['arena_count'],
                    $player['total_games'],
                    $player['total_wins'],
                    $player['total_draws'],
                    $player['total_losses'],
                    $player['best_streak'],
                    $player['max_wins_single_arena'],
                    $player['max_games_single_arena'],
                    $player['best_rank'],
                    (int)$player['first_place_count'],
                    (int)$player['top3_count'],
                    (int)$player['top10_count'],
                    $player['best_score'],
                    $current ? 1 : 0,
                    $current ? 'current_member' : 'pending_profile',
                    $current ? gmdate('Y-m-d H:i:s') : null,
                    null,
                    json_encode(array_values(array_unique($player['files'])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    $identityGeneration,
                    $player['identity_conflict'] ? 'hard_conflict' : (count($player['identity_reasons'])===1 ? (string)array_key_first($player['identity_reasons']) : 'mixed'),
                ]);
            }
            $pending = count(array_filter($aggregates, static fn(array $row): bool => !isset($roster[$row['username_key']])));
            $state = $this->pdo->prepare(
                "INSERT INTO p2k_lr_processing_state(
                    club_slug,status,phase,total_files,processed_files,total_players,checked_players,possible_renamed,closed_accounts,identity_map_generation,identity_stale,
                    started_at,updated_at,finished_at,last_error
                 ) VALUES(?,?,?,?,?,?,?,?,?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,NULL)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),phase=VALUES(phase),total_files=VALUES(total_files),
                    processed_files=VALUES(processed_files),total_players=VALUES(total_players),checked_players=VALUES(checked_players),
                    possible_renamed=0,closed_accounts=0,identity_map_generation=VALUES(identity_map_generation),identity_stale=0,started_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP(),finished_at=VALUES(finished_at),last_error=NULL"
            );
            $status = $pending > 0 ? 'running' : 'completed';
            $phase = $pending > 0 ? 'profile_checks' : 'complete';
            $state->execute([
                $this->clubSlug,
                $status,
                $phase,
                count($files),
                count(array_filter($fileStats, static fn(array $row): bool => $row['error'] === null)),
                count($aggregates),
                0,
                0,
                0,
                $identityGeneration,
                $pending > 0 ? null : gmdate('Y-m-d H:i:s'),
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        return $this->statusPayload();
    }

    public function processProfileBatch(int $limit = 8, ?callable $batchFetcher = null): array
    {
        $limit = max(1, min(256, $limit));
        $query = $this->pdo->prepare(
            "SELECT username_key,username FROM p2k_lr_players
             WHERE club_slug=? AND account_state='pending_profile' ORDER BY username_key LIMIT {$limit}"
        );
        $query->execute([$this->clubSlug]);
        $rows = $query->fetchAll() ?: [];
        $batchResults = $batchFetcher !== null ? $batchFetcher($rows) : null;
        foreach ($rows as $row) {
            $username = (string)$row['username'];
            $usernameKey = (string)$row['username_key'];
            $state = 'former_member';
            $error = null;
            $conclusive = true;
            if (is_array($batchResults)) {
                $response = is_array($batchResults[$usernameKey] ?? null) ? $batchResults[$usernameKey] : [];
                $status = (int)($response['status'] ?? 0);
                $profile = is_array($response['profile'] ?? null) ? $response['profile'] : null;
                if ($status >= 200 && $status < 300 && $profile !== null) {
                    if ($this->isClosedProfile($profile)) $state = 'closed_account';
                } elseif (in_array($status, [404, 410], true)) {
                    $state = 'possible_renamed';
                    $error = 'Chess.com no longer exposes this username.';
                } else {
                    // 429, transport failures and server failures are not identity
                    // evidence. Keep the player pending so a later adaptive batch
                    // can retry instead of misclassifying a temporary outage.
                    $conclusive = false;
                    $error = trim((string)($response['error'] ?? ($status ? "Chess.com HTTP {$status}" : 'Chess.com profile request failed.')));
                }
            } else {
                try {
                    $profile = $this->api->json('https://api.chess.com/pub/player/' . rawurlencode($usernameKey), true);
                    if ($this->isClosedProfile($profile)) $state = 'closed_account';
                } catch (\Throwable $exception) {
                    // Legacy anonymous path: retain established classification behavior.
                    $state = 'possible_renamed';
                    $error = $exception->getMessage();
                }
            }
            if ($conclusive) {
                $update = $this->pdo->prepare(
                    'UPDATE p2k_lr_players SET account_state=?,profile_checked_at=UTC_TIMESTAMP(),last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND username_key=?'
                );
                $update->execute([$state, $error, $this->clubSlug, $usernameKey]);
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE p2k_lr_players SET last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND username_key=?'
                );
                $update->execute([$error, $this->clubSlug, $usernameKey]);
            }
        }
        $pending = $this->pendingProfileCount();
        $counts = $this->accountCounts();
        $totalToCheck = array_sum($counts) - ($counts['current_member'] ?? 0);
        $checked = max(0, $totalToCheck - $pending);
        $complete = $pending === 0;
        $updateState = $this->pdo->prepare(
            "UPDATE p2k_lr_processing_state SET status=?,phase=?,checked_players=?,possible_renamed=?,closed_accounts=?,updated_at=UTC_TIMESTAMP(),finished_at=? WHERE club_slug=?"
        );
        $updateState->execute([
            $complete ? 'completed' : 'running',
            $complete ? 'complete' : 'profile_checks',
            $checked,
            (int)($counts['possible_renamed'] ?? 0),
            (int)($counts['closed_account'] ?? 0),
            $complete ? gmdate('Y-m-d H:i:s') : null,
            $this->clubSlug,
        ]);
        return $this->statusPayload();
    }


    /** v2.10.6.25 manual-only known-source timestamp repair status. */
    public function autoSyncStatus(): array
    {
        $counts=['pending'=>0,'running'=>0,'completed'=>0,'error'=>0];
        $cq=$this->pdo->prepare("SELECT status,COUNT(*) total FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=0 GROUP BY status");$cq->execute([$this->clubSlug]);
        foreach($cq->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)if(isset($counts[(string)$row['status']]))$counts[(string)$row['status']]=(int)$row['total'];
        $total=array_sum($counts);$done=$counts['completed']+$counts['error'];
        $eq=$this->pdo->prepare("SELECT arena_id,arena_slug,arena_url,csv_url,stage,attempts,last_error,updated_at FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=0 AND needs_date=1 AND status='error' ORDER BY updated_at DESC,arena_id DESC LIMIT 100");
        $eq->execute([$this->clubSlug]);$errors=array_map(static fn(array $row):array=>[
            'arena_id'=>(int)$row['arena_id'],'arena_slug'=>(string)$row['arena_slug'],'arena_url'=>(string)$row['arena_url'],
            'csv_url'=>(string)$row['csv_url'],'stage'=>(string)$row['stage'],'attempts'=>(int)$row['attempts'],
            'error'=>(string)($row['last_error']??''),'updated_at'=>(string)($row['updated_at']??''),
        ],$eq->fetchAll(PDO::FETCH_ASSOC)?:[]);
        $missingQ=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_files WHERE club_slug=? AND actual_event_date IS NULL');$missingQ->execute([$this->clubSlug]);$missing=(int)$missingQ->fetchColumn();
        $stateQ=$this->pdo->prepare('SELECT last_request_at,request_count FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');$stateQ->execute([$this->clubSlug]);$shared=$stateQ->fetch(PDO::FETCH_ASSOC)?:[];
        $status=($counts['pending']+$counts['running'])>0?'running':($total>0?'completed':'idle');
        return [
            'status'=>$status,'phase'=>$status==='running'?'dates':'manual','total_events'=>$total,'checked_events'=>$done,
            'dates_added'=>$counts['completed'],'error_count'=>$counts['error'],'queue'=>$counts,'errors'=>$errors,
            'progress_percent'=>$total>0?round(100*$done/$total,1):($missing===0?100.0:0.0),'request_spacing_ms'=>1000,'serial'=>true,
            'mode'=>'manual_timestamp_only','manual_only'=>true,'historical_missing_dates'=>$missing,
            'last_request_at'=>$shared['last_request_at']??null,'request_count'=>(int)($shared['request_count']??0),
        ];
    }

    private function syncLock(callable $callback): mixed
    {
        $key='p2k:mca-sync:'.substr($this->clubSlug,0,40);$q=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$key]);
        if((int)$q->fetchColumn()!==1)throw new ApiException('Another MCA source synchronization step is already active.',409,'MCA_SYNC_BUSY');
        try{return $callback();}finally{try{$r=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$r->execute([$key]);}catch(\Throwable){}}
    }

    private function ensureSyncState(): void
    {
        $this->pdo->prepare("INSERT IGNORE INTO p2k_lr_sync_state(club_slug,status,phase,updated_at) VALUES(?,'idle','idle',UTC_TIMESTAMP())")->execute([$this->clubSlug]);
    }

    private function waitForSyncRequestSlot(): void
    {
        $this->ensureSyncState();$q=$this->pdo->prepare('SELECT last_request_at FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');$q->execute([$this->clubSlug]);$last=(string)($q->fetchColumn()?:'');
        if($last!==''){$stamp=(float)(strtotime(substr($last,0,19).' UTC')?:0);$micro=0.0;if(preg_match('/\.(\d+)/',$last,$m))$micro=(float)('0.'.substr($m[1],0,6));$elapsed=microtime(true)-($stamp+$micro);if($elapsed<1.0)usleep((int)ceil((1.0-$elapsed)*1000000));}
        $this->pdo->prepare('UPDATE p2k_lr_sync_state SET last_request_at=UTC_TIMESTAMP(6),request_count=request_count+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
    }

    private function syncHttpGet(string $url): array
    {
        $this->waitForSyncRequestSlot();
        $headers=['Accept: text/html,text/csv;q=0.9,*/*;q=0.5','Accept-Language: en-US,en;q=0.8'];
        $body='';$status=0;$contentType='';
        if(function_exists('curl_init')){
            $ch=curl_init($url);if($ch===false)throw new \RuntimeException('Unable to initialize MCA HTTP client.');
            curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>30,CURLOPT_USERAGENT=>'PromoteToKing/2.10.6.25 MCA Date Repair (+https://www.promotetoking.org)',CURLOPT_HTTPHEADER=>$headers,CURLOPT_HEADER=>false]);
            $raw=curl_exec($ch);if($raw===false){$error=curl_error($ch);curl_close($ch);throw new \RuntimeException('MCA fetch failed: '.$error);}
            $body=(string)$raw;$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$contentType=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);curl_close($ch);
        }else{
            $context=stream_context_create(['http'=>['method'=>'GET','timeout'=>30,'ignore_errors'=>true,'header'=>"User-Agent: PromoteToKing/2.10.6.25 MCA Date Repair\r\nAccept: text/html,text/csv;q=0.9,*/*;q=0.5\r\nAccept-Language: en-US,en;q=0.8\r\n"]]);
            $raw=@file_get_contents($url,false,$context);$body=$raw===false?'':(string)$raw;
            foreach($http_response_header??[] as $header){if(preg_match('~^HTTP/\S+\s+(\d+)~i',$header,$m))$status=(int)$m[1];elseif(stripos($header,'Content-Type:')===0)$contentType=trim(substr($header,13));}
        }
        if($status<200||$status>=300)throw new \RuntimeException('MCA fetch returned HTTP '.$status.' for '.$url);
        return ['status'=>$status,'body'=>$body,'content_type'=>$contentType,'url'=>$url];
    }

    private function arenaIdentityFromName(string $name): ?array
    {
        $slug=preg_replace('/\.csv$/i','',basename(trim($name)))??'';if($slug===''||!preg_match('/-(\d+)$/',$slug,$m))return null;
        $id=(int)$m[1];if($id<=0)return null;$url='https://www.chess.com/tournament/live/arena/'.$slug;
        return ['arena_id'=>$id,'arena_slug'=>$slug,'arena_url'=>$url,'original_name'=>$slug.'.csv'];
    }

    /**
     * Manual historical-date repair only. Automatic Results discovery/download
     * is owned by McaResultsCronService; this queue slice contains needs_csv=0
     * rows derived exclusively from already stored source filenames.
     */
    private function seedTimestampQueue(): int
    {
        $files=$this->storedFileRecords();$queued=0;
        $update=$this->pdo->prepare("UPDATE p2k_lr_files SET arena_id=COALESCE(arena_id,?),arena_slug=CASE WHEN arena_slug IS NULL OR arena_slug='' THEN ? ELSE arena_slug END,event_url=? WHERE club_slug=? AND id=?");
        $ins=$this->pdo->prepare("INSERT INTO p2k_lr_sync_queue(club_slug,arena_id,arena_slug,arena_url,csv_url,stage,status,needs_csv,needs_date,discovered_at,updated_at) VALUES(?,?,?,?,?,'page','pending',0,1,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE arena_slug=VALUES(arena_slug),arena_url=VALUES(arena_url),csv_url='',stage='page',status='pending',needs_csv=0,needs_date=1,last_error=NULL,updated_at=UTC_TIMESTAMP()");
        foreach($files as $file){
            $identity=$this->arenaIdentityFromName((string)$file['original_name']);if(!$identity)continue;
            $update->execute([$identity['arena_id'],$identity['arena_slug'],$identity['arena_url'],$this->clubSlug,(int)$file['id']]);
            if(!empty($file['actual_event_date']))continue;
            $ins->execute([$this->clubSlug,$identity['arena_id'],$identity['arena_slug'],$identity['arena_url'],'']);$queued++;
        }
        return $queued;
    }

    /** Normalize a cycle left running by the retired discovery/CSV-fetch code. */
    private function normalizeLegacySyncCycle(array $state): array
    {
        $phase=(string)($state['phase']??'');
        if(($state['status']??'')!=='running'||$phase==='dates')return $state;
        $this->pdo->prepare('DELETE FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=0')->execute([$this->clubSlug]);
        $total=$this->seedTimestampQueue();
        $status=$total>0?'running':'completed';
        $this->pdo->prepare("UPDATE p2k_lr_sync_state SET status=?,phase=?,total_events=?,checked_events=0,csv_found=0,csv_added=0,error_count=0,rebuild_required=0,current_arena_id=NULL,current_arena_slug=NULL,current_stage=NULL,finished_at=?,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=?")->execute([$status,$total>0?'dates':'complete',$total,$total>0?null:gmdate('Y-m-d H:i:s'),$this->clubSlug]);
        $q=$this->pdo->prepare('SELECT * FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');$q->execute([$this->clubSlug]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
    }

    private function plausiblePastEventStamp(int $stamp): bool
    {
        return $stamp>=strtotime('2015-01-01 UTC')&&$stamp<=time()+86400;
    }

    private function extractArenaStart(string $html): array
    {
        $text=html_entity_decode(str_replace('\\/','/',$html),ENT_QUOTES|ENT_HTML5,'UTF-8');$plain=trim(preg_replace('/\s+/u',' ',strip_tags($text))??'');
        // Past-event visible header is the most trustworthy source (e.g. "127 players Aug 18, 2026, 7:30 PM").
        if(preg_match('~\b\d{1,5}\s+players\b.{0,120}?\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},\s+20\d{2}(?:,\s+\d{1,2}:\d{2}\s*(?:AM|PM))?~i',$plain,$m)){
            if(preg_match('~\b(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\s+\d{1,2},\s+20\d{2}(?:,\s+\d{1,2}:\d{2}\s*(?:AM|PM))?~i',$m[0],$d)){$stamp=strtotime($d[0].' UTC');if($stamp!==false&&$this->plausiblePastEventStamp($stamp))return ['event_start_at'=>gmdate('Y-m-d H:i:s',$stamp),'event_date'=>gmdate('Y-m-d',$stamp),'precision'=>'visible'];}
        }
        $candidates=[];
        if(preg_match_all('~"(?:startTime|start_time|startDate|start_date)"\s*:\s*"([^"]+)"~i',$text,$m))$candidates=array_merge($candidates,$m[1]);
        if(preg_match_all('~"(?:startTime|start_time)"\s*:\s*(\d{10,13})~i',$text,$m))$candidates=array_merge($candidates,$m[1]);
        foreach($candidates as $candidate){$candidate=trim((string)$candidate);$stamp=false;if(ctype_digit($candidate)){$n=(int)$candidate;if($n>20000000000)$n=(int)floor($n/1000);$stamp=$n;}else $stamp=strtotime($candidate);if($stamp!==false&&$this->plausiblePastEventStamp((int)$stamp))return ['event_start_at'=>gmdate('Y-m-d H:i:s',(int)$stamp),'event_date'=>gmdate('Y-m-d',(int)$stamp),'precision'=>'machine'];}
        // Generic <time> is last-resort only and is still subject to past-event sanity.
        if(preg_match_all('~<time[^>]+datetime=["\']([^"\']+)~i',$text,$m))foreach($m[1] as $candidate){$stamp=strtotime(trim((string)$candidate));if($stamp!==false&&$this->plausiblePastEventStamp((int)$stamp))return ['event_start_at'=>gmdate('Y-m-d H:i:s',(int)$stamp),'event_date'=>gmdate('Y-m-d',(int)$stamp),'precision'=>'machine'];}
        return ['event_start_at'=>null,'event_date'=>null,'precision'=>'unknown'];
    }

    private function clearImpossibleFutureEventDates(): int
    {
        $q=$this->pdo->prepare("UPDATE p2k_lr_files SET actual_event_date=NULL,event_date_updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND actual_event_date>UTC_DATE()");$q->execute([$this->clubSlug]);$changed=$q->rowCount();if($changed>0)$this->recomputeEventDates();return $changed;
    }

    public function startAutoSync(bool $force=false): array
    {
        return $this->syncLock(function() use($force):array {
            $this->ensureStorage();$this->ensureSyncState();$this->clearImpossibleFutureEventDates();
            $state=$this->autoSyncStatus();
            if(!$force&&$state['status']==='running')return $state;
            // Date repair owns only needs_csv=0 rows. MCA Results discovery/hydration
            // owns needs_csv=1 rows and must remain untouched.
            $this->pdo->prepare('DELETE FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=0')->execute([$this->clubSlug]);
            $this->seedTimestampQueue();
            return $this->autoSyncStatus();
        });
    }

    public function retryAutoSyncErrors(): array
    {
        return $this->syncLock(function():array {
            $q=$this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='pending',stage='page',last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND needs_csv=0 AND needs_date=1 AND status='error'");$q->execute([$this->clubSlug]);
            return $this->autoSyncStatus();
        });
    }

    private function finishSyncItem(int $arenaId): void
    {
        $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='completed',stage='done',needs_csv=0,needs_date=0,fetched_at=UTC_TIMESTAMP(),last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=? AND needs_csv=0")->execute([$this->clubSlug,$arenaId]);
    }

    public function autoSyncStep(): array
    {
        return $this->syncLock(function():array {
            $this->ensureSyncState();
            $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='pending' WHERE club_slug=? AND needs_csv=0 AND needs_date=1 AND status='running'")->execute([$this->clubSlug]);
            $q=$this->pdo->prepare("SELECT q.* FROM p2k_lr_sync_queue q JOIN p2k_lr_files f ON f.club_slug=q.club_slug AND (f.arena_id=q.arena_id OR f.original_name=CONCAT(q.arena_slug,'.csv')) WHERE q.club_slug=? AND q.needs_csv=0 AND q.needs_date=1 AND q.status='pending' AND f.actual_event_date IS NULL ORDER BY q.arena_id DESC LIMIT 1");$q->execute([$this->clubSlug]);$item=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($item)){$this->recomputeEventDates();return $this->autoSyncStatus();}
            $arenaId=(int)$item['arena_id'];
            $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='running',stage='page',attempts=attempts+1,last_error=NULL,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=? AND needs_csv=0")->execute([$this->clubSlug,$arenaId]);
            try{
                $page=$this->syncHttpGet((string)$item['arena_url']);$date=$this->extractArenaStart((string)$page['body']);
                if(empty($date['event_date']))throw new \RuntimeException('Unable to extract the MCA event date from the known arena page.');
                $fq=$this->pdo->prepare('SELECT id,actual_event_date FROM p2k_lr_files WHERE club_slug=? AND (arena_id=? OR original_name=?) LIMIT 1');$fq->execute([$this->clubSlug,$arenaId,$item['arena_slug'].'.csv']);$file=$fq->fetch(PDO::FETCH_ASSOC);
                if(!is_array($file))throw new \RuntimeException('The queued MCA timestamp item no longer has a stored source CSV.');
                if(empty($file['actual_event_date']))$this->pdo->prepare('UPDATE p2k_lr_files SET actual_event_date=?,event_date_updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$date['event_date'],(int)$file['id']]);
                $this->pdo->prepare('UPDATE p2k_lr_sync_queue SET event_start_at=?,event_date=? WHERE club_slug=? AND arena_id=? AND needs_csv=0')->execute([$date['event_start_at'],$date['event_date'],$this->clubSlug,$arenaId]);
                $this->finishSyncItem($arenaId);
            }catch(\Throwable $e){
                $this->pdo->prepare("UPDATE p2k_lr_sync_queue SET status='error',stage='page',needs_csv=0,needs_date=1,last_error=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND arena_id=?")->execute([substr($e->getMessage(),0,4000),$this->clubSlug,$arenaId]);
            }
            $pending=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_lr_sync_queue WHERE club_slug=? AND needs_csv=0 AND needs_date=1 AND status='pending'");$pending->execute([$this->clubSlug]);
            if((int)$pending->fetchColumn()===0)$this->recomputeEventDates();
            return $this->autoSyncStatus();
        });
    }

    public function acknowledgeAutoSyncRebuild(): array
    {
        return $this->autoSyncStatus();
    }

    /** Compatibility hook only. Historical date repair is manual-only in v2.10.6.25. */
    public function runAutoSyncCron(int $maxSeconds=90): array
    {
        return $this->autoSyncStatus()+['cron_disabled'=>true];
    }

    public function statusPayload(): array
    {
        $stateQuery = $this->pdo->prepare('SELECT * FROM p2k_lr_processing_state WHERE club_slug=? LIMIT 1');
        $stateQuery->execute([$this->clubSlug]);
        $state = $stateQuery->fetch() ?: [];
        $players = $this->adminPlayerRows();
        return [
            'files' => $this->fileRows(),
            'processing' => $state,
            'sync' => (new McaResultsCronService($this->pdo, $this->repository))->status(),
            'date_sync' => $this->autoSyncStatus(),
            'players' => $players,
            'summary' => $this->summary($players),
        ];
    }

    public function identityAttributionStatus(): array
    {
        $current=(new MiacService($this->repository->core(),$this->clubSlug))->generation();
        $q=$this->pdo->prepare('SELECT identity_map_generation,identity_stale,status,phase,updated_at FROM p2k_lr_processing_state WHERE club_slug=? LIMIT 1');
        $q->execute([$this->clubSlug]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];$stored=(int)($row['identity_map_generation']??0);
        $has=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_source_rows WHERE club_slug=?');$has->execute([$this->clubSlug]);$sourceRows=(int)$has->fetchColumn();
        return ['current_generation'=>$current,'derived_generation'=>$stored,'stale'=>$sourceRows>0&&$stored!==$current,'source_rows'=>$sourceRows,'processing'=>$row];
    }

    public function rebuildIdentityAttributionIfNeeded(?float $deadlineAt = null): array
    {
        $state=$this->identityAttributionStatus();
        if(empty($state['stale']))return ['ran'=>false,'reason'=>'current']+$state;
        $files=$this->storedFileRecords();
        if($files===[])return ['ran'=>false,'reason'=>'no_source_files']+$state;
        $result=$this->startProcessing($deadlineAt);
        return ['ran'=>true,'reason'=>'identity_generation_changed','before'=>$state,'after'=>$this->identityAttributionStatus(),'processing'=>$result];
    }

    public function publicPayload(string $rankKey = '', int $page = 1, int $pageSize = 25): array
    {
        $thresholds=self::thresholds();$page=max(1,$page);$pageSize=max(10,min(50,$pageSize));$groups=[];
        foreach($thresholds as $i=>$rank){$min=(float)$rank['minimum'];$upper=$thresholds[$i+1]['minimum']??null;$where='club_slug=? AND current_member=1 AND total_points>=?';$params=[$this->clubSlug,$min];if($upper!==null){$where.=' AND total_points<?';$params[]=(float)$upper;}
            $q=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_lr_players WHERE {$where}");$q->execute($params);$count=(int)$q->fetchColumn();$q=$this->pdo->prepare("SELECT username,total_points FROM p2k_lr_players WHERE {$where} ORDER BY total_points DESC,username_key ASC LIMIT 1");$q->execute($params);$top=$q->fetch()?:null;
            $groups[$rank['key']]=['rank'=>$rank,'member_count'=>$count,'top_member'=>$top['username']??null,'top_points'=>$top===null?null:(float)$top['total_points']];}
        $summaryQ=$this->pdo->prepare('SELECT COUNT(*) players,SUM(total_points>=50) ranked_players,ROUND(COALESCE(SUM(total_points),0),2) total_points FROM p2k_lr_players WHERE club_slug=? AND current_member=1');$summaryQ->execute([$this->clubSlug]);$sr=$summaryQ->fetch()?:[];$counts=$this->accountCounts();$summary=['players'=>(int)($sr['players']??0),'ranked_players'=>(int)($sr['ranked_players']??0),'total_points'=>(float)($sr['total_points']??0),'current_members'=>(int)($sr['players']??0),'former_members'=>(int)($counts['former_member']??0),'closed_accounts'=>(int)($counts['closed_account']??0),'possible_renames'=>(int)($counts['possible_renamed']??0),'pending_checks'=>(int)($counts['pending_profile']??0)];
        $unranked=max(0,$summary['players']-$summary['ranked_players']);$stateQuery=$this->pdo->prepare('SELECT status,phase,updated_at,finished_at,last_error FROM p2k_lr_processing_state WHERE club_slug=? LIMIT 1');$stateQuery->execute([$this->clubSlug]);$payload=['thresholds'=>$thresholds,'groups'=>array_values($groups),'unranked_count'=>$unranked,'summary'=>$summary,'processing'=>$stateQuery->fetch()?:null,'materialized_summary'=>true];
        $leaderQ=$this->pdo->prepare('SELECT username,total_points FROM p2k_lr_players WHERE club_slug=? AND current_member=1 ORDER BY total_points DESC,username_key ASC LIMIT 1');$leaderQ->execute([$this->clubSlug]);$leader=$leaderQ->fetch();if(is_array($leader))$payload['leader']=['username'=>(string)$leader['username'],'points'=>(float)$leader['total_points']];
        if($rankKey!==''){
            if(!isset($groups[$rankKey]))throw new ApiException('Unknown Live rank.',400,'UNKNOWN_LIVE_RANK');$rank=$groups[$rankKey]['rank'];$min=(float)$rank['minimum'];$upper=null;foreach($thresholds as $i=>$candidate)if($candidate['key']===$rankKey){$upper=$thresholds[$i+1]['minimum']??null;break;}$where='p.club_slug=? AND p.current_member=1 AND p.total_points>=?';$params=[$this->clubSlug,$min];if($upper!==null){$where.=' AND p.total_points<?';$params[]=(float)$upper;}
            $cq=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_lr_players p WHERE {$where}");$cq->execute($params);$total=(int)$cq->fetchColumn();$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);$offset=($page-1)*$pageSize;
            $sql="SELECT p.*,1+(SELECT COUNT(*) FROM p2k_lr_players x WHERE x.club_slug=p.club_slug AND x.current_member=1 AND (x.total_points>p.total_points OR (x.total_points=p.total_points AND x.username_key<p.username_key))) team_position FROM p2k_lr_players p WHERE {$where} ORDER BY p.total_points DESC,p.username_key ASC LIMIT {$pageSize} OFFSET {$offset}";$q=$this->pdo->prepare($sql);$q->execute($params);$members=[];foreach($q->fetchAll()?:[] as $i=>$row){$member=$this->compactPlayerRow($row);$member['team_position']=(int)$row['team_position'];$member['rank_key']=$rankKey;$member['rank_name']=(string)$rank['name'];$member['category_position']=$offset+$i+1;$members[]=$member;}
            $slice=$members;$payload['selected_rank']=array_merge($rank,['members'=>$total]);$payload['members']=$slice;$payload['pagination']=['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$total,'total_pages'=>$pages];}
        $payload['identity_attribution']=$this->identityAttributionStatus();
        return $payload;
    }

    public function publicPlayerPayload(string $username): array
    {
        $usernameKey = \p2k_tp_username_key($username);
        $query = $this->pdo->prepare(
            "SELECT username,total_points,arena_count,total_games,total_wins,total_draws,total_losses,
                    best_streak,max_wins_single_arena,max_games_single_arena,best_rank,first_place_count,top3_count,top10_count,best_score,current_member,account_state,updated_at
             FROM p2k_lr_players WHERE club_slug=? AND username_key=? LIMIT 1"
        );
        $query->execute([$this->clubSlug, $usernameKey]);
        $row = $query->fetch();
        if (!is_array($row) || !(bool)$row['current_member']) {
            return [
                'username' => $username,
                'current_member' => false,
                'available' => false,
                'points' => 0.0,
                'rank' => null,
                'team_position' => null,
                'category_position' => null,
            ];
        }
        $points = (float)$row['total_points'];
        $rank = $this->rankFor($points);
        $position = $this->pdo->prepare(
            "SELECT 1+COUNT(*) FROM p2k_lr_players
             WHERE club_slug=? AND current_member=1
               AND (total_points>? OR (total_points=? AND username_key<?))"
        );
        $position->execute([$this->clubSlug, $points, $points, $usernameKey]);
        $teamPosition = (int)$position->fetchColumn();
        $categoryPosition = null;
        if ($rank !== null) {
            $thresholds = self::thresholds();
            $upper = null;
            foreach ($thresholds as $index => $candidate) {
                if ($candidate['key'] === $rank['key']) {
                    $upper = $thresholds[$index + 1]['minimum'] ?? null;
                    break;
                }
            }
            $sql = "SELECT 1+COUNT(*) FROM p2k_lr_players
                    WHERE club_slug=? AND current_member=1 AND total_points>=?
                      AND (total_points>? OR (total_points=? AND username_key<?))";
            $params = [$this->clubSlug, (float)$rank['minimum'], $points, $points, $usernameKey];
            if ($upper !== null) {
                $sql .= ' AND total_points<?';
                $params[] = (float)$upper;
            }
            $category = $this->pdo->prepare($sql);
            $category->execute($params);
            $categoryPosition = (int)$category->fetchColumn();
        }
        return $this->compactPlayerRow($row) + [
            'available' => true,
            'rank' => $rank,
            'rank_key' => $rank['key'] ?? 'unranked',
            'rank_name' => $rank['name'] ?? 'Unranked',
            'team_position' => $teamPosition,
            'category_position' => $categoryPosition,
        ];
    }


    public function publicTeamPayload(): array
    {
        $summary = $this->pdo->prepare(
            "SELECT COUNT(*) AS arenas,COALESCE(SUM(participant_count),0) AS participations,
                    COALESCE(MAX(participant_count),0) AS most_participants,
                    COALESCE(MAX(total_points),0) AS most_points,
                    COALESCE(SUM(first_places),0) AS first_places,
                    COALESCE(SUM(second_places),0) AS second_places,
                    COALESCE(SUM(third_places),0) AS third_places,
                    COALESCE(SUM(total_points),0) AS aggregate_points
             FROM p2k_lr_arena_stats WHERE club_slug=?"
        );
        $summary->execute([$this->clubSlug]);
        $row = $summary->fetch() ?: [];
        $maxParticipants = $this->pdo->prepare(
            'SELECT original_name,participant_count FROM p2k_lr_arena_stats WHERE club_slug=? ORDER BY participant_count DESC,total_points DESC,file_id ASC LIMIT 1'
        );
        $maxParticipants->execute([$this->clubSlug]);
        $participantsArena = $maxParticipants->fetch() ?: [];
        $maxPoints = $this->pdo->prepare(
            'SELECT original_name,total_points FROM p2k_lr_arena_stats WHERE club_slug=? ORDER BY total_points DESC,participant_count DESC,file_id ASC LIMIT 1'
        );
        $maxPoints->execute([$this->clubSlug]);
        $pointsArena = $maxPoints->fetch() ?: [];
        $players = $this->pdo->prepare(
            'SELECT COUNT(*) AS current_players,COALESCE(SUM(total_points),0) AS current_points FROM p2k_lr_players WHERE club_slug=? AND current_member=1'
        );
        $players->execute([$this->clubSlug]);
        $playerRow = $players->fetch() ?: [];
        return [
            'arenas' => (int)($row['arenas'] ?? 0),
            'participations' => (int)($row['participations'] ?? 0),
            'current_players' => (int)($playerRow['current_players'] ?? 0),
            'current_player_points' => (float)($playerRow['current_points'] ?? 0),
            'aggregate_points' => (float)($row['aggregate_points'] ?? 0),
            'most_participants' => (int)($row['most_participants'] ?? 0),
            'most_participants_arena' => (string)($participantsArena['original_name'] ?? ''),
            'most_points' => (float)($row['most_points'] ?? 0),
            'most_points_arena' => (string)($pointsArena['original_name'] ?? ''),
            'first_places' => (int)($row['first_places'] ?? 0),
            'second_places' => (int)($row['second_places'] ?? 0),
            'third_places' => (int)($row['third_places'] ?? 0),
        ];
    }

    /** Public MCA/Arena Insights derived only from processed Results CSV evidence and canonical MIRA attribution. */
    public function publicArenasInsights(string $section = 'all', array $options = []): array
    {
        $section = strtolower(trim($section));
        $allowed = ['all','summary','trend','leaders','records','table','detail'];
        if (!in_array($section, $allowed, true)) throw new ApiException('Unknown Arena Insights section.', 400, 'UNKNOWN_ARENA_INSIGHTS_SECTION');

        $arenas = $this->arenaInsightsRows();
        $resultsByFile = $this->arenaCanonicalResults();
        $trend = [];
        $cumulative = 0.0;
        foreach ($arenas as $arena) {
            $fileId = (int)$arena['file_id'];
            $results = $resultsByFile[$fileId] ?? [];
            $best = null;
            $top10 = 0;
            $podiums = 0;
            $games = 0;
            $wins = 0;
            $draws = 0;
            $losses = 0;
            $hasGames = false;
            foreach ($results as $player) {
                $rank = $player['rank'] === null ? null : (int)$player['rank'];
                if ($rank !== null && $rank > 0) {
                    if ($best === null || $rank < (int)$best['rank'] || ($rank === (int)$best['rank'] && strcasecmp((string)$player['username'], (string)$best['username']) < 0)) $best = $player;
                    if ($rank <= 10) $top10++;
                    if ($rank <= 3) $podiums++;
                }
                foreach (['games','wins','draws','losses'] as $metric) {
                    if ($player[$metric] !== null) $hasGames = true;
                }
                $games += (int)($player['games'] ?? 0);
                $wins += (int)($player['wins'] ?? 0);
                $draws += (int)($player['draws'] ?? 0);
                $losses += (int)($player['losses'] ?? 0);
            }
            $points = round((float)$arena['p2k_points'], 2);
            $cumulative = round($cumulative + $points, 2);
            $fieldSize = max(0, (int)$arena['total_players']);
            $p2kPlayers = max(0, (int)$arena['p2k_players']);
            $bestRank = $best === null ? null : (int)$best['rank'];
            $percentile = null;
            if ($bestRank !== null && $fieldSize > 0) {
                $percentile = $fieldSize <= 1 ? 100.0 : round(100 * max(0, $fieldSize - $bestRank) / max(1, $fieldSize - 1), 1);
            }
            $scorePercent = $hasGames && $games > 0 ? round(100 * ($wins + 0.5 * $draws) / $games, 1) : null;
            $trend[] = $arena + [
                'p2k_share_percent' => $fieldSize > 0 ? round(100 * $p2kPlayers / $fieldSize, 1) : null,
                'best_rank' => $bestRank,
                'best_finisher' => $best === null ? null : (string)$best['username'],
                'top10_count' => $top10,
                'podium_count' => $podiums,
                'games' => $hasGames ? $games : null,
                'wins' => $hasGames ? $wins : null,
                'draws' => $hasGames ? $draws : null,
                'losses' => $hasGames ? $losses : null,
                'score_percent' => $scorePercent,
                'best_percentile' => $percentile,
                'cumulative_points' => $cumulative,
            ];
        }

        $summary = $this->arenaInsightsSummary($trend);
        if ($section === 'summary') return ['summary' => $summary];
        if ($section === 'trend') return ['trend' => $trend];

        $leaders = $this->arenaInsightsLeaders($arenas);
        if ($section === 'leaders') return ['leaders' => $leaders];
        $records = $this->arenaInsightsRecords($leaders, $arenas);
        if ($section === 'records') return ['records' => $records];

        if ($section === 'detail') {
            $fileId = max(0, (int)($options['file_id'] ?? 0));
            if ($fileId <= 0) throw new ApiException('A valid arena file id is required.', 400, 'ARENA_FILE_ID_REQUIRED');
            $arena = null;
            foreach ($trend as $row) if ((int)$row['file_id'] === $fileId) { $arena = $row; break; }
            if ($arena === null) throw new ApiException('Arena not found.', 404, 'ARENA_NOT_FOUND');
            $participants = array_values($resultsByFile[$fileId] ?? []);
            usort($participants, static function(array $a,array $b):int {
                $ar = $a['rank'] === null ? PHP_INT_MAX : (int)$a['rank'];
                $br = $b['rank'] === null ? PHP_INT_MAX : (int)$b['rank'];
                return $ar === $br ? ((float)$b['points'] <=> (float)$a['points']) ?: strcasecmp((string)$a['username'],(string)$b['username']) : $ar <=> $br;
            });
            return ['arena' => $arena, 'participants' => $participants];
        }

        $table = $this->arenaInsightsTable($trend, $options);
        if ($section === 'table') return $table;
        return ['summary'=>$summary,'trend'=>$trend,'leaders'=>$leaders,'records'=>$records] + $table;
    }

    private function arenaInsightsRows(): array
    {
        $q = $this->pdo->prepare(
            "SELECT s.file_id,s.original_name,s.participant_count AS p2k_players,s.total_points AS p2k_points,
                    s.first_places,s.second_places,s.third_places,
                    f.arena_id,f.arena_slug,f.event_url,f.actual_event_date,f.effective_event_date,f.event_date_precision,
                    f.row_count AS total_players,f.p2k_row_count,f.uploaded_at,f.processed_at
             FROM p2k_lr_arena_stats s
             JOIN p2k_lr_files f ON f.club_slug=s.club_slug AND f.id=s.file_id
             WHERE s.club_slug=? AND f.status='processed'
             ORDER BY COALESCE(f.effective_event_date,DATE(f.uploaded_at)) ASC,COALESCE(f.arena_id,0) ASC,s.file_id ASC"
        );
        $q->execute([$this->clubSlug]);
        return array_map(function(array $row):array {
            $slug=trim((string)($row['arena_slug']??''));
            if($slug==='')$slug=preg_replace('/\.csv$/i','',(string)$row['original_name'])??(string)$row['original_name'];
            $arenaId=is_numeric($row['arena_id']??null)?(int)$row['arena_id']:null;
            $name=preg_replace('/-\d+$/','',$slug)??$slug;
            $name=trim(preg_replace('/[-_]+/',' ',$name)??$name);
            $display=$name!==''?ucwords($name):(string)$row['original_name'];
            $date=(string)($row['effective_event_date']??'');
            if($date==='')$date=substr((string)$row['uploaded_at'],0,10);
            $url=trim((string)($row['event_url']??''));
            if($url===''&&$slug!=='')$url='https://www.chess.com/tournament/live/arena/'.$slug;
            return [
                'file_id'=>(int)$row['file_id'],'arena_id'=>$arenaId,'arena_slug'=>$slug,'arena_name'=>$display,
                'source_name'=>(string)$row['original_name'],'event_url'=>$url,'event_date'=>$date,
                'event_date_precision'=>(string)($row['event_date_precision']??'upload-fallback'),
                'event_date_approximate'=>(string)($row['event_date_precision']??'upload-fallback')!=='known',
                'total_players'=>(int)$row['total_players'],'p2k_players'=>(int)$row['p2k_players'],
                'p2k_points'=>(float)$row['p2k_points'],'first_places'=>(int)$row['first_places'],
                'second_places'=>(int)$row['second_places'],'third_places'=>(int)$row['third_places'],
                'processed_at'=>$row['processed_at']!==null?(string)$row['processed_at']:null,
            ];
        },$q->fetchAll(PDO::FETCH_ASSOC)?:[]);
    }

    /** Canonical per-arena Results rows. Multiple historical aliases are merged exactly as in MIRA processing. */
    private function arenaCanonicalResults(): array
    {
        $q=$this->pdo->prepare(
            "SELECT r.file_id,a.canonical_username_key,a.canonical_username,r.score,r.games,r.wins,r.draws,r.losses,r.streak,r.max_wins,r.max_games,r.rank_value
             FROM p2k_lr_source_rows r
             JOIN p2k_lr_attributions a ON a.club_slug=r.club_slug AND a.file_id=r.file_id AND a.source_row_no=r.source_row_no
             WHERE r.club_slug=?
             ORDER BY r.file_id,r.source_row_no"
        );
        $q->execute([$this->clubSlug]);$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $file=(int)$row['file_id'];$key=(string)$row['canonical_username_key'];
            if(!isset($out[$file][$key]))$out[$file][$key]=['username_key'=>$key,'username'=>(string)$row['canonical_username'],'points'=>0.0,'games'=>null,'wins'=>null,'draws'=>null,'losses'=>null,'streak'=>null,'max_wins'=>null,'max_games'=>null,'rank'=>null];
            $p=&$out[$file][$key];$p['points']=round((float)$p['points']+(float)$row['score'],2);
            foreach(['games','wins','draws','losses'] as $metric)if($row[$metric]!==null)$p[$metric]=(int)($p[$metric]??0)+(int)$row[$metric];
            foreach(['streak','max_wins','max_games'] as $metric)if($row[$metric]!==null)$p[$metric]=max((int)($p[$metric]??0),(int)$row[$metric]);
            if($row['rank_value']!==null&&(int)$row['rank_value']>0)$p['rank']=$p['rank']===null?(int)$row['rank_value']:min((int)$p['rank'],(int)$row['rank_value']);
            unset($p);
        }
        return $out;
    }

    private function arenaInsightsSummary(array $trend): array
    {
        $uniqueQ=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_players WHERE club_slug=?');$uniqueQ->execute([$this->clubSlug]);
        $participations=0;$victories=0;$podiums=0;$top10=0;$best=null;
        foreach($trend as $row){$participations+=(int)$row['p2k_players'];if((int)($row['best_rank']??0)===1)$victories++;$podiums+=(int)$row['podium_count'];$top10+=(int)$row['top10_count'];if($row['best_rank']!==null)$best=$best===null?(int)$row['best_rank']:min($best,(int)$row['best_rank']);}
        $arenas=count($trend);
        return ['arenas'=>$arenas,'participations'=>$participations,'unique_players'=>(int)$uniqueQ->fetchColumn(),'victories'=>$victories,'podiums'=>$podiums,'top10_finishes'=>$top10,'best_finish'=>$best,'average_p2k_players'=>$arenas>0?round($participations/$arenas,1):0.0];
    }

    private function arenaInsightsLeaders(array $arenas): array
    {
        $latestIndex=count($arenas)-1;$fileIndex=[];foreach($arenas as $index=>$arena)$fileIndex[(string)$arena['source_name']]=$index;
        $q=$this->pdo->prepare("SELECT username,username_key,total_points,arena_count,total_games,total_wins,total_draws,total_losses,best_rank,first_place_count,top3_count,top10_count,current_member,account_state,source_files_json FROM p2k_lr_players WHERE club_slug=? ORDER BY total_points DESC,username_key ASC");$q->execute([$this->clubSlug]);$rows=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $indexes=[];foreach(\p2k_tp_json_decode((string)($row['source_files_json']??'[]')) as $name)if(isset($fileIndex[(string)$name]))$indexes[]=$fileIndex[(string)$name];$indexes=array_values(array_unique($indexes));sort($indexes,SORT_NUMERIC);
            $longest=0;$current=0;$run=0;$previous=null;foreach($indexes as $idx){$run=($previous!==null&&$idx===$previous+1)?$run+1:1;$longest=max($longest,$run);$previous=$idx;}if($indexes!==[]&&end($indexes)===$latestIndex)$current=$run;
            $points=(float)$row['total_points'];$rank=$this->rankFor($points);
            $rows[]=['username'=>(string)$row['username'],'username_key'=>(string)$row['username_key'],'arenas'=>(int)$row['arena_count'],'wins'=>(int)$row['first_place_count'],'podiums'=>(int)$row['top3_count'],'top10s'=>(int)$row['top10_count'],'points'=>$points,'games'=>$row['total_games']===null?null:(int)$row['total_games'],'game_wins'=>$row['total_wins']===null?null:(int)$row['total_wins'],'draws'=>$row['total_draws']===null?null:(int)$row['total_draws'],'losses'=>$row['total_losses']===null?null:(int)$row['total_losses'],'best_finish'=>$row['best_rank']===null?null:(int)$row['best_rank'],'live_rank_key'=>$rank['key']??'unranked','live_rank_name'=>$rank['name']??'Unranked','current_member'=>(bool)$row['current_member'],'account_state'=>(string)$row['account_state'],'longest_participation_streak'=>$longest,'current_participation_streak'=>$current];
        }
        return $rows;
    }

    private function arenaInsightsRecords(array $leaders,array $arenas): array
    {
        $spec=[['arenas','Most arena participations'],['wins','Most arena victories'],['podiums','Most podium finishes'],['top10s','Most Top-10 finishes'],['points','Most MCA points'],['longest_participation_streak','Longest participation streak'],['current_participation_streak','Current participation streak']];$records=[];
        foreach($spec as [$key,$label]){$best=null;foreach($leaders as $row){$value=(float)($row[$key]??0);if($best===null||$value>(float)($best[$key]??0)||($value===(float)($best[$key]??0)&&strcasecmp((string)$row['username'],(string)$best['username'])<0))$best=$row;}if($best!==null)$records[]=['key'=>$key,'label'=>$label,'username'=>(string)$best['username'],'value'=>$best[$key]??0];}
        $largest=null;$highestPoints=null;foreach($arenas as $arena){if($largest===null||(int)$arena['p2k_players']>(int)$largest['p2k_players'])$largest=$arena;if($highestPoints===null||(float)$arena['p2k_points']>(float)$highestPoints['p2k_points'])$highestPoints=$arena;}
        if($largest)$records[]=['key'=>'arena_participants','label'=>'Largest P2K arena turnout','arena'=>(string)$largest['arena_name'],'value'=>(int)$largest['p2k_players'],'file_id'=>(int)$largest['file_id']];
        if($highestPoints)$records[]=['key'=>'arena_points','label'=>'Most P2K points in one arena','arena'=>(string)$highestPoints['arena_name'],'value'=>(float)$highestPoints['p2k_points'],'file_id'=>(int)$highestPoints['file_id']];
        return $records;
    }

    private function arenaInsightsTable(array $trend,array $options): array
    {
        $rows=$trend;$search=strtolower(trim((string)($options['search']??'')));if($search!=='')$rows=array_values(array_filter($rows,static fn(array $row):bool=>str_contains(strtolower((string)$row['arena_name'].' '.(string)$row['arena_slug'].' '.(string)($row['best_finisher']??'')),$search)));
        $sort=strtolower(trim((string)($options['sort']??'event_date')));$valid=['event_date','arena_name','total_players','p2k_players','p2k_share_percent','best_rank','top10_count','podium_count','p2k_points','score_percent'];if(!in_array($sort,$valid,true))$sort='event_date';$direction=strtolower((string)($options['direction']??'desc'))==='asc'?1:-1;
        usort($rows,static function(array $a,array $b)use($sort,$direction):int{$av=$a[$sort]??null;$bv=$b[$sort]??null;if($av===null&&$bv!==null)return 1;if($bv===null&&$av!==null)return -1;$cmp=is_numeric($av)&&is_numeric($bv)?((float)$av<=>(float)$bv):strcasecmp((string)$av,(string)$bv);if($cmp===0)$cmp=(int)$a['file_id']<=>(int)$b['file_id'];return $direction*$cmp;});
        $page=max(1,(int)($options['page']??1));$pageSize=max(10,min(100,(int)($options['page_size']??25)));$total=count($rows);$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);$slice=array_slice($rows,($page-1)*$pageSize,$pageSize);
        return ['rows'=>$slice,'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$total,'total_pages'=>$pages]];
    }


    public function exportCorrectionsCsv(): void
    {
        $query = $this->pdo->prepare(
            "SELECT username,total_points,arena_count,source_files_json,last_error
             FROM p2k_lr_players WHERE club_slug=? AND account_state='possible_renamed' ORDER BY username"
        );
        $query->execute([$this->clubSlug]);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="p2k-live-ranks-possible-renames.csv"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['username_in_csv', 'points', 'arenas', 'source_files', 'verification_error', 'corrected_username']);
        foreach ($query->fetchAll() ?: [] as $row) {
            $files = implode(' | ', \p2k_tp_json_decode((string)($row['source_files_json'] ?? '[]')));
            fputcsv($out, [$row['username'], $row['total_points'], $row['arena_count'], $files, $row['last_error'], '']);
        }
        fclose($out);
    }

    public static function thresholds(): array
    {
        return [
            ['key' => 'live_pawn', 'name' => 'Live Pawn', 'minimum' => 50, 'icon' => '01_Pawn.png', 'framed_image' => '01_Live_Pawn_50_points.png', 'image' => '01_Live_Pawn_50_points.png', 'thumbnail' => '01_Live_Pawn_50_points.webp'],
            ['key' => 'live_knight', 'name' => 'Live Knight', 'minimum' => 150, 'icon' => '02_Knight.png', 'framed_image' => '02_Live_Knight_150_points.png', 'image' => '02_Live_Knight_150_points.png', 'thumbnail' => '02_Live_Knight_150_points.webp'],
            ['key' => 'live_bishop', 'name' => 'Live Bishop', 'minimum' => 500, 'icon' => '03_Bishop.png', 'framed_image' => '03_Live_Bishop_500_points.png', 'image' => '03_Live_Bishop_500_points.png', 'thumbnail' => '03_Live_Bishop_500_points.webp'],
            ['key' => 'live_rook', 'name' => 'Live Rook', 'minimum' => 2500, 'icon' => '04_Rook.png', 'framed_image' => '04_Live_Rook_2500_points.png', 'image' => '04_Live_Rook_2500_points.png', 'thumbnail' => '04_Live_Rook_2500_points.webp'],
            ['key' => 'live_queen', 'name' => 'Live Queen', 'minimum' => 7500, 'icon' => '05_Queen.png', 'framed_image' => '05_Live_Queen_7500_points.png', 'image' => '05_Live_Queen_7500_points.png', 'thumbnail' => '05_Live_Queen_7500_points.webp'],
            ['key' => 'live_king', 'name' => 'Live King', 'minimum' => 15000, 'icon' => '06_King.png', 'framed_image' => '06_Live_King_15000_points.png', 'image' => '06_Live_King_15000_points.png', 'thumbnail' => '06_Live_King_15000_points.webp'],
        ];
    }

    private function adminPlayerRows(): array
    {
        $query = $this->pdo->prepare(
            "SELECT username,total_points,arena_count,total_games,total_wins,total_draws,total_losses,
                    best_streak,max_wins_single_arena,max_games_single_arena,best_rank,first_place_count,top3_count,top10_count,best_score,current_member,account_state,
                    profile_checked_at,last_error,source_files_json,updated_at
             FROM p2k_lr_players WHERE club_slug=? ORDER BY total_points DESC,username"
        );
        $query->execute([$this->clubSlug]);
        return array_map(function (array $row): array {
            return $this->compactPlayerRow($row) + [
                'profile_checked_at' => $row['profile_checked_at'] !== null ? (string)$row['profile_checked_at'] : null,
                'error' => $row['last_error'] !== null ? (string)$row['last_error'] : null,
                'source_files' => \p2k_tp_json_decode((string)($row['source_files_json'] ?? '[]')),
            ];
        }, $query->fetchAll() ?: []);
    }

    private function publicPlayerRows(): array
    {
        $query = $this->pdo->prepare(
            "SELECT username,total_points,arena_count,total_games,total_wins,total_draws,total_losses,
                    best_streak,max_wins_single_arena,max_games_single_arena,best_rank,first_place_count,top3_count,top10_count,best_score,current_member,account_state,updated_at
             FROM p2k_lr_players
             WHERE club_slug=? AND current_member=1
             ORDER BY total_points DESC,username_key"
        );
        $query->execute([$this->clubSlug]);
        return array_map(fn(array $row): array => $this->compactPlayerRow($row), $query->fetchAll() ?: []);
    }

    private function compactPlayerRow(array $row): array
    {
        $nullableInt = static fn(array $source, string $key): ?int => $source[$key] === null || $source[$key] === '' ? null : (int)$source[$key];
        return [
            'username' => (string)$row['username'],
            'points' => (float)$row['total_points'],
            'arenas' => (int)$row['arena_count'],
            'games' => $nullableInt($row, 'total_games'),
            'wins' => $nullableInt($row, 'total_wins'),
            'draws' => $nullableInt($row, 'total_draws'),
            'losses' => $nullableInt($row, 'total_losses'),
            'best_streak' => $nullableInt($row, 'best_streak'),
            'max_wins_single_arena' => $nullableInt($row, 'max_wins_single_arena'),
            'max_games_single_arena' => $nullableInt($row, 'max_games_single_arena'),
            'best_rank' => $nullableInt($row, 'best_rank'),
            'first_place_count' => $nullableInt($row, 'first_place_count') ?? 0,
            'top3_count' => $nullableInt($row, 'top3_count') ?? 0,
            'top10_count' => $nullableInt($row, 'top10_count') ?? 0,
            'best_score' => $row['best_score'] === null || $row['best_score'] === '' ? null : (float)$row['best_score'],
            'current_member' => (bool)$row['current_member'],
            'account_state' => (string)$row['account_state'],
            'updated_at' => (string)$row['updated_at'],
        ];
    }

    private function publicSummary(array $currentPlayers): array
    {
        $counts = $this->accountCounts();
        $points = array_sum(array_map(static fn(array $row): float => (float)$row['points'], $currentPlayers));
        return [
            'players' => count($currentPlayers),
            'ranked_players' => count(array_filter($currentPlayers, static fn(array $row): bool => (float)$row['points'] >= 50)),
            'total_points' => round($points, 2),
            'current_members' => count($currentPlayers),
            'former_members' => (int)($counts['former_member'] ?? 0),
            'closed_accounts' => (int)($counts['closed_account'] ?? 0),
            'possible_renames' => (int)($counts['possible_renamed'] ?? 0),
            'pending_checks' => (int)($counts['pending_profile'] ?? 0),
        ];
    }

    private function summary(array $players): array
    {
        $states = [];
        $points = 0.0;
        foreach ($players as $player) {
            $states[$player['account_state']] = ($states[$player['account_state']] ?? 0) + 1;
            $points += (float)$player['points'];
        }
        return [
            'players' => count($players),
            'ranked_players' => count(array_filter($players, static fn(array $row): bool => (float)$row['points'] >= 50)),
            'total_points' => round($points, 2),
            'current_members' => (int)($states['current_member'] ?? 0),
            'former_members' => (int)($states['former_member'] ?? 0),
            'closed_accounts' => (int)($states['closed_account'] ?? 0),
            'possible_renames' => (int)($states['possible_renamed'] ?? 0),
            'pending_checks' => (int)($states['pending_profile'] ?? 0),
        ];
    }

    private function storedFileRecords(): array
    {
        $query = $this->pdo->prepare('SELECT * FROM p2k_lr_files WHERE club_slug=? ORDER BY id');
        $query->execute([$this->clubSlug]);
        return $query->fetchAll() ?: [];
    }

    private function currentMemberMap(): array
    {
        $payload = $this->api->json('https://api.chess.com/pub/club/' . rawurlencode($this->clubSlug) . '/members', true);
        $members = [];
        foreach (['weekly', 'monthly', 'all_time'] as $bucket) {
            foreach ((array)($payload[$bucket] ?? []) as $entry) {
                $username = is_array($entry) ? trim((string)($entry['username'] ?? '')) : trim((string)$entry);
                if ($username !== '') $members[\p2k_tp_username_key($username)] = $username;
            }
        }
        if ($members === []) throw new RetryableException('Chess.com returned an empty P2K member list; processing was not committed.', 60);
        return $members;
    }

    private function pendingProfileCount(): int
    {
        $query = $this->pdo->prepare("SELECT COUNT(*) FROM p2k_lr_players WHERE club_slug=? AND account_state='pending_profile'");
        $query->execute([$this->clubSlug]);
        return (int)$query->fetchColumn();
    }

    private function accountCounts(): array
    {
        $query = $this->pdo->prepare('SELECT account_state,COUNT(*) AS total FROM p2k_lr_players WHERE club_slug=? GROUP BY account_state');
        $query->execute([$this->clubSlug]);
        $result = [];
        foreach ($query->fetchAll() ?: [] as $row) $result[(string)$row['account_state']] = (int)$row['total'];
        return $result;
    }

    private function rankFor(float $points): ?array
    {
        $chosen = null;
        foreach (self::thresholds() as $rank) if ($points >= (float)$rank['minimum']) $chosen = $rank;
        return $chosen;
    }

    private function isClosedProfile(array $profile): bool
    {
        $values = [];
        foreach (['status', 'account_status', 'closed_reason', 'reason'] as $field) $values[] = strtolower(trim((string)($profile[$field] ?? '')));
        $joined = implode(' ', $values);
        return str_contains($joined, 'closed') || str_contains($joined, 'fair_play') || str_contains($joined, 'fair play') || str_contains($joined, 'abuse');
    }

    private function parseCsvFile(string $storedName): array
    {
        $path = $this->storageDir . '/' . basename($storedName);
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new \RuntimeException('Unable to read stored CSV file ' . basename($storedName));
        $first = fgets($handle);
        if ($first === false) { fclose($handle); throw new \RuntimeException('CSV file is empty.'); }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first) ?? $first;
        $delimiter = $this->detectDelimiter($first);
        rewind($handle);
        $headers = fgetcsv($handle, 0, $delimiter);
        if (!is_array($headers)) { fclose($handle); throw new \RuntimeException('CSV header could not be read.'); }
        $headers = array_map(static fn($value): string => strtolower(trim(preg_replace('/\s+/u', ' ', (string)$value) ?? (string)$value)), $headers);
        $positions = [];
        foreach ($headers as $index => $header) if ($header !== '') $positions[$header] = $index;
        foreach (['username', 'club', 'score'] as $required) {
            if (!array_key_exists($required, $positions)) { fclose($handle); throw new \RuntimeException("CSV column {$required} is missing."); }
        }
        $optional = [
            'games' => $this->firstHeaderPosition($positions, ['games', 'games played', 'total games', 'number of games']),
            'wins' => $this->firstHeaderPosition($positions, ['wins', 'games won', 'total wins', 'most wins']),
            'draws' => $this->firstHeaderPosition($positions, ['draws', 'games drawn', 'total draws']),
            'losses' => $this->firstHeaderPosition($positions, ['losses', 'games lost', 'total losses']),
            'streak' => $this->firstHeaderPosition($positions, ['longest streak', 'best streak', 'streak']),
            'max_wins' => $this->firstHeaderPosition($positions, ['most wins', 'maximum wins', 'max wins', 'highest wins']),
            'max_games' => $this->firstHeaderPosition($positions, ['most games', 'most games played', 'maximum games', 'max games']),
            'rank' => $this->firstHeaderPosition($positions, ['rank', 'place', 'placement']),
        ];
        $players = [];
        $rows = 0;
        $p2kRows = 0;
        $sourceRows = [];
        $sourceRowNo = 1;
        while (($line = fgetcsv($handle, 0, $delimiter)) !== false) {
            $sourceRowNo++;
            if ($line === [null] || $line === []) continue;
            $rows++;
            $club = $this->normalizeClub((string)($line[$positions['club']] ?? ''));
            if ($club !== 'promote to king') continue;
            $username = trim((string)($line[$positions['username']] ?? ''));
            if ($username === '') continue;
            $score = $this->optionalNumber($line, $positions['score']);
            if ($score === null) continue;
            $games = $this->optionalInteger($line, $optional['games']);
            $wins = $this->optionalInteger($line, $optional['wins']);
            $draws = $this->optionalInteger($line, $optional['draws']);
            $losses = $this->optionalInteger($line, $optional['losses']);
            $streak = $this->optionalInteger($line, $optional['streak']);
            $maxWins = $this->optionalInteger($line, $optional['max_wins']);
            $maxGames = $this->optionalInteger($line, $optional['max_games']);
            $rank = $this->optionalInteger($line, $optional['rank']);
            if ($games === null && $wins !== null && $draws !== null && $losses !== null) {
                $games = $wins + $draws + $losses;
            }
            $key = \p2k_tp_username_key($username);
            $sourceRows[]=['source_row_no'=>$sourceRowNo,'username_key'=>$key,'username'=>$username,'score'=>$score,'games'=>$games,'wins'=>$wins,'draws'=>$draws,'losses'=>$losses,'streak'=>$streak,'max_wins'=>$maxWins,'max_games'=>$maxGames,'rank'=>$rank];
            if (!isset($players[$key])) {
                $players[$key] = ['username' => $username, 'score' => 0.0, 'games' => null, 'wins' => null, 'draws' => null, 'losses' => null, 'streak' => null, 'max_wins' => null, 'max_games' => null, 'rank' => null];
            }
            $players[$key]['score'] += $score;
            foreach (['games' => $games, 'wins' => $wins, 'draws' => $draws, 'losses' => $losses] as $metric => $value) {
                if ($value !== null) $players[$key][$metric] = (int)($players[$key][$metric] ?? 0) + $value;
            }
            if ($streak !== null) $players[$key]['streak'] = max((int)($players[$key]['streak'] ?? 0), $streak);
            if ($maxWins !== null) $players[$key]['max_wins'] = max((int)($players[$key]['max_wins'] ?? 0), $maxWins);
            if ($maxGames !== null) $players[$key]['max_games'] = max((int)($players[$key]['max_games'] ?? 0), $maxGames);
            if ($rank !== null && $rank > 0) $players[$key]['rank'] = $players[$key]['rank'] === null ? $rank : min((int)$players[$key]['rank'], $rank);
            $p2kRows++;
        }
        fclose($handle);
        $arenaPoints = 0.0; $first = 0; $second = 0; $third = 0;
        foreach ($players as $player) {
            $arenaPoints += (float)$player['score'];
            $rank = $player['rank'];
            if ($rank === 1) $first++;
            elseif ($rank === 2) $second++;
            elseif ($rank === 3) $third++;
        }
        return [
            'rows' => $rows, 'p2k_rows' => $p2kRows, 'players' => $players, 'source_rows'=>$sourceRows,
            'arena' => ['participants' => count($players), 'points' => round($arenaPoints, 2), 'first' => $first, 'second' => $second, 'third' => $third],
        ];
    }

    private function firstHeaderPosition(array $positions, array $aliases): ?int
    {
        foreach ($aliases as $alias) if (array_key_exists($alias, $positions)) return (int)$positions[$alias];
        return null;
    }

    private function optionalNumber(array $line, ?int $position): ?float
    {
        if ($position === null) return null;
        $text = trim((string)($line[$position] ?? ''));
        if ($text === '') return null;
        if (str_contains($text, ',') && !str_contains($text, '.')) $text = str_replace(',', '.', $text);
        return is_numeric($text) ? (float)$text : null;
    }

    private function optionalInteger(array $line, ?int $position): ?int
    {
        $value = $this->optionalNumber($line, $position);
        return $value === null ? null : max(0, (int)round($value));
    }

    private function detectDelimiter(string $line): string
    {
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);
        $delimiter = (string)array_key_first($counts);
        return ($counts[$delimiter] ?? 0) > 0 ? $delimiter : ',';
    }

    private function normalizeClub(string $club): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $club) ?? $club));
    }

    /** IPDR: detect a definitive one-to-one username substitution across two versions of the same arena source. */
    private function recordHistoricalArenaSubstitution(string $oldStored,string $newStored,string $originalName,string $oldHash,string $newHash): ?array
    {
        $old=$this->parseCsvFile($oldStored);$new=$this->parseCsvFile($newStored);
        $fingerprint=static function(array $row):string{return hash('sha256',json_encode([(float)$row['score'],$row['games'],$row['wins'],$row['draws'],$row['losses'],$row['streak'],$row['max_wins'],$row['max_games'],$row['rank']],JSON_PRESERVE_ZERO_FRACTION));};
        $build=static function(array $rows) use($fingerprint):array{$out=[];$amb=[];foreach($rows as $r){$k=(string)$r['username_key'];if(isset($out[$k])){$amb[$k]=true;continue;}$out[$k]=['username'=>(string)$r['username'],'fingerprint'=>$fingerprint($r)];}foreach($amb as $k=>$_)unset($out[$k]);return [$out,$amb];};
        [$a,$ambA]=$build($old['source_rows']??[]);[$b,$ambB]=$build($new['source_rows']??[]);if($ambA||$ambB)return null;
        $gone=array_values(array_diff(array_keys($a),array_keys($b)));$appeared=array_values(array_diff(array_keys($b),array_keys($a)));if(count($gone)!==1||count($appeared)!==1)return null;
        $oldKey=$gone[0];$newKey=$appeared[0];$unchanged=array_values(array_intersect(array_keys($a),array_keys($b)));foreach($unchanged as $k)if($a[$k]['fingerprint']!==$b[$k]['fingerprint'])return null;
        if($a[$oldKey]['fingerprint']!==$b[$newKey]['fingerprint'])return null;
        $miac=new MiacService($this->repository->core(),$this->clubSlug);
        return $miac->recordDefinitiveSubstitution($a[$oldKey]['username'],$b[$newKey]['username'],'mca_substitution',['arena_source'=>$originalName,'old_source_sha256'=>$oldHash,'new_source_sha256'=>$newHash,'stable_fingerprint'=>$a[$oldKey]['fingerprint'],'old_username_key'=>$oldKey,'new_username_key'=>$newKey,'unchanged_participants'=>count($unchanged),'one_to_one_substitution'=>true]);
    }

    private function storeUpload(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new ApiException('Upload failed with code ' . $error . '.', 400, 'UPLOAD_FAILED');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) throw new ApiException('The uploaded file could not be validated.', 400, 'INVALID_UPLOAD');
        $original = basename(trim((string)($file['name'] ?? '')));
        if ($original === '' || !preg_match('/\.csv$/i', $original)) throw new ApiException('Only CSV files are accepted.', 400, 'CSV_REQUIRED');
        $size = (int)($file['size'] ?? filesize($tmp) ?: 0);
        if ($size <= 0 || $size > 20 * 1024 * 1024) throw new ApiException('Each CSV file must be between 1 byte and 20 MB.', 400, 'INVALID_FILE_SIZE');
        $hash = hash_file('sha256', $tmp);
        if ($hash === false) throw new \RuntimeException('Unable to checksum the uploaded CSV.');
        // The original CSV filename is the arena identity. Re-uploading that
        // filename always replaces the previous copy, even when the bytes are
        // identical. Different filenames remain distinct pack entries.
        $existing = $this->pdo->prepare('SELECT id,stored_name,sha256 FROM p2k_lr_files WHERE club_slug=? AND original_name=? LIMIT 1');
        $existing->execute([$this->clubSlug, $original]);
        $existingRow = $existing->fetch();
        if (!is_array($existingRow)) {
            $count = $this->pdo->prepare('SELECT COUNT(*) FROM p2k_lr_files WHERE club_slug=?');
            $count->execute([$this->clubSlug]);
            if ((int)$count->fetchColumn() >= $this->maxUploadFiles) {
                throw new ApiException('The configured Live Ranks source-file limit has been reached. Replace an existing filename or raise the storage limit deliberately.', 409, 'LIVE_RANKS_FILE_LIMIT');
            }
        }
        $stored = bin2hex(random_bytes(12)) . '.csv';
        $destination = $this->storageDir . '/' . $stored;
        if (!move_uploaded_file($tmp, $destination)) throw new \RuntimeException('Unable to store the uploaded CSV.');
        @chmod($destination, 0660);
        if (is_array($existingRow)) {
            // Compare while both immutable byte versions still exist and persist definitive evidence before DB/file replacement.
            try{$this->recordHistoricalArenaSubstitution((string)$existingRow['stored_name'],$stored,$original,(string)($existingRow['sha256']??''),$hash);}catch(\Throwable $e){@unlink($destination);throw $e;}
            $update = $this->pdo->prepare(
                "UPDATE p2k_lr_files SET stored_name=?,sha256=?,size_bytes=?,uploaded_at=UTC_TIMESTAMP(),replaced_at=UTC_TIMESTAMP(),status='uploaded',row_count=0,p2k_row_count=0,processed_at=NULL,last_error=NULL WHERE id=?"
            );
            $update->execute([$stored, $hash, $size, (int)$existingRow['id']]);
            $this->recomputeEventDates();
            @unlink($this->storageDir . '/' . basename((string)$existingRow['stored_name']));
            return ['replaced' => true, 'id' => (int)$existingRow['id'], 'name' => $original, 'sha256' => $hash, 'size' => $size];
        }
        $insert = $this->pdo->prepare(
            "INSERT INTO p2k_lr_files(club_slug,original_name,stored_name,sha256,size_bytes,uploaded_at,status,row_count,p2k_row_count)
             VALUES(?,?,?,?,?,UTC_TIMESTAMP(),'uploaded',0,0)"
        );
        $insert->execute([$this->clubSlug, $original, $stored, $hash, $size]);
        $id=(int)$this->pdo->lastInsertId();$this->recomputeEventDates();
        return ['replaced' => false, 'id' => $id, 'name' => $original, 'sha256' => $hash, 'size' => $size];
    }

    private function invalidateProcessingAfterUpload(): void
    {
        $this->pdo->beginTransaction();
        try {
            // Any changed arena file invalidates the aggregate computed from the
            // complete pack. Clear published rows rather than serving stale points.
            $this->pdo->prepare('DELETE FROM p2k_lr_players WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->prepare('DELETE FROM p2k_lr_arena_stats WHERE club_slug=?')->execute([$this->clubSlug]);
            $state = $this->pdo->prepare(
                "INSERT INTO p2k_lr_processing_state(
                    club_slug,status,phase,total_files,processed_files,total_players,checked_players,
                    possible_renamed,closed_accounts,started_at,updated_at,finished_at,last_error
                 ) VALUES(?,'idle','files_changed',0,0,0,0,0,0,NULL,UTC_TIMESTAMP(),NULL,NULL)
                 ON DUPLICATE KEY UPDATE status='idle',phase='files_changed',total_files=0,processed_files=0,
                    total_players=0,checked_players=0,possible_renamed=0,closed_accounts=0,started_at=NULL,
                    updated_at=UTC_TIMESTAMP(),finished_at=NULL,last_error=NULL"
            );
            $state->execute([$this->clubSlug]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function processingState(): array
    {
        $query = $this->pdo->prepare('SELECT * FROM p2k_lr_processing_state WHERE club_slug=? LIMIT 1');
        $query->execute([$this->clubSlug]);
        $row = $query->fetch();
        return is_array($row) ? $row : [];
    }

    private function normalizeUploadArray(array $files): array
    {
        if (!isset($files['name'])) return [];
        if (!is_array($files['name'])) return [$files];
        $rows = [];
        foreach ($files['name'] as $index => $name) {
            $rows[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }
        return $rows;
    }
}
