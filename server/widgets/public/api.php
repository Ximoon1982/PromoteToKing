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
    api_error(405,'METHOD_NOT_ALLOWED','Method/action not allowed');
} catch(RevisionConflict $e){api_error(409,'REVISION_CONFLICT',$e->getMessage(),['current'=>$e->current]);}
catch(InvalidArgumentException $e){api_error(400,'INVALID_REQUEST',$e->getMessage());}
catch(\P2K\TeamPoints\ApiException $e){api_error($e->httpStatus,$e->errorCode,$e->getMessage(),$e->details);}
catch(Throwable $e){error_log('P2K widgets API: '.$e);api_error(500,'WIDGETS_ERROR','Widget service is temporarily unavailable.');}
