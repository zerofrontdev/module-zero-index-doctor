<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Zero\IndexDoctor\Model\Check\IndexerCheck;

/**
 * bin/magento zero:index:doctor --repair: what the doctor can fix itself, and only that.
 * - An index "working" for over two hours that holds no lock (a reindex that died) is marked invalid, like
 *   indexer:reset; one still holding the lock (still running) is left alone. Without indexer/use_application_lock
 *   nothing tells a dead reindex from a long live one ("updated" is written once, when it starts), so such an index
 *   is only reported, unless the operator forces it (--repair --force).
 * - Every invalid index is then rebuilt in Magento's own order, in this process; one that fails does not stop the
 *   others.
 * Settings (the database's, env.php) are never changed: those are the operator's call.
 */
class Repair
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly IndexerRegistry $registry,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly LockManagerInterface $locks,
        private readonly CollectionFactory $indexers
    ) {
    }

    /**
     * @param bool $force also release without the application lock (the operator checked nothing is running); never
     *                    releases an index whose lock is held
     * @return array{released: string[], kept: string[]} the indexes released, and the stuck-looking ones left alone
     */
    public function releaseStuck(bool $force = false): array
    {
        $connection = $this->resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('indexer_state'), ['indexer_id'])
                ->where('status = ?', StateInterface::STATUS_WORKING)
                ->where('updated < ?', gmdate('Y-m-d H:i:s', time() - IndexerCheck::STUCK_SECONDS))
        );
        $locking = $this->usesApplicationLock();
        $released = [];
        $kept = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if ($locking ? $this->locks->isLocked('INDEXER' . $id) : !$force) {
                $kept[] = $id;
                continue;
            }
            $this->registry->get($id)->getState()->setStatus(StateInterface::STATUS_INVALID)->save();
            $released[] = $id;
        }

        return ['released' => $released, 'kept' => $kept];
    }

    /**
     * Whether a running reindex holds Magento's lock ("INDEXER<id>"), which tells it apart from one that died.
     */
    public function usesApplicationLock(): bool
    {
        return (bool) $this->deploymentConfig->get('indexer/use_application_lock');
    }

    /**
     * @param callable(string, float, ?string): void $done told after each index (its id, seconds, the error if it
     *                                                    failed)
     * @return array{rebuilt: string[], failed: array<string, string>} the indexes rebuilt, and the failed ones with
     *                                                                  their error
     */
    public function rebuildInvalid(callable $done): array
    {
        $rebuilt = [];
        $failed = [];
        // The collection lists the indexers in Magento's order, dependencies first.
        foreach ($this->indexers->create()->getItems() as $indexer) {
            if (!$indexer->isInvalid()) {
                continue;
            }
            $id = (string) $indexer->getId();
            $start = microtime(true);
            try {
                $indexer->reindexAll();
            } catch (\Throwable $e) {
                $failed[$id] = $e->getMessage();
                $done($id, microtime(true) - $start, $e->getMessage());
                continue;
            }
            $done($id, microtime(true) - $start, null);
            $rebuilt[] = $id;
        }

        return ['rebuilt' => $rebuilt, 'failed' => $failed];
    }
}
