<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Secure file uploads. Files are stored OUTSIDE the web root (storage/uploads) under random names
 * and are only served through FileController after authorization.
 */
final class Upload
{
    public const TYPES = [
        'mp4' => ['video', ['video/mp4', 'application/mp4']],
        'webm' => ['video', ['video/webm']],
        'mov' => ['video', ['video/quicktime']],
        'mp3' => ['audio', ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'application/octet-stream']],
        'm4a' => ['audio', ['audio/mp4', 'audio/x-m4a', 'video/mp4']],
        'wav' => ['audio', ['audio/wav', 'audio/x-wav', 'audio/wave']],
        'ogg' => ['audio', ['audio/ogg', 'application/ogg']],
        'pdf' => ['pdf', ['application/pdf']],
        'jpg' => ['image', ['image/jpeg']],
        'jpeg' => ['image', ['image/jpeg']],
        'png' => ['image', ['image/png']],
        'webp' => ['image', ['image/webp']],
        'gif' => ['image', ['image/gif']],
        'doc' => ['doc', ['application/msword', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream', 'application/vnd.ms-office']],
        'docx' => ['doc', ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream']],
        'ppt' => ['doc', ['application/vnd.ms-powerpoint', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream', 'application/vnd.ms-office']],
        'pptx' => ['doc', ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream']],
        'xls' => ['doc', ['application/vnd.ms-excel', 'application/cdfv2', 'application/x-ole-storage', 'application/octet-stream', 'application/vnd.ms-office']],
        'xlsx' => ['doc', ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream']],
        'txt' => ['doc', ['text/plain']],
    ];

    public static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'حجم فایل بیشتر از حد مجاز سرور است.',
            UPLOAD_ERR_PARTIAL => 'فایل به صورت کامل آپلود نشد.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'خطای ذخیره‌سازی سرور.',
            default => 'آپلود فایل ناموفق بود.',
        };
    }

    /**
     * @param string[] $kinds allowed kinds: video, audio, pdf, image, doc
     * @return array file row
     */
    public static function store(array $file, array $kinds, array $opts = []): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new HttpException(422, self::errorMessage((int)($file['error'] ?? 4)));
        if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') throw new HttpException(422, 'فایل نامعتبر است.');
        $maxMb = (int)($opts['max_mb'] ?? setting('max_upload_mb', 512));
        if ($file['size'] > $maxMb * 1024 * 1024) throw new HttpException(422, 'حجم فایل بیش از ' . fa($maxMb) . ' مگابایت است.');
        $original = self::cleanName((string)$file['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::TYPES[$ext])) throw new HttpException(422, 'نوع فایل «' . e($ext) . '» مجاز نیست.');
        [$kind, $mimes] = self::TYPES[$ext];
        if (!in_array($kind, $kinds, true)) throw new HttpException(422, 'این نوع فایل در این بخش مجاز نیست.');
        $mime = self::detectMime($file['tmp_name']);
        if (!in_array($mime, $mimes, true)) throw new HttpException(422, 'محتوای فایل با پسوند آن مطابقت ندارد (' . e($mime) . ').');
        if ($kind === 'image' && @getimagesize($file['tmp_name']) === false) throw new HttpException(422, 'فایل تصویر معتبر نیست.');
        self::assertNoScript($file['tmp_name'], $kind);

        $sub = date('Y/m');
        $dir = STORAGE_PATH . '/uploads/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new \RuntimeException('Cannot create upload dir');
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . '/' . $stored;
        $moved = PHP_SAPI === 'cli' ? copy($file['tmp_name'], $dest) : move_uploaded_file($file['tmp_name'], $dest);
        if (!$moved) throw new \RuntimeException('Cannot move uploaded file');
        @chmod($dest, 0640);

        $row = [
            'uuid' => uuid4(), 'original_name' => $original, 'stored_path' => $sub . '/' . $stored, 'mime' => $mime, 'ext' => $ext,
            'size' => (int)filesize($dest), 'kind' => $kind, 'title' => $opts['title'] ?? pathinfo($original, PATHINFO_FILENAME),
            'viewable' => (int)($opts['viewable'] ?? 1), 'downloadable' => (int)($opts['downloadable'] ?? 0),
            'folder' => $opts['folder'] ?? null, 'is_library' => (int)($opts['library'] ?? 1),
            'uploaded_by' => Auth::id(), 'created_at' => now(),
        ];
        $row['id'] = DB::insert('files', $row);
        return $row;
    }

    /** Store avatar image: re-encoded with GD (strips metadata/payloads) and resized. Returns relative path. */
    public static function avatar(array $file): string
    {
        if (($file['error'] ?? 4) !== UPLOAD_ERR_OK) throw new HttpException(422, self::errorMessage((int)($file['error'] ?? 4)));
        if ($file['size'] > 5 * 1024 * 1024) throw new HttpException(422, 'حجم تصویر حداکثر ۵ مگابایت است.');
        $mime = self::detectMime($file['tmp_name']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new HttpException(422, 'فقط تصاویر JPG، PNG و WEBP مجاز است.');
        return self::saveAvatarFromString((string)file_get_contents($file['tmp_name']));
    }

    public static function saveAvatarFromString(string $bin): string
    {
        if (!function_exists('imagecreatefromstring')) throw new HttpException(500, 'افزونه GD روی سرور فعال نیست.');
        $img = @imagecreatefromstring($bin);
        if (!$img) throw new HttpException(422, 'تصویر معتبر نیست.');
        $w = imagesx($img); $h = imagesy($img);
        $s = min($w, $h);
        $dst = imagecreatetruecolor(320, 320);
        imagecopyresampled($dst, $img, 0, 0, (int)(($w - $s) / 2), (int)(($h - $s) / 2), 320, 320, $s, $s);
        $dir = STORAGE_PATH . '/avatars';
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $name = bin2hex(random_bytes(12)) . '.jpg';
        imagejpeg($dst, $dir . '/' . $name, 86);
        imagedestroy($img); imagedestroy($dst);
        return 'avatars/' . $name;
    }

    public static function detectMime(string $path): string
    {
        if (function_exists('finfo_open')) {
            $f = finfo_open(FILEINFO_MIME_TYPE);
            $m = finfo_file($f, $path) ?: 'application/octet-stream';
            finfo_close($f);
            return strtolower($m);
        }
        return function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
    }

    private static function assertNoScript(string $path, string $kind): void
    {
        $h = fopen($path, 'rb');
        $head = (string)fread($h, 4096);
        fclose($h);
        if (preg_match('/<\?php|<\?=|<script\b/i', $head) && !in_array($kind, ['video', 'audio'], true)) {
            throw new HttpException(422, 'فایل حاوی محتوای غیرمجاز است.');
        }
    }

    public static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\p{L}\p{N}\s._\-()]/u', '', $name) ?? 'file';
        return mb_substr(trim($name), -150) ?: 'file';
    }

    public static function path(array $fileRow): string
    {
        $rel = str_replace(['..', '\\'], '', (string)$fileRow['stored_path']);
        return STORAGE_PATH . '/uploads/' . $rel;
    }

    public static function kinds(): array
    {
        return ['video' => 'ویدیو', 'audio' => 'صوت', 'pdf' => 'PDF', 'image' => 'تصویر', 'doc' => 'سند (Word/PowerPoint/Excel)'];
    }
}
