<?php
declare(strict_types=1);

return [
    'club_slug' => 'promote-to-king',
    'discovery_start_year' => 2024,
    'cron_candidate_batch_size' => 3,
    'cron_status_batch_size' => 4,
    'cron_podium_batch_size' => 1,
    'known_tournaments' => [
        'promote-to-king-top-32-players-of-january-2026-knock-out',
    ],
    'request_timeout_seconds' => 15,
    'request_delay_ms' => 350,
    'cache_ttl_seconds' => 21600,
    'status_cache_ttl_seconds' => 3600,
    'max_ranking_players' => 160,
    'user_agent' => 'PromoteToKing-Tournaments/2.8.8 (+https://www.promotetoking.org/)',
];
