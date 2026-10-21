<?php

declare(strict_types=1);

namespace App\Modules\Ops;

use App\Core\Config;
use App\Core\Database\Migrator;
use App\Core\Database\PartitionManager;
use App\Core\Db\Connection;
use App\Core\Env;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;

/**
 * Operations for hosts without SSH. Every call needs OPS_TOKEN in the X-Ops-Token header.
 *
 * OPS_TOKEN is deliberately separate from HEALTH_TOKEN: the health token lives in external
 * uptime monitors, while these endpoints can grant Super Admin and credit Stars.
 *  - OPS_TOKEN empty/short  → every /ops/* endpoint is switched off (404). Recommended default.
 *  - OPS_TOKEN == HEALTH_TOKEN → refused (404), so the old shared setup cannot keep working by accident.
 *  - OPS_ALLOWED_IPS (optional, comma-separated IPs/CIDRs) → only these client IPs may call.
 * Wrong or missing token → 404 (the endpoints stay invisible).
 */
final class OpsController extends Controller
{
    public function migrate(Request $request): Response
    {
        $this->guard($request);
        $db = $this->c->get(Connection::class);
        $db->allowLongRunning();
        $ran = (new Migrator($db, BASE_PATH . '/database/migrations'))->migrate();
        $parts = (new PartitionManager(
            $db,
            (array) $this->c->get(Config::class)->get('database.partitioned', []),
            (int) $this->c->get(Config::class)->get('database.partition_months_ahead', 3)
        ))->ensure();
        $this->c->get(Audit::class)->log('ops.migrate', null, null, null, 'success', $request, ['ran' => $ran]);
        return Response::json(['migrations' => $ran, 'partitions' => $parts], count($ran) . ' migration(s) applied')
            ->withHeader('Cache-Control', 'no-store');
    }

    /** Grants Super Admin to an already registered account. */
    public function promoteAdmin(Request $request): Response
    {
        $this->guard($request);
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $db = $this->c->get(Connection::class);
        $userId = $db->scalar('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        if ($userId === null) {
            return Response::json([], 'No account with this email. Register first at /register.', 404);
        }
        $db->exec(
            "INSERT IGNORE INTO user_roles (user_id, role_id, created_at) SELECT ?, id, NOW(3) FROM roles WHERE slug = 'super_admin'",
            [(int) $userId]
        );
        $this->c->get(Audit::class)->log('ops.promote_admin', null, 'user', (int) $userId, 'success', $request);
        return Response::json(['user_id' => (int) $userId], 'Super Admin role granted');
    }

    private function guard(Request $request): void
    {
        $token = (string) Env::get('OPS_TOKEN', '');
        $health = (string) Env::get('HEALTH_TOKEN', '');
        if (strlen($token) < 32 || ($health !== '' && hash_equals($health, $token))) {
            throw new HttpException(404);
        }
        $allowed = trim((string) Env::get('OPS_ALLOWED_IPS', ''));
        if ($allowed !== '' && !\App\Core\Support\IpMatcher::matches($request->ip(), $allowed)) {
            throw new HttpException(404);
        }
        if (!hash_equals($token, (string) $request->header('x-ops-token', ''))) {
            throw new HttpException(404);
        }
    }

    /** Admin credit of Stars (testing / support), until the admin panel ships. Audited. */
    public function walletCredit(Request $request): Response
    {
        $this->guard($request);
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $stars = (int) $request->input('stars', 0);
        $note = trim((string) $request->input('note', ''));
        if ($stars <= 0 || $stars > 100000 || $note === '') {
            return Response::json([], 'stars (1..100000) and note are required', 422);
        }
        $userId = $this->c->get(Connection::class)->scalar('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        if ($userId === null) {
            return Response::json([], 'No account with this email', 404);
        }
        $wallet = $this->c->get(\App\Modules\Wallet\WalletService::class);
        $txId = $wallet->credit((int) $userId, $stars, \App\Modules\Wallet\WalletService::T_ADMIN_CREDIT, 'admin', null, null, null, null, mb_substr($note, 0, 255));
        $this->c->get(Audit::class)->log('ops.wallet_credit', null, 'user', (int) $userId, 'success', $request, ['stars' => $stars, 'note' => $note, 'tx' => $txId]);
        return Response::json(['tx_id' => $txId, 'balance' => $wallet->balance((int) $userId)], 'Stars credited');
    }

    /** Fill daily metrics for past days (first run after installing the admin panel). */
    public function backfillMetrics(Request $request): Response
    {
        $this->guard($request);
        @set_time_limit(600);
        $this->c->get(\App\Core\Db\Connection::class)->allowLongRunning();
        $days = max(1, min(730, (int) $request->input('days', 90)));
        $m = $this->c->get(\App\Modules\Admin\MetricsService::class);
        for ($i = $days; $i >= 0; $i--) {
            $m->computeDay($m::today(-$i));
        }
        return Response::json(['days' => $days], 'Metrics computed');
    }
}
