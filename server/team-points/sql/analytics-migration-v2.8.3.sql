-- Promote to King v2.8.3 additive Analytics migration.
-- Achievement trigger metadata is rebuildable from Core, Live/MCA and tournament sources.
ALTER TABLE p2k_an_achievement_unlocks ADD COLUMN IF NOT EXISTS source_type VARCHAR(32) NULL AFTER earned_at_precision;
ALTER TABLE p2k_an_achievement_unlocks ADD COLUMN IF NOT EXISTS source_name VARCHAR(255) NULL AFTER source_type;
ALTER TABLE p2k_an_achievement_unlocks ADD COLUMN IF NOT EXISTS source_url VARCHAR(500) NULL AFTER source_name;
-- Tournament award dates in pre-2.8.3 projections used the period bucket rather than the tournament finish time.
-- Remove only those derived Analytics rows so the next achievement refresh recreates them from authoritative finish_time.
DELETE FROM p2k_an_achievement_unlocks WHERE achievement_key LIKE 'tournament-%';
DELETE FROM p2k_an_refresh_state WHERE domain_key='achievements';
INSERT IGNORE INTO p2k_analytics_schema_version(version) VALUES(5);
