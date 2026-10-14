-- v2.15.0 Core schema 18: persist Chess.com player titles already returned by profile refreshes.
ALTER TABLE p2k_tp_members
  ADD COLUMN IF NOT EXISTS title VARCHAR(8) NULL AFTER profile_status;
INSERT IGNORE INTO p2k_core_schema_version(version) VALUES(18);
