<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
try {
    // Lightweight Core rating query only. PublicReadDatabase::core() still validates
    // the Green Core/Analytics pair; this path does not run population-wide Analytics.
    Http::method('GET');
    $config=p2k_tp_config();
    $club=strtolower((string)($config['app']['club_slug']??'promote-to-king'));
    $rules=strtolower(trim((string)($_GET['rules']??'chess')));
    if(!in_array($rules,['chess','standard','chess960','960'],true)) throw new ApiException('rules must be chess or chess960.',400,'INVALID_RULES');
    $repo=new Repository(PublicReadDatabase::core());
    if (!$repo->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    $pool=$repo->recruitmentRatingPool($club,$rules);
    Http::json(['ok'=>true,'club_slug'=>$club,'server_utc'=>gmdate(DATE_ATOM)] + $pool);
} catch(ApiException $e){
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch(Throwable $e){
    error_log('P2K recruitment rating pool: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'The stored recruitment rating pool is temporarily unavailable.']],500);
}
