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
 *   indexer:reset; one still holding the lock (still running) is left alone.
 * - Every invalid index is then rebuilt in Magento's own order, in this process.
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
     * @return string[] the indexes released
     */
    public function releaseStuck(): array
    {
        $connection = $this->resource->getConnection();
        $ids = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('indexer_state'), ['indexer_id'])
                ->where('status = ?', StateInterface::STATUS_WORKING)
                ->where('updated < ?', gmdate('Y-m-d H:i:s', time() - IndexerCheck::STUCK_SECONDS))
        );
        $locking = (bool) $this->deploymentConfig->get('indexer/use_application_lock');
        $released = [];
        foreach ($ids as $id) {
            if ($locking && $this->locks->isLocked('INDEXER' . $id)) {
                continue;
            }
            $this->registry->get((string) $id)->getState()->setStatus(StateInterface::STATUS_INVALID)->save();
            $released[] = (string) $id;
        }

        return $released;
    }

    /**
     * @param callable(string, float): void $done told after each index (its id, seconds)
     * @return string[] the indexes rebuilt
     */
    public function rebuildInvalid(callable $done): array
    {
        $rebuilt = [];
        // The collection lists the indexers in Magento's order, dependencies first.
        foreach ($this->indexers->create()->getItems() as $indexer) {
            if (!$indexer->isInvalid()) {
                continue;
            }
            $start = microtime(true);
            $indexer->reindexAll();
            $done((string) $indexer->getId(), microtime(true) - $start);
            $rebuilt[] = (string) $indexer->getId();
        }

        return $rebuilt;
    }
}
