<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ClubIntelligenceService;
try {
    Http::method('GET');
    $config=p2k_tp_config();
    $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $rules=strtolower(trim((string)($_GET['rules']??'chess')));
    if(!in_array($rules,['chess','standard','chess960','960'],true)) throw new ApiException('rules must be chess or chess960.',400,'INVALID_RULES');
    $repo=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repo->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    if(!$repo->schemaInstalled()) throw new ApiException('The Team Points database is not installed or upgraded.',503,'SCHEMA_NOT_INSTALLED');
    $pool=$repo->recruitmentRatingPool($club,$rules);$intel=new ClubIntelligenceService(PublicReadDatabase::core(),PublicReadDatabase::analytics(),$club);$byUser=[];foreach($intel->memberActivity()['rows'] as $row)$byUser[(string)$row['username_key']]=$row;foreach($pool['rows'] as &$row){$extra=$byUser[(string)$row['username_key']]??[];$row['availability_score']=(int)($extra['availability_score']??0);$row['activity_class']=(string)($extra['activity_class']??'unknown');$row['current_load']=(int)($extra['current_load']??0);$row['in_progress_boards']=(int)($extra['in_progress_boards']??0);$row['registered_boards']=(int)($extra['registered_boards']??0);$row['last_activity']=$extra['last_activity']??null;}unset($row);Http::json(['ok'=>true,'club_slug'=>$club,'server_utc'=>gmdate(DATE_ATOM)] + $pool);
} catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch(Throwable $e){
    error_log('P2K recruitment rating pool: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'The stored recruitment rating pool is temporarily unavailable.']],500);
}
