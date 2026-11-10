<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/server/team-points/src/bootstrap.php';
// The stable public P2K runtime may predate PreviewIsolation.php, while the
// shared match-tracking helpers already call PreviewIsolation::active().
// Provide the exact P2K implementation locally only when absent; never
// modify the stable public source tree or weaken preview write protection.
if (!class_exists(\P2K\TeamPoints\PreviewIsolation::class)) {
    require_once __DIR__.'/compat/PreviewIsolation.php';
}
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\OAuthSession;
use P2K\Shared\SharedChessGateway;
function team_france_authorize(): array {
    // Share the already-established P2KOAUTH identity, as in the standalone
    // Existing standalone club applications. authenticatedUsername(true) closes the
    // OAuth PHP session AND restores its original session runtime.
    $session = OAuthSession::sessionInfo();
    $username = OAuthSession::authenticatedUsername(true);
    if (empty($session['authenticated']) || empty($session['real_oauth']) ||
        $username === '' ||
        !hash_equals($username, strtolower(trim((string)($session['profile']['username'] ?? ''))))) {
        return ['ok'=>false,'status'=>401,'code'=>'OAUTH_REQUIRED'];
    }
    if (!preg_match('/^[a-z0-9_-]{1,80}$/D', $username)) return ['ok'=>false,'status'=>401,'code'=>'OAUTH_IDENTITY_MISSING'];
    try {
        $config=p2k_tp_config();
        $gateway=new SharedChessGateway(null,(array)($config['app']??[]));
        $club=$gateway->json('https://api.chess.com/pub/club/team-france', [
            'consumer'=>'team-france-admin-authentication','cache_ttl_seconds'=>300,
            'allow_stale_on_error'=>false,'max_stale_seconds'=>0
        ]);
        if (!is_array($club)) throw new RuntimeException('Administrator list unavailable.');
    } catch (Throwable $e) {
        error_log('Team France administrator verification: '.$e->getMessage());
        return ['ok'=>false,'status'=>503,'code'=>'ADMIN_VERIFICATION_UNAVAILABLE'];
    }
    if (!Auth::clubProfileHasAdmin($club,$username)) return ['ok'=>false,'status'=>403,'code'=>'TEAM_FRANCE_ADMIN_REQUIRED'];
    return ['ok'=>true,'username'=>$username,'csrf'=>(string)($session['csrf']??'')];
}
