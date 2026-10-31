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

    /**
     * Open an in-memory SQLite database.
     */
    public function __construct()
    {
        parent::__construct('sqlite::memory:');
    }

    /**
     * This connection, unless the database is down.
     *
     * @return self The connection.
     */
    public function availableConnection(): self
    {
        if ($this->down) {
            throw new PDOException('The database has not started yet.');
        }

        return $this;
    }

    /**
     * Prepare a statement, failing while the database is down or once the
     * query matches {@see self::$failOnSql}.
     *
     * @param  string  $query  The SQL.
     * @param  array<mixed>  $options  The driver options.
     * @return PDOStatement|false The statement.
     */
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
