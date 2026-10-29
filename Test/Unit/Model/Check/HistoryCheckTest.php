<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Model\Check;

use PHPUnit\Framework\TestCase;
use Zero\IndexDoctor\Model\Check\HistoryCheck;
use Zero\IndexDoctor\Model\Finding;
use Zero\IndexDoctor\Model\History;

class HistoryCheckTest extends TestCase
{
    public function testAFullRebuildOverTwiceTheUsualAndOverAMinuteIsSlower(): void
    {
        $finding = $this->finding(['catalog_category_product' => ['last' => 12544.0, 'median' => 12.0]]);

        $this->assertSame(Finding::WARN, $finding->status);
        $this->assertStringContainsString('catalog_category_product 12544', $finding->detail);
    }

    public function testSecondsOfJitterAndNormalRunsAreNoNews(): void
    {
        $this->assertSame(Finding::OK, $this->finding(['catalogrule_rule' => ['last' => 9.0, 'median' => 3.0]])->status);
        $this->assertSame(Finding::OK, $this->finding(['catalog_product_price' => ['last' => 180.0, 'median' => 170.0]])->status);
        $this->assertSame(Finding::OK, $this->finding(['catalog_product_price' => ['last' => 180.0, 'median' => null]])->status);
    }

    public function testNothingRecordedYetIsSaidSo(): void
    {
        $this->assertSame(Finding::INFO, $this->finding([])->status);
    }

    public function testTheMedian(): void
    {
        $this->assertNull(History::median([]));
        $this->assertSame(5.0, History::median([9.0, 1.0, 5.0]));
        $this->assertSame(4.0, History::median([2.0, 6.0, 3.0, 5.0]));
    }

    /**
     * @param array<string, array{last: float|null, median: float|null}> $rows
     */
    private function finding(array $rows): Finding
    {
        $history = $this->createMock(History::class);
        $history->method('summary')->willReturn(array_map(
            static fn (array $r) => $r + ['last_at' => null, 'runs' => 2, 'changes' => 0, 'changes_seconds' => 0.0],
            $rows
        ));

        return (new HistoryCheck($history))->run()[0];
    }
}
