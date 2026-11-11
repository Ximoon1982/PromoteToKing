<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';

use P2K\TeamPoints\ApiException;
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\Http;
use P2K\TeamPoints\McaRenameReviewService;
use P2K\TeamPoints\PreviewIsolation;
use P2K\TeamPoints\PublicReadDatabase;

try {
    Auth::requireAdmin();
    $core = PublicReadDatabase::core();
    $analytics = PublicReadDatabase::analytics();
    $club = strtolower((string)(p2k_tp_config()['app']['club_slug'] ?? 'promote-to-king'));
    $service = new McaRenameReviewService($core, $analytics, $club);
    $action = strtolower(trim((string)($_GET['action'] ?? 'report')));
    if ($action === 'report') {
        Http::method('GET');
        Http::json($service->report());
    }
    if ($action === 'export') {
        Http::method('GET');
        $service->exportUnresolvedCsv();
        exit;
    }
    if (in_array($action, ['propose', 'review'], true)) {
        Http::method('POST');
        if (PreviewIsolation::active()) {
            throw new ApiException('MIAC review is read-only in release preview.', 403, 'PREVIEW_READ_ONLY');
        }
        $body = Http::body();
        $old = (string)($body['old_username'] ?? '');
        $new = (string)($body['new_username'] ?? '');
        if ($action === 'propose') {
            $change = $service->propose($old, $new);
        } else {
            $decision = strtolower((string)($body['decision'] ?? ''));
            if (!in_array($decision, ['confirmed', 'rejected'], true)) {
                throw new ApiException('Choose Confirm or Reject.', 400, 'INVALID_MIAC_DECISION');
            }
            $change = $service->review($old, $new, $decision);
        }
        Http::json(['ok'=>true,'change'=>$change,'report'=>$service->report()]);
    }
    throw new ApiException('Unknown MCA alias review action.', 404, 'UNKNOWN_ACTION');
} catch (ApiException $e) {
    Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]], $e->httpStatus);
} catch (Throwable $e) {
    error_log('P2K MCA alias review: '.$e);
    Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'MCA alias review is temporarily unavailable.']], 500);
}
