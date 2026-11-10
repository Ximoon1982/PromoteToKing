<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/MatchRecruitment/auth.php';
use P2K\Shared\SharedChessGateway;
header('Cache-Control: no-store, private');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
function tgp_overview_reply(int $status,array $value): never {
    http_response_code($status);
    echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function tgp_match_id(array $row): string {
    $candidates=[$row['id']??null,$row['@id']??null,$row['url']??null];
    foreach($candidates as $value){
        $value=trim((string)$value);
        if(preg_match('/^[0-9]{1,18}$/D',$value))return $value;
        if(preg_match('~/(?:pub/)?match/([0-9]{1,18})(?:[/?#]|$)~',$value,$m))return $m[1];
        if(preg_match('~/club/matches/(?:[^/?#]+/)?([0-9]{1,18})(?:[/?#]|$)~',$value,$m))return $m[1];
    }
    return '';
}
function tgp_registered_matches(array $index): array {
    $out=[]; $seen=[];
    foreach(is_array($index['registered']??null)?$index['registered']:[] as $item){
        if(!is_array($item)||strtolower(trim((string)($item['time_class']??'')))!=='daily')continue;
        $id=tgp_match_id($item);
        if($id===''||isset($seen[$id]))continue;
        $seen[$id]=true;
        $out[]=['id'=>$id,'name'=>trim((string)($item['name']??''))?:'Match #'.$id,
            'startTime'=>is_numeric($item['start_time']??null)?(int)$item['start_time']:null,
            'url'=>'https://www.chess.com/club/matches/the-golden-phoenix/'.$id];
    }
    usort($out,static fn(array $a,array $b):int=>($a['startTime']??PHP_INT_MAX)<=>($b['startTime']??PHP_INT_MAX)?:strcmp($a['id'],$b['id']));
    return $out;
}
function tgp_admins(array $club): array {
    $names=[];
    foreach(['admin','admins','super_admins'] as $field){
        foreach(is_array($club[$field]??null)?$club[$field]:[] as $value){
            if(is_array($value))$value=$value['username']??$value['url']??$value['@id']??'';
            $value=trim((string)$value);
            if(str_contains($value,'/'))$value=rawurldecode((string)basename((string)(parse_url($value,PHP_URL_PATH)?:'')));
            if(preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$value))$names[strtolower($value)]=$value;
        }
    }
    natcasesort($names);return array_values($names);
}
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')tgp_overview_reply(405,['ok'=>false,'error'=>'Only GET is allowed.']);
$access=tgp_authorize();
if(!$access['ok'])tgp_overview_reply((int)$access['status'],['ok'=>false,'error'=>'The Golden Phoenix administrator authorization is required.']);
try {
    $app=(array)(p2k_tp_config()['app']??[]);
    $gateway=new SharedChessGateway(null,$app);
    $club=null;$index=null;$errors=[];
    try{$club=$gateway->json('https://api.chess.com/pub/club/the-golden-phoenix',['consumer'=>'tgp-admin-overview','cache_ttl_seconds'=>300,'allow_stale_on_error'=>false]);if(!is_array($club))throw new RuntimeException('Invalid club response.');}
    catch(Throwable $e){error_log('TGP overview club: '.$e->getMessage());$errors[]='Club profile is temporarily unavailable.';}
    try{$index=$gateway->json('https://api.chess.com/pub/club/the-golden-phoenix/matches',['consumer'=>'tgp-admin-overview','cache_ttl_seconds'=>60,'allow_stale_on_error'=>false]);if(!is_array($index))throw new RuntimeException('Invalid match index.');}
    catch(Throwable $e){error_log('TGP overview matches: '.$e->getMessage());$errors[]='The club match list is temporarily unavailable.';}
    if($club===null&&$index===null)tgp_overview_reply(503,['ok'=>false,'error'=>'Chess.com club data is temporarily unavailable.']);
    $avatar=trim((string)($club['icon']??$club['image']??''));
    $validAvatar=preg_match('~^https://images\.chesscomfiles\.com/~i',$avatar)===1;
    $registered=$index!==null?tgp_registered_matches($index):null;
    tgp_overview_reply(200,[
        'ok'=>true,'club'=> $club===null?null:[
            'name'=>(string)($club['name']??'The Golden Phoenix'),
            'membersCount'=>is_numeric($club['members_count']??null)?(int)$club['members_count']:null,
            'admins'=>tgp_admins($club),'icon'=>$validAvatar?$avatar:null,
            'visibility'=>(string)($club['visibility']??''),
            'created'=>is_numeric($club['created']??null)?(int)$club['created']:null,
            'lastActivity'=>is_numeric($club['last_activity']??null)?(int)$club['last_activity']:null,
            'averageDailyRating'=>is_numeric($club['average_daily_rating']??null)?(int)$club['average_daily_rating']:null,
        ],
        'matches'=>$registered,
        'registeredCount'=>is_array($index['registered']??null)?count($index['registered']):null,
        'inProgressCount'=>is_array($index['in_progress']??null)?count($index['in_progress']):null,
        'asOf'=>gmdate(DATE_ATOM),'warnings'=>$errors
    ]);
}catch(Throwable $e){error_log('TGP overview fatal: '.$e->getMessage());tgp_overview_reply(503,['ok'=>false,'error'=>'TGP club overview is temporarily unavailable.']);}
