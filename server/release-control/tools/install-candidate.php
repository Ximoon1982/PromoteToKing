<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ReleaseSlotPolicy.php';
require_once dirname(__DIR__) . '/src/ReleaseSlotFilesystemProbe.php';
require_once dirname(__DIR__) . '/src/ReleaseSlotStore.php';
require_once dirname(__DIR__) . '/src/ReleaseCandidatePackage.php';
require_once dirname(__DIR__) . '/src/ReleaseStateStore.php';
require_once dirname(__DIR__) . '/src/ReleaseCandidateInstaller.php';

use P2K\ReleaseControl\ReleaseCandidateInstaller;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['root:', 'runtime::', 'package:', 'actor::', 'replace-candidate']);
$root = rtrim((string)($options['root'] ?? ''), '/\\');
$runtime = trim((string)($options['runtime'] ?? ''));
$package = rtrim((string)($options['package'] ?? ''), '/\\');
$actor = trim((string)($options['actor'] ?? 'cli'));
$replace = array_key_exists('replace-candidate', $options);

if ($root === '' || $package === '') {
    fwrite(STDERR, "Usage: php install-candidate.php --root=/path --package=/path/to/extracted/package [--actor=name] [--replace-candidate]\n");
    exit(2);
}

try {
    $beforeVersion = is_file($root . '/VERSION') ? hash_file('sha256', $root . '/VERSION') : '';
    $beforeUi = is_file($root . '/ui-v2.html') ? hash_file('sha256', $root . '/ui-v2.html') : '';
    $installer = new ReleaseCandidateInstaller($root, $runtime !== '' ? $runtime : null);
    $result = $installer->install($package, $actor, $replace);
    $afterVersion = is_file($root . '/VERSION') ? hash_file('sha256', $root . '/VERSION') : '';
    $afterUi = is_file($root . '/ui-v2.html') ? hash_file('sha256', $root . '/ui-v2.html') : '';
    if ($beforeVersion !== $afterVersion || $beforeUi !== $afterUi) {
        throw new RuntimeException('Candidate installation modified the direct-root public identity files.');
    }
    echo json_encode(['ok'=>true] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Candidate installation failed: ' . $e->getMessage() . "\n");
    exit(1);
}
