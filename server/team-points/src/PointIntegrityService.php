<?php
declare(strict_types=1);
namespace P2K\TeamPoints;
use PDO;

/** PIR: detect integrity drift, record it durably, and request authoritative repair only. */
final class PointIntegrityService
{
    public function __construct(private readonly PDO $pdo,private readonly Repository $repo,private readonly string $clubSlug){}

    public function runStep(int $limit=30,?float $deadlineAt=null):array
    {
        $limit=max(1,min(100,$limit));$state=$this->state();$cursor=(int)($state['cursor_match_id']??0);$checked=0;$issues=0;$queued=0;$last=$cursor;
        $q=$this->pdo->prepare("SELECT match_id,board_count,p2k_score,opponent_score,result,competition_points,is_void FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished' AND match_id>? ORDER BY match_id LIMIT {$limit}");$q->execute([$this->clubSlug,$cursor]);$matches=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $job=$this->repo->createOrGetActiveJob($this->clubSlug,'club');
        foreach($matches as $m){if($deadlineAt!==null&&microtime(true)>=$deadlineAt-0.25)break;$mid=(int)$m['match_id'];$last=$mid;$checked++;$seen=[];
            $expected=Repository::canonicalMatchOutcome((float)$m['p2k_score'],$m['opponent_score']===null?null:(float)$m['opponent_score'],(int)$m['board_count'],!empty($m['is_void']));
            if((string)$m['result']!==(string)$expected['result']||(int)$m['competition_points']!==(int)$expected['competition_points']){
                $seen[]='match_outcome_points';$issues++;$this->upsertIssue($mid,null,'match_outcome_points','critical',['stored_result'=>$m['result'],'stored_points'=>(int)$m['competition_points'],'expected_result'=>$expected['result'],'expected_points'=>$expected['competition_points']]);
            }
            $b=$this->pdo->prepare("SELECT COUNT(DISTINCT b.board_id) boards,SUM(b.state='complete_immutable' AND b.finished_game_count>=2) complete_boards,COUNT(g.game_row_id) games,COALESCE(SUM(g.points_x2),0) points_x2,SUM(g.points_x2 NOT IN (0,1,2)) invalid_points FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE u.club_slug=? AND b.match_id=?");$b->execute([$this->clubSlug,$mid]);$a=$b->fetch(PDO::FETCH_ASSOC)?:[];$actualBoards=(int)($a['boards']??0);$complete=(int)($a['complete_boards']??0);$games=(int)($a['games']??0);$pointsX2=(int)($a['points_x2']??0);
            if($actualBoards!==(int)$m['board_count']){$seen[]='board_count_mismatch';$issues++;$this->upsertIssue($mid,null,'board_count_mismatch','critical',['metadata_boards'=>(int)$m['board_count'],'stored_boards'=>$actualBoards]);}
            if(empty($m['is_void'])&&$actualBoards===(int)$m['board_count']&&$complete===$actualBoards&&$games===2*$actualBoards){$eventScore=$pointsX2/2.0;if(abs($eventScore-(float)$m['p2k_score'])>0.001){$seen[]='member_points_team_score_mismatch';$issues++;$this->upsertIssue($mid,null,'member_points_team_score_mismatch','critical',['team_score'=>(float)$m['p2k_score'],'event_score'=>$eventScore,'points_x2'=>$pointsX2,'games'=>$games]);}}
            if($actualBoards>0&&($complete<$actualBoards||$games<2*$actualBoards)){ $seen[]='finished_board_incomplete';$issues++;$this->upsertIssue($mid,null,'finished_board_incomplete','error',['boards'=>$actualBoards,'complete_boards'=>$complete,'games'=>$games]); }
            if((int)($a['invalid_points']??0)>0){$seen[]='invalid_game_points';$issues++;$this->upsertIssue($mid,null,'invalid_game_points','critical',['invalid_rows'=>(int)$a['invalid_points']]);}
            if($seen!==[]){
                if($this->repo->enqueue((string)$job['id'],'sync_match','pir:match:'.$mid,['match_id'=>$mid,'source'=>'pir','priority_discovery'=>true,'force'=>true]))$queued++;
                $boards=$this->pdo->prepare("SELECT u.username,b.board_id,b.board_no,COALESCE(b.board_url_override,CONCAT('https://api.chess.com/pub/match/',b.match_id,'/',b.board_no)) board_url,b.source_bucket FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND b.match_id=?");$boards->execute([$this->clubSlug,$mid]);foreach($boards->fetchAll(PDO::FETCH_ASSOC)?:[] as $br){if($this->repo->enqueue((string)$job['id'],'sync_board','pir:board:'.$mid.':'.(int)$br['board_id'],['username'=>$br['username'],'match_id'=>$mid,'board_url'=>$br['board_url'],'source_bucket'=>$br['source_bucket'],'source'=>'pir','force_revalidate'=>true]))$queued++;}
                $this->pdo->prepare("UPDATE p2k_tp_pir_issues SET status='queued' WHERE club_slug=? AND match_id=? AND status='open'")->execute([$this->clubSlug,$mid]);
            }
            $this->resolveAbsent($mid,$seen);
        }
        $processedAll=$checked===count($matches);$cycleComplete=$processedAll&&count($matches)<$limit;$next=$cycleComplete?0:$last;
        $u=$this->pdo->prepare("INSERT INTO p2k_tp_pir_state(club_slug,cursor_match_id,last_run_at,last_completed_cycle_at,checked_matches,issues_found,repairs_queued,last_error,updated_at) VALUES(?,?,UTC_TIMESTAMP(),?, ?,?,?,NULL,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE cursor_match_id=VALUES(cursor_match_id),last_run_at=UTC_TIMESTAMP(),last_completed_cycle_at=COALESCE(VALUES(last_completed_cycle_at),last_completed_cycle_at),checked_matches=checked_matches+VALUES(checked_matches),issues_found=issues_found+VALUES(issues_found),repairs_queued=repairs_queued+VALUES(repairs_queued),last_error=NULL,updated_at=UTC_TIMESTAMP()");$u->execute([$this->clubSlug,$next,$cycleComplete?gmdate('Y-m-d H:i:s'):null,$checked,$issues,$queued]);
        return ['ran'=>true,'checked'=>$checked,'issues'=>$issues,'repairs_queued'=>$queued,'cursor'=>$next,'cycle_complete'=>$cycleComplete];
    }
    private function state():array{$q=$this->pdo->prepare('SELECT * FROM p2k_tp_pir_state WHERE club_slug=?');$q->execute([$this->clubSlug]);return $q->fetch(PDO::FETCH_ASSOC)?:[];}
    private function upsertIssue(int $matchId,?int $boardId,string $type,string $severity,array $details):void{$q=$this->pdo->prepare("INSERT INTO p2k_tp_pir_issues(club_slug,match_id,board_id,issue_type,severity,details_json,status,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,'open',UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE severity=VALUES(severity),details_json=VALUES(details_json),status=IF(status='ignored','ignored','open'),last_seen_at=UTC_TIMESTAMP(),resolved_at=NULL");$q->execute([$this->clubSlug,$matchId,$boardId??0,$type,$severity,json_encode($details,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]);}
    private function resolveAbsent(int $matchId,array $seen):void{$params=[$this->clubSlug,$matchId];$sql="UPDATE p2k_tp_pir_issues SET status='resolved',resolved_at=UTC_TIMESTAMP(),last_seen_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=? AND status IN ('open','queued')";if($seen!==[]){$sql.=' AND issue_type NOT IN ('.implode(',',array_fill(0,count($seen),'?')).')';$params=array_merge($params,$seen);} $this->pdo->prepare($sql)->execute($params);}
    public function summary():array{$s=$this->state();$q=$this->pdo->prepare("SELECT status,severity,COUNT(*) n FROM p2k_tp_pir_issues WHERE club_slug=? GROUP BY status,severity");$q->execute([$this->clubSlug]);$counts=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$counts[$r['status']][$r['severity']]=(int)$r['n'];return ['state'=>$s,'counts'=>$counts];}
}
