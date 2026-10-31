<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\IndexDoctor\Model\Doctor;
use Zero\IndexDoctor\Model\Finding;
use Zero\IndexDoctor\Model\Repair;

/**
 * bin/magento zero:index:doctor [--repair [--force]] [--json]: what slows the indexers down or stops them, with the
 * fix for each; --repair releases reindexes that died and rebuilds the invalid indexes (without
 * indexer/use_application_lock a long "working" index is only reported, unless --force). Exit code 1 when something
 * failed, a rebuild included (for a monitor or a deploy script), 0 otherwise.
 *
 * --repair sets the adminhtml area (config scope), like indexer:reindex: Magento_CatalogRuleConfigurable declares the
 * plugins that give a configurable's rule discount to its variants only for adminhtml and crontab, so a catalog rule
 * index rebuilt in the global area holds only the configurable parents. (indexer:reindex also loads the adminhtml
 * ObjectManager config; that makes no difference here.)
 */
class DoctorCommand extends Command
{
    private const MARK = [Finding::OK => '  ok', Finding::INFO => 'info', Finding::WARN => 'warn', Finding::FAIL => 'FAIL'];

    public function __construct(
        private readonly Doctor $doctor,
        private readonly Repair $repair,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:index:doctor')
            ->setDescription('What slows Magento\'s indexers down or stops them, and how to fix each (Zero Index Doctor)')
            ->addOption('repair', null, InputOption::VALUE_NONE, 'Release reindexes that died ("working" for hours, no lock) and rebuild the invalid indexes')
            ->addOption('force', null, InputOption::VALUE_NONE, 'With --repair: also release a "working" index when indexer/use_application_lock is off (only when no reindex is running)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'The findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');
        if ($force && !$input->getOption('repair')) {
            $output->writeln('<error>--force goes with --repair.</error>');

            return Command::INVALID;
        }
        $rebuildFailed = false;
        if ($input->getOption('repair')) {
            $this->useAdminArea($output);
            $stuck = $this->repair->releaseStuck($force);
            foreach ($stuck['released'] as $id) {
                $output->writeln(sprintf('Released %s (was stuck "working")', $id));
            }
            $locking = $this->repair->usesApplicationLock();
            foreach ($stuck['kept'] as $id) {
                $output->writeln($locking
                    ? sprintf('Kept %s: "working" for over two hours, and its reindex still holds the lock (still running)', $id)
                    : sprintf('<comment>Kept %s: "working" for over two hours, but with indexer/use_application_lock off a running reindex looks the same.</comment>', $id));
                if (!$locking) {
                    $output->writeln('       → check that no indexer:reindex is running, then --repair --force; better, turn on indexer/use_application_lock');
                }
            }
            $rebuilt = $this->repair->rebuildInvalid(static function (string $id, float $seconds, ?string $error) use ($output): void {
                $output->writeln($error === null
                    ? sprintf('Rebuilt %s in %.1f s', $id, $seconds)
                    : sprintf('<error>Failed %s: %s</error>', $id, $error));
            });
            $rebuildFailed = (bool) $rebuilt['failed'];
        }
        $findings = $this->doctor->examine();
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode(array_map(static fn (Finding $f) => $f->toArray(), $findings), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($findings as $finding) {
                $output->writeln(sprintf('[%s] %s: %s', self::MARK[$finding->status] ?? $finding->status, $finding->title, $finding->detail));
                if ($finding->fix !== '' && $finding->status !== Finding::OK) {
                    $output->writeln('       → ' . $finding->fix);
                }
            }
        }

        return $rebuildFailed || array_filter($findings, static fn (Finding $f) => $f->status === Finding::FAIL)
            ? Command::FAILURE
            : Command::SUCCESS;
    }

    /**
     * The adminhtml area for the rebuilds (see the class comment); an area set already is kept, with a warning when
     * it is not one where the catalog rule index is built right.
     */
    private function useAdminArea(OutputInterface $output): void
    {
        try {
            $this->state->setAreaCode(Area::AREA_ADMINHTML);

            return;
        } catch (LocalizedException) {
            // Already set.
        }
        $area = $this->state->getAreaCode();
        if (!in_array($area, [Area::AREA_ADMINHTML, Area::AREA_CRONTAB], true)) {
            $output->writeln(sprintf('<comment>Area "%s" already set: a catalog rule index rebuilt here leaves the configurable variants without the rule discount; run bin/magento indexer:reindex catalogrule_rule afterwards.</comment>', $area));
        }
    }
}
