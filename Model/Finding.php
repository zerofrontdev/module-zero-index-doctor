<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model;

/**
 * One thing the doctor looked at: how it stands, what it saw, and what to do about it.
 */
class Finding
{
    public const OK = 'ok';
    public const INFO = 'info';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** The worst first. */
    private const ORDER = [self::FAIL => 0, self::WARN => 1, self::INFO => 2, self::OK => 3];

    public function __construct(
        public readonly string $status,
        public readonly string $title,
        public readonly string $detail,
        public readonly string $fix = ''
    ) {
    }

    public function rank(): int
    {
        return self::ORDER[$this->status] ?? 9;
    }

    /**
     * @return array{status: string, title: string, detail: string, fix: string}
     */
    public function toArray(): array
    {
        return ['status' => $this->status, 'title' => $this->title, 'detail' => $this->detail, 'fix' => $this->fix];
    }
}
