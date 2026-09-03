<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/team-points/src/AdminJob/JobStateReader.php';
require_once __DIR__ . '/../server/team-points/src/AdminJob/JobCheckpointStore.php';
require_once __DIR__ . '/../server/team-points/src/AdminJob/JobState.php';
require_once __DIR__ . '/../server/team-points/src/AdminJob/JobTelemetry.php';
require_once __DIR__ . '/../server/team-points/src/AdminJob/JobRunner.php';
require_once __DIR__ . '/../server/team-points/src/AdminJob/RecruitmentRunStateReader.php';

use P2K\TeamPoints\AdminJob\JobRunner;
use P2K\TeamPoints\AdminJob\RecruitmentRunStateReader;

$run = [
    'id'=>'recruitment-1', 'status'=>'paused',
    'createdAt'=>'2026-09-03T10:00:00+00:00',
    'updatedAt'=>'2026-09-03T10:05:00+00:00',
    'candidates'=>['alpha','beta','gamma'],
    'results'=>[['username'=>'alpha'],['username'=>'beta']],
];
$runner = new JobRunner(new RecruitmentRunStateReader(static fn(): array => $run));
$state = $runner->observe('recruitment-1');
if (($state['type'] ?? '') !== 'recruitment-scan' || ($state['state'] ?? '') !== 'paused') exit(1);
if (($state['cursor'] ?? null) !== 2 || ($state['completed'] ?? null) !== 2) exit(2);
if (($state['total'] ?? null) !== 3 || ($state['checkpoint_backlog'] ?? null) !== 1) exit(3);
if ($runner->observe('different-run') !== null) exit(4);
echo "v2.11.3 Recruitment AdminJob adapter passed.\n";
