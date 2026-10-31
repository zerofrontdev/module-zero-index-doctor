<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model\Check;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Module\Manager as ModuleManager;
use Zero\IndexDoctor\Model\CheckInterface;
use Zero\IndexDoctor\Model\Finding;

/**
 * Whether the catalog price rule index reached the variants of configurable products.
 *
 * Magento_CatalogRuleConfigurable gives a matching configurable's discount to its variants and drops the parent
 * from catalogrule_product, but its plugins are declared only for the adminhtml and crontab areas. A rule reindex
 * run anywhere else (a CLI command that sets no area, a storefront or API request) matches the parents alone:
 * the variants lose the discount, configurable final prices show none, and Sale badges disappear. So a
 * configurable product in catalogrule_product means the index was built without those plugins.
 */
class CatalogRuleCheck implements CheckInterface
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly ModuleManager $moduleManager
    ) {
    }

    public function run(): array
    {
        if (!$this->moduleManager->isEnabled('Magento_CatalogRuleConfigurable')) {
            return [];
        }
        $title = (string) __('Catalog price rules on configurable products');
        $connection = $this->resource->getConnection();
        $parents = (int) $connection->fetchOne(
            $connection->select()
                ->from(['rule' => $this->resource->getTableName('catalogrule_product')], [new \Zend_Db_Expr('COUNT(DISTINCT rule.product_id)')])
                ->join(['product' => $this->resource->getTableName('catalog_product_entity')], 'product.entity_id = rule.product_id', [])
                ->where('product.type_id = ?', 'configurable')
        );
        if ($parents === 0) {
            return [new Finding(Finding::OK, $title, (string) __('The rule index holds the variants, not the configurable parents.'))];
        }

        return [new Finding(
            Finding::FAIL,
            $title,
            (string) __('%1 configurable products sit in catalogrule_product in place of their variants: a rule reindex ran outside the admin and cron areas, so the variants lost their catalog rule discount.', $parents),
            'bin/magento indexer:reindex catalogrule_rule catalogrule_product catalog_product_price'
        )];
    }
}
