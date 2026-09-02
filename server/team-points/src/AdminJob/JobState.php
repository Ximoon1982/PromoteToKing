<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

/** Immutable normalized view over an existing administrative process state. */
final class JobState
{
    public function __construct(
        public readonly string $jobId,
        public readonly string $type,
        public readonly string $state,
        public readonly int|string|null $cursor,
        public readonly int $completed,
        public readonly int $total,
        public readonly float $rate,
        public readonly ?int $eta,
        public readonly ?string $startedAt,
        public readonly ?string $updatedAt,
        public readonly int $checkpointBacklog,
        public readonly ?string $lastError,
    ) {}

    public static function fromExisting(array $row): self
    {
        return new self(
            (string)($row['job_id'] ?? $row['id'] ?? ''),
            (string)($row['type'] ?? ''),
            (string)($row['state'] ?? $row['status'] ?? 'idle'),
            $row['cursor'] ?? null,
            max(0, (int)($row['completed'] ?? 0)),
            max(0, (int)($row['total'] ?? 0)),
            max(0.0, (float)($row['rate'] ?? $row['throughput'] ?? 0)),
            isset($row['eta']) ? (int)$row['eta'] : null,
            isset($row['started_at']) ? (string)$row['started_at'] : null,
            isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            max(0, (int)($row['checkpoint_backlog'] ?? 0)),
            isset($row['last_error']) ? (string)$row['last_error'] : null,
        );
    }

    public function toArray(): array
    {
        return ['job_id'=>$this->jobId,'type'=>$this->type,'state'=>$this->state,'cursor'=>$this->cursor,'completed'=>$this->completed,'total'=>$this->total,'rate'=>$this->rate,'eta'=>$this->eta,'started_at'=>$this->startedAt,'updated_at'=>$this->updatedAt,'checkpoint_backlog'=>$this->checkpointBacklog,'last_error'=>$this->lastError];
    }
}
