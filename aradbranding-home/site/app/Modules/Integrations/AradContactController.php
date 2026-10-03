<?php

declare(strict_types=1);

namespace App\Modules\Integrations;

use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Support\Str;
use App\Modules\Notifications\NotificationService;
use App\Modules\Users\AuthService;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;

/**
 * Internal API for «آراد کانتکت» — /api/integrations/arad-contact/*. Plain JSON (no envelope), as agreed with the
 * Arad Contact team. Auth: `Authorization: Bearer <key>` with a partner key made in Admin → «اتصال API»; a missing or
 * wrong key gets 401 and nothing else is revealed.
 *
 * external_id makes writes idempotent: the first result for an external_id is stored in integration_refs (under a
 * named lock) and every repeat — a retry after a dropped connection, a double click — gets that same result back.
 * Wallet credits carry a second guard: the wallet's own idempotency key.
 * Reference: docs/API-arad-contact-internal.md.
 */
final class AradContactController extends Controller
{
    private const PASSWORD_REPLAY_DAYS = 7;

    /** POST users/lookup {"phones": ["0912…", "۰۹۳۵…"]} → the first registered number. */
    public function lookup(Request $request): Response
    {
        $client = $this->client($request, 'users.read');
        if ($client instanceof Response) {
            return $client;
        }
        $phones = $request->input('phones');
        if (!is_array($phones) || $phones === [] || count($phones) > 20) {
            return $this->error($request, $client, 'lookup', 422, 'validation', 'phones must be a list of 1–20 mobile numbers.');
        }
        $nationals = [];
        foreach ($phones as $p) {
            $n = is_scalar($p) ? self::iranMobile((string) $p) : null;
            if ($n !== null && !in_array($n, $nationals, true)) {
                $nationals[] = $n;
            }
        }
        $found = null;
        if ($nationals !== []) {
            $rows = $this->c->get(Connection::class)->select(
                "SELECT id, phone FROM users WHERE phone_cc = '98' AND deleted_at IS NULL AND phone IN (" . implode(',', array_fill(0, count($nationals), '?')) . ')',
                $nationals
            );
            $byPhone = array_column($rows, 'id', 'phone');
            foreach ($nationals as $n) { // in the caller's order
                if (isset($byPhone[$n])) {
                    $found = ['found' => true, 'user_id' => (int) $byPhone[$n], 'phone' => '0' . $n];
                    break;
                }
            }
        }
        $out = $found ?? ['found' => false];
        $this->log($request, $client, 'ac.lookup', 200, $out, null, $found['user_id'] ?? null);
        return self::json($out);
    }

    /** POST users {"full_name", "phone", "external_id"} → a new account with a temporary password (once per external_id). */
    public function createUser(Request $request): Response
    {
        $client = $this->client($request, 'users.create');
        if ($client instanceof Response) {
            return $client;
        }
        $fullName = trim(preg_replace('/\s+/u', ' ', (string) $request->input('full_name', '')) ?? '');
        $national = self::iranMobile((string) $request->input('phone', ''));
        $externalId = trim((string) $request->input('external_id', ''));
        $errors = [];
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 150) {
            $errors['full_name'] = 'full_name is required (2–150 characters).';
        }
        if ($national === null) {
            $errors['phone'] = 'phone must be an Iranian mobile number, e.g. 09121234567.';
        }
        if (!self::validExternalId($externalId)) {
            $errors['external_id'] = 'external_id is required (1–120 chars: letters, digits, - _ . :).';
        }
        if ($errors !== []) {
            return $this->error($request, $client, 'users', 422, 'validation', 'Validation failed.', $errors, $externalId ?: null);
        }

        return $this->once('user', $externalId, function () use ($request, $client, $fullName, $national, $externalId): Response {
            $db = $this->c->get(Connection::class);
            $existing = $db->first("SELECT id, email FROM users WHERE phone_cc = '98' AND phone = ? AND deleted_at IS NULL", [$national]);
            $password = null;
            if ($existing !== null) {
                // The number already has an account: link this external_id to it; the member keeps their own password.
                $userId = (int) $existing['id'];
                $created = false;
            } else {
                [$first, $last] = array_pad(explode(' ', $fullName, 2), 2, '');
                $password = self::tempPassword();
                $host = parse_url((string) Env::get('APP_URL', 'https://aradbranding.app'), PHP_URL_HOST) ?: 'aradbranding.app';
                try {
                    $userId = $this->c->get(AuthService::class)->register([
                        'first_name' => mb_substr($first, 0, 100),
                        'last_name' => mb_substr($last, 0, 100),
                        'email' => 'm98' . $national . '@customers.' . $host,
                        'phone_cc' => '98',
                        'phone' => $national,
                        'password' => $password,
                        'country_id' => (int) $db->scalar("SELECT id FROM countries WHERE code = 'IR'"),
                        'language_id' => (int) $db->scalar("SELECT id FROM languages WHERE code = 'fa'"),
                    ], null);
                } catch (ValidationFailed $e) {
                    return $this->error($request, $client, 'users', 409, 'conflict', (string) reset($e->errors), [], $externalId);
                }
                $created = true;
            }
            $out = [
                'user_id' => $userId,
                'username' => '0' . $national,
                'password' => $password,
                'login_url' => rtrim((string) Env::get('APP_URL', 'https://aradbranding.app'), '/') . '/login',
                'created' => $created,
            ];
            $this->remember($client, 'user', $externalId, $userId, null, ['password' => null] + $out, $password);
            $this->log($request, $client, 'ac.users', 201, ['password' => $password !== null ? '***' : null] + $out, $externalId, $userId);
            $this->c->get(Audit::class)->log('api.ac_user', null, 'user', $userId, 'success', $request,
                ['client' => $client['name'], 'external_id' => $externalId, 'created' => $created]);
            return self::json($out, $created ? 201 : 200);
        }, function (array $ref): Response {
            $out = json_decode((string) $ref['response'], true) ?: [];
            $out['password'] = $this->reveal($ref);
            $out['replayed'] = true;
            return self::json($out);
        });
    }

    /** GET stars/rate → {"toman_per_star": 1000} */
    public function rate(Request $request): Response
    {
        $client = $this->client($request, 'wallet.charge');
        if ($client instanceof Response) {
            return $client;
        }
        return self::json(['toman_per_star' => $this->tomanPerStar()]);
    }

    /** POST stars/credit {"user_id", "amount_toman", "external_id", "note"} → Stars at today's rate, once per external_id. */
    public function credit(Request $request): Response
    {
        $client = $this->client($request, 'wallet.charge');
        if ($client instanceof Response) {
            return $client;
        }
        $userId = $request->input('user_id');
        $amount = $request->input('amount_toman');
        $externalId = trim((string) $request->input('external_id', ''));
        $note = trim((string) $request->input('note', ''));
        $errors = [];
        if (!self::isInt($userId) || (int) $userId < 1) {
            $errors['user_id'] = 'user_id must be a positive integer.';
        }
        if (!self::isInt($amount) || (int) $amount < 1 || (int) $amount > 100_000_000_000) {
            $errors['amount_toman'] = 'amount_toman must be a positive whole number of tomans.';
        }
        if (!self::validExternalId($externalId)) {
            $errors['external_id'] = 'external_id is required (1–120 chars: letters, digits, - _ . :).';
        }
        if (mb_strlen($note) > 200) {
            $errors['note'] = 'note is at most 200 characters.';
        }
        if ($errors !== []) {
            return $this->error($request, $client, 'stars.credit', 422, 'validation', 'Validation failed.', $errors, $externalId ?: null);
        }

        return $this->once('credit', $externalId, function () use ($request, $client, $userId, $amount, $externalId, $note): Response {
            $db = $this->c->get(Connection::class);
            $user = $db->first('SELECT id FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $userId]);
            if ($user === null) {
                return $this->error($request, $client, 'stars.credit', 404, 'user_not_found', 'No account with this user_id.', [], $externalId);
            }
            $rate = $this->tomanPerStar();
            $stars = intdiv((int) $amount, $rate);
            if ($stars < 1) {
                return $this->error($request, $client, 'stars.credit', 422, 'amount_too_small',
                    'amount_toman is less than the price of one Star (' . $rate . ' toman).', ['toman_per_star' => $rate], $externalId);
            }
            $memo = mb_substr('آراد کانتکت · ' . number_format((int) $amount) . ' تومان' . ($note !== '' ? ' · ' . $note : '') . ' · ' . $externalId, 0, 255);
            $wallet = $this->c->get(WalletService::class);
            $txId = $wallet->credit((int) $userId, $stars, WalletService::T_PURCHASE, 'api_charge', 'arad_contact', null,
                'arad-contact-credit:' . $externalId, null, $memo);
            $out = [
                'stars' => $stars,
                'toman_per_star' => $rate,
                'balance' => $wallet->balance((int) $userId),
                'transaction_id' => (string) $txId,
                'remainder_toman' => (int) $amount - $stars * $rate,
            ];
            $this->remember($client, 'credit', $externalId, (int) $userId, $txId, $out, null);
            $this->log($request, $client, 'ac.stars.credit', 200, $out, $externalId, (int) $userId, $stars, $txId);
            $this->c->get(NotificationService::class)->notify([(int) $userId], 'wallet_credit', null, '/wallet', [
                'n' => $stars, 'subject' => 'شارژ از آراد کانتکت' . ($note !== '' ? ': ' . $note : '') . '.',
            ]);
            $this->c->get(Audit::class)->log('api.ac_credit', null, 'user', (int) $userId, 'success', $request,
                ['client' => $client['name'], 'external_id' => $externalId, 'amount_toman' => (int) $amount, 'stars' => $stars, 'rate' => $rate, 'tx' => $txId]);
            return self::json($out);
        }, static function (array $ref): Response {
            return self::json((json_decode((string) $ref['response'], true) ?: []) + ['replayed' => true]);
        });
    }

    // ---------- plumbing ----------

    /**
     * Runs $first only for the first request with this external_id; later ones get $replay(stored ref).
     * A named lock serialises concurrent duplicates (the second waits, then sees the stored result).
     */
    private function once(string $kind, string $externalId, callable $first, callable $replay): Response
    {
        $db = $this->c->get(Connection::class);
        $lock = 'ac:' . $kind . ':' . hash('sha256', $externalId);
        if ((int) $db->scalar('SELECT GET_LOCK(?, 15)', [$lock]) !== 1) {
            return self::json(['error' => 'busy', 'message' => 'The same external_id is being processed; retry in a moment.'], 409);
        }
        try {
            $ref = $db->first('SELECT * FROM integration_refs WHERE kind = ? AND external_id = ?', [$kind, $externalId]);
            return $ref !== null ? $replay($ref) : $first();
        } finally {
            $db->scalar('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function remember(array $client, string $kind, string $externalId, ?int $userId, ?int $txId, array $response, ?string $password): void
    {
        $this->c->get(Connection::class)->exec(
            'INSERT IGNORE INTO integration_refs (client_id, kind, external_id, user_id, tx_id, response, secret, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(3))',
            [$client['id'], $kind, $externalId, $userId, $txId, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $password !== null ? $this->seal($password) : null]
        );
    }

    /** The temporary password of a replayed "create user", while it is still fresh; null afterwards. */
    private function reveal(array $ref): ?string
    {
        if ($ref['secret'] === null || strtotime((string) $ref['created_at'] . ' UTC') < time() - self::PASSWORD_REPLAY_DAYS * 86400) {
            return null;
        }
        $raw = (string) $ref['secret'];
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->boxKey());
        return $plain === false ? null : $plain;
    }

    private function seal(string $secret): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($secret, $nonce, $this->boxKey());
    }

    private function boxKey(): string
    {
        return sodium_crypto_generichash('arad-contact-temp-password|' . (string) Env::get('APP_KEY', ''), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private function tomanPerStar(): int
    {
        return max(1, intdiv((int) ($this->c->get(Pricing::class)->rate('IRR')['minor_per_star'] ?? 10000), 10));
    }

    /** @return array<string, mixed>|Response */
    private function client(Request $request, string $scope): array|Response
    {
        $key = preg_match('/^Bearer\s+(\S+)$/i', (string) $request->header('authorization', ''), $m) ? $m[1] : '';
        $client = (new ApiClients($this->c->get(Connection::class)))->authenticate($key, $request->ip());
        if ($client === null) {
            return self::json(['error' => 'unauthorized', 'message' => 'Missing or invalid API key.'], 401)
                ->withHeader('WWW-Authenticate', 'Bearer realm="arad-contact"');
        }
        if (!in_array($scope, $client['scopes'], true)) {
            return $this->error($request, $client, $scope, 403, 'forbidden', 'This key is not allowed to use ' . $scope . '.');
        }
        return $client;
    }

    private function error(Request $request, array $client, string $endpoint, int $status, string $code, string $message, array $errors = [], ?string $externalId = null): Response
    {
        $out = ['error' => $code, 'message' => $message] + ($errors !== [] ? ['errors' => $errors] : []);
        $this->log($request, $client, 'ac.' . $endpoint, $status, $out, null);
        return self::json($out, $status);
    }

    private function log(Request $request, array $client, string $endpoint, int $status, array $data, ?string $externalId,
        ?int $userId = null, ?int $stars = null, ?int $txId = null): void
    {
        $this->c->get(Connection::class)->exec(
            'INSERT IGNORE INTO api_requests (client_id, endpoint, order_id, user_id, stars, tx_id, status, response, ip, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3))',
            [$client['id'], $endpoint, $externalId !== null ? mb_substr($externalId, 0, 80) : null, $userId, $stars, $txId, $status,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $request->ip()]
        );
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $status,
            ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    /** «۰۹۱۲…», «0912…», «912…», «+98 912…», «0098912…» → «912…» (Iranian mobile, 10 digits); null if not one. */
    public static function iranMobile(string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', Str::latinDigits($raw)) ?? '';
        if (str_starts_with($d, '0098')) {
            $d = substr($d, 4);
        } elseif (str_starts_with($d, '98') && strlen($d) === 12) {
            $d = substr($d, 2);
        }
        $d = ltrim($d, '0');
        return preg_match('/^9\d{9}$/', $d) ? $d : null;
    }

    private static function validExternalId(string $id): bool
    {
        return preg_match('/^[A-Za-z0-9_.:\-]{1,120}$/', $id) === 1;
    }

    private static function isInt(mixed $v): bool
    {
        return is_int($v) || (is_string($v) && ctype_digit($v));
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
}
