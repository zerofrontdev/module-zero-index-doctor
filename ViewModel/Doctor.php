<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\IndexDoctor\Model\Doctor as DoctorModel;
use Zero\IndexDoctor\Model\Finding;
use Zero\IndexDoctor\Model\History;

/**
 * The Index Doctor page's data: the findings, the worst first, and the reindex times per index.
 */
class Doctor implements ArgumentInterface
{
    public function __construct(
        private readonly DoctorModel $doctor,
        private readonly History $history
    ) {
    }

    /**
     * @return Finding[]
     */
    public function findings(): array
    {
        return $this->doctor->examine();
    }

    /**
     * @return array<string, array{last: float|null, last_at: string|null, median: float|null, runs: int, changes: int, changes_seconds: float}>
     */
    public function times(): array
    {
        return $this->history->summary();
    }
}
