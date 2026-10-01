<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleasePreviewSession
{
    public const COOKIE = 'P2KRC_PREVIEW';
    public const PENDING_COOKIE = 'P2KRC_PREVIEW_PENDING';
    private const TTL_SECONDS = 28800;
    private const PENDING_TTL_SECONDS = 600;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function enable(string $username): array
    {
        $username = strtolower(trim($username));
        if ($username === '') throw new \RuntimeException('Preview identity is unavailable.');
        $state = (new ReleaseStateStore($this->root, $this->runtimeOverride))->read();
        $releaseId = trim((string)($state['candidate_release'] ?? ''));
        if ($releaseId === '') throw new \RuntimeException('No candidate release is registered.');

        $tree = (new ReleasePreviewTree($this->root, $this->runtimeOverride))->describeExisting($releaseId);
        if (!is_array($tree)) {
            throw new \RuntimeException('Candidate preview tree is not prepared. Re-run the candidate-preview bootstrap.');
        }
        $expires = time() + self::TTL_SECONDS;
        $payload = ['u'=>$username,'r'=>$releaseId,'e'=>$expires];
        $encoded = $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = $this->b64url(hash_hmac('sha256', $encoded, $this->secret(true), true));
        $this->setCookie($encoded . '.' . $signature, $expires);
        return ['enabled'=>true,'release_id'=>$releaseId,'expires_at'=>$expires,'preview_tree'=>$tree];
    }

    public function disable(): void
    {
        $this->setCookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
    }

    public function status(string $username): array
    {
        $username = strtolower(trim($username));
        $raw = trim((string)($_COOKIE[self::COOKIE] ?? ''));
        if ($username === '' || $raw === '') return ['enabled'=>false,'release_id'=>null,'reason'=>'not_enabled'];

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) return ['enabled'=>false,'release_id'=>null,'reason'=>'invalid_cookie'];
        [$encoded, $signature] = $parts;
        $secret = $this->secret(false);
        if ($secret === '') return ['enabled'=>false,'release_id'=>null,'reason'=>'secret_unavailable'];
        $expected = $this->b64url(hash_hmac('sha256', $encoded, $secret, true));
        if (!hash_equals($expected, $signature)) return ['enabled'=>false,'release_id'=>null,'reason'=>'invalid_signature'];

        $decoded = $this->unb64url($encoded);
        if ($decoded === '') return ['enabled'=>false,'release_id'=>null,'reason'=>'invalid_payload'];
        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['enabled'=>false,'release_id'=>null,'reason'=>'invalid_payload'];
        }
        if (!is_array($payload)) return ['enabled'=>false,'release_id'=>null,'reason'=>'invalid_payload'];
        if (!hash_equals($username, strtolower(trim((string)($payload['u'] ?? ''))))) {
            return ['enabled'=>false,'release_id'=>null,'reason'=>'identity_mismatch'];
        }
        $releaseId = trim((string)($payload['r'] ?? ''));
        $expires = (int)($payload['e'] ?? 0);
        if ($expires <= time()) return ['enabled'=>false,'release_id'=>$releaseId ?: null,'reason'=>'expired'];

        try {
            $state = (new ReleaseStateStore($this->root, $this->runtimeOverride))->read();
        } catch (\Throwable) {
            return ['enabled'=>false,'release_id'=>$releaseId ?: null,'reason'=>'state_unavailable'];
        }
        if ($releaseId === '' || !hash_equals($releaseId, trim((string)($state['candidate_release'] ?? '')))) {
            return ['enabled'=>false,'release_id'=>$releaseId ?: null,'reason'=>'candidate_changed'];
        }
        $tree = (new ReleasePreviewTree($this->root, $this->runtimeOverride))->describeExisting($releaseId);
        if (!is_array($tree)) return ['enabled'=>false,'release_id'=>$releaseId,'reason'=>'preview_tree_unavailable'];
        return ['enabled'=>true,'release_id'=>$releaseId,'expires_at'=>$expires,'reason'=>'ok','preview_tree'=>$tree];
    }

    public function beginPendingEnable(string $usernameHint): void
    {
        $usernameHint = strtolower(trim($usernameHint));
        if (!preg_match('/^[a-z0-9_-]{1,80}$/', $usernameHint)) {
            throw new \RuntimeException('Preview identity hint is unavailable.');
        }
        $expires = time() + self::PENDING_TTL_SECONDS;
        $payload = ['a'=>'enable-preview','u'=>$usernameHint,'e'=>$expires];
        $encoded = $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = $this->b64url(hash_hmac('sha256', $encoded, $this->secret(true), true));
        $this->setNamedCookie(self::PENDING_COOKIE, $encoded . '.' . $signature, $expires);
    }

    public function consumePendingEnable(string $username): bool
    {
        $username = strtolower(trim($username));
        $raw = trim((string)($_COOKIE[self::PENDING_COOKIE] ?? ''));
        $this->setNamedCookie(self::PENDING_COOKIE, '', time() - 3600);
        unset($_COOKIE[self::PENDING_COOKIE]);
        if ($username === '' || $raw === '') return false;

        $parts = explode('.', $raw, 2);
        if (count($parts) !== 2) return false;
        [$encoded, $signature] = $parts;
        $secret = $this->secret(false);
        if ($secret === '') return false;
        $expected = $this->b64url(hash_hmac('sha256', $encoded, $secret, true));
        if (!hash_equals($expected, $signature)) return false;
        $decoded = $this->unb64url($encoded);
        if ($decoded === '') return false;
        try {
            $payload = json_decode($decoded, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($payload)) return false;
        if ((string)($payload['a'] ?? '') !== 'enable-preview') return false;
        if ((int)($payload['e'] ?? 0) <= time()) return false;
        return hash_equals($username, strtolower(trim((string)($payload['u'] ?? ''))));
    }

    public function clearInvalidCookie(): void
    {
        if (!empty($_COOKIE[self::COOKIE])) $this->disable();
    }

    private function secret(bool $create): string
    {
        $store = new ReleaseSlotStore($this->root, $this->runtimeOverride);
        $dir = $store->controlDir();
        $path = $dir . '/preview-secret.key';
        if (is_file($path)) {
            $raw = @file_get_contents($path);
            return is_string($raw) && strlen($raw) >= 32 ? $raw : '';
        }
        if (!$create) return '';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to prepare preview secret storage.');
        }
        $secret = random_bytes(32);
        $tmp = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $secret, LOCK_EX) === false) throw new \RuntimeException('Unable to stage preview secret.');
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) { @unlink($tmp); throw new \RuntimeException('Unable to publish preview secret.'); }
        return $secret;
    }

    private function setCookie(string $value, int $expires): void
    {
        $this->setNamedCookie(self::COOKIE, $value, $expires);
        if ($value !== '') $_COOKIE[self::COOKIE] = $value;
    }

    private function setNamedCookie(string $name, string $value, int $expires): void
    {
        setcookie($name, $value, [
            'expires'=>$expires,
            'path'=>'/',
            'secure'=>true,
            'httponly'=>true,
            'samesite'=>'Lax',
        ]);
        if ($value !== '') $_COOKIE[$name] = $value;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function unb64url(string $encoded): string
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $encoded)) return '';
        $pad = strlen($encoded) % 4;
        if ($pad !== 0) $encoded .= str_repeat('=', 4 - $pad);
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);
        return is_string($raw) ? $raw : '';
    }
}
