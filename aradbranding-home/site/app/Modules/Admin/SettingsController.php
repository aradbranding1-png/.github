<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Cache\Cache;
use App\Core\Db\Connection;
use App\Core\Http\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Audit;
use App\Core\Settings\Settings;
use App\Modules\Wallet\Pricing;

/** /admin/settings — prices and billing switches. Requires settings.manage. Every change is audited. */
final class SettingsController extends Controller
{
    public const ACTIONS = [
        'page_view' => 'مشاهده کامل صفحه تجاری',
        'proposal_send' => 'ارسال پیشنهاد به یک تاجر',
        'public_letter' => 'نامه عمومی (هر گیرنده)',
        'private_letter' => 'نامه اختصاصی (هر گیرنده)',
    ];

    public function show(Request $request, array $errors = [], int $status = 200): Response
    {
        $settings = $this->c->get(Settings::class);
        return $this->view($request, 'admin/settings', [
            'title' => 'تنظیمات سامانه',
            'prices' => $this->c->get(Pricing::class)->all(),
            'actions' => self::ACTIONS,
            'publishEnabled' => (bool) $settings->get('proposal_publish.enabled', false),
            'chargeMode' => (string) $settings->get('page_view.charge_mode', 'every_view'),
            'windowMinutes' => (int) $settings->get('page_view.view_window_minutes', 30),
            'periodHours' => (int) $settings->get('page_view.period_hours', 24),
            'lifetimeDays' => (int) $settings->get('proposal.lifetime_days', 90),
            'tomanPerStar' => intdiv((int) ($this->c->get(Pricing::class)->rate('IRR')['minor_per_star'] ?? 10000), 10),
            'packages' => $this->c->get(Connection::class)->select('SELECT id, stars, bonus_stars, active FROM star_packages ORDER BY sort, stars'),
            'maxRecipients' => (int) $settings->get('letters.public_max_recipients', 10000),
            'dailyCampaigns' => (int) $settings->get('letters.public_daily_campaigns', 3),
            'uploadKb' => (int) $settings->get('upload.max_kb', 200),
            'showStats' => (bool) $settings->get('landing.show_stats', false),
            'purchaseEnabled' => (bool) $settings->get('payments.purchase_enabled', false),
            'unlockCharge' => (bool) $settings->get('pages.unlock_charge', false),
            'dailyDigest' => (bool) $settings->get('notifications.daily_digest', true),
            'autoBackup' => (bool) $settings->get('backup.auto_daily', false),
            'retention' => (int) $settings->get('backup.retention_days', 7),
            'old' => $status === 422 ? $request->all() : [],
            'errors' => $errors,
        ], 'layouts/app', $status);
    }

    public function update(Request $request): Response
    {
        $actor = $this->user($request)['id'];
        $errors = [];
        $num = static function (mixed $v, int $min, int $max) use (&$errors): ?int {
            $v = \App\Core\Support\Str::latinDigits(trim((string) $v));
            return ctype_digit($v) && (int) $v >= $min && (int) $v <= $max ? (int) $v : null;
        };

        $prices = [];
        foreach (array_keys(self::ACTIONS) as $action) {
            foreach (['domestic', 'international'] as $scope) {
                $value = $num($request->input("price_{$action}_{$scope}"), 0, 100000);
                if ($value === null) {
                    $errors["price_{$action}_{$scope}"] = 'عدد ۰ تا ۱۰۰٬۰۰۰';
                }
                $prices[] = [$action, $scope, $value];
            }
        }
        $publishPrice = $num($request->input('publish_price'), 0, 100000);
        $window = $num($request->input('window_minutes'), 1, 1440);
        $period = $num($request->input('period_hours'), 1, 720);
        $lifetime = $num($request->input('lifetime_days'), 1, 365);
        $mode = $request->input('charge_mode') === 'once_per_period' ? 'once_per_period' : 'every_view';
        $publishEnabled = (bool) $request->input('publish_enabled');
        foreach (['publish_price' => $publishPrice, 'window_minutes' => $window, 'period_hours' => $period, 'lifetime_days' => $lifetime] as $k => $v) {
            if ($v === null) {
                $errors[$k] = 'مقدار معتبر نیست.';
            }
        }
        if ($publishEnabled && $publishPrice === 0) {
            $errors['publish_price'] = 'وقتی هزینه انتشار فعال است، مبلغ باید بیشتر از صفر باشد.';
        }
        $toman = $num($request->input('toman_per_star'), 1, 100_000_000);
        $maxRecipients = $num($request->input('max_recipients'), 1, 1_000_000);
        $daily = $num($request->input('daily_campaigns'), 1, 1000);
        $uploadKb = $num($request->input('upload_kb'), 20, 10_000);
        $retention = $num($request->input('retention_days'), 1, 365);
        if ($retention === null) {
            $errors['retention_days'] = 'مقدار معتبر نیست.';
        }
        foreach (['toman_per_star' => $toman, 'max_recipients' => $maxRecipients, 'daily_campaigns' => $daily, 'upload_kb' => $uploadKb] as $k => $v) {
            if ($v === null) {
                $errors[$k] = 'مقدار معتبر نیست.';
            }
        }

        // Star packages: edit / deactivate / delete existing, optionally add one.
        $packages = [];
        $seen = [];
        foreach ((array) $request->input('pkg', []) as $id => $row) {
            if (!is_array($row) || !ctype_digit((string) $id)) {
                continue;
            }
            $delete = !empty($row['delete']);
            $stars = $num($row['stars'] ?? '', 1, 1_000_000);
            $bonus = $num($row['bonus'] ?? '', 0, 1_000_000);
            if (!$delete && ($stars === null || $bonus === null)) {
                $errors['packages'] = 'تعداد Star و هدیه هر بسته باید عدد باشد.';
                continue;
            }
            if (!$delete && isset($seen[$stars])) {
                $errors['packages'] = 'دو بسته با تعداد Star یکسان نمی‌تواند وجود داشته باشد.';
            }
            $seen[$stars] = true;
            $packages[] = ['id' => (int) $id, 'stars' => $stars, 'bonus' => $bonus, 'active' => !empty($row['active']), 'delete' => $delete];
        }
        $newStars = trim((string) $request->input('new_stars', ''));
        $newPackage = null;
        if ($newStars !== '') {
            $ns = $num($newStars, 1, 1_000_000);
            $nb = $num($request->input('new_bonus', '0') ?: '0', 0, 1_000_000);
            if ($ns === null || $nb === null) {
                $errors['packages'] = 'بسته جدید معتبر نیست.';
            } elseif (isset($seen[$ns])) {
                $errors['packages'] = 'بسته‌ای با همین تعداد Star وجود دارد.';
            } else {
                $newPackage = [$ns, $nb];
            }
        }
        if ($errors !== []) {
            return $this->show($request, $errors, 422);
        }

        $db = $this->c->get(Connection::class);
        $db->transaction(function (Connection $db) use ($prices, $publishPrice, $actor, $packages, $newPackage, $toman): void {
            foreach ($packages as $i => $p) {
                if ($p['delete']) {
                    $db->exec('DELETE FROM star_packages WHERE id = ?', [$p['id']]);
                    continue;
                }
                // Temporarily move unique values out of the way so swaps between rows never collide.
                $db->exec('UPDATE star_packages SET stars = ? WHERE id = ?', [2_000_000 + $p['id'], $p['id']]);
            }
            foreach ($packages as $i => $p) {
                if (!$p['delete']) {
                    $db->exec('UPDATE star_packages SET stars = ?, bonus_stars = ?, active = ?, sort = ? WHERE id = ?',
                        [$p['stars'], $p['bonus'], $p['active'] ? 1 : 0, $i + 1, $p['id']]);
                }
            }
            if ($newPackage !== null) {
                $db->exec('INSERT INTO star_packages (stars, bonus_stars, active, sort) VALUES (?, ?, 1, ?)', [$newPackage[0], $newPackage[1], count($packages) + 1]);
            }
            $db->exec('UPDATE fx_rates SET minor_per_star = ?, updated_by = ?, updated_at = NOW(3) WHERE currency = ?', [$toman * 10, $actor, 'IRR']);
            foreach ([...$prices, ['proposal_publish', 'domestic', $publishPrice], ['proposal_publish', 'international', $publishPrice]] as [$a, $s, $v]) {
                $db->exec(
                    'INSERT INTO pricing_rules (action, scope, stars, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW(3))
                     ON DUPLICATE KEY UPDATE stars = ?, updated_by = ?, updated_at = NOW(3)',
                    [$a, $s, $v, $actor, $v, $actor]
                );
            }
        });
        $settings = $this->c->get(Settings::class);
        $settings->set('proposal_publish.enabled', $publishEnabled, $actor);
        $settings->set('page_view.charge_mode', $mode, $actor);
        $settings->set('page_view.view_window_minutes', $window, $actor);
        $settings->set('page_view.period_hours', $period, $actor);
        $settings->set('proposal.lifetime_days', $lifetime, $actor);
        $settings->set('letters.public_max_recipients', $maxRecipients, $actor);
        $settings->set('letters.public_daily_campaigns', $daily, $actor);
        $settings->set('upload.max_kb', $uploadKb, $actor);
        $settings->set('landing.show_stats', (bool) $request->input('show_stats'), $actor);
        $settings->set('payments.purchase_enabled', (bool) $request->input('purchase_enabled'), $actor);
        $settings->set('pages.unlock_charge', (bool) $request->input('unlock_charge'), $actor);
        $settings->set('notifications.daily_digest', (bool) $request->input('daily_digest'), $actor);
        $settings->set('backup.auto_daily', (bool) $request->input('auto_backup'), $actor);
        $settings->set('backup.retention_days', $retention, $actor);
        $this->c->get(\App\Core\Cache\Cache::class)->bump('landing');
        $this->c->get(Cache::class)->bump('pricing');

        $this->c->get(Audit::class)->log('settings.update', $actor, 'settings', null, 'success', $request, [
            'prices' => $prices, 'publish_enabled' => $publishEnabled, 'publish_price' => $publishPrice,
            'charge_mode' => $mode, 'window' => $window, 'period' => $period, 'lifetime' => $lifetime,
            'toman_per_star' => $toman, 'packages' => $packages, 'new_package' => $newPackage,
            'max_recipients' => $maxRecipients, 'daily' => $daily, 'upload_kb' => $uploadKb,
            'purchase_enabled' => (bool) $request->input('purchase_enabled'),
            'unlock_charge' => (bool) $request->input('unlock_charge'),
        ]);
        return $this->redirect('/admin/settings', 'تنظیمات ذخیره شد و از همین لحظه اعمال می‌شود.');
    }
}
