<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

/** Adapter contract only; existing process-specific persistence remains authoritative. */
interface JobCheckpointStore
{
    public function load(string $jobId): ?JobState;
    public function save(JobState $state): void;
}
