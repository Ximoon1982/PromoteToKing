<?php
declare(strict_types=1);
namespace P2K\TeamPoints;

/**
 * Consumes raw Chess.com JSON that the browser already fetched.
 *
 * Browser payloads are untrusted observations. They may prioritize/discover
 * canonical work. Claim-bound ACAMR observations may update separate observed-freshness/shadow-rating fields,
 * but canonical roster membership, verified ratings, match facts and point events remain server-authoritative.
 */
final class ObservationIngestor
{
    /** @var array<string,array<string,mixed>> */
    private array $jobs=[];
    public function __construct(private readonly Repository $repository,private readonly string $clubSlug){}

    /** @return array<string,mixed> */
    public function ingest(string $url,array $payload,string $source='browser',array $context=[]):array
    {
        $source=in_array($source,['acamr','client_refresh'],true)?$source:'browser';
        $parts=parse_url($url);
        if(($parts['scheme']??'')!=='https'||strtolower((string)($parts['host']??''))!=='api.chess.com')return ['accepted'=>false,'reason'=>'unsupported_origin'];
        $segments=array_values(array_filter(explode('/',trim((string)($parts['path']??''),'/')),static fn(string $v):bool=>$v!==''));
        if(($segments[0]??'')!=='pub')return ['accepted'=>false,'reason'=>'unsupported_path'];

        if(($segments[1]??'')==='club'&&isset($segments[2])){
            $slug=strtolower(rawurldecode((string)$segments[2]));
            if($slug!==$this->clubSlug)return ['accepted'=>false,'reason'=>'other_club'];
            if(($segments[3]??'')==='matches')return $this->clubMatches($payload,$source);
            if(($segments[3]??'')==='members')return $this->clubMembers($payload);
            if(!isset($segments[3]))return $this->clubProfile($payload);
            return ['accepted'=>true,'type'=>'club_other','queued'=>0,'updated'=>0];
        }

        if(($segments[1]??'')==='player'&&isset($segments[2])){
            $username=rawurldecode((string)$segments[2]);
            if(!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$username))return ['accepted'=>false,'reason'=>'invalid_username'];
            $tail=(string)($segments[3]??'profile');
            if($tail==='matches')return $this->playerMatches($username,$payload,$source,$context);
            if($tail==='stats')return $this->playerStats($username,$payload,$source,$context);
            if($tail==='games'&&isset($segments[4],$segments[5]))return $this->playerArchiveHint($username,(string)$segments[4],(string)$segments[5],$payload,$source);
            if($tail==='profile')return $this->playerProfile($username,$payload);
            return ['accepted'=>true,'type'=>'player_other','queued'=>0,'updated'=>0];
        }

        if(($segments[1]??'')==='match'&&isset($segments[2])&&ctype_digit((string)$segments[2])){
            return $this->matchDetail((int)$segments[2],$payload);
        }
        return ['accepted'=>false,'reason'=>'unsupported_endpoint'];
    }

    private function jobId(string $lane):string
    {
        if(!isset($this->jobs[$lane]))$this->jobs[$lane]=$this->repository->createOrGetActiveJob($this->clubSlug,$lane);
        return(string)$this->jobs[$lane]['id'];
    }

    private function bucketKey(string $prefix,int $seconds):string
    {
        $now=time();$bucket=$now-($now%max(60,$seconds));
        return $prefix.':'.gmdate('YmdHi',$bucket);
    }

    private function clubProfile(array $payload):array
    {
        $count=null;foreach(['members_count','member_count','members'] as $field){$value=$payload[$field]??null;if(is_numeric($value)&&((int)$value)>=0){$count=(int)$value;break;}}
        if($count===null)return ['accepted'=>true,'type'=>'club_profile','queued'=>0,'updated'=>0,'member_count_observed'=>false];
        $this->repository->markMemberCountObserved($this->clubSlug,$count);
        return ['accepted'=>true,'type'=>'club_profile','queued'=>0,'updated'=>1,'observed_member_count'=>$count,'member_count_observed'=>true,'canonical_roster_written'=>false];
    }

    private function clubMembers(array $payload):array
    {
        $seen=[];
        foreach(['weekly','monthly','all_time'] as $group){
            foreach(is_array($payload[$group]??null)?$payload[$group]:[] as $entry){
                if(is_string($entry))$username=trim($entry);
                elseif(is_array($entry))$username=trim((string)($entry['username']??$entry['name']??''));
                else $username='';
                if($username!==''&&preg_match('/^[A-Za-z0-9_-]{1,80}$/',$username))$seen[\p2k_tp_username_key($username)]=true;
            }
        }
        if($seen===[])return ['accepted'=>false,'reason'=>'invalid_member_payload'];
        $this->repository->markMembersObserved($this->clubSlug,count($seen),false);
        $key=$this->bucketKey('browser-roster-refresh',1800);
        $queued=$this->repository->enqueue($this->jobId('player'),'sync_roster',$key,['source'=>'browser_observation','priority_discovery'=>true]);
        return ['accepted'=>true,'type'=>'club_members','queued'=>$queued?1:0,'updated'=>1,'observed_member_count'=>count($seen),'verification'=>'server_required','canonical_membership_written'=>false];
    }

    private function playerProfile(string $username,array $payload):array
    {
        $payloadName=trim((string)($payload['username']??''));
        if($payloadName!=='' && \p2k_tp_username_key($payloadName)!==\p2k_tp_username_key($username))return ['accepted'=>false,'reason'=>'profile_username_mismatch'];
        $snapshot=$this->repository->playerProfileSnapshot($this->clubSlug,$username);
        if($snapshot===null)return ['accepted'=>true,'type'=>'player_profile','queued'=>0,'updated'=>0,'known_member'=>false];
        $updated=$this->repository->storeObservedPlayerProfile($this->clubSlug,$username,$payload)?1:0;
        $key=$this->bucketKey('browser-profile-refresh:'.\p2k_tp_username_key($username),900);
        $queued=$this->repository->enqueue($this->jobId('player'),'sync_player_profile',$key,['username'=>$username,'source'=>'browser_observation','force_refresh'=>true]);
        return ['accepted'=>true,'type'=>'player_profile','queued'=>$queued?1:0,'updated'=>$updated,'known_member'=>true,'verification'=>'server_required','canonical_profile_written'=>false];
    }

    private function playerStats(string $username,array $payload,string $source='browser',array $context=[]):array
    {
        $snapshot=$this->repository->playerProfileSnapshot($this->clubSlug,$username);
        if($snapshot===null)return ['accepted'=>true,'type'=>'player_stats','queued'=>0,'updated'=>0,'known_member'=>false];
        $claimVerified=in_array($source,['acamr','client_refresh'],true)&&!empty($context['claim_verified']);
        $claimUsername=trim((string)($context['claim_username']??''));
        if($claimVerified&&$claimUsername!==''&&\p2k_tp_username_key($claimUsername)!==\p2k_tp_username_key($username))$claimVerified=false;
        $ratings=Repository::ratingsFromStats($payload);
        if($claimVerified){
            $updated=$this->repository->storeObservedMemberRatings($this->clubSlug,$username,$ratings['daily_rating'],$ratings['chess960_rating'],'acamr_claim',true)?1:0;
            return ['accepted'=>true,'type'=>'player_stats','queued'=>0,'updated'=>$updated,'known_member'=>true,'claim_verified'=>true,
                'observed_daily_rating'=>$ratings['daily_rating'],'observed_chess960_rating'=>$ratings['chess960_rating'],
                'verification'=>'deferred_authoritative_audit','canonical_rating_written'=>false];
        }
        $updated=$this->repository->storeObservedMemberRatings($this->clubSlug,$username,$ratings['daily_rating'],$ratings['chess960_rating'],'browser_passive',false)?1:0;
        $key=$this->bucketKey('browser-stats-refresh:'.\p2k_tp_username_key($username),900);
        $queued=$this->repository->enqueue($this->jobId('player'),'sync_player_stats',$key,['username'=>$username,'source'=>'browser_observation','force_refresh'=>true]);
        return ['accepted'=>true,'type'=>'player_stats','queued'=>$queued?1:0,'updated'=>$updated,'known_member'=>true,'claim_verified'=>false,'verification'=>'server_required','canonical_rating_written'=>false,'passive_freshness_only'=>true];
    }

    private function matchDetail(int $id,array $payload):array
    {
        if($id<=0)return ['accepted'=>false,'reason'=>'invalid_match'];
        $payloadId=$this->matchId((string)($payload['@id']??''),(string)($payload['url']??''));
        if($payloadId!==null && $payloadId!==$id)return ['accepted'=>false,'reason'=>'match_id_mismatch'];
        $serialized=strtolower((string)json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        $relevant=$this->repository->isKnownMatch($this->clubSlug,$id)||str_contains($serialized,'/club/'.$this->clubSlug);
        if(!$relevant)return ['accepted'=>false,'reason'=>'match_not_known_or_club_related'];
        if(!$this->repository->isKnownMatch($this->clubSlug,$id)){$this->repository->recordObservedClubMatchReference($this->clubSlug,$id,'unknown',$payload);$updated=1;}
        else $updated=$this->repository->markMatchPassiveObserved($this->clubSlug,$id)?1:0;
        $key=$this->bucketKey('browser-match-detail:'.$id,900);
        $queued=$this->repository->enqueue($this->jobId('club'),'sync_match',$key,['match_id'=>$id,'source'=>'browser_observation','priority_discovery'=>true]);
        return ['accepted'=>true,'type'=>'match','queued'=>$queued?1:0,'updated'=>$updated,'verification'=>'server_required','canonical_match_written'=>false];
    }

    private function clubMatches(array $payload,string $source='browser'):array
    {
        $seen=0;$queued=0;$updated=0;$counts=['registered'=>0,'in_progress'=>0,'finished'=>0];
        // ACAMR may use match facts only for Team Points verification. Registration
        // monitoring/recruitment is explicitly outside its scope. Ordinary browser
        // observations cover the complete club index and register discoveries immediately.
        $buckets=$source==='acamr'?['in_progress','finished']:['registered','in_progress','finished'];
        foreach($buckets as $bucket){
            $rows=is_array($payload[$bucket]??null)?$payload[$bucket]:[];$counts[$bucket]=count($rows);
            foreach($rows as $entry){
                if(!is_array($entry)||$seen>=750)continue;
                $id=$this->matchId((string)($entry['@id']??''),(string)($entry['url']??''));if($id===null)continue;$seen++;
                $due=$this->repository->recordObservedClubMatchReference($this->clubSlug,$id,$bucket,$entry);$updated++;
                if(!$due)continue;$key=$this->bucketKey('browser-club-match:'.$id,900);
                if($this->repository->enqueue($this->jobId('club'),'sync_match',$key,['match_id'=>$id,'source'=>'browser_club_index','source_bucket'=>$bucket,'priority_discovery'=>true]))$queued++;
            }
        }
        if($source!=='acamr')$this->repository->markClubIndexObserved($this->clubSlug,$counts,false);
        // MLP Fix: do not trust the browser bucket as canonical. If the shadow
        // lifecycle differs from canonical state, queue exactly the existing
        // authoritative club-index work. v2.9.13 canonical queue identity
        // coalesces repeated producers to one outstanding club-match-index job.
        $lifecycleAuditQueued=false;
        if($seen>0 && $this->repository->observedClubLifecycleAuditDue($this->clubSlug)){
            $auditKey=$this->bucketKey('mlp-club-lifecycle-audit',60);
            $lifecycleAuditQueued=$this->repository->enqueue($this->jobId('club'),'sync_club_matches',$auditKey,[
                'club_slug'=>$this->clubSlug,'source'=>'observed_club_lifecycle','priority_discovery'=>true,'mlp_fix'=>true,
            ]);
            if($lifecycleAuditQueued)$queued++;
        }
        return ['accepted'=>true,'type'=>'club_matches','references'=>$seen,'queued'=>$queued,'updated'=>$updated+($source!=='acamr'?1:0),'observed_counts'=>$counts,'verification'=>'server_required','source'=>$source,'match_registration_processed'=>$source!=='acamr','canonical_match_status_written'=>false,'lifecycle_audit_queued'=>$lifecycleAuditQueued];
    }

    private function playerMatches(string $username,array $payload,string $source='browser',array $context=[]):array
    {
        if(!$this->repository->isCurrentMember($this->clubSlug,$username))return ['accepted'=>true,'type'=>'player_matches','queued'=>0,'updated'=>0,'current_member'=>false];
        $claimVerified=in_array($source,['acamr','client_refresh'],true)&&!empty($context['claim_verified']);$claimUsername=trim((string)($context['claim_username']??''));
        if($claimVerified&&$claimUsername!==''&&\p2k_tp_username_key($claimUsername)!==\p2k_tp_username_key($username))$claimVerified=false;
        $observedUpdated=$claimVerified?($this->repository->markPlayerMatchesObserved($this->clubSlug,$username)?1:0):($this->repository->markPlayerMatchesPassiveObserved($this->clubSlug,$username)?1:0);
        $target='https://api.chess.com/pub/club/'.$this->clubSlug;$seen=0;$matchTasks=0;$boardTasks=0;
        $fields=$source==='acamr'?[['finished','finished'],['in_progress','in_progress']]:[['finished','finished'],['in_progress','in_progress'],['registered','registered']];
        foreach($fields as [$field,$bucket]){
            foreach(is_array($payload[$field]??null)?$payload[$field]:[] as $entry){
                if(!is_array($entry)||$seen>=750)continue;
                if(rtrim(strtolower((string)($entry['club']??'')),'/')!==$target)continue;
                $seen++;$board=trim((string)($entry['board']??''));$id=$this->matchId($board,(string)($entry['@id']??$entry['url']??''));if($id===null)continue;
                if($this->repository->recordObservedClubMatchReference($this->clubSlug,$id,$bucket,$entry)){
                    $mkey=$this->bucketKey('browser-player-match:'.$id,900);
                    $queueSource=$source==='acamr'?'acamr_player_matches':'browser_player_matches';
                    if($this->repository->enqueue($this->jobId('club'),'sync_match',$mkey,['match_id'=>$id,'source'=>$queueSource,'source_bucket'=>$bucket,'priority_discovery'=>true]))$matchTasks++;
                }
                if($board===''||!str_starts_with(strtolower($board),'https://api.chess.com/pub/'))continue;
                $state=$this->repository->boardState($this->clubSlug,strtolower($username),$board);
                if(is_array($state)){
                    if((int)($state['finished_game_count']??0)>=2)continue;
                    $next=!empty($state['next_check_at'])?(strtotime((string)$state['next_check_at'].' UTC')?:0):0;if($next>time())continue;
                }
                $key=$this->bucketKey('browser-board:'.strtolower($username).':'.hash('sha256',$board),900);
                if($this->repository->enqueue($this->jobId('club'),'sync_board',$key,['username'=>$username,'match_id'=>$id,'board_url'=>$board,'source_bucket'=>$bucket,'browser_observation'=>true,'acamr'=>$source==='acamr','priority_discovery'=>true]))$boardTasks++;
            }
        }
        return ['accepted'=>true,'type'=>'player_matches','references'=>$seen,'match_tasks_queued'=>$matchTasks,'board_tasks_queued'=>$boardTasks,'queued'=>$matchTasks+$boardTasks,'updated'=>$observedUpdated,'current_member'=>true,'source'=>$source,'claim_verified'=>$claimVerified,'discovery_freshness'=>$claimVerified?'claim_observed':'passive_observed','verification'=>'targeted_server_verification','match_registration_processed'=>$source!=='acamr'];
    }

    private function playerArchiveHint(string $username,string $year,string $month,array $payload,string $source='browser'):array
    {
        if(!$this->repository->isCurrentMember($this->clubSlug,$username))return ['accepted'=>true,'type'=>'player_games_archive','queued'=>0,'updated'=>0,'current_member'=>false];
        if(!preg_match('/^20\d{2}$/',$year)||!preg_match('/^(0[1-9]|1[0-2])$/',$month))return ['accepted'=>false,'reason'=>'invalid_archive_month'];
        if(!is_array($payload['games']??null))return ['accepted'=>false,'reason'=>'invalid_archive_payload'];
        $known=[];
        foreach(array_slice($payload['games'],0,5000) as $game){
            if(!is_array($game))continue;
            $matchId=$this->matchId((string)($game['match']??''));
            if($matchId!==null&&$this->repository->isKnownMatch($this->clubSlug,$matchId))$known[$matchId]=true;
        }
        $usernameKey=\p2k_tp_username_key($username);$queued=0;$boardTasks=0;$matchTasks=0;
        if($source!=='acamr'){
            // Ordinary opportunistic observations keep the historical behavior: the
            // server re-fetches the archive before any canonical point event is stored.
            $archiveKey=$this->bucketKey('browser-archive-verify:'.$usernameKey.':'.$year.'-'.$month,900);
            if($this->repository->enqueue($this->jobId('player'),'sync_player_archive',$archiveKey,['username'=>$username,'month'=>$year.'-'.$month,'source'=>'browser_observation','force_refresh'=>true]))$queued++;
        }
        foreach(array_keys($known) as $matchId){
            $participation=$this->repository->participationForMatch($this->clubSlug,$usernameKey,(int)$matchId);
            if($source==='acamr'&&is_array($participation)){
                $board=trim((string)($participation['board_url']??''));
                if($board!==''&&str_starts_with(strtolower($board),'https://api.chess.com/pub/')){
                    $state=$this->repository->boardState($this->clubSlug,$usernameKey,$board);
                    $complete=is_array($state)&&(int)($state['finished_game_count']??0)>=2;
                    $next=is_array($state)&&!empty($state['next_check_at'])?(strtotime((string)$state['next_check_at'].' UTC')?:0):0;
                    if(!$complete&&$next<=time()){
                        $key=$this->bucketKey('acamr-archive-board:'.$usernameKey.':'.hash('sha256',$board),900);
                        if($this->repository->enqueue($this->jobId('club'),'sync_board',$key,['username'=>$username,'match_id'=>(int)$matchId,'board_url'=>$board,'source_bucket'=>'finished','browser_observation'=>true,'acamr'=>true,'priority_discovery'=>true])){$boardTasks++;$queued++;}
                    }
                }
            }
            if($this->repository->matchDetailDueFromObservation($this->clubSlug,(int)$matchId,'finished')){
                $key=$this->bucketKey(($source==='acamr'?'acamr':'browser').'-archive-match:'.$matchId,900);
                if($this->repository->enqueue($this->jobId('club'),'sync_match',$key,['match_id'=>(int)$matchId,'source'=>$source==='acamr'?'acamr_player_archive':'browser_player_archive','source_bucket'=>'finished','priority_discovery'=>true])){$matchTasks++;$queued++;}
            }
        }
        return ['accepted'=>true,'type'=>'player_games_archive','queued'=>$queued,'updated'=>0,'current_member'=>true,'known_p2k_match_hints'=>count($known),'match_tasks_queued'=>$matchTasks,'board_tasks_queued'=>$boardTasks,'verification'=>'server_required','source'=>$source,'canonical_point_events_from_browser'=>false];
    }

    private function usernameFromPlayer(mixed $player):string
    {
        if(is_string($player)){$value=trim($player);if($value==='')return'';$path=parse_url($value,PHP_URL_PATH);if(is_string($path)&&str_contains($value,'/')){$parts=array_values(array_filter(explode('/',trim($path,'/')),static fn(string $v):bool=>$v!==''));if($parts!==[])return rawurldecode((string)end($parts));}return$value;}
        if(!is_array($player))return'';foreach(['username','name'] as $field){$value=trim((string)($player[$field]??''));if($value!=='')return$value;}foreach(['@id','url','player'] as $field){$value=$this->usernameFromPlayer($player[$field]??null);if($value!=='')return$value;}return'';
    }
    private function playerSide(array $game,string $username):?string
    {
        $key=\p2k_tp_username_key($username);$players=is_array($game['players']??null)?$game['players']:[];
        foreach(['white','black'] as $side){$player=$game[$side]??($players[$side]??$game[$side.'_player']??null);$candidate=$this->usernameFromPlayer($player);if($candidate!==''&&\p2k_tp_username_key($candidate)===$key)return$side;}return null;
    }
    private function gameEndTime(array $game):int
    {
        foreach(['end_time','endTime','finished_time','finishedAt'] as $field){$value=$game[$field]??null;if(!is_numeric($value))continue;$time=(int)$value;if($time>100000000000)$time=(int)floor($time/1000);if($time>0)return$time;}return 0;
    }
    private function resultForSide(array $game,string $side):string
    {
        $players=is_array($game['players']??null)?$game['players']:[];$player=$game[$side]??($players[$side]??$game[$side.'_player']??null);
        if(is_array($player))foreach(['result','outcome'] as $field){$value=strtolower(trim((string)($player[$field]??'')));if($value!=='')return$value;}
        foreach([$side.'_result',$side.'Result'] as $field){$value=strtolower(trim((string)($game[$field]??'')));if($value!=='')return$value;}
        $results=is_array($game['results']??null)?$game['results']:[];$value=strtolower(trim((string)($results[$side]??'')));if($value!=='')return$value;
        $score=strtolower(trim((string)($game['result']??'')));if($score==='1-0')return$side==='white'?'win':'lose';if($score==='0-1')return$side==='black'?'win':'lose';if(in_array($score,['1/2-1/2','0.5-0.5','½-½'],true))return'agreed';return'';
    }
    private function pointsForResult(string $result):float
    {
        if($result==='win')return 1.0;if(in_array($result,['agreed','repetition','stalemate','insufficient','50move','timevsinsufficient'],true))return 0.5;return 0.0;
    }

    private function matchId(string ...$values):?int
    {
        foreach($values as $value)if(preg_match('~/match/(\\d+)~',$value,$m)){ $id=(int)$m[1];if($id>0)return$id;}
        return null;
    }
}
