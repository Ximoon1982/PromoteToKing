<?php
declare(strict_types=1);

namespace P2K\TrophyGallery;

use RuntimeException;

final class TrophyGalleryStore
{
    private string $root;
    private string $catalog;
    private string $artwork;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?: dirname(__DIR__, 3) . '/data/trophy-gallery';
        $this->catalog = $this->root . '/catalog.json';
        $this->artwork = $this->root . '/artwork';
        $this->ensureDirectories();
    }

    public function records(bool $publishedOnly = false): array
    {
        $catalog = $this->readCatalog();
        $records = is_array($catalog['records'] ?? null) ? $catalog['records'] : [];
        if ($publishedOnly) $records = array_values(array_filter($records, static fn(array $r): bool => ($r['status'] ?? '') === 'published'));
        usort($records, static fn(array $a, array $b): int => strcmp((string)($b['award_date'] ?? ''), (string)($a['award_date'] ?? '')) ?: strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? '')));
        return $records;
    }

    public function save(array $input): array
    {
        return $this->mutate(function (array &$catalog) use ($input): array {
            $now = gmdate('c');
            $id = $this->id((string)($input['id'] ?? ''));
            $index = null;
            foreach ($catalog['records'] as $i => $record) if (($record['id'] ?? '') === $id) { $index = $i; break; }
            $previous = $index === null ? [] : $catalog['records'][$index];
            $record = [
                'id' => $id,
                'status' => ($input['status'] ?? '') === 'published' ? 'published' : 'draft',
                'league' => $this->text($input['league'] ?? '', 160),
                'competition' => $this->text($input['competition'] ?? '', 200),
                'award' => $this->text($input['award'] ?? '', 200),
                'title' => $this->text($input['title'] ?? '', 240),
                'award_date' => $this->date($input['award_date'] ?? ''),
                'description_md' => $this->text($input['description_md'] ?? '', 12000),
                'source_url' => $this->url($input['source_url'] ?? ''),
                'competition_url' => $this->url($input['competition_url'] ?? ''),
                'award_url' => $this->url($input['award_url'] ?? ''),
                'vignette_media_id' => $this->mediaId($input['vignette_media_id'] ?? ''),
                'modal_media_id' => $this->mediaId($input['modal_media_id'] ?? ''),
                'matches' => $this->matches($input['matches'] ?? []),
                'created_at' => (string)($previous['created_at'] ?? $now),
                'updated_at' => $now,
            ];
            if ($record['title'] === '' || $record['league'] === '' || $record['award_date'] === '') throw new RuntimeException('Title, league and award date are required.');
            if ($index === null) $catalog['records'][] = $record; else $catalog['records'][$index] = $record;
            return $record;
        });
    }

    public function delete(string $id): array
    {
        return $this->mutate(function (array &$catalog) use ($id): array {
            $id = $this->id($id); $deleted = null;
            $catalog['records'] = array_values(array_filter($catalog['records'], static function (array $r) use ($id, &$deleted): bool { if (($r['id'] ?? '') === $id) { $deleted = $r; return false; } return true; }));
            if (!$deleted) throw new RuntimeException('Trophy record not found.');
            return $deleted;
        });
    }

    public function upload(array $file): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Artwork upload failed.');
        $tmp = (string)($file['tmp_name'] ?? ''); $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > 10 * 1024 * 1024 || !is_uploaded_file($tmp)) throw new RuntimeException('Artwork must be a browser upload no larger than 10 MB.');
        $info = @getimagesize($tmp); $mime = strtolower((string)($info['mime'] ?? ''));
        $extensions = ['image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp'];
        if (!isset($extensions[$mime]) || ($info[0] ?? 0) > 6000 || ($info[1] ?? 0) > 6000) throw new RuntimeException('Artwork must be PNG, JPEG or WebP up to 6000 × 6000.');
        $id = bin2hex(random_bytes(16)); $name = $id . '.' . $extensions[$mime]; $target = $this->artwork . '/' . $name;
        if (!move_uploaded_file($tmp, $target)) throw new RuntimeException('Artwork could not be stored.');
        chmod($target, 0640);
        return $this->mutate(function (array &$catalog) use ($id, $name, $mime, $size, $info): array {
            $media = ['id'=>$id,'file'=>$name,'mime'=>$mime,'bytes'=>$size,'width'=>(int)$info[0],'height'=>(int)$info[1],'created_at'=>gmdate('c')];
            $catalog['media'][$id] = $media; return $media;
        });
    }

    public function media(string $id): array
    {
        $catalog = $this->readCatalog(); $id = $this->mediaId($id);
        $media = $catalog['media'][$id] ?? null;
        if (!is_array($media)) throw new RuntimeException('Artwork not found.');
        $path = $this->artwork . '/' . basename((string)$media['file']);
        if (!is_file($path)) throw new RuntimeException('Artwork file is missing.');
        return [$media, $path];
    }

    public function audit(bool $purge = false): array
    {
        return $this->mutate(function (array &$catalog) use ($purge): array {
            $used = [];
            foreach ($catalog['records'] as $record) foreach (['vignette_media_id','modal_media_id'] as $field) if (($record[$field] ?? '') !== '') $used[(string)$record[$field]] = true;
            $orphans = array_values(array_diff(array_keys($catalog['media']), array_keys($used)));
            if ($purge) foreach ($orphans as $id) { $entry=$catalog['media'][$id]; @unlink($this->artwork.'/'.basename((string)$entry['file'])); unset($catalog['media'][$id]); }
            return ['orphan_media_ids'=>$orphans,'orphan_count'=>count($orphans),'purged'=>$purge ? count($orphans) : 0];
        });
    }

    private function ensureDirectories(): void { foreach ([$this->root,$this->artwork] as $dir) if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Trophy data directory is unavailable.'); }
    private function readCatalog(): array
    {
        if (!is_file($this->catalog)) return ['version'=>1,'records'=>[],'media'=>[]];
        $fh=fopen($this->catalog,'rb'); if(!$fh)throw new RuntimeException('Trophy catalog is unavailable.'); flock($fh,LOCK_SH); $raw=stream_get_contents($fh); flock($fh,LOCK_UN); fclose($fh);
        $value=json_decode((string)$raw,true); if(!is_array($value))throw new RuntimeException('Trophy catalog is invalid.');
        $value['records']=is_array($value['records']??null)?$value['records']:[]; $value['media']=is_array($value['media']??null)?$value['media']:[]; return $value;
    }
    private function mutate(callable $callback): mixed
    {
        $lock=fopen($this->root.'/.catalog.lock','c+'); if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Trophy catalog lock is unavailable.');
        $catalog=$this->readCatalog(); $result=$callback($catalog); $catalog['version']=1; $catalog['updated_at']=gmdate('c');
        $tmp=$this->catalog.'.tmp.'.bin2hex(random_bytes(6)); $json=json_encode($catalog,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
        if(file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$this->catalog)){@unlink($tmp);flock($lock,LOCK_UN);fclose($lock);throw new RuntimeException('Trophy catalog could not be saved.');}
        chmod($this->catalog,0640); flock($lock,LOCK_UN); fclose($lock); return $result;
    }
    private function id(string $value): string { $value=strtolower(trim($value)); if($value==='')$value='trophy-'.bin2hex(random_bytes(8)); if(!preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/',$value))throw new RuntimeException('Invalid trophy ID.'); return $value; }
    private function text(mixed $value,int $max): string { $value=trim((string)$value); if(strlen($value)>$max)throw new RuntimeException('A trophy field is too long.'); return $value; }
    private function date(mixed $value): string { $value=trim((string)$value); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)?$value:''; }
    private function url(mixed $value): string { $value=trim((string)$value); if($value==='')return ''; $parts=parse_url($value); if(!is_array($parts)||!in_array(strtolower((string)($parts['scheme']??'')),['http','https'],true)||($parts['host']??'')==='')throw new RuntimeException('Links must use HTTP or HTTPS.'); return $value; }
    private function mediaId(mixed $value): string { $value=trim((string)$value); if($value!==''&&!preg_match('/^[a-f0-9]{32}$/',$value))throw new RuntimeException('Invalid media ID.'); return $value; }
    private function matches(mixed $value): array { if(!is_array($value))return []; $result=[]; foreach($value as $id){$id=(int)$id;if($id>0)$result[]=$id;} return array_values(array_unique($result)); }
}
