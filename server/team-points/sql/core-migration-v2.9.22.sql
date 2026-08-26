CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_runs (
  run_id CHAR(36) NOT NULL PRIMARY KEY,
  club_slug VARCHAR(120) NOT NULL,
  include_club TINYINT(1) NOT NULL DEFAULT 1,
  include_player TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('running','paused','ready','applying','applied','cancelled','failed') NOT NULL DEFAULT 'running',
  phase VARCHAR(64) NOT NULL DEFAULT 'created',
  phase_label VARCHAR(160) NOT NULL DEFAULT 'Created',
  overall_progress DECIMAL(6,2) NOT NULL DEFAULT 0,
  club_progress DECIMAL(6,2) NOT NULL DEFAULT 0,
  player_progress DECIMAL(6,2) NOT NULL DEFAULT 0,
  metrics_json MEDIUMTEXT NULL,
  opening_roster_count INT UNSIGNED NOT NULL DEFAULT 0,
  closing_roster_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  applied_at DATETIME NULL,
  club_applied_at DATETIME NULL,
  player_applied_at DATETIME NULL,
  last_error TEXT NULL,
  KEY idx_tp_reconstruction_latest (club_slug,created_at),
  KEY idx_tp_reconstruction_status (club_slug,status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_matches (
  run_id CHAR(36) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  stage_state ENUM('pending','resolved','unresolved','failed') NOT NULL DEFAULT 'pending',
  source_flags VARCHAR(80) NOT NULL DEFAULT '',
  status VARCHAR(24) NOT NULL DEFAULT 'unknown',
  board_count INT UNSIGNED NOT NULL DEFAULT 0,
  p2k_score DECIMAL(12,1) NOT NULL DEFAULT 0,
  opponent_score DECIMAL(12,1) NOT NULL DEFAULT 0,
  excluded_zero_zero TINYINT(1) NOT NULL DEFAULT 0,
  payload_json MEDIUMTEXT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (run_id,match_id),
  KEY idx_tp_reconstruction_match_state (run_id,stage_state,match_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_members (
  run_id CHAR(36) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  joined_epoch BIGINT UNSIGNED NOT NULL DEFAULT 0,
  opening_member TINYINT(1) NOT NULL DEFAULT 0,
  closing_member TINYINT(1) NOT NULL DEFAULT 0,
  stage_state ENUM('pending','matches_done','archive_fallback','boards_done','complete','unresolved','failed') NOT NULL DEFAULT 'pending',
  points_x2 BIGINT UNSIGNED NOT NULL DEFAULT 0,
  metrics_json MEDIUMTEXT NULL,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (run_id,username_key),
  KEY idx_tp_reconstruction_member_state (run_id,stage_state,username_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_boards (
  run_id CHAR(36) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  username VARCHAR(80) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  board_no INT UNSIGNED NOT NULL DEFAULT 0,
  board_url VARCHAR(255) NULL,
  source_bucket VARCHAR(24) NOT NULL DEFAULT 'unknown',
  stage_state ENUM('discovered','pending','resolved','unresolved','failed') NOT NULL DEFAULT 'discovered',
  white_result VARCHAR(40) NULL,
  black_result VARCHAR(40) NULL,
  p2k_rating SMALLINT UNSIGNED NULL,
  opponent_rating SMALLINT UNSIGNED NULL,
  points_x2 SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  finished_game_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (run_id,username_key,match_id),
  KEY idx_tp_reconstruction_board_state (run_id,stage_state,match_id),
  KEY idx_tp_reconstruction_board_match (run_id,match_id,board_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS p2k_tp_reconstruction_games (
  run_id CHAR(36) NOT NULL,
  username_key VARCHAR(80) NOT NULL,
  match_id BIGINT UNSIGNED NOT NULL,
  sequence_no TINYINT UNSIGNED NOT NULL,
  game_id BIGINT UNSIGNED NULL,
  game_url VARCHAR(255) NULL,
  game_end_utc DATETIME NOT NULL,
  result_code VARCHAR(40) NOT NULL,
  points_x2 TINYINT UNSIGNED NOT NULL,
  source_hash CHAR(64) NULL,
  PRIMARY KEY (run_id,username_key,match_id,sequence_no),
  KEY idx_tp_reconstruction_game_match (run_id,match_id),
  KEY idx_tp_reconstruction_game_end (run_id,game_end_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(15);
