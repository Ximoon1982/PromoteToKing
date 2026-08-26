-- v2.10.6 Analytics schema 9: MCA results auto-sync and durable date backfill.
ALTER TABLE p2k_lr_files
  ADD COLUMN IF NOT EXISTS arena_id BIGINT UNSIGNED NULL AFTER replaced_at,
  ADD COLUMN IF NOT EXISTS arena_slug VARCHAR(255) NULL AFTER arena_id,
  ADD COLUMN IF NOT EXISTS event_url VARCHAR(500) NULL AFTER arena_slug,
  ADD COLUMN IF NOT EXISTS csv_url VARCHAR(500) NULL AFTER event_url,
  ADD COLUMN IF NOT EXISTS source_origin VARCHAR(16) NOT NULL DEFAULT 'manual' AFTER csv_url,
  ADD COLUMN IF NOT EXISTS source_fetched_at DATETIME NULL AFTER source_origin,
  ADD UNIQUE KEY IF NOT EXISTS uq_lr_file_arena (club_slug,arena_id);

CREATE TABLE IF NOT EXISTS p2k_lr_sync_state (
  club_slug VARCHAR(120) NOT NULL PRIMARY KEY,
  status ENUM('idle','running','completed','failed') NOT NULL DEFAULT 'idle',
  phase VARCHAR(40) NOT NULL DEFAULT 'idle',
  total_events INT UNSIGNED NOT NULL DEFAULT 0,
  checked_events INT UNSIGNED NOT NULL DEFAULT 0,
  csv_found INT UNSIGNED NOT NULL DEFAULT 0,
  csv_added INT UNSIGNED NOT NULL DEFAULT 0,
  dates_added INT UNSIGNED NOT NULL DEFAULT 0,
  error_count INT UNSIGNED NOT NULL DEFAULT 0,
  request_count INT UNSIGNED NOT NULL DEFAULT 0,
  current_arena_id BIGINT UNSIGNED NULL,
  current_arena_slug VARCHAR(255) NULL,
  current_stage VARCHAR(32) NULL,
  high_water_arena_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_request_at DATETIME(6) NULL,
  last_scan_at DATETIME NULL,
  next_scan_at DATETIME NULL,
  rebuild_required TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  last_error TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_sync_queue (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  arena_slug VARCHAR(255) NOT NULL,
  arena_url VARCHAR(500) NOT NULL,
  csv_url VARCHAR(500) NOT NULL,
  stage ENUM('page','csv','done') NOT NULL DEFAULT 'page',
  status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  needs_csv TINYINT(1) NOT NULL DEFAULT 1,
  needs_date TINYINT(1) NOT NULL DEFAULT 1,
  event_start_at DATETIME NULL,
  event_date DATE NULL,
  discovered_at DATETIME NOT NULL,
  fetched_at DATETIME NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id),
  KEY idx_lr_sync_queue_status(club_slug,status,stage,arena_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(9);
