<?php
declare(strict_types=1);

const P2K_OAUTH_TEST_VERSION = '2.9.7-poc2';
const P2K_OAUTH_SESSION_NAME = 'P2KOAUTHTEST';

function oauth_cfg(): array {
    // Release files contain only defaults/examples. Host-specific OAuth registration
    // values live beside config.local.php so full/incremental releases never overwrite them.
    $legacyPath = __DIR__ . '/config/oauth-test.php';
    $localPath = __DIR__ . '/server/team-points/config/oauth.local.php';
    $legacy = is_file($legacyPath) ? require $legacyPath : [];
    $local = is_file($localPath) ? require $localPath : [];
    if (!is_array($legacy)) $legacy = [];
    if (!is_array($local)) $local = [];
    $file = array_replace($legacy, $local);
    $envConfigured = trim((string)(getenv('P2K_OAUTH_CLIENT_ID') ?: '')) !== '';
    return [
        'name' => trim((string)(getenv('P2K_OAUTH_APP_NAME') ?: ($file['name'] ?? ''))),
        'client_id' => trim((string)(getenv('P2K_OAUTH_CLIENT_ID') ?: ($file['client_id'] ?? ''))),
        'redirect_url' => trim((string)(getenv('P2K_OAUTH_REDIRECT_URL') ?: ($file['redirect_url'] ?? ''))),
        'authorize_url' => trim((string)(getenv('P2K_OAUTH_AUTHORIZE_URL') ?: ($file['authorize_url'] ?? 'https://oauth.chess.com/authorize'))),
        'token_url' => trim((string)(getenv('P2K_OAUTH_TOKEN_URL') ?: ($file['token_url'] ?? 'https://oauth.chess.com/token'))),
        'scope' => trim((string)(getenv('P2K_OAUTH_SCOPE') ?: ($file['scope'] ?? 'openid'))),
        'config_source' => $envConfigured ? 'host environment' : (is_file($localPath) ? 'protected oauth.local.php' : 'release defaults'),
    ];
}

function oauth_start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ||
        (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    session_name(P2K_OAUTH_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function oauth_b64url_encode(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function oauth_b64url_decode(string $raw): string|false {
    $raw = strtr($raw, '-_', '+/');
    $padding = strlen($raw) % 4;
    if ($padding) $raw .= str_repeat('=', 4 - $padding);
    return base64_decode($raw, true);
}

function oauth_decode_jwt_payload(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('Chess.com returned an invalid ID token format.');
    $json = oauth_b64url_decode($parts[1]);
    if ($json === false) throw new RuntimeException('Chess.com returned an unreadable ID token.');
    $payload = json_decode($json, true);
    if (!is_array($payload)) throw new RuntimeException('Chess.com returned an invalid ID token payload.');
    return $payload;
}

function oauth_http_post_form(string $url, array $fields): array {
    if (!preg_match('~^https?://~i', $url)) throw new RuntimeException('OAuth token endpoint is invalid.');
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    $status = 0;
    $response = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Unable to initialize the OAuth token request.');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: PromoteToKing-OAuth-PoC/' . P2K_OAUTH_TEST_VERSION,
            ],
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('OAuth token request failed: ' . $error);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: PromoteToKing-OAuth-PoC/" . P2K_OAUTH_TEST_VERSION . "\r\n",
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents($url, false, $context);
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string)$header, $m)) { $status = (int)$m[1]; break; }
        }
        if ($response === false) throw new RuntimeException('OAuth token request failed.');
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) throw new RuntimeException('Chess.com returned a non-JSON token response (HTTP ' . $status . ').');
    if ($status < 200 || $status >= 300) {
        $message = trim((string)($json['error_description'] ?? $json['error'] ?? ('HTTP ' . $status)));
        throw new RuntimeException('Chess.com rejected the token exchange: ' . $message);
    }
    return $json;
}

function oauth_configured(array $cfg): bool {
    return $cfg['name'] !== '' && $cfg['client_id'] !== '' && $cfg['redirect_url'] !== '';
}

function oauth_test_flag_enabled(): bool {
    return !isset($_GET['oauth']) || (string)$_GET['oauth'] !== '1';
}

function oauth_callback_present(): bool {
    return isset($_GET['code']) || isset($_GET['error']);
}

function oauth_self_path(): string {
    $path = (string)($_SERVER['SCRIPT_NAME'] ?? '/OAuthTest.php');
    return $path !== '' ? $path : '/OAuthTest.php';
}

function oauth_redirect_to_test(string $result = ''): never {
    $query = [];
    if ($result !== '') $query['result'] = $result;
    $suffix = $query ? ('?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)) : '';
    header('Location: ' . oauth_self_path() . $suffix, true, 303);
    exit;
}

function oauth_fail(string $message): never {
    $_SESSION['oauth_flash'] = ['kind' => 'fail', 'message' => $message];
    unset($_SESSION['oauth_pending']);
    oauth_redirect_to_test('fail');
}

function oauth_extract_username(array $token, array $claims): string {
    foreach (['preferred_username', 'username', 'nickname', 'name'] as $key) {
        $value = trim((string)($claims[$key] ?? ''));
        if ($value !== '') return $value;
    }
    foreach (['username', 'preferred_username', 'name'] as $key) {
        $value = trim((string)($token[$key] ?? ''));
        if ($value !== '') return $value;
    }
    $sub = trim((string)($claims['sub'] ?? ''));
    return $sub;
}

function oauth_handle_callback(array $cfg): never {
    oauth_start_session();
    if (isset($_GET['error'])) {
        $detail = trim((string)($_GET['error_description'] ?? $_GET['error']));
        oauth_fail('Chess.com authorization failed' . ($detail !== '' ? ': ' . $detail : '.'));
    }
    if (!oauth_configured($cfg)) oauth_fail('OAuth test configuration is incomplete.');

    $pending = $_SESSION['oauth_pending'] ?? null;
    if (!is_array($pending)) oauth_fail('OAuth state is missing or expired. Start again with Log in.');
    $state = trim((string)($_GET['state'] ?? ''));
    $expectedState = trim((string)($pending['state'] ?? ''));
    if ($state === '' || $expectedState === '' || !hash_equals($expectedState, $state)) {
        oauth_fail('OAuth state validation failed.');
    }
    $createdAt = (int)($pending['created_at'] ?? 0);
    if ($createdAt <= 0 || time() - $createdAt > 600) oauth_fail('OAuth login attempt expired. Start again.');

    $code = trim((string)($_GET['code'] ?? ''));
    $verifier = trim((string)($pending['code_verifier'] ?? ''));
    if ($code === '' || $verifier === '') oauth_fail('Chess.com did not return a usable authorization code.');

    try {
        $token = oauth_http_post_form($cfg['token_url'], [
            'grant_type' => 'authorization_code',
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_url'],
            'code_verifier' => $verifier,
            'code' => $code,
        ]);
        $idToken = trim((string)($token['id_token'] ?? ''));
        if ($idToken === '') throw new RuntimeException('Chess.com token response did not contain an ID token.');
        $claims = oauth_decode_jwt_payload($idToken);

        $aud = $claims['aud'] ?? null;
        $audOk = is_string($aud) ? hash_equals($cfg['client_id'], $aud)
            : (is_array($aud) && in_array($cfg['client_id'], array_map('strval', $aud), true));
        if (!$audOk) throw new RuntimeException('ID token audience does not match this OAuth client.');
        if (isset($claims['exp']) && (int)$claims['exp'] < time() - 30) throw new RuntimeException('ID token is already expired.');
        $nonce = trim((string)($pending['nonce'] ?? ''));
        if ($nonce !== '' && isset($claims['nonce']) && !hash_equals($nonce, (string)$claims['nonce'])) {
            throw new RuntimeException('ID token nonce validation failed.');
        }

        $username = oauth_extract_username($token, $claims);
        if ($username === '') throw new RuntimeException('Authorization succeeded but no Chess.com username/subject was returned.');

        $_SESSION['oauth_user'] = [
            'username' => $username,
            'subject' => trim((string)($claims['sub'] ?? '')),
            'scope' => trim((string)($token['scope'] ?? $cfg['scope'])),
            'token_type' => trim((string)($token['token_type'] ?? 'Bearer')),
            'authenticated_at' => time(),
            'expires_at' => isset($token['expires_in']) ? time() + max(0, (int)$token['expires_in']) : null,
        ];
        unset($_SESSION['oauth_pending']);
        $_SESSION['oauth_flash'] = ['kind' => 'success', 'message' => 'Chess.com OAuth login succeeded.'];
        session_regenerate_id(true);
        oauth_redirect_to_test('success');
    } catch (Throwable $e) {
        oauth_fail($e->getMessage());
    }
}

$cfg = oauth_cfg();

// The registered redirect URI does not need an OAuth mode flag. A callback is
// therefore processed server-side first and then redirected to the gated view.
if (oauth_callback_present()) oauth_handle_callback($cfg);

if (!oauth_test_flag_enabled()) {
    http_response_code(404);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo 'Not found';
    exit;
}

oauth_start_session();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'self'");

if (!isset($_SESSION['oauth_csrf'])) $_SESSION['oauth_csrf'] = oauth_b64url_encode(random_bytes(24));
$csrf = (string)$_SESSION['oauth_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string)($_POST['csrf'] ?? '');
    if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
        $_SESSION['oauth_flash'] = ['kind' => 'fail', 'message' => 'Request validation failed. Refresh and try again.'];
        oauth_redirect_to_test('fail');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'logout') {
        unset($_SESSION['oauth_user'], $_SESSION['oauth_pending']);
        $_SESSION['oauth_flash'] = ['kind' => 'success', 'message' => 'OAuth test session logged out locally.'];
        session_regenerate_id(true);
        oauth_redirect_to_test('logout');
    }
    if ($action === 'login') {
        if (!oauth_configured($cfg)) {
            $_SESSION['oauth_flash'] = ['kind' => 'fail', 'message' => 'Install name, client ID and redirect URL in server/team-points/config/oauth.local.php before testing login.'];
            oauth_redirect_to_test('fail');
        }
        $verifier = oauth_b64url_encode(random_bytes(64));
        $state = oauth_b64url_encode(random_bytes(32));
        $nonce = oauth_b64url_encode(random_bytes(32));
        $_SESSION['oauth_pending'] = [
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => $verifier,
            'created_at' => time(),
        ];
        $challenge = oauth_b64url_encode(hash('sha256', $verifier, true));
        $query = [
            'client_id' => $cfg['client_id'],
            'redirect_uri' => $cfg['redirect_url'],
            'response_type' => 'code',
            'scope' => $cfg['scope'] !== '' ? $cfg['scope'] : 'openid',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        $separator = str_contains($cfg['authorize_url'], '?') ? '&' : '?';
        header('Location: ' . $cfg['authorize_url'] . $separator . http_build_query($query, '', '&', PHP_QUERY_RFC3986), true, 303);
        exit;
    }
}

$flash = $_SESSION['oauth_flash'] ?? null;
unset($_SESSION['oauth_flash']);
$user = is_array($_SESSION['oauth_user'] ?? null) ? $_SESSION['oauth_user'] : null;
$configured = oauth_configured($cfg);

function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title>Promote to King · Chess.com OAuth test</title>
<style>
:root{color-scheme:dark;--bg:#100f0e;--panel:#1d1a17;--panel2:#27221d;--text:#f3e8d7;--muted:#aaa198;--gold:#f6b73c;--good:#8dcc84;--bad:#ef8e82;--line:#ffffff18}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at top,#241d15 0,#100f0e 42%);color:var(--text);font:16px/1.5 Arial,sans-serif;min-height:100vh}.wrap{max-width:760px;margin:0 auto;padding:28px 18px 54px}.brand{display:flex;align-items:center;gap:14px;margin-bottom:18px}.brand img{width:58px;height:58px;border-radius:14px;object-fit:cover}.brand h1{font-size:24px;margin:0;color:#ffd078}.brand p{margin:2px 0 0;color:var(--muted)}.card{border:1px solid var(--line);border-radius:16px;background:linear-gradient(145deg,var(--panel2),var(--panel));padding:20px;margin-top:14px}.status{padding:13px 14px;border-radius:10px;border:1px solid var(--line);margin:0 0 16px}.status.success{border-color:#8dcc8466;background:#8dcc8412;color:#c8f0c3}.status.fail{border-color:#ef8e8266;background:#ef8e8212;color:#ffd0ca}.user{font-size:24px;color:#fff;margin:4px 0}.label{font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted)}.buttons{display:flex;gap:10px;flex-wrap:wrap;margin-top:18px}button{appearance:none;border:1px solid #ffffff22;border-radius:10px;padding:11px 18px;background:#2b251f;color:var(--text);font-weight:700;cursor:pointer}button.primary{background:var(--gold);color:#18120a;border-color:var(--gold)}button:disabled{opacity:.45;cursor:not-allowed}.meta{display:grid;grid-template-columns:150px 1fr;gap:7px 12px;margin-top:16px;font-size:14px}.meta dt{color:var(--muted)}.meta dd{margin:0;overflow-wrap:anywhere}.note{font-size:13px;color:var(--muted);margin-top:14px}code{color:#ffd078}.badge{display:inline-block;padding:3px 8px;border:1px solid #ffffff20;border-radius:999px;color:#ffd078;font-size:12px}@media(max-width:560px){.meta{grid-template-columns:1fr}.meta dd{margin-bottom:8px}}
</style>
</head>
<body>
<main class="wrap">
  <div class="brand">
    <img src="assets/images/p2k-logo.jpg" alt="Promote to King logo">
    <div><h1>Chess.com OAuth proof of concept</h1><p>Real OAuth test · default mode; use <code>?oauth=1</code> only for simulated-mode testing</p></div>
  </div>

  <section class="card">
    <?php if (is_array($flash)): ?>
      <div class="status <?= h((string)($flash['kind'] ?? 'fail')) ?>" role="status"><?= h((string)($flash['message'] ?? '')) ?></div>
    <?php endif; ?>

    <?php if ($user): ?>
      <div class="label">Authenticated Chess.com user</div>
      <div class="user">@<?= h((string)$user['username']) ?></div>
      <span class="badge">OAuth success</span>
      <dl class="meta">
        <dt>Application</dt><dd><?= h($cfg['name'] ?: '—') ?></dd>
        <dt>Scope</dt><dd><?= h((string)($user['scope'] ?? 'openid')) ?></dd>
        <dt>Authenticated</dt><dd><?= h(gmdate('Y-m-d H:i:s', (int)($user['authenticated_at'] ?? time()))) ?> UTC</dd>
      </dl>
      <form method="post" class="buttons">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button type="submit" name="action" value="logout">Log out</button>
      </form>
    <?php else: ?>
      <div class="label">Status</div>
      <div class="user"><?= $configured ? 'Not logged in' : 'Configuration required' ?></div>
      <?php if (!$configured): ?>
        <p>Install the Chess.com-issued <strong>name</strong>, <strong>client ID</strong>, and exact approved <strong>redirect URL</strong> in protected <code>server/team-points/config/oauth.local.php</code>.</p>
      <?php else: ?>
        <p>Press Log in to start a Chess.com authorization-code + PKCE test. The callback exchanges the one-time code server-side and displays the returned Chess.com identity.</p>
      <?php endif; ?>
      <form method="post" class="buttons">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <button class="primary" type="submit" name="action" value="login" <?= $configured ? '' : 'disabled' ?>>Log in with Chess.com</button>
      </form>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="label">POC configuration</div>
    <dl class="meta">
      <dt>Name</dt><dd><?= h($cfg['name'] ?: 'not configured') ?></dd>
      <dt>Client ID</dt><dd><?= h($cfg['client_id'] ?: 'not configured') ?></dd>
      <dt>Redirect URL</dt><dd><?= h($cfg['redirect_url'] ?: 'not configured') ?></dd>
      <dt>Authorization</dt><dd><?= h($cfg['authorize_url']) ?></dd>
      <dt>Token endpoint</dt><dd><?= h($cfg['token_url']) ?></dd>
      <dt>Configuration source</dt><dd><?= h((string)($cfg['config_source'] ?? '—')) ?></dd>
      <dt>POC version</dt><dd><?= h(P2K_OAUTH_TEST_VERSION) ?></dd>
    </dl>
    <p class="note">This test deliberately does not expose access or ID tokens to JavaScript or render them in the page. Log out ends the local P2K proof-of-concept session; it does not sign the browser out of Chess.com itself.</p>
  </section>
</main>
</body>
</html>
