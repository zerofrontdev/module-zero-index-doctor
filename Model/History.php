<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * How long each reindex took (zero_index_doctor_run, written by Plugin\RecordReindex): a full rebuild and the
 * changelog runs apart, kept 90 days (Cron\Prune).
 */
class History
{
    public const KEEP_DAYS = 90;
    /** Full rebuilds a slowdown is judged against. */
    private const BASELINE = 5;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function record(string $indexerId, string $kind, float $seconds, bool $failed): void
    {
        $this->resource->getConnection()->insert($this->table(), [
            'indexer_id' => $indexerId,
            'kind' => $kind,
            'seconds' => round($seconds, 2),
            'failed' => (int) $failed,
            'started_at' => gmdate('Y-m-d H:i:s', (int) (time() - $seconds)),
        ]);
    }

    /**
     * Per indexer: its last full rebuild, the median of the ones before it, how many there were, its changelog runs.
     * Bounded: counts and sums come from the database, and only the last few full rebuilds of each indexer are read.
     *
     * @return array<string, array{last: float|null, last_at: string|null, median: float|null, runs: int, changes: int, changes_seconds: float}>
     */
    public function summary(): array
    {
        $connection = $this->resource->getConnection();
        $fullCounts = $connection->fetchPairs(
            $connection->select()->from($this->table(), ['indexer_id', 'runs' => new \Zend_Db_Expr('COUNT(*)')])
                ->where('failed = 0')->where('kind = ?', 'full')->group('indexer_id')
        );
        $recentFull = [];
        if ($fullCounts) {
            // One query: each indexer's newest rows, through its (indexer_id, kind, started_at) index.
            $parts = [];
            foreach (array_keys($fullCounts) as $id) {
                $parts[] = '(' . $connection->select()->from($this->table(), ['indexer_id', 'seconds', 'started_at'])
                    ->where('indexer_id = ?', (string) $id)->where('kind = ?', 'full')->where('failed = 0')
                    ->order(['started_at DESC', 'run_id DESC'])->limit(self::BASELINE + 1) . ')';
            }
            $recentFull = $connection->fetchAll(implode(' UNION ALL ', $parts));
        }
        $changes = $connection->fetchAll(
            $connection->select()->from($this->table(), [
                'indexer_id',
                'changes' => new \Zend_Db_Expr('COUNT(*)'),
                'changes_seconds' => new \Zend_Db_Expr('SUM(seconds)'),
            ])->where('failed = 0')->where('kind <> ?', 'full')->group('indexer_id')
        );

        return self::assemble($fullCounts, $recentFull, $changes);
    }

    /**
     * The summary from what the three queries read.
     *
     * @param array<string, int|string> $fullCounts indexer id => its full rebuilds
     * @param array<int, array{indexer_id: string, seconds: float|string, started_at: string}> $recentFull the last
     *        full rebuilds of each indexer, newest first within an indexer
     * @param array<int, array{indexer_id: string, changes: int|string, changes_seconds: float|string}> $changes
     * @return array<string, array{last: float|null, last_at: string|null, median: float|null, runs: int, changes: int, changes_seconds: float}>
     */
    public static function assemble(array $fullCounts, array $recentFull, array $changes): array
    {
        $full = [];
        $lastAt = [];
        foreach ($recentFull as $row) {
            $id = (string) $row['indexer_id'];
            $full[$id][] = (float) $row['seconds'];
            $lastAt[$id] ??= (string) $row['started_at'];
        }
        $changed = [];
        foreach ($changes as $row) {
            $changed[(string) $row['indexer_id']] = [(int) $row['changes'], (float) $row['changes_seconds']];
        }
        $summary = [];
        foreach (array_unique(array_merge(array_map('strval', array_keys($fullCounts)), array_keys($changed))) as $id) {
            $times = $full[$id] ?? [];
            $summary[$id] = [
                'last' => $times[0] ?? null,
                'last_at' => $lastAt[$id] ?? null,
                'median' => self::median(array_slice($times, 1, self::BASELINE)),
                'runs' => (int) ($fullCounts[$id] ?? 0),
                'changes' => $changed[$id][0] ?? 0,
                'changes_seconds' => round($changed[$id][1] ?? 0.0, 2),
            ];
        }
        ksort($summary);

        return $summary;
    }

    public function prune(): int
    {
        return $this->resource->getConnection()->delete(
            $this->table(),
            ['started_at < ?' => gmdate('Y-m-d H:i:s', time() - self::KEEP_DAYS * 86400)]
        );
    }

    /**
     * @param float[] $values
     */
    public static function median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);

        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    private function table(): string
    {
        return $this->resource->getTableName('zero_index_doctor_run');
    }
}
