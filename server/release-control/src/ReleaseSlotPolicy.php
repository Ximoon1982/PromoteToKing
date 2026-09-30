<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleaseSlotPolicy
{
    public const POLICY_VERSION = 1;

    public static function sharedPathDescriptions(): array
    {
        return [
            'data/** (runtime/cache/session/upload state)',
            'logs/**',
            'storage/**',
            '**/*.local.* and **/.env* host-local configuration',
            'ReleaseControl.php and server/release-control/** recovery plane',
        ];
    }

    public static function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $path = preg_replace('~/+~', '/', $path) ?? $path;
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) return '';
        while (str_starts_with($path, './')) $path = substr($path, 2);
        if ($path === '') return '';
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, "\n") || str_contains($part, "\r") || str_contains($part, "\t")) {
                return '';
            }
        }
        return implode('/', $parts);
    }

    public static function isReleaseOwnedPath(string $path): bool
    {
        $path = self::normalizeRelativePath($path);
        if ($path === '') return false;
        $lower = strtolower($path);
        if ($lower === 'releasecontrol.php' || str_starts_with($lower, 'server/release-control/')) return false;
        foreach (['data/', 'logs/', 'storage/'] as $prefix) {
            if (str_starts_with($lower, $prefix)) return false;
        }
        $parts = explode('/', $lower);
        foreach ($parts as $part) {
            if ($part === '.env' || str_starts_with($part, '.env.')) return false;
            if (str_contains($part, '.local.') && !str_contains($part, '.example.')) return false;
        }
        return true;
    }

    public static function normalizeReleasePaths(iterable $paths): array
    {
        $out = [];
        foreach ($paths as $raw) {
            $path = self::normalizeRelativePath((string)$raw);
            if ($path === '') throw new \InvalidArgumentException('Release slot path is invalid.');
            if (!self::isReleaseOwnedPath($path)) {
                throw new \InvalidArgumentException('Release slot path belongs to shared/recovery state: ' . $path);
            }
            $out[$path] = true;
        }
        $paths = array_keys($out);
        sort($paths, SORT_STRING);
        return $paths;
    }
}
