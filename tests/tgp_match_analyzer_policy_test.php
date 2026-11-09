<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/TGP/MatchAnalyzer/MatchPolicy.php';
$c=['@id'=>'https://api.chess.com/pub/club/the-golden-phoenix'];
$o=['@id'=>'https://api.chess.com/pub/club/opponent'];
$cases=[
 [[$c,$o],true,'first'],[[$o,$c],true,'second'],[[$o,$o],false,'not TGP'],
 [[['name'=>'The Golden Phoenix'],$o],false,'name spoof'],
 [[['@id'=>'https://evil.example/club/the-golden-phoenix'],$o],false,'host spoof'],
 [[['@id'=>'https://api.chess.com/pub/club/the-golden-phoenix.evil'],$o],false,'slug spoof'],
 [[['url'=>'https://www.chess.com/club/the-golden-phoenix/'],$o],true,'web URL'],
 [[['@id'=>'http://api.chess.com/pub/club/the-golden-phoenix'],$o],false,'HTTP'],
 [[$c],false,'missing team'],[[$c,$o,$o],false,'extra team']
];
foreach($cases as [$teams,$expected,$label]) {
 $match=['teams'=>array_combine(array_map(fn($i)=>'team'.($i+1),array_keys($teams)),$teams)];
 if(TgpMatchPolicy::containsClub($match)!==$expected)throw new RuntimeException('Failed: '.$label);
}
echo "TGP policy PASS\n";
