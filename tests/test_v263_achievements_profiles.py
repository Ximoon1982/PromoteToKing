from pathlib import Path
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]

def check(condition, message):
    if not condition:
        raise AssertionError(message)

def main():
    page = (ROOT / 'TournamentAchievementBadgesDemo.html').read_text()
    dashboard = (ROOT / 'assets/js/pages/dashboard-v2.js').read_text()
    css = (ROOT / 'assets/css/dashboard-v2.css').read_text()
    worker = (ROOT / 'server/team-points/src/Worker.php').read_text()
    repository = (ROOT / 'server/team-points/src/Repository.php').read_text()
    catalog = (ROOT / 'server/team-points/src/AchievementCatalog.php').read_text()

    check('id="loadMore"' in page and 'PAGE_SIZE=12' in page, 'Achievement players are not paginated with Load more.')
    check('player-cards.php' in page and 'class="avatar"' in page, 'Achievement cards do not load player avatars.')
    check('Math.round(Number(p.points)' not in page and 'maximumFractionDigits:1' in page, 'Team Points are still rounded in achievements.')
    check('p2k-open-player-profile' in page and 'p2k-open-achievement-catalog' in page, 'Embedded achievement actions are not delegated to the parent viewport.')
    check('p2k-open-player-profile' in dashboard and 'openAchievementCatalog' in dashboard, 'UI v2 does not handle achievement-frame profile/catalog messages.')
    check('100dvh' in css and 'p2k-profile-avatar' in css and 'p2k-profile-rank-pair' in css, 'Unified player profile is not viewport safe or lacks avatar/rank layout.')
    check('rank_name' in repository and 'LiveRanksService::thresholds()' in repository, 'Unified profile does not include Live-rank details.')
    check('AchievementCatalog::earned' in repository and 'public static function dailyRankDefinitions' in repository, 'Server achievement evaluation is not centralized.')
    check('singleUnfinishedGameAwaitingSecond' in worker and 'sequential_second_game_pending' in worker, 'One-concurrent-game board handling is missing.')
    check('tournament-first-medal' in catalog and 'live-rank-' in catalog and 'daily-' in catalog, 'Achievement catalogue does not cover tournament, Live and Daily ranks.')

    icons = [
        'first-point.png','matches-10.png','games-100.png','wins-50.png','matches-50.png',
        'wins-250.png','points-1000.png','matches-100.png','live-ranked.png','daily-king.png',
    ]
    for filename in icons:
        path = ROOT / 'assets/images/achievements' / filename
        check(path.is_file(), f'Missing achievement artwork: {filename}')
        with Image.open(path) as image:
            check(image.size[0] == image.size[1] and image.size[0] >= 384, f'Achievement artwork must be square and at least 384px: {filename}')

    check((ROOT / 'server/team-points/public/achievements.php').is_file(), 'Achievement catalogue endpoint is missing.')
    check((ROOT / 'server/team-points/public/player-cards.php').is_file(), 'Player-card avatar endpoint is missing.')
    print('v2.8.1 achievements, avatars, Live ranks, viewport profile and sequential-game tests passed.')

if __name__ == '__main__':
    main()
