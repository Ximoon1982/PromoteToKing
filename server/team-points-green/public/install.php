<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
use P2K\Green\GreenConfig;
use P2K\Green\GreenRepository;
use P2K\Green\GreenIdentityMigration;

try {
    GreenConfig::authorizeAdmin();
    $action=strtolower(trim((string)($_GET['action']??'status')));
    if($action==='status'){
        $cfg=GreenConfig::load(false);$configured=(bool)$cfg;$schema=false;$state=null;$error=null;
        $identity=null;$sizes=null;
        if($configured){try{$r=GreenRepository::open();$state=$r->state();$schema=true;$sizes=$r->databaseSizes();try{$identity=(new GreenIdentityMigration($r))->status();}catch(Throwable $ignored){} }catch(Throwable $e){$error=$e->getMessage();}}
        $version=trim((string)@file_get_contents(GreenConfig::siteRoot().'/VERSION'));
        GreenConfig::json(['ok'=>true,'release'=>'2.10.4.3','baseline_expected'=>'2.9.22.10','site_version'=>$version,'configured'=>$configured,'config'=>$configured?['core'=>GreenConfig::redact((array)($cfg['databases']['core']??[])),'analytics'=>GreenConfig::redact((array)($cfg['databases']['analytics']??[]))]:null,'schema_initialized'=>$schema,'state'=>$state,'identity'=>$identity,'database_sizes'=>$sizes,'error'=>$error,'public_source'=>'blue_hardwired']);
    }
    if($action==='cron-info'){
        $cfg=GreenConfig::load();$token=(string)($cfg['app']['cron_token']??'');$scheme=((!empty($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off')?'https':'http');$host=(string)($_SERVER['HTTP_HOST']??'www.promotetoking.org');
        $url=$scheme.'://'.$host.'/server/team-points-green/public/cron.php?token='.rawurlencode($token);
        GreenConfig::json(['ok'=>true,'url'=>$url,'strategy'=>'GSCF','recommended'=>'2,32 * * * * /usr/bin/curl --fail --silent --show-error --max-time 30 '.escapeshellarg($url),'note'=>'GSCF: one Green-only feeder at minute 2 and 32 each hour. Worker soft target 50 s, hard budget 55 s; the Green worker lease prevents overlap. Existing Blue and unrelated CRON entries remain untouched.']);
    }
    if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))!=='POST')GreenConfig::json(['ok'=>false,'error'=>'Use POST for installer actions.'],405);
    if($action==='test')GreenConfig::json(['ok'=>true,'databases'=>GreenConfig::test(GreenConfig::body()),'note'=>'IONOS control-panel quota is authoritative; information_schema size is only an estimate.']);
    if($action==='save')GreenConfig::json(['ok'=>true,'saved'=>GreenConfig::save(GreenConfig::body())]);
    if($action==='initialize'){$repo=GreenRepository::open();$repo->initializeSchemas();GreenConfig::json(['ok'=>true,'state'=>$repo->state()]);}
    if($action==='import-findings'){
        $text='';
        if(isset($_FILES['findings'])&&is_array($_FILES['findings'])&&is_uploaded_file((string)($_FILES['findings']['tmp_name']??'')))$text=(string)file_get_contents((string)$_FILES['findings']['tmp_name']);
        else{$body=GreenConfig::body();$text=(string)($body['findings_text']??'');}
        if(trim($text)==='')throw new RuntimeException('No findings.txt content was supplied.');
        $repo=GreenRepository::open();GreenConfig::json(['ok'=>true,'import'=>$repo->importFindings($text),'state'=>$repo->state()]);
    }
    if($action==='identity-discover'){$repo=GreenRepository::open();$mi=new GreenIdentityMigration($repo);GreenConfig::json(['ok'=>true,'discovery'=>$mi->discover(),'status'=>$mi->status()]);}
    if($action==='identity-import-blue'){$repo=GreenRepository::open();$mi=new GreenIdentityMigration($repo);GreenConfig::json(['ok'=>true,'import'=>$mi->importBlue(),'status'=>$mi->status()]);}
    if($action==='identity-import-json'){
        $payload=[];
        if(isset($_FILES['identity'])&&is_array($_FILES['identity'])&&is_uploaded_file((string)($_FILES['identity']['tmp_name']??''))){$raw=(string)file_get_contents((string)$_FILES['identity']['tmp_name']);$payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}else{$payload=GreenConfig::body();}
        if(!is_array($payload))throw new RuntimeException('Invalid identity JSON.');$repo=GreenRepository::open();$mi=new GreenIdentityMigration($repo);GreenConfig::json(['ok'=>true,'import'=>$mi->importJson($payload),'status'=>$mi->status()]);
    }
    GreenConfig::json(['ok'=>false,'error'=>'Unknown installer action.'],404);
}catch(Throwable $e){GreenConfig::json(['ok'=>false,'error'=>$e->getMessage()],500);}
