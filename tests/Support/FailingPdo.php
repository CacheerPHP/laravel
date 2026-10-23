<?php

declare(strict_types=1);

namespace Cacheer\Laravel\Tests\Support;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/** Real SQLite storage with a controllable failure at the PDO boundary. */
final class FailingPdo extends PDO
{
    public bool $down = false;

    public ?string $failOnSql = null;

    public ?Throwable $failure = null;

    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    public function availableConnection(): self
    {
        if ($this->down) {
            throw new PDOException('The database has not started yet.');
        }

        return $this;
    }

    /** @param array<mixed> $options */
    #[\Override]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->failOnSql !== null && str_contains($query, $this->failOnSql)) {
            $this->down = true;
        }

        if ($this->down) {
            throw $this->failure ?? new PDOException('The test database is unavailable.');
        }

        return parent::prepare($query, $options);
    }
}
