-- v2.9.18 Analytics schema 7: MIRA source attribution + identity generation.
ALTER TABLE p2k_lr_players
  ADD COLUMN IF NOT EXISTS identity_map_generation BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER source_files_json,
  ADD COLUMN IF NOT EXISTS identity_resolution VARCHAR(32) NOT NULL DEFAULT 'self' AFTER identity_map_generation;
ALTER TABLE p2k_lr_processing_state
  ADD COLUMN IF NOT EXISTS identity_map_generation BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER closed_accounts,
  ADD COLUMN IF NOT EXISTS identity_stale TINYINT(1) NOT NULL DEFAULT 0 AFTER identity_map_generation;

CREATE TABLE IF NOT EXISTS p2k_lr_source_rows (
  club_slug VARCHAR(120) NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  source_row_no INT UNSIGNED NOT NULL,
  raw_username_key VARCHAR(80) NOT NULL,
  raw_username VARCHAR(80) NOT NULL,
  score DECIMAL(14,2) NOT NULL DEFAULT 0,
  games INT UNSIGNED NULL,wins INT UNSIGNED NULL,draws INT UNSIGNED NULL,losses INT UNSIGNED NULL,
  streak INT UNSIGNED NULL,max_wins INT UNSIGNED NULL,max_games INT UNSIGNED NULL,rank_value INT UNSIGNED NULL,
  source_event_key VARCHAR(320) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,file_id,source_row_no),
  KEY idx_lr_source_user(club_slug,raw_username_key),
  KEY idx_lr_source_event(club_slug,source_event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_attributions (
  club_slug VARCHAR(120) NOT NULL,
  file_id BIGINT UNSIGNED NOT NULL,
  source_row_no INT UNSIGNED NOT NULL,
  raw_username_key VARCHAR(80) NOT NULL,
  raw_username VARCHAR(80) NOT NULL,
  canonical_username_key VARCHAR(80) NOT NULL,
  canonical_username VARCHAR(80) NOT NULL,
  resolution_reason VARCHAR(32) NOT NULL,
  identity_map_generation BIGINT UNSIGNED NOT NULL,
  conflict TINYINT(1) NOT NULL DEFAULT 0,
  attributed_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,file_id,source_row_no),
  KEY idx_lr_attr_canonical(club_slug,canonical_username_key),
  KEY idx_lr_attr_raw(club_slug,raw_username_key),
  KEY idx_lr_attr_generation(club_slug,identity_map_generation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(7);
