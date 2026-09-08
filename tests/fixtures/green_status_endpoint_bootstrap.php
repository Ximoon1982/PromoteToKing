<?php
declare(strict_types=1);

namespace P2K\Green;

final class FixtureStatement
{
    public function fetchColumn(): int { return 17; }
}

final class FixtureConnection
{
    public function query(string $sql): FixtureStatement { return new FixtureStatement(); }
}

final class GreenRepository
{
    public FixtureConnection $core;
    public FixtureConnection $analytics;

    public function __construct() { $this->core=new FixtureConnection();$this->analytics=new FixtureConnection(); }
    public static function open(): self { return new self(); }
    public function state(): array { return ['cycle_no'=>1,'mode'=>'quick','stage'=>'quick_matches','seed_completed_at'=>'2026-09-04 08:00:00','last_index_fetch'=>'2026-09-04 08:00:00','last_roster_fetch'=>'2026-09-04 08:00:00']; }
    public function recentInvocations(int $limit=20): array { return []; }
    public function greenSummary(): array { return ['state'=>$this->state(),'progress'=>['matches'=>['unknown'=>0],'boards'=>['pending'=>0],'players'=>['profiles_pending'=>0,'initial_stats_pending'=>0]],'integrity'=>[]]; }
}

final class GreenComparison
{
    public function __construct(GreenRepository $repo) {}
    public function summary(): array { return ['fixture'=>true]; }
}

final class GreenAnalyticsBootstrap
{
    public function __construct(GreenRepository $repo) {}
    public function status(): array
    {
        $fixture=json_decode((string)getenv('P2K_PARITY_FIXTURE'),true,512,JSON_THROW_ON_ERROR);
        return ['status'=>'running','lanes'=>[$fixture]];
    }
}

final class GreenCompatibility
{
    public function __construct(GreenRepository $repo) {}
}

final class GreenLegacyFacts
{
    public const EXPECTED_ROWS=0;
    public const EXPECTED_VALID_FINISHED=0;
    public const EXPECTED_VOID=0;
    public const EXPECTED_POINTS=0;
}

final class GreenConfig
{
    public static function authorizeAdmin(): void {}
    public static function json(array $data,int $status=200): void
    {
        echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        exit($status>=400?1:0);
    }
}

namespace P2K\TeamPoints;

final class PublicReadDatabase
{
    public static function reset(): void {}
    public static function source(): string { return 'green'; }
}

$_GET['action']='status';
$_SERVER['REQUEST_METHOD']='GET';
