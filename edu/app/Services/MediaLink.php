<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Lesson media given as a link instead of an uploaded file.
 *  - Aparat / YouTube page links  → embedded player (iframe, allow-listed in CSP frame-src)
 *  - Direct file links (mp4, m4v, mp3, …) on any HTTPS host → native <video>/<audio> player
 *    with the same progress tracking and resume as uploaded files.
 */
final class MediaLink
{
    public const VIDEO_EXT = ['mp4', 'm4v', 'webm', 'mov', 'ogv'];
    public const AUDIO_EXT = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac'];

    /** Embeddable player URL for known video platforms, or null */
    public static function embed(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') return null;
        // Aparat: /v/HASH, /v/HASH/title, already-embed links
        if (preg_match('~aparat\.com/(?:v|video/video/embed/videohash)/([A-Za-z0-9]+)~i', $url, $m)) {
            return 'https://www.aparat.com/video/video/embed/videohash/' . $m[1] . '/vt/frame';
        }
        // YouTube: watch?v=, youtu.be/, shorts/, embed/, live/
        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/)|youtu\.be/)([\w-]{6,})~i', $url, $m)) {
            return 'https://www.youtube.com/embed/' . $m[1];
        }
        return null;
    }

    /** 'video' | 'audio' when the link points directly at a media file, otherwise null */
    public static function directKind(string $url): ?string
    {
        $path = (string)parse_url(trim($url), PHP_URL_PATH);
        $ext = strtolower(pathinfo(rawurldecode($path), PATHINFO_EXTENSION));
        if (in_array($ext, self::VIDEO_EXT, true)) return 'video';
        if (in_array($ext, self::AUDIO_EXT, true)) return 'audio';
        return null;
    }

    /**
     * How a lesson's main content is shown when it has no uploaded/library file.
     * @return array{mode:string,src:string}|null  mode: embed | video | audio | link
     */
    public static function resolve(array $lesson): ?array
    {
        $url = trim((string)($lesson['link_url'] ?? ''));
        if ($url === '') return null;
        if ($e = self::embed($url)) return ['mode' => 'embed', 'src' => $e];
        if ($k = self::directKind($url)) return ['mode' => $k, 'src' => $url];
        // a link saved on a video/audio lesson is treated as a stream even without a known extension
        if (in_array($lesson['content_type'] ?? '', ['video', 'audio'], true)) return ['mode' => $lesson['content_type'], 'src' => $url];
        return ['mode' => 'link', 'src' => $url];
    }
}
