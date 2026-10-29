<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model\Check;

use Zero\IndexDoctor\Model\CheckInterface;
use Zero\IndexDoctor\Model\Finding;
use Zero\IndexDoctor\Model\History;

/**
 * A full rebuild that took much longer than the ones before it: something changed (the catalogue, the database, its
 * settings) and is worth a look before it gets worse.
 */
class HistoryCheck implements CheckInterface
{
    /** Twice the usual, and at least a minute (a few seconds' jitter is no news). */
    public const FACTOR = 2.0;
    public const MIN_SECONDS = 60.0;

    public function __construct(private readonly History $history)
    {
    }

    public function run(): array
    {
        $title = (string) __('Reindex times');
        $summary = $this->history->summary();
        if (!$summary) {
            return [new Finding(Finding::INFO, $title, (string) __('No reindex recorded yet: times are kept from the next one on.'))];
        }
        $slower = [];
        foreach ($summary as $id => $row) {
            if ($row['last'] !== null && $row['median'] !== null
                && $row['last'] >= self::MIN_SECONDS && $row['last'] > self::FACTOR * $row['median']) {
                $slower[] = sprintf('%s %ss (usually %ss)', $id, $row['last'], round($row['median'], 1));
            }
        }
        if ($slower) {
            return [new Finding(
                Finding::WARN,
                $title,
                (string) __('Slower than usual: %1.', implode(', ', $slower)),
                (string) __('Compare with the other checks here: a new setting, a grown catalogue, a full disk, another process on the database.')
            )];
        }

        $timed = count($summary) === 1 ? __('1 index timed') : __('%1 indexes timed', count($summary));

        return [new Finding(Finding::OK, $title, (string) __('%1; none slower than usual.', $timed))];
    }
}
