<?php
/**
 * ارسال اعلان Push به مرورگر کاربر — پیاده‌سازی خالص PHP بدون کتابخانه بیرونی
 * (چون امکان composer/دانلود پکیج روی این هاست وجود ندارد)، طبق استانداردهای:
 * - RFC 8291 (رمزنگاری پیام با aes128gcm)
 * - RFC 8292 (احراز هویت VAPID)
 * نیازمند افزونه‌های PHP: openssl، gmp، curl.
 */
require_once __DIR__ . '/ec_p256.php';

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($data);
}

/** تبدیل امضای DER به فرمت خام R||S (۶۴ بایت) که JOSE/ES256 نیاز دارد */
function der_to_raw_ecdsa(string $der): string
{
    $offset = 2; // 0x30 len
    $lenOfSeq = ord($der[1]);
    if ($lenOfSeq & 0x80) {
        $offset += ($lenOfSeq & 0x7F);
    }
    // اولین INTEGER (R)
    $offset++; // 0x02
    $rLen = ord($der[$offset]);
    $offset++;
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;
    // دومین INTEGER (S)
    $offset++; // 0x02
    $sLen = ord($der[$offset]);
    $offset++;
    $s = substr($der, $offset, $sLen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
    return $r . $s;
}

/** یک جفت‌کلید VAPID جدید تولید می‌کند: ['private_pem'=>..., 'public_raw_b64url'=>..., 'private_raw_b64url'=>...] */
function vapid_generate_keys(): array
{
    $res = openssl_pkey_new([
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    if (!$res) {
        throw new RuntimeException('امکان ساخت کلید EC وجود ندارد (openssl_pkey_new شکست خورد).');
    }
    openssl_pkey_export($res, $privPem);
    $details = openssl_pkey_get_details($res);
    $pubRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
    $privRaw = $details['ec']['d'];

    return [
        'private_pem'        => $privPem,
        'public_raw_b64url'  => base64url_encode($pubRaw),
        'private_raw_b64url' => base64url_encode($privRaw),
    ];
}

/** ساخت JWT با امضای ES256 برای هدر Authorization طبق VAPID */
function vapid_create_jwt(string $audience, string $subjectMailto, string $privateKeyPem): string
{
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $claims = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => $subjectMailto,
    ]));
    $signingInput = $header . '.' . $claims;

    $key = openssl_pkey_get_private($privateKeyPem);
    if (!$key) {
        throw new RuntimeException('کلید خصوصی VAPID نامعتبر است.');
    }
    openssl_sign($signingInput, $derSignature, $key, OPENSSL_ALGO_SHA256);
    $rawSignature = der_to_raw_ecdsa($derSignature);

    return $signingInput . '.' . base64url_encode($rawSignature);
}

/**
 * رمزنگاری و ارسال یک پیام Push به یک اشتراک مشخص (طبق RFC 8291 + 8292).
 * $subscription: ['endpoint'=>string, 'p256dh'=>base64url, 'auth'=>base64url]
 * برمی‌گرداند: ['ok'=>bool, 'http_code'=>int, 'expired'=>bool, 'error'=>?string]
 */
function web_push_send(array $subscription, string $payloadJson, array $vapidKeys, string $vapidSubjectMailto): array
{
    if (!ec_p256_available()) {
        return ['ok' => false, 'http_code' => 0, 'expired' => false, 'error' => 'افزونه GMP روی سرور فعال نیست.'];
    }

    try {
        $clientPub = base64url_decode($subscription['p256dh']);
        $authSecret = base64url_decode($subscription['auth']);

        // ۱) جفت‌کلید یک‌بارمصرف برای این پیام
        $ephemeral = ec_p256_generate_keypair();
        $sharedSecret = ec_p256_ecdh($ephemeral['priv'], $clientPub);

        // ۲) HKDF مرحله اول: استخراج IKM از راز مشترک با نمک auth_secret
        $keyInfo = "WebPush: info\x00" . $clientPub . $ephemeral['pub'];
        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $ikm = substr(hash_hmac('sha256', $keyInfo . "\x01", $prkKey, true), 0, 32);

        // ۳) HKDF مرحله دوم: تولید کلید رمزنگاری (CEK) و نانس، با نمک تصادفی این پیام
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00" . "\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00" . "\x01", $prk, true), 0, 12);

        // ۴) بدنه پیام + padding (۰x02 به‌عنوان جداکننده، طبق aes128gcm)
        $plaintext = $payloadJson . "\x02";

        $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ciphertext === false) {
            return ['ok' => false, 'http_code' => 0, 'expired' => false, 'error' => 'رمزنگاری پیام شکست خورد.'];
        }
        $body = $ciphertext . $tag;

        // ۵) هدر aes128gcm: salt(16) || rs(4) || idlen(1) || keyid(65)
        $header = $salt . pack('N', 4096) . chr(65) . $ephemeral['pub'];
        $encryptedPayload = $header . $body;

        // ۶) هدر Authorization با JWT امضاشده VAPID
        $endpointUrl = parse_url($subscription['endpoint']);
        $audience = $endpointUrl['scheme'] . '://' . $endpointUrl['host'];
        $jwt = vapid_create_jwt($audience, $vapidSubjectMailto, $vapidKeys['private_pem']);

        $headers = [
            'Authorization: vapid t=' . $jwt . ', k=' . $vapidKeys['public_raw_b64url'],
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'Content-Length: ' . strlen($encryptedPayload),
            'TTL: 86400',
            'Urgency: normal',
        ];

        $ch = curl_init($subscription['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encryptedPayload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        // ۴۰۴/۴۱۰ یعنی این اشتراک دیگر معتبر نیست (کاربر اعلان را غیرفعال کرده یا مرورگر عوض شده)
        $expired = in_array($httpCode, [404, 410], true);

        return ['ok' => $httpCode >= 200 && $httpCode < 300, 'http_code' => $httpCode, 'expired' => $expired, 'error' => $curlErr ?: null];
    } catch (Throwable $e) {
        return ['ok' => false, 'http_code' => 0, 'expired' => false, 'error' => $e->getMessage()];
    }
}
