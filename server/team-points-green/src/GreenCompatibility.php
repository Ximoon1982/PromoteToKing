<?php
declare(strict_types=1);
namespace P2K\Green;

use PDO;
use P2K\TeamPoints\AnalyticsBuilder;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\Repository;

/** Green-owned compatibility projection consumed by existing public read services. */
final class GreenCompatibility
{
    public function __construct(private GreenRepository $green) {}

    public function ensureSchema(): array
    {
        $repo = new Repository($this->green->core,$this->green->analytics);
        if (!$repo->schemaInstalled()) {
            if ($repo->schemaVersion()===0 || $repo->analyticsSchemaVersion()===0) $repo->installSchema();
            else $repo->upgradeExistingSchema();
        }
        return ['core_schema'=>$repo->schemaVersion(),'analytics_schema'=>$repo->analyticsSchemaVersion(),'ready'=>$repo->schemaInstalled()];
    }

    private function opponentSlug(?string $url): ?string
    {
        $path=parse_url((string)$url,PHP_URL_PATH);if(!is_string($path))return null;
        if(preg_match('~/club/([^/]+)~i',$path,$m))return strtolower(rawurldecode($m[1]));
        return null;
    }

    /**
     * Build one deterministic compatibility lineup row per authoritative Green board/side.
     *
     * p2k_g_match_players is keyed by (match_id, username_key), so a historical alias
     * can legitimately coexist with a newer username on the same board. Joining that
     * table directly to p2k_g_boards therefore multiplies a board and can violate the
     * compatibility UNIQUE(match_id,board_no) key. Prefer point-event evidence and the
     * authoritative board payload, then trusted/canonical identity evidence.
     */
    private function canonicalBoardProjection(int $matchId): array
    {
        $bq=$this->green->core->prepare("SELECT gb.*,m.status match_status FROM p2k_g_boards gb JOIN p2k_g_matches m ON m.match_id=gb.match_id WHERE gb.match_id=? ORDER BY gb.board_no");
        $bq->execute([$matchId]);
        $boards=$bq->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(!$boards)return ['rows'=>[],'duplicates_resolved'=>0,'member_duplicates_resolved'=>0];
        $boardByNo=[];foreach($boards as $b)$boardByNo[(int)$b['board_no']]=$b;

        $cq=$this->green->core->prepare("SELECT mp.*,COALESCE(im.canonical_username_key,mp.username_key) canonical_username_key,
              CASE WHEN im.username_key IS NOT NULL AND im.username_key=im.canonical_username_key THEN 1 ELSE 0 END identity_is_canonical,
              COALESCE(im.trusted,0) identity_trusted,
              CASE WHEN EXISTS(SELECT 1 FROM p2k_g_point_events e WHERE e.match_id=mp.match_id AND e.board_no=mp.board_no AND e.username_key=mp.username_key) THEN 1 ELSE 0 END has_point_event
            FROM p2k_g_match_players mp
            LEFT JOIN p2k_g_identity_map im ON im.username_key=mp.username_key
            WHERE mp.match_id=? AND mp.board_no IS NOT NULL
            ORDER BY mp.board_no,mp.is_p2k DESC,mp.updated_at DESC,mp.username_key");
        $cq->execute([$matchId]);
        $byBoard=[];$p2kCandidates=[];
        foreach($cq->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $bn=(int)($row['board_no']??0);if($bn<=0||!isset($boardByNo[$bn]))continue;
            $side=(int)($row['is_p2k']??0)===1?'p2k':'opp';
            $byBoard[$bn][$side][]=$row;
            if($side==='p2k')$p2kCandidates[]=['board_no'=>$bn,'row'=>$row];
        }

        $rank=static function(array $row,array $board): array {
            $key=strtolower((string)($row['username_key']??''));
            $white=strtolower((string)($board['white_username']??''));
            $black=strtolower((string)($board['black_username']??''));
            $boardExact=($key!==''&&($key===$white||$key===$black))?1:0;
            $updated=strtotime((string)($row['updated_at']??'').' UTC');if($updated===false)$updated=0;
            $mutable=in_array((string)($board['match_status']??''),['registered','in_progress'],true);
            // Registration lineups are mutable: the newest authoritative match snapshot
            // wins before historical identity hints. Finished boards prefer game evidence.
            return $mutable
                ? [(int)$updated,$boardExact,(int)($row['has_point_event']??0),(int)($row['identity_is_canonical']??0),(int)($row['identity_trusted']??0)]
                : [(int)($row['has_point_event']??0),$boardExact,(int)($row['identity_is_canonical']??0),(int)($row['identity_trusted']??0),(int)$updated];
        };
        $compare=static function(array $a,array $b) use($rank,$boardByNo): int {
            $ra=$rank($a['row'],$boardByNo[(int)$a['board_no']]);$rb=$rank($b['row'],$boardByNo[(int)$b['board_no']]);
            for($i=0;$i<count($ra);$i++){if($ra[$i]!==$rb[$i])return $rb[$i]<=>$ra[$i];}
            $bn=(int)$a['board_no']<=>(int)$b['board_no'];if($bn!==0)return $bn;
            return strcmp((string)($a['row']['username_key']??''),(string)($b['row']['username_key']??''));
        };
        usort($p2kCandidates,$compare);
        $chosen=[];$usedMembers=[];$memberDuplicates=0;
        foreach($p2kCandidates as $candidate){
            $bn=(int)$candidate['board_no'];$row=$candidate['row'];
            $member=strtolower(trim((string)($row['canonical_username_key']??$row['username_key']??'')));if($member==='')$member=strtolower((string)($row['username_key']??''));
            if(isset($chosen[$bn]))continue;
            if($member!==''&&isset($usedMembers[$member])){$memberDuplicates++;continue;}
            $chosen[$bn]=$row;if($member!=='')$usedMembers[$member]=$bn;
        }

        $pickOpponent=static function(array $candidates,array $board) use($rank): ?array {
            if(!$candidates)return null;
            usort($candidates,static function(array $a,array $b) use($rank,$board): int {
                $ra=$rank($a,$board);$rb=$rank($b,$board);
                for($i=0;$i<count($ra);$i++){if($ra[$i]!==$rb[$i])return $rb[$i]<=>$ra[$i];}
                return strcmp((string)($a['username_key']??''),(string)($b['username_key']??''));
            });
            return $candidates[0]??null;
        };

        $out=[];$duplicates=0;
        foreach($boards as $board){
            $bn=(int)$board['board_no'];$p2kCandidatesOnBoard=$byBoard[$bn]['p2k']??[];$oppCandidates=$byBoard[$bn]['opp']??[];
            $duplicates+=max(0,count($p2kCandidatesOnBoard)-1)+max(0,count($oppCandidates)-1);
            $mp=$chosen[$bn]??null;if(!is_array($mp))continue;
            $opp=$pickOpponent($oppCandidates,$board);
            $board['username_key']=(string)$mp['username_key'];
            $board['canonical_username_key']=(string)($mp['canonical_username_key']??$mp['username_key']);
            $p2kRating=is_numeric($mp['start_rating']??null)&&(int)$mp['start_rating']>0?(int)$mp['start_rating']:null;
            $opponentRating=is_array($opp)&&is_numeric($opp['start_rating']??null)&&(int)$opp['start_rating']>0?(int)$opp['start_rating']:null;
            if($p2kRating===null||$opponentRating===null){$stored=$this->storedGameRatingPair($matchId,$bn,(string)($mp['canonical_username_key']??$mp['username_key']));if($p2kRating===null&&is_numeric($stored['p2k']??null))$p2kRating=(int)$stored['p2k'];if($opponentRating===null&&is_numeric($stored['opponent']??null))$opponentRating=(int)$stored['opponent'];$board['rating_source']=$stored['source']??null;}
            $board['p2k_rating']=$p2kRating;
            $board['played_as_white']=$mp['played_as_white']??null;
            $board['played_as_black']=$mp['played_as_black']??null;
            $board['opponent_rating']=$opponentRating;
            $opponentUsername=is_array($opp)?trim((string)($opp['username']??$opp['username_key']??'')):'';
            if($opponentUsername===''){
                $p2kKey=strtolower((string)($mp['username_key']??''));
                $white=trim((string)($board['white_username']??''));$black=trim((string)($board['black_username']??''));
                if($white!==''&&strtolower($white)===$p2kKey)$opponentUsername=$black;
                elseif($black!==''&&strtolower($black)===$p2kKey)$opponentUsername=$white;
            }
            $board['opponent_username']=$opponentUsername!==''?$opponentUsername:null;
            $out[]=$board;
        }
        return ['rows'=>$out,'duplicates_resolved'=>$duplicates+$memberDuplicates,'member_duplicates_resolved'=>$memberDuplicates];
    }

    /**
     * Recover one paired rating observation for a board from already-stored Green
     * game payloads. Historical /pub/match/{id} responses do not expose player
     * ratings, while /pub/match/{id}/{board} game objects do. Point-event identity
     * evidence identifies the P2K side even across historical username aliases.
     */
    private function storedGameRatingPair(int $matchId,int $boardNo,string $memberKey): array
    {
        $memberKey=strtolower(trim($memberKey));
        $q=$this->green->core->prepare("SELECT g.game_index,g.white_username,g.black_username,g.white_rating,g.black_rating,g.updated_at,
                   e.username_key event_username_key,COALESCE(im.canonical_username_key,e.username_key) event_canonical_username_key,
                   COALESCE(iw.canonical_username_key,LOWER(g.white_username)) white_canonical_username_key,
                   COALESCE(ib.canonical_username_key,LOWER(g.black_username)) black_canonical_username_key
              FROM p2k_g_games g
              LEFT JOIN p2k_g_point_events e ON e.game_url=g.game_url AND e.match_id=g.match_id AND e.board_no=g.board_no
              LEFT JOIN p2k_g_identity_map im ON im.username_key=e.username_key
              LEFT JOIN p2k_g_identity_map iw ON iw.username_key=LOWER(g.white_username)
              LEFT JOIN p2k_g_identity_map ib ON ib.username_key=LOWER(g.black_username)
             WHERE g.match_id=? AND g.board_no=?
             ORDER BY g.game_index,g.updated_at DESC,e.updated_at DESC");
        $q->execute([$matchId,$boardNo]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $wr=is_numeric($row['white_rating']??null)?(int)$row['white_rating']:0;
            $br=is_numeric($row['black_rating']??null)?(int)$row['black_rating']:0;
            if($wr<=0||$br<=0)continue;
            $eventKey=strtolower((string)($row['event_username_key']??''));
            $eventCanonical=strtolower((string)($row['event_canonical_username_key']??''));
            $whiteKey=strtolower((string)($row['white_username']??''));
            $blackKey=strtolower((string)($row['black_username']??''));
            if($eventCanonical!==''&&$eventCanonical===$memberKey){
                if($eventKey!==''&&$eventKey===$whiteKey)return ['p2k'=>$wr,'opponent'=>$br,'source'=>'stored_game'];
                if($eventKey!==''&&$eventKey===$blackKey)return ['p2k'=>$br,'opponent'=>$wr,'source'=>'stored_game'];
            }
            $wc=strtolower((string)($row['white_canonical_username_key']??''));
            $bc=strtolower((string)($row['black_canonical_username_key']??''));
            if($wc!==''&&$wc===$memberKey)return ['p2k'=>$wr,'opponent'=>$br,'source'=>'stored_game'];
            if($bc!==''&&$bc===$memberKey)return ['p2k'=>$br,'opponent'=>$wr,'source'=>'stored_game'];
        }
        return ['p2k'=>null,'opponent'=>null,'source'=>null];
    }

    /**
     * Build one deterministic compatibility game row per authoritative board sequence.
     *
     * Historical aliases can leave more than one p2k_g_point_events row for the same
     * Green game. Once those aliases resolve to the same canonical player, a direct
     * join multiplies the game and can violate compatibility UNIQUE(board_id,sequence_no).
     * Green games can also retain stale duplicate URLs at the same game_index. Prefer
     * the canonical username event, then trusted identity evidence, then the newest
     * deterministic observation, and emit exactly one row per sequence.
     */
    private function canonicalGameProjection(int $matchId,int $boardNo,string $memberKey): array
    {
        $memberKey=strtolower(trim($memberKey));
        $q=$this->green->core->prepare("SELECT g.*,
                   e.username_key event_username_key,e.result event_result,e.points event_points,e.updated_at event_updated_at,
                   COALESCE(im.canonical_username_key,e.username_key) event_canonical_username_key,
                   COALESCE(im.trusted,0) event_identity_trusted
              FROM p2k_g_games g
              JOIN p2k_g_point_events e ON e.game_url=g.game_url AND e.match_id=g.match_id AND e.board_no=g.board_no
              LEFT JOIN p2k_g_identity_map im ON im.username_key=e.username_key
             WHERE g.match_id=? AND g.board_no=? AND COALESCE(im.canonical_username_key,e.username_key)=?
             ORDER BY g.game_index,
                      CASE WHEN e.username_key=? THEN 0 ELSE 1 END,
                      COALESCE(im.trusted,0) DESC,
                      e.updated_at DESC,g.updated_at DESC,e.username_key,g.game_url");
        $q->execute([$matchId,$boardNo,$memberKey,$memberKey]);
        $bySequence=[];$duplicates=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $seq=max(1,(int)($row['game_index']??0));
            if(isset($bySequence[$seq])){$duplicates++;continue;}
            $row['result']=$row['event_result']??null;
            $row['points']=$row['event_points']??0;
            $bySequence[$seq]=$row;
        }
        ksort($bySequence,SORT_NUMERIC);
        return ['rows'=>array_values($bySequence),'duplicates_resolved'=>$duplicates];
    }

    private function projectMemberRows(?string $usernameKey=null): int
    {
        $this->ensureSchema();$club=$this->green->clubSlug;
        $filter=$usernameKey===null?'':' WHERE p.username_key=?';
        $sql="INSERT INTO p2k_tp_members(club_slug,username_key,username,player_id,current_member,joined_at,daily_rating,chess960_rating,rating_updated_at,player_matches_checked_at,stats_checked_at,avatar_url,profile_url,country_code,profile_status,avatar_checked_at,profile_updated_at,first_seen_at,last_seen_at)
              SELECT ?,p.username_key,LEFT(p.username,80),p.chess_player_id,p.current_member,CASE WHEN p.joined_epoch IS NULL THEN NULL ELSE FROM_UNIXTIME(p.joined_epoch) END,
                     NULLIF(p.daily_rating,0),NULLIF(p.chess960_rating,0),p.stats_checked_at,p.profile_checked_at,p.stats_checked_at,LEFT(p.avatar_url,500),CONCAT('https://www.chess.com/member/',p.username_key),
                     CASE WHEN p.country_url IS NULL OR p.country_url='' THEN NULL ELSE UPPER(SUBSTRING_INDEX(p.country_url,'/',-1)) END,p.account_status,p.profile_checked_at,p.profile_checked_at,p.created_at,p.updated_at
              FROM p2k_g_players p{$filter}
              ON DUPLICATE KEY UPDATE username=VALUES(username),player_id=VALUES(player_id),current_member=VALUES(current_member),joined_at=COALESCE(VALUES(joined_at),joined_at),daily_rating=VALUES(daily_rating),chess960_rating=VALUES(chess960_rating),rating_updated_at=VALUES(rating_updated_at),player_matches_checked_at=VALUES(player_matches_checked_at),stats_checked_at=VALUES(stats_checked_at),avatar_url=VALUES(avatar_url),profile_url=VALUES(profile_url),country_code=COALESCE(VALUES(country_code),country_code),profile_status=COALESCE(VALUES(profile_status),profile_status),avatar_checked_at=VALUES(avatar_checked_at),profile_updated_at=VALUES(profile_updated_at),last_seen_at=VALUES(last_seen_at)";
        $q=$this->green->core->prepare($sql);$args=[$club];if($usernameKey!==null)$args[]=strtolower($usernameKey);$q->execute($args);
        $this->green->core->prepare("INSERT INTO p2k_tp_state(club_slug,core_generation,members_last_observed_at,members_last_verified_at,updated_at) VALUES(?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE core_generation=core_generation+1,members_last_observed_at=UTC_TIMESTAMP(),members_last_verified_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()")->execute([$club]);
        return (int)$q->rowCount();
    }

    public function projectMembers(): int { return $this->projectMemberRows(null); }
    public function projectMember(string $username): int { return $this->projectMemberRows(strtolower(trim($username))); }

    public function projectMatch(int $matchId,bool $projectMembers=true): array
    {
        $this->ensureSchema();if($projectMembers)$this->projectMembers();$club=$this->green->clubSlug;
        $q=$this->green->core->prepare("SELECT * FROM p2k_g_matches WHERE match_id=? AND club_verified=1 AND time_class='daily' LIMIT 1");$q->execute([$matchId]);$m=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($m)){
            $this->green->core->prepare('DELETE FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=?')->execute([$club,$matchId]);
            return ['projected'=>false,'match_id'=>$matchId,'reason'=>'not_eligible'];
        }
        $status=(string)$m['status'];if(in_array($status,['cancelled','unavailable'],true))$status='finished';if(!in_array($status,['unknown','registered','in_progress','finished'],true))$status='unknown';
        $result=(string)$m['result'];if(!in_array($result,['win','draw','loss'],true))$result='unknown';
        $slug=$this->opponentSlug((string)($m['opponent_url']??''));
        $projection=$this->canonicalBoardProjection($matchId);$projectedBoards=$projection['rows']??[];
        $p2kRatings=[];$oppRatings=[];$rated=0;$maxRating=null;
        foreach($projectedBoards as $pb){
            $pr=is_numeric($pb['p2k_rating']??null)?(int)$pb['p2k_rating']:0;$or=is_numeric($pb['opponent_rating']??null)?(int)$pb['opponent_rating']:0;
            if($pr<=0||$or<=0)continue;
            $p2kRatings[]=$pr;$oppRatings[]=$or;$rated++;$maxRating=$maxRating===null?max($pr,$or):max($maxRating,$pr,$or);
        }
        $r=['p2k_avg'=>$p2kRatings?(int)round(array_sum($p2kRatings)/count($p2kRatings)):null,'opp_avg'=>$oppRatings?(int)round(array_sum($oppRatings)/count($oppRatings)):null,'rated'=>$rated,'max_rating'=>$maxRating];
        $up=$this->green->core->prepare("INSERT INTO p2k_tp_match_metadata(club_slug,match_id,match_name,match_url,status,observed_status,rules,time_control,is_league,start_time,end_time,board_count,p2k_score,opponent_score,p2k_avg_rating,opponent_avg_rating,rated_board_count,max_rating,first_discovered_at,result,competition_points,is_void,opponent_slug,opponent_name,opponent_url,discovery_source,last_verified_at,last_observed_at,last_index_seen_at,next_detail_check_at,finalized_at,updated_at)
          VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
          ON DUPLICATE KEY UPDATE match_name=VALUES(match_name),match_url=VALUES(match_url),status=VALUES(status),observed_status=VALUES(observed_status),rules=VALUES(rules),time_control=VALUES(time_control),start_time=VALUES(start_time),end_time=VALUES(end_time),board_count=VALUES(board_count),p2k_score=VALUES(p2k_score),opponent_score=VALUES(opponent_score),p2k_avg_rating=VALUES(p2k_avg_rating),opponent_avg_rating=VALUES(opponent_avg_rating),rated_board_count=VALUES(rated_board_count),max_rating=VALUES(max_rating),result=VALUES(result),competition_points=VALUES(competition_points),is_void=VALUES(is_void),opponent_slug=VALUES(opponent_slug),opponent_name=VALUES(opponent_name),opponent_url=VALUES(opponent_url),last_verified_at=VALUES(last_verified_at),last_observed_at=VALUES(last_observed_at),last_index_seen_at=VALUES(last_index_seen_at),finalized_at=VALUES(finalized_at),updated_at=UTC_TIMESTAMP()");
        $up->execute([$club,$matchId,(string)($m['name']??''),$m['web_url'],$status,$status,$m['rules'],$m['time_control'],0,$m['start_epoch']?gmdate('Y-m-d H:i:s',(int)$m['start_epoch']):null,$m['end_epoch']?gmdate('Y-m-d H:i:s',(int)$m['end_epoch']):null,(int)($m['board_count']??0),(float)($m['p2k_score']??0),(float)($m['opponent_score']??0),$r['p2k_avg']??null,$r['opp_avg']??null,(int)($r['rated']??0),$r['max_rating']??null,$m['created_at'],$result,max(0,(int)($m['competition_points']??0)),(int)$m['is_void'],$slug,$m['opponent_name'],$m['opponent_url'],(string)($m['fact_source']??'green'),$m['last_verified_at'],$m['last_observed_at'],$m['last_observed_at'],null,$status==='finished'?($m['end_epoch']?gmdate('Y-m-d H:i:s',(int)$m['end_epoch']):$m['last_verified_at']):null]);
        if($slug){$this->green->core->prepare("INSERT INTO p2k_tp_opponents(club_slug,opponent_slug,display_name,club_url,first_seen_at,last_seen_at) VALUES(?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE display_name=COALESCE(NULLIF(VALUES(display_name),''),display_name),club_url=COALESCE(VALUES(club_url),club_url),last_seen_at=UTC_TIMESTAMP()")->execute([$club,$slug,(string)($m['opponent_name']??$slug),$m['opponent_url']]);}

        // Rebuild only this match's compact board/game compatibility rows. Cascading delete removes old games.
        $this->green->core->prepare('DELETE b FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND b.match_id=?')->execute([$club,$matchId]);
        $boards=$projectedBoards;
        $insB=$this->green->core->prepare("INSERT INTO p2k_tp_boards(member_id,match_id,board_no,p2k_rating,opponent_rating,opponent_username,rating_source,rating_captured_at,board_url_override,white_result,black_result,source_bucket,state,finished_game_count,first_discovered_at,last_discovered_at,last_checked_at,next_check_at,completed_at,failure_count,last_error) VALUES(?,?,?,?,?,?,'green',UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $memberId=$this->green->core->prepare('SELECT member_id FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
        $gameRows=0;$boardRows=0;$gameDuplicatesResolved=0;
        foreach($boards as $b){$memberKey=(string)($b['canonical_username_key']??$b['username_key']);$memberId->execute([$club,$memberKey]);$mid=(int)$memberId->fetchColumn();if($mid<=0){$memberId->execute([$club,$b['username_key']]);$mid=(int)$memberId->fetchColumn();}if($mid<=0)continue;$source=in_array($b['match_status'],['registered','in_progress','finished'],true)?$b['match_status']:'unknown';$state=match((string)$b['state']){'finished'=>'complete_immutable','in_progress'=>'recent_in_progress','cancelled','unavailable'=>'failed_malformed',default=>'potentially_incomplete'};$completed=$b['state']==='finished'?($b['last_verified_at']??$b['updated_at']):null;
            $insB->execute([$mid,$matchId,(int)$b['board_no'],$b['p2k_rating'],$b['opponent_rating'],$b['opponent_username']??null,$b['board_url'],$b['played_as_white'],$b['played_as_black'],$source,$state,(int)$b['finished_game_count'],$b['updated_at'],$b['updated_at'],$b['last_verified_at'],$b['retry_after'],$completed,0,$b['last_http_status']>=400?'Green HTTP '.$b['last_http_status']:null]);$boardRows++;
            $bid=(int)$this->green->core->lastInsertId();if($bid<=0){$z=$this->green->core->prepare('SELECT board_id FROM p2k_tp_boards WHERE match_id=? AND board_no=?');$z->execute([$matchId,(int)$b['board_no']]);$bid=(int)$z->fetchColumn();}
            $gameProjection=$this->canonicalGameProjection($matchId,(int)$b['board_no'],$memberKey);
            $gameDuplicatesResolved+=(int)($gameProjection['duplicates_resolved']??0);
            $ig=$this->green->core->prepare("INSERT INTO p2k_tp_games(board_id,sequence_no,game_id,game_url_override,game_end_utc,result_code,points_x2,source_hash,verified_at,is_seed) VALUES(?,?,?,?,?,?,?,?,?,0)");
            foreach($gameProjection['rows']??[] as $g){$gid=null;if(preg_match('~/(?:daily|live)/(\d+)(?:$|[/?#])~',(string)$g['game_url'],$mm))$gid=(int)$mm[1];$end=(int)($g['end_epoch']??0);if($end<=0)continue;$hash=hash('sha256',(string)$g['game_url'].'|'.(string)$g['result'].'|'.(string)$g['points'],true);$ig->execute([$bid,max(1,(int)$g['game_index']),$gid,(string)$g['game_url'],gmdate('Y-m-d H:i:s',$end),(string)($g['result']??''),max(0,min(4,(int)round(((float)$g['points'])*2))),$hash,$g['updated_at']]);$gameRows++;}
        }
        $this->green->core->prepare('UPDATE p2k_tp_state SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$club]);
        return ['projected'=>true,'match_id'=>$matchId,'boards'=>$boardRows,'games'=>$gameRows,'lineup_duplicates_resolved'=>(int)($projection['duplicates_resolved']??0),'member_duplicates_resolved'=>(int)($projection['member_duplicates_resolved']??0),'game_duplicates_resolved'=>$gameDuplicatesResolved];
    }

    public function projectMatchBatch(int $afterId=0,int $limit=100): array
    {
        $limit=max(1,min(500,$limit));$this->projectMembers();$q=$this->green->core->prepare("SELECT match_id FROM p2k_g_matches WHERE match_id>? AND club_verified=1 AND time_class='daily' ORDER BY match_id LIMIT {$limit}");$q->execute([$afterId]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);$last=$afterId;$changed=0;
        foreach($ids as $id){$this->projectMatch($id,false);$last=$id;$changed++;}
        return ['processed'=>$changed,'last_id'=>$last,'drained'=>count($ids)<$limit];
    }

    /**
     * GABCRF: repair compatibility rows whose authoritative Green evidence moved
     * after the original ascending-ID projection cursor had already passed them.
     * The lane is resumable; at the end of a pass it rechecks from ID zero so
     * writes that landed behind the cursor during the pass cannot be missed.
     */
    public function reconcileCompatibilityBatch(int $afterId=0,int $limit=50): array
    {
        $this->ensureSchema();$this->projectMembers();$club=$this->green->clubSlug;$limit=max(1,min(250,$limit));
        $candidateSql="SELECT gm.match_id
          FROM p2k_g_matches gm
          LEFT JOIN p2k_tp_match_metadata cm ON cm.club_slug=? AND cm.match_id=gm.match_id
         WHERE gm.match_id>? AND gm.club_verified=1 AND gm.time_class='daily' AND (
               cm.match_id IS NULL
            OR COALESCE(UNIX_TIMESTAMP(gm.updated_at),0)>COALESCE(UNIX_TIMESTAMP(cm.updated_at),0)
            OR COALESCE((SELECT UNIX_TIMESTAMP(MAX(gb.updated_at)) FROM p2k_g_boards gb WHERE gb.match_id=gm.match_id),0)>COALESCE(UNIX_TIMESTAMP(cm.updated_at),0)
            OR COALESCE((SELECT UNIX_TIMESTAMP(MAX(gg.updated_at)) FROM p2k_g_games gg WHERE gg.match_id=gm.match_id),0)>COALESCE(UNIX_TIMESTAMP(cm.updated_at),0)
            OR (SELECT COUNT(DISTINCT COALESCE(im2.canonical_username_key,mp2.username_key)) FROM p2k_g_match_players mp2 LEFT JOIN p2k_g_identity_map im2 ON im2.username_key=mp2.username_key WHERE mp2.match_id=gm.match_id AND mp2.is_p2k=1 AND mp2.board_no IS NOT NULL)
               <> (SELECT COUNT(*) FROM p2k_tp_boards cb JOIN p2k_tp_members cu ON cu.member_id=cb.member_id WHERE cu.club_slug=? AND cb.match_id=gm.match_id)
            OR ((COALESCE(cm.p2k_avg_rating,0)<=0 OR COALESCE(cm.opponent_avg_rating,0)<=0 OR COALESCE(cm.rated_board_count,0)<=0)
                AND EXISTS(SELECT 1 FROM p2k_g_games gr WHERE gr.match_id=gm.match_id AND COALESCE(gr.white_rating,0)>0 AND COALESCE(gr.black_rating,0)>0))
            OR (SELECT COUNT(DISTINCT CONCAT(gx.board_no,':',GREATEST(1,gx.game_index))) FROM p2k_g_games gx JOIN p2k_g_point_events ex ON ex.game_url=gx.game_url AND ex.match_id=gx.match_id AND ex.board_no=gx.board_no WHERE gx.match_id=gm.match_id)
               <> (SELECT COUNT(*) FROM p2k_tp_games cg JOIN p2k_tp_boards cbg ON cbg.board_id=cg.board_id JOIN p2k_tp_members cug ON cug.member_id=cbg.member_id WHERE cug.club_slug=? AND cbg.match_id=gm.match_id)
         )
         ORDER BY gm.match_id LIMIT {$limit}";
        $q=$this->green->core->prepare($candidateSql);$q->execute([$club,$afterId,$club,$club]);$ids=array_map('intval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
        $last=$afterId;$changed=0;$boards=0;$games=0;$gameDuplicatesResolved=0;
        foreach($ids as $id){$r=$this->projectMatch($id,false);$last=$id;$changed++;$boards+=(int)($r['boards']??0);$games+=(int)($r['games']??0);$gameDuplicatesResolved+=(int)($r['game_duplicates_resolved']??0);}
        $passDrained=count($ids)<$limit;$remaining=0;
        if($passDrained){
            $countSql="SELECT COUNT(*) FROM p2k_g_matches gm LEFT JOIN p2k_tp_match_metadata cm ON cm.club_slug=? AND cm.match_id=gm.match_id
              WHERE gm.club_verified=1 AND gm.time_class='daily' AND (
                    cm.match_id IS NULL
                 OR COALESCE(UNIX_TIMESTAMP(gm.updated_at),0)>COALESCE(UNIX_TIMESTAMP(cm.updated_at),0)
                 OR COALESCE((SELECT UNIX_TIMESTAMP(MAX(gb.updated_at)) FROM p2k_g_boards gb WHERE gb.match_id=gm.match_id),0)>COALESCE(UNIX_TIMESTAMP(cm.updated_at),0)
                 OR COALESCE((SELECT UNIX_TIMESTAMP(MAX(gg.updated_at)) FROM p2k_g_games gg WHERE gg.match_id=gm.match_id),0)>COALESCE(UNIX_TIMESTAMP(cm.updated_at),0)
                 OR (SELECT COUNT(DISTINCT COALESCE(im2.canonical_username_key,mp2.username_key)) FROM p2k_g_match_players mp2 LEFT JOIN p2k_g_identity_map im2 ON im2.username_key=mp2.username_key WHERE mp2.match_id=gm.match_id AND mp2.is_p2k=1 AND mp2.board_no IS NOT NULL)
                    <> (SELECT COUNT(*) FROM p2k_tp_boards cb JOIN p2k_tp_members cu ON cu.member_id=cb.member_id WHERE cu.club_slug=? AND cb.match_id=gm.match_id)
                 OR ((COALESCE(cm.p2k_avg_rating,0)<=0 OR COALESCE(cm.opponent_avg_rating,0)<=0 OR COALESCE(cm.rated_board_count,0)<=0)
                     AND EXISTS(SELECT 1 FROM p2k_g_games gr WHERE gr.match_id=gm.match_id AND COALESCE(gr.white_rating,0)>0 AND COALESCE(gr.black_rating,0)>0))
                 OR (SELECT COUNT(DISTINCT CONCAT(gx.board_no,':',GREATEST(1,gx.game_index))) FROM p2k_g_games gx JOIN p2k_g_point_events ex ON ex.game_url=gx.game_url AND ex.match_id=gx.match_id AND ex.board_no=gx.board_no WHERE gx.match_id=gm.match_id)
                    <> (SELECT COUNT(*) FROM p2k_tp_games cg JOIN p2k_tp_boards cbg ON cbg.board_id=cg.board_id JOIN p2k_tp_members cug ON cug.member_id=cbg.member_id WHERE cug.club_slug=? AND cbg.match_id=gm.match_id)
              )";
            $c=$this->green->core->prepare($countSql);$c->execute([$club,$club,$club]);$remaining=(int)$c->fetchColumn();
        }
        return ['processed'=>$changed,'changed'=>$changed,'boards'=>$boards,'games'=>$games,'game_duplicates_resolved'=>$gameDuplicatesResolved,'last_id'=>$last,'pass_drained'=>$passDrained,'remaining'=>$remaining,'drained'=>$passDrained&&$remaining===0,'reset_cursor'=>$passDrained&&$remaining>0];
    }

    public function rebuildAnalytics(): array
    {
        $this->ensureSchema();$builder=new AnalyticsBuilder($this->green->core,$this->green->analytics);$all=$builder->rebuildAll($this->green->clubSlug);$ach=$builder->refreshAchievementsIfNeeded($this->green->clubSlug);$this->green->core->prepare('UPDATE p2k_g_state SET compat_analytics_rebuilt_at=UTC_TIMESTAMP() WHERE club_slug=?')->execute([$this->green->clubSlug]);return ['analytics'=>$all,'achievements'=>$ach];
    }

    public function maybeRebuildAnalytics(int $minimumSeconds=30): bool
    {
        // Green is authoritative even while GAB backfill/reconciliation is still converging.
        // Keep the mature public Analytics contracts current from the live Green projection
        // instead of freezing p2k_an_* until bootstrap completion. GAB remains a historical
        // completeness/backfill lane, not a freshness gate.
        $s=$this->green->state();$last=(string)($s['compat_analytics_rebuilt_at']??'');
        if($last!==''&&($ts=strtotime($last.' UTC'))!==false&&$ts>time()-max(30,$minimumSeconds))return false;
        $this->rebuildAnalytics();return true;
    }

    /**
     * Public-read contract audit used to measure Green compatibility convergence. It checks both
     * projection completeness and the mature public service methods which are reused
     * against Green.  It never reads Blue, so once GAB is complete this gate remains
     * meaningful after Blue has been frozen/retired, but no longer blocks live Green freshness.
     */
    public function smokeTest(): array
    {
        $schema=$this->ensureSchema();$repo=new Repository($this->green->core,$this->green->analytics);$club=$this->green->clubSlug;$checks=['schema'=>$repo->schemaInstalled()];$errors=[];$counts=[];
        try{
            $counts['green_members']=(int)$this->green->core->query('SELECT COUNT(*) FROM p2k_g_players')->fetchColumn();
            $q=$this->green->core->prepare('SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=?');$q->execute([$club]);$counts['compat_members']=(int)$q->fetchColumn();
            $counts['green_matches']=(int)$this->green->core->query("SELECT COUNT(*) FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily'")->fetchColumn();
            $q=$this->green->core->prepare('SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=?');$q->execute([$club]);$counts['compat_matches']=(int)$q->fetchColumn();
            $counts['green_boards']=(int)$this->green->core->query("SELECT COUNT(DISTINCT CONCAT(mp.match_id,':',COALESCE(im.canonical_username_key,mp.username_key))) FROM p2k_g_match_players mp JOIN p2k_g_matches m ON m.match_id=mp.match_id LEFT JOIN p2k_g_identity_map im ON im.username_key=mp.username_key WHERE mp.is_p2k=1 AND mp.board_no IS NOT NULL AND m.club_verified=1 AND m.time_class='daily'")->fetchColumn();
            $q=$this->green->core->prepare('SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?');$q->execute([$club]);$counts['compat_boards']=(int)$q->fetchColumn();
            $counts['green_games']=(int)$this->green->core->query("SELECT COUNT(DISTINCT CONCAT(g.match_id,':',g.board_no,':',GREATEST(1,g.game_index))) FROM p2k_g_games g JOIN p2k_g_matches m ON m.match_id=g.match_id JOIN p2k_g_point_events e ON e.match_id=g.match_id AND e.board_no=g.board_no AND e.game_url=g.game_url WHERE m.club_verified=1 AND m.time_class='daily'")->fetchColumn();
            $q=$this->green->core->prepare('SELECT COUNT(*) FROM p2k_tp_games g JOIN p2k_tp_boards b ON b.board_id=g.board_id JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=?');$q->execute([$club]);$counts['compat_games']=(int)$q->fetchColumn();
            $checks['members_projection']=$counts['green_members']===$counts['compat_members'];
            $checks['matches_projection']=$counts['green_matches']===$counts['compat_matches'];
            $checks['boards_projection']=$counts['green_boards']===$counts['compat_boards'];
            $checks['games_projection']=$counts['green_games']===$counts['compat_games'];
            $q=$this->green->analytics->prepare("SELECT COUNT(*) FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='all' AND last_error IS NULL");$q->execute([$club]);$checks['analytics_materialized']=(int)$q->fetchColumn()===1;
            $q=$this->green->analytics->prepare("SELECT COUNT(*) FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key='achievements' AND last_error IS NULL");$q->execute([$club]);$checks['achievements_materialized']=(int)$q->fetchColumn()===1;
        }catch(\Throwable $e){$checks['projection_counts']=false;$errors['projection_counts']=$e->getMessage();}

        $member='';$opponent='';
        try{$q=$this->green->core->prepare('SELECT username FROM p2k_tp_members WHERE club_slug=? ORDER BY current_member DESC,last_seen_at DESC LIMIT 1');$q->execute([$club]);$member=(string)($q->fetchColumn()?:'');}catch(\Throwable){}
        try{$q=$this->green->core->prepare('SELECT opponent_slug FROM p2k_tp_opponents WHERE club_slug=? AND disabled=0 ORDER BY last_seen_at DESC LIMIT 1');$q->execute([$club]);$opponent=(string)($q->fetchColumn()?:'');}catch(\Throwable){}
        $live=new LiveRanksService($this->green->analytics,$repo,new ChessApi($repo));
        $contracts=[
            'team'=>fn()=>$repo->publicClubDashboard($club),
            'hall'=>fn()=>$repo->publicHallOfFame($club,'','',1,10),
            'matches'=>fn()=>$repo->publicMatchInsights($club,['page'=>1,'page_size'=>5,'include_summary'=>true]),
            'members'=>fn()=>$repo->publicMemberInsights($club,['page'=>1,'page_size'=>5]),
            'opponents'=>fn()=>$repo->publicOpponentStats($club,['page'=>1,'page_size'=>5,'include_summary'=>true]),
            'achievements'=>fn()=>$repo->publicAchievementCatalog($club),
            'achievement_players'=>fn()=>$repo->publicAchievementPlayers($club,['page'=>1,'page_size'=>5]),
            'team_insights'=>fn()=>$repo->publicTeamInsights($club),
            'league_seasons'=>fn()=>$repo->publicLeagueSeasons($club),
            'recent_matches'=>fn()=>$repo->publicRecentMatches($club,168),
            'live_ranks'=>fn()=>$live->publicPayload(),
        ];
        if($member!=='')$contracts['player_profile']=fn()=>$repo->publicPlayerProfile($club,$member,'full');
        if($opponent!=='')$contracts['opponent_profile']=fn()=>$repo->publicOpponentProfile($club,$opponent);
        foreach($contracts as $key=>$fn){try{$fn();$checks['contract_'.$key]=true;}catch(\Throwable $e){$checks['contract_'.$key]=false;$errors['contract_'.$key]=$e->getMessage();}}
        return ['ready'=>!in_array(false,$checks,true),'checks'=>$checks,'errors'=>$errors,'counts'=>$counts,'schema'=>$schema,'sample_member'=>$member?:null,'sample_opponent'=>$opponent?:null];
    }
}
