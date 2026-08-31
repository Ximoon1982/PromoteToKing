<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * v2.10.9.6 Fair Play Team-Point Reconciliation.
 *
 * Chess.com can award team-match points after a fair-play closure while leaving the
 * underlying game result untouched. P2K therefore keeps result_code as the raw game
 * fact and stores the effective team-point correction in points_x2, with a durable
 * adjustment row explaining why. The match payload's fair_play_removals list is
 * consumed while a match is active, again on the first finished observation, and by
 * a resumable one-time historical backfill.
 */
final class FairPlayReconciliationService
{
    public const BACKFILL_VERSION = 1;

    public function __construct(
        private readonly PDO $core,
        private readonly Repository $repository,
        private readonly ChessApi $api,
        private readonly string $clubSlug
    ) {}

    public function ensureSchema(): void
    {
        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_tp_fair_play_match_state (
            club_slug VARCHAR(120) NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            removals_json LONGTEXT NOT NULL,
            source_status VARCHAR(24) NOT NULL DEFAULT 'unknown',
            checked_at DATETIME NOT NULL,
            finalized_at DATETIME NULL,
            backfill_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            affected_games INT UNSIGNED NOT NULL DEFAULT 0,
            corrected_games INT UNSIGNED NOT NULL DEFAULT 0,
            points_added_x2 INT NOT NULL DEFAULT 0,
            raw_score_x2 INT NULL,
            effective_score_x2 INT NULL,
            official_score_x2 INT NULL,
            mismatch_before_x2 INT NULL,
            mismatch_after_x2 INT NULL,
            last_error TEXT NULL,
            PRIMARY KEY (club_slug,match_id),
            KEY idx_tp_fp_match_backfill (club_slug,backfill_version,match_id),
            KEY idx_tp_fp_match_checked (club_slug,checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_tp_fair_play_game_adjustments (
            game_row_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            club_slug VARCHAR(120) NOT NULL,
            match_id BIGINT UNSIGNED NOT NULL,
            board_id BIGINT UNSIGNED NOT NULL,
            opponent_username VARCHAR(80) NOT NULL,
            raw_points_x2 TINYINT UNSIGNED NOT NULL,
            effective_points_x2 TINYINT UNSIGNED NOT NULL,
            applied_at DATETIME NOT NULL,
            last_verified_at DATETIME NOT NULL,
            KEY idx_tp_fp_adjust_match (club_slug,match_id),
            KEY idx_tp_fp_adjust_opponent (club_slug,opponent_username),
            CONSTRAINT fk_tp_fp_adjust_game FOREIGN KEY (game_row_id) REFERENCES p2k_tp_games(game_row_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $this->core->exec("CREATE TABLE IF NOT EXISTS p2k_tp_fair_play_backfill_state (
            club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
            status ENUM('running','paused','complete') NOT NULL DEFAULT 'running',
            cursor_match_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            checked_matches BIGINT UNSIGNED NOT NULL DEFAULT 0,
            matches_with_removals BIGINT UNSIGNED NOT NULL DEFAULT 0,
            affected_games BIGINT UNSIGNED NOT NULL DEFAULT 0,
            corrected_games BIGINT UNSIGNED NOT NULL DEFAULT 0,
            points_added_x2 BIGINT NOT NULL DEFAULT 0,
            mismatches_before BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mismatches_resolved BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mismatches_remaining BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_run_at DATETIME NULL,
            completed_at DATETIME NULL,
            last_error TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $q=$this->core->prepare("INSERT INTO p2k_tp_fair_play_backfill_state(club_slug,status) VALUES(?,'running') ON DUPLICATE KEY UPDATE club_slug=VALUES(club_slug)");
        $q->execute([$this->clubSlug]);
    }

    /** @return list<string> */
    public static function normalizeRemovalList(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        $out=[];
        foreach ($raw as $entry) {
            $username='';
            if (is_string($entry)) $username=$entry;
            elseif (is_array($entry)) {
                foreach (['username','name','player'] as $field) {
                    if (!empty($entry[$field]) && is_string($entry[$field])) { $username=(string)$entry[$field]; break; }
                }
                if ($username==='' && !empty($entry['@id']) && is_string($entry['@id'])) $username=(string)$entry['@id'];
                if ($username==='' && !empty($entry['url']) && is_string($entry['url'])) $username=(string)$entry['url'];
            }
            $username=trim($username);
            if ($username==='' ) continue;
            if (str_contains($username,'/')) {
                $path=(string)(parse_url($username,PHP_URL_PATH)?:'');
                $parts=array_values(array_filter(explode('/',trim($path,'/')),static fn(string $v):bool=>$v!==''));
                if ($parts!==[]) $username=rawurldecode((string)end($parts));
            }
            $key=p2k_tp_username_key($username);
            if ($key!=='' && preg_match('/^[a-z0-9_-]{1,80}$/i',$key)) $out[$key]=true;
        }
        $keys=array_keys($out);sort($keys,SORT_STRING);return $keys;
    }

    public static function rawPointsX2(string $resultCode): int
    {
        $result=strtolower(trim($resultCode));
        if ($result==='win') return 2;
        if (in_array($result,['agreed','repetition','stalemate','insufficient','50move','timevsinsufficient'],true)) return 1;
        return 0;
    }

    public function applyMatchPayload(int $matchId,array $match,bool $finalize=false,bool $backfill=false): array
    {
        $this->ensureSchema();
        if ($matchId<=0) return ['ran'=>false,'reason'=>'invalid_match'];
        $status=strtolower(trim((string)($match['status']??'unknown')));
        $eligibleStatus=in_array($status,['in_progress','ongoing','started','finished','complete','completed'],true);
        if (!$eligibleStatus) return ['ran'=>false,'reason'=>'status_not_eligible','status'=>$status];

        $incoming=self::normalizeRemovalList($match['fair_play_removals']??[]);
        $prior=$this->matchState($matchId);
        $stored=[];
        if (is_array($prior)) {
            $decoded=json_decode((string)($prior['removals_json']??'[]'),true);
            $stored=self::normalizeRemovalList(is_array($decoded)?$decoded:[]);
        }
        $removals=array_values(array_unique(array_merge($stored,$incoming)));
        sort($removals,SORT_STRING);
        $removed=array_fill_keys($removals,true);

        $games=$this->gamesForMatch($matchId);
        $affected=0;$corrected=0;$pointsAddedX2=0;$changed=false;
        $rawScoreX2=0;
        foreach($games as $game) {
            $raw=self::rawPointsX2((string)$game['result_code']);
            $rawScoreX2 += $raw;
            $opponent=p2k_tp_username_key((string)($game['opponent_username']??''));
            if ($opponent==='' || !isset($removed[$opponent])) continue;
            $affected++;
            $before=(int)$game['points_x2'];
            $effective=2;
            $adj=$this->core->prepare("INSERT INTO p2k_tp_fair_play_game_adjustments(game_row_id,club_slug,match_id,board_id,opponent_username,raw_points_x2,effective_points_x2,applied_at,last_verified_at)
                VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE opponent_username=VALUES(opponent_username),raw_points_x2=VALUES(raw_points_x2),effective_points_x2=VALUES(effective_points_x2),last_verified_at=UTC_TIMESTAMP()");
            $adj->execute([(int)$game['game_row_id'],$this->clubSlug,$matchId,(int)$game['board_id'],(string)$game['opponent_username'],$raw,$effective]);
            if ($before!==$effective) {
                $u=$this->core->prepare('UPDATE p2k_tp_games SET points_x2=?,verified_at=UTC_TIMESTAMP() WHERE game_row_id=?');
                $u->execute([$effective,(int)$game['game_row_id']]);
                if ($u->rowCount()>0) {$corrected++;$pointsAddedX2+=($effective-$before);$changed=true;}
            }
        }

        $effectiveScoreX2=$this->effectiveScoreX2($matchId);
        $officialScoreX2=$this->officialScoreX2($matchId,$match);
        $beforeMismatch=$officialScoreX2===null?null:$officialScoreX2-$rawScoreX2;
        $afterMismatch=$officialScoreX2===null?null:$officialScoreX2-$effectiveScoreX2;
        $finalized=$finalize || in_array($status,['finished','complete','completed'],true);
        $backfillVersion=$backfill?self::BACKFILL_VERSION:(int)($prior['backfill_version']??0);

        $q=$this->core->prepare("INSERT INTO p2k_tp_fair_play_match_state(club_slug,match_id,removals_json,source_status,checked_at,finalized_at,backfill_version,affected_games,corrected_games,points_added_x2,raw_score_x2,effective_score_x2,official_score_x2,mismatch_before_x2,mismatch_after_x2,last_error)
            VALUES(?,?,?,?,UTC_TIMESTAMP(),?, ?,?,?,?,?,?,?,?,?,NULL)
            ON DUPLICATE KEY UPDATE removals_json=VALUES(removals_json),source_status=VALUES(source_status),checked_at=UTC_TIMESTAMP(),finalized_at=COALESCE(finalized_at,VALUES(finalized_at)),backfill_version=GREATEST(backfill_version,VALUES(backfill_version)),affected_games=VALUES(affected_games),corrected_games=corrected_games+VALUES(corrected_games),points_added_x2=points_added_x2+VALUES(points_added_x2),raw_score_x2=VALUES(raw_score_x2),effective_score_x2=VALUES(effective_score_x2),official_score_x2=VALUES(official_score_x2),mismatch_before_x2=VALUES(mismatch_before_x2),mismatch_after_x2=VALUES(mismatch_after_x2),last_error=NULL");
        $q->execute([$this->clubSlug,$matchId,json_encode($removals,JSON_UNESCAPED_SLASHES),$status,$finalized?gmdate('Y-m-d H:i:s'):null,$backfillVersion,$affected,$corrected,$pointsAddedX2,$rawScoreX2,$effectiveScoreX2,$officialScoreX2,$beforeMismatch,$afterMismatch]);

        if ($changed) $this->touchGeneration();
        return [
            'ran'=>true,'match_id'=>$matchId,'status'=>$status,'fair_play_removals'=>$removals,
            'has_removals'=>$removals!==[],'affected_games'=>$affected,'corrected_games'=>$corrected,
            'points_added'=>$pointsAddedX2/2,'raw_score'=>$rawScoreX2/2,'effective_score'=>$effectiveScoreX2/2,
            'official_score'=>$officialScoreX2===null?null:$officialScoreX2/2,
            'mismatch_before'=>$beforeMismatch===null?null:$beforeMismatch/2,
            'mismatch_after'=>$afterMismatch===null?null:$afterMismatch/2,
            'finalized'=>$finalized,'backfilled'=>$backfill,
        ];
    }

    public function runStep(int $maxMatches=3,?float $deadline=null): array
    {
        $this->ensureSchema();
        $maxMatches=max(1,min(10,$maxMatches));
        $processed=0;$live=0;$backfilled=0;$corrected=0;$pointsAdded=0.0;$errors=[];

        $limit=min($maxMatches,3);
        $q=$this->core->prepare("SELECT m.match_id FROM p2k_tp_match_metadata m LEFT JOIN p2k_tp_fair_play_match_state f ON f.club_slug=m.club_slug AND f.match_id=m.match_id WHERE m.club_slug=? AND m.status='in_progress' ORDER BY COALESCE(f.checked_at,'1970-01-01') ASC,m.match_id ASC LIMIT {$limit}");
        $q->execute([$this->clubSlug]);
        foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id) {
            if ($deadline!==null && microtime(true)+1.5>=$deadline) break;
            try {
                $match=$this->api->json('https://api.chess.com/pub/match/'.(int)$id,true);
                $r=$this->applyMatchPayload((int)$id,$match,false,false);$processed++;$live++;$corrected+=(int)($r['corrected_games']??0);$pointsAdded+=(float)($r['points_added']??0);
            } catch(\Throwable $e) {$errors[]='match '.(int)$id.': '.$e->getMessage();}
        }

        if ($processed<$maxMatches && ($deadline===null || microtime(true)+1.5<$deadline)) {
            $state=$this->backfillState();
            if (($state['status']??'running')==='running') {
                $remaining=$maxMatches-$processed;
                $cursor=max(0,(int)($state['cursor_match_id']??0));
                $q=$this->core->prepare("SELECT m.match_id FROM p2k_tp_match_metadata m WHERE m.club_slug=? AND m.status='finished' AND m.match_id>? ORDER BY m.match_id ASC LIMIT {$remaining}");
                $q->execute([$this->clubSlug,$cursor]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
                if($ids===[]) $this->markBackfillComplete();
                foreach($ids as $id) {
                    if ($deadline!==null && microtime(true)+1.5>=$deadline) break;
                    try {
                        $match=$this->api->json('https://api.chess.com/pub/match/'.$id,true);
                        $r=$this->applyMatchPayload($id,$match,true,true);$processed++;$backfilled++;$corrected+=(int)($r['corrected_games']??0);$pointsAdded+=(float)($r['points_added']??0);
                        $this->advanceBackfill($id,$r);
                    } catch(\Throwable $e) {$errors[]='backfill '.$id.': '.$e->getMessage();$this->recordBackfillError($e->getMessage());break;}
                }
            }
        }

        return ['ran'=>true,'processed_matches'=>$processed,'live_matches_checked'=>$live,'finished_matches_backfilled'=>$backfilled,'corrected_games'=>$corrected,'points_added'=>$pointsAdded,'errors'=>$errors,'status'=>$this->status()];
    }

    public function status(): array
    {
        $this->ensureSchema();
        $state=$this->backfillState();
        $totalQ=$this->core->prepare("SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished'");$totalQ->execute([$this->clubSlug]);$total=(int)$totalQ->fetchColumn();
        $checkedQ=$this->core->prepare('SELECT COUNT(*) FROM p2k_tp_fair_play_match_state WHERE club_slug=? AND backfill_version>=?');$checkedQ->execute([$this->clubSlug,self::BACKFILL_VERSION]);$checked=(int)$checkedQ->fetchColumn();
        $remainingQ=$this->core->prepare('SELECT COUNT(*) FROM p2k_tp_fair_play_match_state WHERE club_slug=? AND mismatch_after_x2 IS NOT NULL AND mismatch_after_x2<>0');$remainingQ->execute([$this->clubSlug]);
        return $state+['backfill_version'=>self::BACKFILL_VERSION,'finished_matches_total'=>$total,'finished_matches_checked'=>$checked,'finished_matches_remaining'=>max(0,$total-$checked),'current_unresolved_score_mismatches'=>(int)$remainingQ->fetchColumn()];
    }

    public function control(string $action): array
    {
        $this->ensureSchema();$action=strtolower(trim($action));
        if($action==='pause'){$q=$this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET status='paused',last_error=NULL WHERE club_slug=?");$q->execute([$this->clubSlug]);}
        elseif(in_array($action,['start','resume'],true)){$q=$this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET status='running',completed_at=NULL,last_error=NULL WHERE club_slug=?");$q->execute([$this->clubSlug]);}
        elseif($action==='restart'){
            $this->core->beginTransaction();try{
                $q=$this->core->prepare('UPDATE p2k_tp_fair_play_match_state SET backfill_version=0 WHERE club_slug=?');$q->execute([$this->clubSlug]);
                $q=$this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET status='running',cursor_match_id=0,checked_matches=0,matches_with_removals=0,affected_games=0,corrected_games=0,points_added_x2=0,mismatches_before=0,mismatches_resolved=0,mismatches_remaining=0,last_run_at=NULL,completed_at=NULL,last_error=NULL WHERE club_slug=?");$q->execute([$this->clubSlug]);$this->core->commit();
            }catch(\Throwable $e){$this->core->rollBack();throw $e;}
        }
        return $this->status();
    }

    private function matchState(int $matchId): ?array
    {
        $q=$this->core->prepare('SELECT * FROM p2k_tp_fair_play_match_state WHERE club_slug=? AND match_id=? LIMIT 1');$q->execute([$this->clubSlug,$matchId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }

    private function gamesForMatch(int $matchId): array
    {
        $q=$this->core->prepare("SELECT g.game_row_id,g.board_id,g.result_code,g.points_x2,b.opponent_username FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND b.match_id=? ORDER BY b.board_no,g.sequence_no");
        $q->execute([$this->clubSlug,$matchId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function effectiveScoreX2(int $matchId): int
    {
        $q=$this->core->prepare("SELECT COALESCE(SUM(g.points_x2),0) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND b.match_id=?");$q->execute([$this->clubSlug,$matchId]);return (int)$q->fetchColumn();
    }

    private function officialScoreX2(int $matchId,array $match): ?int
    {
        foreach(is_array($match['teams']??null)?$match['teams']:[] as $team){
            if(!is_array($team))continue;$isClub=false;
            foreach(['@id','url'] as $field){$value=rtrim(strtolower(trim((string)($team[$field]??''))),'/');$path=trim((string)(parse_url($value,PHP_URL_PATH)?:''),'/');$parts=$path===''?[]:explode('/',$path);if($value==="https://api.chess.com/pub/club/{$this->clubSlug}"||($parts!==[]&&strtolower((string)end($parts))===$this->clubSlug)){$isClub=true;break;}}
            if(!$isClub)continue;foreach(['score','points','team_score'] as $field)if(is_numeric($team[$field]??null))return (int)round((float)$team[$field]*2);
        }
        $q=$this->core->prepare('SELECT p2k_score FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');$q->execute([$this->clubSlug,$matchId]);$v=$q->fetchColumn();return is_numeric($v)?(int)round((float)$v*2):null;
    }

    private function touchGeneration(): void
    {
        $q=$this->core->prepare('UPDATE p2k_tp_state SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?');$q->execute([$this->clubSlug]);
    }

    private function backfillState(): array
    {
        $q=$this->core->prepare('SELECT * FROM p2k_tp_fair_play_backfill_state WHERE club_slug=? LIMIT 1');$q->execute([$this->clubSlug]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
    }

    private function advanceBackfill(int $matchId,array $r): void
    {
        $has=!empty($r['has_removals']);$before=isset($r['mismatch_before']) && (float)$r['mismatch_before']!==0.0;$after=isset($r['mismatch_after']) && (float)$r['mismatch_after']!==0.0;$resolved=$before&&!$after;
        $q=$this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET cursor_match_id=GREATEST(cursor_match_id,?),checked_matches=checked_matches+1,matches_with_removals=matches_with_removals+?,affected_games=affected_games+?,corrected_games=corrected_games+?,points_added_x2=points_added_x2+?,mismatches_before=mismatches_before+?,mismatches_resolved=mismatches_resolved+?,mismatches_remaining=mismatches_remaining+?,last_run_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?");
        $q->execute([$matchId,$has?1:0,(int)($r['affected_games']??0),(int)($r['corrected_games']??0),(int)round(2*(float)($r['points_added']??0)),$before?1:0,$resolved?1:0,$after?1:0,$this->clubSlug]);
    }

    private function markBackfillComplete(): void
    {
        $q=$this->core->prepare("UPDATE p2k_tp_fair_play_backfill_state SET status='complete',completed_at=COALESCE(completed_at,UTC_TIMESTAMP()),last_run_at=UTC_TIMESTAMP(),last_error=NULL WHERE club_slug=?");$q->execute([$this->clubSlug]);
    }

    private function recordBackfillError(string $error): void
    {
        $q=$this->core->prepare('UPDATE p2k_tp_fair_play_backfill_state SET last_run_at=UTC_TIMESTAMP(),last_error=? WHERE club_slug=?');$q->execute([substr($error,0,4000),$this->clubSlug]);
    }
}
