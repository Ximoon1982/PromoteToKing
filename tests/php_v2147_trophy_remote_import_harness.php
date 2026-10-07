<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/trophy-gallery/src/TrophyRemoteArtworkImporter.php';
require_once dirname(__DIR__) . '/server/trophy-gallery/src/TrophyGalleryStore.php';

use P2K\TrophyGallery\TrophyGalleryStore;
use P2K\TrophyGallery\TrophyRemoteArtworkFetcher;
use P2K\TrophyGallery\TrophyRemoteArtworkImporter;

function fail2147t(string $message): never { throw new RuntimeException($message); }

final class FakeTrophyRemoteFetcher implements TrophyRemoteArtworkFetcher {
    public int $calls = 0;
    public function fetch(string $url): array {
        $this->calls++;
        $body = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        if (!is_string($body)) fail2147t('fixture decode failed');
        $image = TrophyRemoteArtworkImporter::validateImageBytes($body);
        return $image + ['source_url'=>$url,'final_url'=>$url];
    }
}

$tmp = sys_get_temp_dir() . '/p2k-v2147-trophy-url-' . bin2hex(random_bytes(5));
$fetcher = new FakeTrophyRemoteFetcher();
$store = new TrophyGalleryStore($tmp, $fetcher);

$saved = $store->save([
    'id'=>'remote-url-test',
    'status'=>'published',
    'league'=>'Test League',
    'competition'=>'Remote artwork',
    'title'=>'Remote import',
    'vignette_url'=>'https://images.example.test/trophy.png',
], null);
$revision = (int)$saved['revision'];

$first = $store->importUrlAndAssign(
    'https://images.example.test/trophy.png',
    'remote-url-test',
    'vignette',
    $revision
);
if (empty($first['value']['imported'])) fail2147t('first remote import was not persisted');
if ($fetcher->calls !== 1) fail2147t('remote fetch count after first import is wrong');
$revision = (int)$first['revision'];
$record = $first['value']['record'];
$mediaId = (string)($record['vignette_media_id'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/D', $mediaId)) fail2147t('managed media id missing after import');
[$media, $path] = $store->media($mediaId);
if (!is_file($path) || ($media['mime'] ?? '') !== 'image/png') fail2147t('managed image file missing after import');

$again = $store->importUrlAndAssign(
    'https://images.example.test/trophy.png',
    'remote-url-test',
    'vignette',
    $revision
);
if (!empty($again['value']['imported'])) fail2147t('same URL should reuse healthy managed media');
if ($fetcher->calls !== 1) fail2147t('same URL unexpectedly fetched twice');

$changed = $store->importUrlAndAssign(
    'https://images.example.test/trophy-v2.png',
    'remote-url-test',
    'vignette',
    (int)$again['revision']
);
if (empty($changed['value']['imported']) || $fetcher->calls !== 2) fail2147t('changed URL was not re-imported');
$newMediaId = (string)($changed['value']['record']['vignette_media_id'] ?? '');
if ($newMediaId === $mediaId) fail2147t('changed URL did not replace managed media');
if (is_file($path)) fail2147t('superseded managed artwork was not removed');

$catalog = json_decode((string)file_get_contents($tmp . '/catalog.json'), true);
$storedMedia = $catalog['media'][$newMediaId] ?? null;
if (!is_array($storedMedia) || ($storedMedia['source'] ?? '') !== 'external_url') fail2147t('remote provenance source missing');
if (($storedMedia['source_url'] ?? '') !== 'https://images.example.test/trophy-v2.png') fail2147t('remote provenance URL missing');

$policy = new TrophyRemoteArtworkImporter();
try {
    $policy->inspectRemoteUrl('http://127.0.0.1/private.png');
    fail2147t('localhost URL unexpectedly accepted');
} catch (RuntimeException $expected) {}
try {
    $policy->inspectRemoteUrl('http://169.254.169.254/latest/meta-data');
    fail2147t('link-local URL unexpectedly accepted');
} catch (RuntimeException $expected) {}
$public = $policy->inspectRemoteUrl('https://93.184.216.34/trophy.png');
if (($public['ip'] ?? '') !== '93.184.216.34') fail2147t('public IP URL validation failed');

try {
    TrophyRemoteArtworkImporter::validateImageBytes('not an image');
    fail2147t('invalid image bytes unexpectedly accepted');
} catch (RuntimeException $expected) {}

echo "v2.14.7 Trophy external URL import harness passed\n";
