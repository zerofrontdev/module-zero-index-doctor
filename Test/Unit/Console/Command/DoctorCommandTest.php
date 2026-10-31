<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Test\Unit\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zero\IndexDoctor\Console\Command\DoctorCommand;
use Zero\IndexDoctor\Model\Doctor;
use Zero\IndexDoctor\Model\Finding;
use Zero\IndexDoctor\Model\Repair;

/**
 * --repair [--force]: what was released, kept and rebuilt, and the exit code a monitor reads.
 */
class DoctorCommandTest extends TestCase
{
    public function testForceWithoutRepairIsRefusedAndChangesNothing(): void
    {
        $repair = $this->createMock(Repair::class);
        $repair->expects($this->never())->method('releaseStuck');
        $repair->expects($this->never())->method('rebuildInvalid');
        $doctor = $this->createMock(Doctor::class);
        $doctor->expects($this->never())->method('examine');
        $tester = new CommandTester(new DoctorCommand($doctor, $repair, $this->createStub(State::class)));

        $this->assertSame(Command::INVALID, $tester->execute(['--force' => true]));
        $this->assertStringContainsString('--force goes with --repair.', $tester->getDisplay());
    }

    public function testWithoutTheLockAKeptIndexIsExplainedAndTheRunSucceeds(): void
    {
        $repair = $this->createMock(Repair::class);
        $repair->expects($this->once())->method('releaseStuck')->with(false)
            ->willReturn(['released' => [], 'kept' => ['catalogsearch_fulltext']]);
        $repair->method('usesApplicationLock')->willReturn(false);
        $repair->method('rebuildInvalid')->willReturn(['rebuilt' => [], 'failed' => []]);
        $tester = new CommandTester(new DoctorCommand($this->doctor([new Finding(Finding::WARN, 'Reindexes possibly stuck "working"', 'x')]), $repair, $this->createStub(State::class)));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--repair' => true]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Kept catalogsearch_fulltext: "working" for over two hours, but with indexer/use_application_lock off a running reindex looks the same.', $display);
        $this->assertStringContainsString('check that no indexer:reindex is running, then --repair --force; better, turn on indexer/use_application_lock', $display);
        $this->assertStringNotContainsString('Released', $display);
    }

    public function testRepairForcePassesTheForceOn(): void
    {
        $repair = $this->createMock(Repair::class);
        $repair->expects($this->once())->method('releaseStuck')->with(true)
            ->willReturn(['released' => ['catalogsearch_fulltext'], 'kept' => []]);
        $repair->method('rebuildInvalid')->willReturn(['rebuilt' => [], 'failed' => []]);
        $tester = new CommandTester(new DoctorCommand($this->doctor([]), $repair, $this->createStub(State::class)));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--repair' => true, '--force' => true]));
        $this->assertStringContainsString('Released catalogsearch_fulltext (was stuck "working")', $tester->getDisplay());
    }

    public function testWithTheLockAKeptIndexIsStillRunning(): void
    {
        $repair = $this->createMock(Repair::class);
        $repair->expects($this->once())->method('releaseStuck')->willReturn(['released' => [], 'kept' => ['catalog_product_price']]);
        $repair->method('usesApplicationLock')->willReturn(true);
        $repair->method('rebuildInvalid')->willReturn(['rebuilt' => [], 'failed' => []]);
        $tester = new CommandTester(new DoctorCommand($this->doctor([]), $repair, $this->createStub(State::class)));

        $tester->execute(['--repair' => true]);

        $this->assertStringContainsString('Kept catalog_product_price: "working" for over two hours, and its reindex still holds the lock (still running)', $tester->getDisplay());
        $this->assertStringNotContainsString('--repair --force', $tester->getDisplay());
    }

    public function testAFailedRebuildIsPrintedAndFailsTheRun(): void
    {
        $repair = $this->createMock(Repair::class);
        $repair->method('releaseStuck')->willReturn(['released' => [], 'kept' => []]);
        $repair->expects($this->once())->method('rebuildInvalid')->willReturnCallback(static function (callable $done) {
            $done('catalogsearch_fulltext', 0.2, 'OpenSearch is down');
            $done('catalog_product_price', 12.34, null);

            return ['rebuilt' => ['catalog_product_price'], 'failed' => ['catalogsearch_fulltext' => 'OpenSearch is down']];
        });
        $tester = new CommandTester(new DoctorCommand($this->doctor([new Finding(Finding::OK, 'a', 'b')]), $repair, $this->createStub(State::class)));

        $this->assertSame(Command::FAILURE, $tester->execute(['--repair' => true]));
        $this->assertStringContainsString('Failed catalogsearch_fulltext: OpenSearch is down', $tester->getDisplay());
        $this->assertStringContainsString('Rebuilt catalog_product_price in 12.3 s', $tester->getDisplay());
    }

    public function testAFailFindingStillFailsTheRun(): void
    {
        $repair = $this->createMock(Repair::class);
        $repair->expects($this->never())->method('releaseStuck');
        $tester = new CommandTester(new DoctorCommand($this->doctor([new Finding(Finding::FAIL, 'a', 'b')]), $repair, $this->createStub(State::class)));

        $this->assertSame(Command::FAILURE, $tester->execute([]));
    }

    public function testRepairSetsTheAdminAreaBeforeAnyRebuild(): void
    {
        $calls = [];
        $state = $this->createMock(State::class);
        $state->expects($this->once())->method('setAreaCode')->with('adminhtml')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'area';
            });
        $repair = $this->createStub(Repair::class);
        $repair->method('releaseStuck')->willReturnCallback(static function () use (&$calls): array {
            $calls[] = 'release';

            return ['released' => [], 'kept' => []];
        });
        $repair->method('rebuildInvalid')->willReturnCallback(static function () use (&$calls): array {
            $calls[] = 'rebuild';

            return ['rebuilt' => [], 'failed' => []];
        });
        $tester = new CommandTester(new DoctorCommand($this->doctor([]), $repair, $state));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--repair' => true]));
        $this->assertSame(['area', 'release', 'rebuild'], $calls);
        $this->assertStringNotContainsString('already set', $tester->getDisplay());
    }

    public function testReportOnlyLeavesTheAreaAlone(): void
    {
        $state = $this->createMock(State::class);
        $state->expects($this->never())->method('setAreaCode');
        $tester = new CommandTester(new DoctorCommand($this->doctor([]), $this->createStub(Repair::class), $state));

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
    }

    public function testAnAdminAreaSetAlreadyIsKeptQuietly(): void
    {
        $state = $this->createStub(State::class);
        $state->method('setAreaCode')->willThrowException(new LocalizedException(new Phrase('Area code is already set')));
        $state->method('getAreaCode')->willReturn('crontab');
        $repair = $this->createMock(Repair::class);
        $repair->method('releaseStuck')->willReturn(['released' => [], 'kept' => []]);
        $repair->expects($this->once())->method('rebuildInvalid')->willReturn(['rebuilt' => [], 'failed' => []]);
        $tester = new CommandTester(new DoctorCommand($this->doctor([]), $repair, $state));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--repair' => true]));
        $this->assertStringNotContainsString('already set', $tester->getDisplay());
    }

    public function testAnotherAreaSetAlreadyIsWarnedAboutAndTheRepairGoesOn(): void
    {
        $state = $this->createStub(State::class);
        $state->method('setAreaCode')->willThrowException(new LocalizedException(new Phrase('Area code is already set')));
        $state->method('getAreaCode')->willReturn('frontend');
        $repair = $this->createMock(Repair::class);
        $repair->method('releaseStuck')->willReturn(['released' => [], 'kept' => []]);
        $repair->expects($this->once())->method('rebuildInvalid')->willReturn(['rebuilt' => [], 'failed' => []]);
        $tester = new CommandTester(new DoctorCommand($this->doctor([]), $repair, $state));

        $this->assertSame(Command::SUCCESS, $tester->execute(['--repair' => true]));
        $this->assertStringContainsString('Area "frontend" already set', $tester->getDisplay());
        $this->assertStringContainsString('indexer:reindex catalogrule_rule', $tester->getDisplay());
    }

    /**
     * @param Finding[] $findings
     */
    private function doctor(array $findings): Doctor
    {
        $doctor = $this->createStub(Doctor::class);
        $doctor->method('examine')->willReturn($findings);

        return $doctor;
    }
}
