<?php

declare(strict_types=1);

namespace App\Modules\Admin;

use App\Core\Db\Connection;
use App\Core\Export\XlsxWriter;
use App\Core\Queue\DatabaseQueue;
use App\Core\Support\Ulid;
use App\Modules\Payments\PaymentService;
use App\Modules\Proposals\ProposalService;
use App\Modules\Wallet\WalletService;

/** Async CSV/XLSX exports (doc §85): queued, streamed in id batches, stored privately, downloaded with auth. */
final class ExportService
{
    public const TYPES = ['users' => 'کاربران', 'payments' => 'پرداخت‌ها', 'wallet' => 'تراکنش‌های Stars', 'proposals' => 'پیشنهادها'];
    public const DIR = BASE_PATH . '/storage/private/exports';

    public function __construct(private Connection $db, private DatabaseQueue $queue)
    {
    }

    public function request(int $userId, string $type, string $format): void
    {
        $pub = Ulid::generateBinary();
        $id = $this->db->insert(
            'INSERT INTO exports (public_id, user_id, type, format, status, created_at) VALUES (?, ?, ?, ?, ?, NOW(3))',
            [$pub, $userId, $type, $format, 'queued']
        );
        $this->queue->push('reports', ExportJob::class, ['export_id' => $id]);
    }

    public function run(int $exportId): void
    {
        $e = $this->db->first('SELECT * FROM exports WHERE id = ?', [$exportId]);
        if ($e === null || $e['status'] === 'done') {
            return;
        }
        if (!is_dir(self::DIR)) {
            mkdir(self::DIR, 0750, true);
        }
        $name = strtolower(Ulid::toString($e['public_id'])) . '.' . $e['format'];
        $path = self::DIR . '/' . $name;
        [$header, $sql, $map] = $this->definition((string) $e['type']);

        if ($e['format'] === 'xlsx') {
            $xlsx = new XlsxWriter($path);
            $write = static fn (array $row) => $xlsx->addRow($row);
        } else {
            $fh = fopen($path, 'wb');
            fwrite($fh, "\xEF\xBB\xBF"); // BOM: Excel opens UTF-8 Persian correctly
            $write = static fn (array $row) => fputcsv($fh, $row);
        }
        $write($header);
        $count = 0;
        $last = 0;
        while (true) {
            $rows = $this->db->select($sql . ' LIMIT 2000', [$last]);
            if ($rows === []) {
                break;
            }
            foreach ($rows as $r) {
                $write($map($r));
                $last = (int) $r['id'];
                $count++;
            }
        }
        isset($xlsx) ? $xlsx->close() : fclose($fh);
        $this->db->exec('UPDATE exports SET status = ?, file = ?, row_count = ?, finished_at = NOW(3) WHERE id = ?', ['done', $name, $count, $exportId]);
    }

    /** @return list<array<string, mixed>> */
    public function listFor(int $userId): array
    {
        $rows = $this->db->select('SELECT * FROM exports WHERE user_id = ? ORDER BY id DESC LIMIT 30', [$userId]);
        foreach ($rows as &$r) {
            $r['uid'] = strtolower(Ulid::toString($r['public_id']));
        }
        return $rows;
    }

    public function find(string $uid, int $userId): ?array
    {
        if (!Ulid::isValid($uid)) {
            return null;
        }
        return $this->db->first('SELECT * FROM exports WHERE public_id = ? AND user_id = ? AND status = ?', [Ulid::toBinary($uid), $userId, 'done']);
    }

    /** @return array{0: list<string>, 1: string, 2: callable} */
    private function definition(string $type): array
    {
        $status = [1 => 'فعال', 2 => 'محدود', 3 => 'معلق', 4 => 'مسدود', 9 => 'حذف‌شده'];
        return match ($type) {
            'users' => [
                ['شناسه', 'نام', 'نام خانوادگی', 'ایمیل', 'تلفن', 'نشانی صفحه', 'کشور', 'وضعیت', 'عضویت', 'آخرین فعالیت', 'موجودی Stars'],
                'SELECT u.id, u.first_name, u.last_name, u.email, u.phone_cc, u.phone, u.handle, c.code, u.status, u.created_at, u.last_active_at, COALESCE(w.balance, 0) AS balance
                 FROM users u JOIN countries c ON c.id = u.country_id LEFT JOIN wallets w ON w.user_id = u.id WHERE u.id > ? ORDER BY u.id',
                static fn (array $r): array => [(int) $r['id'], $r['first_name'], $r['last_name'], $r['email'], '+' . $r['phone_cc'] . $r['phone'], $r['handle'],
                    $r['code'], $status[(int) $r['status']] ?? '', $r['created_at'], $r['last_active_at'], (int) $r['balance']],
            ],
            'payments' => [
                ['شناسه', 'کاربر', 'ایمیل', 'مبلغ (تومان)', 'Stars', 'هدیه', 'وضعیت', 'درگاه', 'کد پیگیری', 'تاریخ'],
                'SELECT p.id, u.first_name, u.last_name, u.email, p.amount_minor, p.stars, p.bonus_stars, p.status, g.name, p.gateway_ref, p.created_at
                 FROM payments p JOIN users u ON u.id = p.user_id JOIN payment_gateways g ON g.id = p.gateway_id WHERE p.id > ? ORDER BY p.id',
                static fn (array $r): array => [(int) $r['id'], trim($r['first_name'] . ' ' . $r['last_name']), $r['email'], intdiv((int) $r['amount_minor'], 10),
                    (int) $r['stars'], (int) $r['bonus_stars'], PaymentService::LABELS[(int) $r['status']] ?? '', $r['name'], $r['gateway_ref'], $r['created_at']],
            ],
            'wallet' => [
                ['شناسه', 'کاربر', 'نوع', 'بابت', 'مقدار', 'مانده', 'تاریخ'],
                'SELECT id, user_id, type, reason, amount, balance_after, created_at FROM wallet_transactions WHERE id > ? ORDER BY id',
                static fn (array $r): array => [(int) $r['id'], (int) $r['user_id'], WalletService::TYPE_LABELS[(int) $r['type']] ?? '',
                    WalletService::REASON_LABELS[$r['reason']] ?? $r['reason'], (int) $r['amount'], (int) $r['balance_after'], $r['created_at']],
            ],
            'proposals' => [
                ['شناسه', 'عنوان', 'نوع', 'وضعیت', 'مالک', 'کشور', 'بازدید', 'ارسال', 'انتشار'],
                'SELECT p.id, p.title, p.type, p.status, u.first_name, u.last_name, c.code, p.view_count, p.send_count, p.published_at
                 FROM proposals p JOIN users u ON u.id = p.user_id JOIN countries c ON c.id = p.country_id WHERE p.id > ? AND p.deleted_at IS NULL ORDER BY p.id',
                static fn (array $r): array => [(int) $r['id'], $r['title'], ProposalService::TYPES[(int) $r['type']] ?? '', ProposalService::STATUS_LABELS[(int) $r['status']] ?? '',
                    trim($r['first_name'] . ' ' . $r['last_name']), $r['code'], (int) $r['view_count'], (int) $r['send_count'], $r['published_at']],
            ],
            default => throw new \InvalidArgumentException('Unknown export type'),
        };
    }
}
