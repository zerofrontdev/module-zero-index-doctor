<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Model\Check;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\TestCase;
use Zero\IndexDoctor\Model\Check\CatalogRuleCheck;
use Zero\IndexDoctor\Model\Finding;

class CatalogRuleCheckTest extends TestCase
{
    public function testConfigurableParentsInTheRuleIndexFailWithTheReindexFix(): void
    {
        $findings = $this->findings(true, '9');

        $this->assertCount(1, $findings);
        $this->assertSame(Finding::FAIL, $findings[0]->status);
        $this->assertStringContainsString('9 configurable products', $findings[0]->detail);
        $this->assertStringContainsString('indexer:reindex catalogrule_rule', $findings[0]->fix);
    }

    public function testOnlyVariantsInTheRuleIndexIsOk(): void
    {
        $findings = $this->findings(true, '0');

        $this->assertSame(Finding::OK, $findings[0]->status);
    }

    public function testWithoutCatalogRuleConfigurableNothingIsChecked(): void
    {
        $this->assertSame([], $this->findings(false, null));
    }

    /**
     * @return Finding[]
     */
    private function findings(bool $moduleEnabled, ?string $count): array
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects($count === null ? $this->never() : $this->once())
            ->method('fetchOne')->willReturn($count);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $modules = $this->createStub(ModuleManager::class);
        $modules->method('isEnabled')->willReturnCallback(
            static fn (string $module) => $moduleEnabled && $module === 'Magento_CatalogRuleConfigurable'
        );

        return (new CatalogRuleCheck($resource, $modules))->run();
    }
}
