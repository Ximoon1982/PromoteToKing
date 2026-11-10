<?php
declare(strict_types=1);
$base=dirname(__DIR__).'/TeamFrance';
require $base.'/MatchAnalyzer/MatchPolicy.php';
require $base.'/Widget/src/Store.php';
use TeamFrance\Widget\Store;
function ensure(bool $ok, string $name): void {if (!$ok) throw new RuntimeException($name);}
$fr=['@id'=>'https://api.chess.com/pub/club/team-france'];
$tgp=['@id'=>'https://api.chess.com/pub/club/the-golden-phoenix'];
$other=['@id'=>'https://api.chess.com/pub/club/some-other-club'];
ensure(TeamFranceMatchPolicy::containsClub(['teams'=>[$fr,$other]]),'Team France home team allowed');
ensure(TeamFranceMatchPolicy::containsClub(['teams'=>[$other,$fr]]),'Team France opponent allowed');
ensure(!TeamFranceMatchPolicy::containsClub(['teams'=>[$tgp,$other]]),'TGP-only match rejected');
ensure(!TeamFranceMatchPolicy::containsClub(['teams'=>[$fr,$other,$tgp]]),'Three-team match rejected');
foreach (['http://api.chess.com/pub/club/team-france','https://api.chess.com/pub/club/team-france.evil','https://evil.example/pub/club/team-france','https://api.chess.com:443/pub/club/team-france','https://api.chess.com/pub/club/the-golden-phoenix'] as $bad) ensure(!TeamFranceMatchPolicy::isClubReference($bad),'Rejected spoofed URL '.$bad);
$dir=sys_get_temp_dir().'/team-france-widget-test-'.bin2hex(random_bytes(4));mkdir($dir.'/data',0775,true);
try {
 $store=new Store($dir,100);
 ensure($store->read()['items']===[],'Fresh Team France configuration');
 $st=$store->save(['items'=>[['matchId'=>'123','enabled'=>true,'urgent'=>true]]],0);
 ensure($st['revision']===1,'State revision');
 ensure($store->read()['items'][0]['matchId']==='123','State persisted');
} finally {foreach (glob($dir.'/data/*') as $f) unlink($f);rmdir($dir.'/data');rmdir($dir);}
echo "Team France club policy and isolated widget state: PASS\n";
