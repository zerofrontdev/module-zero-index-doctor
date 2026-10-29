<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Cron;

use Zero\IndexDoctor\Model\History;

/**
 * Daily: reindex times older than History::KEEP_DAYS go.
 */
class Prune
{
    public function __construct(private readonly History $history)
    {
    }

    public function execute(): void
    {
        $this->history->prune();
    }
}
