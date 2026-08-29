<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class Repository
{
    private const CORE_SCHEMA_VERSION = 17;
    private const ANALYTICS_SCHEMA_VERSION = 10;
    private PDO $pdo;
    private ?PDO $analyticsPdo = null;
    private bool $analyticsFailed = false;
    /** Per-request club resolution memo; avoids repeated population-wide checks. */
    private array $resolvedClubSlugs = [];
    /** Queue job -> club scope memo for canonical outstanding-work deduplication. */
    private array $queueJobScopes = [];

    public function __construct(PDO $pdo, ?PDO $analytics = null)
    {
        $this->pdo = $pdo;
        $this->analyticsPdo = $analytics;
    }

    public function core(): PDO { return $this->pdo; }

    public function analytics(): PDO
    {
        if ($this->analyticsPdo instanceof PDO) return $this->analyticsPdo;
        if ($this->analyticsFailed) throw new \RuntimeException('The Analytics database is unavailable.');
        try {
            $this->analyticsPdo = Database::analytics();
            return $this->analyticsPdo;
        } catch (\Throwable $exception) {
            $this->analyticsFailed = true;
            throw $exception;
        }
    }

    public function tryAnalytics(): ?PDO
    {
        try { return $this->analytics(); } catch (\Throwable) { return null; }
    }

    /** Public reads are strictly read-only in v2.8.8; CRON owns materialization. */
    private function refreshAnalyticsForRead(string $clubSlug): array
    {
        return ['ran'=>false,'refreshed'=>false,'read_only'=>true];
    }

    private function refreshAchievementsForRead(string $clubSlug): array
    {
        return ['ran'=>false,'refreshed'=>false,'read_only'=>true];
    }

    private function executeSqlFile(PDO $pdo, string $schemaPath): void
    {
        $sql = file_get_contents($schemaPath);
        if ($sql === false) throw new \RuntimeException("Unable to read schema file: {$schemaPath}");
        foreach (preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [] as $statement) {
            $statement = trim($statement);
            if ($statement !== '') $pdo->exec($statement);
        }
    }

    /** v2.8.0 is fresh-only and installs both data domains. */
    public function installSchema(string $schemaPath = ''): void
    {
        $root = dirname(__DIR__);
        $this->executeSqlFile($this->pdo, $root . '/sql/core-schema.sql');
        $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-schema.sql');
    }

    public function schemaVersion(): int
    {
        try { return (int)$this->pdo->query('SELECT COALESCE(MAX(version),0) FROM p2k_core_schema_version')->fetchColumn(); }
        catch (\Throwable) { return 0; }
    }

    public function analyticsSchemaVersion(): int
    {
        try { return (int)$this->analytics()->query('SELECT COALESCE(MAX(version),0) FROM p2k_analytics_schema_version')->fetchColumn(); }
        catch (\Throwable) { return 0; }
    }

    public function schemaInstalled(): bool
    {
        return $this->schemaVersion() >= self::CORE_SCHEMA_VERSION && $this->analyticsSchemaVersion() >= self::ANALYTICS_SCHEMA_VERSION;
    }

    /** v2.8.2 converges all supported v2.8 branches in place; pre-v2.8 layouts remain unsupported. */
    public function upgradeExistingSchema(string $schemaPath = ''): bool
    {
        $coreVersion = $this->schemaVersion();
        $analyticsVersion = $this->analyticsSchemaVersion();
        if ($coreVersion < 1 || $analyticsVersion < 1) return false;

        $root = dirname(__DIR__);
        if ($coreVersion < 3) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.8.1-hotfix2.sql');
        if ($coreVersion < 4) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.8.2.sql');
        if ($coreVersion < 5) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.8.5.sql');
        if ($coreVersion < 6) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.8.8.sql');
        if ($coreVersion < 7) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.8.10.sql');
        if ($coreVersion < 8) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.8.11.sql');
        if ($coreVersion < 9) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.2.sql');
        if ($coreVersion < 10) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.5.sql');
        if ($coreVersion < 11) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-post-v2.9.7-observation-provenance.sql');
        if ($coreVersion < 12) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.10.sql');
        if ($coreVersion < 13) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.13.sql');
        if ($coreVersion < 14) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.18.sql');
        if ($coreVersion < 15) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.22.sql');
        if ($coreVersion < 16) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.9.22.6.sql');
        if ($coreVersion < 17) $this->executeSqlFile($this->pdo, $root . '/sql/core-migration-v2.10.6.sql');
        if ($analyticsVersion < 3) $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.8.1-hotfix2.sql');
        if ($analyticsVersion < 4) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.8.2.sql');
        }
        if ($analyticsVersion < 5) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.8.3.sql');
        }
        if ($analyticsVersion < 6) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.9.2.sql');

            // Force the first post-upgrade Analytics read to populate the combined schema
            // even when a pre-upgrade six-hour filesystem refresh marker is still recent.
            try {
                $config = \p2k_tp_config();
                $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
                $clubSlug = strtolower((string)($config['app']['club_slug'] ?? 'promote-to-king'));
                $analyticsRoot = \P2K\Shared\FilesystemCache::runtimeRoot($storage) . '/analytics';
                $safeSlug = preg_replace('/[^a-z0-9_-]+/i', '-', $clubSlug);
                $marker = $analyticsRoot . '/refresh-' . $safeSlug . '.json';
                $achievementMarker = $analyticsRoot . '/refresh-' . $safeSlug . '-achievements.json';
                if (is_file($marker)) @unlink($marker);
                if (is_file($achievementMarker)) @unlink($achievementMarker);
                $this->analytics()->exec("DELETE FROM p2k_an_refresh_state WHERE domain_key IN ('all','achievements')");
            } catch (\Throwable) { /* Rebuild will still occur when the normal refresh window expires. */ }
        }
        if ($analyticsVersion < 7) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.9.18.sql');
        }
        if ($analyticsVersion < 8) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.10.4.sql');
            // MCA event dates change historical achievement timestamps. Force the
            // next achievement materialization to consume schema-8 provenance.
            try { $this->analytics()->exec("DELETE FROM p2k_an_refresh_state WHERE domain_key='achievements'"); } catch (\Throwable) {}
        }
        if ($analyticsVersion < 9) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.10.6.sql');
        }
        if ($analyticsVersion < 10) {
            $this->executeSqlFile($this->analytics(), $root . '/sql/analytics-migration-v2.10.9.sql');
        }
        if ($this->schemaVersion() >= 14) {
            try { (new MiacService($this->pdo, strtolower((string)(\p2k_tp_config()['app']['club_slug'] ?? 'promote-to-king'))))->importSeedIfNeeded(); } catch (\Throwable $miacSeedError) { error_log('P2K MIAC seed import: '.$miacSeedError->getMessage()); }
        }
        if ($this->schemaVersion() >= 13) {
            // Idempotent: fresh installs have nothing to compact; upgraded installations
            // collapse only currently-outstanding legacy duplicates.
            $this->compactOutstandingQueue(null,1000000);
        }
        return $this->schemaInstalled();
    }

    public function storeMemberRatings(string $clubSlug, string $username, ?int $dailyRating, ?int $chess960Rating): bool
    {
        $clubSlug=strtolower(trim($clubSlug)); $usernameKey=\p2k_tp_username_key($username);
        $dailyRating=$dailyRating!==null && $dailyRating>0 ? $dailyRating : null;
        $chess960Rating=$chess960Rating!==null && $chess960Rating>0 ? $chess960Rating : null;
        if ($clubSlug==='' || $usernameKey==='') return false;
        $before=$this->pdo->prepare('SELECT daily_rating,chess960_rating FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
        $before->execute([$clubSlug,$usernameKey]);$old=$before->fetch(PDO::FETCH_ASSOC);
        if(!is_array($old))return false;
        $oldDaily=$old['daily_rating']===null?null:(int)$old['daily_rating'];
        $old960=$old['chess960_rating']===null?null:(int)$old['chess960_rating'];
        $nextDaily=$dailyRating??$oldDaily;$next960=$chess960Rating??$old960;
        $changed=$nextDaily!==$oldDaily || $next960!==$old960;
        $q=$this->pdo->prepare(
            'UPDATE p2k_tp_members SET daily_rating=COALESCE(?,daily_rating),chess960_rating=COALESCE(?,chess960_rating),stats_checked_at=UTC_TIMESTAMP(),stats_unverified_since=NULL,rating_updated_at=CASE WHEN ? IS NOT NULL OR ? IS NOT NULL THEN UTC_TIMESTAMP() ELSE rating_updated_at END WHERE club_slug=? AND username_key=?'
        );
        $q->execute([$dailyRating,$chess960Rating,$dailyRating,$chess960Rating,$clubSlug,$usernameKey]);
        if($changed)$this->touchCoreGeneration($clubSlug);
        return $changed;
    }

    public function markPlayerMatchesChecked(string $clubSlug,string $username): bool
    {
        $q=$this->pdo->prepare('UPDATE p2k_tp_members SET player_matches_checked_at=UTC_TIMESTAMP(),player_matches_unverified_since=NULL WHERE club_slug=? AND username_key=?');
        $q->execute([strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
        return $q->rowCount() > 0;
    }

    public function memberRefreshSnapshot(string $clubSlug,string $username): ?array
    {
        $q=$this->pdo->prepare('SELECT member_id,current_member,player_matches_checked_at,player_matches_observed_at,player_matches_passive_observed_at,player_matches_unverified_since,stats_checked_at,stats_observed_at,stats_passive_observed_at,stats_unverified_since,profile_observed_at,profile_updated_at FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
        $q->execute([strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        return is_array($row)?$row:null;
    }

    public function markPlayerStatsChecked(string $clubSlug,string $username): void
    {
        $q=$this->pdo->prepare('UPDATE p2k_tp_members SET stats_checked_at=UTC_TIMESTAMP(),stats_unverified_since=NULL WHERE club_slug=? AND username_key=?');
        $q->execute([strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
    }

    public static function ratingsFromStats(array $stats): array
    {
        $read=static function(array $payload,string $key): ?int {
            $rating=$payload[$key]['last']['rating']??null;
            return is_numeric($rating) && (int)$rating>0 ? (int)$rating : null;
        };
        return ['daily_rating'=>$read($stats,'chess_daily'),'chess960_rating'=>$read($stats,'chess960_daily')];
    }


    /** Record a claim-backed browser observation without promoting it to verified freshness. */
    public function markPlayerMatchesObserved(string $clubSlug,string $username):bool
    {
        $q=$this->pdo->prepare("UPDATE p2k_tp_members SET player_matches_observed_at=UTC_TIMESTAMP(),player_matches_unverified_since=COALESCE(player_matches_unverified_since,UTC_TIMESTAMP()) WHERE club_slug=? AND username_key=? AND current_member=1");
        $q->execute([strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
        return $q->rowCount()>0;
    }

    /** Store low-risk observed ratings separately; verified rating columns remain authoritative. */
    public function storeObservedMemberRatings(string $clubSlug,string $username,?int $daily,?int $chess960,string $source='acamr_claim',bool $claimFreshness=true):bool
    {
        $freshnessColumn=$claimFreshness?'stats_observed_at':'stats_passive_observed_at';
        $unverified=$claimFreshness?',stats_unverified_since=COALESCE(stats_unverified_since,UTC_TIMESTAMP())':'';
        $q=$this->pdo->prepare("UPDATE p2k_tp_members SET {$freshnessColumn}=UTC_TIMESTAMP(){$unverified},observed_daily_rating=?,observed_chess960_rating=?,observed_rating_source=? WHERE club_slug=? AND username_key=? AND current_member=1");
        $q->execute([$daily,$chess960,substr($source,0,32),strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
        return $q->rowCount()>0;
    }

    /** Passive browser player-match observations are recorded but never suppress the claim/audit freshness clock. */
    public function markPlayerMatchesPassiveObserved(string $clubSlug,string $username):bool
    {
        $q=$this->pdo->prepare("UPDATE p2k_tp_members SET player_matches_passive_observed_at=UTC_TIMESTAMP() WHERE club_slug=? AND username_key=? AND current_member=1");
        $q->execute([strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
        return $q->rowCount()>0;
    }

    /** Persist display-only profile observations in shadow columns; verified profile fields remain untouched. */
    public function storeObservedPlayerProfile(string $clubSlug,string $username,array $profile):bool
    {
        $clubSlug=strtolower(trim($clubSlug));$key=\p2k_tp_username_key($username);if($clubSlug===''||$key==='')return false;
        $avatar=trim((string)($profile['avatar']??''));$url=trim((string)($profile['url']??$profile['@id']??''));$country=trim((string)($profile['country']??''));
        if($country!==''&&str_contains($country,'/')){$parts=array_values(array_filter(explode('/',$country)));$country=strtoupper((string)end($parts));}
        $status=trim((string)($profile['status']??''));
        $q=$this->pdo->prepare("UPDATE p2k_tp_members SET profile_observed_at=UTC_TIMESTAMP(),observed_avatar_url=NULLIF(?,''),observed_profile_url=NULLIF(?,''),observed_country_code=NULLIF(?,''),observed_profile_status=NULLIF(?,'') WHERE club_slug=? AND username_key=?");
        $q->execute([$avatar,$url,$country,$status,$clubSlug,$key]);return $q->rowCount()>0;
    }

    public function ensureState(string $clubSlug): void
    {
        $clubSlug=strtolower(trim($clubSlug));
        if($clubSlug==='')return;
        $q=$this->pdo->prepare("INSERT IGNORE INTO p2k_tp_state(club_slug,core_generation,updated_at) VALUES(?,1,UTC_TIMESTAMP())");
        $q->execute([$clubSlug]);
    }

    public function coreGeneration(string $clubSlug): int
    {
        $clubSlug=strtolower(trim($clubSlug)); $this->ensureState($clubSlug);
        $q=$this->pdo->prepare('SELECT core_generation FROM p2k_tp_state WHERE club_slug=? LIMIT 1');
        $q->execute([$clubSlug]); return max(1,(int)($q->fetchColumn()?:1));
    }

    public function touchCoreGeneration(string $clubSlug): int
    {
        $clubSlug=strtolower(trim($clubSlug)); $this->ensureState($clubSlug);
        $q=$this->pdo->prepare('UPDATE p2k_tp_state SET core_generation=core_generation+1,updated_at=UTC_TIMESTAMP() WHERE club_slug=?');
        $q->execute([$clubSlug]); return $this->coreGeneration($clubSlug);
    }

    public function markMembersObserved(string $clubSlug,?int $memberCount=null,bool $authoritative=false): void
    {
        $clubSlug=strtolower(trim($clubSlug)); $this->ensureState($clubSlug);
        $verified=$authoritative?',members_last_verified_at=UTC_TIMESTAMP()':'';
        $q=$this->pdo->prepare("UPDATE p2k_tp_state SET members_last_observed_at=UTC_TIMESTAMP(),members_last_observed_count=COALESCE(?,members_last_observed_count){$verified},updated_at=UTC_TIMESTAMP() WHERE club_slug=?");
        $q->execute([$memberCount,$clubSlug]);
    }

    public function markMemberCountObserved(string $clubSlug,int $memberCount): void
    {
        if($memberCount<0)return;$clubSlug=strtolower(trim($clubSlug));$this->ensureState($clubSlug);
        $q=$this->pdo->prepare('UPDATE p2k_tp_state SET members_last_observed_count=?,members_count_observed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE club_slug=?');
        $q->execute([$memberCount,$clubSlug]);
    }

    public function markClubIndexObserved(string $clubSlug,array $counts=[],bool $authoritative=false): void
    {
        $clubSlug=strtolower(trim($clubSlug)); $this->ensureState($clubSlug);
        $verified=$authoritative?',club_index_last_verified_at=UTC_TIMESTAMP()':'';
        $q=$this->pdo->prepare("UPDATE p2k_tp_state SET club_index_last_observed_at=UTC_TIMESTAMP(),club_index_registered_observed=COALESCE(?,club_index_registered_observed),club_index_in_progress_observed=COALESCE(?,club_index_in_progress_observed),club_index_finished_observed=COALESCE(?,club_index_finished_observed){$verified},updated_at=UTC_TIMESTAMP() WHERE club_slug=?");
        $q->execute([isset($counts['registered'])?(int)$counts['registered']:null,isset($counts['in_progress'])?(int)$counts['in_progress']:null,isset($counts['finished'])?(int)$counts['finished']:null,$clubSlug]);
    }

    public function state(string $clubSlug): array
    {
        $clubSlug=strtolower(trim($clubSlug)); $this->ensureState($clubSlug);
        return $this->readState($clubSlug);
    }

    /**
     * Read an existing Core freshness row without creating or updating anything.
     * Public GET paths must use this helper so they can never wait behind a worker
     * transaction merely because INSERT IGNORE/UPDATE is touching p2k_tp_state.
     */
    private function readState(string $clubSlug): array
    {
        $clubSlug=strtolower(trim($clubSlug));
        if($clubSlug==='')return [];
        $q=$this->pdo->prepare('SELECT core_generation,members_last_observed_at,members_last_verified_at,members_last_observed_count,members_count_observed_at,club_index_last_observed_at,club_index_last_verified_at,club_index_registered_observed,club_index_in_progress_observed,club_index_finished_observed,updated_at FROM p2k_tp_state WHERE club_slug=? LIMIT 1');
        $q->execute([$clubSlug]); return $q->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function storePlayerProfileSnapshot(string $clubSlug,string $username,array $profile,bool $markChecked=true): bool
    {
        $clubSlug=strtolower(trim($clubSlug)); $key=\p2k_tp_username_key($username);
        if($clubSlug===''||$key==='')return false;
        $avatar=trim((string)($profile['avatar']??''));
        $profileUrl=trim((string)($profile['url']??$profile['@id']??''));
        $country=trim((string)($profile['country']??''));
        if($country!=='' && str_contains($country,'/')){$parts=array_values(array_filter(explode('/',$country)));$country=(string)end($parts);}
        $status=trim((string)($profile['status']??''));
        $q=$this->pdo->prepare("UPDATE p2k_tp_members SET
            avatar_url=CASE WHEN ?=1 THEN NULLIF(?,'') WHEN ?<>'' THEN ? ELSE avatar_url END,
            profile_url=CASE WHEN ?<>'' THEN ? ELSE profile_url END,
            country_code=CASE WHEN ?<>'' THEN ? ELSE country_code END,
            profile_status=CASE WHEN ?<>'' THEN ? ELSE profile_status END,
            avatar_checked_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE avatar_checked_at END,
            profile_updated_at=UTC_TIMESTAMP()
            WHERE club_slug=? AND username_key=?");
        $checked=$markChecked?1:0;
        $q->execute([$checked,$avatar,$avatar,$avatar,$profileUrl,$profileUrl,$country,$country,$status,$status,$checked,$clubSlug,$key]);
        $changed=$q->rowCount()>0;
        $playerId=(int)($profile['player_id']??0);
        if($markChecked&&$playerId>0){try{(new MiacService($this->pdo,$clubSlug))->observePlayerId($username,$playerId);}catch(\Throwable $identityError){RuntimeTelemetry::record('miac_player_id_error',['username'=>$key,'error'=>$identityError->getMessage()]);}}
        return $changed;
    }

    public function playerProfileSnapshot(string $clubSlug,string $username): ?array
    {
        $q=$this->pdo->prepare('SELECT username,avatar_url,profile_url,country_code,profile_status,avatar_checked_at,profile_updated_at FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
        $q->execute([strtolower(trim($clubSlug)),\p2k_tp_username_key($username)]);
        $row=$q->fetch(PDO::FETCH_ASSOC); return is_array($row)?$row:null;
    }

    /** @param string[] $usernameKeys @return array<string,array<string,mixed>> */
    public function playerProfileSnapshots(string $clubSlug,array $usernameKeys): array
    {
        $keys=array_values(array_unique(array_filter(array_map('strval',$usernameKeys))));
        if($keys===[])return [];
        if(count($keys)>100)$keys=array_slice($keys,0,100);
        $q=$this->pdo->prepare('SELECT username_key,username,avatar_url,profile_url,country_code,profile_status,avatar_checked_at,profile_updated_at FROM p2k_tp_members WHERE club_slug=? AND username_key IN ('.implode(',',array_fill(0,count($keys),'?')).')');
        $q->execute(array_merge([strtolower(trim($clubSlug))],$keys));$out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[(string)$row['username_key']]=$row;
        return $out;
    }


    /** Cache Chess.com club icon metadata for an opponent, mirroring member-avatar semantics. */
    public function storeOpponentProfileSnapshot(string $clubSlug,string $opponentSlug,array $profile,bool $markChecked=true): bool
    {
        $clubSlug=strtolower(trim($clubSlug));$slug=strtolower(trim($opponentSlug));
        if($clubSlug===''||$slug==='')return false;
        $icon=trim((string)($profile['icon']??$profile['avatar']??$profile['image']??''));
        $name=trim((string)($profile['name']??''));
        $url=self::chessClubHumanUrl((string)($profile['url']??$profile['@id']??''),$slug);
        $country=trim((string)($profile['country']??''));
        if($country!==''&&str_contains($country,'/')){$parts=array_values(array_filter(explode('/',$country)));$country=strtoupper((string)end($parts));}
        if($country!==''&&!preg_match('/^[A-Z0-9]{2,4}$/',$country))$country='';
        $before=$this->pdo->prepare('SELECT country_code FROM p2k_tp_opponents WHERE club_slug=? AND opponent_slug=? LIMIT 1');
        $before->execute([$clubSlug,$slug]);$oldCountry=strtoupper(trim((string)($before->fetchColumn()?:'')));
        $checked=$markChecked?1:0;
        $q=$this->pdo->prepare("UPDATE p2k_tp_opponents SET
          icon_url=CASE WHEN ?=1 THEN NULLIF(?,'') WHEN ?<>'' THEN ? ELSE icon_url END,
          display_name=CASE WHEN ?<>'' THEN ? ELSE display_name END,
          club_url=CASE WHEN ?<>'' THEN ? ELSE club_url END,
          country_code=CASE WHEN ?<>'' THEN ? ELSE country_code END,
          icon_checked_at=CASE WHEN ?=1 THEN UTC_TIMESTAMP() ELSE icon_checked_at END,
          profile_updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND opponent_slug=?");
        $q->execute([$checked,$icon,$icon,$icon,$name,$name,$url,$url,$country,$country,$checked,$clubSlug,$slug]);
        if($country!==''&&$country!==$oldCountry)$this->touchCoreGeneration($clubSlug);
        return $q->rowCount()>0;
    }

    public function opponentProfileSnapshot(string $clubSlug,string $opponentSlug): ?array
    {
        $q=$this->pdo->prepare('SELECT opponent_slug,display_name,club_url,country_code,icon_url,icon_checked_at,profile_updated_at,disabled FROM p2k_tp_opponents WHERE club_slug=? AND opponent_slug=? LIMIT 1');
        $q->execute([strtolower(trim($clubSlug)),strtolower(trim($opponentSlug))]);$r=$q->fetch(PDO::FETCH_ASSOC);return is_array($r)?$r:null;
    }

    /** @param string[] $slugs @return array<string,array<string,mixed>>
     *  Return snapshots keyed by the caller's requested slug while transparently
     *  following any previously repaired legacy alias to its canonical opponent.
     */
    public function opponentProfileSnapshots(string $clubSlug,array $slugs): array
    {
        $keys=array_values(array_unique(array_filter(array_map(static fn($v)=>strtolower(trim((string)$v)),$slugs))));
        if($keys===[])return [];if(count($keys)>25)$keys=array_slice($keys,0,25);
        $club=strtolower(trim($clubSlug));$marks=implode(',',array_fill(0,count($keys),'?'));
        $aliases=[];$aq=$this->pdo->prepare('SELECT alias_slug,canonical_slug FROM p2k_tp_opponent_aliases WHERE club_slug=? AND alias_slug IN ('.$marks.')');
        $aq->execute(array_merge([$club],$keys));foreach($aq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$alias=(string)$r['alias_slug'];$canonical=(string)$r['canonical_slug'];if($alias!==''&&$canonical!=='')$aliases[$alias]=$canonical;}
        $lookup=array_values(array_unique(array_map(static fn(string $k):string=>$aliases[$k]??$k,$keys)));
        $q=$this->pdo->prepare('SELECT opponent_slug,display_name,club_url,country_code,icon_url,icon_checked_at,profile_updated_at,disabled FROM p2k_tp_opponents WHERE club_slug=? AND opponent_slug IN ('.implode(',',array_fill(0,count($lookup),'?')).')');
        $q->execute(array_merge([$club],$lookup));$byCanonical=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$byCanonical[(string)$r['opponent_slug']]=$r;
        $out=[];foreach($keys as $requested){$canonical=$aliases[$requested]??$requested;if(isset($byCanonical[$canonical]))$out[$requested]=$byCanonical[$canonical];}return $out;
    }

    /** Return a small ordered set of opponent profiles whose country/profile metadata is missing or stale. */
    public function opponentProfileRefreshCandidates(string $clubSlug,int $limit=5): array
    {
        $clubSlug=strtolower(trim($clubSlug));$limit=max(1,min(25,$limit));
        $q=$this->pdo->prepare("SELECT o.opponent_slug,o.display_name,o.country_code,o.icon_checked_at,o.profile_updated_at,COUNT(m.match_id) match_count
            FROM p2k_tp_opponents o LEFT JOIN p2k_tp_match_metadata m ON m.club_slug=o.club_slug AND m.opponent_slug=o.opponent_slug
            WHERE o.club_slug=? AND o.disabled=0 AND (((COALESCE(o.country_code,'')='' AND (o.profile_updated_at IS NULL OR o.profile_updated_at<UTC_TIMESTAMP()-INTERVAL 90 DAY))) OR o.icon_checked_at IS NULL OR o.icon_checked_at<UTC_TIMESTAMP()-INTERVAL 90 DAY)
            GROUP BY o.opponent_slug,o.display_name,o.country_code,o.icon_checked_at,o.profile_updated_at
            ORDER BY (COALESCE(o.country_code,'')='') DESC,COALESCE(o.profile_updated_at,o.icon_checked_at) IS NULL DESC,COALESCE(o.profile_updated_at,o.icon_checked_at) ASC,match_count DESC,o.opponent_slug LIMIT {$limit}");
        $q->execute([$clubSlug]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    /** Apply a validated raw Chess.com club-members payload directly. */
    public function applyMembersObservation(string $clubSlug,array $payload): array
    {
        $clubSlug=strtolower(trim($clubSlug));
        $members=[];
        foreach(['weekly','monthly','all_time'] as $bucket){
            foreach(is_array($payload[$bucket]??null)?$payload[$bucket]:[] as $entry){
                $joined=0;
                if(is_string($entry))$username=$entry;
                elseif(is_array($entry)){ $username=(string)($entry['username']??$entry['name']??''); $joined=(int)($entry['joined']??0); }
                else $username='';
                $username=trim($username);
                if($username!==''&&preg_match('/^[A-Za-z0-9_-]{1,80}$/',$username))$members[strtolower($username)]=['username'=>$username,'joined'=>$joined];
            }
        }
        if($members===[])return ['updated'=>0,'member_count'=>0,'valid'=>false,'changed'=>false];
        ksort($members,SORT_STRING);
        $this->pdo->beginTransaction();
        try{
            $current=$this->pdo->prepare('SELECT username_key FROM p2k_tp_members WHERE club_slug=? AND current_member=1 ORDER BY username_key');
            $current->execute([$clubSlug]);$before=array_map('strval',$current->fetchAll(PDO::FETCH_COLUMN)?:[]);
            $this->resetCurrentMembers($clubSlug);
            foreach($members as $member)$this->upsertMember($clubSlug,(string)$member['username'],(int)$member['joined']);
            $this->markMembersObserved($clubSlug,count($members),true);
            $after=array_keys($members);
            $changed=$before!==$after;
            if($changed)$this->touchCoreGeneration($clubSlug);
            $this->pdo->commit();
        }catch(\Throwable $e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        return ['updated'=>count($members),'member_count'=>count($members),'valid'=>true,'changed'=>$changed];
    }

    public function queueCronFreshness(string $clubSlug,string $jobId,string $lane): array
    {
        $lane=in_array($lane,['club','player'],true)?$lane:'club';$now=time();$state=$this->state($clubSlug);$deadlineSeconds=3600;
        // Promote before the hard one-hour boundary by one external-CRON cadence so
        // queue jitter cannot turn a 60-minute freshness contract into 65-70 minutes.
        $deadlineLeadSeconds=$lane==='club'?300:600;
        if($lane==='club'){
            $verified=!empty($state['club_index_last_verified_at'])?(strtotime((string)$state['club_index_last_verified_at'].' UTC')?:0):0;
            $age=$verified>0?max(0,$now-$verified):PHP_INT_MAX;
            $deadlinePriority=$verified<=0||$age>=($deadlineSeconds-$deadlineLeadSeconds);
            $hardOverdue=$verified<=0||$age>=$deadlineSeconds;
            $bucket=$deadlinePriority?$verified:($now-($now%300));
            $key=$deadlinePriority?'freshness-deadline:club-index:'.($verified>0?gmdate('YmdHis',$verified):'bootstrap'):'priority-discovery:cron-club-index:'.gmdate('YmdHi',$bucket);
            $queued=$this->enqueue($jobId,'sync_club_matches',$key,['club_slug'=>$clubSlug,'lane'=>'club','priority_discovery'=>true,'freshness_guard'=>true,'freshness_deadline'=>$deadlinePriority,'freshness_hard_overdue'=>$hardOverdue,'verified_at'=>$state['club_index_last_verified_at']??null,'bucket_epoch'=>$bucket]);
            $opponentQueued=false;$opponentSlug=null;
            if(!$deadlinePriority)foreach($this->opponentProfileRefreshCandidates($clubSlug,5) as $candidate){$slug=strtolower(trim((string)($candidate['opponent_slug']??'')));if($slug==='')continue;$opponentKey='opponent-profile:'.$slug.':'.gmdate('YmdHi',$bucket);if($this->enqueue($jobId,'sync_opponent_profile',$opponentKey,['opponent_slug'=>$slug,'freshness_guard'=>true,'bucket_epoch'=>$bucket])){$opponentQueued=true;$opponentSlug=$slug;break;}}
            return ['club_index_queued'=>$queued,'club_index_deadline'=>$deadlinePriority,'club_index_hard_overdue'=>$hardOverdue,'club_index_age_seconds'=>$verified>0?$age:null,'club_index_last_verified_at'=>$state['club_index_last_verified_at']??null,'opponent_profile_queued'=>$opponentQueued,'opponent_slug'=>$opponentSlug,'roster_queued'=>false,'bucket_epoch'=>$bucket];
        }
        $verified=!empty($state['members_last_verified_at'])?(strtotime((string)$state['members_last_verified_at'].' UTC')?:0):0;
        $age=$verified>0?max(0,$now-$verified):PHP_INT_MAX;
        $deadlinePriority=$verified<=0||$age>=($deadlineSeconds-$deadlineLeadSeconds);$hardOverdue=$verified<=0||$age>=$deadlineSeconds;
        $bucket=$deadlinePriority?$verified:($now-($now%1800));
        $key=$deadlinePriority?'freshness-deadline:roster:'.($verified>0?gmdate('YmdHis',$verified):'bootstrap'):'priority-discovery:cron-roster:'.gmdate('YmdHi',$bucket);
        $queued=$this->enqueue($jobId,'sync_roster',$key,['club_slug'=>$clubSlug,'lane'=>'player','priority_discovery'=>true,'freshness_guard'=>true,'freshness_deadline'=>$deadlinePriority,'freshness_hard_overdue'=>$hardOverdue,'verified_at'=>$state['members_last_verified_at']??null,'bucket_epoch'=>$bucket]);
        return ['club_index_queued'=>false,'roster_queued'=>$queued,'roster_deadline'=>$deadlinePriority,'roster_hard_overdue'=>$hardOverdue,'roster_age_seconds'=>$verified>0?$age:null,'members_last_verified_at'=>$state['members_last_verified_at']??null,'bucket_epoch'=>$bucket];
    }


    /** Current-member recruitment pool. Claim-backed observed ratings may be used only when explicitly enabled. */
    public function recruitmentRatingPool(string $clubSlug, string $rules): array
    {
        $clubSlug=strtolower(trim($clubSlug));
        $normalizedRules=str_contains(strtolower($rules),'960')?'chess960':'chess';
        $verifiedColumn=$normalizedRules==='chess960'?'chess960_rating':'daily_rating';
        $observedColumn=$normalizedRules==='chess960'?'observed_chess960_rating':'observed_daily_rating';
        $app=\p2k_tp_config()['app']??[];$allowObserved=!array_key_exists('allow_claimed_observed_ratings_for_recruitment',$app)||!empty($app['allow_claimed_observed_ratings_for_recruitment']);
        $observedMaxAge=max(3600,(int)($app['observed_rating_recruitment_max_age_seconds']??259200));
        $q=$this->pdo->prepare(
            "SELECT username_key,username,{$verifiedColumn} verified_rating,{$observedColumn} observed_rating,rating_updated_at,stats_observed_at,observed_rating_source,last_seen_at
             FROM p2k_tp_members WHERE club_slug=? AND current_member=1 ORDER BY username_key"
        );
        $q->execute([$clubSlug]);
        $rows=[];$rated=0;$unrated=0;$oldest=null;$newest=null;$now=time();
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){
            $verified=$row['verified_rating']===null?null:(int)$row['verified_rating'];$observed=$row['observed_rating']===null?null:(int)$row['observed_rating'];
            $observedAt=is_string($row['stats_observed_at']??null)?(strtotime((string)$row['stats_observed_at'].' UTC')?:0):0;
            $verifiedAt=is_string($row['rating_updated_at']??null)?(strtotime((string)$row['rating_updated_at'].' UTC')?:0):0;
            $useObserved=$allowObserved&&$observed!==null&&$observedAt>0&&$now-$observedAt<=$observedMaxAge&&$observedAt>$verifiedAt;
            $rating=$useObserved?$observed:$verified;$source=$useObserved?'browser_observed':'server_verified';
            if($rating===null)$unrated++;else$rated++;
            $updated=$useObserved?($row['stats_observed_at']??null):($row['rating_updated_at']??null);
            if(is_string($updated)&&$updated!==''){if($oldest===null||$updated<$oldest)$oldest=$updated;if($newest===null||$updated>$newest)$newest=$updated;}
            $rows[]=['username_key'=>(string)$row['username_key'],'username'=>(string)$row['username'],'rating'=>$rating,'rating_updated_at'=>$updated,'rating_source'=>$source,'rating_verified'=>!$useObserved,'observed_rating_source'=>$row['observed_rating_source']??null,'last_seen_at'=>$row['last_seen_at']??null];
        }
        return ['rules'=>$normalizedRules,'rating_field'=>$verifiedColumn,'rows'=>$rows,'summary'=>['members'=>count($rows),'rated'=>$rated,'unrated'=>$unrated,'oldest_rating_at'=>$oldest,'newest_rating_at'=>$newest,'observed_ratings_enabled'=>$allowObserved]];
    }

    /** Keep Live/MCA current-member flags aligned with the authoritative Core roster. */
    public function reconcileLiveCurrentMembers(string $clubSlug): int
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$a=$this->tryAnalytics();if(!$a)return 0;
        $q=$this->pdo->prepare('SELECT username_key FROM p2k_tp_members WHERE club_slug=? AND current_member=1 ORDER BY username_key');$q->execute([$clubSlug]);$keys=array_map('strval',$q->fetchAll(PDO::FETCH_COLUMN)?:[]);
        $a->prepare("UPDATE p2k_lr_players SET current_member=0,account_state=CASE WHEN account_state='current_member' THEN 'former_member' ELSE account_state END,updated_at=updated_at WHERE club_slug=?")->execute([$clubSlug]);
        $updated=0;foreach(array_chunk($keys,250) as $chunk){$in=implode(',',array_fill(0,count($chunk),'?'));$u=$a->prepare("UPDATE p2k_lr_players SET current_member=1,account_state=CASE WHEN account_state IN ('closed_account','possible_renamed') THEN account_state ELSE 'current_member' END WHERE club_slug=? AND username_key IN ({$in})");$u->execute(array_merge([$clubSlug],$chunk));$updated+=$u->rowCount();}
        try{$u=$a->prepare("UPDATE p2k_lr_processing_state SET updated_at=UTC_TIMESTAMP() WHERE club_slug=?");$u->execute([$clubSlug]);}catch(\Throwable){}
        return $updated;
    }

    public function ensureSummaryIndexes(): bool { return false; }

    /** Compact v2.8.0 stores board state on the board row; no backfill table exists. */
    public function backfillBoardStatesBatch(string $clubSlug, int $limit = 250): int
    {
        return 0;
    }

    public function acquireCronChain(string $taskKey, string $chainId, int $leaseSeconds): bool
    {
        $leaseSeconds = max(120, $leaseSeconds);
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT chain_id,lease_until FROM p2k_tp_cron_state WHERE task_key=? FOR UPDATE');
            $select->execute([$taskKey]);
            $row = $select->fetch();
            $now = time();
            if (is_array($row)) {
                $leaseUntil = strtotime((string)$row['lease_until'] . ' UTC') ?: 0;
                if ((string)$row['chain_id'] !== $chainId && $leaseUntil > $now) {
                    $this->pdo->commit();
                    return false;
                }
                $update = $this->pdo->prepare(
                    "UPDATE p2k_tp_cron_state SET chain_id=?,lease_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),last_started_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE task_key=?"
                );
                $update->bindValue(1, $chainId);
                $update->bindValue(2, $leaseSeconds, PDO::PARAM_INT);
                $update->bindValue(3, $taskKey);
                $update->execute();
            } else {
                $insert = $this->pdo->prepare(
                    "INSERT INTO p2k_tp_cron_state(task_key,chain_id,lease_until,last_started_at,updated_at) VALUES(?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),UTC_TIMESTAMP(),UTC_TIMESTAMP())"
                );
                $insert->bindValue(1, $taskKey);
                $insert->bindValue(2, $chainId);
                $insert->bindValue(3, $leaseSeconds, PDO::PARAM_INT);
                $insert->execute();
            }
            $this->pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function finishCronInvocation(
        string $taskKey,
        string $chainId,
        int $nextDelaySeconds,
        string $status,
        string $message,
        int $leaseSeconds
    ): void {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_cron_state SET last_finished_at=UTC_TIMESTAMP(),next_run_at=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),last_status=?,last_message=?,lease_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),updated_at=UTC_TIMESTAMP() WHERE task_key=? AND chain_id=?"
        );
        $query->bindValue(1, max(1, $nextDelaySeconds), PDO::PARAM_INT);
        $query->bindValue(2, substr($status, 0, 30));
        $query->bindValue(3, substr($message, 0, 60000));
        $query->bindValue(4, max(120, $leaseSeconds), PDO::PARAM_INT);
        $query->bindValue(5, $taskKey);
        $query->bindValue(6, $chainId);
        $query->execute();
    }

    public function cronState(string $taskKey): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM p2k_tp_cron_state WHERE task_key=?');
        $query->execute([$taskKey]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function createOrGetActiveJob(string $clubSlug, string $lane = 'combined'): array
    {
        $lane = in_array($lane, ['club','player','combined'], true) ? $lane : 'combined';
        $jobType = match ($lane) {
            'club' => 'club_points_sync',
            'player' => 'player_points_sync',
            default => 'incremental_sync',
        };
        $query = $this->pdo->prepare(
            "SELECT * FROM p2k_tp_jobs WHERE club_slug = ? AND job_type = ? AND status IN ('new','running','paused') ORDER BY created_at DESC LIMIT 1"
        );
        $query->execute([$clubSlug, $jobType]);
        $existing = $query->fetch();
        if (is_array($existing)) return $existing;

        $id = \p2k_tp_uuid();
        $now = \p2k_tp_utc_now()->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                "INSERT INTO p2k_tp_jobs(id, club_slug, job_type, status, created_at, updated_at) VALUES(?, ?, ?, 'running', ?, ?)"
            );
            $insert->execute([$id, $clubSlug, $jobType, $now, $now]);
            if ($lane === 'club') {
                $this->enqueue($id, 'sync_club_matches', 'club-cycle:' . gmdate('YmdHi'), [
                    'club_slug'=>$clubSlug,'lane'=>'club','priority_discovery'=>true,
                ]);
            } elseif ($lane === 'player') {
                $this->enqueue($id, 'sync_members', 'player-roster:' . gmdate('YmdHi'), [
                    'club_slug'=>$clubSlug,'lane'=>'player','reconcile_current_members'=>true,
                ]);
            } else {
                $this->enqueue($id, 'sync_club_matches', $clubSlug, ['club_slug'=>$clubSlug,'lane'=>'combined']);
                $this->enqueue($id, 'sync_members', $clubSlug, ['club_slug'=>$clubSlug,'lane'=>'combined']);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
        $this->log($id, null, 'info', 'job_created', $clubSlug, ucfirst($lane) . ' Team Points collection job created.', [
            'job_type'=>$jobType,'lane'=>$lane,'club_slug'=>$clubSlug,
        ]);
        return $this->job($id) ?? throw new \RuntimeException('Created job cannot be read.');
    }

    public function job(string $jobId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM p2k_tp_jobs WHERE id = ?');
        $query->execute([$jobId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function latestJob(string $clubSlug, ?string $lane = null): ?array
    {
        if ($lane !== null && in_array($lane, ['club','player','combined'], true)) {
            $jobType = match ($lane) {
                'club' => 'club_points_sync',
                'player' => 'player_points_sync',
                default => 'incremental_sync',
            };
            $query = $this->pdo->prepare('SELECT * FROM p2k_tp_jobs WHERE club_slug = ? AND job_type = ? ORDER BY created_at DESC LIMIT 1');
            $query->execute([$clubSlug,$jobType]);
        } else {
            $query = $this->pdo->prepare('SELECT * FROM p2k_tp_jobs WHERE club_slug = ? ORDER BY created_at DESC LIMIT 1');
            $query->execute([$clubSlug]);
        }
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function laneForJob(?array $job): string
    {
        return match ((string)($job['job_type'] ?? '')) {
            'club_points_sync' => 'club',
            'player_points_sync' => 'player',
            default => 'combined',
        };
    }

    public function pauseJob(string $jobId): void
    {
        $query = $this->pdo->prepare("UPDATE p2k_tp_jobs SET stop_requested = 1, updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('new','running')");
        $query->execute([$jobId]);
        $this->log($jobId, null, 'info', 'pause_requested', null, 'A safe pause was requested from the interface.');
    }

    public function resumeJob(string $jobId): void
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_jobs SET status = 'running', stop_requested = 0, started_at = COALESCE(started_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP(), finished_at = NULL, last_error = NULL WHERE id = ? AND status IN ('paused','failed')"
        );
        $query->execute([$jobId]);
        $this->log($jobId, null, 'info', 'job_resumed', null, 'The collection job was resumed.');
    }

    public function markJobRunning(string $jobId): void
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_jobs SET status = 'running', started_at = COALESCE(started_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP() WHERE id = ? AND status IN ('new','running')"
        );
        $query->execute([$jobId]);
    }

    public function markJobPaused(string $jobId): void
    {
        $query = $this->pdo->prepare("UPDATE p2k_tp_jobs SET status = 'paused', stop_requested = 0, updated_at = UTC_TIMESTAMP() WHERE id = ?");
        $query->execute([$jobId]);
    }

    public function markJobCompleted(string $jobId): void
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_jobs SET status = 'completed', stop_requested = 0, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?"
        );
        $query->execute([$jobId]);
    }

    public function markJobFailed(string $jobId, string $error): void
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_jobs SET status = 'failed', last_error = ?, finished_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?"
        );
        $query->execute([substr($error, 0, 60000), $jobId]);
    }

    private function queueScopeForJob(string $jobId): string
    {
        if (isset($this->queueJobScopes[$jobId])) return $this->queueJobScopes[$jobId];
        $q=$this->pdo->prepare('SELECT club_slug FROM p2k_tp_jobs WHERE id=? LIMIT 1');
        $q->execute([$jobId]);$scope=strtolower(trim((string)($q->fetchColumn()?:'')));
        if($scope==='')throw new \RuntimeException('Queue job has no club scope: '.$jobId);
        return $this->queueJobScopes[$jobId]=$scope;
    }

    /** Canonical identity describes the authoritative work, never the caller/batch that requested it. */
    private function queueWorkIdentity(string $type,string $key,array $payload): array
    {
        $username=\p2k_tp_username_key((string)($payload['username']??''));
        $canonical=match($type){
            'sync_club_matches'=>'club-match-index',
            'sync_roster','sync_members'=>'club-members',
            'sync_match'=>((int)($payload['match_id']??0)>0?'match:'.(int)$payload['match_id']:'match-key:'.hash('sha256',$key)),
            'sync_board'=>(trim((string)($payload['board_url']??''))!==''?'board:'.$username.':'.hash('sha256',strtolower(trim((string)$payload['board_url']))):'board-key:'.hash('sha256',$key)),
            'sync_player'=>'player-matches:'.$username,
            'sync_player_stats'=>'player-stats:'.$username,
            'sync_player_profile'=>'player-profile:'.$username,
            'sync_opponent_profile'=>'opponent-profile:'.strtolower(trim((string)($payload['opponent_slug']??$payload['club_slug']??$key))),
            'sync_player_archive'=>'player-archive:'.$username.':'.preg_replace('/[^0-9-]+/','',(string)($payload['month']??'')),
            'reconcile_members'=>'member-reconciliation',
            'discover_match_ids'=>'discover-match-ids:'.strtolower(trim((string)($payload['source_key']??$key))),
            default=>strtolower($type).':'.hash('sha256',$key),
        };
        if(strlen($canonical)>190)$canonical=substr($canonical,0,120).':'.hash('sha256',$canonical);
        $priority=match(true){
            !empty($payload['freshness_deadline'])||str_starts_with($key,'freshness-deadline:')=>-100,
            !empty($payload['priority_discovery'])||str_starts_with($key,'priority-discovery:')=>-50,
            !empty($payload['explicit_repair'])=>-20,
            in_array($type,['sync_club_matches','sync_roster','sync_members'],true)=>10,
            $type==='sync_match'=>20,$type==='sync_board'=>30,$type==='sync_opponent_profile'=>40,
            $type==='reconcile_members'=>50,$type==='sync_player_archive'=>60,
            in_array($type,['sync_player_stats','sync_player_profile'],true)=>70,
            $type==='sync_player'=>80,$type==='discover_match_ids'=>90,default=>100,
        };
        return [$canonical,$priority];
    }

    private function strongerQueueType(string $current,string $incoming,string $canonical): string
    {
        if($canonical==='club-members' && ($current==='sync_members'||$incoming==='sync_members'))return 'sync_members';
        return $current!==''?$current:$incoming;
    }

    private function mergeQueuePayloads(array $base,array $incoming): array
    {
        $out=$base;
        $minKeys=['lower','cursor','after_member_id'];$maxKeys=['upper','batch_size'];
        foreach($incoming as $k=>$v){
            if(in_array($k,$minKeys,true) && is_numeric($v) && isset($out[$k]) && is_numeric($out[$k])){$out[$k]=min((int)$out[$k],(int)$v);continue;}
            if(in_array($k,$maxKeys,true) && is_numeric($v) && isset($out[$k]) && is_numeric($out[$k])){$out[$k]=max((int)$out[$k],(int)$v);continue;}
            if(is_bool($v)){$out[$k]=((bool)($out[$k]??false))||$v;continue;}
            if(is_array($v) && is_array($out[$k]??null)){
                if(array_is_list($v)){$out[$k]=array_values(array_unique(array_merge($out[$k],$v),SORT_REGULAR));}
                else{$out[$k]=$this->mergeQueuePayloads($out[$k],$v);}
                continue;
            }
            if($v!==null && $v!=='')$out[$k]=$v;
        }
        $sources=[];
        foreach([$base['source']??null,$incoming['source']??null] as $source)if(is_string($source)&&trim($source)!=='')$sources[]=trim($source);
        foreach([$base['coalesced_sources']??[],$incoming['coalesced_sources']??[]] as $list)if(is_array($list))foreach($list as $source)if(is_string($source)&&trim($source)!=='')$sources[]=trim($source);
        if($sources!==[])$out['coalesced_sources']=array_values(array_unique($sources));
        return $out;
    }

    /** Merge one request into an already-active canonical item. Caller holds the transaction lock. */
    private function mergeActiveQueueItem(array $existing,string $incomingType,string $incomingKey,array $incomingPayload,int $incomingPriority): bool
    {
        $id=(int)$existing['id'];$canonical=(string)$existing['canonical_key'];$status=(string)$existing['status'];
        $priority=min((int)($existing['priority_rank']??100),$incomingPriority);
        $currentPayload=\p2k_tp_json_decode($existing['payload_json']??null);
        $changed=false;
        if($status==='running'){
            $requested=\p2k_tp_json_decode($existing['requested_payload_json']??null);
            $merged=$requested===[]?$incomingPayload:$this->mergeQueuePayloads($requested,$incomingPayload);
            $requestedType=$this->strongerQueueType((string)($existing['requested_item_type']??''),$incomingType,$canonical);
            $requestedGeneration=max((int)$existing['generation']+1,(int)($existing['requested_generation']??1));
            $q=$this->pdo->prepare("UPDATE p2k_tp_job_items SET requested_generation=?,requested_item_type=?,requested_item_key=?,requested_payload_json=?,priority_rank=?,coalesced_count=coalesced_count+1,last_requested_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?");
            $q->execute([$requestedGeneration,$requestedType,$incomingKey,json_encode($merged,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$priority,$id]);
            return true;
        }
        $merged=$this->mergeQueuePayloads($currentPayload,$incomingPayload);
        $stronger=$this->strongerQueueType((string)$existing['item_type'],$incomingType,$canonical);
        $promote=$incomingPriority<(int)($existing['priority_rank']??100);
        $changed=$merged!=$currentPayload||$stronger!==(string)$existing['item_type']||$promote;
        $q=$this->pdo->prepare("UPDATE p2k_tp_job_items SET item_type=?,payload_json=?,priority_rank=?,available_at=CASE WHEN ?=1 THEN LEAST(available_at,UTC_TIMESTAMP()) ELSE available_at END,coalesced_count=coalesced_count+1,last_requested_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=?");
        $q->execute([$stronger,json_encode($merged,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$priority,$promote?1:0,$id]);
        return $changed;
    }

    public function enqueue(string $jobId, string $type, string $key, array $payload): bool
    {
        $scope=$this->queueScopeForJob($jobId);[$canonical,$priority]=$this->queueWorkIdentity($type,$key,$payload);
        $ownsTransaction=!$this->pdo->inTransaction();
        for($attempt=0;$attempt<3;$attempt++){
            if($ownsTransaction)$this->pdo->beginTransaction();
            try{
                $select=$this->pdo->prepare("SELECT * FROM p2k_tp_job_items WHERE canonical_scope=? AND canonical_key=? AND status IN ('pending','running','retry') ORDER BY CASE status WHEN 'running' THEN 0 WHEN 'pending' THEN 1 ELSE 2 END,id LIMIT 1 FOR UPDATE");
                $select->execute([$scope,$canonical]);$existing=$select->fetch(PDO::FETCH_ASSOC);
                if(is_array($existing)){
                    $accepted=$this->mergeActiveQueueItem($existing,$type,$key,$payload,$priority);
                    if($ownsTransaction)$this->pdo->commit();return $accepted;
                }
                $insert=$this->pdo->prepare("INSERT INTO p2k_tp_job_items(job_id,item_type,item_key,canonical_scope,canonical_key,priority_rank,generation,requested_generation,payload_json,status,available_at,last_requested_at,updated_at) VALUES(?,?,?,?,?,?,1,1,?,'pending',UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
                $insert->execute([$jobId,$type,$key,$scope,$canonical,$priority,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
                $update=$this->pdo->prepare('UPDATE p2k_tp_jobs SET total_items=total_items+1,updated_at=UTC_TIMESTAMP() WHERE id=?');$update->execute([$jobId]);
                if($ownsTransaction)$this->pdo->commit();return true;
            }catch(\PDOException $e){
                if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();
                if($ownsTransaction&&$attempt<2&&in_array((string)$e->getCode(),['23000','23505','40001'],true))continue;
                throw $e;
            }catch(\Throwable $e){if($ownsTransaction&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
        }
        return false;
    }

    /** CTAR: coalesce a producer burst under one database transaction. */
    public function enqueueBatch(string $jobId,array $items): int
    {
        if($items===[])return 0;$accepted=0;$owns=!$this->pdo->inTransaction();if($owns)$this->pdo->beginTransaction();
        try{foreach($items as $item){if(!is_array($item))continue;$type=(string)($item['type']??'');$key=(string)($item['key']??'');$payload=is_array($item['payload']??null)?$item['payload']:[];if($type!==''&&$key!==''&&$this->enqueue($jobId,$type,$key,$payload))$accepted++;}if($owns)$this->pdo->commit();return $accepted;}
        catch(\Throwable $e){if($owns&&$this->pdo->inTransaction())$this->pdo->rollBack();throw $e;}
    }

    /**
     * One-time/repair compaction for pre-v2.9.13 outstanding queue rows.
     * Terminal history is never rewritten. Running duplicates already in flight
     * are allowed to finish; pending/retry duplicates are coalesced safely.
     */
    public function legacyActiveQueueCount(?string $clubSlug=null): int
    {
        $sql="SELECT COUNT(*) FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE i.status IN ('pending','running','retry') AND i.canonical_key=''";
        $params=[];
        if($clubSlug!==null&&trim($clubSlug)!==''){$sql.=' AND j.club_slug=?';$params[]=strtolower(trim($clubSlug));}
        $q=$this->pdo->prepare($sql);$q->execute($params);
        return (int)$q->fetchColumn();
    }

    public function compactOutstandingQueue(?string $clubSlug=null,int $limit=1000000): array
    {
        $legacyBefore=$this->legacyActiveQueueCount($clubSlug);
        if($legacyBefore===0)return ['examined'=>0,'canonical_survivors'=>0,'coalesced_pending_retry'=>0,'legacy_running_duplicates'=>0,'legacy_before'=>0];
        $limit=max(1,min(1000000,$limit));$keepers=[];$examined=0;$legacyRunningDuplicates=0;$duplicateIdsByGroup=[];
        $phases=[['running'],['pending','retry']];
        foreach($phases as $statuses){
            $cursor=0;
            while($examined<$limit){
                $remaining=$limit-$examined;$batch=min(2000,$remaining);
                $placeholders=implode(',',array_fill(0,count($statuses),'?'));
                $sql="SELECT i.*,j.club_slug FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE i.id>? AND i.status IN ({$placeholders})";
                $params=[$cursor,...$statuses];
                if($clubSlug!==null&&trim($clubSlug)!==''){$sql.=' AND j.club_slug=?';$params[]=strtolower(trim($clubSlug));}
                $sql.=" ORDER BY i.id LIMIT {$batch}";
                $q=$this->pdo->prepare($sql);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
                if($rows===[])break;
                foreach($rows as $row){
                    $cursor=max($cursor,(int)$row['id']);$examined++;
                    $payload=\p2k_tp_json_decode($row['payload_json']??null);[$canonical,$priority]=$this->queueWorkIdentity((string)$row['item_type'],(string)$row['item_key'],$payload);
                    $scope=strtolower(trim((string)$row['club_slug']));$group=$scope.'|'.$canonical;
                    if(!isset($keepers[$group])){
                        $keepers[$group]=[
                            'id'=>(int)$row['id'],'status'=>(string)$row['status'],'scope'=>$scope,'canonical'=>$canonical,
                            'type'=>(string)$row['item_type'],'key'=>(string)$row['item_key'],'payload'=>$payload,'priority'=>$priority,
                            'generation'=>(int)($row['generation']??1),'requested_generation'=>(int)($row['requested_generation']??1),
                            'requested_type'=>(string)($row['requested_item_type']??''),'requested_key'=>(string)($row['requested_item_key']??''),
                            'requested_payload'=>\p2k_tp_json_decode($row['requested_payload_json']??null),'coalesced_add'=>0,
                        ];
                        continue;
                    }
                    $keeper=&$keepers[$group];
                    if((string)$row['status']==='running'){$legacyRunningDuplicates++;unset($keeper);continue;}
                    $keeper['coalesced_add']++;
                    $duplicateIdsByGroup[$group][]=(int)$row['id'];
                    $keeper['priority']=min((int)$keeper['priority'],$priority);
                    if($keeper['status']==='running'){
                        $keeper['requested_payload']=$keeper['requested_payload']===[]?$payload:$this->mergeQueuePayloads($keeper['requested_payload'],$payload);
                        $keeper['requested_type']=$this->strongerQueueType($keeper['requested_type']!==''?$keeper['requested_type']:$keeper['type'],(string)$row['item_type'],$canonical);
                        $keeper['requested_key']=(string)$row['item_key'];
                        $keeper['requested_generation']=max((int)$keeper['requested_generation'],(int)$keeper['generation']+1);
                    }else{
                        $keeper['payload']=$this->mergeQueuePayloads($keeper['payload'],$payload);
                        $keeper['type']=$this->strongerQueueType($keeper['type'],(string)$row['item_type'],$canonical);
                    }
                    unset($keeper);
                }
                if(count($rows)<$batch)break;
            }
        }
        $compacted=0;$survivors=0;
        foreach($keepers as $group=>$keeper){
            $dups=$duplicateIdsByGroup[$group]??[];
            if($dups!==[]){
                foreach(array_chunk($dups,400) as $ids){
                    $marks=implode(',',array_fill(0,count($ids),'?'));
                    $q=$this->pdo->prepare("UPDATE p2k_tp_job_items SET status='skipped',locked_at=NULL,updated_at=UTC_TIMESTAMP(),last_error=CONCAT(COALESCE(last_error,''),'\\nCoalesced by v2.9.13 canonical queue compaction.') WHERE id IN ({$marks}) AND status IN ('pending','retry')");
                    $q->execute($ids);$compacted+=$q->rowCount();
                }
            }
            if($keeper['status']==='running'){
                $requestedJson=$keeper['requested_payload']!==[]?json_encode($keeper['requested_payload'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR):null;
                $q=$this->pdo->prepare("UPDATE p2k_tp_job_items SET canonical_scope=?,canonical_key=?,priority_rank=?,requested_generation=?,requested_item_type=?,requested_item_key=?,requested_payload_json=?,coalesced_count=coalesced_count+?,last_requested_at=COALESCE(last_requested_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=?");
                $q->execute([$keeper['scope'],$keeper['canonical'],$keeper['priority'],$keeper['requested_generation'],$keeper['requested_type']!==''?$keeper['requested_type']:null,$keeper['requested_key']!==''?$keeper['requested_key']:null,$requestedJson,$keeper['coalesced_add'],$keeper['id']]);
            }else{
                $q=$this->pdo->prepare("UPDATE p2k_tp_job_items SET item_type=?,canonical_scope=?,canonical_key=?,priority_rank=?,payload_json=?,coalesced_count=coalesced_count+?,last_requested_at=COALESCE(last_requested_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP() WHERE id=? AND status IN ('pending','retry')");
                $q->execute([$keeper['type'],$keeper['scope'],$keeper['canonical'],$keeper['priority'],json_encode($keeper['payload'],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),$keeper['coalesced_add'],$keeper['id']]);
            }
            $survivors++;
        }
        return ['examined'=>$examined,'canonical_survivors'=>$survivors,'coalesced_pending_retry'=>$compacted,'legacy_running_duplicates'=>$legacyRunningDuplicates,'legacy_before'=>$legacyBefore];
    }

    /**
     * Queue a fresh member-roster and member-match discovery batch ahead of
     * ordinary pending/retry work. Existing queue rows and payloads remain valid;
     * priority and provenance are merged into the canonical outstanding item.
     */
    public function queuePriorityDiscovery(string $clubSlug): array
    {
        $clubJob=$this->createOrGetActiveJob($clubSlug,'club');
        $playerJob=$this->createOrGetActiveJob($clubSlug,'player');
        $batchId=str_replace('-','',\p2k_tp_uuid());
        $payload=['club_slug'=>$clubSlug,'priority_discovery'=>true,'discovery_batch_id'=>$batchId,'requested_at'=>\p2k_tp_utc_now()->format(DATE_ATOM)];
        $matchQueued=$this->enqueue((string)$clubJob['id'],'sync_club_matches','priority-discovery:' . $batchId . ':club-matches',$payload+['lane'=>'club']);
        $membersQueued=$this->enqueue((string)$playerJob['id'],'sync_members','priority-discovery:' . $batchId . ':members',$payload+['lane'=>'player','reconcile_current_members'=>true]);
        $this->log((string)$clubJob['id'],null,'info','priority_discovery_queued',$batchId,'High-priority club match discovery queued.',['club_matches_queued'=>$matchQueued]);
        $this->log((string)$playerJob['id'],null,'info','priority_discovery_queued',$batchId,'Player roster/reconciliation refresh queued independently.',['members_queued'=>$membersQueued]);
        return [
            'queued'=>$matchQueued||$membersQueued,
            'club_job'=>$this->job((string)$clubJob['id']),
            'player_job'=>$this->job((string)$playerJob['id']),
            'club_matches_queued'=>$matchQueued,
            'members_queued'=>$membersQueued,
            'discovery_batch_id'=>$batchId,
        ];
    }

    /** Browser observations are hints only: decide whether an authoritative match refresh is useful without writing the hinted status. */
    public function matchDetailDueFromObservation(string $clubSlug, int $matchId, string $bucket): bool
    {
        if ($matchId <= 0) return false;
        $q=$this->pdo->prepare('SELECT status,last_verified_at FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');
        $q->execute([$clubSlug,$matchId]); $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return true;
        $bucket=strtolower(trim($bucket));
        if ($bucket==='finished') {
            $summary=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_tp_match_summaries WHERE club_slug=? AND match_id=?');
            $summary->execute([$clubSlug,$matchId]); if((int)$summary->fetchColumn()===0)return true;
            $maxAge=2592000;
        } elseif ($bucket==='in_progress') $maxAge=43200;
        else $maxAge=21600;
        $verified=!empty($row['last_verified_at'])?(strtotime((string)$row['last_verified_at'].' UTC')?:0):0;
        return $verified<=0 || $verified < time()-$maxAge;
    }

    public function isCurrentMember(string $clubSlug,string $username): bool
    {
        $q=$this->pdo->prepare('SELECT current_member FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
        $q->execute([$clubSlug,\p2k_tp_username_key($username)]); return (int)($q->fetchColumn()?:0)===1;
    }

    public function isKnownMatch(string $clubSlug,int $matchId): bool
    {
        if($matchId<=0)return false;
        $q=$this->pdo->prepare('SELECT 1 FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');
        $q->execute([$clubSlug,$matchId]);return (bool)$q->fetchColumn();
    }

    /** Record a passive club-index reference without promoting browser status to canonical status. */
    public function recordObservedClubMatchReference(string $clubSlug,int $matchId,string $bucket,array $entry=[]): bool
    {
        if($matchId<=0)return false;$clubSlug=strtolower(trim($clubSlug));
        $observedStatus=match(strtolower(trim($bucket))){'registered','registration'=>'registered','in_progress','ongoing'=>'in_progress','finished','complete','completed'=>'finished',default=>'unknown'};
        $due=$this->matchDetailDueFromObservation($clubSlug,$matchId,$observedStatus);$known=$this->isKnownMatch($clubSlug,$matchId);
        $name=trim((string)($entry['name']??('Match '.$matchId)));$url=trim((string)($entry['@id']??$entry['url']??('https://api.chess.com/pub/match/'.$matchId)));
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_match_metadata(club_slug,match_id,match_name,match_url,status,observed_status,board_count,p2k_score,opponent_score,discovery_source,last_verified_at,last_observed_at,last_index_seen_at,next_detail_check_at,first_discovered_at) VALUES(?,?,?,?,'unknown',?,0,0,0,'browser_observation',NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE observed_status=VALUES(observed_status),last_observed_at=UTC_TIMESTAMP(),last_index_seen_at=UTC_TIMESTAMP(),match_name=IF(match_name='',VALUES(match_name),match_name),match_url=COALESCE(match_url,VALUES(match_url))");
        $q->execute([$clubSlug,$matchId,$name,$url,$observedStatus]);if(!$known)$this->touchCoreGeneration($clubSlug);return $due;
    }

    /**
     * MLP Fix: browser/ACAMR observations remain non-canonical, but a visible
     * lifecycle disagreement must trigger the authoritative club-index path.
     * The authoritative worker is still the only writer that promotes
     * registered/in_progress/finished into canonical status.
     */
    public function observedClubLifecycleAuditDue(string $clubSlug): bool
    {
        $q=$this->pdo->prepare("SELECT 1 FROM p2k_tp_match_metadata WHERE club_slug=? AND observed_status IN ('registered','in_progress','finished') AND COALESCE(status,'unknown')<>observed_status LIMIT 1");
        $q->execute([strtolower(trim($clubSlug))]);
        return (bool)$q->fetchColumn();
    }

    /** Record a passive detail observation for a known/discovered match. */
    public function markMatchPassiveObserved(string $clubSlug,int $matchId): bool
    {
        $q=$this->pdo->prepare('UPDATE p2k_tp_match_metadata SET last_observed_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=?');
        $q->execute([strtolower(trim($clubSlug)),$matchId]);return $q->rowCount()>0;
    }

    /** CTAR: authoritative club-index projection with one metadata + one summary read for the whole index. */
    public function observeClubMatchIndexBatch(string $clubSlug,array $entries): array
    {
        if($entries===[])return [];$ids=array_values(array_unique(array_map('intval',array_keys($entries))));$existing=[];$summaries=[];
        foreach(array_chunk($ids,500) as $chunk){$in=implode(',',array_fill(0,count($chunk),'?'));$q=$this->pdo->prepare("SELECT match_id,status,last_verified_at,next_detail_check_at FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id IN ($in)");$q->execute(array_merge([$clubSlug],$chunk));foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$existing[(int)$r['match_id']]=$r;$q=$this->pdo->prepare("SELECT match_id FROM p2k_tp_match_summaries WHERE club_slug=? AND match_id IN ($in)");$q->execute(array_merge([$clubSlug],$chunk));foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $id)$summaries[(int)$id]=true;}
        $upsert=$this->pdo->prepare("INSERT INTO p2k_tp_match_metadata(club_slug,match_id,match_name,match_url,status,board_count,p2k_score,opponent_score,discovery_source,last_verified_at,last_index_seen_at,next_detail_check_at,first_discovered_at) VALUES(?,?,?,?,?,0,0,0,'club_index',UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE status=VALUES(status),match_name=IF(match_name='',VALUES(match_name),match_name),match_url=COALESCE(match_url,VALUES(match_url)),last_index_seen_at=UTC_TIMESTAMP(),next_detail_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)");
        $due=[];$changedAny=false;$now=time();
        foreach($entries as $matchId=>$row){$matchId=(int)$matchId;$bucket=(string)($row['bucket']??'unknown');$entry=is_array($row['entry']??null)?$row['entry']:[];$status=match(strtolower(trim($bucket))){'registered','registration'=>'registered','in_progress','ongoing'=>'in_progress','finished','complete','completed'=>'finished',default=>'unknown'};$old=$existing[$matchId]??null;$changed=!is_array($old)||(string)($old['status']??'')!==$status;$isDue=$changed||($status==='finished'&&!isset($summaries[$matchId]));if(!$isDue&&is_array($old)){$next=!empty($old['next_detail_check_at'])?strtotime((string)$old['next_detail_check_at'].' UTC'):0;$isDue=$next<=$now;}$interval=$status==='registered'?21600:($status==='in_progress'?43200:2592000);$name=trim((string)($entry['name']??('Match '.$matchId)));$url=trim((string)($entry['@id']??$entry['url']??('https://api.chess.com/pub/match/'.$matchId)));$upsert->execute([$clubSlug,$matchId,$name,$url,$status,$interval,$interval]);if($changed)$changedAny=true;if($isDue)$due[$matchId]=true;}
        if($changedAny)$this->touchCoreGeneration($clubSlug);return $due;
    }

    /** Record one authoritative club-index observation and report whether its detail is due. */
    public function observeClubMatchIndex(string $clubSlug, int $matchId, string $bucket, array $entry = []): bool
    {
        $status = match (strtolower(trim($bucket))) {
            'registered', 'registration' => 'registered',
            'in_progress', 'ongoing' => 'in_progress',
            'finished', 'complete', 'completed' => 'finished',
            default => 'unknown',
        };
        $existing = $this->pdo->prepare('SELECT status,last_verified_at,next_detail_check_at FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=?');
        $existing->execute([$clubSlug,$matchId]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);
        $summary = $this->pdo->prepare('SELECT COUNT(*) FROM p2k_tp_match_summaries WHERE club_slug=? AND match_id=?');
        $summary->execute([$clubSlug,$matchId]);
        $hasSummary = (int)$summary->fetchColumn() > 0;
        $changed = !is_array($row) || (string)$row['status'] !== $status;
        $due = $changed || ($status === 'finished' && !$hasSummary);
        if (!$due && is_array($row)) {
            $next = !empty($row['next_detail_check_at']) ? strtotime((string)$row['next_detail_check_at'] . ' UTC') : 0;
            $due = $next <= time();
        }
        $interval = $status === 'registered' ? 21600 : ($status === 'in_progress' ? 43200 : 2592000);
        $name = trim((string)($entry['name'] ?? ('Match ' . $matchId)));
        $url = trim((string)($entry['@id'] ?? $entry['url'] ?? ('https://api.chess.com/pub/match/' . $matchId)));
        $query = $this->pdo->prepare(
            "INSERT INTO p2k_tp_match_metadata(club_slug,match_id,match_name,match_url,status,board_count,p2k_score,opponent_score,discovery_source,last_verified_at,last_index_seen_at,next_detail_check_at,first_discovered_at)
             VALUES(?,?,?,?,?,0,0,0,'club_index',UTC_TIMESTAMP(),UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status=VALUES(status),match_name=IF(match_name='',VALUES(match_name),match_name),match_url=COALESCE(match_url,VALUES(match_url)),last_index_seen_at=UTC_TIMESTAMP(),next_detail_check_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)"
        );
        $query->execute([$clubSlug,$matchId,$name,$url,$status,$interval,$interval]);
        if($changed)$this->touchCoreGeneration($clubSlug);
        return $due;
    }

    public function participationForMatch(string $clubSlug, string $usernameKey, int $matchId): ?array
    {
        $query = $this->pdo->prepare('SELECT * FROM p2k_tp_participations WHERE club_slug=? AND username_key=? AND match_id=? LIMIT 1');
        $query->execute([$clubSlug,$usernameKey,$matchId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** Reclassify a board after archive-derived events were written. */
    public function refreshBoardStateFromEvents(string $clubSlug, string $username, int $matchId, string $boardUrl, string $sourceBucket): int
    {
        $count = min(2,$this->pointEventCount($clubSlug,\p2k_tp_username_key($username),$boardUrl));
        $state = $count >= 2 ? 'complete_immutable' : ($count === 1 ? 'potentially_incomplete' : 'recent_in_progress');
        $this->markBoardChecked($clubSlug,$username,$matchId,$boardUrl,$sourceBucket,$state,$count,$count >= 2 ? null : 21600);
        return $count;
    }

    public function queueFullMemberHistoryRepair(string $clubSlug): array
    {
        $job = $this->createOrGetActiveJob($clubSlug,'player');
        $jobId = (string)$job['id'];
        $batch = str_replace('-','',\p2k_tp_uuid());
        $count = 0;
        foreach ($this->currentMembers($clubSlug) as $member) {
            $key = 'repair-member-history:' . $batch . ':' . (string)$member['username_key'];
            if ($this->enqueue($jobId,'sync_player',$key,['username'=>(string)$member['username'],'explicit_repair'=>true])) $count++;
        }
        $this->log($jobId,null,'warning','full_member_history_repair_queued',$batch,"Explicit full member-history repair queued for {$count} current member(s).",['queued'=>$count]);
        return ['job'=>$this->job($jobId),'queued'=>$count,'batch_id'=>$batch];
    }

    public function queueRawHistoryRepair(string $clubSlug, int $lower, int $upper): array
    {
        if ($lower <= 0 || $upper < $lower) throw new \InvalidArgumentException('Invalid raw match-ID repair range.');
        $job = $this->createOrGetActiveJob($clubSlug,'club');
        $jobId = (string)$job['id'];
        $source = 'manual_raw_' . $lower . '_' . $upper;
        $this->saveDiscoveryState($clubSlug,$source,$lower,$lower,$upper,null,0,0);
        $queued = $this->enqueue($jobId,'discover_match_ids',$source . ':' . $lower . ':' . $upper,['source_key'=>$source,'cursor'=>$lower,'lower'=>$lower,'upper'=>$upper,'explicit_repair'=>true]);
        $this->log($jobId,null,'warning','raw_history_repair_queued',$source,'Explicit raw match-ID repair queued.',['lower'=>$lower,'upper'=>$upper,'queued'=>$queued]);
        return ['job'=>$this->job($jobId),'queued'=>$queued,'source_key'=>$source,'lower'=>$lower,'upper'=>$upper];
    }

    public function latestSeedRun(string $clubSlug): ?array
    {
        try {
            $q=$this->pdo->query('SELECT * FROM p2k_core_initialization ORDER BY initialized_at DESC LIMIT 1');
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return null;
            return [
                'run_id'=>(string)$row['initialization_id'],'club_slug'=>$clubSlug,'status'=>'applied',
                'snapshot_epoch'=>(int)$row['snapshot_epoch'],'expected_members'=>(int)$row['members'],
                'expected_matches'=>(int)$row['matches'],'expected_boards'=>(int)$row['boards'],
                'expected_events'=>(int)$row['games'],'expected_club_points'=>(int)$row['club_points'],
                'created_at'=>$row['initialized_at'],'updated_at'=>$row['initialized_at'],'applied_at'=>$row['initialized_at'],
                'message'=>'v2.8.0 fresh Core/Analytics initialization',
            ];
        } catch (\Throwable) { return null; }
    }

    public function recoverStaleItems(string $jobId, int $seconds): int
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_job_items SET status = CASE WHEN item_key LIKE 'freshness-deadline:%' THEN 'retry' WHEN attempts >= 5 THEN 'failed' ELSE 'retry' END, available_at = UTC_TIMESTAMP(), locked_at = NULL, updated_at = UTC_TIMESTAMP(), last_error = CONCAT(COALESCE(last_error,''), '\nRecovered stale running item.')
             WHERE job_id = ? AND status = 'running' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)"
        );
        $query->bindValue(1, $jobId);
        $query->bindValue(2, $seconds, PDO::PARAM_INT);
        $query->execute();
        return $query->rowCount();
    }

    public function claimNextItem(string $jobId, ?array $itemTypes = null): ?array
    {
        $types = array_values(array_unique(array_filter(array_map(static fn($v): string => trim((string)$v), $itemTypes ?? []), static fn(string $v): bool => $v !== '')));
        $whereTypes = '';
        $params = [$jobId];
        if ($types !== []) {
            $whereTypes = ' AND item_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...$types);
        }
        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                "SELECT * FROM p2k_tp_job_items
                 WHERE job_id = ? AND status IN ('pending','retry') AND available_at <= UTC_TIMESTAMP(){$whereTypes}
                 ORDER BY priority_rank, CASE WHEN item_key LIKE 'freshness-deadline:%' THEN -1 WHEN item_key LIKE 'priority-discovery:%' THEN 0 WHEN item_type IN ('sync_club_matches','sync_roster','sync_members') THEN 1 WHEN item_key LIKE 'history-revalidate-v290:%' THEN 8 WHEN item_type='sync_match' THEN 2 WHEN item_type='sync_board' THEN 3 WHEN item_type='sync_opponent_profile' THEN 4 WHEN item_type='reconcile_members' THEN 5 WHEN item_type='sync_player_archive' THEN 6 WHEN item_type IN ('sync_player_stats','sync_player_profile') THEN 7 WHEN item_type='sync_player' THEN 8 ELSE 9 END, CASE WHEN status='pending' THEN 0 ELSE 1 END, available_at, id LIMIT 1 FOR UPDATE"
            );
            $select->execute($params);
            $item = $select->fetch();
            if (!is_array($item)) {
                $this->pdo->commit();
                return null;
            }
            $update = $this->pdo->prepare(
                "UPDATE p2k_tp_job_items SET status = 'running', attempts = attempts + 1, locked_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?"
            );
            $update->execute([$item['id']]);
            $this->pdo->commit();
            $item['attempts'] = (int)$item['attempts'] + 1;
            $item['status'] = 'running';
            return $item;
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function finishItem(int $itemId, string $jobId, string $status = 'done', ?string $error = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $read=$this->pdo->prepare('SELECT * FROM p2k_tp_job_items WHERE id=? FOR UPDATE');$read->execute([$itemId]);$item=$read->fetch(PDO::FETCH_ASSOC);
            if(!is_array($item)){ $this->pdo->commit(); return; }
            $generation=(int)($item['generation']??1);$requested=(int)($item['requested_generation']??$generation);
            if($requested>$generation && trim((string)($item['requested_payload_json']??''))!==''){
                $nextType=trim((string)($item['requested_item_type']??'')) ?: (string)$item['item_type'];
                $nextKey=trim((string)($item['requested_item_key']??'')) ?: (string)$item['item_key'];
                $q=$this->pdo->prepare("UPDATE p2k_tp_job_items SET item_type=?,item_key=?,payload_json=requested_payload_json,generation=?,requested_generation=?,requested_item_type=NULL,requested_item_key=NULL,requested_payload_json=NULL,status='pending',attempts=0,available_at=UTC_TIMESTAMP(),locked_at=NULL,updated_at=UTC_TIMESTAMP(),last_error=? WHERE id=?");
                $q->execute([$nextType,$nextKey,$requested,$requested,$error,$itemId]);
            }else{
                $query=$this->pdo->prepare('UPDATE p2k_tp_job_items SET status=?,locked_at=NULL,updated_at=UTC_TIMESTAMP(),last_error=? WHERE id=?');
                $query->execute([$status,$error,$itemId]);
            }
            $job=$this->pdo->prepare('UPDATE p2k_tp_jobs SET processed_items=processed_items+1,updated_at=UTC_TIMESTAMP() WHERE id=?');$job->execute([$jobId]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function retryItem(int $itemId, int $delaySeconds, string $error): void
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_job_items SET status = 'retry', available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), locked_at = NULL, updated_at = UTC_TIMESTAMP(), last_error = ? WHERE id = ?"
        );
        $query->bindValue(1, max(1, $delaySeconds), PDO::PARAM_INT);
        $query->bindValue(2, substr($error, 0, 60000));
        $query->bindValue(3, $itemId, PDO::PARAM_INT);
        $query->execute();
    }

    public function queueCounts(string $jobId): array
    {
        $query = $this->pdo->prepare('SELECT status, COUNT(*) AS count FROM p2k_tp_job_items WHERE job_id = ? GROUP BY status');
        $query->execute([$jobId]);
        $counts = ['pending' => 0, 'running' => 0, 'retry' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($query->fetchAll() as $row) {
            $counts[(string)$row['status']] = (int)$row['count'];
        }
        try {
            $meta=$this->pdo->prepare("SELECT COALESCE(SUM(coalesced_count),0) AS coalesced_requests,COALESCE(SUM(CASE WHEN status IN ('pending','running','retry') AND canonical_key<>'' THEN 1 ELSE 0 END),0) AS active_canonical,COALESCE(SUM(CASE WHEN status IN ('pending','running','retry') AND canonical_key='' THEN 1 ELSE 0 END),0) AS legacy_active_uncanonical FROM p2k_tp_job_items WHERE job_id=?");
            $meta->execute([$jobId]);$m=$meta->fetch(PDO::FETCH_ASSOC);
            $counts['coalesced_requests']=(int)($m['coalesced_requests']??0);$counts['active_canonical']=(int)($m['active_canonical']??0);$counts['legacy_active_uncanonical']=(int)($m['legacy_active_uncanonical']??0);
        } catch (\Throwable) { $counts['coalesced_requests']=0;$counts['active_canonical']=0;$counts['legacy_active_uncanonical']=0; }
        return $counts;
    }

    /**
     * Recover the v2.9.5 false-completion edge without rewriting historical queue rows.
     * A current member whose sync_player work is already marked done but whose
     * authoritative freshness is still NULL receives one new priority repair item.
     * Members with an outstanding sync_player continuation/work item are left alone.
     */
    public function enqueueMissingPlayerMatchFreshnessRepairs(string $jobId,string $clubSlug,int $limit=250): int
    {
        $limit=max(1,min(1000,$limit));
        $q=$this->pdo->prepare("SELECT DISTINCT m.member_id,m.username,m.username_key
            FROM p2k_tp_job_items d
            JOIN p2k_tp_members m
              ON m.club_slug=?
             AND m.username_key=LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,'$.username'))))
            WHERE d.job_id=?
              AND d.item_type='sync_player'
              AND d.status='done'
              AND m.current_member=1
              AND m.player_matches_checked_at IS NULL
              AND NOT EXISTS (
                SELECT 1 FROM p2k_tp_job_items live
                WHERE live.job_id=d.job_id
                  AND live.item_type='sync_player'
                  AND live.status IN ('pending','retry','running')
                  AND LOWER(TRIM(JSON_UNQUOTE(JSON_EXTRACT(live.payload_json,'$.username'))))=m.username_key
              )
            ORDER BY m.member_id
            LIMIT {$limit}");
        $q->execute([strtolower(trim($clubSlug)),$jobId]);
        $queued=0;
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $id=(int)($row['member_id']??0);$username=trim((string)($row['username']??''));$key=(string)($row['username_key']??'');
            if($id<=0||$username===''||$key==='')continue;
            if($this->enqueue($jobId,'sync_player','priority-discovery:v297-freshness-repair:' . $id . ':' . $key,[
                'username'=>$username,'reconciliation'=>true,'v297_false_completion_repair'=>true
            ]))$queued++;
        }
        return $queued;
    }

    public function taskBreakdown(string $jobId): array
    {
        $query = $this->pdo->prepare(
            "SELECT item_type, status, COUNT(*) AS item_count
             FROM p2k_tp_job_items WHERE job_id=? GROUP BY item_type,status ORDER BY item_type,status"
        );
        $query->execute([$jobId]);
        $result = [];
        foreach ($query->fetchAll() as $row) {
            $type = (string)$row['item_type'];
            if (!isset($result[$type])) {
                $result[$type] = ['item_type' => $type, 'pending' => 0, 'running' => 0, 'retry' => 0, 'done' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0];
            }
            $status = (string)$row['status'];
            $count = (int)$row['item_count'];
            $result[$type][$status] = $count;
            $result[$type]['total'] += $count;
        }
        return array_values($result);
    }

    public function taskActivity(string $jobId, array $itemTypes): array
    {
        $types=array_values(array_unique(array_filter(array_map(static fn($v):string=>trim((string)$v),$itemTypes),static fn(string $v):bool=>$v!=='')));
        if($types===[])return [];
        $placeholders=implode(',',array_fill(0,count($types),'?'));
        $params=array_merge([$jobId],$types);
        $q=$this->pdo->prepare("SELECT task_type,
            MAX(CASE WHEN level='info' AND task_type='task_started' THEN created_at END) generic_started_at,
            MAX(CASE WHEN level='info' AND message LIKE 'Starting %' THEN created_at END) started_at,
            MAX(CASE WHEN level='success' THEN created_at END) success_at
            FROM p2k_tp_job_logs WHERE job_id=? AND (task_type IN ({$placeholders}) OR (task_type='task_started' AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.item_type')) IN ({$placeholders})))
            GROUP BY task_type");
        $q->execute(array_merge($params,$types));
        $out=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            $type=(string)($row['task_type']??'');
            if($type==='task_started')continue;
            $out[$type]=['last_started_at'=>$row['started_at']??$row['generic_started_at']??null,'last_success_at'=>$row['success_at']??null];
        }
        // task_started logs store the actual item type in context_json. Resolve them in a second indexed-by-job query.
        $q=$this->pdo->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.item_type')) item_type,MAX(created_at) last_started_at FROM p2k_tp_job_logs WHERE job_id=? AND task_type='task_started' AND JSON_UNQUOTE(JSON_EXTRACT(context_json,'$.item_type')) IN ({$placeholders}) GROUP BY item_type");
        $q->execute(array_merge([$jobId],$types));
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){$type=(string)$row['item_type'];$out[$type]['last_started_at']=$row['last_started_at']??null;$out[$type]['last_success_at']=$out[$type]['last_success_at']??null;}
        foreach($types as $type)$out[$type]=$out[$type]??['last_started_at'=>null,'last_success_at'=>null];
        return $out;
    }

    public function currentItem(string $jobId): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT id,item_type,item_key,attempts,locked_at,updated_at
             FROM p2k_tp_job_items WHERE job_id=? AND status='running' ORDER BY locked_at DESC,id DESC LIMIT 1"
        );
        $query->execute([$jobId]);
        $row = $query->fetch();
        return is_array($row) ? $row : null;
    }

    public function nextRetryAt(string $jobId): ?string
    {
        $query = $this->pdo->prepare("SELECT MIN(available_at) FROM p2k_tp_job_items WHERE job_id=? AND status='retry'");
        $query->execute([$jobId]);
        $value = $query->fetchColumn();
        return is_string($value) && $value !== '' ? $value : null;
    }

    public function resetCurrentMembers(string $clubSlug): void
    {
        $query = $this->pdo->prepare('UPDATE p2k_tp_members SET current_member = 0 WHERE club_slug = ?');
        $query->execute([$clubSlug]);
    }

    public function upsertMember(string $clubSlug, string $username, ?int $joinedEpoch = null): void
    {
        $key=\p2k_tp_username_key($username);
        $joined=$joinedEpoch!==null&&$joinedEpoch>0?(new \DateTimeImmutable('@'.$joinedEpoch))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'):null;
        $first=$joined ?? \p2k_tp_utc_now()->format('Y-m-d H:i:s');
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_members(club_slug,username_key,username,current_member,joined_at,first_seen_at,last_seen_at)
            VALUES(?,?,?,1,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),current_member=1,
            joined_at=COALESCE(joined_at,VALUES(joined_at)),first_seen_at=LEAST(first_seen_at,VALUES(first_seen_at)),last_seen_at=UTC_TIMESTAMP()");
        $q->execute([$clubSlug,$key,$username,$joined,$first]);
    }

    public function upsertHistoricalMember(string $clubSlug, string $username): void
    {
        $key=\p2k_tp_username_key($username);
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_members(club_slug,username_key,username,current_member,joined_at,first_seen_at,last_seen_at)
            VALUES(?,?,?,0,NULL,UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE username=VALUES(username),last_seen_at=UTC_TIMESTAMP()");
        $q->execute([$clubSlug,$key,$username]);
    }

    public function currentMembers(string $clubSlug): array
    {
        $query = $this->pdo->prepare('SELECT member_id, username, username_key FROM p2k_tp_members WHERE club_slug = ? AND current_member = 1 ORDER BY username_key');
        $query->execute([$clubSlug]);
        return $query->fetchAll();
    }

    public function currentMembersAfterId(string $clubSlug, int $afterId, int $limit = 250): array
    {
        $limit=max(1,min(500,$limit));
        $query=$this->pdo->prepare("SELECT member_id,username,username_key,first_seen_at,player_matches_checked_at,player_matches_observed_at,player_matches_unverified_since,stats_checked_at,stats_observed_at,stats_unverified_since,rating_updated_at FROM p2k_tp_members WHERE club_slug=? AND current_member=1 AND member_id>? ORDER BY member_id ASC LIMIT {$limit}");
        $query->execute([$clubSlug,max(0,$afterId)]);
        return $query->fetchAll() ?: [];
    }

    /** ACAMR candidates rotate from the supplied member cursor and only request due observed-freshness work. */
    public function acamrCandidateMembers(string $clubSlug,int $afterMemberId=0,int $limit=120):array
    {
        $limit=max(12,min(250,$limit));
        $app=\p2k_tp_config()['app']??[];
        $matchesDueHours=max(24,(int)ceil(max(86400,(int)($app['player_reconcile_matches_refresh_seconds']??604800))/3600));
        $statsDueHours=max(24,(int)ceil(max(86400,(int)($app['player_reconcile_stats_refresh_seconds']??259200))/3600));
        $base="SELECT m.member_id,m.username,m.username_key,m.rating_updated_at,m.stats_checked_at,m.stats_observed_at,m.player_matches_checked_at,m.player_matches_observed_at,m.last_seen_at,
          COALESCE(x.in_progress_boards,0) in_progress_boards,COALESCE(x.incomplete_boards,0) incomplete_boards,
          (GREATEST(COALESCE(m.player_matches_checked_at,'1970-01-01'),COALESCE(m.player_matches_observed_at,'1970-01-01')) < UTC_TIMESTAMP()-INTERVAL {$matchesDueHours} HOUR) matches_due,
          (GREATEST(COALESCE(m.stats_checked_at,'1970-01-01'),COALESCE(m.stats_observed_at,'1970-01-01')) < UTC_TIMESTAMP()-INTERVAL {$statsDueHours} HOUR) stats_due,
          (CASE WHEN GREATEST(COALESCE(m.player_matches_checked_at,'1970-01-01'),COALESCE(m.player_matches_observed_at,'1970-01-01'))='1970-01-01' THEN 50 ELSE LEAST(40,GREATEST(0,TIMESTAMPDIFF(HOUR,GREATEST(COALESCE(m.player_matches_checked_at,'1970-01-01'),COALESCE(m.player_matches_observed_at,'1970-01-01')),UTC_TIMESTAMP())/6)) END
           + CASE WHEN GREATEST(COALESCE(m.stats_checked_at,'1970-01-01'),COALESCE(m.stats_observed_at,'1970-01-01'))='1970-01-01' THEN 30 ELSE LEAST(20,GREATEST(0,TIMESTAMPDIFF(HOUR,GREATEST(COALESCE(m.stats_checked_at,'1970-01-01'),COALESCE(m.stats_observed_at,'1970-01-01')),UTC_TIMESTAMP())/12)) END
           + LEAST(25,COALESCE(x.incomplete_boards,0)*5)+LEAST(20,COALESCE(x.in_progress_boards,0)*4)) priority_score
          FROM p2k_tp_members m LEFT JOIN (
            SELECT b.member_id,SUM(mm.status='in_progress') in_progress_boards,SUM(b.state<>'complete_immutable') incomplete_boards
            FROM p2k_tp_boards b JOIN p2k_tp_match_metadata mm ON mm.club_slug=? AND mm.match_id=b.match_id
            WHERE mm.status IN ('in_progress','finished') GROUP BY b.member_id
          ) x ON x.member_id=m.member_id
          WHERE m.club_slug=? AND m.current_member=1 AND m.member_id>?
            AND ((GREATEST(COALESCE(m.player_matches_checked_at,'1970-01-01'),COALESCE(m.player_matches_observed_at,'1970-01-01')) < UTC_TIMESTAMP()-INTERVAL {$matchesDueHours} HOUR)
              OR (GREATEST(COALESCE(m.stats_checked_at,'1970-01-01'),COALESCE(m.stats_observed_at,'1970-01-01')) < UTC_TIMESTAMP()-INTERVAL {$statsDueHours} HOUR)
              OR COALESCE(x.incomplete_boards,0)>0)
          ORDER BY m.member_id ASC LIMIT {$limit}";
        $q=$this->pdo->prepare($base);$q->execute([$clubSlug,$clubSlug,max(0,$afterMemberId)]);
        return $q->fetchAll()?:[];
    }

    /** Requeue legacy permanently-failed board work into the appropriate v2.8.4 lane once. */
    public function recoverFailedBoardsToLane(string $clubSlug, string $targetJobId, string $lane, int $limit = 500): int
    {
        $lane=in_array($lane,['club','player'],true)?$lane:'player';
        $limit=max(1,min(2000,$limit));
        $query=$this->pdo->prepare("SELECT i.id,i.item_key,i.payload_json FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE j.club_slug=? AND i.item_type='sync_board' AND i.status='failed' ORDER BY i.updated_at ASC,i.id ASC LIMIT {$limit}");
        $query->execute([$clubSlug]);
        $recovered=0;
        foreach($query->fetchAll() ?: [] as $row){
            $payload=\p2k_tp_json_decode($row['payload_json']??null);
            if (!empty($payload['recovered_in_release'])) continue; // one-time release repair; normal retry policy owns later failures
            $bucket=strtolower(trim((string)($payload['source_bucket']??'unknown')));
            $urgent=in_array($bucket,['registered','in_progress','finished'],true);
            if(($lane==='club')!==$urgent) continue;
            $payload['recovered_failed_item_id']=(int)$row['id'];
            $payload['recovered_in_release']='2.8.4';
            $queued=$this->enqueue($targetJobId,'sync_board','recovered:' . (int)$row['id'] . ':' . (string)$row['item_key'],$payload);
            if(!$queued) continue;
            $mark=$this->pdo->prepare("UPDATE p2k_tp_job_items SET status='skipped',locked_at=NULL,updated_at=UTC_TIMESTAMP(),last_error=CONCAT(COALESCE(last_error,''),'\nRequeued by v2.8.4 failed-board recovery into ',?) WHERE id=? AND status='failed'");
            $mark->execute([$lane,(int)$row['id']]);
            $recovered++;
        }
        return $recovered;
    }

    private static function storedRating(mixed $value): ?int
    {
        if(!is_numeric($value))return null;$rating=(int)$value;return $rating>0&&$rating<10000?$rating:null;
    }

    /** Persist one paired board-rating snapshot without letting a post-game fallback overwrite a match-lineup snapshot. */
    private function updateBoardRatingSnapshot(int $boardId,mixed $p2kRating,mixed $opponentRating,string $source): bool
    {
        $ours=self::storedRating($p2kRating);$theirs=self::storedRating($opponentRating);
        if($boardId<=0||$ours===null||$theirs===null)return false;
        $source=$source==='match_lineup'?'match_lineup':'board_game';
        $q=$this->pdo->prepare('SELECT p2k_rating,opponent_rating,rating_source FROM p2k_tp_boards WHERE board_id=? LIMIT 1');$q->execute([$boardId]);$old=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($old))return false;$oldSource=(string)($old['rating_source']??'');
        if($oldSource==='match_lineup'&&$source!=='match_lineup')return false;
        if($oldSource==='board_game'&&$source==='board_game')return false;
        if((int)($old['p2k_rating']??0)===$ours&&(int)($old['opponent_rating']??0)===$theirs&&$oldSource===$source)return false;
        $u=$this->pdo->prepare('UPDATE p2k_tp_boards SET p2k_rating=?,opponent_rating=?,rating_source=?,rating_captured_at=UTC_TIMESTAMP() WHERE board_id=?');
        $u->execute([$ours,$theirs,$source,$boardId]);return $u->rowCount()>0;
    }

    public function upsertParticipation(array $row): void
    {
        $club=(string)$row['club_slug'];$username=(string)$row['username'];$key=(string)$row['username_key'];$matchId=(int)$row['match_id'];
        $memberId=$this->memberId($club,$key,$username);
        [$boardNo,$override]=$this->boardReference((string)$row['board_url'],$matchId);
        // IPDR: the same physical Daily match/board may expose a renamed username on a later authoritative observation.
        // Persist one-to-one substitution evidence before reassigning the physical board owner.
        $physical=$this->pdo->prepare('SELECT b.board_id,b.member_id,u.username_key,u.username FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND b.match_id=? AND b.board_no=? LIMIT 1');$physical->execute([$club,$matchId,$boardNo]);$prior=$physical->fetch(PDO::FETCH_ASSOC);
        if(is_array($prior)&&(string)$prior['username_key']!==$key){$oldKey=(string)$prior['username_key'];$other=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members u ON u.member_id=b.member_id WHERE u.club_slug=? AND b.match_id=? AND b.board_id<>? AND u.username_key IN (?,?)');$other->execute([$club,$matchId,(int)$prior['board_id'],$oldKey,$key]);if((int)$other->fetchColumn()===0){$miac=new MiacService($this->pdo,$club);$miac->recordDefinitiveSubstitution((string)$prior['username'],$username,'daily_board_substitution',['match_id'=>$matchId,'board_no'=>$boardNo,'board_url'=>(string)$row['board_url'],'shared_boards'=>1,'old_member_id'=>(int)$prior['member_id'],'new_member_id'=>$memberId,'one_to_one_match_ownership'=>true]);$move=$this->pdo->prepare('UPDATE p2k_tp_boards SET member_id=?,last_discovered_at=UTC_TIMESTAMP() WHERE board_id=?');$move->execute([$memberId,(int)$prior['board_id']]);if($move->rowCount()>0)$this->touchCoreGeneration($club);}}
        $opponentUsername=trim((string)($row['opponent_username']??''));if($opponentUsername==='')$opponentUsername=null;
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_boards(member_id,match_id,board_no,opponent_username,board_url_override,white_result,black_result,source_bucket,state,finished_game_count,first_discovered_at,last_discovered_at,next_check_at)
            VALUES(?,?,?,?,?,?,?,'unknown','newly_discovered',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE opponent_username=COALESCE(VALUES(opponent_username),opponent_username),board_url_override=COALESCE(VALUES(board_url_override),board_url_override),white_result=VALUES(white_result),black_result=VALUES(black_result),last_discovered_at=UTC_TIMESTAMP()");
        $q->execute([$memberId,$matchId,$boardNo,$opponentUsername,$override,$row['white_result']??null,$row['black_result']??null]);
        $boardId=$this->boardId($club,$key,$matchId,(string)$row['board_url'],$username,true);
        if($this->updateBoardRatingSnapshot($boardId,$row['p2k_rating']??null,$row['opponent_rating']??null,(string)($row['rating_source']??'match_lineup')))$this->touchCoreGeneration($club);
        if (!empty($row['match_url'])) {
            $m=$this->pdo->prepare('UPDATE p2k_tp_match_metadata SET match_url=COALESCE(match_url,?),updated_at=UTC_TIMESTAMP() WHERE club_slug=? AND match_id=?');
            $m->execute([(string)$row['match_url'],$club,$matchId]);
        }
    }

    public function upsertPointEvent(array $row): bool
    {
        $club=(string)$row['club_slug'];$key=(string)$row['username_key'];$username=(string)$row['username'];$matchId=(int)$row['match_id'];$boardUrl=(string)$row['board_url'];
        $boardId=$this->boardId($club,$key,$matchId,$boardUrl,$username,true);
        if ($boardId<=0) throw new \RuntimeException('Unable to resolve compact board row for a point event.');
        $ratingsChanged=$this->updateBoardRatingSnapshot($boardId,$row['p2k_rating']??null,$row['opponent_rating']??null,(string)($row['rating_source']??'board_game'));
        $gameUrl=trim((string)$row['game_url']);$gameId=$this->gameIdFromUrl($gameUrl);$isSeed=str_contains($gameUrl,'#')?1:0;
        $sourceHash=$this->hashBinary($row['source_hash']??null);$end=(string)$row['game_end_utc'];$result=(string)$row['result_code'];$pointsX2=max(0,min(2,(int)round(((float)$row['points'])*2)));
        $override=($gameId===null && !$isSeed && $gameUrl!=='')?$gameUrl:null;
        if ($gameId!==null) {
            $exact=$this->pdo->prepare('SELECT game_row_id FROM p2k_tp_games WHERE game_id=? LIMIT 1');$exact->execute([$gameId]);$id=$exact->fetchColumn();
            if($id!==false){$u=$this->pdo->prepare('UPDATE p2k_tp_games SET source_hash=COALESCE(source_hash,?),verified_at=UTC_TIMESTAMP() WHERE game_row_id=?');$u->execute([$sourceHash,$id]);if($ratingsChanged)$this->touchCoreGeneration($club);return false;}
        }
        // Replace a synthetic seed record when the real archive/game URL later appears.
        if (!$isSeed) {
            $seed=$this->pdo->prepare('SELECT game_row_id FROM p2k_tp_games WHERE board_id=? AND game_end_utc=? AND result_code=? AND is_seed=1 LIMIT 1');
            $seed->execute([$boardId,$end,$result]);$seedId=$seed->fetchColumn();
            if($seedId!==false){$u=$this->pdo->prepare('UPDATE p2k_tp_games SET game_id=?,game_url_override=?,source_hash=COALESCE(source_hash,?),verified_at=UTC_TIMESTAMP(),is_seed=0 WHERE game_row_id=?');$u->execute([$gameId,$override,$sourceHash,$seedId]);$this->touchCoreGeneration($club);return false;}
        }
        $sequence=$this->sequenceFromSyntheticUrl($gameUrl);
        if($sequence===null){$q=$this->pdo->prepare('SELECT COALESCE(MAX(sequence_no),0)+1 FROM p2k_tp_games WHERE board_id=?');$q->execute([$boardId]);$sequence=max(1,min(255,(int)$q->fetchColumn()));}
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_games(board_id,sequence_no,game_id,game_url_override,game_end_utc,result_code,points_x2,source_hash,verified_at,is_seed)
            VALUES(?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE game_id=COALESCE(VALUES(game_id),game_id),game_url_override=COALESCE(VALUES(game_url_override),game_url_override),
            game_end_utc=VALUES(game_end_utc),result_code=VALUES(result_code),points_x2=VALUES(points_x2),source_hash=COALESCE(source_hash,VALUES(source_hash)),verified_at=UTC_TIMESTAMP(),is_seed=LEAST(is_seed,VALUES(is_seed))");
        $q->execute([$boardId,$sequence,$gameId,$override,$end,$result,$pointsX2,$sourceHash,$isSeed]);
        $changed=$q->rowCount()>0;if($changed||$ratingsChanged)$this->touchCoreGeneration($club);return $changed;
    }

    public function pointEventCount(string $clubSlug, string $usernameKey, string $boardUrl): int
    {
        $boardId=$this->boardId($clubSlug,$usernameKey,0,$boardUrl,'',false);if($boardId<=0)return 0;
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_tp_games WHERE board_id=?');$q->execute([$boardId]);return (int)$q->fetchColumn();
    }

    public function boardState(string $clubSlug, string $usernameKey, string $boardUrl): ?array
    {
        $q=$this->pdo->prepare('SELECT * FROM p2k_tp_board_states WHERE club_slug=? AND username_key=? AND board_url=? LIMIT 1');
        $q->execute([$clubSlug,$usernameKey,$boardUrl]);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?$row:null;
    }

    public function registerBoardDiscovery(string $clubSlug,string $username,int $matchId,string $boardUrl,string $sourceBucket): array
    {
        $key=\p2k_tp_username_key($username);$existing=$this->boardState($clubSlug,$key,$boardUrl);$isNew=$existing===null;
        if($isNew)$this->upsertParticipation(['club_slug'=>$clubSlug,'username_key'=>$key,'username'=>$username,'match_id'=>$matchId,'match_url'=>'','board_url'=>$boardUrl,'white_result'=>null,'black_result'=>null]);
        $finished=$existing===null?$this->pointEventCount($clubSlug,$key,$boardUrl):(int)($existing['finished_game_count']??0);
        $state=$finished>=2?'complete_immutable':($finished===1?'potentially_incomplete':((($existing['state']??'')==='failed_malformed')?'failed_malformed':(($sourceBucket==='in_progress'||($existing['state']??'')==='recent_in_progress')?'recent_in_progress':'newly_discovered')));
        $boardId=$this->boardId($clubSlug,$key,$matchId,$boardUrl,$username,true);
        $q=$this->pdo->prepare("UPDATE p2k_tp_boards SET source_bucket=?,state=?,finished_game_count=?,last_discovered_at=UTC_TIMESTAMP(),next_check_at=CASE WHEN ?='complete_immutable' THEN NULL ELSE COALESCE(next_check_at,UTC_TIMESTAMP()) END,completed_at=CASE WHEN ?='complete_immutable' THEN COALESCE(completed_at,UTC_TIMESTAMP()) ELSE completed_at END WHERE board_id=?");
        $q->execute([$this->normalizeSourceBucket($sourceBucket),$state,min(255,$finished),$state,$state,$boardId]);
        $row=$this->boardState($clubSlug,$key,$boardUrl)??[];$row['is_new']=$isNew;$row['due']=$state!=='complete_immutable'&&($isNew||empty($row['next_check_at'])||(strtotime((string)$row['next_check_at'].' UTC')?:0)<=time());return $row;
    }

    public function markBoardChecked(string $clubSlug,string $username,int $matchId,string $boardUrl,string $sourceBucket,string $state,int $finishedGameCount,?int $nextCheckSeconds,?string $error=null,bool $incrementFailure=false): void
    {
        $key=\p2k_tp_username_key($username);$boardId=$this->boardId($clubSlug,$key,$matchId,$boardUrl,$username,true);
        $next=$nextCheckSeconds===null?null:gmdate('Y-m-d H:i:s',time()+max(1,$nextCheckSeconds));
        $sql="UPDATE p2k_tp_boards SET source_bucket=CASE WHEN ? IN ('finished','in_progress') THEN ? ELSE source_bucket END,state=?,finished_game_count=?,last_checked_at=UTC_TIMESTAMP(),next_check_at=?,completed_at=CASE WHEN ?='complete_immutable' THEN COALESCE(completed_at,UTC_TIMESTAMP()) ELSE NULL END,failure_count=failure_count+?,last_error=? WHERE board_id=?";
        $q=$this->pdo->prepare($sql);$bucket=$this->normalizeSourceBucket($sourceBucket);$q->execute([$bucket,$bucket,$state,min(255,max(0,$finishedGameCount)),$next,$state,$incrementFailure?1:0,$error===null?null:substr($error,0,60000),$boardId]);
    }

    public function dueBoardRediscoveriesForClub(string $clubSlug, int $limit = 1000): array
    {
        $limit = max(1, min(5000, $limit));
        $query = $this->pdo->prepare(
            "SELECT username,username_key,match_id,board_url,source_bucket,state,finished_game_count,next_check_at
             FROM p2k_tp_board_states
             WHERE club_slug=? AND state<>'complete_immutable'
               AND (next_check_at IS NULL OR next_check_at<=UTC_TIMESTAMP())
             ORDER BY CASE state
                 WHEN 'potentially_incomplete' THEN 0
                 WHEN 'failed_malformed' THEN 1
                 WHEN 'recent_in_progress' THEN 2
                 ELSE 3 END,
                 COALESCE(next_check_at, first_discovered_at), board_url
             LIMIT {$limit}"
        );
        $query->execute([$clubSlug]);
        return $query->fetchAll();
    }

    public function dueBoardRediscoveries(string $clubSlug, string $usernameKey, int $limit = 250): array
    {
        $limit = max(1, min(2000, $limit));
        $query = $this->pdo->prepare(
            "SELECT username,username_key,match_id,board_url,source_bucket,state,finished_game_count,next_check_at
             FROM p2k_tp_board_states
             WHERE club_slug=? AND username_key=? AND state<>'complete_immutable'
               AND (next_check_at IS NULL OR next_check_at<=UTC_TIMESTAMP())
             ORDER BY CASE state
                 WHEN 'potentially_incomplete' THEN 0
                 WHEN 'failed_malformed' THEN 1
                 WHEN 'recent_in_progress' THEN 2
                 ELSE 3 END,
                 COALESCE(next_check_at, first_discovered_at), board_url
             LIMIT {$limit}"
        );
        $query->execute([$clubSlug, $usernameKey]);
        return $query->fetchAll();
    }

    public function boardStateSummary(string $clubSlug): array
    {
        $query = $this->pdo->prepare(
            'SELECT state,COUNT(*) AS board_count FROM p2k_tp_board_states WHERE club_slug=? GROUP BY state ORDER BY state'
        );
        $query->execute([$clubSlug]);
        $summary = [
            'newly_discovered' => 0,
            'recent_in_progress' => 0,
            'potentially_incomplete' => 0,
            'failed_malformed' => 0,
            'complete_immutable' => 0,
        ];
        foreach ($query->fetchAll() as $row) {
            $summary[(string)$row['state']] = (int)$row['board_count'];
        }
        return $summary;
    }

    public function cacheGet(string $url): ?array
    {
        $cache=new \P2K\Shared\FilesystemCache(\p2k_tp_config()['storage']??[]);return $cache->get($url);
    }

    public function cachePut(string $url, int $status, ?string $body, ?string $etag, ?string $lastModified, int $ttlSeconds): void
    {
        $cache=new \P2K\Shared\FilesystemCache(\p2k_tp_config()['storage']??[]);$cache->put($url,$status,$body,$etag,$lastModified,$ttlSeconds,'team-points-legacy-adapter');
    }

    private function memberId(string $clubSlug,string $usernameKey,string $username=''): int
    {
        $q=$this->pdo->prepare('SELECT member_id FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');$q->execute([$clubSlug,$usernameKey]);$id=$q->fetchColumn();
        if($id!==false)return (int)$id;
        if($username==='')return 0;$this->upsertHistoricalMember($clubSlug,$username);$q->execute([$clubSlug,$usernameKey]);return (int)($q->fetchColumn()?:0);
    }

    /** @return array{0:int,1:?string} */
    private function boardReference(string $boardUrl,int $matchId): array
    {
        if(preg_match('~/match/(\\d+)/(\\d+)/?$~i',$boardUrl,$m)&&($matchId<=0||(int)$m[1]===$matchId))return [(int)$m[2],null];
        $fallback=(int)(hexdec(substr(hash('sha256',$boardUrl),0,7))%2000000000)+1;return [$fallback,$boardUrl!==''?$boardUrl:null];
    }

    private function boardId(string $clubSlug,string $usernameKey,int $matchId,string $boardUrl,string $username='',bool $create=false): int
    {
        if ($matchId <= 0 && preg_match('~/match/(\d+)/(\d+)/?$~i', $boardUrl, $m)) $matchId = (int)$m[1];
        $memberId=$this->memberId($clubSlug,$usernameKey,$username);if($memberId<=0)return 0;[$boardNo,$override]=$this->boardReference($boardUrl,$matchId);
        $sql='SELECT board_id FROM p2k_tp_boards WHERE member_id=?'.($matchId>0?' AND match_id=?':' AND board_no=?');$q=$this->pdo->prepare($sql);$q->execute($matchId>0?[$memberId,$matchId]:[$memberId,$boardNo]);$id=$q->fetchColumn();
        if($id!==false)return (int)$id;if(!$create||$matchId<=0)return 0;
        $q=$this->pdo->prepare("INSERT INTO p2k_tp_boards(member_id,match_id,board_no,board_url_override,source_bucket,state,finished_game_count,first_discovered_at,last_discovered_at,next_check_at) VALUES(?,?,?,?,'unknown','newly_discovered',0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE last_discovered_at=UTC_TIMESTAMP()");$q->execute([$memberId,$matchId,$boardNo,$override]);
        $q=$this->pdo->prepare('SELECT board_id FROM p2k_tp_boards WHERE member_id=? AND match_id=? LIMIT 1');$q->execute([$memberId,$matchId]);return (int)($q->fetchColumn()?:0);
    }

    private function gameIdFromUrl(string $url): ?int
    {
        if(preg_match('~/game/(?:daily/)?(\\d+)(?:/|$|\\?)~i',$url,$m))return (int)$m[1];return null;
    }

    private function sequenceFromSyntheticUrl(string $url): ?int
    {
        if(preg_match('/#seed-game-(\\d+)/i',$url,$m))return max(1,min(255,(int)$m[1]));return null;
    }

    private function hashBinary(mixed $hash): ?string
    {
        $value=strtolower(trim((string)$hash));if($value===''||!preg_match('/^[0-9a-f]{64}$/',$value))return null;$binary=hex2bin($value);return $binary===false?null:$binary;
    }

    private function normalizeSourceBucket(string $value): string
    {
        $value=strtolower(trim($value));return in_array($value,['unknown','registered','in_progress','finished','rediscovered'],true)?$value:'unknown';
    }

    public function beginWorkerRun(?string $jobId, string $trigger): int
    {
        $query = $this->pdo->prepare(
            "INSERT INTO p2k_tp_worker_runs(job_id, trigger_type, started_at, result_status) VALUES(?,?,UTC_TIMESTAMP(),'running')"
        );
        $query->execute([$jobId, $trigger]);
        return (int)$this->pdo->lastInsertId();
    }

    public function endWorkerRun(int $runId, int $processed, string $status, string $message): void
    {
        $query = $this->pdo->prepare(
            'UPDATE p2k_tp_worker_runs SET finished_at=UTC_TIMESTAMP(), processed_items=?, result_status=?, message=? WHERE id=?'
        );
        $query->execute([$processed, $status, substr($message, 0, 60000), $runId]);
    }

    public function log(?string $jobId, ?int $runId, string $level, string $taskType, ?string $itemKey, string $message, array $context = []): void
    {
        $query = $this->pdo->prepare(
            "INSERT INTO p2k_tp_job_logs(job_id,worker_run_id,level,task_type,item_key,message,context_json,created_at)
             VALUES(?,?,?,?,?,?,?,UTC_TIMESTAMP())"
        );
        $contextJson = $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $query->execute([$jobId, $runId, substr($level, 0, 12), substr($taskType, 0, 40), $itemKey === null ? null : substr($itemKey, 0, 255), substr($message, 0, 60000), $contextJson]);
    }

    public function recentLogs(string $jobId, int $limit = 150): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->pdo->prepare(
            "SELECT id,worker_run_id,level,task_type,item_key,message,context_json,created_at
             FROM p2k_tp_job_logs WHERE job_id=? ORDER BY id DESC LIMIT {$limit}"
        );
        $query->execute([$jobId]);
        return $query->fetchAll();
    }

    public function jobDetails(?array $job): ?array
    {
        if($job===null)return null;
        $jobId=(string)$job['id'];
        $job['queue']=$this->queueCounts($jobId);
        $q=$job['queue'];
        $qTotal=(int)$q['pending']+(int)$q['running']+(int)$q['retry']+(int)$q['done']+(int)$q['failed']+(int)$q['skipped'];
        $qCommitted=(int)$q['done']+(int)$q['skipped'];
        $job['queue']['total']=$qTotal;
        $job['queue']['committed']=$qCommitted;
        $job['queue']['remaining_backlog']=max(0,$qTotal-$qCommitted);
        $job['queue']['currently_pending']=(int)$q['pending'];
        $job['queue']['claimed_running']=(int)$q['running'];
        $job['queue']['retry_waiting']=(int)$q['retry'];
        $job['task_breakdown']=$this->taskBreakdown($jobId);
        $job['current_item']=$this->currentItem($jobId);
        $job['next_retry_at']=$this->nextRetryAt($jobId);
        $job['issues']=$this->recentJobItems($jobId);
        $job['lane']=$this->laneForJob($job);
        if($job['lane']==='player')$job['task_activity']=$this->taskActivity($jobId,['reconcile_members','sync_player','sync_player_stats','sync_player_archive']);
        return $job;
    }

    public function summary(string $clubSlug): array
    {
        $sql = [
            'current_members' => 'SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=? AND current_member=1',
            'known_members' => 'SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=?',
            'participations' => 'SELECT COUNT(*) FROM p2k_tp_participations WHERE club_slug=?',
            'games' => 'SELECT COUNT(*) FROM p2k_tp_point_events WHERE club_slug=?',
            'points' => 'SELECT COALESCE(SUM(points),0) FROM p2k_tp_point_events WHERE club_slug=?',
            'months' => 'SELECT COUNT(DISTINCT utc_month) FROM p2k_tp_point_events WHERE club_slug=?',
            'first_month' => 'SELECT MIN(utc_month) FROM p2k_tp_point_events WHERE club_slug=?',
            'last_month' => 'SELECT MAX(utc_month) FROM p2k_tp_point_events WHERE club_slug=?',
        ];
        $result = [];
        foreach ($sql as $key => $statement) {
            $query = $this->pdo->prepare($statement);
            $query->execute([$clubSlug]);
            $result[$key] = $query->fetchColumn();
        }
        $cache = (new \P2K\Shared\FilesystemCache(\p2k_tp_config()['storage'] ?? []))->stats();
        $result['http_cache_entries'] = (int)($cache['entries'] ?? 0);
        $result['http_cache_bytes'] = (int)($cache['bytes'] ?? 0);
        $result['http_cache_backend'] = 'filesystem-gzip';
        $result['worker_runs'] = (int)$this->pdo->query('SELECT COUNT(*) FROM p2k_tp_worker_runs')->fetchColumn();
        $result['jobs'] = (int)$this->pdo->query('SELECT COUNT(*) FROM p2k_tp_jobs')->fetchColumn();

        $clubJob=$this->jobDetails($this->latestJob($clubSlug,'club'));
        $playerJob=$this->jobDetails($this->latestJob($clubSlug,'player'));
        $legacyJob=$this->jobDetails($this->latestJob($clubSlug,'combined'));
        $job=$clubJob ?? $playerJob ?? $legacyJob ?? $this->jobDetails($this->latestJob($clubSlug));
        $logs=$job ? $this->recentLogs((string)$job['id']) : [];
        $runs=$this->pdo->query('SELECT * FROM p2k_tp_worker_runs ORDER BY id DESC LIMIT 20')->fetchAll();
        return [
            'totals'=>$result,
            'freshness'=>$this->state($clubSlug),
            'board_states'=>$this->boardStateSummary($clubSlug),
            'coverage'=>$this->synchronizationCoverage($clubSlug),
            'cron_state'=>$this->cronState('team-points-club-continuous'),
            'cron_states'=>[
                'club'=>$this->cronState('team-points-club-continuous'),
                'player'=>$this->cronState('team-points-player-continuous'),
                'legacy'=>$this->cronState('team-points-continuous'),
            ],
            'jobs_by_lane'=>['club'=>$clubJob,'player'=>$playerJob,'legacy'=>$legacyJob],
            'job'=>$job,
            'worker_runs'=>$runs,
            'process_logs'=>$logs,
        ];
    }

    public function synchronizationCoverage(string $clubSlug): array
    {
        $seed=$this->latestSeedRun($clubSlug);
        $counts=[];
        $queries=[
            'members'=>'SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=?',
            'matches'=>'SELECT COUNT(*) FROM p2k_tp_match_metadata WHERE club_slug=?',
            'boards'=>'SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members m ON m.member_id=b.member_id WHERE m.club_slug=?',
            'events'=>'SELECT COUNT(*) FROM p2k_tp_point_events WHERE club_slug=?',
            'club_points'=>'SELECT COALESCE(SUM(competition_points),0) FROM p2k_tp_match_summaries WHERE club_slug=?',
            'members_without_events'=>'SELECT COUNT(*) FROM p2k_tp_members m WHERE m.club_slug=? AND m.current_member=1 AND NOT EXISTS (SELECT 1 FROM p2k_tp_point_events e WHERE e.club_slug=m.club_slug AND e.username_key=m.username_key)',
            'unfinished_board_states'=>"SELECT COUNT(*) FROM p2k_tp_boards b JOIN p2k_tp_members m ON m.member_id=b.member_id WHERE m.club_slug=? AND b.state<>'complete_immutable'",
        ];
        foreach($queries as $key=>$sql){$q=$this->pdo->prepare($sql);$q->execute([$clubSlug]);$counts[$key]=(int)$q->fetchColumn();}
        $expected=[
            'members'=>(int)($seed['expected_members']??0),
            'matches'=>(int)($seed['expected_matches']??0),
            'boards'=>(int)($seed['expected_boards']??0),
            'events'=>(int)($seed['expected_events']??0),
            'club_points'=>(int)($seed['expected_club_points']??0),
        ];
        $floors=[];$baselineOk=true;
        foreach($expected as $key=>$value){$ok=$value<=0 || (int)($counts[$key]??0)>=$value;$floors[$key]=$ok;$baselineOk=$baselineOk&&$ok;}
        $fq=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_tp_job_items i JOIN p2k_tp_jobs j ON j.id=i.job_id WHERE j.club_slug=? AND i.status='failed'");$fq->execute([$clubSlug]);$failed=(int)$fq->fetchColumn();
        $cfg=\p2k_tp_config();$app=is_array($cfg['app']??null)?$cfg['app']:[];
        $matchesCutoff=gmdate('Y-m-d H:i:s',time()-max(86400,(int)($app['player_reconcile_matches_refresh_seconds']??604800)));
        $statsCutoff=gmdate('Y-m-d H:i:s',time()-max(86400,(int)($app['player_reconcile_stats_refresh_seconds']??259200)));
        $rq=$this->pdo->prepare("SELECT COUNT(*) current_members,
            SUM(player_matches_checked_at IS NULL) player_matches_never_checked,
            SUM(stats_checked_at IS NULL) player_stats_never_checked,
            SUM(player_matches_checked_at IS NULL OR player_matches_checked_at<?) player_matches_due,
            SUM(stats_checked_at IS NULL OR stats_checked_at<?) player_stats_due,
            SUM(GREATEST(COALESCE(player_matches_checked_at,'1970-01-01'),COALESCE(player_matches_observed_at,'1970-01-01'))<?) player_matches_operational_due,
            SUM(GREATEST(COALESCE(stats_checked_at,'1970-01-01'),COALESCE(stats_observed_at,'1970-01-01'))<?) player_stats_operational_due
            FROM p2k_tp_members WHERE club_slug=? AND current_member=1");
        $rq->execute([$matchesCutoff,$statsCutoff,$matchesCutoff,$statsCutoff,$clubSlug]);$refresh=$rq->fetch(PDO::FETCH_ASSOC)?:[];$refreshCurrent=max(1,(int)($refresh['current_members']??0));
        $currentMembers=(int)($refresh['current_members']??0);
        $matchesDue=(int)($refresh['player_matches_due']??0);
        $statsDue=(int)($refresh['player_stats_due']??0);
        $matchesOperationalDue=(int)($refresh['player_matches_operational_due']??0);
        $statsOperationalDue=(int)($refresh['player_stats_operational_due']??0);
        $matchesFresh=round(100*max(0,$refreshCurrent-$matchesDue)/$refreshCurrent,1);
        $statsFresh=round(100*max(0,$refreshCurrent-$statsDue)/$refreshCurrent,1);
        $matchesOperationalFresh=round(100*max(0,$refreshCurrent-$matchesOperationalDue)/$refreshCurrent,1);
        $statsOperationalFresh=round(100*max(0,$refreshCurrent-$statsOperationalDue)/$refreshCurrent,1);
        $canonicalDueNow=$matchesDue+$statsDue;
        $operationalDueNow=$matchesOperationalDue+$statsOperationalDue;
        $possibleChecks=max(0,$currentMembers*2);
        $playerRefresh=[
            'current_members'=>$currentMembers,
            'matches_due'=>$matchesDue,
            'stats_due'=>$statsDue,
            'matches_operational_due'=>$matchesOperationalDue,
            'stats_operational_due'=>$statsOperationalDue,
            'matches_never_checked'=>(int)($refresh['player_matches_never_checked']??0),
            'stats_never_checked'=>(int)($refresh['player_stats_never_checked']??0),
            'matches_fresh_percent'=>$matchesFresh,
            'stats_fresh_percent'=>$statsFresh,
            'matches_operational_fresh_percent'=>$matchesOperationalFresh,
            'stats_operational_fresh_percent'=>$statsOperationalFresh,
            // ACSR diagnostics deliberately distinguish what is due for authoritative
            // verification from what is operationally fresh thanks to validated
            // browser observations. CRON/server workers remain the canonical authority.
            'matches_due_now'=>$matchesDue,
            'matches_scheduled_later'=>max(0,$currentMembers-$matchesDue),
            'stats_due_now'=>$statsDue,
            'stats_scheduled_later'=>max(0,$currentMembers-$statsDue),
            'canonical_due_now'=>$canonicalDueNow,
            'canonical_scheduled_later'=>max(0,$possibleChecks-$canonicalDueNow),
            'operational_due_now'=>$operationalDueNow,
            'operational_scheduled_later'=>max(0,$possibleChecks-$operationalDueNow),
            'canonical_fresh_percent'=>round(($matchesFresh+$statsFresh)/2,1),
            'operational_fresh_percent'=>round(($matchesOperationalFresh+$statsOperationalFresh)/2,1),
            'canonical_converged'=>$canonicalDueNow===0,
            'operational_converged'=>$operationalDueNow===0,
        ];
        return [
            'baseline_present'=>is_array($seed),
            'baseline_floor_ok'=>$baselineOk,
            'baseline_floors'=>$floors,
            'expected'=>$expected,
            'current'=>$counts,
            'failed_queue_items'=>$failed,
            'player_refresh'=>$playerRefresh,
            'historical_reconciliation_active'=>in_array((string)($this->latestJob($clubSlug,'player')['status']??''),['new','running'],true),
            'status'=>!$baselineOk?'baseline_below_seed_floor':($failed>0?'unresolved_failures':'covered'),
        ];
    }

    /**
     * Resolve the configured club against existing database content.
     * Older deployments sometimes used a different but single club_slug value;
     * public reads should reuse that data rather than silently returning zeroes.
     */
    private function resolveDataClubSlug(string $preferred): string
    {
        $preferred = strtolower(trim($preferred));
        if ($preferred === '') $preferred = 'promote-to-king';
        if (isset($this->resolvedClubSlugs[$preferred])) return $this->resolvedClubSlugs[$preferred];

        // vNext audit fix: state is keyed by club_slug and is maintained by every
        // supported v2.8 deployment. A single indexed lookup is enough to resolve
        // the normal configured club; do not COUNT members/participations/events.
        try {
            $q=$this->pdo->prepare('SELECT club_slug FROM p2k_tp_state WHERE club_slug=? LIMIT 1');
            $q->execute([$preferred]);$found=$q->fetchColumn();
            if(is_string($found)&&trim($found)!=='') return $this->resolvedClubSlugs[$preferred]=strtolower(trim($found));

            $q=$this->pdo->query("SELECT club_slug FROM p2k_tp_state WHERE club_slug IS NOT NULL AND club_slug<>'' ORDER BY club_slug LIMIT 2");
            $rows=array_values(array_unique(array_map(static fn($v):string=>strtolower(trim((string)$v)),$q->fetchAll(PDO::FETCH_COLUMN)?:[])));
            if(count($rows)===1&&$rows[0]!=='') return $this->resolvedClubSlugs[$preferred]=$rows[0];
        } catch (\Throwable) {}

        // Compatibility fallback for an incomplete legacy state row. All probes
        // are LIMIT 1/indexed and therefore independent of club population size.
        try {
            foreach (['p2k_tp_members','p2k_tp_participations','p2k_tp_match_metadata'] as $table) {
                $q=$this->pdo->prepare("SELECT club_slug FROM {$table} WHERE club_slug=? LIMIT 1");$q->execute([$preferred]);
                if($q->fetchColumn()!==false) return $this->resolvedClubSlugs[$preferred]=$preferred;
            }
        } catch (\Throwable) {}
        return $this->resolvedClubSlugs[$preferred]=$preferred;
    }

    /** Canonical club match outcome from authoritative team scores. */
    public static function canonicalMatchOutcome(float $ourScore, ?float $theirScore, int $boards, bool $isVoid = false): array
    {
        $boards = max(0, $boards);
        if ($isVoid) return ['result'=>'draw','competition_points'=>0,'is_void'=>true];
        if ($theirScore !== null) {
            if ($ourScore > $theirScore + 0.0001) return ['result'=>'win','competition_points'=>5*$boards,'is_void'=>false];
            if ($ourScore + 0.0001 < $theirScore) return ['result'=>'loss','competition_points'=>0,'is_void'=>false];
            return ['result'=>'draw','competition_points'=>2*$boards,'is_void'=>false];
        }
        // Compatibility fallback for legacy payloads without the opponent score.
        if ($ourScore > $boards + 0.0001) return ['result'=>'win','competition_points'=>5*$boards,'is_void'=>false];
        if (abs($ourScore-$boards)<0.0001) return ['result'=>'draw','competition_points'=>2*$boards,'is_void'=>false];
        return ['result'=>'loss','competition_points'=>0,'is_void'=>false];
    }

    /**
     * Finalize or correct one match summary only after the authoritative
     * Chess.com match payload confirms that the whole match is finished and
     * that every P2K board has been discovered.
     *
     * Passing no authoritative payload deliberately performs no write. This
     * prevents a set of completed boards from being mistaken for a completed
     * match while other boards are still running or not yet discovered.
     */
    public function matchReadyForAuthoritativeFinalization(string $clubSlug,int $matchId): bool
    {
        if($matchId<=0)return false;
        $q=$this->pdo->prepare("SELECT board_count FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1");$q->execute([$clubSlug,$matchId]);$expected=(int)($q->fetchColumn()?:0);if($expected<=0)return false;
        $q=$this->pdo->prepare("SELECT COUNT(*) total,SUM(b.state='complete_immutable' AND b.finished_game_count>=2) complete FROM p2k_tp_boards b JOIN p2k_tp_members m ON m.member_id=b.member_id WHERE m.club_slug=? AND b.match_id=?");$q->execute([$clubSlug,$matchId]);$r=$q->fetch()?:[];
        return (int)($r['total']??0)===$expected && (int)($r['complete']??0)===$expected;
    }

    public function finalizeMatchSummaryIfComplete(string $clubSlug, int $matchId, ?array $authoritativeMatch = null): bool
    {
        if ($matchId <= 0 || !is_array($authoritativeMatch)) return false;
        $clubSlug = strtolower(trim($clubSlug));
        $status = strtolower(trim((string)($authoritativeMatch['status'] ?? '')));
        $authoritativeBoards = (int)($authoritativeMatch['boards'] ?? 0);
        if ($status !== 'finished' || $authoritativeBoards <= 0) return false;

        $clubTeam = $this->authoritativeClubTeam($authoritativeMatch, $clubSlug);
        if ($clubTeam === null || !is_numeric($clubTeam['score'] ?? null)) return false;
        $authoritativePlayers = is_array($clubTeam['players'] ?? null) ? $clubTeam['players'] : [];
        if ($authoritativePlayers !== [] && count($authoritativePlayers) !== $authoritativeBoards) return false;
        $authoritativeScore = (float)$clubTeam['score'];
        $opponentScore = null;
        foreach (is_array($authoritativeMatch['teams'] ?? null) ? $authoritativeMatch['teams'] : [] as $teamKey => $team) {
            if (!is_array($team)) continue;
            if ($this->teamSlugFromPayload($team, is_string($teamKey) ? $teamKey : '') === $clubSlug) continue;
            if (is_numeric($team['score'] ?? null)) { $opponentScore = (float)$team['score']; break; }
        }
        $isVoid = abs($authoritativeScore) < 0.001 && $opponentScore !== null && abs($opponentScore) < 0.001;

        if (!$isVoid) {
            $boards = $this->pdo->prepare(
                "SELECT b.state,b.finished_game_count,COUNT(g.game_row_id) game_count
                 FROM p2k_tp_boards b
                 JOIN p2k_tp_members u ON u.member_id=b.member_id
                 LEFT JOIN p2k_tp_games g ON g.board_id=b.board_id
                 WHERE u.club_slug=? AND b.match_id=?
                 GROUP BY b.board_id,b.state,b.finished_game_count"
            );
            $boards->execute([$clubSlug,$matchId]);
            $rows=$boards->fetchAll();
            if (count($rows)!==$authoritativeBoards) return false;
            foreach($rows as $row) {
                if ((string)$row['state']!=='complete_immutable' || (int)$row['finished_game_count']<2 || (int)$row['game_count']<2) return false;
            }
        }

        $outcome = self::canonicalMatchOutcome($authoritativeScore, $opponentScore, $authoritativeBoards, $isVoid);
        $result = (string)$outcome['result'];
        $competitionPoints = (int)$outcome['competition_points'];

        $existing=$this->pdo->prepare('SELECT board_count,p2k_score,opponent_score,result,competition_points,is_void FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');
        $existing->execute([$clubSlug,$matchId]);
        $old=$existing->fetch();
        $changed = !is_array($old)
            || (int)$old['board_count']!==$authoritativeBoards
            || abs((float)$old['p2k_score']-$authoritativeScore)>=0.001
            || ($opponentScore!==null && abs((float)$old['opponent_score']-$opponentScore)>=0.001)
            || (string)$old['result']!==$result
            || (int)$old['competition_points']!==$competitionPoints
            || (bool)$old['is_void']!==$isVoid;
        if (!$changed) return false;

        $q=$this->pdo->prepare(
            "UPDATE p2k_tp_match_metadata SET status='finished',board_count=?,p2k_score=?,opponent_score=COALESCE(?,opponent_score),result=?,competition_points=?,is_void=?,
                    finalized_at=COALESCE(finalized_at,UTC_TIMESTAMP()),updated_at=UTC_TIMESTAMP(),last_verified_at=UTC_TIMESTAMP(),next_detail_check_at=NULL
             WHERE club_slug=? AND match_id=?"
        );
        $q->execute([$authoritativeBoards,$authoritativeScore,$opponentScore,$result,$competitionPoints,$isVoid?1:0,$clubSlug,$matchId]);
        $didChange=$q->rowCount()>0;if($didChange)$this->touchCoreGeneration($clubSlug);return $didChange;
    }

    /**
     * Return a bounded list of locally complete matches that still require an
     * authoritative Chess.com match-status and board-count check.
     */
    public function matchSummaryCandidateIdsBatch(string $clubSlug, int $limit = 2): array
    {
        $limit = max(1, min(25, $limit));
        $query = $this->pdo->prepare(
            "SELECT p.match_id
             FROM p2k_tp_participations p
             JOIN p2k_tp_board_states b
               ON b.club_slug=p.club_slug AND b.username_key=p.username_key AND b.board_url=p.board_url
             LEFT JOIN p2k_tp_match_summaries s
               ON s.club_slug=p.club_slug AND s.match_id=p.match_id
             WHERE p.club_slug=? AND s.match_id IS NULL
             GROUP BY p.match_id
             HAVING COUNT(*)>0
                AND SUM(CASE WHEN b.source_bucket='finished' AND b.state='complete_immutable' AND b.finished_game_count>=2 THEN 1 ELSE 0 END)=COUNT(*)
             ORDER BY MAX(b.completed_at) ASC, p.match_id ASC
             LIMIT {$limit}"
        );
        $query->execute([$clubSlug]);
        return array_map('intval', $query->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * Kept for compatibility with older callers. Unverified DB-only summary
     * finalization is intentionally disabled; Worker now validates candidates
     * against the authoritative match endpoint.
     */
    public function backfillMatchSummariesBatch(string $clubSlug, int $limit = 25): int
    {
        return 0;
    }

    private function authoritativeClubTeam(array $match, string $clubSlug): ?array
    {
        $teams = is_array($match['teams'] ?? null) ? $match['teams'] : [];
        $apiClub = 'https://api.chess.com/pub/club/' . strtolower($clubSlug);
        $webClub = 'https://www.chess.com/club/' . strtolower($clubSlug);
        foreach ($teams as $team) {
            if (!is_array($team)) continue;
            foreach (['@id', 'url'] as $field) {
                $value = rtrim(strtolower(trim((string)($team[$field] ?? ''))), '/');
                if ($value === $apiClub || $value === $webClub) return $team;
                $path = trim((string)(parse_url($value, PHP_URL_PATH) ?: ''), '/');
                $parts = $path === '' ? [] : explode('/', $path);
                if ($parts !== [] && strtolower((string)end($parts)) === strtolower($clubSlug)) return $team;
            }
        }
        return null;
    }

    private function rebuildClubTotalsFromSummaries(string $clubSlug): void
    {
        $analytics=$this->analytics();
        (new AnalyticsBuilder($this->pdo,$analytics))->rebuildAll($clubSlug);
    }

    public function publicPlayerSummary(string $clubSlug, string $username): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$usernameKey=\p2k_tp_username_key($username);
        // Once Green is the public source, Team Points themselves come directly from
        // authoritative Green Core point events. The compatibility Analytics projection
        // remains useful for rank-position indexing but is no longer the source of the
        // displayed score/W-D-L totals.
        $row=$this->greenNativePlayerSummary($clubSlug,$username);
        if($row===null)$row=$this->projectedPlayerSummary($clubSlug,$username);
        if($row===null){
            // Resilience fallback only when Analytics is unavailable/missing this player.
            $event=$this->pdo->prepare("SELECT COALESCE(MAX(username),?) username,ROUND(COALESCE(SUM(points),0),1) points,COUNT(DISTINCT match_id) matches,COUNT(game_url) games,SUM(points=1.0) wins,SUM(points=0.5) draws,SUM(points=0.0) losses FROM p2k_tp_point_events WHERE club_slug=? AND username_key=?");
            $event->execute([$username,$clubSlug,$usernameKey]);$raw=$event->fetch(PDO::FETCH_ASSOC)?:[];$points=(float)($raw['points']??0);$definition=$this->hallRankForPoints($points);
            $row=['username'=>(string)($raw['username']??$username),'current_member'=>false,'points'=>$points,'matches'=>(int)($raw['matches']??0),'games'=>(int)($raw['games']??0),'wins'=>(int)($raw['wins']??0),'draws'=>(int)($raw['draws']??0),'losses'=>(int)($raw['losses']??0),'team_position'=>null,'category_position'=>null,'rank'=>$definition,'available'=>true,'projection_fallback'=>true];
        }
        // When Green is authoritative, the native helper already supplied current
        // membership and chronology from p2k_g_players. Blue/compatibility reads keep
        // the established p2k_tp_members overlay.
        if(($row['data_source']??'')!=='green_native_core'){
            $member=$this->pdo->prepare('SELECT username,current_member,joined_at,first_seen_at,last_seen_at FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
            $member->execute([$clubSlug,$usernameKey]);$core=$member->fetch(PDO::FETCH_ASSOC)?:[];
            if(!empty($core['username']))$row['username']=(string)$core['username'];
            if(array_key_exists('current_member',$core))$row['current_member']=(bool)$core['current_member'];
            $row['joined_at']=$core['joined_at']??null;$row['first_seen_at']=$core['first_seen_at']??null;$row['last_seen_at']=$core['last_seen_at']??null;
        }
        if(empty($row['current_member'])){$row['team_position']=null;$row['category_position']=null;}
        return $row;
    }

    /** Green-native player summary used after public Green cutover. */
    private function greenNativePlayerSummary(string $clubSlug,string $username): ?array
    {
        if(PublicReadDatabase::source()!=='green')return null;
        try{
            $inputKey=\p2k_tp_username_key($username);$canonicalKey=$inputKey;$canonicalName=$username;
            $id=$this->pdo->prepare('SELECT canonical_username_key,canonical_username FROM p2k_g_identity_map WHERE username_key=? LIMIT 1');$id->execute([$inputKey]);$identity=$id->fetch(PDO::FETCH_ASSOC)?:[];
            if(!empty($identity['canonical_username_key']))$canonicalKey=(string)$identity['canonical_username_key'];if(!empty($identity['canonical_username']))$canonicalName=(string)$identity['canonical_username'];
            $member=$this->pdo->prepare('SELECT username,current_member,joined_epoch,created_at,updated_at FROM p2k_g_players WHERE username_key=? LIMIT 1');$member->execute([$canonicalKey]);$core=$member->fetch(PDO::FETCH_ASSOC)?:[];
            if(!$core){$member->execute([$inputKey]);$core=$member->fetch(PDO::FETCH_ASSOC)?:[];if($core)$canonicalKey=$inputKey;}
            if(!$core)return null;
            $event=$this->pdo->prepare("SELECT MAX(COALESCE(im.canonical_username,e.username)) username,ROUND(COALESCE(SUM(e.points),0),1) points,COUNT(DISTINCT e.match_id) matches,COUNT(*) games,COALESCE(SUM(e.points=1.0),0) wins,COALESCE(SUM(e.points=0.5),0) draws,COALESCE(SUM(e.points=0.0),0) losses FROM p2k_g_point_events e JOIN p2k_g_matches m ON m.match_id=e.match_id LEFT JOIN p2k_g_identity_map im ON im.username_key=e.username_key WHERE COALESCE(im.canonical_username_key,e.username_key)=? AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress','finished')");
            $event->execute([$canonicalKey]);$raw=$event->fetch(PDO::FETCH_ASSOC)?:[];$points=(float)($raw['points']??0);$definition=$this->hallRankForPoints($points);$current=(bool)($core['current_member']??false);$teamPosition=null;$categoryPosition=null;
            if($current){
                $a=$this->analytics();$rank=$a->prepare('SELECT 1+COUNT(*) FROM p2k_g_player_totals WHERE points>? OR (points=? AND username_key<?)');$rank->execute([$points,$points,$canonicalKey]);$teamPosition=(int)$rank->fetchColumn();
                $minimum=(float)($definition['minimum']??0);$maximum=$definition['maximum']??null;$sql='SELECT 1+COUNT(*) FROM p2k_g_player_totals WHERE points>=?';$params=[$minimum];if($maximum!==null){$sql.=' AND points<?';$params[]=(float)$maximum;}$sql.=' AND (points>? OR (points=? AND username_key<?))';array_push($params,$points,$points,$canonicalKey);$rank=$a->prepare($sql);$rank->execute($params);$categoryPosition=(int)$rank->fetchColumn();
            }
            $matchSql="SELECT DISTINCT m.match_id,m.api_url,m.web_url,m.name,m.status,m.start_epoch,m.end_epoch,m.board_count
                FROM p2k_g_match_players mp
                JOIN p2k_g_matches m ON m.match_id=mp.match_id
                LEFT JOIN p2k_g_identity_map im ON im.username_key=mp.username_key
                WHERE COALESCE(im.canonical_username_key,mp.username_key)=? AND mp.is_p2k=1
                  AND m.club_verified=1 AND m.time_class='daily' AND m.is_void=0 AND m.status IN ('registered','in_progress')
                ORDER BY COALESCE(m.start_epoch,0),m.match_id";
            $mq=$this->pdo->prepare($matchSql);$mq->execute([$canonicalKey]);$matchLists=['registered'=>[],'in_progress'=>[]];
            foreach($mq->fetchAll(PDO::FETCH_ASSOC)?:[] as $mr){$status=(string)$mr['status'];if(!isset($matchLists[$status]))continue;$matchLists[$status][]=array_filter(['match_id'=>(int)$mr['match_id'],'url'=>(string)($mr['web_url']?:$mr['api_url']),'@id'=>(string)$mr['api_url'],'club'=>'https://api.chess.com/pub/club/'.rawurlencode($clubSlug),'name'=>(string)($mr['name']??'Team match'),'start_time'=>$mr['start_epoch']!==null?gmdate('c',(int)$mr['start_epoch']):null,'end_time'=>$mr['end_epoch']!==null?gmdate('c',(int)$mr['end_epoch']):null,'boards'=>$mr['board_count']!==null?(int)$mr['board_count']:null],static fn($v)=>$v!==null);}
            return ['username'=>(string)($core['username']??$raw['username']??$canonicalName),'current_member'=>$current,'points'=>$points,'matches'=>(int)($raw['matches']??0),'games'=>(int)($raw['games']??0),'wins'=>(int)($raw['wins']??0),'draws'=>(int)($raw['draws']??0),'losses'=>(int)($raw['losses']??0),'team_position'=>$teamPosition,'category_position'=>$categoryPosition,'rank'=>$definition,'available'=>true,'joined_at'=>!empty($core['joined_epoch'])?gmdate('Y-m-d H:i:s',(int)$core['joined_epoch']):null,'first_seen_at'=>$core['created_at']??null,'last_seen_at'=>$core['updated_at']??null,'match_lists'=>$matchLists,'data_source'=>'green_native_core'];
        }catch(\Throwable $e){error_log('P2K Green native player summary fallback: '.$e->getMessage());return null;}
    }

    /** Green-native club totals used after public Green cutover. */
    private function greenNativeClubDashboard(string $clubSlug): ?array
    {
        if(PublicReadDatabase::source()!=='green')return null;
        try{
            $m=$this->pdo->query("SELECT
                COALESCE(SUM(CASE WHEN scoring_eligible=1 THEN competition_points ELSE 0 END),0) club_points,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='registered' AND is_void=0),0) registered_matches,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='in_progress' AND is_void=0),0) in_progress_matches,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0),0) finished_matches,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='cancelled'),0) cancelled_matches,
                COALESCE(SUM(CASE WHEN club_verified=1 AND time_class='daily' AND status='registered' AND is_void=0 THEN COALESCE(board_count,0) ELSE 0 END),0) registered_boards,
                COALESCE(SUM(CASE WHEN club_verified=1 AND time_class='daily' AND status='in_progress' AND is_void=0 THEN COALESCE(board_count,0) ELSE 0 END),0) in_progress_boards,
                COALESCE(SUM(CASE WHEN club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 THEN COALESCE(board_count,0) ELSE 0 END),0) finished_boards,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND result='win'),0) won_matches,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND result='draw'),0) drawn_matches,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND result='loss'),0) lost_matches,
                COALESCE(SUM(club_verified=1 AND time_class='daily' AND status='finished' AND is_void=0 AND scoring_eligible=1),0) scored_finished_matches,
                MAX(updated_at) updated_at FROM p2k_g_matches")->fetch(PDO::FETCH_ASSOC)?:[];
            $members=(int)$this->pdo->query('SELECT COUNT(*) FROM p2k_g_players WHERE current_member=1')->fetchColumn();
            $finishedBoards=(int)($m['finished_boards']??0);$registeredBoards=(int)($m['registered_boards']??0);$ongoingBoards=(int)($m['in_progress_boards']??0);
            return ['club_points'=>(float)($m['club_points']??0),'registered_matches'=>(int)($m['registered_matches']??0),'in_progress_matches'=>(int)($m['in_progress_matches']??0),'ongoing_matches'=>(int)($m['in_progress_matches']??0),'finished_matches'=>(int)($m['finished_matches']??0),'cancelled_matches'=>(int)($m['cancelled_matches']??0),'current_members'=>$members,'registered_boards'=>$registeredBoards,'registered_games'=>$registeredBoards*2,'in_progress_boards'=>$ongoingBoards,'ongoing_boards'=>$ongoingBoards,'in_progress_games'=>$ongoingBoards*2,'ongoing_games'=>$ongoingBoards*2,'finished_boards'=>$finishedBoards,'finished_games'=>$finishedBoards*2,'finished_matches_available'=>true,'scored_finished_matches'=>(int)($m['scored_finished_matches']??0),'won_matches'=>(int)($m['won_matches']??0),'drawn_matches'=>(int)($m['drawn_matches']??0),'lost_matches'=>(int)($m['lost_matches']??0),'scoring_rule'=>'win=5×boards; draw=2×boards; loss=0','updated_from_database'=>true,'cache_mode'=>'green_native_core_live','cache_updated_at'=>$m['updated_at']??null,'resolved_club_slug'=>$clubSlug,'data_source'=>'green_native_core','freshness'=>'live_core'];
        }catch(\Throwable $e){error_log('P2K Green native club dashboard fallback: '.$e->getMessage());return null;}
    }

    /** Fresh Dashboard match rows. Green cutover bypasses compatibility/Chess.com snapshots. */
    public function publicDashboardMatches(string $clubSlug,string $status,int $limit=1500): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$status=strtolower(trim($status));if($status==='ongoing')$status='in_progress';
        if(!in_array($status,['registered','in_progress','finished'],true))$status='registered';$limit=max(1,min(2500,$limit));
        if(PublicReadDatabase::source()==='green'){
            $sql="SELECT match_id,api_url,web_url,name,status,rules,time_control,start_epoch,end_epoch,board_count,p2k_score,opponent_score,opponent_name,opponent_url,result,competition_points,updated_at FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily' AND is_void=0 AND status=? ORDER BY COALESCE(start_epoch,end_epoch,0) DESC,match_id DESC LIMIT {$limit}";
            $count=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_g_matches WHERE club_verified=1 AND time_class='daily' AND is_void=0 AND status=?");$count->execute([$status]);$totalRows=(int)$count->fetchColumn();$q=$this->pdo->prepare($sql);$q->execute([$status]);$rows=[];$boards=0;$latest=null;
            foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$b=(int)($r['board_count']??0);$boards+=$b;$u=(string)($r['updated_at']??'');if($u!==''&&($latest===null||$u>$latest))$latest=$u;$rows[]=['match_id'=>(int)$r['match_id'],'name'=>(string)($r['name']??'Team match'),'url'=>(string)($r['web_url']?:$r['api_url']),'@id'=>(string)$r['api_url'],'status'=>(string)$r['status'],'rules'=>$r['rules']!==null?(string)$r['rules']:null,'time_control'=>$r['time_control']!==null?(string)$r['time_control']:null,'start_time'=>$r['start_epoch']!==null?gmdate('c',(int)$r['start_epoch']):null,'end_time'=>$r['end_epoch']!==null?gmdate('c',(int)$r['end_epoch']):null,'boards'=>$b,'board_count'=>$b,'our_score'=>(float)($r['p2k_score']??0),'their_score'=>(float)($r['opponent_score']??0),'opponent_name'=>(string)($r['opponent_name']??''),'opponent_url'=>(string)($r['opponent_url']??''),'result'=>(string)($r['result']??'none'),'competition_points'=>(int)($r['competition_points']??0)];}
            return ['rows'=>$rows,'status'=>$status,'total_rows'=>$totalRows,'boards'=>$boards,'games'=>$boards*2,'data_source'=>'green_native_core','freshness'=>'live_core','updated_at'=>$latest];
        }
        $fallback=$this->publicMatchInsights($clubSlug,['page'=>1,'page_size'=>min(100,$limit),'filter'=>$status,'sort'=>'start_time','direction'=>'desc','include_summary'=>false]);$rows=$fallback['rows']??[];$boards=0;foreach($rows as $r)$boards+=(int)($r['boards']??0);return ['rows'=>$rows,'status'=>$status,'total_rows'=>(int)($fallback['pagination']['total_rows']??count($rows)),'boards'=>$boards,'games'=>$boards*2,'data_source'=>'compatibility','freshness'=>'database_projection'];
    }

    public function publicClubDashboard(string $clubSlug): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);
        $native=$this->greenNativeClubDashboard($clubSlug);if($native!==null)return $native;
        $analytics=$this->analytics();
        $query=$analytics->prepare('SELECT * FROM p2k_tp_club_totals WHERE club_slug=? LIMIT 1');
        $query->execute([$clubSlug]);
        $row=$query->fetch();
        if(!is_array($row)) $row=[];
        $summaryCount=$this->pdo->prepare("SELECT COUNT(*) FROM p2k_tp_match_summaries WHERE club_slug=?");
        $summaryCount->execute([$clubSlug]);
        return [
            'club_points'=>(float)($row['club_points']??0),'finished_matches'=>(int)($row['finished_matches']??0),
            'current_members'=>$this->currentMemberCountProjected($clubSlug),
            'finished_boards'=>(int)($row['finished_boards']??0),'finished_games'=>(int)($row['finished_games']??0),
            'finished_matches_available'=>true,'scored_finished_matches'=>(int)$summaryCount->fetchColumn(),
            'won_matches'=>(int)($row['won_matches']??0),'drawn_matches'=>(int)($row['drawn_matches']??0),'lost_matches'=>(int)($row['lost_matches']??0),
            'scoring_rule'=>'win=5×boards; draw=2×boards; loss=0','updated_from_database'=>true,
            'cache_mode'=>'analytics_projection_from_core','cache_updated_at'=>$row['updated_at']??null,'resolved_club_slug'=>$clubSlug,
        ];
    }

    /**
     * Read-only Hall of Fame data for the public dashboard.
     * No schema change is required: all rankings are derived from the existing
     * member and point-event tables.
     */
    public function publicHallOfFame(string $clubSlug, string $rankKey = '', string $memberSearch = '', int $page = 1, int $pageSize = 25): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$a=$this->analytics();$page=max(1,$page);$pageSize=max(10,min(50,$pageSize));$definitions=$this->hallRankDefinitions();
        $tot=$a->prepare('SELECT COUNT(*) members,ROUND(COALESCE(SUM(points),0),1) points FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1');$tot->execute([$clubSlug]);$totals=$tot->fetch(PDO::FETCH_ASSOC)?:[];
        $leaderQ=$a->prepare('SELECT username,points FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 ORDER BY points DESC,username_key ASC LIMIT 1');$leaderQ->execute([$clubSlug]);$leader=$leaderQ->fetch(PDO::FETCH_ASSOC)?:null;
        $summaries=[];
        foreach($definitions as $definition){$min=(float)$definition['minimum'];$max=$definition['maximum'];$where='club_slug=? AND current_member=1 AND points>=?';$params=[$clubSlug,$min];if($max!==null){$where.=' AND points<?';$params[]=(float)$max;}
            $q=$a->prepare("SELECT COUNT(*) members FROM p2k_an_player_totals WHERE {$where}");$q->execute($params);$count=(int)$q->fetchColumn();
            $q=$a->prepare("SELECT username,points FROM p2k_an_player_totals WHERE {$where} ORDER BY points DESC,username_key ASC LIMIT 1");$q->execute($params);$top=$q->fetch(PDO::FETCH_ASSOC)?:null;
            $summaries[]=array_merge($definition,['members'=>$count,'top_member'=>$top['username']??null,'top_points'=>$top===null?null:(float)$top['points']]);
        }
        $payload=['total_members'=>(int)($totals['members']??0),'total_points'=>(float)($totals['points']??0),'leader'=>$leader?['username'=>(string)$leader['username'],'points'=>(float)$leader['points']]:null,'ranks'=>$summaries];
        $selected=null;$found=null;$foundDefinition=null;$categoryPosition=null;
        if($memberSearch!==''){$needle=\p2k_tp_username_key($memberSearch);$q=$a->prepare('SELECT username_key,username,points,matches,games,wins,draws,losses FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 AND (username_key=? OR username LIKE ?) ORDER BY (username_key=?) DESC,points DESC,username_key ASC LIMIT 1');$q->execute([$clubSlug,$needle,'%'.$memberSearch.'%',$needle]);$found=$q->fetch(PDO::FETCH_ASSOC)?:null;
            if($found){$foundDefinition=$this->hallRankForPoints((float)$found['points']);$position=$a->prepare('SELECT 1+COUNT(*) FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 AND (points>? OR (points=? AND username_key<?))');$position->execute([$clubSlug,(float)$found['points'],(float)$found['points'],(string)$found['username_key']]);$teamPosition=(int)$position->fetchColumn();
                $min=(float)($foundDefinition['minimum']??0);$max=$foundDefinition['maximum']??null;$sql='SELECT 1+COUNT(*) FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 AND points>=?';$params=[$clubSlug,$min];if($max!==null){$sql.=' AND points<?';$params[]=(float)$max;}$sql.=' AND (points>? OR (points=? AND username_key<?))';array_push($params,(float)$found['points'],(float)$found['points'],(string)$found['username_key']);$position=$a->prepare($sql);$position->execute($params);$categoryPosition=(int)$position->fetchColumn();
                $payload['search']=['query'=>$memberSearch,'found'=>true,'member'=>['username'=>(string)$found['username'],'points'=>(float)$found['points'],'matches'=>(int)$found['matches'],'games'=>(int)$found['games'],'wins'=>(int)$found['wins'],'draws'=>(int)$found['draws'],'losses'=>(int)$found['losses'],'team_position'=>$teamPosition,'category_position'=>$categoryPosition,'rank'=>$foundDefinition]];if(($foundDefinition['key']??'')!=='unranked'){$selected=$foundDefinition;$page=max(1,(int)ceil($categoryPosition/$pageSize));}}
            else $payload['search']=['query'=>$memberSearch,'found'=>false,'member'=>null];}
        if($selected===null&&$rankKey!==''){foreach($definitions as $d)if($d['key']===$rankKey){$selected=$d;break;}if($selected===null)throw new ApiException('Unknown Hall of Fame rank.',400,'UNKNOWN_HALL_RANK');}
        if($selected!==null){$min=(float)$selected['minimum'];$max=$selected['maximum'];$where='club_slug=? AND current_member=1 AND points>=?';$params=[$clubSlug,$min];if($max!==null){$where.=' AND points<?';$params[]=(float)$max;}$q=$a->prepare("SELECT COUNT(*) FROM p2k_an_player_totals WHERE {$where}");$q->execute($params);$total=(int)$q->fetchColumn();$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);$offset=($page-1)*$pageSize;
            $pageWhere=str_replace('club_slug=?','p.club_slug=?',$where);$q=$a->prepare("SELECT p.username_key,p.username,p.points,p.matches,p.games,p.wins,p.draws,p.losses,1+(SELECT COUNT(*) FROM p2k_an_player_totals x WHERE x.club_slug=p.club_slug AND x.current_member=1 AND (x.points>p.points OR (x.points=p.points AND x.username_key<p.username_key))) team_position FROM p2k_an_player_totals p WHERE {$pageWhere} ORDER BY p.points DESC,p.username_key ASC LIMIT {$pageSize} OFFSET {$offset}");$q->execute($params);$rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $i=>$r)$rows[]=['username'=>(string)$r['username'],'points'=>(float)$r['points'],'matches'=>(int)$r['matches'],'games'=>(int)$r['games'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'team_position'=>(int)$r['team_position'],'category_position'=>$offset+$i+1,'rank'=>$selected];
            $payload['selected_rank']=array_merge($selected,['members'=>$total]);$payload['members']=$rows;$payload['pagination']=['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$total,'total_pages'=>$pages];}
        return $payload;
    }

    /** Compatibility helper retained for older internal callers/tests; normal Hall/Profile reads no longer need it. */
    private function publicCurrentMemberRows(string $clubSlug): array
    {
        $q=$this->analytics()->prepare('SELECT username_key,username,points FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 ORDER BY points DESC,username_key ASC');$q->execute([$clubSlug]);return $q->fetchAll(PDO::FETCH_ASSOC)?:[];
    }

    private function hallRankForPoints(float $points): array
    {
        foreach ($this->hallRankDefinitions() as $definition) {
            if ($points >= (float)$definition['minimum']) {
                return $definition;
            }
        }
        return [
            'key' => 'unranked',
            'name' => 'Unranked',
            'minimum' => 0,
            'maximum' => 10,
            'image' => '../p2k-logo.jpg',
            'framed_image' => '../p2k-logo.jpg',
            'description' => 'Pawn rank begins at 10 Team Points.',
        ];
    }

    public static function dailyRankDefinitions(): array
    {
        return [
            ['key'=>'diamond-king','name'=>'Diamond King','minimum'=>10000,'maximum'=>null,'image'=>'16_Diamond_King.png','framed_image'=>'16_Diamond_King_10000_points.png','description'=>'The highest distinction, reserved for the club’s most prolific team competitors.'],
            ['key'=>'ruby-king','name'=>'Ruby King','minimum'=>8500,'maximum'=>10000,'image'=>'15_Ruby_King.png','framed_image'=>'15_Ruby_King_8500_points.png','description'=>'An elite rank recognizing an exceptional and sustained contribution to team matches.'],
            ['key'=>'sapphire-king','name'=>'Sapphire King','minimum'=>7000,'maximum'=>8500,'image'=>'14_Sapphire_King.png','framed_image'=>'14_Sapphire_King_7000_points.png','description'=>'A premier rank for players with a very large body of successful team participation.'],
            ['key'=>'emerald-king','name'=>'Emerald King','minimum'=>5500,'maximum'=>7000,'image'=>'13_Emerald_King.png','framed_image'=>'13_Emerald_King_5500_points.png','description'=>'A prestigious rank reflecting long-term strength and commitment to the team.'],
            ['key'=>'topaz-king','name'=>'Topaz King','minimum'=>4000,'maximum'=>5500,'image'=>'12_Topaz_King.png','framed_image'=>'12_Topaz_King_4000_points.png','description'=>'A senior rank for established players who have accumulated major team-match results.'],
            ['key'=>'amethyst-king','name'=>'Amethyst King','minimum'=>3000,'maximum'=>4000,'image'=>'11_Amethyst_King.png','framed_image'=>'11_Amethyst_King_3000_points.png','description'=>'A distinguished rank recognizing a substantial contribution across many team matches.'],
            ['key'=>'platinum-king','name'=>'Platinum King','minimum'=>2000,'maximum'=>3000,'image'=>'10_Platinum_King.png','framed_image'=>'10_Platinum_King_2000_points.png','description'=>'An advanced rank marking a strong, sustained record for Promote to King.'],
            ['key'=>'gold-king','name'=>'Gold King','minimum'=>1500,'maximum'=>2000,'image'=>'09_Gold_King.png','framed_image'=>'09_Gold_King_1500_points.png','description'=>'A high rank for consistently active and successful team competitors.'],
            ['key'=>'silver-king','name'=>'Silver King','minimum'=>1000,'maximum'=>1500,'image'=>'08_Silver_King.png','framed_image'=>'08_Silver_King_1000_points.png','description'=>'A seasoned rank for members with a significant Team Points record.'],
            ['key'=>'bronze-king','name'=>'Bronze King','minimum'=>500,'maximum'=>1000,'image'=>'07_Bronze_King.png','framed_image'=>'07_Bronze_King_500_points.png','description'=>'A recognised milestone for regular and productive team participation.'],
            ['key'=>'king','name'=>'King','minimum'=>250,'maximum'=>500,'image'=>'06_King.png','framed_image'=>'06_King_250_points.png','description'=>'A major progression milestone earned through repeated team-match contributions.'],
            ['key'=>'queen','name'=>'Queen','minimum'=>150,'maximum'=>250,'image'=>'05_Queen.png','framed_image'=>'05_Queen_150_points.png','description'=>'A strong intermediate rank demonstrating meaningful competitive participation.'],
            ['key'=>'rook','name'=>'Rook','minimum'=>100,'maximum'=>150,'image'=>'04_Rook.png','framed_image'=>'04_Rook_100_points.png','description'=>'A solid rank for members building a dependable Team Points record.'],
            ['key'=>'bishop','name'=>'Bishop','minimum'=>50,'maximum'=>100,'image'=>'03_Bishop.png','framed_image'=>'03_Bishop_50_points.png','description'=>'A developing rank earned through continued participation and results.'],
            ['key'=>'knight','name'=>'Knight','minimum'=>20,'maximum'=>50,'image'=>'02_Knight.png','framed_image'=>'02_Knight_20_points.png','description'=>'An early progression rank for members beginning to establish their team record.'],
            ['key'=>'pawn','name'=>'Pawn','minimum'=>10,'maximum'=>20,'image'=>'01_Pawn.png','framed_image'=>'01_Pawn_10_points.png','description'=>'The first Team Points rank, awarded from 10 points.'],
        ];
    }

    private function hallRankDefinitions(): array
    {
        return self::dailyRankDefinitions();
    }

    public function monthlyResults(
        string $clubSlug,
        string $startMonth,
        string $endMonth,
        bool $currentOnly,
        string $shape,
        string $memberSearch = '',
        string $sortBy = '',
        string $sortDir = '',
        ?int $limit = null
    ): array {
        [$start, $end, $from, $until] = $this->validatedMonthRange($startMonth, $endMonth);
        $groupMonth = $shape === 'monthly';
        if (!in_array($shape, ['totals', 'monthly'], true)) {
            throw new ApiException('shape must be totals or monthly.', 400, 'INVALID_RESULT_SHAPE');
        }
        $allowed = $groupMonth
            ? ['month','username','points','matches','games','wins','draws','losses']
            : ['username','points','matches','games','wins','draws','losses'];
        $defaultSort = $groupMonth ? 'month' : 'points';
        [$sortBy, $sortDir] = $this->validatedSort($sortBy, $sortDir, $allowed, $defaultSort, $groupMonth ? 'asc' : 'desc');
        $sortMap = [
            'month' => 'e.utc_month', 'username' => 'm.username', 'points' => 'points', 'matches' => 'matches',
            'games' => 'games', 'wins' => 'wins', 'draws' => 'draws', 'losses' => 'losses',
        ];
        $currentClause = $currentOnly ? 'AND m.current_member = 1' : '';
        $searchClause = $memberSearch !== '' ? 'AND m.username LIKE ?' : '';
        $monthSelect = $groupMonth ? "DATE_FORMAT(e.utc_month, '%Y-%m') AS month," : '';
        $monthGroup = $groupMonth ? ', e.utc_month' : '';
        $order = $sortMap[$sortBy] . ' ' . strtoupper($sortDir) . ', m.username_key ASC';
        if ($groupMonth && $sortBy !== 'month') {
            $order .= ', e.utc_month ASC';
        }
        $limitSql = $limit === null ? '' : ' LIMIT ' . (max(1, min(5000, $limit)) + 1);
        $sql = "SELECT {$monthSelect} m.username,
                       ROUND(COALESCE(SUM(e.points),0),1) AS points,
                       COUNT(e.game_url) AS games,
                       SUM(CASE WHEN e.points=1.0 THEN 1 ELSE 0 END) AS wins,
                       SUM(CASE WHEN e.points=0.5 THEN 1 ELSE 0 END) AS draws,
                       SUM(CASE WHEN e.points=0.0 THEN 1 ELSE 0 END) AS losses,
                       COUNT(DISTINCT e.match_id) AS matches
                FROM p2k_tp_members m
                LEFT JOIN p2k_tp_point_events e
                  ON e.club_slug=m.club_slug AND e.username_key=m.username_key
                 AND e.utc_month >= ? AND e.utc_month < ?
                WHERE m.club_slug=? {$currentClause} {$searchClause}
                GROUP BY m.username_key, m.username {$monthGroup}
                HAVING games > 0 OR NOT ?
                ORDER BY {$order}{$limitSql}";
        $params = [$from, $until, $clubSlug];
        if ($memberSearch !== '') {
            $params[] = '%' . $memberSearch . '%';
        }
        $params[] = $groupMonth ? 1 : 0;
        $query = $this->pdo->prepare($sql);
        $query->execute($params);
        $rows = $query->fetchAll();
        $truncated = $limit !== null && count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }
        return [
            'range' => ['start_month' => $start->format('Y-m'), 'end_month' => $end->format('Y-m')],
            'shape' => $groupMonth ? 'monthly' : 'totals',
            'current_members_only' => $currentOnly,
            'member_search' => $memberSearch,
            'sort' => ['column' => $sortBy, 'direction' => $sortDir],
            'truncated' => $truncated,
            'rows' => $rows,
        ];
    }

    public function recentJobItems(string $jobId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $query = $this->pdo->prepare(
            "SELECT id,item_type,item_key,status,attempts,available_at,locked_at,updated_at,last_error
             FROM p2k_tp_job_items WHERE job_id=? AND status IN ('retry','failed') ORDER BY updated_at DESC LIMIT {$limit}"
        );
        $query->execute([$jobId]);
        return $query->fetchAll();
    }

    public function eventRows(
        string $clubSlug,
        string $startMonth,
        string $endMonth,
        bool $currentOnly,
        string $memberSearch = '',
        string $sortBy = '',
        string $sortDir = '',
        ?int $limit = null
    ): array {
        [, , $from, $until] = $this->validatedMonthRange($startMonth, $endMonth);
        $allowed = ['username','match_id','game_end_utc','month','result_code','points','current_member'];
        [$sortBy, $sortDir] = $this->validatedSort($sortBy, $sortDir, $allowed, 'game_end_utc', 'asc');
        $sortMap = [
            'username' => 'e.username', 'match_id' => 'e.match_id', 'game_end_utc' => 'e.game_end_utc',
            'month' => 'e.utc_month', 'result_code' => 'e.result_code', 'points' => 'e.points',
            'current_member' => 'm.current_member',
        ];
        $currentClause = $currentOnly ? 'AND m.current_member=1' : '';
        $searchClause = $memberSearch !== '' ? 'AND m.username LIKE ?' : '';
        $limitSql = $limit === null ? '' : ' LIMIT ' . (max(1, min(5000, $limit)) + 1);
        $query = $this->pdo->prepare(
            "SELECT e.username,e.match_id,e.board_url,e.game_url,e.game_end_utc,DATE_FORMAT(e.utc_month,'%Y-%m') AS month,e.result_code,e.points,m.current_member
             FROM p2k_tp_point_events e
             JOIN p2k_tp_members m ON m.club_slug=e.club_slug AND m.username_key=e.username_key
             WHERE e.club_slug=? AND e.utc_month>=? AND e.utc_month<? {$currentClause} {$searchClause}
             ORDER BY {$sortMap[$sortBy]} " . strtoupper($sortDir) . ", e.username_key ASC, e.game_url ASC{$limitSql}"
        );
        $params = [$clubSlug, $from, $until];
        if ($memberSearch !== '') {
            $params[] = '%' . $memberSearch . '%';
        }
        $query->execute($params);
        $rows = $query->fetchAll();
        $truncated = $limit !== null && count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }
        return [
            'range' => ['start_month' => $startMonth, 'end_month' => $endMonth],
            'shape' => 'events',
            'current_members_only' => $currentOnly,
            'member_search' => $memberSearch,
            'sort' => ['column' => $sortBy, 'direction' => $sortDir],
            'truncated' => $truncated,
            'rows' => $rows,
        ];
    }

    private function insightSourceUpdatedAt(string $clubSlug): ?string
    {
        $latest=null;
        foreach([
            'SELECT MAX(last_verified_at) FROM p2k_tp_match_metadata WHERE club_slug=?',
            'SELECT MAX(updated_at) FROM p2k_tp_match_metadata WHERE club_slug=?',
            'SELECT MAX(verified_at) FROM p2k_tp_point_events WHERE club_slug=?',
        ] as $sql){$q=$this->pdo->prepare($sql);$q->execute([$clubSlug]);$v=$q->fetchColumn();if(is_string($v)&&$v!==''&&($latest===null||$v>$latest))$latest=$v;}
        return $latest;
    }

    /** Rebuild the durable daily fact table only when source tables changed. */
    private function refreshTeamInsightFacts(string $clubSlug): array
    {
        $analytics=$this->analytics();
        $q=$analytics->prepare('SELECT source_updated_at,refreshed_at,row_count,last_error FROM p2k_tp_insight_cache_state WHERE club_slug=?');
        $q->execute([$clubSlug]);$row=$q->fetch()?:[];
        return ['source_updated_at'=>$row['source_updated_at']??null,'refreshed_at'=>$row['refreshed_at']??null,'row_count'=>(int)($row['row_count']??0),'last_error'=>$row['last_error']??null,'refreshed'=>false,'database_role'=>'analytics','refresh_mode'=>'background_only'];
    }

    private static function insightDateRange(string $start, string $end): array
    {
        $rows=[];
        $cursor=new DateTimeImmutable($start,new DateTimeZone('UTC'));
        $last=new DateTimeImmutable($end,new DateTimeZone('UTC'));
        while ($cursor <= $last) { $rows[]=$cursor->format('Y-m-d'); $cursor=$cursor->modify('+1 day'); }
        return $rows;
    }

    private static function percentile(array $values, float $percentile): ?float
    {
        if ($values === []) return null;
        sort($values,SORT_NUMERIC);
        $position=(count($values)-1)*$percentile;
        $lower=(int)floor($position); $upper=(int)ceil($position);
        if ($lower===$upper) return (float)$values[$lower];
        $weight=$position-$lower;
        return (float)$values[$lower]*(1-$weight)+(float)$values[$upper]*$weight;
    }

    /** Shared scenario engine for Club Points. Core remains authoritative; forecasts are display-only analytics. */
    private function clubPointsForecastTo(string $clubSlug, string $today, int $actualPoints, string $endDate, bool $includeDayOfYear = false): array
    {
        $tz = new DateTimeZone('UTC');
        $todayObj = new DateTimeImmutable($today, $tz);
        $endObj = new DateTimeImmutable($endDate, $tz);
        if ($endObj < $todayObj) $endObj = $todayObj;
        $historyStart = $todayObj->modify('-89 days');

        $q = $this->analytics()->prepare(
            'SELECT activity_date,club_points FROM p2k_tp_insight_daily WHERE club_slug=? AND activity_date>=? AND activity_date<=? ORDER BY activity_date'
        );
        $q->execute([$clubSlug,$historyStart->format('Y-m-d'),$today]);
        $stored = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $stored[(string)$row['activity_date']] = (float)$row['club_points'];
        $daily = [];
        for ($d=$historyStart; $d<=$todayObj; $d=$d->modify('+1 day')) $daily[] = (float)($stored[$d->format('Y-m-d')] ?? 0.0);
        while (count($daily) < 90) array_unshift($daily,0.0);
        if (count($daily) > 90) $daily = array_slice($daily,-90);

        $blockTotals = [array_sum(array_slice($daily,0,30)),array_sum(array_slice($daily,30,30)),array_sum(array_slice($daily,60,30))];
        $blockRates = array_map(static fn(float $v): float => $v/30.0,$blockTotals);
        $rate90 = array_sum($daily)/90.0;
        $weightedRecent = 0.25*$blockRates[0] + 0.35*$blockRates[1] + 0.40*$blockRates[2];
        $mediumRate = max(0.0,0.50*$rate90 + 0.50*$weightedRecent);
        $denominator = max(1.0,$rate90,($blockRates[0]+$blockRates[1]+$blockRates[2])/3.0);
        $trend = ($blockRates[2]-$blockRates[0])/$denominator;
        $terminalAdjustment = max(-0.08,min(0.08,0.04*$trend));
        $remainingDays = max(0,(int)$todayObj->diff($endObj)->days);

        $low=[]; $medium=[]; $high=[];
        $lowTotal=(float)$actualPoints; $medTotal=(float)$actualPoints; $highTotal=(float)$actualPoints;
        $index=0;
        if ($remainingDays > 0) {
            $periodEnd = $endObj->modify('+1 day');
            foreach(new \DatePeriod($todayObj->modify('+1 day'),new \DateInterval('P1D'),$periodEnd) as $day){
                $index++;
                $fraction=$remainingDays>0?$index/$remainingDays:1.0;
                $curvedRate=max(0.0,$mediumRate*(1.0+$terminalAdjustment*$fraction));
                $lowRate=max(0.0,$curvedRate*0.90);
                $highRate=$curvedRate*1.10;
                $lowTotal+=$lowRate; $medTotal+=$curvedRate; $highTotal+=$highRate;
                $base=['date'=>$day->format('Y-m-d'),'value'=>(int)round($medTotal)];
                $lowRow=['date'=>$day->format('Y-m-d'),'value'=>(int)round($lowTotal)];
                $medRow=$base;
                $highRow=['date'=>$day->format('Y-m-d'),'value'=>(int)round($highTotal)];
                if ($includeDayOfYear) {
                    $doy=(int)$day->format('z')+1;
                    $lowRow['dayOfYear']=$doy; $medRow['dayOfYear']=$doy; $highRow['dayOfYear']=$doy;
                }
                $low[]=$lowRow; $medium[]=$medRow; $high[]=$highRow;
            }
        }
        $anchor=['date'=>$today,'value'=>$actualPoints];
        if ($includeDayOfYear) $anchor['dayOfYear']=(int)$todayObj->format('z')+1;
        array_unshift($low,$anchor); array_unshift($medium,$anchor); array_unshift($high,$anchor);
        return [
            'start_date'=>$today,'end_date'=>$endObj->format('Y-m-d'),'low'=>$low,'medium'=>$medium,'high'=>$high,
            'end_values'=>['low'=>(int)round($lowTotal),'medium'=>(int)round($medTotal),'high'=>(int)round($highTotal)],
            'method'=>[
                'history_days'=>90,
                'thirty_day_points'=>array_map(static fn(float $v): int => (int)round($v),$blockTotals),
                'thirty_day_daily_rates'=>array_map(static fn(float $v): float => round($v,2),$blockRates),
                'daily_rate_90'=>round($rate90,2),
                'medium_daily_rate'=>round($mediumRate,2),
                'terminal_curve_percent'=>round($terminalAdjustment*100,2),
                'confidence_band_percent'=>10,
                'inputs'=>'actual Club Points from the latest three 30-day calendar blocks; near-linear continuation with a capped +/-8% terminal trend bend and +/-10% probability bands'
            ]
        ];
    }

    /** Scenario forecast for current-year Club Points. */
    private function clubPointsForecast(string $clubSlug, string $today, int $actualPoints): array
    {
        $todayObj = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        $end = $todayObj->format('Y').'-12-31';
        $forecast = $this->clubPointsForecastTo($clubSlug,$today,$actualPoints,$end,true);
        $forecast['start_day']=(int)$todayObj->format('z')+1;
        $forecast['year_end']=$forecast['end_values'];
        unset($forecast['end_values']);
        return $forecast;
    }

    /** Same Club Points scenario model extended six calendar months from today. */
    private function clubPointsSixMonthForecast(string $clubSlug, string $today, int $actualPoints): array
    {
        $todayObj = new DateTimeImmutable($today, new DateTimeZone('UTC'));
        return $this->clubPointsForecastTo($clubSlug,$today,$actualPoints,$todayObj->modify('+6 months')->format('Y-m-d'),false);
    }

    /** Shared generation/range-keyed base for progressive Team Insights sections. */
    private function teamInsightsBase(string $clubSlug, ?string $startDate, ?string $endDate): array
    {
        $config=\p2k_tp_config();$storage=is_array($config['storage']??null)?$config['storage']:[];$cache=new ResponseCache($storage);
        $generation=$this->publicReadGenerationToken($clubSlug,false,false);$key='team-insights-base|'.$clubSlug.'|'.$generation.'|'.($startDate??'').'|'.($endDate??'');
        return $cache->remember($key,180,function()use($clubSlug,$startDate,$endDate){
            $cacheState=$this->refreshTeamInsightFacts($clubSlug);$analytics=$this->analytics();$coverageQuery=$analytics->prepare('SELECT MIN(activity_date),MAX(activity_date) FROM p2k_tp_insight_daily WHERE club_slug=?');$coverageQuery->execute([$clubSlug]);[$coverageStart,$coverageEnd]=$coverageQuery->fetch(PDO::FETCH_NUM)?:[null,null];$coverage=['start'=>$coverageStart?:null,'end'=>$coverageEnd?:null];$effectiveStart=$startDate??$coverage['start'];$effectiveEnd=$endDate??$coverage['end'];
            if($effectiveStart===null||$effectiveEnd===null)return ['coverage'=>$coverage,'effective_start'=>$effectiveStart,'effective_end'=>$effectiveEnd,'calendar'=>[],'filtered'=>[],'cache'=>$cacheState];
            $factsQuery=$analytics->prepare('SELECT activity_date,matches_started,matches_finished,boards_started,boards_finished,games_finished,unique_players,club_points FROM p2k_tp_insight_daily WHERE club_slug=? AND activity_date<=? ORDER BY activity_date');$factsQuery->execute([$clubSlug,$effectiveEnd]);$facts=[];foreach($factsQuery->fetchAll()?:[] as $row)$facts[(string)$row['activity_date']]=$row;
            $calendarStart=$coverage['start']??$effectiveStart;$calendar=[];$cumStarted=0;$cumFinished=0;$cumBoardsStarted=0;$cumBoardsFinished=0;$cumPoints=0;foreach(self::insightDateRange($calendarStart,$effectiveEnd) as $day){$fact=$facts[$day]??[];$started=(int)($fact['matches_started']??0);$finished=(int)($fact['matches_finished']??0);$boardsStarted=(int)($fact['boards_started']??0);$boardsFinished=(int)($fact['boards_finished']??0);$points=(int)($fact['club_points']??0);$cumStarted+=$started;$cumFinished+=$finished;$cumBoardsStarted+=$boardsStarted;$cumBoardsFinished+=$boardsFinished;$cumPoints+=$points;$calendar[]=['date'=>$day,'started'=>$started,'finished'=>$finished,'inProgress'=>max(0,$cumStarted-$cumFinished),'boardsStarted'=>$boardsStarted,'boardsFinished'=>$boardsFinished,'activeBoards'=>max(0,$cumBoardsStarted-$cumBoardsFinished),'games'=>(int)($fact['games_finished']??0),'uniquePlayers'=>(int)($fact['unique_players']??0),'points'=>$points,'cumulativePoints'=>$cumPoints];}
            $filtered=array_values(array_filter($calendar,static fn(array $r):bool=>$r['date']>=$effectiveStart&&$r['date']<=$effectiveEnd));
            return ['coverage'=>$coverage,'effective_start'=>$effectiveStart,'effective_end'=>$effectiveEnd,'calendar'=>$calendar,'filtered'=>$filtered,'cache'=>$cacheState];
        },900)['payload'];
    }

    /** Public aggregate data used by the Team Insights dashboard. */
    public function publicTeamInsights(string $clubSlug, ?string $startDate = null, ?string $endDate = null, string $section = 'all'): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);
        $section=strtolower(trim($section));
        if(!in_array($section,['all','summary','progression','mid','deep'],true))$section='all';
        $shared=$this->teamInsightsBase($clubSlug,$startDate,$endDate);$analytics=$this->analytics();$cache=$shared['cache']??[];$coverage=$shared['coverage']??['start'=>null,'end'=>null];$effectiveStart=$shared['effective_start']??null;$effectiveEnd=$shared['effective_end']??null;$calendar=$shared['calendar']??[];$filtered=$shared['filtered']??[];
        $base=['source'=>'database','section'=>$section,'coverage'=>$coverage,'range'=>['start'=>$effectiveStart,'end'=>$effectiveEnd],'cache'=>$cache,'shared_base_cache'=>true];
        if($effectiveStart===null||$effectiveEnd===null)return $base+['summary'=>[],'comparison'=>[],'graphs'=>[]];
        $periodSummary=static fn(array $rows):array=>['matches_started'=>(int)array_sum(array_column($rows,'started')),'matches_finished'=>(int)array_sum(array_column($rows,'finished')),'boards'=>(int)array_sum(array_column($rows,'boardsStarted')),'finished_boards'=>(int)array_sum(array_column($rows,'boardsFinished')),'games'=>(int)array_sum(array_column($rows,'games')),'club_points'=>(int)array_sum(array_column($rows,'points'))];
        $exclusive=(new DateTimeImmutable($effectiveEnd,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        if($section==='summary'||$section==='all'){
            $summary=$periodSummary($filtered);$uniqueQuery=$this->pdo->prepare('SELECT COUNT(DISTINCT e.username_key) FROM p2k_tp_point_events e WHERE e.club_slug=? AND e.game_end_utc>=? AND e.game_end_utc<? AND NOT EXISTS (SELECT 1 FROM p2k_tp_match_metadata vm WHERE vm.club_slug=e.club_slug AND vm.match_id=e.match_id AND vm.is_void=1)');$uniqueQuery->execute([$clubSlug,$effectiveStart.' 00:00:00',$exclusive]);$summary['unique_players']=(int)$uniqueQuery->fetchColumn();
            $startObject=new DateTimeImmutable($effectiveStart,new DateTimeZone('UTC'));$endObject=new DateTimeImmutable($effectiveEnd,new DateTimeZone('UTC'));$periodDays=max(1,(int)$startObject->diff($endObject)->days+1);$previousEnd=$startObject->modify('-1 day');$previousStart=$previousEnd->modify('-'.($periodDays-1).' days');$previousRange=['start'=>$previousStart->format('Y-m-d'),'end'=>$previousEnd->format('Y-m-d')];$previousRows=array_values(array_filter($calendar,static fn(array $r):bool=>$r['date']>=$previousRange['start']&&$r['date']<=$previousRange['end']));$previous=$periodSummary($previousRows);$prevExclusive=$previousEnd->modify('+1 day')->format('Y-m-d H:i:s');$uniqueQuery->execute([$clubSlug,$previousRange['start'].' 00:00:00',$prevExclusive]);$previous['unique_players']=(int)$uniqueQuery->fetchColumn();$comparison=[];foreach($summary as $key=>$value){$prior=(int)($previous[$key]??0);$comparison[$key]=['current'=>(int)$value,'previous'=>$prior,'change_percent'=>$prior===0?null:round(100*((int)$value-$prior)/$prior,1)];}
            $activity=[];$selectedStarted=0;$selectedFinished=0;$activityToday=gmdate('Y-m-d');foreach($filtered as $row){if((string)$row['date']>$activityToday)break;$selectedStarted+=$row['started'];$selectedFinished+=$row['finished'];$activity[]=['date'=>$row['date'],'started'=>$selectedStarted,'finished'=>$selectedFinished,'inProgress'=>$row['inProgress']];}
            $payload=$base+['previous_range'=>$previousRange,'summary'=>$summary,'comparison'=>$comparison,'graphs'=>['cumulativeActivity'=>$activity]];
            if($section==='summary')return $payload;
        } else $payload=$base;
        if($section==='progression'||$section==='all'){
            $today=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->format('Y-m-d');
            // v2.8.8.2: all-history score progression is independent of the selected comparison range.
            // It always starts on the first materialized Team Insights day and extends six months
            // beyond today using exactly the same scenario engine as the year-over-year forecast.
            $scoreStart=$coverage['start']??$effectiveStart;$scoreStored=[];$scoreQuery=$analytics->prepare('SELECT activity_date,club_points FROM p2k_tp_insight_daily WHERE club_slug=? AND activity_date>=? AND activity_date<=? ORDER BY activity_date');$scoreQuery->execute([$clubSlug,$scoreStart,$today]);foreach($scoreQuery->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$scoreStored[(string)$r['activity_date']]=(int)$r['club_points'];$scoreActual=[];$scoreDaily=[];$scoreTotal=0;foreach(self::insightDateRange($scoreStart,$today) as $day){$dailyPoints=(int)($scoreStored[$day]??0);$scoreTotal+=$dailyPoints;$scoreActual[]=['date'=>$day,'value'=>$scoreTotal];$scoreDaily[]=['date'=>$day,'value'=>$dailyPoints];}$scoreForecast=$this->clubPointsSixMonthForecast($clubSlug,$today,$scoreTotal);$scoreProgression=['actual'=>$scoreActual,'daily'=>$scoreDaily,'forecast'=>$scoreForecast,'first_date'=>$scoreStart,'today'=>$today,'forecast_end'=>$scoreForecast['end_date']??$today];
            $yearRows=[];foreach($filtered as $row){$year=substr($row['date'],0,4);$date=new DateTimeImmutable($row['date'],new DateTimeZone('UTC'));$yearRows[$year][]=[$row['date'],(int)$date->format('z')+1,$row['points']];}ksort($yearRows,SORT_NUMERIC);$yearly=[];$currentYear=(int)substr($today,0,4);$forecastMeta=['today'=>$today];foreach($yearRows as $year=>$rows){$total=0;$points=[];foreach($rows as [$date,$doy,$value]){if((int)$year===$currentYear&&$date>$today)continue;$total+=(int)$value;$points[]=['date'=>$date,'dayOfYear'=>$doy,'value'=>$total];}$item=['year'=>(int)$year,'points'=>$points];if((int)$year===$currentYear&&$points!==[]){$forecast=$this->clubPointsForecast($clubSlug,$today,(int)$points[array_key_last($points)]['value']);if($forecast!==[]){$item['forecast']=$forecast;$forecastMeta=array_merge($forecastMeta,['year_end'=>$forecast['year_end']??null,'method'=>$forecast['method']??null]);}}$yearly[]=$item;}
            $payload['forecast']=$forecastMeta;$payload['graphs']=array_merge($payload['graphs']??[],['scoreProgression'=>$scoreProgression,'yearlyComparison'=>$yearly,'dailyBoards'=>array_map(static fn(array $r):array=>['date'=>$r['date'],'started'=>$r['boardsStarted'],'finished'=>$r['boardsFinished'],'active'=>$r['activeBoards']],array_values(array_filter($filtered,static fn(array $r):bool=>(string)$r['date']<=$today)))]);if($section==='progression')return $payload;
        }
        $monthMap=[];foreach($filtered as $row){$month=substr($row['date'],0,7);if(!isset($monthMap[$month]))$monthMap[$month]=['month'=>$month,'points'=>0,'boards'=>0,'finished'=>0,'active_players'=>0];$monthMap[$month]['points']+=$row['points'];$monthMap[$month]['boards']+=$row['boardsStarted'];$monthMap[$month]['finished']+=$row['finished'];}ksort($monthMap);
        if($section==='mid'||$section==='all'){
            $where="m.club_slug=? AND m.status='finished' AND m.is_void=0 AND m.end_time>=? AND m.end_time<?";$params=[$clubSlug,$effectiveStart.' 00:00:00',$exclusive];$outcomeQuery=$this->pdo->prepare("SELECT s.result,COUNT(*) total FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id WHERE {$where} GROUP BY s.result");$outcomeQuery->execute($params);$outcomes=['win'=>0,'draw'=>0,'loss'=>0];foreach($outcomeQuery->fetchAll()?:[] as $r)if(isset($outcomes[$r['result']]))$outcomes[$r['result']]=(int)$r['total'];$sizeQuery=$this->pdo->prepare("SELECT CASE WHEN m.board_count<10 THEN '1–9' WHEN m.board_count<25 THEN '10–24' WHEN m.board_count<50 THEN '25–49' WHEN m.board_count<100 THEN '50–99' WHEN m.board_count<200 THEN '100–199' ELSE '200+' END label,COUNT(*) total,MIN(m.board_count) ordering FROM p2k_tp_match_metadata m WHERE {$where} GROUP BY label ORDER BY ordering");$sizeQuery->execute($params);$boardSizes=array_map(static fn(array $r):array=>['label'=>(string)$r['label'],'count'=>(int)$r['total']],$sizeQuery->fetchAll()?:[]);$sizeStatsQuery=$this->pdo->prepare("SELECT COUNT(*) n,ROUND(AVG(m.board_count),1) mean_boards FROM p2k_tp_match_metadata m WHERE {$where}");$sizeStatsQuery->execute($params);$sizeStats=$sizeStatsQuery->fetch(PDO::FETCH_ASSOC)?:[];$sizeCount=(int)($sizeStats['n']??0);$sizeMedian=null;if($sizeCount>0){$sizeOffset=intdiv($sizeCount-1,2);$sizeTake=$sizeCount%2===0?2:1;$medianSql="SELECT m.board_count FROM p2k_tp_match_metadata m WHERE {$where} ORDER BY m.board_count LIMIT {$sizeTake} OFFSET {$sizeOffset}";$medianQ=$this->pdo->prepare($medianSql);$medianQ->execute($params);$medianVals=array_map('floatval',$medianQ->fetchAll(PDO::FETCH_COLUMN)?:[]);if($medianVals)$sizeMedian=round(array_sum($medianVals)/count($medianVals),1);}$boardSizeStats=['mean'=>$sizeStats['mean_boards']===null?null:(float)$sizeStats['mean_boards'],'median'=>$sizeMedian,'matches'=>$sizeCount];$payload['outcomes']=$outcomes;$payload['boardSizes']=$boardSizes;$payload['boardSizeStats']=$boardSizeStats;$payload['graphs']=array_merge($payload['graphs']??[],['outcomes'=>$outcomes,'boardSizes'=>$boardSizes,'boardSizeStats'=>$boardSizeStats,'monthlyPoints'=>array_values(array_map(static fn(array $r):array=>['month'=>$r['month'],'value'=>$r['points']],$monthMap))]);if($section==='mid')return $payload;
        }
        if($section==='deep'||$section==='all'){
            $rolling=[];$window=[];foreach($calendar as $row){$window[]=$row;while($window&&(new DateTimeImmutable($row['date']))->diff(new DateTimeImmutable($window[0]['date']))->days>=30)array_shift($window);if($row['date']>=$effectiveStart)$rolling[]=['date'=>$row['date'],'matches_started'=>(int)array_sum(array_column($window,'started')),'matches_finished'=>(int)array_sum(array_column($window,'finished')),'boards'=>(int)array_sum(array_column($window,'boardsStarted')),'club_points'=>(int)array_sum(array_column($window,'points'))];}
            $monthlyPlayersQuery=$this->pdo->prepare("SELECT DATE_FORMAT(e.game_end_utc,'%Y-%m') month,COUNT(DISTINCT e.username_key) players FROM p2k_tp_point_events e WHERE e.club_slug=? AND e.game_end_utc>=? AND e.game_end_utc<? AND NOT EXISTS (SELECT 1 FROM p2k_tp_match_metadata vm WHERE vm.club_slug=e.club_slug AND vm.match_id=e.match_id AND vm.is_void=1) GROUP BY month ORDER BY month");$monthlyPlayersQuery->execute([$clubSlug,$effectiveStart.' 00:00:00',$exclusive]);foreach($monthlyPlayersQuery->fetchAll()?:[] as $row){$month=(string)$row['month'];if(!isset($monthMap[$month]))$monthMap[$month]=['month'=>$month,'points'=>0,'boards'=>0,'finished'=>0,'active_players'=>0];$monthMap[$month]['active_players']=(int)$row['players'];}ksort($monthMap);
            $concentrationQuery=$this->pdo->prepare('SELECT e.username_key,SUM(e.points) points FROM p2k_tp_point_events e WHERE e.club_slug=? AND e.game_end_utc>=? AND e.game_end_utc<? AND NOT EXISTS (SELECT 1 FROM p2k_tp_match_metadata vm WHERE vm.club_slug=e.club_slug AND vm.match_id=e.match_id AND vm.is_void=1) GROUP BY e.username_key ORDER BY points DESC');$concentrationQuery->execute([$clubSlug,$effectiveStart.' 00:00:00',$exclusive]);$playerPoints=array_map('floatval',$concentrationQuery->fetchAll(PDO::FETCH_COLUMN,1)?:[]);$totalPoints=array_sum($playerPoints);$concentration=[];foreach([10,25,50] as $percent){$count=max(1,(int)ceil(count($playerPoints)*$percent/100));$share=$totalPoints>0?round(100*array_sum(array_slice($playerPoints,0,$count))/$totalPoints,1):0;$concentration[]=['top_percent'=>$percent,'players'=>$count,'activity_share'=>$share];}$payload['concentration']=$concentration;$payload['graphs']=array_merge($payload['graphs']??[],['rolling30'=>$rolling,'monthlyUniquePlayers'=>array_values(array_map(static fn(array $r):array=>['month'=>$r['month'],'value'=>$r['active_players']],$monthMap))]);if($section==='deep')return $payload;
        }
        return $payload;
    }


    /**
     * Return board-position keyed ratings. A board number supplied by Chess.com is
     * preferred; otherwise the lineup order is the board position. Missing/unrated
     * players remain absent so a paired intersection cannot silently shift boards.
     */
    private function teamRatingsByBoard(array $team): array
    {
        $players = $team['players'] ?? $team['members'] ?? [];
        if (!is_array($players)) return [];
        $ratings = [];
        foreach (array_values($players) as $index => $player) {
            if (!is_array($player)) continue;
            $rating = $player['rating'] ?? $player['elo'] ?? null;
            if (!is_numeric($rating) || (int)$rating <= 0) continue;
            $boardRaw = $player['board'] ?? $player['board_no'] ?? $player['board_number'] ?? ($index + 1);
            $board = is_numeric($boardRaw) && (int)$boardRaw > 0 ? (int)$boardRaw : ($index + 1);
            $ratings[$board] = (int)$rating;
        }
        ksort($ratings, SORT_NUMERIC);
        return $ratings;
    }

    /**
     * Rating invariant used by Opponent Insights: both averages are derived from
     * exactly the same board positions, and only positions where both ratings are
     * valid participate. The returned count is persisted as rated_board_count.
     */
    private function pairedTeamAverageRatings(array $our, array $opponent): array
    {
        $ours = $this->teamRatingsByBoard($our);
        $theirs = $this->teamRatingsByBoard($opponent);
        $boards = array_values(array_intersect(array_keys($ours), array_keys($theirs)));
        if ($boards === []) return ['p2k' => null, 'opponent' => null, 'count' => 0];
        $ourTotal = 0; $theirTotal = 0;
        foreach ($boards as $board) { $ourTotal += $ours[$board]; $theirTotal += $theirs[$board]; }
        $count = count($boards);
        return [
            'p2k' => (int)round($ourTotal / $count),
            'opponent' => (int)round($theirTotal / $count),
            'count' => $count,
        ];
    }

    public function upsertMatchMetadata(string $clubSlug, int $matchId, array $match, string $source = 'unknown'): bool
    {
        if ($matchId <= 0) return false;
        $teams = is_array($match['teams'] ?? null) ? $match['teams'] : [];
        $our = null;
        $opponent = null;
        foreach ($teams as $key => $team) {
            if (!is_array($team)) continue;
            $slug = $this->teamSlugFromPayload($team, is_string($key) ? $key : '');
            if ($slug === strtolower($clubSlug)) $our = $team;
            elseif ($opponent === null) $opponent = $team;
        }
        if ($our === null) return false;
        if ($opponent === null) {
            foreach ($teams as $key => $team) {
                if (!is_array($team) || $team === $our) continue;
                $opponent = $team;
                break;
            }
        }
        $opponent = is_array($opponent) ? $opponent : [];
        $opponentSlug = $this->teamSlugFromPayload($opponent, 'unknown-opponent-' . $matchId);
        $opponentName = trim((string)($opponent['name'] ?? $opponent['club_name'] ?? str_replace('-', ' ', $opponentSlug)));
        if ($opponentName === '') $opponentName = str_replace('-', ' ', $opponentSlug);
        $opponentUrl = self::chessClubHumanUrl((string)($opponent['url'] ?? $opponent['club_url'] ?? $opponent['@id'] ?? ''), $opponentSlug);
        $status = strtolower(trim((string)($match['status'] ?? 'unknown')));
        $status = match ($status) {
            'registered', 'registration' => 'registered',
            'in_progress', 'ongoing', 'started' => 'in_progress',
            'finished', 'complete', 'completed' => 'finished',
            default => $status === '' ? 'unknown' : $status,
        };
        $boardCount = max(0, (int)($match['boards'] ?? $match['board_count'] ?? 0));
        $ourScore = (float)($our['score'] ?? 0);
        $opponentScore = (float)($opponent['score'] ?? 0);
        $start = $this->timestampToSql($match['start_time'] ?? null);
        $end = $this->timestampToSql($match['end_time'] ?? null);
        $matchUrl = trim((string)($match['url'] ?? $match['@id'] ?? ''));
        $name = trim((string)($match['name'] ?? ('Match ' . $matchId)));
        $rules = trim((string)($match['rules'] ?? $match['rule'] ?? ''));
        $timeControl = trim((string)($match['time_control'] ?? $match['timeControl'] ?? ''));
        $isLeague = preg_match('/(^|[^A-Z0-9])(1WL|TCMAC|KOTML|TMCL|WKCL|PCL|CW)([^A-Z0-9]|$)/i', $name) === 1 ? 1 : 0;
        $pairedRatings = $this->pairedTeamAverageRatings($our, $opponent);
        $p2kAvgRating = $pairedRatings['p2k'];
        $opponentAvgRating = $pairedRatings['opponent'];
        $ratedBoardCount = (int)$pairedRatings['count'];
        $settings = is_array($match['settings'] ?? null) ? $match['settings'] : [];
        $maxRatingRaw = $settings['max_rating'] ?? $settings['maxRating'] ?? $match['max_rating'] ?? null;
        $maxRating = is_numeric($maxRatingRaw) && (int)$maxRatingRaw > 0 && (int)$maxRatingRaw < 10000 ? (int)$maxRatingRaw : null;

        $oldQuery=$this->pdo->prepare('SELECT match_name,match_url,status,rules,time_control,is_league,start_time,end_time,board_count,p2k_score,opponent_score,p2k_avg_rating,opponent_avg_rating,rated_board_count,max_rating,opponent_slug,opponent_name,opponent_url FROM p2k_tp_match_metadata WHERE club_slug=? AND match_id=? LIMIT 1');
        $oldQuery->execute([$clubSlug,$matchId]);$old=$oldQuery->fetch(PDO::FETCH_ASSOC);
        $analyticsChanged=!is_array($old)
            || (string)($old['status']??'')!==$status
            || (int)($old['board_count']??0)!==$boardCount
            || abs((float)($old['p2k_score']??0)-$ourScore)>=0.001
            || abs((float)($old['opponent_score']??0)-$opponentScore)>=0.001
            || (string)($old['match_name']??'')!==$name
            || (string)($old['rules']??'')!==$rules
            || (string)($old['time_control']??'')!==$timeControl
            || (int)($old['is_league']??0)!==$isLeague
            || ($start!==null && (string)($old['start_time']??'')!==$start)
            || ($end!==null && (string)($old['end_time']??'')!==$end)
            || (string)($old['opponent_slug']??'')!==$opponentSlug
            || (string)($old['opponent_name']??'')!==$opponentName
            || ($p2kAvgRating!==null && (int)($old['p2k_avg_rating']??0)!==$p2kAvgRating)
            || ($opponentAvgRating!==null && (int)($old['opponent_avg_rating']??0)!==$opponentAvgRating)
            || (int)($old['rated_board_count']??0)!==$ratedBoardCount
            || ($maxRating!==null && (int)($old['max_rating']??0)!==$maxRating);

        $query = $this->pdo->prepare(
            "INSERT INTO p2k_tp_match_metadata(
                club_slug,match_id,match_name,match_url,status,rules,time_control,is_league,start_time,end_time,board_count,p2k_score,opponent_score,p2k_avg_rating,opponent_avg_rating,rated_board_count,max_rating,
                opponent_slug,opponent_name,opponent_url,discovery_source,last_verified_at,first_discovered_at
             ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE match_name=VALUES(match_name),match_url=VALUES(match_url),status=VALUES(status),
                rules=COALESCE(NULLIF(VALUES(rules),''),rules),time_control=COALESCE(NULLIF(VALUES(time_control),''),time_control),is_league=VALUES(is_league),
                start_time=COALESCE(VALUES(start_time),start_time),end_time=COALESCE(VALUES(end_time),end_time),
                board_count=GREATEST(board_count,VALUES(board_count)),p2k_score=VALUES(p2k_score),opponent_score=VALUES(opponent_score),
                p2k_avg_rating=VALUES(p2k_avg_rating),opponent_avg_rating=VALUES(opponent_avg_rating),rated_board_count=VALUES(rated_board_count),max_rating=COALESCE(VALUES(max_rating),max_rating),
                opponent_slug=VALUES(opponent_slug),opponent_name=VALUES(opponent_name),opponent_url=VALUES(opponent_url),
                discovery_source=VALUES(discovery_source),last_verified_at=UTC_TIMESTAMP()"
        );
        $query->execute([$clubSlug,$matchId,$name,$matchUrl,$status,$rules!==''?$rules:null,$timeControl!==''?$timeControl:null,$isLeague,$start,$end,$boardCount,$ourScore,$opponentScore,$p2kAvgRating,$opponentAvgRating,$ratedBoardCount,$maxRating,$opponentSlug,$opponentName,$opponentUrl,$source]);
        $opponentQuery = $this->pdo->prepare(
            "INSERT INTO p2k_tp_opponents(club_slug,opponent_slug,display_name,club_url,disabled,first_seen_at,last_seen_at,last_checked_at,last_error)
             VALUES(?,?,?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL,NULL)
             ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),club_url=VALUES(club_url),last_seen_at=UTC_TIMESTAMP()"
        );
        $opponentQuery->execute([$clubSlug,$opponentSlug,$opponentName,$opponentUrl]);
        if($analyticsChanged)$this->touchCoreGeneration($clubSlug);
        return true;
    }

    public function publicRecentMatches(string $clubSlug, int $hours = 24): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug); $hours=max(1,min(168,$hours));
        $cutoff=gmdate('Y-m-d H:i:s', time() - ($hours * 3600));
        $q=$this->pdo->prepare("SELECT match_id,match_name AS name,match_url AS url,status,rules,time_control,is_league,start_time,end_time,board_count,p2k_score,opponent_score,opponent_name,opponent_slug,max_rating,first_discovered_at,last_verified_at FROM p2k_tp_match_metadata WHERE club_slug=? AND first_discovered_at>=? ORDER BY first_discovered_at DESC,match_id DESC");
        $q->execute([$clubSlug,$cutoff]);
        return array_map(static fn(array $r): array => [
            'match_id'=>(int)$r['match_id'],'name'=>(string)$r['name'],'url'=>$r['url'],'status'=>(string)$r['status'],'rules'=>$r['rules'],'time_control'=>$r['time_control'],'is_league'=>(bool)$r['is_league'],
            'start_time'=>$r['start_time'],'end_time'=>$r['end_time'],'boards'=>(int)$r['board_count'],'our_score'=>(float)$r['p2k_score'],'their_score'=>(float)$r['opponent_score'],'opponent_name'=>$r['opponent_name'],'opponent_slug'=>$r['opponent_slug'],'max_rating'=>$r['max_rating']===null?null:(int)$r['max_rating'],'first_discovered_at'=>$r['first_discovered_at'],'last_verified_at'=>$r['last_verified_at']
        ],$q->fetchAll() ?: []);
    }

    public function publicMatchInsights(string $clubSlug, array $options = []): array
    {
        $clubSlug = $this->resolveDataClubSlug($clubSlug);
        $page = max(1, (int)($options['page'] ?? 1));
        $pageSize = max(10, min(100, (int)($options['page_size'] ?? 25)));
        $search = trim((string)($options['search'] ?? ''));
        if (strlen($search) > 120) $search = substr($search, 0, 120);
        $filter = strtolower(trim((string)($options['filter'] ?? 'all')));
        if (!in_array($filter, ['all','registered','in_progress','finished','win','draw','loss'], true)) $filter = 'all';
        $sort = strtolower(trim((string)($options['sort'] ?? 'start_time')));
        $direction = strtolower((string)($options['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $includeSummary = !array_key_exists('include_summary', $options) || (bool)$options['include_summary'];
        $sortColumns = [
            'name' => 'm.match_name',
            'opponent_name' => 'opponent_name',
            'status' => 'm.status',
            'start_time' => 'm.start_time',
            'boards' => 'm.board_count',
            'our_score' => 'm.p2k_score',
            'their_score' => 'm.opponent_score',
            'result' => 's.result',
            'competition_points' => 's.competition_points',
            'duration_days' => 'duration_days',
        ];
        $sortSql = $sortColumns[$sort] ?? $sortColumns['start_time'];
        $joins = " LEFT JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
                   LEFT JOIN p2k_tp_opponent_aliases a ON a.club_slug=m.club_slug AND a.alias_slug=m.opponent_slug
                   LEFT JOIN p2k_tp_opponents o ON o.club_slug=m.club_slug AND o.opponent_slug=COALESCE(a.canonical_slug,m.opponent_slug)";
        $where = ['m.club_slug=?','m.is_void=0'];
        $params = [$clubSlug];
        if ($search !== '') {
            $needle = '%' . $search . '%';
            $where[] = "(m.match_name LIKE ? OR m.opponent_name LIKE ? OR m.opponent_slug LIKE ? OR COALESCE(o.display_name,'') LIKE ? OR CAST(m.match_id AS CHAR) LIKE ?)";
            array_push($params, $needle, $needle, $needle, $needle, $needle);
        }
        if (in_array($filter, ['registered','in_progress','finished'], true)) {
            $where[] = 'm.status=?';
            $params[] = $filter;
        } elseif (in_array($filter, ['win','draw','loss'], true)) {
            $where[] = "m.status='finished' AND s.result=?";
            $params[] = $filter;
        }
        $whereSql = implode(' AND ', $where);
        $countQuery = $this->pdo->prepare("SELECT COUNT(*) FROM p2k_tp_match_metadata m {$joins} WHERE {$whereSql}");
        $countQuery->execute($params);
        $totalRows = (int)$countQuery->fetchColumn();
        $pages = max(1, (int)ceil($totalRows / $pageSize));
        $page = min($page, $pages);
        $offset = ($page - 1) * $pageSize;
        $rowSql = "SELECT m.match_id,m.match_name,m.match_url,m.status,m.rules,m.time_control,m.is_league,m.start_time,m.end_time,m.board_count,
                          m.p2k_score,m.opponent_score,m.opponent_slug,
                          COALESCE(o.display_name,m.opponent_name,m.opponent_slug) AS opponent_name,
                          COALESCE(o.club_url,m.opponent_url) AS opponent_url,
                          s.result,s.competition_points,s.finalized_at,
                          CASE WHEN m.start_time IS NOT NULL AND m.end_time IS NOT NULL
                               THEN TIMESTAMPDIFF(HOUR,m.start_time,m.end_time)/24 ELSE NULL END AS duration_days
                   FROM p2k_tp_match_metadata m {$joins}
                   WHERE {$whereSql}
                   ORDER BY {$sortSql} {$direction},m.match_id {$direction}
                   LIMIT {$pageSize} OFFSET {$offset}";
        $query = $this->pdo->prepare($rowSql);
        $query->execute($params);
        $rows = array_map(static function (array $row): array {
            return [
                'match_id'=>(int)$row['match_id'],
                'name'=>(string)$row['match_name'],
                'url'=>(string)($row['match_url'] ?? ''),
                'status'=>(string)$row['status'],
                'rules'=>$row['rules'] !== null ? (string)$row['rules'] : null,
                'time_control'=>$row['time_control'] !== null ? (string)$row['time_control'] : null,
                'is_league'=>(bool)$row['is_league'],
                'start_time'=>$row['start_time'] !== null ? (string)$row['start_time'] : null,
                'end_time'=>$row['end_time'] !== null ? (string)$row['end_time'] : null,
                'boards'=>(int)$row['board_count'],
                'our_score'=>(float)$row['p2k_score'],
                'their_score'=>(float)$row['opponent_score'],
                'opponent_slug'=>(string)($row['opponent_slug'] ?? ''),
                'opponent_name'=>(string)($row['opponent_name'] ?? ''),
                'opponent_url'=>(string)($row['opponent_url'] ?? ''),
                'result'=>$row['result'] !== null ? (string)$row['result'] : null,
                'competition_points'=>(int)($row['competition_points'] ?? 0),
                'duration_days'=>$row['duration_days'] === null ? null : round((float)$row['duration_days'], 1),
                'finalized_at'=>$row['finalized_at'] !== null ? (string)$row['finalized_at'] : null,
            ];
        }, $query->fetchAll() ?: []);
        $result = [
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total_rows' => $totalRows,
                'total_pages' => $pages,
            ],
        ];
        if (!$includeSummary) return $result;

        $summaryQuery = $this->pdo->prepare(
            "SELECT COUNT(*) AS different_matches,
                    SUM(m.status='registered') AS registered,
                    SUM(m.status='in_progress') AS ongoing,
                    SUM(m.status='finished') AS finished,
                    SUM(m.status='finished' AND s.result='win') AS wins,
                    SUM(m.status='finished' AND s.result='draw') AS draws,
                    SUM(m.status='finished' AND s.result='loss') AS losses,
                    COALESCE(SUM(CASE WHEN m.status='finished' THEN s.competition_points ELSE 0 END),0) AS competition_points,
                    ROUND(AVG(CASE WHEN m.status='finished' THEN m.board_count END),1) AS average_boards,
                    ROUND(AVG(CASE WHEN m.status='finished' AND v.match_id IS NULL AND m.start_time IS NOT NULL AND m.end_time>m.start_time
                                   THEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400 END),1) AS average_duration_days,
                    SUM(m.status='finished' AND v.match_id IS NULL AND m.start_time IS NOT NULL AND m.end_time>m.start_time) AS duration_count
             FROM p2k_tp_match_metadata m
             LEFT JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
             LEFT JOIN p2k_tp_void_matches v ON v.club_slug=m.club_slug AND v.match_id=m.match_id
             WHERE m.club_slug=? AND m.is_void=0"
        );
        $summaryQuery->execute([$clubSlug]);
        $summary = $summaryQuery->fetch() ?: [];
        $durationCount = (int)($summary['duration_count'] ?? 0);
        $median = null;
        if ($durationCount > 0) {
            $middle = intdiv($durationCount - 1, 2);
            $take = $durationCount % 2 === 0 ? 2 : 1;
            $medianQuery = $this->pdo->prepare(
                "SELECT TIMESTAMPDIFF(HOUR,start_time,end_time)/24 AS duration_days
                 FROM p2k_tp_match_metadata m
                 WHERE club_slug=? AND status='finished' AND is_void=0 AND start_time IS NOT NULL AND end_time>start_time
                   AND NOT EXISTS (SELECT 1 FROM p2k_tp_void_matches v WHERE v.club_slug=m.club_slug AND v.match_id=m.match_id)
                 ORDER BY duration_days
                 LIMIT {$take} OFFSET {$middle}"
            );
            $medianQuery->execute([$clubSlug]);
            $values = array_map('floatval', $medianQuery->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($values !== []) $median = round(array_sum($values) / count($values), 1);
        }
        $trendQuery = $this->pdo->prepare(
            "SELECT DATE_FORMAT(COALESCE(start_time,end_time),'%Y-%m') AS month,
                    SUM(status='registered') AS registered,
                    SUM(status='in_progress') AS ongoing,
                    SUM(status='finished') AS finished,
                    SUM(CASE WHEN status='finished' THEN board_count ELSE 0 END) AS boards,
                    ROUND(AVG(CASE WHEN status='finished' AND board_count>0 THEN board_count END),1) AS average_boards
             FROM p2k_tp_match_metadata
             WHERE club_slug=? AND is_void=0 AND COALESCE(start_time,end_time) IS NOT NULL
             GROUP BY DATE_FORMAT(COALESCE(start_time,end_time),'%Y-%m')
             ORDER BY month DESC LIMIT 36"
        );
        $trendQuery->execute([$clubSlug]);
        $result['summary'] = [
            'different_matches'=>(int)($summary['different_matches'] ?? 0),
            'registered'=>(int)($summary['registered'] ?? 0),
            'ongoing'=>(int)($summary['ongoing'] ?? 0),
            'finished'=>(int)($summary['finished'] ?? 0),
            'wins'=>(int)($summary['wins'] ?? 0),
            'draws'=>(int)($summary['draws'] ?? 0),
            'losses'=>(int)($summary['losses'] ?? 0),
            'competition_points'=>(int)($summary['competition_points'] ?? 0),
            'average_boards'=>$summary['average_boards'] === null ? 0 : (float)$summary['average_boards'],
            'average_duration_days'=>$summary['average_duration_days'] === null ? null : (float)$summary['average_duration_days'],
            'median_duration_days'=>$median,
        ];
        $result['trend'] = array_reverse(array_map(static fn(array $row): array => [
            'month'=>(string)$row['month'],
            'registered'=>(int)$row['registered'],
            'ongoing'=>(int)$row['ongoing'],
            'finished'=>(int)$row['finished'],
            'boards'=>(int)$row['boards'],
        ], $trendQuery->fetchAll() ?: []));

        $sizeQuery = $this->pdo->prepare(
            "SELECT CASE WHEN m.board_count<10 THEN '1–9' WHEN m.board_count<25 THEN '10–24' WHEN m.board_count<50 THEN '25–49'
                         WHEN m.board_count<100 THEN '50–99' WHEN m.board_count<200 THEN '100–199' ELSE '200+' END AS label,
                    COUNT(*) AS matches,SUM(s.result='win') AS wins,SUM(s.result='draw') AS draws,SUM(s.result='loss') AS losses,
                    ROUND(AVG(m.board_count),1) AS average_boards
             FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
             WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 GROUP BY label
             ORDER BY MIN(m.board_count)"
        );
        $sizeQuery->execute([$clubSlug]);
        $result['analytics']['results_by_size'] = array_map(static fn(array $row): array => [
            'label'=>(string)$row['label'],'matches'=>(int)$row['matches'],'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses'],
            'average_boards'=>(float)$row['average_boards'],'win_rate'=>(int)$row['matches']?round(100*(int)$row['wins']/(int)$row['matches'],1):0,
        ], $sizeQuery->fetchAll() ?: []);

        $durationTrend = $this->pdo->prepare(
            "SELECT DATE_FORMAT(m.end_time,'%Y-%m') AS month,ROUND(AVG(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400),1) AS average_days,
                    ROUND(MIN(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400),1) AS minimum_days,
                    ROUND(MAX(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400),1) AS maximum_days,COUNT(*) AS matches
             FROM p2k_tp_match_metadata m LEFT JOIN p2k_tp_void_matches v ON v.club_slug=m.club_slug AND v.match_id=m.match_id
             WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 AND v.match_id IS NULL AND m.start_time IS NOT NULL AND m.end_time>m.start_time
             GROUP BY month ORDER BY month DESC LIMIT 36"
        );
        $durationTrend->execute([$clubSlug]);
        $result['analytics']['duration_trend'] = array_reverse(array_map(static fn(array $row): array => [
            'month'=>(string)$row['month'],'average_duration_days'=>(float)$row['average_days'],'minimum_days'=>(float)$row['minimum_days'],'maximum_days'=>(float)$row['maximum_days'],'matches'=>(int)$row['matches'],
        ], $durationTrend->fetchAll() ?: []));

        $categoryQuery = $this->pdo->prepare(
            "SELECT CASE WHEN m.is_league=1 THEN 'League' ELSE 'Friendly' END AS category,
                    COUNT(*) AS matches,SUM(s.result='win') AS wins,SUM(s.result='draw') AS draws,SUM(s.result='loss') AS losses,
                    COALESCE(SUM(s.competition_points),0) AS club_points
             FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
             WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 GROUP BY category"
        );
        $categoryQuery->execute([$clubSlug]);
        $result['analytics']['categories'] = array_map(static fn(array $row): array => [
            'category'=>(string)$row['category'],'matches'=>(int)$row['matches'],'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses'],
            'club_points'=>(int)$row['club_points'],'win_rate'=>(int)$row['matches']?round(100*(int)$row['wins']/(int)$row['matches'],1):0,
        ], $categoryQuery->fetchAll() ?: []);

        $dimensionQuery = $this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(m.rules),''),'Unknown') AS label,COUNT(*) AS matches FROM p2k_tp_match_metadata m WHERE m.club_slug=? AND m.is_void=0 GROUP BY label ORDER BY matches DESC,label");
        $dimensionQuery->execute([$clubSlug]);
        $result['analytics']['rules_distribution'] = array_map(static function (array $row): array {
            $raw = strtolower(trim((string)$row['label']));
            $label = match ($raw) {
                'chess', 'standard' => 'Standard',
                'chess960', '960' => 'Chess960',
                default => $raw === '' || $raw === 'unknown' ? 'Unknown' : (string)$row['label'],
            };
            return ['label'=>$label,'matches'=>(int)$row['matches']];
        },$dimensionQuery->fetchAll() ?: []);
        $dimensionQuery = $this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(m.time_control),''),'Unknown') AS label,COUNT(*) AS matches FROM p2k_tp_match_metadata m WHERE m.club_slug=? AND m.is_void=0 GROUP BY label ORDER BY matches DESC,label");
        $dimensionQuery->execute([$clubSlug]);
        $result['analytics']['time_control_distribution'] = array_map(static function (array $row): array {
            $raw = trim((string)$row['label']);
            $label = $raw;
            if (preg_match('/^1\/(\d+)$/',$raw,$matches) === 1) {
                $days = (int)$matches[1] / 86400;
                $label = rtrim(rtrim(number_format($days,2,'.',''),'0'),'.') . ($days === 1.0 ? ' day / move' : ' days / move');
            } elseif ($raw === '' || strtolower($raw) === 'unknown') {
                $label = 'Unknown';
            }
            return ['label'=>$label,'raw'=>$raw,'matches'=>(int)$row['matches']];
        },$dimensionQuery->fetchAll() ?: []);

        $durationDistribution = $this->pdo->prepare("SELECT CASE
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<30 THEN '<30 d'
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<60 THEN '30–59 d'
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<90 THEN '60–89 d'
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<120 THEN '90–119 d'
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<180 THEN '120–179 d'
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<270 THEN '180–269 d'
              WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<365 THEN '270–364 d'
              ELSE '365+ d' END AS label,COUNT(*) AS matches,MIN(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400) AS ordering
            FROM p2k_tp_match_metadata m LEFT JOIN p2k_tp_void_matches v ON v.club_slug=m.club_slug AND v.match_id=m.match_id
            WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 AND v.match_id IS NULL AND m.start_time IS NOT NULL AND m.end_time>m.start_time
            GROUP BY label ORDER BY ordering");
        $durationDistribution->execute([$clubSlug]);
        $result['analytics']['duration_distribution'] = array_map(static fn(array $row): array => ['label'=>(string)$row['label'],'matches'=>(int)$row['matches']],$durationDistribution->fetchAll() ?: []);
        $result['analytics']['size_distribution'] = $result['analytics']['results_by_size'];

        $extremeSql = "SELECT m.match_id,m.match_name AS name,m.match_url AS url,m.start_time,m.end_time,m.board_count AS boards,
                              m.p2k_score AS our_score,m.opponent_score AS their_score,m.opponent_name,s.result,s.competition_points AS club_points,
                              ROUND(TIMESTAMPDIFF(HOUR,m.start_time,m.end_time)/24,1) AS duration_days
                       FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id
                       WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0";
        $extremeMap = [
            'closest' => ' ORDER BY ABS(m.p2k_score-m.opponent_score) ASC,m.end_time DESC LIMIT 5',
            'largest' => ' ORDER BY m.board_count DESC,m.end_time DESC LIMIT 5',
            'longest' => " AND m.start_time IS NOT NULL AND m.end_time>m.start_time AND NOT EXISTS (SELECT 1 FROM p2k_tp_void_matches v WHERE v.club_slug=m.club_slug AND v.match_id=m.match_id) ORDER BY duration_days DESC LIMIT 5",
            'biggest_wins' => " AND s.result='win' ORDER BY (m.p2k_score-m.opponent_score) DESC,m.end_time DESC LIMIT 5",
            'biggest_losses' => " AND s.result='loss' ORDER BY (m.opponent_score-m.p2k_score) DESC,m.end_time DESC LIMIT 5",
        ];
        foreach ($extremeMap as $key=>$suffix) {
            $extreme = $this->pdo->prepare($extremeSql . $suffix);
            $extreme->execute([$clubSlug]);
            $result['analytics'][$key] = array_map(static fn(array $row): array => [
                'match_id'=>(int)$row['match_id'],'name'=>(string)$row['name'],'url'=>$row['url'],'start_time'=>$row['start_time'],'end_time'=>$row['end_time'],
                'boards'=>(int)$row['boards'],'our_score'=>(float)$row['our_score'],'their_score'=>(float)$row['their_score'],'opponent_name'=>$row['opponent_name'],
                'result'=>$row['result'],'club_points'=>(int)$row['club_points'],'duration_days'=>$row['duration_days']===null?null:(float)$row['duration_days'],
            ], $extreme->fetchAll() ?: []);
        }
        $ongoingExtreme = $this->pdo->prepare(
            "SELECT m.match_id,m.match_name AS name,m.match_url AS url,m.start_time,NULL AS end_time,m.board_count AS boards,
                    m.p2k_score AS our_score,m.opponent_score AS their_score,m.opponent_name,NULL AS result,0 AS club_points,
                    ROUND(TIMESTAMPDIFF(HOUR,m.start_time,UTC_TIMESTAMP())/24,1) AS duration_days
             FROM p2k_tp_match_metadata m WHERE m.club_slug=? AND m.status='in_progress' AND m.start_time IS NOT NULL
             ORDER BY m.start_time ASC LIMIT 5"
        );
        $ongoingExtreme->execute([$clubSlug]);
        $result['analytics']['longest_ongoing'] = array_map(static fn(array $row): array => [
            'match_id'=>(int)$row['match_id'],'name'=>(string)$row['name'],'url'=>$row['url'],'start_time'=>$row['start_time'],'end_time'=>null,
            'boards'=>(int)$row['boards'],'our_score'=>(float)$row['our_score'],'their_score'=>(float)$row['their_score'],'opponent_name'=>$row['opponent_name'],
            'result'=>null,'club_points'=>0,'duration_days'=>$row['duration_days']===null?null:(float)$row['duration_days'],
        ], $ongoingExtreme->fetchAll() ?: []);
        $finishedCount = max(1,(int)($result['summary']['finished']??0));
        $result['summary']['club_points_per_finished_match'] = (int)round((int)$result['summary']['competition_points']/$finishedCount);
        return $result;
    }

    public function publicOpponentStats(string $clubSlug, array $options = []): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$analytics=$this->analytics();$validAchievementKeys=$this->achievementCatalogSqlList();
        $page=max(1,(int)($options['page']??1));$pageSize=max(10,min(100,(int)($options['page_size']??25)));
        $search=substr(trim((string)($options['search']??'')),0,120);$filter=strtolower(trim((string)($options['filter']??'all')));
        if(!in_array($filter,['all','active','registered','finished','disabled'],true))$filter='all';
        $sort=strtolower(trim((string)($options['sort']??'total')));$direction=strtolower((string)($options['direction']??'desc'))==='asc'?'ASC':'DESC';
        $includeSummary=!array_key_exists('include_summary',$options)||(bool)$options['include_summary'];
        $where=['club_slug=?'];$params=[$clubSlug];
        if($search!==''){$where[]='(display_name LIKE ? OR opponent_slug LIKE ?)';$needle='%'.$search.'%';$params[]=$needle;$params[]=$needle;}
        if($filter==='active')$where[]='ongoing>0';elseif($filter==='registered')$where[]='registered>0';elseif($filter==='finished')$where[]='finished>0';elseif($filter==='disabled')$where[]='disabled=1';
        $whereSql=' WHERE '.implode(' AND ',$where);
        $count=$analytics->prepare('SELECT COUNT(*) FROM p2k_an_opponent_stats'.$whereSql);$count->execute($params);$totalRows=(int)$count->fetchColumn();$pages=max(1,(int)ceil($totalRows/$pageSize));$page=min($page,$pages);$offset=($page-1)*$pageSize;
        $sortColumns=['name'=>'display_name','total'=>'matches','ongoing'=>'ongoing','registered'=>'registered','finished'=>'finished','wins'=>'wins','draws'=>'draws','losses'=>'losses','our_points'=>'our_points','their_points'=>'their_points','balance'=>'(our_points-their_points)','win_rate'=>'CASE WHEN (wins+draws+losses)>0 THEN wins/(wins+draws+losses) ELSE 0 END'];$sortSql=$sortColumns[$sort]??'matches';
        $q=$analytics->prepare("SELECT *,ROUND(our_points-their_points,1) balance,(wins+draws+losses) result_covered,GREATEST(0,finished-(wins+draws+losses)) result_missing,CASE WHEN finished>0 THEN ROUND(100*(wins+draws+losses)/finished,1) ELSE 100 END result_coverage_percent,CASE WHEN (wins+draws+losses)>0 THEN ROUND(100*wins/(wins+draws+losses),1) ELSE 0 END win_rate FROM p2k_an_opponent_stats{$whereSql} ORDER BY {$sortSql} {$direction},display_name ASC LIMIT {$pageSize} OFFSET {$offset}");$q->execute($params);
        $map=static fn(array $r):array=>['slug'=>(string)$r['opponent_slug'],'name'=>(string)$r['display_name'],'url'=>self::chessClubHumanUrl((string)($r['club_url']??''),(string)$r['opponent_slug']),'disabled'=>(bool)$r['disabled'],'total'=>(int)$r['matches'],'registered'=>(int)$r['registered'],'ongoing'=>(int)$r['ongoing'],'finished'=>(int)$r['finished'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'result_covered'=>(int)$r['result_covered'],'result_missing'=>(int)$r['result_missing'],'result_coverage_percent'=>(float)$r['result_coverage_percent'],'our_points'=>(float)$r['our_points'],'their_points'=>(float)$r['their_points'],'balance'=>(float)$r['balance'],'win_rate'=>(float)$r['win_rate']];
        $result=['rows'=>array_map($map,$q->fetchAll()?:[]),'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$totalRows,'total_pages'=>$pages]];if(!$includeSummary)return $result;
        $summary=$analytics->prepare("SELECT COUNT(*) different_opponents,COALESCE(SUM(finished>0),0) played_historically,COALESCE(SUM(ongoing>0),0) currently_playing,COALESCE(SUM(registered>0),0) in_registration,COALESCE(SUM(finished),0) finished_matches FROM p2k_an_opponent_stats WHERE club_slug=?");$summary->execute([$clubSlug]);$sr=$summary->fetch()?:[];
        $result['summary']=['different_opponents'=>(int)($sr['different_opponents']??0),'played_historically'=>(int)($sr['played_historically']??0),'currently_playing'=>(int)($sr['currently_playing']??0),'in_registration'=>(int)($sr['in_registration']??0),'finished_matches'=>(int)($sr['finished_matches']??0)];
        $top=$analytics->prepare("SELECT *,ROUND(our_points-their_points,1) balance,(wins+draws+losses) result_covered,GREATEST(0,finished-(wins+draws+losses)) result_missing,CASE WHEN finished>0 THEN ROUND(100*(wins+draws+losses)/finished,1) ELSE 100 END result_coverage_percent,CASE WHEN (wins+draws+losses)>0 THEN ROUND(100*wins/(wins+draws+losses),1) ELSE 0 END win_rate FROM p2k_an_opponent_stats WHERE club_slug=? ORDER BY matches DESC,display_name ASC LIMIT 15");$top->execute([$clubSlug]);$result['top_opponents']=array_map(static fn(array $r):array=>['slug'=>(string)$r['opponent_slug'],'name'=>(string)$r['display_name'],'total'=>(int)$r['matches'],'ongoing'=>(int)$r['ongoing'],'registered'=>(int)$r['registered'],'finished'=>(int)$r['finished'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'result_covered'=>(int)$r['result_covered'],'result_missing'=>(int)$r['result_missing'],'result_coverage_percent'=>(float)$r['result_coverage_percent']],$top->fetchAll()?:[]);
        return $result;
    }



    /** v2.8.8: lightweight staged Members Insights reads. */
    public function publicMemberInsightsSection(string $clubSlug,array $options,string $section): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$section=strtolower($section);if(!in_array($section,['summary','ranks','table'],true))$section='summary';
        $start=trim((string)($options['start']??''));$end=trim((string)($options['end']??''));
        if($start!==''||$end!==''){
            $full=$this->publicMemberInsights($clubSlug,$options);
            return match($section){'table'=>['rows'=>$full['rows']??[],'pagination'=>$full['pagination']??[],'range'=>$full['range']??[]],'ranks'=>['analytics'=>['rank_distribution'=>$full['analytics']['rank_distribution']??[]],'range'=>$full['range']??[]],default=>['summary'=>$full['summary']??[],'analytics'=>['monthly_activity'=>$full['analytics']['monthly_activity']??[]],'range'=>$full['range']??[]]};
        }
        $a=$this->analytics();
        if($section==='summary'){
            $sq=$a->prepare('SELECT COUNT(*) total_members,SUM(current_member=1) current_members,SUM(current_member=0) former_members,SUM(games>0) active_members,ROUND(COALESCE(SUM(points),0),1) team_points FROM p2k_an_player_totals WHERE club_slug=?');$sq->execute([$clubSlug]);$summary=$sq->fetch(PDO::FETCH_ASSOC)?:[];
            $playing=$this->pdo->prepare("SELECT COUNT(DISTINCT p.username_key) FROM p2k_tp_participations p JOIN p2k_tp_match_metadata m ON m.club_slug=p.club_slug AND m.match_id=p.match_id WHERE p.club_slug=? AND m.is_void=0 AND m.status IN ('registered','in_progress')");$playing->execute([$clubSlug]);
            $activity=$a->prepare("SELECT DATE_FORMAT(month_start,'%Y-%m') month,COUNT(DISTINCT username_key) active_members,SUM(games) games,ROUND(SUM(points_x2)/2,1) points FROM p2k_an_player_monthly WHERE club_slug=? GROUP BY month ORDER BY month");$activity->execute([$clubSlug]);
            $monthly=array_map(static fn(array $r):array=>['month'=>(string)$r['month'],'active_members'=>(int)$r['active_members'],'games'=>(int)$r['games'],'points'=>(float)$r['points']],$activity->fetchAll()?:[]);
            return ['summary'=>['total_members'=>(int)($summary['total_members']??0),'current_members'=>(int)($summary['current_members']??0),'former_members'=>(int)($summary['former_members']??0),'active_members'=>(int)($summary['active_members']??0),'currently_playing'=>(int)$playing->fetchColumn(),'team_points'=>(float)($summary['team_points']??0)],'analytics'=>['monthly_activity'=>$monthly],'range'=>['start'=>null,'end'=>null]];
        }
        if($section==='ranks'){
            $q=$a->prepare('SELECT points FROM p2k_an_player_totals WHERE club_slug=?');$q->execute([$clubSlug]);$counts=[];foreach($q->fetchAll(PDO::FETCH_COLUMN)?:[] as $v){$rank=$this->hallRankForPoints((float)$v);$name=(string)($rank['name']??'Unranked');$counts[$name]=($counts[$name]??0)+1;}$rows=[];foreach($counts as $name=>$count)$rows[]=['rank'=>$name,'members'=>(int)$count];usort($rows,static fn(array $x,array $y):int=>$y['members']<=>$x['members']?:strcmp($x['rank'],$y['rank']));return ['analytics'=>['rank_distribution'=>$rows],'range'=>['start'=>null,'end'=>null]];
        }
        // Table-only all-time path. Reuse the optimized SQL paginator, then discard its secondary analytics.
        $full=$this->publicMemberInsightsMaterialized($clubSlug,max(1,(int)($options['page']??1)),max(10,min(100,(int)($options['page_size']??25))),substr(trim((string)($options['search']??'')),0,120),strtolower(trim((string)($options['filter']??'current'))),array_values(array_unique(array_filter(array_map(static fn(string $v):string=>\p2k_tp_username_key($v),explode(',',(string)($options['usernames']??'')))))),strtolower(trim((string)($options['sort']??'points'))),strtolower((string)($options['direction']??'desc'))==='asc'?1:-1,false,self::memberActivityStatusList((string)($options['activity_status']??'')));
        return ['rows'=>$full['rows']??[],'pagination'=>$full['pagination']??[],'range'=>$full['range']??[]];
    }

    /** Public all-match opponent balance payload used by Insights · Opponents.
     *
     * v2.9.4 keeps the row stream deliberately compact because this endpoint can
     * cover the club's complete match history. Opponent labels are dictionary
     * encoded and each match is returned as a seven-number tuple:
     * [boards,rated_boards,is_league,chess_code,p2k_rating,opponent_rating,opponent_index].
     * The browser still accepts the legacy object format for compatibility.
     */
    public function publicOpponentBalance(string $clubSlug): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$a=$this->analytics();
        $summary=$a->prepare("SELECT COUNT(*) all_matches,
                    SUM(p2k_avg_rating>0 AND opponent_avg_rating>0 AND rated_board_count>0) paired_rating_matches
             FROM p2k_an_match_facts
             WHERE club_slug=? AND is_void=0 AND board_count>0");
        $summary->execute([$clubSlug]);$sr=$summary->fetch()?:[];

        // Row order has no meaning to either heatmap. Avoiding the former
        // Removing chronological ordering avoids an unnecessary full-history filesort.
        $q=$a->prepare("SELECT board_count,rated_board_count,is_league,rules,p2k_avg_rating,opponent_avg_rating,opponent_slug,opponent_name
             FROM p2k_an_match_facts
             WHERE club_slug=? AND is_void=0 AND board_count>0
               AND p2k_avg_rating>0 AND opponent_avg_rating>0
               AND rated_board_count IS NOT NULL AND rated_board_count>0");
        $q->execute([$clubSlug]);$rows=[];$opponents=[];$opponentIds=[];
        while(($r=$q->fetch(PDO::FETCH_ASSOC))!==false){
            $boards=max(1,(int)$r['board_count']);$rated=(int)$r['rated_board_count'];
            $p=(int)$r['p2k_avg_rating'];$o=(int)$r['opponent_avg_rating'];
            if($p<=0||$o<=0||$rated<=0)continue;
            $slug=trim((string)($r['opponent_slug']??''));$name=trim((string)($r['opponent_name']??''));
            $opponentKey=strtolower($slug!==''?$slug:($name!==''?$name:'unknown-opponent'));
            if(!isset($opponentIds[$opponentKey])){
                $opponentIds[$opponentKey]=count($opponents);
                $opponents[]=['slug'=>$slug,'name'=>$name!==''?$name:($slug!==''?$slug:'Unknown opponent')];
            }
            $rules=strtolower(trim((string)($r['rules']??'')));
            $chessCode=in_array($rules,['chess960','960'],true)?2:(in_array($rules,['chess','standard','daily'],true)?1:0);
            $rows[]=[$boards,$rated,(int)$r['is_league']===1?1:0,$chessCode,$p,$o,$opponentIds[$opponentKey]];
        }
        $total=(int)($sr['all_matches']??0);$paired=count($rows);
        return ['format'=>2,'columns'=>['boards','rated_boards','is_league','chess_code','p2k_avg_rating','opponent_avg_rating','opponent_index'],
            'opponents'=>$opponents,'rows'=>$rows,
            'rating_source'=>'paired_board_positions',
            'rating_source_note'=>'same valid rated board positions on both teams; rows without paired-board provenance are omitted until authoritative revalidation',
            'rated_board_count_available'=>true,
            'coverage'=>['all_matches'=>$total,'paired_rating_matches'=>$paired,'paired_match_percent'=>$total>0?round(100*$paired/$total,1):0]];
    }

    /** v2.8.8: summary/top chart can load without the opponent table. */
    public function publicOpponentStatsSection(string $clubSlug,array $options,string $section): array
    {
        $section=strtolower($section);if($section==='table'){ $options['include_summary']=false; return $this->publicOpponentStats($clubSlug,$options); }
        if($section==='balance') return $this->publicOpponentBalance($clubSlug);
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$a=$this->analytics();$summary=$a->prepare("SELECT COUNT(*) different_opponents,COALESCE(SUM(finished>0),0) played_historically,COALESCE(SUM(ongoing>0),0) currently_playing,COALESCE(SUM(registered>0),0) in_registration,COALESCE(SUM(finished),0) finished_matches FROM p2k_an_opponent_stats WHERE club_slug=?");$summary->execute([$clubSlug]);$sr=$summary->fetch()?:[];
        $top=$a->prepare("SELECT opponent_slug,display_name,matches,ongoing,registered,finished,wins,draws,losses FROM p2k_an_opponent_stats WHERE club_slug=? ORDER BY matches DESC,display_name ASC LIMIT 15");$top->execute([$clubSlug]);
        return ['summary'=>['different_opponents'=>(int)($sr['different_opponents']??0),'played_historically'=>(int)($sr['played_historically']??0),'currently_playing'=>(int)($sr['currently_playing']??0),'in_registration'=>(int)($sr['in_registration']??0),'finished_matches'=>(int)($sr['finished_matches']??0)],'top_opponents'=>array_map(static fn(array $r):array=>['slug'=>(string)$r['opponent_slug'],'name'=>(string)$r['display_name'],'total'=>(int)$r['matches'],'ongoing'=>(int)$r['ongoing'],'registered'=>(int)$r['registered'],'finished'=>(int)$r['finished'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses']],$top->fetchAll()?:[])];
    }

    /** v2.8.8: staged Match Insights. Legacy publicMatchInsights remains available. */
    public function publicMatchInsightsSection(string $clubSlug,array $options,string $section): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$section=strtolower($section);
        if($section==='table'){ $options['include_summary']=false; return $this->publicMatchInsights($clubSlug,$options); }
        if(!in_array($section,['summary','results','duration','dimensions','highlights'],true))$section='summary';
        if($section==='summary'){
            $q=$this->pdo->prepare("SELECT COUNT(*) different_matches,SUM(m.status='registered') registered,SUM(m.status='in_progress') ongoing,SUM(m.status='finished') finished,SUM(m.status='finished' AND s.result='win') wins,SUM(m.status='finished' AND s.result='draw') draws,SUM(m.status='finished' AND s.result='loss') losses,COALESCE(SUM(CASE WHEN m.status='finished' THEN s.competition_points ELSE 0 END),0) competition_points,ROUND(AVG(CASE WHEN m.status='finished' THEN m.board_count END),1) average_boards,ROUND(AVG(CASE WHEN m.status='finished' AND m.start_time IS NOT NULL AND m.end_time>m.start_time THEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400 END),1) average_duration_days,SUM(m.status='finished' AND m.start_time IS NOT NULL AND m.end_time>m.start_time) duration_count FROM p2k_tp_match_metadata m LEFT JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id WHERE m.club_slug=? AND m.is_void=0");$q->execute([$clubSlug]);$summary=$q->fetch()?:[];$durationCount=(int)($summary['duration_count']??0);$median=null;if($durationCount>0){$middle=intdiv($durationCount-1,2);$take=$durationCount%2===0?2:1;$mq=$this->pdo->prepare("SELECT TIMESTAMPDIFF(HOUR,start_time,end_time)/24 duration_days FROM p2k_tp_match_metadata WHERE club_slug=? AND status='finished' AND is_void=0 AND start_time IS NOT NULL AND end_time>start_time ORDER BY duration_days LIMIT {$take} OFFSET {$middle}");$mq->execute([$clubSlug]);$vals=array_map('floatval',$mq->fetchAll(PDO::FETCH_COLUMN)?:[]);if($vals!==[])$median=round(array_sum($vals)/count($vals),1);} $summary=['different_matches'=>(int)($summary['different_matches']??0),'registered'=>(int)($summary['registered']??0),'ongoing'=>(int)($summary['ongoing']??0),'finished'=>(int)($summary['finished']??0),'wins'=>(int)($summary['wins']??0),'draws'=>(int)($summary['draws']??0),'losses'=>(int)($summary['losses']??0),'competition_points'=>(int)($summary['competition_points']??0),'average_boards'=>$summary['average_boards']===null?null:(float)$summary['average_boards'],'average_duration_days'=>$summary['average_duration_days']===null?null:(float)$summary['average_duration_days'],'median_duration_days'=>$median];$summary['club_points_per_finished_match']=$summary['finished']?round($summary['competition_points']/$summary['finished'],1):0;
            $t=$this->pdo->prepare("SELECT DATE_FORMAT(COALESCE(start_time,end_time),'%Y-%m') month,SUM(status='registered') registered,SUM(status='in_progress') ongoing,SUM(status='finished') finished,SUM(CASE WHEN status='finished' THEN board_count ELSE 0 END) boards,ROUND(AVG(CASE WHEN status='finished' AND board_count>0 THEN board_count END),1) average_boards FROM p2k_tp_match_metadata WHERE club_slug=? AND is_void=0 AND COALESCE(start_time,end_time) IS NOT NULL GROUP BY month ORDER BY month");$t->execute([$clubSlug]);$trend=array_map(static fn(array $r):array=>['month'=>(string)$r['month'],'registered'=>(int)$r['registered'],'ongoing'=>(int)$r['ongoing'],'finished'=>(int)$r['finished'],'boards'=>(int)$r['boards'],'average_boards'=>$r['average_boards']===null?null:(float)$r['average_boards']],$t->fetchAll()?:[]);return ['summary'=>$summary,'trend'=>$trend];
        }
        if($section==='results'){
            $size=$this->pdo->prepare("SELECT CASE WHEN m.board_count<10 THEN '1–9' WHEN m.board_count<25 THEN '10–24' WHEN m.board_count<50 THEN '25–49' WHEN m.board_count<100 THEN '50–99' WHEN m.board_count<200 THEN '100–199' ELSE '200+' END label,COUNT(*) matches,SUM(s.result='win') wins,SUM(s.result='draw') draws,SUM(s.result='loss') losses,ROUND(AVG(m.board_count),1) average_boards,MIN(m.board_count) ordering FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 GROUP BY label ORDER BY ordering");$size->execute([$clubSlug]);$sizes=array_map(static fn(array $r):array=>['label'=>(string)$r['label'],'matches'=>(int)$r['matches'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'average_boards'=>(float)$r['average_boards'],'win_rate'=>(int)$r['matches']?round(100*(int)$r['wins']/(int)$r['matches'],1):0],$size->fetchAll()?:[]);$cat=$this->pdo->prepare("SELECT CASE WHEN m.is_league=1 THEN 'League' ELSE 'Friendly' END category,COUNT(*) matches,SUM(s.result='win') wins,SUM(s.result='draw') draws,SUM(s.result='loss') losses,COALESCE(SUM(s.competition_points),0) club_points FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 GROUP BY category");$cat->execute([$clubSlug]);return ['analytics'=>['results_by_size'=>$sizes,'size_distribution'=>$sizes,'categories'=>array_map(static fn(array $r):array=>['category'=>(string)$r['category'],'matches'=>(int)$r['matches'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'club_points'=>(int)$r['club_points'],'win_rate'=>(int)$r['matches']?round(100*(int)$r['wins']/(int)$r['matches'],1):0],$cat->fetchAll()?:[])]];
        }
        if($section==='duration'){
            $dt=$this->pdo->prepare("SELECT DATE_FORMAT(m.end_time,'%Y-%m') month,ROUND(AVG(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400),1) average_days,ROUND(MIN(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400),1) minimum_days,ROUND(MAX(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400),1) maximum_days,COUNT(*) matches FROM p2k_tp_match_metadata m WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 AND m.start_time IS NOT NULL AND m.end_time>m.start_time GROUP BY month ORDER BY month DESC LIMIT 36");$dt->execute([$clubSlug]);$trend=array_reverse(array_map(static fn(array $r):array=>['month'=>(string)$r['month'],'average_duration_days'=>(float)$r['average_days'],'minimum_days'=>(float)$r['minimum_days'],'maximum_days'=>(float)$r['maximum_days'],'matches'=>(int)$r['matches']],$dt->fetchAll()?:[]));$dd=$this->pdo->prepare("SELECT CASE WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<30 THEN '<30 d' WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<60 THEN '30–59 d' WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<90 THEN '60–89 d' WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<120 THEN '90–119 d' WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<180 THEN '120–179 d' WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<270 THEN '180–269 d' WHEN TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400<365 THEN '270–364 d' ELSE '365+ d' END label,COUNT(*) matches,MIN(TIMESTAMPDIFF(SECOND,m.start_time,m.end_time)/86400) ordering FROM p2k_tp_match_metadata m WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0 AND m.start_time IS NOT NULL AND m.end_time>m.start_time GROUP BY label ORDER BY ordering");$dd->execute([$clubSlug]);return ['analytics'=>['duration_trend'=>$trend,'duration_distribution'=>array_map(static fn(array $r):array=>['label'=>(string)$r['label'],'matches'=>(int)$r['matches']],$dd->fetchAll()?:[])]];
        }
        if($section==='dimensions'){
            $rq=$this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(rules),''),'Unknown') label,COUNT(*) matches FROM p2k_tp_match_metadata WHERE club_slug=? AND is_void=0 GROUP BY label ORDER BY matches DESC,label");$rq->execute([$clubSlug]);$rules=array_map(static fn(array $r):array=>['label'=>match(strtolower(trim((string)$r['label']))){'chess','standard'=>'Standard','chess960','960'=>'Chess960',default=>(string)$r['label']},'matches'=>(int)$r['matches']],$rq->fetchAll()?:[]);$tq=$this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(time_control),''),'Unknown') label,COUNT(*) matches FROM p2k_tp_match_metadata WHERE club_slug=? AND is_void=0 GROUP BY label ORDER BY matches DESC,label");$tq->execute([$clubSlug]);$times=[];foreach($tq->fetchAll()?:[] as $r){$raw=trim((string)$r['label']);$label=$raw;if(preg_match('/^1\/(\d+)$/',$raw,$m)===1){$days=(int)$m[1]/86400;$label=rtrim(rtrim(number_format($days,2,'.',''),'0'),'.').($days===1.0?' day / move':' days / move');}$times[]=['label'=>$label,'raw'=>$raw,'matches'=>(int)$r['matches']];}return ['analytics'=>['rules_distribution'=>$rules,'time_control_distribution'=>$times]];
        }
        $extremeSql="SELECT m.match_id,m.match_name name,m.match_url url,m.start_time,m.end_time,m.board_count boards,m.p2k_score our_score,m.opponent_score their_score,m.opponent_name,s.result,s.competition_points club_points,ROUND(TIMESTAMPDIFF(HOUR,m.start_time,m.end_time)/24,1) duration_days FROM p2k_tp_match_metadata m JOIN p2k_tp_match_summaries s ON s.club_slug=m.club_slug AND s.match_id=m.match_id WHERE m.club_slug=? AND m.status='finished' AND m.is_void=0";$map=['closest'=>' ORDER BY ABS(m.p2k_score-m.opponent_score) ASC,m.end_time DESC LIMIT 5','largest'=>' ORDER BY m.board_count DESC,m.end_time DESC LIMIT 5','longest'=>' AND m.start_time IS NOT NULL AND m.end_time>m.start_time ORDER BY duration_days DESC LIMIT 5','biggest_wins'=>" AND s.result='win' ORDER BY (m.p2k_score-m.opponent_score) DESC,m.end_time DESC LIMIT 5",'biggest_losses'=>" AND s.result='loss' ORDER BY (m.opponent_score-m.p2k_score) DESC,m.end_time DESC LIMIT 5"];$analytics=[];foreach($map as $key=>$suffix){$q=$this->pdo->prepare($extremeSql.$suffix);$q->execute([$clubSlug]);$analytics[$key]=array_map(static fn(array $r):array=>['match_id'=>(int)$r['match_id'],'name'=>(string)$r['name'],'url'=>$r['url'],'start_time'=>$r['start_time'],'end_time'=>$r['end_time'],'boards'=>(int)$r['boards'],'our_score'=>(float)$r['our_score'],'their_score'=>(float)$r['their_score'],'opponent_name'=>$r['opponent_name'],'result'=>$r['result'],'club_points'=>(int)$r['club_points'],'duration_days'=>$r['duration_days']===null?null:(float)$r['duration_days']],$q->fetchAll()?:[]);} $ongoing=$this->pdo->prepare("SELECT match_id,match_name name,match_url url,start_time,board_count boards,p2k_score our_score,opponent_score their_score,opponent_name,ROUND(TIMESTAMPDIFF(HOUR,start_time,UTC_TIMESTAMP())/24,1) duration_days FROM p2k_tp_match_metadata WHERE club_slug=? AND status='in_progress' AND is_void=0 AND start_time IS NOT NULL ORDER BY start_time ASC LIMIT 5");$ongoing->execute([$clubSlug]);$analytics['longest_ongoing']=array_map(static fn(array $r):array=>['match_id'=>(int)$r['match_id'],'name'=>(string)$r['name'],'url'=>$r['url'],'start_time'=>$r['start_time'],'end_time'=>null,'boards'=>(int)$r['boards'],'our_score'=>(float)$r['our_score'],'their_score'=>(float)$r['their_score'],'opponent_name'=>$r['opponent_name'],'result'=>null,'club_points'=>0,'duration_days'=>$r['duration_days']===null?null:(float)$r['duration_days']],$ongoing->fetchAll()?:[]);return ['analytics'=>$analytics];
    }

    /** Common public provenance without scanning Core on every GET. */
    public function publicInsightsMeta(string $clubSlug): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);
        $meta=$this->publicReadMeta($clubSlug);
        $from=null;$to=null;
        try{
            $q=$this->analytics()->prepare('SELECT MIN(activity_date),MAX(activity_date) FROM p2k_tp_insight_daily WHERE club_slug=?');
            $q->execute([$clubSlug]);[$from,$to]=$q->fetch(PDO::FETCH_NUM)?:[null,null];
        }catch(\Throwable){}
        $meta['coverage']=['from'=>$from?:null,'to'=>$to?:null];
        return $meta;
    }

    /** Cheap generation metadata for cache keys and public read headers. */
    public function publicReadMeta(string $clubSlug): array
    {
        $clubSlug = $this->resolveDataClubSlug($clubSlug);
        $states = [];
        try {
            $q = $this->analytics()->prepare("SELECT domain_key,refreshed_at,row_count,last_error FROM p2k_an_refresh_state WHERE club_slug=? AND domain_key IN ('all','achievements')");
            $q->execute([$clubSlug]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $states[(string)$row['domain_key']] = $row;
        } catch (\Throwable) {}
        $liveUpdated = null;
        try {
            $q = $this->analytics()->prepare('SELECT updated_at FROM p2k_lr_processing_state WHERE club_slug=? LIMIT 1');
            $q->execute([$clubSlug]);
            $value = $q->fetchColumn();
            if (is_string($value) && $value !== '') $liveUpdated = $value;
        } catch (\Throwable) {}
        $allUpdated = (string)($states['all']['refreshed_at'] ?? '');
        $achievementUpdated = (string)($states['achievements']['refreshed_at'] ?? '');
        $coreState=$this->readState($clubSlug);$coreGeneration=max(1,(int)($coreState['core_generation']??1));
        $latest = array_values(array_filter([$allUpdated,$achievementUpdated,$liveUpdated,(string)($coreState['updated_at']??'')], static fn($v): bool => is_string($v) && $v !== ''));
        sort($latest);
        return [
            'source'=>PublicReadDatabase::source()==='green'?'green_core_live':'database','generated_at'=>gmdate('c'),
            'last_database_update'=>$latest ? str_replace(' ','T',(string)end($latest)).'Z' : null,
            'schema_version'=>$this->schemaVersion(),'analytics_schema_version'=>$this->analyticsSchemaVersion(),
            'database_architecture'=>'core+analytics','resolved_club_slug'=>$clubSlug,
            'generations'=>[
                'core'=>$coreGeneration,
                'analytics'=>$allUpdated ?: null,
                'achievements'=>$achievementUpdated ?: null,
                'live'=>$liveUpdated ?: null,
            ],
            'freshness'=>[
                'members_last_observed_at'=>$coreState['members_last_observed_at']??null,
                'club_index_last_observed_at'=>$coreState['club_index_last_observed_at']??null,
                'core_updated_at'=>$coreState['updated_at']??null,
                'analytics_updated_at'=>$allUpdated?:null,
            ],
        ];
    }

    /** Stable cheap token: no Core COUNT/MAX scans are required on a public GET. */
    public function publicReadGenerationToken(string $clubSlug, bool $includeAchievements = true, bool $includeLive = true): string
    {
        $meta = $this->publicReadMeta($clubSlug);
        $generations = is_array($meta['generations'] ?? null) ? $meta['generations'] : [];
        $parts = ['core:' . (string)($generations['core'] ?? 'none'),'analytics:' . (string)($generations['analytics'] ?? 'none')];
        if ($includeAchievements) $parts[] = 'achievements:' . (string)($generations['achievements'] ?? 'none');
        if ($includeLive) $parts[] = 'live:' . (string)($generations['live'] ?? 'none');
        return substr(hash('sha256', implode('|',$parts)),0,24);
    }

    private function currentMemberCountCore(string $clubSlug): int
    {
        $q=$this->pdo->prepare('SELECT COUNT(*) FROM p2k_tp_members WHERE club_slug=? AND current_member=1');$q->execute([$clubSlug]);return (int)$q->fetchColumn();
    }

    /** Compatibility alias; current membership is authoritative in Core. */
    private function currentMemberCountProjected(string $clubSlug): int { return $this->currentMemberCountCore($clubSlug); }

    /** Fast Analytics-backed summary used by the unified player profile. */
    private function projectedPlayerSummary(string $clubSlug, string $username): ?array
    {
        $usernameKey = \p2k_tp_username_key($username);
        try {
            $q=$this->analytics()->prepare('SELECT username,current_member,points,matches,games,wins,draws,losses FROM p2k_an_player_totals WHERE club_slug=? AND username_key=? LIMIT 1');
            $q->execute([$clubSlug,$usernameKey]);
            $row=$q->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) return null;
            $points=(float)$row['points'];
            $current=(bool)$row['current_member'];
            $definition=$this->hallRankForPoints($points);
            $teamPosition=null;$categoryPosition=null;
            if($current){
                $rank=$this->analytics()->prepare('SELECT 1+COUNT(*) FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 AND (points>? OR (points=? AND username_key<?))');
                $rank->execute([$clubSlug,$points,$points,$usernameKey]);$teamPosition=(int)$rank->fetchColumn();
                $minimum=(float)($definition['minimum']??0);$maximum=$definition['maximum']??null;
                $sql='SELECT 1+COUNT(*) FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1 AND points>=?';$params=[$clubSlug,$minimum];
                if($maximum!==null){$sql.=' AND points<?';$params[]=(float)$maximum;}
                $sql.=' AND (points>? OR (points=? AND username_key<?))';array_push($params,$points,$points,$usernameKey);
                $rank=$this->analytics()->prepare($sql);$rank->execute($params);$categoryPosition=(int)$rank->fetchColumn();
            }
            return ['username'=>(string)$row['username'],'current_member'=>$current,'points'=>$points,'matches'=>(int)$row['matches'],'games'=>(int)$row['games'],'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses'],'team_position'=>$teamPosition,'category_position'=>$categoryPosition,'rank'=>$definition,'available'=>true];
        } catch (\Throwable) { return null; }
    }

    private function liveRankForPoints(float $points): array
    {
        $rank=null;foreach(LiveRanksService::thresholds() as $candidate)if($points>=(float)$candidate['minimum'])$rank=$candidate;
        return $rank??['key'=>'unranked','name'=>'Unranked','minimum'=>0];
    }

    /** SQL literal list for the authoritative current achievement catalogue. */
    private function achievementCatalogSqlList(): string
    {
        $pdo=$this->analytics();$keys=[];
        foreach(AchievementCatalog::all() as $item){$key=(string)($item['key']??'');if($key!=='')$keys[$key]=true;}
        if($keys===[])return "''";
        return implode(',',array_map(static fn(string $key):string=>(string)$pdo->quote($key),array_keys($keys)));
    }

    /** Lightweight Achievement wall query; pagination and sorting happen in MariaDB. */
    public function publicAchievementPlayers(string $clubSlug, array $options = []): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$analytics=$this->analytics();$validAchievementKeys=$this->achievementCatalogSqlList();
        $page=max(1,(int)($options['page']??1));$pageSize=max(6,min(60,(int)($options['page_size']??12)));
        $filter=strtolower(trim((string)($options['filter']??'current')));if(!in_array($filter,['current','milestones'],true))$filter='current';
        $wanted=array_values(array_unique(array_filter(array_map(static fn(string $v):string=>\p2k_tp_username_key($v),explode(',',(string)($options['usernames']??''))))));if(count($wanted)>250)$wanted=array_slice($wanted,0,250);
        $where=['p.club_slug=?','p.current_member=1'];$params=[$clubSlug];
        if($wanted!==[]){$where[]='p.username_key IN ('.implode(',',array_fill(0,count($wanted),'?')).')';array_push($params,...$wanted);}
        if($filter==='milestones')$where[]='(COALESCE(a.achievement_count,0)>0 OR p.points>0 OR COALESCE(l.total_points,0)>0)';
        $whereSql=' WHERE '.implode(' AND ',$where);
        $from=" FROM p2k_an_player_totals p LEFT JOIN (SELECT club_slug,username_key,COUNT(*) achievement_count FROM p2k_an_achievement_unlocks WHERE club_slug=? AND achievement_key IN ({$validAchievementKeys}) GROUP BY club_slug,username_key) a ON a.club_slug=p.club_slug AND a.username_key=p.username_key LEFT JOIN p2k_lr_players l ON l.club_slug=p.club_slug AND l.username_key=p.username_key";
        $joinParams=array_merge([$clubSlug],$params);
        $count=$analytics->prepare('SELECT COUNT(*)'.$from.$whereSql);$count->execute($joinParams);$totalRows=(int)$count->fetchColumn();$pages=max(1,(int)ceil($totalRows/$pageSize));$page=min($page,$pages);$offset=($page-1)*$pageSize;
        $sql='SELECT p.username_key,p.username,p.points,p.matches,p.games,p.wins,p.draws,p.losses,p.daily_rating,p.chess960_rating,p.last_standard_game_at,p.last_chess960_game_at,COALESCE(a.achievement_count,0) achievement_count,COALESCE(l.total_points,0) live_points,COALESCE(l.arena_count,0) live_arenas,l.best_rank live_best_rank'.$from.$whereSql." ORDER BY COALESCE(a.achievement_count,0) DESC,p.username ASC LIMIT {$pageSize} OFFSET {$offset}";
        $q=$analytics->prepare($sql);$q->execute($joinParams);$rawRows=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$rows=[];
        $snapshots=$this->playerProfileSnapshots($clubSlug,array_map(static fn(array $r):string=>(string)$r['username_key'],$rawRows));
        foreach($rawRows as $r){$points=(float)$r['points'];$snapshot=$snapshots[(string)$r['username_key']]??[];$rows[]=['username_key'=>(string)$r['username_key'],'username'=>(string)$r['username'],'current_member'=>true,'points'=>$points,'matches'=>(int)$r['matches'],'games'=>(int)$r['games'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'daily_rating'=>$r['daily_rating']===null?null:(int)$r['daily_rating'],'chess960_rating'=>$r['chess960_rating']===null?null:(int)$r['chess960_rating'],'last_standard_game_at'=>$r['last_standard_game_at'],'last_chess960_game_at'=>$r['last_chess960_game_at'],'live_points'=>(float)$r['live_points'],'live_arenas'=>(int)$r['live_arenas'],'live_best_rank'=>$r['live_best_rank']===null?null:(int)$r['live_best_rank'],'live_rank'=>$this->liveRankForPoints((float)$r['live_points']),'achievement_count'=>(int)$r['achievement_count'],'daily_rank'=>$this->hallRankForPoints($points),'avatar'=>(string)($snapshot['avatar_url']??''),'profile_url'=>(string)($snapshot['profile_url']??''),'country'=>(string)($snapshot['country_code']??''),'profile_status'=>(string)($snapshot['profile_status']??''),'avatar_checked_at'=>$snapshot['avatar_checked_at']??null];}
        $summaryQ=$analytics->prepare('SELECT COUNT(*) current_members,ROUND(COALESCE(SUM(points),0),1) team_points FROM p2k_an_player_totals WHERE club_slug=? AND current_member=1');$summaryQ->execute([$clubSlug]);$summary=$summaryQ->fetch(PDO::FETCH_ASSOC)?:[];
        return ['rows'=>$rows,'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$totalRows,'total_pages'=>$pages],'summary'=>['current_members'=>(int)($summary['current_members']??0),'team_points'=>(float)($summary['team_points']??0)]];
    }

    private static function memberActivityStatusList(string $raw): array
    {
        $valid=['active','cooling','inactive','dormant','unknown'];
        return array_values(array_intersect($valid,array_values(array_unique(array_filter(array_map('trim',explode(',',strtolower($raw))))))));
    }

    private static function memberActivityStatus(?string $lastActivity): string
    {
        if(!$lastActivity)return 'unknown';
        $ts=strtotime($lastActivity.(preg_match('/(?:Z|[+-]\d\d:?\d\d)$/',$lastActivity)?'':' UTC'));
        if($ts===false)return 'unknown';
        $days=max(0,(int)floor((time()-$ts)/86400));
        return $days<=30?'active':($days<=90?'cooling':($days<=180?'inactive':'dormant'));
    }

    /** Paginated, database-backed member aggregates for Members Insights. */
    public function publicMemberInsights(string $clubSlug, array $options = []): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);
        $page=max(1,(int)($options['page']??1));$unpaged=!empty($options['_unpaged']);$pageSize=$unpaged?100000:max(10,min(100,(int)($options['page_size']??25)));
        $search=substr(trim((string)($options['search']??'')),0,120);$filter=strtolower(trim((string)($options['filter']??'current')));$activityStatuses=self::memberActivityStatusList((string)($options['activity_status']??''));
        if(!in_array($filter,['all','current','former','active','playing','new','milestones'],true))$filter='current';
        $wanted=array_values(array_unique(array_filter(array_map(static fn(string $v):string=>\p2k_tp_username_key($v),explode(',',(string)($options['usernames']??''))))));if(count($wanted)>200)$wanted=array_slice($wanted,0,200);
        $sort=strtolower(trim((string)($options['sort']??'points')));$direction=strtolower((string)($options['direction']??'desc'))==='asc'?1:-1;
        $startDate=trim((string)($options['start']??''));$endDate=trim((string)($options['end']??''));
        foreach([$startDate,$endDate] as $value){if($value==='')continue;$date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d')!==$value)throw new ApiException('Invalid member insight date.',400,'INVALID_DATE');}
        if($startDate!==''&&$endDate!==''&&$startDate>$endDate)throw new ApiException('The start date must not be after the end date.',400,'INVALID_DATE_RANGE');
        if($startDate===''&&$endDate===''&&!in_array($filter,['playing','new'],true)&&$sort!=='current_matches'){
            return $this->publicMemberInsightsMaterialized($clubSlug,$page,$pageSize,$search,$filter,$wanted,$sort,$direction,true,$activityStatuses);
        }
        $eventWhere="e.club_slug=? AND NOT EXISTS (SELECT 1 FROM p2k_tp_match_metadata vm WHERE vm.club_slug=e.club_slug AND vm.match_id=e.match_id AND vm.is_void=1)";$eventParams=[$clubSlug];if($startDate!==''){$eventWhere.=' AND game_end_utc>=?';$eventParams[]=$startDate.' 00:00:00';}
        if($endDate!==''){$exclusive=(new DateTimeImmutable($endDate,new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');$eventWhere.=' AND game_end_utc<?';$eventParams[]=$exclusive;}
        $base="SELECT m.username_key,m.username,m.current_member,m.first_seen_at,m.last_seen_at,m.daily_rating,m.chess960_rating,m.rating_updated_at,
                     COALESCE(e.points,0) points,COALESCE(e.matches,0) matches,COALESCE(e.games,0) games,COALESCE(e.wins,0) wins,COALESCE(e.draws,0) draws,COALESCE(e.losses,0) losses,
                     e.first_activity,e.last_activity,COALESCE(cm.current_matches,0) current_matches
              FROM p2k_tp_members m
              LEFT JOIN (SELECT username_key,ROUND(SUM(points),1) points,COUNT(DISTINCT match_id) matches,COUNT(*) games,SUM(points=1.0) wins,SUM(points=0.5) draws,SUM(points=0.0) losses,MIN(game_end_utc) first_activity,MAX(game_end_utc) last_activity FROM p2k_tp_point_events e WHERE {$eventWhere} GROUP BY username_key) e ON e.username_key=m.username_key
              LEFT JOIN (SELECT p.username_key,COUNT(DISTINCT p.match_id) current_matches FROM p2k_tp_participations p JOIN p2k_tp_match_metadata md ON md.club_slug=p.club_slug AND md.match_id=p.match_id WHERE p.club_slug=? AND md.status IN ('registered','in_progress') GROUP BY p.username_key) cm ON cm.username_key=m.username_key
              WHERE m.club_slug=?";
        $q=$this->pdo->prepare($base);$q->execute(array_merge($eventParams,[$clubSlug,$clubSlug]));$all=$q->fetchAll()?:[];
        $live=[];try{$aq=$this->analytics()->prepare('SELECT username_key,total_points,arena_count,best_rank FROM p2k_lr_players WHERE club_slug=?');$aq->execute([$clubSlug]);foreach($aq->fetchAll()?:[] as $r)$live[(string)$r['username_key']]=$r;}catch(\Throwable){}
        $activitySnapshot=[];try{$aq=$this->analytics()->prepare('SELECT username_key,last_game_at,last_standard_game_at,last_chess960_game_at FROM p2k_an_player_totals WHERE club_slug=?');$aq->execute([$clubSlug]);foreach($aq->fetchAll()?:[] as $r)$activitySnapshot[(string)$r['username_key']]=$r;}catch(\Throwable){}
        $achievementCounts=[];$validAchievementKeys=$this->achievementCatalogSqlList();try{$aq=$this->analytics()->prepare("SELECT username_key,COUNT(*) achievement_count FROM p2k_an_achievement_unlocks WHERE club_slug=? AND achievement_key IN ({$validAchievementKeys}) GROUP BY username_key");$aq->execute([$clubSlug]);foreach($aq->fetchAll()?:[] as $r)$achievementCounts[(string)$r['username_key']]=(int)$r['achievement_count'];}catch(\Throwable){}
        $summary=['total_members'=>count($all),'current_members'=>0,'former_members'=>0,'active_members'=>0,'currently_playing'=>0,'team_points'=>0.0];$rows=[];
        foreach($all as $r){$key=(string)$r['username_key'];$l=$live[$key]??[];$a=$activitySnapshot[$key]??[];$points=(float)$r['points'];$games=(int)$r['games'];$row=[
            'username_key'=>$key,'username'=>(string)$r['username'],'current_member'=>(bool)$r['current_member'],'first_seen_at'=>$r['first_seen_at'],'last_seen_at'=>$r['last_seen_at'],
            'daily_rating'=>$r['daily_rating']===null?null:(int)$r['daily_rating'],'chess960_rating'=>$r['chess960_rating']===null?null:(int)$r['chess960_rating'],'rating_updated_at'=>$r['rating_updated_at'],
            'last_standard_game_at'=>$a['last_standard_game_at']??null,'last_chess960_game_at'=>$a['last_chess960_game_at']??null,
            'points'=>$points,'matches'=>(int)$r['matches'],'games'=>$games,'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],
            'win_rate'=>$games?round(100*(int)$r['wins']/$games,1):0.0,'points_per_game'=>$games?round($points/$games,3):0.0,'first_activity'=>$r['first_activity'],'last_activity'=>$r['last_activity'],'activity_status'=>self::memberActivityStatus($a['last_game_at']??null),'current_matches'=>(int)$r['current_matches'],
            'live_points'=>(float)($l['total_points']??0),'live_arenas'=>(int)($l['arena_count']??0),'live_best_rank'=>isset($l['best_rank'])&&$l['best_rank']!==null?(int)$l['best_rank']:null,'achievement_count'=>(int)($achievementCounts[$key]??0),'daily_rank'=>$this->hallRankForPoints($points)];
            $summary[$row['current_member']?'current_members':'former_members']++;if($games>0)$summary['active_members']++;if($row['current_matches']>0)$summary['currently_playing']++;$summary['team_points']+=$points;
            if($search!==''&&stripos($row['username'],$search)===false)continue;if($wanted!==[]&&!in_array($key,$wanted,true))continue;
            if($filter==='current'&&!$row['current_member'])continue;if($filter==='former'&&$row['current_member'])continue;if($filter==='active'&&$games===0)continue;if($filter==='playing'&&$row['current_matches']===0)continue;
            if($filter==='new'&&(strtotime((string)$row['first_seen_at'].' UTC')?:0)<time()-30*86400)continue;if($filter==='milestones'&&$row['achievement_count']<=0&&$points<=0&&$row['live_points']<=0)continue;if($activityStatuses!==[]&&!in_array((string)$row['activity_status'],$activityStatuses,true))continue;
            $rows[]=$row;
        }
        $validSort=['username','points','matches','games','wins','draws','losses','win_rate','points_per_game','first_activity','last_activity','current_matches','live_points','achievement_count','daily_rating','chess960_rating','last_standard_game_at','last_chess960_game_at'];if(!in_array($sort,$validSort,true))$sort='points';
        usort($rows,static function(array $a,array $b)use($sort,$direction):int{$av=$a[$sort]??0;$bv=$b[$sort]??0;$cmp=is_numeric($av)&&is_numeric($bv)?($av<=>$bv):strcasecmp((string)$av,(string)$bv);return $cmp==0?strcasecmp((string)$a['username'],(string)$b['username']):$direction*$cmp;});
        $totalRows=count($rows);$totalPages=max(1,(int)ceil($totalRows/$pageSize));$page=min($page,$totalPages);if(!$unpaged)$rows=array_slice($rows,($page-1)*$pageSize,$pageSize);
        $activity=$this->pdo->prepare("SELECT DATE_FORMAT(game_end_utc,'%Y-%m') month,COUNT(DISTINCT username_key) active_members,COUNT(*) games,ROUND(SUM(points),1) points FROM p2k_tp_point_events e WHERE {$eventWhere} GROUP BY month ORDER BY month");$activity->execute($eventParams);
        $monthlyActivity=array_map(static fn(array $r):array=>['month'=>(string)$r['month'],'active_members'=>(int)$r['active_members'],'games'=>(int)$r['games'],'points'=>(float)$r['points']],$activity->fetchAll()?:[]);
        $rankQ=$this->pdo->prepare("SELECT username_key,SUM(points) points FROM p2k_tp_point_events e WHERE {$eventWhere} GROUP BY username_key");$rankQ->execute($eventParams);$rankCounts=[];foreach($rankQ->fetchAll()?:[] as $rr){$rank=$this->hallRankForPoints((float)$rr['points']);$name=(string)($rank['name']??'Unranked');$rankCounts[$name]=($rankCounts[$name]??0)+1;}$rankDistribution=[];foreach($rankCounts as $name=>$count)$rankDistribution[]=['rank'=>$name,'members'=>(int)$count];usort($rankDistribution,static fn(array $a,array $b):int=>$b['members']<=>$a['members']?:strcmp($a['rank'],$b['rank']));
        return ['rows'=>$rows,'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$totalRows,'total_pages'=>$totalPages],'analytics'=>['monthly_activity'=>$monthlyActivity,'rank_distribution'=>$rankDistribution],
            'summary'=>['total_members'=>(int)$summary['total_members'],'current_members'=>(int)$summary['current_members'],'former_members'=>(int)$summary['former_members'],'active_members'=>(int)$summary['active_members'],'currently_playing'=>(int)$summary['currently_playing'],'team_points'=>round((float)$summary['team_points'],1)],'range'=>['start'=>$startDate?:null,'end'=>$endDate?:null]];
    }

    /** Fast all-time Members Insights path using materialized Analytics and SQL pagination. */
    private function publicMemberInsightsMaterialized(string $clubSlug,int $page,int $pageSize,string $search,string $filter,array $wanted,string $sort,int $direction,bool $includeSecondary=true,array $activityStatuses=[]): array
    {
        $a=$this->analytics();$validAchievementKeys=$this->achievementCatalogSqlList();$where=['p.club_slug=?'];$params=[$clubSlug];
        if($search!==''){$where[]='p.username LIKE ?';$params[]='%'.$search.'%';}
        if($wanted!==[]){$where[]='p.username_key IN ('.implode(',',array_fill(0,count($wanted),'?')).')';array_push($params,...$wanted);}
        if($filter==='current')$where[]='p.current_member=1';elseif($filter==='former')$where[]='p.current_member=0';elseif($filter==='active')$where[]='p.games>0';elseif($filter==='milestones')$where[]='(p.points>0 OR COALESCE(l.total_points,0)>0 OR COALESCE(ac.achievement_count,0)>0)';
        if($activityStatuses!==[]){$activityClauses=[];foreach($activityStatuses as $status){if($status==='active')$activityClauses[]='p.last_game_at>=UTC_TIMESTAMP()-INTERVAL 30 DAY';elseif($status==='cooling')$activityClauses[]='(p.last_game_at<UTC_TIMESTAMP()-INTERVAL 30 DAY AND p.last_game_at>=UTC_TIMESTAMP()-INTERVAL 90 DAY)';elseif($status==='inactive')$activityClauses[]='(p.last_game_at<UTC_TIMESTAMP()-INTERVAL 90 DAY AND p.last_game_at>=UTC_TIMESTAMP()-INTERVAL 180 DAY)';elseif($status==='dormant')$activityClauses[]='p.last_game_at<UTC_TIMESTAMP()-INTERVAL 180 DAY';elseif($status==='unknown')$activityClauses[]='p.last_game_at IS NULL';}if($activityClauses!==[])$where[]='('.implode(' OR ',$activityClauses).')';}
        $from=" FROM p2k_an_player_totals p LEFT JOIN p2k_lr_players l ON l.club_slug=p.club_slug AND l.username_key=p.username_key LEFT JOIN (SELECT club_slug,username_key,COUNT(*) achievement_count FROM p2k_an_achievement_unlocks WHERE achievement_key IN ({$validAchievementKeys}) GROUP BY club_slug,username_key) ac ON ac.club_slug=p.club_slug AND ac.username_key=p.username_key";
        $whereSql=' WHERE '.implode(' AND ',$where);$count=$a->prepare('SELECT COUNT(*)'.$from.$whereSql);$count->execute($params);$totalRows=(int)$count->fetchColumn();$totalPages=max(1,(int)ceil($totalRows/$pageSize));$page=min($page,$totalPages);$offset=($page-1)*$pageSize;
        $sortMap=['username'=>'p.username','points'=>'p.points','matches'=>'p.matches','games'=>'p.games','wins'=>'p.wins','draws'=>'p.draws','losses'=>'p.losses','win_rate'=>'CASE WHEN p.games>0 THEN p.wins/p.games ELSE 0 END','points_per_game'=>'CASE WHEN p.games>0 THEN p.points/p.games ELSE 0 END','first_activity'=>'p.first_game_at','last_activity'=>'p.last_game_at','live_points'=>'COALESCE(l.total_points,0)','achievement_count'=>'COALESCE(ac.achievement_count,0)','daily_rating'=>'p.daily_rating','chess960_rating'=>'p.chess960_rating','last_standard_game_at'=>'p.last_standard_game_at','last_chess960_game_at'=>'p.last_chess960_game_at'];$sortSql=$sortMap[$sort]??'p.points';$dir=$direction===1?'ASC':'DESC';
        $q=$a->prepare('SELECT p.*,COALESCE(l.total_points,0) live_points,COALESCE(l.arena_count,0) live_arenas,l.best_rank live_best_rank,COALESCE(ac.achievement_count,0) achievement_count'.$from.$whereSql." ORDER BY {$sortSql} {$dir},p.username ASC LIMIT {$pageSize} OFFSET {$offset}");$q->execute($params);$raw=$q->fetchAll(PDO::FETCH_ASSOC)?:[];$keys=array_map(static fn(array $r):string=>(string)$r['username_key'],$raw);
        $memberMeta=[];$currentMatches=[];if($keys!==[]){$in=implode(',',array_fill(0,count($keys),'?'));$mq=$this->pdo->prepare("SELECT username_key,first_seen_at,last_seen_at FROM p2k_tp_members WHERE club_slug=? AND username_key IN ({$in})");$mq->execute(array_merge([$clubSlug],$keys));foreach($mq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$memberMeta[(string)$r['username_key']]=$r;$cq=$this->pdo->prepare("SELECT p.username_key,COUNT(DISTINCT p.match_id) current_matches FROM p2k_tp_participations p JOIN p2k_tp_match_metadata m ON m.club_slug=p.club_slug AND m.match_id=p.match_id WHERE p.club_slug=? AND p.username_key IN ({$in}) AND m.status IN ('registered','in_progress') GROUP BY p.username_key");$cq->execute(array_merge([$clubSlug],$keys));foreach($cq->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$currentMatches[(string)$r['username_key']]=(int)$r['current_matches'];}
        $rows=[];foreach($raw as $r){$key=(string)$r['username_key'];$points=(float)$r['points'];$games=(int)$r['games'];$meta=$memberMeta[$key]??[];$rows[]=['username_key'=>$key,'username'=>(string)$r['username'],'current_member'=>(bool)$r['current_member'],'first_seen_at'=>$meta['first_seen_at']??null,'last_seen_at'=>$meta['last_seen_at']??null,'daily_rating'=>$r['daily_rating']===null?null:(int)$r['daily_rating'],'chess960_rating'=>$r['chess960_rating']===null?null:(int)$r['chess960_rating'],'rating_updated_at'=>$r['rating_updated_at'],'last_standard_game_at'=>$r['last_standard_game_at'],'last_chess960_game_at'=>$r['last_chess960_game_at'],'points'=>$points,'matches'=>(int)$r['matches'],'games'=>$games,'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses'],'win_rate'=>$games?round(100*(int)$r['wins']/$games,1):0.0,'points_per_game'=>$games?round($points/$games,3):0.0,'first_activity'=>$r['first_game_at'],'last_activity'=>$r['last_game_at'],'activity_status'=>self::memberActivityStatus($r['last_game_at']??null),'current_matches'=>(int)($currentMatches[$key]??0),'live_points'=>(float)$r['live_points'],'live_arenas'=>(int)$r['live_arenas'],'live_best_rank'=>$r['live_best_rank']===null?null:(int)$r['live_best_rank'],'achievement_count'=>(int)$r['achievement_count'],'daily_rank'=>$this->hallRankForPoints($points)];}
        if(!$includeSecondary)return ['rows'=>$rows,'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$totalRows,'total_pages'=>$totalPages],'range'=>['start'=>null,'end'=>null],'materialized'=>true];
        $sq=$a->prepare('SELECT COUNT(*) total_members,SUM(current_member=1) current_members,SUM(current_member=0) former_members,SUM(games>0) active_members,ROUND(COALESCE(SUM(points),0),1) team_points FROM p2k_an_player_totals WHERE club_slug=?');$sq->execute([$clubSlug]);$summary=$sq->fetch(PDO::FETCH_ASSOC)?:[];$playing=$this->pdo->prepare("SELECT COUNT(DISTINCT p.username_key) FROM p2k_tp_participations p JOIN p2k_tp_match_metadata m ON m.club_slug=p.club_slug AND m.match_id=p.match_id WHERE p.club_slug=? AND m.status IN ('registered','in_progress')");$playing->execute([$clubSlug]);
        $activity=$a->prepare("SELECT DATE_FORMAT(month_start,'%Y-%m') month,COUNT(DISTINCT username_key) active_members,SUM(games) games,ROUND(SUM(points_x2)/2,1) points FROM p2k_an_player_monthly WHERE club_slug=? GROUP BY month ORDER BY month");$activity->execute([$clubSlug]);$monthlyActivity=array_map(static fn(array $r):array=>['month'=>(string)$r['month'],'active_members'=>(int)$r['active_members'],'games'=>(int)$r['games'],'points'=>(float)$r['points']],$activity->fetchAll()?:[]);
        $rankQ=$a->prepare('SELECT points FROM p2k_an_player_totals WHERE club_slug=?');$rankQ->execute([$clubSlug]);$rankCounts=[];foreach($rankQ->fetchAll(PDO::FETCH_COLUMN)?:[] as $value){$rank=$this->hallRankForPoints((float)$value);$name=(string)($rank['name']??'Unranked');$rankCounts[$name]=($rankCounts[$name]??0)+1;}$rankDistribution=[];foreach($rankCounts as $name=>$count)$rankDistribution[]=['rank'=>$name,'members'=>$count];usort($rankDistribution,static fn(array $x,array $y):int=>$y['members']<=>$x['members']?:strcmp($x['rank'],$y['rank']));
        return ['rows'=>$rows,'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$totalRows,'total_pages'=>$totalPages],'analytics'=>['monthly_activity'=>$monthlyActivity,'rank_distribution'=>$rankDistribution],'summary'=>['total_members'=>(int)($summary['total_members']??0),'current_members'=>(int)($summary['current_members']??0),'former_members'=>(int)($summary['former_members']??0),'active_members'=>(int)($summary['active_members']??0),'currently_playing'=>(int)$playing->fetchColumn(),'team_points'=>(float)($summary['team_points']??0)],'range'=>['start'=>null,'end'=>null],'materialized'=>true];
    }

    /** Unified database profile used by member, Hall of Fame and tournament views. */
    public function publicPlayerProfile(string $clubSlug, string $username, string $mode = 'full'): array
    {
        $mode = in_array($mode, ['full','modal','search'], true) ? $mode : 'full';
        $includeMonthly = $mode !== 'search';
        $includeExtended = $mode === 'full';
        $includeLive = $mode !== 'search';
        $clubSlug = $this->resolveDataClubSlug($clubSlug);
        $miac=new MiacService($this->pdo,$clubSlug);$resolved=$miac->resolve($username);$usernameKey=(string)$resolved['canonical_username_key'];$username=(string)$resolved['canonical_username'];$aliasKeys=$miac->aliasesFor($usernameKey);if(!in_array($usernameKey,$aliasKeys,true))$aliasKeys[]=$usernameKey;$aliasKeys=array_values(array_unique(array_filter($aliasKeys)));$aliasMarks=implode(',',array_fill(0,count($aliasKeys),'?'));
        $profile = $this->projectedPlayerSummary($clubSlug, $username) ?? $this->publicPlayerSummary($clubSlug, $username);$profile['identity_aliases']=$aliasKeys;$profile['identity_generation']=(int)$resolved['generation'];
        $memberQuery = $this->pdo->prepare('SELECT joined_at,first_seen_at,last_seen_at,current_member,daily_rating,chess960_rating,rating_updated_at,avatar_url,profile_url,country_code,profile_status,avatar_checked_at,profile_updated_at FROM p2k_tp_members WHERE club_slug=? AND username_key=? LIMIT 1');
        $memberQuery->execute([$clubSlug,$usernameKey]);
        $member = $memberQuery->fetch() ?: [];
        $profile['joined_at'] = $member['joined_at'] ?? null;
        $profile['first_seen_at'] = $member['first_seen_at'] ?? null;
        if(array_key_exists('current_member',$member))$profile['current_member']=(bool)$member['current_member'];
        if(empty($profile['current_member'])){$profile['team_position']=null;$profile['category_position']=null;}
        $profile['last_seen_at'] = $member['last_seen_at'] ?? null;
        $profile['daily_rating'] = isset($member['daily_rating']) && $member['daily_rating']!==null ? (int)$member['daily_rating'] : null;
        $profile['chess960_rating'] = isset($member['chess960_rating']) && $member['chess960_rating']!==null ? (int)$member['chess960_rating'] : null;
        $profile['rating_updated_at'] = $member['rating_updated_at'] ?? null;
        $profile['avatar'] = (string)($member['avatar_url'] ?? '');
        $profile['profile_url'] = (string)($member['profile_url'] ?? '');
        $profile['country'] = (string)($member['country_code'] ?? '');
        $profile['profile_status'] = (string)($member['profile_status'] ?? '');
        $profile['avatar_checked_at'] = $member['avatar_checked_at'] ?? null;
        $profile['profile_updated_at'] = $member['profile_updated_at'] ?? null;
        try { $aq=$this->analytics()->prepare('SELECT last_standard_game_at,last_chess960_game_at FROM p2k_an_player_totals WHERE club_slug=? AND username_key=? LIMIT 1');$aq->execute([$clubSlug,$usernameKey]);$activity=$aq->fetch()?:[];$profile['last_standard_game_at']=$activity['last_standard_game_at']??null;$profile['last_chess960_game_at']=$activity['last_chess960_game_at']??null; }
        catch(\Throwable){$profile['last_standard_game_at']=null;$profile['last_chess960_game_at']=null;}

        if ($includeMonthly) {
            try {
                $monthlyQuery = $this->analytics()->prepare(
                    "SELECT DATE_FORMAT(month_start,'%Y-%m') AS month,points_x2/2 AS points,games,wins,draws,losses
                     FROM p2k_an_player_monthly WHERE club_slug=? AND username_key=? ORDER BY month_start"
                );
                $monthlyQuery->execute([$clubSlug,$usernameKey]);
                $profile['monthly'] = array_map(static fn(array $row): array => [
                    'month'=>(string)$row['month'],'points'=>(float)$row['points'],'games'=>(int)$row['games'],
                    'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses'],
                ], $monthlyQuery->fetchAll() ?: []);
            } catch (\Throwable) {
                $profile['monthly'] = [];
            }
        } else {
            $profile['monthly'] = [];
        }

        if ($includeExtended) {
        $recentQuery = $this->pdo->prepare(
            "SELECT metadata.match_id,metadata.match_name AS name,metadata.match_url AS url,metadata.status,metadata.start_time,metadata.end_time,
                    metadata.opponent_slug,metadata.opponent_name,metadata.opponent_url,metadata.board_count,
                    summaries.result,summaries.competition_points,
                    ROUND(COALESCE(SUM(events.points),0),1) AS player_points,COUNT(events.game_url) AS player_games
             FROM p2k_tp_participations participation
             LEFT JOIN p2k_tp_match_metadata metadata ON metadata.club_slug=participation.club_slug AND metadata.match_id=participation.match_id
             LEFT JOIN p2k_tp_match_summaries summaries ON summaries.club_slug=participation.club_slug AND summaries.match_id=participation.match_id
             LEFT JOIN p2k_tp_point_events events ON events.club_slug=participation.club_slug AND events.match_id=participation.match_id AND events.username_key=participation.username_key
             WHERE participation.club_slug=? AND participation.username_key IN (' . $aliasMarks . ') AND COALESCE(metadata.is_void,0)=0
             GROUP BY metadata.match_id,metadata.match_name,metadata.match_url,metadata.status,metadata.start_time,metadata.end_time,
                      metadata.opponent_slug,metadata.opponent_name,metadata.opponent_url,metadata.board_count,summaries.result,summaries.competition_points
             ORDER BY COALESCE(metadata.end_time,metadata.start_time,MAX(participation.last_seen_at)) DESC LIMIT 30"
        );
        $recentQuery->execute(array_merge([$clubSlug],$aliasKeys));
        $profile['recent_matches'] = array_map(static function (array $row): array {
            $opponentSlug = trim((string)($row['opponent_slug'] ?? ''));
            $opponentName = trim((string)($row['opponent_name'] ?? ''));
            if ($opponentName === '' && $opponentSlug !== '') {
                $opponentName = ucwords(str_replace(['-', '_'], ' ', $opponentSlug));
            }
            if ($opponentName === '') {
                $matchName = trim((string)($row['name'] ?? ''));
                $parts = preg_split('/\s+(?:vs\.?|v\.?|versus|against)\s+/i', $matchName, 2) ?: [];
                if (count($parts) === 2) {
                    $candidate = trim((string)$parts[1]);
                    if ($candidate !== '') $opponentName = $candidate;
                }
            }
            return [
                'match_id'=>(int)$row['match_id'],'name'=>(string)($row['name']??('Match '.$row['match_id'])),'url'=>$row['url'],'status'=>$row['status'],
                'start_time'=>$row['start_time'],'end_time'=>$row['end_time'],'opponent_slug'=>$row['opponent_slug'],'opponent_name'=>$opponentName,
                'opponent_url'=>$row['opponent_url'],'boards'=>(int)($row['board_count']??0),'result'=>$row['result'],
                'club_points'=>(int)($row['competition_points']??0),'player_points'=>(float)$row['player_points'],'player_games'=>(int)$row['player_games'],
            ];
        }, $recentQuery->fetchAll() ?: []);

        $opponentQuery = $this->pdo->prepare(
            "SELECT metadata.opponent_slug,MAX(metadata.opponent_name) AS opponent_name,MAX(metadata.opponent_url) AS opponent_url,
                    COUNT(DISTINCT participation.match_id) AS matches,ROUND(SUM(events.points),1) AS points,COUNT(events.game_url) AS games
             FROM p2k_tp_participations participation
             JOIN p2k_tp_match_metadata metadata ON metadata.club_slug=participation.club_slug AND metadata.match_id=participation.match_id
             LEFT JOIN p2k_tp_point_events events ON events.club_slug=participation.club_slug AND events.match_id=participation.match_id AND events.username_key=participation.username_key
             WHERE participation.club_slug=? AND participation.username_key IN (' . $aliasMarks . ') AND metadata.is_void=0 AND metadata.opponent_slug IS NOT NULL
             GROUP BY metadata.opponent_slug ORDER BY matches DESC,points DESC LIMIT 10"
        );
        $opponentQuery->execute(array_merge([$clubSlug],$aliasKeys));
        $profile['top_opponents'] = array_map(static fn(array $row): array => [
            'slug'=>(string)$row['opponent_slug'],'name'=>(string)$row['opponent_name'],'url'=>$row['opponent_url'],
            'matches'=>(int)$row['matches'],'points'=>(float)$row['points'],'games'=>(int)$row['games'],
        ], $opponentQuery->fetchAll() ?: []);
        } else {
            $profile['recent_matches'] = [];
            $profile['top_opponents'] = [];
        }

        if ($includeLive) {
        try {
            $liveQuery = $this->analytics()->prepare(
                'SELECT total_points,arena_count,total_games,total_wins,total_draws,total_losses,best_streak,max_wins_single_arena,best_rank,first_place_count,top3_count,top10_count,best_score,current_member,account_state,updated_at FROM p2k_lr_players WHERE club_slug=? AND username_key=? LIMIT 1'
            );
            $liveQuery->execute([$clubSlug,$usernameKey]);
            $live = $liveQuery->fetch();
            $profile['live'] = is_array($live) ? [
                'points'=>(float)$live['total_points'],'arenas'=>(int)$live['arena_count'],'games'=>$live['total_games']===null?null:(int)$live['total_games'],
                'wins'=>$live['total_wins']===null?null:(int)$live['total_wins'],'draws'=>$live['total_draws']===null?null:(int)$live['total_draws'],
                'losses'=>$live['total_losses']===null?null:(int)$live['total_losses'],'best_streak'=>$live['best_streak']===null?null:(int)$live['best_streak'],
                'max_wins_single_arena'=>$live['max_wins_single_arena']===null?null:(int)$live['max_wins_single_arena'],'best_rank'=>$live['best_rank']===null?null:(int)$live['best_rank'],'top3'=>(int)$live['top3_count'],'top10'=>(int)$live['top10_count'],
                'best_score'=>$live['best_score']===null?null:(float)$live['best_score'],'current_member'=>(bool)$live['current_member'],
                'account_state'=>(string)$live['account_state'],'updated_at'=>$live['updated_at'],
            ] : null;
        } catch (\Throwable) {
            $profile['live'] = null;
        }

        if (is_array($profile['live'] ?? null)) {
            $liveRank = null;
            foreach (LiveRanksService::thresholds() as $candidate) {
                if ((float)($profile['live']['points'] ?? 0) >= (float)$candidate['minimum']) $liveRank = $candidate;
            }
            $profile['live']['rank'] = $liveRank;
            $profile['live']['rank_key'] = $liveRank['key'] ?? 'unranked';
            $profile['live']['rank_name'] = $liveRank['name'] ?? 'Unranked';
        }
        } else {
            $profile['live'] = null;
        }
        $fallbackAchievements = AchievementCatalog::earned($profile);
        try {
            $currentClubMembers=$this->currentMemberCountProjected($clubSlug);
            $unlockQuery = $this->analytics()->prepare('SELECT achievement_key,earned_at,earned_at_precision,source_type,source_name,source_url,first_recorded_at FROM p2k_an_achievement_unlocks WHERE club_slug=? AND username_key=? ORDER BY COALESCE(earned_at,first_recorded_at),achievement_key');
            $unlockQuery->execute([$clubSlug,$usernameKey]);
            $unlockRows=$unlockQuery->fetchAll(PDO::FETCH_ASSOC)?:[];
            $catalogByKey=[]; foreach(AchievementCatalog::all() as $item)$catalogByKey[(string)$item['key']]=$item;
            // v2.9.4: persisted unlocks are additive evidence, never a replacement for
            // achievements derivable directly from the current profile. This matters when
            // a newer family (for example achievement breadth) has been persisted before a
            // full historical achievement rebuild: one breadth row must not hide legacy
            // match/game/win/rank achievements that are still provably earned.
            $countKeys=array_map(static fn(array $r):string=>(string)$r['achievement_key'],$unlockRows);
            foreach($fallbackAchievements as $item)$countKeys[]=(string)($item['key']??'');
            $catalogCounts = $this->achievementCountMap($clubSlug,array_values(array_unique(array_filter($countKeys))));
            $earnedByKey=[];
            foreach($unlockRows as $row){$key=(string)$row['achievement_key'];if(!isset($catalogByKey[$key]))continue;$counts=$catalogCounts[$key]??['all'=>0,'current'=>0];$precision=(string)$row['earned_at_precision'];$earnedAt=$row['earned_at'];if($earnedAt===null&&!in_array($precision,['tournament-pending','threshold-reconciled'],true))$earnedAt=$row['first_recorded_at'];$earnedByKey[$key]=$catalogByKey[$key]+['level'=>'earned','earned_at'=>$earnedAt,'earned_at_precision'=>$precision,'source_type'=>$row['source_type']??null,'source_name'=>$row['source_name']??null,'source_url'=>\p2k_tp_chess_web_url(isset($row['source_url'])?(string)$row['source_url']:null,(string)($row['source_type']??'')),'earned_member_count'=>(int)$counts['all'],'earned_current_member_count'=>(int)$counts['current'],'club_current_member_count'=>$currentClubMembers];}
            foreach($fallbackAchievements as $item){$key=(string)($item['key']??'');if($key===''||isset($earnedByKey[$key]))continue;$counts=$catalogCounts[$key]??['all'=>0,'current'=>0];$item['earned_member_count']=(int)$counts['all'];$item['earned_current_member_count']=(int)$counts['current'];$item['club_current_member_count']=$currentClubMembers;$earnedByKey[$key]=$item;}
            $profile['achievements']=array_values($earnedByKey);
        } catch (\Throwable) { $profile['achievements']=$fallbackAchievements; }
        return $profile;
    }

    private function achievementCountMap(string $clubSlug, ?array $keys = null): array
    {
        $where='u.club_slug=?';$params=[$clubSlug];
        if(is_array($keys)){
            $keys=array_values(array_unique(array_filter(array_map('strval',$keys))));
            if($keys===[])return [];
            $where.=' AND u.achievement_key IN ('.implode(',',array_fill(0,count($keys),'?')).')';
            array_push($params,...$keys);
        }
        $q=$this->analytics()->prepare(
            "SELECT u.achievement_key,COUNT(*) all_count,COALESCE(SUM(COALESCE(p.current_member,0)=1),0) current_count
             FROM p2k_an_achievement_unlocks u LEFT JOIN p2k_an_player_totals p
               ON p.club_slug=u.club_slug AND p.username_key=u.username_key
             WHERE {$where} GROUP BY u.achievement_key"
        );
        $q->execute($params);$map=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$map[(string)$r['achievement_key']]=['all'=>(int)$r['all_count'],'current'=>(int)$r['current_count']];
        return $map;
    }

    public function publicAchievementCatalog(string $clubSlug): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);
        $counts=[];try{$counts=$this->achievementCountMap($clubSlug);}catch(\Throwable){}
        $currentClubMembers=0;try{$currentClubMembers=$this->currentMemberCountProjected($clubSlug);}catch(\Throwable){}
        $catalog=[];
        foreach(AchievementCatalog::all() as $item){$key=(string)$item['key'];$c=$counts[$key]??['all'=>0,'current'=>0];$catalog[]=$item+['earned_member_count'=>(int)$c['all'],'earned_current_member_count'=>(int)$c['current'],'club_current_member_count'=>$currentClubMembers];}
        return $catalog;
    }

    public function publicMatchDetail(string $clubSlug, int $matchId): array
    {
        if ($matchId <= 0) throw new ApiException('A valid match id is required.',400,'MATCH_REQUIRED');
        $clubSlug = $this->resolveDataClubSlug($clubSlug);
        $query = $this->pdo->prepare(
            "SELECT metadata.*,summaries.result,summaries.competition_points,summaries.game_count,summaries.finalized_at,
                    CASE WHEN metadata.start_time IS NOT NULL AND metadata.end_time IS NOT NULL THEN TIMESTAMPDIFF(SECOND,metadata.start_time,metadata.end_time)/86400 ELSE NULL END AS duration_days
             FROM p2k_tp_match_metadata metadata LEFT JOIN p2k_tp_match_summaries summaries
               ON summaries.club_slug=metadata.club_slug AND summaries.match_id=metadata.match_id
             WHERE metadata.club_slug=? AND metadata.match_id=? LIMIT 1"
        );
        $query->execute([$clubSlug,$matchId]);
        $row = $query->fetch();
        if (!is_array($row)) throw new ApiException('Match not found in the database.',404,'MATCH_NOT_FOUND');
        $playersQuery = $this->pdo->prepare(
            "SELECT participation.username,participation.username_key,ROUND(COALESCE(SUM(events.points),0),1) AS points,
                    COUNT(events.game_url) AS games,SUM(events.points=1.0) AS wins,SUM(events.points=0.5) AS draws,SUM(events.points=0.0) AS losses
             FROM p2k_tp_participations participation
             LEFT JOIN p2k_tp_point_events events ON events.club_slug=participation.club_slug AND events.match_id=participation.match_id AND events.username_key=participation.username_key
             WHERE participation.club_slug=? AND participation.match_id=?
             GROUP BY participation.username_key,participation.username ORDER BY points DESC,participation.username"
        );
        $playersQuery->execute([$clubSlug,$matchId]);
        $players = array_map(static fn(array $player): array => [
            'username'=>(string)$player['username'],'points'=>(float)$player['points'],'games'=>(int)$player['games'],
            'wins'=>(int)$player['wins'],'draws'=>(int)$player['draws'],'losses'=>(int)$player['losses'],
        ], $playersQuery->fetchAll() ?: []);
        return [
            'match_id'=>(int)$row['match_id'],'name'=>(string)$row['match_name'],'url'=>$row['match_url'],'status'=>(string)$row['status'],
            'start_time'=>$row['start_time'],'end_time'=>$row['end_time'],'boards'=>(int)$row['board_count'],'games'=>(int)($row['game_count']??0),
            'rules'=>$row['rules'] !== null ? (string)$row['rules'] : null,'time_control'=>$row['time_control'] !== null ? (string)$row['time_control'] : null,
            'is_league'=>(bool)$row['is_league'],
            'our_score'=>(float)$row['p2k_score'],'their_score'=>(float)$row['opponent_score'],'result'=>$row['result'],
            'club_points'=>(int)($row['competition_points']??0),'time_control'=>$row['time_control']??null,'p2k_avg_rating'=>$row['p2k_avg_rating']===null?null:(int)$row['p2k_avg_rating'],'opponent_avg_rating'=>$row['opponent_avg_rating']===null?null:(int)$row['opponent_avg_rating'],'duration_days'=>$row['duration_days']===null?null:(float)$row['duration_days'],
            'opponent'=>['slug'=>$row['opponent_slug'],'name'=>$row['opponent_name'],'url'=>self::chessClubHumanUrl((string)($row['opponent_url']??''),(string)($row['opponent_slug']??''))],
            'last_verified_at'=>$row['last_verified_at'],'finalized_at'=>$row['finalized_at'],'players'=>$players,
        ];
    }

    public function publicOpponentProfile(string $clubSlug, string $opponentSlug): array
    {
        $clubSlug = $this->resolveDataClubSlug($clubSlug);
        $opponentSlug = strtolower(trim($opponentSlug));
        if ($opponentSlug === '' || strlen($opponentSlug) > 160) throw new ApiException('Opponent slug is required.',400,'OPPONENT_REQUIRED');
        $aliasQuery = $this->pdo->prepare('SELECT canonical_slug FROM p2k_tp_opponent_aliases WHERE club_slug=? AND alias_slug=? LIMIT 1');
        $aliasQuery->execute([$clubSlug,$opponentSlug]);
        $canonical = (string)($aliasQuery->fetchColumn() ?: $opponentSlug);
        $opponentQuery = $this->pdo->prepare('SELECT * FROM p2k_tp_opponents WHERE club_slug=? AND opponent_slug=? LIMIT 1');
        $opponentQuery->execute([$clubSlug,$canonical]);
        $opponent = $opponentQuery->fetch() ?: ['opponent_slug'=>$canonical,'display_name'=>str_replace('-',' ',$canonical),'club_url'=>'https://www.chess.com/club/'.$canonical,'disabled'=>0];
        $scopeJoin = " FROM p2k_tp_match_metadata metadata LEFT JOIN p2k_tp_match_summaries summaries ON summaries.club_slug=metadata.club_slug AND summaries.match_id=metadata.match_id LEFT JOIN p2k_tp_opponent_aliases aliases ON aliases.club_slug=metadata.club_slug AND aliases.alias_slug=metadata.opponent_slug WHERE metadata.club_slug=? AND metadata.is_void=0 AND COALESCE(aliases.canonical_slug,metadata.opponent_slug)=?";
        $summaryQuery=$this->pdo->prepare("SELECT COUNT(*) total,SUM(metadata.status='registered') registered,SUM(metadata.status='in_progress') ongoing,SUM(metadata.status='finished') finished,SUM(metadata.status='finished' AND summaries.result='win') wins,SUM(metadata.status='finished' AND summaries.result='draw') draws,SUM(metadata.status='finished' AND summaries.result='loss') losses,COALESCE(SUM(CASE WHEN metadata.status='finished' THEN metadata.p2k_score ELSE 0 END),0) our_points,COALESCE(SUM(CASE WHEN metadata.status='finished' THEN metadata.opponent_score ELSE 0 END),0) their_points,COALESCE(SUM(metadata.board_count),0) boards,AVG(CASE WHEN metadata.start_time IS NOT NULL AND metadata.end_time IS NOT NULL THEN TIMESTAMPDIFF(SECOND,metadata.start_time,metadata.end_time)/86400 END) average_duration_days".$scopeJoin);
        $summaryQuery->execute([$clubSlug,$canonical]);$summaryRow=$summaryQuery->fetch(PDO::FETCH_ASSOC)?:[];
        $summary=['total'=>(int)($summaryRow['total']??0),'registered'=>(int)($summaryRow['registered']??0),'ongoing'=>(int)($summaryRow['ongoing']??0),'finished'=>(int)($summaryRow['finished']??0),'wins'=>(int)($summaryRow['wins']??0),'draws'=>(int)($summaryRow['draws']??0),'losses'=>(int)($summaryRow['losses']??0),'our_points'=>(float)($summaryRow['our_points']??0),'their_points'=>(float)($summaryRow['their_points']??0),'boards'=>(int)($summaryRow['boards']??0),'average_duration_days'=>$summaryRow['average_duration_days']===null?null:round((float)$summaryRow['average_duration_days'],1)];
        $resultCovered=$summary['wins']+$summary['draws']+$summary['losses'];$resultMissing=max(0,$summary['finished']-$resultCovered);
        $summary['result_covered']=$resultCovered;$summary['result_missing']=$resultMissing;$summary['result_coverage_percent']=$summary['finished']>0?round(100*$resultCovered/$summary['finished'],1):100.0;

        $trendQuery=$this->pdo->prepare("SELECT DATE_FORMAT(COALESCE(metadata.end_time,metadata.start_time),'%Y-%m') month,COUNT(*) matches,SUM(metadata.status='finished' AND summaries.result='win') wins,SUM(metadata.status='finished' AND summaries.result='draw') draws,SUM(metadata.status='finished' AND summaries.result='loss') losses".$scopeJoin." AND COALESCE(metadata.end_time,metadata.start_time) IS NOT NULL GROUP BY month ORDER BY month");
        $trendQuery->execute([$clubSlug,$canonical]);$trend=array_map(static fn(array $r):array=>['month'=>(string)$r['month'],'matches'=>(int)$r['matches'],'wins'=>(int)$r['wins'],'draws'=>(int)$r['draws'],'losses'=>(int)$r['losses']],$trendQuery->fetchAll(PDO::FETCH_ASSOC)?:[]);

        $timeControls=[];$tcQuery=$this->pdo->prepare("SELECT COALESCE(NULLIF(TRIM(metadata.time_control),''),'Unknown') raw_time_control,COUNT(*) finished,SUM(summaries.result='win') wins,SUM(summaries.result='draw') draws,SUM(summaries.result='loss') losses".$scopeJoin." AND metadata.status='finished' AND summaries.result IN ('win','draw','loss') GROUP BY raw_time_control");
        $tcQuery->execute([$clubSlug,$canonical]);foreach($tcQuery->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$rawTc=(string)$row['raw_time_control'];$label=$rawTc;if(preg_match('/^1\/(\d+)$/',$rawTc,$tm)===1){$days=(int)$tm[1]/86400;$label=rtrim(rtrim(number_format($days,2,'.',''),'0'),'.').($days===1.0?' day / move':' days / move');}$timeControls[$rawTc]=['key'=>$rawTc,'label'=>$label,'finished'=>(int)$row['finished'],'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses']];}
        $maxRatingRates=[];$mrQuery=$this->pdo->prepare("SELECT metadata.max_rating,COUNT(*) finished,SUM(summaries.result='win') wins,SUM(summaries.result='draw') draws,SUM(summaries.result='loss') losses".$scopeJoin." AND metadata.status='finished' AND summaries.result IN ('win','draw','loss') AND metadata.max_rating IS NOT NULL AND metadata.max_rating>0 GROUP BY metadata.max_rating");
        $mrQuery->execute([$clubSlug,$canonical]);foreach($mrQuery->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$maxRating=(int)$row['max_rating'];$key=(string)$maxRating;$maxRatingRates[$key]=['key'=>$key,'label'=>'≤ '.$maxRating,'max_rating'=>$maxRating,'finished'=>(int)$row['finished'],'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses']];}

        $matchQuery=$this->pdo->prepare("SELECT metadata.match_id,metadata.match_name AS name,metadata.match_url AS url,metadata.status,metadata.start_time,metadata.end_time,metadata.board_count,metadata.p2k_score,metadata.opponent_score,summaries.result,summaries.competition_points,CASE WHEN metadata.start_time IS NOT NULL AND metadata.end_time IS NOT NULL THEN TIMESTAMPDIFF(SECOND,metadata.start_time,metadata.end_time)/86400 ELSE NULL END duration_days".$scopeJoin." ORDER BY COALESCE(metadata.end_time,metadata.start_time,metadata.last_verified_at) DESC LIMIT 200");
        $matchQuery->execute([$clubSlug,$canonical]);$matches=[];foreach($matchQuery->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$matches[]=['match_id'=>(int)$row['match_id'],'name'=>(string)$row['name'],'url'=>$row['url'],'status'=>(string)$row['status'],'start_time'=>$row['start_time'],'end_time'=>$row['end_time'],'boards'=>(int)$row['board_count'],'our_score'=>(float)$row['p2k_score'],'their_score'=>(float)$row['opponent_score'],'result'=>$row['result'],'club_points'=>(int)($row['competition_points']??0),'duration_days'=>$row['duration_days']===null?null:(float)$row['duration_days']];}

        // v2.10.6 Opponent-player intelligence: compact lineup facts are enough to
        // describe who we repeatedly face without issuing one profile request per player.
        $playerSql="SELECT LOWER(TRIM(b.opponent_username)) opponent_key,MAX(b.opponent_username) username,
                           COUNT(*) appearances,COUNT(DISTINCT b.match_id) matches,
                           ROUND(AVG(NULLIF(b.opponent_rating,0))) average_rating,MAX(NULLIF(b.opponent_rating,0)) max_rating,
                           MIN(COALESCE(metadata.start_time,metadata.end_time,metadata.last_verified_at)) first_seen_at,
                           MAX(COALESCE(metadata.start_time,metadata.end_time,metadata.last_verified_at)) last_seen_at,
                           COALESCE(SUM(bg.games),0) games,COALESCE(SUM(bg.wins),0) wins,COALESCE(SUM(bg.draws),0) draws,COALESCE(SUM(bg.losses),0) losses,
                           COALESCE(SUM(bg.points_x2),0)/2 p2k_points
                      FROM p2k_tp_boards b
                      JOIN p2k_tp_members member ON member.member_id=b.member_id
                      JOIN p2k_tp_match_metadata metadata ON metadata.club_slug=member.club_slug AND metadata.match_id=b.match_id
                      LEFT JOIN p2k_tp_opponent_aliases aliases ON aliases.club_slug=metadata.club_slug AND aliases.alias_slug=metadata.opponent_slug
                      LEFT JOIN (
                          SELECT board_id,COUNT(*) games,SUM(result_code='win') wins,SUM(result_code='draw') draws,SUM(result_code='loss') losses,SUM(points_x2) points_x2
                            FROM p2k_tp_games GROUP BY board_id
                      ) bg ON bg.board_id=b.board_id
                     WHERE member.club_slug=? AND metadata.is_void=0 AND COALESCE(aliases.canonical_slug,metadata.opponent_slug)=?
                       AND b.opponent_username IS NOT NULL AND TRIM(b.opponent_username)<>''
                     GROUP BY LOWER(TRIM(b.opponent_username))
                     ORDER BY appearances DESC,last_seen_at DESC,username ASC LIMIT 250";
        $pq=$this->pdo->prepare($playerSql);$pq->execute([$clubSlug,$canonical]);$players=[];
        foreach($pq->fetchAll(PDO::FETCH_ASSOC)?:[] as $row){$players[]=[
            'username'=>(string)$row['username'],'appearances'=>(int)$row['appearances'],'matches'=>(int)$row['matches'],
            'average_rating'=>$row['average_rating']===null?null:(int)$row['average_rating'],'max_rating'=>$row['max_rating']===null?null:(int)$row['max_rating'],
            'first_seen_at'=>$row['first_seen_at'],'last_seen_at'=>$row['last_seen_at'],'games'=>(int)$row['games'],
            'wins'=>(int)$row['wins'],'draws'=>(int)$row['draws'],'losses'=>(int)$row['losses'],'p2k_points'=>(float)$row['p2k_points'],
            'recent'=>(!empty($row['last_seen_at']) && (strtotime((string)$row['last_seen_at'].' UTC')?:0)>=time()-31536000),
        ];}
        $playerSummary=['unique_players'=>count($players),'recent_players'=>count(array_filter($players,static fn(array $r):bool=>(bool)$r['recent'])),
            'appearances'=>array_sum(array_column($players,'appearances')),'games'=>array_sum(array_column($players,'games'))];
        $ratedPlayers=array_values(array_filter($players,static fn(array $r):bool=>$r['average_rating']!==null));
        $playerSummary['average_rating']=$ratedPlayers?(int)round(array_sum(array_column($ratedPlayers,'average_rating'))/count($ratedPlayers)):null;
        $finished=max(0,$summary['finished']);
        $tags = [];
        if ($summary['total'] >= 10) $tags[] = 'Frequent opponent';
        if ($summary['ongoing'] > 0 || $summary['registered'] > 0) $tags[] = 'Currently active';
        if ($resultCovered >= 4 && abs($summary['wins']-$summary['losses']) <= max(1,(int)round($resultCovered*.2))) $tags[] = 'Balanced rivalry';
        if ($resultCovered >= 3 && $summary['wins']/$resultCovered >= .65) $tags[] = 'Strong historical record';
        if ((bool)($opponent['disabled']??false)) $tags[] = 'Inactive club';
        elseif ($finished >= 3 && $summary['ongoing'] === 0 && $summary['registered'] === 0) $tags[] = 'Good rematch candidate';
        $summary['average_boards'] = $summary['total'] ? round($summary['boards']/$summary['total'],1) : 0;
        $summary['win_rate'] = $resultCovered ? round(100*$summary['wins']/$resultCovered,1) : 0;
        $summary['balance'] = round($summary['our_points']-$summary['their_points'],1);
        $summary['matches_returned']=count($matches);$summary['match_list_complete']=count($matches)>=$summary['total'];
        unset($summary['boards']);
        foreach($timeControls as &$r)$r['win_rate']=$r['finished']?round(100*$r['wins']/$r['finished'],1):0;unset($r);
        foreach($maxRatingRates as &$r)$r['win_rate']=$r['finished']?round(100*$r['wins']/$r['finished'],1):0;unset($r);
        uasort($timeControls,static fn(array $a,array $b):int=>$b['finished']<=>$a['finished']?:strcmp((string)$a['label'],(string)$b['label']));
        ksort($maxRatingRates,SORT_NUMERIC);
        return [
            'slug'=>$canonical,'name'=>(string)$opponent['display_name'],'url'=>self::chessClubHumanUrl((string)($opponent['club_url']??''),$canonical),'disabled'=>(bool)$opponent['disabled'],
            'first_seen_at'=>$opponent['first_seen_at']??null,'last_seen_at'=>$opponent['last_seen_at']??null,'last_checked_at'=>$opponent['last_checked_at']??null,
            'tags'=>$tags,'summary'=>$summary,'coverage'=>['total_matches'=>$summary['total'],'detail_matches_returned'=>count($matches),'detail_list_complete'=>$summary['match_list_complete'],'finished_matches'=>$finished,'canonical_results'=>$resultCovered,'missing_finished_results'=>$resultMissing,'result_coverage_percent'=>$summary['result_coverage_percent']],
            'trend'=>array_values($trend),'time_controls'=>array_values($timeControls),'max_rating_rates'=>array_values($maxRatingRates),'rating_brackets'=>array_values($maxRatingRates),
            'player_summary'=>$playerSummary,'players'=>$players,'matches'=>$matches,
        ];
    }

    /** League and season roll-up for the administrator dashboard. */
    public function publicLeagueSeasons(string $clubSlug): array
    {
        $clubSlug = $this->resolveDataClubSlug($clubSlug);
        $query = $this->pdo->prepare(
            "SELECT metadata.match_id,metadata.match_name,metadata.match_url,metadata.status,metadata.start_time,metadata.end_time,metadata.board_count,
                    metadata.opponent_slug,metadata.opponent_name,summaries.result,summaries.competition_points
             FROM p2k_tp_match_metadata metadata LEFT JOIN p2k_tp_match_summaries summaries
               ON summaries.club_slug=metadata.club_slug AND summaries.match_id=metadata.match_id
             WHERE metadata.club_slug=? AND metadata.is_void=0 ORDER BY COALESCE(metadata.start_time,metadata.end_time,metadata.last_verified_at) DESC"
        );
        $query->execute([$clubSlug]);
        $leagues = ['1WL','TCMAC','KOTML','TMCL','WKCL','PCL','CW'];
        $groups = [];
        foreach ($query->fetchAll() ?: [] as $row) {
            $name = strtoupper((string)$row['match_name']);
            $league = null;
            foreach ($leagues as $candidate) {
                if (preg_match('/(?:^|[^A-Z0-9])' . preg_quote($candidate,'/') . '(?:[^A-Z0-9]|$)/i', $name)) { $league = $candidate; break; }
            }
            if ($league === null) continue;
            $dateValue = (string)($row['start_time'] ?: $row['end_time'] ?: '');
            $season = $dateValue !== '' ? substr($dateValue,0,4) : 'Unscheduled';
            $key = $league . ':' . $season;
            if (!isset($groups[$key])) $groups[$key] = [
                'league'=>$league,'season'=>$season,'matches'=>0,'registered'=>0,'ongoing'=>0,'finished'=>0,'wins'=>0,'draws'=>0,'losses'=>0,
                'boards'=>0,'club_points'=>0,'opponents'=>[],'upcoming'=>[],
            ];
            $group =& $groups[$key];
            $group['matches']++;
            $status = (string)$row['status'];
            if ($status === 'registered') $group['registered']++;
            elseif ($status === 'in_progress') $group['ongoing']++;
            elseif ($status === 'finished') $group['finished']++;
            $result = (string)($row['result']??'');
            $resultKey=['win'=>'wins','draw'=>'draws','loss'=>'losses'][$result]??null;
            if ($resultKey!==null) $group[$resultKey]++;
            $group['boards'] += (int)$row['board_count'];
            $group['club_points'] += (int)($row['competition_points']??0);
            if (!empty($row['opponent_slug'])) $group['opponents'][(string)$row['opponent_slug']] = (string)($row['opponent_name'] ?: $row['opponent_slug']);
            if ($status !== 'finished' && count($group['upcoming']) < 5) $group['upcoming'][] = [
                'match_id'=>(int)$row['match_id'],'name'=>(string)$row['match_name'],'url'=>$row['match_url'],'status'=>$status,'start_time'=>$row['start_time'],
                'opponent'=>(string)($row['opponent_name'] ?: $row['opponent_slug']),
            ];
            unset($group);
        }
        $rows = [];
        foreach ($groups as $group) {
            $group['opponents'] = array_values($group['opponents']);
            $rows[] = $group;
        }
        usort($rows, static fn(array $a,array $b): int => strcmp((string)$b['season'],(string)$a['season']) ?: strcmp((string)$a['league'],(string)$b['league']));
        return ['leagues'=>$leagues,'seasons'=>$rows];
    }

    public function publicInsightsHealth(string $clubSlug): array
    {
        $clubSlug=$this->resolveDataClubSlug($clubSlug);$checks=[];
        $definitions=[
            ['key'=>'team','label'=>'Team Insights','role'=>'analytics','table'=>'p2k_tp_insight_daily','date'=>'computed_at'],
            ['key'=>'members','label'=>'Members Insights','role'=>'core','table'=>'p2k_tp_members','date'=>'last_seen_at'],
            ['key'=>'matches','label'=>'Match Insights','role'=>'core','table'=>'p2k_tp_match_metadata','date'=>'last_verified_at'],
            ['key'=>'opponents','label'=>'Opponent Insights','role'=>'analytics','table'=>'p2k_an_opponent_stats','date'=>'updated_at'],
            ['key'=>'live','label'=>'Live ranks','role'=>'analytics','table'=>'p2k_lr_players','date'=>'updated_at'],
        ];
        foreach($definitions as $d){try{$pdo=$d['role']==='analytics'?$this->analytics():$this->pdo;$q=$pdo->prepare("SELECT COUNT(*) rows_count,MAX({$d['date']}) updated_at FROM {$d['table']} WHERE club_slug=?");$q->execute([$clubSlug]);$r=$q->fetch()?:[];$count=(int)($r['rows_count']??0);$checks[]=['key'=>$d['key'],'label'=>$d['label'],'status'=>$count>0?'healthy':'empty','rows'=>$count,'last_update'=>$r['updated_at']??null,'source'=>$d['role'].' database'];}catch(\Throwable $e){$checks[]=['key'=>$d['key'],'label'=>$d['label'],'status'=>'error','rows'=>0,'last_update'=>null,'source'=>$d['role'].' database','message'=>$e->getMessage()];}}
        return ['checks'=>$checks,'healthy'=>count(array_filter($checks,static fn(array $r):bool=>$r['status']==='healthy')),'total'=>count($checks),'architecture'=>'core+analytics'];
    }

    public function opponentAdminRows(string $clubSlug): array
    {
        $query = $this->pdo->prepare(
            "SELECT o.opponent_slug,o.display_name,o.club_url,o.disabled,o.last_seen_at,o.last_checked_at,o.last_error,
                    COUNT(DISTINCT m.match_id) AS matches
             FROM p2k_tp_opponents o LEFT JOIN p2k_tp_match_metadata m
               ON m.club_slug=o.club_slug AND m.opponent_slug=o.opponent_slug AND m.is_void=0
             WHERE o.club_slug=? GROUP BY o.opponent_slug,o.display_name,o.club_url,o.disabled,o.last_seen_at,o.last_checked_at,o.last_error
             ORDER BY o.display_name"
        );
        $query->execute([$clubSlug]);
        return $query->fetchAll() ?: [];
    }

    public function matchPayloadCandidateForOpponent(string $clubSlug, string $opponentSlug): ?array
    {
        $query = $this->pdo->prepare(
            "SELECT match_id,match_url,match_name FROM p2k_tp_match_metadata
             WHERE club_slug=? AND opponent_slug=? ORDER BY status='finished' DESC,match_id DESC LIMIT 1"
        );
        $query->execute([$clubSlug,$opponentSlug]);
        $row = $query->fetch();
        return is_array($row)?$row:null;
    }

    public function recordOpponentCheck(string $clubSlug, string $oldSlug, ?string $newSlug, ?string $newName, bool $disabled, ?string $error): void
    {
        $query = $this->pdo->prepare(
            "UPDATE p2k_tp_opponents SET disabled=?,last_checked_at=UTC_TIMESTAMP(),last_error=? WHERE club_slug=? AND opponent_slug=?"
        );
        $query->execute([$disabled?1:0,$error,$clubSlug,$oldSlug]);
        if ($newSlug !== null && $newSlug !== '' && $newSlug !== $oldSlug) {
            $name = trim((string)$newName) ?: str_replace('-',' ',$newSlug);
            $upsert = $this->pdo->prepare(
                "INSERT INTO p2k_tp_opponents(club_slug,opponent_slug,display_name,club_url,disabled,first_seen_at,last_seen_at,last_checked_at,last_error)
                 VALUES(?,?,?,?,0,UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),NULL)
                 ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),club_url=VALUES(club_url),disabled=0,last_checked_at=UTC_TIMESTAMP(),last_error=NULL"
            );
            $upsert->execute([$clubSlug,$newSlug,$name,'https://www.chess.com/club/'.$newSlug]);
            $alias = $this->pdo->prepare(
                "INSERT INTO p2k_tp_opponent_aliases(club_slug,alias_slug,canonical_slug,alias_name,detected_at)
                 VALUES(?,?,?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE canonical_slug=VALUES(canonical_slug),alias_name=VALUES(alias_name),detected_at=UTC_TIMESTAMP()"
            );
            $alias->execute([$clubSlug,$oldSlug,$newSlug,$newName]);
        } elseif ($newName !== null && trim($newName) !== '') {
            $rename = $this->pdo->prepare('UPDATE p2k_tp_opponents SET display_name=? WHERE club_slug=? AND opponent_slug=?');
            $rename->execute([trim($newName),$clubSlug,$oldSlug]);
        }
    }

    public function discoveryState(string $clubSlug, string $sourceKey): array
    {
        $query = $this->pdo->prepare('SELECT * FROM p2k_tp_match_discovery_state WHERE club_slug=? AND source_key=? LIMIT 1');
        $query->execute([$clubSlug,$sourceKey]);
        return $query->fetch() ?: [];
    }

    public function saveDiscoveryState(string $clubSlug, string $sourceKey, ?int $cursor, ?int $lower, ?int $upper, ?int $lastSuccess, int $scannedAdd, int $matchedAdd): void
    {
        $query = $this->pdo->prepare(
            "INSERT INTO p2k_tp_match_discovery_state(club_slug,source_key,cursor_match_id,lower_bound_match_id,upper_bound_match_id,last_success_match_id,scanned_count,matched_count,updated_at)
             VALUES(?,?,?,?,?,?,?, ?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE cursor_match_id=VALUES(cursor_match_id),lower_bound_match_id=COALESCE(VALUES(lower_bound_match_id),lower_bound_match_id),
                upper_bound_match_id=COALESCE(VALUES(upper_bound_match_id),upper_bound_match_id),last_success_match_id=COALESCE(VALUES(last_success_match_id),last_success_match_id),
                scanned_count=scanned_count+VALUES(scanned_count),matched_count=matched_count+VALUES(matched_count),updated_at=UTC_TIMESTAMP()"
        );
        $query->execute([$clubSlug,$sourceKey,$cursor,$lower,$upper,$lastSuccess,$scannedAdd,$matchedAdd]);
    }

    public function knownMatchBounds(string $clubSlug): array
    {
        $query = $this->pdo->prepare(
            "SELECT MIN(match_id) AS minimum,MAX(match_id) AS maximum FROM (
                SELECT match_id FROM p2k_tp_match_metadata WHERE club_slug=?
                UNION ALL SELECT match_id FROM p2k_tp_participations WHERE club_slug=?
                UNION ALL SELECT match_id FROM p2k_tp_match_summaries WHERE club_slug=?
             ) known_matches"
        );
        $query->execute([$clubSlug,$clubSlug,$clubSlug]);
        $row = $query->fetch() ?: [];
        return ['minimum'=>(int)($row['minimum']??0),'maximum'=>(int)($row['maximum']??0)];
    }

    public static function chessClubSlugFromUrl(string $value): string
    {
        $value=trim($value); if($value==='') return '';
        $path=trim((string)(parse_url($value,PHP_URL_PATH) ?: ''),'/');
        if($path==='') return '';
        $parts=array_values(array_filter(explode('/',$path),static fn($v)=>$v!==''));
        $last=strtolower((string)end($parts));
        if($last==='' || ctype_digit($last) || !preg_match('/^[a-z0-9_-]{1,160}$/',$last)) return '';
        return $last;
    }

    /** Normalize any Chess.com club/API reference to the human-facing club URL. */
    public static function chessClubHumanUrl(string $value, string $fallbackSlug=''): string
    {
        $slug=self::chessClubSlugFromUrl($value);
        if($slug===''){
            $fallback=strtolower(trim($fallbackSlug));
            if($fallback!=='' && preg_match('/^[a-z0-9_-]{1,160}$/',$fallback))$slug=$fallback;
        }
        if($slug!=='')return 'https://www.chess.com/club/'.rawurlencode($slug);
        $value=trim($value);
        return preg_match('~^https://www\.chess\.com/club/[a-z0-9_-]+/?$~i',$value)===1?$value:'';
    }

    public static function clubSlugFromTeamPayload(array $team, string $fallback=''): string
    {
        foreach (['@id','url','club_url'] as $field) {
            $slug=self::chessClubSlugFromUrl((string)($team[$field] ?? ''));
            if($slug!=='') return $slug;
        }
        $explicit=strtolower(trim((string)($team['slug'] ?? '')));
        if($explicit!=='' && preg_match('/^[a-z0-9_-]{1,160}$/',$explicit)) return $explicit;
        $value=strtolower(trim((string)($team['name'] ?? $fallback)));
        $value=preg_replace('/[^a-z0-9]+/','-',$value) ?: strtolower(trim($fallback));
        return trim($value,'-');
    }

    private function teamSlugFromPayload(array $team, string $fallback): string
    {
        return self::clubSlugFromTeamPayload($team,$fallback);
    }

    private function timestampToSql(mixed $value): ?string
    {
        $timestamp = (int)$value;
        if ($timestamp <= 0) return null;
        return (new \DateTimeImmutable('@'.$timestamp))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function validatedMonthRange(string $startMonth, string $endMonth): array
    {
        $timezone = new DateTimeZone('UTC');
        $start = DateTimeImmutable::createFromFormat('!Y-m', $startMonth, $timezone);
        $end = DateTimeImmutable::createFromFormat('!Y-m', $endMonth, $timezone);
        if (!$start || !$end || $start > $end || $start->format('Y-m') !== $startMonth || $end->format('Y-m') !== $endMonth) {
            throw new ApiException('Invalid month range.', 400, 'INVALID_MONTH_RANGE');
        }
        if (((int)$end->format('Y') - (int)$start->format('Y')) * 12 + (int)$end->format('n') - (int)$start->format('n') > 240) {
            throw new ApiException('The selected range cannot exceed 20 years.', 400, 'MONTH_RANGE_TOO_LARGE');
        }
        return [$start, $end, $start->format('Y-m-01'), $end->modify('first day of next month')->format('Y-m-01')];
    }

    private function validatedSort(string $sortBy, string $sortDir, array $allowed, string $defaultColumn, string $defaultDirection): array
    {
        $column = in_array($sortBy, $allowed, true) ? $sortBy : $defaultColumn;
        $direction = strtolower($sortDir);
        if (!in_array($direction, ['asc','desc'], true)) {
            $direction = $defaultDirection;
        }
        return [$column, $direction];
    }
}
