<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Indexer\StateInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Indexer\Model\Indexer\Collection;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Magento\Indexer\Model\Indexer\State;
use PHPUnit\Framework\TestCase;
use Zero\IndexDoctor\Model\Repair;

/**
 * --repair: an index "working" for hours is released only when the lock says its reindex died, or when the operator
 * forces it without the lock (owner decision D16); one failing rebuild does not stop the others.
 */
class RepairTest extends TestCase
{
    /** @var string[] indexes whose status was written, in order */
    private array $released = [];

    public function testWithoutTheLockAStuckLookingIndexIsKept(): void
    {
        $registry = $this->createMock(IndexerRegistry::class);
        $registry->expects($this->never())->method('get');
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->never())->method('isLocked');

        $result = $this->repair(['catalogsearch_fulltext', 'catalog_product_price'], false, $locks, $registry)->releaseStuck();

        $this->assertSame(['released' => [], 'kept' => ['catalogsearch_fulltext', 'catalog_product_price']], $result);
    }

    public function testForceReleasesWithoutTheLock(): void
    {
        $result = $this->repair(['catalogsearch_fulltext', 'catalog_product_price'], false)->releaseStuck(true);

        $this->assertSame(['released' => ['catalogsearch_fulltext', 'catalog_product_price'], 'kept' => []], $result);
        $this->assertSame(['catalogsearch_fulltext', 'catalog_product_price'], $this->released);
    }

    public function testWithTheLockALockedIndexIsKeptAndADeadOneReleased(): void
    {
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('isLocked')->willReturnCallback(static fn (string $name) => $name === 'INDEXERcatalogsearch_fulltext');

        $result = $this->repair(['catalogsearch_fulltext', 'catalog_product_price'], true, $locks)->releaseStuck();

        $this->assertSame(['released' => ['catalog_product_price'], 'kept' => ['catalogsearch_fulltext']], $result);
        $this->assertSame(['catalog_product_price'], $this->released);
    }

    public function testForceNeverReleasesAnIndexWhoseLockIsHeld(): void
    {
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('isLocked')->willReturn(true);

        $result = $this->repair(['catalogsearch_fulltext'], true, $locks)->releaseStuck(true);

        $this->assertSame(['released' => [], 'kept' => ['catalogsearch_fulltext']], $result);
        $this->assertSame([], $this->released);
    }

    public function testOnlyRowsWorkingForOverTwoHoursAreAsked(): void
    {
        $wheres = [];
        $this->repair([], false, null, null, $wheres)->releaseStuck(true);

        $this->assertSame(['status = ?', StateInterface::STATUS_WORKING], $wheres[0]);
        $this->assertSame('updated < ?', $wheres[1][0]);
        $this->assertEqualsWithDelta(time() - 7200, strtotime($wheres[1][1] . ' UTC'), 5);
    }

    public function testOneFailingRebuildDoesNotStopTheOthers(): void
    {
        $search = $this->indexer('catalogsearch_fulltext', true, new \RuntimeException('OpenSearch is down'));
        $price = $this->indexer('catalog_product_price', true);
        $price->expects($this->once())->method('reindexAll');
        $fresh = $this->indexer('customer_grid', false);
        $fresh->expects($this->never())->method('reindexAll');
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$search, $fresh, $price]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        $repair = new Repair(
            $this->createStub(ResourceConnection::class),
            $this->createStub(IndexerRegistry::class),
            $this->createStub(DeploymentConfig::class),
            $this->createStub(LockManagerInterface::class),
            $factory
        );
        $told = [];

        $result = $repair->rebuildInvalid(static function (string $id, float $seconds, ?string $error) use (&$told): void {
            $told[] = [$id, $error];
        });

        $this->assertSame(['rebuilt' => ['catalog_product_price'], 'failed' => ['catalogsearch_fulltext' => 'OpenSearch is down']], $result);
        $this->assertSame([['catalogsearch_fulltext', 'OpenSearch is down'], ['catalog_product_price', null]], $told);
    }

    /**
     * @param string[] $stuck the ids the indexer_state query returns
     * @param array<int, array> $wheres filled with the where() calls of the query
     */
    private function repair(
        array $stuck,
        bool $locking,
        ?LockManagerInterface $locks = null,
        ?IndexerRegistry $registry = null,
        array &$wheres = []
    ): Repair {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($condition, $value = null) use ($select, &$wheres) {
            $wheres[] = [$condition, $value];

            return $select;
        });
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturn($stuck);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $path === 'indexer/use_application_lock' ? $locking : null
        );
        if ($registry === null) {
            $registry = $this->createStub(IndexerRegistry::class);
            $registry->method('get')->willReturnCallback(function (string $id) {
                $status = null;
                $state = $this->createStub(State::class);
                $state->method('setStatus')->willReturnCallback(static function ($value) use (&$status, $state) {
                    $status = $value;

                    return $state;
                });
                $state->method('save')->willReturnCallback(function () use (&$status, $id, $state) {
                    if ($status === StateInterface::STATUS_INVALID) {
                        $this->released[] = $id;
                    }

                    return $state;
                });
                $indexer = $this->createStub(IndexerInterface::class);
                $indexer->method('getState')->willReturn($state);

                return $indexer;
            });
        }

        return new Repair(
            $resource,
            $registry,
            $deploymentConfig,
            $locks ?? $this->createStub(LockManagerInterface::class),
            $this->createStub(CollectionFactory::class)
        );
    }

    private function indexer(string $id, bool $invalid, ?\Throwable $throws = null): IndexerInterface
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->method('isInvalid')->willReturn($invalid);
        if ($throws) {
            $indexer->expects($this->once())->method('reindexAll')->willThrowException($throws);
        }

        return $indexer;
    }
}
