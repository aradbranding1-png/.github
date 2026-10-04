<?php

declare(strict_types=1);

namespace App\Modules\Proposals;

use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Security\ContactGuard;
use App\Core\Settings\Settings;
use App\Core\Storage\ImageUploader;
use App\Core\Storage\UploadException;
use App\Core\Support\Ulid;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;

/**
 * Proposals: create, edit, publish (optional Stars fee), hide, delete, expire, send to a trader.
 * The feed never reads `proposals`; every state change keeps `proposal_feed` in sync.
 */
final class ProposalService
{
    public const DRAFT = 0, PUBLISHED = 2, HIDDEN = 3, REJECTED = 4, EXPIRED = 5;

    public const TYPES = [
        1 => 'درخواست خرید', 2 => 'عرضه و فروش', 3 => 'مشارکت', 4 => 'سرمایه‌گذاری', 5 => 'خدمات', 6 => 'نمایندگی',
    ];
    public const TYPES_EN = [
        1 => 'Buying request', 2 => 'Selling offer', 3 => 'Partnership', 4 => 'Investment', 5 => 'Services', 6 => 'Agency',
    ];
    public const STATUS_LABELS = [
        self::DRAFT => 'پیش‌نویس', self::PUBLISHED => 'منتشرشده', self::HIDDEN => 'مخفی', self::REJECTED => 'ردشده', self::EXPIRED => 'منقضی',
    ];

    /** Text fields that are checked for contact details (proposals are free to read). */
    public const GUARDED = ['title', 'summary', 'body', 'product', 'quantity', 'target_markets', 'terms'];

    public function __construct(
        private Connection $db,
        private ImageUploader $images,
        private WalletService $wallet,
        private Pricing $pricing,
        private Settings $settings,
        private Outbox $outbox,
        private \App\Modules\Letters\LetterService $letters,
        private \App\Modules\Trust\TrustService $trust,
    ) {
    }

    public function publishFee(): int
    {
        return $this->settings->get('proposal_publish.enabled', false) ? $this->pricing->price('proposal_publish', true) : 0;
    }

    /**
     * @param array<string, mixed> $d validated
     * @param array{cover: ?array, gallery: list<array>} $files
     * @return string public id
     */
    public function create(array $user, array $d, array $files): string
    {
        $this->guard($d);
        [$cover, $thumb, $gallery] = $this->storeImages($files, null, null, []);
        $publicId = Ulid::generateBinary();
        $this->db->insert(
            'INSERT INTO proposals (public_id, user_id, type, category_id, country_id, language_id, status, title, summary, body,
                                    product, quantity, target_markets, terms, tags, cover_path, thumb_path, images, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))',
            [$publicId, $user['id'], $d['type'], $d['category_id'], $user['country_id'], $user['language_id'], $d['title'],
                $d['summary'], $d['body'], $d['product'], $d['quantity'], $d['target_markets'], $d['terms'],
                json_encode($d['tags'], JSON_UNESCAPED_UNICODE), $cover, $thumb, json_encode($gallery)]
        );
        return strtolower(Ulid::toString($publicId));
    }

    public function update(array $p, array $d, array $files): void
    {
        $this->guard($d);
        $gallery = json_decode((string) ($p['images'] ?? '[]'), true) ?: [];
        if (!empty($d['remove_gallery'])) {
            foreach ($gallery as $g) {
                $this->images->delete($g);
            }
            $gallery = [];
        }
        [$cover, $thumb, $gallery] = $this->storeImages($files, $d['remove_cover'] ? null : $p['cover_path'], $d['remove_cover'] ? null : $p['thumb_path'], $gallery);
        if ($cover !== $p['cover_path']) {
            $this->images->delete($p['cover_path']);
            $this->images->delete($p['thumb_path']);
        }
        $this->db->exec(
            'UPDATE proposals SET type = ?, category_id = ?, title = ?, summary = ?, body = ?, product = ?, quantity = ?,
                    target_markets = ?, terms = ?, tags = ?, cover_path = ?, thumb_path = ?, images = ?, updated_at = NOW(3)
             WHERE id = ?',
            [$d['type'], $d['category_id'], $d['title'], $d['summary'], $d['body'], $d['product'], $d['quantity'],
                $d['target_markets'], $d['terms'], json_encode($d['tags'], JSON_UNESCAPED_UNICODE), $cover, $thumb,
                json_encode($gallery), $p['id']]
        );
        if ((int) $p['status'] === self::PUBLISHED) {
            $this->syncFeed((int) $p['id']);
        }
    }

    /**
     * Publishes. If the publishing fee is enabled it is charged once per proposal (idempotent).
     * @throws InsufficientStars
     */
    public function publish(array $p): void
    {
        $this->trust->assertCanAct(['status' => $this->db->scalar('SELECT status FROM users WHERE id = ?', [$p['user_id']])], 'proposal');
        $days = max(1, (int) $this->settings->get('proposal.lifetime_days', 90));
        $apply = function () use ($p, $days): void {
            $this->db->exec(
                'UPDATE proposals SET status = ?, published_at = COALESCE(published_at, NOW(3)),
                        expires_at = NOW(3) + INTERVAL ? DAY, updated_at = NOW(3) WHERE id = ?',
                [self::PUBLISHED, $days, $p['id']]
            );
            $this->syncFeed((int) $p['id']);
        };

        $fee = $this->publishFee();
        if ($fee > 0 && (int) $p['fee_paid'] === 0) {
            $this->wallet->spend((int) $p['user_id'], $fee, 'proposal_publish', 'proposal', (int) $p['id'],
                'proposal_publish:' . $p['id'], function () use ($p, $apply): void {
                    $this->db->exec('UPDATE proposals SET fee_paid = 1 WHERE id = ?', [$p['id']]);
                    $apply();
                });
            return;
        }
        $this->db->transaction(static fn () => $apply());
    }

    public function unpublish(array $p): void
    {
        $this->db->transaction(function () use ($p): void {
            $this->db->exec('UPDATE proposals SET status = ?, updated_at = NOW(3) WHERE id = ?', [self::DRAFT, $p['id']]);
            $this->db->exec('DELETE FROM proposal_feed WHERE proposal_id = ?', [$p['id']]);
        });
    }

    public function delete(array $p): void
    {
        $this->db->transaction(function () use ($p): void {
            $this->db->exec('UPDATE proposals SET status = ?, deleted_at = NOW(3), updated_at = NOW(3) WHERE id = ?', [self::HIDDEN, $p['id']]);
            $this->db->exec('DELETE FROM proposal_feed WHERE proposal_id = ?', [$p['id']]);
        });
    }

    /** Cron: expire in batches and drop from the feed. */
    public function expireDue(): int
    {
        $ids = array_map('intval', array_column($this->db->select(
            'SELECT id FROM proposals WHERE status = ? AND expires_at < NOW(3) LIMIT 1000',
            [self::PUBLISHED]
        ), 'id'));
        if ($ids === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $this->db->transaction(function () use ($ids, $marks): void {
            $this->db->exec("UPDATE proposals SET status = " . self::EXPIRED . " WHERE id IN ({$marks})", $ids);
            $this->db->exec("DELETE FROM proposal_feed WHERE proposal_id IN ({$marks})", $ids);
        });
        return count($ids);
    }

    /** Rebuild one feed card from the source row. */
    public function syncFeed(int $id): void
    {
        $r = $this->db->first(
            'SELECT p.id, p.public_id, p.user_id, p.type, p.category_id, p.country_id, p.language_id, p.title, p.tags,
                    p.thumb_path, p.published_at, p.status, p.deleted_at,
                    u.first_name, u.last_name, u.handle, u.avatar_path, u.business_verified_at,
                    c.code AS country_code, up.company_name
             FROM proposals p
             JOIN users u ON u.id = p.user_id
             JOIN countries c ON c.id = p.country_id
             LEFT JOIN user_profiles up ON up.user_id = p.user_id
             WHERE p.id = ?',
            [$id]
        );
        if ($r === null || (int) $r['status'] !== self::PUBLISHED || $r['deleted_at'] !== null) {
            $this->db->exec('DELETE FROM proposal_feed WHERE proposal_id = ?', [$id]);
            return;
        }
        $card = [
            'uid' => strtolower(Ulid::toString($r['public_id'])),
            'title' => $r['title'],
            'type' => (int) $r['type'],
            'thumb' => $r['thumb_path'],
            'owner' => trim(($r['company_name'] ?: $r['first_name'] . ' ' . $r['last_name'])),
            'owner_avatar' => $r['avatar_path'],
            'handle' => $r['handle'],
            'country' => $r['country_code'],
            'tags' => array_slice(json_decode((string) ($r['tags'] ?? '[]'), true) ?: [], 0, 3),
        ];
        $json = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $verified = $r['business_verified_at'] !== null ? 1 : 0;
        $this->db->exec(
            'INSERT INTO proposal_feed (proposal_id, user_id, published_at, category_id, country_id, language_id, type, verified, card)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE category_id = ?, country_id = ?, language_id = ?, type = ?, verified = ?, card = ?',
            [$id, $r['user_id'], $r['published_at'], $r['category_id'], $r['country_id'], $r['language_id'], $r['type'], $verified, $json,
                $r['category_id'], $r['country_id'], $r['language_id'], $r['type'], $verified, $json]
        );
    }

    /**
     * Sends a published proposal to a trader. Price by sender/recipient countries. One send per proposal per recipient.
     * @throws InsufficientStars
     * @throws ValidationFailed
     */
    public function send(array $sender, array $proposal, array $recipient, ?string $message, string $token): int
    {
        if ((int) $proposal['status'] !== self::PUBLISHED) {
            throw new ValidationFailed(['proposal' => t('فقط پیشنهادهای منتشرشده را می‌توانید ارسال کنید.')]);
        }
        if ((int) $recipient['id'] === (int) $sender['id']) {
            throw new ValidationFailed(['proposal' => t('نمی‌توانید برای خودتان پیشنهاد بفرستید.')]);
        }
        $this->trust->assertCanAct($sender, 'proposal');
        try {
            $this->trust->assertCanMessage((int) $sender['id'], (int) $recipient['id']);
        } catch (ValidationFailed $e) {
            throw new ValidationFailed(['proposal' => reset($e->errors)]);
        }
        $exists = $this->db->scalar('SELECT 1 FROM proposal_sends WHERE proposal_id = ? AND recipient_id = ?', [$proposal['id'], $recipient['id']]);
        if ($exists !== null) {
            throw new ValidationFailed(['proposal' => t('این پیشنهاد را قبلاً برای این تاجر فرستاده‌اید.')]);
        }
        $price = $this->pricing->price('proposal_send', (int) $sender['country_id'] === (int) $recipient['country_id']);
        $sendId = 0;
        try {
            $this->wallet->spend((int) $sender['id'], $price, 'proposal_send', 'proposal', (int) $proposal['id'],
                'proposal_send:' . $sender['id'] . ':' . $token,
                function (int $txId) use ($sender, $proposal, $recipient, $message, $price, &$sendId): void {
                    $sendId = $this->db->insert(
                        'INSERT INTO proposal_sends (proposal_id, sender_id, recipient_id, message, cost, tx_id, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW(3))',
                        [$proposal['id'], $sender['id'], $recipient['id'], $message, $price, $txId]
                    );
                    $this->db->exec('UPDATE proposals SET send_count = send_count + 1 WHERE id = ?', [$proposal['id']]);
                    // The proposal also opens a conversation, so the recipient can simply reply.
                    $this->letters->openThread(
                        \App\Modules\Letters\LetterService::T_PROPOSAL,
                        (int) $sender['id'],
                        (int) $recipient['id'],
                        (string) $proposal['title'],
                        $message ?? t('پیشنهاد «:title» را برای شما فرستادم.', ['title' => $proposal['title']]),
                        (int) $proposal['id']
                    );
                    $this->outbox->record('proposal_sent', (int) $sender['id'], (int) $recipient['id'], (int) $proposal['id']);
                });
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                throw new ValidationFailed(['proposal' => t('این پیشنهاد را قبلاً برای این تاجر فرستاده‌اید.')]);
            }
            throw $e;
        }
        return $sendId;
    }

    private function guard(array $d): void
    {
        $errors = [];
        foreach (self::GUARDED as $field) {
            if (is_string($d[$field] ?? null) && ContactGuard::contains($d[$field])) {
                $errors[$field] = ContactGuard::message();
            }
        }
        foreach ($d['tags'] ?? [] as $tag) {
            if (ContactGuard::contains($tag)) {
                $errors['tags'] = ContactGuard::message();
            }
        }
        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }
    }

    /** @return array{0: ?string, 1: ?string, 2: list<string>} */
    private function storeImages(array $files, ?string $cover, ?string $thumb, array $gallery): array
    {
        $new = [];
        try {
            if (!empty($files['cover'])) {
                $set = $this->images->storeSet($files['cover'], ['proposal', 'thumb']);
                $new[] = $set['proposal'];
                $new[] = $set['thumb'];
                [$cover, $thumb] = [$set['proposal'], $set['thumb']];
            }
            foreach ($files['gallery'] ?? [] as $file) {
                if (count($gallery) >= 4) {
                    break;
                }
                $path = $this->images->store($file, 'gallery');
                $new[] = $path;
                $gallery[] = $path;
            }
        } catch (UploadException $e) {
            foreach ($new as $n) {
                $this->images->delete($n);
            }
            throw new ValidationFailed(['images' => $e->getMessage()]);
        }
        return [$cover, $thumb, $gallery];
    }
}
