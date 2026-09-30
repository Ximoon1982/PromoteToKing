<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/ReleaseSlotPolicy.php';
require_once dirname(__DIR__) . '/src/ReleaseSlotStore.php';

use P2K\ReleaseControl\ReleaseSlotStore;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['root:', 'runtime::', 'release-id:']);
$root = rtrim((string)($options['root'] ?? ''), '/\\');
$runtime = trim((string)($options['runtime'] ?? ''));
$releaseId = trim((string)($options['release-id'] ?? ''));
if ($root === '' || $releaseId === '') {
    fwrite(STDERR, "Usage: php verify-slot.php --root=/path --release-id=x.y.z-abcdef123456 [--runtime=/path]\n");
    exit(2);
}

$store = new ReleaseSlotStore($root, $runtime !== '' ? $runtime : null);
$result = $store->inspectSlot($releaseId, true);
echo json_encode(['ok'=>($result['integrity_status'] ?? '') === 'valid', 'slot'=>$result], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
exit(($result['integrity_status'] ?? '') === 'valid' ? 0 : 1);
