<?php
declare(strict_types=1);

require_once __DIR__ . '/../server/team-points/src/McaArenaParser.php';

use P2K\TeamPoints\McaArenaParser;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$row = static function (string $username, ?int $rank, float $score=0.0): string {
    $removed = $rank === null;
    $user = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
    $rankText = $removed ? '-' : '#' . $rank;
    $removedIcon = $removed
        ? '<span class="cc-icon-glyph cc-icon-size-16 tournaments-live-view-removed-icon" '
            . 'v-tooltip="User removed from the tournament"><svg data-glyph="local-door-right-exit"></svg></span>'
        : '';
    return '<tr class="tournaments-live-view-results-row">'
        . '<td><span class="table-text-center tournaments-live-view-results-rank">' . $rankText . '</span>'
        . '<a data-test-element="user-tagline-username" href="https://www.chess.com/member/' . $user . '">'
        . $user . '</a>' . $removedIcon . '</td>'
        . '<td><div class="tournaments-live-view-total-score">' . $score . '</div></td>'
        . '</tr>';
};
$page = static function (array $rows, int $number, int $total=23): string {
    return '<section><h2>Club Results</h2></section>'
        . '<h2>Player Results</h2><table class="tournaments-live-view-results-table"><tbody>'
        . implode('', $rows) . '</tbody></table>'
        . '<div data-current-page="' . $number . '" data-total-pages="' . $total . '" id="players-pagination-bottom"></div>'
        . '<h2>Pairings</h2>';
};

$ranked = [];
for ($i=1; $i<=19; $i++) $ranked[] = $row('rankeduser' . $i, 180+$i, (float)$i);
$removed = [];
for ($i=1; $i<=6; $i++) $removed[] = $row('removeduser' . $i, null, (float)($i*2));
$mixed = $page(array_merge($ranked,$removed), 22);
$parsed = McaArenaParser::playerRows($mixed);
$assert(count($parsed)===19, 'Mixed page must retain the 19 ranked rows and skip removed users');
$assert($parsed[0]['username']==='rankeduser1' && $parsed[0]['rank']===181 && $parsed[0]['score']===1.0,
    'Existing ranked player extraction and score must remain unchanged');
$assert(!McaArenaParser::removedOnlyPlayerPage($mixed,22), 'Mixed page is not a removed-only page');

$removedOnly = $page($removed,23);
$assert(McaArenaParser::playerRows($removedOnly)===[], 'Removed-only page contains no ranked score rows');
$assert(McaArenaParser::removedOnlyPlayerPage($removedOnly,23),
    'Last page with only removed entries must be recognized and safely advanced');
$assert(!McaArenaParser::removedOnlyPlayerPage($removedOnly,22),
    'Requesting page 22 must not accept a repeated page 23');
$assert(!McaArenaParser::removedOnlyPlayerPage($page($removed,22),23),
    'Requesting page 23 must not accept a repeated page 22');
$assert(!McaArenaParser::removedOnlyPlayerPage($page($removed,23,22),23),
    'Page number must be within the advertised total');
$assert(!McaArenaParser::removedOnlyPlayerPage($page([],23),23),
    'Genuinely empty Player Results table must remain an error');
$assert(!McaArenaParser::removedOnlyPlayerPage(str_replace('tournaments-live-view-removed-icon',
    'ordinary-player-icon',$removedOnly),23),'Rows without the removed-player icon are not valid evidence');
$assert(!McaArenaParser::removedOnlyPlayerPage(str_replace('results-rank">-',
    'results-rank">#1',$removedOnly),23),'Ranked entries must not be mistaken for removed entries');
$assert(!McaArenaParser::removedOnlyPlayerPage(str_replace('id="players-pagination-bottom"',
    'id="another-pagination-bottom"',$removedOnly),23),'Wrong pagination element must not be accepted');
$assert(!McaArenaParser::removedOnlyPlayerPage(str_replace('<h2>Player Results</h2>',
    '<h2>Other Results</h2>',$removedOnly),23),'Unrelated tables must not be accepted');
$assert(!McaArenaParser::removedOnlyPlayerPage(str_replace('https://www.chess.com/member/',
    'https://www.chess.com/not-a-member/',$removedOnly),23),'Removed entries require recognizable usernames');

// The original page-22 HTML can be supplied locally for exact fixture testing:
// php tests/php_v21511_mca_removed_pages.php /path/to/chesscom-page22.html
if (isset($argv[1])) {
    $actual = file_get_contents($argv[1]);
    $assert(is_string($actual) && $actual!=='', 'Unable to read supplied Chess.com HTML');
    $rows = McaArenaParser::playerRows($actual);
    $assert(count($rows)===19, 'Supplied page 22 should have 19 ranked players');
    $assert(substr_count($actual,'tournaments-live-view-removed-icon')===6,
        'Supplied page 22 should contain 6 removed participants');
    $assert(!McaArenaParser::removedOnlyPlayerPage($actual,22), 'Supplied page 22 is mixed');
    echo "PASS real Chess.com page-22 fixture: 19 ranked, 6 removed\n";
}
echo "PASS MCA removed-only page acceptance; scores unchanged; empty/mismatched pages rejected\n";
