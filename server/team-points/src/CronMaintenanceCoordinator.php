<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * CMDI: optional CRON maintenance is isolated from canonical queue execution.
 *
 * The worker always runs first. Every optional class gets a bounded local wall-clock
 * slice and a matching MariaDB statement timeout. A maintenance failure is telemetry,
 * never a reason to turn a successful canonical worker segment into a failed CRON run.
 */
final class CronMaintenanceCoordinator
{
    private readonly string $clubSlug;
    private readonly float $hardReturnReserve;

    public function __construct(
        private readonly PDO $core,
        private readonly PDO $analytics,
        private readonly Repository $repository,
        private readonly array $config,
        private readonly string $lane,
        private readonly float $requestDeadlineAt
    ) {
        $this->clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
        $this->hardReturnReserve = max(2.0, min(8.0, (float)($config['app']['cron_maintenance_return_reserve_seconds'] ?? 4.0)));
    }

    public function run(): array
    {
        $out = [
            'mode' => 'cmdi-isolated',
            'request_deadline_epoch_ms' => (int)round($this->requestDeadlineAt * 1000),
            'hard_return_reserve_seconds' => $this->hardReturnReserve,
            'classes' => [],
        ];

        if ($this->lane === 'club') {
            $out['classes']['analytics'] = $this->runClass('analytics', 7.0, 3.0, function(float $deadline): array {
                $builder = new AnalyticsBuilder($this->core, $this->analytics);
                return $builder->refreshIfDue($this->clubSlug, 300, $deadline) + ['maintenance_class'=>'analytics'];
            });
            $out['classes']['intelligence'] = $this->runClass('intelligence', 5.0, 2.0, function(float $deadline): array {
                if (!$this->hasTime($deadline, 1.0)) return ['ran'=>false,'deferred'=>true,'reason'=>'local_deadline'];
                $service = new ClubIntelligenceService($this->core, $this->analytics, $this->clubSlug);
                $snapshot = $service->captureDailySnapshot();
                if (!$this->hasTime($deadline, 0.8)) return ['ran'=>true,'partial'=>true,'snapshot_date'=>$snapshot['date']??null,'deferred_anomaly_scan'=>true];
                $scan = $service->automaticAnomalyScan();
                RuntimeTelemetry::record('intelligence_maintenance',['lane'=>$this->lane,'anomalies'=>(int)($scan['summary']['total']??0)]);
                return ['ran'=>true,'snapshot_date'=>$snapshot['date']??null,'anomaly_scan'=>$scan['summary']??null,'scanned_at'=>$scan['scanned_at']??null];
            });
            // v2.10.9.6: fair-play correction is bounded background integrity work.
            // Active matches are revisited first; the one-time finished-match backfill
            // uses any remaining slice and checkpoints after each authoritative match.
            $out['classes']['fair_play'] = $this->runClass('fair_play', 8.0, 3.0, function(float $deadline): array {
                $service = new FairPlayReconciliationService($this->core, $this->repository, new ChessApi($this->repository), $this->clubSlug);
                return $service->runStep(3, $deadline) + ['maintenance_class'=>'fair_play'];
            });
            $out['classes']['pir'] = $this->runClass('pir', 6.0, 2.0, function(float $deadline): array {
                return (new PointIntegrityService($this->core, $this->repository, $this->clubSlug))->runStep(30, $deadline);
            });
        } else {
            $out['classes']['achievements'] = $this->runClass('achievements', 15.0, 11.0, function(float $deadline): array {
                $builder = new AnalyticsBuilder($this->core, $this->analytics);
                return $builder->refreshAchievementsIfDue($this->clubSlug, 1800, $deadline) + ['maintenance_class'=>'achievements'];
            });
            $out['classes']['mira'] = $this->runClass('mira', 6.0, 2.0, function(float $deadline): array {
                if (!$this->hasTime($deadline, 1.0)) return ['ran'=>false,'deferred'=>true,'reason'=>'local_deadline'];
                $service = new LiveRanksService($this->analytics, $this->repository, new ChessApi($this->repository));
                $status = $service->identityAttributionStatus();
                if (empty($status['stale'])) return ['ran'=>false,'reason'=>'current','identity_attribution'=>$status];
                if (!$this->hasTime($deadline, 4.0)) return ['ran'=>false,'deferred'=>true,'reason'=>'insufficient_mira_slice','identity_attribution'=>$status];
                return $service->rebuildIdentityAttributionIfNeeded($deadline);
            });
            $out['classes']['housekeeping'] = $this->runClass('housekeeping', 6.0, 2.0, function(float $deadline): array {
                return (new Housekeeping($this->core, $this->analytics, $this->config))->runIfDue(21600, $deadline);
            });
            $out['classes']['storage'] = $this->runClass('storage', 4.0, 2.0, function(float $deadline): array {
                return (new StorageMetricsService($this->core, $this->analytics, $this->config))->snapshot(true, $deadline);
            });
        }

        $out['remaining_seconds'] = max(0.0, round($this->requestDeadlineAt - microtime(true), 3));
        return $out;
    }

    /** @param callable(float):array $callback */
    private function runClass(string $name, float $maxSlice, float $minimumStart, callable $callback): array
    {
        $remaining = $this->requestDeadlineAt - microtime(true) - $this->hardReturnReserve;
        if ($remaining < $minimumStart) {
            return ['ran'=>false,'deferred'=>true,'reason'=>'cmdi_no_budget','available_seconds'=>max(0.0,round($remaining,3))];
        }
        $slice = max($minimumStart, min($maxSlice, $remaining));
        $deadline = min($this->requestDeadlineAt - $this->hardReturnReserve, microtime(true) + $slice);
        $started = microtime(true);
        $this->setStatementBudget(max(0.5, $deadline - $started - 0.25));
        try {
            $result = $callback($deadline);
            if (!is_array($result)) $result = ['ran'=>true];
            return $result + [
                'cmdi_slice_seconds'=>round($slice,3),
                'cmdi_elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
                'cmdi_deadline_epoch_ms'=>(int)round($deadline*1000),
            ];
        } catch (\Throwable $e) {
            return [
                'ran'=>false,'deferred'=>false,'error'=>$e->getMessage(),'maintenance_class'=>$name,
                'cmdi_slice_seconds'=>round($slice,3),'cmdi_elapsed_ms'=>(int)round((microtime(true)-$started)*1000),
                'cmdi_deadline_epoch_ms'=>(int)round($deadline*1000),
            ];
        } finally {
            $this->setStatementBudget(0.0);
        }
    }

    private function hasTime(float $deadline, float $reserve = 0.5): bool
    {
        return microtime(true) + max(0.0,$reserve) < $deadline;
    }

    private function setStatementBudget(float $seconds): void
    {
        $value = $seconds > 0 ? number_format(max(0.05,min(30.0,$seconds)),3,'.','') : '0';
        foreach ([$this->core,$this->analytics] as $pdo) {
            try { $pdo->exec('SET SESSION max_statement_time='.$value); } catch (\Throwable) {}
        }
    }
}
