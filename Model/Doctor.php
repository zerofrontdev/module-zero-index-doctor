<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model;

use Psr\Log\LoggerInterface;

/**
 * Runs every check (etc/di.xml `checks`) and lists what it found, the worst first. A check that fails to run
 * becomes a finding of its own instead of hiding the others.
 */
class Doctor
{
    /**
     * @param CheckInterface[] $checks
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly array $checks = []
    ) {
    }

    /**
     * @return Finding[]
     */
    public function examine(): array
    {
        $findings = [];
        foreach ($this->checks as $name => $check) {
            try {
                array_push($findings, ...$check->run());
            } catch (\Throwable $e) {
                $this->logger->error('Zero_IndexDoctor: check ' . $name . ' failed: ' . $e->getMessage());
                $findings[] = new Finding(Finding::INFO, (string) $name, (string) __('This check could not run: %1', $e->getMessage()));
            }
        }
        usort($findings, static fn (Finding $a, Finding $b) => $a->rank() <=> $b->rank());

        return $findings;
    }
}
