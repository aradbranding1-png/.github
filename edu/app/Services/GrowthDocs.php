<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Upload;

/** Documents attached to deals / stage requests (stored privately, visible to the owner and growth staff) */
final class GrowthDocs
{
    public const KINDS = ['image', 'video', 'pdf', 'doc'];

    /**
     * Reads inputs named docs[<index>][] (one per document label) and docs_extra[] and stores them.
     * @param string[] $labels labels by index
     * @return int number of stored files
     */
    public static function store(string $ownerType, int $ownerId, array $labels): int
    {
        $n = 0;
        foreach (self::collect($labels) as [$file, $label]) {
            $row = Upload::store($file, self::KINDS, ['folder' => 'growth', 'library' => 0, 'viewable' => 1, 'downloadable' => 1, 'title' => $label . ' — ' . pathinfo((string)$file['name'], PATHINFO_FILENAME)]);
            DB::insert('tg_docs', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'file_id' => (int)$row['id'], 'label' => mb_substr($label, 0, 150), 'created_at' => now()]);
            $n++;
        }
        return $n;
    }

    /** Validates uploads before anything is written (count/size), returns [file, label] pairs */
    public static function collect(array $labels): array
    {
        $out = [];
        $f = $_FILES['docs'] ?? null;
        if (is_array($f) && isset($f['name']) && is_array($f['name'])) {
            foreach ($f['name'] as $i => $names) {
                foreach ((array)$names as $k => $name) {
                    $err = is_array($f['error'][$i]) ? $f['error'][$i][$k] : $f['error'][$i];
                    if ($err === UPLOAD_ERR_NO_FILE || $name === '') continue;
                    $file = ['name' => $name, 'type' => is_array($f['type'][$i]) ? $f['type'][$i][$k] : $f['type'][$i], 'tmp_name' => is_array($f['tmp_name'][$i]) ? $f['tmp_name'][$i][$k] : $f['tmp_name'][$i], 'error' => $err, 'size' => is_array($f['size'][$i]) ? $f['size'][$i][$k] : $f['size'][$i]];
                    $out[] = [$file, (string)($labels[$i] ?? 'مدرک')];
                }
            }
        }
        $x = $_FILES['docs_extra'] ?? null;
        if (is_array($x) && isset($x['name'])) {
            foreach ((array)$x['name'] as $k => $name) {
                $err = is_array($x['error']) ? $x['error'][$k] : $x['error'];
                if ($err === UPLOAD_ERR_NO_FILE || $name === '') continue;
                $out[] = [['name' => $name, 'type' => is_array($x['type']) ? $x['type'][$k] : $x['type'], 'tmp_name' => is_array($x['tmp_name']) ? $x['tmp_name'][$k] : $x['tmp_name'], 'error' => $err, 'size' => is_array($x['size']) ? $x['size'][$k] : $x['size']], 'سایر مدارک'];
            }
        }
        if (count($out) > 30) throw new HttpException(422, 'حداکثر ۳۰ فایل در هر ارسال مجاز است.');
        foreach ($out as [$file]) if ((int)$file['error'] !== UPLOAD_ERR_OK) throw new HttpException(422, '«' . $file['name'] . '»: ' . Upload::errorMessage((int)$file['error']));
        return $out;
    }

    public static function list(string $ownerType, int $ownerId): array
    {
        return DB::all('SELECT d.*, f.uuid, f.kind, f.original_name, f.size, f.ext FROM tg_docs d JOIN files f ON f.id = d.file_id WHERE d.owner_type = ? AND d.owner_id = ? AND f.deleted_at IS NULL ORDER BY d.id', [$ownerType, $ownerId]);
    }

    /** @return array<int,array> docs grouped by owner id */
    public static function forOwners(string $ownerType, array $ids): array
    {
        if (!$ids) return [];
        $out = [];
        foreach (DB::all('SELECT d.*, f.uuid, f.kind, f.original_name, f.size, f.ext FROM tg_docs d JOIN files f ON f.id = d.file_id WHERE d.owner_type = ? AND d.owner_id IN (' . DB::in($ids) . ') AND f.deleted_at IS NULL ORDER BY d.id', array_merge([$ownerType], array_values($ids))) as $d) $out[(int)$d['owner_id']][] = $d;
        return $out;
    }
}
