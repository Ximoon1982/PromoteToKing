<?php
declare(strict_types=1);
require dirname(__DIR__).'/server/team-points/src/OAuthRateCoordinator.php';
use P2K\TeamPoints\OAuthRateCoordinator;
function verify(bool $condition,string $why):void {if(!$condition)throw new RuntimeException($why);}
$dir=sys_get_temp_dir().'/p2k-oauth-rate-test-'.bin2hex(random_bytes(6));
mkdir($dir,0700);putenv('P2K_OAUTH_RATE_STATE_DIR='.$dir);
$now=10000.0;$clock=static function()use(&$now):float{return $now;};
function clean(OAuthRateCoordinator $c,int $n,float $attempt,float $launched=0.0):array{return $c->feedback(array_fill(0,$n,200),array_fill(0,$n,200.0),'player-profile',$attempt,0,0.0,$launched);}
try{
    // Migration rejects previously inferred 120/s safety while retaining a real cooldown.
    $legacy=$dir.'/'.hash('sha256','p2k-oauth-rate-v2:legacy').'.json';
    file_put_contents($legacy,json_encode(['version'=>2,'updated_at'=>$now,'target_rate_cps'=>120,'safe_rate_cps'=>110,'unsafe_rate_cps'=>120,'cooldown_until'=>$now+9,'next_launch_at'=>$now+1,'backlashes'=>3]));
    $l=new OAuthRateCoordinator('legacy',$clock);$s=$l->snapshot();
    verify($s['rate_target_cps']===30.0&&$s['safe_rate_cps']===0.0&&$s['backlashes']===3&&$s['cooldown_remaining_ms']>=9000,'Legacy migration did not preserve safety');
    verify(!$l->reserveLaunch()['reserved'],'Migrated cooldown was bypassed');
    $now+=10;
    // Reservations are not admissions, even if they look like a high-rate stream.
    $c=new OAuthRateCoordinator('reservations',$clock);
    for($i=0;$i<180;$i++){$now+=0.034;verify($c->reserveLaunch()['reserved'],'Expected launch permit');if($i%8===7)clean($c,8,120);}
    $s=$c->snapshot();verify($s['launches']===0&&$s['reservations']===180&&$s['observed_launch_cps']===0.0&&$s['safe_rate_cps']===0.0&&$s['rate_target_cps']===30.0,'Reservations falsely learned a high rate');
    // Actual, demand-limited traffic must not learn a higher target.
    $a=new OAuthRateCoordinator('actual',$clock);$b=new OAuthRateCoordinator('actual',$clock);
    for($i=0;$i<96;$i++){$now+=1/12;verify($a->reserveLaunch()['reserved'],'Demand-limited admission failed');$a->recordLaunch();if($i%8===7)clean($b,8,120,$now-0.7);}
    $s=$a->snapshot();verify($s['launches']===96&&$s['observed_launch_cps']>=11&&$s['observed_launch_cps']<=12.2&&$s['safe_rate_cps']<=12.2&&$s['rate_target_cps']===30.0,'Under-fed workload inflated safety/target');
    // A few short bursts cannot prove a sustained rate even with clean responses.
    $now+=6;$before=$a->snapshot()['safe_rate_cps'];for($i=0;$i<6;$i++){$now+=0.035;verify($a->reserveLaunch()['reserved'],'Burst admission failed');$a->recordLaunch();}clean($a,6,120);verify($a->snapshot()['safe_rate_cps']===$before,'Short burst inflated safe rate');
    // Healthy, sustained admissions and matching clean outcomes permit gradual probing.
    $g=new OAuthRateCoordinator('growth',$clock);$growth=0;$last=30.0;$lastChange=0.0;
    for($i=0;$i<420;$i++){$now+=0.034;verify($g->reserveLaunch()['reserved'],'Sustained admission failed');$g->recordLaunch();if($i%8===7){$s=clean($g,8,30,$now-0.25);if($s['rate_target_cps']>$last){verify($lastChange===0.0||$now-$lastChange>=5,'Growth was not time-gated');verify($s['rate_target_cps']-$last<=3.01,'Growth was too aggressive');$last=$s['rate_target_cps'];$lastChange=$now;$growth++;}}}
    verify($growth>=1&&$last>=33&&$last<=39,'Healthy sustained traffic could not probe gradually');
    $s=$g->snapshot();$observed=$s['observed_launch_cps'];verify($observed>25&&$observed<32,'Actual window invalid');
    // A 429 uses measured admission evidence, not the attempted 120/s value.
    $sent=$now-0.2;$s=$g->feedback([429],[200.0],'player-profile',120,120,$observed,$sent);
    verify($s['rate_target_cps']<=$observed*0.70+0.001&&$s['safe_rate_cps']===0.0&&$s['unsafe_rate_cps']<32&&$s['unsafe_evidence']==='observed-admission'&&$s['cooldown_remaining_ms']>=120000,'429 boundary/backoff incorrect');
    $target=$s['rate_target_cps'];$backlashes=$s['backlashes'];
    verify(!$g->reserveLaunch()['reserved'],'Cooldown failed');$now+=1;$s=$g->feedback([429],[200.0],'player-profile',120,5,$observed,$sent);verify($s['rate_target_cps']===$target&&$s['backlashes']===$backlashes,'Same episode compounded');
    $now+=121;$s=$g->feedback([429],[200.0],'player-profile',120,2,$observed,$sent);verify($s['rate_target_cps']===$target&&$s['backlashes']===$backlashes,'Late response compounded');
    $now+=3;$s=clean($g,8,120,$sent);verify($s['safe_rate_cps']===0.0&&$s['rate_target_cps']===$target,'Pre-backoff responses polluted evidence');
    // Second independent episode retreats again. Long cooldown survives idle state TTL.
    $s=$g->feedback([429],[200.0],'player-profile',$target,4000,0.0,$now);verify($s['backlashes']===$backlashes+1&&$s['rate_target_cps']<$target,'New episode did not back off');$backedOff=$s['rate_target_cps'];$now+=2000;verify(!$g->reserveLaunch()['reserved'],'Long cooldown lost across idle TTL');verify($g->snapshot()['rate_target_cps']===$backedOff,'Backoff target lost during long cooldown');
    // Requested ceiling controls shared pacing; foreground suppresses background without changing identity.
    $q=new OAuthRateCoordinator('ceiling',$clock);$first=$q->reserveLaunch('foreground',5);verify($first['reserved']&&$first['rate_target_cps']===5.0,'Requested ceiling ignored');$q->recordLaunch();
    $now+=0.05;verify(!$q->reserveLaunch('foreground',5)['reserved'],'Requested ceiling not enforced');verify(!$q->reserveLaunch('background')['reserved'],'Foreground priority lost');$now+=0.3;verify($q->reserveLaunch('foreground',5)['reserved'],'Requested cap failed to reopen');
    $iso=new OAuthRateCoordinator('other-token',$clock);verify($iso->reserveLaunch()['reserved'],'Unrelated token inherited cooldown');
    echo "PASS actual admissions, fixed-window evidence, migration, gradual probing, observed 429 backoff, late-event coalescing, long cooldown, ceiling and token isolation\n";
}finally{foreach(glob($dir.'/*')?:[] as $f)@unlink($f);@rmdir($dir);}
