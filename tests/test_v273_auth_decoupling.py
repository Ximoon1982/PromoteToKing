#!/usr/bin/env python3
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def check(v,m):
    if not v: raise AssertionError(m)

def main():
    guard=(ROOT/'assets/js/shared/admin-page-guard.js').read_text()
    dashboard=(ROOT/'assets/js/pages/dashboard-v2.js').read_text()
    tabs=(ROOT/'assets/js/pages/site-tabs.js').read_text()
    config=(ROOT/'assets/js/site-config.js').read_text()
    branding=(ROOT/'config/site-branding.js').read_text()
    analyze=(ROOT/'AnalyzeMatch.html').read_text()
    session=(ROOT/'server/team-points/public/session.php').read_text()
    check((ROOT/'VERSION').read_text().strip()=='2.8.1','release version mismatch')
    for text,name in ((guard,'standalone guard'),):
        check('P2K_TEAM_POINTS_CLIENT' not in text and 'clubAdminUsernames' in text and 'api.chess.com/pub/club' in text, f'{name} must use public club admin API without DB dependency')
    verify=dashboard[dashboard.index('async function verifyAdmin'):dashboard.index('function adminPanelMarkup')]
    check('P2K_TEAM_POINTS_CLIENT' not in verify and 'clubProfileAPI' in verify and 'apiAuthoritative ? apiAllowed : fallbackAllowed' in verify,'dashboard admin admission must use public API primary and local fallback without DB')
    auth=tabs[tabs.index('async function authenticatedClubAdmin'):tabs.index('async function initializeRouter')]
    check('P2K_TEAM_POINTS_CLIENT' not in auth and 'fetch(' in auth and 'clubAdminUsernames' in tabs,'classic admin admission must use public API primary without DB')
    check('adminFlagEnabled' in dashboard and 'flag("admin")' in guard and 'explicitAdminEnabled' in tabs,'offline-safe admin flag missing')
    check('oauthSessionClaimsAdmin' in guard and 'oauthSessionClaimsAdmin' in dashboard and 'oauthSessionClaimsAdmin' in tabs,'OAuth admin claims not supported consistently')
    check('adminUsernames' in config and 'adminUsernames: [\"Ximoon\"]' in branding,'transitional local OAuth administrator allow-list missing Ximoon')
    check('admin-page-guard.js' not in analyze and 'admin-access-pending' not in analyze,'Open Match Analyzer is still admin guarded')
    check('Database::connection()' in session,'DB-backed Team Points session behavior unexpectedly changed')
    print('v2.8.1 API-primary authentication/database decoupling tests passed.')

if __name__=='__main__': main()
