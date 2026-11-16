<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/server/events-showcase/src/ArenaDiscoveryAdminService.php';

use P2K\EventsShowcase\ArenaDiscoveryAdminService as Parser;

if (!class_exists(DOMDocument::class)) { fwrite(STDERR, "DOM extension missing\n"); exit(2); }

function check(bool $value, string $message): void {
    if (!$value) {fwrite(STDERR, "FAIL: $message\n"); exit(1);}
}
function row(string $id, string $name, string $club, string $date, string $time): string {
    return '<tr><td><a href="https://www.chess.com/play/arena/'.$id.'">'.$id.'</a></td>'
        .'<td><a class="tournaments-upcoming-name" href="https://www.chess.com/play/arena/'.$id.'">'
        .htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'</a></td>'
        .'<td>'.$club.'</td><td>- 50000</td><td>-</td><td>'
        .'<span class="tournaments-upcoming-start">'.$date.'</span>'
        .'<span class="tournaments-upcoming-start">'.$time.'</span></td></tr>';
}
function sourceHtml(string $rows): string {
    return '<html><head><meta charset="UTF-8"></head><body>'
      .'<div id="tournament-root-index" data-total-pages="12">'
      .'<div id="pagination-top" data-current-page="2"></div>'
      .'<table class="table-component"><tbody>'.$rows.'</tbody></table></div></body></html>';
}
$test = sourceHtml(
    row('31194640', '3 + 0 Blitz', '-', 'Nov 16, 2026', '5:00 AM')
    .row('31186950', 'LA FRANCOPHONIE BLITZ ARENA 3 HOURS', '641979', 'Nov 16, 2026', "5:00\xe2\x80\xafAM")
    .row('31199999', 'Summer test', '123456', 'Jul 12, 2026', '5:00 AM')
    .row('31188888', 'Spring transition', '77777', 'Mar 9, 2026', '5:00 AM')
);
$data = Parser::parseIndexHtml($test);
check($data['currentPage'] === 2 && $data['totalPages'] === 12, 'pagination metadata');
check(count($data['rows']) === 4, 'four tournament rows');
check($data['rows'][0]['startUtc'] === '2026-11-16T13:00:00+00:00', 'November PST UTC conversion');
check($data['rows'][0]['localDate'] === '2026-11-16' && $data['rows'][0]['localTime'] === '14:00', 'November CET conversion');
check($data['rows'][1]['clubId'] === '641979', 'organizer ID');
check($data['rows'][1]['rawSource'] === 'Nov 16, 2026 5:00 AM', 'normalize narrow no-break space');
check($data['rows'][2]['startUtc'] === '2026-07-12T12:00:00+00:00', 'July PDT conversion');
check($data['rows'][2]['localTime'] === '14:00', 'July CEST conversion');
check($data['rows'][3]['startUtc'] === '2026-03-09T12:00:00+00:00', 'US/EU differing DST weeks');
check($data['rows'][3]['localTime'] === '13:00', 'March CET conversion');
$failed = false;
try { Parser::parseIndexHtml('<html><body>Changed structure</body></html>'); }
catch (RuntimeException) { $failed = true; }
check($failed, 'fail closed on changed upstream HTML structure');
echo "OK: arena parser, IDs, pagination, PST/PDT, CET/CEST, changed-source safety\n";
