<?php

declare(strict_types=1);

namespace App\Modules\Letters;

use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\ContactGuard;
use App\Core\Security\Idempotency;
use App\Core\Support\Ulid;
use App\Modules\Pages\PageRouter;
use App\Modules\Reference\ReferenceData;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\WalletService;

final class LetterController extends Controller
{
    private const FOLDERS = ['inbox' => LetterService::INBOX, 'sent' => LetterService::SENT, 'archive' => LetterService::ARCHIVE];

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $folderKey = (string) $request->query('folder', 'inbox');
        $category = (string) $request->query('type', 'all');
        if (!array_key_exists($category, LetterService::CATEGORIES)) {
            $category = 'all';
        }
        $letters = $this->c->get(LetterService::class);
        $data = [
            'title' => 'نامه‌ها',
            'folderKey' => $folderKey,
            'category' => $category,
            'rows' => [],
            'next' => null,
            'campaigns' => [],
            'announcements' => [],
            'lastSeen' => 0,
            'unreadByType' => $letters->unreadByType($user['id']),
            'unreadOfficial' => (int) ($user['unread_official'] ?? 0),
            'orgName' => (string) $this->c->get(\App\Core\Settings\Settings::class)->get('org.name', 'آراد برندینگ'),
        ];

        if ($folderKey === 'groups') {
            $data['campaigns'] = $this->c->get(CampaignService::class)->listForSender($user['id']);
            return $this->view($request, 'letters/index', $data);
        }
        $folder = self::FOLDERS[$folderKey] ?? LetterService::INBOX;
        $data['folderKey'] = (string) array_search($folder, self::FOLDERS, true);

        if ($category === 'official' && $folder === LetterService::INBOX) {
            $ann = $this->c->get(AnnouncementService::class);
            $data['lastSeen'] = $ann->lastSeen($user['id']);
            $data['announcements'] = $ann->latest();
            $ann->markSeen($user['id']);
        }
        $cursor = $request->query('cursor');
        $box = $letters->box($user['id'], $folder, is_string($cursor) ? $cursor : null, LetterService::CATEGORIES[$category]);
        $data['rows'] = $box['rows'];
        $data['next'] = $box['next'];
        return $this->view($request, 'letters/index', $data);
    }

    /** GET /letters/official/{uid} — an announcement from the organisation (read-only). */
    public function official(Request $request): Response
    {
        $a = $this->c->get(AnnouncementService::class)->find((string) $request->param('uid'));
        if ($a === null) {
            throw new HttpException(404);
        }
        return $this->view($request, 'letters/official', [
            'title' => $a['subject'],
            'a' => $a,
            'orgName' => (string) $this->c->get(\App\Core\Settings\Settings::class)->get('org.name', 'آراد برندینگ'),
        ]);
    }

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $letters = $this->c->get(LetterService::class);
        $thread = $letters->thread((string) $request->param('uid'), $user['id']);
        if ($thread === null) {
            throw new HttpException(404);
        }
        $letters->markRead($user['id'], $thread['id']);
        $before = $request->query('before');
        $messages = $letters->messages($thread, is_string($before) && ctype_digit($before) ? (int) $before : null);
        $cards = $letters->userCards(array_merge([$thread['peer_id'], $user['id']], array_column($messages['rows'], 'sender_id')));
        $proposal = null;
        if ($thread['proposal_id']) {
            $proposal = $this->c->get(\App\Core\Db\Connection::class)->first('SELECT public_id, title, thumb_path FROM proposals WHERE id = ?', [$thread['proposal_id']]);
            if ($proposal) {
                $proposal['uid'] = strtolower(Ulid::toString($proposal['public_id']));
            }
        }
        return $this->view($request, 'letters/show', [
            'title' => $thread['subject'],
            'thread' => $thread,
            'messages' => $messages,
            'cards' => $cards,
            'peer' => $cards[(int) $thread['peer_id']] ?? null,
            'proposal' => $proposal,
            'orgName' => (string) $this->c->get(\App\Core\Settings\Settings::class)->get('org.name', 'آراد برندینگ'),
            'errors' => [],
        ]);
    }

    public function reply(Request $request): Response
    {
        $user = $this->user($request);
        $letters = $this->c->get(LetterService::class);
        $thread = $letters->thread((string) $request->param('uid'), $user['id']);
        if ($thread === null) {
            throw new HttpException(404);
        }
        $body = trim((string) $request->input('body', ''));
        if ($body === '' || mb_strlen($body) > 10000) {
            return $this->redirect('/letters/' . $thread['uid'] . '#reply', 'متن پاسخ را بنویسید (حداکثر ۱۰٬۰۰۰ نویسه).', 'error');
        }
        if (ContactGuard::contains($body)) {
            return $this->redirect('/letters/' . $thread['uid'] . '#reply', ContactGuard::message(), 'error');
        }
        try {
            $letters->reply($user, $thread, $body);
        } catch (ValidationFailed $e) {
            return $this->redirect('/letters/' . $thread['uid'], reset($e->errors), 'error');
        }
        return $this->redirect('/letters/' . $thread['uid'] . '#last', 'پاسخ ارسال شد.');
    }

    public function archive(Request $request): Response
    {
        $user = $this->user($request);
        $letters = $this->c->get(LetterService::class);
        $thread = $letters->thread((string) $request->param('uid'), $user['id']);
        if ($thread === null) {
            throw new HttpException(404);
        }
        $toArchive = (int) $thread['folder'] !== LetterService::ARCHIVE;
        $letters->setFolder($user['id'], $thread['id'], $toArchive ? LetterService::ARCHIVE : LetterService::INBOX);
        return $this->redirect('/letters', $toArchive ? 'گفتگو بایگانی شد.' : 'گفتگو به صندوق ورودی برگشت.');
    }

    // ---------- Private letter ----------

    public function compose(Request $request, array $errors = [], int $status = 200): Response
    {
        $user = $this->user($request);
        $to = trim((string) $request->input('to', ''));
        if ($to === '' && $status === 200) {
            return Response::redirect('/letters/send', 302);
        }
        $recipient = $to !== '' ? $this->recipient($to) : null;
        return $this->view($request, 'letters/compose', [
            'title' => 'نامه اختصاصی',
            'recipient' => $recipient,
            'price' => $recipient ? $this->c->get(LetterService::class)->privatePrice($user, $recipient) : null,
            'balance' => $this->c->get(WalletService::class)->balance($user['id']),
            'token' => Idempotency::token(),
            'old' => $request->all(),
            'errors' => $errors,
        ], 'layouts/app', $status);
    }

    public function sendPrivate(Request $request): Response
    {
        $user = $this->user($request);
        $recipient = $this->recipient((string) $request->input('to', ''));
        if ($recipient === null) {
            return $this->compose($request, ['to' => 'تاجری با این نشانی پیدا نشد.'], 422);
        }
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        $token = (string) $request->input('token', '');
        $errors = [];
        if ($subject === '' || mb_strlen($subject) > 150) {
            $errors['subject'] = 'موضوع را بنویسید (حداکثر ۱۵۰ نویسه).';
        }
        if ($body === '' || mb_strlen($body) > 10000) {
            $errors['body'] = 'متن نامه را بنویسید (حداکثر ۱۰٬۰۰۰ نویسه).';
        }
        $errors += $this->offPlatform(['subject' => $subject, 'body' => $body]);
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $errors['body'] = 'فرم منقضی شده است؛ دوباره ارسال کنید.';
        }
        if ($errors !== []) {
            return $this->compose($request, $errors, 422);
        }
        try {
            $uid = $this->c->get(LetterService::class)->sendPrivate($user, $recipient, $subject, $body, $token);
        } catch (ValidationFailed $e) {
            return $this->compose($request, $e->errors, 422);
        } catch (InsufficientStars $e) {
            return $this->redirect('/wallet?need=' . $e->missing() . '&next=' . rawurlencode('/letters/new?to=' . $recipient['handle']), 'موجودی Stars کافی نیست.', 'error');
        }
        return $this->redirect('/letters/' . $uid, 'نامه ارسال شد.');
    }

    // ---------- Public letter ----------

    /** GET /letters/send — "ارسال نامه": recipients first, then what to send (public letter, private letters or a proposal). */
    public function sendNew(Request $request, array $errors = [], int $status = 200): Response
    {
        $user = $this->user($request);
        $ref = $this->c->get(ReferenceData::class);
        $campaigns = $this->c->get(CampaignService::class);
        // Every proposal the user created (drafts and expired ones are published automatically on confirm).
        $proposals = $this->c->get(\App\Core\Db\Connection::class)->select(
            'SELECT public_id, title, type, status, thumb_path FROM proposals
             WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100',
            [$user['id']]
        );
        foreach ($proposals as &$p) {
            $p['uid'] = strtolower(Ulid::toString($p['public_id']));
        }
        $prices = [];
        foreach (array_keys(CampaignService::KINDS) as $kind) {
            $prices[$kind] = $campaigns->unitPrices($kind);
        }
        return $this->view($request, 'letters/send', [
            'title' => 'ارسال نامه',
            'countries' => $ref->countries(),
            'languages' => $ref->languages(),
            'categories' => $ref->categories(),
            'proposals' => $proposals,
            'prices' => $prices,
            'old' => $request->all(),
            'errors' => $errors,
        ], 'layouts/app', $status);
    }

    /** POST /letters/send — count recipients and cost; nothing is charged until the user confirms. */
    public function sendQuote(Request $request): Response
    {
        $user = $this->user($request);
        $kind = (int) $request->input('kind', LetterService::T_PUBLIC);
        $subject = trim((string) $request->input('subject', ''));
        $body = trim((string) $request->input('body', ''));
        $errors = [];
        $proposal = null;
        if ($kind === LetterService::T_PROPOSAL) {
            $uid = (string) $request->input('proposal', '');
            if (Ulid::isValid($uid)) {
                $proposal = $this->c->get(\App\Core\Db\Connection::class)->first(
                    'SELECT id, title FROM proposals WHERE public_id = ? AND user_id = ? AND deleted_at IS NULL',
                    [Ulid::toBinary($uid), $user['id']]
                );
            }
            if ($proposal === null) {
                $errors['proposal'] = 'یکی از پیشنهادهای خود را انتخاب کنید.';
            }
            if (mb_strlen($body) > 1000) {
                $errors['body'] = 'پیام همراه حداکثر ۱۰۰۰ نویسه است.';
            }
        } else {
            if ($subject === '' || mb_strlen($subject) > 150) {
                $errors['subject'] = 'موضوع را بنویسید (حداکثر ۱۵۰ نویسه).';
            }
            if ($body === '' || mb_strlen($body) > 10000) {
                $errors['body'] = 'متن نامه را بنویسید (حداکثر ۱۰٬۰۰۰ نویسه).';
            }
        }
        $errors += $this->offPlatform(['subject' => $subject, 'body' => $body]);
        $handles = array_values(array_unique(array_filter(array_map(
            static fn (string $h): string => strtolower(trim(ltrim(trim($h), '@'))),
            preg_split('/[\s,،]+/u', (string) $request->input('handles', '')) ?: []
        ))));
        $filter = [
            'country' => (int) $request->input('country', 0) ?: null,
            'language' => (int) $request->input('language', 0) ?: null,
            'category' => (int) $request->input('category', 0) ?: null,
            'handles' => array_slice($handles, 0, 200),
        ];
        if ($errors === []) {
            try {
                $uid = $this->c->get(CampaignService::class)->quote($user, $kind, $subject, $body, $filter, $proposal);
                return $this->redirect('/letters/public/' . $uid);
            } catch (ValidationFailed $e) {
                $errors = $e->errors;
            }
        }
        return $this->sendNew($request, $errors, 422);
    }

    public function campaign(Request $request): Response
    {
        $user = $this->user($request);
        $campaign = $this->c->get(CampaignService::class)->find((string) $request->param('uid'), $user['id']);
        if ($campaign === null) {
            throw new HttpException(404);
        }
        $ref = $this->c->get(ReferenceData::class);
        return $this->view($request, 'letters/campaign', [
            'title' => 'ارسال گروهی',
            'campaign' => $campaign,
            'balance' => $this->c->get(WalletService::class)->balance($user['id']),
            'country' => $ref->country($campaign['filter']['country'] ?? null),
            'language' => $ref->language($campaign['filter']['language'] ?? null),
            'category' => $ref->category($campaign['filter']['category'] ?? null),
        ]);
    }

    public function publicConfirm(Request $request): Response
    {
        $user = $this->user($request);
        $service = $this->c->get(CampaignService::class);
        $campaign = $service->find((string) $request->param('uid'), $user['id']);
        if ($campaign === null) {
            throw new HttpException(404);
        }
        try {
            if ((int) ($campaign['kind'] ?? 0) === LetterService::T_PROPOSAL && $campaign['proposal_id']) {
                $this->ensurePublished((int) $campaign['proposal_id'], (int) $user['id']);
            }
            $service->confirm($user, $campaign);
        } catch (InsufficientStars $e) {
            return $this->redirect('/wallet?need=' . $e->missing() . '&next=' . rawurlencode('/letters/public/' . $campaign['uid']), 'موجودی Stars کافی نیست.', 'error');
        } catch (ValidationFailed $e) {
            return $this->redirect('/letters/public/' . $campaign['uid'], reset($e->errors), 'error');
        }
        return $this->redirect('/letters/public/' . $campaign['uid'], 'نامه در صف ارسال قرار گرفت. پیشرفت را همین‌جا ببینید.');
    }

    /**
     * Letters stay inside the platform: no phone numbers, e-mail, links or messenger / social IDs.
     * @param array<string, string> $fields @return array<string, string>
     */
    private function offPlatform(array $fields): array
    {
        $errors = [];
        foreach ($fields as $field => $text) {
            if ($text !== '' && ContactGuard::contains($text)) {
                $errors[$field] = ContactGuard::message();
            }
        }
        return $errors;
    }

    private function recipient(string $handle): ?array
    {
        $owner = $this->c->get(PageRouter::class)->ownerByHandle(strtolower(ltrim($handle, '@')));
        if ($owner === null) {
            return null;
        }
        return $this->c->get(\App\Core\Db\Connection::class)->first(
            'SELECT u.id, u.first_name, u.last_name, u.handle, u.country_id, u.avatar_path, c.code AS country_code, c.name_fa AS country_fa
             FROM users u JOIN countries c ON c.id = u.country_id WHERE u.id = ? AND u.status IN (1, 2)',
            [$owner['id']]
        );
    }

    /** A draft or expired proposal is published before it is sent (publishing fee applies if enabled). */
    private function ensurePublished(int $proposalId, int $userId): void
    {
        $p = $this->c->get(\App\Core\Db\Connection::class)->first(
            'SELECT id, user_id, status, fee_paid FROM proposals WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$proposalId, $userId]
        );
        if ($p !== null && (int) $p['status'] !== \App\Modules\Proposals\ProposalService::PUBLISHED) {
            $this->c->get(\App\Modules\Proposals\ProposalService::class)->publish($p);
        }
    }
}
