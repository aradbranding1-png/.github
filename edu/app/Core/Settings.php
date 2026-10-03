<?php
declare(strict_types=1);

namespace App\Core;

/** Key/value settings stored in DB with file cache. Secrets are never stored here (they live in .env). */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULTS = [
        'site_name' => 'سامانه آموزش آراد برندینگ',
        'site_tagline' => 'مسیر رشد تاجران، کارمندان و نمایندگان',
        'registration_enabled' => '0',
        'registration_requires_approval' => '1',
        'minutes_enabled' => '1',
        'minutes_charge_text' => 'برای خرید یا شارژ اعتبار زمانی با پشتیبانی آراد برندینگ تماس بگیرید.',
        'registration_segments' => 'merchant,agent,employee',
        'inactivity_days' => '14',
        'deadline_warning_days' => '3',
        'max_upload_mb' => '512',
        'video_complete_percent' => '90',
        'certificate_issuer' => 'آراد برندینگ',
        'sso_enabled' => '0',
        'sso_mode' => 'oauth2',
        'sso_button_label' => 'ورود با حساب my.aradbranding.me',
        'sso_authorize_url' => '',
        'sso_token_url' => '',
        'sso_userinfo_url' => '',
        'sso_logout_url' => '',
        'sso_scopes' => 'openid profile',
        'sso_map_id' => 'id',
        'sso_map_first_name' => 'first_name',
        'sso_map_last_name' => 'last_name',
        'sso_map_mobile' => 'mobile',
        'sso_map_email' => 'email',
        'sso_map_avatar' => 'avatar',
        'sso_map_status' => 'status',
        'sso_active_values' => 'active,1,true',
        'sso_auto_register' => '1',
        'sso_default_segment' => 'merchant',
        'sso_sync_avatar' => '1',
        'update_require_signature' => '0',
        'growth_segments' => 'merchant',
        'growth_track_self' => '1',
        'growth_show_badge' => '1',
        'growth_currency' => 'تومان',
        'growth_deal_docs' => "عکس بارگیری\nفیلم بارگیری\nفیلم معرفی معامله توسط خود تاجر\nاسناد حمل\nفاکتور\nسایر مدارک معامله",
        'growth_api_enabled' => '0',
        'growth_api_url' => '',
        'growth_api_method' => 'GET',
        'growth_api_phone_param' => 'mobile',
        'growth_api_name_param' => 'name',
        'growth_api_phone_format' => '09',
        'growth_api_list_path' => '',
        'growth_api_field_name' => '',
        'growth_api_field_code' => '',
        'growth_api_field_status' => '',
        'growth_api_field_date' => '',
        'growth_api_ok_statuses' => '',
        'growth_api_timeout' => '15',
    ];

    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $file = STORAGE_PATH . '/cache/settings.php';
        if (is_file($file)) {
            $data = include $file;
            if (is_array($data)) return self::$cache = array_merge(self::DEFAULTS, $data);
        }
        $data = [];
        try {
            $data = DB::pairs('SELECT `key`, `value` FROM settings');
            @file_put_contents($file, '<?php return ' . var_export($data, true) . ';', LOCK_EX);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        } catch (\Throwable) {
        }
        return self::$cache = array_merge(self::DEFAULTS, $data);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function set(array $values): void
    {
        foreach ($values as $k => $v) {
            DB::upsert('settings', ['key' => $k, 'value' => (string)$v, 'updated_at' => now()], ['value', 'updated_at']);
        }
        self::clear();
    }

    public static function clear(): void
    {
        self::$cache = null;
        $file = STORAGE_PATH . '/cache/settings.php';
        // opcache would otherwise keep serving the old settings for a few seconds
        if (function_exists('opcache_invalidate')) @opcache_invalidate($file, true);
        @unlink($file);
    }
}
