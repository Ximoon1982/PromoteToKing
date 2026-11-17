<?php
declare(strict_types=1);
require_once __DIR__.'/../TGP/Widget/src/StandaloneArenaDiscoveryService.php';

use P2K\EventsShowcase\StandaloneArenaDiscoveryService as Resolver;

function verifyClubRef(bool $value, string $why): void
{
    if (!$value) throw new RuntimeException($why);
}
function rejectClubRef(callable $call, string $why): void
{
    try { $call(); } catch (InvalidArgumentException | RuntimeException $e) { return; }
    throw new RuntimeException('Expected rejection: '.$why);
}
foreach ([
    'the-golden-phoenix'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    'THE-GOLDEN-PHOENIX'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    'https://www.chess.com/club/the-golden-phoenix'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    'https://chess.com/club/the-golden-phoenix/'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    'www.chess.com/club/the-golden-phoenix'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    'https://api.chess.com/pub/club/the-golden-phoenix'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    'http://www.chess.com/club/the-golden-phoenix?x=1'=>['kind'=>'slug','value'=>'the-golden-phoenix'],
    '816742'=>['kind'=>'id','value'=>'816742'],
    'https://www.chess.com/club/816742'=>['kind'=>'id','value'=>'816742']
] as $input=>$wanted) {
    verifyClubRef(Resolver::parseClubReference((string)$input)===$wanted, 'Parsing rejected valid club reference: '.$input);
}
foreach ([
    '', '0','-88','../../etc/passwd','https://evil.example/club/my-club',
    'https://www.chess.com.evil.example/club/test',
    'https://user:password@www.chess.com/club/test',
    'https://www.chess.com:444/club/test',
    'https://www.chess.com/club/test/extra',
    'https://api.chess.com/pub/player/someone',
    'javascript:alert(1)','https://www.chess.com/club/foo%2fbar',
    'https://www.chess.com@evil.example/club/test'
] as $input) rejectClubRef(fn()=>Resolver::parseClubReference($input), 'Unsafe club reference must be rejected: '.$input);

$profile=[
    '@id'=>'https://api.chess.com/pub/club/the-golden-phoenix',
    'name'=>'The Golden Phoenix',
    'club_id'=>816742
];
$expected=['clubId'=>'816742','name'=>'The Golden Phoenix','slug'=>'the-golden-phoenix',
    'url'=>'https://www.chess.com/club/the-golden-phoenix','enabled'=>true];
verifyClubRef(Resolver::normalizeClubProfile($profile,'the-golden-phoenix')===$expected,'Profile must resolve authoritative name and numeric ID.');
verifyClubRef(Resolver::normalizeClubProfile($profile,'','816742')===$expected,'Numeric lookup must verify original ID.');
rejectClubRef(fn()=>Resolver::normalizeClubProfile($profile,'','816743'), 'Numeric ID conflict must not add wrong club.');
rejectClubRef(fn()=>Resolver::normalizeClubProfile(['name'=>'No ID'], 'test'), 'Incomplete Chess.com club profile must not be saved.');

$headers="HTTP/1.1 302 Found\r\nLocation: https://www.chess.com/club/the-golden-phoenix\r\n\r\n";
verifyClubRef(Resolver::canonicalClubSlug($headers,'')==='the-golden-phoenix',
    'Official club redirect must resolve slug.');
verifyClubRef(Resolver::canonicalClubSlug('', '<html><head><link rel="canonical" href="https://www.chess.com/club/team-france"></head></html>')==='team-france',
    'Canonical link must resolve slug.');
verifyClubRef(Resolver::canonicalClubSlug('', '<a href="https://www.chess.com/club/other">Unrelated club</a>')==='',
    'Unrelated HTML links must not be used to resolve club ID.');
verifyClubRef(Resolver::canonicalClubSlug("Location: https://evil.example/club/surprise\r\n",'')==='',
    'Cross-domain redirects must not be trusted.');

verifyClubRef(
    file_get_contents(__DIR__.'/../TGP/Widget/src/StandaloneArenaDiscoveryService.php') ===
    file_get_contents(__DIR__.'/../TeamFrance/Widget/src/StandaloneArenaDiscoveryService.php'),
    'Both physical widget services must carry the same audited lookup.');
foreach(['TGP','TeamFrance'] as $club) {
    $api=(string)file_get_contents(__DIR__.'/../'.$club.'/Widget/api.php');
    $html=(string)file_get_contents(__DIR__.'/../'.$club.'/Widget/admin/index.php');
    $js=(string)file_get_contents(__DIR__.'/../'.$club.'/Widget/assets/arena-discovery.js');
    $service=(string)file_get_contents(__DIR__.'/../'.$club.'/Widget/src/StandaloneArenaDiscoveryService.php');
    verifyClubRef(str_contains($api,"if (\$action === 'arena-discovery-resolve')"),$club.' resolution API missing.');
    verifyClubRef(str_contains($api,'Auth::requireAdmin($sessionLifetime, true)'),$club.' CSRF/admin gate missing.');
    verifyClubRef(str_contains($html,'id="arenaDiscoveryClubReference"'),$club.' single club reference field missing.');
    verifyClubRef(!str_contains($html,'id="arenaDiscoveryClubName"')&&!str_contains($html,'id="arenaDiscoveryClubId"'),
        $club.' obsolete ID/name inputs still visible.');
    verifyClubRef(str_contains($js,'request("resolve",{reference})'),$club.' reference resolution request missing.');
    verifyClubRef(str_contains($js,'clubId:String(organizer.clubId)')&&str_contains($js,'name:String(organizer.name)'),
        $club.' verified profile must be saved with legacy compatible watchlist shape.');
    verifyClubRef(str_contains($service,'public static function parseClubReference') &&
        str_contains($service,'public static function normalizeClubProfile'),$club.' API verifier missing.');
}
echo "PASS slug, Chess.com URL, numeric ID, canonical redirect/profile verification, safe origin, admin CSRF, legacy watchlist preservation.\n";
