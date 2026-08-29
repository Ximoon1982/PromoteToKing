<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

/** Parser for Chess.com live-arena HTML/Results CSV used by the durable MCA acquisition worker. */
final class McaArenaParser
{
    public static function arenaPage(string $html): array
    {
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $date = McaIndexParser::extractDateFromText($decoded);
        $rating = null; $players = null; $maxScorers = null;
        if (preg_match('~Rating:\s*([^<\r\n]+)~i', $decoded, $m)) $rating = trim(preg_replace('/\s+/u',' ', $m[1]) ?? $m[1]);
        if (preg_match('~\b(\d+)\s+players\b~i', $decoded, $m)) $players = (int)$m[1];
        if (preg_match('~\((\d+)\s+max eligible scorers per club\)~i', $decoded, $m)) $maxScorers = (int)$m[1];
        $csvUrl = null;
        if (preg_match('~href=["\']([^"\']+/download-results)["\']~i', $html, $m)) $csvUrl = self::absoluteChessUrl($m[1]);
        $gameIds=[];$gameUuids=[];
        if (preg_match('~data-game-ids=["\']([^"\']*)["\']~i',$html,$m)) $gameIds=array_values(array_filter(array_map('trim',explode(',',html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'))),static fn($v)=>$v!==''));
        if (preg_match('~data-game-uuids=["\']([^"\']*)["\']~i',$html,$m)) $gameUuids=array_values(array_filter(array_map('trim',explode(',',html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8'))),static fn($v)=>$v!==''));
        return [
            'event_start_at'=>$date['event_start_at']??null,'event_date'=>$date['event_date']??null,'date_precision'=>$date['date_precision']??'unknown',
            'rating'=>$rating,'advertised_players'=>$players,'max_eligible_scorers'=>$maxScorers,'csv_url'=>$csvUrl,
            'clubs_pages'=>self::paginationTotal($html,'clubs'),'players_pages'=>self::paginationTotal($html,'players'),'pairings_pages'=>self::paginationTotal($html,'pairings'),
            'embedded_game_ids'=>$gameIds,'embedded_game_uuids'=>$gameUuids,
        ];
    }

    public static function clubRows(string $html): array
    {
        $section=self::between($html,'Club Results','Player Results');
        if($section==='')$section=$html;
        if(!preg_match('~<tbody[^>]*>(.*?)</tbody>~is',$section,$tb))return [];
        preg_match_all('~<tr[^>]*>(.*?)</tr>~is',$tb[1],$rows);
        $out=[];
        foreach($rows[1]??[] as $row){
            if(!preg_match('~#\s*(\d+)~',$row,$r))continue;
            if(!preg_match('~href=["\']https?://www\.chess\.com/club/([^"\'?]+)[^"\']*["\']~i',$row,$c))continue;
            $slug=strtolower(rawurldecode(trim($c[1])));
            $name='';
            if(preg_match_all('~<a\b[^>]*href=["\']https?://www\.chess\.com/club/[^"\']+["\'][^>]*>(.*?)</a>~is',$row,$nms,PREG_SET_ORDER)){
                foreach($nms as $nm){
                    $candidate='';
                    if(preg_match('~title=["\']([^"\']+)["\']~i',$nm[0],$tt))$candidate=self::cleanText($tt[1]);
                    if($candidate==='')$candidate=self::cleanText($nm[1]);
                    if($candidate!==''){$name=$candidate;break;}
                }
            }
            preg_match_all('~<td[^>]*class=["\'][^"\']*table-text-right[^"\']*["\'][^>]*>\s*([^<]+)\s*</td>~is',$row,$nums);
            if(count($nums[1]??[])<2)continue;
            $total=(int)preg_replace('/[^0-9]/','',$nums[1][0]);
            $score=self::number($nums[1][1]); if($score===null)continue;
            $out[]=['rank'=>(int)$r[1],'club'=>$name,'club_slug'=>$slug,'club_url'=>'https://www.chess.com/club/'.$slug,'total_players'=>$total,'score'=>$score];
        }
        return $out;
    }

    public static function playerRows(string $html): array
    {
        $section=self::between($html,'Player Results','Pairings'); if($section==='')$section=$html;
        if(!preg_match('~<tbody[^>]*>(.*?)</tbody>~is',$section,$tb))return [];
        preg_match_all('~<tr[^>]*class=["\'][^"\']*tournaments-live-view-results-row[^"\']*["\'][^>]*>(.*?)</tr>~is',$tb[1],$rows);
        $out=[];
        foreach($rows[1]??[] as $row){
            if(!preg_match('~#\s*(\d+)~',$row,$rank))continue;
            $username=self::usernameFromRow($row); if($username===null)continue;
            $rating=null;if(preg_match('~class=["\'][^"\']*user-rating[^"\']*["\'][^>]*>\s*\((\d+)\)~is',$row,$rt))$rating=(int)$rt[1];
            $clubSlug=null;$clubName=null;
            if(preg_match('~href=["\']https?://www\.chess\.com/club/([^"\'?]+)[^"\']*["\'][^>]*>(.*?)</a>~is',$row,$cl)){$clubSlug=strtolower(rawurldecode(trim($cl[1])));$clubName=self::cleanText($cl[2]);}
            $score=null;if(preg_match('~class=["\'][^"\']*tournaments-live-view-total-score[^"\']*["\'][^>]*>\s*([^<]+)~is',$row,$sc))$score=self::number($sc[1]);
            if($score===null)continue;
            $wins=$draws=$losses=$bye=$streak=null;
            if(preg_match('~v-tooltip=["\']\s*(\d+)\s+wins,\s*(\d+)\s+draws,\s*(\d+)\s+bye["\']~i',$row,$wd)){$wins=(int)$wd[1];$draws=(int)$wd[2];$bye=(int)$wd[3];}
            if(preg_match('~class=["\'][^"\']*lost[^"\']*["\'][^>]*>\s*(\d+)\s*L~i',$row,$lo))$losses=(int)$lo[1];
            if(preg_match('~class=["\'][^"\']*tournaments-live-view-streak-record[^"\']*["\'][^>]*>\s*(\d+)~is',$row,$st))$streak=(int)$st[1];
            $out[]=['rank'=>(int)$rank[1],'username'=>$username,'username_key'=>strtolower($username),'rating'=>$rating,'club'=>$clubName,'club_slug'=>$clubSlug,'score'=>$score,'wins'=>$wins,'draws'=>$draws,'losses'=>$losses,'bye'=>$bye,'streak'=>$streak];
        }
        return $out;
    }

    public static function pairingRows(string $html): array
    {
        $pos=stripos($html,'>Pairings<');$section=$pos===false?$html:substr($html,$pos);
        if(!preg_match('~<tbody[^>]*>(.*?)</tbody>~is',$section,$tb))return [];
        preg_match_all('~<tr[^>]*>(.*?)</tr>~is',$tb[1],$rows);
        $out=[];
        foreach($rows[1]??[] as $row){
            if(!preg_match('~https?://www\.chess\.com/game/live/(\d+)~i',$row,$g))continue;
            $names=self::usernamesFromRow($row); if(count($names)<2)continue;
            preg_match_all('~class=["\'][^"\']*user-rating[^"\']*["\'][^>]*>\s*\((\d+)\)~is',$row,$ratings);
            $decodedRow=html_entity_decode($row,ENT_QUOTES|ENT_HTML5,'UTF-8');if(!preg_match('~>\s*(1\s*-\s*0|0\s*-\s*1|½\s*-\s*½|1/2\s*-\s*1/2)\s*<~u',$decodedRow,$res))continue;
            $result=preg_replace('/\s+/u',' ',trim($res[1])); if($result==='1/2 - 1/2')$result='½ - ½';
            $out[]=['game_id'=>(int)$g[1],'game_url'=>'https://www.chess.com/game/live/'.$g[1],'white'=>$names[0],'white_key'=>strtolower($names[0]),'white_rating'=>isset($ratings[1][0])?(int)$ratings[1][0]:null,'black'=>$names[1],'black_key'=>strtolower($names[1]),'black_rating'=>isset($ratings[1][1])?(int)$ratings[1][1]:null,'result'=>$result];
        }
        return $out;
    }

    /** Parse both Player Results and Club Results tables from the Chess.com Results CSV. */
    public static function resultsCsv(string $body): array
    {
        $body=preg_replace('/^\xEF\xBB\xBF/','',$body)??$body;
        $lines=preg_split('/\r\n|\n|\r/',$body)?:[];$delimiter=',';
        foreach($lines as $line){if(trim($line)==='')continue;$scores=[','=>substr_count($line,','),';'=>substr_count($line,';'),"\t"=>substr_count($line,"\t")];arsort($scores);if((int)reset($scores)>0)$delimiter=(string)key($scores);break;}
        $mode=null;$map=[];$players=[];$clubs=[];
        foreach($lines as $line){
            if(trim($line)===''){continue;}
            $cols=str_getcsv($line,$delimiter);$norm=array_map(static fn($v)=>strtolower(trim(preg_replace('/\s+/u',' ',(string)$v)??(string)$v)),$cols);
            $positions=[];foreach($norm as $i=>$h)if($h!=='')$positions[$h]=$i;
            $hasUser=array_key_exists('username',$positions);$hasClub=array_key_exists('club',$positions);$hasScore=array_key_exists('score',$positions);$totalPos=self::firstPos($positions,['total players','players','player count']);
            if($hasUser&&$hasClub&&$hasScore){$mode='players';$map=$positions;continue;}
            if(!$hasUser&&$hasClub&&$hasScore&&$totalPos!==null){$mode='clubs';$map=$positions;continue;}
            if($mode==='players'){
                $uPos=$map['username']??null;$cPos=$map['club']??null;$sPos=$map['score']??null;if($uPos===null||$cPos===null||$sPos===null)continue;
                $username=trim((string)($cols[$uPos]??''));if($username==='')continue;$score=self::number($cols[$sPos]??'');if($score===null)continue;
                $rankPos=self::firstPos($map,['rank','place','placement']);$ratingPos=self::firstPos($map,['rating','elo']);$winsPos=self::firstPos($map,['wins','games won','total wins']);$drawsPos=self::firstPos($map,['draws','games drawn','total draws']);$lossPos=self::firstPos($map,['losses','games lost','total losses']);$streakPos=self::firstPos($map,['longest streak','best streak','streak']);$maxWinsPos=self::firstPos($map,['most wins','maximum wins','max wins','highest wins']);
                $club=trim((string)($cols[$cPos]??''));
                $players[]=['rank'=>self::intValue($cols,$rankPos),'username'=>$username,'username_key'=>strtolower($username),'rating'=>self::intValue($cols,$ratingPos),'club'=>$club!==''?$club:null,'club_slug'=>null,'score'=>$score,'wins'=>self::intValue($cols,$winsPos),'draws'=>self::intValue($cols,$drawsPos),'losses'=>self::intValue($cols,$lossPos),'bye'=>null,'streak'=>self::intValue($cols,$streakPos),'most_wins'=>self::intValue($cols,$maxWinsPos)];
            }elseif($mode==='clubs'){
                $cPos=$map['club']??null;$sPos=$map['score']??null;$tPos=self::firstPos($map,['total players','players','player count']);if($cPos===null||$sPos===null||$tPos===null)continue;
                $club=trim((string)($cols[$cPos]??''));if($club==='')continue;$score=self::number($cols[$sPos]??'');if($score===null)continue;$rankPos=self::firstPos($map,['rank','place','placement']);
                $clubs[]=['rank'=>self::intValue($cols,$rankPos),'club'=>$club,'club_slug'=>null,'club_url'=>null,'total_players'=>(int)(self::intValue($cols,$tPos)??0),'score'=>$score];
            }
        }
        return ['players'=>$players,'clubs'=>$clubs,'valid'=>($players!==[]||$clubs!==[])];
    }

    private static function usernameFromRow(string $row): ?string
    {
        $names=self::usernamesFromRow($row);
        return $names[0]??null;
    }

    /** @return list<string> */
    private static function usernamesFromRow(string $row): array
    {
        $out=[];
        // Prefer the visible username anchors: their text preserves Chess.com display casing.
        if(preg_match_all('~<a\\b(?=[^>]*href=["\']https?://www\\.chess\\.com/member/[^"\']+["\'])(?=[^>]*data-test-element=["\']user-tagline-username["\'])[^>]*>(.*?)</a>~is',$row,$m)){
            foreach($m[1] as $raw){$name=self::cleanText((string)$raw);if($name!==''&&!self::hasName($out,$name))$out[]=$name;}
        }
        // Avatar anchors carry the canonical display name in title= and are the next-best source.
        if(count($out)<2 && preg_match_all('~<a\\b(?=[^>]*href=["\']https?://www\\.chess\\.com/member/([^"\'?]+)[^"\']*["\'])(?=[^>]*title=["\']([^"\']+)["\'])[^>]*>~is',$row,$m,PREG_SET_ORDER)){
            foreach($m as $x){$name=trim(html_entity_decode((string)$x[2],ENT_QUOTES|ENT_HTML5,'UTF-8'));if($name!==''&&!self::hasName($out,$name))$out[]=$name;}
        }
        if(count($out)<2 && preg_match_all('~href=["\']https?://www\\.chess\\.com/member/([^"\'?]+)[^"\']*["\']~i',$row,$m)){
            foreach($m[1] as $slug){$name=rawurldecode((string)$slug);if($name!==''&&!self::hasName($out,$name))$out[]=$name;}
        }
        return $out;
    }

    private static function hasName(array $names,string $candidate): bool
    {
        $key=strtolower($candidate);foreach($names as $name)if(strtolower((string)$name)===$key)return true;return false;
    }

    private static function paginationTotal(string $html,string $kind): int
    {
        if(preg_match('~data-total-pages=["\'](\d+)["\'][^>]*id=["\']'.preg_quote($kind,'~').'-pagination-bottom["\']~is',$html,$m))return max(1,(int)$m[1]);
        if(preg_match('~id=["\']'.preg_quote($kind,'~').'-pagination-bottom["\'][^>]*data-total-pages=["\'](\d+)["\']~is',$html,$m))return max(1,(int)$m[1]);
        return 1;
    }
    private static function between(string $html,string $from,string $to): string{$a=stripos($html,$from);if($a===false)return '';$b=stripos($html,$to,$a+strlen($from));return $b===false?substr($html,$a):substr($html,$a,$b-$a);}
    private static function cleanText(string $s): string{return trim(preg_replace('/\s+/u',' ',strip_tags(html_entity_decode($s,ENT_QUOTES|ENT_HTML5,'UTF-8')))??'');}
    private static function number(mixed $v): ?float{$s=trim((string)$v);if($s==='')return null;$s=str_replace(["\xc2\xa0",' '],'',$s);$s=str_replace(',','.',$s);return is_numeric($s)?(float)$s:null;}
    private static function intValue(array $cols,?int $pos): ?int{if($pos===null)return null;$s=trim((string)($cols[$pos]??''));if($s==='')return null;$s=preg_replace('/[^0-9-]/','',$s)??'';return $s!==''&&$s!=='-'?(int)$s:null;}
    private static function firstPos(array $positions,array $names): ?int{foreach($names as $n)if(array_key_exists($n,$positions))return (int)$positions[$n];return null;}
    private static function absoluteChessUrl(string $href): string{$href=html_entity_decode(trim($href),ENT_QUOTES|ENT_HTML5,'UTF-8');if(str_starts_with($href,'https://')||str_starts_with($href,'http://'))return $href;return 'https://www.chess.com/'.ltrim($href,'/');}
}
