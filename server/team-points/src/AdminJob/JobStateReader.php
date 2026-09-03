<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

/** Read-only adapter boundary over an existing process-specific checkpoint. */
interface JobStateReader
{
    public function load(string $jobId): ?JobState;
}
