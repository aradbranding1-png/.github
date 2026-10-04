<?php

declare(strict_types=1);

namespace App\Modules\Letters;

use App\Core\Db\Connection;
use App\Core\Events\Outbox;
use App\Core\Support\Str;
use App\Core\Support\Ulid;
use App\Modules\Trust\TrustService;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;

/**
 * Letters. The inbox is the `thread_participants` read model (one row per user per thread),
 * so mailbox speed never depends on the size of `letter_messages`.
 * Private letter: one atomic transaction (charge + thread + message + participants + counters).
 * Replies are free. A first reply from the other side creates a business connection.
 */
final class LetterService
{
    public const T_PRIVATE = 1, T_PUBLIC = 2, T_PROPOSAL = 3, T_OFFICIAL = 4;
    public const INBOX = 1, SENT = 2, ARCHIVE = 3;
    public const TYPE_LABELS = [self::T_PRIVATE => 'اختصاصی', self::T_PUBLIC => 'عمومی', self::T_PROPOSAL => 'پیشنهاد', self::T_OFFICIAL => 'آراد برندینگ'];
    /** Category tabs of the mailbox (horizontal). */
    public const CATEGORIES = ['all' => null, 'private' => self::T_PRIVATE, 'public' => self::T_PUBLIC, 'proposal' => self::T_PROPOSAL, 'official' => self::T_OFFICIAL];

    /** Preview stored for a message hidden by moderation (TrustAdminController); translated when listed. */
    public const HIDDEN_PREVIEW = 'این پیام توسط تیم بررسی پنهان شد.';

    public function __construct(
        private Connection $db,
        private WalletService $wallet,
        private Pricing $pricing,
        private Outbox $outbox,
        private TrustService $trust,
    ) {
    }

    public function privatePrice(array $sender, array $recipient): int
    {
        return $this->pricing->price('private_letter', (int) $sender['country_id'] === (int) $recipient['country_id']);
    }

    /**
     * @throws InsufficientStars|ValidationFailed
     * @return string thread public id
     */
    public function sendPrivate(array $sender, array $recipient, string $subject, string $body, string $token): string
    {
        if ((int) $sender['id'] === (int) $recipient['id']) {
            throw new ValidationFailed(['to' => t('نمی‌توانید برای خودتان نامه بفرستید.')]);
        }
        $this->trust->assertCanAct($sender);
        $this->trust->assertCanMessage((int) $sender['id'], (int) $recipient['id']);
        $uid = '';
        $this->wallet->spend((int) $sender['id'], $this->privatePrice($sender, $recipient), 'private_letter', 'letter', null,
            'private_letter:' . $sender['id'] . ':' . $token,
            function () use ($sender, $recipient, $subject, $body, &$uid): void {
                $uid = $this->openThread(self::T_PRIVATE, (int) $sender['id'], (int) $recipient['id'], $subject, $body, null);
                $this->db->exec('UPDATE user_counters SET letters_sent = letters_sent + 1 WHERE user_id = ?', [$sender['id']]);
            });
        return $uid;
    }

    /**
     * Thread + first message + both participant rows + recipient counter + outbox event.
     * Called inside the caller's transaction (private letter, proposal send).
     */
    public function openThread(int $type, int $senderId, int $recipientId, string $subject, string $body, ?int $proposalId): string
    {
        $publicId = Ulid::generateBinary();
        $threadId = $this->db->insert(
            'INSERT INTO letter_threads (public_id, type, subject, created_by, proposal_id, message_count, last_message_at, created_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(3), NOW(3))',
            [$publicId, $type, $subject, $senderId, $proposalId]
        );
        $this->db->insert(
            'INSERT INTO letter_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, NOW(3))',
            [$threadId, $senderId, $body]
        );
        $preview = Str::excerpt($body, 150);
        $this->db->exec(
            'INSERT INTO thread_participants (user_id, thread_id, peer_id, folder, unread_count, thread_type, subject, preview, last_message_at)
             VALUES (?, ?, ?, ?, 0, ?, ?, ?, NOW(3)), (?, ?, ?, ?, 1, ?, ?, ?, NOW(3))',
            [$senderId, $threadId, $recipientId, self::SENT, $type, $subject, $preview,
                $recipientId, $threadId, $senderId, self::INBOX, $type, $subject, $preview]
        );
        $this->db->exec('UPDATE user_counters SET unread_letters = unread_letters + 1 WHERE user_id = ?', [$recipientId]);
        $uid = strtolower(Ulid::toString($publicId));
        if ($type !== self::T_PROPOSAL) {
            $this->outbox->record('letter_sent', $senderId, $recipientId, $threadId,
                $type === self::T_OFFICIAL ? ['thread' => $uid, 'official' => 1] : ['thread' => $uid]);
        }
        return $uid;
    }

    /** Reply (free). First reply from the non-creator creates the business connection. */
    public function reply(array $user, array $thread, string $body): void
    {
        $userId = (int) $user['id'];
        $threadId = (int) $thread['id'];
        $this->trust->assertCanAct($user);
        // A block on either side closes the conversation (official threads stay open).
        if ((int) $thread['type'] !== self::T_OFFICIAL && $this->trust->blockedBetween($userId, (int) $thread['peer_id'])) {
            throw new ValidationFailed(['body' => t('این گفتگو به دلیل مسدودی بسته شده است.')]);
        }
        $this->db->transaction(function (Connection $db) use ($userId, $threadId, $thread, $body): void {
            $me = $db->first('SELECT folder FROM thread_participants WHERE user_id = ? AND thread_id = ? FOR UPDATE', [$userId, $threadId]);
            if ($me === null) {
                throw new ValidationFailed(['body' => t('دسترسی به این گفتگو ندارید.')]);
            }
            $db->insert('INSERT INTO letter_messages (thread_id, sender_id, body, created_at) VALUES (?, ?, ?, NOW(3))', [$threadId, $userId, $body]);
            $db->exec('UPDATE letter_threads SET message_count = message_count + 1, last_message_at = NOW(3) WHERE id = ?', [$threadId]);
            $preview = Str::excerpt($body, 150);

            // Public letter: the campaign sender joins the conversation on the first reply.
            $creator = (int) $thread['created_by'];
            if ($creator !== $userId) {
                $db->exec(
                    'INSERT IGNORE INTO thread_participants (user_id, thread_id, peer_id, folder, unread_count, thread_type, subject, preview, last_message_at)
                     VALUES (?, ?, ?, ?, 0, ?, ?, ?, NOW(3))',
                    [$creator, $threadId, $userId, self::INBOX, $thread['type'], $thread['subject'], $preview]
                );
            }
            $db->exec(
                'UPDATE thread_participants SET preview = ?, last_message_at = NOW(3) WHERE user_id = ? AND thread_id = ?',
                [$preview, $userId, $threadId]
            );
            $peers = array_map('intval', array_column($db->select(
                'SELECT user_id FROM thread_participants WHERE thread_id = ? AND user_id <> ?',
                [$threadId, $userId]
            ), 'user_id'));
            if ($peers === []) {
                return;
            }
            $marks = implode(',', array_fill(0, count($peers), '?'));
            $db->exec(
                "UPDATE thread_participants SET unread_count = unread_count + 1, folder = ?, preview = ?, last_message_at = NOW(3)
                 WHERE thread_id = ? AND user_id IN ({$marks})",
                [self::INBOX, $preview, $threadId, ...$peers]
            );
            $db->exec("UPDATE user_counters SET unread_letters = unread_letters + 1, replies = replies + 1 WHERE user_id IN ({$marks})", $peers);

            $uid = strtolower(Ulid::toString($thread['public_id']));
            $this->outbox->record('letter_replied', $userId, $peers[0], $threadId, ['thread' => $uid]);

            if ($creator !== $userId) {
                $this->connect($userId, $creator, (int) $thread['type'], $threadId);
            }
        });
    }

    /** Business connection (stored both ways for fast keyset listing). */
    public function connect(int $a, int $b, int $source, ?int $threadId): void
    {
        $added = $this->db->exec(
            'INSERT IGNORE INTO user_connections (user_id, peer_id, source, thread_id, created_at) VALUES (?, ?, ?, ?, NOW(3)), (?, ?, ?, ?, NOW(3))',
            [$a, $b, $source, $threadId, $b, $a, $source, $threadId]
        );
        if ($added > 0) {
            $this->db->exec('UPDATE user_counters SET connections = connections + 1 WHERE user_id IN (?, ?)', [$a, $b]);
            $this->outbox->record('connection_created', $a, $b, $threadId);
        }
    }

    public function markRead(int $userId, int $threadId): void
    {
        $this->db->transaction(function (Connection $db) use ($userId, $threadId): void {
            $n = (int) $db->scalar('SELECT unread_count FROM thread_participants WHERE user_id = ? AND thread_id = ? FOR UPDATE', [$userId, $threadId]);
            if ($n > 0) {
                $db->exec('UPDATE thread_participants SET unread_count = 0 WHERE user_id = ? AND thread_id = ?', [$userId, $threadId]);
                $db->exec('UPDATE user_counters SET unread_letters = IF(unread_letters > ?, unread_letters - ?, 0) WHERE user_id = ?', [$n, $n, $userId]);
            }
        });
    }

    public function setFolder(int $userId, int $threadId, int $folder): void
    {
        $this->db->exec('UPDATE thread_participants SET folder = ? WHERE user_id = ? AND thread_id = ?', [$folder, $userId, $threadId]);
    }

    /** @return array{rows: list<array<string, mixed>>, next: ?string} */
    public function box(int $userId, int $folder, ?string $cursor, ?int $type = null): array
    {
        $bind = [$userId, $folder];
        $cond = '';
        if ($type !== null) {
            $cond .= ' AND tp.thread_type = ?';
            $bind[] = $type;
        }
        if ($cursor !== null && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d+)?)_(\d+)$/', $cursor, $m)) {
            $cond .= ' AND (tp.last_message_at < ? OR (tp.last_message_at = ? AND tp.thread_id < ?))';
            array_push($bind, $m[1], $m[1], (int) $m[2]);
        }
        $rows = $this->db->select(
            'SELECT tp.thread_id, tp.peer_id, tp.unread_count, tp.thread_type, tp.subject, tp.preview, tp.last_message_at, t.public_id, t.created_by
             FROM thread_participants tp JOIN letter_threads t ON t.id = tp.thread_id
             WHERE tp.user_id = ? AND tp.folder = ?' . $cond . '
             ORDER BY tp.last_message_at DESC, tp.thread_id DESC LIMIT 26',
            $bind
        );
        $next = null;
        if (count($rows) > 25) {
            array_pop($rows);
            $last = end($rows);
            $next = $last['last_message_at'] . '_' . $last['thread_id'];
        }
        $cards = $this->userCards(array_column($rows, 'peer_id'));
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
            $r['peer'] = $cards[(int) $r['peer_id']] ?? null;
        }
        return ['rows' => $rows, 'next' => $next];
    }

    /** @return array<string, mixed>|null thread visible to $userId */
    public function thread(string $uid, int $userId): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        $t = $this->db->first(
            'SELECT t.*, tp.folder, tp.peer_id FROM letter_threads t
             JOIN thread_participants tp ON tp.thread_id = t.id AND tp.user_id = ?
             WHERE t.public_id = ?',
            [$userId, Ulid::toBinary($uid)]
        );
        if ($t !== null) {
            $t['id'] = (int) $t['id'];
            $t['uid'] = strtolower($uid);
        }
        return $t;
    }

    /** Latest messages first from the DB, returned oldest-first for display. Partition-pruned by thread creation. */
    public function messages(array $thread, ?int $beforeId, int $limit = 30): array
    {
        $bind = [$thread['id'], $thread['created_at']];
        $cond = '';
        if ($beforeId !== null) {
            $cond = ' AND m.id < ?';
            $bind[] = $beforeId;
        }
        $rows = $this->db->select(
            'SELECT m.id, m.sender_id, IF(m.hidden_at IS NULL, COALESCE(m.body, c.body), NULL) AS body, m.hidden_at, m.created_at
             FROM letter_messages m LEFT JOIN letter_campaigns c ON c.id = m.campaign_id
             WHERE m.thread_id = ? AND m.created_at >= ?' . $cond . '
             ORDER BY m.created_at DESC, m.id DESC LIMIT ' . ($limit + 1),
            $bind
        );
        $more = count($rows) > $limit;
        if ($more) {
            array_pop($rows);
        }
        return ['rows' => array_reverse($rows), 'older' => $more ? (int) end($rows)['id'] : null];
    }

    /** @param list<int|string> $ids @return array<int, array<string, mixed>> */
    public function userCards(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->select(
            'SELECT u.id, u.first_name, u.last_name, u.handle, u.avatar_path, c.code AS country_code, p.company_name
             FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) as $u) {
            $out[(int) $u['id']] = $u;
        }
        return $out;
    }

    /** @return array<string, int> unread threads per category in the inbox (for tab badges) */
    public function unreadByType(int $userId): array
    {
        $out = [];
        foreach ($this->db->select(
            'SELECT thread_type, COUNT(*) AS n FROM thread_participants
             WHERE user_id = ? AND folder = ? AND unread_count > 0 GROUP BY thread_type',
            [$userId, self::INBOX]
        ) as $r) {
            $out[(int) $r['thread_type']] = (int) $r['n'];
        }
        return $out;
    }
}
