<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model\Check;

use Zero\IndexDoctor\Model\CheckInterface;
use Zero\IndexDoctor\Model\Database;
use Zero\IndexDoctor\Model\Finding;

/**
 * The database server: its version, the two MariaDB optimizer settings that made a category reindex take hours, the
 * InnoDB buffer pool against the store's size, the in-memory temporary tables the indexers lean on.
 *
 * Found on Zero's 100 000-product stress test (2026-10-28, MariaDB 10.5): catalog_category_product took 3.5 h with
 * the defaults and 12 s with in_predicate_conversion_threshold=0 and optimizer_use_condition_selectivity=1. On
 * MariaDB 11.x (a new cost model) it was not measured, so there it is advice to try, not a warning.
 */
class DatabaseCheck implements CheckInterface
{
    private const MB = 1048576;

    public function __construct(private readonly Database $database)
    {
    }

    public function run(): array
    {
        $version = $this->database->version();
        $mariadb = stripos($version, 'mariadb') !== false;
        preg_match('/^(\d+)\.(\d+)/', $version, $m);
        $major = (int) ($m[1] ?? 0);
        $minor = (int) ($m[2] ?? 0);

        $findings = [$this->versionFinding($version, $mariadb, $major, $minor)];
        if ($mariadb) {
            $findings[] = $this->optimizerFinding($major);
        }
        $findings[] = $this->bufferPoolFinding();
        $findings[] = $this->tempTablesFinding();

        return $findings;
    }

    private function versionFinding(string $version, bool $mariadb, int $major, int $minor): Finding
    {
        $title = (string) __('Database server');
        if ($mariadb && ($major < 11 || ($major === 11 && $minor < 4))) {
            return new Finding(
                Finding::WARN,
                $title,
                (string) __('MariaDB %1: Magento 2.4.8 and 2.4.9 support MariaDB 11.4 and later.', $version),
                (string) __('Plan a move to MariaDB 11.4 LTS or later (11.8 runs Zero\'s demo).')
            );
        }
        if (!$mariadb && ($major < 8 || ($major === 8 && $minor < 4))) {
            return new Finding(
                Finding::WARN,
                $title,
                (string) __('MySQL %1: Magento 2.4.8 and 2.4.9 support MySQL 8.4.', $version),
                (string) __('Plan a move to MySQL 8.4 LTS.')
            );
        }

        // "11.8.9-MariaDB" reads "MariaDB 11.8.9".
        return new Finding(Finding::OK, $title, ($mariadb ? 'MariaDB ' : 'MySQL ') . preg_replace('/-MariaDB.*$/i', '', $version));
    }

    private function optimizerFinding(int $major): Finding
    {
        $title = (string) __('MariaDB optimizer settings');
        $threshold = $this->database->variable('in_predicate_conversion_threshold');
        $selectivity = $this->database->variable('optimizer_use_condition_selectivity');
        $off = [];
        if ($threshold !== null && $threshold !== '0') {
            $off[] = 'in_predicate_conversion_threshold=' . $threshold;
        }
        if ($selectivity !== null && (int) $selectivity > 1) {
            $off[] = 'optimizer_use_condition_selectivity=' . $selectivity;
        }
        if (!$off) {
            return new Finding(Finding::OK, $title, (string) __('in_predicate_conversion_threshold=0 and optimizer_use_condition_selectivity=1.'));
        }
        $fix = (string) __('In the server\'s my.cnf, [mysqld]: in_predicate_conversion_threshold=0 and optimizer_use_condition_selectivity=1 (or SET GLOBAL for both, which needs no restart).');
        if ($major < 11) {
            return new Finding(
                Finding::WARN,
                $title,
                (string) __('%1. With these defaults MariaDB 10.x turned the category-products reindex of a 100 000-product catalogue into 3.5 hours; with both changed it took 12 seconds.', implode(', ', $off)),
                $fix
            );
        }

        return new Finding(
            Finding::INFO,
            $title,
            (string) __('%1 (the defaults). On MariaDB 10.x they made the category-products reindex of a large catalogue take hours; MariaDB 11 plans queries differently and was not measured. If catalog_category_product reindexes slowly, try the change on a copy first.', implode(', ', $off)),
            $fix
        );
    }

    private function bufferPoolFinding(): Finding
    {
        $title = (string) __('InnoDB buffer pool');
        $pool = (int) $this->database->variable('innodb_buffer_pool_size');
        $size = $this->database->size();
        $detail = (string) __('%1 MB for a %2 MB database.', round($pool / self::MB), round($size / self::MB));
        $fix = (string) __('innodb_buffer_pool_size about the size of the database and its growth, within what the server\'s memory allows (often half to three quarters of it on a database-only server).');
        if ($pool > 0 && $pool <= 128 * self::MB && $size > 256 * self::MB) {
            return new Finding(Finding::FAIL, $title, $detail . ' ' . __('That is the server\'s default: the indexers read from disk all the time.'), $fix);
        }
        if ($pool > 0 && $pool < $size) {
            return new Finding(Finding::WARN, $title, $detail . ' ' . __('The database does not fit in memory.'), $fix);
        }

        return new Finding(Finding::OK, $title, $detail);
    }

    private function tempTablesFinding(): Finding
    {
        $title = (string) __('In-memory temporary tables');
        $tmp = (int) $this->database->variable('tmp_table_size');
        $heap = (int) $this->database->variable('max_heap_table_size');
        $least = min($tmp ?: PHP_INT_MAX, $heap ?: PHP_INT_MAX);
        $detail = (string) __('tmp_table_size %1 MB, max_heap_table_size %2 MB.', round($tmp / self::MB), round($heap / self::MB));
        if ($least < 64 * self::MB) {
            return new Finding(
                Finding::WARN,
                $title,
                $detail . ' ' . __('The smaller of the two decides: an indexer\'s temporary table larger than that goes to disk.'),
                (string) __('Both at 64–256 MB (the same value), depending on the server\'s memory.')
            );
        }

        return new Finding(Finding::OK, $title, $detail);
    }
}
