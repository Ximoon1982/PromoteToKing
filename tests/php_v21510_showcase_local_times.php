<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/events-showcase/src/EventsShowcaseStore.php';

use P2K\EventsShowcase\EventsShowcaseStore;

$root = sys_get_temp_dir() . '/p2k-showcase-dst-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($root . '/data', 0700, true)) throw new RuntimeException('Cannot create test directory');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$arena = static function (string $id, string $instant): array {
    return ['active' => true, 'name' => 'DST arena ' . $id,
        'link' => 'https://www.chess.com/play/arena/' . $id,
        'date' => '2027-10-31', 'time' => '02:30',
        'timeControl' => '3+2', 'duration' => '2h',
        'startAt' => $instant];
};
try {
    $store = new EventsShowcaseStore($root);
    // The Europe/Luxembourg 02:30 wall time occurs twice during this autumn transition.
    $first = '2027-10-31T00:30:00Z';
    $second = '2027-10-31T01:30:00Z';
    $state = $store->save(['items' => [], 'arenas' => [
        $arena('92111101', $first), $arena('92111102', $second)]], 0);
    $assert(count($state['arenas']) === 2, 'Both DST fold events must be saved');
    $byId = static function (array $arenas, string $id): array {
        foreach ($arenas as $a) if (str_ends_with((string)$a['link'], '/' . $id)) return $a;
        throw new RuntimeException('Missing arena ' . $id);
    };
    foreach (['92111101' => $first, '92111102' => $second] as $id => $expected) {
        $actual = $byId($state['arenas'], (string)$id);
        $assert(strtotime($actual['startAt']) === strtotime($expected),
            'Explicit startAt changed at DST fold: ' . $id);
    }
    $roundtrip = $store->read();
    $roundtrip['arenas'][0]['name'] = 'Edited without changing the start';
    $saved = $store->save(['items' => [], 'arenas' => $roundtrip['arenas']],
        (int)$roundtrip['revision']);
    foreach (['92111101' => $first, '92111102' => $second] as $id => $expected) {
        $actual = $byId($saved['arenas'], (string)$id);
        $assert(strtotime($actual['startAt']) === strtotime($expected),
            'Unrelated edit changed an arena start: ' . $id);
    }
    // Old clients that edit Luxembourg wall fields but retain an old startAt still work:
    // a mismatched explicit instant falls back to the deliberately changed wall fields.
    $changed = $saved['arenas'];
    foreach ($changed as &$item) if (str_ends_with($item['link'], '/92111102')) $item['time'] = '03:30';
    unset($item);
    $updated = $store->save(['items' => [], 'arenas' => $changed], (int)$saved['revision']);
    $after = $byId($updated['arenas'], '92111102');
    $assert(strtotime($after['startAt']) === strtotime('2027-10-31T02:30:00Z'),
        'Legacy Luxembourg wall-time edit failed');
    echo "PASS Showcase UTC instant persistence, DST fold distinction, read/edit/save stability and legacy compatibility\n";
} finally {
    foreach (glob($root . '/data/*') ?: [] as $path) @unlink($path);
    @rmdir($root . '/data'); @rmdir($root);
}
