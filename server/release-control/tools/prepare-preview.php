<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use P2K\ReleaseControl\ReleasePreviewTree;
use P2K\ReleaseControl\ReleaseStateStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['root:', 'runtime::']);
$root = rtrim((string)($options['root'] ?? ''), '/\\');
$runtime = trim((string)($options['runtime'] ?? ''));
if ($root === '') {
    fwrite(STDERR, "Usage: php prepare-preview.php --root=/path [--runtime=/path]\n");
    exit(2);
}

try {
    $state = (new ReleaseStateStore($root, $runtime !== '' ? $runtime : null))->read();
    $releaseId = trim((string)($state['candidate_release'] ?? ''));
    if ($releaseId === '') {
        throw new RuntimeException('No candidate release is registered.');
    }
    $tree = (new ReleasePreviewTree($root, $runtime !== '' ? $runtime : null))->prepare($releaseId);
    echo json_encode([
        'ok'=>true,
        'candidate_release'=>$releaseId,
        'preview_tree'=>$tree,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Candidate preview preparation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
