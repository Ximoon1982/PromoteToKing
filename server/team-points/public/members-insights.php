<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
use P2K\TeamPoints\PublicReadDatabase;
use P2K\TeamPoints\{ApiException,Database,Http,Repository,ResponseCache};
try{
 Http::method('GET');$config=p2k_tp_config();$repository=new Repository(PublicReadDatabase::core(),PublicReadDatabase::analytics());
 if(!$repository->schemaInstalled())throw new ApiException('Team Points schema must be upgraded by CRON/installation before public reads.',503,'SCHEMA_NOT_INSTALLED');
 $club=(string)$config['app']['club_slug'];$section=strtolower(trim((string)($_GET['section']??'all')));$options=['page'=>(int)($_GET['page']??1),'page_size'=>(int)($_GET['page_size']??25),'search'=>(string)($_GET['search']??''),'filter'=>(string)($_GET['filter']??'current'),'sort'=>(string)($_GET['sort']??'points'),'direction'=>(string)($_GET['direction']??'desc'),'start'=>(string)($_GET['start']??''),'end'=>(string)($_GET['end']??''),'usernames'=>(string)($_GET['usernames']??''),'activity_status'=>(string)($_GET['activity_status']??''),'section'=>$section];
 $gen=$repository->publicReadGenerationToken($club,true,true);$cache=new ResponseCache(is_array($config['storage']??null)?$config['storage']:[]);$key='members-insights|'.$club.'|'.$gen.'|'.hash('sha256',json_encode($options));
 $builder=static function()use($repository,$club,$options,$section,$cache,$gen){
   $meta=['ok'=>true,'meta'=>$repository->publicReadMeta($club)];
   $hasRange=trim((string)($options['start']??''))!==''||trim((string)($options['end']??''))!=='';
   if($hasRange&&in_array($section,['summary','ranks','table'],true)){
     // One range-wide Core/event projection is shared by every progressive section.
     // Table search/filter/sort/pagination is applied to the cached row set, so
     // scrolling to the table cannot trigger a second all-member event scan.
     $base=$options;$base['page']=1;$base['page_size']=100000;$base['search']='';$base['filter']='all';$base['sort']='points';$base['direction']='desc';$base['usernames']='';$base['activity_status']='';$base['_unpaged']=true;
     $sharedKey='members-range-base|'.$club.'|'.$gen.'|'.hash('sha256',json_encode([$base['start'],$base['end']]));
     $shared=$cache->remember($sharedKey,180,static fn()=>$repository->publicMemberInsights($club,$base),900)['payload'];
     if($section==='summary')return $meta+['summary'=>$shared['summary']??[],'analytics'=>['monthly_activity'=>$shared['analytics']['monthly_activity']??[]],'range'=>$shared['range']??[],'shared_range_cache'=>true];
     if($section==='ranks')return $meta+['analytics'=>['rank_distribution'=>$shared['analytics']['rank_distribution']??[]],'range'=>$shared['range']??[],'shared_range_cache'=>true];
     $rows=is_array($shared['rows']??null)?$shared['rows']:[];$search=strtolower(trim((string)($options['search']??'')));$filter=strtolower(trim((string)($options['filter']??'current')));$wanted=array_values(array_unique(array_filter(array_map(static fn(string $v):string=>p2k_tp_username_key($v),explode(',',(string)($options['usernames']??''))))));if(count($wanted)>200)$wanted=array_slice($wanted,0,200);$activityStatuses=array_values(array_intersect(['active','cooling','inactive','dormant','unknown'],array_values(array_unique(array_filter(array_map('trim',explode(',',strtolower((string)($options['activity_status']??'')))))))));
     $rows=array_values(array_filter($rows,static function(array $row)use($search,$filter,$wanted,$activityStatuses):bool{$key=(string)($row['username_key']??p2k_tp_username_key((string)($row['username']??'')));if($search!==''&&!str_contains(strtolower((string)($row['username']??'')),$search))return false;if($wanted!==[]&&!in_array($key,$wanted,true))return false;$current=!empty($row['current_member']);$games=(int)($row['games']??0);$matches=(int)($row['current_matches']??0);if($filter==='current'&&!$current)return false;if($filter==='former'&&$current)return false;if($filter==='active'&&$games===0)return false;if($filter==='playing'&&$matches===0)return false;if($filter==='new'&&(strtotime((string)($row['first_seen_at']??'').' UTC')?:0)<time()-30*86400)return false;if($filter==='milestones'&&(int)($row['achievement_count']??0)<=0&&(float)($row['points']??0)<=0&&(float)($row['live_points']??0)<=0)return false;if($activityStatuses!==[]&&!in_array((string)($row['activity_status']??'unknown'),$activityStatuses,true))return false;return true;}));
     $sort=strtolower(trim((string)($options['sort']??'points')));$valid=['username','points','matches','games','wins','draws','losses','win_rate','points_per_game','first_activity','last_activity','current_matches','live_points','achievement_count','daily_rating','chess960_rating','last_standard_game_at','last_chess960_game_at'];if(!in_array($sort,$valid,true))$sort='points';$direction=strtolower((string)($options['direction']??'desc'))==='asc'?1:-1;usort($rows,static function(array $a,array $b)use($sort,$direction):int{$av=$a[$sort]??0;$bv=$b[$sort]??0;$cmp=is_numeric($av)&&is_numeric($bv)?($av<=>$bv):strcasecmp((string)$av,(string)$bv);return $cmp===0?strcasecmp((string)($a['username']??''),(string)($b['username']??'')):$direction*$cmp;});
     $page=max(1,(int)($options['page']??1));$pageSize=max(10,min(100,(int)($options['page_size']??25)));$total=count($rows);$pages=max(1,(int)ceil($total/$pageSize));$page=min($page,$pages);$slice=array_slice($rows,($page-1)*$pageSize,$pageSize);
     return $meta+['rows'=>$slice,'pagination'=>['page'=>$page,'page_size'=>$pageSize,'total_rows'=>$total,'total_pages'=>$pages],'range'=>$shared['range']??[],'shared_range_cache'=>true];
   }
   return $meta+($section==='all'?$repository->publicMemberInsights($club,$options):$repository->publicMemberInsightsSection($club,$options,$section));
 };
 $entry=$cache->remember($key,120,$builder,600);Http::jsonCacheable($entry['payload'],200,60,300,$entry['etag']);
}catch(ApiException $e){Http::json(['ok'=>false,'error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->httpStatus);}catch(Throwable $e){error_log('P2K Members insights: '.$e);Http::json(['ok'=>false,'error'=>['code'=>'SERVER_ERROR','message'=>'Member Insights data is temporarily unavailable.']],500);}
