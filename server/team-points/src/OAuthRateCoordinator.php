<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/**
 * Cross-request OAuth PubAPI launch-rate coordinator.
 *
 * One state file is keyed by the opaque Bearer token hash, so all browser frames
 * and same-origin admin bridges using the same OAuth session share one launch
 * clock and one learned safe/unsafe boundary. The token itself is never stored.
 */
final class OAuthRateCoordinator
{
    private const STATE_VERSION = 2;
    private const INITIAL_RATE = 30.0;
    private const MIN_RATE = 1.0;
    private const MAX_RATE = 120.0;
    private const STATE_TTL_SECONDS = 1800.0;
    private const FOREGROUND_HOLD_SECONDS = 1.5;
    private const MAX_COOLDOWN_SECONDS = 30.0;
    private const MAX_RESERVATION_AHEAD_SECONDS = 0.025;

    private string $path;

    public function __construct(string $token)
    {
        $dir = trim((string)(getenv('P2K_OAUTH_RATE_STATE_DIR') ?: ''));
        if ($dir === '') $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'p2k-oauth-rate';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @chmod($dir, 0700);
        $this->path = $dir . DIRECTORY_SEPARATOR . hash('sha256', 'p2k-oauth-rate-v2:' . $token) . '.json';
    }

    public function announceTraffic(string $trafficClass): void
    {
        if ($this->trafficClass($trafficClass) !== 'foreground') return;
        $this->mutate(function (array &$state, float $now): void {
            $state['foreground_until'] = max((float)($state['foreground_until'] ?? 0), $now + self::FOREGROUND_HOLD_SECONDS);
        });
    }

    /**
     * Reserve one globally paced launch slot. The returned delay has already been
     * slept before this method returns.
     */
    public function reserveLaunch(string $trafficClass = 'foreground'): array
    {
        $trafficClass = $this->trafficClass($trafficClass);
        $reservation = $this->mutate(function (array &$state, float $now) use ($trafficClass): array {
            $rate = $this->clampRate((float)($state['target_rate_cps'] ?? self::INITIAL_RATE));
            $notBefore = max(
                $now,
                (float)($state['next_launch_at'] ?? 0),
                (float)($state['cooldown_until'] ?? 0)
            );
            if ($trafficClass === 'background') {
                $foregroundUntil = (float)($state['foreground_until'] ?? 0);
                if ($foregroundUntil > $notBefore) $state['background_suppressions'] = (int)($state['background_suppressions'] ?? 0) + 1;
                $notBefore = max($notBefore, $foregroundUntil);
            } else {
                $state['foreground_until'] = max((float)($state['foreground_until'] ?? 0), $now + self::FOREGROUND_HOLD_SECONDS);
            }
            $slot = $notBefore;
            if($slot-$now>self::MAX_RESERVATION_AHEAD_SECONDS){
                return ['slot'=>$slot,'reserved'=>false,'rate_target_cps'=>$rate,'safe_rate_cps'=>(float)($state['safe_rate_cps']??0),'unsafe_rate_cps'=>(float)($state['unsafe_rate_cps']??0)];
            }
            $state['next_launch_at'] = $slot + (1.0 / max(self::MIN_RATE, $rate));
            $state['launches'] = (int)($state['launches'] ?? 0) + 1;
            $recent=is_array($state['recent_launch_slots']??null)?$state['recent_launch_slots']:[];
            $recent[]=$slot;$cutoff=$slot-2.5;$recent=array_values(array_filter($recent,static fn($value):bool=>(float)$value>=$cutoff));
            if(count($recent)>256)$recent=array_slice($recent,-256);$state['recent_launch_slots']=$recent;
            return [
                'slot' => $slot,
                'reserved' => true,
                'rate_target_cps' => $rate,
                'safe_rate_cps' => (float)($state['safe_rate_cps'] ?? 0),
                'unsafe_rate_cps' => (float)($state['unsafe_rate_cps'] ?? 0),
            ];
        });
        $reservation['wait_ms'] = max(0.0, ((float)$reservation['slot'] - microtime(true)) * 1000.0);
        return $reservation;
    }

    public function waitForLaunch(string $trafficClass = 'foreground'): array
    {
        do{
            $reservation = $this->reserveLaunch($trafficClass);
            $delay = max(0.0, (float)$reservation['slot'] - microtime(true));
            if ($delay > 0) usleep((int)min(5000000, max(0, round($delay * 1000000))));
        }while(empty($reservation['reserved']));
        $reservation['wait_ms'] = $delay * 1000.0;
        unset($reservation['slot']);
        return $reservation;
    }

    /**
     * Hybrid controller: endpoint-normalized latency is the continuous PI/D
     * pressure signal; 429/Retry-After is a hard anti-windup boundary event.
     * Permanent 4xx responses are application results and never reduce rate.
     */
    public function feedback(
        array $statuses,
        array $latenciesMs,
        string $endpointClass,
        float $attemptedRateCps,
        int $retryAfterSeconds = 0
    ): array {
        $endpointClass = preg_replace('/[^a-z0-9_-]+/i', '-', strtolower(trim($endpointClass))) ?: 'other';
        $allLatencies=[];$successLatencies=[];
        $rate429 = 0; $transient = 0; $permanent4xx = 0; $success = 0;
        foreach ($statuses as $index=>$raw) {
            $status = (int)$raw;$latency=max(0.0,(float)($latenciesMs[$index]??0));if($latency>0)$allLatencies[]=$latency;
            if ($status === 429) $rate429++;
            elseif ($status === 0 || $status >= 500) $transient++;
            elseif ($status >= 400) $permanent4xx++;
            else {$success++;if($latency>0)$successLatencies[]=$latency;}
        }
        $latencies=$successLatencies!==[]?$successLatencies:$allLatencies;sort($latencies,SORT_NUMERIC);
        $median = $this->percentile($latencies, 0.5);$p95 = $this->percentile($latencies, 0.95);
        $attemptedRateCps = $this->clampRate($attemptedRateCps > 0 ? $attemptedRateCps : self::INITIAL_RATE);
        return $this->mutate(function (array &$state, float $now) use (
            $endpointClass, $median, $p95, $attemptedRateCps, $rate429, $transient,
            $permanent4xx, $success, $retryAfterSeconds
        ): array {
            $current = $this->clampRate((float)($state['target_rate_cps'] ?? self::INITIAL_RATE));
            $safe = max(0.0, (float)($state['safe_rate_cps'] ?? 0));
            $unsafe = max(0.0, (float)($state['unsafe_rate_cps'] ?? 0));
            $observedRate=$this->observedLaunchRate($state,$now);
            $testedRate=$observedRate>0?min($attemptedRateCps,max(self::MIN_RATE,$observedRate*1.04)):$attemptedRateCps;
            $baselines = is_array($state['latency_baseline_ms'] ?? null) ? $state['latency_baseline_ms'] : [];
            $baseline = max(0.0, (float)($baselines[$endpointClass] ?? 0));
            if ($success > 0 && $rate429 === 0 && $median > 0 && ($baseline <= 0 || $median < $baseline)) {
                $baseline = $median;
                $baselines[$endpointClass] = round($baseline, 3);
            }

            $total = max(1, $success + $rate429 + $transient + $permanent4xx);
            $transientRatio = $transient / $total;
            $pressure = ($baseline > 0 && $median > 0) ? max(0.0, ($median / $baseline) - 1.0) : 0.0;
            $state['last_pressure'] = (float)($state['last_pressure'] ?? 0);
            $state['integral_error'] = (float)($state['integral_error'] ?? 0);

            $reason = 'steady';
            if ($rate429 > 0) {
                // A 429 proves the current launch target was unsafe even if the
                // caller itself was under-fed. Use the attempted target for the
                // upper boundary; clean samples use observed launch rate instead.
                $unsafe = $unsafe > 0 ? min($unsafe, $attemptedRateCps) : $attemptedRateCps;
                $sameEpisode=(float)($state['cooldown_until']??0)>$now;
                if(!$sameEpisode){
                    $state['backlashes'] = (int)($state['backlashes'] ?? 0) + 1;
                    // Hard anti-windup: retreat once per backlash episode. Other
                    // in-flight 429s update the same boundary but do not compound it.
                    $next = $safe > 0 ? min($safe * 0.97, $attemptedRateCps * 0.78) : $attemptedRateCps * 0.58;
                    $state['target_rate_cps'] = $this->clampRate($next);
                    $state['integral_error'] = 0.0;$state['last_pressure'] = 0.0;$state['clean_samples'] = 0;
                }
                $cooldown = max(1, min((int)self::MAX_COOLDOWN_SECONDS, $retryAfterSeconds > 0 ? $retryAfterSeconds : 1));
                $state['cooldown_until'] = max((float)($state['cooldown_until'] ?? 0), $now + $cooldown);
                $reason = $sameEpisode?'rate-limit-coalesced':'rate-limit-boundary';
            } elseif ($transientRatio > 0.15) {
                // 5xx/network errors are a soft reliability signal. Do not confuse
                // them with 404/410 and do not create an unsafe rate boundary.
                $state['target_rate_cps'] = $this->clampRate($current * 0.90);
                $state['integral_error'] = max(-1.0, (float)$state['integral_error'] - 0.15);
                $state['clean_samples'] = 0;
                $reason = 'transient-pressure';
            } else {
                // Clean application results (including permanent 4xx) prove that
                // transport at the attempted rate was not rate-limited.
                if ($unsafe <= 0 || $testedRate < $unsafe * 0.985) {
                    $safe = max($safe, $testedRate);
                }
                $state['clean_samples'] = (int)($state['clean_samples'] ?? 0) + 1;

                $desiredPressure = 0.20;
                $error = $desiredPressure - $pressure;
                $integral = max(-1.5, min(1.5, (float)$state['integral_error'] + $error));
                $derivative = $pressure - (float)$state['last_pressure'];
                $state['integral_error'] = $integral;
                $state['last_pressure'] = $pressure;

                // Continuous PID-like correction. It is intentionally bounded; the
                // boundary search below remains authoritative after an actual 429.
                $pidFraction = (0.16 * $error) + (0.025 * $integral) - (0.08 * $derivative);
                $pidFraction = max(-0.12, min(0.16, $pidFraction));

                if ($unsafe > 0) {
                    $ceiling = max(self::MIN_RATE, $unsafe * 0.92);
                    $anchor = max(self::MIN_RATE, min($safe > 0 ? $safe : $current, $ceiling));
                    $gap = max(0.0, $ceiling - $anchor);
                    // Geometric convergence from below. Once the gap is small we
                    // hold the proven-safe point instead of repeatedly touching the cliff.
                    $probe = $gap > 0.35 ? $anchor + max(0.20, $gap * 0.35) : $anchor;
                    $next = min($ceiling, max($anchor, $probe * (1.0 + min(0.03, max(-0.03, $pidFraction)))));
                    $state['target_rate_cps'] = $this->clampRate($next);
                    $reason = $gap > 0.35 ? 'boundary-converge' : 'boundary-hold';
                } else {
                    // Before a boundary exists, probe quickly enough to exploit a
                    // healthy OAuth connection but only after a clean sample.
                    $growth = max(0.10, min(0.30, 0.20 + $pidFraction));
                    if($testedRate >= $current*0.78){$state['target_rate_cps']=$this->clampRate($current*(1.0+$growth));$reason='clean-probe';}
                    else{$state['target_rate_cps']=$current;$reason='demand-limited-hold';}
                }
            }

            if ($unsafe > 0 && $safe >= $unsafe) $safe = max(0.0, $unsafe * 0.90);
            $state['safe_rate_cps'] = round($safe, 4);
            $state['unsafe_rate_cps'] = round($unsafe, 4);
            $state['latency_baseline_ms'] = $baselines;
            $state['last_endpoint_class'] = $endpointClass;
            $state['last_median_ms'] = round($median, 3);
            $state['last_p95_ms'] = round($p95, 3);
            $state['last_feedback_at'] = $now;
            $state['last_reason'] = $reason;
            $state['permanent_4xx_seen'] = (int)($state['permanent_4xx_seen'] ?? 0) + $permanent4xx;
            return $this->publicState($state, $reason);
        });
    }

    public function snapshot(): array
    {
        return $this->mutate(fn(array &$state, float $now): array => $this->publicState($state, 'snapshot'));
    }

    private function trafficClass(string $value): string
    {
        return strtolower(trim($value)) === 'background' ? 'background' : 'foreground';
    }

    private function clampRate(float $rate): float
    {
        if (!is_finite($rate)) $rate = self::INITIAL_RATE;
        return max(self::MIN_RATE, min(self::MAX_RATE, $rate));
    }

    private function defaultState(float $now): array
    {
        return [
            'version' => self::STATE_VERSION,
            'updated_at' => $now,
            'target_rate_cps' => self::INITIAL_RATE,
            'safe_rate_cps' => 0.0,
            'unsafe_rate_cps' => 0.0,
            'next_launch_at' => 0.0,
            'cooldown_until' => 0.0,
            'foreground_until' => 0.0,
            'integral_error' => 0.0,
            'last_pressure' => 0.0,
            'latency_baseline_ms' => [],
            'clean_samples' => 0,
            'backlashes' => 0,
            'launches' => 0,
            'recent_launch_slots' => [],
            'permanent_4xx_seen' => 0,
            'background_suppressions' => 0,
        ];
    }

    private function mutate(callable $callback): mixed
    {
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            $state = $this->defaultState(microtime(true));
            return $callback($state, microtime(true));
        }
        @chmod($this->path, 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                $state = $this->defaultState(microtime(true));
                return $callback($state, microtime(true));
            }
            rewind($handle);
            $raw = stream_get_contents($handle);
            $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
            $now = microtime(true);
            $state = is_array($decoded) ? $decoded : $this->defaultState($now);
            if ((int)($state['version'] ?? 0) !== self::STATE_VERSION || $now - (float)($state['updated_at'] ?? 0) > self::STATE_TTL_SECONDS) {
                $state = $this->defaultState($now);
            }
            $result = $callback($state, $now);
            $state['version'] = self::STATE_VERSION;
            $state['updated_at'] = microtime(true);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES));
            fflush($handle);
            flock($handle, LOCK_UN);
            return $result;
        } finally {
            fclose($handle);
        }
    }

    private function publicState(array $state, string $reason): array
    {
        return [
            'rate_target_cps' => round($this->clampRate((float)($state['target_rate_cps'] ?? self::INITIAL_RATE)), 4),
            'safe_rate_cps' => round(max(0.0, (float)($state['safe_rate_cps'] ?? 0)), 4),
            'unsafe_rate_cps' => round(max(0.0, (float)($state['unsafe_rate_cps'] ?? 0)), 4),
            'clean_samples' => (int)($state['clean_samples'] ?? 0),
            'backlashes' => (int)($state['backlashes'] ?? 0),
            'launches' => (int)($state['launches'] ?? 0),
            'observed_launch_cps' => round($this->observedLaunchRate($state,microtime(true)),4),
            'permanent_4xx_seen' => (int)($state['permanent_4xx_seen'] ?? 0),
            'background_suppressions' => (int)($state['background_suppressions'] ?? 0),
            'foreground_hold_ms' => (int)round(self::FOREGROUND_HOLD_SECONDS * 1000),
            'latency_baseline_ms' => is_array($state['latency_baseline_ms'] ?? null) ? $state['latency_baseline_ms'] : [],
            'last_endpoint_class' => (string)($state['last_endpoint_class'] ?? ''),
            'last_median_ms' => (float)($state['last_median_ms'] ?? 0),
            'last_p95_ms' => (float)($state['last_p95_ms'] ?? 0),
            'reason' => $reason,
        ];
    }

    private function observedLaunchRate(array $state,float $now): float
    {
        $slots=is_array($state['recent_launch_slots']??null)?array_values(array_map('floatval',$state['recent_launch_slots'])):[];
        $cutoff=$now-2.0;$slots=array_values(array_filter($slots,static fn(float $v):bool=>$v>=$cutoff&&$v<=$now+0.05));
        $count=count($slots);if($count<4)return 0.0;sort($slots,SORT_NUMERIC);$span=max(0.001,end($slots)-$slots[0]);return ($count-1)/$span;
    }

    private function percentile(array $values, float $p): float
    {
        if ($values === []) return 0.0;
        $index = (int)floor((count($values) - 1) * max(0.0, min(1.0, $p)));
        return (float)$values[$index];
    }
}
