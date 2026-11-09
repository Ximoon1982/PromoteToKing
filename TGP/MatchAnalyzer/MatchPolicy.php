<?php
declare(strict_types=1);
final class TgpMatchPolicy {
    public static function isClubReference(mixed $value): bool {
        if(!is_string($value)||$value==='')return false;
        $url=parse_url($value); if(!is_array($url))return false;
        if(strtolower((string)($url['scheme']??''))!=='https')return false;
        if(!in_array(strtolower((string)($url['host']??'')),['api.chess.com','www.chess.com','chess.com'],true))return false;
        if(isset($url['user'])||isset($url['pass'])||isset($url['port']))return false;
        return preg_match('~^/(?:pub/)?club/the-golden-phoenix/?$~iD',(string)($url['path']??''))===1;
    }
    public static function containsClub(array $match): bool {
        $teams=$match['teams']??null;
        if(!is_array($teams)||count($teams)!==2)return false;
        foreach($teams as $team) {
            if(!is_array($team))return false;
            foreach(['@id','url'] as $field) if(self::isClubReference($team[$field]??null))return true;
        }
        return false;
    }
}
