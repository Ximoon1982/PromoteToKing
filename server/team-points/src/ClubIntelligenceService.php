<?php
declare(strict_types=1);
namespace P2K\TeamPoints;

use PDO;
use P2K\Shared\FilesystemCache;

final class ClubIntelligenceService
{
    public function __construct(
        private readonly PDO $core,
        private readonly PDO $analytics,
        private readonly string $clubSlug
    ) {}

    private ?array $memberRowsCache = null;

    private function one(PDO $pdo, string $sql, array $args = []): array
    {
        $q = $pdo->prepare($sql); $q->execute($args); $row = $q->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private function all(PDO $pdo, string $sql, array $args = []): array
    {
        $q = $pdo->prepare($sql); $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function optionalOne(PDO $pdo, string $sql, array $args = []): array
    {
        try { return $this->one($pdo,$sql,$args); }
        catch (\Throwable $e) { error_log('P2K member intelligence optional query: '.$e->getMessage()); return []; }
    }

    private function optionalAll(PDO $pdo, string $sql, array $args = []): array
    {
        try { return $this->all($pdo,$sql,$args); }
        catch (\Throwable $e) { error_log('P2K member intelligence optional query: '.$e->getMessage()); return []; }
    }

    private static function epoch(?string $value): ?int
    {
        if (!$value) return null;
        $t = strtotime($value . (preg_match('/(?:Z|[+-]\d\d:?\d\d)$/', $value) ? '' : ' UTC'));
        return $t === false ? null : $t;
    }

    private static function ageSeconds(?string $value): ?int
    {
        $t = self::epoch($value); return $t === null ? null : max(0, time() - $t);
    }

    private static function ageDays(?string $value): ?int
    {
        $seconds = self::ageSeconds($value); return $seconds === null ? null : (int)floor($seconds / 86400);
    }

    private static function activityClass(?int $days): string
    {
        return $days === null ? 'unknown' : ($days <= 30 ? 'active' : ($days <= 90 ? 'cooling' : ($days <= 180 ? 'inactive' : 'dormant')));
    }

    private function decorateMember(array $member, array $load = [], array $total = [], array $contrib = [], array $consistency = []): array
    {
        $r=$member+$total+$load+$contrib;
        $r['points']=(float)($r['points']??0);
        foreach(['matches','games','wins','draws','losses','registered_boards','in_progress_boards'] as $k) $r[$k]=(int)($r[$k]??0);
        foreach(['league_points','standard_points','chess960_points','strength_adjusted_points'] as $k) $r[$k]=round((float)($r[$k]??0),2);
        $last=$r['last_game_at']??$r['last_seen_at']??null;
        $days=self::ageDays(is_string($last)?$last:null);
        $stdDays=self::ageDays(is_string($r['last_standard_game_at']??null)?$r['last_standard_game_at']:null);
        $c960Days=self::ageDays(is_string($r['last_chess960_game_at']??null)?$r['last_chess960_game_at']:null);
        $loadCount=$r['in_progress_boards']+$r['registered_boards'];
        $ratingAge=self::ageDays(is_string($r['rating_updated_at']??null)?$r['rating_updated_at']:null);
        $fresh=$ratingAge===null?0:max(0,100-min(100,$ratingAge));
        $activity=$days===null?10:max(0,100-min(100,$days));
        $penalty=min(65,$r['in_progress_boards']*6+$r['registered_boards']*3);
        $availability=max(0,min(100,(int)round(.55*$activity+.25*$fresh+20-$penalty)));
        $r['last_activity']=$last;$r['activity_days']=$days;$r['activity_class']=self::activityClass($days);
        $r['standard_activity_days']=$stdDays;$r['standard_activity_class']=self::activityClass($stdDays);
        $r['chess960_activity_days']=$c960Days;$r['chess960_activity_class']=self::activityClass($c960Days);
        $r['rating_age_days']=$ratingAge;$r['current_load']=$loadCount;$r['overloaded']=$loadCount>=8;$r['availability_score']=$availability;
        $r['points_per_game']=$r['games']>0?round($r['points']/$r['games'],3):0.0;
        $r['points_per_board']=$r['matches']>0?round($r['points']/$r['matches'],3):0.0;
        $r['win_rate']=$r['games']>0?round(100*$r['wins']/$r['games'],1):0.0;
        $avg=(float)($consistency['avg_month_points_x2']??0);$sd=(float)($consistency['sd_month_points_x2']??0);
        $r['consistency_score']=$avg>0?round(max(0,100-min(100,100*$sd/$avg)),1):null;
        return $r;
    }

    private function memberRow(string $usernameKey): ?array
    {
        $m=$this->one($this->core,"SELECT member_id,username_key,username,current_member,daily_rating,chess960_rating,rating_updated_at,last_seen_at,first_seen_at FROM p2k_tp_members WHERE club_slug=? AND current_member=1 AND username_key=? LIMIT 1",[$this->clubSlug,$usernameKey]);
        if(!$m)return null;
        $load=$this->optionalOne($this->core,"SELECT SUM(mm.status='registered') registered_boards,SUM(mm.status='in_progress') in_progress_boards FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id=? AND mm.status IN ('registered','in_progress')",[$this->clubSlug,(int)$m['member_id']]);
        $total=$this->optionalOne($this->analytics,"SELECT username_key,points,matches,games,wins,draws,losses,last_game_at,last_standard_game_at,last_chess960_game_at FROM p2k_an_player_totals WHERE club_slug=? AND username_key=? LIMIT 1",[$this->clubSlug,$usernameKey]);
        $contrib=$this->optionalOne($this->core,"SELECT SUM(CASE WHEN mm.is_league=1 THEN g.points_x2 ELSE 0 END)/2.0 league_points,SUM(CASE WHEN LOWER(COALESCE(mm.rules,'')) IN ('chess','standard') THEN g.points_x2 ELSE 0 END)/2.0 standard_points,SUM(CASE WHEN LOWER(COALESCE(mm.rules,'')) IN ('chess960','960') THEN g.points_x2 ELSE 0 END)/2.0 chess960_points,SUM((g.points_x2/2.0)*LEAST(1.25,GREATEST(.75,COALESCE(mm.opponent_avg_rating,1500)/1500.0))) strength_adjusted_points FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id=? AND mm.is_void=0",[$this->clubSlug,(int)$m['member_id']]);
        $consistency=$this->optionalOne($this->analytics,"SELECT AVG(points_x2) avg_month_points_x2,STDDEV_POP(points_x2) sd_month_points_x2 FROM p2k_an_player_monthly WHERE club_slug=? AND username_key=? AND month_start>=DATE_FORMAT(UTC_DATE()-INTERVAL 5 MONTH,'%Y-%m-01')",[$this->clubSlug,$usernameKey]);
        return $this->decorateMember($m,$load,$total,$contrib,$consistency);
    }

    public function memberRows(int $limit = 5000): array
    {
        $limit = max(1, min(10000, $limit));
        if($limit>=5000 && $this->memberRowsCache!==null)return $this->memberRowsCache;
        $members = $this->all($this->core,
            "SELECT member_id,username_key,username,current_member,daily_rating,chess960_rating,rating_updated_at,last_seen_at
             FROM p2k_tp_members WHERE club_slug=? AND current_member=1 ORDER BY member_id LIMIT {$limit}", [$this->clubSlug]);
        $loads = $this->all($this->core,
            "SELECT b.member_id,SUM(mm.status='registered') registered_boards,SUM(mm.status='in_progress') in_progress_boards
             FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id
             WHERE mm.status IN ('registered','in_progress') GROUP BY b.member_id", [$this->clubSlug]);
        $totals = $this->all($this->analytics,
            "SELECT username_key,points,matches,games,wins,draws,losses,last_game_at,last_standard_game_at,last_chess960_game_at
             FROM p2k_an_player_totals WHERE club_slug=?", [$this->clubSlug]);
        $contrib = $this->all($this->core,
            "SELECT u.username_key,
                    SUM(CASE WHEN m.is_league=1 THEN g.points_x2 ELSE 0 END)/2.0 league_points,
                    SUM(CASE WHEN LOWER(COALESCE(m.rules,'')) IN ('chess','standard') THEN g.points_x2 ELSE 0 END)/2.0 standard_points,
                    SUM(CASE WHEN LOWER(COALESCE(m.rules,'')) IN ('chess960','960') THEN g.points_x2 ELSE 0 END)/2.0 chess960_points,
                    SUM((g.points_x2/2.0)*LEAST(1.25,GREATEST(.75,COALESCE(m.opponent_avg_rating,1500)/1500.0))) strength_adjusted_points
             FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id
             JOIN p2k_tp_members u ON u.member_id=b.member_id
             JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id
             WHERE u.club_slug=? AND m.is_void=0 GROUP BY u.username_key", [$this->clubSlug]);
        $consistency = $this->all($this->analytics,
            "SELECT username_key,AVG(points_x2) avg_month_points_x2,STDDEV_POP(points_x2) sd_month_points_x2
             FROM p2k_an_player_monthly WHERE club_slug=? AND month_start>=DATE_FORMAT(UTC_DATE()-INTERVAL 5 MONTH,'%Y-%m-01')
             GROUP BY username_key", [$this->clubSlug]);

        $byLoad=[]; foreach($loads as $r) $byLoad[(int)$r['member_id']]=$r;
        $byTotal=[]; foreach($totals as $r) $byTotal[(string)$r['username_key']]=$r;
        $byContrib=[]; foreach($contrib as $r) $byContrib[(string)$r['username_key']]=$r;
        $byConsistency=[]; foreach($consistency as $r) $byConsistency[(string)$r['username_key']]=$r;

        $rows=[];
        foreach($members as $m){
            $key=(string)$m['username_key'];
            $rows[]=$this->decorateMember($m,$byLoad[(int)$m['member_id']]??[],$byTotal[$key]??[],$byContrib[$key]??[],$byConsistency[$key]??[]);
        }
        usort($rows,static fn($a,$b)=>(float)$b['points']<=>(float)$a['points']?:strcmp((string)$a['username_key'],(string)$b['username_key']));
        if($limit>=5000)$this->memberRowsCache=$rows;
        return $rows;
    }

    public function teamDepthReport(): array
    {
        $bands=[];
        $summary=[];
        $members=$this->memberRows();
        foreach(['daily_rating'=>'daily','chess960_rating'=>'chess960'] as $col=>$mode){
            $stats=['members'=>count($members),'rated'=>0,'unrated'=>0,'active'=>0,'available'=>0,'overloaded'=>0];
            foreach($members as $r){
                $rating=$r[$col]===null?null:(int)$r[$col];
                if($rating===null || $rating<=0){$stats['unrated']++;continue;}
                $stats['rated']++;
                if($r['activity_class']==='active')$stats['active']++;
                if((int)$r['availability_score']>=60)$stats['available']++;
                if($r['overloaded'])$stats['overloaded']++;
                $min=(int)floor($rating/100)*100; $band=$min.'-'.($min+99); $id=$mode.'|'.$band;
                $b=$bands[$id]??['mode'=>$mode,'band'=>$band,'minimum'=>$min,'members'=>0,'active'=>0,'available'=>0,'overloaded'=>0];
                $b['members']++; if($r['activity_class']==='active')$b['active']++; if((int)$r['availability_score']>=60)$b['available']++; if($r['overloaded'])$b['overloaded']++;
                $bands[$id]=$b;
            }
            $den=max(1,(int)$stats['rated']);
            foreach(['active','available','overloaded'] as $key)$stats[$key.'_percent']=round(100*(int)$stats[$key]/$den,1);
            $stats['rating_coverage_percent']=round(100*(int)$stats['rated']/max(1,(int)$stats['members']),1);
            $summary[$mode]=$stats;
        }
        $out=array_values($bands);
        usort($out,static fn($a,$b)=>strcmp($a['mode'],$b['mode'])?:((int)$a['minimum']<=>(int)$b['minimum']));
        return ['rows'=>$out,'summary'=>$summary];
    }

    public function teamDepth(): array
    {
        return $this->teamDepthReport()['rows'];
    }

    public function memberActivity(): array
    {
        $rows=$this->memberRows(); $summary=['active'=>0,'cooling'=>0,'inactive'=>0,'dormant'=>0,'unknown'=>0];
        $standard=$summary; $chess960=$summary;
        foreach($rows as $r){$summary[$r['activity_class']]++;$standard[$r['standard_activity_class']]++;$chess960[$r['chess960_activity_class']]++;}
        return ['summary'=>$summary,'standard_summary'=>$standard,'chess960_summary'=>$chess960,'rows'=>$rows];
    }

    private function tournamentFreshness(): array
    {
        $root=dirname(__DIR__,3);
        $path=$root.'/data/tournaments/archive.json';
        $at=null; $statusAt=null; $source='none'; $count=null;

        $readArchive=static function(string $candidate): ?array {
            if(!is_file($candidate)) return null;
            $data=json_decode((string)@file_get_contents($candidate),true);
            return is_array($data)?$data:null;
        };
        $useArchive=static function(array $data,string $candidateSource) use (&$at,&$statusAt,&$source,&$count): void {
            $last=$data['lastStatusRefresh']??null;
            $at=is_array($last)?($last['at']??null):(is_string($last)?$last:null);
            $statusAt=$data['scanState']['lastStatusCheckAt']??(is_array($last)?($last['statusCheckedAt']??null):null);
            $at ??= $data['scanState']['lastSync']??($data['generatedAt']??null);
            $statusAt ??= $at;
            $count=is_array($data['tournaments']??null)?count($data['tournaments']):null;
            $source=$candidateSource;
        };

        $primary=$readArchive($path);
        if(is_array($primary) && (!empty($primary['tournaments']) || !empty($primary['reinitializedAt']))){
            $useArchive($primary,'archive');
            $at ??= gmdate('Y-m-d H:i:s',(int)filemtime($path));
        } else {
            // Keep Admin freshness aligned with TournamentService read-time recovery.
            // Older standalone releases could overwrite the live archive with an empty placeholder.
            $backups=glob(dirname($path).'/backups/archive-*.json')?:[];
            rsort($backups,SORT_STRING);
            foreach(array_merge([$path.'.bak'],$backups) as $candidate){
                $data=$readArchive($candidate);
                if(!is_array($data) || empty($data['tournaments'])) continue;
                $useArchive($data,'backup:'.basename($candidate));
                $at ??= gmdate('Y-m-d H:i:s',(int)filemtime($candidate));
                break;
            }
            if($source==='none'){
                $browse=$root.'/data/tournaments/cache/browse-index-v1.json';
                $cache=$readArchive($browse);
                $rows=is_array($cache['index']['tournaments']??null)?$cache['index']['tournaments']:[];
                if($rows){
                    $at=$cache['index']['generatedAt']??($cache['generatedAt']??null);
                    $at ??= gmdate('Y-m-d H:i:s',(int)filemtime($browse));
                    $count=count($rows);
                    $source='browse-index-cache';
                }
            }
            if($source==='none' && is_array($primary)){
                $useArchive($primary,'archive-empty');
                $at ??= is_file($path)?gmdate('Y-m-d H:i:s',(int)filemtime($path)):null;
            }
        }
        return ['observed_at'=>$at,'age_seconds'=>self::ageSeconds(is_string($at)?$at:null),'status_checked_at'=>$statusAt,'status_age_seconds'=>self::ageSeconds(is_string($statusAt)?$statusAt:null),'tournament_count'=>$count,'source'=>$source];
    }

    public function freshnessCoverage(): array
    {
        $state=$this->one($this->core,'SELECT * FROM p2k_tp_state WHERE club_slug=?',[$this->clubSlug]);
        $cfg=\p2k_tp_config(); $app=is_array($cfg['app']??null)?$cfg['app']:[];
        $matchesFresh=max(86400,(int)($app['player_reconcile_matches_refresh_seconds']??604800));
        $statsFresh=max(86400,(int)($app['player_reconcile_stats_refresh_seconds']??259200));
        $matchesCutoff=gmdate('Y-m-d H:i:s',time()-$matchesFresh);
        $statsCutoff=gmdate('Y-m-d H:i:s',time()-$statsFresh);
        $members=$this->one($this->core,
            "SELECT COUNT(*) members,SUM(current_member=1) current_members,
                    SUM(current_member=1 AND (daily_rating IS NOT NULL OR chess960_rating IS NOT NULL)) rated,
                    SUM(current_member=1 AND daily_rating IS NULL AND chess960_rating IS NULL) unrated,
                    SUM(current_member=1 AND avatar_url IS NOT NULL AND avatar_url<>'') avatars,
                    SUM(current_member=1 AND rating_updated_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY) fresh_ratings,
                    SUM(current_member=1 AND player_matches_checked_at IS NULL) matches_never_checked,
                    SUM(current_member=1 AND stats_checked_at IS NULL) stats_never_checked,
                    SUM(current_member=1 AND (player_matches_checked_at IS NULL OR player_matches_checked_at<?)) matches_due,
                    SUM(current_member=1 AND (stats_checked_at IS NULL OR stats_checked_at<?)) stats_due,
                    SUM(current_member=1 AND GREATEST(COALESCE(player_matches_checked_at,'1970-01-01'),COALESCE(player_matches_observed_at,'1970-01-01'))<?) matches_operational_due,
                    SUM(current_member=1 AND GREATEST(COALESCE(stats_checked_at,'1970-01-01'),COALESCE(stats_observed_at,'1970-01-01'))<?) stats_operational_due,
                    SUM(current_member=1 AND player_matches_observed_at IS NOT NULL) matches_observed,
                    SUM(current_member=1 AND stats_observed_at IS NOT NULL) stats_observed,
                    SUM(current_member=1 AND stats_observed_at IS NOT NULL AND (rating_updated_at IS NULL OR stats_observed_at>rating_updated_at)) newer_observed_ratings,
                    MIN(CASE WHEN current_member=1 AND rating_updated_at IS NOT NULL THEN rating_updated_at END) oldest_rating_at
             FROM p2k_tp_members WHERE club_slug=?",[$matchesCutoff,$statsCutoff,$matchesCutoff,$statsCutoff,$this->clubSlug]);
        $boards=$this->one($this->core,
            "SELECT COUNT(*) boards,SUM(b.state='complete_immutable') complete,SUM(b.state='failed_malformed') failed,
                    SUM(b.state IN ('newly_discovered','recent_in_progress','potentially_incomplete')) pending,
                    SUM(mm.status='finished') finished_boards,
                    SUM(mm.status='finished' AND b.state='complete_immutable') finished_complete,
                    SUM(mm.status='finished' AND b.state<>'complete_immutable') finished_unresolved,
                    SUM(mm.status IN ('registered','in_progress') AND b.state<>'complete_immutable') active_backlog,
                    MIN(CASE WHEN mm.status='finished' AND b.state<>'complete_immutable' THEN COALESCE(b.last_checked_at,b.last_discovered_at) END) oldest_finished_unresolved_at
             FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id",[$this->clubSlug]);
        $jobs=$this->one($this->core,
            "SELECT SUM(i.status IN ('pending','retry','running')) pending,SUM(i.status='failed') failed,
                    MIN(CASE WHEN i.status IN ('pending','retry','running') THEN i.updated_at END) oldest_pending_at
             FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE j.club_slug=?",[$this->clubSlug]);
        $refreshRows=$this->all($this->analytics,'SELECT domain_key,source_watermark,refreshed_at,row_count,last_error FROM p2k_an_refresh_state WHERE club_slug=?',[$this->clubSlug]);
        $refresh=[];$latestAnalytics=null; foreach($refreshRows as $r){$refresh[(string)$r['domain_key']]=$r;$t=self::epoch((string)$r['refreshed_at']);if($t!==null)$latestAnalytics=max($latestAnalytics??0,$t);}
        $totals=$this->one($this->analytics,'SELECT * FROM p2k_tp_club_totals WHERE club_slug=?',[$this->clubSlug]);
        $cur=max(1,(int)($members['current_members']??0)); $allBoards=max(1,(int)($boards['boards']??0)); $finished=max(1,(int)($boards['finished_boards']??0));
        $coreUpdated=self::epoch($state['updated_at']??null); $lag=($coreUpdated!==null&&$latestAnalytics!==null)?max(0,$coreUpdated-$latestAnalytics):null;
        return [
            'core'=>$state,'analytics'=>$refresh,'totals'=>$totals,'tournaments'=>$this->tournamentFreshness(),
            'ages'=>[
                'roster_seconds'=>self::ageSeconds($state['members_last_verified_at']??null),
                'club_index_seconds'=>self::ageSeconds($state['club_index_last_verified_at']??null),
                'roster_observed_seconds'=>self::ageSeconds($state['members_last_observed_at']??null),
                'club_index_observed_seconds'=>self::ageSeconds($state['club_index_last_observed_at']??null),
                'oldest_rating_seconds'=>self::ageSeconds($members['oldest_rating_at']??null),
                'oldest_finished_unresolved_seconds'=>self::ageSeconds($boards['oldest_finished_unresolved_at']??null),
                'oldest_queue_seconds'=>self::ageSeconds($jobs['oldest_pending_at']??null),
                'core_analytics_lag_seconds'=>$lag,
            ],
            'coverage'=>[
                'current_members'=>(int)($members['current_members']??0),'unrated_members'=>(int)($members['unrated']??0),
                'player_matches_due'=>(int)($members['matches_due']??0),'player_stats_due'=>(int)($members['stats_due']??0),
                'player_matches_never_checked'=>(int)($members['matches_never_checked']??0),'player_stats_never_checked'=>(int)($members['stats_never_checked']??0),
                'player_matches_fresh_percent'=>round(100*max(0,$cur-(int)($members['matches_due']??0))/$cur,1),
                'player_stats_fresh_percent'=>round(100*max(0,$cur-(int)($members['stats_due']??0))/$cur,1),
                'player_matches_operational_due'=>(int)($members['matches_operational_due']??0),'player_stats_operational_due'=>(int)($members['stats_operational_due']??0),
                'player_matches_operational_fresh_percent'=>round(100*max(0,$cur-(int)($members['matches_operational_due']??0))/$cur,1),
                'player_stats_operational_fresh_percent'=>round(100*max(0,$cur-(int)($members['stats_operational_due']??0))/$cur,1),
                'player_matches_observed_members'=>(int)($members['matches_observed']??0),'player_stats_observed_members'=>(int)($members['stats_observed']??0),
                'newer_observed_ratings'=>(int)($members['newer_observed_ratings']??0),
                'rating_percent'=>round(100*(int)($members['rated']??0)/$cur,1),'fresh_rating_percent'=>round(100*(int)($members['fresh_ratings']??0)/$cur,1),'avatar_percent'=>round(100*(int)($members['avatars']??0)/$cur,1),
                'board_complete_percent'=>round(100*(int)($boards['complete']??0)/$allBoards,1),
                'finished_board_complete_percent'=>round(100*(int)($boards['finished_complete']??0)/$finished,1),
                'finished_boards'=>(int)($boards['finished_boards']??0),'finished_boards_unresolved'=>(int)($boards['finished_unresolved']??0),'active_board_backlog'=>(int)($boards['active_backlog']??0),
                'boards_failed'=>(int)($boards['failed']??0),'boards_pending'=>(int)($boards['pending']??0),'queue_pending'=>(int)($jobs['pending']??0),'queue_failed'=>(int)($jobs['failed']??0)
            ]
        ];
    }

    public function anomalies(): array
    {
        $f=$this->freshnessCoverage(); $c=$f['coverage']; $a=$f['ages']; $items=[];
        $add=static function(array &$x,string $severity,string $code,string $title,string $detail,string $action=''):void{$x[]=['severity'=>$severity,'code'=>$code,'title'=>$title,'detail'=>$detail,'action'=>$action];};
        $roster=$a['roster_seconds'];
        if($roster===null||$roster>7200)$add($items,'high','roster-stale','Roster freshness is critically stale','Authoritative roster has not been fetched successfully within 2 hours (30-minute target; hard floor 60 minutes).','Run/resume Player worker');
        elseif($roster>3600)$add($items,'medium','roster-late','Roster refresh is late','Authoritative roster fetch is older than 60 minutes (30-minute target; hard floor breached).','Inspect Player worker');
        $clubIndex=$a['club_index_seconds'];
        if($clubIndex===null||$clubIndex>1800)$add($items,'high','club-index-stale','Club match index is critically stale','Authoritative club-index fetch is older than 30 minutes (5-minute target; hard floor 60 minutes).','Run Club worker');
        elseif($clubIndex>900)$add($items,'medium','club-index-late','Club match index refresh is late','Authoritative club-index fetch is older than 15 minutes (5-minute target).','Inspect Club worker');
        if($c['boards_failed']>0)$add($items,'high','failed-boards','Failed boards need attention',number_format($c['boards_failed']).' board records are failed/malformed.','Open scheduled task control');
        if($c['queue_failed']>0)$add($items,'high','failed-jobs','Failed queue items detected',number_format($c['queue_failed']).' worker queue items are failed.','Open scheduled task control');
        if($c['fresh_rating_percent']<70)$add($items,'medium','rating-stale','Rating coverage is aging',number_format($c['fresh_rating_percent'],1).'% of current members have a rating refreshed in the last 30 days.','Run/resume Player refresh');
        if($c['finished_board_complete_percent']<99.5)$add($items,'medium','finished-board-coverage','Finished-board verification is incomplete',number_format($c['finished_board_complete_percent'],1).'% of finished-match boards are immutable/complete.','Inspect worker backlog');
        if($c['finished_boards_unresolved']>0 && ($a['oldest_finished_unresolved_seconds']??0)>86400)$add($items,'high','old-finished-board','Finished board unresolved for over 24 hours',number_format($c['finished_boards_unresolved']).' finished boards still require verification.','Inspect failed/pending board work');
        if(($a['core_analytics_lag_seconds']??0)>3600)$add($items,'medium','analytics-lag','Analytics trails Core by over one hour','Core has newer authoritative data than the latest Analytics refresh.','Run Analytics maintenance');
        $ta=$f['tournaments']['age_seconds']??null;
        $tsa=$f['tournaments']['status_age_seconds']??$ta;
        if($ta===null||$ta>3600)$add($items,'high','tournament-stale','Tournament maintenance is critically stale','Tournament CRON maintenance has not run within 60 minutes (10-minute target).','Open Tournament Management');
        elseif($ta>1800)$add($items,'medium','tournament-late','Tournament maintenance is late','Tournament CRON maintenance is older than 30 minutes (10-minute target).','Inspect Tournament worker');
        if($tsa!==null&&$tsa>1800)$add($items,'medium','tournament-status-late','Tournament status checking is late','Stored unfinished-tournament status checking is older than 30 minutes.','Open Tournament Management');
        $snap=$this->snapshots(); if(count($snap)>=2){$x=$snap[count($snap)-2]['metrics']??[];$y=$snap[count($snap)-1]['metrics']??[];if(($y['club_points']??0)<($x['club_points']??0))$add($items,'high','points-regression','Club Points regressed','Latest daily snapshot contains fewer Club Points than previous snapshot.','Run consistency audit');}
        return $items;
    }

    private function adminActionsFrom(array $items): array
    {
        $map=['roster-stale'=>'TaskControl.html#greenSchedulerControl','roster-late'=>'TaskControl.html#greenSchedulerControl','club-index-stale'=>'TaskControl.html#greenSchedulerControl','club-index-late'=>'TaskControl.html#greenSchedulerControl','failed-boards'=>'TaskControl.html#greenSchedulerControl','failed-jobs'=>'TaskControl.html#greenSchedulerControl','rating-stale'=>'TaskControl.html#greenSchedulerControl','finished-board-coverage'=>'InsightsHealth.html','old-finished-board'=>'TaskControl.html#greenSchedulerControl','analytics-lag'=>'TaskControl.html#greenSchedulerControl','tournament-stale'=>'TournamentManagement.html','tournament-late'=>'TournamentManagement.html','tournament-status-late'=>'TournamentManagement.html','points-regression'=>'TaskControl.html#greenSchedulerControl'];
        return array_map(static fn($x)=>$x+['url'=>$map[$x['code']]??'ClubIntelligence.html'],$items);
    }
    public function adminActions(): array { return $this->adminActionsFrom($this->anomalies()); }

    public function forecasts(): array
    {
        $daily=$this->all($this->analytics,"SELECT activity_date,club_points,boards_started,boards_finished FROM p2k_tp_insight_daily WHERE club_slug=? AND activity_date>=UTC_DATE()-INTERVAL 89 DAY ORDER BY activity_date",[$this->clubSlug]);
        $blocks=[[],[],[]]; foreach($daily as $r){$age=(int)floor((time()-(strtotime((string)$r['activity_date'].' UTC')?:time()))/86400);$blocks[min(2,max(0,(int)floor($age/30)))][]=$r;}
        $rates=[]; foreach($blocks as $rows)$rates[]=count($rows)?array_sum(array_map(static fn($r)=>(float)$r['club_points'],$rows))/count($rows):0;
        $recent=$rates[0]??0; $older=$rates[2]??$recent; $trend=$older>0?max(-.08,min(.08,($recent-$older)/$older)):0; $medium=max(0,$recent*(1+$trend));
        $today=new \DateTimeImmutable('now',new \DateTimeZone('UTC')); $end=new \DateTimeImmutable($today->format('Y').'-12-31',new \DateTimeZone('UTC')); $days=max(0,(int)$today->diff($end)->format('%a'));
        $total=$this->one($this->analytics,'SELECT club_points FROM p2k_tp_club_totals WHERE club_slug=?',[$this->clubSlug]); $base=(float)($total['club_points']??0);
        return ['as_of'=>$today->format('Y-m-d'),'current_points'=>$base,'days_remaining'=>$days,'daily_rates'=>['recent_30'=>$rates[0]??0,'previous_30'=>$rates[1]??0,'older_30'=>$rates[2]??0],'trend_adjustment'=>round($trend*100,1),'low'=>round($base+$days*$medium*.9),'medium'=>round($base+$days*$medium),'high'=>round($base+$days*$medium*1.1),'drivers'=>['Recent 30-day Club Points rate is the primary signal.','The three 30-day blocks add a capped ±8% trend bend.','Low/High scenarios are ±10% around the medium rate.','Authoritative void 0–0 matches remain excluded.']];
    }

    private function intelligenceDir(): string {$c=\p2k_tp_config();$d=FilesystemCache::runtimeRoot(is_array($c['storage']??null)?$c['storage']:[]).'/intelligence';FilesystemCache::ensureProtectedDirectory($d);return $d;}
    private function snapshotDir(): string {$d=$this->intelligenceDir().'/snapshots';FilesystemCache::ensureProtectedDirectory($d);return $d;}

    public function captureDailySnapshot(bool $force=false): array
    {
        $path=$this->snapshotDir().'/'.gmdate('Y-m-d').'.json'; if(!$force&&is_file($path))return json_decode((string)file_get_contents($path),true)?:[];
        $f=$this->freshnessCoverage(); $activity=$this->memberActivity(); $t=$f['totals'];
        $data=['date'=>gmdate('Y-m-d'),'captured_at'=>gmdate(DATE_ATOM),'metrics'=>[
            'current_members'=>$f['coverage']['current_members'],'club_points'=>(float)($t['club_points']??0),'finished_matches'=>(int)($t['finished_matches']??0),'finished_boards'=>(int)($t['finished_boards']??0),'finished_games'=>(int)($t['finished_games']??0),
            'won_matches'=>(int)($t['won_matches']??0),'drawn_matches'=>(int)($t['drawn_matches']??0),'lost_matches'=>(int)($t['lost_matches']??0),
            'active_30'=>(int)($activity['summary']['active']??0),'standard_active_30'=>(int)($activity['standard_summary']['active']??0),'chess960_active_30'=>(int)($activity['chess960_summary']['active']??0),
            'rating_coverage'=>$f['coverage']['rating_percent'],'finished_board_coverage'=>$f['coverage']['finished_board_complete_percent'],'core_generation'=>(int)($f['core']['core_generation']??0)
        ]];
        @file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX); return $data;
    }

    public function snapshots(int $limit=365): array
    {
        $files=glob($this->snapshotDir().'/*.json')?:[]; sort($files,SORT_STRING); $files=array_slice($files,-max(1,min(1000,$limit))); $out=[];
        foreach($files as $path){$row=json_decode((string)@file_get_contents($path),true);if(is_array($row))$out[]=$row;} return $out;
    }

    public function snapshotComparisons(): array
    {
        $rows=$this->snapshots(400); if(!$rows)return ['latest'=>null,'comparisons'=>[]]; $latest=$rows[count($rows)-1]; $latestDate=strtotime((string)$latest['date'].' UTC')?:time(); $targets=['previous'=>1,'week'=>7,'month'=>30]; $out=[];
        foreach($targets as $label=>$days){$best=null;$bestDistance=PHP_INT_MAX;$target=$latestDate-$days*86400;foreach($rows as $row){$ts=strtotime((string)($row['date']??'').' UTC')?:0;if($ts>=$latestDate)continue;$distance=abs($ts-$target);if($distance<$bestDistance){$best=$row;$bestDistance=$distance;}}if($best){$delta=[];foreach(['current_members','club_points','finished_matches','finished_boards','active_30','rating_coverage'] as $k)$delta[$k]=round((float)($latest['metrics'][$k]??0)-(float)($best['metrics'][$k]??0),2);$out[$label]=['from_date'=>$best['date'],'to_date'=>$latest['date'],'delta'=>$delta];}}
        return ['latest'=>$latest,'comparisons'=>$out];
    }

    public function automaticAnomalyScan(bool $force=false): array
    {
        $path=$this->intelligenceDir().'/anomaly-latest.json'; if(!$force&&is_file($path)&&time()-(int)@filemtime($path)<3600){$cached=json_decode((string)@file_get_contents($path),true);if(is_array($cached))return $cached;}
        $items=$this->anomalies(); $actions=$this->adminActionsFrom($items); $data=['scanned_at'=>gmdate(DATE_ATOM),'anomalies'=>$items,'actions'=>$actions,'summary'=>['total'=>count($items),'high'=>count(array_filter($items,static fn($x)=>(string)($x['severity']??'')==='high')),'medium'=>count(array_filter($items,static fn($x)=>(string)($x['severity']??'')==='medium'))]];
        @file_put_contents($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),LOCK_EX); return $data;
    }

    public function opponentBalance(): array
    {
        $summary=$this->one($this->analytics,
            "SELECT COUNT(*) finished_matches,
                    SUM(p2k_avg_rating IS NOT NULL AND opponent_avg_rating IS NOT NULL AND rated_board_count>0) paired_rating_matches
             FROM p2k_an_match_facts
             WHERE club_slug=? AND status='finished' AND is_void=0 AND board_count>0",[$this->clubSlug]);
        $rows=$this->all($this->analytics,
            "SELECT match_id,board_count,rated_board_count,is_league,rules,p2k_avg_rating,opponent_avg_rating,end_time,opponent_slug,opponent_name
             FROM p2k_an_match_facts
             WHERE club_slug=? AND status='finished' AND is_void=0 AND board_count>0
               AND p2k_avg_rating IS NOT NULL AND opponent_avg_rating IS NOT NULL
               AND rated_board_count IS NOT NULL AND rated_board_count>0
             ORDER BY end_time,match_id",[$this->clubSlug]);
        $out=[];
        foreach($rows as $r){
            $rules=strtolower(trim((string)($r['rules']??'')));
            $chess=in_array($rules,['chess960','960'],true)?'chess960':(in_array($rules,['chess','standard','daily'],true)?'classical':'unknown');
            $p=(int)$r['p2k_avg_rating'];$o=(int)$r['opponent_avg_rating'];$rated=(int)$r['rated_board_count'];$boards=max(1,(int)$r['board_count']);
            if($p<=0||$o<=0||$rated<=0)continue;
            $out[]=[
                'match_id'=>(int)$r['match_id'],'boards'=>(int)$r['board_count'],'rated_boards'=>$rated,
                'rated_coverage_percent'=>round(100*$rated/$boards,1),
                'match_type'=>(int)$r['is_league']===1?'league':'friendly','chess_type'=>$chess,
                'p2k_avg_rating'=>$p,'opponent_avg_rating'=>$o,'avg_rating_delta'=>$p-$o,
                'opponent_slug'=>(string)($r['opponent_slug']??''),'opponent_name'=>(string)($r['opponent_name']??''),
                'rating_source'=>'paired_board_positions'
            ];
        }
        $total=(int)($summary['finished_matches']??0);$paired=(int)($summary['paired_rating_matches']??0);
        return [
            'rows'=>$out,
            'rating_source'=>'same valid rated board positions on both teams; historical rows without paired-board provenance are omitted until authoritative revalidation',
            'rated_board_count_available'=>true,
            'coverage'=>['finished_matches'=>$total,'paired_rating_matches'=>$paired,'paired_match_percent'=>$total>0?round(100*$paired/$total,1):0]
        ];
    }

    public function opponentProfiles(int $limit=30): array
    {
        $limit=max(1,min(100,$limit));
        $rows=$this->all($this->analytics,"SELECT opponent_slug,display_name,club_url,matches,finished,wins,draws,losses,ongoing,registered,total_boards,our_points,their_points,first_match_at,last_match_at,(wins+draws+losses) result_covered,GREATEST(0,finished-(wins+draws+losses)) result_missing,CASE WHEN finished>0 THEN ROUND(100*(wins+draws+losses)/finished,1) ELSE 100 END result_coverage_percent,CASE WHEN (wins+draws+losses)>0 THEN ROUND(100*wins/(wins+draws+losses),1) ELSE 0 END win_rate FROM p2k_an_opponent_stats WHERE club_slug=? ORDER BY matches DESC,opponent_slug LIMIT {$limit}",[$this->clubSlug]);
        $behaviour=$this->all($this->analytics,
            "SELECT opponent_slug,COUNT(*) sample_matches,ROUND(AVG(board_count),1) avg_boards,ROUND(AVG(opponent_avg_rating),0) avg_opponent_rating,
                    SUM(is_league=1) league_matches,SUM(is_league=0) friendly_matches,
                    SUM(end_time>=UTC_TIMESTAMP()-INTERVAL 90 DAY) matches_last_90d,
                    ROUND(AVG(CASE WHEN status='finished' THEN p2k_score-opponent_score END),2) avg_score_margin
             FROM p2k_an_match_facts WHERE club_slug=? AND opponent_slug IS NOT NULL AND opponent_slug<>'' AND is_void=0 GROUP BY opponent_slug",[$this->clubSlug]);
        $bm=[];foreach($behaviour as $r)$bm[(string)$r['opponent_slug']]=$r;
        $icons=[];try{foreach($this->all($this->core,'SELECT opponent_slug,icon_url,icon_checked_at,disabled,last_error FROM p2k_tp_opponents WHERE club_slug=?',[$this->clubSlug]) as $r)$icons[(string)$r['opponent_slug']]=$r;}catch(\Throwable){}
        foreach($rows as &$r){$slug=(string)$r['opponent_slug'];$i=$icons[$slug]??[];$b=$bm[$slug]??[];$r['club_url']=Repository::chessClubHumanUrl((string)($r['club_url']??''),$slug);$r['icon_url']=(string)($i['icon_url']??'');$r['icon_checked_at']=$i['icon_checked_at']??null;$r['disabled']=(bool)($i['disabled']??false);$r['profile_error']=$i['last_error']??null;$r['result_covered']=(int)($r['result_covered']??0);$r['result_missing']=(int)($r['result_missing']??0);$r['result_coverage_percent']=(float)($r['result_coverage_percent']??0);$r['avg_boards']=(float)($b['avg_boards']??0);$r['avg_opponent_rating']=$b['avg_opponent_rating']===null?null:(int)$b['avg_opponent_rating'];$r['league_matches']=(int)($b['league_matches']??0);$r['friendly_matches']=(int)($b['friendly_matches']??0);$r['matches_last_90d']=(int)($b['matches_last_90d']??0);$r['avg_score_margin']=(float)($b['avg_score_margin']??0);}
        unset($r); return $rows;
    }


    /** v2.10.7: resolve every physical member row belonging to one MIAC identity. */
    private function canonicalMemberIds(int $fallbackMemberId,string $usernameKey): array
    {
        $canonical=$usernameKey;
        try{
            $r=$this->optionalOne($this->core,"SELECT COALESCE(im.canonical_username_key,u.username_key) canonical_username_key FROM p2k_tp_members u LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0 WHERE u.club_slug=? AND u.member_id=? LIMIT 1",[$this->clubSlug,$fallbackMemberId]);
            if(!empty($r['canonical_username_key']))$canonical=(string)$r['canonical_username_key'];
            $rows=$this->optionalAll($this->core,"SELECT u.member_id FROM p2k_tp_members u LEFT JOIN p2k_miac_canonical_map im ON im.club_slug=u.club_slug AND im.username_key=u.username_key AND im.conflict=0 WHERE u.club_slug=? AND COALESCE(im.canonical_username_key,u.username_key)=? ORDER BY u.member_id",[$this->clubSlug,$canonical]);
            $ids=array_values(array_unique(array_filter(array_map(static fn($x)=>(int)($x['member_id']??0),$rows),static fn($x)=>$x>0)));
            if($ids)return $ids;
        }catch(\Throwable $e){error_log('P2K canonical progress identity: '.$e->getMessage());}
        return [$fallbackMemberId];
    }

    private static function memberScopeSql(array $memberIds): string
    {
        $ids=array_values(array_unique(array_filter(array_map('intval',$memberIds),static fn($x)=>$x>0)));
        return $ids?implode(',',$ids):'0';
    }

    /** Durable lower-tier evidence: no monotonic ladder may display below an earned tier. */
    private static function progressFloorIdentity(string $key): ?array
    {
        if($key==='first-match')return ['matches',1.0];
        if($key==='first-point')return ['team-points-total',1.0];
        if(preg_match('/^matches-(\d+)$/',$key,$m))return ['matches',(float)$m[1]];
        if(preg_match('/^games-(\d+)$/',$key,$m))return ['games',(float)$m[1]];
        if(preg_match('/^wins-(\d+)$/',$key,$m))return ['wins',(float)$m[1]];
        $daily=['daily-pawn'=>10,'daily-knight'=>20,'daily-bishop'=>50,'daily-rook'=>100,'daily-queen'=>150,'daily-king'=>250,'daily-bronze-king'=>500,'daily-silver-king'=>1000,'daily-gold-king'=>1500,'daily-platinum-king'=>2000,'daily-amethyst-king'=>3000,'daily-topaz-king'=>4000,'daily-emerald-king'=>5500,'daily-sapphire-king'=>7000,'daily-ruby-king'=>8500,'daily-diamond-king'=>10000];
        if(isset($daily[$key]))return ['team-points-total',(float)$daily[$key]];
        $live=['live-rank-pawn'=>50,'live-rank-knight'=>150,'live-rank-bishop'=>500,'live-rank-rook'=>2500,'live-rank-queen'=>7500,'live-rank-king'=>15000];
        if(isset($live[$key]))return ['mca-points',(float)$live[$key]];
        $generalLeague=['league-debut'=>1,'league-regular'=>10,'league-veteran'=>25,'league-specialist'=>50,'league-legend'=>100];
        if(isset($generalLeague[$key]))return ['league-matches',(float)$generalLeague[$key]];
        if($key==='multi-league')return ['league-kinds',3.0];if($key==='all-league')return ['league-kinds',5.0];
        if(preg_match('/^(1wl|pcl|tcmac|tmcl|kotml)-(competitor|veteran|legend)$/',$key,$m))return ['league-'.$m[1].'-matches',(float)(['competitor'=>1,'veteran'=>10,'legend'=>20][$m[2]])];
        if(preg_match('/^(1wl|pcl|tcmac|tmcl|kotml)-(first-point|scorer|specialist|master)$/',$key,$m))return ['league-'.$m[1].'-points',(float)(['first-point'=>1,'scorer'=>5,'specialist'=>10,'master'=>20][$m[2]])];
        if(preg_match('/^mca-(?:debut|(10|50|100|250))$/',$key,$m))return ['mca-arenas',$key==='mca-debut'?1.0:(float)$m[1]];
        if($key==='mca-top10')return ['mca-top10',1.0];if($key==='mca-top10-10')return ['mca-top10',10.0];
        if($key==='mca-podium')return ['mca-podium',1.0];if($key==='mca-podium-5')return ['mca-podium',5.0];
        if(preg_match('/^mca-win-(\d+)$/',$key,$m))return ['mca-wins',(float)$m[1]];
        if(preg_match('/^mca-streak-(\d+)$/',$key,$m))return ['mca-streak',(float)$m[1]];
        if(preg_match('/^mca-wins-(\d+)$/',$key,$m))return ['mca-wins-one-arena',(float)$m[1]];
        if(preg_match('/^same-day-matches-(\d+)$/',$key,$m))return ['same-day-matches',(float)$m[1]];
        if(preg_match('/^concurrent-games-(\d+)$/',$key,$m))return ['concurrent-games',(float)$m[1]];
        if(preg_match('/^groups-(5|10|15)$/',$key,$m))return ['legacy-breadth',(float)$m[1]];
        if($key==='groups-all')return ['legacy-breadth',21.0];
        if(preg_match('/^breadth-groups-(\d+)$/',$key,$m))return ['breadth',(float)$m[1]];
        if(preg_match('/^collector-(\d+)$/',$key,$m))return ['collector',(float)$m[1]];
        if(preg_match('/^rivalry-(\d+)$/',$key,$m))return ['rivalry',(float)$m[1]];
        if(preg_match('/^opponent-countries-(\d+)$/',$key,$m))return ['opponent-countries',(float)$m[1]];
        if(preg_match('/^chess960-matches-(\d+)$/',$key,$m))return ['chess960',(float)$m[1]];
        if(preg_match('/^active-months-(\d+)$/',$key,$m))return ['active-months',(float)$m[1]];
        if(preg_match('/^large-match-(\d+)$/',$key,$m))return ['large-match',(float)$m[1]];
        if(preg_match('/^upset-(\d+)$/',$key,$m))return ['upset',(float)$m[1]];
        if($key==='match-score-15')return ['match-score',3.0];if($key==='match-score-20')return ['match-score',4.0];
        if(preg_match('/^match-start-streak-(\d+)$/',$key,$m))return ['match-start-streak',(float)$m[1]];
        if(preg_match('/^match-winner-(\d+)$/',$key,$m))return ['match-winner',(float)$m[1]];
        if(preg_match('/^match-saver-(\d+)$/',$key,$m))return ['match-saver',(float)$m[1]];
        if($key==='photo-finish-5')return ['close-call',5.0];if(preg_match('/^close-call-(?:veteran-20|master-50|legend-100)$/',$key))return ['close-call',str_ends_with($key,'-20')?20.0:(str_ends_with($key,'-50')?50.0:100.0)];
        if(preg_match('/^winning-side-(\d+)$/',$key,$m))return ['winning-side',(float)$m[1]];
        if(preg_match('/^opponent-variety-(\d+)$/',$key,$m))return ['opponent-variety',(float)$m[1]];
        if(preg_match('/^old-foes-(\d+)$/',$key,$m))return ['old-foes',(float)$m[1]];
        if(preg_match('/^team-points-(day|week|month|year)-(\d+)$/',$key,$m))return ['team-points-'.$m[1],(float)$m[2]];
        return null;
    }

    public function memberProfile(string $username): array
    {
        $key=\p2k_tp_username_key($username);$row=$this->memberRow($key);if(!$row)return ['found'=>false,'username'=>$username];
        $memberIds=$this->canonicalMemberIds((int)$row['member_id'],$key);$memberScope=self::memberScopeSql($memberIds);
        $live=$this->optionalOne($this->analytics,'SELECT total_points,arena_count,total_games,total_wins,total_draws,total_losses,best_streak,max_wins_single_arena,best_rank,first_place_count,top3_count,top10_count FROM p2k_lr_players WHERE club_slug=? AND username_key=? LIMIT 1',[$this->clubSlug,$key]);
        $row['live']=['points'=>(float)($live['total_points']??0),'arenas'=>(int)($live['arena_count']??0),'games'=>(int)($live['total_games']??0),'wins'=>(int)($live['total_wins']??0),'draws'=>(int)($live['total_draws']??0),'losses'=>(int)($live['total_losses']??0),'best_streak'=>(int)($live['best_streak']??0),'max_wins_single_arena'=>(int)($live['max_wins_single_arena']??0),'best_rank'=>(int)($live['best_rank']??0),'first_place_count'=>(int)($live['first_place_count']??0),'top3'=>(int)($live['top3_count']??0),'top10'=>(int)($live['top10_count']??0)];
        $recent=$this->optionalOne($this->core,
            "SELECT SUM(CASE WHEN g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 7 DAY THEN g.points_x2 ELSE 0 END)/2.0 points_7d,
                    SUM(CASE WHEN g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 7 DAY THEN 1 ELSE 0 END) games_7d,
                    SUM(CASE WHEN g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 7 DAY AND g.points_x2=2 THEN 1 ELSE 0 END) wins_7d,
                    SUM(CASE WHEN g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 7 DAY AND g.points_x2=1 THEN 1 ELSE 0 END) draws_7d,
                    SUM(CASE WHEN g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 7 DAY AND g.points_x2=0 THEN 1 ELSE 0 END) losses_7d,
                    SUM(CASE WHEN g.game_end_utc<UTC_TIMESTAMP()-INTERVAL 7 DAY AND g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 14 DAY THEN g.points_x2 ELSE 0 END)/2.0 previous_points_7d,
                    SUM(CASE WHEN g.game_end_utc<UTC_TIMESTAMP()-INTERVAL 7 DAY AND g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 14 DAY THEN 1 ELSE 0 END) previous_games_7d
             FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id
             JOIN p2k_tp_match_metadata m ON m.club_slug=u.club_slug AND m.match_id=b.match_id
             WHERE u.club_slug=? AND u.username_key=? AND m.is_void=0 AND g.game_end_utc>=UTC_TIMESTAMP()-INTERVAL 14 DAY",[$this->clubSlug,$key]);
        $row['recent_7d']=['points'=>(float)($recent['points_7d']??0),'games'=>(int)($recent['games_7d']??0),'wins'=>(int)($recent['wins_7d']??0),'draws'=>(int)($recent['draws_7d']??0),'losses'=>(int)($recent['losses_7d']??0),'previous_points'=>(float)($recent['previous_points_7d']??0),'previous_games'=>(int)($recent['previous_games_7d']??0)];
        $current=$this->optionalOne($this->core,"SELECT SUM(mm.status='registered') registered_matches,SUM(mm.status='in_progress') in_progress_matches FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id JOIN p2k_tp_match_metadata mm ON mm.club_slug=u.club_slug AND mm.match_id=b.match_id WHERE u.club_slug=? AND u.username_key=? AND mm.status IN ('registered','in_progress')",[$this->clubSlug,$key]);
        $row['current_matches']=['registered'=>(int)($current['registered_matches']??0),'in_progress'=>(int)($current['in_progress_matches']??0)];
        $total=$this->optionalOne($this->analytics,'SELECT club_points FROM p2k_tp_club_totals WHERE club_slug=?',[$this->clubSlug]);$club=max(1,(float)($total['club_points']??0));$row['club_point_share_percent']=round(100*(float)$row['points']/$club,2);
        // Player-catalogue progress uses the same authoritative stored metrics as
        // achievement unlock generation. It remains an intelligence backend
        // concern even though Member Intelligence is no longer shown on the
        // normal Dashboard/Profile surface.
        $leagueRows=$this->optionalAll($this->core,"SELECT mm.match_id,mm.match_name,1 matches,COALESCE(SUM(g.points_x2),0)/2.0 points FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 AND mm.is_league=1 GROUP BY mm.match_id,mm.match_name",[$this->clubSlug]);
        $league=['matches'=>0,'leagues'=>[]];
        foreach($leagueRows as $lr){$name=strtoupper((string)($lr['match_name']??''));$league['matches']+=(int)($lr['matches']??0);foreach(['1WL','PCL','TCMAC','TMCL','KOTML'] as $code){if(preg_match('/(^|[^A-Z0-9])'.preg_quote($code,'/').'([^A-Z0-9]|$)/',$name)){if(!isset($league['leagues'][strtolower($code)]))$league['leagues'][strtolower($code)]=['matches'=>0,'points'=>0.0];$league['leagues'][strtolower($code)]['matches']+=(int)($lr['matches']??0);$league['leagues'][strtolower($code)]['points']+=(float)($lr['points']??0);break;}}}
        // v2.9.0 achievement-family progress metrics. These are reconstructed only
        // from authoritative stored participation/match/game intervals; incomplete
        // finished boards are excluded from concurrency rather than guessed.
        $sameDay=$this->optionalOne($this->core,"SELECT MAX(day_matches) peak FROM (SELECT DATE(mm.start_time) d,COUNT(DISTINCT mm.match_id) day_matches FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 AND mm.start_time IS NOT NULL AND mm.start_time<=UTC_TIMESTAMP() GROUP BY DATE(mm.start_time)) x",[$this->clubSlug]);
        $concurrentPeak=0;try{$concurrentPeak=$this->concurrentGamesPeak($memberIds);}catch(\Throwable $e){error_log('P2K member intelligence concurrency metric: '.$e->getMessage());}
        $earnedKeys=array_map(static fn($r)=>(string)$r['achievement_key'],$this->optionalAll($this->analytics,'SELECT achievement_key FROM p2k_an_achievement_unlocks WHERE club_slug=? AND username_key=?',[$this->clubSlug,$key]));
        $categoryByKey=[];foreach(AchievementCatalog::all() as $a)$categoryByKey[(string)$a['key']]=(string)$a['category'];
        $eligible=array_fill_keys(AchievementCatalog::eligibleBreadthCategories(),true);$legacyEligible=array_fill_keys(AchievementCatalog::legacyBreadthCategories(),true);$earnedCategories=[];$legacyEarnedCategories=[];
        $earnedNonCollector=0;foreach($earnedKeys as $achievementKey){$cat=$categoryByKey[$achievementKey]??'';if(isset($eligible[$cat]))$earnedCategories[$cat]=true;if(isset($legacyEligible[$cat]))$legacyEarnedCategories[$cat]=true;if($cat!==''&&$cat!=='achievement-collector')$earnedNonCollector++;}
        $newMetrics=$this->optionalOne($this->core,"SELECT
            (SELECT MAX(cnt) FROM (SELECT COUNT(DISTINCT mm.match_id) cnt FROM p2k_tp_boards bx JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=bx.match_id LEFT JOIN p2k_tp_opponent_aliases oa ON oa.club_slug=mm.club_slug AND oa.alias_slug=mm.opponent_slug WHERE bx.member_id IN ($memberScope) AND mm.is_void=0 AND COALESCE(oa.canonical_slug,mm.opponent_slug,'')<>'' GROUP BY COALESCE(oa.canonical_slug,mm.opponent_slug)) r) rivalry_max,
            COUNT(DISTINCT CASE WHEN COALESCE(o.country_code,'')<>'' THEN o.country_code END) opponent_countries,
            COUNT(DISTINCT CASE WHEN LOWER(COALESCE(mm.rules,'')) LIKE '%960%' THEN mm.match_id END) chess960_matches,
            MAX(mm.board_count) largest_match_boards,
            MAX(CASE WHEN g.points_x2=2 AND b.p2k_rating IS NOT NULL AND b.opponent_rating IS NOT NULL THEN b.opponent_rating-b.p2k_rating ELSE NULL END) max_upset_delta
            FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id LEFT JOIN p2k_tp_opponent_aliases oa2 ON oa2.club_slug=mm.club_slug AND oa2.alias_slug=mm.opponent_slug LEFT JOIN p2k_tp_opponents o ON o.club_slug=mm.club_slug AND o.opponent_slug=COALESCE(oa2.canonical_slug,mm.opponent_slug) LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0",[$this->clubSlug,$this->clubSlug]);
        $months=$this->optionalAll($this->core,"SELECT DISTINCT DATE_FORMAT(COALESCE(mm.start_time,mm.end_time),'%Y-%m') ym FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 AND COALESCE(mm.start_time,mm.end_time) IS NOT NULL ORDER BY ym",[$this->clubSlug]);
        $maxMonthStreak=0;$currentStreak=0;$previous=null;foreach($months as $mr){$ym=(string)($mr['ym']??'');if(!preg_match('/^(\d{4})-(\d{2})$/',$ym,$mx))continue;$idx=((int)$mx[1])*12+(int)$mx[2];$currentStreak=$previous!==null&&$idx===$previous+1?$currentStreak+1:1;$previous=$idx;$maxMonthStreak=max($maxMonthStreak,$currentStreak);}
        $boardPerf=$this->optionalOne($this->core,"SELECT MAX(points_x2) best_board_points_x2,MAX(two_draws) two_draws FROM (SELECT SUM(g.points_x2) points_x2,CASE WHEN SUM(g.points_x2=1)>=2 THEN 1 ELSE 0 END two_draws FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 GROUP BY b.board_id HAVING COUNT(g.game_row_id)>=2) z",[$this->clubSlug]);
        $participations=$this->optionalAll($this->core,"SELECT mm.match_id,mm.start_time,mm.end_time,mm.status,mm.result,mm.p2k_score,mm.opponent_score,COALESCE(oa.canonical_slug,mm.opponent_slug) opponent_slug,COALESCE(SUM(g.points_x2),0) player_points_x2 FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id LEFT JOIN p2k_tp_opponent_aliases oa ON oa.club_slug=mm.club_slug AND oa.alias_slug=mm.opponent_slug LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 GROUP BY mm.match_id,mm.start_time,mm.end_time,mm.status,mm.result,mm.p2k_score,mm.opponent_score,COALESCE(oa.canonical_slug,mm.opponent_slug) ORDER BY COALESCE(mm.start_time,mm.end_time),mm.match_id",[$this->clubSlug]);
        $startDays=[];$opponents=[];$rematches=0;$closeCalls=0;$winningSide=0;$matchWinners=0;$matchSavers=0;
        foreach($participations as $pr){$start=(string)($pr['start_time']??'');if($start!=='')$startDays[substr($start,0,10)]=true;$opp=strtolower(trim((string)($pr['opponent_slug']??'')));if($opp!==''){if(isset($opponents[$opp]))$rematches++;else $opponents[$opp]=true;}if(strtolower((string)($pr['status']??''))!=='finished')continue;$result=strtolower((string)($pr['result']??''));$p2k=(float)($pr['p2k_score']??0);$other=(float)($pr['opponent_score']??0);if(abs($p2k-$other)<=1.000001)$closeCalls++;if($result==='win')$winningSide++;$own=(int)($pr['player_points_x2']??0);$p2kx2=(int)round($p2k*2);$otherx2=(int)round($other*2);if($own>0&&$result==='win'&&$p2kx2-$own<=$otherx2)$matchWinners++;if($own>0&&$result==='draw'&&$p2kx2-$own<$otherx2)$matchSavers++;}
        $dayIndexes=[];foreach(array_keys($startDays) as $d){$ts=strtotime($d.' UTC');if($ts!==false)$dayIndexes[]=(int)floor($ts/86400);}sort($dayIndexes,SORT_NUMERIC);$startDayPeak=0;$run=0;$prev=null;foreach($dayIndexes as $idx){$run=$prev!==null&&$idx===$prev+1?$run+1:1;$prev=$idx;$startDayPeak=max($startDayPeak,$run);}
        $periodPeaks=$this->optionalOne($this->core,"SELECT MAX(CASE WHEN period_type='day' THEN points_x2 END)/2.0 team_points_day_peak,MAX(CASE WHEN period_type='week' THEN points_x2 END)/2.0 team_points_week_peak,MAX(CASE WHEN period_type='month' THEN points_x2 END)/2.0 team_points_month_peak,MAX(CASE WHEN period_type='year' THEN points_x2 END)/2.0 team_points_year_peak FROM (SELECT 'day' period_type,DATE(g.game_end_utc) period_key,SUM(g.points_x2) points_x2 FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 GROUP BY DATE(g.game_end_utc) UNION ALL SELECT 'week',YEARWEEK(g.game_end_utc,3),SUM(g.points_x2) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 GROUP BY YEARWEEK(g.game_end_utc,3) UNION ALL SELECT 'month',DATE_FORMAT(g.game_end_utc,'%Y-%m'),SUM(g.points_x2) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 GROUP BY DATE_FORMAT(g.game_end_utc,'%Y-%m') UNION ALL SELECT 'year',YEAR(g.game_end_utc),SUM(g.points_x2) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 GROUP BY YEAR(g.game_end_utc)) p",[$this->clubSlug,$this->clubSlug,$this->clubSlug,$this->clubSlug]);
        $row['achievement_metrics']=['team_points_day_peak'=>(float)($periodPeaks['team_points_day_peak']??0),'team_points_week_peak'=>(float)($periodPeaks['team_points_week_peak']??0),'team_points_month_peak'=>(float)($periodPeaks['team_points_month_peak']??0),'team_points_year_peak'=>(float)($periodPeaks['team_points_year_peak']??0),'same_day_match_starts_peak'=>(int)($sameDay['peak']??0),'concurrent_games_peak'=>$concurrentPeak,'earned_group_count'=>count($earnedCategories),'eligible_group_count'=>count($eligible),'legacy_earned_group_count'=>count($legacyEarnedCategories),'legacy_eligible_group_count'=>count($legacyEligible),'earned_non_collector_count'=>$earnedNonCollector,'rivalry_max'=>(int)($newMetrics['rivalry_max']??0),'opponent_countries'=>(int)($newMetrics['opponent_countries']??0),'chess960_matches'=>(int)($newMetrics['chess960_matches']??0),'active_month_streak'=>$maxMonthStreak,'largest_match_boards'=>(int)($newMetrics['largest_match_boards']??0),'max_upset_delta'=>(int)($newMetrics['max_upset_delta']??0),'best_board_points_x2'=>(int)($boardPerf['best_board_points_x2']??0),'two_draws'=>(int)($boardPerf['two_draws']??0),'consecutive_match_start_days_peak'=>$startDayPeak,'opponent_variety'=>count($opponents),'rematch_count'=>$rematches,'close_call_matches'=>$closeCalls,'winning_side_matches'=>$winningSide,'match_winner_count'=>$matchWinners,'match_saver_count'=>$matchSavers];
        $row['achievement_progress']=$this->achievementProgress($row,$league);$row['challenges']=array_slice($row['achievement_progress'],0,5);return ['found'=>true,'member'=>$row];
    }

    private function concurrentGamesPeak(array $memberIds): int
    {
        $memberScope=self::memberScopeSql($memberIds);if($memberScope==='0')return 0;
        $rows=$this->all($this->core,"SELECT b.board_id,mm.status,mm.start_time,g.game_end_utc FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE b.member_id IN ($memberScope) AND mm.is_void=0 AND mm.start_time IS NOT NULL AND mm.start_time<=UTC_TIMESTAMP() AND mm.status IN ('in_progress','finished') ORDER BY b.board_id,g.game_end_utc",[$this->clubSlug]);
        $boards=[];foreach($rows as $r){$id=(int)$r['board_id'];if(!isset($boards[$id]))$boards[$id]=['status'=>(string)$r['status'],'start'=>(string)$r['start_time'],'ends'=>[]];if(!empty($r['game_end_utc']))$boards[$id]['ends'][]=(string)$r['game_end_utc'];}
        $events=[];foreach($boards as $b){if($b['status']==='finished'&&count($b['ends'])<2)continue;$start=strtotime($b['start'].' UTC');if(!$start)continue;$events[]=[$start,2];foreach($b['ends'] as $end){$ts=strtotime($end.' UTC');if($ts)$events[]=[$ts,-1];}}
        usort($events,static fn($a,$b)=>$a[0]<=>$b[0]?:$a[1]<=>$b[1]);$active=0;$peak=0;foreach($events as [, $delta]){$active=max(0,$active+$delta);$peak=max($peak,$active);}return $peak;
    }

    private function achievementProgress(array $m,array $league=[]): array
    {
        $earned=$this->optionalAll($this->analytics,'SELECT achievement_key FROM p2k_an_achievement_unlocks WHERE club_slug=? AND username_key=?',[$this->clubSlug,(string)$m['username_key']]);
        $set=array_fill_keys(array_map(static fn($r)=>(string)$r['achievement_key'],$earned),true);$out=[];
        $leagueMap=is_array($league['leagues']??null)?$league['leagues']:[];$leagueMatches=(int)($league['matches']??0);$leagueKinds=count(array_filter($leagueMap,static fn($x)=>(int)($x['matches']??0)>0));
        $seniorityBase=null;if(!empty($m['first_seen_at'])){$ts=strtotime((string)$m['first_seen_at'].' UTC');if($ts)$seniorityBase=$ts;}
        foreach(AchievementCatalog::all() as $item){$key=(string)$item['key'];if(isset($set[$key]))continue;$cur=$target=null;$metric='';
            if($key==='first-match'){$cur=(float)$m['matches'];$target=1;$metric='Team matches';}
            elseif($key==='first-point'){$cur=(float)$m['points'];$target=1;$metric='Team Points';}
            elseif(preg_match('/^team-points-(day|week|month|year)-(2|5|10|20|25|50|100|250)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['team_points_'.$x[1].'_peak']??0);$target=(float)$x[2];$metric='Team Points in one '.($x[1]==='day'?'UTC day':($x[1]==='week'?'ISO week':('UTC calendar '.$x[1])));}
            elseif(preg_match('/^matches-(\d+)$/',$key,$x)){$cur=(float)$m['matches'];$target=(float)$x[1];$metric='Team matches';}
            elseif(preg_match('/^games-(\d+)$/',$key,$x)){$cur=(float)$m['games'];$target=(float)$x[1];$metric='Team games';}
            elseif(preg_match('/^wins-(\d+)$/',$key,$x)){$cur=(float)$m['wins'];$target=(float)$x[1];$metric='Team wins';}
            elseif(str_starts_with($key,'daily-')&&preg_match('/Reach ([0-9,]+) Team Points/i',(string)$item['description'],$x)){$cur=(float)$m['points'];$target=(float)str_replace(',','',$x[1]);$metric='Team Points';}
            elseif(str_starts_with($key,'live-rank-')&&preg_match('/Reach ([0-9,]+) MCA points/i',(string)$item['description'],$x)){$cur=(float)($m['live']['points']??0);$target=(float)str_replace(',','',$x[1]);$metric='MCA points';}
            elseif($key==='league-debut'){$cur=$leagueMatches;$target=1;$metric='League matches';}
            elseif($key==='league-regular'){$cur=$leagueMatches;$target=10;$metric='League matches';}
            elseif($key==='league-veteran'){$cur=$leagueMatches;$target=25;$metric='League matches';}
            elseif($key==='league-specialist'){$cur=$leagueMatches;$target=50;$metric='League matches';}
            elseif($key==='league-legend'){$cur=$leagueMatches;$target=100;$metric='League matches';}
            elseif($key==='multi-league'){$cur=$leagueKinds;$target=3;$metric='Leagues represented';}
            elseif($key==='all-league'){$cur=$leagueKinds;$target=5;$metric='Leagues represented';}
            elseif(preg_match('/^(1wl|pcl|tcmac|tmcl|kotml)-(competitor|veteran|legend)$/',$key,$x)){$cur=(float)($leagueMap[$x[1]]['matches']??0);$target=$x[2]==='competitor'?1:($x[2]==='veteran'?10:20);$metric=strtoupper($x[1]).' matches';}
            elseif(preg_match('/^(1wl|pcl|tcmac|tmcl|kotml)-(first-point|scorer|specialist|master)$/',$key,$x)){$cur=(float)($leagueMap[$x[1]]['points']??0);$target=['first-point'=>1,'scorer'=>5,'specialist'=>10,'master'=>20][$x[2]];$metric=strtoupper($x[1]).' points';}
            elseif(preg_match('/^seniority-(1m|3m|6m|1y|2y|3y|5y)$/',$key,$x)&&$seniorityBase!==null){$offset=['1m'=>'+1 month','3m'=>'+3 months','6m'=>'+6 months','1y'=>'+1 year','2y'=>'+2 years','3y'=>'+3 years','5y'=>'+5 years'][$x[1]];$threshold=strtotime($offset,$seniorityBase);if($threshold!==false){$target=max(1,(float)(int)ceil(($threshold-$seniorityBase)/86400));$cur=max(0,(float)(int)floor((time()-$seniorityBase)/86400));$metric='Membership days';}}
            elseif(preg_match('/^mca-(?:debut|(\d+))$/',$key,$x)){$cur=(float)($m['live']['arenas']??0);$target=$key==='mca-debut'?1.0:(float)$x[1];$metric='MCA arenas';}
            elseif($key==='mca-top10'){$cur=(float)($m['live']['top10']??0);$target=1;$metric='MCA top-ten finishes';}
            elseif($key==='mca-top10-10'){$cur=(float)($m['live']['top10']??0);$target=10;$metric='MCA top-ten finishes';}
            elseif($key==='mca-podium'){$cur=(float)($m['live']['top3']??0);$target=1;$metric='MCA podiums';}
            elseif($key==='mca-podium-5'){$cur=(float)($m['live']['top3']??0);$target=5;$metric='MCA podiums';}
            elseif(preg_match('/^mca-win-(\d+)$/',$key,$x)){$cur=(float)($m['live']['first_place_count']??0);$target=(float)$x[1];$metric='MCA wins';}
            elseif(preg_match('/^mca-streak-(\d+)$/',$key,$x)){$cur=(float)($m['live']['best_streak']??0);$target=(float)$x[1];$metric='Best MCA streak';}
            elseif(preg_match('/^mca-wins-(\d+)$/',$key,$x)){$cur=(float)($m['live']['max_wins_single_arena']??0);$target=(float)$x[1];$metric='Wins in one MCA';}
            elseif(preg_match('/^same-day-matches-(2|3|4|5)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['same_day_match_starts_peak']??0);$target=(float)$x[1];$metric='Matches started on one UTC day';}
            elseif(preg_match('/^concurrent-games-(5|10|25|50|100)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['concurrent_games_peak']??0);$target=(float)$x[1];$metric='Concurrent P2K team games';}
            elseif(preg_match('/^groups-(5|10|15)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['legacy_earned_group_count']??0);$target=(float)$x[1];$metric='Original achievement groups represented';}
            elseif($key==='groups-all'){$cur=(float)($m['achievement_metrics']['legacy_earned_group_count']??0);$target=(float)($m['achievement_metrics']['legacy_eligible_group_count']??21);$metric='Original achievement groups represented';}
            elseif(preg_match('/^breadth-groups-(1|5|10|15|20)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['earned_group_count']??0);$target=(float)$x[1];$metric='Distinct achievement groups represented';}
            elseif(preg_match('/^collector-(25|50|75|100|125)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['earned_non_collector_count']??0);$target=(float)$x[1];$metric='Achievements earned (excluding Collector)';}
            elseif(preg_match('/^rivalry-(5|10|25|50)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['rivalry_max']??0);$target=(float)$x[1];$metric='Matches vs one opponent club';}
            elseif(preg_match('/^opponent-countries-(10|25|50)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['opponent_countries']??0);$target=(float)$x[1];$metric='Opponent countries/regions';}
            elseif(preg_match('/^chess960-matches-(10|50|100)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['chess960_matches']??0);$target=(float)$x[1];$metric='Chess960 team matches';}
            elseif(preg_match('/^active-months-(3|6|12)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['active_month_streak']??0);$target=(float)$x[1];$metric='Consecutive active months';}
            elseif(preg_match('/^large-match-(100|200|500)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['largest_match_boards']??0);$target=(float)$x[1];$metric='Largest team match (boards)';}
            elseif(preg_match('/^upset-(100|200|400)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['max_upset_delta']??0);$target=(float)$x[1];$metric='Largest rating upset';}
            elseif($key==='match-score-15'){$cur=(float)($m['achievement_metrics']['best_board_points_x2']??0);$target=3;$metric='Best two-game match score ×2';}
            elseif($key==='match-score-20'){$cur=(float)($m['achievement_metrics']['best_board_points_x2']??0);$target=4;$metric='Best two-game match score ×2';}
            elseif($key==='match-two-draws'){$cur=(float)($m['achievement_metrics']['two_draws']??0);$target=1;$metric='Two-draw match';}
            elseif(preg_match('/^match-start-streak-(3|5|7|10|14)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['consecutive_match_start_days_peak']??0);$target=(float)$x[1];$metric='Consecutive match-start days';}
            elseif(preg_match('/^match-winner-(1|5|10|15|20)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['match_winner_count']??0);$target=(float)$x[1];$metric='Decisive team-match wins';}
            elseif(preg_match('/^match-saver-(1|5|10|15|20)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['match_saver_count']??0);$target=(float)$x[1];$metric='Team matches saved';}
            elseif($key==='photo-finish-5'){$cur=(float)($m['achievement_metrics']['close_call_matches']??0);$target=5;$metric='Close team matches';}
            elseif(preg_match('/^close-call-(?:veteran-20|master-50|legend-100)$/',$key)){$cur=(float)($m['achievement_metrics']['close_call_matches']??0);$target=str_ends_with($key,'-20')?20:(str_ends_with($key,'-50')?50:100);$metric='Close team matches';}
            elseif(preg_match('/^winning-side-(10|50|100|250)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['winning_side_matches']??0);$target=(float)$x[1];$metric='Team-match wins participated in';}
            elseif(preg_match('/^opponent-variety-(25|50|100)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['opponent_variety']??0);$target=(float)$x[1];$metric='Distinct opponent clubs';}
            elseif(preg_match('/^old-foes-(5|10|25)$/',$key,$x)){$cur=(float)($m['achievement_metrics']['rematch_count']??0);$target=(float)$x[1];$metric='Rematch participations';}
            if($target===null||$target<=0||$cur===null)continue;$cur=max(0,(float)$cur);$identity=self::progressFloorIdentity($key);if($identity!==null){$floor=0.0;foreach(array_keys($set) as $earnedKey){$earnedIdentity=self::progressFloorIdentity((string)$earnedKey);if($earnedIdentity!==null&&$earnedIdentity[0]===$identity[0])$floor=max($floor,(float)$earnedIdentity[1]);}$cur=max($cur,$floor);}$remaining=max(0,$target-$cur);$out[]=$item+['current'=>$cur,'target'=>$target,'remaining'=>$remaining,'progress_percent'=>round(min(100,100*$cur/$target),1),'progress_metric'=>$metric];
        }
        usort($out,static fn($a,$b)=>$a['remaining']<=>$b['remaining']?:$b['progress_percent']<=>$a['progress_percent']);return $out;
    }

    /** Backward-compatible admin/internal challenge surface. Player-facing catalogue uses the full progress map. */
    public function achievementChallenges(array $member): array
    {
        return array_slice($this->achievementProgress($member,[]),0,5);
    }


    public function traffic(int $days=90): array
    {
        $root=dirname(__DIR__,3);$report=\P2K\Shared\TrafficAnalytics::report($root,$days);$report['diagnostics']=\P2K\Shared\TrafficAnalytics::diagnostics($root);$report['self_test']=\P2K\Shared\TrafficAnalytics::selfTest($root);return $report;
    }

    public function overview(): array
    {
        $activity=$this->memberActivity();return ['freshness'=>$this->freshnessCoverage(),'activity_summary'=>$activity['summary'],'activity_standard_summary'=>$activity['standard_summary'],'activity_chess960_summary'=>$activity['chess960_summary'],'team_depth'=>$this->teamDepth(),'anomalies'=>$this->anomalies(),'actions'=>$this->adminActions(),'forecast'=>$this->forecasts(),'telemetry'=>RuntimeTelemetry::summary(7),'opponents'=>$this->opponentProfiles(15),'snapshots'=>$this->snapshots(90),'snapshot_comparisons'=>$this->snapshotComparisons()];
    }
}
