<?php
declare(strict_types=1);

namespace TGP\Widget;

use RuntimeException;

final class Auth
{
    private const USER = 'tgp_widget_user';
    private const CSRF = 'tgp_widget_csrf';
    private const EXPIRES = 'tgp_widget_expires';

    public static function start(int $lifetime): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $secure = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? '')) === 'https';
        $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/TGP/Widget/api.php');
        $path = rtrim(str_replace('\\', '/', dirname($script)), '/');
        if ($path === '' || $path === '.') $path = '/';
        session_name('TGPWIDGETSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path . '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if (!session_start()) throw new RuntimeException('Unable to start showcase administrator session.');
        if (!isset($_SESSION[self::CSRF])) $_SESSION[self::CSRF] = bin2hex(random_bytes(24));
        if (isset($_SESSION[self::EXPIRES]) && (int)$_SESSION[self::EXPIRES] <= time()) self::clear();
        if (isset($_SESSION[self::USER])) $_SESSION[self::EXPIRES] = time() + $lifetime;
    }

    public static function create(string $username, int $lifetime): array
    {
        self::start($lifetime);
        session_regenerate_id(true);
        $_SESSION[self::USER] = strtolower(trim($username));
        $_SESSION[self::CSRF] = bin2hex(random_bytes(24));
        $_SESSION[self::EXPIRES] = time() + $lifetime;
        return self::info($lifetime);
    }

    public static function info(int $lifetime): array
    {
        self::start($lifetime);
        $username = trim((string)($_SESSION[self::USER] ?? ''));
        $expires = (int)($_SESSION[self::EXPIRES] ?? 0);
        return [
            'authenticated' => $username !== '' && $expires > time(),
            'username' => $username,
            'csrf' => (string)($_SESSION[self::CSRF] ?? ''),
            'expiresAt' => $expires,
        ];
    }

    public static function requireAdmin(int $lifetime, bool $write = false): string
    {
        self::enforceSameOrigin($write);
        self::start($lifetime);
        $username = trim((string)($_SESSION[self::USER] ?? ''));
        $expires = (int)($_SESSION[self::EXPIRES] ?? 0);
        if ($username === '' || $expires <= time()) {
            self::clear();
            throw new RuntimeException('Administrator login required.', 401);
        }
        if ($write) {
            $provided = trim((string)($_SERVER['HTTP_X_TGP_CSRF'] ?? ''));
            $expected = (string)($_SESSION[self::CSRF] ?? '');
            if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
                throw new RuntimeException('Administrator request could not be verified.', 403);
            }
        }
        $_SESSION[self::EXPIRES] = time() + $lifetime;
        return strtolower($username);
    }

    public static function logout(int $lifetime): void
    {
        self::start($lifetime);
        self::clear();
        session_regenerate_id(true);
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
        foreach ($values as $entry) if (self::adminEntryUsername($entry) === $needle) return true;
        return false;
    }

    private static function adminEntryUsername(mixed $entry): string
    {
        if (is_array($entry)) {
            foreach (['username', 'name', 'url', '@id'] as $key) if (isset($entry[$key])) return self::adminEntryUsername($entry[$key]);
            return '';
        }
        $value = trim((string)$entry);
        if ($value === '') return '';
        $path = (string)(parse_url($value, PHP_URL_PATH) ?: '');
        if ($path !== '') {
            $parts = array_values(array_filter(explode('/', $path), static fn(string $part): bool => $part !== ''));
            foreach ($parts as $index => $part) {
                if (in_array(strtolower($part), ['player', 'member'], true) && isset($parts[$index + 1])) return strtolower(rawurldecode($parts[$index + 1]));
            }
            if ($parts !== []) return strtolower(rawurldecode((string)end($parts)));
        }
        return strtolower(ltrim($value, '@'));
    }

    public static function enforceSameOrigin(bool $required): void
    {
        $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
        $host = strtolower(preg_replace('/:\d+$/', '', trim((string)($_SERVER['HTTP_HOST'] ?? ''))) ?? '');
        foreach ([$origin, $referer] as $candidate) {
            if ($candidate === '') continue;
            $candidateHost = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
            if ($candidateHost !== '' && $host !== '' && !hash_equals($host, $candidateHost)) throw new RuntimeException('Request origin is not allowed.', 403);
        }
        if ($required && $origin === '' && $referer === '') throw new RuntimeException('A same-origin browser request is required.', 403);
    }

    private static function clear(): void
    {
        unset($_SESSION[self::USER], $_SESSION[self::EXPIRES]);
    }
}
