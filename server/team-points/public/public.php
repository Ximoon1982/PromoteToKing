<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\ChessApi;
use P2K\TeamPoints\Database;
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\LiveRanksService;
use P2K\TeamPoints\Repository;

try {
    Http::method('GET');
    Auth::enforceSameOrigin(false);
    $config = p2k_tp_config();
    $clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
    $pdo = PublicReadDatabase::core();
    $analytics = PublicReadDatabase::analytics();
    $repository = new Repository($pdo, $analytics);
    if (!$repository->schemaInstalled()) {
        throw new ApiException('Team Points data is not available yet.', 503, 'SCHEMA_NOT_INSTALLED');
    }

    $action = strtolower(trim((string)($_GET['action'] ?? 'team')));
    if ($action === 'player') {
        $username = trim((string)($_GET['username'] ?? ''));
        if ($username === '' || strlen($username) > 80) {
            throw new ApiException('username is required.', 400, 'USERNAME_REQUIRED');
        }
        Http::json(['ok' => true, 'club_slug' => $clubSlug, 'player' => $repository->publicPlayerSummary($clubSlug, $username)]);
    }
    if ($action === 'team') {
        Http::json(['ok' => true, 'club_slug' => $clubSlug, 'team' => $repository->publicClubDashboard($clubSlug)]);
    }
    if ($action === 'dashboard-matches') {
        $status = strtolower(trim((string)($_GET['status'] ?? 'registered')));
        $limit = max(1,min(2500,(int)($_GET['limit'] ?? 1500)));
        Http::json(['ok'=>true,'club_slug'=>$clubSlug,'matches'=>$repository->publicDashboardMatches($clubSlug,$status,$limit)]);
    }
    if ($action === 'hall') {
        $rank = strtolower(trim((string)($_GET['rank'] ?? '')));
        $member = trim((string)($_GET['member'] ?? ''));
        $page = max(1,(int)($_GET['page'] ?? 1));
        $pageSize = max(10,min(50,(int)($_GET['page_size'] ?? 25)));
        if (strlen($rank) > 80 || strlen($member) > 80) {
            throw new ApiException('Hall of Fame filters are too long.', 400, 'INVALID_HALL_FILTER');
        }
        Http::json(['ok' => true, 'club_slug' => $clubSlug, 'hall' => $repository->publicHallOfFame($clubSlug, $rank, $member, $page, $pageSize)]);
    }
    if ($action === 'team-insights') {
        $startDate = isset($_GET['start']) && trim((string)$_GET['start']) !== '' ? trim((string)$_GET['start']) : null;
        $endDate = isset($_GET['end']) && trim((string)$_GET['end']) !== '' ? trim((string)$_GET['end']) : null;
        foreach (['start' => $startDate, 'end' => $endDate] as $label => $value) {
            if ($value === null) continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            if (!$date || $date->format('Y-m-d') !== $value) throw new ApiException('Invalid ' . $label . ' date.', 400, 'INVALID_DATE');
        }
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            throw new ApiException('The start date must not be after the end date.', 400, 'INVALID_DATE_RANGE');
        }
        Http::json(['ok' => true, 'source' => 'database', 'data' => $repository->publicTeamInsights($clubSlug, $startDate, $endDate)]);
    }
    if ($action === 'matches') {
        $options = [
            'page' => (int)($_GET['page'] ?? 1),
            'page_size' => (int)($_GET['page_size'] ?? 25),
            'search' => (string)($_GET['search'] ?? ''),
            'filter' => (string)($_GET['filter'] ?? 'all'),
            'sort' => (string)($_GET['sort'] ?? 'start_time'),
            'direction' => (string)($_GET['direction'] ?? 'desc'),
            'include_summary' => (string)($_GET['include_summary'] ?? '1') !== '0',
        ];
        Http::json(['ok' => true, 'source' => 'database'] + $repository->publicMatchInsights($clubSlug, $options));
    }
    if ($action === 'opponents') {
        $options = [
            'page' => (int)($_GET['page'] ?? 1),
            'page_size' => (int)($_GET['page_size'] ?? 25),
            'search' => (string)($_GET['search'] ?? ''),
            'filter' => (string)($_GET['filter'] ?? 'all'),
            'sort' => (string)($_GET['sort'] ?? 'total'),
            'direction' => (string)($_GET['direction'] ?? 'desc'),
            'include_summary' => (string)($_GET['include_summary'] ?? '1') !== '0',
        ];
        Http::json(['ok' => true, 'source' => 'database'] + $repository->publicOpponentStats($clubSlug, $options));
    }
    if ($action === 'live-ranks') {
        $service = new LiveRanksService($analytics, $repository, new ChessApi($repository));
        $rank = strtolower(trim((string)($_GET['rank'] ?? '')));
        $page = max(1,(int)($_GET['page'] ?? 1));
        $pageSize = max(10,min(50,(int)($_GET['page_size'] ?? 25)));
        Http::json(['ok' => true, 'source' => 'database'] + $service->publicPayload($rank,$page,$pageSize));
    }
    if ($action === 'live-team') {
        $service = new LiveRanksService($analytics, $repository, new ChessApi($repository));
        Http::json(['ok' => true, 'source' => 'database', 'team' => $service->publicTeamPayload()]);
    }
    if ($action === 'live-player') {
        $username = trim((string)($_GET['username'] ?? ''));
        if ($username === '' || strlen($username) > 80) {
            throw new ApiException('username is required.', 400, 'USERNAME_REQUIRED');
        }
        $service = new LiveRanksService($analytics, $repository, new ChessApi($repository));
        Http::json(['ok' => true, 'source' => 'database', 'player' => $service->publicPlayerPayload($username)]);
    }
    throw new ApiException('Unknown public Team Points action.', 404, 'UNKNOWN_ACTION');
} catch (ApiException $exception) {
    Http::json(['ok' => false, 'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()]], $exception->httpStatus);
} catch (Throwable $exception) {
    error_log('P2K Team Points public API: ' . $exception);
    Http::json(['ok' => false, 'error' => ['code' => 'SERVER_ERROR', 'message' => 'Team Points data is temporarily unavailable.']], 500);
}
