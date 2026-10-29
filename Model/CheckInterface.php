<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model;

/**
 * A group of the doctor's checks (etc/di.xml, Doctor's `checks`). Read-only: a check never changes anything.
 */
interface CheckInterface
{
    /**
     * @return Finding[]
     */
    public function run(): array;
}
