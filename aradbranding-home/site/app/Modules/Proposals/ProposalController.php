<?php

declare(strict_types=1);

namespace App\Modules\Proposals;

use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Idempotency;
use App\Core\Session\Session;
use App\Core\Support\Ulid;
use App\Core\Validation\Validator;
use App\Core\View\View;
use App\Modules\Pages\PageRouter;
use App\Modules\Reference\ReferenceData;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\Pricing;

final class ProposalController extends Controller
{
    private const LABELS = [
        'type' => 'نوع پیشنهاد', 'category_id' => 'دسته', 'title' => 'عنوان', 'summary' => 'خلاصه', 'body' => 'توضیحات',
        'product' => 'محصول یا خدمت', 'quantity' => 'مقدار', 'target_markets' => 'بازار هدف', 'terms' => 'شرایط', 'tags' => 'برچسب‌ها',
    ];

    // ---------- Feed ----------

    public function feed(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->feedData($request, $user);
        return $this->view($request, 'proposals/feed', $data + [
            'title' => 'پیشنهادهای تجاری',
            'categories' => $this->c->get(ReferenceData::class)->categories(),
            'countries' => $this->c->get(ReferenceData::class)->countries(),
            'hasInterests' => $this->interests($user['id']) !== [],
        ]);
    }

    /** Infinite scroll: JSON {html, next}. */
    public function feedMore(Request $request): Response
    {
        $user = $this->user($request);
        $data = $this->feedData($request, $user);
        $view = $this->c->get(View::class);
        $html = '';
        foreach ($data['items'] as $item) {
            $html .= $view->partial('proposals/card', ['p' => $item]);
        }
        return Response::json(['html' => $html, 'next' => $data['next'] ? $data['moreUrl'] : null]);
    }

    private function feedData(Request $request, array $user): array
    {
        $tab = $request->query('tab') === 'latest' ? 'latest' : 'foryou';
        $filters = [
            'category' => (int) $request->query('category', '0') ?: null,
            'country' => (int) $request->query('country', '0') ?: null,
            'type' => (int) $request->query('type', '0') ?: null,
        ];
        if (array_filter($filters)) {
            $tab = 'latest';
        }
        $cursor = $request->query('cursor');
        $cursor = is_string($cursor) ? $cursor : null;
        $feed = $this->c->get(FeedService::class);
        $result = $tab === 'latest'
            ? $feed->latest($filters, $cursor)
            : $feed->forYou($user, $this->interests($user['id']), $cursor);

        $query = array_filter(['tab' => $tab === 'latest' ? 'latest' : null] + $filters) + ['cursor' => $result['next']];
        return [
            'items' => $result['items'],
            'next' => $result['next'],
            'moreUrl' => '/proposals/more?' . http_build_query($query),
            'nextUrl' => '/proposals?' . http_build_query($query),
            'tab' => $tab,
            'filters' => $filters,
        ];
    }

    // ---------- Detail ----------

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $p = $this->find((string) $request->param('uid'));
        if ($p === null || ((int) $p['status'] !== ProposalService::PUBLISHED && (int) $p['user_id'] !== (int) $user['id'])) {
            throw new HttpException(404);
        }
        $isOwner = (int) $p['user_id'] === (int) $user['id'];
        if (!$isOwner) {
            $seen = (array) Session::get('_seen_proposals', []);
            if (!isset($seen[$p['id']])) {
                $seen[$p['id']] = 1;
                Session::put('_seen_proposals', array_slice($seen, -200, null, true));
                $this->c->get(Outbox::class)->record('proposal_viewed', $user['id'], (int) $p['user_id'], (int) $p['id']);
            }
        }
        return $this->view($request, 'proposals/show', [
            'title' => $p['title'],
            'p' => $p,
            'isOwner' => $isOwner,
            'category' => $this->c->get(ReferenceData::class)->category((int) $p['category_id']),
        ]);
    }

    // ---------- My proposals ----------

    public function mine(Request $request): Response
    {
        $user = $this->user($request);
        $rows = $this->c->get(Connection::class)->select(
            'SELECT public_id, title, type, status, thumb_path, view_count, send_count, published_at, expires_at, updated_at
             FROM proposals WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 100',
            [$user['id']]
        );
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        return $this->view($request, 'proposals/mine', ['title' => 'پیشنهادهای من', 'rows' => $rows]);
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null, ['type' => 2], []);
    }

    public function store(Request $request): Response
    {
        $user = $this->user($request);
        [$d, $errors] = $this->validate($request);
        if ($errors === []) {
            try {
                $service = $this->c->get(ProposalService::class);
                $uid = $service->create($user, $d, ['cover' => $request->file('cover'), 'gallery' => $request->fileList('gallery', 4)]);
                if ($request->input('publish')) {
                    return $this->publishOrWallet($this->find($uid), 'پیشنهاد منتشر شد.');
                }
                return $this->redirect('/proposals/mine', 'پیش‌نویس ذخیره شد.');
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }
        return $this->form($request, null, $request->all(), $errors, 422);
    }

    public function edit(Request $request): Response
    {
        $p = $this->owned($request);
        $old = $p + ['tags_text' => implode('، ', json_decode((string) ($p['tags'] ?? '[]'), true) ?: [])];
        return $this->form($request, $p, $old, []);
    }

    public function update(Request $request): Response
    {
        $p = $this->owned($request);
        [$d, $errors] = $this->validate($request);
        if ($errors === []) {
            try {
                $this->c->get(ProposalService::class)->update($p, $d, ['cover' => $request->file('cover'), 'gallery' => $request->fileList('gallery', 4)]);
                return $this->redirect('/proposals/' . $p['uid'] . '/edit', 'تغییرات ذخیره شد.');
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }
        return $this->form($request, $p, $request->all() + $p, $errors, 422);
    }

    public function publish(Request $request): Response
    {
        return $this->publishOrWallet($this->owned($request), 'پیشنهاد منتشر شد.');
    }

    public function unpublish(Request $request): Response
    {
        $this->c->get(ProposalService::class)->unpublish($this->owned($request));
        return $this->redirect('/proposals/mine', 'پیشنهاد از فید خارج شد.');
    }

    public function destroy(Request $request): Response
    {
        $this->c->get(ProposalService::class)->delete($this->owned($request));
        return $this->redirect('/proposals/mine', 'پیشنهاد حذف شد.');
    }

    // ---------- Send to a trader ----------

    public function sendForm(Request $request, array $errors = [], int $status = 200): Response
    {
        $user = $this->user($request);
        $recipient = $this->recipient((string) $request->input('to', ''));
        $proposals = $this->c->get(Connection::class)->select(
            'SELECT public_id, title, status FROM proposals WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100',
            [$user['id']]
        );
        foreach ($proposals as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        $price = $this->c->get(Pricing::class)->price('proposal_send', (int) $user['country_id'] === (int) $recipient['country_id']);
        return $this->view($request, 'proposals/send', [
            'title' => 'ارسال پیشنهاد',
            'recipient' => $recipient,
            'proposals' => $proposals,
            'price' => $price,
            'balance' => $this->c->get(\App\Modules\Wallet\WalletService::class)->balance($user['id']),
            'token' => Idempotency::token(),
            'errors' => $errors,
            'old' => $request->all(),
        ], 'layouts/app', $status);
    }

    public function send(Request $request): Response
    {
        $user = $this->user($request);
        $recipient = $this->recipient((string) $request->input('to', ''));
        $proposal = $this->find((string) $request->input('proposal', ''));
        $message = trim((string) $request->input('message', ''));
        $token = (string) $request->input('token', '');
        if ($proposal === null || (int) $proposal['user_id'] !== (int) $user['id']) {
            return $this->sendForm($request, ['proposal' => 'یکی از پیشنهادهای منتشرشده خود را انتخاب کنید.'], 422);
        }
        if (mb_strlen($message) > 1000 || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return $this->sendForm($request, ['message' => 'پیام حداکثر ۱۰۰۰ نویسه است.'], 422);
        }
        try {
            if ((int) $proposal['status'] !== ProposalService::PUBLISHED) {
                $this->c->get(ProposalService::class)->publish($proposal);
                $proposal['status'] = ProposalService::PUBLISHED;
            }
            $this->c->get(ProposalService::class)->send($user, $proposal, $recipient, $message !== '' ? $message : null, $token);
        } catch (ValidationFailed $e) {
            return $this->sendForm($request, $e->errors, 422);
        } catch (InsufficientStars $e) {
            return $this->redirect('/wallet?need=' . $e->missing() . '&next=' . rawurlencode('/proposals/send?to=' . $recipient['handle']),
                'موجودی Stars کافی نیست.', 'error');
        }
        return $this->redirect('/p/' . $recipient['handle'], 'پیشنهاد شما برای ' . $recipient['first_name'] . ' ارسال شد.');
    }

    public function received(Request $request): Response
    {
        $user = $this->user($request);
        $db = $this->c->get(Connection::class);
        $before = $request->query('before');
        $bind = [$user['id']];
        $cond = '';
        if (is_string($before) && ctype_digit($before)) {
            $cond = ' AND s.id < ?';
            $bind[] = (int) $before;
        }
        $rows = $db->select(
            'SELECT s.id, s.message, s.read_at, s.created_at, p.public_id, p.title, p.type, p.thumb_path,
                    u.first_name, u.last_name, u.handle, u.avatar_path, c.code AS country_code
             FROM proposal_sends s
             JOIN proposals p ON p.id = s.proposal_id
             JOIN users u ON u.id = s.sender_id
             JOIN countries c ON c.id = u.country_id
             WHERE s.recipient_id = ?' . $cond . '
             ORDER BY s.id DESC LIMIT 26',
            $bind
        );
        $next = null;
        if (count($rows) > 25) {
            array_pop($rows);
            $next = (int) end($rows)['id'];
        }
        $unread = array_map('intval', array_column(array_filter($rows, static fn ($r) => $r['read_at'] === null), 'id'));
        if ($unread !== []) {
            $db->exec('UPDATE proposal_sends SET read_at = NOW(3) WHERE id IN (' . implode(',', $unread) . ')');
        }
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        return $this->view($request, 'proposals/received', ['title' => 'پیشنهادهای دریافتی', 'rows' => $rows, 'next' => $next]);
    }

    // ---------- helpers ----------

    private function publishOrWallet(?array $p, string $ok): Response
    {
        if ($p === null) {
            throw new HttpException(404);
        }
        try {
            $this->c->get(ProposalService::class)->publish($p);
        } catch (InsufficientStars $e) {
            return $this->redirect('/wallet?need=' . $e->missing() . '&next=' . rawurlencode('/proposals/mine'),
                'پیشنهاد به‌صورت پیش‌نویس ذخیره شد. برای انتشار ' . fa_num($e->required) . ' Star لازم است.', 'error');
        } catch (ValidationFailed $e) {
            return $this->redirect('/proposals/mine', (string) reset($e->errors), 'error');
        }
        return $this->redirect('/proposals/' . $p['uid'], $ok);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, string>} */
    private function validate(Request $request): array
    {
        [$d, $errors] = Validator::make($request->all(), [
            'type' => ['required', 'int'],
            'category_id' => ['required', 'int'],
            'title' => ['required', 'max:120'],
            'summary' => ['required', 'max:300'],
            'body' => ['max:5000'],
            'product' => ['max:200'],
            'quantity' => ['max:100'],
            'target_markets' => ['max:500'],
            'terms' => ['max:3000'],
            'tags_text' => ['max:200'],
            'remove_cover' => ['bool'],
            'remove_gallery' => ['bool'],
        ], self::LABELS);
        if (!isset($errors['type']) && !isset(ProposalService::TYPES[$d['type']])) {
            $errors['type'] = 'نوع پیشنهاد را انتخاب کنید.';
        }
        if (!isset($errors['category_id']) && $this->c->get(ReferenceData::class)->category($d['category_id']) === null) {
            $errors['category_id'] = 'دسته را انتخاب کنید.';
        }
        $tags = array_values(array_unique(array_filter(array_map(
            static fn (string $t): string => mb_substr(trim($t), 0, 30),
            preg_split('/[,،\n]/u', (string) ($d['tags_text'] ?? '')) ?: []
        ))));
        $d['tags'] = array_slice($tags, 0, 5);
        return [$d, $errors];
    }

    private function find(string $uid): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        $p = $this->c->get(Connection::class)->first(
            'SELECT p.*, u.first_name, u.last_name, u.handle, u.avatar_path AS owner_avatar, u.business_verified_at,
                    c.code AS country_code, c.name_fa AS country_fa, up.company_name, l.code AS lang_code, l.direction
             FROM proposals p
             JOIN users u ON u.id = p.user_id
             JOIN countries c ON c.id = p.country_id
             LEFT JOIN languages l ON l.id = p.language_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE p.public_id = ? AND p.deleted_at IS NULL',
            [Ulid::toBinary($uid)]
        );
        if ($p !== null) {
            $p['id'] = (int) $p['id'];
            $p['uid'] = strtolower($uid);
        }
        return $p;
    }

    private function owned(Request $request): array
    {
        $p = $this->find((string) $request->param('uid'));
        if ($p === null || (int) $p['user_id'] !== (int) $this->user($request)['id']) {
            throw new HttpException(404);
        }
        return $p;
    }

    private function recipient(string $handle): array
    {
        $owner = $this->c->get(PageRouter::class)->ownerByHandle($handle);
        if ($owner === null) {
            throw new HttpException(404);
        }
        $r = $this->c->get(Connection::class)->first(
            'SELECT u.id, u.first_name, u.last_name, u.handle, u.country_id, u.avatar_path, c.code AS country_code, c.name_fa AS country_fa
             FROM users u JOIN countries c ON c.id = u.country_id WHERE u.id = ? AND u.status IN (1, 2)',
            [$owner['id']]
        );
        if ($r === null) {
            throw new HttpException(404);
        }
        return $r;
    }

    /** @return list<int> */
    private function interests(int $userId): array
    {
        return array_map('intval', array_column(
            $this->c->get(Connection::class)->select('SELECT category_id FROM user_interests WHERE user_id = ?', [$userId]),
            'category_id'
        ));
    }

    private function form(Request $request, ?array $p, array $old, array $errors, int $status = 200): Response
    {
        return $this->view($request, 'proposals/form', [
            'title' => $p === null ? 'پیشنهاد تجاری جدید' : 'ویرایش پیشنهاد',
            'p' => $p,
            'old' => $old,
            'errors' => $errors,
            'categories' => $this->c->get(ReferenceData::class)->categories(),
            'fee' => $this->c->get(ProposalService::class)->publishFee(),
        ], 'layouts/app', $status);
    }
}
