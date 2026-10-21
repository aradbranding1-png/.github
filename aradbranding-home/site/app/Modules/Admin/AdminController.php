<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Auth\Gate;
use App\Core\Auth\Password;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\HttpException;
use App\Core\Http\Request;

/** Shared helpers for admin controllers: data scope and re-authentication. */
abstract class AdminController extends Controller
{
    /**
     * Data scope as a country filter (doc §47): "all" → null (no filter);
     * own/team/department → countries assigned to the staff member (empty = nothing visible).
     * @return list<int>|null
     */
    protected function scopeCountries(Request $request, string $permission): ?array
    {
        $uid = (int) $this->user($request)['id'];
        $scope = $this->c->get(Gate::class)->scope($uid, $permission);
        if ($scope === null) {
            throw new HttpException(403);
        }
        if ($scope === 'all') {
            return null;
        }
        return array_map('intval', array_column(
            $this->c->get(Connection::class)->select('SELECT country_id FROM staff_assignments WHERE user_id = ?', [$uid]),
            'country_id'
        ));
    }

    /** Can this staff member act on a user from $countryId? */
    protected function inScope(Request $request, string $permission, int $countryId): bool
    {
        $countries = $this->scopeCountries($request, $permission);
        return $countries === null || in_array($countryId, $countries, true);
    }

    /** Sensitive actions (wallet, ban, roles) need the admin's password again (doc §102). */
    protected function reauth(Request $request): bool
    {
        $hash = (string) $this->c->get(Connection::class)->scalar('SELECT password_hash FROM users WHERE id = ?', [$this->user($request)['id']]);
        return Password::verify((string) $request->input('password', ''), $hash);
    }

    protected function allows(Request $request, string $permission): bool
    {
        return $this->c->get(Gate::class)->allows((int) $this->user($request)['id'], $permission);
    }
}
