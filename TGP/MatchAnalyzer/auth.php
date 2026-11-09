<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2).'/server/team-points/src/bootstrap.php';
use P2K\TeamPoints\Auth;
use P2K\TeamPoints\OAuthSession;
use P2K\Shared\SharedChessGateway;
function tgp_authorize(): array {
    $session = OAuthSession::sessionInfo();
    if (empty($session['authenticated']) || empty($session['real_oauth'])) return ['ok'=>false,'status'=>401,'code'=>'OAUTH_REQUIRED'];
    $username = strtolower(trim((string)($session['profile']['username'] ?? '')));
    if (!preg_match('/^[a-z0-9_-]{1,80}$/D', $username)) return ['ok'=>false,'status'=>401,'code'=>'OAUTH_IDENTITY_MISSING'];
    try {
        $config=p2k_tp_config();
        $gateway=new SharedChessGateway(null,(array)($config['app']??[]));
        $club=$gateway->json('https://api.chess.com/pub/club/the-golden-phoenix', [
            'consumer'=>'tgp-admin-authentication','cache_ttl_seconds'=>300,
            'allow_stale_on_error'=>false,'max_stale_seconds'=>0
        ]);
        if (!is_array($club)) throw new RuntimeException('Administrator list unavailable.');
    } catch (Throwable $e) {
        error_log('TGP administrator verification: '.$e->getMessage());
        return ['ok'=>false,'status'=>503,'code'=>'ADMIN_VERIFICATION_UNAVAILABLE'];
    }
    if (!Auth::clubProfileHasAdmin($club,$username)) return ['ok'=>false,'status'=>403,'code'=>'TGP_ADMIN_REQUIRED'];
    return ['ok'=>true,'username'=>$username,'csrf'=>(string)($session['csrf']??'')];
}
