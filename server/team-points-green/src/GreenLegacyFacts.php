<?php
declare(strict_types=1);

namespace P2K\Green;

use RuntimeException;

final class GreenLegacyFacts
{
    public const EXPECTED_SHA256 = 'cb5bfc768e458870c61fc20d4e09255e28a8dec58c1367ce162501f711840a63';
    public const EXPECTED_ROWS = 16048;
    public const EXPECTED_VALID_FINISHED = 14538;
    public const EXPECTED_VOID = 1510;
    public const EXPECTED_POINTS = 373025;

    private GreenRepository $repo;

    public function __construct(GreenRepository $repo)
    {
        $this->repo=$repo;
    }

    public static function locateTrustedFile(string $siteRoot): ?string
    {
        $siteRoot=rtrim($siteRoot,'/');
        $candidates=[];
        $exact=$siteRoot.'/data/runtime-v280/reconciliation/20260810-142640-d769a55a04/FinishedMatches-P2K.csv';
        if(is_file($exact))$candidates[]=$exact;
        foreach(glob($siteRoot.'/data/runtime-v280/reconciliation/*/FinishedMatches-P2K*.csv')?:[] as $p)$candidates[]=$p;
        foreach(glob($siteRoot.'/data/**/FinishedMatches-P2K*.csv',GLOB_NOSORT)?:[] as $p)$candidates[]=$p;
        foreach(array_values(array_unique($candidates)) as $path){
            if(!is_file($path))continue;
            $sha=@hash_file('sha256',$path);
            if(is_string($sha)&&hash_equals(self::EXPECTED_SHA256,$sha))return $path;
        }
        return null;
    }

    private static function boolish($value): bool
    {
        if(is_bool($value))return $value;
        $s=strtolower(trim((string)$value));
        return in_array($s,['1','true','yes','y'],true);
    }

    private static function result(string $value): string
    {
        $r=strtolower(trim($value));
        if(in_array($r,['lose','lost'],true))$r='loss';
        if($r==='tie')$r='draw';
        return in_array($r,['win','draw','loss'],true)?$r:'none';
    }

    private static function matchId(string $value): int
    {
        $value=trim($value);
        if(ctype_digit($value))return (int)$value;
        if(preg_match('~/match/(\d+)/?(?:[?#].*)?$~i',$value,$m))return (int)$m[1];
        if(preg_match('~(\d+)/?(?:[?#].*)?$~',$value,$m))return (int)$m[1];
        return 0;
    }

    public function inspect(string $path): array
    {
        if(!is_file($path))throw new RuntimeException('Trusted legacy FinishedMatches CSV was not found.');
        $sha=(string)hash_file('sha256',$path);
        if(!hash_equals(self::EXPECTED_SHA256,$sha))throw new RuntimeException('Legacy FinishedMatches CSV SHA-256 does not match the verified trusted snapshot.');

        $fh=fopen($path,'rb');if(!$fh)throw new RuntimeException('Unable to open trusted legacy FinishedMatches CSV.');
        try{
            $header=fgetcsv($fh);if(!is_array($header))throw new RuntimeException('Trusted legacy CSV header is missing.');
            $idx=[];foreach($header as $i=>$name)$idx[trim((string)$name)]=$i;
            foreach(['MatchAPI','EndTimeUnix','Status','Boards','Forfeit','OurResult','OurScore','TheirScore','OurFinalPoints'] as $required){
                if(!array_key_exists($required,$idx))throw new RuntimeException('Trusted legacy CSV is missing required column '.$required.'.');
            }
            $rows=[];$line=1;$valid=0;$voids=0;$points=0;$wins=0;$draws=0;$losses=0;
            while(($raw=fgetcsv($fh))!==false){
                $line++;if(count($raw)===1&&trim((string)($raw[0]??''))==='')continue;
                $get=static function(string $name,$default='') use($raw,$idx){return isset($idx[$name])&&array_key_exists($idx[$name],$raw)?$raw[$idx[$name]]:$default;};
                $id=self::matchId((string)$get('MatchAPI'));if($id<=0)throw new RuntimeException('Invalid match ID at trusted CSV line '.$line.'.');
                if(isset($rows[$id]))throw new RuntimeException('Duplicate match ID '.$id.' in trusted legacy CSV.');
                $status=strtolower(trim((string)$get('Status')));$boards=max(0,(int)$get('Boards'));
                $p2k=is_numeric($get('OurScore'))?(float)$get('OurScore'):0.0;$opp=is_numeric($get('TheirScore'))?(float)$get('TheirScore'):0.0;
                $void=self::boolish($get('Forfeit'))||($status==='finished'&&abs($p2k)<.001&&abs($opp)<.001);
                $result=$void?'none':self::result((string)$get('OurResult'));
                $rowPoints=$void?0:(int)round((float)$get('OurFinalPoints'));
                if(!$void){
                    if($boards<=0||!in_array($result,['win','draw','loss'],true))throw new RuntimeException('Invalid non-void match facts at trusted CSV line '.$line.'.');
                    $formula=$result==='win'?5*$boards:($result==='draw'?2*$boards:0);
                    if($rowPoints!==$formula)throw new RuntimeException('Trusted CSV scoring formula mismatch for match '.$id.': file='.$rowPoints.' formula='.$formula.'.');
                }
                $endEpoch=is_numeric($get('EndTimeUnix'))?(int)$get('EndTimeUnix'):0;
                $startEpoch=array_key_exists('StartTimeUnix',$idx)&&is_numeric($get('StartTimeUnix'))?(int)$get('StartTimeUnix'):null;
                $api=trim((string)$get('MatchAPI'));if(strpos($api,'http')!==0)$api='https://api.chess.com/pub/match/'.$id;
                $rows[$id]=[
                    'match_id'=>$id,'api_url'=>$api,'name'=>trim((string)$get('MatchName')),'opponent_name'=>trim((string)$get('OpponentName')),
                    'status'=>$void?'cancelled':'finished','result'=>$result,'is_void'=>$void?1:0,'boards'=>$boards,
                    'p2k_score'=>$p2k,'opponent_score'=>$opp,'points'=>$rowPoints,'end_epoch'=>$endEpoch,'start_epoch'=>$startEpoch,
                ];
                if($void){$voids++;}else{$valid++;$points+=$rowPoints;if($result==='win')$wins++;elseif($result==='draw')$draws++;elseif($result==='loss')$losses++;}
            }
        }finally{fclose($fh);}

        $summary=['sha256'=>$sha,'rows'=>count($rows),'valid_finished'=>$valid,'void'=>$voids,'points'=>$points,'wins'=>$wins,'draws'=>$draws,'losses'=>$losses,'rows_data'=>$rows];
        if($summary['rows']!==self::EXPECTED_ROWS||$valid!==self::EXPECTED_VALID_FINISHED||$voids!==self::EXPECTED_VOID||$points!==self::EXPECTED_POINTS){
            throw new RuntimeException('Trusted legacy CSV failed invariant gate: expected '.self::EXPECTED_ROWS.' rows / '.self::EXPECTED_VALID_FINISHED.' valid / '.self::EXPECTED_VOID.' void / '.self::EXPECTED_POINTS.' points; got '.$summary['rows'].' / '.$valid.' / '.$voids.' / '.$points.'.');
        }
        return $summary;
    }

    public function import(string $path): array
    {
        $audit=$this->inspect($path);$rows=$audit['rows_data'];unset($audit['rows_data']);
        $pdo=$this->repo->core;$pdo->beginTransaction();
        try{
            $upsert=$pdo->prepare("INSERT INTO p2k_g_matches(match_id,api_url,name,opponent_name,status,club_verified,verified_club_slug,club_side,scoring_eligible,exclusion_reason,trusted_legacy,fact_source,time_class,start_epoch,end_epoch,board_count,p2k_score,opponent_score,result,competition_points,is_void,created_at,updated_at) VALUES(?,?,?,?,?,1,?,'trusted_csv',?,?,1,'trusted_legacy_csv','daily',?,?,?,?,?,?,?, ?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE api_url=VALUES(api_url),name=CASE WHEN VALUES(name)<>'' THEN VALUES(name) ELSE name END,opponent_name=CASE WHEN VALUES(opponent_name)<>'' THEN VALUES(opponent_name) ELSE opponent_name END,status=VALUES(status),club_verified=1,verified_club_slug=VALUES(verified_club_slug),club_side='trusted_csv',scoring_eligible=VALUES(scoring_eligible),exclusion_reason=VALUES(exclusion_reason),trusted_legacy=1,fact_source='trusted_legacy_csv',time_class='daily',start_epoch=COALESCE(VALUES(start_epoch),start_epoch),end_epoch=VALUES(end_epoch),board_count=VALUES(board_count),p2k_score=VALUES(p2k_score),opponent_score=VALUES(opponent_score),result=VALUES(result),competition_points=VALUES(competition_points),is_void=VALUES(is_void),retry_after=NULL,updated_at=UTC_TIMESTAMP()");
            $imported=0;
            foreach($rows as $r){
                $eligible=$r['is_void']?0:1;$reason=$r['is_void']?'trusted_finished_0_0':null;
                $upsert->execute([$r['match_id'],$r['api_url'],$r['name'],$r['opponent_name'],$r['status'],$this->repo->clubSlug,$eligible,$reason,$r['start_epoch'],$r['end_epoch'],$r['boards'],$r['p2k_score'],$r['opponent_score'],$r['result'],$r['points'],$r['is_void']]);
                $imported++;
            }

            // Trusted 0-0 rows are historical exclusions, so they must not retain any
            // board/game/member-point material that an earlier Green build may have created.
            $pdo->exec("DELETE e FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id WHERE m.trusted_legacy=1 AND m.is_void=1");
            $pdo->exec("DELETE g FROM p2k_g_games g JOIN p2k_g_matches m ON m.match_id=g.match_id WHERE m.trusted_legacy=1 AND m.is_void=1");
            $pdo->exec("DELETE b FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE m.trusted_legacy=1 AND m.is_void=1");
            $pdo->exec("DELETE mp FROM p2k_g_match_players mp JOIN p2k_g_matches m ON m.match_id=mp.match_id WHERE m.trusted_legacy=1 AND m.is_void=1");

            // If an API observation previously claimed more boards than the historical
            // trusted record, remove only the surplus derived rows.
            $pdo->exec("DELETE e FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id WHERE m.trusted_legacy=1 AND m.is_void=0 AND e.board_no>m.board_count");
            $pdo->exec("DELETE g FROM p2k_g_games g JOIN p2k_g_matches m ON m.match_id=g.match_id WHERE m.trusted_legacy=1 AND m.is_void=0 AND g.board_no>m.board_count");
            $pdo->exec("DELETE b FROM p2k_g_boards b JOIN p2k_g_matches m ON m.match_id=b.match_id WHERE m.trusted_legacy=1 AND m.is_void=0 AND b.board_no>m.board_count");
            $pdo->exec("DELETE mp FROM p2k_g_match_players mp JOIN p2k_g_matches m ON m.match_id=mp.match_id WHERE m.trusted_legacy=1 AND m.is_void=0 AND mp.board_no IS NOT NULL AND mp.board_no>m.board_count");

            // Recreate only genuinely missing trusted board skeletons. Existing hydrated
            // boards are preserved; missing historical rows become normal seed_board work.
            $missing=$pdo->query("SELECT m.match_id,m.board_count,COUNT(b.board_no) have_boards FROM p2k_g_matches m LEFT JOIN p2k_g_boards b ON b.match_id=m.match_id WHERE m.trusted_legacy=1 AND m.is_void=0 AND m.board_count>0 GROUP BY m.match_id,m.board_count HAVING COUNT(b.board_no)<m.board_count")->fetchAll()?:[];
            $insBoard=$pdo->prepare("INSERT IGNORE INTO p2k_g_boards(match_id,board_no,board_url,state,needs_refresh,updated_at) VALUES(?,?,?,'unknown',1,UTC_TIMESTAMP())");
            $skeletons=0;
            foreach($missing as $m){
                $id=(int)$m['match_id'];$count=(int)$m['board_count'];
                for($b=1;$b<=$count;$b++){$insBoard->execute([$id,$b,'https://api.chess.com/pub/match/'.$id.'/'.$b]);$skeletons+=$insBoard->rowCount();}
            }

            $pdo->commit();
            return $audit+['imported'=>$imported,'board_skeletons_created'=>$skeletons,'source_path'=>$path];
        }catch(\Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
