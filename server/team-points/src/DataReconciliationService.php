<?php
declare(strict_types=1);

namespace P2K\TeamPoints;

use PDO;
use P2K\Shared\FilesystemCache;

final class DataReconciliationService
{
    private string $clubSlug;
    private Repository $repo;
    private PDO $pdo;
    private string $root;

    public function __construct(string $clubSlug, Repository $repo)
    {
        $this->clubSlug = strtolower(trim($clubSlug));
        $this->repo = $repo;
        $this->pdo = $repo->core();
        $config = \p2k_tp_config();
        $storage = is_array($config['storage'] ?? null) ? $config['storage'] : [];
        $this->root = FilesystemCache::runtimeRoot($storage) . '/reconciliation';
        FilesystemCache::ensureProtectedDirectory($this->root);
    }

    public function createBatch(array $uploads): array
    {
        $id = gmdate('Ymd-His') . '-' . substr(str_replace('-', '', \p2k_tp_uuid()), 0, 10);
        $dir = $this->batchDir($id);
        FilesystemCache::ensureProtectedDirectory($dir);
        $files = [];
        foreach ($uploads as $upload) {
            if (!is_array($upload)) continue;
            $tmp = (string)($upload['tmp_name'] ?? '');
            $name = basename((string)($upload['name'] ?? 'upload.csv'));
            $size = (int)($upload['size'] ?? 0);
            $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
                throw new ApiException('One of the reconciliation files could not be uploaded.', 400, 'UPLOAD_FAILED');
            }
            if ($size <= 0 || $size > 32 * 1024 * 1024) {
                throw new ApiException('Each reconciliation file must be between 1 byte and 32 MB.', 400, 'UPLOAD_SIZE_INVALID');
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true)) {
                throw new ApiException('Only CSV and TXT reconciliation inputs are accepted.', 400, 'UPLOAD_TYPE_INVALID');
            }
            $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?: ('upload.' . $ext);
            $dest = $dir . '/' . $safe;
            if (!move_uploaded_file($tmp, $dest)) {
                throw new \RuntimeException('Unable to store uploaded reconciliation file.');
            }
            @chmod($dest, 0600);
            $files[] = ['name'=>$name,'stored_name'=>$safe,'bytes'=>$size,'sha256'=>hash_file('sha256',$dest) ?: ''];
        }
        if ($files === []) throw new ApiException('Upload at least one reconciliation file.', 400, 'UPLOAD_REQUIRED');
        $meta = ['batch_id'=>$id,'club_slug'=>$this->clubSlug,'created_at'=>gmdate(DATE_ATOM),'files'=>$files,'status'=>'uploaded'];
        $this->writeJson($dir . '/batch.json', $meta);
        return $meta;
    }

    public function batch(string $id): array
    {
        $dir = $this->batchDir($id);
        $meta = $this->readJson($dir . '/batch.json');
        if ($meta === []) throw new ApiException('Reconciliation batch not found.', 404, 'BATCH_NOT_FOUND');
        $report = $this->readJson($dir . '/report.json');
        $plan = $this->readJson($dir . '/plan.json');
        return ['batch'=>$meta,'report'=>$report ?: null,'plan'=>$plan ? $this->planSummary($plan) : null,'apply_state'=>$this->readJson($dir . '/apply-state.json') ?: null];
    }

    public function inspect(string $id): array
    {
        $dir = $this->batchDir($id);
        $meta = $this->readJson($dir . '/batch.json');
        if ($meta === []) throw new ApiException('Reconciliation batch not found.', 404, 'BATCH_NOT_FOUND');
        $recognized = [];
        foreach ($meta['files'] ?? [] as $file) {
            $path = $dir . '/' . basename((string)$file['stored_name']);
            $recognized[] = $this->recognize($path, (string)$file['name']);
        }
        $byType = [];
        foreach ($recognized as $item) {
            $type = (string)$item['type'];
            if ($type === 'unknown') continue;
            if (isset($byType[$type])) throw new ApiException('A reconciliation batch may contain only one file of each recognized family.', 400, 'DUPLICATE_FILE_FAMILY');
            $byType[$type] = $item;
        }
        foreach (['finished','active','daily','findings'] as $required) {
            if (!isset($byType[$required])) throw new ApiException("The batch is missing the {$required} reconciliation input.", 400, 'REQUIRED_FILE_MISSING');
        }

        $issues = [];
        $finished = $this->loadMatchCsv($byType['finished']['path']);
        $active = $this->loadMatchCsv($byType['active']['path']);
        $master = $finished['rows'] + $active['rows'];
        $findings = $this->loadFindings($byType['findings']['path']);
        $daily = $this->loadDaily($byType['daily']['path']);
        $parsedMembers = isset($byType['parsedmembers']) ? $this->loadParsedMembers($byType['parsedmembers']['path'], $master, $finished['rows']) : ['rows'=>[], 'issues'=>[], 'board_seeds'=>[]];
        $members = isset($byType['allmembers']) ? $this->loadMembers($byType['allmembers']['path']) : ['rows'=>[], 'issues'=>[]];
        $parsedData = isset($byType['parseddata']) ? $this->validateParsedData($byType['parseddata']['path'], array_keys($master)) : ['issues'=>[],'match_ids'=>[]];
        $issues = array_merge($issues,$finished['issues'],$active['issues'],$daily['issues'],$parsedMembers['issues'],$members['issues'],$parsedData['issues']);

        $masterIds = array_fill_keys(array_keys($master), true);
        foreach ($findings as $mid => $_) {
            if (!isset($masterIds[$mid])) $issues[]=$this->issue('warning','discovery_gap',(string)$mid,'Match is present in findings but absent from active/finished exports.','queue_sync_match');
        }
        foreach ($parsedData['match_ids'] as $mid => $_) {
            if (!isset($masterIds[$mid])) $issues[]=$this->issue('warning','parseddata_extra',(string)$mid,'Match is in parseddata but absent from active/finished exports.','queue_sync_match');
        }

        $dailyCheck = $this->checkDailyAgainstMatches($daily['rows'], $finished['rows'], $active['rows']);
        $issues = array_merge($issues, $dailyCheck['issues']);
        $production = $this->compareProduction($master, $dailyCheck['checkpoint']);
        $issues = array_merge($issues, $production['issues']);

        $queueMatches = [];
        foreach ($issues as $issue) {
            if (str_contains((string)$issue['recommended_action'], 'sync_match')) {
                $mid = (int)$issue['key']; if ($mid > 0) $queueMatches[$mid] = true;
            }
        }
        foreach ($production['missing_match_ids'] as $mid) $queueMatches[(int)$mid] = true;
        foreach ($production['conflicting_match_ids'] as $mid) $queueMatches[(int)$mid] = true;

        $plan = [
            'batch_id'=>$id,
            'generated_at'=>gmdate(DATE_ATOM),
            'checkpoint'=>$dailyCheck['checkpoint'],
            'queue_sync_match'=>array_values(array_map('intval',array_keys($queueMatches))),
            'member_positive_seeds'=>$members['rows'],
            'board_positive_seeds'=>$parsedMembers['board_seeds'],
            'policy'=>[
                'conflicting_scores_results_points'=>'authoritative_sync_only',
                'member_absence'=>'never_demote_from_csv',
                'positive_member_presence'=>'safe_seed_then_roster_refresh',
                'board_ownership'=>'seed_only_if_non_conflicting_then_authoritative_board_sync',
                'daily_checkpoint'=>'dated_prefix_checksum_not_terminal_total',
            ],
        ];
        $report = [
            'batch_id'=>$id,
            'checked_at'=>gmdate(DATE_ATOM),
            'recognized_files'=>array_map(static fn(array $x): array => ['name'=>$x['name'],'type'=>$x['type'],'sha256'=>$x['sha256'],'bytes'=>$x['bytes']],$recognized),
            'counts'=>[
                'finished'=>count($finished['rows']),'active'=>count($active['rows']),'findings'=>count($findings),'members'=>count($members['rows']),
                'parsedmember_rows'=>$parsedMembers['row_count'] ?? count($parsedMembers['rows']),'board_seed_candidates'=>count($parsedMembers['board_seeds']),
            ],
            'daily'=>$dailyCheck,
            'production'=>$production['summary'],
            'issues'=>$issues,
            'issues_by_severity'=>array_count_values(array_map(static fn(array $x):string=>(string)$x['severity'],$issues)),
        ];
        $meta['status']='checked';$meta['checked_at']=gmdate(DATE_ATOM);$this->writeJson($dir.'/batch.json',$meta);$this->writeJson($dir.'/report.json',$report);$this->writeJson($dir.'/plan.json',$plan);
        return ['batch'=>$meta,'report'=>$report,'plan'=>$this->planSummary($plan)];
    }

    /**
     * Apply only positive seeds and targeted authoritative queue work.
     * CSV conflicts never overwrite canonical score/result/point fields.
     */
    public function applyStep(string $id, string $confirmation, array $options, int $limit = 250): array
    {
        if (!hash_equals('APPLY ' . $id, trim($confirmation))) throw new ApiException('Type the exact batch confirmation phrase before applying.',400,'CONFIRMATION_REQUIRED');
        $dir=$this->batchDir($id);$plan=$this->readJson($dir.'/plan.json');if($plan===[])throw new ApiException('Run the reconciliation check before applying.',409,'PLAN_REQUIRED');
        $state=$this->readJson($dir.'/apply-state.json');
        if($state===[])$state=['batch_id'=>$id,'phase'=>'matches','cursor'=>0,'queued_matches'=>0,'seeded_members'=>0,'seeded_boards'=>0,'queued_boards'=>0,'started_at'=>gmdate(DATE_ATOM),'complete'=>false];
        $limit=max(1,min(1000,$limit));$remaining=$limit;
        $clubJob=$this->repo->createOrGetActiveJob($this->clubSlug,'club');$playerJob=$this->repo->createOrGetActiveJob($this->clubSlug,'player');
        $queueMatches=(bool)($options['queue_match_revalidation']??true);$seedMembers=(bool)($options['seed_members']??true);$seedBoards=(bool)($options['seed_boards']??true);
        while($remaining>0 && empty($state['complete'])){
            if($state['phase']==='matches'){
                $rows=$plan['queue_sync_match']??[];$i=(int)$state['cursor'];
                if($i>=count($rows)){ $state['phase']='members';$state['cursor']=0;continue; }
                $mid=(int)$rows[$i];$state['cursor']=$i+1;$remaining--;
                if($queueMatches && $mid>0 && $this->repo->enqueue((string)$clubJob['id'],'sync_match','reconcile:'.$id.':'.$mid,['match_id'=>$mid,'source'=>'csv_reconciliation','priority_discovery'=>true,'reconciliation_batch'=>$id]))$state['queued_matches']++;
                continue;
            }
            if($state['phase']==='members'){
                $rows=$plan['member_positive_seeds']??[];$i=(int)$state['cursor'];
                if($i>=count($rows)){ $state['phase']='boards';$state['cursor']=0;continue; }
                $row=$rows[$i];$state['cursor']=$i+1;$remaining--;
                if($seedMembers){$this->repo->upsertMember($this->clubSlug,(string)$row['username'],isset($row['joined'])?(int)$row['joined']:null);$state['seeded_members']++;}
                continue;
            }
            if($state['phase']==='boards'){
                $rows=$plan['board_positive_seeds']??[];$i=(int)$state['cursor'];
                if($i>=count($rows)){ $state['phase']='roster_refresh';$state['cursor']=0;continue; }
                $row=$rows[$i];$state['cursor']=$i+1;$remaining--;
                if($seedBoards){
                    $reg=$this->repo->registerBoardDiscovery($this->clubSlug,(string)$row['username'],(int)$row['match_id'],(string)$row['board_url'],(string)$row['source_bucket']);$state['seeded_boards']++;
                    if(!empty($reg['due']) && $this->repo->enqueue((string)$playerJob['id'],'sync_board',\p2k_tp_username_key((string)$row['username']).'|'.hash('sha256',(string)$row['board_url']),['username'=>(string)$row['username'],'match_id'=>(int)$row['match_id'],'board_url'=>(string)$row['board_url'],'source_bucket'=>(string)$row['source_bucket'],'board_state'=>(string)($reg['state']??'newly_discovered'),'rediscovered'=>true,'reconciliation_batch'=>$id]))$state['queued_boards']++;
                }
                continue;
            }
            if($state['phase']==='roster_refresh'){
                $remaining--;$state['phase']='done';$state['cursor']=0;
                $this->repo->enqueue((string)$playerJob['id'],'sync_members','reconcile:'.$id.':authoritative-roster',['club_slug'=>$this->clubSlug,'lane'=>'player','reconcile_current_members'=>true,'source'=>'csv_reconciliation','reconciliation_batch'=>$id]);
                continue;
            }
            $state['complete']=true;$state['completed_at']=gmdate(DATE_ATOM);break;
        }
        if($state['phase']==='done'){$state['complete']=true;$state['completed_at']=$state['completed_at']??gmdate(DATE_ATOM);}
        $this->writeJson($dir.'/apply-state.json',$state);
        return $state;
    }

    private function compareProduction(array $master, array $checkpoint): array
    {
        $q=$this->pdo->prepare('SELECT match_id,status,board_count,p2k_score,opponent_score,result,competition_points,is_void,UNIX_TIMESTAMP(start_time) start_epoch,UNIX_TIMESTAMP(end_time) end_epoch FROM p2k_tp_match_metadata WHERE club_slug=?');
        $q->execute([$this->clubSlug]);$prod=[];foreach($q->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$prod[(int)$r['match_id']]=$r;
        $missing=[];$conflicts=[];$issues=[];
        foreach($master as $mid=>$src){
            if(!isset($prod[$mid])){$missing[]=$mid;continue;}
            $p=$prod[$mid];$bad=[];
            $expectedStatus=(string)$src['status'];if($expectedStatus==='registration')$expectedStatus='registered';
            if($expectedStatus!=='' && (string)$p['status']!==$expectedStatus)$bad[]='status';
            if((int)$src['boards']>0 && (int)$p['board_count']!==(int)$src['boards'])$bad[]='board_count';
            if(!empty($src['internally_sane'])){
                if(abs((float)$p['p2k_score']-(float)$src['our_score'])>0.001)$bad[]='p2k_score';
                if(abs((float)$p['opponent_score']-(float)$src['their_score'])>0.001)$bad[]='opponent_score';
                if($src['status']==='finished' && (string)$p['result']!==(string)$src['result'])$bad[]='result';
                if($src['status']==='finished' && (int)$p['competition_points']!==(int)$src['points'])$bad[]='competition_points';
            }
            if($bad!==[]){$conflicts[]=$mid;$issues[]=$this->issue('warning','production_conflict',(string)$mid,'Production differs from an internally sane CSV row in: '.implode(', ',$bad).'.','queue_sync_match');}
        }
        $prefix=['count'=>0,'points'=>0];
        if(!empty($checkpoint['end_epoch'])){
            $s=$this->pdo->prepare('SELECT COUNT(*) c,COALESCE(SUM(competition_points),0) p FROM p2k_tp_match_metadata WHERE club_slug=? AND status=\'finished\' AND is_void=0 AND end_time<=FROM_UNIXTIME(?)');
            $s->execute([$this->clubSlug,(int)$checkpoint['end_epoch']]);$r=$s->fetch(PDO::FETCH_ASSOC)?:[];$prefix=['count'=>(int)($r['c']??0),'points'=>(int)($r['p']??0)];
            if($prefix['count']!==(int)($checkpoint['cum_finished']??-1) || $prefix['points']!==(int)($checkpoint['cum_points']??-1))$issues[]=$this->issue('warning','production_checkpoint','daily_prefix','Production prefix through the DailyData checkpoint is '.$prefix['count'].' non-void finished / '.$prefix['points'].' points; expected '.(int)$checkpoint['cum_finished'].' / '.(int)$checkpoint['cum_points'].'.','targeted_prefix_reconciliation');
        }
        return ['missing_match_ids'=>$missing,'conflicting_match_ids'=>$conflicts,'issues'=>$issues,'summary'=>['known_production_matches'=>count($prod),'missing_from_production'=>count($missing),'field_conflicts'=>count($conflicts),'checkpoint_prefix'=>$prefix]];
    }

    private function checkDailyAgainstMatches(array $daily, array $finished, array $active): array
    {
        $issues=[];$byEnd=[];$byStart=[];$last=null;
        foreach($finished as $r){
            if(!$r['is_void']){
                $day=gmdate('Y-m-d',(int)$r['end_epoch']);
                $byEnd[$day]['finished']=($byEnd[$day]['finished']??0)+1;
                $byEnd[$day]['points']=($byEnd[$day]['points']??0)+(int)$r['points'];
                if((int)$r['start_epoch']>0){$sd=gmdate('Y-m-d',(int)$r['start_epoch']);$byStart[$sd]=($byStart[$sd]??0)+1;}
            }
        }
        foreach($active as $r){if(!$r['is_void'] && (int)$r['start_epoch']>0){$sd=gmdate('Y-m-d',(int)$r['start_epoch']);$byStart[$sd]=($byStart[$sd]??0)+1;}}
        $cumStarted=$cumFinished=$cumPoints=0;$lastIndex=max(0,count($daily)-1);
        foreach($daily as $i=>$row){
            $day=(string)$row['day'];$started=(int)$row['started'];$finishedN=(int)$row['finished'];$points=(int)$row['points'];$cumStarted+=$started;$cumFinished+=$finishedN;$cumPoints+=$points;
            // All complete days must reconcile exactly. The final day may have newer export rows after DailyData was emitted.
            if($i<$lastIndex){
                if($started!==(int)($byStart[$day]??0))$issues[]=$this->issue('error','daily_start_mismatch',$day,"DailyData started={$started}; match timestamps=".(int)($byStart[$day]??0).'.','reject_checkpoint');
                if($finishedN!==(int)($byEnd[$day]['finished']??0))$issues[]=$this->issue('error','daily_finish_mismatch',$day,"DailyData finished={$finishedN}; non-void finished export=".(int)($byEnd[$day]['finished']??0).'.','reject_checkpoint');
                if($points!==(int)($byEnd[$day]['points']??0))$issues[]=$this->issue('error','daily_points_mismatch',$day,"DailyData points={$points}; finished export=".(int)($byEnd[$day]['points']??0).'.','reject_checkpoint');
            }
            if($cumStarted!==(int)$row['cum_started']||$cumFinished!==(int)$row['cum_finished']||$cumPoints!==(int)$row['cum_points'])$issues[]=$this->issue('error','daily_arithmetic',$day,'DailyData cumulative arithmetic is inconsistent.','reject_checkpoint');
            $last=$row;
        }

        // Resolve the dated checkpoint by chronological non-void finish prefix, not by end-of-day.
        // This permits production/export matches that finished later on the same UTC day.
        $endEpoch=0;$prefixCount=0;$prefixPoints=0;$prefixFound=false;
        $ordered=array_values(array_filter($finished,static fn(array $r): bool => !$r['is_void'] && (int)$r['end_epoch']>0));
        usort($ordered,static fn(array $a,array $b): int => ((int)$a['end_epoch'] <=> (int)$b['end_epoch']) ?: ((int)$a['match_id'] <=> (int)$b['match_id']));
        $targetCount=(int)($last['cum_finished']??0);$targetPoints=(int)($last['cum_points']??0);
        foreach($ordered as $r){
            $prefixCount++;$prefixPoints+=(int)$r['points'];
            if($prefixCount===$targetCount){
                if($prefixPoints===$targetPoints){$endEpoch=(int)$r['end_epoch'];$prefixFound=true;}
                break;
            }
        }
        if($last && !$prefixFound)$issues[]=$this->issue('error','daily_prefix_checkpoint',(string)$last['day'],'No chronological non-void finished-match prefix matches DailyData cumulative count/points '.$targetCount.' / '.$targetPoints.'.','reject_checkpoint');

        $checkpoint=['date'=>$last['day']??null,'end_epoch'=>$endEpoch,'cum_started'=>$last['cum_started']??0,'cum_finished'=>$last['cum_finished']??0,'in_progress'=>$last['in_progress']??0,'cum_points'=>$last['cum_points']??0,'valid'=>$issues===[],'prefix_resolved'=>$prefixFound];
        return ['issues'=>$issues,'checkpoint'=>$checkpoint,'days'=>count($daily),'point_mismatch_days'=>count(array_filter($issues,static fn($x)=>$x['category']==='daily_points_mismatch')),'finish_mismatch_days'=>count(array_filter($issues,static fn($x)=>$x['category']==='daily_finish_mismatch')),'start_mismatch_days'=>count(array_filter($issues,static fn($x)=>$x['category']==='daily_start_mismatch'))];
    }

    private function loadMatchCsv(string $path): array
    {
        $rows=[];$issues=[];$fh=fopen($path,'rb');if(!$fh)throw new \RuntimeException('Unable to read match CSV.');$h=fgetcsv($fh);$idx=array_flip(array_map('trim',$h?:[]));
        while(($r=fgetcsv($fh))!==false){$get=static fn(string $k) => $r[$idx[$k]??-1]??'';$mid=$this->matchId($get('MatchAPI'));if($mid<=0)continue;$status=strtolower(trim($get('Status')));$boards=(int)$get('Boards');$our=(float)$get('OurScore');$their=(float)$get('TheirScore');$result=strtolower(trim($get('OurResult')));if($result==='lose')$result='loss';$points=(int)$get('OurFinalPoints');$start=(int)$get('StartTimeUnix');$end=(int)$get('EndTimeUnix');$isVoid=$status==='finished'&&abs($our)<0.001&&abs($their)<0.001;$sane=true;
            if($status==='finished'&&!$isVoid){$scoreOk=abs(($our+$their)-(2*$boards))<0.001;$resultOk=($result==='win'&&$our>$their)||($result==='loss'&&$our<$their)||($result==='draw'&&abs($our-$their)<0.001);$expected=$result==='win'?5*$boards:($result==='draw'?2*$boards:0);if(!$scoreOk){$issues[]=$this->issue('warning','score_total',(string)$mid,"{$boards} boards but score total ".($our+$their)." instead of ".(2*$boards).'.','queue_sync_match');$sane=false;}if(!$resultOk){$issues[]=$this->issue('error','score_result',(string)$mid,"Result {$result} conflicts with score {$our}-{$their}.",'queue_sync_match');$sane=false;}if($points!==$expected){$issues[]=$this->issue('error','club_points_formula',(string)$mid,"Competition points {$points}; expected {$expected}.",'queue_sync_match');$sane=false;}}
            $display=trim($get('StartDateUTC'));if($start>0&&$display!==''&&$display!=='01/01/1970'){$dt=\DateTimeImmutable::createFromFormat('!d/m/Y',$display,new \DateTimeZone('UTC'));if($dt&&$dt->format('Y-m-d')!==gmdate('Y-m-d',$start))$issues[]=$this->issue('warning','formatted_date',(string)$mid,"StartDateUTC {$display} disagrees with StartTimeUnix ".gmdate('Y-m-d',$start).'. Unix timestamp will be used.','use_unix_timestamp');}
            $rows[$mid]=['match_id'=>$mid,'status'=>$status==='registration'?'registered':$status,'boards'=>$boards,'our_score'=>$our,'their_score'=>$their,'result'=>$result,'points'=>$points,'start_epoch'=>$start,'end_epoch'=>$end,'is_void'=>$isVoid,'internally_sane'=>$sane];
        }fclose($fh);return ['rows'=>$rows,'issues'=>$issues];
    }

    private function loadDaily(string $path): array
    {
        $fh=fopen($path,'rb');$h=fgetcsv($fh);$idx=array_flip(array_map('trim',$h?:[]));$rows=[];$issues=[];$previous=null;
        while(($r=fgetcsv($fh))!==false){$get=static fn(string $k)=>trim((string)($r[$idx[$k]??-1]??''));$dt=\DateTimeImmutable::createFromFormat('!d/m/Y',$get('Date'),new \DateTimeZone('UTC'));if(!$dt){$issues[]=$this->issue('error','daily_date',$get('Date'),'Invalid DailyData date.','reject_checkpoint');continue;}$day=$dt->format('Y-m-d');if($previous&&$dt->getTimestamp()-$previous->getTimestamp()!==86400)$issues[]=$this->issue('error','daily_gap',$day,'DailyData contains a calendar gap.','reject_checkpoint');$previous=$dt;$rows[]=['day'=>$day,'started'=>(int)$get('Games started'),'finished'=>(int)$get('Games finished'),'cum_started'=>(int)$get('Cumulated started games'),'cum_finished'=>(int)$get('Cumulated finished games'),'in_progress'=>(int)$get('Games in progress'),'points'=>(int)$get('Points'),'cum_points'=>(int)$get('Cumulated points')];}
        fclose($fh);return ['rows'=>$rows,'issues'=>$issues];
    }

    private function loadFindings(string $path): array { $out=[];foreach(file($path,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES)?:[] as $line){$mid=$this->matchId($line);if($mid>0)$out[$mid]=true;}return $out; }

    private function loadMembers(string $path): array
    {
        $fh=fopen($path,'rb');$h=fgetcsv($fh);$idx=array_flip(array_map('trim',$h?:[]));$rows=[];$issues=[];$seen=[];
        while(($r=fgetcsv($fh))!==false){$u=trim((string)($r[$idx['PlayerName']??-1]??''));$joined=(int)($r[$idx['Joined']??-1]??0);$key=strtolower($u);if($u===''||!preg_match('/^[A-Za-z0-9_-]{1,80}$/',$u)){$issues[]=$this->issue('warning','member_name',$u,'Invalid member username.','reject_row');continue;}if(isset($seen[$key])){$issues[]=$this->issue('error','member_duplicate',$u,'Duplicate current-member username.','reject_row');continue;}$seen[$key]=true;$rows[]=['username'=>$u,'joined'=>$joined>0?$joined:null];}
        fclose($fh);return ['rows'=>$rows,'issues'=>$issues];
    }

    private function loadParsedMembers(string $path,array $master,array $finished): array
    {
        $fh=fopen($path,'rb');$h=fgetcsv($fh);$idx=array_flip(array_map('trim',$h?:[]));$issues=[];$seeds=[];$seen=[];$count=0;$finishedIds=array_fill_keys(array_keys($finished),true);$missingEnd=0;$finishedTbd=0;
        while(($r=fgetcsv($fh))!==false){$count++;$get=static fn(string $k)=>trim((string)($r[$idx[$k]??-1]??''));$u=$get('Player');$mid=$this->matchId($get('Match'));$boardUrl=$get('Board');$boardNo=$this->boardNo($boardUrl);if($mid<=0||$boardNo<=0||!isset($master[$mid])||$boardNo>(int)$master[$mid]['boards']){$issues[]=$this->issue('error','parsedmember_reference',(string)$mid,'Invalid match/board reference in parsedmembers.','reject_row');continue;}$k=$mid.'|'.$boardNo;if(isset($seen[$k])&&strtolower($seen[$k])!==strtolower($u)){$issues[]=$this->issue('error','board_owner_conflict',(string)$mid,"Board {$boardNo} has conflicting CSV owners {$seen[$k]} and {$u}.",'review');continue;}$seen[$k]=$u;$source=(string)$master[$mid]['status'];if($source==='registered')$source='registered';elseif($source==='in_progress')$source='in_progress';else$source='finished';$seeds[]=['username'=>$u,'match_id'=>$mid,'board_url'=>$boardUrl,'source_bucket'=>$source];
            if(isset($finishedIds[$mid]))for($n=1;$n<=2;$n++){ $result=strtolower($get('Result'.$n));$end=(int)$get('End'.$n);if($result==='tbd'){$finishedTbd++;}$mend=(int)$master[$mid]['end_epoch'];if($result!=='tbd'&&$end===0){$missingEnd++;}if($end>0&&$mend>0&&$end>$mend)$issues[]=$this->issue('warning','game_after_match_end',(string)$mid,"{$boardUrl} game {$n} ends after recorded match end.",'queue_sync_match_and_board'); }
        }
        fclose($fh);
        if($finishedTbd>0)$issues[]=$this->issue('warning','finished_tbd','aggregate',(string)$finishedTbd.' game slots remain tbd although their match is finished.','queue_sync_board');
        if($missingEnd>0)$issues[]=$this->issue('info','missing_game_end','aggregate',(string)$missingEnd.' resolved finished-game slots have no end timestamp; ownership may be seeded but dated events require authoritative board completion.','queue_sync_board');
        return ['row_count'=>$count,'issues'=>$issues,'board_seeds'=>$seeds,'finished_tbd_game_slots'=>$finishedTbd,'resolved_without_end'=>$missingEnd];
    }

    private function validateParsedData(string $path,array $masterIds): array
    {
        $fh=fopen($path,'rb');$h=fgetcsv($fh);$expected=count($h?:[]);$issues=[];$ids=[];$line=1;
        while(($r=fgetcsv($fh))!==false){$line++;if(count($r)>$expected&&count(array_filter(array_slice($r,$expected),static fn($v)=>trim((string)$v)!==''))===0){$issues[]=$this->issue('info','parseddata_trailing_empty','line-'.$line,'Row has harmless trailing empty CSV fields; trim them during ingestion.','trim_trailing_empty');$r=array_slice($r,0,$expected);}if(count($r)!==$expected){$issues[]=$this->issue('warning','parseddata_malformed','line-'.$line,'Malformed parseddata row.','reject_row');continue;}$mid=$this->matchId((string)($r[0]??''));if($mid>0)$ids[$mid]=true;}fclose($fh);return ['issues'=>$issues,'match_ids'=>$ids];
    }

    private function recognize(string $path,string $name): array
    {
        $sha=hash_file('sha256',$path)?:'';$bytes=filesize($path)?:0;$type='unknown';$first='';$fh=fopen($path,'rb');if($fh){$first=(string)fgets($fh);fclose($fh);} $norm=strtolower(str_replace([' ','\r','\n'],'',$first));
        if(str_starts_with($norm,'playername,joined'))$type='allmembers';
        elseif(str_starts_with($norm,'player,match,board,start1,end1,result1,start2,end2,result2'))$type='parsedmembers';
        elseif(str_starts_with($norm,'matchurl,matchname,starttime,endtime,status,boards,rules,timecontrol,opponenturl,opponentname,ourresult,ourscore,theirscore,ourplayers'))$type='parseddata';
        elseif(str_starts_with($norm,'date,gamesstarted,gamesfinished,delta,cumulatedstartedgames,cumulatedfinishedgames,gamesinprogress,points,cumulatedpoints'))$type='daily';
        elseif(str_starts_with($norm,'matchapi,matchurl,matchname,opponentname,opponenturl,starttimeunix')){ $type=str_contains($norm,',status,')?'match_export':'unknown'; $probe=file($path,FILE_IGNORE_NEW_LINES)?:[];$sample=implode("\n",array_slice($probe,1,40));if(str_contains($sample,',finished,'))$type='finished';elseif(str_contains($sample,',in_progress,')||str_contains($sample,',registration,'))$type='active'; }
        elseif(preg_match('~^https://api\.chess\.com/pub/match/\d+~i',trim($first)))$type='findings';
        return ['name'=>$name,'type'=>$type,'path'=>$path,'sha256'=>$sha,'bytes'=>$bytes];
    }

    private function matchId(string $url): int { return preg_match('~/(?:match|matches)/(\d+)(?:/|$)~i',$url,$m)?(int)$m[1]:0; }
    private function boardNo(string $url): int { return preg_match('~/match/\d+/(\d+)(?:/|$)~i',$url,$m)?(int)$m[1]:0; }
    private function issue(string $severity,string $category,string $key,string $message,string $action): array { return ['severity'=>$severity,'category'=>$category,'key'=>$key,'message'=>$message,'recommended_action'=>$action]; }
    private function planSummary(array $plan): array
    {
        return [
            'batch_id'=>$plan['batch_id']??null,
            'generated_at'=>$plan['generated_at']??null,
            'checkpoint'=>$plan['checkpoint']??null,
            'counts'=>[
                'queue_sync_match'=>count($plan['queue_sync_match']??[]),
                'member_positive_seeds'=>count($plan['member_positive_seeds']??[]),
                'board_positive_seeds'=>count($plan['board_positive_seeds']??[]),
            ],
            'policy'=>$plan['policy']??[],
        ];
    }

    private function batchDir(string $id): string { if(!preg_match('/^[A-Za-z0-9-]{8,80}$/',$id))throw new ApiException('Invalid batch id.',400,'INVALID_BATCH');return $this->root.'/'.$id; }
    private function readJson(string $path): array { if(!is_file($path))return[];$v=json_decode((string)file_get_contents($path),true);return is_array($v)?$v:[]; }
    private function writeJson(string $path,array $data): void { $tmp=$path.'.tmp-'.getmypid();file_put_contents($tmp,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));@chmod($tmp,0600);rename($tmp,$path); }
}
