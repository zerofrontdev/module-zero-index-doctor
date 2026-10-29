<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model\Check;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Indexer\Model\Indexer\CollectionFactory;
use Zero\IndexDoctor\Model\CheckInterface;
use Zero\IndexDoctor\Model\Finding;

/**
 * Magento's own indexers: which need a reindex, which are stuck "working" after a crash, whether a crash can leave
 * them stuck at all (indexer/use_application_lock), their modes on a large catalogue, the changes waiting in their
 * changelogs, Adobe's data exporters, parallel reindexing and the price index's dimensions.
 */
class IndexerCheck implements CheckInterface
{
    /** A status "working" older than this, with nothing running, is a reindex that died. */
    public const STUCK_SECONDS = 7200;
    /** Changes waiting in one changelog past which the cron is not keeping up. */
    public const BACKLOG = 10000;
    private const LARGE_CATALOGUE = 5000;
    private const HUGE_CATALOGUE = 50000;
    private const CATALOGUE_INDEXERS = [
        'catalog_category_product', 'catalog_product_category', 'catalog_product_price', 'catalog_product_attribute',
        'cataloginventory_stock', 'catalogrule_rule', 'catalogrule_product', 'catalogsearch_fulltext',
    ];

    public function __construct(
        private readonly CollectionFactory $indexers,
        private readonly ResourceConnection $resource,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LockManagerInterface $locks
    ) {
    }

    public function run(): array
    {
        /** @var IndexerInterface[] $indexers */
        $indexers = $this->indexers->create()->getItems();
        $products = (int) $this->resource->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . $this->resource->getTableName('catalog_product_entity')
        );

        return array_values(array_filter([
            $this->invalid($indexers),
            $this->stuck(),
            $this->applicationLock(),
            $this->modes($indexers, $products),
            $this->backlog($indexers),
            $this->exporters($indexers),
            $this->threads(),
            $this->priceDimensions($products),
        ]));
    }

    /**
     * @param IndexerInterface[] $indexers
     */
    private function invalid(array $indexers): Finding
    {
        $title = (string) __('Indexes to rebuild');
        $ids = [];
        foreach ($indexers as $indexer) {
            if ($indexer->isInvalid()) {
                $ids[] = $indexer->getId();
            }
        }
        if (!$ids) {
            return new Finding(Finding::OK, $title, (string) __('Every index is up to date.'));
        }

        return new Finding(
            Finding::WARN,
            $title,
            (string) __('%1 need a full reindex: %2. Until then the storefront may show old prices, stock or categories.', count($ids), implode(', ', $ids)),
            'bin/magento indexer:reindex ' . implode(' ', $ids)
        );
    }

    /**
     * Read from the table: with the application lock on, Magento itself reports such an index as invalid.
     */
    private function stuck(): Finding
    {
        $title = (string) __('Reindexes stuck "working"');
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('indexer_state'), ['indexer_id', 'updated'])
                ->where('status = ?', 'working')
                ->where('updated < ?', gmdate('Y-m-d H:i:s', time() - self::STUCK_SECONDS))
        );
        // With the application lock, a reindex still running holds Magento's lock ("INDEXER<id>"): not stuck.
        if ($this->deploymentConfig->get('indexer/use_application_lock')) {
            $rows = array_filter($rows, fn ($updated, $id) => !$this->locks->isLocked('INDEXER' . $id), ARRAY_FILTER_USE_BOTH);
        }
        if (!$rows) {
            return new Finding(Finding::OK, $title, (string) __('None.'));
        }

        return new Finding(
            Finding::FAIL,
            $title,
            (string) __('%1 "working" for over two hours: %2. A reindex that died leaves this, and the cron then skips the index: it never rebuilds again.', count($rows), implode(', ', array_keys($rows))),
            (string) __('If no reindex is running: bin/magento zero:index:doctor --repair (or indexer:reset, then indexer:reindex).')
        );
    }

    private function applicationLock(): Finding
    {
        $title = (string) __('Crashed reindexes released');
        if ($this->deploymentConfig->get('indexer/use_application_lock')) {
            return new Finding(Finding::OK, $title, (string) __('indexer/use_application_lock is on: a reindex that dies is seen as invalid and rebuilt.'));
        }

        return new Finding(
            Finding::WARN,
            $title,
            (string) __('indexer/use_application_lock is off: a reindex killed half way (a deploy, the memory limit, a timeout) stays "working" for good, and that index is never rebuilt again.'),
            (string) __('In app/etc/env.php: \'indexer\' => [\'use_application_lock\' => true], then bin/magento cache:clean config.')
        );
    }

    /**
     * @param IndexerInterface[] $indexers
     */
    private function modes(array $indexers, int $products): ?Finding
    {
        $title = (string) __('Index modes');
        $onSave = [];
        foreach ($indexers as $indexer) {
            if (in_array($indexer->getId(), self::CATALOGUE_INDEXERS, true) && !$indexer->isScheduled()) {
                $onSave[] = $indexer->getId();
            }
        }
        if (!$onSave) {
            return new Finding(Finding::OK, $title, (string) __('The catalogue indexes update by schedule.'));
        }
        $detail = (string) __('%1 update on save: every product saved in the admin or by an import waits for them.', implode(', ', $onSave));
        $fix = 'bin/magento indexer:set-mode schedule ' . implode(' ', $onSave);

        return $products >= self::LARGE_CATALOGUE
            ? new Finding(Finding::WARN, $title, $detail . ' ' . __('With %1 products, saves and imports slow down.', $products), $fix)
            : new Finding(Finding::INFO, $title, $detail, $fix);
    }

    /**
     * @param IndexerInterface[] $indexers
     */
    private function backlog(array $indexers): Finding
    {
        $title = (string) __('Changes waiting in the changelogs');
        $waiting = [];
        foreach ($indexers as $indexer) {
            if (!$indexer->isScheduled()) {
                continue;
            }
            try {
                $view = $indexer->getView();
                $behind = (int) $view->getChangelog()->getVersion() - (int) $view->getState()->getVersionId();
            } catch (\Throwable) {
                continue;
            }
            if ($behind > self::BACKLOG) {
                $waiting[$indexer->getId()] = $behind;
            }
        }
        if (!$waiting) {
            return new Finding(Finding::OK, $title, (string) __('The scheduled indexes are keeping up.'));
        }
        $list = implode(', ', array_map(static fn ($id, $n) => $id . ' ' . $n, array_keys($waiting), $waiting));

        return new Finding(
            Finding::WARN,
            $title,
            (string) __('%1. The cron is not keeping up, or not running (see Cron below).', $list),
            'bin/magento cron:run (indexer_update_all_views), or once: bin/magento indexer:reindex ' . implode(' ', array_keys($waiting))
        );
    }

    /**
     * @param IndexerInterface[] $indexers
     */
    private function exporters(array $indexers): ?Finding
    {
        $ids = [];
        foreach ($indexers as $indexer) {
            if (str_ends_with((string) $indexer->getId(), '_data_exporter')) {
                $ids[] = $indexer->getId();
            }
        }
        if (!$ids) {
            return null;
        }

        return new Finding(
            Finding::INFO,
            (string) __('Adobe Commerce Services exporters'),
            (string) __('%1 feed Adobe\'s cloud services (Catalog Service, Live Search, Product Recommendations, Payment Services). A store that uses none of them reindexes them for nothing.', implode(', ', $ids)),
            (string) __('If unused: bin/magento module:disable the Magento_*DataExporter modules and their services, then setup:upgrade (their tables go).')
        );
    }

    private function threads(): Finding
    {
        $title = (string) __('Parallel reindexing');
        $threads = (int) getenv('MAGE_INDEXER_THREADS_COUNT');
        if ($threads > 1) {
            return new Finding(Finding::OK, $title, (string) __('MAGE_INDEXER_THREADS_COUNT=%1.', $threads));
        }

        return new Finding(
            Finding::INFO,
            $title,
            (string) __('MAGE_INDEXER_THREADS_COUNT is not set here: the price, category and search indexes rebuild in one process.'),
            (string) __('MAGE_INDEXER_THREADS_COUNT=4 bin/magento indexer:reindex (set it for the cron\'s user too; up to the server\'s CPU cores).')
        );
    }

    private function priceDimensions(int $products): ?Finding
    {
        if ($products < self::HUGE_CATALOGUE) {
            return null;
        }
        $mode = (string) $this->scopeConfig->getValue('indexer/catalog_product_price/dimensions_mode');
        $title = (string) __('Price index dimensions');
        if ($mode !== '' && $mode !== 'none') {
            return new Finding(Finding::OK, $title, (string) __('Split by %1.', $mode));
        }

        return new Finding(
            Finding::INFO,
            $title,
            (string) __('One price table for %1 products: a full reindex rewrites it whole.', $products),
            'bin/magento indexer:set-dimensions-mode catalog_product_price website_and_customer_group'
        );
    }
}
