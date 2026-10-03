<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Auth\Auth;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\RateLimiter;
use App\Core\Settings\Settings;
use App\Core\Support\Str;
use App\Core\Support\Ulid;
use App\Modules\Letters\LetterService;
use App\Modules\Notifications\NotificationService;
use App\Modules\Pages\PageRouter;
use App\Modules\Proposals\FeedService;
use App\Modules\Users\TradeRoles;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\WalletService;

/**
 * Official API v1 with personal keys (`Authorization: Bearer ark_…`, made in «API و کلید دسترسی»).
 * The key acts as its owner, limited to its scopes. Public identifiers only (ULIDs and handles, never row ids).
 * Envelope: {"ok": bool, "data": …, "message": "…"}; errors carry data.error = machine code.
 * Paid writes (sending a letter) need an Idempotency-Key header: retrying with the same key never charges twice.
 * Reference: docs/API-v1.md.
 */
final class PublicApiController extends Controller
{
    private const FOLDERS = ['inbox' => LetterService::INBOX, 'sent' => LetterService::SENT, 'archive' => LetterService::ARCHIVE];
    private const TX_TYPES = [
        WalletService::T_PURCHASE => 'purchase', WalletService::T_SPEND => 'spend', WalletService::T_RESERVE => 'reserve',
        WalletService::T_RELEASE => 'release', WalletService::T_REFUND => 'refund', WalletService::T_BONUS => 'bonus',
        WalletService::T_ADMIN_CREDIT => 'admin_credit', WalletService::T_ADMIN_DEBIT => 'admin_debit',
    ];

    /** GET /api/v1/me */
    public function me(Request $request): Response
    {
        return $this->run($request, 'profile.read', function (array $user): Response {
            $db = $this->c->get(Connection::class);
            $counters = $db->first('SELECT unread_letters, unread_notifications, connections, letters_sent, replies, page_views_received, proposal_views_received FROM user_counters WHERE user_id = ?', [$user['id']]) ?? [];
            return Response::json([
                'user' => $this->userOut($user),
                'counters' => array_map('intval', $counters),
                'stars' => $this->c->get(WalletService::class)->balance((int) $user['id']),
            ]);
        });
    }

    /** GET /api/v1/wallet?before={id} */
    public function wallet(Request $request): Response
    {
        return $this->run($request, 'wallet.read', function (array $user) use ($request): Response {
            $wallet = $this->c->get(WalletService::class);
            $before = (string) $request->query('before', '');
            $page = $wallet->history((int) $user['id'], ctype_digit($before) ? (int) $before : null, 25);
            return Response::json([
                'balance' => $wallet->balance((int) $user['id']),
                'transactions' => array_map(fn (array $t): array => [
                    'id' => (int) $t['id'],
                    'type' => self::TX_TYPES[(int) $t['type']] ?? 'other',
                    'stars' => (int) $t['amount'],
                    'balance_after' => (int) $t['balance_after'],
                    'reason' => (string) $t['reason'],
                    'label' => WalletService::REASON_LABELS[$t['reason']] ?? $t['reason'],
                    'note' => $t['note'],
                    'refund_of' => $t['ref_type'] === 'refund_of' ? (int) $t['ref_id'] : null,
                    'created_at' => self::iso($t['created_at']),
                ], $page['rows']),
                'next' => $page['next'] !== null ? (string) $page['next'] : null,
            ]);
        });
    }

    /** GET /api/v1/letters?folder=inbox|sent|archive&type=all|private|public|proposal|official&cursor= */
    public function letters(Request $request): Response
    {
        return $this->run($request, 'letters.read', function (array $user) use ($request): Response {
            $folder = self::FOLDERS[(string) $request->query('folder', 'inbox')] ?? null;
            $typeKey = (string) $request->query('type', 'all');
            if ($folder === null || !array_key_exists($typeKey, LetterService::CATEGORIES)) {
                return self::error(422, 'validation', 'folder must be inbox|sent|archive; type must be all|private|public|proposal|official.');
            }
            $cursor = $request->query('cursor');
            $box = $this->c->get(LetterService::class)->box((int) $user['id'], $folder, is_string($cursor) ? $cursor : null, LetterService::CATEGORIES[$typeKey]);
            return Response::json([
                'threads' => array_map(fn (array $r): array => [
                    'id' => $r['uid'],
                    'type' => array_search((int) $r['thread_type'], LetterService::CATEGORIES, true) ?: 'private',
                    'subject' => $r['subject'],
                    'preview' => $r['preview'],
                    'unread' => (int) $r['unread_count'],
                    'last_message_at' => self::iso($r['last_message_at']),
                    'peer' => $r['peer'] ? $this->peerOut($r['peer']) : null,
                ], $box['rows']),
                'next' => $box['next'],
            ]);
        });
    }

    /** GET /api/v1/letters/{uid}?before={message id} — also marks the thread read. */
    public function thread(Request $request): Response
    {
        return $this->run($request, 'letters.read', function (array $user) use ($request): Response {
            $letters = $this->c->get(LetterService::class);
            $thread = $letters->thread((string) $request->param('uid'), (int) $user['id']);
            if ($thread === null) {
                return self::error(404, 'not_found', 'Thread not found.');
            }
            $letters->markRead((int) $user['id'], $thread['id']);
            $before = (string) $request->query('before', '');
            $page = $letters->messages($thread, ctype_digit($before) ? (int) $before : null);
            $cards = $letters->userCards(array_column($page['rows'], 'sender_id'));
            return Response::json([
                'thread' => [
                    'id' => $thread['uid'],
                    'type' => array_search((int) $thread['type'], LetterService::CATEGORIES, true) ?: 'private',
                    'subject' => $thread['subject'],
                    'folder' => (string) array_search((int) $thread['folder'], self::FOLDERS, true),
                ],
                'messages' => array_map(fn (array $m): array => [
                    'id' => (int) $m['id'],
                    'from' => (int) $m['sender_id'] === (int) $user['id'] ? 'me' : ($cards[(int) $m['sender_id']]['handle'] ?? null),
                    'body' => $m['hidden_at'] === null ? $m['body'] : null,
                    'hidden' => $m['hidden_at'] !== null,
                    'created_at' => self::iso($m['created_at']),
                ], $page['rows']),
                'older' => $page['older'],
            ]);
        });
    }

    /** POST /api/v1/letters {to, subject, body} + Idempotency-Key — a private letter (charged in Stars). */
    public function send(Request $request): Response
    {
        return $this->run($request, 'letters.send', function (array $user) use ($request): Response {
            $token = self::idempotency($request);
            if ($token === null) {
                return self::error(422, 'idempotency_key_required', 'Send an Idempotency-Key header (8–64 chars: letters, digits, - _).');
            }
            $to = strtolower(ltrim(trim((string) $request->input('to', '')), '@'));
            $subject = trim((string) $request->input('subject', ''));
            $body = trim((string) $request->input('body', ''));
            $errors = [];
            if ($subject === '' || mb_strlen($subject) > 150) {
                $errors['subject'] = 'subject is required (max 150 chars).';
            }
            if ($body === '' || mb_strlen($body) > 10000) {
                $errors['body'] = 'body is required (max 10,000 chars).';
            }
            $recipient = $to !== '' ? $this->recipient($to) : null;
            if ($recipient === null) {
                $errors['to'] = 'No active trader with this handle.';
            }
            if ($errors !== []) {
                return self::error(422, 'validation', 'Validation failed.', $errors);
            }
            $letters = $this->c->get(LetterService::class);
            $price = $letters->privatePrice($user, $recipient);
            try {
                $uid = $letters->sendPrivate($user, $recipient, $subject, $body, $token);
            } catch (ValidationFailed $e) {
                return self::error(422, 'not_allowed', (string) reset($e->errors));
            } catch (InsufficientStars $e) {
                return self::error(402, 'insufficient_stars', 'Not enough Stars.', ['required' => $e->required, 'missing' => $e->missing()]);
            }
            $balance = $this->c->get(WalletService::class)->balance((int) $user['id']);
            if ($uid === '') {
                // Same Idempotency-Key as an earlier call: nothing was sent or charged again.
                return Response::json(['replayed' => true, 'thread' => null, 'stars_charged' => 0, 'balance' => $balance], 'Already sent with this Idempotency-Key; nothing was sent or charged again.');
            }
            return Response::json(['replayed' => false, 'thread' => $uid, 'stars_charged' => $price, 'balance' => $balance], 'Letter sent.', 201);
        });
    }

    /** POST /api/v1/letters/{uid}/reply {body} — free. */
    public function reply(Request $request): Response
    {
        return $this->run($request, 'letters.send', function (array $user) use ($request): Response {
            $letters = $this->c->get(LetterService::class);
            $thread = $letters->thread((string) $request->param('uid'), (int) $user['id']);
            if ($thread === null) {
                return self::error(404, 'not_found', 'Thread not found.');
            }
            $body = trim((string) $request->input('body', ''));
            if ($body === '' || mb_strlen($body) > 10000) {
                return self::error(422, 'validation', 'Validation failed.', ['body' => 'body is required (max 10,000 chars).']);
            }
            try {
                $letters->reply($user, $thread, $body);
            } catch (ValidationFailed $e) {
                return self::error(422, 'not_allowed', (string) reset($e->errors));
            }
            return Response::json(['thread' => $thread['uid']], 'Reply sent.', 201);
        });
    }

    /** GET /api/v1/proposals/mine */
    public function myProposals(Request $request): Response
    {
        return $this->run($request, 'proposals.read', function (array $user): Response {
            $rows = $this->c->get(Connection::class)->select(
                'SELECT public_id, type, status, title, summary, view_count, send_count, published_at, expires_at, created_at
                   FROM proposals WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 100',
                [$user['id']]
            );
            $states = [0 => 'draft', 2 => 'published'];
            return Response::json(['proposals' => array_map(static fn (array $p): array => [
                'id' => strtolower(Ulid::toString($p['public_id'])),
                'type' => (int) $p['type'],
                'status' => $states[(int) $p['status']] ?? 'inactive',
                'title' => $p['title'],
                'summary' => $p['summary'],
                'views' => (int) $p['view_count'],
                'sends' => (int) $p['send_count'],
                'published_at' => self::iso($p['published_at']),
                'expires_at' => self::iso($p['expires_at']),
            ], $rows)]);
        });
    }

    /** GET /api/v1/proposals/feed?category=&country=&type=&cursor= */
    public function feed(Request $request): Response
    {
        return $this->run($request, 'proposals.read', function () use ($request): Response {
            $filters = [];
            foreach (['category', 'country', 'type'] as $k) {
                $v = (string) $request->query($k, '');
                if (ctype_digit($v) && (int) $v > 0) {
                    $filters[$k] = (int) $v;
                }
            }
            $cursor = $request->query('cursor');
            $page = $this->c->get(FeedService::class)->latest($filters, is_string($cursor) ? $cursor : null);
            return Response::json([
                'proposals' => array_map(static function (array $c): array {
                    unset($c['id'], $c['thumb'], $c['owner_avatar']);
                    $c['published_at'] = self::iso($c['published_at'] ?? null);
                    return $c;
                }, $page['items']),
                'next' => $page['next'],
            ]);
        });
    }

    /** GET /api/v1/notifications?cursor= */
    public function notifications(Request $request): Response
    {
        return $this->run($request, 'notifications.read', function (array $user) use ($request): Response {
            $cursor = $request->query('cursor');
            $page = $this->c->get(NotificationService::class)->list((int) $user['id'], is_string($cursor) ? $cursor : null);
            return Response::json([
                'notifications' => array_map(static fn (array $n): array => [
                    'type' => $n['type'], 'text' => $n['text'], 'link' => $n['link'], 'read' => (bool) $n['is_read'], 'created_at' => self::iso($n['created_at']),
                ], $page['rows']),
                'next' => $page['next'],
            ]);
        });
    }

    /** GET /api/v1/traders?q= (name or company, 2+ chars) */
    public function traders(Request $request): Response
    {
        return $this->run($request, 'directory.read', function (array $user) use ($request): Response {
            $q = trim((string) $request->query('q', ''));
            $words = array_values(array_filter(preg_split('/\s+/u', Str::normalize($q)) ?: [], static fn (string $w): bool => mb_strlen($w) >= 2));
            if ($words === []) {
                return self::error(422, 'validation', 'q needs at least 2 characters.');
            }
            $rows = $this->c->get(Connection::class)->select(
                'SELECT u.handle, u.first_name, u.last_name, u.business_verified_at, c.code AS country_code, p.company_name, p.trade_role
                   FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.status = 1 AND u.deleted_at IS NULL AND u.handle IS NOT NULL
                    AND (u.handle LIKE ? OR MATCH(u.search_name) AGAINST (? IN BOOLEAN MODE))
                  ORDER BY u.business_verified_at IS NULL, u.id DESC LIMIT 20',
                [strtolower($q) . '%', implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words))]
            );
            return Response::json(['traders' => array_map(fn (array $r): array => $this->traderOut($r), $rows)]);
        });
    }

    /** GET /api/v1/traders/{handle} */
    public function trader(Request $request): Response
    {
        return $this->run($request, 'directory.read', function () use ($request): Response {
            $handle = strtolower((string) $request->param('handle'));
            $owner = $this->c->get(PageRouter::class)->ownerByHandle($handle);
            if ($owner === null || $owner['status'] !== Auth::STATUS_ACTIVE) {
                return self::error(404, 'not_found', 'Trader not found.');
            }
            $db = $this->c->get(Connection::class);
            $r = $db->first(
                'SELECT u.handle, u.first_name, u.last_name, u.business_verified_at, c.code AS country_code, p.company_name, p.trade_role
                   FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN user_profiles p ON p.user_id = u.id WHERE u.id = ?',
                [$owner['id']]
            );
            $pages = $db->select(
                'SELECT l.code, p.title, p.teaser FROM pages p JOIN languages l ON l.id = p.language_id
                  WHERE p.user_id = ? AND p.status = 2 AND p.deleted_at IS NULL ORDER BY p.id',
                [$owner['id']]
            );
            $base = rtrim((string) \App\Core\Env::get('APP_URL', ''), '/');
            return Response::json(['trader' => $this->traderOut($r) + ['pages' => array_map(static fn (array $p): array => [
                'language' => $p['code'], 'title' => $p['title'], 'teaser' => $p['teaser'], 'url' => $base . '/p/' . $handle . '/' . $p['code'],
            ], $pages)]]);
        });
    }

    // ---------- plumbing ----------

    /** Authenticate, check scope/standing, rate-limit per key, then run $fn($user). */
    private function run(Request $request, string $scope, callable $fn): Response
    {
        if (!(bool) $this->c->get(Settings::class)->get('api.enabled', true)) {
            return self::error(503, 'api_disabled', 'The API is turned off by the administrator.');
        }
        $auth = (string) $request->header('authorization', '');
        $bearer = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
        $res = $this->c->get(ApiKeys::class)->authenticate($bearer, $request->ip());
        if ($res['error'] !== null) {
            $messages = [
                'invalid' => 'Missing or invalid API key.',
                'revoked' => 'This API key was revoked.',
                'expired' => 'This API key has expired.',
                'account' => 'The account that owns this key is not active.',
                'forbidden' => 'API access is limited to Super Admin accounts.',
            ];
            return self::error(in_array($res['error'], ['account', 'forbidden'], true) ? 403 : 401, 'unauthorized_' . $res['error'], $messages[$res['error']])
                ->withHeader('WWW-Authenticate', 'Bearer realm="api"');
        }
        $key = $res['key'];
        $user = $res['user'];
        if (!in_array($scope, $key['scopes'], true)) {
            return self::error(403, 'insufficient_scope', 'This key does not have the "' . $scope . '" scope.', ['required_scope' => $scope]);
        }
        if (in_array($scope, ApiKeys::WRITE_SCOPES, true) && (int) $user['status'] === Auth::STATUS_RESTRICTED) {
            return self::error(403, 'account_restricted', 'The account is restricted: read-only access.');
        }
        $limit = $this->c->get(RateLimiter::class)->hit('api', 'key:' . $key['id']);
        if (!$limit['allowed']) {
            return self::error(429, 'rate_limited', 'Too many requests for this key.')->withHeader('Retry-After', (string) $limit['retry_after']);
        }
        return $fn($user)
            ->withHeader('X-RateLimit-Limit', (string) $limit['limit'])
            ->withHeader('X-RateLimit-Remaining', (string) $limit['remaining'])
            ->withHeader('Cache-Control', 'private, no-store');
    }

    private static function error(int $status, string $code, string $message, array $details = []): Response
    {
        return Response::json(['error' => $code] + ($details !== [] ? ['details' => $details] : []), $message, $status);
    }

    /** Idempotency-Key header → the 32-hex token the wallet uses. */
    private static function idempotency(Request $request): ?string
    {
        $k = trim((string) $request->header('idempotency-key', ''));
        return preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $k) ? md5('api:' . $k) : null;
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null || $utc === '' ? null : gmdate('Y-m-d\TH:i:s\Z', (int) strtotime($utc . ' UTC'));
    }

    private function recipient(string $handle): ?array
    {
        $owner = $this->c->get(PageRouter::class)->ownerByHandle($handle);
        if ($owner === null) {
            return null;
        }
        return $this->c->get(Connection::class)->first(
            'SELECT u.id, u.first_name, u.last_name, u.handle, u.country_id, u.avatar_path FROM users u WHERE u.id = ? AND u.status IN (1, 2)',
            [$owner['id']]
        );
    }

    private function userOut(array $u): array
    {
        return [
            'id' => strtolower(Ulid::toString($u['public_id'])),
            'handle' => $u['handle'],
            'first_name' => $u['first_name'],
            'last_name' => $u['last_name'],
            'company' => $u['company_name'],
            'trade_role' => $u['trade_role'],
            'trade_role_label' => TradeRoles::title(null, $u['trade_role']),
            'country' => $u['country_code'],
            'language' => $u['language_code'],
            'restricted' => (int) $u['status'] === Auth::STATUS_RESTRICTED,
            'member_since' => self::iso($u['created_at']),
        ];
    }

    private function peerOut(array $p): array
    {
        return ['handle' => $p['handle'], 'name' => trim($p['first_name'] . ' ' . $p['last_name']), 'company' => $p['company_name'], 'country' => $p['country_code']];
    }

    private function traderOut(array $r): array
    {
        return [
            'handle' => $r['handle'],
            'name' => trim($r['first_name'] . ' ' . $r['last_name']),
            'company' => $r['company_name'],
            'country' => $r['country_code'],
            'trade_role' => $r['trade_role'],
            'verified' => $r['business_verified_at'] !== null,
        ];
    }
}
