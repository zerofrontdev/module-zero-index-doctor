<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Plugin;

use Magento\Framework\Indexer\IndexerInterface;
use Magento\Indexer\Model\WorkingStateProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\IndexDoctor\Model\History;
use Zero\IndexDoctor\Plugin\RecordReindex;

/**
 * A full reindex is timed; one Magento skips because the index is already "working" is not recorded as a 0 s run.
 */
class RecordReindexTest extends TestCase
{
    public function testASkippedRunIsNotRecorded(): void
    {
        $history = $this->createMock(History::class);
        $history->expects($this->never())->method('record');
        $proceeded = 0;

        $result = $this->plugin($history, true)->aroundReindexAll($this->indexer(), static function () use (&$proceeded) {
            $proceeded++;

            return 'skipped';
        });

        $this->assertSame('skipped', $result);
        $this->assertSame(1, $proceeded, 'Magento still gets the call');
    }

    public function testARunIsRecorded(): void
    {
        $history = $this->createMock(History::class);
        $history->expects($this->once())->method('record')
            ->with('catalog_product_price', 'full', $this->greaterThanOrEqual(0.0), false);

        $result = $this->plugin($history, false)->aroundReindexAll($this->indexer(), static fn () => 'done');

        $this->assertSame('done', $result);
    }

    public function testAFailedRunIsRecordedAndRethrown(): void
    {
        $history = $this->createMock(History::class);
        $history->expects($this->once())->method('record')
            ->with('catalog_product_price', 'full', $this->greaterThanOrEqual(0.0), true);
        $error = new \RuntimeException('Deadlock found');

        try {
            $this->plugin($history, false)->aroundReindexAll($this->indexer(), static function () use ($error) {
                throw $error;
            });
            $this->fail('the reindex error is rethrown');
        } catch (\RuntimeException $e) {
            $this->assertSame($error, $e);
        }
    }

    public function testTheWorkingStateIsAskedForThisIndex(): void
    {
        $working = $this->createMock(WorkingStateProvider::class);
        $working->expects($this->once())->method('isWorking')->with('catalog_product_price')->willReturn(true);

        (new RecordReindex($this->createStub(History::class), $this->createStub(LoggerInterface::class), $working))
            ->aroundReindexAll($this->indexer(), static fn () => null);
    }

    private function plugin(History $history, bool $working): RecordReindex
    {
        $state = $this->createStub(WorkingStateProvider::class);
        $state->method('isWorking')->willReturn($working);

        return new RecordReindex($history, $this->createStub(LoggerInterface::class), $state);
    }

    private function indexer(): IndexerInterface
    {
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('getId')->willReturn('catalog_product_price');

        return $indexer;
    }
}
