<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

final class Worker
{
    private readonly array $app;
    private readonly string $clubSlug;
    private float $deadlineAt = 0.0;
    private ?string $clubJobIdCache = null;
    private ?string $playerJobIdCache = null;
    private readonly PlayerMatchesFallbackState $playerMatchesFallback;
    private int $playerFairCursor = 0;
    private int $clubFairCursor = 0;
    /** @var array<int,bool> */
    private array $finalizationAttempted = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly Repository $repository,
        private readonly ChessApi $api,
        private readonly string $lane = 'combined'
    ) {
        $config = \p2k_tp_config();
        $this->app = $config['app'] ?? [];
        $this->clubSlug = strtolower((string)($this->app['club_slug'] ?? 'promote-to-king'));
        $storage=is_array($config['storage'] ?? null)?$config['storage']:[];
        $this->playerMatchesFallback = new PlayerMatchesFallbackState($storage, $this->app);
        $this->playerFairCursor=$this->loadFairCursor('player');
        $this->clubFairCursor=$this->loadFairCursor('club');
    }

    /** Lightweight authoritative roster refresh that may run even while the historical Player lane is paused. */
    public function refreshRosterOnly(): array
    {
        return $this->syncRoster(['paused_lane_freshness'=>true]);
    }

    /** v2.10.6 administrator network-first refresh for a newly discovered match. */
    public function refreshMatchNow(int $matchId): array
    {
        if($matchId<=0)throw new \InvalidArgumentException('A valid match ID is required.');
        $match=$this->api->json('https://api.chess.com/pub/match/'.$matchId,true);
        return $this->processMatchPayload($this->clubJobId(),$matchId,$match,'admin_recent_match_click');
    }

    /** Lightweight authoritative club-index refresh that may run even while the historical Club lane is paused. */
    public function refreshClubIndexOnly(): array
    {
        $url="https://api.chess.com/pub/club/{$this->clubSlug}/matches";$data=$this->api->json($url,true);
        $counts=['registered'=>count(is_array($data['registered']??null)?$data['registered']:[]),'in_progress'=>count(is_array($data['in_progress']??null)?$data['in_progress']:[]),'finished'=>count(is_array($data['finished']??null)?$data['finished']:[])];
        if(array_sum($counts)===0)throw new RetryableException('Chess.com returned an empty club match index; authoritative freshness was not advanced.',60);
        foreach(['registered','in_progress','finished'] as $bucket)foreach(is_array($data[$bucket]??null)?$data[$bucket]:[] as $entry){if(!is_array($entry))continue;$matchId=$this->extractMatchId((string)($entry['@id']??''),(string)($entry['url']??''));if($matchId!==null)$this->repository->observeClubMatchIndex($this->clubSlug,$matchId,$bucket,$entry);}
        $this->repository->markClubIndexObserved($this->clubSlug,$counts,true);
        return ['registered_visible'=>$counts['registered'],'in_progress_visible'=>$counts['in_progress'],'finished_visible'=>$counts['finished'],'source_url'=>$url,'algorithm'=>'lightweight_authoritative_club_index'];
    }

    public function run(?string $requestedJobId, string $trigger, ?int $maxSecondsOverride = null, ?float $absoluteDeadlineAt = null, ?int $maxItemsOverride = null): array
    {
        if (!$this->acquireLock()) {
            return [
                'ok' => true,
                'status' => 'busy',
                'processed_items' => 0,
                'wait_seconds' => 2,
                'message' => 'Another worker invocation is active; the interface will retry automatically.',
            ];
        }

        $job = $requestedJobId !== null ? $this->repository->job($requestedJobId) : $this->repository->latestJob($this->clubSlug, $this->normalizedLane());
        $runId = $this->repository->beginWorkerRun($job['id'] ?? null, $trigger);
        $processed = 0;
        $message = 'No runnable job.';
        $resultStatus = 'idle';
        $startedAt = microtime(true);
        $configuredMaxSeconds = (int)($this->app['worker_max_seconds'] ?? 35);
        $maxSeconds = max(5, min(40, $maxSecondsOverride ?? $configuredMaxSeconds));
        $this->deadlineAt = $absoluteDeadlineAt!==null?min($startedAt+$maxSeconds,$absoluteDeadlineAt):($startedAt+$maxSeconds);
        $this->api->setDeadlineAt($this->deadlineAt);
        $summariesFinalized = 0;
        $summaryIndexCreated = false;

        try {
            if ($job === null || !in_array($job['status'], ['new', 'running'], true)) {
                $idleReason = $job === null
                    ? 'no durable job exists for this lane'
                    : sprintf('latest durable job is %s (updated %s)', (string)($job['status'] ?? 'unknown'), (string)($job['updated_at'] ?? 'unknown'));
                $idleMessage = 'No runnable job — ' . $idleReason . '.';
                return [
                    'ok' => true,
                    'status' => 'idle',
                    'processed_items' => 0,
                    'job' => $job,
                    'idle_reason' => $idleReason,
                    'job_status' => $job['status'] ?? null,
                    'job_updated_at' => $job['updated_at'] ?? null,
                    'message' => $summariesFinalized > 0 ? "Finalized {$summariesFinalized} immutable historical match summary(s). {$idleMessage}" : $idleMessage,
                    'match_summaries_finalized' => $summariesFinalized,
                    'summary_index_created' => $summaryIndexCreated,
                ];
            }

            $jobId = (string)$job['id'];
            $laneName=$this->normalizedLane();
            $maxItemsKey=$laneName==='player'?'player_worker_max_items':($laneName==='club'?'club_worker_max_items':'worker_max_items');
            $configuredRaw=(int)($this->app[$maxItemsKey] ?? $this->app['worker_max_items'] ?? 25);
            // v2.9.22 ACDM correction: old protected config.local.php files may still carry
            // the historical 25-item ceiling. Lane policy owns the new safe floor so the
            // release correction takes effect without mutating protected host config.
            $configuredMaxItems=$laneName==='club'?100:($laneName==='player'?max(75,min(100,$configuredRaw)):max(1,min(100,$configuredRaw)));
            $maxItems = $maxItemsOverride === null
                ? $configuredMaxItems
                : max(1, min($configuredMaxItems, $maxItemsOverride));
            $deadline = $this->deadlineAt;
            $this->repository->markJobRunning($jobId);
            $recovered = $this->repository->recoverStaleItems($jobId, max(60, (int)($this->app['stale_item_seconds'] ?? 900)));
            $failedBoardsRecovered = in_array($this->normalizedLane(), ['club','player'], true)
                ? $this->repository->recoverFailedBoardsToLane($this->clubSlug,$jobId,$this->normalizedLane(),max(50,(int)($this->app['failed_board_recovery_batch_size']??500)))
                : 0;
            // v2.9.5 could mark a final sync_player item done without persisting
            // player_matches_checked_at. Preserve those historical rows and enqueue
            // a distinct priority repair only when no continuation is still active.
            $freshnessRepairsQueued=$this->normalizedLane()==='player'
                ? $this->repository->enqueueMissingPlayerMatchFreshnessRepairs($jobId,$this->clubSlug,max(50,(int)($this->app['player_false_completion_repair_batch_size']??250)))
                : 0;
            $this->repository->log($jobId, $runId, 'info', 'worker_started', $trigger, 'Worker invocation started.', [
                'trigger' => $trigger,
                'lane' => $this->normalizedLane(),
                'max_seconds' => $maxSeconds,
                'max_items' => $maxItems,
                'recovered_stale_items' => $recovered,
                'recovered_failed_boards' => $failedBoardsRecovered,
                'player_freshness_repairs_queued' => $freshnessRepairsQueued,
            ]);

            while ($processed < $maxItems && microtime(true) < $deadline) {
                if (!$this->hasOutboundRequestBudget()) {
                    $message = 'The worker checkpointed before starting another outbound request; remaining work will continue in the next invocation.';
                    $resultStatus = $processed > 0 ? 'partial' : 'waiting';
                    break;
                }
                $job = $this->repository->job($jobId);
                if ($job === null) {
                    throw new \RuntimeException('The active job disappeared.');
                }
                if ((int)$job['stop_requested'] === 1) {
                    $this->repository->markJobPaused($jobId);
                    $message = 'The job was paused safely between queue items.';
                    $resultStatus = 'paused';
                    $this->repository->log($jobId, $runId, 'info', 'job_paused', null, $message);
                    break;
                }

                $item = $this->claimNextScheduledItem($jobId);
                if ($item === null) {
                    $counts = $this->repository->queueCounts($jobId);
                    if ($counts['pending'] === 0 && $counts['running'] === 0 && $counts['retry'] === 0) {
                        if ($counts['failed'] > 0) {
                            $message = "Synchronization incomplete: {$counts['failed']} queue item(s) failed permanently and require recovery.";
                            $this->repository->markJobFailed($jobId, $message);
                            $resultStatus = 'failed';
                            $this->repository->log($jobId, $runId, 'error', 'job_incomplete', null, $message, $counts);
                        } else {
                            $this->repository->markJobCompleted($jobId);
                            $message = 'Job completed successfully.';
                            $resultStatus = 'completed';
                            $this->repository->log($jobId, $runId, 'success', 'job_completed', null, $message, $counts);
                        }
                    } else {
                        $message = 'No queue item is currently due; retry items will continue when they become available.';
                        $resultStatus = 'waiting';
                        $this->repository->log($jobId, $runId, 'info', 'worker_waiting', null, $message, [
                            'queue' => $counts,
                            'next_retry_at' => $this->repository->nextRetryAt($jobId),
                        ]);
                    }
                    break;
                }

                $this->processItem($jobId, $runId, $item);
                $processed++;
                $message = "Processed {$processed} queue item(s) in this server invocation.";
                $resultStatus = 'partial';
            }

            // Historical summary reconciliation is maintenance, never a precondition
            // for fresh Club discovery. Run it only in the Player lane and only
            // after ordinary queue work has had the execution window.
            if($this->normalizedLane()==='player' && $this->hasOutboundRequestBudget()){
                $summaryIndexCreated=$this->repository->ensureSummaryIndexes();
                $summaryCandidateLimit=max(1,min(25,(int)($this->app['match_summary_backfill_batch_size']??25)));
                foreach($this->repository->matchSummaryCandidateIdsBatch($this->clubSlug,$summaryCandidateLimit) as $candidateMatchId){
                    if(!$this->hasOutboundRequestBudget())break;
                    if($this->finalizeMatchSummaryAuthoritatively((int)$candidateMatchId))$summariesFinalized++;
                }
            }

            $job = $this->repository->job($jobId);
            $queue = $this->repository->queueCounts($jobId);
            $nextRetryAt = $this->repository->nextRetryAt($jobId);
            return [
                'ok' => true,
                'status' => $resultStatus,
                'processed_items' => $processed,
                // ACDC observability: execution attempts are not the same metric as
                // terminal queue completion. A processed attempt may have scheduled a
                // retry, so expose both explicitly instead of calling every attempt Done.
                'execution_attempted_items' => $processed,
                'terminal_committed_items' => (int)($queue['committed'] ?? (($queue['done'] ?? 0)+($queue['skipped'] ?? 0))),
                'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'job' => $job,
                'queue' => $queue,
                'next_retry_at' => $nextRetryAt,
                'wait_seconds' => $resultStatus === 'waiting' ? $this->secondsUntil($nextRetryAt) : 0,
                'message' => $message,
                'match_summaries_finalized' => $summariesFinalized,
                'summary_index_created' => $summaryIndexCreated,
                'recovered_failed_boards' => $failedBoardsRecovered ?? 0,
                'player_freshness_repairs_queued' => $freshnessRepairsQueued ?? 0,
                'lane' => $this->normalizedLane(),
            ];
        } catch (\Throwable $exception) {
            if ($job !== null) {
                $jobId = (string)$job['id'];
                $this->repository->markJobFailed($jobId, $exception->getMessage());
                $this->repository->log($jobId, $runId, 'error', 'worker_failed', null, $exception->getMessage(), [
                    'exception' => $exception::class,
                ]);
            }
            $resultStatus = 'failed';
            $message = $exception->getMessage();
            throw $exception;
        } finally {
            if (isset($jobId)) {
                $this->repository->log($jobId, $runId, 'info', 'worker_finished', $trigger, 'Worker invocation finished.', [
                    'status' => $resultStatus,
                    'processed_items' => $processed,
                    'execution_attempted_items' => $processed,
                    'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                ]);
            }
            $this->repository->endWorkerRun($runId, $processed, $resultStatus, $message);
            $this->releaseLock();
        }
    }

    private function normalizedLane(): string
    {
        return in_array($this->lane, ['club','player','combined'], true) ? $this->lane : 'combined';
    }

    private function clubJobId(): string
    {
        if ($this->clubJobIdCache === null) {
            $job=$this->repository->createOrGetActiveJob($this->clubSlug,'club');
            $this->clubJobIdCache=(string)$job['id'];
        }
        return $this->clubJobIdCache;
    }

    private function playerJobId(): string
    {
        if ($this->playerJobIdCache === null) {
            $job=$this->repository->createOrGetActiveJob($this->clubSlug,'player');
            $this->playerJobIdCache=(string)$job['id'];
        }
        return $this->playerJobIdCache;
    }

    private function boardTargetJobId(string $currentJobId, string $sourceBucket): string
    {
        if ($this->normalizedLane() !== 'player') return $currentJobId;
        return in_array(strtolower($sourceBucket), ['registered','in_progress','finished'], true) ? $this->clubJobId() : $currentJobId;
    }

    /**
     * Player-lane scheduling is deliberately fair rather than absolute-priority.
     * Cheap discovery/reconciliation and urgent board work stay protected, then
     * Player Matches and Stats/Profile receive two turns each for every one
     * archive turn. Empty classes lend their turn to the next class.
     */
    private function claimNextScheduledItem(string $jobId): ?array
    {
        $lane=$this->normalizedLane();
        if($lane==='player'){
            $item=$this->repository->claimNextItem($jobId,['reconcile_members']);if($item!==null)return $item;
            $schedule=[['sync_player'],['sync_player_stats','sync_player_profile'],['sync_player'],['sync_player_stats','sync_player_profile'],['sync_player_archive','sync_roster','sync_members','sync_match','sync_board','sync_opponent_profile','sync_club_matches']];
            $count=count($schedule);for($attempt=0;$attempt<$count;$attempt++){$types=$schedule[$this->playerFairCursor%$count];$this->playerFairCursor=($this->playerFairCursor+1)%$count;$this->saveFairCursor('player',$this->playerFairCursor);$item=$this->repository->claimNextItem($jobId,$types);if($item!==null)return $item;}return $this->repository->claimNextItem($jobId);
        }
        if($lane==='club'){
            $item=$this->repository->claimNextItem($jobId,['sync_club_matches','sync_roster','sync_members']);if($item!==null)return $item;
            $schedule=[['sync_match'],['sync_board'],['sync_match'],['sync_board','sync_opponent_profile']];$count=count($schedule);
            for($attempt=0;$attempt<$count;$attempt++){$types=$schedule[$this->clubFairCursor%$count];$this->clubFairCursor=($this->clubFairCursor+1)%$count;$this->saveFairCursor('club',$this->clubFairCursor);$item=$this->repository->claimNextItem($jobId,$types);if($item!==null)return $item;}return $this->repository->claimNextItem($jobId);
        }
        return $this->repository->claimNextItem($jobId);
    }

    private function fairCursorPath(string $lane): string
    {
        $config=\p2k_tp_config();$storage=is_array($config['storage']??null)?$config['storage']:[];$root=\P2K\Shared\FilesystemCache::runtimeRoot($storage).'/acdc';\P2K\Shared\FilesystemCache::ensureProtectedDirectory($root);return $root.'/fairness-'.$lane.'.json';
    }
    private function loadFairCursor(string $lane): int
    {
        try{$j=json_decode((string)@file_get_contents($this->fairCursorPath($lane)),true);return max(0,(int)($j['cursor']??0));}catch(\Throwable){return 0;}
    }
    private function saveFairCursor(string $lane,int $cursor): void
    {
        try{$path=$this->fairCursorPath($lane);$tmp=$path.'.tmp-'.bin2hex(random_bytes(3));@file_put_contents($tmp,json_encode(['cursor'=>$cursor,'updated_at'=>gmdate('c')],JSON_UNESCAPED_SLASHES),LOCK_EX);@rename($tmp,$path);@unlink($tmp);}catch(\Throwable){}
    }

    private function refreshTimestampIsFresh(mixed $value,int $freshSeconds): bool
    {
        if($value===null||trim((string)$value)==='')return false;
        $epoch=strtotime((string)$value.' UTC');
        return $epoch!==false && time()-$epoch<max(60,$freshSeconds);
    }

    private function processItem(string $jobId, int $runId, array $item): void
    {
        $payload = \p2k_tp_json_decode($item['payload_json'] ?? null);
        $type = (string)$item['item_type'];
        $key = (string)$item['item_key'];
        // Queue identity is intentionally passed only in-memory. It is not persisted
        // into application data, but gives bounded continuation items a stable,
        // retry-safe chain identifier within this durable job.
        $payload['_queue_item_key'] = $key;
        $this->repository->log($jobId, $runId, 'info', 'task_started', $key, "Starting {$type}.", [
            'item_type' => $type,
            'attempt' => (int)$item['attempts'],
            'payload' => $this->safePayload($payload),
        ]);
        try {
            $details = match ($type) {
                'sync_club_matches' => $this->syncClubMatches($jobId, $payload),
                'sync_match' => $this->syncMatch($jobId, $payload),
                'discover_match_ids' => $this->discoverMatchIds($jobId, $payload),
                'sync_roster' => $this->syncRoster($payload),
                'sync_members' => $this->syncMembers($jobId, $payload),
                'sync_player_archive' => $this->syncPlayerArchive($jobId,$payload),
                'sync_player' => $this->syncPlayer($jobId, $payload),
                'sync_player_stats' => $this->syncPlayerStats($payload),
                'sync_player_profile' => $this->syncPlayerProfile($payload),
                'sync_opponent_profile' => $this->syncOpponentProfile($payload),
                'reconcile_members' => $this->reconcileMembers($jobId, $payload),
                'sync_board' => $this->syncBoard($payload),
                default => throw new \RuntimeException('Unknown queue item type: ' . $type),
            };
            $queueStatus=(string)($details['_queue_status']??'done');
            if(!in_array($queueStatus,['done','skipped'],true))$queueStatus='done';
            unset($details['_queue_status']);
            $this->repository->finishItem((int)$item['id'], $jobId, $queueStatus);
            $this->repository->log($jobId, $runId, 'success', $type, $key, $queueStatus==='skipped' ? ('Task skipped: ' . (string)($details['skip_reason']??'already fresh.')) : $this->successMessage($type, $details), $details + ['queue_status'=>$queueStatus]);
        } catch (RetryableException $exception) {
            $attempts = (int)$item['attempts'];
            $freshnessDeadline = !empty($payload['freshness_deadline']) || str_starts_with($key, 'freshness-deadline:');
            $retryLimit = $type === 'sync_board' ? 8 : 5;
            if (!$freshnessDeadline && $attempts >= $retryLimit) {
                $this->repository->finishItem((int)$item['id'], $jobId, 'failed', $exception->getMessage());
                $this->repository->log($jobId, $runId, 'error', $type, $key, 'Task permanently failed after retry limit: ' . $exception->getMessage(), [
                    'attempts' => $attempts,
                    'retry_limit' => $retryLimit,
                ]);
                return;
            }
            // Hard freshness deadlines remain overdue until an authoritative fetch succeeds.
            // They honor Retry-After/exponential backoff, but never age into a permanent failure.
            $exponent = min(8, max(0, $attempts - 1));
            $delay = max($exception->retryAfterSeconds, min(3600, 15 * (2 ** $exponent)));
            $this->repository->retryItem((int)$item['id'], $delay, $exception->getMessage());
            $this->repository->log($jobId, $runId, 'warning', $type, $key, $freshnessDeadline ? 'Freshness deadline remains overdue; retry scheduled: ' . $exception->getMessage() : 'Task scheduled for retry: ' . $exception->getMessage(), [
                'attempts' => $attempts,
                'retry_in_seconds' => $delay,
                'freshness_deadline' => $freshnessDeadline,
            ]);
        } catch (\Throwable $exception) {
            $attempts = (int)$item['attempts'];
            $freshnessDeadline = !empty($payload['freshness_deadline']) || str_starts_with($key, 'freshness-deadline:');
            if ($freshnessDeadline || $attempts < 3) {
                $exponent = min(5, max(0, $attempts - 1));
                $delay = min(900, 30 * (2 ** $exponent));
                $this->repository->retryItem((int)$item['id'], $delay, $exception->getMessage());
                $this->repository->log($jobId, $runId, 'warning', $type, $key, $freshnessDeadline ? 'Freshness deadline hit an unexpected error and remains overdue; retry scheduled: ' . $exception->getMessage() : 'Unexpected task error; retry scheduled: ' . $exception->getMessage(), [
                    'attempts' => $attempts,
                    'retry_in_seconds' => $delay,
                    'freshness_deadline' => $freshnessDeadline,
                    'exception' => $exception::class,
                ]);
                return;
            }
            $this->repository->finishItem((int)$item['id'], $jobId, 'failed', $exception->getMessage());
            $this->repository->log($jobId, $runId, 'error', $type, $key, 'Task permanently failed: ' . $exception->getMessage(), [
                'attempts' => $attempts,
                'exception' => $exception::class,
            ]);
        }
    }

    private function syncClubMatches(string $jobId, array $payload = []): array
    {
        $url = "https://api.chess.com/pub/club/{$this->clubSlug}/matches";
        $data = $this->api->json($url, true);
        $entries = [];
        foreach (['registered', 'in_progress', 'finished'] as $bucket) {
            foreach (is_array($data[$bucket] ?? null) ? $data[$bucket] : [] as $entry) {
                if (!is_array($entry)) continue;
                $matchId = $this->extractMatchId((string)($entry['@id'] ?? ''), (string)($entry['url'] ?? ''));
                if ($matchId === null) continue;
                $entries[$matchId] = ['bucket' => $bucket, 'entry' => $entry];
            }
        }
        if ($entries === []) throw new RetryableException('Chess.com returned an empty club match index; no cursors were advanced.', 60);
        $this->repository->markClubIndexObserved($this->clubSlug,['registered'=>count(is_array($data['registered']??null)?$data['registered']:[]),'in_progress'=>count(is_array($data['in_progress']??null)?$data['in_progress']:[]),'finished'=>count(is_array($data['finished']??null)?$data['finished']:[])],true);
        ksort($entries, SORT_NUMERIC);
        $dueMap=$this->repository->observeClubMatchIndexBatch($this->clubSlug,$entries);$batch=[];
        foreach($entries as $matchId=>$row)if(isset($dueMap[(int)$matchId]))$batch[]=['type'=>'sync_match','key'=>(string)$matchId,'payload'=>['match_id'=>(int)$matchId,'source'=>'club_index','source_bucket'=>(string)$row['bucket']]];
        $matchTasks=$this->repository->enqueueBatch($jobId,$batch);
        $rawTasks = 0;
        if (!empty($payload['explicit_raw_repair'])) {
            $lower = max(1,(int)($payload['lower'] ?? 1));
            $upper = max($lower,(int)($payload['upper'] ?? $lower));
            $rawTasks = $this->queueRawDiscoveryChain($jobId,'manual_raw_' . $lower . '_' . $upper,$lower,$upper) ? 1 : 0;
        }
        $bounds = $this->repository->knownMatchBounds($this->clubSlug);
        return [
            'registered_visible'=>count(is_array($data['registered']??null)?$data['registered']:[]),
            'in_progress_visible'=>count(is_array($data['in_progress']??null)?$data['in_progress']:[]),
            'finished_visible'=>count(is_array($data['finished']??null)?$data['finished']:[]),
            'match_tasks_enqueued'=>$matchTasks,'raw_discovery_chains_enqueued'=>$rawTasks,
            'newest_visible_match_id'=>(int)array_key_last($entries),
            'known_minimum_match_id'=>(int)$bounds['minimum'],'known_maximum_match_id'=>(int)$bounds['maximum'],
            'algorithm'=>'seeded_incremental_index','source_url'=>$url,
        ];
    }

    private function queueRawDiscoveryChain(string $jobId, string $sourceKey, int $lower, int $upper): bool
    {
        if ($lower <= 0 || $upper < $lower) return false;
        $state = $this->repository->discoveryState($this->clubSlug, $sourceKey);
        $cursor = max($lower, (int)($state['cursor_match_id'] ?? 0));
        if ($cursor > $upper) return false;
        $this->repository->saveDiscoveryState(
            $this->clubSlug,
            $sourceKey,
            $cursor,
            $lower,
            $upper,
            null,
            0,
            0
        );
        return $this->repository->enqueue(
            $jobId,
            'discover_match_ids',
            $sourceKey . ':' . $cursor . ':' . $upper,
            ['source_key' => $sourceKey, 'cursor' => $cursor, 'lower' => $lower, 'upper' => $upper]
        );
    }

    private function discoverMatchIds(string $jobId, array $payload): array
    {
        $sourceKey = preg_replace('/[^a-z0-9_-]+/i', '', (string)($payload['source_key'] ?? 'raw_forward')) ?: 'raw_forward';
        $lower = max(1, (int)($payload['lower'] ?? 1));
        $upper = max(0, (int)($payload['upper'] ?? 0));
        if ($upper < $lower) throw new \RuntimeException('A discover_match_ids item has an invalid range.');
        $state = $this->repository->discoveryState($this->clubSlug, $sourceKey);
        $cursor = max($lower, (int)($payload['cursor'] ?? $lower), (int)($state['cursor_match_id'] ?? 0));
        if ($cursor > $upper) {
            return ['source_key' => $sourceKey, 'scanned' => 0, 'matched' => 0, 'next_cursor' => null, 'complete' => true];
        }
        $batchSize = max(1, min(100, (int)($this->app['historical_match_scan_batch_size'] ?? 20)));
        $last = min($upper, $cursor + $batchSize - 1);
        $scanned = 0;
        $matched = 0;
        $boardsEnqueued = 0;
        $lastSuccess = null;
        $next = $cursor;
        $deadlineCheckpoint = false;
        for ($matchId = $cursor; $matchId <= $last; $matchId++) {
            if (!$this->hasOutboundRequestBudget()) {
                $next = $matchId;
                $deadlineCheckpoint = true;
                break;
            }
            $match = $this->api->jsonIfExists('https://api.chess.com/pub/match/' . $matchId, false);
            $scanned++;
            if (is_array($match)) {
                $details = $this->processMatchPayload($jobId, $matchId, $match, 'raw_id_scan');
                if (!empty($details['is_p2k_match'])) {
                    $matched++;
                    $boardsEnqueued += (int)($details['board_tasks_enqueued'] ?? 0);
                    $lastSuccess = $matchId;
                }
            }
            // Persist after each conclusive response. A rate limit or transport
            // exception occurs before this point and therefore cannot skip an ID.
            $next = $matchId + 1;
            $this->repository->saveDiscoveryState(
                $this->clubSlug,
                $sourceKey,
                $next,
                $lower,
                $upper,
                $lastSuccess,
                1,
                $lastSuccess === $matchId ? 1 : 0
            );
        }
        if (!$deadlineCheckpoint && $next <= $last) {
            $next = $last + 1;
        }
        $queuedNext = false;
        if ($next <= $upper) {
            $nextKey = $sourceKey . ':' . $next . ':' . $upper;
            if ($next === $cursor) {
                $nextKey .= ':checkpoint:' . substr(str_replace('-', '', \p2k_tp_uuid()), 0, 12);
            }
            $queuedNext = $this->repository->enqueue(
                $jobId,
                'discover_match_ids',
                $nextKey,
                ['source_key' => $sourceKey, 'cursor' => $next, 'lower' => $lower, 'upper' => $upper]
            );
        }
        return [
            'source_key' => $sourceKey,
            'range_start' => $cursor,
            'range_end' => $scanned > 0 ? ($cursor + $scanned - 1) : ($cursor - 1),
            'planned_range_end' => $last,
            'deadline_checkpoint' => $deadlineCheckpoint,
            'scanned' => $scanned,
            'matched' => $matched,
            'board_tasks_enqueued' => $boardsEnqueued,
            'next_cursor' => $next <= $upper ? $next : null,
            'next_batch_enqueued' => $queuedNext,
            'complete' => $next > $upper,
        ];
    }

    private function syncMatch(string $jobId, array $payload): array
    {
        $matchId = (int)($payload['match_id'] ?? 0);
        if ($matchId <= 0) throw new \RuntimeException('A sync_match item has no valid match ID.');
        $match = $this->api->json('https://api.chess.com/pub/match/' . $matchId, true);
        $result=$this->processMatchPayload($jobId, $matchId, $match, (string)($payload['source'] ?? 'club_index'));
        // PMAF archive discovery can identify a match ID already known to P2K while
        // the member participation row is missing. Only after this authoritative
        // match verification confirms the match belongs to P2K do we replay the
        // exact archive month, now with the participation/board mapping available.
        if(!empty($result['is_p2k_match']) && !empty($payload['pmaf_username']) && preg_match('/^20\d{2}-\d{2}$/',(string)($payload['pmaf_month']??''))){
            $username=trim((string)$payload['pmaf_username']);$month=(string)$payload['pmaf_month'];
            if($username!=='' && $this->repository->participationForMatch($this->clubSlug,\p2k_tp_username_key($username),$matchId)!==null){
                $result['pmaf_archive_replay_enqueued']=$this->enqueuePlayerArchive($this->playerJobId(),$username,$month,['source'=>'player_matches_archive_fallback','force_refresh'=>true,'pmaf_replay'=>true,'pmaf_generation'=>(int)($payload['pmaf_generation']??0)]);
            }
        }
        return $result;
    }

    private function processMatchPayload(string $jobId, int $matchId, array $match, string $source): array
    {
        if (!$this->repository->upsertMatchMetadata($this->clubSlug, $matchId, $match, $source)) {
            return ['match_id' => $matchId, 'is_p2k_match' => false, 'board_tasks_enqueued' => 0];
        }
        $team = $this->clubTeam($match);
        $status = strtolower(trim((string)($match['status'] ?? 'unknown')));
        $sourceBucket = in_array($status, ['finished', 'complete', 'completed'], true)
            ? 'finished'
            : (in_array($status, ['in_progress', 'ongoing', 'started'], true)
                ? 'in_progress'
                : (in_array($status, ['registered', 'registration'], true) ? 'registered' : 'unknown'));
        $matchUrl = trim((string)($match['@id'] ?? $match['url'] ?? ('https://api.chess.com/pub/match/' . $matchId)));
        $players = is_array($team['players'] ?? null) ? $team['players'] : [];
        $opponent = $this->opponentTeam($match);
        $opponentPlayers = is_array($opponent['players'] ?? null) ? $opponent['players'] : [];
        $opponentRatingsByBoard = [];
        $opponentNamesByBoard = [];
        foreach ($opponentPlayers as $opponentPlayer) {
            if (!is_array($opponentPlayer)) continue;
            $opponentBoardUrl = $this->boardUrlFromMatchPlayer($opponentPlayer);
            $boardKey = $this->boardPairingKey($opponentBoardUrl, $opponentPlayer);
            $rating = $this->playerRating($opponentPlayer);
            $opponentUsername = trim((string)($opponentPlayer['username'] ?? $opponentPlayer['name'] ?? ''));
            if ($boardKey !== '' && $rating !== null) $opponentRatingsByBoard[$boardKey] = $rating;
            if ($boardKey !== '' && $opponentUsername !== '') $opponentNamesByBoard[$boardKey] = $opponentUsername;
        }
        $participations = 0;
        $enqueued = 0;
        $archiveEnqueued = 0;
        $withoutBoard = 0;
        foreach ($players as $player) {
            if (!is_array($player)) continue;
            $username = trim((string)($player['username'] ?? $player['name'] ?? ''));
            if ($username === '') continue;
            $boardUrl = $this->boardUrlFromMatchPlayer($player);
            if ($boardUrl === '') {
                $withoutBoard++;
                continue;
            }
            $this->repository->upsertHistoricalMember($this->clubSlug, $username);
            $results = is_array($player['results'] ?? null) ? $player['results'] : [];
            $pairingKey = $this->boardPairingKey($boardUrl, $player);
            $p2kRating = $this->playerRating($player);
            $opponentRating = $pairingKey !== '' ? ($opponentRatingsByBoard[$pairingKey] ?? null) : null;
            $opponentUsername = $pairingKey !== '' ? ($opponentNamesByBoard[$pairingKey] ?? null) : null;
            $this->repository->upsertParticipation([
                'club_slug' => $this->clubSlug,
                'username_key' => \p2k_tp_username_key($username),
                'username' => $username,
                'match_id' => $matchId,
                'match_url' => $matchUrl,
                'board_url' => $boardUrl,
                'white_result' => isset($results['played_as_white']) ? (string)$results['played_as_white'] : null,
                'black_result' => isset($results['played_as_black']) ? (string)$results['played_as_black'] : null,
                'p2k_rating' => $p2kRating,
                'opponent_rating' => $opponentRating,
                'opponent_username' => $opponentUsername,
                'rating_source' => 'match_lineup',
            ]);
            $participations++;
            $discovery = $this->repository->registerBoardDiscovery(
                $this->clubSlug,
                $username,
                $matchId,
                $boardUrl,
                $sourceBucket
            );
            if (($discovery['state'] ?? '') !== 'complete_immutable' && !empty($discovery['due'])) {
                if ($this->normalizedLane() !== 'club') {
                    foreach ($this->archiveMonthsForMatch($match) as $month) {
                        if ($this->enqueuePlayerArchive($jobId,$username,$month)) $archiveEnqueued++;
                    }
                }
                if ($this->enqueueBoard($jobId, [
                    'username' => $username,
                    'username_key' => \p2k_tp_username_key($username),
                    'match_id' => $matchId,
                    'board_url' => $boardUrl,
                    'source_bucket' => $sourceBucket,
                    'state' => (string)($discovery['state'] ?? 'newly_discovered'),
                ])) $enqueued++;
            }
        }
        return [
            'match_id' => $matchId,
            'is_p2k_match' => true,
            'status' => $status,
            'players_in_match_payload' => count($players),
            'participations_upserted' => $participations,
            'players_without_board_url' => $withoutBoard,
            'archive_tasks_enqueued' => $archiveEnqueued,
            'board_tasks_enqueued' => $enqueued,
            'discovery_source' => $source,
        ];
    }

    private function clubTeam(array $match): array
    {
        foreach (is_array($match['teams'] ?? null) ? $match['teams'] : [] as $team) {
            if (!is_array($team)) continue;
            foreach (['@id', 'url'] as $field) {
                $value = rtrim(strtolower(trim((string)($team[$field] ?? ''))), '/');
                if ($value === "https://api.chess.com/pub/club/{$this->clubSlug}"
                    || $value === "https://www.chess.com/club/{$this->clubSlug}") {
                    return $team;
                }
                $path = trim((string)(parse_url($value, PHP_URL_PATH) ?: ''), '/');
                $parts = $path === '' ? [] : explode('/', $path);
                if ($parts !== [] && strtolower((string)end($parts)) === $this->clubSlug) return $team;
            }
        }
        return [];
    }

    private function opponentTeam(array $match): array
    {
        $clubTeam = $this->clubTeam($match);
        foreach (is_array($match['teams'] ?? null) ? $match['teams'] : [] as $team) {
            if (!is_array($team) || $team === $clubTeam) continue;
            return $team;
        }
        return [];
    }

    private function playerRating(array $player): ?int
    {
        foreach (['rating','daily_rating','chess960_rating'] as $field) {
            $value = $player[$field] ?? null;
            if (!is_numeric($value)) continue;
            $rating = (int)$value;
            if ($rating >= 100 && $rating <= 4000) return $rating;
        }
        return null;
    }

    private function boardPairingKey(string $boardUrl, array $player = []): string
    {
        $boardUrl = trim($boardUrl);
        if ($boardUrl !== '' && preg_match('~/match/(\d+)/(?:board/)?(\d+)(?:/|$)~', $boardUrl, $m)) return $m[1] . ':' . $m[2];
        foreach (['board_no','board_number','board'] as $field) {
            $value = $player[$field] ?? null;
            if (is_numeric($value) && (int)$value > 0) return 'board:' . (int)$value;
        }
        return $boardUrl !== '' ? strtolower(rtrim($boardUrl,'/')) : '';
    }

    private function boardUrlFromMatchPlayer(array $player): string
    {
        foreach (['board', 'board_url'] as $field) {
            $value = trim((string)($player[$field] ?? ''));
            if ($value !== '') return $value;
        }
        foreach (['@id', 'url'] as $field) {
            $value = trim((string)($player[$field] ?? ''));
            if ($value !== '' && (str_contains($value, '/board/') || preg_match('~/match/\d+/\d+~', $value))) return $value;
        }
        return '';
    }

    private function syncRoster(array $payload = []): array
    {
        $url="https://api.chess.com/pub/club/{$this->clubSlug}/members";
        $data=$this->api->json($url,true);
        $applied=$this->repository->applyMembersObservation($this->clubSlug,$data);
        if(empty($applied['valid']))throw new RetryableException('Chess.com returned no club members; the current roster was preserved.',60);
        $liveReconciled=$this->repository->reconcileLiveCurrentMembers($this->clubSlug);
        return [
            'members_found'=>(int)($applied['member_count']??0),
            'live_members_reconciled'=>$liveReconciled,
            'roster_changed'=>(bool)($applied['changed']??false),
            'source_url'=>$url,
            'algorithm'=>'lightweight_authoritative_roster'
        ];
    }

    private function syncMembers(string $jobId, array $payload = []): array
    {
        $url = "https://api.chess.com/pub/club/{$this->clubSlug}/members";
        // sync_roster is independently injected first every 30 minutes. Reuse that fresh
        // gateway response for the heavier reconciliation pass instead of immediately
        // issuing an identical second Chess.com request.
        $networkOnly = !empty($payload['freshness_deadline']) || !empty($payload['freshness_hard_overdue']) || !empty($payload['force_refresh']);
        $data = $this->api->json($url, $networkOnly);
        $applied=$this->repository->applyMembersObservation($this->clubSlug,$data);
        if(empty($applied['valid']))throw new RetryableException('Chess.com returned no club members; the current roster was preserved.',60);
        $liveReconciled=$this->repository->reconcileLiveCurrentMembers($this->clubSlug);
        $members = [];
        foreach (['weekly','monthly','all_time'] as $group) {
            foreach (is_array($data[$group] ?? null) ? $data[$group] : [] as $entry) {
                if (is_string($entry)) { $username=trim($entry); $joined=0; }
                elseif(is_array($entry)){ $username=trim((string)($entry['username']??$entry['name']??'')); $joined=(int)($entry['joined']??0); }
                else { $username=''; $joined=0; }
                if ($username!=='') $members[\p2k_tp_username_key($username)] = ['username'=>$username,'joined'=>$joined];
            }
        }

        $fullRepair = !empty($payload['full_member_history']) || !empty($payload['explicit_repair']);
        $playerTasks=0;
        if ($fullRepair) {
            foreach ($members as $key=>$member) {
                if ($this->repository->enqueue($jobId,'sync_player','repair-player:' . $key,['username'=>$member['username'],'explicit_repair'=>true])) $playerTasks++;
            }
        }
        $backfilled=$this->repository->backfillBoardStatesBatch($this->clubSlug,max(1,(int)($this->app['board_state_backfill_batch_size']??500)));
        $rediscovered=0; $archives=0; $routedToClub=0;$archiveUsers=[];
        $limit=max(1,(int)($this->app['board_rediscovery_limit_per_job']??1000));
        foreach ($this->repository->dueBoardRediscoveriesForClub($this->clubSlug,$limit) as $board) {
            $username=trim((string)($board['username']??''));if($username!=='')$archiveUsers[\p2k_tp_username_key($username)]=$username;
            $target=$this->boardTargetJobId($jobId,(string)($board['source_bucket']??'rediscovered'));
            if ($this->enqueueBoard($target,$board,true)) {$rediscovered++;if ($target!==$jobId) $routedToClub++;}
        }
        foreach($archiveUsers as $username)foreach($this->recentArchiveMonths() as $month)if($this->enqueuePlayerArchive($jobId,$username,$month))$archives++;
        $reconcileQueued=false;
        if ($this->normalizedLane()==='player' && !empty($payload['reconcile_current_members'])) {
            $reconcileQueued=$this->repository->enqueue($jobId,'reconcile_members','reconcile-page:0',['after_member_id'=>0,'batch_size'=>max(50,min(500,(int)($this->app['player_reconcile_batch_size']??250)))]);
        }
        return [
            'members_found'=>count($members),'player_tasks_enqueued'=>$playerTasks,
            'archive_tasks_enqueued'=>$archives,'board_states_backfilled'=>$backfilled,
            'board_tasks_rediscovered'=>$rediscovered,'boards_routed_to_club_lane'=>$routedToClub,
            'reconciliation_seeded'=>$reconcileQueued,'live_members_reconciled'=>$liveReconciled,'source_url'=>$url,
            'full_member_history_repair'=>$fullRepair,'algorithm'=>'roster_plus_bounded_member_reconciliation',
        ];
    }

    private function syncPlayer(string $jobId, array $payload): array
    {
        $username = trim((string)($payload['username'] ?? ''));
        if ($username === '') throw new \RuntimeException('A sync_player item has no username.');
        $usernameKey = \p2k_tp_username_key($username);
        $snapshot=$this->repository->memberRefreshSnapshot($this->clubSlug,$username);
        $matchesFresh=max(86400,(int)($this->app['player_reconcile_matches_refresh_seconds']??604800));
        $matchesAudit=max($matchesFresh,(int)($this->app['player_matches_authoritative_audit_seconds']??2592000));
        $now=time();$checkedAt=is_array($snapshot)&&!empty($snapshot['player_matches_checked_at'])?(strtotime((string)$snapshot['player_matches_checked_at'].' UTC')?:0):0;
        $observedAt=is_array($snapshot)&&!empty($snapshot['player_matches_observed_at'])?(strtotime((string)$snapshot['player_matches_observed_at'].' UTC')?:0):0;
        $unverifiedSince=is_array($snapshot)&&!empty($snapshot['player_matches_unverified_since'])?(strtotime((string)$snapshot['player_matches_unverified_since'].' UTC')?:0):0;
        $coverageAt=max($checkedAt,$observedAt);$auditDue=$checkedAt>0?($now-$checkedAt>=$matchesAudit):($unverifiedSince<=0||$now-$unverifiedSince>=$matchesAudit);
        if(empty($payload['force']) && empty($payload['authoritative_audit']) && empty($payload['pmaf_continue']) && is_array($snapshot) && $coverageAt>0 && $now-$coverageAt<$matchesFresh && !$auditDue) {
            return ['username'=>$username,'skip_reason'=>'claim-backed observed player-match freshness is current; authoritative audit not yet due','freshness_at'=>gmdate('Y-m-d H:i:s',$coverageAt),'verified_at'=>$snapshot['player_matches_checked_at']??null,'observed_at'=>$snapshot['player_matches_observed_at']??null,'_queue_status'=>'skipped'];
        }
        $fallbackEntry=$this->playerMatchesFallback->entry($username);
        if(is_array($fallbackEntry)&&!empty($fallbackEntry['active'])&&!$this->playerMatchesFallback->primaryProbeDue($fallbackEntry)){
            return $this->continuePlayerMatchesArchiveFallback($jobId,$username,$snapshot,$fallbackEntry,'cooldown');
        }
        $url = 'https://api.chess.com/pub/player/' . rawurlencode($username) . '/matches';
        try{
            $data = $this->api->json($url, false);
            $finishedValid=array_key_exists('finished',$data)&&is_array($data['finished']);
            $inProgressValid=array_key_exists('in_progress',$data)&&is_array($data['in_progress']);
            if(!$finishedValid||!$inProgressValid)throw new \RuntimeException('PMAF_INVALID_PLAYER_MATCHES_PAYLOAD: Chess.com player matches payload lacks finished/in_progress arrays.');
        }catch(RetryableException $exception){
            // Shared gateway saturation, network trouble, 429 and 5xx remain normal
            // retryable failures. PMAF must never amplify them into archive traffic.
            throw $exception;
        }catch(\Throwable $exception){
            $knownValid=is_array($snapshot)&&(int)($snapshot['current_member']??0)===1;
            if(!$knownValid||!$this->playerMatchesFailureEligibleForFallback($exception))throw $exception;
            $fallbackEntry=$this->playerMatchesFallback->activate($username,$exception->getMessage());
            return $this->continuePlayerMatchesArchiveFallback($jobId,$username,$snapshot,$fallbackEntry,'primary_unusable');
        }
        // A healthy primary endpoint immediately retires any prior PMAF state.
        $this->playerMatchesFallback->clear($username);
        $targetClub = "https://api.chess.com/pub/club/{$this->clubSlug}";
        $finished = $data['finished'];
        $inProgress = $data['in_progress'];

        // v2.9.0: one player can expose hundreds of matches. Processing every row in a
        // single HTTP CRON request can exceed IONOS' request window even after the API
        // call itself has returned. Flatten the authoritative response and checkpoint it
        // into deterministic slices. Follow-up slices normally reuse the gateway cache.
        $finishedCount=count($finished);$inProgressCount=count($inProgress);$totalEntries=$finishedCount+$inProgressCount;
        $offset=max(0,(int)($payload['entry_offset'] ?? 0));
        $sliceChain=(string)($payload['slice_chain'] ?? '');
        if($sliceChain===''){
            $origin=(string)($payload['_queue_item_key'] ?? ('player:' . $usernameKey));
            $sliceChain=substr(hash('sha256',$jobId . '|' . $origin . '|' . $usernameKey),0,16);
        }
        $sliceLimit=max(10,min(100,(int)($this->app['player_match_entries_per_item'] ?? 50)));
        $matching=0;$participations=0;$enqueued=0;$immutableSkipped=0;$deferred=0;$rediscovered=0;
        $seenBoards=[];$queuedBoards=[];$processedEntries=0;$nextOffset=$offset;

        for($i=$offset;$i<$totalEntries && $processedEntries<$sliceLimit;$i++) {
            if (!$this->hasProcessingBudget(5)) { $nextOffset=$i; break; }
            if($i<$finishedCount){$sourceBucket='finished';$entry=$finished[$i]??null;}else{$sourceBucket='in_progress';$entry=$inProgress[$i-$finishedCount]??null;}if(!is_array($entry))continue;
            $processedEntries++;$nextOffset=$i+1;
            $club=rtrim(strtolower((string)($entry['club']??'')),'/');
            if($club!==$targetClub) continue;
            $matching++;
            $boardUrl=trim((string)($entry['board']??''));
            if($boardUrl===''||isset($seenBoards[$boardUrl])) continue;
            $seenBoards[$boardUrl]=true;
            $matchId=$this->extractMatchId($boardUrl,(string)($entry['@id']??$entry['url']??''));
            if($matchId===null) continue;
            $results=is_array($entry['results']??null)?$entry['results']:[];
            $this->repository->upsertParticipation([
                'club_slug'=>$this->clubSlug,'username_key'=>$usernameKey,'username'=>$username,'match_id'=>$matchId,
                'match_url'=>(string)($entry['@id']??$entry['url']??''),'board_url'=>$boardUrl,
                'white_result'=>isset($results['played_as_white'])?(string)$results['played_as_white']:null,
                'black_result'=>isset($results['played_as_black'])?(string)$results['played_as_black']:null,
            ]);
            $participations++;
            $discovery=$this->repository->registerBoardDiscovery($this->clubSlug,$username,$matchId,$boardUrl,$sourceBucket);
            if(($discovery['state']??'')==='complete_immutable'){$immutableSkipped++;continue;}
            if(empty($discovery['due'])){$deferred++;continue;}
            $targetJobId=$this->boardTargetJobId($jobId,$sourceBucket);
            if($this->enqueueBoard($targetJobId,[
                'username'=>$username,'username_key'=>$usernameKey,'match_id'=>$matchId,'board_url'=>$boardUrl,
                'source_bucket'=>$sourceBucket,'state'=>(string)($discovery['state']??'newly_discovered'),
            ])){$enqueued++;$queuedBoards[$boardUrl]=true;}
        }

        $continuationQueued=false;$freshnessWritten=false;
        $complete=$nextOffset >= $totalEntries;
        if(!$complete){
            $continuationQueued=$this->repository->enqueue(
                $jobId,'sync_player','player-slice:' . $usernameKey . ':' . $sliceChain . ':' . $nextOffset,
                ['username'=>$username,'entry_offset'=>$nextOffset,'slice_chain'=>$sliceChain,'reconciliation'=>!empty($payload['reconciliation']),'authoritative_audit'=>!empty($payload['authoritative_audit']),'continuation'=>true]
            );
        } else {
            // The authoritative scan is complete at this point. Commit its freshness
            // immediately, before optional rediscovery and irrespective of remaining
            // worker budget. A queue item must never become done without this write.
            $freshnessWritten=$this->repository->markPlayerMatchesChecked($this->clubSlug,$username);
            if(!$freshnessWritten)throw new \RuntimeException('Completed player-match scan could not persist player_matches_checked_at for ' . $username . '.');
            // Rediscovery is useful follow-on maintenance, not part of scan completion.
            if($this->hasProcessingBudget(5)){
                $rediscoveryLimit=max(1,min(50,(int)($this->app['board_rediscovery_limit_per_player_segment']??40)));
                foreach($this->repository->dueBoardRediscoveries($this->clubSlug,$usernameKey,$rediscoveryLimit) as $board){
                    if(!$this->hasProcessingBudget(4)) break;
                    $boardUrl=trim((string)($board['board_url']??''));$matchId=(int)($board['match_id']??0);
                    if($boardUrl===''||$matchId<=0||isset($queuedBoards[$boardUrl]))continue;
                    $targetJobId=$this->boardTargetJobId($jobId,(string)($board['source_bucket']??'rediscovered'));
                    if($this->enqueueBoard($targetJobId,$board,true)){$enqueued++;$rediscovered++;$queuedBoards[$boardUrl]=true;}
                }
            }
        }

        return [
            'username'=>$username,'finished_matches_available'=>count($finished),'in_progress_matches_available'=>count($inProgress),
            'entries_total'=>$totalEntries,'entry_offset'=>$offset,'entries_processed'=>$processedEntries,'next_entry_offset'=>$complete?null:$nextOffset,
            'checkpointed'=>!$complete,'continuation_enqueued'=>$continuationQueued,'club_matches_found_in_slice'=>$matching,
            'participations_upserted'=>$participations,'board_tasks_enqueued'=>$enqueued,'immutable_boards_skipped'=>$immutableSkipped,
            'boards_deferred_until_due'=>$deferred,'boards_rediscovered_from_database'=>$rediscovered,'freshness_written'=>$freshnessWritten,'source_url'=>$url,
            'algorithm'=>'bounded_cached_player_match_slices_v297',
        ];
    }

    private function playerMatchesFailureEligibleForFallback(\Throwable $exception): bool
    {
        if($exception instanceof RetryableException)return false;
        $message=strtolower($exception->getMessage());
        if(str_contains($message,'pmaf_invalid_player_matches_payload'))return true;
        if(preg_match('/http\s+(404|410)\b/',$message)===1)return true;
        // Authentication/authorization failures are never endpoint-specific evidence.
        if(preg_match('/http\s+(401|403|429)\b/',$message)===1)return false;
        return false;
    }

    /** @return list<string> */
    private function playerArchiveMonthsFromIndex(array $payload): array
    {
        $out=[];
        foreach(is_array($payload['archives']??null)?$payload['archives']:[] as $url){
            $path=strtolower((string)(parse_url((string)$url,PHP_URL_PATH)?:''));
            if(preg_match('~/games/(20\d{2})/(0[1-9]|1[0-2])/?$~',$path,$m)===1)$out[$m[1].'-'.$m[2]]=true;
        }
        $months=array_keys($out);rsort($months,SORT_STRING);return $months;
    }

    private function continuePlayerMatchesArchiveFallback(string $jobId,string $username,?array $snapshot,array $entry,string $reason): array
    {
        if(!is_array($snapshot)||(int)($snapshot['current_member']??0)!==1){
            $this->playerMatchesFallback->clear($username);
            throw new \RuntimeException('PMAF fallback refused because the player is not a current known-valid P2K member.');
        }
        $generation=max(1,(int)($entry['generation']??1));
        $indexGeneration=(int)($entry['archive_index_generation']??0);
        $indexFetched=false;$indexUrl='https://api.chess.com/pub/player/'.rawurlencode($username).'/games/archives';
        if($indexGeneration<$generation||!is_array($entry['archive_months']??null)){
            try{$index=$this->api->jsonIfExists($indexUrl,false);}catch(RetryableException $exception){throw $exception;}
            if($index===null)throw new RetryableException('PMAF archive index is temporarily unavailable for known-valid player '.$username.'.',3600);
            if(!array_key_exists('archives',$index)||!is_array($index['archives']))throw new RetryableException('PMAF archive index returned an invalid payload for '.$username.'.',3600);
            $entry=$this->playerMatchesFallback->recordArchiveIndex($username,$this->playerArchiveMonthsFromIndex($index));$indexFetched=true;
        }
        $batch=max(1,min(12,(int)($this->app['player_matches_fallback_archive_batch']??6)));
        $months=$this->playerMatchesFallback->takePendingMonths($username,$batch);$scheduled=[];$queued=0;
        foreach($months as $month){
            if($this->enqueuePlayerArchive($jobId,$username,$month,['source'=>'player_matches_archive_fallback','pmaf_generation'=>$generation]))$queued++;
            $scheduled[]=$month;
        }
        $entry=$this->playerMatchesFallback->markMonthsScheduled($username,$scheduled);
        $remaining=count(is_array($entry['pending_months']??null)?$entry['pending_months']:[]);
        $continuation=false;$freshness=false;$verification=$this->playerMatchesFallback->verificationStatus($username);
        if($remaining>0){
            $continuation=$this->repository->enqueue($jobId,'sync_player','pmaf-continuation:'.\p2k_tp_username_key($username).':'.$generation.':'.$remaining,[
                'username'=>$username,'force'=>true,'pmaf_continue'=>true,'source'=>'player_matches_archive_fallback'
            ]);
        }elseif(empty($verification['complete'])){
            // ACDC expansion: queued archive work is not canonical verification. Keep
            // this sync_player item retryable until every generation-scoped archive
            // month has actually completed successfully. Failed months remain visible
            // in the bounded PMAF ledger and their durable queue retries can recover.
            throw new RetryableException(sprintf(
                'PMAF verification pending for %s: %d/%d archive months succeeded, %d failed, %d unresolved.',
                $username,(int)($verification['succeeded']??0),(int)($verification['required']??0),(int)($verification['failed']??0),(int)($verification['unresolved']??0)
            ),60);
        }else{
            $freshness=$this->repository->markPlayerMatchesChecked($this->clubSlug,$username);
            if(!$freshness)throw new \RuntimeException('PMAF verified all archive months but could not persist player_matches_checked_at for '.$username.'.');
        }
        return [
            'username'=>$username,'fallback'=>'player_games_archives','fallback_reason'=>$reason,
            'primary_matches_endpoint'=>'unusable','primary_reprobe_at'=>$entry['next_primary_probe_at']??null,
            'archive_index_url'=>$indexUrl,'archive_index_fetched'=>$indexFetched,
            'archive_months_available'=>count(is_array($entry['archive_months']??null)?$entry['archive_months']:[]),
            'archive_months_scheduled_this_item'=>count($scheduled),'archive_tasks_enqueued'=>$queued,
            'archive_months_remaining'=>$remaining,'continuation_enqueued'=>$continuation,'freshness_written'=>$freshness,
            'archive_verification'=>$verification,'checkpointed'=>$remaining>0||empty($verification['complete']),'verification'=>'authoritative_archive_index_plus_completed_server_archive_tasks',
            'algorithm'=>'pmaf_bounded_archive_fallback_v1',
        ];
    }

    private function reconcileMembers(string $jobId, array $payload): array
    {
        if ($this->normalizedLane() !== 'player') return ['players_queued'=>0,'next_cursor'=>0,'complete'=>true];
        $after=max(0,(int)($payload['after_member_id']??0));
        $batch=max(50,min(500,(int)($payload['batch_size']??($this->app['player_reconcile_batch_size']??250))));
        // Bulk current-member reconciliation must be sustainable even with ~4,000 members.
        // Match-history refresh is weekly; ratings are checked every three days. Urgent
        // boards/matches remain on the fast Club lane and ACAMR can accelerate due work.
        $matchesFresh=max(86400,(int)($this->app['player_reconcile_matches_refresh_seconds']??604800));
        $statsFresh=max(86400,(int)($this->app['player_reconcile_stats_refresh_seconds']??259200));
        $matchesAudit=max($matchesFresh,(int)($this->app['player_matches_authoritative_audit_seconds']??2592000));
        $statsAudit=max($statsFresh,(int)($this->app['player_stats_authoritative_audit_seconds']??604800));
        $members=$this->repository->currentMembersAfterId($this->clubSlug,$after,$batch);
        $queued=0;$stats=0;$freshSkipped=0;$observedSuppressed=0;$auditQueued=0;$next=$after;$now=time();
        foreach($members as $member){
            $id=(int)($member['member_id']??0);if($id>$next)$next=$id;
            $username=(string)($member['username']??'');$key=(string)($member['username_key']??'');
            if($username==='')continue;
            $matchesChecked=!empty($member['player_matches_checked_at'])?(strtotime((string)$member['player_matches_checked_at'].' UTC')?:0):0;
            $matchesObserved=!empty($member['player_matches_observed_at'])?(strtotime((string)$member['player_matches_observed_at'].' UTC')?:0):0;
            $matchesUnverified=!empty($member['player_matches_unverified_since'])?(strtotime((string)$member['player_matches_unverified_since'].' UTC')?:0):0;
            $statsChecked=!empty($member['stats_checked_at'])?(strtotime((string)$member['stats_checked_at'].' UTC')?:0):0;
            $statsObserved=!empty($member['stats_observed_at'])?(strtotime((string)$member['stats_observed_at'].' UTC')?:0):0;
            $statsUnverified=!empty($member['stats_unverified_since'])?(strtotime((string)$member['stats_unverified_since'].' UTC')?:0):0;
            $matchesCoverage=max($matchesChecked,$matchesObserved);$statsCoverage=max($statsChecked,$statsObserved);
            $matchesRoutineDue=$matchesCoverage<=0||$now-$matchesCoverage>=$matchesFresh;
            $statsRoutineDue=$statsCoverage<=0||$now-$statsCoverage>=$statsFresh;
            $matchesAuditDue=$matchesChecked>0?($now-$matchesChecked>=$matchesAudit):($matchesUnverified<=0||$now-$matchesUnverified>=$matchesAudit);
            $statsAuditDue=$statsChecked>0?($now-$statsChecked>=$statsAudit):($statsUnverified<=0||$now-$statsUnverified>=$statsAudit);
            $matchesDue=$matchesRoutineDue||$matchesAuditDue;$statsDue=$statsRoutineDue||$statsAuditDue;
            if($matchesDue && $this->repository->enqueue($jobId,'sync_player','reconcile-player:' . $id . ':' . $key,['username'=>$username,'reconciliation'=>true,'authoritative_audit'=>$matchesAuditDue])){$queued++;if($matchesAuditDue)$auditQueued++;}
            if($statsDue && $this->repository->enqueue($jobId,'sync_player_stats','reconcile-stats:' . $id . ':' . $key,['username'=>$username,'reconciliation'=>true,'authoritative_audit'=>$statsAuditDue])){$stats++;if($statsAuditDue)$auditQueued++;}
            if(!$matchesDue&&!$statsDue){$freshSkipped++;if($matchesObserved>$matchesChecked||$statsObserved>$statsChecked)$observedSuppressed++;}
        }
        $complete=count($members)<$batch;
        $nextQueued=false;
        if(!$complete && $next>$after){
            // Reconciliation pages outrank expensive player requests, so all due members
            // are enumerated quickly and the queue becomes a finite measurable set.
            $nextQueued=$this->repository->enqueue($jobId,'reconcile_members','reconcile-page:' . $next,['after_member_id'=>$next,'batch_size'=>$batch]);
        }
        return ['players_queued'=>$queued,'stats_queued'=>$stats,'authoritative_audits_queued'=>$auditQueued,'claim_observation_suppressed'=>$observedSuppressed,'fresh_members_skipped'=>$freshSkipped,'members_in_page'=>count($members),'next_cursor'=>$next,'next_page_queued'=>$nextQueued,'complete'=>$complete];
    }

    private function syncPlayerStats(array $payload): array
    {
        $username=trim((string)($payload['username']??''));
        if($username===''||!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$username))throw new \RuntimeException('A sync_player_stats item has no valid username.');
        $snapshot=$this->repository->memberRefreshSnapshot($this->clubSlug,$username);
        $statsFresh=max(86400,(int)($this->app['player_reconcile_stats_refresh_seconds']??259200));
        $statsAudit=max($statsFresh,(int)($this->app['player_stats_authoritative_audit_seconds']??604800));
        $now=time();$checkedAt=is_array($snapshot)&&!empty($snapshot['stats_checked_at'])?(strtotime((string)$snapshot['stats_checked_at'].' UTC')?:0):0;
        $observedAt=is_array($snapshot)&&!empty($snapshot['stats_observed_at'])?(strtotime((string)$snapshot['stats_observed_at'].' UTC')?:0):0;
        $unverifiedSince=is_array($snapshot)&&!empty($snapshot['stats_unverified_since'])?(strtotime((string)$snapshot['stats_unverified_since'].' UTC')?:0):0;
        $coverageAt=max($checkedAt,$observedAt);$auditDue=$checkedAt>0?($now-$checkedAt>=$statsAudit):($unverifiedSince<=0||$now-$unverifiedSince>=$statsAudit);
        if(empty($payload['force']) && empty($payload['authoritative_audit']) && is_array($snapshot) && $coverageAt>0 && $now-$coverageAt<$statsFresh && !$auditDue) {
            return ['username'=>$username,'skip_reason'=>'claim-backed observed stats freshness is current; authoritative audit not yet due','freshness_at'=>gmdate('Y-m-d H:i:s',$coverageAt),'verified_at'=>$snapshot['stats_checked_at']??null,'observed_at'=>$snapshot['stats_observed_at']??null,'_queue_status'=>'skipped'];
        }
        $url='https://api.chess.com/pub/player/'.rawurlencode(strtolower($username)).'/stats';
        $stats=$this->api->json($url,true); $ratings=Repository::ratingsFromStats($stats);
        $stored=$this->repository->storeMemberRatings($this->clubSlug,$username,$ratings['daily_rating'],$ratings['chess960_rating']);
        return ['username'=>$username,'stored'=>$stored,'daily_rating'=>$ratings['daily_rating'],'chess960_rating'=>$ratings['chess960_rating'],'source_url'=>$url];
    }

    private function syncPlayerProfile(array $payload): array
    {
        $username=trim((string)($payload['username']??''));
        if($username===''||!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$username))throw new \RuntimeException('A sync_player_profile item has no valid username.');
        $snapshot=$this->repository->memberRefreshSnapshot($this->clubSlug,$username);
        if(empty($payload['force']) && is_array($snapshot) && $this->refreshTimestampIsFresh($snapshot['profile_updated_at']??null,2592000)) {
            return ['username'=>$username,'skip_reason'=>'player profile is already current','freshness_at'=>$snapshot['profile_updated_at']??null,'_queue_status'=>'skipped'];
        }
        if($this->repository->playerProfileSnapshot($this->clubSlug,$username)===null)return ['username'=>$username,'stored'=>false,'known_member'=>false,'_queue_status'=>'skipped','skip_reason'=>'member no longer exists'];
        $url='https://api.chess.com/pub/player/'.rawurlencode(strtolower($username));
        $profile=$this->api->json($url,true);
        $returned=trim((string)($profile['username']??''));
        if($returned!=='' && \p2k_tp_username_key($returned)!==\p2k_tp_username_key($username))throw new RetryableException('Chess.com player profile username did not match the requested member.',60);
        $stored=$this->repository->storePlayerProfileSnapshot($this->clubSlug,$username,$profile,true);
        return ['username'=>$username,'stored'=>$stored,'known_member'=>true,'source_url'=>$url];
    }

    private function recentArchiveMonths(): array
    {
        $now = new \DateTimeImmutable('first day of this month',new \DateTimeZone('UTC'));
        return [$now->format('Y-m'),$now->modify('-1 month')->format('Y-m')];
    }

    private function archiveMonthsForMatch(array $match): array
    {
        $months=$this->recentArchiveMonths();
        $end=(int)($match['end_time']??0);
        if($end>0) $months[]=(new \DateTimeImmutable('@'.$end))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
        return array_values(array_unique($months));
    }

    private function enqueuePlayerArchive(string $jobId,string $username,string $month,array $extra=[]): bool
    {
        $username=trim($username);
        if($username===''||!preg_match('/^\d{4}-\d{2}$/',$month)) return false;
        $key='archive|' . \p2k_tp_username_key($username) . '|' . $month;
        return $this->repository->enqueue($jobId,'sync_player_archive',$key,array_merge(['username'=>$username,'month'=>$month],$extra));
    }

    private function syncPlayerArchive(string $jobId,array $payload): array
    {
        $username=trim((string)($payload['username']??''));
        $month=trim((string)($payload['month']??''));
        if($username===''||!preg_match('/^(\d{4})-(\d{2})$/',$month,$parts)) throw new \RuntimeException('A sync_player_archive item is incomplete.');
        $url='https://api.chess.com/pub/player/' . rawurlencode($username) . '/games/' . $parts[1] . '/' . $parts[2];
        $pmaf=(string)($payload['source']??'')==='player_matches_archive_fallback'||!empty($payload['pmaf_replay']);
        $pmafGeneration=max(0,(int)($payload['pmaf_generation']??0));
        try{
            $data=$this->api->jsonIfExists($url,false);
            if($pmaf&&$data===null)throw new RetryableException('PMAF listed archive month returned no authoritative payload: '.$username.' '.$month,3600);
        }catch(\Throwable $exception){
            if($pmaf&&$pmafGeneration>0)$this->playerMatchesFallback->markMonthFailed($username,$month,$pmafGeneration,$exception->getMessage());
            throw $exception;
        }
        $games=is_array($data['games']??null)?$data['games']:[];
        $usernameKey=\p2k_tp_username_key($username); $matched=0; $stored=0; $boards=[];$pmafMatchesQueued=0;$knownWithoutParticipation=0;
        foreach($games as $game){
            if(!is_array($game)) continue;
            $matchId=$this->extractMatchId((string)($game['match']??''));
            if($matchId===null) continue;
            $participation=$this->repository->participationForMatch($this->clubSlug,$usernameKey,$matchId);
            if(!is_array($participation)){
                // A personal game archive contains all clubs. PMAF therefore requests
                // verification only for IDs that the canonical P2K store already knows;
                // unknown non-P2K history is never fanned out into sync_match traffic.
                if($pmaf&&$this->repository->isKnownMatch($this->clubSlug,$matchId)){
                    $knownWithoutParticipation++;
                    if($this->repository->enqueue($this->clubJobId(),'sync_match','pmaf-archive-match:'.$matchId,[
                        'match_id'=>$matchId,'source'=>'player_archive_fallback','source_bucket'=>'finished','priority_discovery'=>true,
                        'pmaf_username'=>$username,'pmaf_month'=>$month,'pmaf_generation'=>$pmafGeneration
                    ]))$pmafMatchesQueued++;
                }
                continue;
            }
            $side=$this->playerSide($game,$username);
            if($side===null||!is_array($game[$side]??null)) continue;
            $end=$this->gameEndTime($game); $result=$this->resultForSide($game,$side);
            if($end<=0||$result==='') continue;
            $gameUrl=trim((string)($game['url']??$game['@id']??''));
            if($gameUrl==='') $gameUrl=(string)$participation['board_url'] . '#archive-' . $end;
            $date=(new \DateTimeImmutable('@'.$end))->setTimezone(new \DateTimeZone('UTC'));
            [$p2kRating,$opponentRating]=$this->ratingPairForSide($game,$side);
            if($this->repository->upsertPointEvent([
                'club_slug'=>$this->clubSlug,'username_key'=>$usernameKey,'username'=>$username,'match_id'=>$matchId,
                'board_url'=>(string)$participation['board_url'],'game_url'=>$gameUrl,'game_end_utc'=>$date->format('Y-m-d H:i:s'),
                'utc_month'=>$date->format('Y-m-01'),'result_code'=>$result,'points'=>$this->pointsForResult($result),
                'p2k_rating'=>$p2kRating,'opponent_rating'=>$opponentRating,'rating_source'=>'board_game',
                'source_hash'=>hash('sha256',json_encode($game,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)),
            ])) $stored++;
            $matched++; $boards[$matchId.'|'.$participation['board_url']]=[$matchId,(string)$participation['board_url']];
        }
        $completed=0;
        foreach($boards as [$matchId,$boardUrl]){
            $known=$this->repository->boardState($this->clubSlug,$usernameKey,$boardUrl);
            $sourceBucket=is_array($known)?(string)($known['source_bucket']??'unknown'):'unknown';
            if(!in_array($sourceBucket,['finished','in_progress','registered','rediscovered','unknown'],true))$sourceBucket='unknown';
            $count=$this->repository->refreshBoardStateFromEvents($this->clubSlug,$username,(int)$matchId,$boardUrl,$sourceBucket);
            if($count>=2)$completed++;
        }
        // If a known P2K match still lacks participation, the authoritative match
        // verification/replay is part of PMAF completion; do not attest this month yet.
        if($pmaf&&$pmafGeneration>0){
            if($knownWithoutParticipation>0){
                $this->playerMatchesFallback->markMonthFailed($username,$month,$pmafGeneration,'awaiting authoritative match verification and archive replay');
            }else{
                $this->playerMatchesFallback->markMonthSucceeded($username,$month,$pmafGeneration);
            }
        }
        return ['username'=>$username,'month'=>$month,'archive_games_scanned'=>count($games),'team_games_matched'=>$matched,'new_point_events_stored'=>$stored,'boards_completed'=>$completed,'pmaf_known_matches_without_participation'=>$knownWithoutParticipation,'pmaf_match_verifications_queued'=>$pmafMatchesQueued,'pmaf_generation'=>$pmafGeneration,'pmaf_month_verified'=>$pmaf&&$pmafGeneration>0&&$knownWithoutParticipation===0,'source_url'=>$url];
    }

    private function syncOpponentProfile(array $payload): array
    {
        $slug=strtolower(trim((string)($payload['opponent_slug']??'')));
        if($slug===''||!preg_match('/^[a-z0-9_-]{1,100}$/',$slug))throw new \RuntimeException('A sync_opponent_profile item is incomplete.');
        $url='https://api.chess.com/pub/club/'.rawurlencode($slug);
        $profile=$this->api->json($url,true);
        $updated=$this->repository->storeOpponentProfileSnapshot($this->clubSlug,$slug,$profile,true);
        return ['opponent_slug'=>$slug,'updated'=>$updated,'country'=>(string)($profile['country']??''),'source_url'=>$url];
    }

    private function enqueueBoard(string $jobId, array $board, bool $rediscovered = false): bool
    {
        $username = trim((string)($board['username'] ?? ''));
        $usernameKey = trim((string)($board['username_key'] ?? ''));
        if ($usernameKey === '' && $username !== '') {
            $usernameKey = \p2k_tp_username_key($username);
        }
        $boardUrl = trim((string)($board['board_url'] ?? ''));
        $matchId = (int)($board['match_id'] ?? 0);
        if ($username === '' || $usernameKey === '' || $boardUrl === '' || $matchId <= 0) {
            return false;
        }
        $sourceBucket = trim((string)($board['source_bucket'] ?? 'rediscovered'));
        if (!in_array($sourceBucket, ['finished', 'in_progress', 'registered', 'rediscovered', 'unknown'], true)) {
            $sourceBucket = 'unknown';
        }
        $itemKey = $usernameKey . '|' . hash('sha256', $boardUrl);
        return $this->repository->enqueue($jobId, 'sync_board', $itemKey, [
            'username' => $username,
            'match_id' => $matchId,
            'board_url' => $boardUrl,
            'source_bucket' => $sourceBucket,
            'board_state' => (string)($board['state'] ?? $board['board_state'] ?? 'newly_discovered'),
            'rediscovered' => $rediscovered,
        ]);
    }

    private function syncBoard(array $payload): array
    {
        $username = trim((string)($payload['username'] ?? ''));
        $boardUrl = trim((string)($payload['board_url'] ?? ''));
        $matchId = (int)($payload['match_id'] ?? 0);
        if ($username === '' || $boardUrl === '' || $matchId <= 0) {
            throw new \RuntimeException('A sync_board item is incomplete.');
        }

        $usernameKey = \p2k_tp_username_key($username);
        $knownState = $this->repository->boardState($this->clubSlug, $usernameKey, $boardUrl);
        $sourceBucket = trim((string)($payload['source_bucket'] ?? $knownState['source_bucket'] ?? 'rediscovered'));
        if (!in_array($sourceBucket, ['finished', 'in_progress', 'registered', 'rediscovered', 'unknown'], true)) {
            $sourceBucket = 'unknown';
        }

        $storedBefore = $knownState === null
            ? $this->repository->pointEventCount($this->clubSlug, $usernameKey, $boardUrl)
            : (int)($knownState['finished_game_count'] ?? 0);
        if ($storedBefore >= 2 && empty($payload['force_revalidate'])) {
            $this->repository->markBoardChecked(
                $this->clubSlug, $username, $matchId, $boardUrl, $sourceBucket,
                'complete_immutable', $storedBefore, null
            );
            $summaryFinalized = $this->finalizeMatchSummaryAuthoritatively($matchId);
            return [
                'username' => $username,
                'match_id' => $matchId,
                'board_url' => $boardUrl,
                'classification' => 'complete_immutable',
                'game_records_found' => 0,
                'point_events_upserted' => 0,
                'finished_games_stored' => $storedBefore,
                'api_request_skipped' => true,
                'match_summary_finalized' => $summaryFinalized,
            ];
        }

        try {
            $data = $this->api->json($boardUrl, true);
        } catch (RetryableException $exception) {
            $this->recordBoardFailure($username, $matchId, $boardUrl, $sourceBucket, $storedBefore, $exception->getMessage());
            throw $exception;
        } catch (\Throwable $exception) {
            $this->recordBoardFailure($username, $matchId, $boardUrl, $sourceBucket, $storedBefore, $exception->getMessage());
            throw $exception;
        }

        $games = $this->extractGames($data);
        if ($games === []) {
            $message = "No game records were found in board payload {$boardUrl}";
            $this->recordBoardFailure($username, $matchId, $boardUrl, $sourceBucket, $storedBefore, $message);
            throw new RetryableException($message, 300);
        }

        $storedThisRun = 0;
        $newlyStored = 0;
        $normalizedFinishedGameUrls = [];
        $playerGames = 0;
        $unfinishedGames = 0;
        $malformedGames = 0;
        foreach ($games as $index => $game) {
            if (!is_array($game)) {
                $malformedGames++;
                continue;
            }
            $side = $this->playerSide($game, $username);
            if ($side === null) {
                continue;
            }
            $playerGames++;
            $endTime = $this->gameEndTime($game);
            if ($endTime <= 0) {
                $unfinishedGames++;
                continue;
            }
            $result = $this->resultForSide($game, $side);
            if ($result === '') {
                $malformedGames++;
                continue;
            }
            $end = (new \DateTimeImmutable('@' . $endTime))->setTimezone(new \DateTimeZone('UTC'));
            $gameUrl = trim((string)($game['url'] ?? $game['@id'] ?? ''));
            if ($gameUrl === '') {
                $gameUrl = $boardUrl . '#game-' . ($index + 1) . '-' . $endTime;
            }
            $normalizedFinishedGameUrls[$gameUrl] = true;
            $source = json_encode($game, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            [$p2kRating,$opponentRating]=$this->ratingPairForSide($game,$side);
            $inserted = $this->repository->upsertPointEvent([
                'club_slug' => $this->clubSlug,
                'username_key' => $usernameKey,
                'username' => $username,
                'match_id' => $matchId,
                'board_url' => $boardUrl,
                // P2K_BOARD_SLOT_CANONICAL_V1: the Chess.com board-array index is the physical game slot.
                'sequence_no' => $index + 1,
                'game_url' => $gameUrl,
                'game_end_utc' => $end->format('Y-m-d H:i:s'),
                'utc_month' => $end->format('Y-m-01'),
                'result_code' => $result,
                'points' => $this->pointsForResult($result),
                'p2k_rating' => $p2kRating,
                'opponent_rating' => $opponentRating,
                'rating_source' => 'board_game',
                'source_hash' => hash('sha256', $source),
            ]);
            $storedThisRun++;
            if ($inserted) {
                $newlyStored++;
            }
        }

        // A team-match board contains at most two games. The state row gives the
        // previous durable count and INSERT-vs-update tells us whether this run
        // added a new event, avoiding another board-wide event-table search.
        $finishedCount = min(2, max($storedBefore + $newlyStored, count($normalizedFinishedGameUrls)));
        $singleUnfinishedGameAwaitingSecond = count($games) === 1
            && $playerGames === 1
            && $unfinishedGames === 1
            && $finishedCount === 0;
        if ($finishedCount >= 2) {
            $classification = 'complete_immutable';
            $nextCheckSeconds = null;
        } elseif ($finishedCount === 1) {
            // In one-concurrent-game matches Chess.com may create game two only
            // after game one finishes. A missing second game is therefore merely
            // incomplete after the first result exists, never malformed by itself.
            $classification = 'potentially_incomplete';
            $nextCheckSeconds = $this->boardInterval('board_recheck_incomplete_seconds', 21600);
        } elseif ($singleUnfinishedGameAwaitingSecond) {
            // Normal sequential-game state: game one is still running and game two
            // has not been created yet. Recheck it as in progress without recording
            // a failure or increasing the malformed-board failure counter.
            $classification = 'recent_in_progress';
            $nextCheckSeconds = $this->boardInterval('board_recheck_in_progress_seconds', 21600);
        } elseif ($playerGames > 0 && $unfinishedGames > 0) {
            $classification = 'recent_in_progress';
            $nextCheckSeconds = $this->boardInterval('board_recheck_in_progress_seconds', 21600);
        } else {
            $message = $playerGames === 0
                ? "The board payload does not contain a game for {$username}."
                : "The board payload for {$username} is malformed or has no normalizable game.";
            $this->recordBoardFailure($username, $matchId, $boardUrl, $sourceBucket, $finishedCount, $message);
            throw new RetryableException($message, 300);
        }

        $this->repository->markBoardChecked(
            $this->clubSlug,
            $username,
            $matchId,
            $boardUrl,
            $sourceBucket,
            $classification,
            $finishedCount,
            $nextCheckSeconds,
            $singleUnfinishedGameAwaitingSecond
                ? 'One-concurrent-game match: the second game has not been created while the first remains in progress.'
                : ($malformedGames > 0 ? "{$malformedGames} malformed game record(s) ignored." : null)
        );
        $summaryFinalized = $classification === 'complete_immutable'
            ? $this->finalizeMatchSummaryAuthoritatively($matchId)
            : false;

        return [
            'username' => $username,
            'match_id' => $matchId,
            'board_url' => $boardUrl,
            'classification' => $classification,
            'game_records_found' => count($games),
            'player_game_records_found' => $playerGames,
            'unfinished_game_records' => $unfinishedGames,
            'malformed_game_records' => $malformedGames,
            'point_events_upserted' => $storedThisRun,
            'new_point_events_stored' => $newlyStored,
            'finished_games_stored' => $finishedCount,
            'next_check_seconds' => $nextCheckSeconds,
            'sequential_second_game_pending' => $singleUnfinishedGameAwaitingSecond,
            'api_request_skipped' => false,
            'match_summary_finalized' => $summaryFinalized,
        ];
    }

    private function finalizeMatchSummaryAuthoritatively(int $matchId): bool
    {
        if ($matchId <= 0 || isset($this->finalizationAttempted[$matchId]) || !$this->hasOutboundRequestBudget()) return false;
        if(!$this->repository->matchReadyForAuthoritativeFinalization($this->clubSlug,$matchId))return false;
        $this->finalizationAttempted[$matchId]=true;
        try {
            $match = $this->api->json('https://api.chess.com/pub/match/' . $matchId, true);
            return $this->repository->finalizeMatchSummaryIfComplete($this->clubSlug, $matchId, $match);
        } catch (\Throwable) {
            return false;
        }
    }

    private function recordBoardFailure(
        string $username,
        int $matchId,
        string $boardUrl,
        string $sourceBucket,
        int $finishedCount,
        string $message
    ): void {
        $state = $finishedCount === 1 ? 'potentially_incomplete' : 'failed_malformed';
        $this->repository->markBoardChecked(
            $this->clubSlug,
            $username,
            $matchId,
            $boardUrl,
            $sourceBucket,
            $state,
            $finishedCount,
            $this->boardInterval('board_retry_failed_seconds', 3600),
            $message,
            true
        );
    }

    private function boardInterval(string $key, int $default): int
    {
        return max(300, (int)($this->app[$key] ?? $default));
    }

    private function successMessage(string $type, array $details): string
    {
        return match ($type) {
            'sync_club_matches' => sprintf('Club match index synchronized: %d visible match detail task(s) and %d raw discovery chain(s) added.', (int)($details['match_tasks_enqueued'] ?? 0), (int)($details['raw_discovery_chains_enqueued'] ?? 0)),
            'sync_match' => !empty($details['is_p2k_match'])
                ? sprintf('Match %d synchronized: %d participation(s), %d board task(s) added.', (int)($details['match_id'] ?? 0), (int)($details['participations_upserted'] ?? 0), (int)($details['board_tasks_enqueued'] ?? 0))
                : sprintf('Match %d does not involve Promote to King.', (int)($details['match_id'] ?? 0)),
            'discover_match_ids' => sprintf('Raw match-ID discovery scanned %d ID(s), found %d P2K match(es), next cursor %s.', (int)($details['scanned'] ?? 0), (int)($details['matched'] ?? 0), ($details['next_cursor'] ?? null) === null ? 'complete' : (string)$details['next_cursor']),
            'sync_roster' => sprintf('Lightweight member roster synchronized: %d member(s).',(int)($details['members_found']??0)),
            'sync_members' => sprintf('Member roster synchronized: %d member(s), %d explicit player-repair task(s) and %d due board fallback task(s) added.', (int)($details['members_found'] ?? 0), (int)($details['player_tasks_enqueued'] ?? 0), (int)($details['board_tasks_rediscovered'] ?? 0)),
            'sync_player_archive' => sprintf('Player archive synchronized: %s %s, %d team game(s) matched, %d new event(s).',(string)($details['username']??''),(string)($details['month']??''),(int)($details['team_games_matched']??0),(int)($details['new_point_events_stored']??0)),
            'sync_player_stats' => sprintf('Player ratings verified: %s.',(string)($details['username']??'')),
            'sync_player_profile' => sprintf('Player profile/avatar verified: %s.',(string)($details['username']??'')),
            'sync_opponent_profile' => sprintf('Opponent profile/country verified: %s.',(string)($details['opponent_slug']??'')),
            'reconcile_members' => sprintf('Player reconciliation page queued %d member checks; next cursor %d.',(int)($details['players_queued']??0),(int)($details['next_cursor']??0)),
            'sync_player' => sprintf(
                'Player synchronized: %s, %d finished and %d in-progress match(es) scanned, %d board task(s) added (%d rediscovered, %d immutable skipped).',
                (string)($details['username'] ?? ''),
                (int)($details['finished_matches_scanned'] ?? 0),
                (int)($details['in_progress_matches_scanned'] ?? 0),
                (int)($details['board_tasks_enqueued'] ?? 0),
                (int)($details['boards_rediscovered_from_database'] ?? 0),
                (int)($details['immutable_boards_skipped'] ?? 0)
            ),
            'sync_board' => sprintf('Board classified %s: %s, match %d, %d finished game(s) stored.', (string)($details['classification'] ?? 'unknown'), (string)($details['username'] ?? ''), (int)($details['match_id'] ?? 0), (int)($details['finished_games_stored'] ?? 0)),
            default => 'Task completed.',
        };
    }

    private function safePayload(array $payload): array
    {
        $safe = $payload;
        if (isset($safe['board_url'])) {
            $safe['board_url'] = substr((string)$safe['board_url'], 0, 255);
        }
        return $safe;
    }

    private function secondsUntil(?string $timestamp): int
    {
        if ($timestamp === null) {
            return 5;
        }
        try {
            $then = new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC'));
            return max(1, $then->getTimestamp() - time());
        } catch (\Throwable) {
            return 5;
        }
    }

    private function extractGames(array $data): array
    {
        foreach (['games', 'game'] as $key) {
            if (!isset($data[$key]) || !is_array($data[$key])) continue;
            $value = $data[$key];
            if (array_is_list($value)) {
                $list = array_values(array_filter($value, 'is_array'));
                if ($list !== []) return $list;
            }
            if (isset($value['white']) || isset($value['black']) || isset($value['players'])) {
                return [$value];
            }
        }
        $games = [];
        foreach (['game_1', 'game_2', 'first_game', 'second_game'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) $games[] = $data[$key];
        }
        if ($games !== []) return $games;
        foreach ($data as $value) {
            if (is_array($value) && (isset($value['white'], $value['black']) || isset($value['players']))) $games[] = $value;
        }
        return $games;
    }

    private function usernameFromPlayer(mixed $player): string
    {
        if (is_string($player)) {
            $value = trim($player);
            if ($value === '') return '';
            $path = parse_url($value, PHP_URL_PATH);
            if (is_string($path) && str_contains($value, '/')) {
                $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn(string $v): bool => $v !== ''));
                if ($parts !== []) return rawurldecode((string)end($parts));
            }
            return $value;
        }
        if (!is_array($player)) return '';
        foreach (['username','name'] as $field) {
            $value = trim((string)($player[$field] ?? ''));
            if ($value !== '') return $value;
        }
        foreach (['@id','url','player'] as $field) {
            $value = $this->usernameFromPlayer($player[$field] ?? null);
            if ($value !== '') return $value;
        }
        return '';
    }

    private function playerSide(array $game, string $username): ?string
    {
        $key = \p2k_tp_username_key($username);
        $players = is_array($game['players'] ?? null) ? $game['players'] : [];
        foreach (['white', 'black'] as $side) {
            $player = $game[$side] ?? ($players[$side] ?? $game[$side . '_player'] ?? null);
            $candidate = $this->usernameFromPlayer($player);
            if ($candidate !== '' && \p2k_tp_username_key($candidate) === $key) return $side;
        }
        return null;
    }

    private function gameEndTime(array $game): int
    {
        foreach (['end_time','endTime','finished_time','finishedAt'] as $field) {
            $value = $game[$field] ?? null;
            if (!is_numeric($value)) continue;
            $time = (int)$value;
            if ($time > 100000000000) $time = (int)floor($time / 1000);
            if ($time > 0) return $time;
        }
        return 0;
    }

    private function ratingPairForSide(array $game, string $side): array
    {
        $players = is_array($game['players'] ?? null) ? $game['players'] : [];
        $other = $side === 'white' ? 'black' : 'white';
        $mine = $game[$side] ?? ($players[$side] ?? $game[$side . '_player'] ?? null);
        $theirs = $game[$other] ?? ($players[$other] ?? $game[$other . '_player'] ?? null);
        $rating = static function(mixed $player): ?int {
            if (!is_array($player) || !is_numeric($player['rating'] ?? null)) return null;
            $value=(int)$player['rating'];return ($value>=100&&$value<=4000)?$value:null;
        };
        return [$rating($mine),$rating($theirs)];
    }

    private function resultForSide(array $game, string $side): string
    {
        $players = is_array($game['players'] ?? null) ? $game['players'] : [];
        $player = $game[$side] ?? ($players[$side] ?? $game[$side . '_player'] ?? null);
        if (is_array($player)) {
            foreach (['result','outcome'] as $field) {
                $value = strtolower(trim((string)($player[$field] ?? '')));
                if ($value !== '') return $value;
            }
        }
        foreach ([$side . '_result', $side . 'Result'] as $field) {
            $value = strtolower(trim((string)($game[$field] ?? '')));
            if ($value !== '') return $value;
        }
        $results = is_array($game['results'] ?? null) ? $game['results'] : [];
        $value = strtolower(trim((string)($results[$side] ?? '')));
        if ($value !== '') return $value;
        $score = strtolower(trim((string)($game['result'] ?? '')));
        if ($score === '1-0') return $side === 'white' ? 'win' : 'lose';
        if ($score === '0-1') return $side === 'black' ? 'win' : 'lose';
        if (in_array($score, ['1/2-1/2','0.5-0.5','½-½'], true)) return 'agreed';
        return '';
    }

    private function pointsForResult(string $result): float
    {
        if ($result === 'win') {
            return 1.0;
        }
        if (in_array($result, ['agreed', 'repetition', 'stalemate', 'insufficient', '50move', 'timevsinsufficient'], true)) {
            return 0.5;
        }
        return 0.0;
    }

    private function extractMatchId(string ...$values): ?int
    {
        foreach ($values as $value) {
            if (preg_match('~/match/(\d+)~', $value, $match)) {
                return (int)$match[1];
            }
        }
        return null;
    }

    private function hasOutboundRequestBudget(int $extraMarginSeconds = 2): bool
    {
        if ($this->deadlineAt <= 0.0) return true;
        return ($this->deadlineAt - microtime(true)) >= max(3.0, 1.0 + $extraMarginSeconds);
    }

    private function hasProcessingBudget(int $marginSeconds = 4): bool
    {
        if ($this->deadlineAt <= 0.0) return true;
        return ($this->deadlineAt - microtime(true)) >= max(1, $marginSeconds);
    }

    private function acquireLock(): bool
    {
        $name='p2k_team_points_worker_' . $this->normalizedLane();
        $query = $this->pdo->prepare('SELECT GET_LOCK(?,0)');
        $query->execute([$name]);
        return (int)$query->fetchColumn() === 1;
    }

    private function releaseLock(): void
    {
        try {
            $name='p2k_team_points_worker_' . $this->normalizedLane();
            $query=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $query->execute([$name]);
        } catch (\Throwable) {
        }
    }
}
