<?php
declare(strict_types=1);
require_once __DIR__ . '/../TGP/Widget/src/Store.php';
require_once __DIR__ . '/../TeamFrance/Widget/src/Store.php';

function verify(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function expectedError(callable $fn, string $message): void {
    try { $fn(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException($message);
}
foreach (['TGP\\Widget\\Store', 'TeamFrance\\Widget\\Store'] as $class) {
    $root = sys_get_temp_dir() . '/widget-arena-test-' . bin2hex(random_bytes(6));
    mkdir($root);
    try {
        $store = new $class($root);
        $start = (new DateTimeImmutable('+2 days', new DateTimeZone('Europe/Luxembourg')))->setTime(18, 30);
        $arena = [
            'active' => true, 'name' => 'WCGC multi-club arena',
            'link' => 'https://www.chess.com/tournament/live/arena/test-event',
            'date' => $start->format('Y-m-d'), 'time' => $start->format('H:i'),
            'duration' => '1h30m', 'timeControl' => '3+2',
        ];
        $initial = $store->read();
        verify($initial['schemaVersion'] === 2 && $initial['arenas'] === [], "$class initial schema");
        $saved = $store->save(['items'=>[['matchId'=>'12345','enabled'=>true,'urgent'=>false]],'arenas'=>[$arena]], 0);
        verify($saved['revision'] === 1, "$class revision");
        verify(count($saved['arenas']) === 1 && $saved['arenas'][0]['durationMinutes'] === 90, "$class arena persisted");
        verify($store->read()['items'][0]['matchId'] === '12345', "$class Daily cards preserved");
        $stale = false;
        try { $store->save(['items'=>[], 'arenas'=>[]], 0); }
        catch (\TGP\Widget\RevisionConflict | \TeamFrance\Widget\RevisionConflict $e) { $stale = true; }
        verify($stale, "$class revision race");
        $bad = $arena; $bad['link'] = 'https://evil.example/arena';
        expectedError(fn() => $store->save(['items'=>[], 'arenas'=>[$bad]], 1), "$class reject external URL");
        $expired = $arena; $expired['date'] = '2020-01-01';
        $saved2 = $store->save(['items'=>[['matchId'=>'12345']], 'arenas'=>[$expired]], 1);
        verify(count($saved2['arenas']) === 0, "$class expire past arena");
        verify(count($saved2['items']) === 1, "$class preserve Daily matches");
    } catch (\TGP\Widget\RevisionConflict | \TeamFrance\Widget\RevisionConflict $e) {
        // Expected only for the explicit stale revision scenario, which is verified below.
        throw new RuntimeException("$class unexpected revision conflict");
    } finally {
        foreach (glob($root . '/data/*') ?: [] as $file) if (is_file($file)) unlink($file);
        @rmdir($root . '/data'); @rmdir($root);
    }
}
echo "Both widgets: arena storage and Daily state checks passed.\n";
