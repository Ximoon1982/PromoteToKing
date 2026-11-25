#!/usr/bin/env bash
set -euo pipefail
stage="$(mktemp -d)"
pid=""
cleanup(){ if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; fi; rm -rf "$stage"; }
trap cleanup EXIT
mkdir -p "$stage/TeamFrance/MatchAnalyzer"
cp -a TeamFrance/Leaderboard "$stage/TeamFrance/"
cat > "$stage/TeamFrance/MatchAnalyzer/auth.php" <<'PHP'
<?php
function team_france_authorize(): array {
    return ($_SERVER['HTTP_X_TEST_ADMIN']??'')==='yes'
      ? ['ok'=>true,'username'=>'test-admin','csrf'=>'fixture-token']
      : ['ok'=>false,'status'=>403,'code'=>'forbidden'];
}
PHP
php -r 'require $argv[1]."/TeamFrance/Leaderboard/src/Core.php";$s=new \TeamFrance\Leaderboard\Store($argv[1]."/TeamFrance/Leaderboard/data");$s->saveSeason(["kind"=>"daily","name"=>"Fixture"]);' "$stage"
port="$(python3 -c 'import socket;s=socket.socket();s.bind(("127.0.0.1",0));print(s.getsockname()[1])')"
php -S "127.0.0.1:$port" -t "$stage" > "$stage/server.log" 2>&1 &
pid=$!
base="http://127.0.0.1:$port/TeamFrance/Leaderboard/api.php?action=export-csv"
for n in $(seq 1 40); do if curl -s -o /dev/null "$base"; then break; fi; sleep .1; done
test "$(curl -s -o "$stage/denied" -w '%{http_code}' "$base&kind=daily")" = 403
grep -q forbidden "$stage/denied"
test "$(curl -s -X POST -H 'X-Test-Admin: yes' -o /dev/null -w '%{http_code}' "$base&kind=daily")" = 405
test "$(curl -s -H 'X-Test-Admin: yes' -o /dev/null -w '%{http_code}' "$base&kind=arena&season=1")" = 400
test "$(curl -s -H 'X-Test-Admin: yes' -o /dev/null -w '%{http_code}' "$base&kind=daily&season=invalid")" = 400
test "$(curl -s -H 'X-Test-Admin: yes' -D "$stage/headers" -o "$stage/export" -w '%{http_code}' "$base&kind=daily&season=1")" = 200
grep -iEq '^Content-Type: text/csv' "$stage/headers"
grep -iEq '^Content-Disposition: attachment; filename="team-france-daily-season-1-fixture-[0-9]{8}-[0-9]{6}Z.csv"' "$stage/headers"
grep -iEq '^Cache-Control: no-store, private' "$stage/headers"
printf 'rank,username,points,matches\r\n' | cmp - "$stage/export"
echo 'PASS: export HTTP authentication, method validation, invalid scope rejection, headers and real CSV response.'
