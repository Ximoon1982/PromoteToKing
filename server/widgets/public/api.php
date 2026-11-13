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

    if($action==='hall-leaderboard' && $method==='GET') {
        require_once dirname(__DIR__).'/src/HallOfFameLeaderboardService.php';
        $kind=strtolower(trim((string)($_GET['kind']??'daily')));
        $period=strtolower(trim((string)($_GET['period']??'all')));
        if(!in_array($kind,['daily','arena'],true)||!in_array($period,['all','month','quarter'],true)) {
            api_error(400,'INVALID_LEADERBOARD','Unknown Hall of Fame leaderboard or period.');
        }
        $page=max(1,min(100000,(int)($_GET['page']??1)));
        if($kind==='arena')$period='all';
        $app=p2k_tp_config();
        $club=strtolower(trim((string)($app['app']['club_slug']??'promote-to-king')));
        $repository=new \P2K\TeamPoints\Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
        if(!$repository->schemaInstalled())api_error(503,'SCHEMA_NOT_INSTALLED','Team Points data is not available.');
        $generation=$repository->publicReadGenerationToken($club,true,true);
        $cache=new ResponseCache(is_array($app['storage']??null)?$app['storage']:[]);
        $key='hof-leaderboard-v2159-v1|'.$club.'|'.$generation.'|'.gmdate('Y-m-d').'|'.$kind.'|'.$period.'|'.$page;
        $entry=$cache->remember($key,120,static function()use($kind,$period,$page,$repository,$cache,$generation,$club):array{
            if($kind==='arena')return \P2K\Widgets\HallOfFameLeaderboardService::arena(
                PublicReadDatabase::analytics(),$club,$page
            );
            return \P2K\Widgets\HallOfFameLeaderboardService::daily(
                $repository,$cache,$generation,$club,$period,$page
            );
        },600);
        \P2K\TeamPoints\Http::jsonCacheable($entry['payload'],200,60,180,$entry['etag']);
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
        require_once dirname(__DIR__).'/src/TournamentWidgetReadService.php';
        $search=trim((string)($_GET['search']??''));
        if(strlen($search)>80)api_error(400,'INVALID_SEARCH','Tournament search is too long.');
        $page=max(1,(int)($_GET['page']??1));
        $completedPage=max(1,(int)($_GET['completed_page']??1));
        $indexer=new \P2K\Tournaments\BrowseIndex();
        $config=p2k_tp_config();
        $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);
        $key='widgets-tournament-highlights-v2159-all-finished-v3|'.$indexer->signature().'|'.
            hash('sha256',strtolower($search).'|'.$page.'|'.$completedPage);
        $entry=$cache->remember($key,120,static function()use($indexer,$search,$page,$completedPage):array{
            $archive=$indexer->index();
            $tournaments=is_array($archive['tournaments']??null)?$archive['tournaments']:[];
            // BrowseIndex excludes disqualified medalists. Never query raw,
            // unfiltered archive data for public results.
            return \P2K\Widgets\TournamentWidgetReadService::read(
                $tournaments,$search,$page,$completedPage
            );
        },600);
        \P2K\TeamPoints\Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
    }
    api_error(405,'METHOD_NOT_ALLOWED','Method/action not allowed');
} catch(RevisionConflict $e){api_error(409,'REVISION_CONFLICT',$e->getMessage(),['current'=>$e->current]);}
catch(InvalidArgumentException $e){api_error(400,'INVALID_REQUEST',$e->getMessage());}
catch(\P2K\TeamPoints\ApiException $e){api_error($e->httpStatus,$e->errorCode,$e->getMessage(),$e->details);}
catch(Throwable $e){error_log('P2K widgets API: '.$e);api_error(500,'WIDGETS_ERROR','Widget service is temporarily unavailable.');}
