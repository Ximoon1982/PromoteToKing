<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/shared/TrafficAnalytics.php';
use P2K\Shared\TrafficAnalytics as T;
function check(bool $pass,string $note):void {if(!$pass)throw new RuntimeException('FAIL '.$note);echo "PASS ".$note."\n";}
function wipe(string $path):void {if(!is_dir($path))return;foreach(scandir($path)?:[] as $n){if($n==='.'||$n==='..')continue;$f=$path.'/'.$n;if(is_dir($f))wipe($f);else unlink($f);}rmdir($path);}
$root=sys_get_temp_dir().'/p2k-traffic-v21510-'.bin2hex(random_bytes(6));
mkdir($root.'/data',0700,true);
file_put_contents($root.'/data/server-config.json',json_encode(['trafficAnalyticsSecret'=>bin2hex(random_bytes(32))]));
$ip='192.0.2.33';
$server=['REMOTE_ADDR'=>$ip,'HTTP_USER_AGENT'=>'Mozilla/5.0 P2K-Test','HTTP_HOST'=>'www.promotetoking.org'];
$tabA=str_repeat('a',32);$tabB=str_repeat('b',32);$tabC=str_repeat('c',32);
$view1=str_repeat('1',32);$view2=str_repeat('2',32);$view3=str_repeat('3',32);
$emit=static function(string $event,string $tab,string $view,array $extra=[],array $serverOverrides=[])use($root,$server):array{
  return T::record($root,array_merge(['event'=>$event,'path'=>'/FindMatch.htm','visit_key'=>$tab,'view_id'=>$view],$extra),array_merge($server,$serverOverrides));
};
try {
  $orphan=$emit('pagehide',$tabA,$view1);
  check(($orphan['recorded']??true)===false,'orphan pagehide creates no session');
  $emit('pageview',$tabA,$view1);
  $emit('visibility',$tabA,$view1);
  $emit('resume',$tabA,$view1);
  $emit('pagehide',$tabA,$view1);
  $emit('pagehide',$tabA,$view1);
  $d=T::report($root,1);
  check($d['summary']['pageviews']===1 && $d['summary']['sessions']===1,'one view opens one session');
  check(($d['page_leaves'][0]['count']??0)===1,'deduplicated page departure, no visibility inflation');
  $emit('widgetview',$tabA,$view2,['path'=>'/server/widgets/public/embed.php','widget'=>'competition']);
  $emit('widgettab',$tabA,$view2,['widget'=>'competition','action'=>'match-calls']);
  $emit('widgetaction',$tabA,$view2,['widget'=>'competition','action'=>'open-match-assistant']);
  $d=T::report($root,1);
  check($d['summary']['pageviews']===1 && $d['summary']['widget_views']===1,'widgets counted separately from website page views');
  check($d['summary']['radar_tab_views']===1 && $d['summary']['assistant_link_clicks']===1,'Competition tab views and assistant clicks counted');
  check(($d['widget_tabs'][0]['name']??'')==='competition:showcase' || count($d['widget_tabs'])===2,'Competition default showcase view recorded');
  $emit('pageview',$tabB,$view3);
  $emit('pageview',$tabC,str_repeat('4',32),[],['REMOTE_ADDR'=>'198.51.100.44']);
  $d=T::report($root,1);
  check($d['summary']['pageviews']===3 && $d['summary']['sessions']===3,'independent tab visits counted and pageviews preserved');
  check($d['summary']['estimated_unique_visitor_days']===2,'visitor-days remain IP-only daily estimates');
  $opt=$emit('widgetview',str_repeat('d',32),str_repeat('5',32),['widget'=>'competition'],['HTTP_DNT'=>'1']);
  check(($opt['reason']??'')==='privacy_signal','Do Not Track suppresses widget activity');
  $invalid=$emit('widgetaction',$tabA,$view2,['widget'=>'competition','action'=>'unknown']);
  check(($invalid['reason']??'')==='invalid_widget_event','unrecognized widget events rejected');
  $runtime=(string)file_get_contents($root.'/data/traffic/runtime/sessions.json');
  check(!str_contains($runtime,$tabA)&&!str_contains($runtime,$ip),'no raw session token or raw IP persisted');
  check((T::selfTest($root)['ok']??false)===true,'collector isolated self-test');
} finally {wipe($root);}
