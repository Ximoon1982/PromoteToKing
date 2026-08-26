<?php
declare(strict_types=1);

return [
    // v2.8.0 uses two fresh MariaDB databases. Create both databases in IONOS
    // first, then copy this file to config.local.php and fill both credentials.
    'databases' => [
        'core' => [
            'host' => 'dbXXXXXXXX.hosting-data.io',
            'port' => 3306,
            'name' => 'dbXXXXXXXX_core',
            'user' => 'dboXXXXXXXX',
            'password' => 'CHANGE_ME',
            'charset' => 'utf8mb4',
            'connect_timeout_seconds' => 5,
            'lock_timeout_seconds' => 5,
            // Standard IONOS DB default. Change if this database has another quota.
            'quota_bytes' => 2147483648,
        ],
        'analytics' => [
            'host' => 'dbYYYYYYYY.hosting-data.io',
            'port' => 3306,
            'name' => 'dbYYYYYYYY_analytics',
            'user' => 'dboYYYYYYYY',
            'password' => 'CHANGE_ME',
            'charset' => 'utf8mb4',
            'connect_timeout_seconds' => 5,
            'lock_timeout_seconds' => 5,
            'quota_bytes' => 2147483648,
        ],
    ],
    'storage' => [
        // Empty paths use protected directories under data/runtime-v280.
        'runtime_dir' => '',
        'cache_dir' => '',
        'logs_dir' => '',
        'archive_dir' => '',
        // Cache is compressed on disk and bounded independently from MariaDB.
        'cache_max_bytes' => 536870912,
        // Host inode/file-count protection: bound cache objects independently of bytes.
        'cache_max_entries' => 30000,
        // New writes use one shard level; old two-level cache entries remain readable.
        'cache_shard_depth' => 1,
        'public_response_cache_max_entries' => 2000,
        'public_response_cache_max_bytes' => 134217728,
        'cache_cleanup_probability_percent' => 3,
        'cache_expired_grace_seconds' => 86400,
        'log_retention_days' => 30,
        'job_retention_days' => 14,
        'failed_job_retention_days' => 30,
        'storage_warning_ratio' => 0.80,
        'storage_history_months' => 120,
        // Optional filesystem quota. Set 0 when the host does not expose a fixed quota.
        'filesystem_quota_bytes' => 0,
        // Optional hosting file/inode contract. 0 disables percentage warnings.
        'filesystem_max_files' => 200000,
        'filesystem_file_warning_ratio' => 0.80,
        'match_tracking_recent_days' => 7,
        'match_tracking_dense_days' => 30,
        'match_tracking_daily_days' => 180,
        'match_tracking_max_snapshots_per_match' => 1200,
        // Low-volume runtime history is bounded too, so file count never grows only with elapsed time.
        'telemetry_retention_days' => 30,
        'telemetry_max_files' => 35,
        'reconciliation_retention_days' => 365,
        'reconciliation_max_batches' => 250,
        'intelligence_snapshot_retention_days' => 730,
        'intelligence_snapshot_max_files' => 730,
        // Legacy one-file-per-member ACAMR leases are safe to discard after this age.
        'acamr_member_lease_retention_seconds' => 86400,
        'scheduled_task_file_retention_days' => 30,
        'scheduled_task_file_max_files' => 35,
        // Traffic reports expose at most 366 days, so keep a small safety margin.
        'traffic_analytics_retention_days' => 400,
        'traffic_analytics_max_files' => 400,
        // Fresh-initializer receipts are replay/coverage state, not permanent records.
        'fresh_init_nonce_retention_days' => 2,
        'fresh_init_nonce_max_files' => 500,
        'fresh_init_coverage_retention_days' => 90,
        'fresh_init_coverage_max_runs' => 20,
        // Deliberate tournament reinitialization backups are rare; keep a generous bounded history.
        'tournament_backup_retention_days' => 3650,
        'tournament_backup_max_files' => 100,
        // Durable uploads are never auto-deleted; reject new distinct names at this ceiling.
        'live_ranks_max_upload_files' => 5000,
    ],
    'app' => [
        'club_slug' => 'promote-to-king',
        'admin_token' => 'CHANGE_TO_A_LONG_RANDOM_ADMIN_TOKEN',
        'cron_token' => 'CHANGE_TO_A_DIFFERENT_LONG_RANDOM_CRON_TOKEN',
        // Optional one-time/fresh-install token. If empty the admin_token is used.
        'init_token' => '',
        'allowed_origin' => '',
        'admin_session_lifetime_seconds' => 1800,
        'worker_max_seconds' => 30,
        'worker_segment_seconds' => 30,
        // v2.8.8 split lanes: keep Club Points fast; let Member Points use the remaining background budget.
        'club_worker_segment_seconds' => 34,
        'player_worker_segment_seconds' => 30,
        'worker_max_items' => 25,
        'club_worker_max_items' => 100,
        'player_worker_max_items' => 75,
        'cron_continuous_enabled' => false,
        'cron_loop_max_seconds' => 42,
        'cron_endpoint_max_seconds' => 42,
        'player_cron_endpoint_max_seconds' => 38, // leave transport/headroom below the 55s shell curl ceiling
        'cron_expected_interval_seconds' => 300, // legacy compatibility
        'club_cron_expected_interval_seconds' => 300,
        'player_cron_expected_interval_seconds' => 600,
        'player_reconcile_batch_size' => 250,
        // One-time-safe recovery batch for legacy done sync_player rows that never wrote freshness.
        'player_false_completion_repair_batch_size' => 250,
        // Sustainable bulk reconciliation for ~4,000 members. Fast current-match/board
        // maintenance remains the Club lane; ACAMR opportunistically accelerates due members.
        'player_reconcile_matches_refresh_seconds' => 604800, // 7 days observed-or-verified discovery freshness
        'player_reconcile_stats_refresh_seconds' => 259200,   // 3 days observed-or-verified stats freshness
        // Claim-backed browser observations may satisfy routine discovery freshness,
        // but server verification still runs on a slower hard-audit ceiling.
        'player_matches_authoritative_audit_seconds' => 2592000, // 30 days
        'player_stats_authoritative_audit_seconds' => 604800,    // 7 days
        // PMAF: only endpoint-specific /matches failures for a known-valid player enter
        // archive fallback. Normal 429/network/gateway/auth failures remain ordinary retries.
        'player_matches_fallback_reprobe_seconds' => 604800,     // 7 days before primary /matches is tried again
        'player_matches_fallback_archive_batch' => 6,            // archive months scheduled per sync_player generation
        'player_matches_fallback_state_retention_seconds' => 15552000, // 180 days, one bounded shared ledger
        'player_matches_fallback_max_entries' => 6000,
        // Recruitment may opt into newer claim-backed observed ratings while exposing
        // provenance; all score/points/achievement analytics continue to use verified data.
        'allow_claimed_observed_ratings_for_recruitment' => true,
        'observed_rating_recruitment_max_age_seconds' => 259200, // 3 days
        // Legacy compatibility keys retained for older local configs/callers.
        'player_matches_refresh_seconds' => 21600,
        'player_stats_refresh_seconds' => 86400,
        'acamr_claims_per_pulse' => 3,
        // Anonymous/fake OAuth stays low-pulse. Real OAuth may feed a much deeper
        // reservoir to the shared adaptive Bearer gateway.
        'acamr_oauth_claims_per_pulse' => 48,
        'acamr_claim_ttl_seconds' => 1200,
        'acamr_pulse_ms' => 20000,
        'acamr_oauth_pulse_ms' => 5000,
        'acamr_scan_batch_size' => 60,
        'acamr_oauth_scan_batch_size' => 600,
        'failed_board_recovery_batch_size' => 500,
        // Public Analytics are materialized by background CRON work; GET requests never rebuild them.
        'analytics_refresh_interval_seconds' => 300,
        'cron_reschedule_delay_seconds' => 60,
        'cron_self_url' => '',
        'stale_item_seconds' => 900,
        'request_timeout_seconds' => 15,
        'request_delay_ms' => 350,
        'shared_gateway_request_delay_ms' => 350,
        'gateway_max_stale_seconds' => 604800,
        'admin_gateway_stale_seconds' => 86400,
        'gateway_health_url' => 'https://api.chess.com/pub/club/promote-to-king',
        'shared_gateway_user_agent' => 'PromoteToKing-Gateway/2.9.8 (+https://www.promotetoking.org/)',
        'http_cache_ttl_seconds' => 21600,
        'club_match_detail_limit_per_job' => 600,
        // Disabled in normal operation. Enable only as a deliberate repair policy.
        'routine_member_history_enabled' => false,
        'historical_match_discovery_enabled' => false,
        'historical_match_start_id' => 1568027,
        'historical_match_scan_batch_size' => 20,
        'live_ranks_upload_dir' => '',
        'board_recheck_incomplete_seconds' => 21600,
        'board_recheck_in_progress_seconds' => 21600,
        'board_retry_failed_seconds' => 3600,
        'board_rediscovery_limit_per_player' => 250,
        'board_rediscovery_limit_per_job' => 1000,
        'board_state_backfill_batch_size' => 500,
        'match_summary_backfill_batch_size' => 25,
        'user_agent' => 'PromoteToKing-TeamPoints/2.9.8 (+https://www.promotetoking.org/)',
    ],
];
