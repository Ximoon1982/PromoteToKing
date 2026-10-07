<?php
declare(strict_types=1);

namespace P2K\TrophyGallery;

interface TrophyRemoteArtworkFetcher
{
    public function fetch(string $url): array;
}

final class TrophyRemoteArtworkImporter implements TrophyRemoteArtworkFetcher
{
    public const MAX_BYTES = 10485760;
    public const MAX_DIMENSION = 6000;
    public const MAX_REDIRECTS = 4;

    public function fetch(string $url): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Remote artwork import requires the PHP cURL extension.');
        }

        $current = trim($url);
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $target = $this->inspectRemoteUrl($current);
            $response = $this->request($target);

            if ($response['status'] >= 300 && $response['status'] < 400) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    throw new \RuntimeException('Remote artwork redirected too many times.');
                }
                $location = trim((string)$response['location']);
                if ($location === '') {
                    throw new \RuntimeException('Remote artwork redirect did not provide a destination.');
                }
                $current = $this->resolveRedirect($current, $location);
                continue;
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException('Remote artwork request returned HTTP ' . $response['status'] . '.');
            }

            $image = self::validateImageBytes((string)$response['body']);
            return $image + [
                'source_url'=>trim($url),
                'final_url'=>$current,
            ];
        }

        throw new \RuntimeException('Remote artwork import did not complete.');
    }

    public function inspectRemoteUrl(string $url): array
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048) {
            throw new \RuntimeException('Remote artwork URL is invalid.');
        }

        $parts = parse_url($url);
        if (!is_array($parts)) throw new \RuntimeException('Remote artwork URL is invalid.');
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''), '[]');
        if (!in_array($scheme, ['http','https'], true) || $host === '') {
            throw new \RuntimeException('Remote artwork URL must use HTTP or HTTPS.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \RuntimeException('Remote artwork URL must not contain credentials.');
        }
        if (!filter_var($host, FILTER_VALIDATE_IP) && preg_match('/^[A-Za-z0-9.-]+$/D', $host) !== 1) {
            throw new \RuntimeException('Remote artwork hostname is invalid.');
        }

        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
            throw new \RuntimeException('Remote artwork URL uses an unsupported port.');
        }

        $ip = $this->resolvePublicAddress($host);
        return [
            'url'=>$url,
            'scheme'=>$scheme,
            'host'=>$host,
            'port'=>$port,
            'ip'=>$ip,
        ];
    }

    public static function validateImageBytes(string $body): array
    {
        $bytes = strlen($body);
        if ($bytes < 1 || $bytes > self::MAX_BYTES) {
            throw new \RuntimeException('Remote artwork must be no larger than 10 MB.');
        }
        $info = @getimagesizefromstring($body);
        $mime = strtolower((string)($info['mime'] ?? ''));
        $extensions = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        if (!isset($extensions[$mime])) {
            throw new \RuntimeException('Remote artwork must be a genuine PNG, JPEG or WebP image.');
        }
        $width = (int)($info[0] ?? 0);
        $height = (int)($info[1] ?? 0);
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
            throw new \RuntimeException('Remote artwork dimensions must be between 1 and 6000 pixels.');
        }
        return [
            'bytes_data'=>$body,
            'extension'=>$extensions[$mime],
            'mime'=>$mime,
            'bytes'=>$bytes,
            'width'=>$width,
            'height'=>$height,
        ];
    }

    private function request(array $target): array
    {
        $body = '';
        $location = '';
        $tooLarge = false;
        $ch = curl_init();
        if ($ch === false) throw new \RuntimeException('Remote artwork request could not start.');

        $ip = (string)$target['ip'];
        $resolveIp = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        $options = [
            CURLOPT_URL=>(string)$target['url'],
            CURLOPT_HTTPGET=>true,
            CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_MAXREDIRS=>0,
            CURLOPT_CONNECTTIMEOUT=>8,
            CURLOPT_TIMEOUT=>20,
            CURLOPT_USERAGENT=>'PromoteToKing-TrophyImporter/2.14.7',
            CURLOPT_HTTPHEADER=>['Accept: image/png,image/jpeg,image/webp,image/*;q=0.8'],
            CURLOPT_PROXY=>'',
            CURLOPT_RESOLVE=>[(string)$target['host'] . ':' . (int)$target['port'] . ':' . $resolveIp],
            CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HEADERFUNCTION=>static function ($curl, string $line) use (&$location): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'Location:') === 0) {
                    $location = trim(substr($trimmed, 9));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($tooLarge) throw new \RuntimeException('Remote artwork exceeds the 10 MB download limit.');
        if ($ok === false) {
            throw new \RuntimeException('Remote artwork request failed' . ($error !== '' ? ': ' . $error : '.'));
        }
        return ['status'=>$status,'location'=>$location,'body'=>$body];
    }

    private function resolvePublicAddress(string $host): string
    {
        $literal = filter_var($host, FILTER_VALIDATE_IP);
        if ($literal !== false) {
            if (!self::isPublicIp((string)$literal)) {
                throw new \RuntimeException('Remote artwork URL resolves to a private or reserved address.');
            }
            return (string)$literal;
        }

        $addresses = [];
        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $row) {
                    if (!empty($row['ip'])) $addresses[] = (string)$row['ip'];
                    if (!empty($row['ipv6'])) $addresses[] = (string)$row['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            $fallback = @gethostbynamel($host);
            if (is_array($fallback)) $addresses = array_merge($addresses, $fallback);
        }
        $addresses = array_values(array_unique(array_filter($addresses)));
        if ($addresses === []) throw new \RuntimeException('Remote artwork hostname could not be resolved.');

        foreach ($addresses as $address) {
            if (!self::isPublicIp($address)) {
                throw new \RuntimeException('Remote artwork hostname resolves to a private or reserved address.');
            }
        }
        return $addresses[0];
    }

    public static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private function resolveRedirect(string $base, string $location): string
    {
        if (preg_match('~^https?://~i', $location)) return $location;
        $baseParts = parse_url($base);
        if (!is_array($baseParts)) throw new \RuntimeException('Remote artwork redirect base is invalid.');
        $scheme = strtolower((string)($baseParts['scheme'] ?? ''));
        $host = (string)($baseParts['host'] ?? '');
        if ($scheme === '' || $host === '') throw new \RuntimeException('Remote artwork redirect base is invalid.');
        $origin = $scheme . '://' . $host;
        if (isset($baseParts['port'])) $origin .= ':' . (int)$baseParts['port'];
        if (str_starts_with($location, '//')) return $scheme . ':' . $location;
        if (str_starts_with($location, '/')) return $origin . $location;
        if (str_starts_with($location, '?')) {
            return $origin . ((string)($baseParts['path'] ?? '/')) . $location;
        }
        $path = (string)($baseParts['path'] ?? '/');
        $dir = str_contains($path, '/') ? substr($path, 0, (int)strrpos($path, '/') + 1) : '/';
        return $origin . $this->normalizePath($dir . $location);
    }

    private function normalizePath(string $path): string
    {
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $tail] = explode('?', $path, 2);
            $query = '?' . $tail;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($parts); continue; }
            $parts[] = $part;
        }
        return '/' . implode('/', $parts) . $query;
    }
}
