<?php
declare(strict_types=1);
namespace P2K\Widgets;

use InvalidArgumentException;
use RuntimeException;

final class RevisionConflict extends RuntimeException {
    public function __construct(public readonly array $current) { parent::__construct('Widget settings changed in another session.'); }
}

/** Small, versioned, independent widget settings registry. No legacy data is changed. */
final class WidgetConfigStore {
    public const IDS = ['club-pulse','recruitment-radar','trophy-spotlight','rivalry-spotlight','club-progress','around-the-world','player-spotlight','tournament-winners'];
    public const SCHEMA = 1;
    private string $path;
    private string $lock;

    public function __construct(string $root) {
        $dir = rtrim($root, '/\\') . '/data/widgets';
        $this->path = $dir . '/config.json';
        $this->lock = $dir . '/config.lock';
    }

    public static function defaults(string $id): array {
        if (!in_array($id, self::IDS, true)) throw new InvalidArgumentException('Unknown widget');
        $common = ['enabled'=>true, 'title'=>[
            'club-pulse'=>'Club Pulse', 'recruitment-radar'=>'Recruitment Radar',
            'trophy-spotlight'=>'Trophy Spotlight', 'rivalry-spotlight'=>'Rivalry Spotlight',
            'club-progress'=>'Club Progress', 'around-the-world'=>'P2K Around the World',
            'player-spotlight'=>'Player Spotlight', 'tournament-winners'=>'Tournament Winners'
        ][$id], 'theme'=>'auto'];
        return $common + match ($id) {
            'club-pulse'=>['period'=>'7d','showMembers'=>true],
            'recruitment-radar'=>['window'=>'48h','limit'=>4],
            'trophy-spotlight'=>['limit'=>5,'mode'=>'latest'],
            'rivalry-spotlight'=>['opponent'=>'','limit'=>6],
            'club-progress'=>['period'=>'30d','metric'=>'points'],
            'around-the-world'=>['metric'=>'matches','limit'=>8],
            'player-spotlight'=>['limit'=>5,'showArena'=>true,'showMedals'=>true],
            'tournament-winners'=>['limit'=>5,'medalPageSize'=>3],
        };
    }

    private static function scalarString(mixed $value, int $limit): string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) throw new InvalidArgumentException('Invalid setting type');
        $text=trim((string)$value);
        if (strlen($text)>$limit || preg_match('/[\x00-\x1F\x7F]/', $text)) throw new InvalidArgumentException('Invalid widget text');
        return $text;
    }

    public static function normalize(string $id, mixed $raw): array {
        $settings=self::defaults($id);
        if (!is_array($raw)) throw new InvalidArgumentException('Settings must be an object');
        // Ignore unknown properties for forward-compatibility, but never persist them.
        foreach ($settings as $key=>$initial) {
            if (!array_key_exists($key,$raw)) continue;
            $value=$raw[$key];
            if (is_bool($initial)) {
                if (!is_bool($value)) throw new InvalidArgumentException($key . ' must be boolean');
                $settings[$key]=$value;
            } elseif (is_int($initial)) {
                if (!is_int($value) || $value<1 || $value>20) throw new InvalidArgumentException('Invalid widget limit');
                $settings[$key]=$value;
            } else {
                $settings[$key]=self::scalarString($value,$key==='title'?64:($key==='opponent'?160:24));
            }
        }
        foreach (['theme'=>['dark','light','auto'],'period'=>['7d','30d','12m'],'window'=>['24h','48h','72h','7d'],'metric'=>['points','boards','matches','clubs'],'mode'=>['latest','random']] as $key=>$options) {
            if (isset($settings[$key]) && !in_array($settings[$key],$options,true)) throw new InvalidArgumentException('Invalid '.$key.' option');
        }
        if ($settings['title']==='') throw new InvalidArgumentException('Widget title is required');
        return $settings;
    }

    private function base(): array {
        return ['schemaVersion'=>self::SCHEMA,'revision'=>0,'updatedAt'=>null,'widgets'=>[]];
    }
    private function readUnlocked(): array {
        $data=$this->base();
        if (is_file($this->path)) {
            $raw=@file_get_contents($this->path);
            $json=$raw===false?null:json_decode($raw,true);
            if (!is_array($json)) throw new RuntimeException('Widget settings are unreadable; refusing to overwrite.');
            $data['revision']=max(0,(int)($json['revision']??0));
            $data['updatedAt']=$json['updatedAt']??null;
            $saved=is_array($json['widgets']??null)?$json['widgets']:[];
            foreach(self::IDS as $id){
                try {$data['widgets'][$id]=self::normalize($id,$saved[$id]??[]);}
                catch (InvalidArgumentException $e) { throw new RuntimeException('Invalid saved settings for '.$id.': '.$e->getMessage()); }
            }
        } else foreach (self::IDS as $id) $data['widgets'][$id]=self::defaults($id);
        return $data;
    }
    public function read(): array {return $this->readUnlocked();}
    public function get(string $id): array {
        if (!in_array($id,self::IDS,true)) throw new InvalidArgumentException('Unknown widget');
        $data=$this->readUnlocked();return $data['widgets'][$id];
    }
    public function save(string $id, array $settings, int $revision): array {
        if (!in_array($id,self::IDS,true)) throw new InvalidArgumentException('Unknown widget');
        // Validate input before acquiring the exclusive file lock.
        $valid=self::normalize($id,$settings);
        $dir=dirname($this->path);
        if(!is_dir($dir)&&!@mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Cannot create widget settings directory');
        $handle=@fopen($this->lock,'c+');if(!$handle)throw new RuntimeException('Cannot open widget settings lock');
        try {
            if(!flock($handle,LOCK_EX))throw new RuntimeException('Cannot lock widget settings');
            $data=$this->readUnlocked();
            if($data['revision']!==$revision)throw new RevisionConflict($data);
            $data['widgets'][$id]=$valid;
            $data['revision']++;
            $data['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');
            $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if($json===false)throw new RuntimeException('Cannot encode widget settings');
            $tmp=tempnam($dir,'.widgets.');if($tmp===false)throw new RuntimeException('Cannot create widget settings temp file');
            try{
                if(file_put_contents($tmp,$json."\n")===false)throw new RuntimeException('Cannot write widget settings');
                @chmod($tmp,0640);
                if(is_file($this->path))@copy($this->path,$this->path.'.bak');
                if(!rename($tmp,$this->path))throw new RuntimeException('Cannot commit widget settings');
            } finally {if(is_file($tmp))@unlink($tmp);}
            return $data;
        } finally {flock($handle,LOCK_UN);fclose($handle);}
    }
}
