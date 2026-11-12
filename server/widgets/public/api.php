<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/api/_common.php';
require_once dirname(__DIR__).'/src/WidgetConfigStore.php';

use P2K\Widgets\WidgetConfigStore;
use P2K\Widgets\RevisionConflict;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\ResponseCache;

$store=new WidgetConfigStore(root_dir());
$action=strtolower(trim((string)($_GET['action']??'config')));
$method=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));

try {
    if($action==='config' && $method==='GET') {
        $id=trim((string)($_GET['widget']??''));
        $settings=$store->get($id);
        json_response(200,['ok'=>true,'widget'=>$id,'settings'=>$settings]);
    }
    if($action==='admin-state' && $method==='GET') {
        Auth::requireAdmin();
        json_response(200,['ok'=>true]+$store->read());
    }
    if($action==='save' && in_array($method,['PUT','POST'],true)) {
        require_admin_write('widgets');
        $body=body_json(16384);
        $id=trim((string)($body['widget']??''));
        $revision=filter_var($body['revision']??null,FILTER_VALIDATE_INT);
        if($revision===false || $revision<0)throw new InvalidArgumentException('Revision must be a non-negative integer');
        if(!is_array($body['settings']??null))throw new InvalidArgumentException('Settings must be an object');
        json_response(200,['ok'=>true]+$store->save($id,$body['settings'],(int)$revision));
    }
    if($action==='countries' && $method==='GET') {
        $config=p2k_tp_config();
        $club=strtolower(trim((string)($config['app']['club_slug']??'promote-to-king')));
        $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
        $entry=$cache->remember('widgets-countries-v1|'.$club,3600,static function()use($club):array {
            // Opponent geography is not inferred from names. Include only countries
            // observed in the canonical opponent record, and report missing coverage.
            $core=PublicReadDatabase::core();
            $analytics=PublicReadDatabase::analytics();
            $query=$core->prepare("SELECT opponent_slug,UPPER(TRIM(COALESCE(country_code,''))) country FROM p2k_tp_opponents WHERE club_slug=?");
            $query->execute([$club]);
            $countries=[];$bySlug=[];$unclassified=0;$total=0;
            foreach($query->fetchAll(\PDO::FETCH_ASSOC)?:[] as $r) {
                $slug=(string)$r['opponent_slug'];$code=(string)$r['country'];$total++;
                if(!preg_match('/^[A-Z]{2}$/',$code)){$unclassified++;continue;}
                if(!isset($countries[$code]))$countries[$code]=['country'=>$code,'clubs'=>0,'matches'=>0];
                $countries[$code]['clubs']++;
                $bySlug[$slug]=$code;
            }
            // A bounded analytics scan, cached for an hour. Match counts come
            // from authoritative opponent statistics, never country inference.
            $stats=$analytics->prepare('SELECT opponent_slug,matches FROM p2k_an_opponent_stats WHERE club_slug=?');
            $stats->execute([$club]);
            foreach($stats->fetchAll(\PDO::FETCH_ASSOC)?:[] as $r){
                $code=$bySlug[(string)$r['opponent_slug']]??null;
                if($code!==null)$countries[$code]['matches']+=max(0,(int)$r['matches']);
            }
            $rows=array_values($countries);
            usort($rows,static fn(array $a,array $b):int=>$b['matches']<=>$a['matches']);
            return ['ok'=>true,'source'=>'P2K opponent metadata + Green opponent statistics','countries'=>$rows,'total_opponents'=>$total,'unclassified'=>$unclassified,'generated_at'=>gmdate('c')];
        },86400);
        \P2K\TeamPoints\Http::jsonCacheable($entry['payload'],200,300,1200,$entry['etag']);
    }

    // Read-only spotlight widgets reuse canonical Green member and MCA projections.
    // No widget-owned leaderboard, scores, or mutable result store is introduced.
    if($action==='spotlight-leaders' && $method==='GET') {
        $config=p2k_tp_config();
        $club=strtolower(trim((string)($config['app']['club_slug']??'promote-to-king')));
        $repository=new \P2K\TeamPoints\Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
        if(!$repository->schemaInstalled())api_error(503,'SCHEMA_NOT_INSTALLED','Team Points data is not available.');
        $generation=$repository->publicReadGenerationToken($club,true,true);
        $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
        $entry=$cache->remember('widgets-leaders-v2159|'.$club.'|'.$generation,300,static function()use($repository,$club):array{
            $daily=$repository->publicMemberInsights($club,[
                'page'=>1,'page_size'=>10,'sort'=>'points','direction'=>'desc',
                'filter'=>'current','_include_secondary'=>false
            ]);
            $topDaily=array_map(static fn(array $row):array=>[
                'username'=>(string)($row['username']??''),
                'points'=>(float)($row['points']??0),
                'rank'=>$row['daily_rank']??null,
            ],array_slice(is_array($daily['rows']??null)?$daily['rows']:[],0,5));
            $live=[];
            $q=PublicReadDatabase::analytics()->prepare(
                'SELECT username,total_points FROM p2k_lr_players WHERE club_slug=? AND current_member=1 ORDER BY total_points DESC,username_key ASC LIMIT 5'
            );
            $q->execute([$club]);
            foreach($q->fetchAll(\PDO::FETCH_ASSOC)?:[] as $row){
                $live[]=['username'=>(string)$row['username'],'points'=>(float)$row['total_points']];
            }
            return ['ok'=>true,'daily'=>$topDaily,'arena'=>$live,'source'=>'Green Members Insights + canonical MCA players'];
        },900);
        \P2K\TeamPoints\Http::jsonCacheable($entry['payload'],200,90,300,$entry['etag']);
    }
    if($action==='tournament-highlights' && $method==='GET') {
        require_once dirname(__DIR__,2).'/tournaments/src/bootstrap.php';
        $search=trim((string)($_GET['search']??''));
        if(strlen($search)>80)api_error(400,'INVALID_SEARCH','Tournament search is too long.');
        $page=max(1,min(500,(int)($_GET['page']??1)));
        $indexer=new \P2K\Tournaments\BrowseIndex();
        $config=p2k_tp_config();
        $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
        $key='widgets-tournament-highlights-v2159-medalist-search-v2|'.$indexer->signature().'|'.hash('sha256',strtolower($search).'|'.$page);
        $entry=$cache->remember($key,120,static function()use($indexer,$search,$page):array{
            $archive=$indexer->index();
            $all=is_array($archive['tournaments']??null)?$archive['tournaments']:[];
            $items=array_values(array_filter($all,'is_array'));
            // Respect stored, admin-maintained tournament status. Only explicitly
            // completed items qualify for the recent winners rotation.
            usort($items,static function(array $a,array $b):int{
                $value=static function(array $t):string{foreach(['finishAt','periodSort','period'] as $field){$v=trim((string)($t[$field]??''));if($v!=='')return $v;}return '';};
                return strnatcasecmp($value($b),$value($a));
            });
            $project=static function(array $t):array{
                return [
                    'name'=>(string)($t['name']??$t['title']??$t['slug']??'Tournament'),
                    'slug'=>(string)($t['slug']??''),
                    'status'=>(string)($t['status']??'unknown'),
                    'period'=>(string)($t['period']??''),
                    'finishAt'=>(string)($t['finishAt']??''),
                    'webUrl'=>(string)($t['webUrl']??$t['url']??''),
                    'podium'=>is_array($t['podium']??null)?$t['podium']:[],
                ];
            };
            $finished=array_values(array_filter($items,static fn(array $t):bool=>strtolower(trim((string)($t['status']??'')))==='finished'));
            $recent=array_map($project,array_slice($finished,0,5));
            // Search by tournament title or a *medalist* (not merely any entrant).
            // BrowseIndex already filters excluded podium accounts, so these
            // results honor the Hall of Fame's fair-play exclusions.
            $tournamentMatches=[];$exactPlayers=[];$partialPlayers=[];
            if($search!=='')foreach($items as $t){
                $title=(string)($t['name']??$t['title']??'');
                $slug=(string)($t['slug']??'');
                if(stripos($title,$search)!==false||stripos($slug,$search)!==false)$tournamentMatches[]=$project($t);
                $foundExact=[];$foundPartial=[];
                foreach(['gold','silver','bronze'] as $metal){
                    foreach(is_array($t['podium'][$metal]??null)?$t['podium'][$metal]:[] as $raw){
                        $username=trim((string)(is_array($raw)?($raw['username']??$raw['name']??''):$raw));
                        if($username==='')continue;
                        $record=['username'=>$username,'medal'=>$metal];
                        if(strcasecmp($username,$search)===0)$foundExact[]=$record;
                        elseif(stripos($username,$search)!==false)$foundPartial[]=$record;
                    }
                }
                if($foundExact!==[]){$row=$project($t);$row['matched_medalists']=$foundExact;$exactPlayers[]=$row;}
                if($foundPartial!==[]){$row=$project($t);$row['matched_medalists']=$foundPartial;$partialPlayers[]=$row;}
            }
            // Exact username takes priority over coincidental tournament title.
            // Otherwise prefer title matches, then partial username matches.
            $kind='tournament';$found=$tournamentMatches;
            if($exactPlayers!==[]){$kind='player';$found=$exactPlayers;}
            elseif($tournamentMatches===[]&&$partialPlayers!==[]){$kind='player';$found=$partialPlayers;}
            $size=10;$total=count($found);$pages=max(1,(int)ceil($total/$size));$current=min($page,$pages);
            return [
                'ok'=>true,'recent'=>$recent,'search_kind'=>$kind,
                'matches'=>array_slice($found,($current-1)*$size,$size),
                'pagination'=>['page'=>$current,'pages'=>$pages,'total'=>$total,'per_page'=>$size],
                'source'=>'Finalized tournament archive with medalist exclusions'
            ];
        },600);
        \P2K\TeamPoints\Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
    }
    api_error(405,'METHOD_NOT_ALLOWED','Method/action not allowed');
} catch(RevisionConflict $e){api_error(409,'REVISION_CONFLICT',$e->getMessage(),['current'=>$e->current]);}
catch(InvalidArgumentException $e){api_error(400,'INVALID_REQUEST',$e->getMessage());}
catch(\P2K\TeamPoints\ApiException $e){api_error($e->httpStatus,$e->errorCode,$e->getMessage(),$e->details);}
catch(Throwable $e){error_log('P2K widgets API: '.$e);api_error(500,'WIDGETS_ERROR','Widget service is temporarily unavailable.');}
