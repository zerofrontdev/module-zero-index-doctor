<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Model\Check;

use PHPUnit\Framework\TestCase;
use Zero\IndexDoctor\Model\Check\DatabaseCheck;
use Zero\IndexDoctor\Model\Database;
use Zero\IndexDoctor\Model\Finding;

class DatabaseCheckTest extends TestCase
{
    private const MB = 1048576;

    public function testMariaDb105WithTheDefaultsWarnsAboutTheVersionAndTheOptimizer(): void
    {
        $findings = $this->findings('10.5.29-MariaDB', ['in_predicate_conversion_threshold' => '1000', 'optimizer_use_condition_selectivity' => '4']);

        $this->assertSame(Finding::WARN, $findings['Database server']->status);
        $this->assertSame(Finding::WARN, $findings['MariaDB optimizer settings']->status);
        $this->assertStringContainsString('in_predicate_conversion_threshold=0', $findings['MariaDB optimizer settings']->fix);
    }

    public function testOnMariaDb11TheOptimizerIsAdviceOnlyNotMeasuredThere(): void
    {
        $findings = $this->findings('11.8.9-MariaDB', ['in_predicate_conversion_threshold' => '1000', 'optimizer_use_condition_selectivity' => '4']);

        $this->assertSame(Finding::OK, $findings['Database server']->status);
        $this->assertSame(Finding::INFO, $findings['MariaDB optimizer settings']->status);
    }

    public function testMariaDbWithBothSettingsChangedIsOk(): void
    {
        $findings = $this->findings('10.5.29-MariaDB', ['in_predicate_conversion_threshold' => '0', 'optimizer_use_condition_selectivity' => '1']);

        $this->assertSame(Finding::OK, $findings['MariaDB optimizer settings']->status);
    }

    public function testMySqlHasNoMariaDbFindingAndOld80IsAWarning(): void
    {
        $this->assertArrayNotHasKey('MariaDB optimizer settings', $this->findings('8.4.9', []));
        $this->assertSame(Finding::OK, $this->findings('8.4.9', [])['Database server']->status);
        $this->assertSame(Finding::WARN, $this->findings('8.0.40', [])['Database server']->status);
    }

    public function testTheDefaultBufferPoolOnABigDatabaseFailsASmallerOneThanTheDatabaseWarns(): void
    {
        $this->assertSame(Finding::FAIL, $this->findings('8.4.9', ['innodb_buffer_pool_size' => (string) (128 * self::MB)], 1712 * self::MB)['InnoDB buffer pool']->status);
        $this->assertSame(Finding::WARN, $this->findings('8.4.9', ['innodb_buffer_pool_size' => (string) (1024 * self::MB)], 1712 * self::MB)['InnoDB buffer pool']->status);
        $this->assertSame(Finding::OK, $this->findings('8.4.9', ['innodb_buffer_pool_size' => (string) (8192 * self::MB)], 1712 * self::MB)['InnoDB buffer pool']->status);
    }

    public function testTheSmallerOfTheTwoTemporaryTableSizesDecides(): void
    {
        $small = $this->findings('8.4.9', ['tmp_table_size' => (string) (256 * self::MB), 'max_heap_table_size' => (string) (16 * self::MB)]);
        $big = $this->findings('8.4.9', ['tmp_table_size' => (string) (128 * self::MB), 'max_heap_table_size' => (string) (128 * self::MB)]);

        $this->assertSame(Finding::WARN, $small['In-memory temporary tables']->status);
        $this->assertSame(Finding::OK, $big['In-memory temporary tables']->status);
    }

    /**
     * @param array<string, string> $variables
     * @return array<string, Finding> by title
     */
    private function findings(string $version, array $variables, int $size = 100 * self::MB): array
    {
        $variables += ['innodb_buffer_pool_size' => (string) (4096 * self::MB), 'tmp_table_size' => (string) (64 * self::MB), 'max_heap_table_size' => (string) (64 * self::MB)];
        $database = $this->createMock(Database::class);
        $database->method('version')->willReturn($version);
        $database->method('size')->willReturn($size);
        $database->method('variable')->willReturnCallback(static fn (string $name) => $variables[$name] ?? null);
        $out = [];
        foreach ((new DatabaseCheck($database))->run() as $finding) {
            $out[$finding->title] = $finding;
        }

        return $out;
    }
}
