<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Env;
use App\Core\Settings\Settings;

/**
 * Google sign-in (OAuth 2.0 authorization code flow with PKCE). Credentials come from GOOGLE_CLIENT_ID /
 * GOOGLE_CLIENT_SECRET in .env, or from Admin › Settings. The id_token is read straight from Google's token
 * endpoint over TLS, so its claims are trusted without checking the signature (Google's documented rule).
 */
final class GoogleAuth
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(private Settings $settings)
    {
    }

    public function clientId(): string
    {
        return trim((string) (Env::get('GOOGLE_CLIENT_ID') ?: $this->settings->get('google.client_id', '')));
    }

    private function clientSecret(): string
    {
        return trim((string) (Env::get('GOOGLE_CLIENT_SECRET') ?: $this->settings->get('google.client_secret', '')));
    }

    public function fromEnv(): bool
    {
        return (string) Env::get('GOOGLE_CLIENT_ID', '') !== '';
    }

    public function hasSecret(): bool
    {
        return $this->clientSecret() !== '';
    }

    public function enabled(): bool
    {
        return (bool) $this->settings->get('google.enabled', false) && $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    public static function redirectUri(): string
    {
        return rtrim((string) Env::get('APP_URL', 'https://aradbranding.app'), '/') . '/auth/google/callback';
    }

    public function authUrl(string $state, string $verifier, ?string $hint = null): string
    {
        $query = [
            'client_id' => $this->clientId(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email',
            'state' => $state,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ];
        if ($hint !== null && $hint !== '') {
            $query['login_hint'] = $hint;
        }
        return self::AUTH_URL . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array{sub: string, email: string}|null null when Google refuses the code or the account is unusable */
    public function exchange(string $code, string $verifier): ?array
    {
        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => self::redirectUri(),
                'grant_type' => 'authorization_code',
                'code_verifier' => $verifier,
            ]),
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
        ]);
        $raw = curl_exec($ch);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $parts = is_array($data) ? explode('.', (string) ($data['id_token'] ?? '')) : [];
        if (count($parts) !== 3) {
            return null;
        }
        $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        if (!is_array($claims)
            || !in_array($claims['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
            || ($claims['aud'] ?? '') !== $this->clientId()
            || (int) ($claims['exp'] ?? 0) < time()
            || ($claims['email_verified'] ?? false) !== true
            || !is_string($claims['sub'] ?? null) || !is_string($claims['email'] ?? null)) {
            return null;
        }
        return ['sub' => $claims['sub'], 'email' => self::canonical($claims['email'])];
    }

    /** Gmail ignores dots and "+tags" and treats googlemail.com as gmail.com: ali.reza+x@GMail.com → alireza@gmail.com */
    public static function canonical(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        [$local, $domain] = [substr($email, 0, $at), substr($email, $at + 1)];
        if ($domain === 'gmail.com' || $domain === 'googlemail.com') {
            $local = str_replace('.', '', explode('+', $local, 2)[0]);
            $domain = 'gmail.com';
        }
        return $local . '@' . $domain;
    }

    public static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
