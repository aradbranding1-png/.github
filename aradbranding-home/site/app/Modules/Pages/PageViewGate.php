<?php

declare(strict_types=1);

namespace App\Modules\Pages;

use App\Core\Auth\Gate;
use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Settings\Settings;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;

/**
 * Billing in front of the full page layer (architecture doc §20–21).
 * Financial path (price, charge, grant) is synchronous and atomic.
 * Analytics path (page_viewed event) goes through the outbox.
 *
 * Modes (settings):
 *   every_view       — each unlock is charged; refresh/navigation inside `view_window_minutes` is free.
 *   once_per_period  — one charge per viewer per page every `period_hours`.
 */
final class PageViewGate
{
    public function __construct(
        private Connection $db,
        private WalletService $wallet,
        private Pricing $pricing,
        private Settings $settings,
        private Gate $gate,
        private Outbox $outbox,
    ) {
    }

    /** Whether opening a full page costs Stars at all (admin switch "pages.unlock_charge", off by default). */
    public function charging(): bool
    {
        return (bool) $this->settings->get('pages.unlock_charge', false);
    }

    /** Free for every signed-in trader while charging is off, for the owner and for staff; otherwise needs an active grant. */
    public function hasAccess(array $viewer, int $ownerId, int $pageId): bool
    {
        if (!$this->charging() || (int) $viewer['id'] === $ownerId || $this->gate->allows((int) $viewer['id'], 'pages.view')) {
            return true;
        }
        return $this->db->scalar(
            'SELECT 1 FROM page_view_grants WHERE viewer_id = ? AND page_id = ? AND expires_at > NOW(3)',
            [$viewer['id'], $pageId]
        ) !== null;
    }

    public function price(array $viewer, int $ownerCountryId): int
    {
        return $this->pricing->price('page_view', (int) $viewer['country_id'] === $ownerCountryId);
    }

    /**
     * Charges and grants access. Idempotent per form token (double click = one charge).
     * @throws InsufficientStars
     */
    public function unlock(array $viewer, int $ownerId, int $ownerCountryId, int $pageId, string $token): void
    {
        $price = $this->price($viewer, $ownerCountryId);
        $minutes = $this->settings->get('page_view.charge_mode', 'every_view') === 'once_per_period'
            ? max(1, (int) $this->settings->get('page_view.period_hours', 24)) * 60
            : max(1, (int) $this->settings->get('page_view.view_window_minutes', 30));
        $viewerId = (int) $viewer['id'];

        $this->wallet->spend(
            $viewerId,
            $price,
            'page_view',
            'page',
            $pageId,
            'page_view:' . $viewerId . ':' . $token,
            function () use ($viewerId, $pageId, $minutes, $ownerId, $price): void {
                $this->db->exec(
                    'INSERT INTO page_view_grants (viewer_id, page_id, expires_at) VALUES (?, ?, NOW(3) + INTERVAL ? MINUTE)
                     ON DUPLICATE KEY UPDATE expires_at = NOW(3) + INTERVAL ? MINUTE',
                    [$viewerId, $pageId, $minutes, $minutes]
                );
                $this->outbox->record('page_viewed', $viewerId, $ownerId, $pageId, ['stars' => $price]);
            }
        );
    }
}
