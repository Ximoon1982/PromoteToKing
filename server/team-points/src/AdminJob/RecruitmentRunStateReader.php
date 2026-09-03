<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

use Closure;

/** Read-only view over Recruitment's existing JSON run; it owns no persistence policy. */
final class RecruitmentRunStateReader implements JobStateReader
{
    public function __construct(private readonly Closure $loadRun) {}

    public function load(string $jobId): ?JobState
    {
        $run = ($this->loadRun)();
        if (!is_array($run) || $run === []) return null;
        $id = (string)($run['id'] ?? '');
        if ($jobId !== '' && !hash_equals($id, $jobId)) return null;
        $completed = count(array_filter((array)($run['results'] ?? []), 'is_array'));
        $total = count((array)($run['candidates'] ?? []));
        return JobState::fromExisting([
            'job_id'=>$id,
            'type'=>'recruitment-scan',
            'state'=>(string)($run['status'] ?? 'idle'),
            'cursor'=>$completed,
            'completed'=>$completed,
            'total'=>$total,
            'rate'=>0,
            'eta'=>null,
            'started_at'=>$run['createdAt'] ?? null,
            'updated_at'=>$run['updatedAt'] ?? null,
            'checkpoint_backlog'=>max(0, $total - $completed),
            'last_error'=>null,
        ]);
    }
}
