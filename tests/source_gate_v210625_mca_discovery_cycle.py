from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
service = (ROOT / 'server/team-points/src/McaDiscoveryService.php').read_text(encoding='utf-8')
discovery = (ROOT / 'server/team-points/bin/mca-discovery.php').read_text(encoding='utf-8')
hydrate = (ROOT / 'server/team-points/bin/mca-hydrate.php').read_text(encoding='utf-8')
rebuild = (ROOT / 'server/team-points/bin/mca-rebuild.php').read_text(encoding='utf-8')
compat = (ROOT / 'server/team-points/bin/mca-results-sync.php').read_text(encoding='utf-8')
admin = (ROOT / 'server/team-points/public/live-ranks-admin.php').read_text(encoding='utf-8')

checks = {
    'old club multi-arena index is canonical discovery source': "https://www.chess.com/club/live-tournaments/" in service and "?type=multi&page=" in service,
    'cycle captures high-water boundary': 'high_water_arena_id' in service and 'maxKnownArenaId' in service,
    'discovery stops at or below previous high-water': '$arenaId <= $highWater' in service,
    'phase split is explicit': all(x in service for x in ["phase='discover'", "phase='hydrate'", "phase='rebuild'", "phase='complete'"]),
    'new cycle blocked while another phase runs': "if ($status === 'running' && $phase !== 'discover') return $state;" in service,
    'next discovery time assigned only at completion': "next_scan_at=NULL" in service and "next_scan_at=DATE_ADD(UTC_TIMESTAMP()" in service,
    'one-second request pacing retained': 'REQUEST_SPACING_SECONDS = 1.0' in service and 'last_request_at=UTC_TIMESTAMP(6)' in service,
    'advisory lock retained': 'GET_LOCK(?,0)' in service and 'RELEASE_LOCK(?)' in service,
    'index date is current-cycle hydration fallback': "Index date belongs to this newly discovered queue item" in service and "$eventDate = trim((string)($item['event_date'] ?? '')) ?: null;" in service,
    'event page can replace index fallback': "$eventDate = $fromPage['event_date'];" in service,
    'fallback parser works without DOM spacing': "preg_replace('~<[^>]+>~', ' ', $after)" in service,
    'historical missing-date scan absent': 'actual_event_date IS NULL' not in service and 'seedTimestampQueue' not in service,
    'split CLIs call only their lane': 'runDiscoveryCron' in discovery and 'runHydrationCron' in hydrate and 'runRebuildCron' in rebuild,
    'legacy cron is discovery-only': 'runDiscoveryCron' in compat and 'runAutoSyncCron' not in compat and 'runHydrationCron' not in compat,
    'hydration errors block completion': "status='error'" in service and "if ((int)$errors->fetchColumn() > 0) return $this->status();" in service,
    'rebuild is required after added CSVs': "rebuild_required=1" in service and 'startProcessing' in service,
    'admin no longer calls legacy sync engine': all(x not in admin for x in ['startAutoSync(', 'autoSyncStep(', 'retryAutoSyncErrors(', 'acknowledgeAutoSyncRebuild(']),
    'admin exposes current split sync status': 'McaDiscoveryService' in admin and "$payload['sync'] = $syncService->status();" in admin,
}

failed = [name for name, ok in checks.items() if not ok]
for name, ok in checks.items():
    print(('PASS' if ok else 'FAIL') + ' - ' + name)
if failed:
    raise SystemExit('FAILED: ' + '; '.join(failed))
print(f'PASS - {len(checks)} MCA v2.10.6.25 source gates')
