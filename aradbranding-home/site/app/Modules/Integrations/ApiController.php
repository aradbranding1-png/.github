<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Auth\Password;
use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Support\Str;
use App\Core\Support\Ulid;
use App\Modules\Notifications\NotificationService;
use App\Modules\Users\AuthService;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\WalletService;

/**
 * Partner API (v1) for Arad Contact: charge a customer's Stars wallet by mobile number when an order is registered
 * there, opening an account first if the number is new, and issue sign-in credentials to hand over in a ticket.
 *
 * Auth: `Authorization: Bearer ab_xxxxxxxx_…` (keys are made in Admin → «اتصال API»), scope per endpoint.
 * Every charge carries the partner's `order_id`; repeating an order never charges twice (the first answer is
 * returned again with "replayed": true). Full reference: docs/API-arad-contact.md.
 */
final class ApiController extends Controller
{
    public function charge(Request $request): Response
    {
        $client = $this->client($request, 'wallet.charge');
        if ($client instanceof Response) {
            return $client;
        }
        $db = $this->c->get(Connection::class);
        $orderId = trim((string) $request->input('order_id', ''));
        $stars = $request->input('stars');
        $note = trim((string) $request->input('note', ''));
        $errors = [];
        if (!preg_match('/^[A-Za-z0-9_.:\-\/]{1,80}$/', $orderId)) {
            $errors['order_id'] = 'order_id is required (1–80 chars: letters, digits, _ . : - /).';
        }
        if (!is_int($stars) && !(is_string($stars) && ctype_digit($stars))) {
            $errors['stars'] = 'stars must be a positive integer.';
        } elseif ((int) $stars < 1 || (int) $stars > 1_000_000) {
            $errors['stars'] = 'stars must be between 1 and 1,000,000.';
        }
        if (mb_strlen($note) > 200) {
            $errors['note'] = 'note is at most 200 characters.';
        }
        [$cc, $national, $phoneError] = $this->phone($request);
        if ($phoneError !== null) {
            $errors['mobile'] = $phoneError;
        }
        if ($errors !== []) {
            return $this->fail($request, $client, 'wallet.charge', 422, 'Validation failed.', $errors, $orderId ?: null);
        }
        $stars = (int) $stars;

        // Same order again → same answer, nothing charged.
        $previous = $db->first(
            'SELECT response FROM api_requests WHERE client_id = ? AND endpoint = ? AND order_id = ? AND status = 200',
            [$client['id'], 'wallet.charge', $orderId]
        );
        if ($previous !== null) {
            $data = json_decode((string) $previous['response'], true) ?: [];
            $data['replayed'] = true;
            $data['credentials'] = null;
            return Response::json($data, 'Order already charged; nothing was charged again.');
        }

        [$user, $created, $password, $error] = $this->findOrCreate($request, $cc, $national);
        if ($error !== null) {
            return $this->fail($request, $client, 'wallet.charge', 422, 'Account could not be created.', $error, $orderId);
        }

        $wallet = $this->c->get(WalletService::class);
        $memo = 'سفارش ' . $orderId . ($note !== '' ? ' · ' . $note : '');
        $txId = $wallet->credit((int) $user['id'], $stars, WalletService::T_PURCHASE, 'api_charge', 'api_order', null,
            'api:' . $client['id'] . ':' . $orderId, null, mb_substr($memo, 0, 255));
        $balance = $wallet->balance((int) $user['id']);
        $this->c->get(NotificationService::class)->notify([(int) $user['id']], 'wallet_credit', null, '/wallet', [
            'n' => $stars, 'subject' => 'شارژ بابت ' . $memo,
        ]);

        $data = [
            'replayed' => false,
            'account_created' => $created,
            'user' => $this->userOut($user, $cc, $national),
            'transaction' => ['id' => $txId, 'stars' => $stars, 'balance' => $balance, 'order_id' => $orderId],
        ];
        $this->log($request, $client, 'wallet.charge', 200, $data, $orderId, (int) $user['id'], $stars, $txId);
        $this->c->get(Audit::class)->log('api.wallet_charge', null, 'user', (int) $user['id'], 'success', $request,
            ['client' => $client['name'], 'order_id' => $orderId, 'stars' => $stars, 'tx' => $txId, 'created' => $created]);
        // The temporary password is returned once and never stored in the request log.
        $data['credentials'] = $created ? $this->credentialsOut($user, $cc, $national, (string) $password) : null;
        return Response::json($data, $created ? 'Account created and wallet charged.' : 'Wallet charged.');
    }

    public function lookup(Request $request): Response
    {
        $client = $this->client($request, 'users.read');
        if ($client instanceof Response) {
            return $client;
        }
        [$cc, $national, $phoneError] = $this->phone($request);
        if ($phoneError !== null) {
            return $this->fail($request, $client, 'users.lookup', 422, 'Validation failed.', ['mobile' => $phoneError]);
        }
        $user = $this->find($cc, $national);
        $data = $user === null
            ? ['exists' => false]
            : ['exists' => true, 'user' => $this->userOut($user, $cc, $national), 'balance' => $this->c->get(WalletService::class)->balance((int) $user['id'])];
        $this->log($request, $client, 'users.lookup', 200, $data, null, $user === null ? null : (int) $user['id']);
        return Response::json($data);
    }

    /** New temporary password for an existing customer (to send in a ticket). Other sessions are signed out. */
    public function credentials(Request $request): Response
    {
        $client = $this->client($request, 'users.credentials');
        if ($client instanceof Response) {
            return $client;
        }
        [$cc, $national, $phoneError] = $this->phone($request);
        if ($phoneError !== null) {
            return $this->fail($request, $client, 'users.credentials', 422, 'Validation failed.', ['mobile' => $phoneError]);
        }
        $user = $this->find($cc, $national);
        if ($user === null) {
            return $this->fail($request, $client, 'users.credentials', 404, 'No account with this mobile number.');
        }
        $password = self::tempPassword();
        $db = $this->c->get(Connection::class);
        $db->exec('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL, updated_at = NOW(3) WHERE id = ?', [Password::hash($password), $user['id']]);
        $db->exec('DELETE FROM sessions WHERE user_id = ?', [$user['id']]);
        $this->log($request, $client, 'users.credentials', 200, ['user' => $this->userOut($user, $cc, $national)], null, (int) $user['id']);
        $this->c->get(Audit::class)->log('api.credentials', null, 'user', (int) $user['id'], 'success', $request, ['client' => $client['name']]);
        return Response::json(['user' => $this->userOut($user, $cc, $national), 'credentials' => $this->credentialsOut($user, $cc, $national, $password)],
            'New temporary password issued. Send it to the customer and ask them to change it after signing in.');
    }

    // ---------- helpers ----------

    /** @return array<string, mixed>|Response */
    private function client(Request $request, string $scope): array|Response
    {
        $auth = (string) $request->header('authorization', '');
        $key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : (string) $request->header('x-api-key', '');
        $client = (new ApiClients($this->c->get(Connection::class)))->authenticate($key, $request->ip());
        if ($client === null) {
            return Response::json([], 'Invalid or missing API key.', 401);
        }
        if (!in_array($scope, $client['scopes'], true)) {
            return $this->fail($request, $client, $scope, 403, 'This key is not allowed to use ' . $scope . '.');
        }
        return $client;
    }

    /** @return array{0: string, 1: string, 2: ?string} calling code, national number, error */
    private function phone(Request $request): array
    {
        $cc = preg_replace('/\D/', '', Str::latinDigits((string) $request->input('country_code', '98'))) ?: '98';
        $raw = trim(Str::latinDigits((string) $request->input('mobile', '')));
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($raw, '+') || str_starts_with($digits, '00')) {
            $digits = ltrim($digits, '0');
            if (!str_starts_with($digits, $cc)) {
                return [$cc, '', 'mobile has a different country code than country_code.'];
            }
            $digits = substr($digits, strlen($cc));
        }
        $national = AuthService::normalizePhone($digits);
        if (strlen($cc) > 4 || strlen($national) < 6 || strlen($national) > 15) {
            return [$cc, $national, 'mobile is required: a valid mobile number (e.g. 09121234567 with country_code 98).'];
        }
        return [$cc, $national, null];
    }

    private function find(string $cc, string $national): ?array
    {
        return $this->c->get(Connection::class)->first(
            'SELECT id, public_id, first_name, last_name, email, handle FROM users WHERE phone_cc = ? AND phone = ? AND deleted_at IS NULL',
            [$cc, $national]
        );
    }

    /** @return array{0: ?array, 1: bool, 2: ?string, 3: ?array} user, created, temp password, errors */
    private function findOrCreate(Request $request, string $cc, string $national): array
    {
        $user = $this->find($cc, $national);
        if ($user !== null) {
            return [$user, false, null, null];
        }
        if ($request->input('create_account', true) === false || $request->input('create_account') === '0') {
            return [null, false, null, ['mobile' => 'No account with this mobile number and create_account is false.']];
        }
        $db = $this->c->get(Connection::class);
        $email = mb_strtolower(Str::cleanEmail((string) $request->input('email', '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $db->scalar('SELECT 1 FROM users WHERE email = ?', [$email]) !== null) {
            $host = parse_url((string) Env::get('APP_URL', 'https://aradbranding.app'), PHP_URL_HOST) ?: 'aradbranding.app';
            $email = 'm' . $cc . $national . '@customers.' . $host;
        }
        $countryId = (int) ($db->scalar('SELECT id FROM countries WHERE calling_code = ? ORDER BY id LIMIT 1', [$cc])
            ?? $db->scalar("SELECT id FROM countries WHERE code = 'IR'"));
        $languageId = (int) $db->scalar("SELECT id FROM languages WHERE code = 'fa'");
        $first = trim(mb_substr((string) $request->input('first_name', ''), 0, 100));
        $last = trim(mb_substr((string) $request->input('last_name', ''), 0, 100));
        $password = self::tempPassword();
        try {
            $id = $this->c->get(AuthService::class)->register([
                'first_name' => $first !== '' ? $first : 'مشتری',
                'last_name' => $last !== '' ? $last : 'آراد ' . substr($national, -4),
                'email' => $email,
                'phone_cc' => $cc,
                'phone' => $national,
                'password' => $password,
                'country_id' => $countryId,
                'language_id' => $languageId,
            ], null);
        } catch (ValidationFailed $e) {
            // Created a moment ago by a parallel request with the same number: use that account.
            $user = $this->find($cc, $national);
            return $user !== null ? [$user, false, null, null] : [null, false, null, $e->errors];
        }
        return [$this->find($cc, $national) ?? ['id' => $id], true, $password, null];
    }

    /** @return array<string, mixed> */
    private function userOut(array $user, string $cc, string $national): array
    {
        return [
            'id' => isset($user['public_id']) ? strtolower(Ulid::toString((string) $user['public_id'])) : null,
            'name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
            'mobile' => '+' . $cc . $national,
        ];
    }

    /** @return array<string, string> */
    private function credentialsOut(array $user, string $cc, string $national, string $password): array
    {
        return [
            'login_url' => rtrim((string) Env::get('APP_URL', 'https://aradbranding.app'), '/') . '/login',
            'username' => '0' . $national,
            'username_alt' => (string) ($user['email'] ?? ''),
            'password' => $password,
            'ticket_text' => "حساب کاربری شما در سامانه توسعه تجارت آماده است.\nنشانی ورود: "
                . rtrim((string) Env::get('APP_URL', 'https://aradbranding.app'), '/') . "/login\nنام کاربری (شماره موبایل): 0" . $national
                . "\nرمز عبور موقت: " . $password . "\nلطفاً پس از ورود، رمز عبور را از «حساب کاربری ← امنیت» تغییر دهید.",
        ];
    }

    private static function tempPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 12; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }

    private function fail(Request $request, array $client, string $endpoint, int $status, string $message, array $errors = [], ?string $orderId = null): Response
    {
        $this->log($request, $client, $endpoint, $status, ['errors' => $errors], null);
        return Response::json($errors === [] ? [] : ['errors' => $errors], $message, $status);
    }

    private function log(Request $request, array $client, string $endpoint, int $status, array $data, ?string $orderId,
        ?int $userId = null, ?int $stars = null, ?int $txId = null): void
    {
        $this->c->get(Connection::class)->exec(
            'INSERT IGNORE INTO api_requests (client_id, endpoint, order_id, user_id, stars, tx_id, status, response, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))',
            [$client['id'], $endpoint, $orderId, $userId, $stars, $txId, $status,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $request->ip()]
        );
    }
}
