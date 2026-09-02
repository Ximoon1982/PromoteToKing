<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
/**
 * Rebuildable projection layer for v2.8.0.
 *
 * Core remains authoritative. Every row written here can be recreated from Core,
 * which lets Analytics be dropped/rebuilt without reacquiring Chess.com history.
 */
final class AnalyticsBuilder
{
    public function __construct(private PDO $core, private PDO $analytics) {}

    public function sourceWatermark(string $clubSlug): string
    {
        $clubSlug = strtolower(trim($clubSlug));
        try {
            $q=$this->core->prepare('SELECT core_generation FROM p2k_tp_state WHERE club_slug=? LIMIT 1');
            $q->execute([$clubSlug]);
            $generation=(int)($q->fetchColumn()?:0);
            $iq=$this->core->prepare('SELECT identity_map_generation FROM p2k_miac_state WHERE club_slug=? LIMIT 1');$iq->execute([$clubSlug]);$identity=(int)($iq->fetchColumn()?:0);
            if($generation>0)return 'generation:' . $generation . '|identity:' . $identity . '|logic:canonical-score-outcomes-v2810|logic:opponent-coverage-results-v1';
        } catch (\Throwable) {
            // Pre-v2.8.5 fallback only; upgraded installations use the O(1) generation row.
        }
        $parts = ['logic:canonical-score-outcomes-v2810|logic:opponent-coverage-results-v1'];try{$iq=$this->core->prepare('SELECT identity_map_generation FROM p2k_miac_state WHERE club_slug=? LIMIT 1');$iq->execute([$clubSlug]);$parts[]='identity:'.(int)($iq->fetchColumn()?:0);}catch(\Throwable){$parts[]='identity:0';}
        $queries = [
            "SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(last_seen_at),'1970-01-01 00:00:00')) FROM p2k_tp_members WHERE club_slug=?",
            "SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(updated_at),'1970-01-01 00:00:00')) FROM p2k_tp_match_metadata WHERE club_slug=?",
            "SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(last_discovered_at),'1970-01-01 00:00:00')) FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?",
            "SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(verified_at),'1970-01-01 00:00:00')) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?",
        ];
        foreach ($queries as $sql) {
            $q = $this->core->prepare($sql);
            $q->execute([$clubSlug]);
            $parts[] = (string)$q->fetchColumn();
        }
        return hash('sha256', implode(';', $parts));
    }

    /** Achievement sources evolve independently from the Core-derived Insights projections. */
    public function achievementSourceWatermark(string $clubSlug): string
    {
        $clubSlug = strtolower(trim($clubSlug));
        $parts = ['logic:achievement-v2108-canonical-counts', 'logic:mca-event-date-v1', 'logic:tournament-achievement-date-v3', 'core:' . $this->sourceWatermark($clubSlug)];
        try {
            $q = $this->analytics->prepare("SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(updated_at),'1970-01-01 00:00:00')) FROM p2k_lr_players WHERE club_slug=?");
            $q->execute([$clubSlug]);
            $parts[] = 'live:' . (string)$q->fetchColumn();
            $fq=$this->analytics->prepare("SELECT CONCAT(COUNT(*),'|',COALESCE(MAX(event_date_updated_at),'1970-01-01 00:00:00'),'|',COALESCE(MAX(effective_event_date),'1970-01-01')) FROM p2k_lr_files WHERE club_slug=?");
            $fq->execute([$clubSlug]);$parts[]='mca-dates:'.(string)$fq->fetchColumn();
        } catch (\Throwable) {
            $parts[] = 'live:unavailable';
        }
        $tournamentArchive = dirname(__DIR__, 3) . '/data/tournaments/archive.json';
        $parts[] = is_file($tournamentArchive) ? 'tournaments:' . (string)@filemtime($tournamentArchive) . '|' . (string)@filesize($tournamentArchive) : 'tournaments:none';
        return hash('sha256', implode(';', $parts));
    }

    private function runtimeRefreshPaths(string $clubSlug, string $domain = 'all'): array
    {
        return AnalyticsRefreshRuntime::paths($clubSlug, $domain);
    }

    private function readCompletedEpoch(string $marker): int
    {
        return AnalyticsRefreshRuntime::completedEpoch($marker);
    }

    private static function isLockWaitTimeout(\Throwable $exception): bool
    {
        return AnalyticsRefreshRuntime::isLockWaitTimeout($exception);
    }

    public function refreshIfDue(string $clubSlug, int $minimumSeconds = 1800, ?float $deadlineAt = null): array
    {
        [$marker,$lockPath] = $this->runtimeRefreshPaths($clubSlug, 'all');
        $minimumSeconds = max(300, $minimumSeconds);
        $last = $this->readCompletedEpoch($marker);
        // v2.9.0 convergence rule: the interval is only a throttle while Analytics
        // already represents the current Core generation. A newly committed Core
        // generation gets the next bounded refresh opportunity immediately, preventing
        // the public projection from remaining one finished match behind indefinitely.
        $currentWatermark=$this->sourceWatermark($clubSlug);$storedWatermark='';
        try{$q=$this->analytics->prepare("SELECT source_watermark FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='all' LIMIT 1");$q->execute([$clubSlug]);$storedWatermark=(string)($q->fetchColumn()?:'');}catch(\Throwable){}
        if ($storedWatermark===$currentWatermark && $last > 0 && time() - $last < $minimumSeconds) return ['ran'=>false,'last_epoch'=>$last,'watermark'=>$currentWatermark];
        $generationCoalesce=max(15,min(300,(int)((\p2k_tp_config()['app']['analytics_generation_coalesce_seconds']??60))));
        if($storedWatermark!==''&&$storedWatermark!==$currentWatermark&&$last>0&&time()-$last<$generationCoalesce)return ['ran'=>false,'last_epoch'=>$last,'refresh_deferred'=>true,'reason'=>'generation_coalescing','coalesce_seconds'=>$generationCoalesce];

        // Multiple dashboard/Insights requests can arrive together. Only one may rebuild;
        // all others keep serving the last committed Analytics snapshot instead of waiting
        // on InnoDB row locks and eventually raising SQLSTATE HY000/1205.
        $handle = @fopen($lockPath, 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) @fclose($handle);
            return ['ran'=>false,'last_epoch'=>$last,'refresh_in_progress'=>true];
        }
        try {
            // Double-check after acquiring the lock; another request may have just completed.
            $last = $this->readCompletedEpoch($marker);
            $currentWatermark=$this->sourceWatermark($clubSlug);$storedWatermark='';
            try{$q=$this->analytics->prepare("SELECT source_watermark FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='all' LIMIT 1");$q->execute([$clubSlug]);$storedWatermark=(string)($q->fetchColumn()?:'');}catch(\Throwable){}
            if ($storedWatermark===$currentWatermark && $last > 0 && time() - $last < $minimumSeconds) return ['ran'=>false,'last_epoch'=>$last,'watermark'=>$currentWatermark];
            if($storedWatermark!==''&&$storedWatermark!==$currentWatermark&&$last>0&&time()-$last<$generationCoalesce)return ['ran'=>false,'last_epoch'=>$last,'refresh_deferred'=>true,'reason'=>'generation_coalescing','coalesce_seconds'=>$generationCoalesce];
            if($deadlineAt!==null && $deadlineAt-microtime(true)<8.0)return ['ran'=>false,'last_epoch'=>$last,'refresh_deferred'=>true,'reason'=>'deadline'];
            try {
                $result = $this->refreshIfNeeded($clubSlug);
            } catch (\Throwable $exception) {
                if (self::isLockWaitTimeout($exception)) {
                    // Keep the previous committed projection readable and retry on a later request.
                    // Do not advance the marker: this is a deferred refresh, not a successful one.
                    return ['ran'=>false,'last_epoch'=>$last,'refresh_deferred'=>true,'reason'=>'database_lock'];
                }
                throw $exception;
            }
            @file_put_contents($marker, json_encode(['completed_epoch'=>time(),'result'=>$result], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
            return ['ran'=>true] + $result;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function refreshAchievementsIfDue(string $clubSlug, int $minimumSeconds = 21600, ?float $deadlineAt = null): array
    {
        [$marker,$lockPath] = $this->runtimeRefreshPaths($clubSlug, 'achievements');
        $minimumSeconds = max(1800, $minimumSeconds);
        $last = $this->readCompletedEpoch($marker);

        // The filesystem interval is only a throttle when the persisted achievement
        // watermark already matches the current sources/logic. A release logic-token
        // change must force a rebuild immediately instead of waiting up to six hours.
        $currentWatermark = $this->achievementSourceWatermark($clubSlug);
        $storedWatermark = '';
        try {
            $q = $this->analytics->prepare("SELECT source_watermark FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='achievements' LIMIT 1");
            $q->execute([$clubSlug]);
            $storedWatermark = (string)($q->fetchColumn() ?: '');
        } catch (\Throwable) {}
        if ($storedWatermark === $currentWatermark && $last > 0 && time() - $last < $minimumSeconds) {
            return ['ran'=>false,'last_epoch'=>$last,'watermark'=>$currentWatermark];
        }

        $handle = @fopen($lockPath, 'c+');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) @fclose($handle);
            return ['ran'=>false,'last_epoch'=>$last,'refresh_in_progress'=>true];
        }
        try {
            // Recheck after the lock in case another request refreshed while we waited.
            $last = $this->readCompletedEpoch($marker);
            $currentWatermark = $this->achievementSourceWatermark($clubSlug);
            $storedWatermark = '';
            try {
                $q = $this->analytics->prepare("SELECT source_watermark FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='achievements' LIMIT 1");
                $q->execute([$clubSlug]);
                $storedWatermark = (string)($q->fetchColumn() ?: '');
            } catch (\Throwable) {}
            if ($storedWatermark === $currentWatermark && $last > 0 && time() - $last < $minimumSeconds) {
                return ['ran'=>false,'last_epoch'=>$last,'watermark'=>$currentWatermark];
            }
            if($deadlineAt!==null && $deadlineAt-microtime(true)<10.0)return ['ran'=>false,'last_epoch'=>$last,'refresh_deferred'=>true,'reason'=>'deadline'];
            $result = $this->refreshAchievementsIfNeeded($clubSlug);
            @file_put_contents($marker, json_encode(['completed_epoch'=>time(),'result'=>$result], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), LOCK_EX);
            return ['ran'=>true] + $result;
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function refreshIfNeeded(string $clubSlug): array
    {
        $watermark = $this->sourceWatermark($clubSlug);
        try {
            $q = $this->analytics->prepare("SELECT source_watermark FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='all' LIMIT 1");
            $q->execute([$clubSlug]);
            if ((string)($q->fetchColumn() ?: '') === $watermark) return ['refreshed'=>false,'watermark'=>$watermark];
        } catch (\Throwable) {}
        return ['refreshed'=>true] + $this->rebuildAll($clubSlug, $watermark);
    }

    public function rebuildAll(string $clubSlug, ?string $watermark = null): array
    {
        $clubSlug = strtolower(trim($clubSlug));
        $watermark ??= $this->sourceWatermark($clubSlug);
        $this->analytics->beginTransaction();
        try {
            $matchRows = $this->rebuildMatchFacts($clubSlug);
            $monthlyRows = $this->rebuildPlayerMonthly($clubSlug);
            $playerRows = $this->rebuildPlayers($clubSlug);
            $clubRows = $this->rebuildClubTotals($clubSlug);
            $dailyRows = $this->rebuildDaily($clubSlug, $watermark);
            $opponentRows = $this->rebuildOpponents($clubSlug);
            $rowCount = $matchRows + $monthlyRows + $playerRows + $clubRows + $dailyRows + $opponentRows;
            $upsert = $this->analytics->prepare(
                "INSERT INTO p2k_an_refresh_state(club_slug,domain_key,source_watermark,refreshed_at,row_count,last_error)
                 VALUES(?,'all',?,UTC_TIMESTAMP(),?,NULL)
                 ON DUPLICATE KEY UPDATE source_watermark=VALUES(source_watermark),refreshed_at=UTC_TIMESTAMP(),row_count=VALUES(row_count),last_error=NULL"
            );
            $upsert->execute([$clubSlug,$watermark,$rowCount]);
            $this->analytics->commit();
            return compact('watermark','matchRows','monthlyRows','playerRows','clubRows','dailyRows','opponentRows') + [
                'match_rows'=>$matchRows,'monthly_rows'=>$monthlyRows,'player_rows'=>$playerRows,'club_rows'=>$clubRows,
                'daily_rows'=>$dailyRows,'opponent_rows'=>$opponentRows,
            ];
        } catch (\Throwable $exception) {
            if ($this->analytics->inTransaction()) $this->analytics->rollBack();
            try {
                $q=$this->analytics->prepare(
                    "INSERT INTO p2k_an_refresh_state(club_slug,domain_key,source_watermark,refreshed_at,row_count,last_error)
                     VALUES(?,'all',NULL,UTC_TIMESTAMP(),0,?)
                     ON DUPLICATE KEY UPDATE refreshed_at=UTC_TIMESTAMP(),last_error=VALUES(last_error)"
                );
                $q->execute([$clubSlug,substr($exception->getMessage(),0,60000)]);
            } catch (\Throwable) {}
            throw $exception;
        }
    }

    private function rebuildMatchFacts(string $clubSlug): int
    {
        $this->analytics->prepare('DELETE FROM p2k_an_match_facts WHERE club_slug=?')->execute([$clubSlug]);
        $q=$this->core->prepare(
            "SELECT m.club_slug,m.match_id,m.match_name,m.match_url,m.status,m.rules,m.time_control,m.is_league,m.start_time,m.end_time,
                    CASE WHEN m.start_time IS NOT NULL AND m.end_time>m.start_time THEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time) ELSE NULL END duration_seconds,
                    m.board_count,m.p2k_score,m.opponent_score,
                    COALESCE(NULLIF(m.p2k_avg_rating,0),br.p2k_avg_rating) p2k_avg_rating,
                    COALESCE(NULLIF(m.opponent_avg_rating,0),br.opponent_avg_rating) opponent_avg_rating,
                    CASE WHEN COALESCE(m.rated_board_count,0)>0 THEN m.rated_board_count ELSE COALESCE(br.rated_board_count,0) END rated_board_count,
                    m.max_rating,m.first_discovered_at,
                    COALESCE(s.result,m.result) result,COALESCE(s.competition_points,m.competition_points) competition_points,m.is_void,m.opponent_slug,m.opponent_name,m.opponent_url,m.updated_at
             FROM p2k_tp_match_metadata m
             LEFT JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
             LEFT JOIN (
                SELECT members.club_slug,boards.match_id,ROUND(AVG(boards.p2k_rating)) p2k_avg_rating,ROUND(AVG(boards.opponent_rating)) opponent_avg_rating,COUNT(*) rated_board_count
                FROM p2k_tp_boards boards JOIN p2k_tp_members members ON members.member_id=boards.member_id
                WHERE boards.p2k_rating IS NOT NULL AND boards.p2k_rating>0 AND boards.opponent_rating IS NOT NULL AND boards.opponent_rating>0
                GROUP BY members.club_slug,boards.match_id
             ) br ON br.club_slug=m.club_slug AND br.match_id=m.match_id
             WHERE m.club_slug=? ORDER BY m.match_id"
        );
        $q->execute([$clubSlug]);
        $ins=$this->analytics->prepare(
            "INSERT INTO p2k_an_match_facts(club_slug,match_id,match_name,match_url,status,rules,time_control,is_league,start_time,end_time,duration_seconds,
                    board_count,p2k_score,opponent_score,p2k_avg_rating,opponent_avg_rating,rated_board_count,max_rating,first_discovered_at,result,competition_points,is_void,opponent_slug,opponent_name,opponent_url,updated_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $count=0;
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $ins->execute([$r['club_slug'],$r['match_id'],$r['match_name'],$r['match_url'],$r['status'],$r['rules'],$r['time_control'],(int)$r['is_league'],$r['start_time'],$r['end_time'],$r['duration_seconds'],$r['board_count'],$r['p2k_score'],$r['opponent_score'],$r['p2k_avg_rating'],$r['opponent_avg_rating'],$r['rated_board_count'],$r['max_rating'],$r['first_discovered_at'],$r['result'],$r['competition_points'],(int)$r['is_void'],$r['opponent_slug'],$r['opponent_name'],$r['opponent_url'],$r['updated_at']]);
            $count++;
        }
        return $count;
    }

    private function rebuildPlayerMonthly(string $clubSlug): int
    {
        $this->analytics->prepare('DELETE FROM p2k_an_player_monthly WHERE club_slug=?')->execute([$clubSlug]);
        $q=$this->core->prepare("SELECT COALESCE(im.canonical_username_key,u.username_key) username_key,COALESCE(MAX(im.canonical_username),MAX(u.username)) username,CAST(DATE_FORMAT(g.game_end_utc,'%Y-%m-01') AS DATE) month_start,COALESCE(SUM(g.points_x2),0) points_x2,COUNT(DISTINCT b.match_id) matches,COUNT(*) games,COALESCE(SUM(g.points_x2=2),0) wins,COALESCE(SUM(g.points_x2=1),0) draws,COALESCE(SUM(g.points_x2=0),0) losses,MIN(g.game_end_utc) first_game_at,MAX(g.game_end_utc) last_game_at FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0 JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id WHERE u.club_slug=? AND m.is_void=0 GROUP BY COALESCE(im.canonical_username_key,u.username_key),CAST(DATE_FORMAT(g.game_end_utc,'%Y-%m-01') AS DATE) ORDER BY month_start,username_key");
        $q->execute([$clubSlug]);$ins=$this->analytics->prepare("INSERT INTO p2k_an_player_monthly(club_slug,username_key,username,month_start,points_x2,matches,games,wins,draws,losses,first_game_at,last_game_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");$count=0;
        while($r=$q->fetch(PDO::FETCH_ASSOC)){$ins->execute([$clubSlug,$r['username_key'],$r['username'],$r['month_start'],(int)$r['points_x2'],(int)$r['matches'],(int)$r['games'],(int)$r['wins'],(int)$r['draws'],(int)$r['losses'],$r['first_game_at'],$r['last_game_at']]);$count++;}
        return $count;
    }

    private function rebuildPlayers(string $clubSlug): int
    {
        $this->analytics->prepare('DELETE FROM p2k_an_player_totals WHERE club_slug=?')->execute([$clubSlug]);
        $q=$this->core->prepare("SELECT COALESCE(im.canonical_username_key,u.username_key) username_key,COALESCE(MAX(im.canonical_username),MAX(u.username)) username,MAX(u.current_member) current_member,COALESCE(MAX(CASE WHEN u.current_member=1 THEN u.daily_rating END),MAX(u.daily_rating)) daily_rating,COALESCE(MAX(CASE WHEN u.current_member=1 THEN u.chess960_rating END),MAX(u.chess960_rating)) chess960_rating,MAX(u.rating_updated_at) rating_updated_at,COALESCE(SUM(g.points_x2),0)/2.0 points,COUNT(DISTINCT b.match_id) matches,COUNT(g.game_row_id) games,COALESCE(SUM(g.points_x2=2),0) wins,COALESCE(SUM(g.points_x2=1),0) draws,COALESCE(SUM(g.points_x2=0),0) losses,MIN(g.game_end_utc) first_game_at,MAX(g.game_end_utc) last_game_at,MAX(CASE WHEN LOWER(COALESCE(m.rules,'')) IN ('chess','standard') THEN g.game_end_utc END) last_standard_game_at,MAX(CASE WHEN LOWER(COALESCE(m.rules,'')) IN ('chess960','960') THEN g.game_end_utc END) last_chess960_game_at FROM p2k_tp_members u LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0 LEFT JOIN p2k_tp_boards b ON b.member_id=u.member_id LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id WHERE u.club_slug=? AND (m.match_id IS NULL OR m.is_void=0) GROUP BY COALESCE(im.canonical_username_key,u.username_key) ORDER BY username_key");
        $q->execute([$clubSlug]);$insert=$this->analytics->prepare("INSERT INTO p2k_an_player_totals(club_slug,username_key,username,current_member,points,matches,games,wins,draws,losses,daily_rating,chess960_rating,rating_updated_at,first_game_at,last_game_at,last_standard_game_at,last_chess960_game_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");$count=0;
        while($row=$q->fetch(PDO::FETCH_ASSOC)){$insert->execute([$clubSlug,$row['username_key'],$row['username'],(int)$row['current_member'],(float)$row['points'],(int)$row['matches'],(int)$row['games'],(int)$row['wins'],(int)$row['draws'],(int)$row['losses'],$row['daily_rating']===null?null:(int)$row['daily_rating'],$row['chess960_rating']===null?null:(int)$row['chess960_rating'],$row['rating_updated_at'],$row['first_game_at'],$row['last_game_at'],$row['last_standard_game_at'],$row['last_chess960_game_at']]);$count++;}return $count;
    }

    private function rebuildClubTotals(string $clubSlug): int
    {
        $q=$this->core->prepare(
            "SELECT COUNT(*) finished_matches,COALESCE(SUM(board_count),0) finished_boards,COALESCE(SUM(board_count*2),0) finished_games,
                    COALESCE(SUM(competition_points),0) club_points,COALESCE(SUM(result='win'),0) won_matches,
                    COALESCE(SUM(result='draw'),0) drawn_matches,COALESCE(SUM(result='loss'),0) lost_matches
             FROM p2k_tp_match_summaries WHERE club_slug=?"
        );
        $q->execute([$clubSlug]);$row=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $up=$this->analytics->prepare(
            "INSERT INTO p2k_tp_club_totals(club_slug,finished_matches,finished_boards,finished_games,club_points,won_matches,drawn_matches,lost_matches,updated_at)
             VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE finished_matches=VALUES(finished_matches),finished_boards=VALUES(finished_boards),
             finished_games=VALUES(finished_games),club_points=VALUES(club_points),won_matches=VALUES(won_matches),drawn_matches=VALUES(drawn_matches),lost_matches=VALUES(lost_matches),updated_at=UTC_TIMESTAMP()"
        );
        $up->execute([$clubSlug,(int)($row['finished_matches']??0),(int)($row['finished_boards']??0),(int)($row['finished_games']??0),(int)($row['club_points']??0),(int)($row['won_matches']??0),(int)($row['drawn_matches']??0),(int)($row['lost_matches']??0)]);
        return 1;
    }

    private function rebuildDaily(string $clubSlug, string $watermark): int
    {
        $facts=[];
        $ensure=static function(array &$facts,string $day): void { if(!isset($facts[$day]))$facts[$day]=['matches_started'=>0,'matches_finished'=>0,'boards_started'=>0,'boards_finished'=>0,'games_finished'=>0,'unique_players'=>0,'club_points'=>0]; };
        $q=$this->core->prepare("SELECT DATE(start_time) d,COUNT(*) matches_started,COALESCE(SUM(board_count),0) boards_started FROM p2k_tp_match_metadata WHERE club_slug=? AND is_void=0 AND start_time IS NOT NULL GROUP BY DATE(start_time)");
        $q->execute([$clubSlug]);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$d=(string)$r['d'];$ensure($facts,$d);$facts[$d]['matches_started']=(int)$r['matches_started'];$facts[$d]['boards_started']=(int)$r['boards_started'];}
        $q=$this->core->prepare("SELECT DATE(m.end_time) d,COUNT(*) matches_finished,COALESCE(SUM(m.board_count),0) boards_finished,COALESCE(SUM(s.competition_points),0) club_points FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id WHERE m.club_slug=? AND m.end_time IS NOT NULL GROUP BY DATE(m.end_time)");
        $q->execute([$clubSlug]);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$d=(string)$r['d'];$ensure($facts,$d);$facts[$d]['matches_finished']=(int)$r['matches_finished'];$facts[$d]['boards_finished']=(int)$r['boards_finished'];$facts[$d]['club_points']=(int)$r['club_points'];}
        $q=$this->core->prepare("SELECT DATE(g.game_end_utc) d,COUNT(*) games_finished,COUNT(DISTINCT COALESCE(im.canonical_username_key,u.username_key)) unique_players FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0 JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id WHERE u.club_slug=? AND m.is_void=0 GROUP BY DATE(g.game_end_utc)");
        $q->execute([$clubSlug]);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$d=(string)$r['d'];$ensure($facts,$d);$facts[$d]['games_finished']=(int)$r['games_finished'];$facts[$d]['unique_players']=(int)$r['unique_players'];}
        ksort($facts);$this->analytics->prepare('DELETE FROM p2k_tp_insight_daily WHERE club_slug=?')->execute([$clubSlug]);
        $ins=$this->analytics->prepare('INSERT INTO p2k_tp_insight_daily(club_slug,activity_date,matches_started,matches_finished,boards_started,boards_finished,games_finished,unique_players,club_points,computed_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');
        foreach($facts as $d=>$r)$ins->execute([$clubSlug,$d,$r['matches_started'],$r['matches_finished'],$r['boards_started'],$r['boards_finished'],$r['games_finished'],$r['unique_players'],$r['club_points']]);
        $state=$this->analytics->prepare("INSERT INTO p2k_tp_insight_cache_state(club_slug,source_updated_at,refreshed_at,row_count,last_error) VALUES(?,UTC_TIMESTAMP(),UTC_TIMESTAMP(),?,NULL) ON DUPLICATE KEY UPDATE source_updated_at=VALUES(source_updated_at),refreshed_at=UTC_TIMESTAMP(),row_count=VALUES(row_count),last_error=NULL");
        $state->execute([$clubSlug,count($facts)]);return count($facts);
    }

    private function rebuildOpponents(string $clubSlug): int
    {
        $this->analytics->prepare('DELETE FROM p2k_an_opponent_stats WHERE club_slug=?')->execute([$clubSlug]);
        $q=$this->core->prepare(
            "SELECT COALESCE(a.canonical_slug,m.opponent_slug) opponent_slug,COALESCE(o.display_name,MAX(m.opponent_name),COALESCE(a.canonical_slug,m.opponent_slug)) display_name,
                    COALESCE(o.club_url,MAX(m.opponent_url)) club_url,COALESCE(o.disabled,0) disabled,COUNT(*) matches,
                    COALESCE(SUM(m.status='finished'),0) finished,COALESCE(SUM(m.status='finished' AND s.result='win'),0) wins,
                    COALESCE(SUM(m.status='finished' AND s.result='draw'),0) draws,COALESCE(SUM(m.status='finished' AND s.result='loss'),0) losses,
                    COALESCE(SUM(m.status='in_progress'),0) ongoing,COALESCE(SUM(m.status='registered'),0) registered,COALESCE(SUM(m.board_count),0) total_boards,
                    COALESCE(SUM(CASE WHEN m.status='finished' THEN m.p2k_score ELSE 0 END),0) our_points,COALESCE(SUM(CASE WHEN m.status='finished' THEN m.opponent_score ELSE 0 END),0) their_points,
                    MIN(COALESCE(m.start_time,m.end_time)) first_match_at,MAX(COALESCE(m.end_time,m.start_time)) last_match_at
             FROM p2k_tp_match_metadata m
             LEFT JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
             LEFT JOIN p2k_tp_opponent_aliases a ON a.club_slug=m.club_slug AND a.alias_slug=m.opponent_slug
             LEFT JOIN p2k_tp_opponents o ON o.club_slug=m.club_slug AND o.opponent_slug=COALESCE(a.canonical_slug,m.opponent_slug)
             WHERE m.club_slug=? AND m.is_void=0 AND m.opponent_slug IS NOT NULL AND m.opponent_slug<>'' GROUP BY COALESCE(a.canonical_slug,m.opponent_slug)"
        );
        $q->execute([$clubSlug]);
        $ins=$this->analytics->prepare("INSERT INTO p2k_an_opponent_stats(club_slug,opponent_slug,display_name,club_url,disabled,matches,finished,wins,draws,losses,ongoing,registered,total_boards,our_points,their_points,first_match_at,last_match_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        $count=0;while($r=$q->fetch(PDO::FETCH_ASSOC)){$ins->execute([$clubSlug,$r['opponent_slug'],$r['display_name'],$r['club_url'],(int)$r['disabled'],(int)$r['matches'],(int)$r['finished'],(int)$r['wins'],(int)$r['draws'],(int)$r['losses'],(int)$r['ongoing'],(int)$r['registered'],(int)$r['total_boards'],(float)$r['our_points'],(float)$r['their_points'],$r['first_match_at'],$r['last_match_at']]);$count++;}
        return $count;
    }
    public function refreshAchievementsIfNeeded(string $clubSlug): array
    {
        $clubSlug = strtolower(trim($clubSlug));
        $watermark = $this->achievementSourceWatermark($clubSlug);
        try {
            $q = $this->analytics->prepare("SELECT source_watermark FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='achievements' LIMIT 1");
            $q->execute([$clubSlug]);
            if ((string)($q->fetchColumn() ?: '') === $watermark) return ['refreshed'=>false,'watermark'=>$watermark];
        } catch (\Throwable) {}

        // Achievement persistence is intentionally outside the general Insights transaction.
        // It can scan substantial history, but it only writes the unlock table and must never
        // hold Team/Opponent projection rows locked while a dashboard page is loading.
        try {
            $rows = $this->rebuildAchievements($clubSlug);
            $q = $this->analytics->prepare(
                "INSERT INTO p2k_an_refresh_state(club_slug,domain_key,source_watermark,refreshed_at,row_count,last_error)
                 VALUES(?,'achievements',?,UTC_TIMESTAMP(),?,NULL)
                 ON DUPLICATE KEY UPDATE source_watermark=VALUES(source_watermark),refreshed_at=UTC_TIMESTAMP(),row_count=VALUES(row_count),last_error=NULL"
            );
            $q->execute([$clubSlug,$watermark,$rows]);
            return ['refreshed'=>true,'watermark'=>$watermark,'achievement_rows'=>$rows];
        } catch (\Throwable $exception) {
            try {
                $q=$this->analytics->prepare(
                    "INSERT INTO p2k_an_refresh_state(club_slug,domain_key,source_watermark,refreshed_at,row_count,last_error)
                     VALUES(?,'achievements',NULL,UTC_TIMESTAMP(),0,?)
                     ON DUPLICATE KEY UPDATE refreshed_at=UTC_TIMESTAMP(),last_error=VALUES(last_error)"
                );
                $q->execute([$clubSlug,substr($exception->getMessage(),0,60000)]);
            } catch (\Throwable) {}
            throw $exception;
        }
    }

    private function rebuildAchievements(string $clubSlug): int
    {
        // v2.8.3 could persist YYYY-MM-01 synthetic tournament dates with precision
        // tournament-period. Clear those legacy placeholders up front. Exact dates are
        // restored below only when an authoritative tournament finishAt is available.
        try {
            $cleanup = $this->analytics->prepare(
                "UPDATE p2k_an_achievement_unlocks
                 SET earned_at=NULL, earned_at_precision='tournament-pending', last_verified_at=UTC_TIMESTAMP()
                 WHERE club_slug=? AND source_type='tournament' AND earned_at_precision='tournament-period'"
            );
            $cleanup->execute([$clubSlug]);
        } catch (\Throwable) {}

        // v2.10.8: remove only the two proven transient v2.9.3 breadth identities.
        // Never delete arbitrary unknown achievement keys: historical identities remain durable.
        try {
            $cleanup = $this->analytics->prepare(
                "DELETE FROM p2k_an_achievement_unlocks WHERE club_slug=? AND achievement_key IN ('groups-1','groups-20')"
            );
            $cleanup->execute([$clubSlug]);
        } catch (\Throwable) {}

        $catalog = [];
        foreach (AchievementCatalog::all() as $item) $catalog[(string)$item['key']] = true;
        $unlock = $this->analytics->prepare(
            "INSERT INTO p2k_an_achievement_unlocks(club_slug,username_key,achievement_key,earned_at,earned_at_precision,source_type,source_name,source_url,first_recorded_at,last_verified_at)
             VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE earned_at=CASE
                                         WHEN VALUES(earned_at_precision)='tournament-finish' THEN VALUES(earned_at)
                                         WHEN VALUES(earned_at_precision)='tournament-pending' AND earned_at_precision<>'tournament-finish' THEN NULL
                                         ELSE COALESCE(earned_at,VALUES(earned_at))
                                     END,
                                     earned_at_precision=CASE
                                         WHEN VALUES(earned_at_precision)='tournament-finish' THEN 'tournament-finish'
                                         WHEN VALUES(earned_at_precision)='tournament-pending' AND earned_at_precision<>'tournament-finish' THEN 'tournament-pending'
                                         ELSE earned_at_precision
                                     END,
                                     source_type=CASE WHEN VALUES(source_type)='tournament' THEN VALUES(source_type) ELSE COALESCE(source_type,VALUES(source_type)) END,
                                     source_name=CASE WHEN VALUES(source_type)='tournament' THEN VALUES(source_name) ELSE COALESCE(source_name,VALUES(source_name)) END,
                                     source_url=CASE WHEN VALUES(source_type)='tournament' THEN VALUES(source_url) ELSE COALESCE(source_url,VALUES(source_url)) END,
                                     last_verified_at=UTC_TIMESTAMP()"
        );
        $canonical=[];try{$cq=$this->core->prepare('SELECT username_key,canonical_username_key FROM p2k_miac_canonical_map WHERE club_slug=? AND conflict=0');$cq->execute([$clubSlug]);foreach($cq->fetchAll(PDO::FETCH_ASSOC)?:[] as $cr)$canonical[(string)$cr['username_key']]=(string)$cr['canonical_username_key'];}catch(\Throwable){}
        $seen = [];
        $record = static function(string $user,string $key,?string $when,string $precision='derived',?string $sourceType=null,?string $sourceName=null,?string $sourceUrl=null) use ($unlock,$clubSlug,$catalog,&$seen,$canonical): void {
            $user=$canonical[$user]??$user;if (!isset($catalog[$key])) return;
            $sourceUrl=\p2k_tp_chess_web_url($sourceUrl,(string)($sourceType??''));
            $id = $user . "\0" . $key;
            if (isset($seen[$id])) return;
            $unlock->execute([$clubSlug,$user,$key,$when,$precision,$sourceType,$sourceName,$sourceUrl]);
            $seen[$id] = true;
        };
        $leagueFromName = static function(string $name): ?string {
            foreach (['1wl','pcl','tcmac','tmcl','kotml'] as $league) {
                if (preg_match('/(^|[^A-Z0-9])'.preg_quote($league,'/').'([^A-Z0-9]|$)/i',$name)) return $league;
            }
            return null;
        };

        // Match milestones and league participation use the first known match timestamp.
        $q = $this->core->prepare(
            "SELECT COALESCE(im.canonical_username_key,u.username_key) username_key,m.match_id,m.match_name,m.match_url,m.is_league,m.start_time,m.end_time,m.status,m.rules,m.board_count,COALESCE(oa.canonical_slug,m.opponent_slug) opponent_slug,m.result,m.p2k_score,m.opponent_score,o.country_code,
                    COALESCE(m.start_time,m.end_time,m.last_verified_at) event_at,COALESCE(SUM(g.points_x2),0) player_points_x2
             FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id
             LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0
             JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id
             LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id
             LEFT JOIN p2k_tp_opponent_aliases oa ON oa.club_slug=m.club_slug AND oa.alias_slug=m.opponent_slug
             LEFT JOIN p2k_tp_opponents o ON o.club_slug=m.club_slug AND o.opponent_slug=COALESCE(oa.canonical_slug,m.opponent_slug)
             WHERE u.club_slug=? AND m.is_void=0 GROUP BY COALESCE(im.canonical_username_key,u.username_key),m.match_id,m.match_name,m.match_url,m.is_league,m.start_time,m.end_time,m.status,m.rules,m.board_count,COALESCE(oa.canonical_slug,m.opponent_slug),m.result,m.p2k_score,m.opponent_score,o.country_code,event_at
             ORDER BY username_key,event_at,m.match_id"
        );
        $q->execute([$clubSlug]);
        $state=[];
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $u=(string)$r['username_key']; $at=$r['event_at']!==null?(string)$r['event_at']:null;
            if(!isset($state[$u]))$state[$u]=['matches'=>0,'league'=>0,'leagues'=>[],'same_day'=>[],'last_start_day_index'=>null,'start_day_streak'=>0,'start_day_peak'=>0,'rivalry'=>[],'opponents'=>[],'rematches'=>0,'countries'=>[],'chess960'=>0,'last_month'=>null,'active_month_streak'=>0,'lost_to'=>[],'close_calls'=>0,'winning_side'=>0,'match_winners'=>0,'match_savers'=>0];
            $st=&$state[$u]; $st['matches']++;
            $startAt=$r['start_time']!==null?(string)$r['start_time']:'';
            if($startAt!==''){
                $day=substr($startAt,0,10);$st['same_day'][$day]=($st['same_day'][$day]??0)+1;$sameDayCount=$st['same_day'][$day];
                foreach([2,3,4,5] as $n)if($sameDayCount===$n)$record($u,'same-day-matches-'.$n,$startAt,'match-start-time','match',(string)$r['match_name'],(string)$r['match_url']);
                if($sameDayCount===1){$dayTs=strtotime($day.' UTC');if($dayTs!==false){$dayIndex=(int)floor($dayTs/86400);$st['start_day_streak']=$st['last_start_day_index']!==null&&$dayIndex===$st['last_start_day_index']+1?$st['start_day_streak']+1:1;$st['last_start_day_index']=$dayIndex;$st['start_day_peak']=max((int)$st['start_day_peak'],(int)$st['start_day_streak']);foreach([[3,'match-start-streak-3'],[5,'match-start-streak-5'],[7,'match-start-streak-7'],[10,'match-start-streak-10'],[14,'match-start-streak-14']] as $m)if($st['start_day_streak']===$m[0])$record($u,$m[1],$startAt,'match-start-time','match',(string)$r['match_name'],(string)$r['match_url']);}}
            }
            if($st['matches']===1)$record($u,'first-match',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
            foreach([10,50,100,250,500,1000] as $n)if($st['matches']===$n)$record($u,'matches-'.$n,$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
            $opponentSlug=strtolower(trim((string)($r['opponent_slug']??'')));
            if($opponentSlug!==''){
                if(isset($st['opponents'][$opponentSlug])){$st['rematches']++;foreach([[5,'old-foes-5'],[10,'old-foes-10'],[25,'old-foes-25']] as $m)if($st['rematches']===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);}
                else{$st['opponents'][$opponentSlug]=true;$opponentCount=count($st['opponents']);foreach([[25,'opponent-variety-25'],[50,'opponent-variety-50'],[100,'opponent-variety-100']] as $m)if($opponentCount===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);}
                $st['rivalry'][$opponentSlug]=($st['rivalry'][$opponentSlug]??0)+1;$rivalryCount=$st['rivalry'][$opponentSlug];
                foreach([[5,'rivalry-5'],[10,'rivalry-10'],[25,'rivalry-25'],[50,'rivalry-50']] as $m)if($rivalryCount===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                $result=(string)($r['result']??'unknown');
                if($result==='win'&&!empty($st['lost_to'][$opponentSlug]))$record($u,'rivalry-turnaround',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                if($result==='loss')$st['lost_to'][$opponentSlug]=true;
            }
            if(strtolower((string)($r['status']??''))==='finished'){
                $result=strtolower((string)($r['result']??''));$p2kScore=(float)($r['p2k_score']??0);$oppScore=(float)($r['opponent_score']??0);$resultAt=$r['end_time']!==null?(string)$r['end_time']:$at;
                if(abs($p2kScore-$oppScore)<=1.000001){$st['close_calls']++;foreach([[5,'photo-finish-5'],[20,'close-call-veteran-20'],[50,'close-call-master-50'],[100,'close-call-legend-100']] as $m)if($st['close_calls']===$m[0])$record($u,$m[1],$resultAt,'match-result','match',(string)$r['match_name'],(string)$r['match_url']);}
                if($result==='win'){$st['winning_side']++;foreach([[10,'winning-side-10'],[50,'winning-side-50'],[100,'winning-side-100'],[250,'winning-side-250']] as $m)if($st['winning_side']===$m[0])$record($u,$m[1],$resultAt,'match-result','match',(string)$r['match_name'],(string)$r['match_url']);}
                $playerPointsX2=(int)($r['player_points_x2']??0);$p2kX2=(int)round($p2kScore*2);$oppX2=(int)round($oppScore*2);
                if($playerPointsX2>0&&$result==='win'&&$p2kX2-$playerPointsX2<=$oppX2){$st['match_winners']++;foreach([[1,'match-winner-1'],[5,'match-winner-5'],[10,'match-winner-10'],[15,'match-winner-15'],[20,'match-winner-20']] as $m)if($st['match_winners']===$m[0])$record($u,$m[1],$resultAt,'match-result','match',(string)$r['match_name'],(string)$r['match_url']);}
                if($playerPointsX2>0&&$result==='draw'&&$p2kX2-$playerPointsX2<$oppX2){$st['match_savers']++;foreach([[1,'match-saver-1'],[5,'match-saver-5'],[10,'match-saver-10'],[15,'match-saver-15'],[20,'match-saver-20']] as $m)if($st['match_savers']===$m[0])$record($u,$m[1],$resultAt,'match-result','match',(string)$r['match_name'],(string)$r['match_url']);}
            }
            $country=strtoupper(trim((string)($r['country_code']??'')));
            if($country!==''&&!isset($st['countries'][$country])){
                $st['countries'][$country]=true;$countryCount=count($st['countries']);
                foreach([[10,'opponent-countries-10'],[25,'opponent-countries-25'],[50,'opponent-countries-50']] as $m)if($countryCount===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
            }
            if(str_contains(strtolower((string)($r['rules']??'')),'960')){
                $st['chess960']++;foreach([[10,'chess960-matches-10'],[50,'chess960-matches-50'],[100,'chess960-matches-100']] as $m)if($st['chess960']===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
            }
            $eventMonth=$at!==null&&strlen($at)>=7?substr($at,0,7):'';
            if($eventMonth!==''&&$eventMonth!==$st['last_month']){
                [$year,$month]=array_map('intval',explode('-',$eventMonth));$monthIndex=$year*12+$month;
                if($st['last_month']===null)$st['active_month_streak']=1;else{[$lastYear,$lastMonth]=array_map('intval',explode('-',(string)$st['last_month']));$lastIndex=$lastYear*12+$lastMonth;$st['active_month_streak']=$monthIndex===$lastIndex+1?$st['active_month_streak']+1:1;}
                $st['last_month']=$eventMonth;foreach([[3,'active-months-3'],[6,'active-months-6'],[12,'active-months-12']] as $m)if($st['active_month_streak']===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
            }
            $boards=(int)($r['board_count']??0);foreach([[100,'large-match-100'],[200,'large-match-200'],[500,'large-match-500']] as $m)if($boards>=$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
            if((int)$r['is_league']===1){
                $st['league']++; foreach([[1,'league-debut'],[10,'league-regular'],[25,'league-veteran'],[50,'league-specialist'],[100,'league-legend']] as $m)if($st['league']===$m[0])$record($u,$m[1],$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                $league=$leagueFromName((string)$r['match_name']);
                if($league!==null){
                    $st['leagues'][$league]=($st['leagues'][$league]??0)+1; $n=$st['leagues'][$league];
                    if($n===1)$record($u,$league.'-competitor',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                    if($n===10)$record($u,$league.'-veteran',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                    if($n===20)$record($u,$league.'-legend',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                    $distinct=count($st['leagues']);
                    if($distinct>=3)$record($u,'multi-league',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                    if($distinct>=5)$record($u,'all-league',$at,'match-time','match',(string)$r['match_name'],(string)$r['match_url']);
                }
            }
            unset($st);
        }

        // ACIF v2.10.4.3: enforce final threshold integrity after the chronological match scan.
        // Exact crossing timestamps are normally recorded above. This reconciliation exists
        // so a stale/partial historical projection cannot leave a provably completed metric
        // unearned. It never overwrites an exact unlock because $record de-duplicates by key.
        foreach ($state as $u => $st) {
            foreach ([[3,'match-start-streak-3'],[5,'match-start-streak-5'],[7,'match-start-streak-7'],[10,'match-start-streak-10'],[14,'match-start-streak-14']] as $m) if ((int)$st['start_day_peak'] >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
            foreach ([[1,'match-winner-1'],[5,'match-winner-5'],[10,'match-winner-10'],[15,'match-winner-15'],[20,'match-winner-20']] as $m) if ((int)$st['match_winners'] >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
            foreach ([[1,'match-saver-1'],[5,'match-saver-5'],[10,'match-saver-10'],[15,'match-saver-15'],[20,'match-saver-20']] as $m) if ((int)$st['match_savers'] >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
            foreach ([[5,'photo-finish-5'],[20,'close-call-veteran-20'],[50,'close-call-master-50'],[100,'close-call-legend-100']] as $m) if ((int)$st['close_calls'] >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
            foreach ([[10,'winning-side-10'],[50,'winning-side-50'],[100,'winning-side-100'],[250,'winning-side-250']] as $m) if ((int)$st['winning_side'] >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
            foreach ([[25,'opponent-variety-25'],[50,'opponent-variety-50'],[100,'opponent-variety-100']] as $m) if (count($st['opponents']) >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
            foreach ([[5,'old-foes-5'],[10,'old-foes-10'],[25,'old-foes-25']] as $m) if ((int)$st['rematches'] >= $m[0]) $record((string)$u,$m[1],null,'threshold-reconciled','derived');
        }

        // Concurrent team-game achievements are reconstructible because both games on a
        // team-match board begin at the authoritative match start. Finished game rows
        // provide their individual end times. Incomplete finished boards are skipped
        // rather than guessed, while in-progress boards retain only their known live games.
        try {
            $q=$this->core->prepare(
                "SELECT COALESCE(im.canonical_username_key,u.username_key) username_key,m.match_id,m.match_name,m.match_url,m.status,m.start_time,b.finished_game_count,
                        GROUP_CONCAT(DATE_FORMAT(g.game_end_utc,'%Y-%m-%d %H:%i:%s') ORDER BY g.game_end_utc,g.sequence_no SEPARATOR '|') game_ends,
                        COUNT(g.game_row_id) stored_games
                 FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id
                 LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0
                 JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id
                 LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id
                 WHERE u.club_slug=? AND m.is_void=0 AND m.start_time IS NOT NULL AND m.start_time<=UTC_TIMESTAMP()
                   AND m.status IN ('in_progress','finished')
                 GROUP BY b.board_id,COALESCE(im.canonical_username_key,u.username_key),m.match_id,m.match_name,m.match_url,m.status,m.start_time,b.finished_game_count
                 ORDER BY username_key,m.start_time,m.match_id"
            );
            $q->execute([$clubSlug]);$events=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
                $stored=(int)($r['stored_games']??0);$status=(string)$r['status'];
                if($status==='finished'&&$stored<2)continue;
                $u=(string)$r['username_key'];$start=(string)$r['start_time'];
                $events[$u][]=[$start,2,(string)$r['match_name'],(string)$r['match_url']];
                $ends=trim((string)($r['game_ends']??''));
                if($ends!=='')foreach(explode('|',$ends) as $end)if($end!=='')$events[$u][]=[$end,-1,(string)$r['match_name'],(string)$r['match_url']];
            }
            foreach($events as $u=>$timeline){
                usort($timeline,static fn(array $a,array $b):int=>strcmp((string)$a[0],(string)$b[0])?:((int)$a[1]<=>(int)$b[1]));
                $active=0;foreach($timeline as $event){$active=max(0,$active+(int)$event[1]);if((int)$event[1]<=0)continue;foreach([5,10,25,50,100] as $n)if($active>=$n)$record((string)$u,'concurrent-games-'.$n,(string)$event[0],'reconstructed-game-interval','match',(string)$event[2],(string)$event[3]);}
            }
        } catch(\Throwable) {}

        // Game, win, Team Point and league-scoring milestones are derived chronologically.
        $q=$this->core->prepare(
            "SELECT COALESCE(im.canonical_username_key,u.username_key) username_key,g.game_end_utc,g.points_x2,b.p2k_rating,b.opponent_rating,m.match_name,m.match_url,m.is_league
             FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id
             LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0
             JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id
             WHERE u.club_slug=? AND m.is_void=0 ORDER BY username_key,g.game_end_utc,g.game_row_id"
        );
        $q->execute([$clubSlug]); $state=[];
        $pointThresholds=['daily-pawn'=>20,'daily-knight'=>40,'daily-bishop'=>100,'daily-rook'=>200,'daily-queen'=>300,'daily-king'=>500,'daily-bronze-king'=>1000,'daily-silver-king'=>2000,'daily-gold-king'=>3000,'daily-platinum-king'=>4000,'daily-amethyst-king'=>6000,'daily-topaz-king'=>8000,'daily-emerald-king'=>11000,'daily-sapphire-king'=>14000,'daily-ruby-king'=>17000,'daily-diamond-king'=>20000];
        while($r=$q->fetch(PDO::FETCH_ASSOC)){
            $u=(string)$r['username_key']; $at=(string)$r['game_end_utc']; $px=(int)$r['points_x2'];
            if(!isset($state[$u]))$state[$u]=['games'=>0,'wins'=>0,'points'=>0,'league_points'=>[],'period_keys'=>[],'period_points'=>[]];
            $st=&$state[$u]; $st['games']++; if($px===2)$st['wins']++; $st['points']+=$px;
            $ts=strtotime($at.' UTC');
            if($ts!==false){
                $periods=['day'=>gmdate('Y-m-d',$ts),'week'=>gmdate('o-\WW',$ts),'month'=>gmdate('Y-m',$ts),'year'=>gmdate('Y',$ts)];
                $thresholds=['day'=>[[4,'team-points-day-2'],[10,'team-points-day-5']],'week'=>[[20,'team-points-week-10'],[40,'team-points-week-20']],'month'=>[[50,'team-points-month-25'],[100,'team-points-month-50']],'year'=>[[200,'team-points-year-100'],[500,'team-points-year-250']]];
                foreach($periods as $period=>$periodKey){if(($st['period_keys'][$period]??null)!==$periodKey){$st['period_keys'][$period]=$periodKey;$st['period_points'][$period]=0;}$st['period_points'][$period]+=$px;foreach($thresholds[$period] as $threshold)if($st['period_points'][$period]>=$threshold[0])$record($u,$threshold[1],$at,'game-time','match',(string)$r['match_name'],(string)$r['match_url']);}
            }
            if($st['points']>0)$record($u,'first-point',$at,'game-time','match',(string)$r['match_name'],(string)$r['match_url']);
            foreach([100,500,1000,5000,10000] as $n)if($st['games']===$n)$record($u,'games-'.$n,$at,'game-time','match',(string)$r['match_name'],(string)$r['match_url']);
            foreach([50,250,1000] as $n)if($st['wins']===$n)$record($u,'wins-'.$n,$at,'game-time','match',(string)$r['match_name'],(string)$r['match_url']);
            if($px===2&&is_numeric($r['p2k_rating']??null)&&is_numeric($r['opponent_rating']??null)){$delta=(int)$r['opponent_rating']-(int)$r['p2k_rating'];foreach([[100,'upset-100'],[200,'upset-200'],[400,'upset-400']] as $m)if($delta>=$m[0])$record($u,$m[1],$at,'stored-paired-rating','match',(string)$r['match_name'],(string)$r['match_url']);}
            foreach($pointThresholds as $key=>$threshold)if($st['points'] >= $threshold)$record($u,$key,$at,'game-time','match',(string)$r['match_name'],(string)$r['match_url']);
            if((int)$r['is_league']===1 && ($league=$leagueFromName((string)$r['match_name']))!==null){
                $st['league_points'][$league]=($st['league_points'][$league]??0)+$px; $lp=$st['league_points'][$league];
                foreach([[2,'first-point'],[10,'scorer'],[20,'specialist'],[40,'master']] as $m)if($lp >= $m[0])$record($u,$league.'-'.$m[1],$at,'game-time','match',(string)$r['match_name'],(string)$r['match_url']);
            }
            unset($st);
        }

        // Single-match two-game performance uses only boards with both finished games stored.
        try{
            $q=$this->core->prepare("SELECT COALESCE(im.canonical_username_key,u.username_key) username_key,m.match_name,m.match_url,MAX(g.game_end_utc) event_at,COUNT(g.game_row_id) stored_games,SUM(g.points_x2) points_x2,SUM(CASE WHEN g.points_x2=1 THEN 1 ELSE 0 END) draws FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0 JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE u.club_slug=? AND m.is_void=0 GROUP BY b.board_id,COALESCE(im.canonical_username_key,u.username_key),m.match_name,m.match_url HAVING stored_games>=2 ORDER BY username_key,event_at,b.board_id");
            $q->execute([$clubSlug]);foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$u=(string)$r['username_key'];$at=(string)$r['event_at'];$points=(int)$r['points_x2'];$draws=(int)$r['draws'];if($points>=3)$record($u,'match-score-15',$at,'board-complete','match',(string)$r['match_name'],(string)$r['match_url']);if($points>=4)$record($u,'match-score-20',$at,'board-complete','match',(string)$r['match_name'],(string)$r['match_url']);if($draws>=2)$record($u,'match-two-draws',$at,'board-complete','match',(string)$r['match_name'],(string)$r['match_url']);}
        }catch(\Throwable){}

        // Membership seniority has an exact threshold date when first_seen is known.
        $q=$this->core->prepare("SELECT username_key,current_member,first_seen_at FROM p2k_tp_members WHERE club_slug=? AND current_member=1 AND first_seen_at IS NOT NULL");
        $q->execute([$clubSlug]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
            $base=strtotime((string)$r['first_seen_at'].' UTC'); if(!$base)continue; $now=time();
            foreach([['+1 month','seniority-1m'],['+3 months','seniority-3m'],['+6 months','seniority-6m'],['+1 year','seniority-1y'],['+2 years','seniority-2y'],['+3 years','seniority-3y'],['+5 years','seniority-5y']] as $m){$t=strtotime($m[0],$base);if($t!==false&&$t<=$now)$record((string)$r['username_key'],$m[1],gmdate('Y-m-d H:i:s',$t),'membership-threshold');}
        }

        // MCA achievements are reconstructed event-by-event from stored source rows.
        // Schema 8 provides a known or explicitly approximate event date per arena.
        // Derived MCA unlocks are discarded first so older first-recorded timestamps
        // cannot mask a newly available historical event date.
        try{
            $mcaKeys=[];foreach(AchievementCatalog::all() as $item){$cat=(string)($item['category']??'');if($cat==='live-ranks'||str_starts_with($cat,'mca-'))$mcaKeys[]=(string)$item['key'];}
            if($mcaKeys){$placeholders=implode(',',array_fill(0,count($mcaKeys),'?'));$del=$this->analytics->prepare("DELETE FROM p2k_an_achievement_unlocks WHERE club_slug=? AND achievement_key IN ($placeholders)");$del->execute(array_merge([$clubSlug],$mcaKeys));foreach(array_keys($seen) as $id){foreach($mcaKeys as $key)if(str_ends_with($id,"\0".$key)){unset($seen[$id]);break;}}}
            $sql="SELECT COALESCE(a.canonical_username_key,s.raw_username_key) username_key,f.id file_id,f.original_name,
                         COALESCE(f.effective_event_date,DATE(f.uploaded_at)) event_date,
                         COALESCE(f.event_date_precision,'upload-fallback') event_date_precision,
                         SUM(s.score) score,
                         CASE WHEN COUNT(s.games)>0 THEN SUM(s.games) ELSE NULL END games,
                         CASE WHEN COUNT(s.wins)>0 THEN SUM(s.wins) ELSE NULL END wins,
                         MAX(s.streak) streak,MAX(s.max_wins) max_wins,MIN(NULLIF(s.rank_value,0)) rank_value
                  FROM p2k_lr_source_rows s
                  JOIN p2k_lr_files f ON f.club_slug=s.club_slug AND f.id=s.file_id
                  LEFT JOIN p2k_lr_attributions a ON a.club_slug=s.club_slug AND a.file_id=s.file_id AND a.source_row_no=s.source_row_no AND a.conflict=0
                  WHERE s.club_slug=? AND f.status='processed'
                  GROUP BY COALESCE(a.canonical_username_key,s.raw_username_key),f.id,f.original_name,f.effective_event_date,f.uploaded_at,f.event_date_precision
                  ORDER BY username_key,event_date,f.id";
            $q=$this->analytics->prepare($sql);$q->execute([$clubSlug]);$mcaState=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){
                $u=(string)$r['username_key'];if($u==='')continue;
                if(!isset($mcaState[$u]))$mcaState[$u]=['points'=>0.0,'arenas'=>0,'top10'=>0,'top3'=>0,'firsts'=>0,'best_streak'=>0,'max_wins'=>0];
                $st=&$mcaState[$u];$st['points']+=(float)$r['score'];$st['arenas']++;
                $rank=$r['rank_value']!==null?(int)$r['rank_value']:0;if($rank===1)$st['firsts']++;if($rank>0&&$rank<=3)$st['top3']++;if($rank>0&&$rank<=10)$st['top10']++;
                $st['best_streak']=max($st['best_streak'],(int)($r['streak']??0));$st['max_wins']=max($st['max_wins'],(int)($r['max_wins']??0));
                $date=(string)($r['event_date']??'');$at=$date!==''?$date.' 12:00:00':null;
                $datePrecision=(string)($r['event_date_precision']??'upload-fallback');$precision=$datePrecision==='known'?'mca-event-date':($datePrecision==='interpolated'?'mca-interpolated':'mca-upload-fallback');
                $name=(string)$r['original_name'];$slug=preg_replace('/\.csv$/i','',$name)??$name;$url='https://www.chess.com/tournament/live/arena/'.rawurlencode($slug);
                foreach([['live-rank-pawn',50],['live-rank-knight',150],['live-rank-bishop',500],['live-rank-rook',2500],['live-rank-queen',7500],['live-rank-king',15000]] as $m)if($st['points']>=$m[1])$record($u,$m[0],$at,$precision,'mca',$name,$url);
                foreach([[1,'mca-debut'],[10,'mca-10'],[50,'mca-50'],[100,'mca-100'],[250,'mca-250']] as $m)if($st['arenas']>=$m[0])$record($u,$m[1],$at,$precision,'mca',$name,$url);
                if($st['top10']>=1)$record($u,'mca-top10',$at,$precision,'mca',$name,$url);if($st['top10']>=10)$record($u,'mca-top10-10',$at,$precision,'mca',$name,$url);
                if($st['top3']>=1)$record($u,'mca-podium',$at,$precision,'mca',$name,$url);if($st['top3']>=5)$record($u,'mca-podium-5',$at,$precision,'mca',$name,$url);
                foreach([[1,'mca-win-1'],[5,'mca-win-5'],[10,'mca-win-10']] as $m)if($st['firsts']>=$m[0])$record($u,$m[1],$at,$precision,'mca',$name,$url);
                foreach([[3,'mca-streak-3'],[5,'mca-streak-5'],[10,'mca-streak-10'],[15,'mca-streak-15'],[20,'mca-streak-20']] as $m)if($st['best_streak']>=$m[0])$record($u,$m[1],$at,$precision,'mca',$name,$url);
                foreach([5,10,15,20,25,50,75,100] as $n)if($st['max_wins']>=$n)$record($u,'mca-wins-'.$n,$at,$precision,'mca',$name,$url);
                unset($st);
            }
        } catch(\Throwable) {}

        // Tournament medals are filesystem-backed, but achievement unlocks are persisted in Analytics.
        try {
            $archivePath=dirname(__DIR__,3).'/data/tournaments/archive.json';
            $archive=is_file($archivePath)?json_decode((string)file_get_contents($archivePath),true):null;
            $events=[];
            foreach(is_array($archive['tournaments']??null)?$archive['tournaments']:[] as $t){
                if(!is_array($t)||strtolower((string)($t['status']??''))!=='finished')continue;
                $period=(string)($t['periodSort']??'');
                $finishRaw=(string)($t['finishAt']??'');
                $finishTs=$finishRaw!==''?strtotime($finishRaw):false;
                // A tournament period is only an ordering hint. Never convert it into a fake award date.
                $at=$finishTs!==false?gmdate('Y-m-d H:i:s',$finishTs):null;
                $precision=$finishTs!==false?'tournament-finish':'tournament-pending';
                $sortKey=$finishTs!==false?gmdate('Y-m-d H:i:s',$finishTs):($period!==''?$period.'-99 23:59:59':'9999-12-99 23:59:59');
                $eventName=(string)($t['name']??$t['title']??$t['slug']??'Tournament');
                $eventUrl=(string)($t['webUrl']??$t['url']??'');
                $podium=is_array($t['podium']??null)?$t['podium']:[];
                foreach(['gold','silver','bronze'] as $medal)foreach(is_array($podium[$medal]??null)?$podium[$medal]:[] as $name){$user=strtolower(trim((string)$name));if($user!=='')$events[]=['u'=>$user,'medal'=>$medal,'at'=>$at,'period'=>$period,'sort_key'=>$sortKey,'precision'=>$precision,'name'=>$eventName,'url'=>$eventUrl];}
            }
            usort($events,static fn(array $a,array $b):int=>strcmp((string)$a['sort_key'],(string)$b['sort_key'])?:strcmp((string)$a['period'],(string)$b['period'])?:strcmp((string)$a['name'],(string)$b['name'])?:strcmp((string)$a['u'],(string)$b['u']));
            $medals=[];
            foreach($events as $e){$u=$e['u'];if(!isset($medals[$u]))$medals[$u]=['total'=>0,'gold'=>0,'silver'=>0,'bronze'=>0];$medals[$u]['total']++;if(isset($medals[$u][$e['medal']]))$medals[$u][$e['medal']]++;$at=$e['at'];
                if($medals[$u]['total']===1)$record($u,'tournament-first-medal',$at,$e['precision'],'tournament',$e['name'],$e['url']);
                if($medals[$u]['gold']===1)$record($u,'tournament-first-gold',$at,$e['precision'],'tournament',$e['name'],$e['url']);
                if($medals[$u]['total']===5)$record($u,'tournament-medals-5',$at,$e['precision'],'tournament',$e['name'],$e['url']);
                if($medals[$u]['total']===10)$record($u,'tournament-medals-10',$at,$e['precision'],'tournament',$e['name'],$e['url']);
                if($medals[$u]['gold']>0&&$medals[$u]['silver']>0&&$medals[$u]['bronze']>0)$record($u,'tournament-medal-set',$at,$e['precision'],'tournament',$e['name'],$e['url']);
            }
        } catch(\Throwable) {}

        // Breadth achievements are meta-achievements and never count toward their own
        // breadth. Preserve the complete v2.9.0 legacy ladder (5/10/15/all) and add
        // the later 1/5/10/15/20 distinct-group ladder under separate keys. This is
        // intentionally additive: an old unlock is never renamed into a new one.
        try {
            $byKey=[];foreach(AchievementCatalog::all() as $item)$byKey[(string)$item['key']]=(string)$item['category'];
            $eligible=AchievementCatalog::eligibleBreadthCategories();$eligibleSet=array_fill_keys($eligible,true);
            $legacyEligible=AchievementCatalog::legacyBreadthCategories();$legacySet=array_fill_keys($legacyEligible,true);$legacyAllTarget=count($legacyEligible);
            $q=$this->analytics->prepare("SELECT username_key,achievement_key,COALESCE(earned_at,first_recorded_at) event_at FROM p2k_an_achievement_unlocks WHERE club_slug=? ORDER BY username_key,event_at,achievement_key");
            $q->execute([$clubSlug]);$cats=[];$legacyCats=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$category=$byKey[(string)$r['achievement_key']]??'';if($category==='')continue;$u=(string)$r['username_key'];$at=(string)$r['event_at'];if(isset($eligibleSet[$category])&&(!isset($cats[$u][$category])||strcmp($at,(string)$cats[$u][$category])<0))$cats[$u][$category]=$at;if(isset($legacySet[$category])&&(!isset($legacyCats[$u][$category])||strcmp($at,(string)$legacyCats[$u][$category])<0))$legacyCats[$u][$category]=$at;}
            foreach(array_unique(array_merge(array_keys($cats),array_keys($legacyCats))) as $u){
                $legacyDates=array_values($legacyCats[$u]??[]);sort($legacyDates,SORT_STRING);$legacyCount=count($legacyDates);
                // Historical v2.9.0 identities are evaluated only against their frozen
                // original 21-category universe.
                foreach([[5,'groups-5'],[10,'groups-10'],[15,'groups-15']] as $m)if($legacyCount>=$m[0])$record((string)$u,$m[1],$legacyDates[$m[0]-1]??end($legacyDates),'achievement-threshold');
                if($legacyAllTarget>0&&$legacyCount>=$legacyAllTarget)$record((string)$u,'groups-all',$legacyDates[$legacyAllTarget-1]??end($legacyDates),'achievement-threshold');
                $dates=array_values($cats[$u]??[]);sort($dates,SORT_STRING);$count=count($dates);
                // New additive ladder can recognize newly introduced behavioral groups.
                foreach([[1,'breadth-groups-1'],[5,'breadth-groups-5'],[10,'breadth-groups-10'],[15,'breadth-groups-15'],[20,'breadth-groups-20']] as $m)if($count>=$m[0])$record((string)$u,$m[1],$dates[$m[0]-1]??end($dates),'achievement-threshold');
            }
        } catch(\Throwable) {}

        // Collector milestones measure catalogue depth, not category breadth. Collector
        // achievements themselves are excluded so an unlock can never advance its own ladder.
        try{
            $byKey=[];foreach(AchievementCatalog::all() as $item)$byKey[(string)$item['key']]=(string)$item['category'];
            $q=$this->analytics->prepare("SELECT username_key,achievement_key,COALESCE(earned_at,first_recorded_at) event_at FROM p2k_an_achievement_unlocks WHERE club_slug=? ORDER BY username_key,event_at,achievement_key");
            $q->execute([$clubSlug]);$counts=[];$owned=[];
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$key=(string)$r['achievement_key'];if(($byKey[$key]??'')==='achievement-collector')continue;$u=(string)$r['username_key'];if(isset($owned[$u][$key]))continue;$owned[$u][$key]=true;$counts[$u]=($counts[$u]??0)+1;$n=$counts[$u];foreach([[25,'collector-25'],[50,'collector-50'],[75,'collector-75'],[100,'collector-100'],[125,'collector-125']] as $m)if($n===$m[0])$record($u,$m[1],(string)$r['event_at'],'achievement-count-threshold');}
        }catch(\Throwable){}
        return count($seen);
    }

}
