<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\IndexDoctor\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * What the database server says about itself: its version, its settings, the store's size.
 */
class Database
{
    /** @var array<string, string>|null */
    private ?array $variables = null;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * "11.8.9-MariaDB", "8.4.3".
     */
    public function version(): string
    {
        return (string) $this->resource->getConnection()->fetchOne('SELECT VERSION()');
    }

    public function variable(string $name): ?string
    {
        if ($this->variables === null) {
            $this->variables = array_change_key_case(
                $this->resource->getConnection()->fetchPairs('SHOW GLOBAL VARIABLES'),
                CASE_LOWER
            );
        }

        return isset($this->variables[$name]) ? (string) $this->variables[$name] : null;
    }

    /**
     * The store's tables, data and indexes, in bytes.
     */
    public function size(): int
    {
        $connection = $this->resource->getConnection();

        return (int) $connection->fetchOne(
            'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()'
        );
    }
}
