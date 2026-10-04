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
    /** Countries shown on the globe besides the admin's markets: code, Persian name, lat, lon. */
    public const ROUTE_COUNTRIES = [
        ['IQ', 'عراق', 33.0, 43.5], ['AF', 'افغانستان', 34.0, 66.0],
        ['KE', 'کنیا', 0.3, 37.9], ['NG', 'نیجریه', 9.1, 8.7],
        ['US', 'آمریکا', 39.5, -98.0], ['CA', 'کانادا', 56.0, -106.0],
        ['ES', 'اسپانیا', 40.2, -3.7], ['FR', 'فرانسه', 46.6, 2.4],
        ['MA', 'مراکش', 31.8, -7.1], ['MR', 'موریتانی', 20.3, -10.3], ['NE', 'نیجر', 17.6, 8.1],
        ['MX', 'مکزیک', 23.6, -102.5], ['PE', 'پرو', -9.2, -75.0], ['AR', 'آرژانتین', -34.6, -64.0],
        ['OM', 'عمان', 21.0, 57.0], ['SY', 'سوریه', 35.0, 38.5], ['PK', 'پاکستان', 30.4, 69.3], ['KZ', 'قزاقستان', 48.0, 67.0],
        ['IR', 'ایران', 32.5, 53.7], ['EG', 'مصر', 26.8, 30.8], ['LY', 'لیبی', 27.0, 17.0],
        ['GH', 'غنا', 7.9, -1.0], ['TZ', 'تانزانیا', -6.4, 34.9], ['ZA', 'آفریقای جنوبی', -28.5, 25.5],
        ['GB', 'انگلستان', 53.5, -1.8], ['MY', 'مالزی', 4.2, 102.0], ['AU', 'استرالیا', -25.3, 134.0],
        ['KR', 'کره جنوبی', 36.4, 127.9], ['ID', 'اندونزی', -2.5, 117.9], ['SG', 'سنگاپور', 1.35, 103.8],
        ['SA', 'عربستان', 24.0, 45.0], ['PY', 'پاراگوئه', -23.4, -58.4], ['BD', 'بنگلادش', 23.7, 90.4],
        ['UG', 'اوگاندا', 1.4, 32.3],
    ];

    /** Flags drawn in public/_trade_sprite.php; any other country gets its emoji flag. */
    public const SPRITE_FLAGS = ['CN', 'IN', 'AE', 'TR', 'DE', 'RU', 'BR', 'IR', 'IQ', 'AF', 'KE', 'TZ', 'ZA', 'NG', 'US', 'CA', 'GB', 'NL', 'ES', 'KZ',
        'NE', 'MR', 'FR', 'AR', 'PE', 'MX', 'MA', 'KR', 'ID', 'SG', 'PK', 'OM', 'SY', 'AU', 'MY', 'GH', 'EG', 'LY', 'SA', 'PY', 'BD', 'UG'];

    /**
     * Every card carries the same caption, «بازار هدف».
     * @param list<array<string, mixed>> $globeMarkets markets marked for the globe (already in the interface language)
     * @return list<array{code: string, name: string, lat: float, lon: float, note: string, class: string}>
     */
    public static function build(array $globeMarkets): array
    {
        $note = t('بازار هدف');
        $cards = [];
        foreach ($globeMarkets as $m) {
            $cards[] = ['code' => (string) $m['code'], 'name' => (string) $m['name'], 'lat' => (float) $m['lat'], 'lon' => (float) $m['lon'],
                'note' => $note, 'class' => 'tg-card' . ($m['code'] === 'IR' ? ' is-home' : ($m['secondary'] ? ' is-secondary' : ''))];
        }
        $listed = array_column($globeMarkets, 'code');
        foreach (self::ROUTE_COUNTRIES as [$code, $name, $lat, $lon]) {
            if (in_array($code, $listed, true)) {
                continue;
            }
            $cards[] = ['code' => $code, 'name' => I18n::isSource() ? $name : I18n::countryName($code, $name), 'lat' => $lat, 'lon' => $lon,
                'note' => $note, 'class' => 'tg-card ' . ($code === 'IR' ? 'is-home' : 'is-secondary') . ' is-route'];
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
