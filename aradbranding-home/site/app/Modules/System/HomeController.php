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
 */
final class HomeController
{
    public function __construct(private Container $c)
    {
    }

    public function index(Request $request): Response
    {
        $showStats = (bool) $this->c->get(Settings::class)->get('landing.show_stats', false);
        $metrics = $this->c->get(MetricsService::class);

        $countryIds = [];
        foreach ($this->c->get(ReferenceData::class)->countries() as $country) {
            $countryIds[(string) $country['code']] = (int) $country['id'];
        }
        $countryStats = [];
        if ($showStats) {
            foreach ($metrics->byCountry() as $row) {
                $countryStats[(int) $row['country_id']] = $row;
            }
        }

        $html = $this->c->get(View::class)->render('public/landing', [
            'stats' => $showStats ? $metrics->totals() : null,
            'countryIds' => $countryIds,
            'countryStats' => $countryStats,
            'publishFee' => $this->c->get(\App\Modules\Proposals\ProposalService::class)->publishFee(),
            'baseUrl' => rtrim((string) Env::get('APP_URL', ''), '/'),
        ], null);
        return Response::html($html)
            ->withHeader('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600')
            ->withEtag($request);
    }
}
