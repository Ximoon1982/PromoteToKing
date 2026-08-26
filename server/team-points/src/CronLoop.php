<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/**
 * Hosting-safe Team Points CRON adapter.
 *
 * One external CRON request runs one bounded Worker segment and returns before
 * the host's 60-second ceiling. The durable MariaDB queue is continued by the
 * next external five-minute invocation; no browser or self-scheduled PHP chain
 * is required.
 */
final class CronLoop
{
    private readonly array $app;
    private readonly string $clubSlug;

    public function __construct(
        private readonly Repository $repository,
        private readonly Worker $worker,
        private readonly string $lane = 'combined'
    ) {
        $this->app = \p2k_tp_config()['app'] ?? [];
        $this->clubSlug = strtolower((string)($this->app['club_slug'] ?? 'promote-to-king'));
    }

    private function normalizedLane(): string
    {
        return in_array($this->lane,['club','player','combined'],true)?$this->lane:'combined';
    }

    public function maxSeconds(): int
    {
        $lane=$this->normalizedLane();
        $configured=$lane==='player'
            ? (int)($this->app['player_cron_endpoint_max_seconds'] ?? 38)
            : (int)($this->app['cron_endpoint_max_seconds'] ?? $this->app['cron_loop_max_seconds'] ?? 42);
        return max(15, min($lane==='player'?38:42, $configured));
    }

    public function workerSeconds(): int
    {
        $key=$this->normalizedLane()==='club'?'club_worker_segment_seconds':($this->normalizedLane()==='player'?'player_worker_segment_seconds':'worker_segment_seconds');
        $lane=$this->normalizedLane();$hardCap=$lane==='club'?36:30;
        $configured=(int)($this->app[$key] ?? $this->app['worker_segment_seconds'] ?? $this->app['worker_max_seconds'] ?? $hardCap);
        // The Club drain floor is release policy, not protected local configuration:
        // installations upgraded from the old 25-second setting still receive ACDM.
        if($lane==='club')$configured=max(34,$configured);
        return max(5, min($hardCap, $configured));
    }

    public function nextDelaySeconds(): int
    {
        if($this->normalizedLane()==='club')$seconds=(int)($this->app['club_cron_expected_interval_seconds']??300);
        elseif($this->normalizedLane()==='player')$seconds=(int)($this->app['player_cron_expected_interval_seconds']??600);
        else $seconds=(int)($this->app['cron_expected_interval_seconds']??300);
        return max(300,min(86400,$seconds));
    }

    public function leaseSeconds(): int
    {
        return $this->maxSeconds() + 30;
    }

    /** Kept for compatibility; execution is now externally scheduled. */
    public function enabled(): bool
    {
        return false;
    }

    public function execute(string $chainId, string $trigger, ?int $workerBudgetSeconds = null): array
    {
        $startedAt = microtime(true);
        $absoluteDeadlineAt=$startedAt+$this->maxSeconds()-4.0;
        $lane=$this->normalizedLane();
        $segmentSeconds = $workerBudgetSeconds === null ? $this->workerSeconds() : max(5,min($this->workerSeconds(),$workerBudgetSeconds));
        $job = $this->repository->latestJob($this->clubSlug,$lane);
        if ($job === null || in_array((string)($job['status'] ?? ''), ['completed','failed','cancelled'], true)) {
            $job = $this->repository->createOrGetActiveJob($this->clubSlug,$lane);
        }
        if ((string)($job['status'] ?? '') === 'paused') {
            // Pausing historical work must not break the hourly authoritative freshness floor.
            $fresh=null;$message=ucfirst($lane) . ' Team Points is paused. Resume it from the unified task control page.';
            try{
                if($lane==='player'){$fresh=$this->worker->refreshRosterOnly();$message.=' Lightweight authoritative roster freshness still ran.';}
                elseif($lane==='club'){$fresh=$this->worker->refreshClubIndexOnly();$message.=' Lightweight authoritative club-index freshness still ran.';}
            }catch(\Throwable $exception){$fresh=['ok'=>false,'error'=>$exception->getMessage()];$message.=' Freshness guard failed: '.$exception->getMessage();}
            return [
                'ok' => true,'status' => 'paused','chain_id' => $chainId,'lane' => $lane,'worker_invocations' => 1,'processed_items' => 0,
                'elapsed_ms' => (int)round((microtime(true)-$startedAt)*1000),'max_seconds' => $this->maxSeconds(),'worker_segment_seconds' => $segmentSeconds,'absolute_deadline_epoch_ms'=>(int)round($absoluteDeadlineAt*1000),
                'message' => $message,'freshness_guard'=>$fresh,'last_worker_result' => ['job'=>$job],
            ];
        }

        // Every external CRON invocation evaluates the authoritative freshness clock.
        // Normal refreshes are time-bucketed; a deadline item uses the last successful
        // verified timestamp as its stable identity and outranks historical backlog.
        $freshness=$this->repository->queueCronFreshness($this->clubSlug,(string)$job['id'],$lane);


        try {
            $result = $this->worker->run((string)$job['id'], $trigger, $segmentSeconds, $absoluteDeadlineAt);
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 'failed',
                'chain_id' => $chainId,
                'worker_invocations' => 1,
                'processed_items' => 0,
                'elapsed_ms' => (int)round((microtime(true)-$startedAt)*1000),
                'max_seconds' => $this->maxSeconds(),
                'worker_segment_seconds' => $segmentSeconds,
                'message' => $exception->getMessage(),
                'last_worker_result' => null,
                'freshness' => $freshness ?? null,
            ];
        }


        $status = (string)($result['status'] ?? 'partial');
        $message = (string)($result['message'] ?? 'Worker segment completed.');
        if (in_array($status, ['partial','waiting','busy','running'], true)) {
            $message .= ' Remaining durable work will continue on the next external CRON invocation.';
        }
        return [
            'ok' => ($result['ok'] ?? true) === true,
            'status' => $status,
            'chain_id' => $chainId,
            'lane' => $lane,
            'worker_invocations' => 1,
            'processed_items' => (int)($result['processed_items'] ?? 0),
            'elapsed_ms' => (int)round((microtime(true)-$startedAt)*1000),
            'max_seconds' => $this->maxSeconds(),
            'worker_segment_seconds' => $segmentSeconds,
            'message' => $message,
            'last_worker_result' => $result,
            'freshness' => $freshness,
        ];
    }
}
