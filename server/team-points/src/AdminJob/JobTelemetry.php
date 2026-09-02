<?php
declare(strict_types=1);

namespace P2K\TeamPoints\AdminJob;

final class JobTelemetry
{
    public static function fromState(JobState $state): array
    {
        return $state->toArray();
    }
}
