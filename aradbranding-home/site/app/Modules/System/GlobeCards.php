<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\I18n\I18n;

/**
 * Country cards on the 3D globe — the same set on the home page and on the sign-in / sign-up pages: the markets the
 * admin marks «روی کره» at /admin/home, plus every country reached by a route drawn on the globe. Iran is the
 * origin of the routes (is-home): trade-globe.js shows it first whenever it faces the viewer.
 */
final class GlobeCards
{
    /** Countries at the end of the globe's routes: code, Persian name, lat, lon, caption. */
    public const ROUTE_COUNTRIES = [
        ['IQ', 'عراق', 33.0, 43.5, 'مسیر زمینی از ایران'], ['AF', 'افغانستان', 34.0, 66.0, 'مسیر زمینی از ایران'],
        ['KE', 'کنیا', 0.3, 37.9, 'دریایی + زمینی از ایران'], ['NG', 'نیجریه', 9.1, 8.7, 'دریایی + زمینی از ایران'],
        ['US', 'آمریکا', 39.5, -98.0, 'مسیر دریایی از آفریقا'], ['CA', 'کانادا', 56.0, -106.0, 'مسیر دریایی از آفریقا'],
        ['ES', 'اسپانیا', 40.2, -3.7, 'بازار هدف'], ['FR', 'فرانسه', 46.6, 2.4, 'بازار هدف'],
        ['MA', 'مراکش', 31.8, -7.1, 'بازار هدف'], ['MR', 'موریتانی', 20.3, -10.3, 'بازار هدف'], ['NE', 'نیجر', 17.6, 8.1, 'بازار هدف'],
        ['MX', 'مکزیک', 23.6, -102.5, 'بازار هدف'], ['PE', 'پرو', -9.2, -75.0, 'بازار هدف'], ['AR', 'آرژانتین', -34.6, -64.0, 'بازار هدف'],
        ['OM', 'عمان', 21.0, 57.0, 'بازار هدف'], ['SY', 'سوریه', 35.0, 38.5, 'بازار هدف'], ['PK', 'پاکستان', 30.4, 69.3, 'بازار هدف'], ['KZ', 'قزاقستان', 48.0, 67.0, 'بازار هدف'],
        ['IR', 'ایران', 32.5, 53.7, 'مبدأ مسیرهای تجاری'], ['EG', 'مصر', 26.8, 30.8, 'بازار هدف'], ['LY', 'لیبی', 27.0, 17.0, 'بازار هدف'],
        ['GH', 'غنا', 7.9, -1.0, 'بازار هدف'], ['TZ', 'تانزانیا', -6.4, 34.9, 'دریایی + زمینی از ایران'], ['ZA', 'آفریقای جنوبی', -28.5, 25.5, 'دریایی + زمینی از ایران'],
        ['GB', 'انگلستان', 53.5, -1.8, 'بازار هدف'], ['MY', 'مالزی', 4.2, 102.0, 'بازار هدف'], ['AU', 'استرالیا', -25.3, 134.0, 'بازار هدف'],
        ['KR', 'کره جنوبی', 36.4, 127.9, 'بازار هدف'], ['ID', 'اندونزی', -2.5, 117.9, 'بازار هدف'], ['SG', 'سنگاپور', 1.35, 103.8, 'بازار هدف'],
    ];

    /** Flags drawn in public/_trade_sprite.php; any other country gets its emoji flag. */
    public const SPRITE_FLAGS = ['CN', 'IN', 'AE', 'TR', 'DE', 'RU', 'BR', 'IR', 'IQ', 'AF', 'KE', 'TZ', 'ZA', 'NG', 'US', 'CA', 'GB', 'NL', 'ES', 'KZ',
        'NE', 'MR', 'FR', 'AR', 'PE', 'MX', 'MA', 'KR', 'ID', 'SG', 'PK', 'OM', 'SY', 'AU', 'MY', 'GH', 'EG', 'LY'];

    /**
     * @param list<array<string, mixed>> $globeMarkets markets marked for the globe (already in the interface language)
     * @param callable(string): string $note caption of a market card
     * @return list<array{code: string, name: string, lat: float, lon: float, note: string, class: string}>
     */
    public static function build(array $globeMarkets, callable $note): array
    {
        $cards = [];
        foreach ($globeMarkets as $m) {
            $cards[] = ['code' => (string) $m['code'], 'name' => (string) $m['name'], 'lat' => (float) $m['lat'], 'lon' => (float) $m['lon'],
                'note' => $note((string) $m['code']), 'class' => 'tg-card' . ($m['code'] === 'IR' ? ' is-home' : ($m['secondary'] ? ' is-secondary' : ''))];
        }
        $listed = array_column($globeMarkets, 'code');
        foreach (self::ROUTE_COUNTRIES as [$code, $name, $lat, $lon, $caption]) {
            if (in_array($code, $listed, true)) {
                continue;
            }
            $cards[] = ['code' => $code, 'name' => I18n::isSource() ? $name : I18n::countryName($code, $name), 'lat' => $lat, 'lon' => $lon,
                'note' => t($caption), 'class' => 'tg-card ' . ($code === 'IR' ? 'is-home' : 'is-secondary') . ' is-route'];
        }
        return $cards;
    }

    /** Markets of the home content that are shown on the globe, in the interface language. @return list<array<string, mixed>> */
    public static function globeMarkets(array $home): array
    {
        $out = [];
        foreach ($home['markets'] ?? [] as $m) {
            if (($m['code'] ?? '') === '' || empty($m['globe'])) {
                continue;
            }
            if (!I18n::isSource()) {
                $m['name'] = I18n::countryName($m['code'], t((string) $m['name']));
            }
            $out[] = $m;
        }
        return $out;
    }
}
