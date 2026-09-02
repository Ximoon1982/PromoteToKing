<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/** Resolves catalogue artwork without owning achievement semantics. */
final class AchievementArtwork
{
    public static function resolve(string $key, string $icon, string $miniature): array
    {
        if (!self::usable($icon)) $icon = self::placeholder($key);
        $miniature = $miniature ?: $icon;
        if (!self::usable($miniature)) $miniature = $icon;
        return [$icon, $miniature];
    }

    private static function placeholder(string $key): string
    {
        return 'assets/images/achievements/placeholders/' . preg_replace('/[^a-z0-9_-]+/i', '-', strtolower($key)) . '.svg';
    }

    private static function usable(string $path): bool
    {
        if ($path === '' || $path === 'p2k-logo.jpg') return false;
        $full = dirname(__DIR__, 3) . '/' . ltrim($path, '/');
        return is_file($full) && filesize($full) > 0;
    }
}
