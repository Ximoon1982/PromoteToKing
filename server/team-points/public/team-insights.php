<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\Repository;
use P2K\TeamPoints\ResponseCache;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') throw new ApiException('GET required.', 405, 'METHOD_NOT_ALLOWED');
    $config = p2k_tp_config();
    $startDate = isset($_GET['start']) && trim((string)$_GET['start']) !== '' ? trim((string)$_GET['start']) : null;
    $endDate = isset($_GET['end']) && trim((string)$_GET['end']) !== '' ? trim((string)$_GET['end']) : null;
    foreach (['start' => $startDate, 'end' => $endDate] as $label => $value) {
        if ($value !== null) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $value) throw new ApiException('Invalid ' . $label . ' date.', 400, 'INVALID_DATE');
        }
    }
    if ($startDate !== null && $endDate !== null && $startDate > $endDate) throw new ApiException('The start date must not be after the end date.', 400, 'INVALID_DATE_RANGE');
    $section=strtolower(trim((string)($_GET['section']??'all')));
    if(!in_array($section,['all','summary','progression','mid','deep'],true))$section='all';
    $repository = new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
    if (!$repository->schemaInstalled()) throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
    $club=(string)$config['app']['club_slug'];$generation=$repository->publicReadGenerationToken($club,false,false);
    $cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);$key='team-insights|'.$club.'|'.$generation.'|'.$section.'|'.($startDate??'').'|'.($endDate??'');
    $entry=$cache->remember($key,120,static fn()=>['ok'=>true,'meta'=>$repository->publicReadMeta($club),'data'=>$repository->publicTeamInsights($club,$startDate,$endDate,$section)],600);
    Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
} catch (ApiException $exception) {
    Http::json(['ok' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->httpStatus);
} catch (Throwable $exception) {
    error_log('P2K Team Insights: ' . $exception);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => $exception->getMessage()]], 500);
}
