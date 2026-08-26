<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * v2.9.22 Fresh Points Reconstruction (FPR).
 *
 * Browser-acquired Chess.com data is staged here and remains isolated from Core
 * until an administrator explicitly promotes one or both tracks. Staging is
 * idempotent so Android suspension/page reloads can resume safely.
 */
final class FreshPointsReconstruction
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Repository $repository,
        private readonly string $clubSlug
    ) {}

    public function create(bool $club, bool $player): array
    {
        if (!$club && !$player) throw new \InvalidArgumentException('Select Club Points and/or Player Points.');
        $existing=$this->latest();
        if(is_array($existing) && in_array((string)$existing['status'],['running','paused','ready','applying'],true)) return $this->snapshot((string)$existing['run_id']);
        $id=\p2k_tp_uuid();
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_reconstruction_runs(run_id,club_slug,include_club,include_player,status,phase,phase_label,created_at,updated_at) VALUES(?,?,?,?, 'running','created','Created',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $q->execute([$id,$this->clubSlug,$club?1:0,$player?1:0]);
        // Seed the fresh run from the known canonical match universe. These are only
        // discovery hints: every detail is fetched again by the browser before apply.
        $seed=$this->pdo->prepare("INSERT INTO p2k_tp_reconstruction_matches(run_id,match_id,stage_state,source_flags,status,board_count,p2k_score,opponent_score,excluded_zero_zero,payload_json,first_seen_at,last_seen_at) SELECT ?,match_id,'pending','core-known','unknown',0,0,0,0,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP() FROM p2k_tp_match_metadata WHERE club_slug=? ON DUPLICATE KEY UPDATE source_flags=LEFT(CONCAT_WS(',',NULLIF(source_flags,''),'core-known'),80),last_seen_at=UTC_TIMESTAMP()");
        $seed->execute([$id,$this->clubSlug]);
        return $this->snapshot($id);
    }

    public function latest(): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM p2k_tp_reconstruction_runs WHERE club_slug=? ORDER BY created_at DESC LIMIT 1');$q->execute([$this->clubSlug]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }

    public function snapshot(?string $runId=null): array
    {
        $run=$runId!==null?$this->run($runId):$this->latest();
        if(!is_array($run)) return ['exists'=>false,'status'=>'idle','run'=>null,'metrics'=>$this->emptyMetrics(),'review'=>null];
        $id=(string)$run['run_id'];$metrics=$this->metrics($id);
        $stored=json_decode((string)($run['metrics_json']??''),true);if(is_array($stored))$metrics=array_replace_recursive($metrics,$stored);
        // v2.9.22.8: snapshot/status must stay metadata-only. A ready run can contain
        // thousands of staged members/boards/games; computing review/differences here
        // makes every page-load/status poll execute the expensive reconciliation SQL.
        // Review is computed only by the explicit reconstruction-review/differences actions.
        return ['exists'=>true,'run'=>$this->publicRun($run),'metrics'=>$metrics,'review'=>null];
    }

    public function command(string $runId,string $command): array
    {
        $run=$this->requireRun($runId);$command=strtolower(trim($command));
        if($command==='pause'){$status='paused';$phase=(string)$run['phase'];$label=(string)$run['phase_label'];}
        elseif($command==='resume'){$status='running';$phase=(string)$run['phase'];$label=(string)$run['phase_label'];}
        elseif($command==='cancel'){$status='cancelled';$phase='cancelled';$label='Cancelled';}
        elseif($command==='repair'){if((string)$run['status']!=='ready')throw new \RuntimeException('Only a ready reconstruction can enter issue-repair mode.');$status='running';$phase='club-repair';$label='Repairing Club reconstruction issues';}
        else throw new \InvalidArgumentException('Unsupported reconstruction command.');
        $q=$this->pdo->prepare('UPDATE p2k_tp_reconstruction_runs SET status=?,phase=?,phase_label=?,updated_at=UTC_TIMESTAMP() WHERE run_id=?');$q->execute([$status,$phase,$label,$runId]);
        return $this->snapshot($runId);
    }

    public function progress(string $runId,array $body): array
    {
        $this->requireRun($runId);
        $phase=substr(trim((string)($body['phase']??'running')),0,64);$label=substr(trim((string)($body['phase_label']??$phase)),0,160);
        $overall=max(0,min(100,(float)($body['overall_progress']??0)));$club=max(0,min(100,(float)($body['club_progress']??0)));$player=max(0,min(100,(float)($body['player_progress']??0)));
        $metrics=is_array($body['metrics']??null)?$body['metrics']:[];
        $json=$metrics===[]?null:json_encode($metrics,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        $q=$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET phase=?,phase_label=?,overall_progress=?,club_progress=?,player_progress=?,metrics_json=COALESCE(?,metrics_json),updated_at=UTC_TIMESTAMP() WHERE run_id=? AND status IN ('running','paused')");
        $q->execute([$phase,$label,$overall,$club,$player,$json,$runId]);return $this->snapshot($runId);
    }

    /** Idempotent batch ingest from the browser reconstruction engine. */
    public function ingest(string $runId,string $kind,array $rows): array
    {
        $run=$this->requireRun($runId);if(!in_array((string)$run['status'],['running','paused'],true))throw new \RuntimeException('This reconstruction run is not accepting staged data.');
        $kind=strtolower(trim($kind));$rows=array_values(array_filter($rows,'is_array'));if(count($rows)>500)throw new \InvalidArgumentException('Reconstruction ingest batches are limited to 500 rows.');
        $count=match($kind){
            'matches'=>$this->ingestMatches($runId,$rows),
            'members'=>$this->ingestMembers($runId,$rows),
            'boards'=>$this->ingestBoards($runId,$rows),
            'games'=>$this->ingestGames($runId,$rows),
            default=>throw new \InvalidArgumentException('Unknown reconstruction ingest kind.'),
        };
        return ['ok'=>true,'ingested'=>$count,'kind'=>$kind,'metrics'=>$this->metrics($runId)];
    }

    public function work(string $runId,string $kind,int $limit=500,string $state=''): array
    {
        $this->requireRun($runId);$limit=max(1,min(5000,$limit));$kind=strtolower(trim($kind));$state=trim($state);
        if($kind==='matches'){
            $where=$state!==''?' AND stage_state=?':'';$q=$this->pdo->prepare("SELECT match_id,stage_state,source_flags,status,board_count,p2k_score,opponent_score,excluded_zero_zero FROM p2k_tp_reconstruction_matches WHERE run_id=?{$where} ORDER BY match_id LIMIT {$limit}");$q->execute($state!==''?[$runId,$state]:[$runId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        if($kind==='members'){
            $where=$state!==''?' AND stage_state=?':'';$q=$this->pdo->prepare("SELECT username_key,username,joined_epoch,opening_member,closing_member,stage_state,points_x2,metrics_json FROM p2k_tp_reconstruction_members WHERE run_id=?{$where} ORDER BY username_key LIMIT {$limit}");$q->execute($state!==''?[$runId,$state]:[$runId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        if($kind==='boards'){
            $where=$state!==''?' AND stage_state=?':'';$q=$this->pdo->prepare("SELECT username_key,username,match_id,board_no,board_url,source_bucket,stage_state,white_result,black_result,p2k_rating,opponent_rating,points_x2,finished_game_count FROM p2k_tp_reconstruction_boards WHERE run_id=?{$where} ORDER BY match_id,username_key LIMIT {$limit}");$q->execute($state!==''?[$runId,$state]:[$runId]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        throw new \InvalidArgumentException('Unknown reconstruction work kind.');
    }

    public function review(string $runId,bool $markReady=true): array
    {
        $run=$this->requireRun($runId);$m=$this->metrics($runId);
        $currentClub=(int)$this->scalar('SELECT COALESCE(SUM(competition_points),0) FROM p2k_tp_match_metadata WHERE club_slug=? AND status=\'finished\' AND is_void=0',[$this->clubSlug]);
        $reconClub=(int)$this->scalar("SELECT COALESCE(SUM(CASE WHEN status='finished' AND excluded_zero_zero=0 THEN CASE WHEN p2k_score>opponent_score THEN 5*board_count WHEN p2k_score=opponent_score THEN 2*board_count ELSE 0 END ELSE 0 END),0) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state='resolved'",[$runId]);
        $currentPlayerX2=(int)$this->scalar('SELECT COALESCE(SUM(g.points_x2),0) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND u.current_member=1',[$this->clubSlug]);
        $reconPlayerX2=(int)$this->scalar('SELECT COALESCE(SUM(points_x2),0) FROM p2k_tp_reconstruction_games WHERE run_id=?',[$runId]);
        $matchChanges=$this->differenceCount($runId,'club');
        $memberChanges=$this->differenceCount($runId,'player');
        $clubUnresolved=(int)$m['matches']['unresolved']+(int)$m['matches']['failed'];
        $playerUnresolved=(int)$m['members']['unresolved']+(int)$m['members']['failed']+(int)$m['boards']['unresolved']+(int)$m['boards']['failed'];
        $unresolved=$clubUnresolved+$playerUnresolved;
        $review=['integrity_ok'=>$unresolved===0&&$matchChanges===0&&$memberChanges===0,'unresolved'=>$unresolved,'club'=>['integrity_ok'=>$clubUnresolved===0,'can_finalize'=>$matchChanges===0,'unresolved'=>$clubUnresolved,'current_score'=>$currentClub,'reconstructed_score'=>$reconClub,'delta'=>$reconClub-$currentClub,'total_matches'=>(int)$m['matches']['total'],'valid_matches'=>(int)$m['matches']['valid'],'finished_matches'=>(int)$m['matches']['finished'],'wins'=>(int)$m['matches']['wins'],'draws'=>(int)$m['matches']['draws'],'losses'=>(int)$m['matches']['losses'],'changed_matches'=>$matchChanges,'actionable_differences'=>$matchChanges,'excluded_zero_zero'=>(int)$m['matches']['excluded_zero_zero'],'queue_supersedable'=>$this->queueImpact('club',$runId)], 'player'=>['integrity_ok'=>$playerUnresolved===0,'can_finalize'=>$memberChanges===0,'unresolved'=>$playerUnresolved,'current_points'=>$currentPlayerX2/2,'reconstructed_points'=>$reconPlayerX2/2,'delta'=>($reconPlayerX2-$currentPlayerX2)/2,'changed_members'=>$memberChanges,'actionable_differences'=>$memberChanges,'members'=>(int)$m['members']['total'],'boards'=>(int)$m['boards']['total'],'games'=>(int)$m['games']['total'],'queue_supersedable'=>$this->queueImpact('player',$runId)]];
        if($markReady){$q=$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET status='ready',phase='review',phase_label='Ready for review',overall_progress=100,club_progress=IF(include_club=1,100,club_progress),player_progress=IF(include_player=1,100,player_progress),updated_at=UTC_TIMESTAMP() WHERE run_id=? AND status IN ('running','paused','ready')");$q->execute([$runId]);}
        return $review;
    }

    /**
     * Re-normalize staged Club match payloads without any Chess.com refetch.
     * v2.9.22.4: the match endpoint is the sole authority for board count.
     */
    public function recalculateClub(string $runId): array
    {
        $run=$this->requireRun($runId);
        if((int)$run['include_club']!==1)throw new \RuntimeException('Club Points was not selected for this reconstruction run.');
        if(!in_array((string)$run['status'],['running','paused','ready'],true))throw new \RuntimeException('This reconstruction run cannot be recalculated in its current state.');
        $q=$this->pdo->prepare("SELECT match_id,payload_json FROM p2k_tp_reconstruction_matches WHERE run_id=? AND payload_json IS NOT NULL ORDER BY match_id");$q->execute([$runId]);
        $u=$this->pdo->prepare("UPDATE p2k_tp_reconstruction_matches SET stage_state=?,status=?,board_count=?,p2k_score=?,opponent_score=?,excluded_zero_zero=?,source_flags=LEFT(CONCAT_WS(',',NULLIF(source_flags,''),?),80),last_seen_at=UTC_TIMESTAMP() WHERE run_id=? AND match_id=?");
        $processed=0;$resolved=0;$issues=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))continue;$n=$this->normalizeClubMatchPayload($payload);$state=$n['ok']?'resolved':'unresolved';$u->execute([$state,$n['status'],$n['boards'],$n['our_score'],$n['their_score'],$n['excluded']?1:0,$n['ok']?'endpoint-boards-v29224':'endpoint-normalize-issue',$runId,(int)$row['match_id']]);$processed++;if($n['ok'])$resolved++;else$issues++;}
        $this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET updated_at=UTC_TIMESTAMP() WHERE run_id=?")->execute([$runId]);
        return ['ok'=>true,'processed'=>$processed,'resolved'=>$resolved,'issues'=>$issues,'review'=>$this->review($runId,true),'reconstruction'=>$this->snapshot($runId)];
    }

    public function clubIssues(string $runId,int $limit=200): array
    {
        $this->requireRun($runId);$limit=max(1,min(1000,$limit));
        $q=$this->pdo->prepare("SELECT match_id,stage_state,source_flags,status,board_count,p2k_score,opponent_score,excluded_zero_zero,last_seen_at FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state IN ('failed','unresolved') ORDER BY match_id LIMIT {$limit}");$q->execute([$runId]);
        return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /**
     * v2.9.22.6 incremental reconciliation: list actionable local-vs-Core
     * differences without requiring the whole reconstruction to be clean.
     */
    public function differences(string $runId,string $scope,int $limit=100,int $offset=0,string $sort='point_delta',string $direction='desc',string $effect='all'): array
    {
        $this->requireRun($runId);$scope=strtolower(trim($scope));$limit=max(1,min(500,$limit));$offset=max(0,$offset);$sort=strtolower(trim($sort));$direction=strtolower(trim($direction))==='asc'?'asc':'desc';$effect=strtolower(trim($effect));if(!in_array($effect,['all','adds','removes','zero'],true))$effect='all';
        if($scope==='club')return $this->clubDifferences($runId,$limit,$offset,$sort,$direction,$effect);
        if($scope==='player')return $this->playerDifferences($runId,$limit,$offset,$sort,$direction);
        throw new \InvalidArgumentException('Reconciliation differences scope must be club or player.');
    }

    public function actions(string $runId,string $scope,int $limit=100): array
    {
        $this->requireRun($runId);$scope=strtolower(trim($scope));if(!in_array($scope,['club','player'],true))throw new \InvalidArgumentException('Invalid reconciliation action scope.');$limit=max(1,min(500,$limit));
        $q=$this->pdo->prepare("SELECT action_id,scope,entity_key,action_type,before_json,after_json,queue_superseded,applied_by,applied_at FROM p2k_tp_reconstruction_actions WHERE run_id=? AND scope=? ORDER BY action_id DESC LIMIT {$limit}");$q->execute([$runId,$scope]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$row){foreach(['before_json'=>'before','after_json'=>'after'] as $field=>$out){$decoded=json_decode((string)($row[$field]??''),true);$row[$out]=is_array($decoded)?$decoded:null;unset($row[$field]);}$row['queue_superseded']=(int)$row['queue_superseded'];}unset($row);return $rows;
    }

    public function applyDifference(string $runId,string $scope,string $entityKey,?string $appliedBy=null): array
    {
        $run=$this->requireRun($runId);$scope=strtolower(trim($scope));$entityKey=trim($entityKey);if(!in_array($scope,['club','player'],true)||$entityKey==='')throw new \InvalidArgumentException('Invalid reconciliation entity.');
        if($scope==='club'&&(int)$run['include_club']!==1)throw new \RuntimeException('Club Points was not selected for this run.');
        if($scope==='player'&&(int)$run['include_player']!==1)throw new \RuntimeException('Player Points was not selected for this run.');
        $lockName='p2k_team_points_worker_'.$scope;$lock=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new \RuntimeException('Another '.$scope.' worker is active. Retry this correction shortly.');
        try{
            $this->pdo->beginTransaction();
            if($scope==='club')$result=$this->applyClubDifference($runId,(int)$entityKey,$appliedBy);
            else $result=$this->applyPlayerDifference($runId,\p2k_tp_username_key($entityKey),$appliedBy);
            $this->pdo->prepare('UPDATE p2k_tp_state SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->pdo->commit();
            return ['ok'=>true,'scope'=>$scope,'result'=>$result,'review'=>$this->review($runId,false),'reconstruction'=>$this->snapshot($runId)];
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        finally{try{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}catch(\Throwable){}}
    }

    public function finalizeReconciliation(string $runId,string $scope,?string $appliedBy=null): array
    {
        $run=$this->requireRun($runId);$scope=strtolower(trim($scope));if(!in_array($scope,['club','player'],true))throw new \InvalidArgumentException('Finalization scope must be club or player.');
        if((string)$run['status']!=='ready' && (string)$run['status']!=='applied')throw new \RuntimeException('Reconciliation can only be finalized after fresh acquisition reaches review.');
        $remaining=$this->differenceCount($runId,$scope);if($remaining>0)throw new \RuntimeException($remaining.' actionable '.$scope.' difference(s) remain. Resolve them before finalizing.');
        $lockName='p2k_team_points_worker_'.$scope;$lock=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute([$lockName]);if((int)$lock->fetchColumn()!==1)throw new \RuntimeException('Another '.$scope.' worker is active. Retry finalization shortly.');
        try{
            $this->pdo->beginTransaction();
            if($scope==='club'){$verified=$this->markVerifiedClubRows($runId);$skipped=$this->finalizeClubQueue($runId);$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET club_applied_at=COALESCE(club_applied_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE run_id=?")->execute([$runId]);}
            else{$verified=$this->markVerifiedPlayerRows($runId);$skipped=$this->finalizePlayerQueue($runId);$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET player_applied_at=COALESCE(player_applied_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE run_id=?")->execute([$runId]);}
            $fresh=$this->requireRun($runId);$all=(!(bool)$fresh['include_club']||!empty($fresh['club_applied_at']))&&(!(bool)$fresh['include_player']||!empty($fresh['player_applied_at']));
            if($all)$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET status='applied',phase='applied',phase_label='Reconciliation finalized',applied_at=COALESCE(applied_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE run_id=?")->execute([$runId]);
            $this->pdo->prepare('UPDATE p2k_tp_state SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
            $this->auditAction($runId,$scope,'__finalize__','finalize_reconciliation',['verified'=>$verified],['queue_superseded'=>$skipped],$skipped,$appliedBy);
            $this->pdo->commit();
            $analytics=['refreshed'=>false];try{$analytics=(new AnalyticsBuilder($this->pdo,$this->repository->analytics()))->rebuildAll($this->clubSlug);}catch(\Throwable $e){$analytics=['refreshed'=>false,'error'=>$e->getMessage()];}
            return ['ok'=>true,'scope'=>$scope,'verified'=>$verified,'queue_superseded'=>$skipped,'analytics'=>$analytics,'reconstruction'=>$this->snapshot($runId)];
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        finally{try{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lockName]);}catch(\Throwable){}}
    }

    private function clubDifferences(string $runId,int $limit,int $offset,string $sort,string $direction,string $effect='all'): array
    {
        $where=$this->clubDifferenceWhere();$points=$this->clubExpectedPointsSql();$delta="({$points}-COALESCE(m.competition_points,0))";
        $count=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches r LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=? AND m.match_id=r.match_id WHERE r.run_id=? AND r.stage_state='resolved' AND ({$where})",[$this->clubSlug,$runId]);
        $statsQ=$this->pdo->prepare("SELECT COALESCE(SUM(CASE WHEN {$delta}>0 THEN {$delta} ELSE 0 END),0) positive_delta,COALESCE(SUM(CASE WHEN {$delta}<0 THEN {$delta} ELSE 0 END),0) negative_delta,COALESCE(SUM({$delta}),0) net_delta,SUM(CASE WHEN {$delta}>0 THEN 1 ELSE 0 END) positive_count,SUM(CASE WHEN {$delta}<0 THEN 1 ELSE 0 END) negative_count,SUM(CASE WHEN ABS({$delta})<0.001 THEN 1 ELSE 0 END) zero_count FROM p2k_tp_reconstruction_matches r LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=? AND m.match_id=r.match_id WHERE r.run_id=? AND r.stage_state='resolved' AND ({$where})");$statsQ->execute([$this->clubSlug,$runId]);$stats=$statsQ->fetch(PDO::FETCH_ASSOC)?:[];
        $effectWhere=match($effect){'adds'=>"{$delta}>0",'removes'=>"{$delta}<0",'zero'=>"ABS({$delta})<0.001",default=>'1=1'};
        $filteredCount=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches r LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=? AND m.match_id=r.match_id WHERE r.run_id=? AND r.stage_state='resolved' AND ({$where}) AND ({$effectWhere})",[$this->clubSlug,$runId]);
        $order=$this->clubDifferenceOrder($sort,$direction);
        $q=$this->pdo->prepare("SELECT r.match_id,r.status local_status,r.board_count local_boards,r.p2k_score local_p2k_score,r.opponent_score local_opponent_score,r.excluded_zero_zero local_void,m.match_id db_match_id,m.status db_status,m.board_count db_boards,m.p2k_score db_p2k_score,m.opponent_score db_opponent_score,m.result db_result,m.competition_points db_points,m.is_void db_void,m.opponent_name,m.match_name FROM p2k_tp_reconstruction_matches r LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=? AND m.match_id=r.match_id WHERE r.run_id=? AND r.stage_state='resolved' AND ({$where}) AND ({$effectWhere}) ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}");$q->execute([$this->clubSlug,$runId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        $clean=[];foreach($rows as $r){$derived=$this->clubDerived((string)$r['local_status'],(int)$r['local_boards'],(float)$r['local_p2k_score'],(float)$r['local_opponent_score'],(bool)$r['local_void']);$db=$r['db_match_id']===null?null:['status'=>$r['db_status'],'board_count'=>$r['db_boards'],'p2k_score'=>$r['db_p2k_score'],'opponent_score'=>$r['db_opponent_score'],'competition_points'=>$r['db_points']];$type=$this->clubDifferenceType($r,$db,$derived);if($type===null)continue;$r['difference_type']=$type;$r['local_result']=$derived['result'];$r['local_points']=$derived['points'];$r['point_delta']=$derived['points']-(int)($r['db_points']??0);$r['match_id']=(int)$r['match_id'];$r['local_boards']=(int)$r['local_boards'];$r['db_boards']=$r['db_match_id']===null?null:(int)$r['db_boards'];$r['local_void']=(bool)$r['local_void'];$r['db_void']=$r['db_match_id']===null?null:(bool)$r['db_void'];$clean[]=$r;}
        return ['scope'=>'club','total'=>$count,'filtered_total'=>$filteredCount,'issue_total'=>$this->clubIssueCount($runId),'limit'=>$limit,'offset'=>$offset,'sort'=>$sort,'direction'=>$direction,'effect'=>$effect,'positive_delta'=>(int)round((float)($stats['positive_delta']??0)),'negative_delta'=>(int)round((float)($stats['negative_delta']??0)),'net_delta'=>(int)round((float)($stats['net_delta']??0)),'positive_count'=>(int)($stats['positive_count']??0),'negative_count'=>(int)($stats['negative_count']??0),'zero_count'=>(int)($stats['zero_count']??0),'rows'=>$clean,'issues'=>$this->clubIssues($runId,500),'applied'=>$this->actions($runId,'club',100)];
    }

    private function clubExpectedPointsSql(): string
    {
        return "CASE WHEN r.status='finished' AND r.excluded_zero_zero=0 THEN CASE WHEN r.p2k_score>r.opponent_score THEN 5*r.board_count WHEN ABS(r.p2k_score-r.opponent_score)<0.001 THEN 2*r.board_count ELSE 0 END ELSE 0 END";
    }

    private function clubDifferenceWhere(): string
    {
        $points=$this->clubExpectedPointsSql();
        return "m.match_id IS NULL OR (r.status='finished' AND (COALESCE(m.status,'')<>'finished' OR ABS(COALESCE(m.p2k_score,-1000000)-r.p2k_score)>=0.001 OR ABS(COALESCE(m.opponent_score,-1000000)-r.opponent_score)>=0.001 OR (r.excluded_zero_zero=0 AND COALESCE(m.board_count,-1)<>r.board_count) OR COALESCE(m.competition_points,-1000000)<>{$points}))";
    }

    private function clubDifferenceOrder(string $sort,string $direction): string
    {
        $dir=$direction==='asc'?'ASC':'DESC';$points=$this->clubExpectedPointsSql();
        $expr=match($sort){
            'match'=>'r.match_id',
            'difference'=>"CASE WHEN m.match_id IS NULL THEN 0 WHEN r.status='finished' AND COALESCE(m.status,'')='finished' AND ABS(COALESCE(m.p2k_score,-1000000)-r.p2k_score)<0.001 AND ABS(COALESCE(m.opponent_score,-1000000)-r.opponent_score)<0.001 AND (r.excluded_zero_zero=1 OR COALESCE(m.board_count,-1)=r.board_count) AND COALESCE(m.competition_points,-1000000)<>{$points} THEN 2 ELSE 1 END",
            'fresh'=>'r.status, r.p2k_score, r.opponent_score',
            'core'=>"COALESCE(m.status,''), COALESCE(m.p2k_score,-1000000), COALESCE(m.opponent_score,-1000000)",
            'point_delta'=>"({$points}-COALESCE(m.competition_points,0))",
            default=>"({$points}-COALESCE(m.competition_points,0))",
        };
        return $expr.' '.$dir.', r.match_id ASC';
    }

    private function playerDifferences(string $runId,int $limit,int $offset,string $sort,string $direction): array
    {
        $order=$this->playerDifferenceOrder($sort,$direction);$sql=$this->playerDifferenceSql(false)." ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}";$q=$this->pdo->prepare($sql);$q->execute([$runId,$runId,$this->clubSlug,$this->clubSlug,$runId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        foreach($rows as &$r){foreach(['local_boards','local_games','local_points_x2','db_boards','db_games','db_points_x2'] as $k)$r[$k]=(int)($r[$k]??0);$r['local_points']=$r['local_points_x2']/2;$r['db_points']=$r['db_points_x2']/2;$r['point_delta']=($r['local_points_x2']-$r['db_points_x2'])/2;$r['difference_type']=($r['db_member_id']??null)===null?'missing_member_history':'different_player';}unset($r);
        $cq=$this->pdo->prepare('SELECT COUNT(*) FROM ('.$this->playerDifferenceSql(true).') d');$cq->execute([$runId,$runId,$this->clubSlug,$this->clubSlug,$runId]);$total=(int)$cq->fetchColumn();
        return ['scope'=>'player','total'=>$total,'issue_total'=>$this->playerIssueCount($runId),'limit'=>$limit,'offset'=>$offset,'sort'=>$sort,'direction'=>$direction,'rows'=>$rows,'issues'=>$this->playerIssues($runId,500),'applied'=>$this->actions($runId,'player',100)];
    }

    private function playerDifferenceOrder(string $sort,string $direction): string
    {
        $dir=$direction==='asc'?'ASC':'DESC';$expr=match($sort){'member'=>'rm.username_key','fresh'=>'COALESCE(lg.local_points_x2,0), COALESCE(lg.local_games,0), COALESCE(lb.local_boards,0)','core'=>'COALESCE(db.db_points_x2,0), COALESCE(db.db_games,0), COALESCE(db.db_boards,0)','point_delta'=>'ABS(COALESCE(lg.local_points_x2,0)-COALESCE(db.db_points_x2,0))',default=>'ABS(COALESCE(lg.local_points_x2,0)-COALESCE(db.db_points_x2,0))'};return $expr.' '.$dir.', rm.username_key ASC';
    }

    private function playerDifferenceSql(bool $countMode): string
    {
        $select=$countMode?'SELECT rm.username_key':"SELECT rm.username_key,rm.username,u.member_id db_member_id,COALESCE(lb.local_boards,0) local_boards,COALESCE(lg.local_games,0) local_games,COALESCE(lg.local_points_x2,0) local_points_x2,COALESCE(db.db_boards,0) db_boards,COALESCE(db.db_games,0) db_games,COALESCE(db.db_points_x2,0) db_points_x2";
        return $select." FROM p2k_tp_reconstruction_members rm LEFT JOIN (SELECT username_key,COUNT(*) local_boards FROM p2k_tp_reconstruction_boards WHERE run_id=? AND stage_state='resolved' GROUP BY username_key) lb ON lb.username_key=rm.username_key LEFT JOIN (SELECT username_key,COUNT(*) local_games,COALESCE(SUM(points_x2),0) local_points_x2 FROM p2k_tp_reconstruction_games WHERE run_id=? GROUP BY username_key) lg ON lg.username_key=rm.username_key LEFT JOIN p2k_tp_members u ON u.club_slug=? AND u.username_key=rm.username_key LEFT JOIN (SELECT b.member_id,COUNT(DISTINCT b.board_id) db_boards,COUNT(g.game_row_id) db_games,COALESCE(SUM(g.points_x2),0) db_points_x2 FROM p2k_tp_boards b INNER JOIN p2k_tp_members du ON du.member_id=b.member_id AND du.club_slug=? LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id GROUP BY b.member_id) db ON db.member_id=u.member_id WHERE rm.run_id=? AND (rm.opening_member=1 OR rm.closing_member=1) AND rm.stage_state IN ('matches_done','boards_done','complete') AND NOT EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards bx WHERE bx.run_id=rm.run_id AND bx.username_key=rm.username_key AND bx.stage_state<>'resolved') AND (u.member_id IS NULL OR COALESCE(lb.local_boards,0)<>COALESCE(db.db_boards,0) OR COALESCE(lg.local_games,0)<>COALESCE(db.db_games,0) OR COALESCE(lg.local_points_x2,0)<>COALESCE(db.db_points_x2,0))";
    }

    private function differenceCount(string $runId,string $scope): int
    {
        if($scope==='club')return (int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches r LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=? AND m.match_id=r.match_id WHERE r.run_id=? AND r.stage_state='resolved' AND (".$this->clubDifferenceWhere().')',[$this->clubSlug,$runId]);
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM ('.$this->playerDifferenceSql(true).') d');$q->execute([$runId,$runId,$this->clubSlug,$this->clubSlug,$runId]);return (int)$q->fetchColumn();
    }

    private function clubIssueCount(string $runId): int{return (int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state IN ('failed','unresolved')",[$runId]);}
    private function playerIssueCount(string $runId): int{return (int)$this->scalar("SELECT COUNT(*) FROM (SELECT rm.username_key FROM p2k_tp_reconstruction_members rm LEFT JOIN p2k_tp_reconstruction_boards b ON b.run_id=rm.run_id AND b.username_key=rm.username_key WHERE rm.run_id=? AND (rm.opening_member=1 OR rm.closing_member=1) GROUP BY rm.username_key,rm.stage_state HAVING rm.stage_state IN ('failed','unresolved','archive_fallback') OR SUM(CASE WHEN b.stage_state IN ('failed','unresolved') THEN 1 ELSE 0 END)>0) x",[$runId]);}

    private function playerIssues(string $runId,int $limit): array
    {
        $limit=max(1,min(1000,$limit));$q=$this->pdo->prepare("SELECT rm.username_key,rm.username,rm.stage_state,rm.metrics_json,COALESCE(SUM(CASE WHEN b.stage_state IN ('failed','unresolved') THEN 1 ELSE 0 END),0) board_issues FROM p2k_tp_reconstruction_members rm LEFT JOIN p2k_tp_reconstruction_boards b ON b.run_id=rm.run_id AND b.username_key=rm.username_key WHERE rm.run_id=? AND (rm.opening_member=1 OR rm.closing_member=1) GROUP BY rm.username_key,rm.username,rm.stage_state,rm.metrics_json HAVING rm.stage_state IN ('failed','unresolved','archive_fallback') OR board_issues>0 ORDER BY rm.username_key LIMIT {$limit}");$q->execute([$runId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];foreach($rows as &$r){$r['board_issues']=(int)$r['board_issues'];$m=json_decode((string)($r['metrics_json']??''),true);$r['metrics']=is_array($m)?$m:[];unset($r['metrics_json']);}unset($r);return $rows;
    }

    private function applyClubDifference(string $runId,int $matchId,?string $appliedBy): array
    {
        if($matchId<=0)throw new \InvalidArgumentException('Invalid match ID.');$q=$this->pdo->prepare("SELECT * FROM p2k_tp_reconstruction_matches WHERE run_id=? AND match_id=? AND stage_state='resolved' LIMIT 1");$q->execute([$runId,$matchId]);$row=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($row))throw new \RuntimeException('This match has no resolved fresh finding.');
        $before=$this->clubCoreSnapshot($matchId);$derived=$this->clubDerived((string)$row['status'],(int)$row['board_count'],(float)$row['p2k_score'],(float)$row['opponent_score'],(bool)$row['excluded_zero_zero']);
        if($before!==null && !$this->clubRowDiffers($row,$before,$derived))throw new \RuntimeException('This match already matches the fresh finding.');
        $payload=json_decode((string)($row['payload_json']??''),true);if(!is_array($payload))throw new \RuntimeException('Fresh match payload is unavailable; refetch this match first.');
        $this->repository->upsertMatchMetadata($this->clubSlug,$matchId,$payload,'fresh_reconciliation');
        $u=$this->pdo->prepare("UPDATE p2k_tp_match_metadata SET status=?,board_count=?,p2k_score=?,opponent_score=?,result=?,competition_points=?,is_void=?,finalized_at=CASE WHEN ?='finished' THEN COALESCE(finalized_at,UTC_TIMESTAMP()) ELSE NULL END,last_verified_at=UTC_TIMESTAMP(),last_observed_at=UTC_TIMESTAMP(),discovery_source='fresh_reconciliation' WHERE club_slug=? AND match_id=?");$u->execute([(string)$row['status'],(int)$row['board_count'],(float)$row['p2k_score'],(float)$row['opponent_score'],$derived['result'],$derived['points'],(int)$row['excluded_zero_zero'],(string)$row['status'],$this->clubSlug,$matchId]);
        $skipped=$this->skipClubMatchQueue($matchId,$runId);$after=$this->clubCoreSnapshot($matchId);$type=$this->clubDifferenceType($row,$before,$derived)??($before===null?'missing_match':'final_result_mismatch');$this->auditAction($runId,'club',(string)$matchId,$type,$before,$after,$skipped,$appliedBy);return ['entity_key'=>(string)$matchId,'action_type'=>$type,'queue_superseded'=>$skipped,'before'=>$before,'after'=>$after];
    }

    private function applyPlayerDifference(string $runId,string $usernameKey,?string $appliedBy): array
    {
        $mq=$this->pdo->prepare("SELECT * FROM p2k_tp_reconstruction_members WHERE run_id=? AND username_key=? AND (opening_member=1 OR closing_member=1) AND stage_state IN ('matches_done','boards_done','complete') LIMIT 1");$mq->execute([$runId,$usernameKey]);$member=$mq->fetch(PDO::FETCH_ASSOC);if(!is_array($member))throw new \RuntimeException('This member is not ready for reconciliation.');
        $issues=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_boards WHERE run_id=? AND username_key=? AND stage_state<>'resolved'",[$runId,$usernameKey]);if($issues>0)throw new \RuntimeException('This member still has unresolved board/game acquisition work.');
        $before=$this->playerCoreSummary($usernameKey);$username=(string)$member['username'];$this->repository->upsertHistoricalMember($this->clubSlug,$username);$uidQ=$this->pdo->prepare('SELECT member_id FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');$uidQ->execute([$this->clubSlug,$usernameKey]);$memberId=(int)$uidQ->fetchColumn();if($memberId<=0)throw new \RuntimeException('Member row could not be prepared.');$this->pdo->prepare('UPDATE p2k_tp_members SET current_member=1,last_seen_at=UTC_TIMESTAMP() WHERE member_id=?')->execute([$memberId]);
        $this->pdo->prepare('DELETE FROM p2k_tp_boards WHERE member_id=?')->execute([$memberId]);
        $bq=$this->pdo->prepare("SELECT * FROM p2k_tp_reconstruction_boards WHERE run_id=? AND username_key=? AND stage_state='resolved' ORDER BY match_id");$bq->execute([$runId,$usernameKey]);$boards=0;$games=0;
        foreach($bq->fetchAll(PDO::FETCH_ASSOC)?:[] as $b){$matchId=(int)$b['match_id'];$boardUrl=trim((string)$b['board_url']);if($matchId<=0||$boardUrl==='')continue;$this->repository->upsertParticipation(['club_slug'=>$this->clubSlug,'username_key'=>$usernameKey,'username'=>$username,'match_id'=>$matchId,'match_url'=>'https://api.chess.com/pub/match/'.$matchId,'board_url'=>$boardUrl,'white_result'=>$b['white_result'],'black_result'=>$b['black_result'],'p2k_rating'=>$b['p2k_rating'],'opponent_rating'=>$b['opponent_rating'],'rating_source'=>'fresh_reconciliation']);$gq=$this->pdo->prepare('SELECT * FROM p2k_tp_reconstruction_games WHERE run_id=? AND username_key=? AND match_id=? ORDER BY sequence_no');$gq->execute([$runId,$usernameKey,$matchId]);$gc=0;foreach($gq->fetchAll(PDO::FETCH_ASSOC)?:[] as $g){$this->repository->upsertPointEvent(['club_slug'=>$this->clubSlug,'username_key'=>$usernameKey,'username'=>$username,'match_id'=>$matchId,'board_url'=>$boardUrl,'game_url'=>(string)($g['game_url']??''),'game_end_utc'=>(string)$g['game_end_utc'],'utc_month'=>substr((string)$g['game_end_utc'],0,7).'-01','result_code'=>(string)$g['result_code'],'points'=>((int)$g['points_x2'])/2,'p2k_rating'=>$b['p2k_rating'],'opponent_rating'=>$b['opponent_rating'],'rating_source'=>'fresh_reconciliation','source_hash'=>(string)($g['source_hash']??'')]);$gc++;$games++;}$state=$gc>=2?'complete_immutable':($gc===1?'potentially_incomplete':'recent_in_progress');$this->repository->markBoardChecked($this->clubSlug,$username,$matchId,$boardUrl,(string)$b['source_bucket'],$state,min(2,$gc),$gc>=2?null:21600);$boards++;}
        $this->pdo->prepare('UPDATE p2k_tp_members SET player_matches_checked_at=UTC_TIMESTAMP(),player_matches_unverified_since=NULL WHERE member_id=?')->execute([$memberId]);$skipped=$this->skipPlayerMemberQueue($usernameKey,$runId);$after=$this->playerCoreSummary($usernameKey);$this->auditAction($runId,'player',$usernameKey,'synchronize_player',$before,$after,$skipped,$appliedBy);return ['entity_key'=>$usernameKey,'username'=>$username,'queue_superseded'=>$skipped,'boards'=>$boards,'games'=>$games,'before'=>$before,'after'=>$after];
    }

    private function clubDerived(string $status,int $boards,float $our,float $their,bool $void): array
    {
        if($status!=='finished')return ['result'=>'unknown','points'=>0];if($void)return ['result'=>'draw','points'=>0];if($our>$their)return ['result'=>'win','points'=>5*$boards];if($our<$their)return ['result'=>'loss','points'=>0];return ['result'=>'draw','points'=>2*$boards];
    }
    private function clubCoreSnapshot(int $matchId): ?array{$q=$this->pdo->prepare('SELECT match_id,status,board_count,p2k_score,opponent_score,result,competition_points,is_void,opponent_name,match_name,last_verified_at FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');$q->execute([$this->clubSlug,$matchId]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function clubDifferenceType(array $row,?array $db,array $derived): ?string{if($db===null)return 'missing_match';if((string)$row['status']!=='finished')return null;$resultDiff=(string)($db['status']??'')!=='finished'||abs((float)($db['p2k_score']??-1000000)-(float)$row['p2k_score'])>=.001||abs((float)($db['opponent_score']??-1000000)-(float)$row['opponent_score'])>=.001||(!(bool)$row['excluded_zero_zero']&&(int)($db['board_count']??-1)!==(int)$row['board_count']);if($resultDiff)return 'final_result_mismatch';if((int)($db['competition_points']??-1000000)!==(int)$derived['points'])return 'points_mismatch';return null;}
    private function clubRowDiffers(array $row,array $db,array $derived): bool{return $this->clubDifferenceType($row,$db,$derived)!==null;}
    private function playerCoreSummary(string $usernameKey): ?array{$q=$this->pdo->prepare('SELECT u.member_id,u.username,COUNT(DISTINCT b.board_id) boards,COUNT(g.game_row_id) games,COALESCE(SUM(g.points_x2),0) points_x2,u.player_matches_checked_at FROM p2k_tp_members u LEFT JOIN p2k_tp_boards b ON b.member_id=u.member_id LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id WHERE u.club_slug=? AND u.username_key=? GROUP BY u.member_id,u.username,u.player_matches_checked_at');$q->execute([$this->clubSlug,$usernameKey]);$r=$q->fetch(PDO::FETCH_ASSOC);if(!is_array($r))return null;$r['boards']=(int)$r['boards'];$r['games']=(int)$r['games'];$r['points_x2']=(int)$r['points_x2'];$r['points']=$r['points_x2']/2;return $r;}
    private function auditAction(string $runId,string $scope,string $entityKey,string $type,mixed $before,mixed $after,int $queueSkipped,?string $appliedBy): void{$q=$this->pdo->prepare('INSERT INTO p2k_tp_reconstruction_actions(run_id,scope,entity_key,action_type,before_json,after_json,queue_superseded,applied_by,applied_at) VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP())');$enc=static fn($v)=>$v===null?null:json_encode($v,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$q->execute([$runId,$scope,substr($entityKey,0,120),substr($type,0,40),$enc($before),$enc($after),max(0,$queueSkipped),$appliedBy!==null?substr(trim($appliedBy),0,80):null]);}

    private function skipClubMatchQueue(int $matchId,string $runId): int{$msg='\nSuperseded by fresh reconciliation '.$runId.' match '.$matchId.'.';$q=$this->pdo->prepare("UPDATE p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id SET i.status='skipped',i.locked_at=NULL,i.updated_at=UTC_TIMESTAMP(),i.last_error=CONCAT(COALESCE(i.last_error,''),?) WHERE j.club_slug=? AND j.job_type='club_points_sync' AND i.status IN ('pending','retry','failed','running') AND i.canonical_key=?");$q->execute([$msg,$this->clubSlug,'match:'.$matchId]);return $q->rowCount();}
    private function skipPlayerMemberQueue(string $usernameKey,string $runId): int{$msg='\nSuperseded by fresh player reconciliation '.$runId.' member '.$usernameKey.'.';$q=$this->pdo->prepare("UPDATE p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id SET i.status='skipped',i.locked_at=NULL,i.updated_at=UTC_TIMESTAMP(),i.last_error=CONCAT(COALESCE(i.last_error,''),?) WHERE j.club_slug=? AND j.job_type='player_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.canonical_key=? OR i.canonical_key LIKE ? OR i.canonical_key LIKE ?)");$q->execute([$msg,$this->clubSlug,'player-matches:'.$usernameKey,'player-archive:'.$usernameKey.':%','board:'.$usernameKey.':%']);return $q->rowCount();}
    private function markVerifiedClubRows(string $runId): int{$q=$this->pdo->prepare("UPDATE p2k_tp_match_metadata m JOIN p2k_tp_reconstruction_matches r ON r.run_id=? AND r.match_id=m.match_id SET m.last_verified_at=UTC_TIMESTAMP(),m.last_observed_at=UTC_TIMESTAMP() WHERE m.club_slug=? AND r.stage_state='resolved'");$q->execute([$runId,$this->clubSlug]);return $q->rowCount();}
    private function markVerifiedPlayerRows(string $runId): int{$q=$this->pdo->prepare("UPDATE p2k_tp_members u JOIN p2k_tp_reconstruction_members rm ON rm.run_id=? AND rm.username_key=u.username_key SET u.player_matches_checked_at=UTC_TIMESTAMP(),u.player_matches_unverified_since=NULL WHERE u.club_slug=? AND rm.closing_member=1 AND rm.stage_state IN ('matches_done','boards_done','complete') AND NOT EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards b WHERE b.run_id=rm.run_id AND b.username_key=rm.username_key AND b.stage_state<>'resolved')");$q->execute([$runId,$this->clubSlug]);return $q->rowCount();}
    private function finalizeClubQueue(string $runId): int{$msg='\nSuperseded by finalized fresh Club reconciliation '.$runId.'.';$q=$this->pdo->prepare("UPDATE p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id SET i.status='skipped',i.locked_at=NULL,i.updated_at=UTC_TIMESTAMP(),i.last_error=CONCAT(COALESCE(i.last_error,''),?) WHERE j.club_slug=? AND j.job_type='club_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.item_type='sync_club_matches' OR (i.item_type='sync_match' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_matches r WHERE r.run_id=? AND r.stage_state='resolved' AND i.canonical_key=CONCAT('match:',r.match_id))))");$q->execute([$msg,$this->clubSlug,$runId]);return $q->rowCount();}
    private function finalizePlayerQueue(string $runId): int{$msg='\nSuperseded by finalized fresh Player reconciliation '.$runId.'.';$q=$this->pdo->prepare("UPDATE p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id SET i.status='skipped',i.locked_at=NULL,i.updated_at=UTC_TIMESTAMP(),i.last_error=CONCAT(COALESCE(i.last_error,''),?) WHERE j.club_slug=? AND j.job_type='player_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.item_type IN ('sync_roster','sync_members','reconcile_members') OR EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members rm WHERE rm.run_id=? AND rm.closing_member=1 AND rm.stage_state IN ('matches_done','boards_done','complete') AND NOT EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards bx WHERE bx.run_id=rm.run_id AND bx.username_key=rm.username_key AND bx.stage_state<>'resolved') AND (i.canonical_key=CONCAT('player-matches:',rm.username_key) OR i.canonical_key LIKE CONCAT('player-archive:',rm.username_key,':%') OR i.canonical_key LIKE CONCAT('board:',rm.username_key,':%'))) OR (i.item_type='sync_match' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards b WHERE b.run_id=? AND b.stage_state='resolved' AND i.canonical_key=CONCAT('match:',b.match_id) AND NOT EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards bx WHERE bx.run_id=b.run_id AND bx.match_id=b.match_id AND bx.stage_state<>'resolved'))))");$q->execute([$msg,$this->clubSlug,$runId,$runId]);return $q->rowCount();}

    public function apply(string $runId,string $scope,bool $force=false): array
    {
        $run=$this->requireRun($runId);$scope=strtolower(trim($scope));if(!in_array($scope,['club','player','both'],true))throw new \InvalidArgumentException('Invalid reconstruction apply scope.');
        $review=$this->review($runId,false);if(!$force&&!$review['integrity_ok'])throw new \RuntimeException('The reconstruction still has unresolved/failed records. Resolve them or explicitly force application.');
        $applyClub=($scope==='club'||$scope==='both')&&(int)$run['include_club']===1;$applyPlayer=($scope==='player'||$scope==='both')&&(int)$run['include_player']===1;if(!$applyClub&&!$applyPlayer)throw new \RuntimeException('The selected reconstruction track was not enabled for this run.');if($applyClub&&!empty($run['club_applied_at']))$applyClub=false;if($applyPlayer&&!empty($run['player_applied_at']))$applyPlayer=false;if(!$applyClub&&!$applyPlayer)throw new \RuntimeException('The selected reconstruction track is already applied.');
        $locks=[];try{
            foreach(['club'=>$applyClub,'player'=>$applyPlayer] as $lane=>$needed){if(!$needed)continue;$name='p2k_team_points_worker_'.$lane;$q=$this->pdo->prepare('SELECT GET_LOCK(?,0)');$q->execute([$name]);if((int)$q->fetchColumn()!==1)throw new \RuntimeException('Another '.$lane.' worker is active. Pause it or retry application shortly.');$locks[]=$name;}
            $this->pdo->beginTransaction();$q=$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET status='applying',phase='applying',phase_label='Applying reconstruction',updated_at=UTC_TIMESTAMP() WHERE run_id=?");$q->execute([$runId]);
            $clubApplied=0;$playerBoards=0;$playerGames=0;$queueSkipped=['club'=>0,'player'=>0];
            if($applyClub)$clubApplied=$this->applyClub($runId);
            if($applyPlayer)[$playerBoards,$playerGames]=$this->applyPlayer($runId);
            if($applyClub)$queueSkipped['club']=$this->supersedeQueue('club',$runId);
            if($applyPlayer)$queueSkipped['player']=$this->supersedeQueue('player',$runId);
            $this->pdo->prepare('UPDATE p2k_tp_state SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);
            $allApplied=(!$run['include_club']||$applyClub||!empty($run['club_applied_at']))&&(!$run['include_player']||$applyPlayer||!empty($run['player_applied_at']));
            $q=$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET status=?,phase=?,phase_label=?,applied_at=CASE WHEN ?='applied' THEN UTC_TIMESTAMP() ELSE applied_at END,club_applied_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE club_applied_at END,player_applied_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE player_applied_at END,updated_at=UTC_TIMESTAMP() WHERE run_id=?");$finalStatus=$allApplied?'applied':'ready';$q->execute([$finalStatus,$allApplied?'applied':'review',$allApplied?'Applied':'Ready for remaining approval',$finalStatus,$applyClub?1:0,$applyPlayer?1:0,$runId]);$this->pdo->commit();
            $analytics=['refreshed'=>false];try{$analytics=(new AnalyticsBuilder($this->pdo,$this->repository->analytics()))->rebuildAll($this->clubSlug);}catch(\Throwable $analyticsError){$analytics=['refreshed'=>false,'warning'=>$analyticsError->getMessage()];}
            return ['ok'=>true,'run_id'=>$runId,'scope'=>$scope,'club_matches_applied'=>$clubApplied,'player_boards_applied'=>$playerBoards,'player_games_applied'=>$playerGames,'queue_superseded'=>$queueSkipped,'analytics'=>$analytics,'review'=>$this->review($runId,false)];
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();$this->pdo->prepare("UPDATE p2k_tp_reconstruction_runs SET status='failed',last_error=?,updated_at=UTC_TIMESTAMP() WHERE run_id=?")->execute([substr($e->getMessage(),0,60000),$runId]);throw $e;}finally{foreach(array_reverse($locks) as $name){try{$q=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$name]);}catch(\Throwable){}}}
    }

    private function applyClub(string $runId): int
    {
        $q=$this->pdo->prepare("SELECT match_id,payload_json,status,board_count,p2k_score,opponent_score FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state='resolved' ORDER BY match_id");$q->execute([$runId]);$count=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$payload=json_decode((string)$row['payload_json'],true);if(!is_array($payload))continue;$id=(int)$row['match_id'];$this->repository->upsertMatchMetadata($this->clubSlug,$id,$payload,'fresh_reconstruction');$status=(string)$row['status'];$our=(float)$row['p2k_score'];$their=(float)$row['opponent_score'];$boards=(int)$row['board_count'];$zero=($status==='finished'&&abs($our)<0.0001&&abs($their)<0.0001);$result=$status==='finished'?($zero?'draw':($our>$their?'win':($our<$their?'loss':'draw'))):'unknown';$points=($status==='finished'&&!$zero)?($result==='win'?5*$boards:($result==='draw'?2*$boards:0)):0;$u=$this->pdo->prepare("UPDATE p2k_tp_match_metadata SET status=?,board_count=?,p2k_score=?,opponent_score=?,result=?,competition_points=?,is_void=?,finalized_at=CASE WHEN ?='finished' THEN UTC_TIMESTAMP() ELSE NULL END,last_verified_at=UTC_TIMESTAMP(),discovery_source='fresh_reconstruction' WHERE club_slug=? AND match_id=?");$u->execute([$status,$boards,$our,$their,$result,$points,$zero?1:0,$status,$this->clubSlug,$id]);$count++;}
        $this->pdo->prepare('UPDATE p2k_tp_state SET club_index_last_observed_at=UTC_TIMESTAMP(),club_index_last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->clubSlug]);return $count;
    }

    private function applyPlayer(string $runId): array
    {
        $rosterQ=$this->pdo->prepare('SELECT username,joined_epoch FROM p2k_tp_reconstruction_members WHERE run_id=? AND closing_member=1 ORDER BY username_key');$rosterQ->execute([$runId]);$roster=$rosterQ->fetchAll(PDO::FETCH_ASSOC)?:[];$payload=['weekly'=>[],'monthly'=>[],'all_time'=>array_map(static fn(array $r):array=>['username'=>(string)$r['username'],'joined'=>(int)$r['joined_epoch']],$roster)];$applied=$this->repository->applyMembersObservation($this->clubSlug,$payload);if(empty($applied['valid']))throw new \RuntimeException('Reconstruction closing roster is empty; Player Points application refused.');
        $keys=array_map(static fn(array $r):string=>\p2k_tp_username_key((string)$r['username']),$roster);if($keys!==[]){$ph=implode(',',array_fill(0,count($keys),'?'));$sel=$this->pdo->prepare("SELECT member_id FROM p2k_tp_members WHERE club_slug=? AND username_key IN ({$ph})");$sel->execute([$this->clubSlug,...$keys]);$ids=array_map('intval',$sel->fetchAll(PDO::FETCH_COLUMN)?:[]);if($ids!==[]){$del=$this->pdo->prepare('DELETE FROM p2k_tp_boards WHERE member_id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$del->execute($ids);}}
        $bq=$this->pdo->prepare("SELECT * FROM p2k_tp_reconstruction_boards WHERE run_id=? AND stage_state='resolved' ORDER BY match_id,username_key");$bq->execute([$runId]);$boards=0;$games=0;
        foreach($bq->fetchAll(PDO::FETCH_ASSOC)?:[] as $b){$username=(string)$b['username'];$matchId=(int)$b['match_id'];$boardUrl=trim((string)$b['board_url']);if($username===''||$matchId<=0||$boardUrl==='')continue;$this->repository->upsertHistoricalMember($this->clubSlug,$username);$this->repository->upsertParticipation(['club_slug'=>$this->clubSlug,'username_key'=>(string)$b['username_key'],'username'=>$username,'match_id'=>$matchId,'match_url'=>'https://api.chess.com/pub/match/'.$matchId,'board_url'=>$boardUrl,'white_result'=>$b['white_result'],'black_result'=>$b['black_result'],'p2k_rating'=>$b['p2k_rating'],'opponent_rating'=>$b['opponent_rating'],'rating_source'=>'fresh_reconstruction']);$gq=$this->pdo->prepare('SELECT * FROM p2k_tp_reconstruction_games WHERE run_id=? AND username_key=? AND match_id=? ORDER BY sequence_no');$gq->execute([$runId,(string)$b['username_key'],$matchId]);$gc=0;foreach($gq->fetchAll(PDO::FETCH_ASSOC)?:[] as $g){$this->repository->upsertPointEvent(['club_slug'=>$this->clubSlug,'username_key'=>(string)$b['username_key'],'username'=>$username,'match_id'=>$matchId,'board_url'=>$boardUrl,'game_url'=>(string)($g['game_url']??''),'game_end_utc'=>(string)$g['game_end_utc'],'utc_month'=>substr((string)$g['game_end_utc'],0,7).'-01','result_code'=>(string)$g['result_code'],'points'=>((int)$g['points_x2'])/2,'p2k_rating'=>$b['p2k_rating'],'opponent_rating'=>$b['opponent_rating'],'rating_source'=>'fresh_reconstruction','source_hash'=>(string)($g['source_hash']??'')]);$gc++;$games++;}$state=$gc>=2?'complete_immutable':($gc===1?'potentially_incomplete':'recent_in_progress');$this->repository->markBoardChecked($this->clubSlug,$username,$matchId,$boardUrl,(string)$b['source_bucket'],$state,min(2,$gc),$gc>=2?null:21600);$boards++;}
        $this->pdo->prepare('UPDATE p2k_tp_members SET player_matches_checked_at=UTC_TIMESTAMP(),player_matches_unverified_since=NULL WHERE club_slug=? AND current_member=1')->execute([$this->clubSlug]);$this->pdo->prepare('UPDATE p2k_tp_state SET members_last_observed_at=UTC_TIMESTAMP(),members_last_verified_at=UTC_TIMESTAMP(),members_last_observed_count=?,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([count($roster),$this->clubSlug]);return [$boards,$games];
    }

    private function supersedeQueue(string $lane,string $runId): int
    {
        $message='\nSuperseded by fresh reconstruction '.$runId.'.';
        if($lane==='club'){
            $q=$this->pdo->prepare("UPDATE p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id SET i.status='skipped',i.locked_at=NULL,i.updated_at=UTC_TIMESTAMP(),i.last_error=CONCAT(COALESCE(i.last_error,''),?) WHERE j.club_slug=? AND j.job_type='club_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.item_type='sync_club_matches' OR (i.item_type='sync_match' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_matches r WHERE r.run_id=? AND i.canonical_key=CONCAT('match:',r.match_id))))");
            $q->execute([$message,$this->clubSlug,$runId]);return $q->rowCount();
        }
        $q=$this->pdo->prepare("UPDATE p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id SET i.status='skipped',i.locked_at=NULL,i.updated_at=UTC_TIMESTAMP(),i.last_error=CONCAT(COALESCE(i.last_error,''),?) WHERE j.club_slug=? AND j.job_type='player_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.item_type IN ('sync_roster','sync_members','reconcile_members') OR (i.item_type='sync_player' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members r WHERE r.run_id=? AND r.closing_member=1 AND i.canonical_key=CONCAT('player-matches:',r.username_key))) OR (i.item_type='sync_player_archive' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members r WHERE r.run_id=? AND r.closing_member=1 AND i.canonical_key LIKE CONCAT('player-archive:',r.username_key,':%'))) OR (i.item_type='sync_board' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members r WHERE r.run_id=? AND r.closing_member=1 AND i.canonical_key LIKE CONCAT('board:',r.username_key,':%'))) OR (i.item_type='sync_match' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards b WHERE b.run_id=? AND i.canonical_key=CONCAT('match:',b.match_id))))");
        $q->execute([$message,$this->clubSlug,$runId,$runId,$runId,$runId]);return $q->rowCount();
    }

    private function queueImpact(string $lane,string $runId): int
    {
        if($lane==='club'){
            return (int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE j.club_slug=? AND j.job_type='club_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.item_type='sync_club_matches' OR (i.item_type='sync_match' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_matches r WHERE r.run_id=? AND i.canonical_key=CONCAT('match:',r.match_id))))",[$this->clubSlug,$runId]);
        }
        return (int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE j.club_slug=? AND j.job_type='player_points_sync' AND i.status IN ('pending','retry','failed','running') AND (i.item_type IN ('sync_roster','sync_members','reconcile_members') OR (i.item_type='sync_player' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members r WHERE r.run_id=? AND r.closing_member=1 AND i.canonical_key=CONCAT('player-matches:',r.username_key))) OR (i.item_type='sync_player_archive' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members r WHERE r.run_id=? AND r.closing_member=1 AND i.canonical_key LIKE CONCAT('player-archive:',r.username_key,':%'))) OR (i.item_type='sync_board' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_members r WHERE r.run_id=? AND r.closing_member=1 AND i.canonical_key LIKE CONCAT('board:',r.username_key,':%'))) OR (i.item_type='sync_match' AND EXISTS (SELECT 1 FROM p2k_tp_reconstruction_boards b WHERE b.run_id=? AND i.canonical_key=CONCAT('match:',b.match_id))))",[$this->clubSlug,$runId,$runId,$runId,$runId]);
    }

    private function ingestMatches(string $runId,array $rows): int
    {
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_reconstruction_matches(run_id,match_id,stage_state,source_flags,status,board_count,p2k_score,opponent_score,excluded_zero_zero,payload_json,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE stage_state=VALUES(stage_state),source_flags=LEFT(CONCAT_WS(',',NULLIF(source_flags,''),NULLIF(VALUES(source_flags),'')),80),status=VALUES(status),board_count=VALUES(board_count),p2k_score=VALUES(p2k_score),opponent_score=VALUES(opponent_score),excluded_zero_zero=VALUES(excluded_zero_zero),payload_json=COALESCE(VALUES(payload_json),payload_json),last_seen_at=UTC_TIMESTAMP()");$n=0;
        foreach($rows as $r){$id=(int)($r['match_id']??0);if($id<=0)continue;$state=in_array((string)($r['stage_state']??'pending'),['pending','resolved','unresolved','failed'],true)?(string)$r['stage_state']:'pending';$payload=is_array($r['payload']??null)?json_encode($r['payload'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null;$q->execute([$runId,$id,$state,substr((string)($r['source_flags']??''),0,80),substr((string)($r['status']??'unknown'),0,24),max(0,(int)($r['board_count']??0)),(float)($r['p2k_score']??0),(float)($r['opponent_score']??0),!empty($r['excluded_zero_zero'])?1:0,$payload]);$n++;}return $n;
    }
    private function ingestMembers(string $runId,array $rows): int
    {
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_reconstruction_members(run_id,username_key,username,joined_epoch,opening_member,closing_member,stage_state,points_x2,metrics_json,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),joined_epoch=GREATEST(joined_epoch,VALUES(joined_epoch)),opening_member=GREATEST(opening_member,VALUES(opening_member)),closing_member=GREATEST(closing_member,VALUES(closing_member)),stage_state=VALUES(stage_state),points_x2=VALUES(points_x2),metrics_json=COALESCE(VALUES(metrics_json),metrics_json),last_seen_at=UTC_TIMESTAMP()");$n=0;
        foreach($rows as $r){$u=trim((string)($r['username']??''));if($u==='')continue;$key=\p2k_tp_username_key($u);$state=in_array((string)($r['stage_state']??'pending'),['pending','matches_done','archive_fallback','boards_done','complete','unresolved','failed'],true)?(string)$r['stage_state']:'pending';$metrics=is_array($r['metrics']??null)?json_encode($r['metrics'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null;$q->execute([$runId,$key,$u,max(0,(int)($r['joined_epoch']??0)),!empty($r['opening_member'])?1:0,!empty($r['closing_member'])?1:0,$state,max(0,(int)($r['points_x2']??0)),$metrics]);$n++;}$rq=$this->pdo->prepare('UPDATE p2k_tp_reconstruction_runs SET opening_roster_count=(SELECT COUNT(*) FROM p2k_tp_reconstruction_members WHERE run_id=? AND opening_member=1),closing_roster_count=(SELECT COUNT(*) FROM p2k_tp_reconstruction_members WHERE run_id=? AND closing_member=1),updated_at=UTC_TIMESTAMP() WHERE run_id=?');$rq->execute([$runId,$runId,$runId]);return $n;
    }
    private function ingestBoards(string $runId,array $rows): int
    {
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_reconstruction_boards(run_id,username_key,username,match_id,board_no,board_url,source_bucket,stage_state,white_result,black_result,p2k_rating,opponent_rating,points_x2,finished_game_count,first_seen_at,last_seen_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),board_no=CASE WHEN VALUES(board_no)>0 THEN VALUES(board_no) ELSE board_no END,board_url=COALESCE(NULLIF(VALUES(board_url),''),board_url),source_bucket=VALUES(source_bucket),stage_state=VALUES(stage_state),white_result=COALESCE(VALUES(white_result),white_result),black_result=COALESCE(VALUES(black_result),black_result),p2k_rating=COALESCE(VALUES(p2k_rating),p2k_rating),opponent_rating=COALESCE(VALUES(opponent_rating),opponent_rating),points_x2=VALUES(points_x2),finished_game_count=VALUES(finished_game_count),last_seen_at=UTC_TIMESTAMP()");$n=0;
        foreach($rows as $r){$u=trim((string)($r['username']??''));$mid=(int)($r['match_id']??0);if($u===''||$mid<=0)continue;$key=\p2k_tp_username_key($u);$state=in_array((string)($r['stage_state']??'discovered'),['discovered','pending','resolved','unresolved','failed'],true)?(string)$r['stage_state']:'discovered';$q->execute([$runId,$key,$u,$mid,max(0,(int)($r['board_no']??0)),substr(trim((string)($r['board_url']??'')),0,255),substr((string)($r['source_bucket']??'unknown'),0,24),$state,$r['white_result']??null,$r['black_result']??null,isset($r['p2k_rating'])?(int)$r['p2k_rating']:null,isset($r['opponent_rating'])?(int)$r['opponent_rating']:null,max(0,(int)($r['points_x2']??0)),max(0,min(2,(int)($r['finished_game_count']??0)))]);$n++;}return $n;
    }
    private function ingestGames(string $runId,array $rows): int
    {
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_reconstruction_games(run_id,username_key,match_id,sequence_no,game_id,game_url,game_end_utc,result_code,points_x2,source_hash) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),game_url=VALUES(game_url),game_end_utc=VALUES(game_end_utc),result_code=VALUES(result_code),points_x2=VALUES(points_x2),source_hash=VALUES(source_hash)");$n=0;
        foreach($rows as $r){$u=trim((string)($r['username']??''));$mid=(int)($r['match_id']??0);$seq=max(1,min(2,(int)($r['sequence_no']??0)));$end=trim((string)($r['game_end_utc']??''));if($u===''||$mid<=0||$end==='')continue;$q->execute([$runId,\p2k_tp_username_key($u),$mid,$seq,isset($r['game_id'])?(int)$r['game_id']:null,substr((string)($r['game_url']??''),0,255),$end,substr((string)($r['result_code']??''),0,40),max(0,min(2,(int)($r['points_x2']??0))),isset($r['source_hash'])?substr((string)$r['source_hash'],0,64):null]);$n++;}return $n;
    }

    private function metrics(string $runId): array
    {
        $match=$this->groupCounts('p2k_tp_reconstruction_matches',$runId,'stage_state');$member=$this->groupCounts('p2k_tp_reconstruction_members',$runId,'stage_state');$board=$this->groupCounts('p2k_tp_reconstruction_boards',$runId,'stage_state');
        $finished=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state='resolved' AND status='finished' AND excluded_zero_zero=0",[$runId]);$zero=(int)$this->scalar('SELECT COUNT(*) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND excluded_zero_zero=1',[$runId]);$valid=$finished;
        $wins=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state='resolved' AND status='finished' AND excluded_zero_zero=0 AND p2k_score>opponent_score",[$runId]);
        $draws=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state='resolved' AND status='finished' AND excluded_zero_zero=0 AND ABS(p2k_score-opponent_score)<0.0001",[$runId]);
        $losses=(int)$this->scalar("SELECT COUNT(*) FROM p2k_tp_reconstruction_matches WHERE run_id=? AND stage_state='resolved' AND status='finished' AND excluded_zero_zero=0 AND p2k_score<opponent_score",[$runId]);
        $maxMatchId=(int)$this->scalar('SELECT COALESCE(MAX(match_id),0) FROM p2k_tp_reconstruction_matches WHERE run_id=?',[$runId]);$games=(int)$this->scalar('SELECT COUNT(*) FROM p2k_tp_reconstruction_games WHERE run_id=?',[$runId]);$pointsX2=(int)$this->scalar('SELECT COALESCE(SUM(points_x2),0) FROM p2k_tp_reconstruction_games WHERE run_id=?',[$runId]);
        return ['matches'=>['total'=>array_sum($match),'pending'=>$match['pending']??0,'resolved'=>$match['resolved']??0,'unresolved'=>$match['unresolved']??0,'failed'=>$match['failed']??0,'valid'=>$valid,'finished'=>$finished,'wins'=>$wins,'draws'=>$draws,'losses'=>$losses,'excluded_zero_zero'=>$zero,'max_match_id'=>$maxMatchId], 'members'=>['total'=>array_sum($member),'pending'=>$member['pending']??0,'matches_done'=>$member['matches_done']??0,'archive_fallback'=>$member['archive_fallback']??0,'boards_done'=>$member['boards_done']??0,'complete'=>$member['complete']??0,'unresolved'=>$member['unresolved']??0,'failed'=>$member['failed']??0], 'boards'=>['total'=>array_sum($board),'discovered'=>$board['discovered']??0,'pending'=>$board['pending']??0,'resolved'=>$board['resolved']??0,'unresolved'=>$board['unresolved']??0,'failed'=>$board['failed']??0], 'games'=>['total'=>$games,'points'=>$pointsX2/2]];
    }
    private function emptyMetrics(): array{return ['matches'=>['total'=>0,'pending'=>0,'resolved'=>0,'unresolved'=>0,'failed'=>0,'valid'=>0,'finished'=>0,'wins'=>0,'draws'=>0,'losses'=>0,'excluded_zero_zero'=>0,'max_match_id'=>0],'members'=>['total'=>0,'pending'=>0,'complete'=>0,'failed'=>0],'boards'=>['total'=>0,'pending'=>0,'resolved'=>0,'unresolved'=>0,'failed'=>0],'games'=>['total'=>0,'points'=>0]];}
    private function normalizeClubMatchPayload(array $payload): array
    {
        $status=$this->normalizeMatchStatus($payload['status']??'unknown');
        $boards=(isset($payload['boards'])&&is_numeric($payload['boards']))?(int)$payload['boards']:0;
        [$ours,$theirs]=$this->clubTeams($payload);
        $ourScore=is_array($ours)&&is_numeric($ours['score']??null)?(float)$ours['score']:null;
        $theirScore=is_array($theirs)&&is_numeric($theirs['score']??null)?(float)$theirs['score']:null;
        if($ourScore===null||$theirScore===null)return ['ok'=>false,'status'=>$status,'boards'=>max(0,$boards),'our_score'=>$ourScore??0.0,'their_score'=>$theirScore??0.0,'excluded'=>false];
        $oursDraw=strtolower(trim((string)($ours['result']??'')))==='draw';
        $theirsDraw=strtolower(trim((string)($theirs['result']??'')))==='draw';
        $zeroZero=abs($ourScore)<0.0001&&abs($theirScore)<0.0001;
        // Chess.com uses a 0-0 draw for cancelled team matches. Such matches can
        // legitimately have no balanced lineup and therefore no meaningful boards
        // value. They are valid excluded records, never payload issues.
        if($zeroZero&&($status==='finished'||$oursDraw||$theirsDraw))return ['ok'=>true,'status'=>'finished','boards'=>max(0,$boards),'our_score'=>0.0,'their_score'=>0.0,'excluded'=>true];
        if($boards<=0||!is_array($ours)||!is_array($theirs))return ['ok'=>false,'status'=>$status,'boards'=>max(0,$boards),'our_score'=>$ourScore,'their_score'=>$theirScore,'excluded'=>false];
        return ['ok'=>true,'status'=>$status,'boards'=>$boards,'our_score'=>$ourScore,'their_score'=>$theirScore,'excluded'=>false];
    }
    private function clubTeams(array $payload): array
    {
        $teams=is_array($payload['teams']??null)?$payload['teams']:[];$ours=null;$theirs=null;$ourKey=null;
        foreach($teams as $k=>$team){if(!is_array($team))continue;$slug=$this->teamSlug($team,is_string($k)?$k:'');if($slug===$this->clubSlug){$ours=$team;$ourKey=$k;break;}}
        if($ours===null)return [null,null];
        foreach($teams as $k=>$team){if(is_array($team)&&$k!==$ourKey){$theirs=$team;break;}}
        return [$ours,$theirs];
    }
    private function teamSlug(array $team,string $fallback=''): string
    {
        foreach(['@id','url','club','club_url'] as $field){$value=strtolower(trim((string)($team[$field]??'')));if(preg_match('~/club/([^/?#]+)~',$value,$m))return strtolower(rawurldecode($m[1]));}
        $name=trim((string)($team['name']??$fallback));$slug=strtolower((string)preg_replace('/[^a-z0-9]+/i','-',$name));return trim($slug,'-');
    }
    private function normalizeMatchStatus(mixed $raw): string
    {
        $s=strtolower(trim((string)$raw));if(in_array($s,['finished','complete','completed','draw'],true))return 'finished';if(in_array($s,['in_progress','ongoing','started'],true))return 'in_progress';if(in_array($s,['registered','registration'],true))return 'registered';return 'unknown';
    }

    private function groupCounts(string $table,string $runId,string $column): array{$q=$this->pdo->prepare("SELECT {$column} k,COUNT(*) n FROM {$table} WHERE run_id=? GROUP BY {$column}");$q->execute([$runId]);$out=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$out[(string)$r['k']]=(int)$r['n'];return $out;}
    private function scalar(string $sql,array $params=[]): mixed{$q=$this->pdo->prepare($sql);$q->execute($params);return $q->fetchColumn();}
    private function run(string $runId): ?array{$q=$this->pdo->prepare('SELECT * FROM p2k_tp_reconstruction_runs WHERE run_id=? AND club_slug=? LIMIT 1');$q->execute([$runId,$this->clubSlug]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;}
    private function requireRun(string $runId): array{$r=$this->run($runId);if(!is_array($r))throw new \RuntimeException('Reconstruction run not found.');return $r;}
    private function publicRun(array $r): array{return ['run_id'=>(string)$r['run_id'],'include_club'=>(bool)$r['include_club'],'include_player'=>(bool)$r['include_player'],'status'=>(string)$r['status'],'phase'=>(string)$r['phase'],'phase_label'=>(string)$r['phase_label'],'overall_progress'=>(float)$r['overall_progress'],'club_progress'=>(float)$r['club_progress'],'player_progress'=>(float)$r['player_progress'],'opening_roster_count'=>(int)$r['opening_roster_count'],'closing_roster_count'=>(int)$r['closing_roster_count'],'created_at'=>$r['created_at'],'updated_at'=>$r['updated_at'],'applied_at'=>$r['applied_at'],'club_applied_at'=>$r['club_applied_at']??null,'player_applied_at'=>$r['player_applied_at']??null,'last_error'=>$r['last_error']];}
}
