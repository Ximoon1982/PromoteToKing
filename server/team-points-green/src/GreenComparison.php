<?php
declare(strict_types=1);

namespace P2K\Green;

use PDO;
use RuntimeException;
use P2K\TeamPoints\Database;

final class GreenComparison
{
    private GreenRepository $green;

    public function __construct(GreenRepository $green)
    {
        $this->green = $green;
    }

    private function blueCore(): PDO
    {
        if (method_exists(Database::class, 'core')) return Database::core();
        if (method_exists(Database::class, 'connection')) return Database::connection();
        throw new RuntimeException('Blue Core database accessor is unavailable.');
    }

    private function tableExists(PDO $pdo, string $name): bool
    {
        $q=$pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
        $q->execute([$name]); return (int)$q->fetchColumn()>0;
    }

    public function blueSummary(): array
    {
        $pdo=$this->blueCore(); $club=$this->green->clubSlug;
        $one=static function(PDO $pdo,string $sql,array $params=[]) {
            $q=$pdo->prepare($sql);$q->execute($params);$v=$q->fetchColumn();
            return is_numeric($v)?(strpos((string)$v,'.') !== false?(float)$v:(int)$v):0;
        };
        $m=$pdo->prepare("SELECT COUNT(*) known_matches,
            COALESCE(SUM(status='registered'),0) registered_matches,
            COALESCE(SUM(status='in_progress'),0) in_progress_matches,
            COALESCE(SUM(status='finished'),0) finished_matches,
            COALESCE(SUM(status='unknown'),0) unknown_matches,
            COALESCE(SUM(status='finished' AND is_void=1),0) cancelled_matches,
            COALESCE(SUM(CASE WHEN status='finished' AND is_void=0 THEN competition_points ELSE 0 END),0) club_points
          FROM p2k_tp_match_metadata WHERE club_slug=?");
        $m->execute([$club]);$matches=$m->fetch()?:[];
        $current=$one($pdo,'SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=? AND current_member=1',[$club]);
        $boards=$this->tableExists($pdo,'p2k_tp_boards')
            ? $one($pdo,'SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?',[$club]) : 0;
        $playersWithPoints=$this->tableExists($pdo,'p2k_tp_point_events')
            ? count(array_filter($this->blueTop(10000),static fn($r)=>(float)($r['points']??0)>0)) : 0;
        return [
            'current_members'=>(int)$current,
            'players_with_points'=>(int)$playersWithPoints,
            'known_matches'=>(int)($matches['known_matches']??0),
            'registered_matches'=>(int)($matches['registered_matches']??0),
            'in_progress_matches'=>(int)($matches['in_progress_matches']??0),
            'finished_matches'=>(int)($matches['finished_matches']??0),
            'cancelled_matches'=>(int)($matches['cancelled_matches']??0),
            'unknown_matches'=>(int)($matches['unknown_matches']??0),
            'total_boards'=>(int)$boards,
            'club_points'=>(float)($matches['club_points']??0),
            'source'=>'blue_core',
        ];
    }

    public function blueTop(int $limit=10): array
    {
        $limit=max(1,min(10000,$limit));$pdo=$this->blueCore();$club=$this->green->clubSlug;
        if(!$this->tableExists($pdo,'p2k_tp_point_events')) return [];
        // Fetch raw Blue totals, then apply Green's trusted identity map in PHP. This makes
        // Blue/Green comparison identity-equivalent without cross-database SQL joins.
        $q=$pdo->prepare("SELECT e.username_key,COALESCE(MAX(m.username),e.username_key) username,
                 COALESCE(SUM(e.points),0) points,COUNT(*) finished_games
              FROM p2k_tp_point_events e
              LEFT JOIN p2k_tp_members m ON m.club_slug=e.club_slug AND m.username_key=e.username_key
              WHERE e.club_slug=? GROUP BY e.username_key");
        $q->execute([$club]);$raw=$q->fetchAll()?:[];
        $imap=[];try{foreach($this->green->core->query('SELECT username_key,canonical_username_key,canonical_username FROM p2k_g_identity_map')->fetchAll()?:[] as $r)$imap[(string)$r['username_key']]=[(string)$r['canonical_username_key'],(string)$r['canonical_username']];}catch(\Throwable $ignored){}
        $agg=[];
        foreach($raw as $r){$rk=(string)$r['username_key'];[$key,$name]=$imap[$rk]??[$rk,(string)$r['username']];$agg[$key]??=['username_key'=>$key,'username'=>$name,'points'=>0.0,'finished_games'=>0];$agg[$key]['points']+=(float)$r['points'];$agg[$key]['finished_games']+=(int)$r['finished_games'];if($name!=='')$agg[$key]['username']=$name;}
        $rows=array_values($agg);usort($rows,static fn($a,$b)=>(float)$b['points']<=>(float)$a['points'] ?: strcmp((string)$a['username_key'],(string)$b['username_key']));return array_slice($rows,0,$limit);
    }

    private function mapByKey(array $rows,string $key): array
    {
        $m=[];foreach($rows as $r)if(is_array($r)&&isset($r[$key]))$m[(string)$r[$key]]=$r;return $m;
    }

    public function playerDiscrepancies(int $limit=50): array
    {
        $limit=max(1,min(200,$limit));
        $blue=$this->blueTop(200);$green=$this->green->greenTop(200);
        $b=$this->mapByKey($blue,'username_key');$g=$this->mapByKey($green,'username_key');$keys=array_unique(array_merge(array_keys($b),array_keys($g)));$out=[];
        foreach($keys as $k){$bp=(float)($b[$k]['points']??0);$gp=(float)($g[$k]['points']??0);$d=$gp-$bp;if(abs($d)<0.0001 && isset($b[$k],$g[$k]))continue;$out[]=['username_key'=>$k,'username'=>(string)($g[$k]['username']??$b[$k]['username']??$k),'blue_points'=>$bp,'green_points'=>$gp,'delta'=>$d,'blue_games'=>(int)($b[$k]['finished_games']??0),'green_games'=>(int)($g[$k]['finished_games']??0),'only_in'=>isset($b[$k])?(isset($g[$k])?null:'blue'):'green'];}
        usort($out,static fn($a,$b)=>abs((float)$b['delta'])<=>abs((float)$a['delta']) ?: strcmp((string)$a['username_key'],(string)$b['username_key']));
        return array_slice($out,0,$limit);
    }

    public function matchDiscrepancies(int $limit=100): array
    {
        $limit=max(1,min(300,$limit));$pdo=$this->blueCore();$club=$this->green->clubSlug;
        $q=$pdo->prepare("SELECT match_id,status,board_count,p2k_score,opponent_score,competition_points,is_void FROM p2k_tp_match_metadata WHERE club_slug=?");$q->execute([$club]);
        $blue=$this->mapByKey($q->fetchAll()?:[],'match_id');
        $gq=$this->green->core->query("SELECT match_id,status,board_count,p2k_score,opponent_score,competition_points,is_void FROM p2k_g_matches WHERE status<>'not_club'");$green=$this->mapByKey($gq->fetchAll()?:[],'match_id');
        $keys=array_unique(array_merge(array_keys($blue),array_keys($green)));rsort($keys,SORT_NUMERIC);$out=[];
        foreach($keys as $k){$b=$blue[$k]??null;$g=$green[$k]??null;if(!$b||!$g){$out[]=['match_id'=>(int)$k,'type'=>$b?'only_blue':'only_green','blue'=>$b,'green'=>$g];}
            else{
                $fields=['status','board_count','p2k_score','opponent_score','competition_points','is_void'];$diff=[];
                foreach($fields as $f){$bv=$b[$f]??null;$gv=$g[$f]??null;if(is_numeric($bv)&&is_numeric($gv)){if(abs((float)$bv-(float)$gv)>0.0001)$diff[$f]=[$bv,$gv];}elseif((string)$bv!==(string)$gv)$diff[$f]=[$bv,$gv];}
                if($diff)$out[]=['match_id'=>(int)$k,'type'=>'different','differences'=>$diff,'blue'=>$b,'green'=>$g];
            }
            if(count($out)>=$limit)break;
        }
        return $out;
    }

    public function summary(): array
    {
        $green=$this->green->greenSummary();$blue=$this->blueSummary();$gt=is_array($green['totals']??null)?$green['totals']:[];
        $keys=['current_members','players_with_points','known_matches','registered_matches','in_progress_matches','finished_matches','cancelled_matches','unknown_matches','total_boards','club_points'];$delta=[];
        foreach($keys as $k)$delta[$k]=(float)($gt[$k]??0)-(float)($blue[$k]??0);
        $state=$this->green->state();return ['public_source'=>(string)($state['public_read_target']??'blue'),'green_freshness'=>'green_core_live','blue'=>$blue,'green'=>$green,'delta'=>$delta,'blue_top10'=>$this->blueTop(10),'green_top10'=>$this->green->greenTop(10),'largest_player_differences'=>$this->playerDiscrepancies(30),'match_differences'=>$this->matchDiscrepancies(100)];
    }

    public function blueTaskState(): array
    {
        $pdo=$this->blueCore();if(!$this->tableExists($pdo,'p2k_control_tasks'))return [];
        // The migration panel only consumes these three stable control fields.
        // Do not select optional telemetry timestamps here: older Blue schemas do not
        // expose last_start_at/last_success_at and native PDO prepares can reject an
        // unknown column before an execute-time fallback can run.
        $keys=['team-points-club','team-points-player'];
        $q=$pdo->prepare("SELECT task_key,status,pause_requested FROM p2k_control_tasks WHERE task_key IN (?,?) ORDER BY task_key");
        $q->execute($keys);
        return $q->fetchAll()?:[];
    }

    public function setBluePaused(bool $paused): void
    {
        $pdo=$this->blueCore();if(!$this->tableExists($pdo,'p2k_control_tasks'))throw new RuntimeException('Blue task control table is unavailable.');
        if($paused){$q=$pdo->prepare("UPDATE p2k_control_tasks SET pause_requested=1 WHERE task_key IN ('team-points-club','team-points-player')");$q->execute();}
        else{$q=$pdo->prepare("UPDATE p2k_control_tasks SET pause_requested=0 WHERE task_key IN ('team-points-club','team-points-player')");$q->execute();}
    }
}
