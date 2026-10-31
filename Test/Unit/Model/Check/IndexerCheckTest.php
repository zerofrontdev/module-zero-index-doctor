<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Model\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use PHPUnit\Framework\TestCase;
use Zero\IndexDoctor\Model\Check\IndexerCheck;
use Zero\IndexDoctor\Model\Finding;

/**
 * An index "working" for over two hours: without indexer/use_application_lock a long live reindex looks the same as
 * a dead one, so it is a warning to check (D16); with the lock, one whose reindex holds no lock has died.
 */
class IndexerCheckTest extends TestCase
{
    public function testWithoutTheLockItIsAWarning(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->never())->method('isLocked');

        $finding = $this->stuck(false, $locks);

        $this->assertSame(Finding::WARN, $finding->status);
        $this->assertSame('Reindexes possibly stuck "working"', $finding->title);
        $this->assertStringContainsString('1 "working" for over two hours: catalogsearch_fulltext.', $finding->detail);
        $this->assertSame(
            'Check that no indexer:reindex is running, then bin/magento zero:index:doctor --repair --force; better, turn on indexer/use_application_lock.',
            $finding->fix
        );
    }

    public function testWithTheLockAnUnlockedOneFails(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->expects($this->once())->method('isLocked')->with('INDEXERcatalogsearch_fulltext')->willReturn(false);

        $finding = $this->stuck(true, $locks);

        $this->assertSame(Finding::FAIL, $finding->status);
        $this->assertSame('Reindexes stuck "working"', $finding->title);
        $this->assertStringContainsString('catalogsearch_fulltext', $finding->detail);
        $this->assertStringContainsString('--repair', $finding->fix);
        $this->assertStringNotContainsString('--force', $finding->fix);
    }

    public function testWithTheLockALockedOneIsOk(): void
    {
        $locks = $this->createStub(LockManagerInterface::class);
        $locks->method('isLocked')->willReturn(true);

        $finding = $this->stuck(true, $locks);

        $this->assertSame(Finding::OK, $finding->status);
        $this->assertSame('Reindexes stuck "working"', $finding->title);
    }

    public function testNothingWorkingForLongIsOkWithoutTheLockToo(): void
    {
        $finding = $this->stuck(false, $this->createStub(LockManagerInterface::class), []);

        $this->assertSame(Finding::OK, $finding->status);
    }

    /**
     * IndexerCheck::stuck() on its own, the indexer_state query answering $rows.
     *
     * @param array<string, string> $rows indexer id => updated
     */
    private function stuck(bool $locking, LockManagerInterface $locks, ?array $rows = null): Finding
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchPairs')->willReturn($rows ?? ['catalogsearch_fulltext' => '2026-10-31 01:00:00']);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnCallback(
            static fn (string $path) => $path === 'indexer/use_application_lock' ? $locking : null
        );
        $check = new IndexerCheck(
            $this->createStub(CollectionFactory::class),
            $resource,
            $deploymentConfig,
            $this->createStub(ScopeConfigInterface::class),
            $locks
        );

        return (new \ReflectionMethod($check, 'stuck'))->invoke($check);
    }
}
