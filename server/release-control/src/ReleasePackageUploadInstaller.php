<?php
declare(strict_types=1);

namespace P2K\ReleaseControl;

final class ReleasePackageUploadInstaller
{
    private const MAX_ZIP_BYTES = 134217728; // 128 MiB
    private const MAX_EXTRACTED_BYTES = 536870912; // 512 MiB
    private const MAX_ENTRIES = 20000;

    public function __construct(
        private readonly string $root,
        private readonly ?string $runtimeOverride = null
    ) {
    }

    public function available(): bool
    {
        return class_exists('ZipArchive');
    }

    public function uploadLimitBytes(): int
    {
        $limits = [self::MAX_ZIP_BYTES];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $parsed = $this->parseIniBytes((string)ini_get($key));
            if ($parsed > 0) $limits[] = $parsed;
        }
        return max(1, min($limits));
    }

    public function installUploaded(array $file, string $actor): array
    {
        if (!$this->available()) throw new \RuntimeException('ZIP upload requires the PHP ZipArchive extension.');
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new \RuntimeException($this->uploadErrorMessage($error));
        $tmp = (string)($file['tmp_name'] ?? '');
        $name = trim((string)($file['name'] ?? ''));
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_file($tmp)) throw new \RuntimeException('Uploaded ZIP temporary file is unavailable.');
        if ($name === '' || !str_ends_with(strtolower($name), '.zip')) throw new \RuntimeException('Release package upload must be a .zip file.');
        if ($size <= 0 || $size > $this->uploadLimitBytes()) throw new \RuntimeException('Uploaded release ZIP exceeds the configured safe upload limit.');
        if (!is_uploaded_file($tmp) && (string)(getenv('P2K_RELEASE_UPLOAD_ALLOW_LOCAL') ?: '') !== '1') {
            throw new \RuntimeException('Release package did not arrive through a valid HTTP file upload.');
        }
        return $this->installZipPath($tmp, $actor, $name);
    }

    /** Test/CLI helper. The web UI calls installUploaded(). */
    public function installZipPath(string $zipPath, string $actor, string $originalName = ''): array
    {
        if (!$this->available()) throw new \RuntimeException('ZIP installation requires the PHP ZipArchive extension.');
        $zipPath = trim($zipPath);
        if ($zipPath === '' || !is_file($zipPath) || is_link($zipPath)) throw new \RuntimeException('Release ZIP is unavailable or unsafe.');
        $zipBytes = (int)(filesize($zipPath) ?: 0);
        if ($zipBytes <= 0 || $zipBytes > self::MAX_ZIP_BYTES) throw new \RuntimeException('Release ZIP exceeds the 128 MiB package limit.');
        $actor = $this->normalizeActor($actor);

        $uploads = $this->uploadsDir();
        $this->ensureDirectory($uploads, 0700);
        $token = gmdate('Ymd\THis\Z') . '-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $stagedZip = $uploads . '/.incoming-' . $token . '.zip';
        $extract = $uploads . '/.extract-' . $token;
        if (!@copy($zipPath, $stagedZip)) throw new \RuntimeException('Unable to stage uploaded release ZIP.');
        @chmod($stagedZip, 0600);

        try {
            $packageRoot = $this->extractVerifiedZip($stagedZip, $extract);
            $package = new ReleaseCandidatePackage($packageRoot);
            $meta = $package->validate();
            $recovery = $this->validateRecoveryPlane($packageRoot);

            $candidateResult = (new ReleaseCandidateInstaller($this->root, $this->runtimeOverride))
                ->install($packageRoot, $actor, true);
            $releaseId = (string)($candidateResult['candidate']['release_id'] ?? '');
            if ($releaseId === '') throw new \RuntimeException('Candidate installer did not return a release identity.');
            $preview = (new ReleasePreviewTree($this->root, $this->runtimeOverride))->prepare($releaseId);

            // Candidate and preview are complete before the recovery plane is touched.
            // If recovery activation fails, public traffic is still unchanged and a retry is safe/idempotent.
            $activation = $this->activateRecoveryPlane($packageRoot, $recovery, $actor, (string)($meta['version'] ?? 'unknown'));

            return [
                'ok'=>true,
                'original_name'=>$originalName !== '' ? $originalName : basename($zipPath),
                'package_version'=>(string)($meta['version'] ?? ''),
                'source_head'=>(string)($meta['source_head'] ?? ''),
                'release_id'=>$releaseId,
                'candidate'=>$candidateResult['candidate'] ?? [],
                'preview'=>$preview,
                'recovery_backup'=>$activation['backup'] ?? '',
                'recovery_files'=>(int)($activation['files'] ?? 0),
                'public_changed'=>false,
            ];
        } finally {
            $this->removeTree($extract);
            @unlink($stagedZip);
        }
    }

    private function extractVerifiedZip(string $zipPath, string $extract): string
    {
        $zip = new \ZipArchive();
        $open = $zip->open($zipPath, \ZipArchive::RDONLY);
        if ($open !== true) throw new \RuntimeException('Uploaded file is not a readable ZIP archive.');
        if ($zip->numFiles <= 0 || $zip->numFiles > self::MAX_ENTRIES) {
            $zip->close();
            throw new \RuntimeException('Release ZIP has an invalid or excessive entry count.');
        }
        $entries = [];
        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
                if (!is_array($stat)) throw new \RuntimeException('Unable to inspect ZIP entry.');
                $raw = (string)($stat['name'] ?? '');
                if ($raw === '' || str_contains($raw, "\\") || str_contains($raw, "\0") || str_starts_with($raw, '/')) {
                    throw new \RuntimeException('Release ZIP contains an unsafe entry path.');
                }
                $isDir = str_ends_with($raw, '/');
                $candidate = $isDir ? rtrim($raw, '/') : $raw;
                $path = ReleaseSlotPolicy::normalizeRelativePath($candidate);
                if ($path === '') throw new \RuntimeException('Release ZIP contains an unsafe relative path: ' . $raw);
                if ($this->zipEntryIsSymlink($zip, $i)) throw new \RuntimeException('Release ZIP contains a symbolic link: ' . $path);
                $size = max(0, (int)($stat['size'] ?? 0));
                if ($size > self::MAX_ZIP_BYTES) throw new \RuntimeException('Release ZIP contains an oversized file: ' . $path);
                $total += $size;
                if ($total > self::MAX_EXTRACTED_BYTES) throw new \RuntimeException('Release ZIP exceeds the 512 MiB extracted-size limit.');
                $entries[] = ['index'=>$i,'path'=>$path,'directory'=>$isDir,'size'=>$size];
            }

            $this->ensureDirectory($extract, 0700);
            foreach ($entries as $entry) {
                $dest = $extract . '/' . $entry['path'];
                if ($entry['directory']) {
                    $this->ensureDirectory($dest, 0700);
                    continue;
                }
                $this->ensureDirectory(dirname($dest), 0700);
                $in = $zip->getStream((string)$zip->getNameIndex((int)$entry['index'], \ZipArchive::FL_UNCHANGED));
                if (!is_resource($in)) throw new \RuntimeException('Unable to read ZIP entry: ' . $entry['path']);
                $out = @fopen($dest, 'xb');
                if ($out === false) { fclose($in); throw new \RuntimeException('Unable to extract ZIP entry: ' . $entry['path']); }
                $written = stream_copy_to_stream($in, $out, self::MAX_ZIP_BYTES + 1);
                fclose($in); fclose($out);
                if ($written === false || $written !== (int)$entry['size']) throw new \RuntimeException('Extracted ZIP entry size mismatch: ' . $entry['path']);
                @chmod($dest, 0600);
            }
        } finally {
            $zip->close();
        }

        if (is_file($extract . '/CANDIDATE_RELEASE.json')) return $extract;
        $roots = [];
        foreach (scandir($extract) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $extract . '/' . $name;
            if (is_dir($path) && !is_link($path) && is_file($path . '/CANDIDATE_RELEASE.json')) $roots[] = $path;
        }
        if (count($roots) !== 1) throw new \RuntimeException('Release ZIP must contain exactly one package root with CANDIDATE_RELEASE.json.');
        return $roots[0];
    }

    /** @return array<string,string> path => sha256 */
    private function validateRecoveryPlane(string $packageRoot): array
    {
        $manifest = $packageRoot . '/RECOVERY_PLANE.sha256';
        if (!is_file($manifest) || is_link($manifest)) throw new \RuntimeException('Release package is missing RECOVERY_PLANE.sha256.');
        $rows = file($manifest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($rows) || $rows === []) throw new \RuntimeException('Recovery-plane manifest is empty.');
        $out = [];
        foreach ($rows as $row) {
            if (!preg_match('/^([0-9a-f]{64})\s+\.?\/?(.+)$/D', trim((string)$row), $m)) {
                throw new \RuntimeException('Recovery-plane manifest contains an invalid row.');
            }
            $path = ReleaseSlotPolicy::normalizeRelativePath($m[2]);
            if ($path === '' || !$this->isRecoveryPlanePath($path)) throw new \RuntimeException('Recovery-plane manifest contains a forbidden path: ' . $m[2]);
            if (isset($out[$path])) throw new \RuntimeException('Recovery-plane manifest contains a duplicate path: ' . $path);
            $file = $packageRoot . '/payload/' . $path;
            if (!is_file($file) || is_link($file)) throw new \RuntimeException('Recovery-plane payload file is missing or unsafe: ' . $path);
            $actual = hash_file('sha256', $file);
            if (!is_string($actual) || !hash_equals($m[1], $actual)) throw new \RuntimeException('Recovery-plane payload hash mismatch: ' . $path);
            if (str_ends_with(strtolower($path), '.php')) $this->validatePhpSyntax($file, $path);
            $out[$path] = $m[1];
        }
        foreach (['ReleaseControl.php','server/release-control/src/bootstrap.php','server/release-control/src/ReleasePackageUploadInstaller.php','server/release-control/src/FilesystemCleanupManager.php'] as $required) {
            if (!isset($out[$required])) throw new \RuntimeException('Recovery-plane manifest is missing required file: ' . $required);
        }
        return $out;
    }

    private function activateRecoveryPlane(string $packageRoot, array $manifest, string $actor, string $version): array
    {
        $root = rtrim($this->root, '/\\');
        $backup = $root . '/storage/release-backups/web-upload-v' . preg_replace('/[^0-9A-Za-z._-]+/', '-', $version)
            . '-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
        $this->ensureDirectory($backup, 0700);
        $absent = $backup . '/.absent';
        $this->ensureDirectory($absent, 0700);
        $staged = [];
        $activated = [];
        $paths = array_keys($manifest);
        usort($paths, fn(string $a, string $b): int => [$this->activationWeight($a), $a] <=> [$this->activationWeight($b), $b]);

        try {
            foreach ($paths as $path) {
                $src = $packageRoot . '/payload/' . $path;
                $dst = $root . '/' . $path;
                if (is_link($dst)) throw new \RuntimeException('Recovery-plane target is unexpectedly a symlink: ' . $path);
                if (is_file($dst)) {
                    $copy = $backup . '/' . $path;
                    $this->ensureDirectory(dirname($copy), 0700);
                    if (!@copy($dst, $copy)) throw new \RuntimeException('Unable to back up recovery-plane file: ' . $path);
                    @chmod($copy, 0600);
                } elseif (file_exists($dst)) {
                    throw new \RuntimeException('Recovery-plane target is not a regular file: ' . $path);
                } else {
                    $marker = $absent . '/' . $path;
                    $this->ensureDirectory(dirname($marker), 0700);
                    if (@file_put_contents($marker, "absent\n", LOCK_EX) === false) throw new \RuntimeException('Unable to record absent recovery-plane file: ' . $path);
                }
                $this->ensureDirectory(dirname($dst), 0755);
                $tmp = $dst . '.p2k-web-package-' . getmypid() . '-' . bin2hex(random_bytes(3)) . '.tmp';
                if (!@copy($src, $tmp)) throw new \RuntimeException('Unable to stage recovery-plane file: ' . $path);
                @chmod($tmp, 0644);
                if (!hash_equals((string)$manifest[$path], (string)hash_file('sha256', $tmp))) {
                    @unlink($tmp);
                    throw new \RuntimeException('Staged recovery-plane hash mismatch: ' . $path);
                }
                $staged[$path] = $tmp;
            }

            foreach ($paths as $path) {
                $dst = $root . '/' . $path;
                if (!@rename($staged[$path], $dst)) throw new \RuntimeException('Unable to activate recovery-plane file: ' . $path);
                unset($staged[$path]);
                $activated[] = $path;
            }
            @file_put_contents($backup . '/ACTIVATION.json', json_encode([
                'schema_version'=>1,'version'=>$version,'actor'=>$actor,'activated_at'=>gmdate('c'),'files'=>$paths,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", LOCK_EX);
            return ['backup'=>$backup,'files'=>count($paths)];
        } catch (\Throwable $e) {
            foreach ($staged as $tmp) @unlink($tmp);
            foreach (array_reverse($activated) as $path) {
                $dst = $root . '/' . $path;
                $saved = $backup . '/' . $path;
                $marker = $absent . '/' . $path;
                if (is_file($saved)) @copy($saved, $dst);
                elseif (is_file($marker)) @unlink($dst);
            }
            throw new \RuntimeException('Recovery-plane activation failed and was rolled back: ' . $e->getMessage(), 0, $e);
        }
    }

    private function isRecoveryPlanePath(string $path): bool
    {
        $lower = strtolower($path);
        if (in_array($lower, ['.htaccess','releasecontrol.php','previewrouter.php','publicrouter.php'], true)) return true;
        if (!str_starts_with($lower, 'server/release-control/')) return false;
        foreach (explode('/', $lower) as $part) {
            if ($part === '.env' || str_starts_with($part, '.env.') || str_contains($part, '.local.')) return false;
        }
        return true;
    }

    private function activationWeight(string $path): int
    {
        $lower = strtolower($path);
        if ($lower === '.htaccess') return 100;
        if ($lower === 'releasecontrol.php') return 80;
        if (in_array($lower, ['previewrouter.php','publicrouter.php'], true)) return 70;
        if (str_starts_with($lower, 'server/release-control/')) return 10;
        return 50;
    }

    private function validatePhpSyntax(string $file, string $label): void
    {
        $code = @file_get_contents($file);
        if ($code === false) throw new \RuntimeException('Unable to read staged PHP file: ' . $label);
        try { token_get_all($code, TOKEN_PARSE); }
        catch (\ParseError $e) { throw new \RuntimeException('Recovery-plane PHP syntax is invalid for ' . $label . ': ' . $e->getMessage(), 0, $e); }
    }

    private function zipEntryIsSymlink(\ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) return false;
        $opsys = 0; $attr = 0;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attr)) return false;
        $mode = ($attr >> 16) & 0xFFFF;
        return ($mode & 0170000) === 0120000;
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded release ZIP exceeds the PHP upload limit.',
            UPLOAD_ERR_PARTIAL => 'Release ZIP upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'Choose a release ZIP to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload temporary directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded release ZIP.',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the release ZIP upload.',
            default => 'Release ZIP upload failed with error code ' . $error . '.',
        };
    }

    private function parseIniBytes(string $raw): int
    {
        $raw = trim($raw);
        if ($raw === '') return 0;
        $unit = strtolower(substr($raw, -1));
        $n = (float)$raw;
        return (int)round($n * match ($unit) { 'g'=>1073741824, 'm'=>1048576, 'k'=>1024, default=>1 });
    }

    private function normalizeActor(string $actor): string
    {
        $actor = strtolower(trim($actor));
        if ($actor === '') $actor = 'web';
        if (!preg_match('/^[a-z0-9_.@-]{1,100}$/D', $actor)) throw new \InvalidArgumentException('Release package actor is invalid.');
        return $actor;
    }

    private function uploadsDir(): string
    {
        return $this->runtimeDir() . '/release-control/uploads';
    }

    private function runtimeDir(): string
    {
        if ($this->runtimeOverride !== null && trim($this->runtimeOverride) !== '') return rtrim($this->runtimeOverride, '/\\');
        $configPath = rtrim($this->root, '/\\') . '/server/team-points/config/config.local.php';
        if (is_file($configPath)) {
            try {
                $config = require $configPath;
                $runtime = is_array($config) ? rtrim((string)(($config['storage']['runtime_dir'] ?? '')), '/\\') : '';
                if ($runtime !== '') return $runtime;
            } catch (\Throwable) {
            }
        }
        return rtrim($this->root, '/\\') . '/data/runtime-v280';
    }

    private function ensureDirectory(string $path, int $mode): void
    {
        if (!is_dir($path) && !@mkdir($path, $mode, true) && !is_dir($path)) throw new \RuntimeException('Unable to prepare release package directory: ' . $path);
        @chmod($path, $mode);
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || (!file_exists($path) && !is_link($path))) return;
        if (is_link($path) || is_file($path)) { @unlink($path); return; }
        @chmod($path, 0700);
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $item) {
                $p = $item->getPathname();
                if ($item->isDir() && !$item->isLink()) { @chmod($p, 0700); @rmdir($p); }
                else @unlink($p);
            }
        } catch (\Throwable) {
        }
        @rmdir($path);
    }
}
