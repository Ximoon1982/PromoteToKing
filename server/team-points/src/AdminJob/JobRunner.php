<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

/** Compatibility adapter for existing runners; it does not introduce scheduling policy. */
final class JobRunner
{
    public function __construct(private readonly JobCheckpointStore $checkpoints) {}

    public function observe(string $jobId): ?array
    {
        $state = $this->checkpoints->load($jobId);
        return $state ? JobTelemetry::fromState($state) : null;
    }
}
