<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

final class Auth
{
    private const SESSION_USERNAME = 'p2k_tp_admin_username';
    private const SESSION_CSRF = 'p2k_tp_csrf';
    private const SESSION_EXPIRES = 'p2k_tp_expires';

    public static function requireAdmin(): void
    {
        self::enforceSameOrigin();
        if (self::validAdminSession()) {
            $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $provided = self::header('X-P2K-CSRF');
                $expected = (string)($_SESSION[self::SESSION_CSRF] ?? '');
                if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
                    throw new ApiException('The secured Team Points session could not validate this request.', 403, 'CSRF_VALIDATION_FAILED');
                }
            }
            self::refreshAdminSession();
            return;
        }

        // Backward-compatible server-to-server and legacy browser access.
        $expected = (string)(\p2k_tp_config()['app']['admin_token'] ?? '');
        if ($expected === '' || str_starts_with($expected, 'CHANGE_')) {
            throw new ApiException('A secured administrator session is required.', 401, 'ADMIN_SESSION_REQUIRED');
        }
        $provided = self::header('X-P2K-Admin-Token');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new ApiException('Administrator authorization failed.', 403, 'ADMIN_AUTH_FAILED');
        }
    }

    public static function requireCron(string $provided): void
    {
        $expected = (string)(\p2k_tp_config()['app']['cron_token'] ?? '');
        if ($expected === '' || str_starts_with($expected, 'CHANGE_')) {
            throw new ApiException('The cron token is not configured.', 503, 'CRON_TOKEN_NOT_CONFIGURED');
        }
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new ApiException('Cron authorization failed.', 403, 'CRON_AUTH_FAILED');
        }
    }

    public static function sessionLifetime(): int
    {
        return max(300, min(86400, (int)(\p2k_tp_config()['app']['admin_session_lifetime_seconds'] ?? 1800)));
    }

    public static function createAdminSession(string $username): string
    {
        self::startSession();
        session_regenerate_id(true);
        $csrf = bin2hex(random_bytes(24));
        $_SESSION[self::SESSION_USERNAME] = strtolower(trim($username));
        $_SESSION[self::SESSION_CSRF] = $csrf;
        $_SESSION[self::SESSION_EXPIRES] = time() + self::sessionLifetime();
        return $csrf;
    }

    public static function clubProfileHasAdmin(array $profile, string $username): bool
    {
        $needle = strtolower(trim($username));
        if ($needle === '') return false;
        $values = [];
        foreach (['admin', 'admins', 'super_admin', 'super_admins'] as $field) {
            $value = $profile[$field] ?? null;
            if (is_array($value)) $values = array_merge($values, $value);
            elseif ($value !== null && $value !== '') $values[] = $value;
        }
        foreach ($values as $entry) {
            if (self::adminEntryUsername($entry) === $needle) return true;
        }
        return false;
    }

    public static function enforceSameOrigin(bool $rejectMissingOrigin = true): void
    {
        $allowed = trim((string)(\p2k_tp_config()['app']['allowed_origin'] ?? ''));
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($allowed !== '') {
            if ($origin !== '' && !hash_equals(rtrim($allowed, '/'), rtrim($origin, '/'))) {
                throw new ApiException('Request origin is not allowed.', 403, 'ORIGIN_NOT_ALLOWED');
            }
            if ($origin === '' && $referer !== '' && !str_starts_with($referer, rtrim($allowed, '/') . '/')) {
                throw new ApiException('Request origin is not allowed.', 403, 'ORIGIN_NOT_ALLOWED');
            }
            if ($rejectMissingOrigin && $origin === '' && $referer === '') {
                throw new ApiException('A same-origin browser request is required.', 403, 'ORIGIN_REQUIRED');
            }
            return;
        }

        if ($host !== '') {
            foreach ([$origin, $referer] as $candidate) {
                if ($candidate === '') continue;
                $candidateHost = (string)(parse_url($candidate, PHP_URL_HOST) ?: '');
                if ($candidateHost !== '' && !hash_equals(strtolower(preg_replace('/:\d+$/', '', $host) ?? $host), strtolower($candidateHost))) {
                    throw new ApiException('Request origin is not allowed.', 403, 'ORIGIN_NOT_ALLOWED');
                }
            }
        }
        if ($rejectMissingOrigin && $origin === '' && $referer === '') {
            throw new ApiException('A same-origin browser request is required.', 403, 'ORIGIN_REQUIRED');
        }
    }

    private static function validAdminSession(): bool
    {
        self::startSession();
        $username = (string)($_SESSION[self::SESSION_USERNAME] ?? '');
        $expires = (int)($_SESSION[self::SESSION_EXPIRES] ?? 0);
        $csrf = (string)($_SESSION[self::SESSION_CSRF] ?? '');
        if ($username === '' || $csrf === '' || $expires <= time()) {
            self::clearSession();
            return false;
        }
        return true;
    }

    private static function refreshAdminSession(): void
    {
        $_SESSION[self::SESSION_EXPIRES] = time() + self::sessionLifetime();
    }

    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('P2KTPSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    private static function clearSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        unset($_SESSION[self::SESSION_USERNAME], $_SESSION[self::SESSION_CSRF], $_SESSION[self::SESSION_EXPIRES]);
    }

    private static function adminEntryUsername(mixed $entry): string
    {
        if (is_array($entry)) {
            foreach (['username', 'name', 'url', '@id'] as $key) {
                if (isset($entry[$key])) return self::adminEntryUsername($entry[$key]);
            }
            return '';
        }
        $value = trim((string)$entry);
        if ($value === '') return '';
        $path = (string)(parse_url($value, PHP_URL_PATH) ?: '');
        if ($path !== '') {
            $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
            foreach ($parts as $index => $part) {
                if (in_array(strtolower($part), ['player', 'member'], true) && isset($parts[$index + 1])) {
                    return strtolower(rawurldecode($parts[$index + 1]));
                }
            }
            if ($parts !== []) return strtolower(rawurldecode((string)end($parts)));
        }
        return strtolower(ltrim($value, '@'));
    }

    private static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return trim((string)($_SERVER[$key] ?? ''));
    }
}
