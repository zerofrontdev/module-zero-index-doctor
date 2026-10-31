<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use Zero\IndexDoctor\Model\History;

/**
 * The summary from three bounded reads: the full rebuilds counted, the last six of each indexer, the changelog runs
 * counted and summed in the database.
 */
class HistoryTest extends TestCase
{
    public function testAssembleMatchesTheSummaryShape(): void
    {
        $summary = History::assemble(
            ['catalog_product_price' => '9', 'customer_grid' => 1],
            [
                // newest first within an indexer, as the query orders them
                ['indexer_id' => 'catalog_product_price', 'seconds' => '30.00', 'started_at' => '2026-10-31 02:00:00'],
                ['indexer_id' => 'catalog_product_price', 'seconds' => '10.00', 'started_at' => '2026-10-30 02:00:00'],
                ['indexer_id' => 'catalog_product_price', 'seconds' => '12.00', 'started_at' => '2026-10-29 02:00:00'],
                ['indexer_id' => 'catalog_product_price', 'seconds' => '14.00', 'started_at' => '2026-10-28 02:00:00'],
                ['indexer_id' => 'catalog_product_price', 'seconds' => '16.00', 'started_at' => '2026-10-27 02:00:00'],
                ['indexer_id' => 'catalog_product_price', 'seconds' => '18.00', 'started_at' => '2026-10-26 02:00:00'],
                ['indexer_id' => 'customer_grid', 'seconds' => '5.5', 'started_at' => '2026-10-31 01:00:00'],
            ],
            [['indexer_id' => 'catalog_product_price', 'changes' => '4', 'changes_seconds' => '6.004']]
        );

        $this->assertSame([
            'catalog_product_price' => [
                'last' => 30.0, 'last_at' => '2026-10-31 02:00:00', 'median' => 14.0, 'runs' => 9,
                'changes' => 4, 'changes_seconds' => 6.0,
            ],
            'customer_grid' => [
                'last' => 5.5, 'last_at' => '2026-10-31 01:00:00', 'median' => null, 'runs' => 1,
                'changes' => 0, 'changes_seconds' => 0.0,
            ],
        ], $summary);
    }

    public function testOnlyChanges(): void
    {
        $summary = History::assemble([], [], [
            ['indexer_id' => 'cataloginventory_stock', 'changes' => '3', 'changes_seconds' => '4.567'],
        ]);

        $this->assertSame(['cataloginventory_stock' => [
            'last' => null, 'last_at' => null, 'median' => null, 'runs' => 0, 'changes' => 3, 'changes_seconds' => 4.57,
        ]], $summary);
    }

    public function testIndexersAreSortedByIdAndNumericIdsKeptAsStrings(): void
    {
        $summary = History::assemble(['zz' => 1, '123' => 1], [
            ['indexer_id' => 'zz', 'seconds' => 1, 'started_at' => 'a'],
            ['indexer_id' => '123', 'seconds' => 2, 'started_at' => 'b'],
        ], []);

        $this->assertSame(['123', 'zz'], array_map('strval', array_keys($summary)));
        $this->assertSame(2.0, $summary['123']['last']);
    }

    public function testSummaryReadsOnlyTheLastSixFullRebuildsAndAggregatesTheChanges(): void
    {
        $selects = [];
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(function () use (&$selects) {
            $calls = [];
            $select = $this->createStub(Select::class);
            foreach (['from', 'where', 'order', 'limit', 'group'] as $method) {
                $select->method($method)->willReturnCallback(function (...$args) use ($method, $select, &$calls) {
                    while ($args && end($args) === null) {
                        array_pop($args); // the defaults PHPUnit passes on
                    }
                    $calls[] = [$method, ...$args];

                    return $select;
                });
            }
            $select->method('__toString')->willReturnCallback(static function () use (&$calls) {
                return json_encode($calls);
            });
            $selects[] = &$calls;
            unset($calls);

            return $select;
        });
        $connection->expects($this->once())->method('fetchPairs')->willReturn(['catalog_product_price' => '9']);
        $reads = [];
        $connection->expects($this->exactly(2))->method('fetchAll')->willReturnCallback(static function ($sql) use (&$reads) {
            $reads[] = (string) $sql;

            return count($reads) === 1
                ? [['indexer_id' => 'catalog_product_price', 'seconds' => '3', 'started_at' => '2026-10-31 00:00:00']]
                : [['indexer_id' => 'catalog_product_price', 'changes' => '2', 'changes_seconds' => '1.5']];
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $summary = (new History($resource))->summary();

        $this->assertSame(9, $summary['catalog_product_price']['runs']);
        $this->assertSame(2, $summary['catalog_product_price']['changes']);
        // The per-indexer read of full rebuilds is limited to BASELINE + 1 rows, newest first.
        $this->assertStringStartsWith('(', $reads[0]);
        $this->assertContains(['limit', 6], $selects[1]);
        $this->assertContains(['order', ['started_at DESC', 'run_id DESC']], $selects[1]);
        $this->assertContains(['where', 'kind = ?', 'full'], $selects[1]);
        // The changelog runs come back as one row per indexer: counted and summed in SQL.
        $this->assertContains(['where', 'kind <> ?', 'full'], $selects[2]);
        $this->assertContains(['group', 'indexer_id'], $selects[2]);
        $this->assertContains(['group', 'indexer_id'], $selects[0]);
    }
}
