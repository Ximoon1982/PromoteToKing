#!/usr/bin/env python3
from pathlib import Path
import json
ROOT=Path(__file__).resolve().parents[1]
def check(v,m):
    if not v: raise AssertionError(m)
def main():
    core=(ROOT/'server/team-points/sql/core-schema.sql').read_text()
    analytics=(ROOT/'server/team-points/sql/analytics-schema.sql').read_text()
    cache=(ROOT/'server/shared/FilesystemCache.php').read_text()
    gateway=(ROOT/'server/shared/SharedChessGateway.php').read_text()
    metrics=(ROOT/'server/team-points/src/StorageMetricsService.php').read_text()
    init=(ROOT/'server/team-points/public/fresh-init.php').read_text()
    admin=(ROOT/'TeamPointsAdmin.html').read_text()
    adminjs=(ROOT/'assets/js/pages/team-points-admin.js').read_text()
    dashboard=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    config=(ROOT/'server/team-points/config/config.example.php').read_text()
    manifest=json.loads((ROOT/'site-manifest.json').read_text())
    for forbidden in ('p2k_tp_http_cache','p2k_shared_http_cache','p2k_tp_seed_members','p2k_tp_seed_matches','p2k_tp_seed_boards'):
        check(forbidden not in core+analytics,f'Forbidden SQL storage remains: {forbidden}')
    check("gzencode" in cache and ".json.gz" in cache and "cache_max_bytes" in cache,'Filesystem gzip cache is incomplete')
    check("database_independent" in gateway and 'FilesystemCache' in gateway,'Shared gateway is not database-independent')
    check("storage_warning_ratio' => 0.80" in config and "quota_bytes' => 2147483648" in config,'80% / 2GB quota defaults missing')
    check('p2k_an_storage_samples' in analytics and 'monthly_history' in metrics and "'basis'=>$projectionBasis" in metrics,'Storage history/projection storage is incomplete')
    check("count($history) >= 2 ? 'monthly' : 'daily_bootstrap'" in metrics,'Projection does not switch to monthly measured trend')
    check('data-tab="storage"' in admin and 'Monthly storage evolution' in admin and 'Capacity projection' in admin,'Storage admin tab is missing')
    check('p2k-storage-light' in adminjs and 'at or above the 80% warning threshold' in adminjs,'Storage warning lights are missing')
    check('title: "Storage & capacity"' in dashboard,'Storage admin card is missing')
    check('previous_run_id' in init and 'resumed_at' in init and 'manifest_sha256' in init,'Fresh initializer retry adoption missing')
    check('init_exact_v280_schema' in init and 'adopted_existing_schema' in init,'Interrupted-schema adoption is missing')
    check(init.index('init_write_state($config,$state);\n            $repo->installSchema();') > 0,'Initializer recovery state is not persisted before DDL')
    check("$core->beginTransaction()" in init and "$core->commit()" in init and "p2k_core_initialization" in init,'Core finalization is not transactionally sealed')
    check(manifest.get('features',{}).get('teamPointsStorageArchitecture',{}).get('sqlHttpCache') is False,'Manifest SQL-cache contract is stale')
    print('v2.8.1 split storage, capacity monitoring and fresh-initializer recovery tests passed.')
if __name__=='__main__': main()
