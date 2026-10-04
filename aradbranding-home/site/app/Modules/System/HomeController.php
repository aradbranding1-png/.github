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
 * Statistics are cached totals (never live counts): real figures when the admin switches public stats on, otherwise the
 * fixed facts, each either typed by the admin or counted from the data (countries, page languages, proposal types).
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

        $totals = $metrics->totals();
        $html = $this->c->get(View::class)->render('public/landing', [
            'home' => (new HomeContent($settings))->get(),
            'stats' => $showStats ? $totals : null,
            'facts' => $this->facts($totals),
            'countryIds' => $countryIds,
            'countryNames' => $countryNames,
            'countryStats' => $countryStats,
            'publishFee' => $this->c->get(\App\Modules\Proposals\ProposalService::class)->publishFee(),
            'baseUrl' => rtrim((string) Env::get('APP_URL', ''), '/'),
        ], null);
        // The page now depends on the visitor's language (cookie, browser, time zone, country), so it is cached only
        // by the browser and revalidated with the ETag; a shared cache must never hand one language to everyone.
        return Response::html($html)
            ->withHeader('Cache-Control', 'private, no-cache')
            ->withHeader('Vary', 'Cookie, Accept-Language')
            ->withHeader('Content-Language', \App\Core\I18n\I18n::locale())
            ->withEtag($request);
    }

    /**
     * Real figures for the stats strip (HomeContent::SOURCES).
     * @param array<string, int> $totals
     * @return array<string, int>
     */
    private function facts(array $totals): array
    {
        $ref = $this->c->get(ReferenceData::class);
        return [
            'countries' => count($ref->countries()),
            'languages' => count($ref->languages()),
            'proposal_types' => count(\App\Modules\Proposals\ProposalService::TYPES),
            'users' => (int) ($totals['users'] ?? 0),
            'active_countries' => (int) ($totals['countries'] ?? 0),
            'pages' => (int) ($totals['pages'] ?? 0),
            'proposals' => (int) ($totals['proposals'] ?? 0),
            'connections' => (int) ($totals['connections'] ?? 0),
        ];
    }
}
