<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Plugin;

use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Mview\ViewInterface;
use Magento\Indexer\Model\WorkingStateProvider;
use Psr\Log\LoggerInterface;
use Zero\IndexDoctor\Model\History;

/**
 * Times every full reindex (IndexerInterface::reindexAll, from the admin, the CLI or the cron) and the changelog runs
 * that took a second or more (ViewInterface::update runs every minute, mostly with nothing to do). Recording never
 * gets in the reindex's way: a failure to write is logged and forgotten. A full reindex of an index already "working"
 * is skipped by Magento (Indexer::reindexAll) and is not recorded as a 0 s run.
 */
class RecordReindex
{
    private const MIN_CHANGES_SECONDS = 1.0;

    public function __construct(
        private readonly History $history,
        private readonly LoggerInterface $logger,
        private readonly WorkingStateProvider $workingState
    ) {
    }

    public function aroundReindexAll(IndexerInterface $subject, callable $proceed): mixed
    {
        $id = (string) $subject->getId();
        if ($this->workingState->isWorking($id)) {
            return $proceed();
        }

        return $this->timed($id, 'full', 0.0, $proceed);
    }

    public function aroundUpdate(ViewInterface $subject, callable $proceed): mixed
    {
        return $this->timed((string) $subject->getId(), 'changes', self::MIN_CHANGES_SECONDS, $proceed);
    }

    private function timed(string $id, string $kind, float $atLeast, callable $proceed): mixed
    {
        $start = microtime(true);
        $failed = true;
        try {
            $result = $proceed();
            $failed = false;

            return $result;
        } finally {
            $seconds = microtime(true) - $start;
            if ($seconds >= $atLeast || $failed) {
                try {
                    $this->history->record($id, $kind, $seconds, $failed);
                } catch (\Throwable $e) {
                    $this->logger->warning('Zero_IndexDoctor: a reindex time was not recorded: ' . $e->getMessage());
                }
            }
        }
    }
}
