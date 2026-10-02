<?php

declare(strict_types=1);

namespace App\Modules\System;

use App\Core\Container;
use App\Core\Env;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Settings\Settings;
use App\Core\View\View;
use App\Modules\Admin\MetricsService;
use App\Modules\Reference\ReferenceData;

/**
 * Public landing page. No inline CSS/JS and no per-user content, so the HTML is publicly cacheable (CDN-ready).
 * Statistics are shown only when the admin switches them on (cached totals, never live counts).
 * Country ids come from the cached reference data so market cards link to the real /discover/country/{id} pages.
 * All texts, links, lists and section switches come from HomeContent (edited at /admin/home).
 */
final class HomeController
{
    public function __construct(private Container $c)
    {
    }

    public function index(Request $request): Response
    {
        $settings = $this->c->get(Settings::class);
        $showStats = (bool) $settings->get('landing.show_stats', false);
        $metrics = $this->c->get(MetricsService::class);

        $countryIds = [];
        $countryNames = [];
        foreach ($this->c->get(ReferenceData::class)->countries() as $country) {
            $countryIds[(string) $country['code']] = (int) $country['id'];
            $countryNames[(string) $country['code']] = (string) $country['name_fa'];
        }
        $countryStats = [];
        if ($showStats) {
            foreach ($metrics->byCountry() as $row) {
                $countryStats[(int) $row['country_id']] = $row;
            }
        }

        $html = $this->c->get(View::class)->render('public/landing', [
            'home' => (new HomeContent($settings))->get(),
            'stats' => $showStats ? $metrics->totals() : null,
            'countryIds' => $countryIds,
            'countryNames' => $countryNames,
            'countryStats' => $countryStats,
            'publishFee' => $this->c->get(\App\Modules\Proposals\ProposalService::class)->publishFee(),
            'baseUrl' => rtrim((string) Env::get('APP_URL', ''), '/'),
        ], null);
        return Response::html($html)
            ->withHeader('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600')
            ->withEtag($request);
    }
}
