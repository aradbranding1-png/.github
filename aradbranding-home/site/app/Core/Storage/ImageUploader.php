<?php

declare(strict_types=1);

namespace App\Core\Storage;

use App\Core\Support\Ulid;
use RuntimeException;

/**
 * Secure image upload:
 *  - size limit, real MIME from file content (finfo), JPEG/PNG/WebP only (no SVG/GIF),
 *  - dimension and pixel limits against decompression bombs,
 *  - full re-encode with GD → strips EXIF/metadata and any embedded payload,
 *  - random ULID filename, fixed .webp extension, no user-controlled path parts.
 * Public images live in public_html/media (CDN / S3-ready later via MEDIA_PUBLIC_URL).
 */
final class ImageUploader
{
    private const MAX_PIXELS = 36_000_000;
    private const ALLOWED = ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'];

    /** preset => [width, height] ; height 0 = keep ratio */
    private const PRESETS = [
        'avatar' => [512, 512],
        'cover' => [1600, 600],
        'proposal' => [1200, 900],
        'thumb' => [480, 360],
        'gallery' => [1600, 0], // 0 = keep the original aspect ratio (landscape stays landscape)
    ];

    public function __construct(
        private string $root = BASE_PATH . '/public_html/media',
        private int $maxBytes = 200 * 1024,
    ) {
    }

    public function maxKb(): int
    {
        return intdiv($this->maxBytes, 1024);
    }

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $file
     * @return string relative path, e.g. "avatar/2026/09/01J....webp"
     * @throws UploadException with a user-facing Persian message
     */
    public function store(array $file, string $preset): string
    {
        return $this->storeSet($file, [$preset])[$preset];
    }

    /**
     * Decode once, write several sizes (e.g. proposal + thumb).
     * @param list<string> $presets
     * @return array<string, string> preset => relative path
     */
    public function storeSet(array $file, array $presets): array
    {
        foreach ($presets as $preset) {
            if (!isset(self::PRESETS[$preset])) {
                throw new RuntimeException("Unknown image preset {$preset}");
            }
        }
        $source = $this->load($file);
        $written = [];
        try {
            foreach ($presets as $preset) {
                [$w, $h] = self::PRESETS[$preset];
                $output = $h === 0 ? $this->fit($source, $w) : $this->cover($source, $w, $h);
                $relative = sprintf('%s/%s/%s.webp', $preset, gmdate('Y/m'), strtolower(Ulid::generate()));
                $path = $this->root . '/' . $relative;
                if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0755, true) && !is_dir(dirname($path))) {
                    throw new RuntimeException('Media directory not writable');
                }
                $ok = imagewebp($output, $path, 82);
                imagedestroy($output);
                if (!$ok) {
                    throw new RuntimeException('WebP encoding failed');
                }
                @chmod($path, 0644);
                $written[$preset] = $relative;
            }
        } catch (\Throwable $e) {
            foreach ($written as $rel) {
                $this->delete($rel);
            }
            throw $e;
        } finally {
            imagedestroy($source);
        }
        return $written;
    }

    /** Validate and decode an upload. */
    private function load(array $file): \GdImage
    {
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new UploadException($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
                ? t('حجم تصویر بیشتر از :n کیلوبایت است.', ['n' => fa_num($this->maxKb())])
                : t('بارگذاری تصویر انجام نشد. دوباره تلاش کنید.'));
        }
        if ($file['size'] > $this->maxBytes) {
            throw new UploadException(sprintf(
                t('حجم تصویر باید حداکثر %s کیلوبایت باشد. حجم این فایل %s کیلوبایت است؛ آن را فشرده کنید و دوباره بارگذاری کنید.'),
                fa_num($this->maxKb()),
                fa_num((int) ceil($file['size'] / 1024))
            ));
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        if (!isset(self::ALLOWED[$mime])) {
            throw new UploadException(t('فقط تصاویر JPG، PNG و WebP پذیرفته می‌شوند.'));
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || $info[0] < 64 || $info[1] < 64 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new UploadException(t('ابعاد تصویر معتبر نیست.'));
        }
        $source = match (self::ALLOWED[$mime]) {
            'jpeg' => @imagecreatefromjpeg($file['tmp_name']),
            'png' => @imagecreatefrompng($file['tmp_name']),
            'webp' => @imagecreatefromwebp($file['tmp_name']),
        };
        if ($source === false) {
            throw new UploadException(t('فایل تصویر خراب است.'));
        }
        return $mime === 'image/jpeg' ? $this->applyExifOrientation($source, $file['tmp_name']) : $source;
    }

    public function delete(?string $relative): void
    {
        if ($relative === null || !preg_match('~^[a-z]+/\d{4}/\d{2}/[0-9a-z]{26}\.webp$~', $relative)) {
            return;
        }
        @unlink($this->root . '/' . $relative);
    }

    /** Resize and centre-crop to exactly $w x $h. */
    private function cover(\GdImage $src, int $w, int $h): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = max($w / $sw, $h / $sh);
        $cropW = (int) round($w / $scale);
        $cropH = (int) round($h / $scale);
        $x = (int) max(0, ($sw - $cropW) / 2);
        $y = (int) max(0, ($sh - $cropH) / 2);

        if ($scale > 1) { // never upscale: keep the source size inside the target ratio
            $w = $cropW;
            $h = $cropH;
        }
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, $x, $y, $w, $h, $cropW, $cropH);
        return $dst;
    }

    /** Scale so the longest side is at most $max; never crop, never upscale. */
    private function fit(\GdImage $src, int $max): \GdImage
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $scale = min(1, $max / max($sw, $sh));
        $w = max(1, (int) round($sw * $scale));
        $h = max(1, (int) round($sh * $scale));
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $sw, $sh);
        return $dst;
    }

    private function applyExifOrientation(\GdImage $img, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $img;
        }
        $exif = @exif_read_data($file);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $img;
        }
        $rotated = imagerotate($img, $angle, 0);
        if ($rotated === false) {
            return $img;
        }
        imagedestroy($img);
        return $rotated;
    }
}
