<?php

declare(strict_types=1);

namespace App\Modules\Letters;

use App\Core\Db\Connection;
use App\Core\Queue\DatabaseQueue;
use App\Core\Settings\Settings;
use App\Core\Support\Str;
use App\Core\Support\Ulid;
use App\Modules\Notifications\NotificationService;
use App\Modules\Users\ValidationFailed;
use App\Modules\Wallet\InsufficientStars;
use App\Modules\Wallet\Pricing;
use App\Modules\Wallet\WalletService;

/**
 * Public letters (architecture doc §28–29):
 * quote (count + cost) → confirm (reserve Stars) → queue → 500-recipient batches → settle (release the unused part).
 * Never sent inside one HTTP request.
 */
final class CampaignService
{
    public const QUOTED = 1, DELIVERING = 3, COMPLETED = 4, FAILED = 5, CANCELED = 6;
    public const LABELS = [self::QUOTED => 'در انتظار تأیید', self::DELIVERING => 'در حال ارسال', self::COMPLETED => 'ارسال‌شده',
        self::FAILED => 'ناموفق', self::CANCELED => 'لغوشده'];
    public const BATCH = 500;

    /** What a group send delivers (= thread type of each delivered conversation) and the price action used. */
    public const KINDS = [
        LetterService::T_PUBLIC => ['label' => 'نامه عمومی', 'action' => 'public_letter', 'notify' => 'public_letter'],
        LetterService::T_PRIVATE => ['label' => 'نامه اختصاصی', 'action' => 'private_letter', 'notify' => 'letter'],
        LetterService::T_PROPOSAL => ['label' => 'پیشنهاد تجاری', 'action' => 'proposal_send', 'notify' => 'proposal'],
    ];

    public function __construct(
        private Connection $db,
        private WalletService $wallet,
        private Pricing $pricing,
        private Settings $settings,
        private DatabaseQueue $queue,
        private NotificationService $notifications,
    ) {
    }

    /**
     * @param array{country: ?int, language: ?int, category: ?int, handles: list<string>} $filter
     * @return array{total: int, domestic: int}
     */
    public function audience(array $sender, array $filter): array
    {
        [$where, $bind] = $this->where($sender, $filter);
        $row = $this->db->first(
            'SELECT COUNT(*) AS total, COALESCE(SUM(u.country_id = ?), 0) AS domestic
             FROM users u JOIN user_profiles p ON p.user_id = u.id WHERE ' . $where,
            [(int) $sender['country_id'], ...$bind]
        );
        return ['total' => (int) ($row['total'] ?? 0), 'domestic' => (int) ($row['domestic'] ?? 0)];
    }

    /** @return array{domestic: int, international: int} price per recipient for a kind */
    public function unitPrices(int $kind): array
    {
        $action = self::KINDS[$kind]['action'];
        return ['domestic' => $this->pricing->price($action, true), 'international' => $this->pricing->price($action, false)];
    }

    /**
     * Creates the quoted send; nothing is charged yet.
     * @param array{country: ?int, language: ?int, category: ?int, handles: list<string>} $filter
     * @param array<string, mixed>|null $proposal for kind = proposal
     * @return string public id
     */
    public function quote(array $sender, int $kind, string $subject, string $body, array $filter, ?array $proposal = null): string
    {
        if (!isset(self::KINDS[$kind])) {
            throw new ValidationFailed(['kind' => t('نوع ارسال را انتخاب کنید.')]);
        }
        if ($kind === LetterService::T_PROPOSAL) {
            if ($proposal === null) {
                throw new ValidationFailed(['proposal' => t('یکی از پیشنهادهای خود را انتخاب کنید.')]);
            }
            $filter['proposal_id'] = (int) $proposal['id'];
            $subject = (string) $proposal['title'];
            $body = $body !== '' ? $body : t('پیشنهاد «:title» را برای شما فرستادم.', ['title' => $proposal['title']]);
        }
        $aud = $this->audience($sender, $filter);
        $max = max(1, (int) $this->settings->get('letters.public_max_recipients', 10000));
        if ($aud['total'] === 0) {
            throw new ValidationFailed(['filter' => $kind === LetterService::T_PROPOSAL
                ? t('گیرنده‌ای پیدا نشد (یا این پیشنهاد قبلاً برای همه آن‌ها ارسال شده است).')
                : t('با این فیلترها هیچ گیرنده‌ای پیدا نشد.')]);
        }
        if ($aud['total'] > $max) {
            throw new ValidationFailed(['filter' => t('تعداد گیرندگان (:n) بیشتر از سقف :max نفر است. فیلتر را محدودتر کنید.', ['n' => fa_int($aud['total']), 'max' => fa_int($max)])]);
        }
        $prices = $this->unitPrices($kind);
        $cost = $aud['domestic'] * $prices['domestic'] + ($aud['total'] - $aud['domestic']) * $prices['international'];
        $filter['prices'] = $prices;

        $publicId = Ulid::generateBinary();
        $this->db->insert(
            'INSERT INTO letter_campaigns (public_id, sender_id, kind, proposal_id, subject, body, filter, recipient_count, domestic_count, total_cost, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(3), NOW(3))',
            [$publicId, $sender['id'], $kind, $filter['proposal_id'] ?? null, $subject, $body, json_encode($filter, JSON_UNESCAPED_UNICODE),
                $aud['total'], $aud['domestic'], $cost, self::QUOTED]
        );
        return strtolower(Ulid::toString($publicId));
    }

    /** Reserve Stars and queue delivery. @throws InsufficientStars|ValidationFailed */
    public function confirm(array $sender, array $campaign): void
    {
        if ((int) $campaign['status'] !== self::QUOTED) {
            return; // already confirmed: idempotent
        }
        $daily = max(1, (int) $this->settings->get('letters.public_daily_campaigns', 3));
        $today = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM letter_campaigns WHERE sender_id = ? AND status IN (?, ?) AND created_at > NOW(3) - INTERVAL 1 DAY',
            [$sender['id'], self::DELIVERING, self::COMPLETED]
        );
        if ($today >= $daily) {
            throw new ValidationFailed(['filter' => t('در ۲۴ ساعت حداکثر :n نامه عمومی می‌توانید بفرستید.', ['n' => fa_int($daily)])]);
        }
        $id = (int) $campaign['id'];
        $reason = self::KINDS[(int) $campaign['kind']]['action'] ?? 'public_letter';
        $this->wallet->reserve((int) $sender['id'], (int) $campaign['total_cost'], $reason, 'campaign', $id, 'campaign:' . $id,
            function () use ($id): void {
                $this->db->exec('UPDATE letter_campaigns SET status = ?, updated_at = NOW(3) WHERE id = ? AND status = ?', [self::DELIVERING, $id, self::QUOTED]);
                $this->queue->push('letters', DeliverCampaignBatch::class, ['campaign_id' => $id]);
            });
    }

    /** One batch. Returns true when more batches remain. Safe to re-run (whole batch is one transaction). */
    public function deliverBatch(int $campaignId): bool
    {
        $more = false;
        $finished = null;
        $this->db->transaction(function (Connection $db) use ($campaignId, &$more, &$finished): void {
            $c = $db->first('SELECT * FROM letter_campaigns WHERE id = ? FOR UPDATE', [$campaignId]);
            if ($c === null || (int) $c['status'] !== self::DELIVERING) {
                return;
            }
            $filter = json_decode((string) $c['filter'], true) ?: [];
            $kind = (int) ($c['kind'] ?? LetterService::T_PUBLIC);
            $sender = $db->first('SELECT id, country_id, first_name, last_name FROM users WHERE id = ?', [$c['sender_id']]);
            $remaining = (int) $c['recipient_count'] - (int) $c['delivered_count'];
            $limit = min(self::BATCH, max(0, $remaining));

            $recipients = [];
            if ($limit > 0) {
                [$where, $bind] = $this->where($sender, $filter);
                $recipients = $db->select(
                    'SELECT u.id, u.country_id FROM users u JOIN user_profiles p ON p.user_id = u.id
                     WHERE ' . $where . ' AND u.id > ? ORDER BY u.id LIMIT ' . $limit,
                    [...$bind, (int) $c['last_user_id']]
                );
            }

            if ($recipients !== []) {
                $prices = $filter['prices'] ?? ['domestic' => 0, 'international' => 0];
                $preview = Str::excerpt((string) $c['body'], 150);
                $threads = [];
                $ids = [];
                $bindT = [];
                foreach ($recipients as $r) {
                    $pub = Ulid::generateBinary();
                    $threads[(int) $r['id']] = $pub;
                    array_push($bindT, $pub, $kind, $c['subject'], $c['sender_id'], $campaignId, $c['proposal_id']);
                }
                $db->exec(
                    'INSERT INTO letter_threads (public_id, type, subject, created_by, campaign_id, proposal_id, message_count, last_message_at, created_at) VALUES '
                        . implode(',', array_fill(0, count($recipients), '(?, ?, ?, ?, ?, ?, 1, NOW(3), NOW(3))')),
                    $bindT
                );
                $pubs = array_values($threads);
                $map = [];
                foreach ($db->select('SELECT id, public_id FROM letter_threads WHERE public_id IN (' . implode(',', array_fill(0, count($pubs), '?')) . ')', $pubs) as $t) {
                    $map[$t['public_id']] = (int) $t['id'];
                }

                $bindM = $bindP = $bindD = [];
                $spent = 0;
                foreach ($recipients as $r) {
                    $rid = (int) $r['id'];
                    $tid = $map[$threads[$rid]];
                    $cost = (int) ($r['country_id'] == $sender['country_id'] ? $prices['domestic'] : $prices['international']);
                    $spent += $cost;
                    $ids[] = $rid;
                    array_push($bindM, $tid, $c['sender_id'], $campaignId);
                    array_push($bindP, $rid, $tid, $c['sender_id'], LetterService::INBOX, $kind, $c['subject'], $preview);
                    array_push($bindD, $campaignId, $rid, $tid, $cost);
                }
                $n = count($recipients);
                $db->exec('INSERT INTO letter_messages (thread_id, sender_id, campaign_id, created_at) VALUES ' . implode(',', array_fill(0, $n, '(?, ?, ?, NOW(3))')), $bindM);
                $db->exec('INSERT INTO thread_participants (user_id, thread_id, peer_id, folder, unread_count, thread_type, subject, preview, last_message_at) VALUES '
                    . implode(',', array_fill(0, $n, '(?, ?, ?, ?, 1, ?, ?, ?, NOW(3))')), $bindP);
                $db->exec('INSERT INTO campaign_deliveries (campaign_id, recipient_id, thread_id, cost) VALUES ' . implode(',', array_fill(0, $n, '(?, ?, ?, ?)')), $bindD);
                $in = implode(',', array_fill(0, $n, '?'));
                $db->exec("UPDATE user_counters SET unread_letters = unread_letters + 1 WHERE user_id IN ({$in})", $ids);
                if ($kind === LetterService::T_PROPOSAL && $c['proposal_id']) {
                    $bindS = [];
                    foreach ($recipients as $i => $r) {
                        array_push($bindS, $c['proposal_id'], $c['sender_id'], (int) $r['id'], $bindD[$i * 4 + 3]);
                    }
                    $db->exec('INSERT IGNORE INTO proposal_sends (proposal_id, sender_id, recipient_id, cost, created_at) VALUES '
                        . implode(',', array_fill(0, $n, '(?, ?, ?, ?, NOW(3))')), $bindS);
                    $db->exec('UPDATE proposals SET send_count = send_count + ? WHERE id = ?', [$n, $c['proposal_id']]);
                    $db->exec("UPDATE user_counters SET proposals = proposals + 1 WHERE user_id IN ({$in})", $ids);
                }
                $this->notifications->notify($ids, self::KINDS[$kind]['notify'] ?? 'public_letter', (int) $c['sender_id'],
                    $kind === LetterService::T_PROPOSAL ? '/proposals/received' : '/letters',
                    ['name' => trim($sender['first_name'] . ' ' . $sender['last_name']), 'subject' => $c['subject']]);
                $db->exec(
                    'UPDATE letter_campaigns SET delivered_count = delivered_count + ?, spent = spent + ?, last_user_id = ?, updated_at = NOW(3) WHERE id = ?',
                    [$n, $spent, max($ids), $campaignId]
                );
                $c['delivered_count'] = (int) $c['delivered_count'] + $n;
                $c['spent'] = (int) $c['spent'] + $spent;
                $more = $n === $limit && $c['delivered_count'] < (int) $c['recipient_count'];
            }

            if ($more) {
                $this->queue->push('letters', DeliverCampaignBatch::class, ['campaign_id' => $campaignId]);
                return;
            }
            $db->exec('UPDATE letter_campaigns SET status = ?, completed_at = NOW(3), updated_at = NOW(3) WHERE id = ?', [self::COMPLETED, $campaignId]);
            $db->exec('UPDATE user_counters SET letters_sent = letters_sent + 1 WHERE user_id = ?', [$c['sender_id']]);
            $finished = $c;
        });

        if ($finished !== null) {
            // Return the share of recipients who could not be reached (deactivated, opted out since the quote).
            $this->wallet->settle((int) $finished['sender_id'], (int) $finished['total_cost'], (int) $finished['spent'],
                'public_letter_release', 'campaign', (int) $finished['id'], 'campaign-settle:' . $finished['id']);
            $this->notifications->notify([(int) $finished['sender_id']], 'campaign_done', null, '/letters/public/' . strtolower(Ulid::toString($finished['public_id'])),
                ['subject' => $finished['subject'], 'n' => (int) $finished['delivered_count']]);
        }
        return $more;
    }

    /** @return array<string, mixed>|null */
    public function find(string $uid, int $senderId): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        $c = $this->db->first('SELECT * FROM letter_campaigns WHERE public_id = ? AND sender_id = ?', [Ulid::toBinary($uid), $senderId]);
        if ($c !== null) {
            $c['id'] = (int) $c['id'];
            $c['uid'] = strtolower($uid);
            $c['filter'] = json_decode((string) $c['filter'], true) ?: [];
        }
        return $c;
    }

    /** @return list<array<string, mixed>> */
    public function listForSender(int $senderId): array
    {
        $rows = $this->db->select(
            'SELECT public_id, kind, subject, recipient_count, delivered_count, total_cost, spent, status, created_at
             FROM letter_campaigns WHERE sender_id = ? AND status <> ? ORDER BY id DESC LIMIT 50',
            [$senderId, self::QUOTED]
        );
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        return $rows;
    }

    /** @return array{0: string, 1: list<mixed>} */
    private function where(array $sender, array $filter): array
    {
        // Public letters reach every active trader: receiving them is no longer optional.
        // Members who blocked the sender, or whom the sender blocked, are skipped.
        $where = ['u.status = 1', 'u.deleted_at IS NULL', 'u.id <> ?', \App\Modules\Trust\TrustService::notBlockedSql()];
        $bind = [(int) $sender['id'], (int) $sender['id'], (int) $sender['id']];
        if (!empty($filter['country'])) {
            $where[] = 'u.country_id = ?';
            $bind[] = (int) $filter['country'];
        }
        if (!empty($filter['language'])) {
            $where[] = 'u.language_id = ?';
            $bind[] = (int) $filter['language'];
        }
        if (!empty($filter['category'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_interests ui WHERE ui.user_id = u.id AND ui.category_id = ?)';
            $bind[] = (int) $filter['category'];
        }
        if (!empty($filter['proposal_id'])) {
            $where[] = 'NOT EXISTS (SELECT 1 FROM proposal_sends ps WHERE ps.proposal_id = ? AND ps.recipient_id = u.id)';
            $bind[] = (int) $filter['proposal_id'];
        }
        if (!empty($filter['handles'])) {
            $handles = array_slice(array_values($filter['handles']), 0, 200);
            $where[] = 'u.handle IN (' . implode(',', array_fill(0, count($handles), '?')) . ')';
            array_push($bind, ...$handles);
        }
        return [implode(' AND ', $where), $bind];
    }
}
