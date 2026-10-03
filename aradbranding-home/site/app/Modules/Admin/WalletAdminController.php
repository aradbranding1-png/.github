<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Security\Idempotency;
use App\Core\Support\Str;
use App\Modules\Notifications\NotificationService;
use App\Modules\Users\AuthService;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\WalletService;

/**
 * «کیف پول مشتریان»: find a customer, see the balance and ledger, and credit or debit Stars by hand with a
 * reason. Every change is a normal ledger row (type ADMIN_CREDIT / ADMIN_DEBIT, actor and note kept), is
 * audited, and is announced to the customer by a notification; the customer sees it with its reason in
 * «گردش حساب». Roles: Super Admin, Admin and Finance Manager (wallet.credit / wallet.debit).
 */
final class WalletAdminController extends AdminController
{
    public function index(Request $request, array $errors = [], int $status = 200, ?int $forUser = null): Response
    {
        $countries = $this->scopeCountries($request, 'wallet.view');
        $db = $this->c->get(Connection::class);
        $q = trim((string) $request->query('q', ''));
        $results = [];
        if ($q !== '' && mb_strlen($q) >= 2) {
            [$where, $bind] = self::search($q);
            if ($countries !== null) {
                $where .= $countries === [] ? ' AND 0 = 1' : ' AND u.country_id IN (' . implode(',', array_map('intval', $countries)) . ')';
            }
            $results = $db->select(
                'SELECT u.id, u.first_name, u.last_name, u.handle, u.email, u.phone_cc, u.phone, u.avatar_path, c.code AS country_code,
                        COALESCE(w.balance, 0) AS balance, p.company_name
                   FROM users u JOIN countries c ON c.id = u.country_id
                   LEFT JOIN wallets w ON w.user_id = u.id LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.deleted_at IS NULL AND ' . $where . ' ORDER BY u.id DESC LIMIT 20',
                $bind
            );
        }

        $customer = null;
        $ledger = [];
        $userId = $forUser ?? (int) $request->query('user', '0');
        if ($userId > 0) {
            $customer = $db->first(
                'SELECT u.id, u.first_name, u.last_name, u.handle, u.email, u.phone_cc, u.phone, u.avatar_path, u.country_id,
                        c.code AS country_code, c.name_fa AS country_fa, COALESCE(w.balance, 0) AS balance,
                        COALESCE(w.lifetime_bought, 0) AS bought, COALESCE(w.lifetime_spent, 0) AS spent, p.company_name
                   FROM users u JOIN countries c ON c.id = u.country_id
                   LEFT JOIN wallets w ON w.user_id = u.id LEFT JOIN user_profiles p ON p.user_id = u.id
                  WHERE u.id = ? AND u.deleted_at IS NULL',
                [$userId]
            );
            if ($customer !== null && !$this->inScope($request, 'wallet.view', (int) $customer['country_id'])) {
                $customer = null;
            }
            if ($customer !== null) {
                $ledger = $db->select(
                    'SELECT t.id, t.type, t.amount, t.balance_after, t.reason, t.ref_type, t.ref_id, t.note, t.created_at, a.first_name AS actor_first, a.last_name AS actor_last
                       FROM wallet_transactions t LEFT JOIN users a ON a.id = t.actor_id
                      WHERE t.user_id = ? ORDER BY t.id DESC LIMIT 15',
                    [$userId]
                );
                $wallet = $this->c->get(WalletService::class);
                foreach ($ledger as &$t) {
                    $t['refund'] = in_array((int) $t['type'], [WalletService::T_SPEND, WalletService::T_RESERVE], true)
                        ? $wallet->refundable($userId, (int) $t['id']) : null;
                }
                unset($t);
            }
        }

        return $this->view($request, 'admin/wallet', [
            'title' => 'کیف پول مشتریان',
            'q' => $q,
            'results' => $results,
            'customer' => $customer,
            'ledger' => $ledger,
            'canCredit' => $this->allows($request, 'wallet.credit'),
            'canDebit' => $this->allows($request, 'wallet.debit'),
            'canRefund' => $this->allows($request, 'payments.refund'),
            'token' => Idempotency::token(),
            'errors' => $errors,
            'old' => $status === 422 ? $request->all() : [],
        ], 'layouts/app', $status);
    }

    public function adjust(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $u = $db->first('SELECT id, first_name, last_name, country_id FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $request->param('id')]);
        if ($u === null || !$this->inScope($request, 'wallet.view', (int) $u['country_id'])) {
            throw new HttpException(404);
        }
        $direction = $request->input('direction') === 'debit' ? 'debit' : 'credit';
        if (!$this->allows($request, 'wallet.' . $direction)) {
            throw new HttpException(403);
        }
        // «۱٬۰۰۰», «1,000», «١٠٠٠» and «1000» all mean one thousand Stars.
        $stars = (int) (preg_replace('/\D+/', '', Str::latinDigits((string) $request->input('stars', '0'))) ?: '0');
        $note = trim((string) $request->input('note', ''));
        $token = (string) $request->input('token', '');
        $errors = [];
        if ($stars <= 0 || $stars > 1_000_000) {
            $errors['stars'] = 'تعداد Star را بین ۱ و ۱٬۰۰۰٬۰۰۰ وارد کنید.';
        }
        if (mb_strlen($note) < 3) {
            $errors['note'] = 'توضیح را بنویسید؛ مشتری آن را در گردش حساب خود می‌بیند.';
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            $errors['note'] = 'فرم منقضی شده است؛ دوباره ارسال کنید.';
        }
        if ($errors === [] && !$this->reauth($request)) {
            $errors['password'] = 'رمز عبور شما درست نیست.';
        }
        if ($errors !== []) {
            return $this->index($request, $errors, 422, (int) $u['id']);
        }

        $admin = (int) $this->user($request)['id'];
        $note = mb_substr($note, 0, 255);
        $wallet = $this->c->get(WalletService::class);
        $key = 'admin-adjust:' . $admin . ':' . $token;
        try {
            $txId = $direction === 'credit'
                ? $wallet->credit((int) $u['id'], $stars, WalletService::T_ADMIN_CREDIT, 'admin', null, null, $key, $admin, $note)
                : $wallet->adminDebit((int) $u['id'], $stars, $admin, $note, $key);
        } catch (InsufficientStars) {
            return $this->index($request, ['stars' => 'موجودی مشتری کمتر از این مقدار است.'], 422, (int) $u['id']);
        }
        $this->c->get(NotificationService::class)->notify([(int) $u['id']], 'wallet_' . $direction, $admin, '/wallet', [
            'n' => $stars, 'subject' => 'توضیح: ' . $note,
        ]);
        $this->c->get(Audit::class)->log('wallet.' . $direction, $admin, 'user', (int) $u['id'], 'success', $request,
            ['stars' => $stars, 'note' => $note, 'tx' => $txId, 'via' => 'admin/wallet'], $request->attribute('impersonator_id'));
        return $this->redirect('/admin/wallet?user=' . $u['id'],
            fa_int($stars) . ' Star ' . ($direction === 'credit' ? 'به کیف پول ' : 'از کیف پول ') . trim($u['first_name'] . ' ' . $u['last_name'])
            . ($direction === 'credit' ? ' اضافه شد.' : ' کسر شد.') . ' مشتری اعلان دریافت کرد.');
    }

    /** POST /admin/wallet/{id}/refund/{tx} — return Stars of one spend (Finance / Super Admin: payments.refund). */
    public function refund(Request $request): Response
    {
        $db = $this->c->get(Connection::class);
        $u = $db->first('SELECT id, first_name, last_name, country_id FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $request->param('id')]);
        if ($u === null || !$this->inScope($request, 'payments.refund', (int) $u['country_id'])) {
            throw new HttpException(404);
        }
        $txId = (int) $request->param('tx');
        $stars = (int) (preg_replace('/\D+/', '', Str::latinDigits((string) $request->input('stars', '0'))) ?: '0');
        $note = mb_substr(trim((string) $request->input('note', '')), 0, 255);
        $token = (string) $request->input('token', '');
        $fail = fn (string $msg): Response => $this->index($request, ['refund' . $txId => $msg], 400, (int) $u['id']); // 400: keeps the adjust form empty
        if (mb_strlen($note) < 3) {
            return $fail('دلیل بازپرداخت را بنویسید؛ مشتری آن را در گردش حساب می‌بیند.');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return $fail('فرم منقضی شده است؛ دوباره ارسال کنید.');
        }
        if (!$this->reauth($request)) {
            return $fail('رمز عبور شما درست نیست.');
        }
        $admin = (int) $this->user($request)['id'];
        $wallet = $this->c->get(WalletService::class);
        try {
            $refundId = $wallet->refund((int) $u['id'], $txId, $stars, $admin, $note, 'refund:' . $admin . ':' . $token);
        } catch (\DomainException $e) {
            return $fail($e->getMessage());
        }
        $orig = $db->first('SELECT reason FROM wallet_transactions WHERE id = ?', [$txId]);
        $this->c->get(NotificationService::class)->notify([(int) $u['id']], 'wallet_refund', $admin, '/wallet', [
            'n' => $stars, 'subject' => WalletService::REASON_LABELS[$orig['reason'] ?? ''] ?? 'تراکنش',
        ]);
        $this->c->get(Audit::class)->log('payments.refund', $admin, 'user', (int) $u['id'], 'success', $request,
            ['stars' => $stars, 'of_tx' => $txId, 'tx' => $refundId, 'note' => $note], $request->attribute('impersonator_id'));
        return $this->redirect('/admin/wallet?user=' . $u['id'] . '#tx' . $txId,
            fa_int($stars) . ' Star به ' . trim($u['first_name'] . ' ' . $u['last_name']) . ' بازپرداخت شد. مشتری اعلان دریافت کرد.');
    }

    /** @return array{0: string, 1: list<mixed>} name, handle, e-mail or mobile number */
    private static function search(string $q): array
    {
        if (str_contains($q, '@')) {
            return ['u.email LIKE ?', [mb_strtolower($q) . '%']];
        }
        $digits = preg_replace('/\D/', '', Str::latinDigits($q)) ?? '';
        if ($digits !== '' && strlen($digits) >= 5 && preg_match('/^[\s\d۰-۹+\-()]+$/u', $q)) {
            $national = AuthService::normalizePhone($digits);
            return ['(u.phone LIKE ? OR CONCAT(u.phone_cc, u.phone) LIKE ?)', [$national . '%', $digits . '%']];
        }
        $words = preg_split('/\s+/u', Str::normalize($q)) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => mb_strlen($w) >= 2));
        if ($words === []) {
            return ['u.handle LIKE ?', [strtolower($q) . '%']];
        }
        return [
            '(u.handle LIKE ? OR MATCH(u.search_name) AGAINST (? IN BOOLEAN MODE))',
            [strtolower($q) . '%', implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words))],
        ];
    }
}
