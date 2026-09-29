<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

/**
 * Minimal recovery-plane authentication.
 *
 * This class intentionally does not bootstrap the normal dashboard/application stack.
 * It reads already-established server-side sessions in read-only mode and applies a
 * separate Super Admin allowlist. Normal Chess.com OAuth login remains the fallback
 * path when neither server session is available.
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

    public function allowlistSource(): string
    {
        if (trim((string)(getenv('P2K_RELEASE_CONTROL_ADMINS') ?: '')) !== '') return 'environment';
        if (is_file($this->root . '/server/release-control/config/config.local.php')) return 'protected config.local.php';
        return 'release default';
    }

    public function loginUrl(): string
    {
        return '/server/team-points/public/oauth.php?action=login&return=' . rawurlencode('/ReleaseControl.php');
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
        if ($token === '' || ($expires > 0 && $expires <= time() + 5) || $username === '') return '';
        return preg_match('/^[a-z0-9_-]{1,80}$/', $username) ? $username : '';
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
