<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Minimal recovery-plane authentication.
 *
 * This class intentionally does not bootstrap the normal dashboard/application stack.
 * It reads already-established server-side sessions and applies a separate Super
 * Admin allowlist. When the OAuth access token is aging out, it may refresh that
 * existing server-side OAuth session using its protected refresh token. A full
 * Chess.com OAuth login remains the fallback when no refreshable identity exists.
 */
final class ReleaseControlAuth
{
    public function __construct(private readonly string $root)
    {
    }

    public function currentUsername(): string
    {
        $username = $this->teamPointsAdminUsername();
        if ($username !== '') return $username;
        return $this->oauthUsername();
    }

    public function isSuperAdmin(string $username): bool
    {
        $needle = strtolower(trim($username));
        return $needle !== '' && in_array($needle, $this->superAdmins(), true);
    }

    /** @return list<string> */
    public function superAdmins(): array
    {
        $raw = trim((string)(getenv('P2K_RELEASE_CONTROL_ADMINS') ?: ''));
        if ($raw !== '') {
            return $this->normalizeUsernames(preg_split('/[\s,;]+/', $raw) ?: []);
        }

        $path = $this->root . '/server/release-control/config/config.local.php';
        if (is_file($path)) {
            $loaded = require $path;
            if (is_array($loaded)) {
                $users = $loaded['super_admin_usernames'] ?? [];
                if (is_array($users)) {
                    $normalized = $this->normalizeUsernames($users);
                    if ($normalized !== []) return $normalized;
                }
            }
        }

        // Safe project default: identity is still proven by a server-side authenticated
        // session. This value is not a credential and can be overridden without editing
        // release files.
        return ['ximoon'];
    }

    public function currentCsrfToken(): string
    {
        if (!empty($_COOKIE['P2KTPSESSID'])) {
            $data = $this->readSession('P2KTPSESSID', null);
            $token = trim((string)($data['p2k_tp_csrf'] ?? ''));
            if ($token !== '') return $token;
        }
        if (!empty($_COOKIE['P2KOAUTH'])) {
            $dir = $this->oauthSessionDirectory();
            if ($dir !== '' && is_dir($dir)) {
                $data = $this->readSession('P2KOAUTH', $dir);
                $token = trim((string)($data['oauth_csrf'] ?? ''));
                if ($token !== '') return $token;
            }
        }
        return '';
    }

    public function allowlistSource(): string
    {
        if (trim((string)(getenv('P2K_RELEASE_CONTROL_ADMINS') ?: '')) !== '') return 'environment';
        if (is_file($this->root . '/server/release-control/config/config.local.php')) return 'protected config.local.php';
        return 'release default';
    }

    public function loginUrl(string $returnTo = '/ReleaseControl.php'): string
    {
        $returnTo = trim($returnTo);
        if ($returnTo === '' || !str_starts_with($returnTo, '/') || str_starts_with($returnTo, '//')) {
            $returnTo = '/ReleaseControl.php';
        }
        if ($returnTo === '/ReleaseControl.php') {
            return '/server/team-points/public/oauth.php?action=login&return=' . rawurlencode('/ReleaseControl.php');
        }
        return '/server/team-points/public/oauth.php?action=login&return=' . rawurlencode($returnTo);
    }

    private function teamPointsAdminUsername(): string
    {
        if (empty($_COOKIE['P2KTPSESSID'])) return '';
        $data = $this->readSession('P2KTPSESSID', null);
        $username = strtolower(trim((string)($data['p2k_tp_admin_username'] ?? '')));
        $expires = (int)($data['p2k_tp_expires'] ?? 0);
        $csrf = trim((string)($data['p2k_tp_csrf'] ?? ''));
        if ($username === '' || $csrf === '' || $expires <= time()) return '';
        return preg_match('/^[a-z0-9_-]{1,80}$/', $username) ? $username : '';
    }

    private function oauthUsername(): string
    {
        if (empty($_COOKIE['P2KOAUTH'])) return '';
        $dir = $this->oauthSessionDirectory();
        if ($dir === '' || !is_dir($dir)) return '';

        $data = $this->readSession('P2KOAUTH', $dir);
        $access = is_array($data['oauth_access'] ?? null) ? $data['oauth_access'] : [];
        $user = is_array($data['oauth_user'] ?? null) ? $data['oauth_user'] : [];
        $claims = is_array($data['oauth_claims'] ?? null) ? $data['oauth_claims'] : [];
        $token = trim((string)($access['access_token'] ?? ''));
        $expires = (int)($access['expires_at'] ?? 0);
        $username = strtolower(trim((string)($user['username'] ?? $claims['preferred_username'] ?? $claims['username'] ?? '')));
        if (!preg_match('/^[a-z0-9_-]{1,80}$/', $username)) return '';

        $now = time();
        $refreshToken = trim((string)($access['refresh_token'] ?? ''));
        $retryAt = (int)($data['oauth_refresh_retry_at'] ?? 0);
        if ($refreshToken !== '' && ($expires <= 0 || $expires <= $now + 300) && $retryAt <= $now) {
            $refreshed = $this->refreshOAuthSession($dir);
            if (is_array($refreshed)) {
                $data = $refreshed;
                $access = is_array($data['oauth_access'] ?? null) ? $data['oauth_access'] : [];
                $user = is_array($data['oauth_user'] ?? null) ? $data['oauth_user'] : [];
                $claims = is_array($data['oauth_claims'] ?? null) ? $data['oauth_claims'] : [];
                $token = trim((string)($access['access_token'] ?? ''));
                $expires = (int)($access['expires_at'] ?? 0);
                $username = strtolower(trim((string)($user['username'] ?? $claims['preferred_username'] ?? $claims['username'] ?? '')));
            }
        }

        if ($token === '' || ($expires > 0 && $expires <= time() + 5) || !preg_match('/^[a-z0-9_-]{1,80}$/', $username)) return '';
        return $username;
    }

    /** Refresh the existing OAuth session in-place without bootstrapping the normal application stack. */
    private function refreshOAuthSession(string $savePath): ?array
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $originalPath = (string)session_save_path();
        @session_save_path($savePath);
        session_name('P2KOAUTH');
        $ok = @session_start();
        if (!$ok) {
            @session_save_path($originalPath);
            return null;
        }

        try {
            $access = is_array($_SESSION['oauth_access'] ?? null) ? $_SESSION['oauth_access'] : [];
            $refreshToken = trim((string)($access['refresh_token'] ?? ''));
            $expires = (int)($access['expires_at'] ?? 0);
            $now = time();
            if ($refreshToken === '' || ($expires > $now + 300 && trim((string)($access['access_token'] ?? '')) !== '')) {
                return is_array($_SESSION) ? $_SESSION : [];
            }

            $config = $this->oauthConfig();
            $clientId = trim((string)($config['client_id'] ?? ''));
            $tokenUrl = trim((string)($config['token_url'] ?? ''));
            if ($clientId === '' || !preg_match('~^https://~i', $tokenUrl)) return is_array($_SESSION) ? $_SESSION : [];

            try {
                $token = $this->postOAuthForm($tokenUrl, [
                    'grant_type'=>'refresh_token',
                    'client_id'=>$clientId,
                    'refresh_token'=>$refreshToken,
                ]);
                $accessToken = trim((string)($token['access_token'] ?? ''));
                $expiresIn = max(1, (int)($token['expires_in'] ?? 0));
                if ($accessToken === '' || $expiresIn <= 1) throw new \RuntimeException('OAuth refresh returned no usable access token.');
                $expiresAt = time() + $expiresIn;
                $_SESSION['oauth_access'] = [
                    'access_token'=>$accessToken,
                    'refresh_token'=>trim((string)($token['refresh_token'] ?? '')) ?: $refreshToken,
                    'id_token'=>trim((string)($token['id_token'] ?? '')) ?: trim((string)($access['id_token'] ?? '')),
                    'token_type'=>trim((string)($token['token_type'] ?? '')) ?: trim((string)($access['token_type'] ?? 'Bearer')),
                    'scope'=>trim((string)($token['scope'] ?? '')) ?: trim((string)($access['scope'] ?? '')),
                    'expires_at'=>$expiresAt,
                ];
                if (is_array($_SESSION['oauth_user'] ?? null)) $_SESSION['oauth_user']['expires_at'] = $expiresAt;
                unset($_SESSION['oauth_refresh_retry_at']);
            } catch (\Throwable) {
                $_SESSION['oauth_refresh_retry_at'] = time() + 60;
            }

            return is_array($_SESSION) ? $_SESSION : [];
        } finally {
            $id = session_id();
            session_write_close();
            @session_save_path($originalPath);
            if ($id !== '') {
                $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
                    || strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '')) === 'https';
                setcookie('P2KOAUTH', $id, [
                    'expires'=>time()+604800,
                    'path'=>'/',
                    'secure'=>$secure,
                    'httponly'=>true,
                    'samesite'=>'Lax',
                ]);
                $_COOKIE['P2KOAUTH'] = $id;
            }
        }
    }

    /** @return array<string,mixed> */
    private function oauthConfig(): array
    {
        $path = $this->root . '/server/team-points/config/oauth.local.php';
        $file = is_file($path) ? require $path : [];
        if (!is_array($file)) $file = [];
        return [
            'client_id'=>trim((string)(getenv('P2K_OAUTH_CLIENT_ID') ?: ($file['client_id'] ?? ''))),
            'token_url'=>trim((string)(getenv('P2K_OAUTH_TOKEN_URL') ?: ($file['token_url'] ?? 'https://oauth.chess.com/token'))),
        ];
    }

    /** @param array<string,string> $fields
     *  @return array<string,mixed>
     */
    private function postOAuthForm(string $url, array $fields): array
    {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) throw new \RuntimeException('Unable to initialize OAuth refresh request.');
            curl_setopt_array($ch, [
                CURLOPT_POST=>true,
                CURLOPT_POSTFIELDS=>$body,
                CURLOPT_RETURNTRANSFER=>true,
                CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>10,
                CURLOPT_TIMEOUT=>20,
                CURLOPT_HTTPHEADER=>[
                    'Accept: application/json',
                    'Content-Type: application/x-www-form-urlencoded',
                    'User-Agent: PromoteToKing-ReleaseControl/2.14.3',
                ],
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $message = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException('OAuth refresh request failed: ' . $message);
            }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
        } else {
            $context = stream_context_create(['http'=>[
                'method'=>'POST',
                'header'=>"Accept: application/json\r\nContent-Type: application/x-www-form-urlencoded\r\nUser-Agent: PromoteToKing-ReleaseControl/2.14.3\r\n",
                'content'=>$body,
                'timeout'=>20,
                'ignore_errors'=>true,
            ]]);
            $response = @file_get_contents($url, false, $context);
            if ($response === false) throw new \RuntimeException('OAuth refresh request failed.');
            $status = 0;
            foreach (($http_response_header ?? []) as $header) {
                if (preg_match('~^HTTP/\\S+\\s+(\\d{3})~', (string)$header, $m)) {
                    $status = (int)$m[1];
                    break;
                }
            }
        }
        $decoded = json_decode((string)$response, true);
        if (!is_array($decoded)) throw new \RuntimeException('OAuth refresh returned invalid JSON.');
        if ($status < 200 || $status >= 300) throw new \RuntimeException('OAuth refresh was rejected.');
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function readSession(string $name, ?string $savePath): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $originalPath = (string)session_save_path();
        if ($savePath !== null) @session_save_path($savePath);
        session_name($name);
        $ok = @session_start(['read_and_close' => true]);
        $data = $ok && is_array($_SESSION ?? null) ? $_SESSION : [];
        $_SESSION = [];
        if ($savePath !== null) @session_save_path($originalPath);
        return $data;
    }

    private function oauthSessionDirectory(): string
    {
        $runtime = '';
        $configPath = $this->root . '/server/team-points/config/config.local.php';
        if (is_file($configPath)) {
            try {
                $config = require $configPath;
                if (is_array($config)) {
                    $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
                    $runtime = rtrim((string)($storage['runtime_dir'] ?? ''), '/\\');
                }
            } catch (\Throwable) {
                $runtime = '';
            }
        }
        if ($runtime === '') $runtime = $this->root . '/data/runtime-v280';
        return $runtime . '/sessions/oauth';
    }

    /** @param array<int|string,mixed> $values
     *  @return list<string>
     */
    private function normalizeUsernames(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            $username = strtolower(trim((string)$value));
            if (!preg_match('/^[a-z0-9_-]{1,80}$/', $username)) continue;
            if (!in_array($username, $out, true)) $out[] = $username;
        }
        return $out;
    }
}
