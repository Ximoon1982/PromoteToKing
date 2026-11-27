<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/** Shared, token-scoped launch clock and evidence-based adaptive rate control. */
final class OAuthRateCoordinator
{
    private const STATE_VERSION = 3;
    private const INITIAL_RATE = 24.0;
    private const MIN_RATE = 1.0;
    private const MAX_RATE = 30.0;
    private const UNSAFE_BOUNDARY_FACTOR = 0.90;
    private const POLICY_REVISION = 1;
    private const STATE_TTL_SECONDS = 1800.0;
    private const FOREGROUND_HOLD_SECONDS = 1.5;
    private const WINDOW_SECONDS = 5.0;
    private const MIN_SPAN_SECONDS = 3.5;
    private const ADJUST_INTERVAL_SECONDS = 5.0;
    private const BOUNDARY_TTL_SECONDS = 900.0;
    private const LATENCY_MODEL_REVISION = 2;
    private const RECOVERY_HOLD_SECONDS = 60.0;
    private array $io = ['calls'=>0,'writes'=>0,'no_write'=>0,'lock_wait_ms'=>0.0,'write_ms'=>0.0];
    private const LATENCY_HISTORY_LIMIT = 21;
    private const LATENCY_MIN_SAMPLES = 7;
    private string $path;
    private ?\Closure $clock;

    public function __construct(string $token, ?callable $clock = null)
    {
        $this->clock = $clock === null ? null : \Closure::fromCallable($clock);
        $dir = trim((string)(getenv('P2K_OAUTH_RATE_STATE_DIR') ?: ''));
        if ($dir === '') $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'p2k-oauth-rate';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('OAuth rate coordinator storage unavailable.');
        @chmod($dir, 0700);
        // Keep the existing token-specific path so promotion preserves active cooldowns.
        $this->path = $dir . DIRECTORY_SEPARATOR . hash('sha256', 'p2k-oauth-rate-v2:' . $token) . '.json';
    }

    private function now(): float { return $this->clock === null ? microtime(true) : (float)($this->clock)(); }
    private function clampRate(float $rate): float { return max(self::MIN_RATE, min(self::MAX_RATE, is_finite($rate) ? $rate : self::INITIAL_RATE)); }
    public static function policy(): array
    {
        return ['revision'=>self::POLICY_REVISION,'initial_rate_cps'=>self::INITIAL_RATE,'maximum_rate_cps'=>self::MAX_RATE,'minimum_rate_cps'=>self::MIN_RATE,'unsafe_boundary_factor'=>self::UNSAFE_BOUNDARY_FACTOR,'recovery_hold_seconds'=>self::RECOVERY_HOLD_SECONDS];
    }

    /** Optional caller ceiling cannot raise the server-owned policy. */
    public static function callerCeiling(float $rate): float
    {
        return is_finite($rate) && $rate>0 ? max(0.5,min(self::MAX_RATE,$rate)) : self::MAX_RATE;
    }

    private function effectiveCeiling(float $unsafe): float
    {
        return $unsafe>0 ? max(self::MIN_RATE,min(self::MAX_RATE,$unsafe*self::UNSAFE_BOUNDARY_FACTOR)) : self::MAX_RATE;
    }

    /** Normalize under the existing token lock, including on read-only snapshots. */
    private function normalizePolicy(array &$s,float $now): bool
    {
        $before=$s;
        if((float)$s['unsafe_rate_cps']>0 && $now >= (float)$s['unsafe_until']){
            $s['unsafe_rate_cps']=0.0;$s['unsafe_until']=0.0;$s['unsafe_evidence']='';
        }
        $oldTarget=(float)$s['target_rate_cps'];
        $ceiling=$this->effectiveCeiling((float)$s['unsafe_rate_cps']);
        $s['target_rate_cps']=min($this->clampRate($oldTarget),$ceiling);
        $s['safe_rate_cps']=max(0.0,min($ceiling,(float)$s['safe_rate_cps']));
        // Never shorten an existing permit deadline. If the policy lowered a
        // previously paced target, conservatively lengthen its last spacing.
        if(is_finite($oldTarget)&&$oldTarget>$s['target_rate_cps']&&(float)$s['next_launch_at']>0){
            $s['next_launch_at']=max((float)$s['next_launch_at'],(float)$s['next_launch_at']-1.0/$oldTarget+1.0/(float)$s['target_rate_cps']);
        }
        $s['policy_revision']=self::POLICY_REVISION;
        // JSON can decode integral floats as ints; compare numerically to avoid
        // writing on every unsuccessful reservation or unchanged snapshot.
        return $before!=$s;
    }

    private function trafficClass(string $value): string { return strtolower(trim($value)) === 'background' ? 'background' : 'foreground'; }

    public function announceTraffic(string $trafficClass): void
    {
        if ($this->trafficClass($trafficClass) !== 'foreground') return;
        $this->mutate(static function (array &$s, float $now): void { $s['foreground_until'] = max((float)$s['foreground_until'], $now + self::FOREGROUND_HOLD_SECONDS); });
    }

    /** Reserve only a currently eligible launch; a future slot is NOT a reservation. */
    public function reserveLaunch(string $trafficClass = 'foreground', float $requestedRateCps = 0.0): array
    {
        $trafficClass = $this->trafficClass($trafficClass);
        $requestedRateCps = self::callerCeiling($requestedRateCps);
        $r = $this->mutate(function (array &$s, float $now) use ($trafficClass, $requestedRateCps): array {
            $rate = min((float)$s['target_rate_cps'], $requestedRateCps);
            $slot = max($now, (float)$s['next_launch_at'], (float)$s['cooldown_until']);
            if ($trafficClass === 'background') {
                if ((float)$s['foreground_until'] > $slot) $s['background_suppressions']++;
                $slot = max($slot, (float)$s['foreground_until']);
            } else if ((float)$s['foreground_until'] < $now + self::FOREGROUND_HOLD_SECONDS/2) $s['foreground_until'] = $now + self::FOREGROUND_HOLD_SECONDS;
            $reserved = $slot <= $now;
            if ($reserved) {
                if ($trafficClass === 'foreground') $s['foreground_until'] = $now + self::FOREGROUND_HOLD_SECONDS;
                $s['next_launch_at'] = $now + 1.0 / $rate;
                $s['reservations']++;
            }
            return ['slot'=>$slot,'reserved'=>$reserved,'rate_target_cps'=>$rate,'safe_rate_cps'=>(float)$s['safe_rate_cps'],'unsafe_rate_cps'=>(float)$s['unsafe_rate_cps'],'cooldown_remaining_ms'=>max(0,(int)ceil(((float)$s['cooldown_until']-$now)*1000)),'foreground_wait_ms'=>$trafficClass==='background'?max(0,(int)ceil(((float)$s['foreground_until']-$now)*1000)):0];
        }, true);
        $r['wait_ms'] = max(0.0, ((float)$r['slot'] - $this->now()) * 1000.0);
        return $r;
    }

    /** Count a real cURL admission only AFTER curl_multi_add_handle succeeds. */
    public function recordLaunch(): float
    {
        return $this->mutate(function (array &$s, float $now): float {
            $s['launches']++;
            $s['recent_actual_launches'][] = $now;
            $this->trimEvidence($s, $now);
            return $this->observedRate($s['recent_actual_launches'], $now);
        });
    }

    public function waitForLaunch(string $trafficClass = 'foreground'): array
    {
        do {
            $reservation = $this->reserveLaunch($trafficClass);
            if (!empty($reservation['reserved'])) break;
            $delay = max(0.0, (float)$reservation['slot'] - $this->now());
            if ($delay > 0) usleep((int)min(5000000, max(1, ceil($delay * 1000000))));
        } while (true);
        // This compatibility API returns a dispatch permit, not a confirmed cURL admission.
        unset($reservation['slot']);
        return $reservation;
    }

    public function feedback(array $statuses, array $latenciesMs, string $endpointClass, float $attemptedRateCps, int $retryAfterSeconds = 0, float $observedAtLaunch = 0.0, float $requestLaunchedAt = 0.0): array
    {
        $endpointClass = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($endpointClass))) ?: 'other';
        $latencies=[];$successLatencies=[];$rate429=0;$transient=0;$permanent4xx=0;$success=0;$authFailures=0;
        foreach ($statuses as $i=>$raw) {
            $status=(int)$raw;$latency=max(0.0,(float)($latenciesMs[$i]??0));if($latency>0)$latencies[]=$latency;
            if($status===429)$rate429++;
            elseif($status===0||$status===408||$status>=500)$transient++;
            elseif($status===401||$status===403){$authFailures++;$permanent4xx++;}
            elseif($status>=400)$permanent4xx++;
            elseif($status>=200){$success++;if($latency>0)$successLatencies[]=$latency;}
        }
        $latencies=$successLatencies!==[]?$successLatencies:$latencies;sort($latencies,SORT_NUMERIC);
        $median=$this->percentile($latencies,0.5);$p95=$this->percentile($latencies,0.95);
        $attempted=$this->clampRate($attemptedRateCps>0?$attemptedRateCps:self::INITIAL_RATE);
        return $this->mutate(function(array &$s,float $now)use($endpointClass,$median,$p95,$attempted,$rate429,$transient,$permanent4xx,$success,$authFailures,$retryAfterSeconds,$observedAtLaunch,$requestLaunchedAt,$successLatencies):array{
            $current=$this->clampRate((float)$s['target_rate_cps']);$safe=(float)$s['safe_rate_cps'];$unsafe=(float)$s['unsafe_rate_cps'];
            if($unsafe>0 && $now >= (float)$s['unsafe_until']){$unsafe=0.0;$s['unsafe_evidence']='';}
            $observed=$this->observedRate($s['recent_actual_launches'],$now);
            $baseline=(float)($s['latency_baseline_ms'][$endpointClass]??0);
            // Compare representative clean batches with a bounded rolling median,
            // not the minimum-ever latency. Ignore tiny and pre-backoff samples.
            $pressure=0.0;$pressureConfirmed=false;
            $eligible=$endpointClass!=='mixed'&&$success>=4&&count($successLatencies)>=4&&$rate429===0&&$transient===0&&$authFailures===0&&$median>0&&(float)$s['cooldown_until']<=$now&&($requestLaunchedAt<=0||$requestLaunchedAt>(float)$s['last_throttle_at']);
            if($eligible){
                $history=$s['latency_history_ms'][$endpointClass]??[];
                $ordered=$history;sort($ordered,SORT_NUMERIC);
                $baseline=$this->percentile($ordered,0.5);
                $pressure=count($history)>=self::LATENCY_MIN_SAMPLES&&$baseline>0?max(0.0,$median/$baseline-1.0):0.0;
                $streak=$pressure>1.0?(int)($s['latency_pressure_streak'][$endpointClass]??0)+1:0;
                $s['latency_pressure_streak'][$endpointClass]=$streak;
                $pressureConfirmed=$streak>=3;
                $history[]=$median;$history=array_slice($history,-self::LATENCY_HISTORY_LIMIT);
                $s['latency_history_ms'][$endpointClass]=$history;
                $ordered=$history;sort($ordered,SORT_NUMERIC);
                $s['latency_baseline_ms'][$endpointClass]=round($this->percentile($ordered,0.5),3);
            }
            $total=max(1,$success+$permanent4xx+$rate429+$transient);
            $reason='steady';
            if($rate429>0){
                // A late response from a pre-backoff request belongs to that episode even after the timer expires.
                $sameEpisode=(float)$s['cooldown_until']>$now||($requestLaunchedAt>0&&(float)$s['last_throttle_at']>0&&$requestLaunchedAt<=(float)$s['last_throttle_at']);
                if(!$sameEpisode){
                    $evidence=$observedAtLaunch>0?$observedAtLaunch:$observed;
                    $boundary=$this->clampRate(min($current,$attempted,$evidence>0?$evidence:$current));
                    if($unsafe<=0||$boundary<$unsafe){$unsafe=$boundary;$s['unsafe_evidence']=$evidence>0?'observed-admission':'conservative-ceiling';}
                    $s['unsafe_until']=$now+self::BOUNDARY_TTL_SECONDS;
                    $s['target_rate_cps']=$this->clampRate(min($current*0.70,$boundary*0.70));
                    $safe=0.0;$s['backlashes']++;$s['last_throttle_at']=$now;$s['last_adjustment_at']=$now;
                    $s['clean_samples']=0;$s['recent_clean_completions']=[];$s['recent_actual_launches']=[];$s['latency_pressure_streak']=[];
                }
                // Honor the supplied Retry-After (OAuthSession bounds parsed values to one day).
                $s['cooldown_until']=max((float)$s['cooldown_until'],$now+max(2,$retryAfterSeconds));
                $s['recovery_hold_until']=max((float)$s['recovery_hold_until'],(float)$s['cooldown_until']+self::RECOVERY_HOLD_SECONDS);
                $reason=$sameEpisode?'rate-limit-coalesced':'rate-limit-boundary';
            }elseif($transient/$total>0.15){
                if($now-(float)$s['last_adjustment_at']>=self::ADJUST_INTERVAL_SECONDS){$s['target_rate_cps']=$this->clampRate($current*0.90);$s['last_adjustment_at']=$now;}
                $s['recent_clean_completions']=[];$s['clean_samples']=0;$reason='transient-pressure';
            }elseif($transient>0||$authFailures>0){
                // These samples are not proof of safe sustained throughput.
                $s['recent_clean_completions']=[];$s['clean_samples']=0;$reason='unclean-sample-hold';
            }elseif((float)$s['cooldown_until']>$now||($requestLaunchedAt>0&&$requestLaunchedAt<=(float)$s['last_throttle_at'])){
                $reason='cooldown-hold';
            }else{
                $clean=$success+$permanent4xx;
                for($i=0;$i<$clean;$i++)$s['recent_clean_completions'][]=$now;
                $this->trimEvidence($s,$now);
                $cleanRate=count($s['recent_clean_completions'])/self::WINDOW_SECONDS;
                $tested=min($attempted,$observed,$cleanRate);
                // No fallback to configured or attempted rate. A full fixed window includes idle gaps.
                if($observed>0&&$tested>0){
                    if($unsafe<=0||$tested<$unsafe*0.95)$safe=max($safe,$tested);
                    $s['clean_samples']++;
                }
                $ceiling=$this->effectiveCeiling($unsafe);
                $ready=$tested >= $current*0.80 && $s['clean_samples']>=2 && $now-(float)$s['last_adjustment_at']>=self::ADJUST_INTERVAL_SECONDS;
                if($ready&&$pressureConfirmed){
                    $s['target_rate_cps']=$this->clampRate($current*0.90);$s['last_adjustment_at']=$now;$s['clean_samples']=0;$s['latency_pressure_streak'][$endpointClass]=0;$reason='latency-pressure';
                }elseif($ready&&$now<(float)$s['recovery_hold_until']){
                    $reason='recovery-hold';
                }elseif($ready&&$pressure<=0.50&&$current<$ceiling-0.01){
                    $next=min($ceiling,$current+max(0.2,min(3.0,$current*0.10)));
                    $s['target_rate_cps']=$this->clampRate($next);$s['last_adjustment_at']=$now;$s['clean_samples']=0;
                    $reason=$unsafe>0?'boundary-converge':'clean-probe';
                }else $reason=$observed<=0?'insufficient-evidence':($ready?'boundary-or-latency-hold':'demand-limited-hold');
            }
            $safe=min($safe,$this->effectiveCeiling($unsafe));
            $s['safe_rate_cps']=round(max(0.0,$safe),4);$s['unsafe_rate_cps']=round(max(0.0,$unsafe),4);
            $this->normalizePolicy($s,$now);
            $s['last_endpoint_class']=$endpointClass;$s['last_median_ms']=round($median,3);$s['last_p95_ms']=round($p95,3);$s['last_reason']=$reason;$s['permanent_4xx_seen']+=$permanent4xx;
            return $this->publicState($s,$now,$reason);
        });
    }

    public function snapshot(): array { return $this->mutate(fn(array &$s,float $now):array=>$this->publicState($s,$now,'snapshot'),true); }

    public function diagnostics(): array { return $this->io; }

    private function defaultState(float $now): array
    {
        return ['version'=>self::STATE_VERSION,'policy_revision'=>self::POLICY_REVISION,'updated_at'=>$now,'target_rate_cps'=>self::INITIAL_RATE,'safe_rate_cps'=>0.0,'unsafe_rate_cps'=>0.0,'unsafe_until'=>0.0,'unsafe_evidence'=>'','next_launch_at'=>0.0,'cooldown_until'=>0.0,'foreground_until'=>0.0,'last_throttle_at'=>0.0,'recovery_hold_until'=>0.0,'last_adjustment_at'=>$now,'latency_baseline_ms'=>[],'latency_history_ms'=>[],'latency_pressure_streak'=>[],'latency_model_revision'=>self::LATENCY_MODEL_REVISION,'clean_samples'=>0,'backlashes'=>0,'launches'=>0,'reservations'=>0,'recent_actual_launches'=>[],'recent_clean_completions'=>[],'permanent_4xx_seen'=>0,'background_suppressions'=>0];
    }

    private function trimEvidence(array &$s,float $now): void
    {
        foreach(['recent_actual_launches','recent_clean_completions'] as $key){
            $s[$key]=array_values(array_filter($s[$key],static fn($v):bool=>(float)$v>$now-self::WINDOW_SECONDS&&(float)$v<=$now));
            if(count($s[$key])>1024)$s[$key]=array_slice($s[$key],-1024);
        }
    }

    private function observedRate(array $events,float $now): float
    {
        $events=array_values(array_filter($events,static fn($v):bool=>(float)$v>$now-self::WINDOW_SECONDS&&(float)$v<=$now));
        if(count($events)<4||max($events)-min($events)<self::MIN_SPAN_SECONDS)return 0.0;
        return count($events)/self::WINDOW_SECONDS;
    }

    private function mutate(callable $callback, bool $skipUnchanged = false): mixed
    {
        $handle=@fopen($this->path,'c+');
        if($handle===false)throw new \RuntimeException('OAuth rate coordinator unavailable.');
        @chmod($this->path,0600);
        try{
            $this->io['calls']++;$lockStarted=hrtime(true);
            if(!flock($handle,LOCK_EX))throw new \RuntimeException('OAuth rate coordinator lock unavailable.');
            $this->io['lock_wait_ms']+=(hrtime(true)-$lockStarted)/1e6;
            rewind($handle);$raw=stream_get_contents($handle);$old=is_string($raw)&&trim($raw)!==''?json_decode($raw,true):null;$now=$this->now();
            $s=$this->defaultState($now);
            if(is_array($old)){
                $fresh=$now-(float)($old['updated_at']??0)<=self::STATE_TTL_SECONDS||((float)($old['cooldown_until']??0)>0&&$now<=(float)$old['cooldown_until']+self::STATE_TTL_SECONDS);
                if($fresh&&(int)($old['version']??0)===self::STATE_VERSION){
                    $s=array_replace($s,$old);
                    // Migrate only latency evidence; retain measured rates and cooldown.
                    if((int)($old['latency_model_revision']??0)!==self::LATENCY_MODEL_REVISION){
                        $s['latency_baseline_ms']=[];$s['latency_history_ms']=[];$s['latency_pressure_streak']=[];
                        $s['latency_model_revision']=self::LATENCY_MODEL_REVISION;
                    }
                }
                elseif($fresh&&(int)($old['version']??0)===2){
                    // Legacy reservation-derived safe rates are not valid evidence. Preserve safety deadlines and conservative ceilings.
                    $s['target_rate_cps']=min(self::INITIAL_RATE,$this->clampRate((float)($old['target_rate_cps']??self::INITIAL_RATE)));
                    $s['backlashes']=max(0,(int)($old['backlashes']??0));$s['unsafe_rate_cps']=max(0.0,(float)($old['unsafe_rate_cps']??0));
                    if($s['unsafe_rate_cps']>0){$s['unsafe_until']=$now+self::BOUNDARY_TTL_SECONDS;$s['unsafe_evidence']='legacy-ceiling';$s['target_rate_cps']=min($s['target_rate_cps'],$this->effectiveCeiling((float)$s['unsafe_rate_cps']));}
                }
                // Even a long Retry-After survives state age or a v2-to-v3 migration.
                foreach(['cooldown_until','next_launch_at','foreground_until','recovery_hold_until'] as $key)$s[$key]=max((float)$s[$key],(float)($old[$key]??0));
            }elseif(is_string($raw)&&trim($raw)!=='')throw new \RuntimeException('OAuth rate coordinator state is invalid.');
            // Preserve a recent legacy throttle's hold without resetting learned state.
            if((float)$s['last_throttle_at']>0)$s['recovery_hold_until']=max((float)$s['recovery_hold_until'],(float)$s['cooldown_until']+self::RECOVERY_HOLD_SECONDS);
            $this->trimEvidence($s,$now);
            $policyChanged=$this->normalizePolicy($s,$now);
            $before=$s;$result=$callback($s,$now);
            if($skipUnchanged&&!$policyChanged&&$before===$s&&is_array($old)&&($old['policy_revision']??0)===self::POLICY_REVISION&&$fresh&&(int)($old['version']??0)===self::STATE_VERSION&&(int)($old['latency_model_revision']??0)===self::LATENCY_MODEL_REVISION&&isset($old['recovery_hold_until'])){
                $this->io['no_write']++;flock($handle,LOCK_UN);return $result;
            }
            $s['version']=self::STATE_VERSION;$s['updated_at']=$this->now();
            $writeStarted=hrtime(true);$encoded=json_encode($s,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
            rewind($handle);if(!ftruncate($handle,0)||fwrite($handle,$encoded)!==strlen($encoded)||!fflush($handle))throw new \RuntimeException('OAuth rate coordinator state write failed.');
            $this->io['writes']++;$this->io['write_ms']+=(hrtime(true)-$writeStarted)/1e6;
            flock($handle,LOCK_UN);return $result;
        }finally{fclose($handle);}
    }

    private function publicState(array $s,float $now,string $reason): array
    {
        return ['rate_policy'=>self::policy(),'effective_ceiling_cps'=>$this->effectiveCeiling((float)$s['unsafe_rate_cps']),'rate_learning_revision'=>self::STATE_VERSION,'latency_model_revision'=>self::LATENCY_MODEL_REVISION,'observed_at_ms'=>(int)floor($now*1000),'rate_target_cps'=>round($this->clampRate((float)$s['target_rate_cps']),4),'safe_rate_cps'=>round((float)$s['safe_rate_cps'],4),'unsafe_rate_cps'=>round((float)$s['unsafe_rate_cps'],4),'unsafe_evidence'=>(string)$s['unsafe_evidence'],'clean_samples'=>(int)$s['clean_samples'],'backlashes'=>(int)$s['backlashes'],'launches'=>(int)$s['launches'],'reservations'=>(int)$s['reservations'],'observed_launch_cps'=>round($this->observedRate($s['recent_actual_launches'],$now),4),'observed_clean_cps'=>round(count($s['recent_clean_completions'])/self::WINDOW_SECONDS,4),'cooldown_remaining_ms'=>max(0,(int)ceil(((float)$s['cooldown_until']-$now)*1000)),'permanent_4xx_seen'=>(int)$s['permanent_4xx_seen'],'background_suppressions'=>(int)$s['background_suppressions'],'recovery_hold_remaining_ms'=>max(0,(int)ceil(((float)$s['recovery_hold_until']-$now)*1000)),'foreground_hold_ms'=>(int)round(self::FOREGROUND_HOLD_SECONDS*1000),'latency_baseline_ms'=>$s['latency_baseline_ms'],'last_endpoint_class'=>(string)($s['last_endpoint_class']??''),'last_median_ms'=>(float)($s['last_median_ms']??0),'last_p95_ms'=>(float)($s['last_p95_ms']??0),'reason'=>$reason];
    }

    private function percentile(array $values,float $p):float { return $values===[]?0.0:(float)$values[(int)floor((count($values)-1)*max(0,min(1,$p)))]; }
}
