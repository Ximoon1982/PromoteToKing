<?php
declare(strict_types=1);
namespace TeamFrance\Leaderboard;
require_once __DIR__.'/Core.php';
use RuntimeException;

final class LeaderboardExport {
    // A leading minus is legal in some username formats; protect spreadsheet
    // readers against interpreting any text cell as a formula.
    private static function safeCell(string $v): string {
        return preg_match('/^[\s\x00-\x1f]*[=+\-@]|^[\t\r\n]/u',$v)===1?"'".$v:$v;
    }
    public static function build(Store $s,string $kind,?int $seasonId): array {
        if(!in_array($kind,['daily','arena'],true))throw new RuntimeException('Unknown export category.');
        // Use a consistent SQLite read snapshot for the selected season and its rows.
        return $s->tx(function()use($s,$kind,$seasonId){
            $label='all-seasons';
            if($seasonId!==null){
                $season=$s->one('SELECT id,name FROM seasons WHERE id=? AND kind=?',[$seasonId,$kind]);
                if(!$season)throw new RuntimeException('Season not found in the selected category.');
                $slug=trim(preg_replace('/[^a-zA-Z0-9]+/','-',(string)$season['name'])??'','-');
                $label='season-'.$seasonId.($slug!==''?'-'.strtolower(substr($slug,0,70)):'');
                $rows=$s->leaderboard($seasonId,true);
            }else $rows=$s->categoryLeaderboard($kind,true);
            $fp=fopen('php://temp/maxmemory:2097152','w+b');
            if($fp===false)throw new RuntimeException('CSV export unavailable.');
            try{
                fputcsv($fp,['rank','username','points',$kind==='daily'?'matches':'arenas'],',','"','',"\r\n");
                foreach($rows as $row){
                    $points=rtrim(rtrim(number_format((float)$row['points'],2,'.',''),'0'),'.');
                    if(fputcsv($fp,[$row['rank']??'',self::safeCell((string)$row['username']),$points,(int)$row['events']],',','"','',"\r\n")===false)throw new RuntimeException('CSV export write failed.');
                }
                rewind($fp);$csv=stream_get_contents($fp);
                if($csv===false)throw new RuntimeException('CSV export read failed.');
            }finally{fclose($fp);}
            return ['filename'=>'team-france-'.$kind.'-'.$label.'-'.gmdate('Ymd-His').'Z.csv','csv'=>$csv];
        });
    }
}
