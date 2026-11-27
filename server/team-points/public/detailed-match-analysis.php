<?php
declare(strict_types=1);

use P2K\TeamPoints\Auth;
use P2K\TeamPoints\OAuthSession;
use P2K\TeamPoints\PreviewIsolation;
use P2K\Shared\SharedChessGateway;

header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://api.chess.com; script-src 'self' https://api.chess.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://images.chesscomfiles.com https://www.chess.com; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
$accessOnly = ($_GET['access'] ?? '') === '1';
$status = 401;
$username = '';
try {
    require_once __DIR__ . '/../src/bootstrap.php';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        $status = 405;
        header('Allow: GET');
    } elseif (PreviewIsolation::active()) {
        // Only the recovery router can establish the isolated preview identity.
        $status = PreviewIsolation::adminReadAuthorized() ? 200 : 403;
        if ($status === 200) $username = PreviewIsolation::username();
    } elseif (!empty($_COOKIE[OAuthSession::SESSION_NAME])) {
        $username = OAuthSession::authenticatedUsername(true);
        if ($username !== '') {
            $config = p2k_tp_config();
            $club = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
            // Same server-verified club role as session.php, without DB/schema bootstrap.
            $gateway = new SharedChessGateway(null, $config['app'] ?? []);
            $profile = $gateway->json('https://api.chess.com/pub/club/' . rawurlencode($club), [
                'consumer' => 'detailed-analysis-admin-access',
                'cache_ttl_seconds' => 300,
                'allow_stale_on_error' => false,
            ]);
            $status = !is_array($profile) ? 503 : (Auth::clubProfileHasAdmin($profile, $username) ? 200 : 403);
        }
    }
} catch (Throwable $error) {
    error_log('P2K detailed analysis authorization: ' . $error->getMessage());
    $status = 503;
}
http_response_code($status);
if ($accessOnly) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $status === 200, 'allowed' => $status === 200, 'username' => $status === 200 ? $username : '']);
    exit;
}
header('Content-Type: text/html; charset=utf-8');
if ($status !== 200) {
    $message = $status === 503 ? 'Administrator verification is temporarily unavailable. Please retry.' : 'Administrator access required. Sign in with an authorized Promote to King Chess.com account.';
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Detailed match analysis</title><body style="background:#171513;color:#eee;font-family:Arial,sans-serif;padding:24px"><h1>Detailed match analysis</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p><a style="color:#ffd078" href="/FindMatch.htm">Return to Match Assistant</a></p></body></html>';
    exit;
}
?>
<!DOCTYPE html>

<html lang="en">
<head>
<base href="/"/>

<meta charset="utf-8"/>
<?php if (!PreviewIsolation::active()): ?>
<meta name="p2k-oauth-required" content="1"/>
<script defer src="assets/js/shared/real-oauth.js?v=2.11.5-bf6a828490f57"></script>
<?php endif; ?>
<meta content="width=device-width, initial-scale=1" name="viewport"/>
<meta content="dark" name="color-scheme"/>
<meta content="default-src 'self'; connect-src 'self' https://api.chess.com; script-src 'self' https://api.chess.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://images.chesscomfiles.com https://www.chess.com; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'" http-equiv="Content-Security-Policy"/><title>Promote to King Detailed Match Analysis</title>
<link href="assets/images/p2k-logo.jpg" rel="icon" type="image/jpeg"/>
<!-- Single-match embedded detail view based on Upcoming Matches Analyzer v42. Loads one match only after its parent modal is opened. -->
<link href="assets/css/upcoming-matches-analyzer.css?v=2.11.5-bf6a828490f57" rel="stylesheet"/><link href="assets/css/analyze-match-modal.css?v=2.11.5-bf6a828490f57" rel="stylesheet"/><link href="assets/css/info-popover.css?v=2.11.5-bf6a828490f57" rel="stylesheet"/><link href="assets/css/match-history.css?v=2.11.5-bf6a828490f57" rel="stylesheet"/><link href="assets/css/analysis-failure-ui.css?v=2.11.5-bf6a828490f57" rel="stylesheet"/>
<script src="assets/js/pages/analyze-match-modal.js?v=p2k-2.16.2-admin-1"></script>
<script src="config/site-branding.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/site-config.js?v=p2k-2.15.10-traffic-activity-1"></script>
<script src="assets/js/shared/svg-touch-title.js?v=2.11.5-bf6a828490f57"></script></head>
<body>
<div class="p2k-analyzer" id="p2kUpcomingAnalyzer">
<div class="p2k-header">
<h2>Promote to King Upcoming Matches Analyzer</h2>
<img alt="Promote to King club logo" src="assets/images/p2k-logo.jpg" title="Promote to King"/>
</div>
<button class="p2k-main-button" id="p2kAnalyzeButton" type="button">
    Analyze upcoming matches
  </button>
<div class="p2k-filter-title">Filters</div>
<fieldset class="p2k-filter-group">
<legend>Registration status</legend>
<div class="p2k-choices">
<label>
<input checked="" name="p2kLockFilter" type="radio" value="open"/>
<span>Open matches only</span>
</label>
<label>
<input name="p2kLockFilter" type="radio" value="all"/>
<span>Include locked matches</span>
</label>
</div>
</fieldset>
<fieldset class="p2k-filter-group">
<legend>Sorting</legend>
<div class="p2k-choices">
<label>
<input checked="" name="p2kSortMode" type="radio" value="date"/>
<span>Sort by date</span>
</label>
<label>
<input name="p2kSortMode" type="radio" value="priority"/>
<span>Sort by priority</span>
</label>
</div>
</fieldset>
<fieldset class="p2k-filter-group">
<legend>Lock readiness</legend>
<div class="p2k-choices">
<label>
<input checked="" name="p2kLockableFilter" type="radio" value="all"/>
<span>Show all</span>
</label>
<label>
<input name="p2kLockableFilter" type="radio" value="lockable"/>
<span>Lockable matches</span>
</label>
</div>
</fieldset>
<div aria-label="Match color legend" class="p2k-legend">
<span><i class="p2k-dot p2k-dot-red"></i>Priority</span>
<span><i class="p2k-dot p2k-dot-orange"></i>Open</span>
<span><i class="p2k-dot p2k-dot-green"></i>Locked</span>
</div>
<div aria-live="polite" class="p2k-status" id="p2kStatus">
<div class="p2k-status-row">
<div class="p2k-status-text" id="p2kStatusText"></div>
<div class="p2k-processing-actions" id="p2kProcessingActions">
<button class="p2k-cancel-button" id="p2kCancelButton" title="Stop processing the remaining matches and display the results loaded so far." type="button">Cancel</button>
</div>
</div>
<div aria-hidden="true" class="p2k-progress-track" id="p2kProgressTrack">
<div class="p2k-progress-bar" id="p2kProgressBar"></div>
</div>
</div>
<section aria-live="polite" class="p2k-recruitment-recap" hidden="" id="p2kRecruitmentRecap"></section>
<div aria-label="Search analyzed matches" class="p2k-match-search-toolbar" hidden="" id="p2kSearchToolbar" role="search">
<div class="p2k-match-search-group">
<label class="p2k-search-label" for="p2kMatchSearch">Match</label>
<input autocomplete="off" class="p2k-match-search" id="p2kMatchSearch" placeholder="Search match name or opponent..." spellcheck="false" type="search"/>
<button class="p2k-search-clear" hidden aria-hidden="true" tabindex="-1" id="p2kClearSearch" type="button">Clear</button>
</div>
</div>
<div class="p2k-results" id="p2kResults"></div>
<div aria-hidden="true" class="p2k-chart-modal" hidden="" id="p2kChartModal">
<div aria-labelledby="p2kChartModalTitle" aria-modal="true" class="p2k-chart-modal-dialog" role="dialog">
<div class="p2k-chart-modal-header">
<div class="p2k-chart-modal-title" id="p2kChartModalTitle"></div>
<button class="p2k-chart-modal-close" id="p2kChartModalClose" type="button">Close</button>
</div>
<div id="p2kChartModalBody"></div>
</div>
</div>
</div>
<script src="assets/js/site-config.js?v=p2k-2.15.10-traffic-activity-1"></script><script src="assets/js/shared/tab-activity.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/info-popover.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/api-cache.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/match-priority.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/api-request-semantics.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/api-oauth-context.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/api-transport.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/api-request-coordinator.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/api-client.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/match-history-ui.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/analysis-failure-ui.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/upcoming-analysis-core.js?v=p2k-2.16.2-detailed-analysis-1"></script><script src="assets/js/shared/control-hints.js?v=2.11.5-bf6a828490f57"></script><script src="assets/js/shared/embedded-page.js?v=2.11.5-bf6a828490f57"></script></body>
</html>
