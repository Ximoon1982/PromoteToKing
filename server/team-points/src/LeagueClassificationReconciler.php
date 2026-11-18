<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;

/**
 * Safely repairs cached Green match league flags from authoritative Green match names.
 * This touches only is_league and updated_at: no board/game/score reconstruction.
 *
 * Idempotent keyset-free queue: every invocation selects the next mismatches
 * (up to 50). Once a row is fixed it disappears from subsequent selections.
 * This also catches new mismatches after a previous pass has drained.
 */
final class LeagueClassificationReconciler
{
    // Same historical league token rule as Repository::upsertMatchMetadata()
    // and GreenCompatibility::projectMatch().
    public const LEAGUE_REGEX = '/(^|[^A-Z0-9])(1WL|TCMAC|KOTML|TMCL|WKCL|PCL|CW)([^A-Z0-9]|$)/i';

    // MariaDB regexp is case insensitive by explicitly lowercasing both sides.
    private const SQL_LEAGUE_EXPR = "(LOWER(COALESCE(gm.name,'')) REGEXP '(^|[^a-z0-9])(1wl|tcmac|kotml|tmcl|wkcl|pcl|cw)([^a-z0-9]|$)')";
    private const FROM_JOIN = " FROM p2k_tp_match_metadata cm
        JOIN p2k_g_matches gm ON gm.match_id=cm.match_id
        WHERE cm.club_slug=? AND gm.club_verified=1 AND gm.time_class='daily'
          AND COALESCE(cm.is_league,0) <> ";

    public function __construct(private readonly PDO $core, private readonly string $clubSlug) {}

    public static function isLeagueName(string $name): bool
    {
        return preg_match(self::LEAGUE_REGEX,$name)===1;
    }

    public function runStep(int $batchSize=50,?float $deadline=null): array
    {
        $limit=max(1,min(50,$batchSize));
        if($deadline!==null&&microtime(true)+0.5>=$deadline){
            return ['ran'=>false,'deferred'=>true,'reason'=>'insufficient_budget'];
        }
        $club=strtolower(trim($this->clubSlug));
        // The same source name and token pattern are used for 0->1 and 1->0.
        // No OFFSET or full-match reprojection, so partial passes resume safely.
        $sql='SELECT cm.match_id,gm.name,cm.is_league'.self::FROM_JOIN.self::SQL_LEAGUE_EXPR.'
            ORDER BY cm.match_id LIMIT '.$limit;
        $select=$this->core->prepare($sql);
        $select->execute([$club]);
        $candidates=$select->fetchAll(PDO::FETCH_ASSOC)?:[];
        $update=$this->core->prepare('UPDATE p2k_tp_match_metadata
            SET is_league=?,updated_at=UTC_TIMESTAMP()
            WHERE club_slug=? AND match_id=? AND COALESCE(is_league,0)<>?');
        $corrected=0;$toLeague=0;$toFriendly=0;
        foreach($candidates as $candidate){
            if($deadline!==null&&microtime(true)+0.3>=$deadline)break;
            $expected=self::isLeagueName((string)($candidate['name']??''))?1:0;
            $previous=(int)($candidate['is_league']??0);
            if($expected===$previous)continue;
            $update->execute([$expected,$club,(int)$candidate['match_id'],$expected]);
            if($update->rowCount()>0){$corrected++;if($expected===1)$toLeague++;else $toFriendly++;}
        }
        if($corrected>0){
            // Invalidate public read caches only when content actually changed.
            $bump=$this->core->prepare('UPDATE p2k_tp_state
                SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP()
                WHERE club_slug=?');
            $bump->execute([$club]);
        }
        // Exact remaining count is for operational diagnostics, not a cursor.
        $remaining=null;
        if($deadline===null||microtime(true)+0.4<$deadline){
            $count=$this->core->prepare('SELECT COUNT(*)'.self::FROM_JOIN.self::SQL_LEAGUE_EXPR);
            $count->execute([$club]);$remaining=(int)$count->fetchColumn();
        }
        return ['ran'=>true,'checked'=>count($candidates),'corrected'=>$corrected,
            'to_league'=>$toLeague,'to_friendly'=>$toFriendly,
            'remaining'=>$remaining,'drained'=>$remaining===0,
            'batch_size'=>$limit];
    }
}
