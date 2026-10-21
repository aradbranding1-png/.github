<?php

declare(strict_types=1);

namespace App\Core\Update;

use App\Core\Db\Connection;
use PDO;

/**
 * Logical database backup written in pure PHP (works on hosts without mysqldump / SSH).
 * Output: gzip SQL file with CREATE TABLE + batched INSERTs, rows streamed in primary-key order.
 */
final class DatabaseBackup
{
    public function __construct(private Connection $db)
    {
    }

    public function dump(string $file): int
    {
        $gz = gzopen($file, 'wb6');
        if ($gz === false) {
            throw new \RuntimeException('Cannot write backup file');
        }
        $rows = 0;
        gzwrite($gz, "-- سامانه توسعه تجارت · database backup · " . gmdate('c') . "\nSET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        foreach ($this->db->select("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME") as $tbl) {
            $table = (string) $tbl['t'];
            $create = $this->db->first("SHOW CREATE TABLE `{$table}`");
            gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n" . array_values($create)[1] . ";\n\n");

            $pk = array_column($this->db->select(
                "SELECT COLUMN_NAME AS c FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
                [$table]
            ), 'c');
            $order = $pk ? ' ORDER BY ' . implode(', ', array_map(static fn ($c) => "`{$c}`", $pk)) : '';
            $binary = [];
            foreach ($this->db->select(
                'SELECT COLUMN_NAME AS c, DATA_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$table]
            ) as $col) {
                $binary[$col['c']] = in_array(strtolower((string) $col['t']), ['binary', 'varbinary', 'blob', 'tinyblob', 'mediumblob', 'longblob'], true);
            }
            $offset = 0;
            while (true) {
                // Backups run rarely and offline-ish (maintenance mode), so OFFSET batching is acceptable here.
                $batch = $this->db->select("SELECT * FROM `{$table}`{$order} LIMIT 1000 OFFSET {$offset}");
                if ($batch === []) {
                    break;
                }
                $values = [];
                foreach ($batch as $row) {
                    $out = [];
                    foreach ($row as $col => $v) {
                        $out[] = $this->quote($v, $binary[$col] ?? false);
                    }
                    $values[] = '(' . implode(',', $out) . ')';
                }
                $cols = implode(',', array_map(static fn ($c) => "`{$c}`", array_keys($batch[0])));
                gzwrite($gz, "INSERT INTO `{$table}` ({$cols}) VALUES\n" . implode(",\n", $values) . ";\n");
                $rows += count($batch);
                $offset += 1000;
            }
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
        return $rows;
    }

    /** Hex literals are binary-safe; text/JSON/date columns get a utf8mb4 introducer. */
    private function quote(mixed $v, bool $binary): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        $s = (string) $v;
        if ($s === '') {
            return "''";
        }
        return ($binary ? '' : '_utf8mb4 ') . '0x' . bin2hex($s);
    }
}
