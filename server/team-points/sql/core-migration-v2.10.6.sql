-- v2.10.6 Core schema 17: opponent-player identity on compact board facts.
ALTER TABLE p2k_tp_boards
  ADD COLUMN IF NOT EXISTS opponent_username VARCHAR(80) NULL AFTER opponent_rating,
  ADD KEY IF NOT EXISTS idx_tp_board_opponent_username (opponent_username,match_id);
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(17);
