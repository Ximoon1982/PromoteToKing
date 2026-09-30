<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ReleaseSlotPolicy.php';
require_once dirname(__DIR__) . '/src/ReleaseSlotFilesystemProbe.php';
require_once dirname(__DIR__) . '/src/ReleaseSlotStore.php';
require_once dirname(__DIR__) . '/src/ReleaseSlotMaterializer.php';

use P2K\ReleaseControl\ReleaseSlotMaterializer;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['root:', 'runtime::', 'version:', 'source-head::', 'cache-key::', 'paths::']);
$root = rtrim((string)($options['root'] ?? ''), '/\\');
$runtime = trim((string)($options['runtime'] ?? ''));
$version = trim((string)($options['version'] ?? ''));
$sourceHead = trim((string)($options['source-head'] ?? ''));
$cacheKey = trim((string)($options['cache-key'] ?? ''));
$pathsArg = (string)($options['paths'] ?? '-');

if ($root === '' || $version === '') {
    fwrite(STDERR, "Usage: php materialize-current-slot.php --root=/path --version=x.y.z [--source-head=sha] [--cache-key=key] [--paths=file|-]\n");
    exit(2);
}

try {
    if ($pathsArg === '' || $pathsArg === '-') {
        $raw = stream_get_contents(STDIN);
        if ($raw === false) throw new RuntimeException('Unable to read release slot path list from stdin.');
    } else {
        $raw = @file_get_contents($pathsArg);
        if ($raw === false) throw new RuntimeException('Unable to read release slot path list.');
    }
    $paths = preg_split('/\r?\n/', trim($raw)) ?: [];
    $paths = array_values(array_filter(array_map('trim', $paths), static fn(string $v): bool => $v !== ''));
    $materializer = new ReleaseSlotMaterializer($root, $runtime !== '' ? $runtime : null);
    $result = $materializer->materialize($paths, $version, $sourceHead, $cacheKey);
    echo json_encode(['ok'=>true, 'slot'=>$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Release slot materialization failed: ' . $e->getMessage() . "\n");
    exit(1);
}
