<?php

declare(strict_types=1);

namespace App\Modules\Discover;

use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Search\SearchProvider;
use App\Modules\Reference\ReferenceData;

final class DiscoverController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $d = $this->c->get(DiscoverService::class);
        $interests = array_map('intval', array_column(
            $this->c->get(Connection::class)->select('SELECT category_id FROM user_interests WHERE user_id = ?', [$user['id']]),
            'category_id'
        ));
        return $this->view($request, 'discover/index', [
            'title' => t('کشف'),
            'recommended' => $d->recommended($user, $interests),
            'hasInterests' => $interests !== [],
            'newProposals' => $d->newProposals(),
            'popular' => $d->popular(),
            'countryStats' => $d->countries(),
            'countries' => $this->c->get(ReferenceData::class)->countries(),
            'categories' => $this->c->get(ReferenceData::class)->categories(),
        ]);
    }

    public function country(Request $request): Response
    {
        $countries = $this->c->get(ReferenceData::class)->countries();
        $id = (int) $request->param('id');
        if (!isset($countries[$id])) {
            return $this->redirect('/discover');
        }
        $before = (int) $request->query('before', '0');
        $list = $this->c->get(DiscoverService::class)->tradersOf($id, $before ?: null);
        return $this->view($request, 'discover/country', [
            'title' => t('تجار :country', ['country' => t($countries[$id]['name_fa'])]),
            'country' => $countries[$id],
            'traders' => $list['rows'],
            'next' => $list['next'],
        ]);
    }

    public function search(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));
        $type = (string) $request->query('type', 'traders');
        if (!in_array($type, ['traders', 'pages', 'proposals'], true)) {
            $type = 'traders';
        }
        $country = (int) $request->query('country', '0');
        $category = (int) $request->query('category', '0');
        $product = trim(mb_substr((string) $request->query('product', ''), 0, 60));
        $page = max(1, (int) $request->query('page', '1'));
        $result = ($q !== '' || $country > 0 || $category > 0 || mb_strlen($product) >= 2)
            ? $this->c->get(SearchProvider::class)->search($type, $q, ['country' => $country, 'category' => $category, 'product' => $product], $page)
            : ['rows' => [], 'more' => false];
        if ($type === 'proposals') {
            $result['rows'] = array_map(static function (array $r): array {
                $card = json_decode((string) $r['card'], true) ?: [];
                $card['id'] = (int) $r['proposal_id'];
                $card['published_at'] = (string) $r['published_at'];
                return $card;
            }, $result['rows']);
        }
        return $this->view($request, 'discover/search', [
            'title' => t('جستجو'),
            'q' => $q,
            'type' => $type,
            'country' => $country,
            'category' => $category,
            'product' => $product,
            'categories' => $this->c->get(ReferenceData::class)->categories(),
            'page' => $page,
            'result' => $result,
            'countries' => $this->c->get(ReferenceData::class)->countries(),
        ]);
    }
}
