<?php
declare(strict_types=1);

namespace P2K\Shared;

final class FilesystemCache
{
    private string $root;
    private int $maxBytes;
    private int $cleanupProbability;
    private int $expiredGrace;
    private int $maxEntries;
    private int $shardDepth;

    public function __construct(array $config = [])
    {
        $this->root = self::resolveCacheDir($config);
        $this->maxBytes = max(16 * 1024 * 1024, (int)($config['cache_max_bytes'] ?? 512 * 1024 * 1024));
        $this->cleanupProbability = max(0, min(100, (int)($config['cache_cleanup_probability_percent'] ?? 3)));
        $this->expiredGrace = max(0, (int)($config['cache_expired_grace_seconds'] ?? 86400));
        $this->maxEntries = max(1000, (int)($config['cache_max_entries'] ?? 30000));
        $this->shardDepth = max(0, min(2, (int)($config['cache_shard_depth'] ?? 1)));
        self::ensureProtectedDirectory($this->root);
    }

    public static function runtimeRoot(array $config = []): string
    {
        $configured = trim((string)($config['runtime_dir'] ?? ''));
        if ($configured !== '') return rtrim($configured, '/\\');
        // server/shared -> site root is two levels up.
        return dirname(__DIR__, 2) . '/data/runtime-v280';
    }

    public static function resolveCacheDir(array $config = []): string
    {
        $configured = trim((string)($config['cache_dir'] ?? ''));
        return $configured !== '' ? rtrim($configured, '/\\') : self::runtimeRoot($config) . '/cache/chesscom';
    }

    public static function resolveLogsDir(array $config = []): string
    {
        $configured = trim((string)($config['logs_dir'] ?? ''));
        return $configured !== '' ? rtrim($configured, '/\\') : self::runtimeRoot($config) . '/logs';
    }

    public static function resolveArchiveDir(array $config = []): string
    {
        $configured = trim((string)($config['archive_dir'] ?? ''));
        return $configured !== '' ? rtrim($configured, '/\\') : dirname(__DIR__, 2) . '/data/archive-v280';
    }

    public static function ensureProtectedDirectory(string $path): void
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create protected runtime directory: {$path}");
        }
        $deny = $path . '/.htaccess';
        if (!is_file($deny)) {
            @file_put_contents($deny, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        $index = $path . '/index.html';
        if (!is_file($index)) @file_put_contents($index, '');
    }

    /** @return array<string,mixed>|null */
    public function get(string $url): ?array
    {
        $path = null;
        foreach ($this->pathCandidates($url) as $candidate) { if (is_file($candidate)) { $path=$candidate; break; } }
        if ($path === null) return null;
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') return null;
        $decoded = @gzdecode($raw);
        if ($decoded === false) {
            @unlink($path);
            return null;
        }
        $payload = json_decode($decoded, true);
        if (!is_array($payload) || !hash_equals((string)($payload['url_hash'] ?? ''), hash('sha256', $url))) {
            @unlink($path);
            return null;
        }
        return [
            'status_code' => (int)($payload['status_code'] ?? 0),
            'body_json' => array_key_exists('body_json', $payload) ? ($payload['body_json'] === null ? null : (string)$payload['body_json']) : null,
            'etag' => $payload['etag'] ?? null,
            'last_modified' => $payload['last_modified'] ?? null,
            'fetched_at' => isset($payload['fetched_at']) ? gmdate('Y-m-d H:i:s', (int)$payload['fetched_at']) : null,
            'expires_at' => isset($payload['expires_at']) ? gmdate('Y-m-d H:i:s', (int)$payload['expires_at']) : null,
            '_fetched_epoch' => (int)($payload['fetched_at'] ?? 0),
            '_expires_epoch' => (int)($payload['expires_at'] ?? 0),
            'consumer' => (string)($payload['consumer'] ?? ''),
            '_path' => $path,
        ];
    }

    public function put(
        string $url,
        int $status,
        ?string $body,
        ?string $etag,
        ?string $lastModified,
        int $ttlSeconds,
        string $consumer = 'shared'
    ): void {
        $now = time();
        $payload = [
            'format' => 1,
            'url_hash' => hash('sha256', $url),
            'status_code' => $status,
            'body_json' => $body,
            'etag' => $etag,
            'last_modified' => $lastModified,
            'fetched_at' => $now,
            'expires_at' => $now + max(0, $ttlSeconds),
            'consumer' => substr($consumer, 0, 80),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $compressed = gzencode($json, 6);
        if ($compressed === false) throw new \RuntimeException('Unable to compress a Chess.com cache entry.');
        $path = $this->pathFor($url, true);
        $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $compressed, LOCK_EX) === false || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write the filesystem Chess.com cache.');
        }
        foreach($this->pathCandidates($url) as $candidate){if($candidate!==$path&&is_file($candidate))@unlink($candidate);}
        @unlink($this->root . '/.stats-cache.json');
        if ($this->cleanupProbability > 0 && random_int(1, 100) <= $this->cleanupProbability) {
            $this->purge(250);
        }
    }

    /** @return array{removed_files:int,removed_bytes:int,remaining_bytes:int,remaining_files:int} */
    public function purge(int $maxFiles = 1000): array
    {
        $maxFiles = max(1, min(100000, $maxFiles));
        $now = time();
        $files = $this->cacheFiles();
        $removed = 0; $removedBytes = 0; $total = 0; $count = 0;
        $survivors = [];
        foreach ($files as $path) {
            $size = (int)@filesize($path);
            $total += max(0, $size); $count++;
            $expired = false;
            if ($removed < $maxFiles) {
                $raw = @file_get_contents($path);
                if ($raw !== false && ($text = @gzdecode($raw)) !== false) {
                    $meta = json_decode($text, true);
                    $expires = is_array($meta) ? (int)($meta['expires_at'] ?? 0) : 0;
                    $expired = $expires > 0 && $expires < ($now - $this->expiredGrace);
                } else {
                    $expired = true;
                }
            }
            if ($expired && @unlink($path)) {
                $removed++; $removedBytes += max(0, $size); $total -= max(0, $size); $count--;
            } else {
                $survivors[] = ['path'=>$path,'mtime'=>(int)@filemtime($path),'size'=>max(0,$size)];
            }
        }
        if (($total > $this->maxBytes || $count > $this->maxEntries) && $removed < $maxFiles) {
            usort($survivors, static fn(array $a,array $b): int => $a['mtime'] <=> $b['mtime']);
            foreach ($survivors as $file) {
                if (($total <= $this->maxBytes && $count <= $this->maxEntries) || $removed >= $maxFiles) break;
                if (@unlink($file['path'])) {
                    $removed++; $removedBytes += $file['size']; $total -= $file['size']; $count--;
                }
            }
        }
        $this->pruneEmptyShardDirectories();
        @unlink($this->root . '/.stats-cache.json');
        return ['removed_files'=>$removed,'removed_bytes'=>$removedBytes,'remaining_bytes'=>max(0,$total),'remaining_files'=>max(0,$count),'max_entries'=>$this->maxEntries];
    }

    /** @return array<string,int|string> */
    public function stats(): array
    {
        // Ordinary status requests need inventory size, not a decompression pass over
        // every cached Chess.com response. Cache the cheap filesystem inventory for 5m.
        $metaPath=$this->root.'/.stats-cache.json';$now=time();
        if(is_file($metaPath) && (int)(@filemtime($metaPath)?:0)>=$now-300){
            $cached=json_decode((string)@file_get_contents($metaPath),true);
            if(is_array($cached))return $cached;
        }
        $bytes=0;$files=0;
        foreach($this->cacheFiles() as $path){$files++;$bytes+=max(0,(int)@filesize($path));}
        $result=[
            'directory'=>$this->root,'entries'=>$files,
            'fresh_entries'=>-1,'expired_entries'=>-1,'bytes'=>$bytes,'max_bytes'=>$this->maxBytes,'max_entries'=>$this->maxEntries,'shard_depth'=>$this->shardDepth,
            'inventory_mode'=>'cached_size_only','generated_at'=>gmdate('c'),
        ];
        @file_put_contents($metaPath,json_encode($result,JSON_UNESCAPED_SLASHES),LOCK_EX);
        return $result;
    }

    public function root(): string { return $this->root; }

    private function pathFor(string $url, bool $create = false): string
    {
        $hash = hash('sha256', $url);$parts=[];
        if($this->shardDepth>=1)$parts[]=substr($hash,0,2);if($this->shardDepth>=2)$parts[]=substr($hash,2,2);
        $dir=$this->root.($parts?'/'.implode('/',$parts):'');
        if ($create && !is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException("Unable to create cache shard directory: {$dir}");
        return $dir . '/' . $hash . '.json.gz';
    }

    /** Current path first, then legacy two-level path for transparent migration. */
    private function pathCandidates(string $url): array
    {
        $current=$this->pathFor($url,false);$hash=hash('sha256',$url);$legacy=$this->root.'/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash.'.json.gz';
        return $current===$legacy?[$current]:[$current,$legacy];
    }

    private function pruneEmptyShardDirectories(): void
    {
        if(!is_dir($this->root))return;
        try{$it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $entry){if($entry->isDir())@rmdir($entry->getPathname());}}catch(\Throwable){}
    }

    /** @return list<string> */
    private function cacheFiles(): array
    {
        if (!is_dir($this->root)) return [];
        $paths = [];
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.json.gz')) $paths[] = $file->getPathname();
            }
        } catch (\Throwable) { return $paths; }
        return $paths;
    }
}
