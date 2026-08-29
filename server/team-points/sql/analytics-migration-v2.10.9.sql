-- v2.10.9 Analytics schema 10: durable MCA arena acquisition + club/player/game facts.
ALTER TABLE p2k_lr_sync_state
  ADD COLUMN IF NOT EXISTS arena_backfill_seeded_at DATETIME NULL AFTER rebuild_required;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_acquisition (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  arena_slug VARCHAR(255) NOT NULL,
  arena_url VARCHAR(500) NOT NULL,
  csv_url VARCHAR(500) NULL,
  source_kind VARCHAR(20) NOT NULL DEFAULT 'historical',
  priority SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  status ENUM('pending','running','completed','error') NOT NULL DEFAULT 'pending',
  stage VARCHAR(24) NOT NULL DEFAULT 'arena',
  results_source VARCHAR(20) NOT NULL DEFAULT 'unknown',
  needs_clubs TINYINT(1) NOT NULL DEFAULT 1,
  needs_players TINYINT(1) NOT NULL DEFAULT 1,
  arena_page_done TINYINT(1) NOT NULL DEFAULT 0,
  advertised_players INT UNSIGNED NULL,
  max_eligible_scorers INT UNSIGNED NULL,
  arena_rating VARCHAR(80) NULL,
  clubs_total_pages SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  clubs_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  players_total_pages SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  players_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pairings_total_pages SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  pairings_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  date_index_next_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  game_count INT UNSIGNED NOT NULL DEFAULT 0,
  event_start_at DATETIME NULL,
  event_date DATE NULL,
  date_precision VARCHAR(32) NOT NULL DEFAULT 'unknown',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  discovered_at DATETIME NOT NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id),
  KEY idx_lr_arena_acq_work(club_slug,status,priority,arena_id),
  KEY idx_lr_arena_acq_stage(club_slug,stage,arena_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_clubs (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  result_key VARCHAR(255) NOT NULL,
  result_club VARCHAR(255) NOT NULL,
  result_club_slug VARCHAR(255) NULL,
  rank_value INT UNSIGNED NULL,
  total_players INT UNSIGNED NOT NULL DEFAULT 0,
  score DECIMAL(14,2) NOT NULL DEFAULT 0,
  source_kind VARCHAR(12) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id,result_key),
  KEY idx_lr_arena_clubs_rank(club_slug,arena_id,rank_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_players (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  rank_value INT UNSIGNED NULL,
  rating INT UNSIGNED NULL,
  result_club VARCHAR(255) NULL,
  result_club_slug VARCHAR(255) NULL,
  score DECIMAL(14,2) NOT NULL DEFAULT 0,
  wins INT UNSIGNED NULL,
  draws INT UNSIGNED NULL,
  losses INT UNSIGNED NULL,
  byes INT UNSIGNED NULL,
  streak INT UNSIGNED NULL,
  most_wins INT UNSIGNED NULL,
  source_kind VARCHAR(12) NOT NULL,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id,username_key),
  KEY idx_lr_arena_players_rank(club_slug,arena_id,rank_value),
  KEY idx_lr_arena_players_club(club_slug,arena_id,result_club_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_lr_arena_games (
  club_slug VARCHAR(120) NOT NULL,
  arena_id BIGINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NOT NULL,
  game_url VARCHAR(500) NOT NULL,
  white_username_key VARCHAR(80) NOT NULL,
  white_username VARCHAR(80) NOT NULL,
  white_rating INT UNSIGNED NULL,
  black_username_key VARCHAR(80) NOT NULL,
  black_username VARCHAR(80) NOT NULL,
  black_rating INT UNSIGNED NULL,
  result VARCHAR(12) NOT NULL,
  source_page SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  captured_at DATETIME NOT NULL,
  PRIMARY KEY(club_slug,arena_id,game_id),
  KEY idx_lr_arena_games_white(club_slug,white_username_key),
  KEY idx_lr_arena_games_black(club_slug,black_username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(10);
