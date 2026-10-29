<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model\Check;

use Magento\Framework\App\ResourceConnection;
use Zero\IndexDoctor\Model\CheckInterface;
use Zero\IndexDoctor\Model\Finding;

/**
 * Whether Magento's cron runs the indexers: scheduled indexes, invalid ones and changelogs depend on it.
 */
class CronCheck implements CheckInterface
{
    /** The indexer jobs run every minute; past this without one, the cron is not running them. */
    public const STALE_SECONDS = 900;
    private const JOBS = ['indexer_update_all_views', 'indexer_reindex_all_invalid'];

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    public function run(): array
    {
        $title = (string) __('Cron');
        $connection = $this->resource->getConnection();
        $last = (string) $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('cron_schedule'), [new \Zend_Db_Expr('MAX(finished_at)')])
                ->where('job_code IN (?)', self::JOBS)
                ->where('status = ?', 'success')
        );
        $fix = (string) __('The server\'s crontab: * * * * * php bin/magento cron:run (bin/magento cron:install writes it), as the web server\'s user.');
        if ($last === '') {
            return [new Finding(Finding::FAIL, $title, (string) __('No indexer job has ever finished: scheduled indexes and invalid ones are never rebuilt.'), $fix)];
        }
        $age = time() - (int) strtotime($last . ' UTC');
        if ($age > self::STALE_SECONDS) {
            return [new Finding(
                Finding::FAIL,
                $title,
                (string) __('The last indexer job finished %1 minutes ago (they run every minute).', (int) round($age / 60)),
                $fix
            )];
        }

        return [new Finding(Finding::OK, $title, (string) __('Indexer jobs ran %1 minutes ago.', max(0, (int) round($age / 60))))];
    }
}
