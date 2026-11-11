<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Bounded original-vs-fresh Chess.com Results CSV comparison for missing MCA names.
 *
 * A positional substitution is conclusive only if the entire participant
 * sequence is unchanged except for one username, and the target row's rank,
 * club, score and available performance counters agree. Other outcomes are
 * diagnostic, never automatic identity merges. Existing raw source remains
 * untouched; MIAC remains the identity authority and enforces player_id conflicts
 * and previously rejected edges.
 */
final class McaRenameRecheckService
{
    private readonly MiacService $miac;
    private readonly string $storageDir;
    private readonly string $stateFile;

    public function __construct(
        private readonly PDO $core,
        private readonly PDO $analytics,
        private readonly string $clubSlug
    ) {
        $this->miac = new MiacService($core, $clubSlug);
        $configured = trim((string)(\p2k_tp_config()['app']['live_ranks_upload_dir'] ?? ''));
        $this->storageDir = $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 3).'/data/live-ranks/uploads';
        $this->stateFile = dirname($this->storageDir).'/.mca-rename-rechecks-'.$clubSlug.'.json';
    }

    /** Read-only: never downloads anything on dashboard rendering. */
    public function status(): array
    {
        $ledger = $this->readState();
        $counts = [];
        $byName = [];
        $recent = [];
        foreach (($ledger['checks'] ?? []) as $key => $item) {
            if (!is_array($item)) continue;
            $status = (string)($item['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $name = (string)($item['old_username'] ?? '');
            if ($name !== '') {
                $previous = $byName[$name] ?? null;
                if (!is_array($previous) || (string)($item['checked_at'] ?? '') > (string)($previous['checked_at'] ?? '')) {
                    $byName[$name] = $item;
                }
            }
            $recent[] = $item;
        }
        usort($recent, static fn(array $a, array $b): int => strcmp((string)($b['checked_at'] ?? ''), (string)($a['checked_at'] ?? '')));
        return [
            'ok' => true, 'checked_sources' => count($ledger['checks'] ?? []),
            'counts' => $counts, 'by_username' => $byName,
            'recent' => array_slice($recent, 0, 15),
            'last_step_at' => $ledger['last_step_at'] ?? null,
            'automatic' => true,
            'policy' => 'exact_one_username_substitution_plus_matching_row_facts',
        ];
    }

    /** One bounded network fetch per call, shared with the existing MCA 1 req/sec lock. */
    public function runStep(int $maxSeconds = 8): array
    {
        if (PreviewIsolation::active()) return ['ok'=>true,'skipped'=>'preview_read_only'] + $this->status();
        $maxSeconds = max(3, min(10, $maxSeconds));
        $this->ensurePrivateStorage();
        $lock = fopen(dirname($this->stateFile).'/.mca-rename-rechecks.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return ['ok'=>true,'skipped'=>'another_recheck_running'] + $this->status();
        }
        $globalLock = false;
        try {
            $q=$this->analytics->prepare('SELECT GET_LOCK(?,0)');
            $q->execute(['p2k:mca-sync:'.substr($this->clubSlug,0,40)]);
            $globalLock = (int)$q->fetchColumn() === 1;
            if (!$globalLock) return ['ok'=>true,'skipped'=>'mca_acquisition_running'] + $this->status();

            $ledger = $this->readState();
            $task = $this->selectTask($ledger);
            if ($task === null) return ['ok'=>true,'skipped'=>'no_due_missing_username'] + $this->status();

            $key = $task['username_key'].'|'.$task['arena_id'];
            $checkedAt = gmdate(DATE_ATOM);
            try {
                $comparison = $this->compareStoredWithLive($task, $maxSeconds);
                $record = [
                    'old_username'=>$task['username_key'], 'arena_id'=>$task['arena_id'],
                    'source_file_id'=>$task['file_id'], 'checked_at'=>$checkedAt,
                    'status'=>$comparison['status'], 'message'=>$comparison['message'],
                    'new_username'=>$comparison['new_username'] ?? null,
                    'old_source_sha256'=>$task['sha256'],
                    'new_source_sha256'=>$comparison['remote_sha256'] ?? null,
                    'next_after'=>time() + ($comparison['status']==='unchanged' ? 7*86400 : 3*86400),
                ];
                if ($comparison['status']==='correlated') {
                    // MIAC is the sole identity authority. It retains vetoes
                    // and refuses contradictory verified Chess.com player_ids.
                    $result = $this->miac->recordDefinitiveSubstitution(
                        $comparison['old_username'], $comparison['new_username'],
                        'mca_substitution', [
                            'arena_id'=>$task['arena_id'],
                            'source_file_id'=>$task['file_id'],
                            'old_csv_sha256'=>$task['sha256'],
                            'new_csv_sha256'=>$comparison['remote_sha256'],
                            'old_row'=>$comparison['old_row'],
                            'new_row'=>$comparison['new_row'],
                            'position'=>$comparison['position'],
                            'match_policy'=>'full_sequence_one_name_change_same_rank_club_score_and_performance',
                            'original_source_unchanged'=>true,
                        ]
                    );
                    $record['miac_status'] = $result['status'];
                    $record['status'] = match ($result['status']) {
                        'confirmed' => 'linked',
                        'rejected' => 'previously_rejected',
                        'conflict' => 'identity_conflict',
                        default => 'needs_review',
                    };
                    $record['next_after'] = time()+365*86400;
                    $record['message'] = 'Fresh Chess.com CSV contains one consistent positional substitution; MIAC: '.$result['status'].'.';
                }
            } catch (Throwable $e) {
                $record = [
                    'old_username'=>$task['username_key'], 'arena_id'=>$task['arena_id'],
                    'source_file_id'=>$task['file_id'], 'checked_at'=>$checkedAt,
                    'status'=>'fetch_error','message'=>substr($e->getMessage(),0,300),
                    'new_username'=>null,'next_after'=>time()+3600,
                ];
            }
            $ledger['checks'][$key] = $record;
            $ledger['last_step_at'] = $checkedAt;
            $this->saveState($ledger);
            return ['ok'=>true,'checked'=>$record] + $this->status();
        } finally {
            if ($globalLock) {
                try { $q=$this->analytics->prepare('SELECT RELEASE_LOCK(?)');$q->execute(['p2k:mca-sync:'.substr($this->clubSlug,0,40)]); } catch (Throwable) {}
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function selectTask(array $ledger): ?array
    {
        // Only verified 404/410 cases from MCA profile checks enter this queue.
        // Use normalized source rows so we examine only arenas containing the
        // unavailable username; never re-fetch the full 688-arena inventory.
        $q=$this->analytics->prepare(
            "SELECT p.username_key, r.file_id, f.arena_id, f.arena_slug, f.original_name,
                    f.stored_name, f.sha256, f.status
             FROM p2k_lr_players p
             JOIN p2k_lr_source_rows r ON r.club_slug=p.club_slug AND r.raw_username_key=p.username_key
             JOIN p2k_lr_files f ON f.club_slug=r.club_slug AND f.id=r.file_id
             WHERE p.club_slug=? AND p.account_state='possible_renamed'
               AND f.status='processed' AND f.arena_id>0
             GROUP BY p.username_key,r.file_id,f.arena_id,f.arena_slug,
                      f.original_name,f.stored_name,f.sha256,f.status
             ORDER BY f.arena_id DESC, p.username_key ASC LIMIT 3000"
        );
        $q->execute([$this->clubSlug]);
        $now=time();
        $resolved=[];
        $miacQ=$this->core->prepare(
            "SELECT COUNT(*) FROM p2k_miac_edges
             WHERE club_slug=? AND (old_username_key=? OR new_username_key=?) AND status='confirmed'"
        );
        $mappedQ=$this->core->prepare(
            "SELECT canonical_username_key,conflict FROM p2k_miac_canonical_map
             WHERE club_slug=? AND username_key=? LIMIT 1"
        );
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $old=(string)$row['username_key'];
            if (!isset($resolved[$old])) {
                $miacQ->execute([$this->clubSlug,$old,$old]);
                $hasEdge=(int)$miacQ->fetchColumn()>0;
                $mappedQ->execute([$this->clubSlug,$old]);
                $map=$mappedQ->fetch(PDO::FETCH_ASSOC) ?: [];
                $resolved[$old] = $hasEdge || (empty($map['conflict']) && !empty($map['canonical_username_key']) && $map['canonical_username_key']!==$old);
            }
            if ($resolved[$old]) continue;
            $id=(int)$row['arena_id'];
            $key=$old.'|'.$id;
            $last=$ledger['checks'][$key] ?? null;
            if (is_array($last) && (int)($last['next_after'] ?? 0)>$now) continue;
            $row['arena_id']=$id;
            return $row;
        }
        return null;
    }

    private function compareStoredWithLive(array $file, int $maxSeconds): array
    {
        $identity = McaSourceCatalogue::identityFromRow($file);
        if ($identity === null || (int)$identity['arena_id'] !== (int)$file['arena_id']) {
            throw new RuntimeException('Stored arena filename and metadata disagree; refusing CSV comparison.');
        }
        $slug=(string)$identity['arena_slug'];
        if (!preg_match('/^[a-z0-9][a-z0-9-]{2,180}$/D', $slug)
            || !str_ends_with($slug, '-'.(int)$file['arena_id'])) {
            throw new RuntimeException('Invalid arena slug; refusing external download.');
        }
        $path=$this->storageDir.'/'.basename((string)$file['stored_name']);
        $oldCsv=@file_get_contents($path);
        if (!is_string($oldCsv) || $oldCsv==='') throw new RuntimeException('Original canonical MCA CSV is unavailable.');
        if (hash('sha256',$oldCsv)!==strtolower((string)$file['sha256'])) {
            throw new RuntimeException('Stored MCA CSV checksum does not match the registered source.');
        }

        $this->waitForMcaRequestSlot();
        $url='https://www.chess.com/tournament/live/arena/'.$slug.'/download-results';
        $ch=curl_init($url);
        if ($ch===false) throw new RuntimeException('Unable to initialize Chess.com CSV request.');
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>3,
            CURLOPT_CONNECTTIMEOUT=>min(4,$maxSeconds),CURLOPT_TIMEOUT=>min(7,$maxSeconds),
            CURLOPT_USERAGENT=>'PromoteToKing/2.15.8 MCA Rename Recheck (+https://www.promotetoking.org)',
            CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>[
                'Accept: text/csv,text/plain;q=0.9,*/*;q=0.3',
                'Accept-Language: en-US,en;q=0.8','Cache-Control: no-cache',
            ],
        ]);
        $latest=curl_exec($ch);
        $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
        $effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);
        $error=curl_error($ch);
        curl_close($ch);
        if (!is_string($latest)) throw new RuntimeException('Chess.com Results download failed: '.$error);
        if ($status!==200) throw new RuntimeException('Chess.com Results download returned HTTP '.$status);
        if (!str_starts_with($effective,'https://www.chess.com/tournament/live/arena/')) {
            throw new RuntimeException('Chess.com redirected Results outside the expected tournament path.');
        }
        if (strlen($latest)>15*1024*1024 || strlen($latest)<50) {
            throw new RuntimeException('Chess.com Results response has unexpected size.');
        }
        $old=McaArenaParser::resultsCsv($oldCsv);
        $new=McaArenaParser::resultsCsv($latest);
        if (empty($old['valid']) || empty($new['valid'])
            || empty($old['players']) || empty($new['players'])) {
            throw new RuntimeException('One of the two MCA CSVs is not a valid Player Results export.');
        }
        $comparison=self::correlateRows($old['players'],$new['players'],(string)$file['username_key']);
        $comparison['remote_sha256']=hash('sha256',$latest);
        return $comparison;
    }

    /**
     * Pure comparison function, exercised against realistic before/after CSVs.
     * Single changed username, exactly same row position, rank/club/score and
     * optional W/D/L. All other names must remain at their previous positions.
     */
    public static function correlateRows(array $original,array $fresh,string $missingUsername): array
    {
        $old=strtolower(trim($missingUsername));
        $n=count($original);
        if ($n!==count($fresh) || $n<1) {
            return ['status'=>'ambiguous','message'=>'CSV participant count changed; positional substitution cannot be proven.'];
        }
        $changed=[];
        $oldNames=[];$newNames=[];
        foreach ($original as $i=>$before) {
            $after=$fresh[$i]??null;
            if (!is_array($before) || !is_array($after)) {
                return ['status'=>'ambiguous','message'=>'Invalid participant row.'];
            }
            $a=strtolower(trim((string)($before['username']??'')));
            $b=strtolower(trim((string)($after['username']??'')));
            if ($a==='' || $b==='') return ['status'=>'ambiguous','message'=>'Missing username in participant list.'];
            $oldNames[]=$a;$newNames[]=$b;
            if ($a!==$b) $changed[]=$i;
        }
        if (count($changed)===0) return ['status'=>'unchanged','message'=>'Fresh CSV still contains the same usernames.'];
        if (count($changed)!==1) {
            return ['status'=>'ambiguous','message'=>'More than one username or participant position changed; manual review required.'];
        }
        $index=$changed[0];
        $before=$original[$index];$after=$fresh[$index];
        $a=strtolower(trim((string)$before['username']));
        $b=strtolower(trim((string)$after['username']));
        if ($a!==$old || $b===$old
            || count(array_filter($oldNames,static fn(string $v):bool=>$v===$a))!==1
            || count(array_filter($newNames,static fn(string $v):bool=>$v===$b))!==1
            || in_array($b,$oldNames,true) || in_array($a,$newNames,true)) {
            return ['status'=>'ambiguous','message'=>'Substitution does not uniquely replace this missing username.'];
        }
        $rankBefore=(int)($before['rank']??0);$rankAfter=(int)($after['rank']??0);
        $clubBefore=strtolower(trim((string)($before['club']??'')));
        $clubAfter=strtolower(trim((string)($after['club']??'')));
        $scoreBefore=$before['score']??null;$scoreAfter=$after['score']??null;
        if ($rankBefore<=0 || $rankBefore!==$rankAfter || $clubBefore==='' || $clubBefore!==$clubAfter
            || $scoreBefore===null || $scoreAfter===null
            || abs((float)$scoreBefore-(float)$scoreAfter)>0.000001) {
            return ['status'=>'ambiguous','message'=>'Username position changed but rank, club or score was not identical.'];
        }
        foreach (['wins','draws','losses','streak','most_wins'] as $metric) {
            if (($before[$metric]??null)!==null && ($after[$metric]??null)!==null
                && (int)$before[$metric] !== (int)$after[$metric]) {
                return ['status'=>'ambiguous','message'=>'Username position changed but '.$metric.' statistics differ.'];
            }
        }
        return [
            'status'=>'correlated','message'=>'One exact username substitution at the same tournament position.',
            'old_username'=>(string)$before['username'],'new_username'=>(string)$after['username'],
            'position'=>$index+1,
            'old_row'=>[
                'rank'=>$rankBefore,'club'=>(string)$before['club'],'score'=>(float)$scoreBefore,
                'wins'=>$before['wins']??null,'draws'=>$before['draws']??null,'losses'=>$before['losses']??null
            ],
            'new_row'=>[
                'rank'=>$rankAfter,'club'=>(string)$after['club'],'score'=>(float)$scoreAfter,
                'wins'=>$after['wins']??null,'draws'=>$after['draws']??null,'losses'=>$after['losses']??null
            ],
        ];
    }

    private function waitForMcaRequestSlot(): void
    {
        $q=$this->analytics->prepare('SELECT last_request_at FROM p2k_lr_sync_state WHERE club_slug=? LIMIT 1');
        $q->execute([$this->clubSlug]);
        $last=(string)($q->fetchColumn() ?: '');
        if ($last!=='') {
            $stamp=(float)(strtotime(substr($last,0,19).' UTC') ?: 0);
            if (preg_match('/\.(\d+)/',$last,$m)) $stamp+=(float)('0.'.substr($m[1],0,6));
            $elapsed=microtime(true)-$stamp;
            if ($elapsed<1.0) usleep((int)ceil((1.0-$elapsed)*1000000));
        }
        $update=$this->analytics->prepare('UPDATE p2k_lr_sync_state SET last_request_at=UTC_TIMESTAMP(6),request_count=request_count+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?');
        $update->execute([$this->clubSlug]);
    }

    private function ensurePrivateStorage(): void
    {
        $dir=dirname($this->stateFile);
        if (!is_dir($dir) && !mkdir($dir,0770,true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to open protected MCA rename-recheck state directory.');
        }
        $deny=$dir.'/.htaccess';
        if (!is_file($deny)) file_put_contents($deny,"Require all denied\nDeny from all\n",LOCK_EX);
    }

    private function readState(): array
    {
        if (!is_file($this->stateFile)) return ['schema'=>1,'checks'=>[]];
        $raw=@file_get_contents($this->stateFile);
        $obj=is_string($raw)?json_decode($raw,true):null;
        return is_array($obj) && is_array($obj['checks']??null) ? $obj : ['schema'=>1,'checks'=>[]];
    }

    private function saveState(array $ledger): void
    {
        $tmp=$this->stateFile.'.'.bin2hex(random_bytes(5)).'.tmp';
        $json=json_encode($ledger,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp,$json,LOCK_EX)===false || !rename($tmp,$this->stateFile)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to persist MCA rename-recheck audit state.');
        }
        @chmod($this->stateFile,0660);
    }
}
