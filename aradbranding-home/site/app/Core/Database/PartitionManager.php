<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Db\Connection;

/**
 * Keeps monthly RANGE COLUMNS(created_at) partitions created ahead of time.
 * Splitting the empty `pmax` partition is instant, so running it daily is cheap.
 * Tables are listed in config/database.php ('partitioned'), not in the admin panel.
 */
final class PartitionManager
{
    /** @param list<string> $tables */
    public function __construct(private Connection $db, private array $tables, private int $monthsAhead = 3)
    {
    }

    /** @return list<string> created partition names */
    public function ensure(): array
    {
        $created = [];
        foreach ($this->tables as $table) {
            $existing = array_column($this->db->select(
                'SELECT PARTITION_NAME AS name FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL',
                [$table]
            ), 'name');
            if (!in_array('pmax', $existing, true)) {
                continue; // not partitioned (yet) — nothing to do
            }

            $month = new \DateTimeImmutable('first day of this month 00:00:00', new \DateTimeZone('UTC'));
            for ($i = 0; $i <= $this->monthsAhead; $i++) {
                $start = $month->modify("+{$i} month");
                $name = 'p' . $start->format('Y_m');
                if (in_array($name, $existing, true)) {
                    continue;
                }
                $boundary = $start->modify('+1 month')->format('Y-m-d');
                $this->db->statement(sprintf(
                    "ALTER TABLE `%s` REORGANIZE PARTITION pmax INTO (PARTITION %s VALUES LESS THAN ('%s'), PARTITION pmax VALUES LESS THAN (MAXVALUE))",
                    $table,
                    $name,
                    $boundary
                ));
                $existing[] = $name;
                $created[] = "{$table}.{$name}";
            }
        }
        return $created;
    }
}
