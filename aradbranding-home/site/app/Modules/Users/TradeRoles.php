<?php

declare(strict_types=1);

namespace App\Modules\Users;

/** The title shown under a member's name (panel header): their trade role, or the staff role in Persian. */
final class TradeRoles
{
    public const TRADE = [
        'exporter' => 'تاجر صادراتی',
        'importer' => 'تاجر واردکننده',
        'international' => 'تاجر بین‌الملل',
        'agent' => 'نماینده بین‌الملل',
        'ally' => 'متحد تجاری',
    ];

    public const DEFAULT = 'international';

    public const STAFF = [
        'super_admin' => 'مدیر کل سامانه', 'admin' => 'مدیر سامانه', 'operations_manager' => 'مدیر عملیات',
        'finance_manager' => 'مدیر مالی', 'support_manager' => 'مدیر پشتیبانی', 'content_manager' => 'مدیر محتوا',
        'moderation_manager' => 'مدیر بررسی محتوا', 'analyst' => 'تحلیلگر',
    ];

    public static function title(?string $roleSlug, ?string $tradeRole): string
    {
        if ($roleSlug !== null && isset(self::STAFF[$roleSlug])) {
            return self::STAFF[$roleSlug];
        }
        return self::TRADE[$tradeRole ?? ''] ?? self::TRADE[self::DEFAULT];
    }
}
