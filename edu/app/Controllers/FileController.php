<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Activity;
use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Upload;

/**
 * Serves stored files from outside the web root after authorization.
 * Supports HTTP Range (video/audio seeking) and per-file "view online" / "download allowed" flags.
 */
final class FileController
{
    public function serve(string $uuid): void
    {
        if (!preg_match('/^[0-9a-f\-]{36}$/', $uuid)) throw new HttpException(404);
        $f = DB::one('SELECT * FROM files WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if (!$f) throw new HttpException(404);
        $uid = (int)Auth::id();
        $download = isset($_GET['dl']);
        $staff = can('library.view') || can('courses.view') || can('lessons.view') || can('reviews.view');
        // growth documents (deal / stage request evidence): the trader who uploaded them and growth staff
        if (($f['folder'] ?? '') === 'growth') $staff = $staff || can('growth.view') || can('growth.approve');

        if (!$staff && !$this->learnerMayAccess($f, $uid)) throw new HttpException(403);
        if ($download && !(int)$f['downloadable'] && !can('library.download')) throw new HttpException(403, 'دانلود این فایل مجاز نیست.');
        if (!$download && !(int)$f['viewable'] && !$staff) throw new HttpException(403, 'مشاهده آنلاین این فایل مجاز نیست.');

        $path = Upload::path($f);
        if (!is_file($path)) throw new HttpException(404);
        // resized copy for cards/lists (?w=320|640|960) — much lighter than the original upload
        $w = (int)($_GET['w'] ?? 0);
        if ($w && !$download && $f['kind'] === 'image' && in_array($w, [320, 640, 960], true)) {
            $thumb = self::thumbnail($path, (string)$f['uuid'], $w);
            if ($thumb) {
                if (self::cacheHeaders('"' . $f['uuid'] . '-w' . $w . '"', (int)filemtime($thumb))) return;
                self::stream($thumb, 'image/webp', pathinfo((string)$f['original_name'], PATHINFO_FILENAME) . '.webp', false);
                return;
            }
        }
        // stored files never change (a new upload gets a new id), so the browser may keep them
        if (self::cacheHeaders('"' . $f['uuid'] . '-' . filesize($path) . '"', (int)filemtime($path))) return;
        $inline = !$download && in_array($f['kind'], ['video', 'audio', 'pdf', 'image'], true);
        if (!$download && !$inline) {
            if (!(int)$f['downloadable'] && !$staff) throw new HttpException(403, 'این نوع فایل قابل نمایش آنلاین نیست.');
            $download = true;
        }
        $isFirstChunk = empty($_SERVER['HTTP_RANGE']) || preg_match('/bytes=0-/', (string)$_SERVER['HTTP_RANGE']);
        if ($isFirstChunk && $uid) {
            DB::run('UPDATE files SET ' . ($download ? 'downloads = downloads + 1' : 'views = views + 1') . ' WHERE id = ?', [(int)$f['id']]);
            if (in_array($f['kind'], ['pdf', 'doc'], true) || $download) Activity::track($uid, $download ? 'file_download' : 'file_view', 'file', (int)$f['id']);
        }
        self::stream($path, (string)$f['mime'], (string)$f['original_name'], $download);
    }

    private function learnerMayAccess(array $f, int $uid): bool
    {
        $fid = (int)$f['id'];
        // lesson media or attachment in a course the user is enrolled in (and not locked), or a free preview lesson
        // with minute credit on, only lessons the learner has activated (or free ones) give access to their files
        $credit = \App\Services\Credit::applies() ? 1 : 0;
        $hit = DB::value("SELECT 1 FROM lessons l JOIN enrollments e ON e.course_id = l.course_id AND e.user_id = ? AND e.status <> 'locked'
                           WHERE l.deleted_at IS NULL AND (l.media_file_id = ? OR EXISTS (SELECT 1 FROM lesson_files lf WHERE lf.lesson_id = l.id AND lf.file_id = ?))
                             AND (? = 0 OR l.is_preview = 1 OR COALESCE(l.duration_minutes, 0) = 0 OR EXISTS (SELECT 1 FROM lesson_unlocks lu WHERE lu.user_id = e.user_id AND lu.lesson_id = l.id)) LIMIT 1", [$uid, $fid, $fid, $credit]);
        if ($hit) return true;
        if (DB::value("SELECT 1 FROM lessons l JOIN courses c ON c.id = l.course_id WHERE l.is_preview = 1 AND l.deleted_at IS NULL AND c.status = 'published' AND (l.media_file_id = ? OR EXISTS (SELECT 1 FROM lesson_files lf WHERE lf.lesson_id = l.id AND lf.file_id = ?)) LIMIT 1", [$fid, $fid])) return true;
        // event card images and banners
        if ($f['kind'] === 'image' && DB::value('SELECT 1 FROM events WHERE (image_file_id = ? OR banner_file_id = ?) AND deleted_at IS NULL LIMIT 1', [$fid, $fid])) return true;
        // course cover images of published courses
        if ($f['kind'] === 'image' && DB::value("SELECT 1 FROM courses WHERE image_file_id = ? AND deleted_at IS NULL LIMIT 1", [$fid])) return true;
        // own growth documents
        if (($f['folder'] ?? '') === 'growth' && (int)$f['uploaded_by'] === $uid) return true;
        // own exercise submission
        if (DB::value('SELECT 1 FROM exercise_submissions WHERE file_id = ? AND user_id = ? LIMIT 1', [$fid, $uid])) return true;
        // team supervisors can see submissions of their team (reviews permission is checked via staff flag)
        return false;
    }

    public function avatar(int $id): void
    {
        $u = DB::one('SELECT avatar_path FROM users WHERE id = ?', [$id]);
        if (!$u || !$u['avatar_path']) throw new HttpException(404);
        $rel = str_replace(['..', '\\'], '', (string)$u['avatar_path']);
        $path = STORAGE_PATH . '/' . $rel;
        if (!is_file($path)) throw new HttpException(404);
        // the URL carries ?v=<hash of avatar path>, so a new photo gets a new URL
        if (self::cacheHeaders('"av' . $id . '-' . substr(md5($rel), 0, 8) . '"', (int)filemtime($path))) return;
        self::stream($path, 'image/jpeg', 'avatar.jpg', false);
    }

    /**
     * Browser caching for authorized files: private (per user) cache for 30 days + ETag.
     * Overrides the no-cache headers PHP sessions add. Returns true when a 304 was sent.
     */
    private static function cacheHeaders(string $etag, int $mtime): bool
    {
        header_remove('Pragma');
        header_remove('Expires');
        header('Cache-Control: private, max-age=2592000, immutable');
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime ?: time()) . ' GMT');
        $inm = trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
        if ($inm !== '' && ($inm === $etag || $inm === 'W/' . $etag)) {
            http_response_code(304);
            return true;
        }
        return false;
    }

    /** Cached WebP thumbnail (storage/cache/thumbs), created once per size */
    private static function thumbnail(string $src, string $uuid, int $w): ?string
    {
        $dir = STORAGE_PATH . '/cache/thumbs';
        $out = $dir . '/' . preg_replace('/[^0-9a-f-]/', '', $uuid) . '-' . $w . '.webp';
        if (is_file($out) && filemtime($out) >= filemtime($src)) return $out;
        if (!function_exists('imagecreatefromstring') || !function_exists('imagewebp')) return null;
        if (filesize($src) > 25 * 1048576) return null;
        $data = @file_get_contents($src);
        $img = $data !== false ? @imagecreatefromstring($data) : false;
        if (!$img) return null;
        $ow = imagesx($img); $oh = imagesy($img);
        if ($ow <= $w) { $nw = $ow; $nh = $oh; } else { $nw = $w; $nh = (int)round($oh * $w / $ow); }
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false); imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $ow, $oh);
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $tmp = $out . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $ok = @imagewebp($dst, $tmp, 82);
        imagedestroy($img); imagedestroy($dst);
        if (!$ok) { @unlink($tmp); return null; }
        @rename($tmp, $out);
        return is_file($out) ? $out : null;
    }

    public static function stream(string $path, string $mime, string $name, bool $download): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        $size = (int)filesize($path);
        $start = 0; $end = $size - 1;
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . ($mime ?: 'application/octet-stream'));
        header('Accept-Ranges: bytes');
        // Sandbox everything except PDFs (browsers refuse to run the PDF viewer in a sandboxed document)
        if ($mime !== 'application/pdf') header("Content-Security-Policy: default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        $disp = $download ? 'attachment' : 'inline';
        header("Content-Disposition: $disp; filename=\"" . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . "\"; filename*=UTF-8''" . rawurlencode($name));
        if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string)$_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') { $start = max(0, $size - (int)$m[2]); }
            else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
            if ($start > $end || $start >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); return; }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        $len = $end - $start + 1;
        header('Content-Length: ' . $len);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') return;
        $fh = fopen($path, 'rb');
        fseek($fh, $start);
        $left = $len;
        @set_time_limit(0);
        while ($left > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, (int)min(1048576, $left));
            if ($chunk === false) break;
            echo $chunk;
            flush();
            $left -= strlen($chunk);
        }
        fclose($fh);
    }
}
