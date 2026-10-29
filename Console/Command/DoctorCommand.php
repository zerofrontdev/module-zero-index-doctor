<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Zero\IndexDoctor\Model\Doctor;
use Zero\IndexDoctor\Model\Finding;
use Zero\IndexDoctor\Model\Repair;

/**
 * bin/magento zero:index:doctor [--repair] [--json]: what slows the indexers down or stops them, with the fix for
 * each; --repair releases reindexes that died and rebuilds the invalid indexes. Exit code 1 when something failed
 * (for a monitor or a deploy script), 0 otherwise.
 */
class DoctorCommand extends Command
{
    private const MARK = [Finding::OK => '  ok', Finding::INFO => 'info', Finding::WARN => 'warn', Finding::FAIL => 'FAIL'];

    public function __construct(
        private readonly Doctor $doctor,
        private readonly Repair $repair
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('zero:index:doctor')
            ->setDescription('What slows Magento\'s indexers down or stops them, and how to fix each (Zero Index Doctor)')
            ->addOption('repair', null, InputOption::VALUE_NONE, 'Release reindexes that died ("working" for hours, no lock) and rebuild the invalid indexes')
            ->addOption('json', null, InputOption::VALUE_NONE, 'The findings as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('repair')) {
            foreach ($this->repair->releaseStuck() as $id) {
                $output->writeln(sprintf('Released %s (was stuck "working")', $id));
            }
            $this->repair->rebuildInvalid(static function (string $id, float $seconds) use ($output): void {
                $output->writeln(sprintf('Rebuilt %s in %.1f s', $id, $seconds));
            });
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

        return array_filter($findings, static fn (Finding $f) => $f->status === Finding::FAIL) ? Command::FAILURE : Command::SUCCESS;
    }
}
