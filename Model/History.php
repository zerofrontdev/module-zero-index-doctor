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
     *
     * @return array<string, array{last: float|null, last_at: string|null, median: float|null, runs: int, changes: int, changes_seconds: float}>
     */
    public function summary(): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()->from($this->table(), ['indexer_id', 'kind', 'seconds', 'started_at'])
                ->where('failed = 0')->order(['indexer_id', 'started_at DESC'])
        );
        $out = [];
        foreach ($rows as $row) {
            $id = (string) $row['indexer_id'];
            $out[$id] ??= ['full' => [], 'last_at' => null, 'changes' => 0, 'changes_seconds' => 0.0];
            if ($row['kind'] === 'full') {
                $out[$id]['full'][] = (float) $row['seconds'];
                $out[$id]['last_at'] ??= (string) $row['started_at'];
            } else {
                $out[$id]['changes']++;
                $out[$id]['changes_seconds'] += (float) $row['seconds'];
            }
        }
        $summary = [];
        foreach ($out as $id => $data) {
            $full = $data['full'];
            $summary[$id] = [
                'last' => $full[0] ?? null,
                'last_at' => $data['last_at'],
                'median' => self::median(array_slice($full, 1, self::BASELINE)),
                'runs' => count($full),
                'changes' => $data['changes'],
                'changes_seconds' => round($data['changes_seconds'], 2),
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
