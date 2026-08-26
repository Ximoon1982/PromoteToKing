<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
try {
    Http::method('GET');
    $config = p2k_tp_config();
    $repository = new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    if (!$repository->schemaInstalled()) throw new ApiException('The Team Points database is not installed or upgraded.',503,'SCHEMA_NOT_INSTALLED');
    $club = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $hours = max(1, min(168, (int)($_GET['hours'] ?? 24)));
    Http::json(['ok'=>true,'hours'=>$hours,'matches'=>$repository->publicRecentMatches($club,$hours),'meta'=>['generated_at'=>gmdate('c')]]);
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K recent matches: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Recent match data is temporarily unavailable.']],500);
}
