<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

/** Adapter contract only; existing process-specific persistence remains authoritative. */
interface JobCheckpointStore extends JobStateReader
{
    public function save(JobState $state): void;
}
