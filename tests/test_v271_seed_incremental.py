#!/usr/bin/env python3
import json
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
def check(v,m):
    if not v: raise AssertionError(m)
def main():
    core=(ROOT/'server/team-points/sql/core-schema.sql').read_text(); analytics=(ROOT/'server/team-points/sql/analytics-schema.sql').read_text(); schema=core+'\n'+analytics
    repo=(ROOT/'server/team-points/src/Repository.php').read_text(); worker=(ROOT/'server/team-points/src/Worker.php').read_text(); config=(ROOT/'server/team-points/config/config.example.php').read_text(); endpoint=(ROOT/'server/team-points/public/fresh-init.php').read_text(); legacy=(ROOT/'server/team-points/public/seed-import.php').read_text(); manifest=json.loads((ROOT/'site-manifest.json').read_text())
    for table in ('p2k_tp_members','p2k_tp_match_metadata','p2k_tp_boards','p2k_tp_games','p2k_core_initialization'):
        check(f'CREATE TABLE IF NOT EXISTS {table}' in core,f'Missing compact Core table {table}')
    for table in ('p2k_an_player_totals','p2k_an_match_facts','p2k_an_player_monthly','p2k_tp_insight_daily','p2k_an_storage_samples','p2k_analytics_initialization'):
        check(f'CREATE TABLE IF NOT EXISTS {table}' in analytics,f'Missing Analytics table {table}')
    for forbidden in ('p2k_tp_seed_runs','p2k_tp_seed_nonces','p2k_tp_seed_members','p2k_tp_seed_matches','p2k_tp_seed_boards','p2k_tp_http_cache','p2k_shared_http_cache'):
        check(forbidden not in schema,f'v2.8 fresh schema still contains disposable SQL table {forbidden}')
    check('last_index_seen_at DATETIME NULL' in core and 'next_detail_check_at DATETIME NULL' in core,'Incremental match-detail fields missing')
    check('rules VARCHAR(32) NULL' in core and 'time_control VARCHAR(32) NULL' in core and 'is_league TINYINT(1)' in core,'Historical dimensions missing')
    for token in ('X-P2K-Init-Signature','init_verify_manifest','INITIALIZE-P2K-2.8.0','init_table_count','Fresh initialization requires either two empty databases or the exact unsealed v2.8.0 schema','AnalyticsBuilder','p2k_core_initialization'):
        check(token in endpoint,f'Fresh initializer missing {token}')
    check('p2k_tp_seed_' not in endpoint and 'staging' not in endpoint.lower(),'Fresh initializer unexpectedly uses SQL staging')
    check("'incremental_sync','paused'" in endpoint and 'sync_club_matches' in endpoint and 'sync_members' in endpoint,'Post-init catch-up job missing')
    check('1983076' not in endpoint,'Initializer endpoint must not hard-code a particular unknown match; it should query unknown rows')
    check('410' in legacy and 'retired' in legacy.lower(),'Legacy seed endpoint was not retired')
    check("'algorithm'=>'seeded_incremental_index'" in worker or 'seeded_incremental_index' in worker,'Incremental index algorithm marker missing')
    check('routine_member_history_enabled' in config and 'historical_match_discovery_enabled' in config and 'full_member_history' in worker and 'historical_match_scan' in worker,'Explicit repair-only switches missing')
    check(manifest.get('version')=='2.8.1','Manifest release is stale')
    print('v2.8.1 fresh compact initialization and incremental Team Points tests passed.')
if __name__=='__main__': main()
